<?php

declare(strict_types=1);

namespace Tests;

use App\Core\App;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Cron\Tasks\VideoSyncTask;
use App\Install\Seeders\VideoSeeder;
use App\Repositories\VideoRepository;
use App\Services\Video\Categorizer;
use App\Services\Video\VideoImporter;
use App\Services\Video\VideoSync;
use App\Services\Video\VideoText;
use App\Services\Video\YouTubeApi;
use App\Services\Video\YouTubeFeed;
use App\Support\Cache;

final class VideoTest extends TestCase
{
    private const UR = 'UCtxD0x6AuNNqdXO9Wp5GHew';
    private const JUVE = 'UCLzKhsxrExAC6yAdtZ-BOWw';

    protected function setUp(): void
    {
        Cache::forget('videos.rejected');
    }

    protected function tearDown(): void
    {
        Cache::forget('videos.rejected');
    }

    // ------------------------------------------------------------------ parsing helpers

    public function testParseIdAcceptsEveryYoutubeUrlForm(): void
    {
        $id = 'c_JlCriK3fY';
        foreach ([
            $id,
            " {$id} ",
            "https://www.youtube.com/watch?v={$id}",
            "https://www.youtube.com/watch?feature=share&v={$id}&t=42s",
            "http://youtube.com/watch?v={$id}",
            "https://m.youtube.com/watch?v={$id}&list=PL123",
            "https://music.youtube.com/watch?v={$id}",
            "www.youtube.com/watch?v={$id}",
            "https://youtu.be/{$id}",
            "https://youtu.be/{$id}?si=abc&t=10",
            "https://www.youtube.com/shorts/{$id}",
            "https://youtube.com/shorts/{$id}?feature=share",
            "https://www.youtube.com/embed/{$id}?autoplay=1",
            "https://www.youtube-nocookie.com/embed/{$id}",
            "https://www.youtube.com/live/{$id}?si=x",
            "https://www.youtube.com/v/{$id}?version=3",
            'https://www.youtube.com/attribution_link?u=' . rawurlencode("/watch?v={$id}&feature=share"),
            "https://www.youtube.com/watch?v={$id}&amp;t=5",
        ] as $input) {
            $this->assertSame($id, VideoText::parseId($input), $input);
        }
        foreach (['', 'abc', 'https://vimeo.com/123456789', 'https://example.com/watch?v=c_JlCriK3fY', 'https://www.youtube.com/channel/UCtxD0x6AuNNqdXO9Wp5GHew',
            'https://www.youtube.com/watch?v=short', 'javascript:alert(1)', 'https://youtu.be/', 'c_JlCriK3fY!'] as $bad) {
            $this->assertNull(VideoText::parseId($bad), $bad);
        }
    }

    public function testDurationsAndTitleCleanup(): void
    {
        $this->assertSame(3723, YouTubeApi::parseDuration('PT1H2M3S'));
        $this->assertSame(104, VideoText::parseIsoDuration('PT1M44S'));
        $this->assertSame(90061, VideoText::parseIsoDuration('P1DT1H1M1S'));
        $this->assertNull(VideoText::parseIsoDuration('P0D'));
        $this->assertNull(VideoText::parseIsoDuration('1:44'));
        $this->assertSame('PT1H2M3S', VideoText::isoDuration(3723));
        $this->assertSame('PT1M44S', VideoText::isoDuration(104));
        $this->assertSame('PT2H', VideoText::isoDuration(7200));
        $this->assertSame('PT45S', VideoText::isoDuration(45));
        $this->assertNull(VideoText::isoDuration(null));
        $this->assertSame('1:02:03', VideoText::clock(3723));
        $this->assertSame('1:44', VideoText::clock(104));
        $this->assertSame('', VideoText::clock(0));
        $this->assertSame('1 sa 36 dk', VideoText::humanDuration(5772));
        $this->assertSame('1 dk 44 sn', VideoText::humanDuration(104));

        $this->assertSame('Ronaldo scored the goal number 980 🐐⚽️', VideoText::cleanTitle('#Ronaldo scored the goal number 980 🐐⚽️ #shorts #alnassr #النصر'));
        $this->assertSame('Ronaldo & Benzema', VideoText::cleanTitle("  Ronaldo &amp; Benzema\n "));
        $this->assertSame('shorts', VideoText::cleanTitle('#shorts'), 'a title made only of hashtags is not emptied');
    }

