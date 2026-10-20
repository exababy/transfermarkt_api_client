<?php

declare(strict_types=1);

namespace Tests;

use App\Core\App;
use App\Core\Database;
use App\Core\Request;
use App\Core\Router;
use App\Repositories\ClubRepository;
use App\Repositories\StatsRepository;
use App\Services\Transfermarkt\TransfermarktClient;
use App\Services\Transfermarkt\TransfermarktMapper;
use App\Services\Transfermarkt\TransfermarktSync;
use App\Support\Svg;

/**
 * Stats module: Transfermarkt client (cache/fallback), mapper, sync (idempotent, pruning),
 * StatsRepository §9 contract, SVG charts and the /kariyer, /takimlar, /istatistikler pages.
 * Uses trimmed real API responses from tests/fixtures/tm/.
 */
final class StatsTest extends TestCase
{
    private const FIXTURES = [
        '/player/8198' => 'player.json',
        '/player/8198/performance-season' => 'performance-season.json',
        '/player/8198/performance-club' => 'performance-club.json',
        '/player/8198/performance-competition' => 'performance-competition.json',
        '/player/8198/performance-game' => 'performance-game.json',
        '/player/8198/national-career-history' => 'national-career-history.json',
        '/transfer/history/player/8198' => 'transfer-history.json',
        '/player/8198/market-value-history' => 'market-value-history.json',
        '/player/8198/injury' => 'injury.json',
    ];

    /** @var list<array{url: string, headers: array}> */
    private array $calls = [];
    private bool $fail = false;
    /** @var array<string, callable(array): array> per-endpoint payload modifiers */
    private array $modify = [];

    public function testClientCachesResponsesAndFallsBackToStaleCache(): void
    {
        $client = $this->client();
        $data = $client->get('player/8198');
        $this->assertSame('Cristiano Ronaldo', $data['name'] ?? null);
        $this->assertCount(1, $this->calls);
        $this->assertSame('tr-TR', $this->calls[0]['headers']['Accept-Language'] ?? null, 'Accept-Language from config');
        $this->assertSame('application/json', $this->calls[0]['headers']['Accept'] ?? null);

        $client->get('player/8198');
        $this->assertCount(1, $this->calls, 'fresh cache entry is served without a request');
        $this->assertSame(1, $client->stats()['cache']);

        $this->fail = true;
        $stale = $client->get('player/8198', 0); // forced refresh fails => stale cache
        $this->assertSame('Cristiano Ronaldo', $stale['name'] ?? null);
        $this->assertSame(1, $client->stats()['stale']);
        $this->assertSame(3, count($this->calls), 'one retry after the failed request');
        $this->assertNull($client->get('player/8198/injury'), 'no cache + API failure => null');
        $this->assertSame(1, $client->stats()['failed']);

        $row = Database::fetchOne('SELECT status, fetched_at FROM api_cache WHERE endpoint = ?', ['player/8198']);
        $this->assertSame(200, (int) $row['status']);
    }

    public function testClientRejectsInvalidPayloadsAndShortensLongCacheKeys(): void
    {
        $this->assertNull(TransfermarktClient::decode('{"success":false,"message":"Not found","data":null}'));
        $this->assertNull(TransfermarktClient::decode('{"detail":"Upstream timeout for /player/8198"}'));
        $this->assertNull(TransfermarktClient::decode('<html>'));
        $this->assertSame(['a' => 1], TransfermarktClient::decode('{"success":true,"data":{"a":1}}'));

        $long = 'clubs?' . implode('&', array_map(static fn (int $i): string => 'ids[]=' . (100000 + $i), range(1, 25)));
        $key = TransfermarktClient::cacheKey($long);
        $this->assertTrue(strlen($key) <= 191, 'api_cache.endpoint is VARCHAR(191)');
        $this->assertSame($key, TransfermarktClient::cacheKey($long), 'deterministic');
        $this->assertSame('player/8198', TransfermarktClient::cacheKey('player/8198'));

        $client = $this->client();
        $this->modify['/player/8198'] = static fn (array $json): array => ['success' => false, 'message' => 'error', 'data' => null];
        $this->assertNull($client->get('player/8198'), 'success=false is a failure');
        $this->assertNull(Database::fetchValue('SELECT payload FROM api_cache WHERE endpoint = ?', ['player/8198']), 'invalid payloads are not cached');
    }

