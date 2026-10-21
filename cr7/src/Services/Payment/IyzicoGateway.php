<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Core\Request;
use App\Services\Shop\Shop;
use App\Support\Date;
use App\Support\Log;

/**
 * iyzico Checkout Form (Ödeme Formu) without the SDK: plain curl + IYZWSv2 HMAC-SHA256 authentication.
 *
 * Docs (checked 2026-10):
 *   Authentication: https://docs.iyzico.com/on-hazirliklar/kimlik-dogrulama/hmacsha256-kimlik-dogrulama
 *   CF initialize:  https://docs.iyzico.com/odeme-metotlari/odeme-formu/cf-entegrasyonu/cf-baslatma
 *   CF retrieve:    https://docs.iyzico.com/odeme-metotlari/odeme-formu/cf-entegrasyonu/cf-sorgulama
 *   Response signature: https://docs.iyzico.com/en/advanced/response-signature-validation
 *   Reference SDK (header + price formatting): https://github.com/iyzico/iyzipay-php (IyziAuthV2Generator, RequestFormatter)
 *
 * Authorization: "IYZWSv2 " . base64("apiKey:{apiKey}&randomKey:{rnd}&signature:{hex(HMAC-SHA256(rnd . uriPath . jsonBody, secretKey))}")
 *   with the same random key also sent as the x-iyzi-rnd header.
 * Flow: initialize (basketId = conversationId = order_no, callbackUrl = /odeme/iyzico/donus) => checkoutFormContent
 *   (script snippet rendered on /odeme/{key}) + token; iyzico POSTs `token` back to callbackUrl; we retrieve the
 *   result server-side and trust only that response (paymentStatus SUCCESS, basketId, price, response signature).
 * Base URLs: sandbox https://sandbox-api.iyzipay.com, live https://api.iyzipay.com.
 */
final class IyzicoGateway extends AbstractGateway
{
    public const SANDBOX_URL = 'https://sandbox-api.iyzipay.com';
    public const LIVE_URL = 'https://api.iyzipay.com';
    public const INITIALIZE_PATH = '/payment/iyzipos/checkoutform/initialize/auth/ecom';
    public const RETRIEVE_PATH = '/payment/iyzipos/checkoutform/auth/ecom/detail';

    /**
     * iyzico requires buyer.identityNumber; the shop does not collect T.C. kimlik numbers (data minimisation),
     * so the placeholder accepted by iyzico for non-invoice-relevant flows is sent.
     */
    public const IDENTITY_PLACEHOLDER = '11111111111';

    /** @var callable(): string random key generator (tests make it deterministic) */
    public $randomKey;

    public function __construct(array $config = [], ?callable $transport = null)
    {
        parent::__construct($config, $transport);
        $this->randomKey = static fn (): string => (string) (int) (microtime(true) * 1000) . bin2hex(random_bytes(4));
    }

    public function key(): string
    {
        return 'iyzico';
    }

    public function label(): string
    {
        return 'Kredi / Banka Kartı (iyzico)';
    }

    public function description(): string
    {
        return 'iyzico güvenli ödeme formu ile kartla veya iyzico hesabınızla ödeme yapabilirsiniz.';
    }

    public function isConfigured(): bool
    {
        return $this->str('api_key') !== '' && $this->str('secret_key') !== '';
    }

    public function sandbox(): bool
    {
        return Shop::bool($this->config['sandbox'] ?? true);
    }

    public function baseUrl(): string
    {
        return $this->sandbox() ? self::SANDBOX_URL : self::LIVE_URL;
    }

    /** IYZWSv2 Authorization header value for a request path + exact JSON body. */
    public static function authorization(string $apiKey, string $secretKey, string $randomKey, string $uriPath, string $body): string
    {
        $signature = bin2hex(hash_hmac('sha256', $randomKey . $uriPath . $body, $secretKey, true));
        return 'IYZWSv2 ' . base64_encode('apiKey:' . $apiKey . '&randomKey:' . $randomKey . '&signature:' . $signature);
    }

    /** iyzico request price format (SDK RequestFormatter): 1499.90 => "1499.9", 250 => "250.0". */
    public static function price(int $kurus): string
    {
        $value = rtrim(rtrim(Shop::decimal($kurus), '0'), '.');
        return str_contains($value, '.') ? $value : $value . '.0';
    }

    /** Response-signature price format: trailing zeros and a bare decimal point removed (10.50 => "10.5", 10.0 => "10"). */
    public static function signaturePrice(mixed $value): string
    {
        if (!is_numeric($value)) {
            return (string) $value;
        }
        $formatted = is_string($value) && str_contains($value, '.') ? $value : number_format((float) $value, 8, '.', '');
        if (str_contains($formatted, '.')) {
            $formatted = rtrim(rtrim($formatted, '0'), '.');
        }
        return $formatted;
    }

    /** hex(HMAC-SHA256(join(':', params), secretKey)) — iyzico response signature. */
    public static function responseSignature(array $params, string $secretKey): string
    {
        return bin2hex(hash_hmac('sha256', implode(':', array_map('strval', $params)), $secretKey, true));
    }