    public function testSlugsAreTurkishAndUnique(): void
    {
        $this->assertSame('ronaldonun-juventusa-attigi-efsane-rovasata-golu-2018-cjlcr', VideoText::slug('Ronaldo’nun Juventus’a Attığı Efsane Rövaşata Golü (2018)', 'c_JlCriK3fY'));
        $this->assertSame('cristiano-ronaldo-video-gxwrq', VideoText::slug('كواليس مباراة العودة امام الدرعية والانتصار بثلاثية رونالدو', 'gXWRqIDTNvs'));
        $this->assertSame('ronaldo-alhamdan-seal-the-win-fdej5', VideoText::slug('كواليس فوزنا بهدفي رونالدو والحمدان 💛 Ronaldo & Alhamdan Seal the Win', 'fDej5TKXgUk'));
        $this->assertSame('video-abcde', VideoText::slug('!!!', 'AbCdE123456'));

        $repo = new VideoRepository();
        $first = $repo->create(['youtube_id' => 'AbCdE111111', 'title' => 'Aynı Başlık']);
        $second = $repo->create(['youtube_id' => 'abcde222222', 'title' => 'Aynı Başlık']);
        $this->assertSame('ayni-baslik-abcde', $repo->find($first)['slug']);
        $this->assertSame('ayni-baslik-abcde222', $repo->find($second)['slug'], 'fragment grows on collision');
    }

    public function testDescriptionHtmlEscapesAndLinkifiesSafely(): void
    {
        $html = VideoText::descriptionHtml("Gol! <script>alert(1)</script>\nİzle: https://www.uefa.com/uefachampionsleague/?a=1&b=2.\n\"javascript:alert(1)\" www.example.com");
        $this->assertNotContains('<script', $html);
        $this->assertContains('&lt;script&gt;', $html);
        $this->assertContains('<a href="https://www.uefa.com/uefachampionsleague/?a=1&amp;b=2" rel="nofollow noopener ugc" target="_blank">', $html);
        $this->assertContains('</a>.<br>', $html, 'trailing punctuation stays outside the link');
        $this->assertNotContains('href="javascript', $html);
        $this->assertSame(1, substr_count($html, '<a '), 'only http(s) URLs become links');
        $this->assertSame('', VideoText::descriptionHtml('   '));
    }

    // ------------------------------------------------------------------ categorizer

    public function testCategorizerRules(): void
    {
        $cases = [
            ['Cristiano Ronaldo’s amazing bicycle kick!', 'goller'],
            ['RONALDO’NUN MÜTHİŞ FRİKİK GOLÜ', 'goller'],
            ['Ronaldo hat-trick vs Spain', 'goller'],
            ['أهداف مباراة النصر 3 - 0 الدرعية', 'goller'],
            ['Portugal 3-3 Spain | Extended Highlights', 'mac-ozetleri'],
            ['MAÇ ÖZETİ: Al Nassr 2-1 Al Hilal', 'mac-ozetleri'],
            ['Juventus 3-0 Atletico | Ronaldo hat-trick | HIGHLIGHTS', 'mac-ozetleri'],
            ['Liga BPI | Resumo | Sporting CP 3 - 2', 'mac-ozetleri'],
            ['CRISTIANO RONALDO INTERVIEW AFTER THE GAME', 'roportajlar'],
            ['Ronaldo’dan basın toplantısında flaş açıklama', 'roportajlar'],
            ['Portugal On Playing Spain | Cristiano Ronaldo & Roberto Martinez Take Questions', 'roportajlar'],
            ['Cristiano Ronaldo completed Monday’s training session', 'antrenman'],
            ['Ronaldo İDMANDA ter döktü', 'antrenman'],
            ['Cristiano Ronaldo: The Documentary', 'belgesel'],
            ['Ronaldo Golden Boot ceremony', 'genel'],
            ['Goalkeeper training with Ronaldo', 'antrenman'],
            ['Cristiano Ronaldo’s unveiling with AlNassr', 'genel'],
            ['Fans filmed Ronaldo arriving', 'genel'],
        ];
        foreach ($cases as [$title, $expected]) {
            $this->assertSame($expected, Categorizer::categorize($title), $title);
        }
        $this->assertSame('ur-cristiano', Categorizer::categorize('My FULL Interview With Piers Morgan', '', self::UR), 'UR channel wins over keywords');
        $this->assertSame('ur-cristiano', Categorizer::categorize('Bir video', '', null, 'UR · Cristiano'));
        $this->assertSame('roportajlar', Categorizer::categorize('Ronaldo speaks', 'Full press conference', self::JUVE));
        $this->assertSame('mac-ozetleri', Categorizer::categorize('Juventus 2-1 Sassuolo', "All the highlights from Turin.\n\nSubscribe: goal goal goal"), 'description first paragraph breaks ties');
        foreach (array_keys(VideoRepository::categories()) as $key) {
            $this->assertSame($key, Categorizer::normalize($key));
        }
        $this->assertSame('genel', Categorizer::normalize('yok'));
    }

