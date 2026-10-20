<?php

declare(strict_types=1);

namespace Tests;

use App\Controllers\FeedController;
use App\Controllers\NewsController;
use App\Core\App;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Settings;
use App\Repositories\NewsRepository;
use App\Services\News\ArticleComposer;
use App\Services\News\ArticleExtractor;
use App\Services\News\Clusterer;
use App\Services\News\CopyCheck;
use App\Services\News\FeedParser;
use App\Services\News\Fetchers\BingFetcher;
use App\Services\News\Fetchers\GoogleFetcher;
use App\Services\News\Fetchers\RssFetcher;
use App\Services\News\IndexNow;
use App\Services\News\NewsCollector;
use App\Services\News\NewsPublisher;
use App\Services\News\PostMeta;
use App\Services\News\RelevanceFilter;
use App\Services\News\SourceRegistry;
use App\Services\News\Spinner;
use App\Services\News\Tagger;
use App\Support\Cache;
use App\Support\Date;
use App\Support\Http;
use App\Support\OgImage;
use App\Support\Str;

final class NewsTest extends TestCase
{
    private const URL_A = 'https://ornek-haber.test/spor/ronaldo-al-nassr-rekor-1001';
    private const URL_B = 'https://baska-gazete.test/futbol/ronaldo-iki-gollu-gece-2002';

    /** @var list<string> cache keys written by a test */
    private array $cacheKeys = [];

    protected function tearDown(): void
    {
        foreach ($this->cacheKeys as $key) {
            Cache::forget($key);
        }
        try {
            foreach (Database::fetchAll('SELECT image_path FROM posts WHERE image_path IS NOT NULL') as $row) {
                OgImage::delete((string) $row['image_path']);
            }
        } catch (\Throwable) {
        }
        Spinner::reset();
    }

    private static function fixture(string $name): string
    {
        return (string) file_get_contents(App::basePath('tests/fixtures/news/' . $name));
    }

    // ------------------------------------------------------------------ feeds

    public function testFeedParserHandlesCdataEntitiesAndImages(): void
    {
        $items = FeedParser::parse(self::fixture('rss-cdata.xml'));
        $this->assertCount(2, $items);
        $this->assertSame("Ronaldo'dan Al-Nassr'a 2-1'lik galibiyet & rekor!", $items[0]['title']);
        $this->assertSame('Cristiano Ronaldo, Al-Hilal maçında iki gol attı ve takımını zirveye taşıdı.', $items[0]['description'], 'HTML stripped, &nbsp; and "Devamı" removed');
        $this->assertContains('“Çok mutluyum”', $items[0]['content']);
        $this->assertSame('2026-10-20 07:15:00', $items[0]['published_at']);
        $this->assertSame('https://img.ornek-spor.test/r-big.jpg', $items[0]['image']);
        $this->assertSame('Fenerbahçe & Galatasaray derbisinin tarihi belli oldu', $items[1]['title']);
        $this->assertContains('çok önemli maç', $items[1]['description'], 'HTML named entities decoded');
        $this->assertSame('https://img.ornek-spor.test/d.jpg', $items[1]['image']);

        $normalized = (new RssFetcher())->normalizeAll('ornek', ['name' => 'Örnek', 'type' => 'rss', 'url' => '', 'filter' => true, 'text' => true], self::fixture('rss-cdata.xml'));
        $this->assertSame('https://ornek-spor.test/haber/ronaldo-rekor-123?id=7', $normalized[0]['url'], 'utm_* removed');
        $this->assertSame('Derbi 26 Ekim\'de oynanacak çok önemli maç …', $normalized[1]['description']);
    }

    public function testFeedParserReadsAtom(): void
    {
        $items = FeedParser::parse(self::fixture('atom.xml'));
        $this->assertCount(1, $items);
        $this->assertSame('https://atom-spor.test/futbol/ronaldo-aciklama-431606', $items[0]['link']);
        $this->assertSame('Portekizli yıldız sosyal medya hesabından açıklama yaptı.', $items[0]['description']);
        $this->assertSame('2026-10-20 14:07:14', $items[0]['published_at']);
        $this->assertSame('https://images.atom-spor.test/r.jpg', $items[0]['image']);
        $this->assertContains("\n\n", $items[0]['content'], 'paragraphs kept');
        $this->assertSame('Atom Spor', $items[0]['source_name']);
    }

    public function testFeedParserConvertsWindows1254(): void
    {
        $raw = self::fixture('sporx-1254.xml');
        $this->assertFalse(mb_check_encoding($raw, 'UTF-8'), 'fixture really is windows-1254');
        $items = FeedParser::parse($raw, 'application/xml; charset=windows-1254');
        $this->assertCount(1, $items);
        $this->assertSame("Ronaldo'ya şok ceza: “30 gün” men!", $items[0]['title']);
        $this->assertContains('Işık, ığdır, İstanbul, Çağrı, Şükrü, Ödül.', $items[0]['description']);
    }

    public function testFeedParserRecoversMalformedXml(): void
    {
        $items = FeedParser::parse(self::fixture('malformed.xml'));
        $titles = array_column($items, 'title');
        $this->assertContains('Ronaldo & Messi yine aynı listede', $titles);
        $this->assertContains('CR7 rekor kırdı', $titles);
        $this->assertSame('https://bozuk.test/a?x=1&y=2', $items[0]['link']);
        $this->assertSame([], FeedParser::parse('<html><body>not a feed</body></html>'));
        $this->assertSame([], FeedParser::parse(''));
    }

    public function testBingLinksAreDecoded(): void
    {
        $items = (new BingFetcher())->normalizeAll('bing', ['name' => 'Bing Haberler', 'type' => 'bing', 'url' => '', 'filter' => false, 'text' => true], self::fixture('bing.xml'));
        $this->assertCount(1, $items);
        $this->assertSame('https://www.sozcu.test/cristiano-ronaldo-nun-uyku-sirri-p370889', $items[0]['url']);
        $this->assertSame('Sözcü Gazetesi', $items[0]['source_name']);
        $this->assertSame("Cristiano Ronaldo'nun uyku sırrı ortaya çıktı: Günde 5 kez uyku", $items[0]['title']);
        $this->assertTrue(str_ends_with($items[0]['description'], 'uyku düzenini…'), 'cut word dropped: ' . $items[0]['description']);
        $this->assertSame('https://example.test/a', BingFetcher::decodeLink('https://example.test/a'));
        $evil = 'https://news.evilbing.com/x?url=http%3a%2f%2f169.254.169.254%2f';
        $this->assertSame($evil, BingFetcher::decodeLink($evil), 'only bing.com and its subdomains are decoded');
    }

    public function testThirdPartyUrlsAreFetchedFromPublicHostsOnly(): void
    {
        foreach ([
            'http://127.0.0.1/hakkinda', 'http://localhost/', 'http://169.254.169.254/latest/meta-data/', 'http://10.0.0.5/',
            'http://100.64.1.1/', 'http://[::1]/', 'http://[fd00::1]/', 'http://[::ffff:127.0.0.1]/', 'http://2130706433/',
            'http://evil.example\\@127.0.0.1/', 'https://www.example.com:8443/', 'ftp://example.com/', 'http://user@example.com/',
        ] as $url) {
            [$blocked] = Http::publicTarget($url);
            $this->assertTrue($blocked !== null, $url . ' must be blocked');
        }
        $response = Http::getPublic('http://127.0.0.1/hakkinda');
        $this->assertSame(0, $response['status']);
        $this->assertContains('Blocked URL', (string) $response['error']);
        $this->assertSame([null, []], Http::publicTarget('https://93.184.215.14/haber?id=1'), 'public literal IP');
        $this->assertTrue(Http::isPublicIp('2606:4700::6810:84e5'));
        $this->assertFalse(Http::isPublicIp('224.0.0.1'));
    }

