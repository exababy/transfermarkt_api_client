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
use App\Services\News\FeedParser;
use App\Services\News\Fetchers\BingFetcher;
use App\Services\News\Fetchers\GoogleFetcher;
use App\Services\News\Fetchers\RssFetcher;
use App\Services\News\IndexNow;
use App\Services\News\NewsCollector;
use App\Services\News\NewsPublisher;
use App\Services\News\RelevanceFilter;
use App\Services\News\SourceRegistry;
use App\Services\News\Spinner;
use App\Services\News\Tagger;
use App\Support\Cache;
use App\Support\Date;
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

    public function testOgImage(): void
    {
        $path = OgImage::generate('Cristiano Ronaldo’ya 30 gün hak mahrumiyeti: Portekiz Futbol Federasyonu kararını açıkladı, işte bütün ayrıntılar ve tepkiler', '20 Ekim 2026', 'test-og-gorsel');
        $this->assertSame('/og/test-og-gorsel.png', $path);
        $file = App::basePath('public/og/test-og-gorsel.png');
        $size = getimagesize($file);
        $this->assertSame([1200, 630, 'image/png'], [$size[0], $size[1], $size['mime']]);
        OgImage::delete($path);
        $this->assertFalse(is_file($file));
        OgImage::delete('/../config/config.example.php');
        $this->assertTrue(is_file(App::basePath('config/config.example.php')), 'delete is confined to public/og');
        $lines = OgImage::wrap('bir iki üç dört beş altı yedi', App::basePath('public/assets/fonts/DejaVuSans-Bold.ttf'), 40, 300);
        $this->assertTrue(count($lines) > 1);
    }
}
