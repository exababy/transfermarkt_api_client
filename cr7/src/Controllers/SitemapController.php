<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Repositories\ClubRepository;
use App\Repositories\NewsRepository;
use App\Repositories\ProductRepository;
use App\Repositories\StatsRepository;
use App\Repositories\VideoRepository;
use App\Support\Cache;
use App\Support\Log;

/**
 * XML sitemaps (index + pages/posts/news/videos/clubs/products) and robots.txt.
 *
 * Every data source is optional (class_exists + try/catch): a missing module yields an
 * empty (well-formed) urlset, and the index only lists child sitemaps that contain URLs. Output is cached for sitemap.cache_ttl seconds (default 3600;
 * the Google News sitemap at most 15 minutes so fresh articles appear quickly).
 */
final class SitemapController
{
    public const TYPES = ['pages', 'posts', 'news', 'videos', 'clubs', 'products'];

    private const NS = 'http://www.sitemaps.org/schemas/sitemap/0.9';
    private const NS_IMAGE = 'http://www.google.com/schemas/sitemap-image/1.1';
    private const NS_NEWS = 'http://www.google.com/schemas/sitemap-news/0.9';
    private const NS_VIDEO = 'http://www.google.com/schemas/sitemap-video/1.1';

    /** @var array<string, list<array<string, mixed>>> per-request memo of entries */
    private array $memo = [];

    public function index(Request $request): Response
    {
        return self::xmlResponse($this->cached('index', function (): string {
            $out = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<sitemapindex xmlns="' . self::NS . '">' . "\n";
            foreach (self::types() as $type) {
                $entries = $this->entries($type);
                if ($entries === [] && $type !== 'pages') {
                    continue; // an empty <urlset> is not schema-valid; list a child once it has URLs
                }
                $lastmod = null;
                foreach ($entries as $entry) {
                    if (!empty($entry['lastmod']) && ($lastmod === null || strcmp((string) $entry['lastmod'], $lastmod) > 0)) {
                        $lastmod = (string) $entry['lastmod'];
                    }
                }
                $out .= '  <sitemap><loc>' . self::x(url('/sitemap-' . $type . '.xml')) . '</loc>'
                    . ($lastmod !== null && ($w3c = self::w3c($lastmod)) !== null ? '<lastmod>' . $w3c . '</lastmod>' : '')
                    . '</sitemap>' . "\n";
            }
            return $out . '</sitemapindex>' . "\n";
        }));
    }

    public function show(Request $request): Response
    {
        $type = (string) $request->param('type', '');
        if (!in_array($type, self::types(), true)) {
            return Response::notFound();
        }
        return self::xmlResponse($this->cached($type, fn (): string => self::urlset($this->entries($type), $type)));
    }

    public function robots(Request $request): Response
    {
        $lines = [
            'User-agent: *',
            'Allow: /',
            'Disallow: /admin',
            'Disallow: /cron',
            'Disallow: /kurulum',
            'Disallow: /arama',
            'Disallow: /odeme',
            'Disallow: /siparis',
            '',
            'Sitemap: ' . url('/sitemap.xml'),
        ];
        return Response::text(implode("\n", $lines) . "\n")->withHeader('Cache-Control', 'public, max-age=3600');
    }

    // ------------------------------------------------------------------ entries

    /** Sitemap types currently published (products only while the shop is enabled). */
    public static function types(): array
    {
        $types = self::TYPES;
        if (!(bool) setting('shop.enabled', true) || !class_exists(ProductRepository::class) || !method_exists(ProductRepository::class, 'forSitemap')) {
            $types = array_values(array_diff($types, ['products']));
        }
        return $types;
    }