    public function testSourceBoxReadsRealHrefsAndOnlyWebLinks(): void
    {
        $body = \App\Support\Html::sanitize('<p>Gövde.</p><p><strong>Kaynak:</strong> <a title=\'href="javascript:alert(document.domain)"\' href="https://www.example.com/">Örnek &amp; Kaynak</a>, '
            . '<a href="https://www.ornek.test/b">B</a></p>');
        $this->assertSame([
            ['url' => 'https://www.example.com/', 'name' => 'Örnek & Kaynak'],
            ['url' => 'https://www.ornek.test/b', 'name' => 'B'],
        ], NewsController::sources(['body' => $body]));
        $this->assertSame([], NewsController::sources(['body' => '<p><strong>Kaynak:</strong> <a href="javascript:alert(1)">x</a></p>', 'source_url' => 'javascript:alert(2)']));
        $this->assertSame([['url' => 'https://a.test/x', 'name' => 'a.test']], NewsController::sources(['body' => '<p>-</p>', 'source_url' => 'https://a.test/x', 'source_name' => '']));
    }

    public function testGoogleTitlesAndStoryClusterAreParsed(): void
    {
        $items = (new GoogleFetcher())->normalizeAll('google', ['name' => 'Google Haberler', 'type' => 'google', 'url' => '', 'filter' => false, 'text' => false], self::fixture('google.xml'));
        $this->assertCount(1, $items);
        $this->assertSame("Cristiano Ronaldo'nun cezası belli oldu", $items[0]['title'], 'publisher suffix removed');
        $this->assertSame('Haberler', $items[0]['source_name']);
        $this->assertSame(["Portekiz'den Cristiano Ronaldo kararı! İşte aldığı ceza", 'Milli takım kampını terk eden Cristiano Ronaldo’ya ceza'], $items[0]['related_titles']);
        $this->assertSame(['Bir başlık - ile', null], GoogleFetcher::splitTitle('Bir başlık - ile'));
        $this->assertSame(['Uzun bir haber başlığı burada', 'Fanatik'], GoogleFetcher::splitTitle('Uzun bir haber başlığı burada - Fanatik'));
    }

    public function testSourceRegistryDefaultsAndDisabledSetting(): void
    {
        $all = SourceRegistry::all();
        foreach (['bing', 'google', 'sozcu', 'sporx', 'ntvspor', 'trthaber'] as $key) {
            $this->assertTrue(isset($all[$key]), "default source {$key}");
        }
        $this->assertFalse(SourceRegistry::hasText('google'), 'Google items are evidence only');
        $this->assertTrue(SourceRegistry::hasText('sozcu'));
        Settings::set('news.sources_disabled', ['sporx', 'google']);
        Settings::flush();
        $this->assertFalse(isset(SourceRegistry::all()['sporx']));
        $this->assertFalse(isset(SourceRegistry::all()['google']));
        $this->assertTrue(isset(SourceRegistry::defined()['sporx']));
    }

    // ------------------------------------------------------------------ filtering / clustering

    public function testRelevanceFilter(): void
    {
        $this->assertSame(2, RelevanceFilter::score("RONALDO'YA ŞOK CEZA", ''));
        $this->assertSame(2, RelevanceFilter::score('Milli takım kampını terk eden Cristiano Ronaldoya ceza'));
        $this->assertSame(2, RelevanceFilter::score('CR7 yine rekor kırdı'));
        $this->assertSame(1, RelevanceFilter::score('Futbolcular neden tükürüyor?', 'Cristiano Ronaldo ve Harry Kane gibi yıldızlar…'));
        $this->assertSame(0, RelevanceFilter::score('Galatasaray derbiye hazır', 'Okan Buruk açıklama yaptı.'));
        $this->assertSame(0, RelevanceFilter::score('Brezilyalı Ronaldo kulübünü sattı'), 'other Ronaldo');
        $this->assertSame(0, RelevanceFilter::score('Ronaldo Nazário Cruzeiro hisselerini sattı'));
        $this->assertSame(0, RelevanceFilter::score('Ronaldinho Türkiye’ye geliyor'));
        $this->assertSame(2, RelevanceFilter::score('Messi, Cristiano Ronaldo ve Ronaldo Nazário aynı listede'), 'Cristiano present');
        // Words between "Brezilyalı" and "Ronaldo"; federation / club markers of Nazário.
        $this->assertSame(0, RelevanceFilter::score('Brezilyalı efsane Ronaldo, Cruzeiro hisselerini sattı'));
        $this->assertSame(0, RelevanceFilter::score("Efsane golcü Ronaldo Real Valladolid'i sattı", 'Brezilyalı yıldız kulübü sattı.'));
        $this->assertSame(0, RelevanceFilter::score('Ronaldo seçim kararını verdi', '1994 ve 2002 şampiyonu Ronaldo, CBF seçimlerinde aday.'));
        $this->assertSame(2, RelevanceFilter::score('Ronaldo, Brezilyalı Neymar ile Al Hilal maçında karşılaştı'), 'a list is not "Brezilyalı Ronaldo"');
        // Publish-time check of the whole story text.
        $this->assertTrue(RelevanceFilter::aboutCristiano("Ronaldo yine attı\nAl-Nassr'ın Portekizli yıldızı Ronaldo iki gol attı."));
        $this->assertTrue(RelevanceFilter::aboutCristiano('Christiano Ronaldo milli takım kampını neden terk ettiğini açıkladı'));
        $this->assertFalse(RelevanceFilter::aboutCristiano("Ronaldo seçim kararını verdi\nBrezilya'nın efsane golcüsü Ronaldo, federasyon başkanlığına aday oldu."));
        $this->assertFalse(RelevanceFilter::aboutCristiano('Portekiz kazandı, Messi gol attı'), 'must mention him');
    }

    public function testPublisherRejectsAnotherRonaldosStory(): void
    {
        Config::set('spinner.min_words', 50); // compose succeeds; only the publish-time check stops it
        $url = 'https://www.aspor.com.tr/futbol/ronaldo-cbf-adayligi';
        $text = implode("\n\n", [
            "Brezilya'nın efsane golcüsü Ronaldo, federasyon başkanlığı için adaylığını resmen açıkladı. 1994 ve 2002 Dünya Kupası'nı kazanan eski futbolcu, uzun süredir yönetimden memnun olmadığını dile getiriyordu.",
            'Ronaldo, düzenlediği basın toplantısında ülke futbolunun yeniden ayağa kalkması gerektiğini söyledi. Eski forvet, altyapı yatırımlarını ve kulüplerin mali yapısını önceliği olarak gösterdi.',
            'Seçimin önümüzdeki yıl mart ayında yapılması bekleniyor. Ronaldo, kampanyası boyunca eyalet federasyonlarıyla tek tek görüşeceğini ve yeni bir dönem başlatmak istediğini vurguladı.',
            "Ronaldo'nun programında kadın futbolu, hakem eğitimi ve stadyum güvenliği gibi başlıklar da yer alıyor. Eski golcü, yayın gelirlerinin daha adil paylaştırılması gerektiğini savundu.",
            'Basın toplantısına katılan eski takım arkadaşları da Ronaldo’ya destek verdi. Cafu ve Roberto Carlos, efsane forvetin futbol bilgisi ve uluslararası tecrübesiyle yeni bir vizyon getireceğini belirtti.',
            "Uzmanlar, eski yıldızın kamuoyundaki popülerliğinin oy kullanacak eyalet federasyonları üzerinde de etkili olabileceğini ifade ediyor. Seçim takvimi önümüzdeki haftalarda netleşecek.",
        ]);
        $hash = Clusterer::urlHash($url);
        $this->cacheKeys[] = 'news:feedtext:' . $hash;
        Cache::set('news:feedtext:' . $hash, $text, 600);
        $id = Database::insert('news_items', [
            'source' => 'aspor', 'source_name' => 'A Spor', 'url' => $url, 'url_hash' => $hash,
            'title' => 'Ronaldo seçim kararını verdi: Federasyon başkanlığına aday', 'title_norm' => Str::normalize('Ronaldo seçim kararını verdi'),
            'description' => 'Efsane golcü Ronaldo, seçimlerde aday olacağını açıkladı.', 'published_at' => Date::now(), 'fetched_at' => Date::now(), 'status' => 'pending',
        ]);
        $publisher = new NewsPublisher();
        $this->assertNull($publisher->publishItem($id, true), 'not published: ' . $publisher->lastMessage());
        $this->assertSame(0, (int) Database::fetchValue('SELECT COUNT(*) FROM posts'));
        $this->assertSame(['failed', 'off-topic'], array_values(Database::fetchOne('SELECT status, note FROM news_items WHERE id = ?', [$id])));
    }

