<?php

declare(strict_types=1);

namespace App\Services\Shop;

use App\Core\App;
use App\Core\Settings;
use App\Services\Payment\PaymentManager;

/**
 * Shop-wide settings and label maps (Turkish UI texts) shared by controllers, templates and services.
 *
 * Settings (admin "Ödeme Ayarları", fall back to config 'shop.*'):
 *   shop.enabled         shop pages visible (default true)
 *   shop.orders_enabled  ordering open (default FALSE — product pages show "Yakında satışta")
 *   shop.shipping_fee    flat shipping fee per order in TL (0 = free)
 *   shop.whatsapp        WhatsApp number for the "Haber ver" link (digits, e.g. 905xxxxxxxxx)
 *   shop.notify_email    new-order / payment notification address
 *   shop.company_*       seller identity printed on the legal pages (Mesafeli Satış Sözleşmesi …)
 */
final class Shop
{
    public const MAX_QUANTITY = 5;
    public const PRINT_NAME_MAX = 12;
    public const RATE_LIMIT_PER_HOUR = 5;

    /** Order fulfilment states (orders.status). */
    public const STATUSES = [
        'yeni' => 'Yeni',
        'onaylandi' => 'Onaylandı',
        'hazirlaniyor' => 'Hazırlanıyor',
        'kargoda' => 'Kargoda',
        'tamamlandi' => 'Tamamlandı',
        'iptal' => 'İptal edildi',
    ];

    /** Payment states (orders.payment_status). */
    public const PAYMENT_STATUSES = [
        'bekliyor' => 'Ödeme bekleniyor',
        'odendi' => 'Ödendi',
        'basarisiz' => 'Ödeme başarısız',
        'iade' => 'İade edildi',
    ];

    public const KIT_TYPES = [
        'ic-saha' => 'İç Saha',
        'deplasman' => 'Deplasman',
        'ucuncu' => 'Üçüncü Forma',
        'retro' => 'Retro',
        'ozel' => 'Özel Seri',
    ];

    /** Company identity keys for the legal pages => admin label. */
    public const COMPANY_FIELDS = [
        'company_name' => 'Satıcı unvanı / ad soyad',
        'company_address' => 'Açık adres',
        'company_phone' => 'Telefon',
        'company_email' => 'E-posta',
        'company_tax_office' => 'Vergi dairesi',
        'company_tax_number' => 'Vergi / T.C. kimlik no',
        'company_mersis' => 'MERSİS no',
    ];

    /** The 81 provinces of Türkiye (order form city select, alphabetical in Turkish). */
    public const CITIES = [
        'Adana', 'Adıyaman', 'Afyonkarahisar', 'Ağrı', 'Aksaray', 'Amasya', 'Ankara', 'Antalya', 'Ardahan', 'Artvin',
        'Aydın', 'Balıkesir', 'Bartın', 'Batman', 'Bayburt', 'Bilecik', 'Bingöl', 'Bitlis', 'Bolu', 'Burdur', 'Bursa',
        'Çanakkale', 'Çankırı', 'Çorum', 'Denizli', 'Diyarbakır', 'Düzce', 'Edirne', 'Elazığ', 'Erzincan', 'Erzurum',
        'Eskişehir', 'Gaziantep', 'Giresun', 'Gümüşhane', 'Hakkâri', 'Hatay', 'Iğdır', 'Isparta', 'İstanbul', 'İzmir',
        'Kahramanmaraş', 'Karabük', 'Karaman', 'Kars', 'Kastamonu', 'Kayseri', 'Kilis', 'Kırıkkale', 'Kırklareli',
        'Kırşehir', 'Kocaeli', 'Konya', 'Kütahya', 'Malatya', 'Manisa', 'Mardin', 'Mersin', 'Muğla', 'Muş', 'Nevşehir',
        'Niğde', 'Ordu', 'Osmaniye', 'Rize', 'Sakarya', 'Samsun', 'Şanlıurfa', 'Siirt', 'Sinop', 'Şırnak', 'Sivas',
        'Tekirdağ', 'Tokat', 'Trabzon', 'Tunceli', 'Uşak', 'Van', 'Yalova', 'Yozgat', 'Zonguldak',
    ];

    public static function enabled(): bool
    {
        return self::bool(Settings::get('shop.enabled', true));
    }

    /** The admin switch only (see ordersOpen() for the effective state). */
    public static function ordersEnabled(): bool
    {
        return self::bool(Settings::get('shop.orders_enabled', false));
    }