    public function testMapperBuildsRowsFromApiPayloads(): void
    {
        $games = $this->fixture('performance-game.json')['data']['performance'];
        $rows = [];
        foreach ($games as $entry) {
            $row = TransfermarktMapper::gameRow($entry, '2026-10-20 10:00:00');
            $rows[$row['game_id']] = $row;
        }
        $hat = $rows['2899815']; // Real Madrid 6–3 Girona, 4 goals
        $this->assertSame('W', $hat['result']);
        $this->assertSame(4, $hat['goals']);
        $this->assertSame(6, $hat['goals_for']);
        $this->assertSame(3, $hat['goals_against']);
        $this->assertSame('home', $hat['venue']);
        $this->assertSame('2018-03-18 19:45:00', $hat['date_utc']);
        $this->assertSame('2017/18', $hat['season_label']);
        $this->assertSame('L', $rows['3838226']['result'], '1–3 defeat');
        $notPlayed = $rows['4971033'];
        $this->assertSame('not in squad', $notPlayed['participation']);
        $this->assertSame(0, $notPlayed['minutes']);
        $this->assertSame(0, $notPlayed['goals']);
        $this->assertSame(0, $notPlayed['is_starting']);
        $this->assertSame(1, $rows['4824840']['is_national']);

        $this->assertSame('2009/10', TransfermarktMapper::seasonLabel(2009));
        $this->assertSame('1999/00', TransfermarktMapper::seasonLabel(1999));
        $this->assertSame('2003-08-12', TransfermarktMapper::dateOnly('2003-08-12T00:00:00+02:00'));

        $transfers = $this->fixture('transfer-history.json')['data']['history']['terminated'];
        $byId = [];
        foreach ($transfers as $t) {
            $row = TransfermarktMapper::transferRow($t, '2026-10-20 10:00:00');
            $byId[$row['id']] = $row;
        }
        $this->assertSame(94000000, $byId['308498']['fee_value']);
        $this->assertSame('2009-07-06', $byId['308498']['transfer_date']);
        $this->assertSame('Bedelsiz', $byId['3927809']['fee_label'], '"Free Transfer" / "Bedelsiz"');
        $this->assertNull($byId['4152208']['fee_label'], '"-" fee => no label');

        $player = TransfermarktMapper::playerRow($this->fixture('player.json')['data'], '{}', '2026-10-20 10:00:00');
        $this->assertSame(185, $player['height_cm']);
        $this->assertSame('Santrafor', $player['position']);
        $this->assertSame('Sol Kanat, Sağ Kanat', $player['side_positions']);
        $this->assertSame('Sağ ayak', $player['foot']);
        $this->assertSame('18544', $player['current_club_id']);
        $this->assertSame(7, $player['shirt_number']);
        $this->assertSame(120000000, $player['highest_market_value']);

        $english = ['id' => '1', 'attributes' => ['position' => ['name' => 'Centre-Forward'], 'preferredFoot' => ['name' => 'right']]];
        $row = TransfermarktMapper::playerRow($english, '{}', '2026-10-20 10:00:00');
        $this->assertSame('Santrafor', $row['position'], 'English fallback translated');
        $this->assertSame('Sağ ayak', $row['foot']);

        $injuries = $this->fixture('injury.json')['data']['injuries'];
        $keys = array_map(static fn (array $i): string => TransfermarktMapper::injuryRow($i)['injury_key'], $injuries);
        $this->assertSame(count($keys), count(array_unique($keys)), 'repeated injury ids get distinct keys');
    }