    public function testNoTextRetriesCountRealFetchesOnly(): void
    {
        $url = 'https://kaynak-kapali.test/ronaldo-rekor';
        $key = 'news:article:' . sha1($url);
        $this->cacheKeys[] = $key;
        $id = Database::insert('news_items', [
            'source' => 'sozcu', 'source_name' => 'X', 'url' => $url, 'url_hash' => Clusterer::urlHash($url),
            'title' => 'Cristiano Ronaldo yeni rekor kırdı', 'title_norm' => 'cristiano ronaldo yeni rekor kirdi', 'published_at' => Date::now(), 'fetched_at' => Date::now(),
            'status' => 'pending', 'attempts' => 1, 'note' => 'no-text',
        ]);
        $publisher = new NewsPublisher();
        Cache::set($key, ['paragraphs' => [], 'words' => 0, 'title' => null, 'method' => 'none'], 600); // the last fetch failed
        $this->assertTrue(ArticleExtractor::failedRecently($url));
        $this->assertFalse(in_array($id, array_map('intval', array_column($publisher->candidates(), 'id')), true), 'no attempt is used up while the failure is cached');
        Cache::forget($key);
        $this->assertTrue(in_array($id, array_map('intval', array_column($publisher->candidates(), 'id')), true), 'retried once it expired');
        // An admin "Şimdi yayınla" fetches again instead of answering from the failure cache.
        Cache::set($key, ['paragraphs' => [], 'words' => 0, 'title' => null, 'method' => 'none'], 600);
        ArticleExtractor::forgetFailure($url);
        $this->assertFalse(ArticleExtractor::failedRecently($url));
    }

    public function testPublishingAMemberOfALiveStoryCreatesNoDuplicate(): void
    {
        Config::set('spinner.min_words', 150);
        [$a, $b] = $this->seedStory();
        $publisher = new NewsPublisher();
        $postId = $publisher->publishItem($a, true);
        $this->assertTrue($postId !== null, 'published: ' . $publisher->lastMessage());
        Database::update('news_items', ['status' => 'duplicate', 'note' => 'cluster:' . $a], 'id = ?', [$b]);
        $this->assertSame($postId, $publisher->storyPost(Database::fetchOne('SELECT * FROM news_items WHERE id = ?', [$b])));
        $this->assertSame($postId, $publisher->publishItem($b, true), '"Şimdi yayınla" on the other outlet');
        $this->assertContains('zaten yayında', $publisher->lastMessage());
        $this->assertSame(1, (int) Database::fetchValue('SELECT COUNT(*) FROM posts'), 'no second post');
        $this->assertSame('cluster:' . $a, Database::fetchValue('SELECT note FROM news_items WHERE id = ?', [$b]), 'cluster stays with the live post');
    }

    public function testScheduledSpunPostDoesNotBlockTheGate(): void
    {
        Settings::set('news.publish_window', [0, 24]);
        Settings::set('news.min_interval_minutes', 60);
        Settings::flush();
        $this->insertPost('Otomatik haber iki saat önce', ['news_item_id' => 1, 'published_at' => Date::ago(7200)]);
        $tomorrow = gmdate('Y-m-d H:i:s', time() + 15 * 3600);
        $this->insertPost('Yarına planlanmış otomatik haber', ['news_item_id' => 2, 'published_at' => $tomorrow]);
        $this->insertPost('Taslağa alınmış otomatik haber', ['news_item_id' => 3, 'status' => 'draft', 'published_at' => Date::ago(60)]);
        $repo = new \App\Repositories\NewsRepository();
        $this->assertSame(Date::ago(7200), $repo->lastSpunAt(), 'only live posts');
        $this->assertTrue((new NewsPublisher())->gate()['ok'], 'interval measured from the last live post');
        [$from, $to] = NewsPublisher::dayBoundsUtc();
        $this->assertSame(strcmp(Date::ago(7200), $from) >= 0 ? 1 : 0, $repo->countSpunBetween($from, $to), 'draft not counted');
    }

    public function testClustererMatchesSameStoryHeadlines(): void
    {
        $this->assertSame(Clusterer::urlHash('https://www.Sozcu.com.tr/haber-1/'), Clusterer::urlHash('http://sozcu.com.tr/haber-1'));
        $this->assertFalse(Clusterer::urlHash('https://a.test/x?id=1') === Clusterer::urlHash('https://a.test/x?id=2'));
        $this->assertTrue(Clusterer::sameStory("Cristiano Ronaldo'nun cezası belli oldu", "Cristiano Ronaldo'nun cezası belli oldu!"));
        $this->assertTrue(Clusterer::sameStory(
            'Milli takım kampını terk eden Cristiano Ronaldo’ya ceza',
            "Portekiz Milli Takımı kampını terk eden Ronaldo'nun cezası açıklandı! Men de var"
        ));
        $this->assertFalse(Clusterer::sameStory(
            "Ten Hag'dan Ronaldo'ya sert sözler: 'Takımı da, beni de yüzüstü bıraktı'",
            "Cristiano Ronaldo'nun uyku sırrı ortaya çıktı: Günde 5 kez uyku"
        ));
        $this->assertTrue(Clusterer::sameText(
            "Ronaldo'ya ceza Portekiz Futbol Federasyonu, milli takım kampını terk eden 41 yaşındaki Cristiano Ronaldo'ya 30 gün hak mahrumiyeti ve 255 avro para cezası verdi.",
            "Kampı terk eden yıldıza men Portekiz Futbol Federasyonu, izinsiz milli takım kampını terk eden Cristiano Ronaldo'ya 30 gün hak mahrumiyeti ve 255 euro para cezası verdi."
        ));
        // Different matches / milestones are different stories (scores and counts differ).
        $this->assertFalse(Clusterer::sameStory("Al Nassr, Al Hilal'i 2-1 yendi: Ronaldo gol attı", "Al Nassr, Al Ittihad'ı 3-0 yendi: Ronaldo gol attı"));
        $this->assertFalse(Clusterer::sameStory('Portekiz, İrlanda’yı 2-0 yendi: Ronaldo iki gol attı', "Portekiz, Macaristan'ı 3-1 yendi: Ronaldo iki gol attı"));
        $this->assertFalse(Clusterer::sameStory('Cristiano Ronaldo Al Nassr formasıyla 950. golünü attı', 'Cristiano Ronaldo Al Nassr formasıyla 951. golünü attı'));
        $this->assertTrue(Clusterer::sameStory("Al Nassr, Al Hilal'i 2-1 yendi: Ronaldo gol attı", "Al Hilal, Al Nassr'a 1-2 yenildi: Ronaldo gol attı"), 'same score told from the other side');
        $this->assertTrue(Clusterer::sameStory("Kampı terk eden Ronaldo'ya 30 gün men cezası", "Kampı terk eden Ronaldo'ya 255 euro para cezası"), 'numbers of different things');
        $this->assertTrue(Clusterer::sameStory('Ronaldo 1.000. golüne yaklaştı', 'Ronaldo 1000. golüne yaklaştı'));
        // Items days apart need nearly identical titles.
        $candidates = [['id' => 7, 'titles' => ["Ronaldo'nun golü Al Nassr'a yetmedi"], 'text' => '', 'published_at' => Date::ago(3 * 86400)]];
        $this->assertSame([], Clusterer::matches(["Ronaldo'nun golü Al Nassr'a galibiyeti getirdi"], '', $candidates, null, Date::now()));
        $this->assertSame(7, Clusterer::matches(["Ronaldo'nun golü Al Nassr'a galibiyeti getirdi"], '', $candidates, null, Date::ago(3 * 86400 - 3600))[0]['id'] ?? null);
        $this->assertSame(7, Clusterer::matches(["Ronaldo'nun golü Al Nassr'a yetmedi!"], '', $candidates, null, Date::now())[0]['id'] ?? null, 'near-identical title');
        $this->assertSame(12, Clusterer::primaryFromNote('cluster:12'));
        $this->assertSame(12, Clusterer::primaryFromNote('cluster:12; low-uniqueness (31%)'));
        $this->assertNull(Clusterer::primaryFromNote('evidence-only'));
    }

