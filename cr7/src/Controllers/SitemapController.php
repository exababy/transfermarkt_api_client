<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Seo;
use App\Repositories\ClubRepository;
use App\Repositories\NewsRepository;
use App\Repositories\ProductRepository;
use App\Repositories\StatsRepository;
use App\Repositories\VideoRepository;
use App\Support\Cache;
use App\Support\Date;
use App\Support\Log;

/**
 * XML sitemaps (index + pages/posts/news/videos/clubs/products) and robots.txt.
 *
 * Every data source is optional (class_exists + try/catch): a missing module yields an
 * empty (well-formed) urlset, and the index only lists child sitemaps that contain URLs. Output is cached for sitemap.cache_ttl seconds (default 3600;
 * the Google News sitemap at most 15 minutes so fresh articles appear quickly); the cache key includes a
 * fingerprint of the content tables, so any content change is visible immediately.
 */
final class SitemapController
{
    public const TYPES = ['pages', 'posts', 'news', 'videos', 'clubs', 'products'];

    /** Post URLs per file: sitemap-posts.xml holds the newest, older ones follow in sitemap-posts-2.xml, -3 … */
    public const POSTS_PER_FILE = 5000;

    private const NS = 'http://www.sitemaps.org/schemas/sitemap/0.9';
    private const NS_IMAGE = 'http://www.google.com/schemas/sitemap-image/1.1';
    private const NS_NEWS = 'http://www.google.com/schemas/sitemap-news/0.9';
    private const NS_VIDEO = 'http://www.google.com/schemas/sitemap-video/1.1';

    /** @var array<string, list<array<string, mixed>>> per-request memo of entries */
    private array $memo = [];

    /** Set by safely() when a data call of the document being built failed. */
    private static bool $failed = false;

    public function index(Request $request): Response
    {
        return self::xmlResponse($this->cached('index', function (): string {
            $out = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<sitemapindex xmlns="' . self::NS . '">' . "\n";
            foreach (self::types() as $type) {
                for ($page = 1, $files = $type === 'posts' ? $this->postFiles() : 1; $page <= $files; $page++) {
                    $entries = $this->entries($type, $page);
                    if ($entries === [] && $type !== 'pages') {
                        continue; // an empty <urlset> is not schema-valid; list a child once it has URLs
                    }
                    $lastmod = null;
                    foreach ($entries as $entry) {
                        if (!empty($entry['lastmod']) && ($lastmod === null || strcmp((string) $entry['lastmod'], $lastmod) > 0)) {
                            $lastmod = (string) $entry['lastmod'];
                        }
                    }
                    $out .= '  <sitemap><loc>' . self::x(url('/sitemap-' . $type . ($page > 1 ? '-' . $page : '') . '.xml')) . '</loc>'
                        . ($lastmod !== null && ($w3c = self::w3c($lastmod)) !== null ? '<lastmod>' . $w3c . '</lastmod>' : '')
                        . '</sitemap>' . "\n";
                }
            }
            return $out . '</sitemapindex>' . "\n";
        }), 'index');
    }

    public function show(Request $request): Response
    {
        $type = (string) $request->param('type', '');
        if (!in_array($type, self::types(), true)) {
            return Response::notFound();
        }
        return self::xmlResponse($this->cached($type, fn (): string => self::urlset($this->entries($type), $type)), $type);
    }