    public function testSyncFillsEveryTableAndIsIdempotent(): void
    {
        $message = $this->sync('2026-10-20 10:00:00');
        $this->assertContains('10 maç', $message);
        $counts = $this->counts();
        $this->assertSame(10, $counts['games']);
        $this->assertSame(count($this->fixture('performance-season.json')['data']['performance']), $counts['stats_season']);
        $this->assertSame(6, $counts['stats_club']);
        $this->assertSame(3, $counts['stats_summary']);
        $this->assertSame(5, $counts['transfers']);
        $this->assertSame(6, $counts['national_career']);
        $this->assertSame(1, $counts['player']);
        $this->assertTrue($counts['market_values'] >= 3, 'market values');
        $this->assertTrue($counts['injuries'] >= 3, 'injuries');
        $this->assertTrue($counts['clubs'] >= 20, 'clubs resolved: ' . $counts['clubs']);
        $this->assertTrue($counts['competitions'] >= 5, 'competitions resolved');

        $slugs = [];
        foreach (Database::fetchAll('SELECT id, slug FROM clubs') as $row) {
            $slugs[$row['id']] = $row['slug'];
        }
        foreach (TransfermarktSync::FIXED_SLUGS as $id => $slug) {
            $this->assertSame($slug, $slugs[$id] ?? null, "fixed slug for {$id}");
        }
        $this->assertSame(count($slugs), count(array_unique($slugs)), 'club slugs are unique');
        $this->assertSame('UEFA Şampiyonlar Ligi', Database::fetchValue('SELECT name FROM competitions WHERE id = ?', ['CL']));

        $career = Database::fetchOne("SELECT * FROM stats_summary WHERE scope = 'career'");
        $seasonPenalties = (int) Database::fetchValue('SELECT SUM(penalty_goals) FROM stats_season');
        $this->assertSame($seasonPenalties, (int) $career['penalty_goals'], 'penalties summed from seasons when TM does not aggregate them');
        $clubs = Database::fetchOne("SELECT * FROM stats_summary WHERE scope = 'clubs'");
        $senior = (int) Database::fetchValue("SELECT SUM(goals) FROM stats_club WHERE club_id IN ('336','985','418','506','18544')");
        $this->assertSame($senior, (int) $clubs['goals']);
        $national = Database::fetchOne("SELECT * FROM stats_summary WHERE scope = 'national'");
        $this->assertSame((int) Database::fetchValue("SELECT goals FROM stats_club WHERE club_id = '3300'"), (int) $national['goals']);

        // Second run (new timestamp, same data): identical content.
        $before = $this->snapshot();
        $this->sync('2026-10-20 16:00:00');
        $this->assertSame($counts, $this->counts(), 'idempotent row counts');
        $this->assertSame($before, $this->snapshot(), 'idempotent content');
    }

    public function testSyncPrunesRemovedRowsAndKeepsDataWhenApiFails(): void
    {
        $this->sync('2026-10-20 10:00:00');
        $this->modify['/player/8198/performance-game'] = static function (array $json): array {
            array_shift($json['data']['performance']); // newest game disappears upstream
            return $json;
        };
        $this->expireCache();
        $this->sync('2026-10-20 16:00:00');
        $this->assertSame(9, (int) Database::fetchValue('SELECT COUNT(*) FROM games'));
        $this->assertNull(Database::fetchValue('SELECT game_id FROM games WHERE game_id = ?', ['5021779']));

        // Truncated upstream response: old games are kept (no mass deletion).
        $this->modify['/player/8198/performance-game'] = static function (array $json): array {
            $json['data']['performance'] = array_slice($json['data']['performance'], 0, 2);
            return $json;
        };
        $this->expireCache();
        $this->sync('2026-10-20 22:00:00');
        $this->assertSame(10, (int) Database::fetchValue('SELECT COUNT(*) FROM games'), 'guard against truncated responses: stored games kept, returned ones upserted');

        // API down + cache expired: the stale cache keeps the sync working.
        $this->modify = [];
        $this->fail = true;
        $this->expireCache();
        $message = $this->sync('2026-10-21 04:00:00');
        $this->assertContains('eski önbellek 10', $message);
        $this->assertSame(10, (int) Database::fetchValue('SELECT COUNT(*) FROM games'));

        // Nothing cached and the API is down: the task fails loudly, data untouched.
        Database::execute('DELETE FROM api_cache');
        $this->assertThrows(\RuntimeException::class, fn () => $this->sync('2026-10-21 10:00:00'));
        $this->assertSame(1, (int) Database::fetchValue('SELECT COUNT(*) FROM player'));
    }