    public function testCollectorStoresDedupesAndClusters(): void
    {
        $collector = new NewsCollector();
        $text = ['name' => 'Örnek', 'type' => 'rss', 'url' => '', 'filter' => true, 'text' => true];
        $google = ['name' => 'Google Haberler', 'type' => 'google', 'url' => '', 'filter' => false, 'text' => false];
        $now = Date::now();
        $item = static fn (string $url, string $title, string $source = 'ornek', array $extra = []): array => $extra + [
            'source' => $source, 'source_name' => 'Örnek', 'url' => $url, 'title' => $title,
            'description' => 'Portekizli yıldız Cristiano Ronaldo ile ilgili gelişme.', 'content' => '',
            'image_url' => null, 'published_at' => $now, 'related_titles' => [],
        ];

        // Google knows the story first: evidence only.
        $this->assertSame('evidence', $collector->store($item('https://news.google.com/rss/articles/X1', "Cristiano Ronaldo'nun cezası belli oldu", 'google', [
            'related_titles' => ['Milli takım kampını terk eden Cristiano Ronaldo’ya ceza'],
        ]), $google));
        // First text source of the story is promoted to primary, the Google item becomes a member.
        $this->assertSame('promoted', $collector->store($item('https://a.test/1', 'Milli takım kampını terk eden Cristiano Ronaldo’ya ceza', 'ornek', ['content' => 'Tam metin paragrafı burada yer alıyor.']), $text));
        $primary = Database::fetchOne('SELECT * FROM news_items WHERE url = ?', ['https://a.test/1']);
        $this->cacheKeys[] = 'news:feedtext:' . $primary['url_hash'];
        $this->assertSame('pending', $primary['status']);
        $this->assertSame('Tam metin paragrafı burada yer alıyor.', Cache::get('news:feedtext:' . $primary['url_hash']), 'feed text cached for the publisher');
        $this->assertSame('cluster:' . $primary['id'], Database::fetchValue('SELECT note FROM news_items WHERE source = ?', ['google']));
        // Another outlet, same story => duplicate member of the cluster.
        $this->assertSame('duplicate', $collector->store($item('https://b.test/2', "Portekiz Milli Takımı kampını terk eden Ronaldo'nun cezası açıklandı!"), $text));
        $this->assertSame('cluster:' . $primary['id'], Database::fetchValue('SELECT note FROM news_items WHERE url = ?', ['https://b.test/2']));
        // Same URL again (tracking params differ only by scheme/www) => exists.
        $this->assertSame('exists', $collector->store($item('http://www.a.test/1/', 'Başka başlık Ronaldo'), $text));
        // Unrelated / irrelevant / too old.
        $this->assertSame('pending', $collector->store($item('https://c.test/3', "Cristiano Ronaldo'nun uyku sırrı ortaya çıktı"), $text));
        $this->assertSame('irrelevant', $collector->store($item('https://d.test/4', 'Galatasaray derbiye hazır', 'ornek', ['description' => 'Okan Buruk konuştu.']), $text));
        $this->assertSame('old', $collector->store($item('https://e.test/5', 'Ronaldo eski haber', 'ornek', ['published_at' => Date::ago(5 * 86400)]), $text));
        $this->assertSame(4, (int) Database::fetchValue('SELECT COUNT(*) FROM news_items'));

        $counts = (new NewsCollector())->storeAll([$item('https://f.test/6', 'CR7 yeni rekor'), $item('https://f.test/6', 'CR7 yeni rekor')], $text);
        $this->assertSame(['fetched' => 2, 'relevant' => 2, 'new' => 1, 'clustered' => 0], $counts);
    }

    // ------------------------------------------------------------------ extraction / tags

    public function testArticleExtractor(): void
    {
        $ld = ArticleExtractor::fromHtml(self::fixture('article-jsonld.html'));
        $this->assertSame('jsonld', $ld['method']);
        $this->assertSame("Ronaldo Al-Nassr'da rekor kırdı", $ld['title']);
        $this->assertTrue($ld['words'] > 120, 'JSON-LD articleBody read: ' . $ld['words']);
        $this->assertContains('“Cristiano her maçta bize liderlik ediyor.”', implode(' ', $ld['paragraphs']));

        $plain = ArticleExtractor::fromHtml(self::fixture('article-plain.html'));
        $text = implode("\n", $plain['paragraphs']);
        $this->assertSame('article', $plain['method']);
        $this->assertContains('Karşılaşmanın yıldızı yine Cristiano Ronaldo oldu.', $text);
        foreach (['YENİ REKOR GELDİ', 'Fotoğraf: AA', 'abone', 'paylaş', 'Çok okunan', 'Copyright'] as $junk) {
            $this->assertNotContains($junk, $text, 'boilerplate removed');
        }
        $this->assertNull(ArticleExtractor::cleanParagraph('Ronaldo ayrıldı mı, Ronaldo bıraktı mı, Portekiz son durum, Ronaldo haberleri, milli takım kampı, Jesus.'), 'keyword list dropped');
        $this->assertSame("Uluslar Ligi'nde 27 Eylül'deki Norveç maçında süre alamadı.", ArticleExtractor::cleanParagraph("NE OLMUŞTU? Uluslar Ligi'nde 27 Eylül'deki Norveç maçında süre alamadı."));

        // Turkish pages declaring windows-1254 / ISO-8859-9 in a <meta> (with or without the header).
        $body = '<article><p>Cristiano Ronaldo bugün sahadaydı; Şükrü, Ömer ve Gülşen tribünde onu izledi. '
            . implode(' ', array_map(static fn (int $i): string => 'kelime' . $i, range(1, 60))) . '</p></article>';
        foreach ([
            '<meta charset="windows-1254">',
            '<meta http-equiv="Content-Type" content="text/html; charset=iso-8859-9">',
        ] as $meta) {
            $html = mb_convert_encoding('<html><head>' . $meta . '<title>Başlık şöyle</title></head><body>' . $body . '</body></html>', 'Windows-1254', 'UTF-8');
            foreach (['text/html; charset=windows-1254', null] as $contentType) {
                $result = ArticleExtractor::fromHtml($html, $contentType);
                $this->assertSame('Başlık şöyle', $result['title'] ?? null, $meta . ' / ' . var_export($contentType, true));
                $this->assertContains('bugün sahadaydı; Şükrü, Ömer ve Gülşen', $result['paragraphs'][0] ?? '', $meta);
            }
        }
        $lying = ArticleExtractor::fromHtml('<html><head><meta charset="windows-1254"><title>Başlık şöyle</title></head><body>' . $body . '</body></html>');
        $this->assertSame('Başlık şöyle', $lying['title'] ?? null, 'UTF-8 bytes with a wrong charset declaration');
    }

    public function testTagger(): void
    {
        $tags = Tagger::tags("Al-Nassr'da Ronaldo şov", "Al-Nassr, Suudi Pro Lig'de Al-Hilal'i yendi. Messi bir kez geçti. Ronaldo sözleşmesini uzattı, yeni sözleşme imzalandı.");
        $names = array_column($tags, 'name');
        $this->assertSame('Al-Nassr', $names[0], 'title mention first');
        $this->assertContains('Transfer', $names, 'topic tag');
        $this->assertNotContains('Lionel Messi', $names, 'single passing mention is not a tag');
        $this->assertSame('al-nassr', $tags[0]['slug']);
        $this->assertSame('Al-Nassr, Transfer', Tagger::toColumn([['name' => 'Al-Nassr'], ['name' => 'Transfer']]));
        $this->assertSame([['name' => 'Ballon d’Or', 'slug' => 'ballon-dor', 'type' => 'award']], Tagger::fromColumn('Ballon d’Or, '));
        $this->assertSame('Portekiz Milli Takımı', Tagger::nameForSlug('portekiz-milli-takimi'));
        $this->assertSame('/takimlar/al-nassr', Tagger::clubPages()['Al-Nassr'] ?? null);
    }

    // ------------------------------------------------------------------ composing / publishing

