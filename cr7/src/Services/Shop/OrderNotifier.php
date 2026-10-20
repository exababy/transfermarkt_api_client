<?php

declare(strict_types=1);

namespace App\Services\Shop;

use App\Core\Config;
use App\Support\Log;

/**
 * Best-effort e-mail notifications to the shop owner (setting shop.notify_email) via PHP mail().
 * Never throws and never blocks an order; disabled when no address is configured.
 */
final class OrderNotifier
{
    /** @var callable(string $to, string $subject, string $body, string $headers): bool|null test hook */
    public static $sender = null;

    public static function created(array $order): void
    {
        self::send(
            'Yeni sipariş: ' . $order['order_no'],
            "Yeni bir forma siparişi alındı.\n\n" . self::summary($order)
        );
    }

    public static function paid(array $order): void
    {
        self::send(
            'Ödeme alındı: ' . $order['order_no'],
            'Siparişin ödemesi onaylandı (' . $order['payment_method'] . ").\n\n" . self::summary($order)
        );
    }

    /** A gateway reported a payment that must be confirmed by hand (Shopier return). */
    public static function review(array $order): void
    {
        $paymentId = (string) ($order['payload']['payment_id'] ?? '');
        self::send(
            'Ödeme kontrolü gerekiyor: ' . $order['order_no'],
            'Müşteri ' . $order['payment_method'] . ' ödeme sayfasından "başarılı" dönüşüyle geldi'
            . ($paymentId !== '' ? ' (bildirilen ödeme no: ' . $paymentId . ')' : '') . ".\n"
            . "Bu dönüş ödemeyi kanıtlamaz: ödemeyi sağlayıcının satıcı panelinde görüp tutarı kontrol ettikten sonra\n"
            . "yönetim panelinde \"Ödeme alındı olarak işaretle\" ile onaylayın.\n\n" . self::summary($order)
        );
    }

    private static function summary(array $order): string
    {
        $lines = [
            'Sipariş no: ' . $order['order_no'],
            'Ürün: ' . $order['product_title'] . ' / Beden: ' . $order['size'] . ' / Adet: ' . $order['quantity'],
        ];
        if (!empty($order['print_name']) || !empty($order['print_number'])) {
            $lines[] = 'Baskı: ' . trim($order['print_name'] . ' ' . $order['print_number']);
        }
        $lines[] = 'Toplam: ' . fmt_price($order['total']);
        $lines[] = 'Ödeme: ' . $order['payment_method'] . ' (' . Shop::paymentStatusLabel((string) $order['payment_status']) . ')';
        $lines[] = 'Müşteri: ' . $order['customer_name'] . ' · ' . $order['phone'] . ' · ' . $order['email'];
        $lines[] = 'Adres: ' . $order['address'] . ' ' . ($order['district'] ?? '') . ' / ' . $order['city'];
        $lines[] = '';
        $lines[] = 'Yönetim: ' . url('/admin/siparisler/' . (int) $order['id']);
        return implode("\n", $lines) . "\n";
    }

    private static function send(string $subject, string $body): void
    {
        $to = Shop::notifyEmail();
        if ($to === '') {
            return;
        }
        $host = (string) (parse_url((string) Config::get('app.url', ''), PHP_URL_HOST) ?: 'localhost');
        $headers = implode("\r\n", [
            'From: CR7.com.tr <no-reply@' . $host . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ]);
        $encodedSubject = function_exists('mb_encode_mimeheader') ? mb_encode_mimeheader($subject, 'UTF-8', 'B') : $subject;
        try {
            $ok = self::$sender !== null
                ? (bool) (self::$sender)($to, $encodedSubject, $body, $headers)
                : (function_exists('mail') && @mail($to, $encodedSubject, $body, $headers));
            if (!$ok) {
                Log::warning('shop', 'Order notification e-mail could not be sent', ['subject' => $subject]);
            }
        } catch (\Throwable $e) {
            Log::warning('shop', 'Order notification e-mail failed: ' . $e->getMessage());
        }
    }
}