    public function testRepositoryContractAfterSync(): void
    {
        $this->sync('2026-10-20 10:00:00');
        $repo = new StatsRepository();

        $player = $repo->player();
        $this->assertSame('Cristiano Ronaldo', $player['name']);
        $this->assertTrue(is_int($player['age']) && $player['age'] >= 41, 'age');
        $this->assertSame('Al-Nassr FC', $player['current_club_name']);
        $this->assertContains('/wappen/head/18544', (string) $player['current_club_logo']);

        $totals = $repo->careerTotals();
        $this->assertSame(['appearances', 'goals', 'assists', 'minutes', 'starts', 'yellow', 'red', 'penalty_goals'], array_keys($totals));
        foreach ($totals as $value) {
            $this->assertTrue(is_int($value), 'int totals');
        }
        $this->assertSame(array_keys($totals), array_keys($repo->clubTotals()));
        $this->assertSame(['games' => 234, 'goals' => 146, 'debut_date' => '2003-08-20', 'last_game_date' => '2026-09-24'], $repo->nationalTotals());

        $clubs = $repo->careerClubs();
        $this->assertSame(['336', '985', '418', '506', '985', '18544'], array_column($clubs, 'club_id'), 'chronological, Man Utd twice');
        foreach (['club_id', 'name', 'slug', 'logo_url', 'from_year', 'to_year', 'appearances', 'goals', 'assists', 'minutes', 'url'] as $key) {
            $this->assertTrue(array_key_exists($key, $clubs[0]), "careerClubs key {$key}");
        }
        [$mu1, $mu2] = [$clubs[1], $clubs[4]];
        $this->assertSame('/takimlar/manchester-united', $mu1['url']);
        $this->assertSame('/takimlar/manchester-united', $mu2['url']);
        $this->assertSame([2003, 2009, 3, 5, 2, 238], [$mu1['from_year'], $mu1['to_year'], $mu1['appearances'], $mu1['goals'], $mu1['assists'], $mu1['minutes']]);
        $this->assertSame([2021, 2022, 2, 0, 1, 180], [$mu2['from_year'], $mu2['to_year'], $mu2['appearances'], $mu2['goals'], $mu2['assists'], $mu2['minutes']]);
        $this->assertNull($clubs[5]['to_year'], 'current club');
        $this->assertSame(1, $clubs[5]['appearances'], 'non-played entries are not appearances');

        $latest = $repo->latestGames(3);
        $this->assertCount(3, $latest);
        foreach (['game_id', 'date_utc', 'competition_id', 'competition_name', 'club_id', 'club_name', 'club_logo', 'opponent_id', 'opponent_name', 'opponent_logo', 'venue', 'goals_for', 'goals_against', 'result', 'minutes', 'goals', 'assists', 'participation', 'is_national'] as $key) {
            $this->assertTrue(array_key_exists($key, $latest[0]), "latestGames key {$key}");
        }
        $this->assertSame('5021779', $latest[0]['game_id'], 'newest first');
        $this->assertSame('3864', $latest[1]['opponent_id'], 'not-played entry skipped');
        $this->assertTrue($latest[1]['is_national']);

        $years = $repo->goalsByYear();
        $this->assertSame([2009, 2018, 2022, 2026], array_keys($years));
        $this->assertSame(['goals' => 5, 'appearances' => 3, 'assists' => 2], $years[2009]);
        $this->assertSame('2026-10-20 10:00:00', $repo->lastSyncedAt());

        $this->assertSame(1, $repo->gameTotals(['club_id' => '418'])['hat_tricks']);
        $this->assertSame('2899815', $repo->hatTricks()[0]['game_id']);
        $this->assertSame(4, $repo->topOpponents(1)[0]['goals']);
        $this->assertSame('2899815', $repo->biggestWins(1, ['club_id' => '418'])[0]['game_id']);
        $this->assertSame(9, $repo->countGames(['played' => true]));
        $this->assertSame(10, $repo->countGames());
        $this->assertSame(3, $repo->countGames(['played' => true, 'season_id' => 2008]), 'Apr–May 2009 games belong to 2008/09');
        $this->assertSame(1, $repo->countGames(['played' => true, 'result' => 'L']));
        $this->assertCount(5, $repo->transfers());
        $this->assertSame('Sporting CP', $repo->transfers()[1]['from_name'] ?? null);

        $pages = (new ClubRepository())->pages();
        $this->assertSame(['sporting-cp', 'manchester-united', 'real-madrid', 'juventus', 'al-nassr', 'portekiz'], array_keys($pages));
        $this->assertSame(['985', '985-2'], array_map('strval', array_keys($pages['manchester-united']['spells'])));
        $this->assertTrue($pages['portekiz']['is_national']);
        $this->assertCount(7, (new ClubRepository())->forSitemap());
    }