    /** Two items of one story with article texts from the fixtures (cached as if fetched). */
    private function seedStory(): array
    {
        foreach ([self::URL_A => 'article-jsonld.html', self::URL_B => 'article-plain.html'] as $url => $file) {
            $key = 'news:article:' . sha1($url);
            $this->cacheKeys[] = $key;
            Cache::set($key, ArticleExtractor::fromHtml(self::fixture($file)), 600);
        }
        $now = Date::now();
        $a = Database::insert('news_items', [
            'source' => 'sozcu', 'source_name' => 'Örnek Haber', 'url' => self::URL_A, 'url_hash' => Clusterer::urlHash(self::URL_A),
            'title' => "Ronaldo Al-Nassr'da rekor kırdı", 'title_norm' => Str::normalize("Ronaldo Al-Nassr'da rekor kırdı"),
            'description' => 'Portekizli yıldız Cristiano Ronaldo, Al-Nassr formasıyla iki gol attı.', 'published_at' => $now, 'fetched_at' => $now, 'status' => 'pending',
        ]);
        $b = Database::insert('news_items', [
            'source' => 'ajansspor', 'source_name' => 'Başka Gazete', 'url' => self::URL_B, 'url_hash' => Clusterer::urlHash(self::URL_B),
            'title' => "Cristiano Ronaldo'dan iki gollü gece", 'title_norm' => Str::normalize("Cristiano Ronaldo'dan iki gollü gece"),
            'description' => 'Al-Nassr rakibini 3-1 mağlup etti.', 'published_at' => $now, 'fetched_at' => $now, 'status' => 'duplicate', 'note' => 'cluster:' . $a,
        ]);
        return [$a, $b];
    }

    public function testComposerBuildsUniqueMixedPost(): void
    {
        Config::set('spinner.min_words', 150);
        [$a] = $this->seedStory();
        $primary = Database::fetchOne('SELECT * FROM news_items WHERE id = ?', [$a]);
        $members = (new NewsPublisher())->members($primary);
        $result = (new ArticleComposer())->compose($primary, $members);
        $this->assertTrue($result['ok'], 'composed: ' . json_encode(array_diff_key($result, ['text' => 1, 'source_texts' => 1]), JSON_UNESCAPED_UNICODE));
        $this->assertTrue($result['uniqueness'] >= 40.0, 'uniqueness vs sources: ' . $result['uniqueness']);
        $this->assertTrue($result['words'] >= 150, 'words: ' . $result['words']);
        $this->assertSame(2, $result['source_count'], 'two outlets mixed');
        $this->assertTrue(Str::similarity($result['title'], "Ronaldo Al-Nassr'da rekor kırdı") < 0.7, 'title differs: ' . $result['title']);
        $this->assertTrue(mb_strlen($result['summary']) <= 160);
        $body = $result['body'];
        $this->assertContains('<p><strong>Kaynak:</strong> <a href="' . self::URL_A . '" rel="nofollow noopener" target="_blank">Örnek Haber</a>, <a href="' . self::URL_B . '"', $body);
        $this->assertContains('3-1', $body, 'scores preserved');
        $this->assertContains('/takimlar/al-nassr', $body, 'club background links the club page');
        $this->assertNotContains('<script', $body);
        $this->assertContains('Al-Nassr', array_column($result['tags'], 'name'));
        // Deterministic per url_hash.
        $again = (new ArticleComposer())->compose($primary, $members);
        $this->assertSame($result['body'], $again['body']);
        $this->assertSame($result['title'], $again['title']);
    }

    public function testComposerRejectsNearCopies(): void
    {
        Config::set('spinner.min_words', 150);
        Settings::set('spinner.min_change_ratio', 0.97);
        Settings::flush();
        [$a] = $this->seedStory();
        $primary = Database::fetchOne('SELECT * FROM news_items WHERE id = ?', [$a]);
        $result = (new ArticleComposer())->compose($primary, (new NewsPublisher())->members($primary));
        $this->assertFalse($result['ok']);
        $this->assertSame('low-uniqueness', $result['reason']);
    }

    public function testComposerRejectsOffTopicText(): void
    {
        $url = 'https://konu-disi.test/futbolcular-neden-tukuruyor';
        $key = 'news:article:' . sha1($url);
        $this->cacheKeys[] = $key;
        Cache::set($key, ['paragraphs' => [
            'Büyük turnuvalarda futbolcuların içecekleri ağızlarında çalkalayıp tükürdükleri görülüyor ve bu durum merak uyandırıyor.',
            'Uzmanlara göre karbonhidratla ağız çalkalama yöntemi, yüksek tempolu karşılaşmalarda performansı olumlu etkileyebiliyor ve yorgunluk hissini azaltabiliyor.',
        ], 'words' => 40, 'title' => null, 'method' => 'article'], 600);
        $item = ['id' => 1, 'source' => 'bing', 'source_name' => 'X', 'url' => $url, 'url_hash' => Clusterer::urlHash($url),
            'title' => 'Futbolcuların içtikleri sıvıyı neden tükürdükleri ortaya çıktı', 'description' => 'Cristiano Ronaldo gibi yıldızlar…', 'published_at' => Date::now(), 'note' => null, 'status' => 'pending', 'attempts' => 0];
        $this->assertSame('off-topic', (new ArticleComposer())->compose($item)['reason']);
    }

    public function testPublisherCreatesPostAndRespectsLimits(): void
    {
        Config::set('spinner.min_words', 150);
        Settings::set('news.min_interval_minutes', 0);
        Settings::set('news.publish_window', [0, 24]);
        Settings::set('news.daily_limit', 1);
        Settings::flush();
        [$a, $b] = $this->seedStory();

        $publisher = new NewsPublisher();
        $postId = $publisher->publishNext();
        $this->assertTrue($postId !== null, 'published: ' . $publisher->lastMessage());
        $post = Database::fetchOne('SELECT * FROM posts WHERE id = ?', [$postId]);
        $this->assertSame(1, (int) $post['is_spun']);
        $this->assertSame(1, (int) $post['is_indexable']);
        $this->assertSame('haber', $post['category']);
        $this->assertSame(2, (int) $post['source_count']);
        $this->assertTrue((float) $post['uniqueness'] >= 40.0);
        $this->assertSame((int) $a, (int) $post['news_item_id']);
        $this->assertSame(Str::slug($post['title'], 80), $post['slug']);
        $this->assertTrue(is_file(App::basePath('public' . $post['image_path'])), 'OG image generated');
        $size = getimagesize(App::basePath('public' . $post['image_path']));
        $this->assertSame([1200, 630], [$size[0], $size[1]]);
        $this->assertSame('published', Database::fetchValue('SELECT status FROM news_items WHERE id = ?', [$a]));
        $this->assertSame('duplicate', Database::fetchValue('SELECT status FROM news_items WHERE id = ?', [$b]));

        // Daily limit (Istanbul day) reached.
        $other = Database::insert('news_items', [
            'source' => 'sozcu', 'source_name' => 'X', 'url' => 'https://x.test/3', 'url_hash' => Clusterer::urlHash('https://x.test/3'),
            'title' => 'Ronaldo başka bir haber', 'title_norm' => 'ronaldo baska bir haber', 'published_at' => Date::now(), 'fetched_at' => Date::now(), 'status' => 'pending',
        ]);
        $noText = 'news:article:' . sha1('https://x.test/3');
        $this->cacheKeys[] = $noText;
        Cache::set($noText, ['paragraphs' => [], 'words' => 0, 'title' => null, 'method' => 'none'], 600); // no network in tests
        $this->assertNull($publisher->publishNext());
        $this->assertContains('Günlük limit doldu (1/1)', $publisher->lastMessage());
        // Interval and window.
        Settings::set('news.daily_limit', 10);
        Settings::set('news.min_interval_minutes', 60);
        Settings::flush();
        $this->assertContains('Asgari yayın aralığı', $publisher->gate()['reason']);
        Settings::set('news.min_interval_minutes', 0);
        $hour = (int) Date::local()->format('G');
        Settings::set('news.publish_window', [($hour + 1) % 24, ($hour + 2) % 24]);
        Settings::flush();
        $this->assertContains('Yayın penceresi dışında', $publisher->gate()['reason']);
        Settings::set('news.publish_window', [$hour, $hour]);
        Settings::flush();
        $this->assertTrue($publisher->gate()['ok'], 'equal window bounds = all day');
        Config::set('spinner.enabled', false);
        $this->assertContains('Otomatik yayın kapalı', $publisher->gate()['reason'], 'spinner.enabled=false stops automatic publishing');
        Config::set('spinner.enabled', true);
        Settings::set('news.publish_window', [($hour + 1) % 24, ($hour + 2) % 24]);
        Settings::flush();
        // Forced publishing ignores the gate but still needs text: x.test/3 has none => no post.
        $this->assertNull($publisher->publishItem($other, true));
        $this->assertSame((int) $postId, $publisher->publishItem($a, true), 'already published item returns its post');

        // regenerateImage keeps a valid image; a new title gets a new file name (share caches refresh).
        $this->assertSame($post['image_path'], $publisher->regenerateImage((int) $postId));
        Database::update('posts', ['title' => 'Cristiano Ronaldo için yeni başlık'], 'id = ?', [$postId]);
        $newPath = $publisher->regenerateImage((int) $postId);
        $this->assertTrue($newPath !== null && $newPath !== $post['image_path'], 'new image name');
        $this->assertFalse(is_file(App::basePath('public' . $post['image_path'])), 'old image removed');
        $this->assertTrue(is_file(App::basePath('public' . $newPath)));
        $this->assertNull($publisher->regenerateImage(999));
        [$from, $to] = NewsPublisher::dayBoundsUtc();
        $this->assertSame(86400, strtotime($to . ' UTC') - strtotime($from . ' UTC'));
    }

