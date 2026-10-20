<?php

declare(strict_types=1);

namespace Tests;

use App\Controllers\HonoursController;
use App\Controllers\LifeController;
use App\Controllers\PageController;
use App\Controllers\SearchController;
use App\Controllers\SitemapController;
use App\Core\App;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Settings;
use App\Support\Date;
use App\Support\OgImage;

/**
 * Site module: home, /hayati, /kupalar-ve-oduller, /arama, static pages, sitemaps, robots.txt.
 */
final class SiteTest extends TestCase
{
    private ?Router $router = null;

    protected function setUp(): void
    {
        Config::set('sitemap.cache_ttl', 0); // never serve sitemaps from the file cache in tests
        Config::set('app.url', 'https://cr7.com.tr');
    }

    public function testHomeDegradesGracefullyWithEmptyDatabase(): void
    {
        $response = $this->get('/');
        $html = $response->body();
        $this->assertSame(200, $response->status());
        $this->assertSame(1, substr_count($html, '<h1'), 'exactly one h1');
        $this->assertContains('>Cristiano Ronaldo</h1>', $html);
        $this->assertContains('Henüz yayımlanmış haber yok', $html);
        $this->assertContains('Maç verileri Transfermarkt senkronizasyonundan sonra', $html);
        $this->assertContains('Ronaldo kimdir?', $html);
        $this->assertContains('href="https://cr7.com.tr/hayati"', $html);
        $this->assertContains('<link rel="canonical" href="https://cr7.com.tr/">', $html);
        $this->assertNotContains('news-card', $html);

        $schemas = $this->jsonLd($html);
        $types = array_column($schemas, '@type');
        foreach (['WebSite', 'Organization', 'Person'] as $type) {
            $this->assertContains($type, $types, 'JSON-LD type');
        }
        $website = $schemas[array_search('WebSite', $types, true)];
        $this->assertSame('https://cr7.com.tr/arama?q={search_term_string}', $website['potentialAction']['target']['urlTemplate']);
        $person = $schemas[array_search('Person', $types, true)];
        $this->assertSame('1985-02-05', $person['birthDate']);
        $this->assertContains('https://www.wikidata.org/wiki/Q11571', $person['sameAs']);
        $this->assertContains('https://www.facebook.com/Cristiano', $person['sameAs']);
        $this->assertContains('https://tr.wikipedia.org/wiki/Cristiano_Ronaldo', $person['sameAs']);
        $this->assertSame('Al-Nassr', $person['memberOf']['name'], 'current club falls back to content/career.php');

        // Shop teaser follows shop.enabled.
        $this->assertContains('/forma-magazasi', $html);
        Settings::set('shop.enabled', false);
        $this->assertNotContains('id="magaza-baslik"', $this->get('/')->body());
    }

    public function testHomeShowsLiveData(): void
    {
        $this->seedStats();
        $this->seedPost('ronaldo-981-golunu-atti', 'Ronaldo 981. golünü attı', 2);
        Database::insert('videos', [
            'youtube_id' => 'abcdefghijk', 'slug' => 'ronaldo-golleri-abcde', 'title' => 'Ronaldo’nun en güzel golleri', 'description' => 'Goller',
            'category' => 'goller', 'duration_seconds' => 125, 'published_at' => Date::ago(3600), 'is_featured' => 1,
            'created_at' => Date::now(), 'updated_at' => Date::now(),
        ]);

        Settings::set('seo.google_verification', 'AbC-123_xyz');
        Settings::set('seo.bing_verification', '"><script>');
        $html = $this->get('/')->body();
        $this->assertContains('<meta name="google-site-verification" content="AbC-123_xyz">', $html, 'webmaster verification tag on the home page');
        $this->assertNotContains('msvalidate.01', $html, 'invalid codes are never printed');
        $this->assertNotContains('google-site-verification', $this->get('/hayati')->body(), 'verification tags only on the home page');
        $this->assertContains('Ronaldo 981. golünü attı', $html);
        $this->assertContains('Ronaldo’nun en güzel golleri', $html);
        $this->assertContains('class="result-W"', $html);
        $this->assertContains('Test FC', $html);
        $this->assertContains('Fotoğraf: IMAGO / Transfermarkt', $html, 'TM portrait credit');
        $this->assertContains('fetchpriority="high"', $html);
        $this->assertContains('>1.360<', $html, 'career apps tile');
        $this->assertContains('>987<', $html, 'career goals tile');
        $this->assertContains('/istatistikler/maclar', $html);

        Settings::set('site.portrait_url', 'https://example.com/cr7.jpg');
        $html = $this->get('/')->body();
        $this->assertContains('src="https://example.com/cr7.jpg"', $html);
        $this->assertNotContains('Fotoğraf: IMAGO / Transfermarkt', $html, 'no TM credit for a custom portrait');
    }

