<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Seo;
use App\Repositories\ClubRepository;
use App\Repositories\NewsRepository;
use App\Repositories\ProductRepository;
use App\Repositories\StatsRepository;
use App\Repositories\VideoRepository;
use App\Services\Shop\Shop;
use App\Support\Str;

/**
 * Home page: hero, live stat tiles, biography teaser, latest news, videos, recent matches,
 * career clubs, honours highlights, shop teaser and FAQ teaser.
 *
 * Every data block is optional: missing modules (class_exists) or empty/missing tables
 * render an empty state instead of failing.
 */
final class HomeController
{
    /** Quick facts (content/biography.php labels) shown in the "Ronaldo kimdir?" teaser. */
    private const TEASER_FACTS = ['Tam adı', 'Doğum tarihi', 'Doğum yeri', 'Uyruğu', 'Mevki', 'Forma numarası', 'Mevcut kulübü'];

    public function index(Request $request): Response
    {
        $player = LifeController::player();
        $bio = LifeController::content('biography');
        $honours = LifeController::content('honours');
        $stats = class_exists(StatsRepository::class) ? new StatsRepository() : null;

        $career = $stats !== null ? (array) LifeController::safely(static fn () => $stats->careerTotals(), []) : [];
        $national = $stats !== null ? (array) LifeController::safely(static fn () => $stats->nationalTotals(), []) : [];
        $games = $stats !== null ? (array) LifeController::safely(static fn () => $stats->latestGames(5), []) : [];
        $clubs = $stats !== null ? (array) LifeController::safely(static fn () => $stats->careerClubs(), []) : [];
        $lastSync = $stats !== null ? LifeController::safely(static fn () => $stats->lastSyncedAt()) : null;
        if ($clubs === []) {
            $clubs = self::clubsFromContent();
        }
        $national = self::nationalTeam($national);

        $news = class_exists(NewsRepository::class)
            ? (array) LifeController::safely(static fn () => (new NewsRepository())->latest(6), [])
            : [];
        $videos = self::videos(4);

        $shopEnabled = (bool) setting('shop.enabled', true);
        $products = $shopEnabled && class_exists(ProductRepository::class) && method_exists(ProductRepository::class, 'all')
            ? array_slice((array) LifeController::safely(static fn () => (new ProductRepository())->all(), []), 0, 4)
            : [];

        $facts = [];
        foreach ((array) ($bio['quick_facts'] ?? []) as $fact) {
            if (is_array($fact) && in_array($fact['label'] ?? '', self::TEASER_FACTS, true)) {
                $facts[] = $fact;
            }
        }
        $lead = (string) ($bio['lead'] ?? '');
        $firstParagraph = preg_match('~<p\b[^>]*>.*?</p>~s', $lead, $m) === 1 ? $m[0] : $lead;

        $portrait = LifeController::portrait($player);
        $heroTitle = trim((string) setting('site.hero_title', ''));
        $h1 = $heroTitle !== '' && str_contains(Str::lower($heroTitle), 'ronaldo') ? $heroTitle : 'Cristiano Ronaldo';
        $kicker = $heroTitle !== '' && $h1 !== $heroTitle ? $heroTitle : null;
        $subtitle = trim((string) setting('site.hero_subtitle', ''));
        if ($subtitle === '') {
            $subtitle = 'Madeira’dan dünyanın zirvesine uzanan bir efsane: CR7’nin hayatı, kariyeri, güncel istatistikleri, kupaları, haberleri ve videoları tek adreste.';
        }

        $seo = new Seo(
            title: 'Cristiano Ronaldo (CR7): Haberler, İstatistikler, Hayatı',
            description: 'Cristiano Ronaldo hakkında her şey: CR7’nin hayatı, kariyeri, güncel istatistikleri, gol rekorları, kupaları, son haberleri ve videoları Türkçe olarak burada.',
            canonical: url('/'),
            jsonLd: [self::websiteSchema(), self::organizationSchema(), LifeController::personSchema($player)],
        );

        return view('pages/home', [
            'seo' => $seo,
            'activeNav' => 'home',
            'extraHead' => self::portraitHint($portrait) . partial('partials/home-styles', ['page' => 'home']),
            'player' => $player,
            'portrait' => $portrait,
            'h1' => $h1,
            'kicker' => $kicker,
            'subtitle' => $subtitle,
            'career' => $career,
            'national' => $national,
            'honourSummary' => HonoursController::summary($honours),
            'highlights' => self::highlights($honours),
            'firstParagraph' => LifeController::localLinks($firstParagraph),
            'facts' => $facts,
            'faq' => array_slice(array_values(array_filter((array) ($bio['faq'] ?? []), static fn ($f): bool => is_array($f) && !empty($f['q']) && !empty($f['a']))), 0, 3),
            'news' => $news,
            'videos' => $videos,
            'games' => $games,
            'clubs' => $clubs,
            'lastSync' => is_string($lastSync) ? $lastSync : null,
            'shopEnabled' => $shopEnabled,
            // Same rule as the shop pages: switched on AND a payment method available.
            'ordersEnabled' => $shopEnabled && (bool) LifeController::safely(static fn (): bool => Shop::ordersOpen(), false),
            'products' => $products,
        ]);
    }