    public function testSpunPostsCanBeNoindex(): void
    {
        Config::set('spinner.min_words', 150);
        Settings::set('news.index_spun_posts', false);
        Settings::flush();
        [$a] = $this->seedStory();
        $postId = (new NewsPublisher())->publishItem($a, true);
        $this->assertTrue($postId !== null);
        $this->assertSame(0, (int) Database::fetchValue('SELECT is_indexable FROM posts WHERE id = ?', [$postId]));
        $this->assertSame([], (new NewsRepository())->forSitemap(), 'noindex posts are not in the sitemap');
        $response = (new NewsController())->show(self::request('/haber/' . Database::fetchValue('SELECT slug FROM posts WHERE id = ?', [$postId])));
        $this->assertContains('<meta name="robots" content="noindex,follow">', $response->body());
    }

    public function testCopyCheckNoindexesNearVerbatimPosts(): void
    {
        $source = 'Medyada çıkan haberler, İtalyan Milan kulübünün mevcut sahibi Amerikan şirketi RedBird Capital’in Al Nassr kulübünün hisselerinin yüzde 70’ini almak istediğini ortaya koymuştu. Ronaldo ise sessiz kaldı.';
        $copied = '<p>Basında yer alan haberler, İtalyan Milan kulübünün mevcut sahibi Amerikan şirketi "RedBird Capital"in Al Nassr kulübünün hisselerinin yüzde 70’ini almak istediğini gösterdi.</p>';
        $report = CopyCheck::measure($copied, [$source]);
        $this->assertFalse($report['ok'], 'long verbatim run fails: ' . json_encode($report, JSON_UNESCAPED_UNICODE));
        $this->assertTrue($report['run'] >= 18, 'a short quoted name does not break the run: ' . $report['run']);
        $this->assertTrue($report['shared'] > 0.5, 'shared 6-grams: ' . $report['shared']);
        // Quotations (4+ words) stay verbatim by design and are not counted.
        $quote = '<p>Ronaldo konuya ilişkin “Medyada çıkan haberler, İtalyan Milan kulübünün mevcut sahibi Amerikan şirketi” ifadesini kullandı.</p>';
        $this->assertSame(0.0, CopyCheck::measure($quote, [$source])['shared']);
        $this->assertTrue(CopyCheck::measure($quote, [$source])['ok']);
        $this->assertTrue(CopyCheck::measure('<p>Portekizli yıldız dün akşam iki gol atarak takımını galibiyete taşıdı.</p>', [$source])['ok']);

        // Publisher: a post failing the gate is published noindex, kept out of the sitemap, noted in the queue.
        Config::set('spinner.min_words', 150);
        Settings::set('spinner.max_verbatim_words', 1);
        Settings::flush();
        [$a] = $this->seedStory();
        $publisher = new NewsPublisher();
        $postId = $publisher->publishItem($a, true);
        $this->assertTrue($postId !== null, $publisher->lastMessage());
        $this->assertSame(0, (int) Database::fetchValue('SELECT is_indexable FROM posts WHERE id = ?', [$postId]));
        $this->assertSame([], (new NewsRepository())->forSitemap());
        $this->assertContains('noindex: kaynakla ortak', (string) Database::fetchValue('SELECT note FROM news_items WHERE id = ?', [$a]));
        $this->assertContains('Noindex:', $publisher->lastMessage());
    }

    public function testPostMetaTitleAndDescriptionLengths(): void
    {
        $long = "Cristiano Ronaldo'ya 38 saniyede ödeyeceği ceza: 255 euro para ve 30 gün men cezası";
        $this->assertSame("Cristiano Ronaldo'ya 38 saniyede ödeyeceği ceza", PostMeta::title(null, $long), 'clause before the colon');
        $this->assertSame("Ten Hag'dan Ronaldo'ya sert sözler", PostMeta::title(null, "Spor gündemi: Ten Hag'dan Ronaldo'ya sert sözler"), 'filler frame dropped');
        $this->assertSame('Kısa başlık', PostMeta::title('Kısa başlık', $long), 'meta_title wins');
        $cut = PostMeta::title(null, 'Futbol gündemi: Cristiano Ronaldo’nun Al Nassr’ın sahibi olduğu iddiasının gerçeği nihayet ortaya çıktı');
        $this->assertTrue(mb_strlen($cut) <= 60 && str_starts_with($cut, 'Cristiano Ronaldo'), $cut);
        foreach (["Ronaldo'dan rekor", str_repeat('Ronaldo gol attı ', 8)] as $title) {
            $this->assertTrue(mb_strlen(PostMeta::title(null, $title)) <= 60);
        }

        // Whole sentences (120–160) instead of a hard cut; the stored summary may end with '…'.
        $body = '<p>Spor gündeminde bu kez Cristiano Ronaldo var.</p><p>Cristiano Ronaldo, Al-Nassr formasıyla çıktığı maçta iki gol attı ve takımını galibiyete taşıdı. '
            . 'Portekizli yıldız gol sayısını 960’a çıkardı. Teknik direktör maç sonunda açıklama yaptı.</p><p><strong>Kaynak:</strong> <a href="https://x.test">X</a></p>';
        $description = PostMeta::description('Cristiano Ronaldo, Al-Nassr formasıyla çıktığı maçta iki gol attı ve takımını…', $body);
        $this->assertSame('Cristiano Ronaldo, Al-Nassr formasıyla çıktığı maçta iki gol attı ve takımını galibiyete taşıdı. Portekizli yıldız gol sayısını 960’a çıkardı.', $description);
        $this->assertTrue(mb_strlen($description) >= 120 && mb_strlen($description) <= 160, (string) mb_strlen($description));
        $this->assertTrue(mb_strlen(PostMeta::description(str_repeat('Ronaldo uzun bir cümle kurdu ', 10) . '.', '')) <= 160, 'one long sentence is cut at a word boundary');
    }

    // ------------------------------------------------------------------ repository / pages / feed

    private function insertPost(string $title, array $extra = []): int
    {
        $now = Date::now();
        return Database::insert('posts', $extra + [
            'slug' => Str::slug($title), 'title' => $title, 'summary' => $title . ' özet metni.', 'body' => '<p>' . e($title) . ' gövde.</p><p><strong>Kaynak:</strong> <a href="https://kaynak.test/a" rel="nofollow noopener" target="_blank">Kaynak Gazete</a></p>',
            'tags' => 'Al-Nassr, Suudi Pro Lig', 'category' => 'haber', 'source_name' => 'Kaynak Gazete', 'source_url' => 'https://kaynak.test/a',
            'is_spun' => 1, 'uniqueness' => 55.5, 'source_count' => 1, 'is_indexable' => 1, 'status' => 'published',
            'published_at' => $now, 'updated_at' => $now, 'created_at' => $now,
        ]);
    }