    /** JSON body of the initialize call (exposed for tests/logging). */
    public function initializeRequest(array $order): array
    {
        [$name, $surname] = self::splitName((string) $order['customer_name']);
        $city = (string) $order['city'];
        $address = trim((string) $order['address'] . ' ' . (string) ($order['district'] ?? '')) . ' ' . $city;
        $items = [];
        $sum = 0;
        foreach (self::basketLines($order) as $i => [$lineName, $kurus, $qty]) {
            $lineTotal = (int) $kurus * (int) $qty;
            $sum += $lineTotal;
            $items[] = [
                'id' => $i === 0 ? 'P' . (int) $order['product_id'] : 'KARGO',
                'price' => self::price($lineTotal),
                'name' => $qty > 1 ? $qty . ' x ' . $lineName : $lineName,
                'category1' => $i === 0 ? 'Forma' : 'Kargo',
                'category2' => $i === 0 ? 'Futbol' : 'Teslimat',
                'itemType' => 'PHYSICAL',
            ];
        }
        $now = Date::now();
        return [
            'locale' => 'tr',
            'conversationId' => (string) $order['order_no'],
            'price' => self::price($sum),
            'paidPrice' => self::price(Shop::kurus($order['total'])),
            'currency' => 'TRY',
            'basketId' => (string) $order['order_no'],
            'paymentGroup' => 'PRODUCT',
            'callbackUrl' => url('/odeme/iyzico/donus'),
            'enabledInstallments' => [1, 2, 3, 6, 9],
            'buyer' => [
                'id' => 'C' . (int) $order['id'],
                'name' => $name,
                'surname' => $surname,
                'gsmNumber' => self::internationalPhone((string) $order['phone']),
                'email' => (string) $order['email'],
                'identityNumber' => self::IDENTITY_PLACEHOLDER,
                'registrationDate' => (string) ($order['created_at'] ?? $now),
                'lastLoginDate' => $now,
                'registrationAddress' => $address,
                'ip' => (string) ($order['client_ip'] ?? $order['ip'] ?? ''),
                'city' => $city,
                'country' => 'Turkey',
            ],
            'shippingAddress' => ['contactName' => (string) $order['customer_name'], 'city' => $city, 'country' => 'Turkey', 'address' => $address],
            'billingAddress' => ['contactName' => (string) $order['customer_name'], 'city' => $city, 'country' => 'Turkey', 'address' => $address],
            'basketItems' => $items,
        ];
    }

    /** Signed JSON POST to the iyzico API; returns [decoded body|null, raw response]. */
    private function call(string $path, array $payload): array
    {
        $body = (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $rnd = ($this->randomKey)();
        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'Authorization' => self::authorization($this->str('api_key'), $this->str('secret_key'), $rnd, $path, $body),
            'x-iyzi-rnd' => $rnd,
            'x-iyzi-client-version' => 'cr7-php-1.0',
        ];
        $response = $this->post($this->baseUrl() . $path, $body, $headers);
        $data = json_decode($response['body'], true);
        return [is_array($data) ? $data : null, $response];
    }

    public function start(array $order): array
    {
        if (!$this->isConfigured()) {
            return ['type' => 'error', 'message' => 'iyzico ile ödeme şu anda kullanılamıyor.'];
        }
        [$data, $response] = $this->call(self::INITIALIZE_PATH, $this->initializeRequest($order));
        $content = is_array($data) ? (string) ($data['checkoutFormContent'] ?? '') : '';
        if (is_array($data) && ($data['status'] ?? '') === 'success' && $content !== '' && !empty($data['token'])) {
            // Response signature (conversationId:token) when iyzico sends one.
            if (isset($data['signature']) && is_string($data['signature'])) {
                $expected = self::responseSignature([(string) ($data['conversationId'] ?? ''), (string) $data['token']], $this->str('secret_key'));
                if (!hash_equals($expected, strtolower($data['signature']))) {
                    Log::error('payment', 'iyzico initialize: bad response signature', ['order_no' => $order['order_no']]);
                    return ['type' => 'error', 'message' => 'iyzico ödeme formu doğrulanamadı. Lütfen daha sonra tekrar deneyin.'];
                }
            }
            Log::info('payment', 'iyzico checkout form initialized', ['order_no' => $order['order_no'], 'sandbox' => $this->sandbox()]);
            $hosts = ['https://*.iyzipay.com', 'https://*.iyzico.com'];
            return [
                'type' => 'checkout',
                'token' => (string) $data['token'],
                'html' => $content,
                'page_url' => is_string($data['paymentPageUrl'] ?? null) && str_starts_with($data['paymentPageUrl'], 'https://') ? $data['paymentPageUrl'] : null,
                // The snippet loads https://(sandbox-)static.iyzipay.com/checkoutform/v2/bundle.js, which talks to the
                // iyzico API and hands 3-D Secure over to the card's bank (any HTTPS host), hence `https:` for
                // frames and form posts on this one page only.
                'csp' => [
                    'script-src' => $hosts,
                    'style-src' => $hosts,
                    'connect-src' => $hosts,
                    'font-src' => $hosts,
                    'frame-src' => [...$hosts, 'https:'],
                    'form-action' => [...$hosts, 'https:'],
                ],
            ];
        }
        Log::error('payment', 'iyzico initialize failed', [
            'order_no' => $order['order_no'],
            'http_status' => $response['status'],
            'error' => $response['error'],
            'error_code' => is_array($data) ? ($data['errorCode'] ?? null) : null,
            'error_message' => is_array($data) ? ($data['errorMessage'] ?? null) : mb_substr($response['body'], 0, 300),
        ]);
        return ['type' => 'error', 'message' => 'iyzico ödeme formu şu anda açılamadı. Lütfen birkaç dakika sonra tekrar deneyin veya farklı bir ödeme yöntemi için bizimle iletişime geçin.'];
    }