    public function testRonaldoFilterAndShorts(): void
    {
        $this->assertTrue(Categorizer::mentionsRonaldo('Cristiano scores again'));
        $this->assertTrue(Categorizer::mentionsRonaldo('RONALDO’NUN golü'));
        $this->assertTrue(Categorizer::mentionsRonaldo('#CR7 magic'));
        $this->assertTrue(Categorizer::mentionsRonaldo('هاتريك رونالدو'));
        $this->assertTrue(Categorizer::mentionsRonaldo('Matchday vlog', 'Ronaldo and the boys warm up.'));
        $this->assertFalse(Categorizer::mentionsRonaldo('Mbappé brace vs Sevilla'));
        $this->assertFalse(Categorizer::mentionsRonaldo('Ronaldinho skills'));
        $this->assertFalse(Categorizer::mentionsRonaldo('Ronaldo Nazário: O Fenômeno'), 'R9 is a different Ronaldo');
        $this->assertTrue(Categorizer::mentionsRonaldo('Cristiano Ronaldo and Ronaldo Nazário'));
        $this->assertFalse(Categorizer::mentionsRonaldo('Matchday', "Highlights of the win.\n\nGo behind the scenes with Ronaldo, Mané and the team."), 'boilerplate after the first paragraph is ignored');
        $this->assertTrue(Categorizer::isShort(null, 'https://www.youtube.com/shorts/c_JlCriK3fY'));
        $this->assertTrue(Categorizer::isShort(45));
        $this->assertFalse(Categorizer::isShort(61));
        $this->assertFalse(Categorizer::isShort(null));
    }

    // ------------------------------------------------------------------ feed + API parsing

    public function testFeedParserNormalisesEntries(): void
    {
        $videos = YouTubeFeed::parse(self::feedXml(self::JUVE, 'Juventus', [
            ['b9EMa4OPacQ', 'RONALDO MISSES… MANDŽUKIĆ DOESN’T! #shorts #juve', 'Watch &amp; enjoy', '2026-10-18T10:00:20+00:00', 471223, true],
            ['qvrlKGlW3PM', 'Dybala &amp; Ronaldo vs the keeper', '', '2026-10-17T15:00:21+02:00', 1522437, false],
            ['bad', 'Broken id', '', '2026-10-17T15:00:21+00:00', 1, false],
        ]));
        $this->assertCount(2, $videos);
        [$a, $b] = $videos;
        $this->assertSame('b9EMa4OPacQ', $a['youtube_id']);
        $this->assertSame('RONALDO MISSES… MANDŽUKIĆ DOESN’T!', $a['title']);
        $this->assertSame('Watch & enjoy', $a['description']);
        $this->assertSame(self::JUVE, $a['channel_id']);
        $this->assertSame('Juventus', $a['channel_title']);
        $this->assertSame('https://i.ytimg.com/vi/b9EMa4OPacQ/hqdefault.jpg', $a['thumbnail_url']);
        $this->assertSame('2026-10-18 10:00:20', $a['published_at']);
        $this->assertSame(471223, $a['view_count']);
        $this->assertTrue($a['is_short']);
        $this->assertSame('rss', $a['source']);
        $this->assertSame('Dybala & Ronaldo vs the keeper', $b['title']);
        $this->assertSame('2026-10-17 13:00:21', $b['published_at'], 'converted to UTC');
        $this->assertFalse($b['is_short']);

        $this->assertSame([], YouTubeFeed::parse('<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><feed>&e;</feed>'), 'DTDs are refused');
        $this->assertSame([], YouTubeFeed::parse('not xml'));

        $feed = new YouTubeFeed(static fn (string $url): array => ['status' => 404, 'body' => '', 'error' => null]);
        $this->assertSame([], $feed->fetch(self::JUVE));
        $this->assertContains('HTTP 404', (string) $feed->lastError());
        $this->assertSame([], $feed->fetch('not-a-channel'));
    }