    // ------------------------------------------------------------------ data helpers

    /** Featured videos first, topped up with the newest ones (no duplicates). */
    private static function videos(int $limit): array
    {
        if (!class_exists(VideoRepository::class)) {
            return [];
        }
        $repo = new VideoRepository();
        $out = [];
        foreach ([(array) LifeController::safely(static fn () => $repo->featured($limit), []), (array) LifeController::safely(static fn () => $repo->latest($limit * 2), [])] as $list) {
            foreach ($list as $video) {
                if (is_array($video) && !empty($video['youtube_id']) && !isset($out[$video['youtube_id']]) && count($out) < $limit) {
                    $out[$video['youtube_id']] = $video;
                }
            }
        }
        return array_values($out);
    }

    /** Career clubs from content/career.php when the stats module is unavailable. */
    private static function clubsFromContent(): array
    {
        $out = [];
        foreach ((array) (LifeController::content('career')['clubs'] ?? []) as $club) {
            if (is_array($club) && !empty($club['slug']) && !empty($club['name'])) {
                $out[] = [
                    'club_id' => (string) ($club['club_id'] ?? ''),
                    'name' => (string) $club['name'],
                    'slug' => (string) $club['slug'],
                    'logo_url' => null,
                    'from_year' => isset($club['from']) ? (int) $club['from'] : null,
                    'to_year' => isset($club['to']) ? (int) $club['to'] : null,
                    'period' => (string) ($club['period'] ?? ''),
                    'appearances' => 0,
                    'goals' => 0,
                    'url' => '/takimlar/' . $club['slug'],
                ];
            }
        }
        return $out;
    }

    /** National totals + the Portekiz club page (logo/url) for the clubs strip. */
    private static function nationalTeam(array $totals): array
    {
        $out = [
            'games' => (int) ($totals['games'] ?? 0),
            'goals' => (int) ($totals['goals'] ?? 0),
            'name' => 'Portekiz',
            'url' => '/takimlar/portekiz',
            'logo_url' => null,
            'period' => (string) (LifeController::content('career')['national']['period'] ?? '2003–'),
        ];
        if (class_exists(ClubRepository::class)) {
            $page = LifeController::safely(static fn () => (new ClubRepository())->page('portekiz'));
            if (is_array($page)) {
                $out['url'] = (string) ($page['url'] ?? $out['url']);
                $out['logo_url'] = ClubRepository::logo($page['logo_url'] ?? null, 'md');
            }
        }
        return $out;
    }

