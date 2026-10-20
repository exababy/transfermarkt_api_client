<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Core\Request;
use App\Services\Shop\Shop;

/**
 * Shopier "Shopier ile Ödeme Al" API module (merchant API key + API secret from the Shopier panel:
 * Entegrasyonlar › Modül Yönetimi). The buyer is sent to Shopier with an auto-submitted, signed form POST;
 * Shopier posts the result back (browser redirect) to the "Geri dönüş URL'si" configured in the Shopier panel
 * for the chosen website index => https://cr7.com.tr/odeme/shopier/donus.
 *
 * Why this approach (checked 2026-10): Shopier's newer REST API (https://developer.shopier.com/, Personal Access
 * Token / OAuth) manages the merchant's own Shopier store (products, orders, payouts, webhooks) and offers no
 * checkout for orders created on an external site; payments for custom sites still go through the signed form of
 * the API module, as implemented by Shopier's PHP SDK (https://github.com/shopier/api →
 * https://github.com/erkineren/shopier, Shopier::calculateSignature / ShopierResponse::hasValidSignature).
 *
 *   request  signature = base64(HMAC-SHA256(random_nr . platform_order_id . total_order_value . currency, api_secret))
 *   callback signature = base64(HMAC-SHA256(random_nr . platform_order_id, api_secret)); status = success|failed
 * The amount is part of the signed request, so it cannot be altered by the buyer; the callback has no amount.
 */
final class ShopierGateway extends AbstractGateway
{
    public const PAYMENT_URL = 'https://www.shopier.com/ShowProduct/api_pay4.php';
    /** Currency codes of the API module: 0 = TL, 1 = USD, 2 = EUR. */
    public const CURRENCY_TL = '0';
    public const MODULE_VERSION = '1.0.4';

    /** @var callable(): int */
    public $random;

    public function __construct(array $config = [], ?callable $transport = null)
    {
        parent::__construct($config, $transport);
        $this->random = static fn (): int => random_int(100000, 999999);
    }

    public function key(): string
    {
        return 'shopier';
    }

    public function label(): string
    {
        return 'Shopier ile Öde';
    }

    public function description(): string
    {
        return 'Shopier güvenli ödeme sayfasına yönlendirilirsiniz; kart ile tek çekim veya taksitli ödeme yapabilirsiniz.';
    }

    public function isConfigured(): bool
    {
        return $this->str('api_key') !== '' && $this->str('api_secret') !== '';
    }

    public static function requestSignature(string $randomNr, string $orderId, string $total, string $currency, string $secret): string
    {
        return base64_encode(hash_hmac('sha256', $randomNr . $orderId . $total . $currency, $secret, true));
    }

    public static function callbackSignature(string $randomNr, string $orderId, string $secret): string
    {
        return base64_encode(hash_hmac('sha256', $randomNr . $orderId, $secret, true));
    }

    /** Hidden fields of the payment form (field names as in the Shopier API module / SDK ShopierParams). */
    public function formFields(array $order): array
    {
        [$name, $surname] = self::splitName((string) $order['customer_name']);
        $phone = preg_replace('/\D+/', '', (string) $order['phone']) ?? '';
        $address = trim((string) $order['address'] . ' ' . (string) ($order['district'] ?? ''));
        $total = Shop::decimal(Shop::kurus($order['total']));
        $randomNr = (string) ($this->random)();
        $orderId = (string) $order['order_no'];
        $websiteIndex = (int) ($this->config['website_index'] ?? 1);
        return [
            'API_key' => $this->str('api_key'),
            'website_index' => (string) max(1, min(5, $websiteIndex)),
            'platform_order_id' => $orderId,
            'product_name' => mb_substr((string) $order['product_title'], 0, 120),
            'product_type' => '0', // 0 = physical product
            'buyer_name' => $name,
            'buyer_surname' => $surname,
            'buyer_email' => (string) $order['email'],
            'buyer_account_age' => '0',
            'buyer_id_nr' => '0',
            'buyer_phone' => $phone,
            'billing_address' => $address,
            'billing_city' => (string) $order['city'],
            'billing_country' => 'Turkey',
            'billing_postcode' => '',
            'shipping_address' => $address,
            'shipping_city' => (string) $order['city'],
            'shipping_country' => 'Turkey',
            'shipping_postcode' => '',
            'total_order_value' => $total,
            'currency' => self::CURRENCY_TL,
            'platform' => '0',
            'is_in_frame' => '0',
            'current_language' => '0', // 0 = Türkçe
            'modul_version' => self::MODULE_VERSION,
            'random_nr' => $randomNr,
            'signature' => self::requestSignature($randomNr, $orderId, $total, self::CURRENCY_TL, $this->str('api_secret')),
        ];
    }

    public function start(array $order): array
    {
        if (!$this->isConfigured()) {
            return ['type' => 'error', 'message' => 'Shopier ile ödeme şu anda kullanılamıyor.'];
        }
        return [
            'type' => 'form',
            'action' => self::PAYMENT_URL,
            'fields' => $this->formFields($order),
            'button' => 'Shopier ile güvenli ödemeye geç',
            'csp' => ['form-action' => ['https://www.shopier.com']],
        ];
    }

    public function handleCallback(Request $request): array
    {
        $post = $request->all();
        $orderId = is_string($post['platform_order_id'] ?? null) ? trim($post['platform_order_id']) : '';
        $randomNr = is_scalar($post['random_nr'] ?? null) ? (string) $post['random_nr'] : '';
        $signature = is_string($post['signature'] ?? null) ? $post['signature'] : '';
        $status = is_string($post['status'] ?? null) ? strtolower($post['status']) : '';
        if (!$this->isConfigured() || $orderId === '' || $randomNr === '' || $signature === '') {
            return self::invalid('Shopier callback: missing fields or gateway not configured');
        }
        $expected = self::callbackSignature($randomNr, $orderId, $this->str('api_secret'));
        if (!hash_equals($expected, $signature)) {
            return self::invalid('Shopier callback: bad signature', $orderId);
        }
        $payload = ['gateway' => 'shopier'];
        foreach (['platform_order_id', 'status', 'payment_id', 'installment', 'random_nr'] as $field) {
            if (isset($post[$field]) && is_scalar($post[$field])) {
                $payload[$field] = mb_substr((string) $post[$field], 0, 100);
            }
        }
        $paymentId = is_scalar($post['payment_id'] ?? null) ? (string) $post['payment_id'] : '';
        return [
            'valid' => true,
            'order_no' => $orderId,
            'paid' => $status === 'success',
            'amount_kurus' => null, // not part of the callback; the request amount was signed
            'ref' => 'shopier:' . ($paymentId !== '' ? $paymentId : $randomNr),
            'payload' => $payload,
            'message' => 'Shopier callback status ' . $status,
        ];
    }
}