    public function testApiNormalizeAndRequests(): void
    {
        $item = [
            'id' => 'c_JlCriK3fY',
            'snippet' => ['title' => 'Bicycle kick #shorts', 'description' => 'Turin', 'channelId' => 'UCWV3obpZVGgJ3j9FVhEjF2Q', 'channelTitle' => 'Real Madrid',
                'publishedAt' => '2020-04-01T10:00:00Z', 'thumbnails' => ['high' => ['url' => 'x']], 'liveBroadcastContent' => 'none'],
            'contentDetails' => ['duration' => 'PT1M44S'],
            'statistics' => ['viewCount' => '1591566'],
            'status' => ['privacyStatus' => 'public', 'embeddable' => true],
        ];
        $video = YouTubeApi::normalize($item);
        $this->assertSame('Bicycle kick', $video['title']);
        $this->assertSame(104, $video['duration_seconds']);
        $this->assertSame(1591566, $video['view_count']);
        $this->assertSame('2020-04-01 10:00:00', $video['published_at']);
        $this->assertSame('api', $video['source']);
        $this->assertNull(YouTubeApi::normalize(array_replace_recursive($item, ['status' => ['embeddable' => false]])));
        $this->assertNull(YouTubeApi::normalize(array_replace_recursive($item, ['status' => ['privacyStatus' => 'private']])));
        $this->assertNull(YouTubeApi::normalize(array_replace_recursive($item, ['snippet' => ['liveBroadcastContent' => 'upcoming']])));

        $urls = [];
        $api = new YouTubeApi('AIzaFakeKeyForTests_1234567890', static function (string $url) use (&$urls, $item): array {
            $urls[] = $url;
            $body = str_contains($url, '/search?')
                ? ['items' => [['id' => ['videoId' => 'c_JlCriK3fY']], ['id' => ['videoId' => 'bad']]]]
                : ['items' => [$item]];
            return ['status' => 200, 'body' => json_encode($body), 'error' => null];
        });
        $this->assertTrue($api->enabled());
        $this->assertSame(['c_JlCriK3fY'], $api->search('Cristiano Ronaldo', 10));
        $this->assertContains('relevanceLanguage=tr', $urls[0]);
        $this->assertContains('regionCode=TR', $urls[0]);
        $this->assertContains('videoEmbeddable=true', $urls[0]);
        $this->assertContains('q=Cristiano%20Ronaldo', $urls[0]);
        $this->assertSame(['c_JlCriK3fY'], array_keys($api->videos(['c_JlCriK3fY', 'nope'])));
        $this->assertContains('part=snippet%2CcontentDetails%2Cstatistics%2Cstatus', $urls[1]);

        $failing = new YouTubeApi('AIzaFakeKeyForTests_1234567890', static fn (string $url): array => ['status' => 403, 'body' => '{"error":{"message":"quotaExceeded"}}', 'error' => null]);
        $this->assertSame([], $failing->search('x'));
        $this->assertContains('quotaExceeded', (string) $failing->lastError());
        $this->assertFalse((new YouTubeApi(''))->enabled());
    }

    // ------------------------------------------------------------------ importer

    public function testImporterUsesOembedAndIsIdempotent(): void
    {
        $requested = [];
        $importer = new VideoImporter(new YouTubeApi(''), self::oembedFake($requested));

        $id = $importer->importByUrl('https://youtu.be/c_JlCriK3fY?t=3', null, true);
        $this->assertTrue(is_int($id) && $id > 0, 'imported');
        $this->assertContains('https://www.youtube.com/oembed?format=json&url=' . rawurlencode('https://www.youtube.com/watch?v=c_JlCriK3fY'), $requested);
        $row = (new VideoRepository())->find($id);
        $this->assertSame("Cristiano Ronaldo's amazing bicycle kick! | Juventus 0-3 Real Madrid", $row['title']);
        $this->assertSame('Real Madrid', $row['channel_title']);
        $this->assertSame('UCWV3obpZVGgJ3j9FVhEjF2Q', $row['channel_id'], 'known handle => channel id');
        $this->assertSame('goller', $row['category']);
        $this->assertSame(1, $row['is_featured']);
        $this->assertSame('manual', $row['source']);
        $this->assertSame('https://i.ytimg.com/vi/c_JlCriK3fY/hqdefault.jpg', $row['thumbnail_url']);

        $again = $importer->importByUrl('https://www.youtube.com/watch?v=c_JlCriK3fY', 'belgesel');
        $this->assertSame($id, $again, 'no duplicate');
        $this->assertSame('belgesel', (new VideoRepository())->find($id)['category'], 'category updated');
        $this->assertSame(1, (int) Database::fetchValue('SELECT COUNT(*) FROM videos'));

        $this->assertNull($importer->importByUrl('https://youtu.be/NoEmbed0001'));
        $this->assertContains('yerleştirilmesine izin verilmiyor', (string) $importer->lastError());
        $this->assertNull($importer->importByUrl('https://youtu.be/Missing0001'));
        $this->assertContains('bulunamadı', (string) $importer->lastError());
        $this->assertNull($importer->importByUrl('https://vimeo.com/1'));
        $this->assertContains('Geçerli bir YouTube', (string) $importer->lastError());
        $this->assertSame(1, (int) Database::fetchValue('SELECT COUNT(*) FROM videos'));

        $urImporter = new VideoImporter(new YouTubeApi(''), self::oembedFake($requested));
        $ur = $urImporter->importByUrl('UrVideo0001');
        $this->assertSame('ur-cristiano', (new VideoRepository())->find((int) $ur)['category']);
    }

    // ------------------------------------------------------------------ sync