    private static function request(string $uri, array $params = [], string $ua = 'Mozilla/5.0 (Windows NT 10.0) Chrome/120'): Request
    {
        $request = Request::create('GET', $uri, [], ['HTTP_USER_AGENT' => $ua]);
        $path = (string) parse_url($uri, PHP_URL_PATH);
        if ($params === [] && preg_match('#^/(?:haber|haberler/etiket)/([a-z0-9-]+)$#', $path, $m) === 1) {
            $params = ['slug' => $m[1]];
        }
        $request->setParams($params);
        App::setRequest($request);
        return $request;
    }

    public function testRepositoryContract(): void
    {
        $repo = new NewsRepository();
        $first = $this->insertPost('Ronaldo Al-Nassr ile rekor kırdı', ['published_at' => Date::ago(3600)]);
        $second = $this->insertPost('Ronaldo sözleşme uzattı', ['tags' => 'Al-Nassr, Transfer']);
        $this->insertPost('Taslak haber', ['status' => 'draft']);
        $this->insertPost('Gelecek haber', ['published_at' => gmdate('Y-m-d H:i:s', time() + 7200)]);
        $this->insertPost('Gizli haber Ronaldo', ['is_indexable' => 0, 'published_at' => Date::ago(7200)]);

        $this->assertSame(3, $repo->count(), 'drafts and scheduled posts hidden');
        $latest = $repo->latest(2);
        $this->assertSame($second, $latest[0]['id']);
        $this->assertSame(['id', 'slug', 'title', 'summary', 'image_url', 'published_at', 'updated_at', 'source_name', 'tags', 'url'], array_keys($latest[0]));
        $this->assertSame('/haber/ronaldo-sozlesme-uzatti', $latest[0]['url']);
        $this->assertSame($first, $repo->latest(1, 1)[0]['id'], 'offset');
        $this->assertNull($repo->findBySlug('taslak-haber'));
        $this->assertSame('Ronaldo Al-Nassr ile rekor kırdı', $repo->findBySlug('ronaldo-al-nassr-ile-rekor-kirdi')['title']);
        $this->assertSame([$second], array_column($repo->search('sözleşme Ronaldo'), 'id'));
        $this->assertSame([], $repo->search('x'));
        // SQLite LIKE folds ASCII only: Turkish-cased variants make the search case-insensitive.
        $turkish = $this->insertPost('ŞAMPİYONLAR LİGİ’NDE ÇILGIN GECE: Ronaldo İzmir’de');
        foreach (['şampiyonlar', 'Şampiyonlar', 'ŞAMPİYONLAR', 'çılgın', 'izmir', 'İZMİR'] as $q) {
            $this->assertSame([$turkish], array_column($repo->search($q), 'id'), 'Turkish case-insensitive search: ' . $q);
        }
        [$sql, $params] = Database::containsAny(['title'], 'şampiyon');
        $this->assertSame("(title LIKE ? ESCAPE '\\' OR title LIKE ? ESCAPE '\\' OR title LIKE ? ESCAPE '\\')", $sql);
        $this->assertSame(['%şampiyon%', '%ŞAMPİYON%', '%Şampiyon%'], $params);
        $this->assertSame(['%50\\%%'], Database::containsAny(['title'], '50%')[1], 'ASCII terms: one escaped LIKE');
        Database::execute('DELETE FROM posts WHERE id = ?', [$turkish]);
        $this->assertCount(2, $repo->forSitemap(), 'indexable only');
        $this->assertSame(['url', 'lastmod', 'image'], array_keys($repo->forSitemap()[0]));
        $this->assertSame(['url', 'title', 'published_at'], array_keys($repo->forNewsSitemap()[0]));
        $this->assertSame(3, $repo->countByTag('al-nassr'));
        $this->assertSame(1, $repo->countByTag('transfer'));
        $this->assertSame(0, $repo->countByTag('yok-boyle'));
        $this->assertSame('Suudi Pro Lig', $repo->tagName('suudi-pro-lig'));
        $related = $repo->related($repo->findBySlug('ronaldo-sozlesme-uzatti'), 3);
        $this->assertSame($first, $related[0]['id']);
        $this->assertNotContains($second, array_column($related, 'id'));
        $this->assertSame('ronaldo-sozlesme-uzatti-2', $repo->uniqueSlug('Ronaldo sözleşme uzattı'));
        $this->assertSame(0, $repo->countSpunBetween(Date::ago(86400), gmdate('Y-m-d H:i:s', time() + 86400)), 'posts without a news item are not counted for the daily limit');
    }

    public function testNewsPages(): void
    {
        $controller = new NewsController();
        $empty = $controller->index(self::request('/haberler'));
        $this->assertSame(200, $empty->status());
        $this->assertContains('Henüz haber yok', $empty->body());

        for ($i = 1; $i <= 13; $i++) {
            $this->insertPost("Ronaldo haber {$i} başlığı", ['published_at' => Date::ago($i * 60)]);
        }
        $page1 = $controller->index(self::request('/haberler'));
        $html = $page1->body();
        $this->assertSame(1, substr_count($html, '<h1'), 'one h1');
        $this->assertContains('<link rel="canonical" href="' . url('/haberler') . '">', $html);
        $this->assertContains('<link rel="next" href="' . url('/haberler?sayfa=2') . '">', $html);
        $this->assertContains('"@type":"ItemList"', $html);
        $this->assertContains('"@type":"BreadcrumbList"', $html);
        $this->assertSame(12, substr_count($html, 'class="card news-card"'));
        $page2 = $controller->index(self::request('/haberler?sayfa=2'))->body();
        $this->assertContains('<link rel="canonical" href="' . url('/haberler?sayfa=2') . '">', $page2);
        $this->assertContains('<link rel="prev" href="' . url('/haberler') . '">', $page2);
        $this->assertSame(404, $controller->index(self::request('/haberler?sayfa=3'))->status());
        $this->assertSame(404, $controller->index(self::request('/haberler?sayfa=0'))->status());
        $first = $controller->index(self::request('/haberler?sayfa=1'));
        $this->assertSame(301, $first->status(), '?sayfa=1 is page 1: one URL');
        $this->assertSame(url('/haberler'), $first->header('Location'));
        $this->assertSame(url('/haberler/etiket/al-nassr'), $controller->tag(self::request('/haberler/etiket/al-nassr?sayfa=1', ['slug' => 'al-nassr']))->header('Location'));

        $slug = 'ronaldo-haber-1-basligi';
        $show = $controller->show(self::request('/haber/' . $slug));
        $html = $show->body();
        $this->assertSame(200, $show->status());
        $this->assertSame(1, substr_count($html, '<h1'));
        $this->assertContains('"@type":"NewsArticle"', $html);
        $this->assertContains('"author":{"@type":"Organization","name":"CR7.com.tr"', $html);
        $this->assertContains('"mainEntityOfPage"', $html);
        $this->assertContains('<meta property="og:type" content="article">', $html);
        $this->assertContains('href="' . url('/haberler/etiket/al-nassr') . '"', $html, 'tag links');
        $this->assertContains('href="' . url('/takimlar/al-nassr') . '"', $html, 'club page link');
        $this->assertContains('href="' . url('/istatistikler') . '"', $html, 'stats link');
        $this->assertContains('Kaynak Gazete', $html, 'source credit');
        $this->assertSame(1, (int) Database::fetchValue('SELECT views FROM posts WHERE slug = ?', [$slug]));
        $controller->show(self::request('/haber/' . $slug, [], 'Googlebot/2.1 (+http://www.google.com/bot.html)'));
        $this->assertSame(1, (int) Database::fetchValue('SELECT views FROM posts WHERE slug = ?', [$slug]), 'bots are not counted');
        $this->assertSame(404, $controller->show(self::request('/haber/yok-boyle'))->status());

        $tag = $controller->tag(self::request('/haberler/etiket/al-nassr'));
        $this->assertSame(200, $tag->status());
        $this->assertContains('Al-Nassr Haberleri', $tag->body());
        $this->assertContains('<meta name="robots" content="index,follow', $tag->body());
        Database::execute("UPDATE posts SET tags = 'Al-Nassr, Rekor' WHERE slug = ?", [$slug]);
        $thin = $controller->tag(self::request('/haberler/etiket/rekor'));
        $this->assertContains('<meta name="robots" content="noindex,follow">', $thin->body(), 'tag with < 3 posts is noindex');
        $this->assertSame(404, $controller->tag(self::request('/haberler/etiket/yok-boyle'))->status());
        $this->assertTrue(NewsController::isBot(''));
        $this->assertFalse(NewsController::isBot('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0) Safari/604.1'));
    }

