<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Core\Settings;
use App\Repositories\OrderRepository;
use App\Services\Shop\OrderNotifier;
use App\Support\Log;

/**
 * Registry of payment gateways. Config per gateway = config 'payment.<key>' merged with the admin's
 * Settings overrides 'payment.<key>.*' (Settings::get('payment.<key>') does the merge).
 * Checkout order follows setting/config 'shop.payment_methods'; a gateway is offered only when
 * it is enabled AND configured (isAvailable()).
 */
final class PaymentManager
{
    public const KEYS = ['paytr', 'iyzico', 'shopier', 'havale'];

    /** @var array<string, PaymentGateway> */
    private array $gateways = [];

    /** @var list<string> */
    private array $order;

    /**
     * @param array<string, array<string, mixed>>|null $config gateway configs keyed by key (null => settings)
     * @param list<string>|null $methods checkout order (null => setting 'shop.payment_methods')
     */
    public function __construct(?array $config = null, ?array $methods = null, ?callable $transport = null)
    {
        foreach (self::KEYS as $key) {
            $gatewayConfig = $config[$key] ?? ($config === null ? Settings::get('payment.' . $key, []) : []);
            $gatewayConfig = is_array($gatewayConfig) ? $gatewayConfig : [];
            $this->gateways[$key] = match ($key) {
                'paytr' => new PaytrGateway($gatewayConfig, $transport),
                'iyzico' => new IyzicoGateway($gatewayConfig, $transport),
                'shopier' => new ShopierGateway($gatewayConfig, $transport),
                'havale' => new BankTransferGateway($gatewayConfig, $transport),
            };
        }
        $methods ??= Settings::get('shop.payment_methods', self::KEYS);
        $methods = is_array($methods) ? $methods : self::KEYS;
        $this->order = array_values(array_unique(array_filter(array_map('strval', $methods), fn (string $k): bool => isset($this->gateways[$k]))));
        foreach (self::KEYS as $key) {
            if (!in_array($key, $this->order, true)) {
                $this->order[] = $key; // gateways missing from the list are still reachable for callbacks, shown last
            }
        }
    }

    /** @return array<string, PaymentGateway> all gateways in checkout order */
    public function all(): array
    {
        $out = [];
        foreach ($this->order as $key) {
            $out[$key] = $this->gateways[$key];
        }
        return $out;
    }

    /** @return array<string, PaymentGateway> gateways offered at checkout (enabled + configured), in order */
    public function available(): array
    {
        return array_filter($this->all(), static fn (PaymentGateway $g): bool => $g->isAvailable());
    }

    public function get(string $key): ?PaymentGateway
    {
        return $this->gateways[$key] ?? null;
    }

    public function label(string $key): string
    {
        return isset($this->gateways[$key]) ? $this->gateways[$key]->label() : $key;
    }

    /**
     * Apply a verified gateway callback to its order (idempotent).
     *
     * @return array{outcome: string, order: ?array<string, mixed>} outcome: invalid|unknown|mismatch|duplicate|pending|paid|failed
     */
    public function applyCallback(PaymentGateway $gateway, array $result, OrderRepository $orders): array
    {
        $order = null;
        if (!empty($result['order_no'])) {
            $order = $orders->findByOrderNo((string) $result['order_no']);
        }
        if ($order === null && !empty($result['token'])) {
            $order = $orders->findByPaymentRef($gateway->key() . '-token:' . $result['token']);
        }
        $context = ['gateway' => $gateway->key(), 'order_no' => $result['order_no'] ?? null, 'message' => $result['message'] ?? ''];
        if (empty($result['valid'])) {
            Log::warning('payment', 'Rejected payment callback', $context);
            return ['outcome' => 'invalid', 'order' => $order];
        }
        if ($order === null) {
            Log::error('payment', 'Payment callback for unknown order', $context);
            return ['outcome' => 'unknown', 'order' => null];
        }
        if ($order['payment_method'] !== $gateway->key()) {
            Log::error('payment', 'Payment callback from a different gateway than the order uses', $context + ['order_method' => $order['payment_method']]);
            return ['outcome' => 'mismatch', 'order' => $order];
        }
        if ($order['payment_status'] === 'odendi') {
            return ['outcome' => 'duplicate', 'order' => $order];
        }
        if (!empty($result['pending'])) {
            Log::info('payment', 'Payment pending review', $context);
            return ['outcome' => 'pending', 'order' => $order];
        }
        $payload = json_encode(($result['payload'] ?? []) + ['received_at' => gmdate('c')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null;
        $ref = isset($result['ref']) ? (string) $result['ref'] : null;

        if (!empty($result['paid'])) {
            $expected = (int) $order['total_kurus'];
            $amount = $result['amount_kurus'] ?? null;
            $mode = $result['amount_mode'] ?? 'exact';
            $currency = (string) ($result['currency'] ?? 'TRY');
            $amountOk = $amount === null
                || ($mode === 'min' ? (int) $amount >= $expected : (int) $amount === $expected);
            if (!$amountOk || !in_array(strtoupper($currency), ['', 'TRY', 'TL'], true)) {
                Log::error('payment', 'Paid amount does not match the order total', $context + ['expected_kurus' => $expected, 'reported_kurus' => $amount, 'currency' => $currency]);
                $orders->markFailed((int) $order['id'], $ref, json_encode(($result['payload'] ?? []) + ['error' => 'amount-mismatch', 'expected_kurus' => $expected, 'reported_kurus' => $amount], JSON_UNESCAPED_UNICODE) ?: null);
                return ['outcome' => 'mismatch', 'order' => $orders->find((int) $order['id'])];
            }
            if ($orders->markPaid((int) $order['id'], $ref, $payload)) {
                Log::info('payment', 'Order paid', $context);
                $fresh = $orders->find((int) $order['id']);
                if ($fresh !== null && class_exists(OrderNotifier::class)) {
                    OrderNotifier::paid($fresh);
                }
                return ['outcome' => 'paid', 'order' => $fresh];
            }
            return ['outcome' => 'duplicate', 'order' => $orders->find((int) $order['id'])];
        }

        $orders->markFailed((int) $order['id'], $ref, $payload);
        Log::info('payment', 'Payment failed', $context);
        return ['outcome' => 'failed', 'order' => $orders->find((int) $order['id'])];
    }
}