    public function testSyncImportsFilteredChannelsAndSkipsUnembeddable(): void
    {
        Config::set('youtube.channels', [['id' => self::UR, 'name' => 'UR · Cristiano', 'filter' => false]]);
        $feeds = [
            self::UR => self::feedXml(self::UR, 'UR · Cristiano', [
                ['UrVideo0001', 'My day with the family', '', '2026-10-01T10:00:00+00:00', 1000, false],
            ]),
            self::JUVE => self::feedXml(self::JUVE, 'Juventus', [
                ['JuveRon0001', 'Ronaldo’s best goals for Juventus', '', '2026-10-02T10:00:00+00:00', 5000, false],
                ['JuveOth0001', 'Yildiz scores a stunner', 'A great goal.', '2026-10-03T10:00:00+00:00', 7000, false],
                ['NoEmbed0001', 'Cristiano Ronaldo blocked clip', '', '2026-10-04T10:00:00+00:00', 10, false],
            ]),
            'UCHEQtltsiDd3p8ga-5nC-ow' => self::feedXml('UCHEQtltsiDd3p8ga-5nC-ow', 'نادي النصر السعودي - AlNassr FC', [
                ['Arabic00001', 'كواليس مباراة النصر بثلاثية رونالدو', '', '2026-10-05T10:00:00+00:00', 10, false],
                ['NassrRon001', 'Ronaldo scored the goal number 980 #shorts', '', '2026-10-06T10:00:00+00:00', 25322, true],
            ]),
        ];
        $feedRequests = 0;
        $feed = new YouTubeFeed(static function (string $url) use (&$feeds, &$feedRequests): array {
            $feedRequests++;
            $channel = substr($url, strlen(YouTubeFeed::URL));
            return ['status' => 200, 'body' => $feeds[$channel] ?? self::feedXml($channel, 'Boş kanal', []), 'error' => null];
        });
        $oembedRequests = [];
        $importer = new VideoImporter(new YouTubeApi(''), self::oembedFake($oembedRequests));
        $sync = new VideoSync($feed, new YouTubeApi(''), $importer);

        $stats = $sync->run();
        $this->assertSame(count(VideoSync::DEFAULT_CHANNELS), $stats['channels']);
        $this->assertSame(count(VideoSync::DEFAULT_CHANNELS), $feedRequests);
        $this->assertSame(6, $stats['fetched']);
        $this->assertSame(3, $stats['new'], 'UR (unfiltered) + Juventus Ronaldo video + Al Nassr short');
        $this->assertSame(3, $stats['skipped'], 'non-Ronaldo, not embeddable, Arabic-only title');
        $this->assertSame([], $stats['errors']);
        $ids = array_column(Database::fetchAll('SELECT youtube_id FROM videos ORDER BY youtube_id'), 'youtube_id');
        $this->assertSame(['JuveRon0001', 'NassrRon001', 'UrVideo0001'], $ids);

        $repo = new VideoRepository();
        $ur = $repo->findByYoutubeId('UrVideo0001');
        $this->assertSame('ur-cristiano', $ur['category']);
        $this->assertSame('rss', $ur['source']);
        $this->assertSame('2026-10-01 10:00:00', $ur['published_at']);
        $this->assertSame('goller', $repo->findByYoutubeId('JuveRon0001')['category']);
        $this->assertSame('Al Nassr FC', $repo->findByYoutubeId('NassrRon001')['channel_title'], 'configured Latin channel name');
        $this->assertSame('Ronaldo scored the goal number 980', $repo->findByYoutubeId('NassrRon001')['title']);

        // Second run: nothing new; the unembeddable video is remembered and not looked up again.
        $repo->update($ur['id'], ['title' => 'Admin başlığı', 'category' => 'genel']);
        $before = count($oembedRequests);
        $feeds[self::UR] = str_replace('<media:statistics views="1000"/>', '<media:statistics views="2500"/>', $feeds[self::UR]);
        $second = $sync->run();
        $this->assertSame(0, $second['new']);
        $this->assertSame(1, $second['updated'], 'view count refreshed');
        $this->assertSame($before, count($oembedRequests), 'no oEmbed lookups for known/rejected videos');
        $ur = $repo->findByYoutubeId('UrVideo0001');
        $this->assertSame('Admin başlığı', $ur['title'], 'admin edits are kept');
        $this->assertSame('genel', $ur['category']);
        $this->assertSame(2500, $ur['view_count']);

        // Every feed failing => the task reports an error.
        $broken = new VideoSync(new YouTubeFeed(static fn (string $url): array => ['status' => 0, 'body' => '', 'error' => 'timeout']), new YouTubeApi(''), $importer);
        $this->assertThrows(\RuntimeException::class, static fn () => $broken->run());
    }