    /** iyzico POSTs `token` (browser redirect); the result is fetched from the API, never taken from the POST. */
    public function handleCallback(Request $request): array
    {
        $token = $request->input('token');
        if (!is_string($token) || preg_match('/^[A-Za-z0-9-]{8,128}$/', $token) !== 1) {
            return self::invalid('iyzico callback without a valid token');
        }
        if (!$this->isConfigured()) {
            return self::invalid('iyzico callback but gateway not configured');
        }
        [$data, $response] = $this->call(self::RETRIEVE_PATH, ['locale' => 'tr', 'token' => $token]);
        if (!is_array($data)) {
            return self::invalid('iyzico retrieve failed: HTTP ' . $response['status'] . ' ' . (string) $response['error']);
        }
        $orderNo = is_string($data['basketId'] ?? null) ? $data['basketId'] : null;
        if (isset($data['signature']) && is_string($data['signature'])) {
            $expected = self::responseSignature([
                (string) ($data['paymentStatus'] ?? ''),
                (string) ($data['paymentId'] ?? ''),
                (string) ($data['currency'] ?? ''),
                (string) ($data['basketId'] ?? ''),
                (string) ($data['conversationId'] ?? ''),
                self::signaturePrice($data['paidPrice'] ?? ''),
                self::signaturePrice($data['price'] ?? ''),
                (string) ($data['token'] ?? $token),
            ], $this->str('secret_key'));
            if (!hash_equals($expected, strtolower($data['signature']))) {
                return self::invalid('iyzico retrieve: bad response signature', $orderNo);
            }
        }
        if (($data['status'] ?? '') !== 'success') {
            // Failed lookups (e.g. expired token) carry no basket data; nothing is changed.
            return ['token' => $token] + self::invalid('iyzico retrieve status ' . (string) ($data['status'] ?? '?') . ': ' . (string) ($data['errorMessage'] ?? ''), $orderNo);
        }
        if ((string) ($data['conversationId'] ?? $orderNo) !== (string) $orderNo) {
            return self::invalid('iyzico retrieve: conversationId/basketId mismatch', $orderNo);
        }
        $success = ($data['paymentStatus'] ?? '') === 'SUCCESS';
        $fraud = (string) ($data['fraudStatus'] ?? '1');
        $paid = $success && $fraud === '1';
        $pending = $success && $fraud === '0'; // under fraud review: leave the order waiting
        $price = is_numeric($data['price'] ?? null) ? (int) round(((float) $data['price']) * 100) : null;
        $paidPrice = is_numeric($data['paidPrice'] ?? null) ? (int) round(((float) $data['paidPrice']) * 100) : null;
        $payload = ['gateway' => 'iyzico'];
        foreach (['status', 'paymentStatus', 'paymentId', 'price', 'paidPrice', 'currency', 'installment', 'basketId', 'conversationId',
            'fraudStatus', 'cardAssociation', 'cardFamily', 'cardType', 'errorCode', 'errorMessage', 'mdStatus'] as $field) {
            if (isset($data[$field]) && is_scalar($data[$field])) {
                $payload[$field] = mb_substr((string) $data[$field], 0, 200);
            }
        }
        $payload['token'] = $token;
        return [
            'valid' => true,
            'order_no' => $orderNo,
            'paid' => $paid,
            'pending' => $pending,
            'token' => $token,
            // price = basket total we sent; paidPrice may include installment interest (>= price).
            'amount_kurus' => $price,
            'amount_mode' => 'exact',
            'paid_kurus' => $paidPrice,
            'currency' => (string) ($data['currency'] ?? ''),
            'ref' => isset($data['paymentId']) ? 'iyzico:' . $data['paymentId'] : 'iyzico:' . $token,
            'payload' => $payload,
            'message' => $paid ? 'iyzico payment success' : 'iyzico payment not successful: ' . (string) ($data['paymentStatus'] ?? '') . ' ' . (string) ($data['errorMessage'] ?? ''),
        ];
    }
}
