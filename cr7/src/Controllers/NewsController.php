<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Seo;
use App\Core\View;
use App\Repositories\NewsRepository;
use App\Services\News\PostMeta;
use App\Services\News\Tagger;
use App\Support\Format;
use App\Support\Str;

/**
 * News list (/haberler), article (/haber/{slug}) and tag pages (/haberler/etiket/{slug}).
 */
final class NewsController
{
    public const PER_PAGE = 12;

    /** Tag pages with fewer posts than this are noindex (thin content). */
    public const TAG_INDEX_MIN = Seo::LISTING_INDEX_MIN;

    private NewsRepository $news;

    public function __construct(?NewsRepository $news = null)
    {
        $this->news = $news ?? new NewsRepository();
    }

    public function index(Request $request): Response
    {
        if ($request->query('sayfa') === '1') {
            return Response::redirect(url('/haberler'), 301); // one URL for page 1
        }
        $page = self::page($request);
        if ($page === null) {
            return Response::notFound();
        }
        $total = $this->news->count();
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        if ($page > $pages) {
            return Response::notFound();
        }
        $posts = $this->news->latest(self::PER_PAGE, ($page - 1) * self::PER_PAGE);
        $base = '/haberler';
        $canonical = url(page_url($base, $page));

        $seo = new Seo(
            title: $page > 1 ? "Cristiano Ronaldo Haberleri – Sayfa {$page}" : 'Cristiano Ronaldo Haberleri – Son Dakika CR7',
            description: $page > 1
                ? "Cristiano Ronaldo haber arşivi, sayfa {$page}: CR7’nin maçları, golleri, transfer gelişmeleri ve açıklamalarıyla ilgili güncel Türkçe haberler."
                : 'Cristiano Ronaldo ile ilgili son dakika haberleri: CR7’nin maçları, golleri, rekorları, transfer gelişmeleri ve açıklamaları Türkçe olarak burada.',
            canonical: $canonical,
            jsonLd: [self::itemList($posts, $canonical, 'Cristiano Ronaldo Haberleri', ($page - 1) * self::PER_PAGE)],
            breadcrumbs: $page > 1 ? [['name' => 'Haberler', 'url' => $base], ['name' => "Sayfa {$page}"]] : [['name' => 'Haberler']],
            prev: $page > 1 ? page_url($base, $page - 1) : null,
            next: $page < $pages ? page_url($base, $page + 1) : null,
        );

        return Response::html(View::render('pages/news', [
            'seo' => $seo,
            'posts' => $posts,
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
            'baseUrl' => $base,
            'popularTags' => $this->popularTags($this->news->latest(60)),
            'activeNav' => 'haberler',
            'extraHead' => self::styles(),
        ]));
    }