    public function testChannelListMergesConfig(): void
    {
        Config::set('youtube.channels', [
            ['id' => self::UR, 'name' => 'UR · Cristiano', 'filter' => false],
            ['id' => 'UCyGa1YEx9ST66rYrJTGIKOw', 'enabled' => false],
            ['id' => 'UC0000000000000000000001', 'name' => 'Yeni Kanal'],
            ['id' => 'geçersiz', 'name' => 'x'],
        ]);
        $channels = [];
        foreach (VideoSync::channels() as $c) {
            $channels[$c['id']] = $c;
        }
        $this->assertFalse(isset($channels['UCyGa1YEx9ST66rYrJTGIKOw']), 'disabled by config');
        $this->assertTrue($channels['UC0000000000000000000001']['filter'], 'new channels are filtered by default');
        $this->assertFalse($channels[self::UR]['filter']);
        $this->assertTrue($channels[self::JUVE]['filter']);
        $this->assertSame(count(VideoSync::DEFAULT_CHANNELS), count($channels));
        $this->assertSame(self::JUVE, VideoSync::channelIdFor('Juventus'));
        $this->assertSame('UC6yW44UGJJBvYTlfC7CRg2Q', VideoSync::channelIdFor('Man Utd', 'https://www.youtube.com/@ManUtd'));
        $this->assertSame('UCabcdefghijklmnopqrstuv', VideoSync::channelIdFor(null, 'https://www.youtube.com/channel/UCabcdefghijklmnopqrstuv'));
        $this->assertNull(VideoSync::channelIdFor('Bilinmeyen'));
        $this->assertSame('videos-sync', (new VideoSyncTask())->name());
        $this->assertSame(180, (new VideoSyncTask())->intervalMinutes());
        $this->assertContains('2 yeni', VideoSync::summary(['channels' => 9, 'fetched' => 20, 'new' => 2, 'updated' => 1, 'skipped' => 17, 'errors' => [], 'new_ids' => []]));
    }

    // ------------------------------------------------------------------ repository + seeder

    public function testSeederIsIdempotentAndRepositoryContract(): void
    {
        $this->assertContains('30 seçkin', (new VideoSeeder())->run());
        $this->assertContains('zaten mevcut (30)', (new VideoSeeder())->run());
        $repo = new VideoRepository();
        $this->assertSame(30, $repo->count());

        $seen = [];
        foreach (VideoSeeder::videos() as [$id, , $category, , $date, $seconds, , $title, $description]) {
            $this->assertSame(1, preg_match(VideoText::ID_PATTERN, $id), $id);
            $this->assertFalse(isset($seen[$id]), "duplicate {$id}");
            $seen[$id] = true;
            $this->assertTrue(isset(VideoRepository::categories()[$category]), $category);
            $this->assertTrue(mb_strlen($title) <= 60, "title ≤ 60: {$title}");
            $this->assertTrue(mb_strlen($description) >= 80, "description: {$title}");
            $this->assertSame(1, preg_match('/^\d{4}-\d{2}-\d{2}$/', $date));
            $this->assertTrue($seconds > 0);
        }

        $latest = $repo->latest(5);
        $this->assertCount(5, $latest);
        foreach (['id', 'youtube_id', 'slug', 'title', 'description', 'thumbnail_url', 'published_at', 'category', 'channel_title', 'duration_seconds', 'url'] as $key) {
            $this->assertTrue(array_key_exists($key, $latest[0]), "latest() has {$key}");
        }
        $this->assertSame('/video/' . $latest[0]['slug'], $latest[0]['url']);
        $this->assertTrue($latest[0]['published_at'] >= $latest[1]['published_at'], 'newest first');
        $this->assertCount(5, $repo->latest(5, null, 25), 'offset');
        $this->assertCount(0, $repo->latest(5, null, 30));
        $goals = $repo->latest(50, 'goller');
        $this->assertSame($repo->count('goller'), count($goals));
        foreach ($goals as $v) {
            $this->assertSame('goller', $v['category']);
        }
        $this->assertCount(5, $repo->featured(10));
        $this->assertSame(1, $repo->featured(10)[0]['is_featured']);
        $counts = $repo->categoryCounts();
        $this->assertSame(30, array_sum($counts));
        $this->assertSame(array_keys(array_intersect_key(VideoRepository::categories(), $counts)), array_keys($counts), 'category order');

        $video = $repo->findBySlug($latest[0]['slug']);
        $this->assertSame($latest[0]['youtube_id'], $video['youtube_id']);
        $this->assertNull($repo->findBySlug('yok-boyle-video'));
        $related = $repo->related($video, 6);
        $this->assertCount(6, $related);
        $this->assertFalse(in_array($video['id'], array_column($related, 'id'), true));

        $this->assertSame('5hFd6zGkxLE', $repo->search('veda filmi')[0]['youtube_id']);
        $this->assertSame([], $repo->search('%%'));
        $this->assertSame([], $repo->search('zzzz_yok'));
        $this->assertTrue(count($repo->search('Juventus')) >= 4);

        $sitemap = $repo->forSitemap();
        $this->assertCount(30, $sitemap);
        foreach (['url', 'lastmod', 'youtube_id', 'title', 'description', 'thumbnail_url', 'published_at', 'duration_seconds'] as $key) {
            $this->assertTrue(array_key_exists($key, $sitemap[0]), "forSitemap() has {$key}");
        }
        $this->assertSame(url($latest[0]['url']), $sitemap[0]['url']);

        // Hidden videos never leave the repository's public methods.
        $repo->update($video['id'], ['status' => 'draft']);
        $this->assertNull($repo->findBySlug($video['slug']));
        $this->assertSame(29, $repo->count());
        $this->assertCount(29, $repo->forSitemap());
        $this->assertSame(30, $repo->adminCount());
        $this->assertSame(1, $repo->adminCount($video['youtube_id']));
        $this->assertTrue($repo->delete($video['id']));
        $this->assertSame(29, $repo->adminCount());
    }