    public function testLifePage(): void
    {
        $bio = LifeController::content('biography');
        $response = $this->get('/hayati');
        $html = $response->body();
        $this->assertSame(200, $response->status());
        $this->assertSame(1, substr_count($html, '<h1'), 'exactly one h1');
        foreach ($bio['sections'] as $section) {
            $this->assertContains('id="' . $section['id'] . '"', $html, 'section anchor');
            $this->assertContains('href="#' . $section['id'] . '"', $html, 'toc link');
        }
        $this->assertContains('id="sss"', $html);
        $this->assertContains('<details', $html);
        $this->assertContains('class="timeline"', $html);
        $this->assertContains('Son güncelleme', $html);
        $this->assertContains('href="https://cr7.com.tr/kupalar-ve-oduller"', $html, 'internal content links are absolute app URLs');

        $schemas = $this->jsonLd($html);
        $types = array_column($schemas, '@type');
        $this->assertContains('Person', $types);
        $this->assertContains('BreadcrumbList', $types);
        $faq = $schemas[array_search('FAQPage', $types, true)];
        $this->assertCount(count($bio['faq']), $faq['mainEntity'], 'every FAQ item in FAQPage');
        $person = $schemas[array_search('Person', $types, true)];
        $this->assertTrue(isset($person['award'], $person['description'], $person['birthPlace']['address']), 'rich Person');
    }

    public function testHonoursPage(): void
    {
        $honours = LifeController::content('honours');
        $summary = HonoursController::summary($honours);
        $expected = array_sum(array_map(static fn (array $t): int => (int) $t['count'], $honours['team']));
        $this->assertSame($expected, $summary['team_total']);
        $this->assertSame(5, $summary['ballon_dor']);
        $this->assertSame(5, $summary['champions_league']);
        $this->assertSame(4, $summary['golden_shoe']);

        $groups = HonoursController::groups($honours);
        $this->assertSame('Sporting CP', $groups[0]['club']);
        $this->assertSame('/takimlar/real-madrid', $groups[array_search('418', array_column($groups, 'club_id'), true)]['url']);

        $html = $this->get('/kupalar-ve-oduller')->body();
        $this->assertSame(1, substr_count($html, '<h1'));
        $this->assertContains('href="https://cr7.com.tr/takimlar/al-nassr"', $html);
        $this->assertContains('id="bireysel-oduller"', $html);
        $this->assertContains('id="rekorlar"', $html);
        $schemas = $this->jsonLd($html);
        $lists = array_values(array_filter($schemas, static fn (array $s): bool => $s['@type'] === 'ItemList'));
        $this->assertCount(2, $lists);
        $this->assertCount(count($honours['team']), $lists[0]['itemListElement']);
    }