    public function show(Request $request): Response
    {
        $slug = (string) $request->param('slug', '');
        $post = $slug !== '' ? $this->news->findBySlug($slug) : null;
        if ($post === null) {
            $moved = $slug !== '' ? $this->news->movedSlug($slug) : null; // renamed in the admin
            return $moved !== null ? Response::redirect(url('/haber/' . $moved), 301) : Response::notFound();
        }
        if ($request->method() === 'GET' && !self::isBot((string) $request->header('User-Agent'))) {
            try {
                $this->news->incrementViews((int) $post['id']);
            } catch (\Throwable) {
                // a failed counter must never break the page
            }
        }

        $canonical = url('/haber/' . $post['slug']);
        $words = count(preg_split('/\s+/u', strip_tags((string) $post['body']), -1, PREG_SPLIT_NO_EMPTY) ?: []);
        $readingMinutes = max(1, (int) ceil($words / 200));
        $tags = $post['tag_list'];
        $clubPages = Tagger::clubPages();
        $clubLinks = [];
        foreach ($tags as $tag) {
            if (isset($clubPages[$tag['name']])) {
                $clubLinks[] = ['name' => $tag['name'], 'url' => $clubPages[$tag['name']]];
            }
        }
        $image = $post['image_url'] ?? null;
        $published = Format::iso((string) $post['published_at']);
        $modified = Format::iso((string) ($post['updated_at'] ?: $post['published_at']));
        $sources = self::sources($post);

        $article = [
            '@type' => 'NewsArticle',
            'headline' => Str::limit((string) $post['title'], 110),
            'description' => (string) $post['summary'],
            'image' => [[
                '@type' => 'ImageObject',
                'url' => $image ?? url(Seo::DEFAULT_IMAGE),
                'width' => 1200,
                'height' => 630,
            ]],
            'datePublished' => $published,
            'dateModified' => $modified,
            'author' => ['@type' => 'Organization', 'name' => Seo::siteName(), 'url' => url('/')],
            'publisher' => [
                '@type' => 'Organization',
                'name' => Seo::siteName(),
                'url' => url('/'),
                'logo' => ['@type' => 'ImageObject', 'url' => url('/assets/img/icon-512.png'), 'width' => 512, 'height' => 512],
            ],
            'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $canonical],
            'articleSection' => 'Haber',
            'inLanguage' => 'tr-TR',
            'wordCount' => $words,
            'isAccessibleForFree' => true,
            'about' => LifeController::personRef(),
        ];
        if ($tags !== []) {
            $article['keywords'] = implode(', ', array_column($tags, 'name'));
        }
        $extraMeta = [['property' => 'article:section', 'content' => 'Haber']];
        foreach ($tags as $tag) {
            $extraMeta[] = ['property' => 'article:tag', 'content' => $tag['name']];
        }
        if ($image !== null) {
            $extraMeta[] = ['property' => 'og:image:width', 'content' => '1200'];
            $extraMeta[] = ['property' => 'og:image:height', 'content' => '630'];
        }

        $seo = new Seo(
            title: PostMeta::title($post['meta_title'] ?? null, (string) $post['title']),
            description: PostMeta::description((string) $post['summary'], (string) $post['body']),
            canonical: $canonical,
            image: $image,
            type: 'article',
            robots: (int) $post['is_indexable'] === 1 ? 'index,follow,max-image-preview:large' : 'noindex,follow',
            jsonLd: [$article],
            breadcrumbs: [['name' => 'Haberler', 'url' => '/haberler'], ['name' => Str::limit((string) $post['title'], 60)]],
            publishedTime: $published,
            modifiedTime: $modified,
            extraMeta: $extraMeta,
        );

        return Response::html(View::render('pages/news-show', [
            'seo' => $seo,
            'post' => $post,
            'tags' => $tags,
            'clubLinks' => $clubLinks,
            'sources' => $sources,
            'related' => $this->news->related($post, 3),
            'latest' => array_values(array_filter($this->news->latest(5), static fn (array $p): bool => (int) $p['id'] !== (int) $post['id'])),
            'readingMinutes' => $readingMinutes,
            'activeNav' => 'haberler',
            'extraHead' => self::styles(),
        ]));
    }

    public function tag(Request $request): Response
    {
        $slug = (string) $request->param('slug', '');
        $name = $slug !== '' ? $this->news->tagName($slug) : null;
        $page = self::page($request);
        if ($name === null || $page === null) {
            return Response::notFound();
        }
        if ($request->query('sayfa') === '1') {
            return Response::redirect(url('/haberler/etiket/' . $slug), 301);
        }
        $total = $this->news->countByTag($slug);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        if ($total === 0 || $page > $pages) {
            return Response::notFound();
        }
        $posts = $this->news->byTag($slug, self::PER_PAGE, ($page - 1) * self::PER_PAGE);
        $base = '/haberler/etiket/' . $slug;
        $canonical = url(page_url($base, $page));
        $clubPage = Tagger::clubPages()[$name] ?? null;

        $seo = new Seo(
            title: $name . ' Haberleri – Cristiano Ronaldo' . ($page > 1 ? " – Sayfa {$page}" : ''),
            description: Str::limit("Cristiano Ronaldo ve {$name} ile ilgili tüm haberler: son gelişmeler, açıklamalar, maç ve transfer haberleri CR7.com.tr’de bir arada.", 160),
            canonical: $canonical,
            robots: $total < self::TAG_INDEX_MIN ? 'noindex,follow' : 'index,follow,max-image-preview:large',
            jsonLd: [self::itemList($posts, $canonical, $name . ' haberleri', ($page - 1) * self::PER_PAGE)],
            breadcrumbs: [['name' => 'Haberler', 'url' => '/haberler'], ['name' => $name, 'url' => $page > 1 ? $base : null]] + ($page > 1 ? [2 => ['name' => "Sayfa {$page}"]] : []),
            prev: $page > 1 ? page_url($base, $page - 1) : null,
            next: $page < $pages ? page_url($base, $page + 1) : null,
        );

        return Response::html(View::render('pages/news-tag', [
            'seo' => $seo,
            'name' => $name,
            'slug' => $slug,
            'posts' => $posts,
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
            'baseUrl' => $base,
            'clubPage' => $clubPage,
            'activeNav' => 'haberler',
            'extraHead' => self::styles(),
        ]));
    }

    /** Crawlers, previews and monitoring tools are not counted as views. */
    public static function isBot(string $userAgent): bool
    {
        if (trim($userAgent) === '') {
            return true;
        }
        return preg_match('/bot\b|bot\/|crawl|spider|slurp|mediapartners|facebookexternalhit|embedly|quora link preview|whatsapp|telegram|skype|preview|monitor|uptime|pingdom|lighthouse|headless|phantom|curl|wget|python|java\/|go-http|okhttp|axios|node-fetch|libwww|httpclient|feed|rss|validator/i', $userAgent) === 1;
    }

