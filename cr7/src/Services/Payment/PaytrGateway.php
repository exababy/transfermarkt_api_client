<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Core\Request;
use App\Services\Shop\Shop;
use App\Support\Log;

/**
 * PayTR iFrame API (card payment inside an iframe on /odeme/{key}).
 *
 * Docs (checked 2026-10):
 *   Step 1 — token:        https://dev.paytr.com/iframe-api/iframe-api-1-adim
 *   Step 2 — notification: https://dev.paytr.com/iframe-api/iframe-api-2-adim
 *
 * Step 1: POST https://www.paytr.com/odeme/api/get-token (form-encoded) with
 *   paytr_token = base64(HMAC-SHA256(merchant_id . user_ip . merchant_oid . email . payment_amount . user_basket
 *                                   . no_installment . max_installment . currency . test_mode . merchant_salt, merchant_key))
 *   user_basket = base64(json [[name, "unit price", qty], …]); payment_amount = kuruş integer (34.56 TL => 3456).
 *   Response {"status":"success","token":"…"} => iframe src https://www.paytr.com/odeme/guvenli/{token}
 * Step 2: PayTR POSTs merchant_oid, status (success|failed), total_amount, payment_amount, hash, … to the
 *   "Bildirim URL" (/odeme/paytr/bildirim, set in the PayTR merchant panel). Verify
 *   hash = base64(HMAC-SHA256(merchant_oid . merchant_salt . status . total_amount, merchant_key)) and answer
 *   exactly "OK" (plain text) — also for repeated notifications, which must not be processed twice.
 *
 * merchant_oid must be alphanumeric, so the order number "CR7-AB12CD" travels as "CR7AB12CD".
 */
final class PaytrGateway extends AbstractGateway
{
    public const TOKEN_URL = 'https://www.paytr.com/odeme/api/get-token';
    public const IFRAME_URL = 'https://www.paytr.com/odeme/guvenli/';

    public function key(): string
    {
        return 'paytr';
    }

    public function label(): string
    {
        return 'Kredi / Banka Kartı (PayTR)';
    }

    public function description(): string
    {
        return 'Kart bilgileriniz PayTR güvenli ödeme sayfasında işlenir; taksit seçenekleri PayTR tarafından sunulur.';
    }

    public function isConfigured(): bool
    {
        return $this->str('merchant_id') !== '' && $this->str('merchant_key') !== '' && $this->str('merchant_salt') !== '';
    }

    /** Off unless the admin switches it on: test-mode payments (PayTR test cards) are not real payments. */
    public function testMode(): bool
    {
        return Shop::bool($this->config['test_mode'] ?? false);
    }

    /** "CR7-AB12CD" => "CR7AB12CD" (PayTR allows alphanumerics only). */
    public static function merchantOid(string $orderNo): string
    {
        return (string) preg_replace('/[^A-Za-z0-9]/', '', $orderNo);
    }

    /** "CR7AB12CD" => "CR7-AB12CD" (null when it is not one of our order numbers). */
    public static function orderNoFromOid(string $oid): ?string
    {
        return preg_match('/^CR7([A-Z0-9]{6})$/', strtoupper($oid), $m) === 1 ? 'CR7-' . $m[1] : null;
    }