    public function testRepositoryReturnsZerosBeforeFirstSync(): void
    {
        $repo = new StatsRepository();
        $this->assertNull($repo->player());
        $this->assertSame(array_fill_keys(['appearances', 'goals', 'assists', 'minutes', 'starts', 'yellow', 'red', 'penalty_goals'], 0), $repo->careerTotals());
        $this->assertSame(['games' => 0, 'goals' => 0, 'debut_date' => null, 'last_game_date' => null], $repo->nationalTotals());
        $this->assertSame([], $repo->latestGames());
        $this->assertSame([], $repo->goalsByYear());
        $this->assertNull($repo->lastSyncedAt());
        $clubs = $repo->careerClubs();
        $this->assertCount(6, $clubs, 'career spells from content/career.php');
        $this->assertSame(0, $clubs[0]['appearances']);
        $this->assertSame('/takimlar/sporting-cp', $clubs[0]['url']);

        // Missing schema (not installed): still no exception.
        Database::pdo()->exec('DROP TABLE games');
        $this->assertSame([], $repo->goalsByYear());
    }

    public function testSvgChartsAreAccessibleAndResponsive(): void
    {
        $bar = Svg::bar([
            ['label' => '2012', 'value' => 63, 'tip' => '71 maç'],
            ['label' => '2013', 'value' => 69, 'tip' => '59 maç'],
            ['label' => '2014', 'value' => 0],
            ['label' => '2015', 'value' => 57],
        ], ['id' => 'test-bar', 'title' => 'Yıllara göre goller', 'desc' => 'En çok 2013', 'unit' => 'gol']);
        $this->assertSame(3, substr_count($bar, '<svg '), 'three responsive variants');
        foreach (['role="img"', 'aria-labelledby="test-bar-s-t test-bar-s-d"', '<title id="test-bar-l-t">Yıllara göre goller</title>', '<desc id="test-bar-m-d">En çok 2013</desc>', 'viewBox="0 0 960 320"', 'data-tip-v="69 gol"', 'data-tip-l="2013 · 59 maç"', 'class="st-mark"', '>69</text>', '>57</text>'] as $needle) {
            $this->assertContains($needle, $bar);
        }
        $this->assertSame(9, substr_count($bar, 'class="st-mark"'), 'zero values draw no column');
        $this->assertSame(1, preg_match('/d="M[\d.]+ [\d.]+V[\d.]+Q/', $bar), 'rounded data end, square base');

        $line = Svg::line([
            ['x' => '2004-10-04', 'y' => 18000000],
            ['x' => '2018-01-01', 'y' => 120000000, 'tip' => 'Real Madrid'],
            ['x' => '2026-06-04', 'y' => 10000000],
        ], ['id' => 'test-line', 'title' => 'Piyasa değeri', 'format' => static fn ($v): string => \App\Support\Format::money((int) $v), 'axisFormat' => static fn ($v): string => (string) ($v / 1e6)]);
        foreach (['role="img"', 'class="st-cross"', 'data-tip-v="120 mil. €"', 'data-tip-l="1 Oca 2018 · Real Madrid"', '>120 mil. €</text>', '>10 mil. €</text>', 'fill-opacity=".1"', 'stroke-width="2"'] as $needle) {
            $this->assertContains($needle, $line);
        }
        $this->assertSame('', Svg::bar([], ['title' => 'Boş']));
        $this->assertSame('', Svg::line([['x' => '2020-01-01', 'y' => 1]], ['title' => 'Tek nokta']));
        $this->assertSame([80.0, 20.0], Svg::niceScale(69));
        $this->assertSame([120000000.0, 30000000.0], Svg::niceScale(120000000));
        $this->assertNotContains('<script', $bar . $line);
    }