    public function testSearch(): void
    {
        $this->seedPost('ronaldo-ballon-dor-aciklamasi', 'Ronaldo’dan Ballon d’Or açıklaması', 1);
        $response = $this->get('/arama?q=' . rawurlencode('ballon'));
        $html = $response->body();
        $this->assertSame(200, $response->status());
        $this->assertContains('<meta name="robots" content="noindex,follow">', $html);
        $this->assertContains('<mark class="site-mark">Ballon</mark>', $html);
        $this->assertContains('/kupalar-ve-oduller#bireysel-oduller', $html, 'static honours hit');
        $this->assertContains('/haber/ronaldo-ballon-dor-aciklamasi', $html, 'news hit');

        $xss = $this->get('/arama?q=' . rawurlencode('<script>alert(1)</script>'))->body();
        $this->assertNotContains('<script>alert(1)', $xss);
        $this->assertContains('&lt;script&gt;', $xss);
        $this->assertContains('en az 2 karakter', $this->get('/arama?q=a')->body());
        $this->assertSame(200, $this->get('/arama?q[]=x')->status(), 'array query is ignored');

        // Turkish folding, word-start matching and safe highlighting.
        $terms = SearchController::terms('ŞAMPİYONLAR ligi');
        $this->assertSame(['sampiyonlar', 'ligi'], $terms);
        $this->assertTrue(SearchController::matches('UEFA Şampiyonlar Ligi’nde rekor', $terms));
        $this->assertFalse(SearchController::matches('Şampiyonlar Kupası Gol Krallığı', $terms), 'no mid-word match');
        $this->assertSame(
            '<mark class="site-mark">Şampiyonlar</mark> <mark class="site-mark">Ligi</mark>’nde &lt;b&gt;',
            SearchController::highlight('Şampiyonlar Ligi’nde <b>', $terms)
        );
        $this->assertTrue(SearchController::matches('Madeira’nın İlk Golü', SearchController::terms('ilk')));
    }

    public function testStaticPages(): void
    {
        $about = $this->get('/hakkinda')->body();
        $this->assertContains('haberler kaynak gösterilerek derlenir ve yeniden yazılır', $about);
        $this->assertContains('hiçbir resmî bağlantısı olmayan', $about);
        $this->assertContains('Transfermarkt', $about);

        $privacy = $this->get('/gizlilik-politikasi')->body();
        foreach (['KVKK', 'PayTR', 'iyzico', 'Shopier', 'Çerez politikası', 'cr7sess'] as $needle) {
            $this->assertContains($needle, $privacy, 'privacy');
        }

        Config::set('app.contact_email', 'iletisim@cr7.com.tr');
        $contact = $this->get('/iletisim')->body();
        $this->assertContains('mailto:iletisim@cr7.com.tr', $contact);
        $this->assertNotContains('wa.me', $contact);
        Settings::set('shop.whatsapp', '0532 123 45 67');
        $this->assertContains('https://wa.me/905321234567', $this->get('/iletisim')->body());
        $this->assertSame('+90 532 123 45 67', PageController::whatsapp('+90 (532) 123-45-67')['display']);
        $this->assertNull(PageController::whatsapp('123'));

        foreach (['/hakkinda', '/gizlilik-politikasi', '/iletisim'] as $path) {
            $html = $this->get($path)->body();
            $this->assertSame(1, substr_count($html, '<h1'), $path . ' h1');
            $this->assertContains('BreadcrumbList', $html, $path . ' breadcrumbs');
        }
    }