    /**
     * @return list<array{loc: string, lastmod?: ?string, changefreq?: string, priority?: string, images?: list<string>, news?: array, video?: array}>
     */
    public function entries(string $type): array
    {
        if (isset($this->memo[$type])) {
            return $this->memo[$type];
        }
        $entries = match ($type) {
            'pages' => $this->pageEntries(),
            'posts' => self::rows(NewsRepository::class, static fn () => (new NewsRepository())->forSitemap(5000), static fn (array $r): array => [
                'loc' => (string) $r['url'],
                'lastmod' => $r['lastmod'] ?? null,
                'images' => !empty($r['image']) ? [(string) $r['image']] : [],
            ]),
            'news' => self::rows(NewsRepository::class, static fn () => (new NewsRepository())->forNewsSitemap(), static fn (array $r): array => [
                'loc' => (string) $r['url'],
                'news' => ['title' => (string) ($r['title'] ?? ''), 'published_at' => (string) ($r['published_at'] ?? '')],
            ]),
            'videos' => self::rows(VideoRepository::class, static fn () => (new VideoRepository())->forSitemap(), static fn (array $r): array => [
                'loc' => (string) $r['url'],
                'lastmod' => $r['lastmod'] ?? null,
                'video' => [
                    'thumbnail' => (string) (($r['thumbnail_url'] ?? '') ?: ('https://i.ytimg.com/vi/' . rawurlencode((string) ($r['youtube_id'] ?? '')) . '/hqdefault.jpg')),
                    'title' => (string) ($r['title'] ?? ''),
                    'description' => (string) (($r['description'] ?? '') !== '' ? $r['description'] : ($r['title'] ?? '')),
                    'player' => (string) (($r['player_url'] ?? '') ?: ('https://www.youtube-nocookie.com/embed/' . rawurlencode((string) ($r['youtube_id'] ?? '')))),
                    'published_at' => $r['published_at'] ?? null,
                    'duration' => isset($r['duration_seconds']) ? (int) $r['duration_seconds'] : null,
                ],
            ]),
            'clubs' => self::rows(ClubRepository::class, static fn () => (new ClubRepository())->forSitemap(), static fn (array $r): array => [
                'loc' => (string) $r['url'],
                'lastmod' => $r['lastmod'] ?? null,
                'images' => !empty($r['image']) ? [(string) $r['image']] : [],
            ]),
            'products' => self::rows(ProductRepository::class, static fn () => (new ProductRepository())->forSitemap(), static fn (array $r): array => [
                'loc' => (string) $r['url'],
                'lastmod' => $r['lastmod'] ?? null,
                'images' => !empty($r['image']) ? [(string) $r['image']] : [],
            ]),
            default => [],
        };
        $entries = array_values(array_filter($entries, static fn (array $e): bool => preg_match('#^https?://#i', $e['loc']) === 1));
        return $this->memo[$type] = $entries;
    }

    /** Static and section pages with lastmod from the data behind them. */
    private function pageEntries(): array
    {
        $newest = static function (array $entries): ?string {
            $max = null;
            foreach ($entries as $e) {
                $value = $e['lastmod'] ?? ($e['news']['published_at'] ?? null);
                if (is_string($value) && $value !== '' && ($max === null || strcmp($value, $max) > 0)) {
                    $max = $value;
                }
            }
            return $max;
        };
        $posts = $newest($this->entries('posts'));
        $videos = $newest($this->entries('videos'));
        $stats = class_exists(StatsRepository::class) ? LifeController::safely(static fn () => (new StatsRepository())->lastSyncedAt()) : null;
        $stats = is_string($stats) ? $stats : null;
        $bio = LifeController::reviewedDate(LifeController::content('biography')['last_reviewed'] ?? null);
        $honours = LifeController::reviewedDate(LifeController::content('honours')['last_reviewed'] ?? null);
        $career = LifeController::reviewedDate(LifeController::content('career')['last_reviewed'] ?? null);
        $max = static fn (?string ...$dates): ?string => array_reduce($dates, static fn (?string $c, ?string $d): ?string => $d !== null && ($c === null || strcmp($d, $c) > 0) ? $d : $c);

        $pages = [
            ['/', $max($posts, $videos, $stats), 'hourly', '1.0'],
            ['/hayati', $bio, 'monthly', '0.9'],
            ['/kariyer', $max($career, $stats), 'weekly', '0.8'],
            ['/takimlar', $max($career, $stats), 'weekly', '0.7'],
            ['/istatistikler', $stats, 'daily', '0.8'],
            ['/istatistikler/maclar', $stats, 'daily', '0.6'],
            ['/kupalar-ve-oduller', $honours, 'monthly', '0.8'],
            ['/haberler', $posts, 'hourly', '0.8'],
            ['/videolar', $videos, 'daily', '0.7'],
        ];
        if ((bool) setting('shop.enabled', true)) {
            $products = in_array('products', self::types(), true) ? $newest($this->entries('products')) : null;
            $pages[] = ['/forma-magazasi', $products, 'weekly', '0.6'];
        }
        foreach (['about' => '/hakkinda', 'contact' => '/iletisim', 'privacy' => '/gizlilik-politikasi'] as $key => $path) {
            $pages[] = [$path, PageController::modified($key) . ' 09:00:00', 'yearly', '0.3'];
        }
        $elsewhere = array_column($this->entries('clubs'), 'loc'); // /takimlar is listed in sitemap-clubs.xml
        $pages = array_filter($pages, static fn (array $p): bool => !in_array(url($p[0]), $elsewhere, true));
        return array_values(array_map(static fn (array $p): array => ['loc' => url($p[0]), 'lastmod' => $p[1], 'changefreq' => $p[2], 'priority' => $p[3]], $pages));
    }