    // ------------------------------------------------------------------ pages

    public function testVideoPagesRenderWithSeo(): void
    {
        (new VideoSeeder())->run();
        $response = $this->get('/videolar');
        $html = $response->body();
        $this->assertSame(200, $response->status());
        $this->assertPageBasics($html, '/videolar');
        $this->assertContains('"@type":"ItemList"', $html);
        $this->assertContains('Öne Çıkan Videolar', $html);
        $this->assertContains('aria-current="page"', $html);
        $this->assertContains('href="' . url('/videolar?kategori=goller') . '"', $html);
        $this->assertContains('loading="lazy"', $html);
        $this->assertContains('<link rel="canonical" href="' . url('/videolar') . '">', $html);
        $this->assertContains('<link rel="next" href="' . url('/videolar?sayfa=2') . '">', $html, '30 videos => 2 pages of 24');

        $goals = $this->get('/videolar?kategori=goller');
        $this->assertSame(200, $goals->status());
        $this->assertPageBasics($goals->body(), 'goller');
        $this->assertContains('<link rel="canonical" href="' . url('/videolar?kategori=goller') . '">', $goals->body());
        $this->assertContains('Cristiano Ronaldo Gol Videoları', $goals->body());
        $this->assertNotContains('Öne Çıkan Videolar', $goals->body());

        foreach (['/videolar?kategori=yok', '/videolar?sayfa=3', '/videolar?sayfa=0', '/videolar?sayfa=abc', '/videolar?kategori[]=x', '/video/yok-boyle-video', '/video/yok-zzzzz'] as $path) {
            $this->assertSame(404, $this->get($path)->status(), $path);
        }

        // Pagination: 30 seeded + 20 more => 3 pages of 24.
        $repo = new VideoRepository();
        for ($i = 0; $i < 20; $i++) {
            $repo->create(['youtube_id' => sprintf('Extra%06d', $i), 'title' => "Ek video {$i}", 'published_at' => '2001-01-01 00:00:00']);
        }
        $page2 = $this->get('/videolar?sayfa=2')->body();
        $this->assertContains('<link rel="canonical" href="' . url('/videolar?sayfa=2') . '">', $page2);
        $this->assertContains('<link rel="prev" href="' . url('/videolar') . '">', $page2);
        $this->assertContains('<link rel="next" href="' . url('/videolar?sayfa=3') . '">', $page2);
        $this->assertContains('"position":25', $page2, 'ItemList positions continue across pages');
        $this->assertPageBasics($page2, 'sayfa 2');

        $video = $repo->findByYoutubeId('c_JlCriK3fY');
        $show = $this->get($video['url']);
        $html = $show->body();
        $this->assertSame(200, $show->status());
        $this->assertPageBasics($html, $video['url']);
        $this->assertContains('"@type":"VideoObject"', $html);
        $this->assertContains('"embedUrl":"https://www.youtube-nocookie.com/embed/c_JlCriK3fY"', $html);
        $this->assertContains('"contentUrl":"https://www.youtube.com/watch?v=c_JlCriK3fY"', $html);
        $this->assertContains('"duration":"PT1M44S"', $html);
        $this->assertContains('"uploadDate":"2020-04-01T15:00:00+03:00"', $html);
        $this->assertContains('"thumbnailUrl":["https://i.ytimg.com/vi/c_JlCriK3fY/hqdefault.jpg"', $html);
        $this->assertContains('"publisher":{"@type":"Organization","name":"Real Madrid"', $html);
        $this->assertContains('"@type":"BreadcrumbList"', $html);
        $this->assertContains('data-youtube-id="c_JlCriK3fY"', $html, 'facade embed');
        $this->assertNotContains('<iframe', $html, 'no YouTube iframe before the click');
        $this->assertContains('<meta property="og:type" content="video.other">', $html);
        $this->assertContains('Benzer Videolar', $html);
        $this->assertContains('<link rel="canonical" href="' . url($video['url']) . '">', $html);

        // Old slug (title changed) => 301 to the current one.
        $moved = $this->get('/video/eski-baslik-cjlcr');
        $this->assertSame(301, $moved->status());
        $this->assertSame(url($video['url']), $moved->header('Location'));

        // A video without description/duration still renders valid structured data.
        $repo->create(['youtube_id' => 'Plain000001', 'title' => 'Ronaldo antrenmanda', 'channel_title' => 'Juventus']);
        $plain = $this->get($repo->findByYoutubeId('Plain000001')['url'])->body();
        $this->assertPageBasics($plain, 'plain');
        $this->assertContains('"description":"“Ronaldo antrenmanda” videosu Juventus kanalına ait.', $plain);
        $this->assertNotContains('"duration":"PT', $plain);
    }