    /** Current ?sayfa= page (null for invalid values like 0, -1 or text). */
    private static function page(Request $request): ?int
    {
        $raw = $request->query('sayfa');
        if ($raw === null || $raw === '') {
            return 1;
        }
        if (!is_string($raw) || preg_match('/^[1-9]\d{0,4}$/', $raw) !== 1) {
            return null;
        }
        return (int) $raw;
    }

    /** schema.org ItemList of a listing page. */
    private static function itemList(array $posts, string $url, string $name, int $offset): array
    {
        $items = [];
        foreach ($posts as $i => $post) {
            $items[] = ['@type' => 'ListItem', 'position' => $offset + $i + 1, 'url' => url($post['url']), 'name' => $post['title']];
        }
        return ['@type' => 'ItemList', 'name' => $name, 'url' => $url, 'numberOfItems' => count($items), 'itemListElement' => $items];
    }

    /** Source links of a post (from the "Kaynak:" line of the body, else source_name/source_url). */
    public static function sources(array $post): array
    {
        $out = [];
        if (preg_match('~<p><strong>Kaynak:</strong>(.*?)</p>~su', (string) $post['body'], $m) === 1) {
            // Read the links with a DOM parser, not a regex over attributes: a title such as
            // 'href="javascript:…"' must never be taken for the href. Only http(s) links are listed.
            $doc = new \DOMDocument();
            $previous = libxml_use_internal_errors(true);
            $doc->loadHTML('<?xml encoding="UTF-8"><div>' . $m[1] . '</div>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            foreach ($doc->getElementsByTagName('a') as $a) {
                $url = trim($a->getAttribute('href'));
                if (self::isWebUrl($url)) {
                    $name = trim((string) preg_replace('/\s+/u', ' ', $a->textContent));
                    $out[] = ['url' => $url, 'name' => $name !== '' ? $name : (string) parse_url($url, PHP_URL_HOST)];
                }
            }
        }
        $fallback = trim((string) ($post['source_url'] ?? ''));
        if ($out === [] && self::isWebUrl($fallback)) {
            $out[] = ['url' => $fallback, 'name' => (string) (($post['source_name'] ?? '') ?: parse_url($fallback, PHP_URL_HOST))];
        }
        return $out;
    }

    private static function isWebUrl(string $url): bool
    {
        return preg_match('~^https?://[^\s/?#\\\\]+~i', $url) === 1;
    }

    /** Most used tags of recent posts (internal links on the listing page). */
    private function popularTags(array $posts): array
    {
        $counts = [];
        $names = [];
        foreach ($posts as $post) {
            foreach (Tagger::fromColumn($post['tags'] ?? '') as $tag) {
                $counts[$tag['slug']] = ($counts[$tag['slug']] ?? 0) + 1;
                $names[$tag['slug']] = $tag['name'];
            }
        }
        arsort($counts);
        $out = [];
        foreach (array_slice($counts, 0, 12, true) as $slug => $count) {
            $out[] = ['slug' => (string) $slug, 'name' => $names[$slug], 'count' => $count];
        }
        return $out;
    }

    /** Small page-specific styles (the shared design system has no article/byline components). */
    private static function styles(): string
    {
        return '<style>'
            . '.news-article h1{font-size:clamp(1.75rem,1.2rem + 2.1vw,2.7rem);margin-bottom:.45em}'
            . '.news-article .meta{margin-bottom:18px}'
            . '.news-figure{margin:0 0 24px;border-radius:var(--radius);overflow:hidden;border:1px solid var(--border);background:var(--bg-elev);aspect-ratio:1200/630}'
            . '.news-figure img{width:100%;height:100%;object-fit:cover}'
            . '.news-article .prose{max-width:none}'
            . '.news-article .prose p:last-child{color:var(--text-muted);font-size:.95rem}'
            . '.news-tags{margin:26px 0 8px}'
            . '.source-box{margin-top:26px}'
            . '.source-box ul{margin:8px 0 0;padding-left:1.2em}'
            . '.side-box{padding:20px;border:1px solid var(--border);border-radius:var(--radius);background:var(--bg-elev)}'
            . '.side-box h2{font-size:1.05rem;margin-bottom:12px}'
            . '.side-list li+li{margin-top:12px;padding-top:12px;border-top:1px solid var(--border)}'
            . '.side-list a{color:var(--text);text-decoration:none;font-weight:650;line-height:1.4}'
            . '.side-list a:hover{color:var(--gold-soft)}'
            . '.side-list .meta{margin-top:4px;font-size:.8rem}'
            . '@media (min-width:960px){.news-aside{position:sticky;top:calc(var(--header-h) + 20px);align-self:start}}'
            . '</style>';
    }
}