    public function testMissingImageFileFallsBackToPlaceholder(): void
    {
        $this->assertNull(NewsRepository::imageUrl(null));
        $this->assertNull(NewsRepository::imageUrl(''));
        $this->assertNull(NewsRepository::imageUrl('/og/haber-silinmis-gorsel-000000.png'), 'missing local file');
        $this->assertSame(url('/assets/img/og-default.png'), NewsRepository::imageUrl('/assets/img/og-default.png'));
        $this->assertSame('https://cdn.example.com/a.jpg', NewsRepository::imageUrl('https://cdn.example.com/a.jpg'), 'remote URLs are kept');

        $this->insertPost('Ronaldo görseli kayıp haber', ['image_path' => '/og/haber-silinmis-gorsel-000000.png']);
        $repo = new NewsRepository();
        $this->assertNull($repo->latest(1)[0]['image_url']);
        $this->assertNull($repo->findBySlug('ronaldo-gorseli-kayip-haber')['image_url']);
        $this->assertNull($repo->forSitemap()[0]['image']);
        $controller = new NewsController();
        $list = $controller->index(self::request('/haberler'))->body();
        $this->assertContains('card-media is-placeholder', $list, 'card renders the placeholder');
        $this->assertNotContains('haber-silinmis-gorsel', $list);
        $show = $controller->show(self::request('/haber/ronaldo-gorseli-kayip-haber'))->body();
        $this->assertNotContains('haber-silinmis-gorsel', $show, 'no broken hero image or og:image');
        $this->assertContains('<meta property="og:image" content="' . url('/assets/img/og-default.png') . '">', $show);
    }

    public function testRssFeedAndIndexNowKey(): void
    {
        $this->insertPost('Ronaldo & Messi: “rekor” <gece>');
        $this->insertPost('Gizli Ronaldo', ['is_indexable' => 0]);
        $feed = (new FeedController())->rss(self::request('/rss.xml'));
        $this->assertSame('application/rss+xml; charset=utf-8', $feed->header('Content-Type'));
        $doc = new \DOMDocument();
        $this->assertTrue($doc->loadXML($feed->body()), 'valid XML');
        $this->assertSame(1, $doc->getElementsByTagName('item')->length, 'indexable posts only');
        $this->assertSame('Ronaldo & Messi: “rekor” <gece>', $doc->getElementsByTagName('item')->item(0)->getElementsByTagName('title')->item(0)->textContent);
        $this->assertContains('<atom:link href="' . url('/rss.xml') . '" rel="self" type="application/rss+xml"/>', $feed->body());
        $this->assertContains('<lastBuildDate>', $feed->body());
        $this->assertContains('<category>Al-Nassr</category>', $feed->body());

        $this->assertSame(404, (new FeedController())->indexNowKey(self::request('/indexnow-key.txt'))->status());
        Settings::set('indexnow.key', 'abc123def456ghi7');
        Settings::flush();
        $key = (new FeedController())->indexNowKey(self::request('/indexnow-key.txt'));
        $this->assertSame(200, $key->status());
        $this->assertSame('abc123def456ghi7', $key->body());
        // Never pings from a local/dev host (tests run with a local app.url or none).
        Config::set('app.url', 'http://127.0.0.1:8080');
        $this->assertFalse(IndexNow::ping([url('/haber/x')]));
        $this->assertTrue(IndexNow::isLocalHost('localhost') && IndexNow::isLocalHost('192.168.1.5') && !IndexNow::isLocalHost('cr7.com.tr'));
    }

    public function testOgImageNamesAreUniquePerPostAndSharedFilesSurvive(): void
    {
        $title = 'Cristiano Ronaldo Portekiz Milli Takımı kampından ayrıldıktan sonra federasyonun verdiği cezayla ilgili ilk kez konuştu ve açıklamalar yaptı';
        $slug = Str::slug($title, 190);
        $this->assertFalse(NewsPublisher::imageName($slug, $title, 10) === NewsPublisher::imageName($slug . '-2', $title, 11), 'same long title, two posts');
        $this->assertTrue(strlen(NewsPublisher::imageName($slug, $title, 10)) <= 120, 'fits OgImage::generate');

        // A file two posts share (names made before the id was part of them) outlives deleting one of them.
        $path = OgImage::generate($title, '20 Ekim 2026', 'haber-test-ortak-gorsel');
        $first = $this->insertPost('Ortak görsel 1', ['image_path' => $path]);
        $second = $this->insertPost('Ortak görsel 2', ['image_path' => $path]);
        Database::execute('DELETE FROM posts WHERE id = ?', [$first]);
        NewsPublisher::deleteImage($path, $first);
        $this->assertTrue(is_file(App::basePath('public' . $path)), 'still used by the other post');
        Database::execute('DELETE FROM posts WHERE id = ?', [$second]);
        NewsPublisher::deleteImage($path, $second);
        $this->assertFalse(is_file(App::basePath('public' . $path)), 'removed with its last post');
    }

    public function testOgImage(): void
    {
        $path = OgImage::generate('Cristiano Ronaldo’ya 30 gün hak mahrumiyeti: Portekiz Futbol Federasyonu kararını açıkladı, işte bütün ayrıntılar ve tepkiler', '20 Ekim 2026', 'test-og-gorsel');
        $this->assertSame('/og/test-og-gorsel.png', $path);
        $file = App::basePath('public/og/test-og-gorsel.png');
        $size = getimagesize($file);
        $this->assertSame([1200, 630, 'image/png'], [$size[0], $size[1], $size['mime']]);
        // 640 px JPEG card for listings (srcset), written next to the PNG.
        $this->assertSame('/og/test-og-gorsel-640.jpg', OgImage::variant($path));
        $small = getimagesize(App::basePath('public/og/test-og-gorsel-640.jpg'));
        $this->assertSame([640, 336, 'image/jpeg'], [$small[0], $small[1], $small['mime']]);
        $this->assertTrue(filesize(App::basePath('public/og/test-og-gorsel-640.jpg')) < filesize($file), 'smaller than the PNG');
        $this->assertNull(OgImage::variant('/assets/img/og-default.png'), 'only generated /og/ images');
        // The first listing card is the LCP image: eager + fetchpriority=high, with the small card in srcset.
        $first = partial('partials/news-card', ['post' => ['title' => 'Ronaldo', 'url' => '/haber/x', 'image_url' => url($path)], 'priority' => true]);
        $this->assertContains('fetchpriority="high"', $first);
        $this->assertNotContains('loading="lazy"', $first);
        $this->assertContains('srcset="' . url('/og/test-og-gorsel-640.jpg') . ' 640w, ' . url($path) . ' 1200w"', $first);
        $this->assertContains('loading="lazy"', partial('partials/news-card', ['post' => ['title' => 'Ronaldo', 'url' => '/haber/x', 'image_url' => url($path)]]));
        $this->assertNotContains('loading="lazy"', partial('partials/news-card', ['post' => ['title' => 'Ronaldo', 'url' => '/haber/x', 'image_url' => url($path)], 'lazy' => false]));
        OgImage::delete($path);
        $this->assertFalse(is_file($file));
        $this->assertFalse(is_file(App::basePath('public/og/test-og-gorsel-640.jpg')), 'variant deleted with the image');
        OgImage::delete('/../config/config.example.php');
        $this->assertTrue(is_file(App::basePath('config/config.example.php')), 'delete is confined to public/og');
        $lines = OgImage::wrap('bir iki üç dört beş altı yedi', App::basePath('public/assets/fonts/DejaVuSans-Bold.ttf'), 40, 300);
        $this->assertTrue(count($lines) > 1);
    }
}