    public function testStatsPagesRenderWithSeoAndCorrectStatus(): void
    {
        $this->sync('2026-10-20 10:00:00');
        $pages = [
            '/kariyer' => ['Person', 'ItemList', 'Transfer Geçmişi', 'Piyasa Değeri Gelişimi', '/takimlar/manchester-united', '/istatistikler'],
            '/takimlar' => ['ItemList', 'club-card', 'Takım Takım Karşılaştırma'],
            '/takimlar/manchester-united' => ['SportsTeam', 'Birinci dönem', 'İkinci dönem', 'Sezon Sezon', 'BreadcrumbList'],
            '/takimlar/portekiz' => ['SportsTeam', 'Tüm Portekiz Takımları'],
            '/istatistikler' => ['Kulüplere göre', 'Turnuvalara göre', 'Sezonlara göre', 'Son güncelleme', 'Kaynak: Transfermarkt', 'Dataset', 'role="img"', 'Sakatlık Geçmişi'],
            '/istatistikler/maclar' => ['result-W', 'Maç Listesi', 'name="sezon"'],
        ];
        foreach ($pages as $path => $needles) {
            $response = $this->get($path);
            $html = $response->body();
            $this->assertSame(200, $response->status(), $path);
            $this->assertSame(1, substr_count($html, '<h1'), "{$path} has exactly one h1");
            $this->assertSame(1, preg_match('#<title>(.+?) \| CR7\.com\.tr</title>#u', $html, $m), "{$path} title");
            $this->assertTrue(mb_strlen(html_entity_decode($m[1])) <= 60, "{$path} title ≤ 60: " . $m[1]);
            $this->assertSame(1, preg_match('#<meta name="description" content="([^"]+)"#u', $html, $d), "{$path} description");
            $len = mb_strlen(html_entity_decode($d[1], ENT_QUOTES | ENT_HTML5));
            $this->assertTrue($len >= 110 && $len <= 160, "{$path} description length {$len}");
            $this->assertContains('<link rel="canonical"', $html, $path);
            foreach ($needles as $needle) {
                $this->assertContains($needle, $html, $path);
            }
            foreach (['Warning:', 'Notice:', 'Deprecated:'] as $bad) {
                $this->assertNotContains($bad, $html, $path);
            }
        }

        $this->assertSame(404, $this->get('/takimlar/olmayan-takim')->status());
        $this->assertSame(404, $this->get('/takimlar/kulupsuz')->status(), 'only career clubs have pages');
        $this->assertSame(404, $this->get('/istatistikler/maclar?sayfa=9')->status(), 'no soft 404 past the last page');

        $season = $this->get('/istatistikler/maclar?sezon=2008');
        $this->assertSame(200, $season->status());
        $this->assertContains('content="index,follow', $season->body(), 'season pages are indexable');
        $this->assertContains('maclar?sezon=2008"', $season->body(), 'canonical keeps the season filter');
        $filtered = $this->get('/istatistikler/maclar?turnuva=GB1');
        $this->assertContains('noindex,follow', $filtered->body());
        $redirect = $this->get('/istatistikler/maclar?sezon=&turnuva=gb1');
        $this->assertSame(301, $redirect->status());
        $this->assertContains('/istatistikler/maclar?turnuva=GB1', (string) $redirect->header('Location'));
        $all = $this->get('/istatistikler/maclar?durum=tumu');
        $this->assertContains('Kadroda yok', $all->body());
        $this->assertSame(301, $this->get('/istatistikler/maclar?sayfa=1')->status());
        $byClub = $this->get('/istatistikler?kulup=real-madrid');
        $this->assertSame(200, $byClub->status());
        $this->assertContains('Real Madrid sezonları', $byClub->body());
    }