    /** Orders can actually be placed: shop visible, ordering switched on and at least one payment method available. */
    public static function ordersOpen(?PaymentManager $payments = null): bool
    {
        if (!self::enabled() || !self::ordersEnabled()) {
            return false;
        }
        return ($payments ?? new PaymentManager())->available() !== [];
    }

    /** Flat shipping fee in kuruş (never negative). */
    public static function shippingFeeKurus(): int
    {
        $fee = Settings::get('shop.shipping_fee', 0);
        $fee = is_numeric($fee) ? (float) $fee : 0.0;
        return max(0, (int) round($fee * 100));
    }

    /** WhatsApp number as digits only ('' when not configured). */
    public static function whatsapp(): string
    {
        $digits = preg_replace('/\D+/', '', (string) Settings::get('shop.whatsapp', '')) ?? '';
        if (str_starts_with($digits, '0')) {
            $digits = '90' . substr($digits, 1);
        }
        return strlen($digits) >= 10 ? $digits : '';
    }

    /** wa.me link with a pre-filled message, or null when no number is configured. */
    public static function whatsappLink(string $message): ?string
    {
        $number = self::whatsapp();
        return $number === '' ? null : 'https://wa.me/' . $number . '?text=' . rawurlencode($message);
    }

    public static function notifyEmail(): string
    {
        $email = trim((string) Settings::get('shop.notify_email', ''));
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : '';
    }

    /** @return array<string, string> company_* key => value ('' when not filled in) */
    public static function company(): array
    {
        $out = [];
        foreach (array_keys(self::COMPANY_FIELDS) as $key) {
            $value = Settings::get('shop.' . $key, '');
            $out[$key] = is_scalar($value) ? trim((string) $value) : '';
        }
        return $out;
    }

    public static function statusLabel(string $status): string
    {
        return self::STATUSES[$status] ?? $status;
    }

    public static function paymentStatusLabel(string $status): string
    {
        return self::PAYMENT_STATUSES[$status] ?? $status;
    }

    public static function kitTypeLabel(string $type): string
    {
        return self::KIT_TYPES[$type] ?? $type;
    }

    /** Badge modifier class for a payment state (public app.css: badge-green/badge-red/badge-muted). */
    public static function paymentBadge(string $status): string
    {
        return match ($status) {
            'odendi' => 'badge-green',
            'basarisiz' => 'badge-red',
            'iade' => 'badge-muted',
            default => '',
        };
    }

    /** Price (float/decimal string) => kuruş integer. */
    public static function kurus(int|float|string|null $amount): int
    {
        return is_numeric($amount) ? (int) round(((float) $amount) * 100) : 0;
    }

    /** Kuruş => decimal string '1499.90' (DB / gateway format). */
    public static function decimal(int $kurus): string
    {
        return number_format($kurus / 100, 2, '.', '');
    }

    /** Root-relative URL of a product image path ('/assets/...' or '/uploads/...'), with app URL prefix. */
    public static function imageUrl(?string $path): string
    {
        $path = trim((string) $path);
        if ($path === '') {
            return App::urlPrefix() . '/assets/img/og-default.png';
        }
        if (preg_match('#^https?://#i', $path) === 1) {
            return $path;
        }
        return App::urlPrefix() . '/' . ltrim($path, '/');
    }

    /** Default illustration path for a product slug (back view), or null if the file is missing. */
    public static function kitPath(string $slug, string $suffix = ''): ?string
    {
        $relative = 'assets/img/kits/' . $slug . $suffix . '.svg';
        return is_file(App::basePath('public/' . $relative)) ? '/' . $relative : null;
    }

    /** Default back number for the print option (catalogue kit number, e.g. 28 for Sporting CP 2002–03; else 7). */
    public static function defaultNumber(string $slug): string
    {
        $kit = class_exists(\App\Install\Seeders\ProductSeeder::class) ? \App\Install\Seeders\ProductSeeder::kitFor($slug) : null;
        return (string) ($kit['number'] ?? '7');
    }

    /** Display text where "2016–17" never breaks at the dash (word joiners around en dashes between digits). */
    public static function nobr(string $text): string
    {
        return (string) preg_replace('/(\d)–(\d)/u', "$1\u{2060}–\u{2060}$2", $text);
    }

    public static function bool(mixed $value): bool
    {
        if (is_string($value)) {
            return !in_array(strtolower(trim($value)), ['', '0', 'false', 'off', 'no', 'hayir', 'hayır'], true);
        }
        return (bool) $value;
    }
}