    /**
     * Headline honours for the home page (sums across clubs of content/honours.php).
     *
     * @return list<array{value: int, label: string, sub: string}>
     */
    private static function highlights(array $honours): array
    {
        $team = [];
        foreach ((array) ($honours['team'] ?? []) as $row) {
            if (!is_array($row) || empty($row['title'])) {
                continue;
            }
            $key = Str::normalize((string) $row['title']);
            $team[$key]['title'] = (string) $row['title'];
            $team[$key]['count'] = ($team[$key]['count'] ?? 0) + (int) ($row['count'] ?? 0);
            $team[$key]['parts'][] = (string) ($row['club'] ?? '') . ' (' . (int) ($row['count'] ?? 0) . ')';
            $team[$key]['seasons'] = array_merge($team[$key]['seasons'] ?? [], array_map('strval', (array) ($row['seasons'] ?? [])));
        }
        $individual = [];
        foreach ((array) ($honours['individual'] ?? []) as $row) {
            if (is_array($row) && !empty($row['title'])) {
                $individual[Str::normalize((string) $row['title'])] = $row;
            }
        }

        $out = [];
        if (isset($individual['ballon dor'])) {
            $out[] = ['value' => (int) $individual['ballon dor']['count'], 'label' => (string) $individual['ballon dor']['title'], 'sub' => implode(', ', (array) $individual['ballon dor']['years'])];
        }
        if (isset($team['uefa sampiyonlar ligi'])) {
            $out[] = ['value' => $team['uefa sampiyonlar ligi']['count'], 'label' => $team['uefa sampiyonlar ligi']['title'], 'sub' => implode(', ', $team['uefa sampiyonlar ligi']['parts'])];
        }
        foreach (['uefa avrupa sampiyonasi', 'uefa uluslar ligi'] as $key) {
            if (isset($team[$key])) {
                $out[] = ['value' => $team[$key]['count'], 'label' => $team[$key]['title'], 'sub' => 'Portekiz · ' . implode(', ', $team[$key]['seasons'])];
            }
        }
        if (isset($individual['avrupa altin ayakkabi'])) {
            $out[] = ['value' => (int) $individual['avrupa altin ayakkabi']['count'], 'label' => (string) $individual['avrupa altin ayakkabi']['title'], 'sub' => implode(', ', (array) $individual['avrupa altin ayakkabi']['years'])];
        }
        $leagues = 0;
        $parts = [];
        foreach (['premier lig', 'la liga', 'serie a', 'suudi pro lig'] as $key) {
            if (isset($team[$key])) {
                $leagues += $team[$key]['count'];
                $parts[] = $team[$key]['title'] . ' (' . $team[$key]['count'] . ')';
            }
        }
        if ($leagues > 0) {
            $out[] = ['value' => $leagues, 'label' => 'Lig şampiyonluğu', 'sub' => implode(', ', $parts)];
        }
        return $out;
    }

    // ------------------------------------------------------------------ JSON-LD

    /** @return array<string, mixed> */
    /**
     * The hero portrait is the home page's LCP image and usually comes from another host
     * (Transfermarkt CDN): open that connection while the HTML is still parsing. No crossorigin
     * attribute — a plain <img> uses the no-CORS connection pool.
     */
    private static function portraitHint(?array $portrait): string
    {
        $host = $portrait !== null ? parse_url((string) $portrait['url'], PHP_URL_HOST) : null;
        $scheme = $portrait !== null ? parse_url((string) $portrait['url'], PHP_URL_SCHEME) : null;
        if (!is_string($host) || $host === '' || !in_array($scheme, ['http', 'https'], true) || $host === parse_url(url('/'), PHP_URL_HOST)) {
            return '';
        }
        return '<link rel="preconnect" href="' . e($scheme . '://' . $host) . '">' . "\n";
    }

    public static function websiteSchema(): array
    {
        return [
            '@type' => 'WebSite',
            '@id' => url('/') . '#website',
            'name' => Seo::siteName(),
            'alternateName' => 'CR7 Türkiye – Cristiano Ronaldo Hayran Sitesi',
            'url' => url('/'),
            'inLanguage' => 'tr-TR',
            'publisher' => ['@id' => url('/') . '#organization'],
            'about' => ['@id' => url('/hayati') . '#cristiano-ronaldo'],
            'potentialAction' => [
                '@type' => 'SearchAction',
                'target' => ['@type' => 'EntryPoint', 'urlTemplate' => url('/arama') . '?q={search_term_string}'],
                'query-input' => 'required name=search_term_string',
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function organizationSchema(): array
    {
        $schema = [
            '@type' => 'Organization',
            '@id' => url('/') . '#organization',
            'name' => Seo::siteName(),
            'url' => url('/'),
            'logo' => ['@type' => 'ImageObject', 'url' => url('/assets/img/icon-512.png'), 'width' => 512, 'height' => 512],
            'description' => 'Cristiano Ronaldo hakkında bağımsız, resmî olmayan Türkçe hayran sitesi.',
        ];
        $email = (string) config('app.contact_email', '');
        if (filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
            $schema['email'] = $email;
            $schema['contactPoint'] = ['@type' => 'ContactPoint', 'contactType' => 'customer support', 'email' => $email, 'availableLanguage' => ['Turkish']];
        }
        return $schema;
    }
}