    /** /sitemap-posts-{n}.xml (n >= 2): older posts beyond the first POSTS_PER_FILE. */
    public function postsPage(Request $request): Response
    {
        $page = (int) $request->param('id', '0');
        self::$failed = false;
        $files = $this->postFiles();
        if (self::$failed) {
            return self::xmlResponse(null, 'posts'); // database unavailable: 503, not 404
        }
        if ($page < 2 || $page > $files) {
            return Response::notFound();
        }
        return self::xmlResponse($this->cached('posts-' . $page, fn (): string => self::urlset($this->entries('posts', $page), 'posts')), 'posts');
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
     * $page: file number of the posts sitemap (POSTS_PER_FILE posts each); ignored by other types.
     *
     * @return list<array{loc: string, lastmod?: ?string, changefreq?: string, priority?: string, images?: list<string>, news?: array, video?: array}>
     */
    public function entries(string $type, int $page = 1): array
    {
        $memoKey = $type === 'posts' && $page > 1 ? $type . '-' . $page : $type;
        if (isset($this->memo[$memoKey])) {
            return $this->memo[$memoKey];
        }
        $offset = max(0, $page - 1) * self::POSTS_PER_FILE;
        $entries = match ($type) {
            'pages' => $this->pageEntries(),
            'posts' => self::rows(NewsRepository::class, static fn () => (new NewsRepository())->forSitemap(self::POSTS_PER_FILE, $offset), static fn (array $r): array => [
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
        return $this->memo[$memoKey] = $entries;
    }

    /** Number of posts sitemap files (at least 1: sitemap-posts.xml). */
    private function postFiles(): int
    {
        $count = class_exists(NewsRepository::class) ? (int) self::safely(static fn () => (new NewsRepository())->countForSitemap(), 0) : 0;
        return max(1, (int) ceil($count / self::POSTS_PER_FILE));
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
        $stats = class_exists(StatsRepository::class) ? self::safely(static fn () => (new StatsRepository())->dataChangedAt()) : null;
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
        if ($stats === null) {
            // Not synced yet: the statistics pages are noindex notices until the first tm-sync.
            $pages = array_values(array_filter($pages, static fn (array $p): bool => !str_starts_with($p[0], '/istatistikler')));
        }
        if ((bool) setting('shop.enabled', true)) {
            $products = in_array('products', self::types(), true) ? $newest($this->entries('products')) : null;
            $pages[] = ['/forma-magazasi', $products, 'weekly', '0.6'];
            // Turkish e-commerce legal pages (indexable, linked from the footer while the shop is on).
            foreach (['/mesafeli-satis-sozlesmesi', '/on-bilgilendirme-formu', '/iptal-ve-iade'] as $path) {
                $pages[] = [$path, PageController::modified('shop-legal') . ' 09:00:00', 'yearly', '0.2'];
            }
        }
        foreach (['about' => '/hakkinda', 'contact' => '/iletisim', 'privacy' => '/gizlilik-politikasi'] as $key => $path) {
            $pages[] = [$path, PageController::modified($key) . ' 09:00:00', 'yearly', '0.3'];
        }
        array_push($pages, ...$this->listingEntries($stats, $posts, $videos));
        $elsewhere = array_column($this->entries('clubs'), 'loc'); // /takimlar is listed in sitemap-clubs.xml
        $pages = array_filter($pages, static fn (array $p): bool => !in_array(url($p[0]), $elsewhere, true));
        return array_values(array_map(static fn (array $p): array => ['loc' => url($p[0]), 'lastmod' => $p[1], 'changefreq' => $p[2], 'priority' => $p[3]], $pages));
    }

    /**
     * Indexable, self-canonical filtered listings (page 1): season and main-competition match lists,
     * video categories, news tags and shop club filters — exactly the ones whose page is
     * index,follow (Seo::LISTING_INDEX_MIN items for tags/categories/clubs).
     *
     * @return list<array{0: string, 1: ?string, 2: string, 3: string}>
     */
    private function listingEntries(?string $stats, ?string $posts, ?string $videos): array
    {
        $out = [];
        if (class_exists(StatsRepository::class)) {
            $repo = new StatsRepository();
            $changed = (array) self::safely(static fn () => $repo->seasonChangedAt(), []);
            foreach ((array) self::safely(static fn () => $repo->seasonOptions(), []) as $season => $info) {
                $out[] = ['/istatistikler/maclar?sezon=' . (int) $season, $changed[(int) $season] ?? $stats, 'monthly', '0.4'];
            }
            foreach ((array) self::safely(static fn () => $repo->competitionOptions(), []) as $id => $info) {
                if (in_array((string) $id, StatsController::INDEXABLE_COMPETITIONS, true)) {
                    $out[] = ['/istatistikler/maclar?turnuva=' . rawurlencode((string) $id), $stats, 'weekly', '0.5'];
                }
            }
        }
        if (class_exists(VideoRepository::class)) {
            foreach ((array) self::safely(static fn () => (new VideoRepository())->categoryCounts(), []) as $key => $count) {
                if ((int) $count >= Seo::LISTING_INDEX_MIN) {
                    $out[] = ['/videolar?kategori=' . rawurlencode((string) $key), $videos, 'weekly', '0.5'];
                }
            }
        }
        if (class_exists(NewsRepository::class)) {
            foreach ((array) self::safely(static fn () => (new NewsRepository())->tagCounts(), []) as $slug => $tag) {
                if ($tag['count'] >= Seo::LISTING_INDEX_MIN) {
                    $out[] = ['/haberler/etiket/' . $slug, $tag['lastmod'], 'daily', '0.5'];
                }
            }
        }
        if (in_array('products', self::types(), true)) {
            $products = new ProductRepository();
            foreach ((array) self::safely(static fn () => $products->clubs(), []) as $clubId => $name) {
                if (count((array) self::safely(static fn () => $products->all((string) $clubId), [])) >= Seo::LISTING_INDEX_MIN) {
                    $out[] = ['/forma-magazasi?takim=' . rawurlencode((string) $clubId), null, 'weekly', '0.4'];
                }
            }
        }
        return $out;
    }

    /**
     * Guarded data call: $default when it throws. A missing table (module not installed) just
     * yields no URLs; any other failure (DB outage, lock timeout …) marks the document being
     * built as failed: it is served as 503 and never cached, so it cannot publish an empty sitemap.
     */
    private static function safely(callable $fn, mixed $default = null): mixed
    {
        try {
            return $fn() ?? $default;
        } catch (\Throwable $e) {
            self::$failed = self::$failed || !self::missingTable($e);
            Log::error('site', 'Sitemap data failed: ' . $e->getMessage(), ['exception' => get_class($e)]);
            return $default;
        }
    }

    /** True for "table does not exist" (MySQL 42S02 / SQLite), i.e. a module that is not installed. */
    public static function missingTable(\Throwable $e): bool
    {
        return $e instanceof \PDOException
            && ((string) $e->getCode() === '42S02' || str_contains($e->getMessage(), 'no such table'));
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
        foreach ((array) self::safely($fetch, []) as $row) {
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

    /** The index and the Google News sitemap may be cached downstream (CDN/proxy) for 5 minutes only. */
    private static function xmlResponse(?string $xml, string $type): Response
    {
        if ($xml === null) {
            // A data call failed (DB outage): 503 tells crawlers to retry instead of reading an empty sitemap.
            return Response::text("Sitemap geçici olarak kullanılamıyor.\n", 503)
                ->withHeader('Retry-After', '600')
                ->withHeader('Cache-Control', 'no-store')
                ->withHeader('X-Robots-Tag', 'noindex');
        }
        return Response::xml($xml)
            ->withHeader('Cache-Control', 'public, max-age=' . ($type === 'news' || $type === 'index' ? 300 : 3600))
            ->withHeader('X-Robots-Tag', 'noindex, follow');
    }

    /**
     * Cached XML document (sitemap.cache_ttl seconds; <= 0 disables caching); null when a data
     * call failed while building it (that document is not cached).
     */
    private function cached(string $type, callable $build): ?string
    {
        $ttl = (int) config('sitemap.cache_ttl', 3600);
        if ($type === 'news' || $type === 'index') {
            $ttl = min($ttl, 900);
        }
        $fresh = static function () use ($build): ?string {
            self::$failed = false;
            $xml = (string) $build();
            return self::$failed ? null : $xml;
        };
        if ($ttl <= 0) {
            return $fresh();
        }
        // The key carries a fingerprint of the content tables, so publishing, editing, unpublishing or
        // deleting a post/video/product (admin, cron or direct DB change) rebuilds the sitemaps at once.
        try {
            $key = 'sitemap:v2:' . $type . ':' . sha1(url('/') . '|' . (setting('shop.enabled', true) ? '1' : '0') . '|' . self::fingerprint());
            $xml = Cache::get($key);
        } catch (\Throwable $e) {
            return null; // settings/fingerprint queries failed: the database is unavailable
        }
        if (is_string($xml)) {
            return $xml;
        }
        $xml = $fresh();
        if ($xml !== null) {
            try {
                Cache::set($key, $xml, $ttl);
            } catch (\Throwable $e) {
                Log::error('site', 'Sitemap cache failed', ['error' => $e->getMessage()]);
            }
        }
        return $xml;
    }

    /** Row count + newest updated_at of every table behind the sitemaps (cheap aggregate queries). */
    private static function fingerprint(): string
    {
        $now = Date::now();
        $parts = [];
        foreach ([
            // Scheduled posts become visible when published_at passes: count the visible ones too.
            ['SELECT COUNT(*), MAX(updated_at), SUM(CASE WHEN published_at <= ? THEN 1 ELSE 0 END) FROM posts', [$now]],
            ['SELECT COUNT(*), MAX(updated_at) FROM videos', []],
            ['SELECT COUNT(*), MAX(updated_at) FROM products', []],
            ['SELECT COUNT(*), MAX(updated_at) FROM stats_summary', []],
        ] as [$sql, $params]) {
            try {
                $row = Database::fetchOne($sql, $params);
                $parts[] = $row !== null ? implode(',', array_map(static fn (mixed $v): string => (string) $v, $row)) : '';
            } catch (\Throwable) {
                $parts[] = '-'; // table missing (module not installed)
            }
        }
        return sha1(implode('|', $parts));
    }
}
