<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Seo;
use App\Services\News\SourceRegistry;
use App\Services\Shop\Shop;
use App\Support\Format;

/**
 * Static pages: /hakkinda (about + disclaimer + editorial policy), /gizlilik-politikasi
 * (KVKK aydınlatma metni + çerez politikası + order/payment data) and /iletisim.
 */
final class PageController
{
    /** Last editorial revision of each static page (also used as sitemap lastmod). */
    public const REVISED = ['about' => '2026-10-20', 'privacy' => '2026-10-20', 'contact' => '2026-10-20', 'shop-legal' => '2026-10-20'];

    public function about(Request $request): Response
    {
        $newsSources = [];
        if (class_exists(SourceRegistry::class)) {
            foreach ((array) LifeController::safely(static fn () => SourceRegistry::all(), []) as $source) {
                if (is_array($source) && !empty($source['name'])) {
                    $newsSources[(string) $source['name']] = true;
                }
            }
        }
        $seo = new Seo(
            title: 'Hakkında: Bağımsız Cristiano Ronaldo Hayran Sitesi',
            description: 'CR7.com.tr, Cristiano Ronaldo için hazırlanan bağımsız ve resmî olmayan bir hayran sitesidir. Veri kaynaklarımızı ve haber yayın politikamızı okuyun.',
            canonical: url('/hakkinda'),
            jsonLd: [[
                '@type' => 'AboutPage',
                'name' => 'CR7.com.tr Hakkında',
                'url' => url('/hakkinda'),
                'inLanguage' => 'tr-TR',
                'isPartOf' => ['@id' => url('/') . '#website'],
                'about' => HomeController::organizationSchema(),
                'dateModified' => self::modified('about'),
            ]],
            breadcrumbs: [['name' => 'Hakkında', 'url' => '/hakkinda']],
        );
        return view('pages/about', [
            'seo' => $seo,
            'extraHead' => partial('partials/home-styles', ['page' => 'static']),
            'newsSources' => array_keys($newsSources),
            'dailyLimit' => max(1, (int) setting('news.daily_limit', 10)),
            'email' => self::email(),
            'shopEnabled' => (bool) setting('shop.enabled', true),
            'updated' => self::modified('about'),
        ]);
    }

    public function privacy(Request $request): Response
    {
        $seo = new Seo(
            title: 'Gizlilik Politikası, KVKK Aydınlatma Metni ve Çerezler',
            description: 'CR7.com.tr gizlilik politikası: KVKK kapsamında kişisel verilerin işlenmesi, çerez kullanımı, sipariş ve ödeme verileri (PayTR, iyzico, Shopier) ve haklarınız.',
            canonical: url('/gizlilik-politikasi'),
            jsonLd: [[
                '@type' => 'WebPage',
                'name' => 'Gizlilik Politikası ve KVKK Aydınlatma Metni',
                'url' => url('/gizlilik-politikasi'),
                'inLanguage' => 'tr-TR',
                'isPartOf' => ['@id' => url('/') . '#website'],
                'dateModified' => self::modified('privacy'),
            ]],
            breadcrumbs: [['name' => 'Gizlilik Politikası', 'url' => '/gizlilik-politikasi']],
        );
        return view('pages/privacy', [
            'seo' => $seo,
            'extraHead' => partial('partials/home-styles', ['page' => 'static']),
            'email' => self::email(),
            'siteUrl' => url('/'),
            'analytics' => preg_match('/^G-[A-Z0-9]{4,20}$/', (string) setting('analytics.ga4_id', '')) === 1,
            'updated' => self::modified('privacy'),
        ]);
    }

    public function contact(Request $request): Response
    {
        $email = self::email();
        $whatsapp = self::whatsapp((string) setting('shop.whatsapp', ''));
        $schema = [
            '@type' => 'ContactPage',
            'name' => 'CR7.com.tr İletişim',
            'url' => url('/iletisim'),
            'inLanguage' => 'tr-TR',
            'isPartOf' => ['@id' => url('/') . '#website'],
        ];
        if ($email !== null) {
            $schema['mainEntity'] = [
                '@type' => 'Organization',
                'name' => Seo::siteName(),
                'url' => url('/'),
                'contactPoint' => array_filter([
                    '@type' => 'ContactPoint',
                    'contactType' => 'customer support',
                    'email' => $email,
                    'telephone' => $whatsapp !== null ? '+' . $whatsapp['digits'] : null,
                    'availableLanguage' => ['Turkish'],
                ]),
            ];
        }
        $seo = new Seo(
            title: 'İletişim: CR7.com.tr’ye Ulaşın',
            description: 'CR7.com.tr ile iletişime geçin: içerik düzeltmeleri, telif ve kaldırma talepleri, forma siparişleri ve KVKK başvuruları için e-posta ve WhatsApp bilgileri.',
            canonical: url('/iletisim'),
            jsonLd: [$schema],
            breadcrumbs: [['name' => 'İletişim', 'url' => '/iletisim']],
        );
        return view('pages/contact', [
            'seo' => $seo,
            'extraHead' => partial('partials/home-styles', ['page' => 'static']),
            'email' => $email,
            'whatsapp' => $whatsapp,
            'shopEnabled' => (bool) setting('shop.enabled', true),
        ]);
    }

    // ------------------------------------------------------------------ helpers

    /** app.contact_email when valid. */
    public static function email(): ?string
    {
        $email = trim((string) config('app.contact_email', ''));
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
    }

    /**
     * WhatsApp number (setting shop.whatsapp) => wa.me link; Turkish local formats are normalised
     * (0532… / 532… => 90532…).
     *
     * @return array{digits: string, url: string, display: string}|null
     */
    public static function whatsapp(string $number): ?array
    {
        $digits = Shop::normalizeWhatsapp($number); // same number as the shop's "Haber ver" links
        if ($digits === '') {
            return null;
        }
        $display = '+' . $digits;
        if (strlen($digits) === 12 && str_starts_with($digits, '90')) {
            $display = sprintf('+90 %s %s %s %s', substr($digits, 2, 3), substr($digits, 5, 3), substr($digits, 8, 2), substr($digits, 10, 2));
        }
        return ['digits' => $digits, 'url' => 'https://wa.me/' . $digits, 'display' => $display];
    }

    /** Editorial revision date of a static page ('Y-m-d'; bump when the text changes). */
    public static function modified(string $page): string
    {
        return self::REVISED[$page] ?? self::REVISED['privacy'];
    }

    /** Turkish date of modified(). */
    public static function modifiedLabel(string $date): string
    {
        return Format::date($date . ' 09:00:00', 'j F Y');
    }
}