    /**
     * Guarded repository call mapped to entries ([] when the class is missing or the query fails).
     *
     * @param class-string $class
     */
    private static function rows(string $class, callable $fetch, callable $map): array
    {
        if (!class_exists($class)) {
            return [];
        }
        $out = [];
        foreach ((array) LifeController::safely($fetch, []) as $row) {
            if (is_array($row) && !empty($row['url'])) {
                $out[] = $map($row);
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------ XML

    private static function urlset(array $entries, string $type): string
    {
        $ns = ' xmlns="' . self::NS . '"';
        $ns .= match ($type) {
            'news' => ' xmlns:news="' . self::NS_NEWS . '"',
            'videos' => ' xmlns:video="' . self::NS_VIDEO . '"',
            'posts', 'clubs', 'products' => ' xmlns:image="' . self::NS_IMAGE . '"',
            default => '',
        };
        $out = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset' . $ns . '>' . "\n";
        foreach ($entries as $entry) {
            $out .= '  <url><loc>' . self::x($entry['loc']) . '</loc>';
            if (!empty($entry['lastmod']) && ($w3c = self::w3c((string) $entry['lastmod'])) !== null) {
                $out .= '<lastmod>' . $w3c . '</lastmod>';
            }
            if (!empty($entry['changefreq'])) {
                $out .= '<changefreq>' . self::x($entry['changefreq']) . '</changefreq>';
            }
            if (!empty($entry['priority'])) {
                $out .= '<priority>' . self::x($entry['priority']) . '</priority>';
            }
            foreach (array_slice((array) ($entry['images'] ?? []), 0, 1000) as $image) {
                $image = url((string) $image);
                if (preg_match('#^https?://#i', $image) === 1) {
                    $out .= '<image:image><image:loc>' . self::x($image) . '</image:loc></image:image>';
                }
            }
            if (isset($entry['news']) && ($date = self::w3c($entry['news']['published_at'] ?? null)) !== null) {
                $out .= '<news:news><news:publication><news:name>' . self::x(\App\Core\Seo::siteName()) . '</news:name><news:language>tr</news:language></news:publication>'
                    . '<news:publication_date>' . $date . '</news:publication_date>'
                    . '<news:title>' . self::x($entry['news']['title']) . '</news:title></news:news>';
            }
            if (isset($entry['video'])) {
                $v = $entry['video'];
                $out .= '<video:video>'
                    . '<video:thumbnail_loc>' . self::x(url($v['thumbnail'])) . '</video:thumbnail_loc>'
                    . '<video:title>' . self::x(mb_substr($v['title'], 0, 100)) . '</video:title>'
                    . '<video:description>' . self::x(mb_substr($v['description'], 0, 2048)) . '</video:description>'
                    . '<video:player_loc>' . self::x($v['player']) . '</video:player_loc>';
                if ($v['duration'] !== null && $v['duration'] >= 1 && $v['duration'] <= 28800) {
                    $out .= '<video:duration>' . $v['duration'] . '</video:duration>';
                }
                if (($date = self::w3c($v['published_at'])) !== null) {
                    $out .= '<video:publication_date>' . $date . '</video:publication_date>';
                }
                $out .= '<video:family_friendly>yes</video:family_friendly></video:video>';
            }
            $out .= '</url>' . "\n";
        }
        return $out . '</urlset>' . "\n";
    }

    /** XML-escape text, dropping characters that are invalid in XML 1.0. */
    public static function x(mixed $value): string
    {
        $text = (string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', (string) $value);
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** UTC 'Y-m-d H:i:s' / 'Y-m-d' => W3C datetime ('2026-10-20T15:28:23+00:00'), null when unparsable. */
    public static function w3c(mixed $utc): ?string
    {
        if (!is_string($utc) || trim($utc) === '' || str_starts_with($utc, '0000')) {
            return null;
        }
        try {
            return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('UTC'))->format(DATE_W3C);
        } catch (\Exception) {
            return null;
        }
    }

    private static function xmlResponse(string $xml): Response
    {
        return Response::xml($xml)
            ->withHeader('Cache-Control', 'public, max-age=3600')
            ->withHeader('X-Robots-Tag', 'noindex, follow');
    }

    /** Cached XML document (sitemap.cache_ttl seconds; <= 0 disables caching). */
    private function cached(string $type, callable $build): string
    {
        $ttl = (int) config('sitemap.cache_ttl', 3600);
        if ($type === 'news' || $type === 'index') {
            $ttl = min($ttl, 900);
        }
        if ($ttl <= 0) {
            return (string) $build();
        }
        $key = 'sitemap:v1:' . $type . ':' . sha1(url('/') . '|' . (setting('shop.enabled', true) ? '1' : '0'));
        try {
            $xml = Cache::remember($key, $ttl, static fn (): string => (string) $build());
        } catch (\Throwable $e) {
            Log::error('site', 'Sitemap cache failed', ['error' => $e->getMessage()]);
            $xml = (string) $build();
        }
        return is_string($xml) ? $xml : (string) $build();
    }
}