    /** base64 JSON basket: [["Ürün adı", "1499.90", 1], …] */
    public static function basket(array $lines): string
    {
        $items = [];
        foreach ($lines as [$name, $kurus, $qty]) {
            $items[] = [(string) $name, Shop::decimal((int) $kurus), (int) $qty];
        }
        return base64_encode((string) json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** Documented Step 1 token. */
    public static function token(string $merchantId, string $userIp, string $merchantOid, string $email, int $paymentAmount, string $userBasket,
        int $noInstallment, int $maxInstallment, string $currency, int $testMode, string $merchantKey, string $merchantSalt): string
    {
        $hashStr = $merchantId . $userIp . $merchantOid . $email . $paymentAmount . $userBasket . $noInstallment . $maxInstallment . $currency . $testMode;
        return base64_encode(hash_hmac('sha256', $hashStr . $merchantSalt, $merchantKey, true));
    }

    /** Documented Step 2 notification hash. */
    public static function callbackHash(string $merchantOid, string $status, string $totalAmount, string $merchantKey, string $merchantSalt): string
    {
        return base64_encode(hash_hmac('sha256', $merchantOid . $merchantSalt . $status . $totalAmount, $merchantKey, true));
    }

    /** Form fields of the get-token request (exposed for tests/logging). */
    public function tokenRequest(array $order): array
    {
        $oid = self::merchantOid((string) $order['order_no']);
        $amount = Shop::kurus($order['total']);
        $basket = self::basket(self::basketLines($order));
        $ip = (string) ($order['client_ip'] ?? $order['ip'] ?? '');
        $testMode = $this->testMode() ? 1 : 0;
        $noInstallment = 0;
        $maxInstallment = 0;
        $currency = 'TL';
        $key = (string) $order['access_key'];
        return [
            'merchant_id' => $this->str('merchant_id'),
            'user_ip' => $ip,
            'merchant_oid' => $oid,
            'email' => (string) $order['email'],
            'payment_amount' => $amount,
            'paytr_token' => self::token($this->str('merchant_id'), $ip, $oid, (string) $order['email'], $amount, $basket, $noInstallment,
                $maxInstallment, $currency, $testMode, $this->str('merchant_key'), $this->str('merchant_salt')),
            'user_basket' => $basket,
            'debug_on' => $testMode,
            'no_installment' => $noInstallment,
            'max_installment' => $maxInstallment,
            'user_name' => mb_substr((string) $order['customer_name'], 0, 60),
            'user_address' => mb_substr(trim((string) $order['address'] . ' ' . (string) ($order['district'] ?? '') . ' / ' . (string) $order['city']), 0, 400),
            'user_phone' => mb_substr((string) $order['phone'], 0, 20),
            'merchant_ok_url' => url('/odeme/sonuc/' . $key),
            'merchant_fail_url' => url('/odeme/sonuc/' . $key . '?durum=basarisiz'),
            'timeout_limit' => 30,
            'currency' => $currency,
            'test_mode' => $testMode,
            'lang' => 'tr',
        ];
    }

    public function start(array $order): array
    {
        if (!$this->isConfigured()) {
            return ['type' => 'error', 'message' => 'PayTR ile ödeme şu anda kullanılamıyor.'];
        }
        $fields = $this->tokenRequest($order);
        $response = $this->post(self::TOKEN_URL, http_build_query($fields), ['Content-Type' => 'application/x-www-form-urlencoded']);
        $data = json_decode($response['body'], true);
        $logFields = $fields;
        unset($logFields['paytr_token'], $logFields['user_address'], $logFields['user_phone']);
        if (is_array($data) && ($data['status'] ?? '') === 'success' && is_string($data['token'] ?? null) && $data['token'] !== '') {
            Log::info('payment', 'PayTR token received', ['order_no' => $order['order_no'], 'test_mode' => $fields['test_mode']]);
            return [
                'type' => 'iframe',
                'url' => self::IFRAME_URL . rawurlencode($data['token']),
                'height' => 720,
                'title' => 'PayTR güvenli ödeme formu',
                // The card's 3-D Secure page (bank host) is loaded inside the PayTR iframe and is checked
                // against this page's frame-src, so any HTTPS host is allowed in frames on this page only.
                'csp' => ['frame-src' => ['https://www.paytr.com', 'https:']],
            ];
        }
        Log::error('payment', 'PayTR get-token failed', [
            'order_no' => $order['order_no'],
            'http_status' => $response['status'],
            'error' => $response['error'],
            'reason' => is_array($data) ? ($data['reason'] ?? null) : mb_substr($response['body'], 0, 300),
            'request' => $logFields,
        ]);
        return ['type' => 'error', 'message' => 'PayTR ödeme formu şu anda açılamadı. Lütfen birkaç dakika sonra tekrar deneyin veya farklı bir ödeme yöntemi için bizimle iletişime geçin.'];
    }

    public function handleCallback(Request $request): array
    {
        $post = $request->all();
        $oid = is_string($post['merchant_oid'] ?? null) ? $post['merchant_oid'] : '';
        $status = is_string($post['status'] ?? null) ? $post['status'] : '';
        $total = is_string($post['total_amount'] ?? null) ? $post['total_amount'] : '';
        $hash = is_string($post['hash'] ?? null) ? $post['hash'] : '';
        if (!$this->isConfigured() || $oid === '' || $hash === '') {
            return self::invalid('PayTR notification: missing fields or gateway not configured');
        }
        $expected = self::callbackHash($oid, $status, $total, $this->str('merchant_key'), $this->str('merchant_salt'));
        if (!hash_equals($expected, $hash)) {
            return self::invalid('PayTR notification failed: bad hash', self::orderNoFromOid($oid));
        }
        $paymentAmount = is_string($post['payment_amount'] ?? null) && ctype_digit($post['payment_amount']) ? (int) $post['payment_amount'] : null;
        $payload = array_intersect_key($post, array_flip([
            'merchant_oid', 'status', 'total_amount', 'payment_amount', 'payment_type', 'currency', 'test_mode',
            'installment_count', 'failed_reason_code', 'failed_reason_msg',
        ]));
        // A test-mode transaction (PayTR's public test cards) is no payment: book it only while the shop is
        // deliberately in test mode (e.g. a token issued before the admin switched test mode off).
        $testPayment = (string) ($post['test_mode'] ?? '0') === '1';
        if ($status === 'success' && $testPayment && !$this->testMode()) {
            Log::warning('payment', 'PayTR test-mode notification ignored: the gateway is in live mode', ['merchant_oid' => mb_substr($oid, 0, 64)]);
            $status = 'test';
            $payload['failed_reason_msg'] = 'test_mode=1 bildirimi canlı modda kabul edilmedi';
        }
        return [
            'valid' => true,
            'order_no' => self::orderNoFromOid($oid),
            'paid' => $status === 'success',
            // payment_amount = the amount we requested; total_amount may be higher (installment interest).
            'amount_kurus' => $paymentAmount ?? (ctype_digit($total) ? (int) $total : null),
            'amount_mode' => $paymentAmount !== null ? 'exact' : 'min',
            'ref' => 'paytr:' . $oid,
            'payload' => ['gateway' => 'paytr'] + array_map(static fn ($v): string => mb_substr((string) $v, 0, 200), $payload),
            'message' => $status === 'success' ? 'PayTR payment success' : 'PayTR payment failed: ' . mb_substr((string) ($payload['failed_reason_msg'] ?? ''), 0, 200),
        ];
    }
}