    public function testEmptyGalleryIsNoindex(): void
    {
        $response = $this->get('/videolar');
        $this->assertSame(200, $response->status());
        $this->assertContains('Bu bölümde henüz video yok', $response->body());
        $this->assertContains('<meta name="robots" content="noindex,follow">', $response->body());
        $this->assertSame(1, substr_count($response->body(), '<h1'));
    }

    // ------------------------------------------------------------------ helpers

    private function assertPageBasics(string $html, string $label): void
    {
        $this->assertSame(1, substr_count($html, '<h1'), "{$label}: exactly one h1");
        $this->assertSame(1, preg_match('#<title>(.+?) \| CR7\.com\.tr</title>#u', $html, $m), "{$label}: title");
        $this->assertTrue(mb_strlen(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5)) <= 60, "{$label}: title ≤ 60: {$m[1]}");
        $this->assertSame(1, preg_match('#<meta name="description" content="([^"]+)"#u', $html, $d), "{$label}: description");
        $len = mb_strlen(html_entity_decode($d[1], ENT_QUOTES | ENT_HTML5));
        $this->assertTrue($len >= 110 && $len <= 160, "{$label}: description length {$len}: {$d[1]}");
        foreach (['Warning:', 'Notice:', 'Deprecated:', 'Fatal error'] as $bad) {
            $this->assertNotContains($bad, $html, $label);
        }
        preg_match_all('#<script type="application/ld\+json"[^>]*>(.*?)</script>#s', $html, $blocks);
        foreach ($blocks[1] as $json) {
            $this->assertTrue(is_array(json_decode($json, true)), "{$label}: valid JSON-LD");
        }
    }

    private function get(string $uri): Response
    {
        $router = new Router();
        (require App::basePath('routes.php'))($router);
        $request = Request::create('GET', $uri);
        App::setRequest($request);
        return $router->dispatch($request);
    }

    /** @param list<array{0: string, 1: string, 2: string, 3: string, 4: int, 5: bool}> $entries id, title, description, published, views, short */
    private static function feedXml(string $channelId, string $channelName, array $entries): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?><feed xmlns:yt="http://www.youtube.com/xml/schemas/2015" xmlns:media="http://search.yahoo.com/mrss/" xmlns="http://www.w3.org/2005/Atom">'
            . '<yt:channelId>' . substr($channelId, 2) . '</yt:channelId><title>' . $channelName . '</title><author><name>' . $channelName . '</name></author>';
        foreach ($entries as [$id, $title, $description, $published, $views, $short]) {
            $link = $short ? "https://www.youtube.com/shorts/{$id}" : "https://www.youtube.com/watch?v={$id}";
            $xml .= "<entry><id>yt:video:{$id}</id><yt:videoId>{$id}</yt:videoId><yt:channelId>{$channelId}</yt:channelId><title>{$title}</title>"
                . "<link rel=\"alternate\" href=\"{$link}\"/><author><name>{$channelName}</name></author><published>{$published}</published>"
                . "<media:group><media:title>{$title}</media:title><media:thumbnail url=\"https://i3.ytimg.com/vi/{$id}/hqdefault.jpg\" width=\"480\" height=\"360\"/>"
                . "<media:description>{$description}</media:description><media:community><media:statistics views=\"{$views}\"/></media:community></media:group></entry>";
        }
        return $xml . '</feed>';
    }

    /** Fake oEmbed: NoEmbed* => 401, Missing* => 404, Ur* => UR channel, anything else => Real Madrid. */
    private static function oembedFake(array &$requested): callable
    {
        return static function (string $url) use (&$requested): array {
            $requested[] = $url;
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $id = VideoText::parseId((string) ($query['url'] ?? '')) ?? '';
            if (str_starts_with($id, 'NoEmbed')) {
                return ['status' => 401, 'body' => 'Unauthorized', 'error' => null];
            }
            if (str_starts_with($id, 'Missing')) {
                return ['status' => 404, 'body' => 'Not Found', 'error' => null];
            }
            $body = str_starts_with($id, 'Ur')
                ? ['title' => 'A day with my family', 'author_name' => 'UR · Cristiano', 'author_url' => 'https://www.youtube.com/@cristiano']
                : ['title' => 'Cristiano Ronaldo&#39;s amazing bicycle kick! | Juventus 0-3 Real Madrid', 'author_name' => 'Real Madrid', 'author_url' => 'https://www.youtube.com/@realmadrid'];
            $body['thumbnail_url'] = "https://i.ytimg.com/vi/{$id}/hqdefault.jpg";
            return ['status' => 200, 'body' => json_encode($body), 'error' => null];
        };
    }
}