    public function testPagesRenderBeforeFirstSync(): void
    {
        foreach (['/kariyer', '/takimlar', '/takimlar/real-madrid', '/takimlar/portekiz', '/istatistikler', '/istatistikler/maclar'] as $path) {
            $response = $this->get($path);
            $this->assertSame(200, $response->status(), $path);
            $this->assertSame(1, substr_count($response->body(), '<h1'), $path);
        }
    }

    // ------------------------------------------------------------------ helpers

    private function client(int $retries = 1): TransfermarktClient
    {
        return new TransfermarktClient(
            ['base_url' => 'https://tm.test/', 'language' => 'tr-TR', 'timeout' => 5, 'retries' => $retries],
            fn (string $url, array $headers, int $timeout): array => $this->respond($url, $headers)
        );
    }

    /** Make every api_cache entry stale so the next sync requests the (fake) API again. */
    private function expireCache(): void
    {
        Database::execute("UPDATE api_cache SET fetched_at = '2001-01-01 00:00:00'");
    }

    private function sync(string $now): string
    {
        return (new TransfermarktSync($this->client(0), 8198))->run($now);
    }

    private function respond(string $url, array $headers): array
    {
        $this->calls[] = ['url' => $url, 'headers' => $headers];
        $result = static fn (int $status, string $body): array => ['status' => $status, 'body' => $body, 'headers' => [], 'error' => null, 'final_url' => $url];
        if ($this->fail) {
            return $result(504, '{"detail":"Upstream timeout"}');
        }
        $path = (string) parse_url($url, PHP_URL_PATH);
        $query = rawurldecode((string) parse_url($url, PHP_URL_QUERY));
        if ($path === '/clubs' || $path === '/competitions') {
            preg_match_all('/ids\[\]=([^&]+)/', $query, $m);
            $pool = $this->fixture($path === '/clubs' ? 'clubs.json' : 'competitions.json')['data'];
            $json = ['success' => true, 'message' => 'OK', 'data' => array_values(array_filter($pool, static fn (array $x): bool => in_array((string) $x['id'], $m[1], true)))];
        } elseif (isset(self::FIXTURES[$path])) {
            $json = $this->fixture(self::FIXTURES[$path]);
        } else {
            return $result(404, '{"success":false,"message":"Not found","data":null}');
        }
        if (isset($this->modify[$path])) {
            $json = ($this->modify[$path])($json);
        }
        return $result(200, (string) json_encode($json, JSON_UNESCAPED_UNICODE));
    }

    private function fixture(string $file): array
    {
        static $cache = [];
        return $cache[$file] ??= json_decode((string) file_get_contents(App::basePath('tests/fixtures/tm/' . $file)), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        $out = [];
        foreach (['player', 'clubs', 'competitions', 'stats_season', 'stats_club', 'stats_competition', 'stats_summary', 'games', 'transfers', 'market_values', 'national_career', 'injuries'] as $table) {
            $out[$table] = (int) Database::fetchValue('SELECT COUNT(*) FROM ' . $table);
        }
        return $out;
    }

    /** Table contents without timestamps (for idempotency checks). */
    private function snapshot(): array
    {
        $out = [];
        foreach (['stats_season' => 'season_id, competition_id, club_id', 'stats_club' => 'club_id', 'stats_summary' => 'scope', 'games' => 'game_id', 'transfers' => 'id', 'clubs' => 'id', 'market_values' => 'value_date', 'injuries' => 'injury_key'] as $table => $order) {
            $rows = Database::fetchAll("SELECT * FROM {$table} ORDER BY {$order}");
            foreach ($rows as &$row) {
                unset($row['updated_at'], $row['id']);
            }
            $out[$table] = $rows;
        }
        return $out;
    }

    private function get(string $uri): \App\Core\Response
    {
        $router = new Router();
        (require App::basePath('routes.php'))($router);
        $request = Request::create('GET', $uri);
        App::setRequest($request);
        return $router->dispatch($request);
    }
}