    public function testTitlesAndDescriptionsAreUniqueAndWellSized(): void
    {
        $titles = [];
        $descriptions = [];
        foreach (['/', '/hayati', '/kupalar-ve-oduller', '/hakkinda', '/gizlilik-politikasi', '/iletisim'] as $path) {
            $html = $this->get($path)->body();
            $this->assertSame(1, preg_match('~<title>(.*?) \| CR7\.com\.tr</title>~u', $html, $t), $path . ' title');
            $this->assertSame(1, preg_match('~<meta name="description" content="([^"]*)">~u', $html, $d), $path . ' description');
            $title = html_entity_decode($t[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $description = html_entity_decode($d[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $this->assertTrue(mb_strlen($title) <= 60, $path . ' title ≤ 60 chars: ' . mb_strlen($title));
            $this->assertTrue(mb_strlen($description) >= 120 && mb_strlen($description) <= 160, $path . ' description 120–160 chars: ' . mb_strlen($description));
            $this->assertContains('index,follow', $html, $path . ' indexable');
            $this->assertContains('og:image', $html);
            $titles[] = $title;
            $descriptions[] = $description;
        }
        $this->assertCount(count($titles), array_unique($titles), 'unique titles');
        $this->assertCount(count($descriptions), array_unique($descriptions), 'unique descriptions');
    }

    public function testSitemapsAreValidXml(): void
    {
        $this->seedStats();
        // An existing image file is listed; a missing one ('/og/silinmis.png') is not (it would 404).
        $this->seedPost('yeni-haber', 'Yeni haber & “özel” <karakterler>', 1, '/assets/img/og-default.png');
        $this->seedPost('eski-haber', 'Eski haber', 24 * 10, '/og/silinmis.png');
        Database::insert('videos', [
            'youtube_id' => 'abcdefghijk', 'slug' => 'ronaldo-golleri-abcde', 'title' => 'Ronaldo’nun & Messi’nin golleri', 'description' => '',
            'category' => 'goller', 'duration_seconds' => 125, 'published_at' => Date::ago(3600), 'created_at' => Date::now(), 'updated_at' => Date::now(),
        ]);
        // An auto-imported video with a foreign-language title is noindex: not in the video sitemap.
        Database::insert('videos', [
            'youtube_id' => 'zyxwvutsrqp', 'slug' => 'next-stop-miami-zyxwv', 'title' => 'NEXT STOP: MIAMI', 'description' => 'Portugal', 'source' => 'rss',
            'category' => 'genel', 'published_at' => Date::ago(7200), 'created_at' => Date::now(), 'updated_at' => Date::now(),
        ]);

        $index = $this->xml('/sitemap.xml');
        $locs = $this->values($index, '//s:sitemap/s:loc');
        foreach (['pages', 'posts', 'news', 'videos', 'clubs'] as $type) {
            $this->assertContains('https://cr7.com.tr/sitemap-' . $type . '.xml', $locs, 'index lists ' . $type);
        }
        $this->assertNotContains('https://cr7.com.tr/sitemap-products.xml', $locs, 'empty child sitemaps are not listed');
        $this->assertTrue(count($this->values($index, '//s:sitemap/s:lastmod')) >= 3, 'lastmod per child');

        $pages = $this->values($this->xml('/sitemap-pages.xml'), '//s:url/s:loc');
        foreach (['/', '/hayati', '/istatistikler/maclar', '/kupalar-ve-oduller', '/forma-magazasi', '/hakkinda', '/mesafeli-satis-sozlesmesi', '/on-bilgilendirme-formu', '/iptal-ve-iade'] as $path) {
            $this->assertContains('https://cr7.com.tr' . $path, $pages, 'pages sitemap: ' . $path);
        }
        $this->assertNotContains('https://cr7.com.tr/arama', $pages);

        $posts = $this->xml('/sitemap-posts.xml');
        $this->assertCount(2, $this->values($posts, '//s:url/s:loc'));
        $this->assertSame(['https://cr7.com.tr/assets/img/og-default.png'], $this->values($posts, '//image:image/image:loc'));

        $news = $this->xml('/sitemap-news.xml');
        $this->assertSame(['https://cr7.com.tr/haber/yeni-haber'], $this->values($news, '//s:url/s:loc'), 'only the last 48 h');
        $this->assertSame(['CR7.com.tr'], $this->values($news, '//news:publication/news:name'));
        $this->assertSame(['tr'], $this->values($news, '//news:publication/news:language'));
        $this->assertSame(['Yeni haber & “özel” <karakterler>'], $this->values($news, '//news:title'));

        $videos = $this->xml('/sitemap-videos.xml');
        $this->assertSame(['https://www.youtube-nocookie.com/embed/abcdefghijk'], $this->values($videos, '//video:player_loc'));
        $this->assertSame(['125'], $this->values($videos, '//video:duration'));
        $this->assertSame(['Ronaldo’nun & Messi’nin golleri'], $this->values($videos, '//video:description'), 'description falls back to the title');
        $this->assertCount(1, $this->values($videos, '//video:thumbnail_loc'));

        $clubs = $this->values($this->xml('/sitemap-clubs.xml'), '//s:url/s:loc');
        $this->assertContains('https://cr7.com.tr/takimlar/manchester-united', $clubs);
        $this->assertContains('https://cr7.com.tr/takimlar/portekiz', $clubs);

        $this->assertSame(404, $this->get('/sitemap-foo.xml')->status());
        if (in_array('products', SitemapController::types(), true)) {
            $this->xml('/sitemap-products.xml');
        }
        Settings::set('shop.enabled', false);
        $this->assertSame(404, $this->get('/sitemap-products.xml')->status(), 'no product sitemap while the shop is off');
        $this->assertNotContains('/forma-magazasi', $this->get('/sitemap-pages.xml')->body());
        $this->assertNotContains('/mesafeli-satis-sozlesmesi', $this->get('/sitemap-pages.xml')->body(), 'shop legal pages leave the sitemap with the shop');
        $this->assertNotContains('sitemap-products.xml', $this->get('/sitemap.xml')->body());
    }

    public function testCachedSitemapsFollowContentChanges(): void
    {
        Config::set('sitemap.cache_ttl', 3600);
        Config::set('app.url', 'https://sitemap-cache-' . bin2hex(random_bytes(4)) . '.test'); // own cache keys
        $this->seedPost('ilk-haber', 'İlk haber', 1);
        $this->assertContains('/haber/ilk-haber<', $this->get('/sitemap-posts.xml')->body());
        $this->assertContains('/haber/ilk-haber<', $this->get('/sitemap-news.xml')->body());

        // Renamed slug, deleted post and a new post show up at once (no stale cache for an hour).
        Database::update('posts', ['slug' => 'yeni-adres'], 'slug = ?', ['ilk-haber']);
        $this->seedPost('ikinci-haber', 'İkinci haber', 0);
        $posts = $this->get('/sitemap-posts.xml')->body();
        $this->assertNotContains('/haber/ilk-haber<', $posts, 'old slug left the sitemap');
        $this->assertContains('/haber/yeni-adres<', $posts);
        $this->assertContains('/haber/ikinci-haber<', $posts);
        Database::execute('DELETE FROM posts WHERE slug = ?', ['ikinci-haber']);
        $this->assertNotContains('/haber/ikinci-haber<', $this->get('/sitemap-news.xml')->body(), 'deleted post left the news sitemap');

        $this->assertSame('public, max-age=300', $this->get('/sitemap-news.xml')->header('Cache-Control'));
        $this->assertSame('public, max-age=300', $this->get('/sitemap.xml')->header('Cache-Control'));
        $this->assertSame('public, max-age=3600', $this->get('/sitemap-posts.xml')->header('Cache-Control'));
    }

    public function testSitemapsAndRssAreNotServedEmptyWhenQueriesFail(): void
    {
        Config::set('sitemap.cache_ttl', 3600);
        Config::set('app.url', 'https://sitemap-down-' . bin2hex(random_bytes(4)) . '.test'); // own cache keys
        $this->seedPost('ilk-haber', 'İlk haber', 1);
        Database::pdo()->exec('ALTER TABLE posts RENAME COLUMN slug TO slug_broken'); // every posts query fails
        foreach (['/sitemap.xml', '/sitemap-posts.xml', '/sitemap-news.xml', '/sitemap-pages.xml', '/rss.xml'] as $path) {
            $response = $this->get($path);
            $this->assertSame(503, $response->status(), $path . ' while the query fails');
            $this->assertSame('600', $response->header('Retry-After'), $path);
            $this->assertSame('no-store', $response->header('Cache-Control'), $path);
        }
        Database::pdo()->exec('ALTER TABLE posts RENAME COLUMN slug_broken TO slug');
        $this->assertContains('/haber/ilk-haber<', $this->get('/sitemap-posts.xml')->body(), 'the failed build was not cached');
        $this->assertContains('/haber/ilk-haber<', $this->get('/rss.xml')->body());
    }

    public function testLargeArchivesAreSplitAndFullyPaged(): void
    {
        $total = SitemapController::POSTS_PER_FILE + 3; // 10 posts a day for ~16 months
        $insert = Database::pdo()->prepare("INSERT INTO posts (slug, title, summary, body, tags, published_at, updated_at, created_at) VALUES (?, ?, 'Özet', '<p>Metin</p>', ?, ?, ?, ?)");
        Database::pdo()->beginTransaction();
        for ($i = 0; $i < $total; $i++) {
            $at = Date::ago(3600 + $i * 8640);
            $insert->execute(['arsiv-haber-' . $i, 'Arşiv haber ' . $i, $i % 2 === 0 ? 'Al-Nassr, Gol' : 'Gol Krallığı', $at, $at, $at]);
        }
        Database::pdo()->commit();

        $index = $this->get('/sitemap.xml')->body();
        $this->assertContains('<loc>https://cr7.com.tr/sitemap-posts.xml</loc>', $index);
        $this->assertContains('<loc>https://cr7.com.tr/sitemap-posts-2.xml</loc>', $index);
        $this->assertNotContains('sitemap-posts-3.xml', $index);
        $this->assertSame(SitemapController::POSTS_PER_FILE, substr_count($this->get('/sitemap-posts.xml')->body(), '<loc>'));
        $older = $this->get('/sitemap-posts-2.xml');
        $this->assertSame(200, $older->status());
        $this->assertSame(3, substr_count($older->body(), '<loc>'));
        $this->assertContains('/haber/arsiv-haber-' . ($total - 1) . '<', $older->body(), 'the oldest post is listed');
        foreach (['/sitemap-posts-3.xml', '/sitemap-posts-1.xml'] as $path) {
            $this->assertSame(404, $this->get($path)->status(), $path);
        }

        // Tag archives count and page in SQL: every post is reachable, exact tag match ("Gol" ≠ "Gol Krallığı").
        $news = new \App\Repositories\NewsRepository();
        $this->assertSame(intdiv($total + 1, 2), $news->countByTag('al-nassr'));
        $this->assertSame(intdiv($total + 1, 2), $news->countByTag('gol'));
        $this->assertSame(intdiv($total, 2), $news->countByTag('gol-kralligi'));
        $last = $news->byTag('al-nassr', 12, intdiv($total + 1, 2) - 1);
        $this->assertSame(['arsiv-haber-' . ($total - 1)], array_column($last, 'slug'), 'oldest tagged post on the last page');
        $this->assertSame(intdiv($total + 1, 2), $news->tagCounts()['al-nassr']['count']);
    }

    public function testSectionPagesHaveTheirOwnShareCardAndHomePreconnect(): void
    {
        $this->seedStats();
        foreach (['/hayati' => 'sayfa-hayati-', '/kupalar-ve-oduller' => 'sayfa-kupalar-ve-oduller-'] as $path => $prefix) {
            $html = $this->get($path)->body();
            $this->assertSame(1, preg_match('#<meta property="og:image" content="https://cr7\.com\.tr/og/' . $prefix . '[0-9a-f]{6}\.png">#', $html), $path . ': own 1200x630 card, not og-default');
            $this->assertContains('<meta property="og:image:width" content="1200">', $html, $path);
        }
        $card = OgImage::page('hayati', 'Cristiano Ronaldo’nun Hayatı', 'Biyografi ve aile', 'HAYATI');
        $this->assertSame([1200, 630], array_slice((array) getimagesize(App::basePath('public' . $card)), 0, 2));
        // Home LCP is the Transfermarkt portrait: connect to its host early (no crossorigin for <img>).
        $this->assertContains('<link rel="preconnect" href="https://img.a.transfermarkt.technology">', $this->get('/')->body());
    }

    public function testRobotsTxt(): void
    {
        $response = $this->get('/robots.txt');
        $this->assertSame(200, $response->status());
        $this->assertContains('text/plain', (string) $response->header('Content-Type'));
        $body = $response->body();
        foreach (['User-agent: *', 'Allow: /', 'Disallow: /admin', 'Disallow: /cron', 'Disallow: /kurulum', 'Disallow: /arama', 'Disallow: /odeme', 'Disallow: /siparis', 'Sitemap: https://cr7.com.tr/sitemap.xml'] as $line) {
            $this->assertContains($line . "\n", $body, 'robots');
        }
    }

    public function testPagesSurviveMissingTables(): void
    {
        foreach (['posts', 'videos', 'games', 'player', 'stats_summary', 'clubs', 'national_career', 'products'] as $table) {
            Database::pdo()->exec('DROP TABLE IF EXISTS ' . $table);
        }
        foreach (['/', '/hayati', '/kupalar-ve-oduller', '/arama?q=ronaldo', '/sitemap.xml', '/sitemap-posts.xml', '/sitemap-videos.xml', '/sitemap-pages.xml'] as $path) {
            $this->assertSame(200, $this->get($path)->status(), $path . ' without tables');
        }
        $this->assertContains('Henüz yayımlanmış haber yok', $this->get('/')->body());
    }

    // ------------------------------------------------------------------ helpers

    private function get(string $uri): Response
    {
        if ($this->router === null) {
            $this->router = new Router();
            (require App::basePath('routes.php'))($this->router);
        }
        $request = Request::create('GET', $uri);
        App::setRequest($request);
        return $this->router->dispatch($request);
    }

    /** @return list<array<string, mixed>> decoded JSON-LD blocks (fails on invalid JSON) */
    private function jsonLd(string $html): array
    {
        preg_match_all('~<script type="application/ld\+json"[^>]*>(.*?)</script>~s', $html, $m);
        $out = [];
        foreach ($m[1] as $json) {
            $data = json_decode($json, true);
            $this->assertTrue(is_array($data), 'valid JSON-LD: ' . json_last_error_msg());
            $this->assertSame('https://schema.org', $data['@context'] ?? null);
            $out[] = $data;
        }
        $this->assertTrue($out !== [], 'JSON-LD present');
        return $out;
    }

    private function xml(string $uri): \DOMXPath
    {
        $response = $this->get($uri);
        $this->assertSame(200, $response->status(), $uri);
        $this->assertContains('application/xml', (string) $response->header('Content-Type'));
        $doc = new \DOMDocument();
        $this->assertTrue(@$doc->loadXML($response->body()) === true, $uri . ' is well-formed XML');
        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $xpath->registerNamespace('image', 'http://www.google.com/schemas/sitemap-image/1.1');
        $xpath->registerNamespace('news', 'http://www.google.com/schemas/sitemap-news/0.9');
        $xpath->registerNamespace('video', 'http://www.google.com/schemas/sitemap-video/1.1');
        return $xpath;
    }

    /** @return list<string> */
    private function values(\DOMXPath $xpath, string $query): array
    {
        $out = [];
        foreach ($xpath->query($query) ?: [] as $node) {
            $out[] = $node->textContent;
        }
        return $out;
    }

    private function seedPost(string $slug, string $title, int $hoursAgo, ?string $image = null): void
    {
        $at = Date::ago($hoursAgo * 3600);
        Database::insert('posts', [
            'slug' => $slug, 'title' => $title, 'summary' => 'Cristiano Ronaldo ile ilgili özet.', 'body' => '<p>Metin</p>',
            'image_path' => $image, 'published_at' => $at, 'updated_at' => $at, 'created_at' => $at,
        ]);
    }

    private function seedStats(): void
    {
        $now = Date::now();
        Database::insert('player', [
            'id' => 8198, 'name' => 'Cristiano Ronaldo', 'full_name' => 'Cristiano Ronaldo dos Santos Aveiro', 'birth_date' => '1985-02-05',
            'height_cm' => 185, 'position' => 'Santrafor', 'shirt_number' => 7, 'current_club_id' => '18544',
            'portrait_url' => 'https://img.a.transfermarkt.technology/portrait/big/8198.jpg', 'updated_at' => $now,
        ]);
        Database::insert('clubs', ['id' => '18544', 'name' => 'Al-Nassr FC', 'slug' => 'al-nassr', 'logo_url' => 'https://img.a.transfermarkt.technology/wappen/head/18544.png', 'updated_at' => $now]);
        Database::insert('clubs', ['id' => '999', 'name' => 'Test FC', 'slug' => 'test-fc', 'updated_at' => $now]);
        Database::insert('stats_summary', ['scope' => 'career', 'appearances' => 1360, 'goals' => 987, 'assists' => 311, 'updated_at' => $now]);
        Database::insert('national_career', ['club_id' => '3300', 'games' => 234, 'goals' => 146, 'updated_at' => $now]);
        Database::insert('games', [
            'game_id' => '1', 'date_utc' => Date::ago(86400), 'season_id' => 2026, 'competition_id' => 'SA1', 'club_id' => '18544', 'opponent_id' => '999',
            'venue' => 'home', 'goals_for' => 3, 'goals_against' => 1, 'result' => 'W', 'participation' => 'played', 'minutes' => 90, 'goals' => 1, 'updated_at' => $now,
        ]);
    }
}
