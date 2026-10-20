<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Core\Request;
use App\Services\Shop\Shop;
use App\Support\Http;

/**
 * Shared plumbing for the gateways: merged config (config 'payment.<key>' + Settings overrides),
 * an injectable HTTP transport (tests capture outgoing requests) and small helpers.
 */
abstract class AbstractGateway implements PaymentGateway
{
    /** @var callable(string, string, array<string, string>): array{status: int, body: string, error: ?string} */
    protected $transport;

    /** @var list<array{url: string, body: string, headers: array<string, string>}> requests sent (debug/tests) */
    public array $sent = [];

    /**
     * @param array<string, mixed> $config gateway config (enabled, credentials, test flags)
     * @param callable|null $transport fn(string $url, string $body, array $headers): array like Http::post()
     */
    public function __construct(protected array $config = [], ?callable $transport = null)
    {
        $this->transport = $transport ?? static fn (string $url, string $body, array $headers): array => Http::post($url, $body, $headers, 30);
    }

    public function isAvailable(): bool
    {
        return Shop::bool($this->config['enabled'] ?? false) && $this->isConfigured();
    }

    public function handleCallback(Request $request): array
    {
        return self::invalid('Bu ödeme yöntemi geri bildirim almaz.');
    }

    /** @return array<string, mixed> */
    public function config(): array
    {
        return $this->config;
    }

    protected function str(string $key): string
    {
        $value = $this->config[$key] ?? '';
        return is_scalar($value) ? trim((string) $value) : '';
    }

    /** POST through the transport and remember the request. */
    protected function post(string $url, string $body, array $headers): array
    {
        $this->sent[] = ['url' => $url, 'body' => $body, 'headers' => $headers];
        $response = ($this->transport)($url, $body, $headers);
        return [
            'status' => (int) ($response['status'] ?? 0),
            'body' => (string) ($response['body'] ?? ''),
            'error' => $response['error'] ?? null,
        ];
    }

    /** Callback result for a rejected/unverifiable notification. */
    protected static function invalid(string $message, ?string $orderNo = null): array
    {
        return ['valid' => false, 'order_no' => $orderNo, 'paid' => false, 'amount_kurus' => null, 'ref' => null, 'payload' => [], 'message' => $message];
    }

    /** "Ahmet Can Yılmaz" => ['Ahmet Can', 'Yılmaz'] (single word => [word, word]). */
    protected static function splitName(string $name): array
    {
        $parts = preg_split('/\s+/u', trim($name)) ?: [];
        $parts = array_values(array_filter($parts, static fn (string $p): bool => $p !== ''));
        if (count($parts) < 2) {
            $single = $parts[0] ?? 'Müşteri';
            return [$single, $single];
        }
        $surname = array_pop($parts);
        return [implode(' ', $parts), $surname];
    }

    /** Stored phone 05XXXXXXXXX => +905XXXXXXXXX. */
    protected static function internationalPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }
        if (!str_starts_with($digits, '90')) {
            $digits = '90' . $digits;
        }
        return '+' . $digits;
    }

    /** Line items of an order for basket fields: [['name', kuruş unit price, quantity], …] (+ shipping line). */
    protected static function basketLines(array $order): array
    {
        $title = (string) $order['product_title'] . ' (' . (string) $order['size'] . ')';
        if (!empty($order['print_name']) || !empty($order['print_number'])) {
            $title .= ' + ' . trim((string) $order['print_name'] . ' ' . (string) $order['print_number']);
        }
        $lines = [[mb_substr($title, 0, 120), Shop::kurus($order['unit_price']), (int) $order['quantity']]];
        $shipping = Shop::kurus($order['shipping_fee']);
        if ($shipping > 0) {
            $lines[] = ['Kargo', $shipping, 1];
        }
        return $lines;
    }
}
