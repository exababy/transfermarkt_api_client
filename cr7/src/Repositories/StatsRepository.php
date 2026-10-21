<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Services\Transfermarkt\TransfermarktMapper;
use App\Services\Transfermarkt\TransfermarktSync;
use App\Support\Date;

/**
 * Read model over the Transfermarkt tables (spec §9 contract + helpers for the stats pages).
 *
 * Every method returns zeros / empty arrays / null before the first sync (or when the
 * schema is missing) — it never throws on a missing table. MySQL returns SUM() as strings,
 * so every aggregate is cast to int here.
 */
final class StatsRepository
{
    private const TOTAL_KEYS = ['appearances', 'goals', 'assists', 'minutes', 'starts', 'yellow', 'red', 'penalty_goals'];

    /** Result filter values used in URLs (?sonuc=) => games.result */
    public const RESULT_SLUGS = ['galibiyet' => 'W', 'beraberlik' => 'D', 'maglubiyet' => 'L'];

    /**
     * Turkish display names for competitions Transfermarkt's tr-TR feed leaves in English/Spanish
     * or marks with "(- 2009)". Proper league names (Premier League, LaLiga, Serie A) stay.
     */
    public const COMPETITION_NAMES = [
        'CONC' => 'Konfederasyonlar Kupası',
        'SAKC' => 'Suudi Kral Kupası',
        'SASS' => 'Suudi Süper Kupası',
        'SA1' => 'Suudi Pro Lig',
        'GBCS' => 'İngiltere Süper Kupası',
        'CGB' => 'İngiltere Lig Kupası',
        'SUC' => 'İspanya Süper Kupası',
        'SCI' => 'İtalya Süper Kupası',
        'POSU' => 'Portekiz Süper Kupası',
        'POPO' => 'Portekiz Kupası',
        'UEFA' => 'UEFA Kupası (2009’a kadar)',
        'AFCL' => 'AFC Şampiyonlar Ligi (2024’e kadar)',
        'ACLQ' => 'AFC Şampiyonlar Ligi Elemeleri',
        'AA17' => 'Algarve U17 Turnuvası',
    ];

    /** Display name of a competition (Turkish map, else the stored name, else the id). */
    public static function competitionName(?string $id, ?string $name): string
    {
        return self::COMPETITION_NAMES[(string) $id] ?? (string) (($name ?? '') !== '' ? $name : $id);
    }

    /** Turkish labels for games.participation. */
    public const PARTICIPATION_LABELS = [
        'played' => 'Oynadı',
        'in squad' => 'Yedek kulübesinde',
        'not in squad' => 'Kadroda yok',
        'injured' => 'Sakat',
        'absent' => 'Yok (ceza/izin)',
    ];

    /** Result letters shown in badges (Galibiyet / Beraberlik / Mağlubiyet). */
    public const RESULT_LABELS = ['W' => ['G', 'Galibiyet'], 'D' => ['B', 'Beraberlik'], 'L' => ['M', 'Mağlubiyet']];

    /** games.venue => Turkish. */
    public const VENUE_LABELS = ['home' => 'İç saha', 'away' => 'Deplasman', 'neutral' => 'Tarafsız saha'];

    private ClubRepository $clubs;
    /** @var array<string, array<string, mixed>|null> */
    private array $memo = [];

    public function __construct(?ClubRepository $clubs = null)
    {
        $this->clubs = $clubs ?? new ClubRepository();
    }

    // ------------------------------------------------------------------ §9 contract

    /** Player row + 'age' (int) + 'current_club_name' + 'current_club_logo'; null before the first sync. */
    public function player(): ?array
    {
        if (!array_key_exists('player', $this->memo)) {
            $id = (int) config('transfermarkt.player_id', 8198);
            $row = self::safe(static fn () => Database::fetchOne(
                'SELECT p.*, c.name AS current_club_name, c.logo_url AS current_club_logo_raw
                 FROM player p LEFT JOIN clubs c ON c.id = p.current_club_id WHERE p.id = ?',
                [$id]
            ), null);
            if ($row !== null) {
                $row['age'] = self::age($row['birth_date'] ?? null);
                $row['current_club_logo'] = ClubRepository::logo($row['current_club_logo_raw'] ?? null, 'md');
                unset($row['current_club_logo_raw']);
                foreach (['id', 'height_cm', 'shirt_number', 'market_value', 'highest_market_value'] as $key) {
                    $row[$key] = isset($row[$key]) ? (int) $row[$key] : null;
                }
            }
            $this->memo['player'] = $row;
        }
        return $this->memo['player'];
    }

    /** Scope 'career' (TM aggregated, incl. youth national teams): appearances, goals, assists, minutes, starts, yellow, red, penalty_goals. */
    public function careerTotals(): array
    {
        return array_intersect_key($this->summary('career'), array_flip(self::TOTAL_KEYS));
    }

    /** Scope 'clubs' (senior clubs only), same keys as careerTotals(). */
    public function clubTotals(): array
    {
        return array_intersect_key($this->summary('clubs'), array_flip(self::TOTAL_KEYS));
    }

    /** Portugal A: ['games','goals','debut_date','last_game_date']. */
    public function nationalTotals(): array
    {
        $row = self::safe(static fn () => Database::fetchOne(
            'SELECT games, goals, debut_date, last_game_date FROM national_career WHERE club_id = ?',
            [TransfermarktSync::NATIONAL_TEAM_ID]
        ), null);
        if ($row === null) {
            $summary = $this->summary('national');
            return ['games' => $summary['appearances'], 'goals' => $summary['goals'], 'debut_date' => null, 'last_game_date' => null];
        }
        return [
            'games' => (int) $row['games'],
            'goals' => (int) $row['goals'],
            'debut_date' => self::dateString($row['debut_date']),
            'last_game_date' => self::dateString($row['last_game_date']),
        ];
    }

    /**
     * Senior club spells, chronological (Manchester United appears twice). Contract keys:
     * club_id, name, slug, logo_url, from_year, to_year (null = current), appearances, goals, assists, minutes, url.
     * Extra keys: key (content key), period, country, summary, from_date, to_date (first/last game, UTC).
     *
     * @return list<array<string, mixed>>
     */
    public function careerClubs(): array
    {
        if (isset($this->memo['careerClubs'])) {
            return $this->memo['careerClubs'];
        }
        $content = ClubRepository::content();
        $spells = [];
        foreach (ClubRepository::SPELL_KEYS as $key) {
            $entry = $content['clubs'][$key] ?? null;
            if (is_array($entry)) {
                $spells[$key] = [
                    'club_id' => (string) ($entry['club_id'] ?? explode('-', $key)[0]),
                    'name' => (string) ($entry['name'] ?? ''),
                    'slug' => (string) ($entry['slug'] ?? ''),
                    'from_year' => isset($entry['from']) ? (int) $entry['from'] : null,
                    'to_year' => isset($entry['to']) ? (int) $entry['to'] : null,
                    'period' => (string) ($entry['period'] ?? ''),
                    'country' => (string) ($entry['country'] ?? ''),
                    'summary' => (string) ($entry['summary'] ?? ''),
                ];
            }
        }
        if ($spells === []) {
            $spells = $this->spellsFromStats();
        }

        $ids = array_values(array_unique(array_column($spells, 'club_id')));
        $clubs = $this->clubs->many($ids);
        $games = $ids === [] ? [] : self::safe(static fn () => Database::fetchAll(
            "SELECT club_id, date_utc, minutes, goals, assists FROM games
             WHERE participation = 'played' AND club_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ')
             ORDER BY date_utc',
            $ids
        ), []);

        $rows = [];
        foreach ($spells as $key => $spell) {
            $club = $clubs[$spell['club_id']] ?? null;
            $slug = $spell['slug'] !== '' ? $spell['slug'] : (string) ($club['slug'] ?? (TransfermarktSync::FIXED_SLUGS[$spell['club_id']] ?? ''));
            $rows[$key] = [
                'club_id' => $spell['club_id'],
                'name' => $spell['name'] !== '' ? $spell['name'] : (string) ($club['name'] ?? $spell['club_id']),
                'slug' => $slug,
                'logo_url' => ClubRepository::logo($club['logo_url'] ?? null, 'md'),
                'from_year' => $spell['from_year'],
                'to_year' => $spell['to_year'],
                'appearances' => 0,
                'goals' => 0,
                'assists' => 0,
                'minutes' => 0,
                'url' => '/takimlar/' . $slug,
                'key' => (string) $key,
                'period' => $spell['period'] !== '' ? $spell['period'] : self::period($spell['from_year'], $spell['to_year']),
                'country' => $spell['country'],
                'summary' => $spell['summary'],
                'from_date' => null,
                'to_date' => null,
            ];
        }
        foreach ($games as $game) {
            $key = self::spellFor($rows, (string) $game['club_id'], (int) substr((string) $game['date_utc'], 0, 4));
            if ($key === null) {
                continue;
            }
            $rows[$key]['appearances']++;
            $rows[$key]['goals'] += (int) $game['goals'];
            $rows[$key]['assists'] += (int) $game['assists'];
            $rows[$key]['minutes'] += (int) $game['minutes'];
            $rows[$key]['from_date'] ??= (string) $game['date_utc'];
            $rows[$key]['to_date'] = (string) $game['date_utc'];
        }
        return $this->memo['careerClubs'] = array_values($rows);
    }

    /**
     * Played games newest first with names/logos.
     *
     * @return list<array<string, mixed>>
     */
    public function latestGames(int $limit = 5): array
    {
        return $this->games(['played' => true], max(1, $limit));
    }

    /** @return array<int, array{goals: int, appearances: int, assists: int}> calendar year => totals (played games), ascending */
    public function goalsByYear(): array
    {
        $rows = self::safe(static fn () => Database::fetchAll(
            "SELECT SUBSTR(date_utc, 1, 4) AS y, COUNT(*) AS appearances, SUM(goals) AS goals, SUM(assists) AS assists
             FROM games WHERE participation = 'played' GROUP BY SUBSTR(date_utc, 1, 4) ORDER BY y"
        ), []);
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['y']] = ['goals' => (int) $row['goals'], 'appearances' => (int) $row['appearances'], 'assists' => (int) $row['assists']];
        }
        ksort($out);
        return $out;
    }

    /** UTC time of the last successful sync (null before the first one). */
    public function lastSyncedAt(): ?string
    {
        $value = self::safe(static fn () => Database::fetchValue('SELECT MAX(updated_at) FROM stats_summary'), null);
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * When the numbers really last changed (sitemap lastmod): the latest match (kick-off + 2 h)
     * up to the last sync, not the cron time — the sync rewrites updated_at on every run.
     */
    public function dataChangedAt(): ?string
    {
        $sync = $this->lastSyncedAt();
        if ($sync === null) {
            return null;
        }
        $game = self::safe(static fn () => Database::fetchValue('SELECT MAX(date_utc) FROM games WHERE date_utc <= ?', [$sync]), null);
        if (!is_string($game) || $game === '') {
            return $sync;
        }
        $end = gmdate('Y-m-d H:i:s', (int) strtotime($game . ' UTC') + 7200);
        return strcmp($end, $sync) < 0 ? $end : $sync;
    }

    /** dataChangedAt() per season (its latest match): season_id => UTC datetime. */
    public function seasonChangedAt(): array
    {
        $sync = $this->lastSyncedAt();
        if ($sync === null) {
            return [];
        }
        $out = [];
        foreach ((array) self::safe(static fn () => Database::fetchAll('SELECT season_id, MAX(date_utc) AS last_game FROM games WHERE date_utc <= ? GROUP BY season_id', [$sync]), []) as $row) {
            $end = gmdate('Y-m-d H:i:s', (int) strtotime($row['last_game'] . ' UTC') + 7200);
            $out[(int) $row['season_id']] = strcmp($end, $sync) < 0 ? $end : $sync;
        }
        return $out;
    }

    // ------------------------------------------------------------------ helpers for the stats pages

    /** Full STATCOLS of a stats_summary scope ('career' | 'clubs' | 'national'); zeros when missing. */
    public function summary(string $scope): array
    {
        $row = self::safe(static fn () => Database::fetchOne('SELECT * FROM stats_summary WHERE scope = ?', [$scope]), null);
        return self::statInts($row ?? []);
    }

    /** stats_club rows + club name/logo/slug, chronological. @return list<array<string, mixed>> */
    public function clubStats(): array
    {
        $rows = self::safe(static fn () => Database::fetchAll(
            'SELECT s.*, c.name, c.slug, c.logo_url, c.is_national_team FROM stats_club s LEFT JOIN clubs c ON c.id = s.club_id
             ORDER BY s.first_season_id, s.last_season_id, s.club_id'
        ), []);
        return array_map(static fn (array $r): array => self::statInts($r) + [
            'club_id' => (string) $r['club_id'],
            'name' => (string) ($r['name'] ?? $r['club_id']),
            'slug' => $r['slug'] ?? null,
            'logo_url' => ClubRepository::logo($r['logo_url'] ?? null, 'xs'),
            'is_national_team' => (int) ($r['is_national_team'] ?? 0) === 1,
            'is_senior' => in_array((string) $r['club_id'], [...TransfermarktSync::SENIOR_CLUB_IDS, TransfermarktSync::NATIONAL_TEAM_ID], true),
            'first_season_id' => isset($r['first_season_id']) ? (int) $r['first_season_id'] : null,
            'last_season_id' => isset($r['last_season_id']) ? (int) $r['last_season_id'] : null,
        ], $rows);
    }

    /** stats_competition rows + competition name/logo, most appearances first. @return list<array<string, mixed>> */
    public function competitionStats(): array
    {
        $rows = self::safe(static fn () => Database::fetchAll(
            'SELECT s.*, c.name, c.short_name, c.logo_url FROM stats_competition s LEFT JOIN competitions c ON c.id = s.competition_id
             ORDER BY s.appearances DESC, s.goals DESC, s.competition_id'
        ), []);
        return array_map(static fn (array $r): array => self::statInts($r) + [
            'competition_id' => (string) $r['competition_id'],
            'name' => self::competitionName((string) $r['competition_id'], $r['name'] ?? null),
            'logo_url' => $r['logo_url'] ?? null,
        ], $rows);
    }

    /**
     * Season rows aggregated per season and club (newest first), optionally for one club.
     *
     * @return list<array<string, mixed>>
     */
    public function seasonStats(?string $clubId = null): array
    {
        $where = $clubId !== null ? 'WHERE s.club_id = ?' : '';
        $rows = self::safe(static fn () => Database::fetchAll(
            'SELECT s.season_id, s.club_id, MAX(s.season_label) AS season_label, COUNT(*) AS competitions, ' . self::sumColumns('s') . ',
                    MAX(c.name) AS name, MAX(c.slug) AS slug, MAX(c.logo_url) AS logo_url
             FROM stats_season s LEFT JOIN clubs c ON c.id = s.club_id ' . $where . '
             GROUP BY s.season_id, s.club_id ORDER BY s.season_id DESC, s.club_id',
            $clubId !== null ? [$clubId] : []
        ), []);
        return array_map(static fn (array $r): array => self::statInts($r) + [
            'season_id' => (int) $r['season_id'],
            'season_label' => (string) ($r['season_label'] ?: TransfermarktMapper::seasonLabel((int) $r['season_id'])),
            'club_id' => (string) $r['club_id'],
            'name' => (string) ($r['name'] ?? $r['club_id']),
            'slug' => $r['slug'] ?? null,
            'logo_url' => ClubRepository::logo($r['logo_url'] ?? null, 'xs'),
            'competitions' => (int) $r['competitions'],
        ], $rows);
    }

    /** Competition breakdown at one club (from stats_season). @return list<array<string, mixed>> */
    public function clubCompetitions(string $clubId): array
    {
        $rows = self::safe(static fn () => Database::fetchAll(
            'SELECT s.competition_id, COUNT(*) AS seasons, ' . self::sumColumns('s') . ', MAX(c.name) AS name, MAX(c.logo_url) AS logo_url
             FROM stats_season s LEFT JOIN competitions c ON c.id = s.competition_id WHERE s.club_id = ?
             GROUP BY s.competition_id ORDER BY appearances DESC, goals DESC',
            [$clubId]
        ), []);
        return array_map(static fn (array $r): array => self::statInts($r) + [
            'competition_id' => (string) $r['competition_id'],
            'name' => self::competitionName((string) $r['competition_id'], $r['name'] ?? null),
            'logo_url' => $r['logo_url'] ?? null,
            'seasons' => (int) $r['seasons'],
        ], $rows);
    }

    /**
     * Opponents he scored most against (played games). Filters: club_id, from, to, is_national.
     *
     * @return list<array{opponent_id: string, name: string, slug: ?string, logo_url: ?string, games: int, goals: int, assists: int, wins: int}>
     */
    public function topOpponents(int $limit = 15, array $filters = []): array
    {
        [$where, $params] = self::where(['played' => true] + $filters);
        $params[] = max(1, $limit);
        $rows = self::safe(static fn () => Database::fetchAll(
            "SELECT g.opponent_id, COUNT(*) AS games, SUM(g.goals) AS goals, SUM(g.assists) AS assists,
                    SUM(CASE WHEN g.result = 'W' THEN 1 ELSE 0 END) AS wins
             FROM games g {$where} AND g.opponent_id IS NOT NULL
             GROUP BY g.opponent_id HAVING SUM(g.goals) > 0
             ORDER BY goals DESC, games ASC, g.opponent_id LIMIT ?",
            $params
        ), []);
        $clubs = $this->clubs->many(array_column($rows, 'opponent_id'));
        return array_map(static function (array $r) use ($clubs): array {
            $club = $clubs[(string) $r['opponent_id']] ?? null;
            return [
                'opponent_id' => (string) $r['opponent_id'],
                'name' => (string) ($club['name'] ?? ('#' . $r['opponent_id'])),
                'slug' => $club['slug'] ?? null,
                'logo_url' => ClubRepository::logo($club['logo_url'] ?? null, 'xs'),
                'games' => (int) $r['games'],
                'goals' => (int) $r['goals'],
                'assists' => (int) $r['assists'],
                'wins' => (int) $r['wins'],
            ];
        }, $rows);
    }

    /** Games with 3+ goals, newest first. @return list<array<string, mixed>> */
    public function hatTricks(array $filters = [], int $limit = 200): array
    {
        return $this->games(['played' => true, 'min_goals' => 3] + $filters, $limit);
    }

    /** Biggest wins (goal difference) in games he played. @return list<array<string, mixed>> */
    public function biggestWins(int $limit = 10, array $filters = []): array
    {
        return $this->games(['played' => true, 'result' => 'W'] + $filters, $limit, 0, 'g.goals_for - g.goals_against DESC, g.goals_for DESC, g.date_utc DESC');
    }

    /**
     * Totals over played games for a filter: appearances, goals, assists, minutes, penalty_goals,
     * hat_tricks, wins, draws, losses, starts, yellow, red.
     */
    public function gameTotals(array $filters = []): array
    {
        [$where, $params] = self::where(['played' => true] + $filters);
        $row = self::safe(static fn () => Database::fetchOne(
            "SELECT COUNT(*) AS appearances, SUM(g.goals) AS goals, SUM(g.assists) AS assists, SUM(g.minutes) AS minutes,
                    SUM(g.penalty_goals) AS penalty_goals, SUM(g.is_starting) AS starts, SUM(g.yellow) AS yellow, SUM(g.red) AS red,
                    SUM(CASE WHEN g.goals >= 3 THEN 1 ELSE 0 END) AS hat_tricks,
                    SUM(CASE WHEN g.result = 'W' THEN 1 ELSE 0 END) AS wins,
                    SUM(CASE WHEN g.result = 'D' THEN 1 ELSE 0 END) AS draws,
                    SUM(CASE WHEN g.result = 'L' THEN 1 ELSE 0 END) AS losses
             FROM games g {$where}",
            $params
        ), null) ?? [];
        $out = [];
        foreach (['appearances', 'goals', 'assists', 'minutes', 'penalty_goals', 'starts', 'yellow', 'red', 'hat_tricks', 'wins', 'draws', 'losses'] as $key) {
            $out[$key] = (int) ($row[$key] ?? 0);
        }
        return $out;
    }

    /**
     * Match log. Filters: played (bool), season_id (int), competition_id, club_id, result (W/D/L),
     * from / to (UTC datetimes, inclusive), min_goals (int), is_national (bool).
     *
     * @return list<array<string, mixed>>
     */
    public function games(array $filters = [], int $limit = 50, int $offset = 0, string $order = 'g.date_utc DESC, g.game_id DESC'): array
    {
        [$where, $params] = self::where($filters);
        $params[] = max(1, $limit);
        $params[] = max(0, $offset);
        $rows = self::safe(static fn () => Database::fetchAll(
            "SELECT g.*, comp.name AS competition_name, comp.logo_url AS competition_logo,
                    c.name AS club_name, c.logo_url AS club_logo_raw, c.slug AS club_slug,
                    o.name AS opponent_name, o.logo_url AS opponent_logo_raw, o.slug AS opponent_slug
             FROM games g
             LEFT JOIN competitions comp ON comp.id = g.competition_id
             LEFT JOIN clubs c ON c.id = g.club_id
             LEFT JOIN clubs o ON o.id = g.opponent_id
             {$where} ORDER BY {$order} LIMIT ? OFFSET ?",
            $params
        ), []);
        return array_map(static function (array $r): array {
            foreach (['season_id', 'goals', 'assists', 'minutes', 'penalty_goals', 'yellow', 'red', 'is_captain', 'is_starting', 'is_national'] as $key) {
                $r[$key] = (int) ($r[$key] ?? 0);
            }
            foreach (['goals_for', 'goals_against', 'shirt_number', 'shots', 'shots_on_target', 'passes'] as $key) {
                $r[$key] = isset($r[$key]) ? (int) $r[$key] : null;
            }
            $r['game_id'] = (string) $r['game_id'];
            $r['competition_name'] = self::competitionName(isset($r['competition_id']) ? (string) $r['competition_id'] : null, $r['competition_name'] ?? null);
            $r['club_name'] = (string) ($r['club_name'] ?? $r['club_id'] ?? '');
            $r['opponent_name'] = (string) ($r['opponent_name'] ?? $r['opponent_id'] ?? '');
            $r['club_logo'] = ClubRepository::logo($r['club_logo_raw'] ?? null, 'sm');
            $r['opponent_logo'] = ClubRepository::logo($r['opponent_logo_raw'] ?? null, 'sm');
            $r['season_label'] = (string) ($r['season_label'] ?: TransfermarktMapper::seasonLabel($r['season_id']));
            $r['is_national'] = $r['is_national'] === 1;
            unset($r['club_logo_raw'], $r['opponent_logo_raw']);
            return $r;
        }, $rows);
    }

    /** Number of games matching games() filters. */
    public function countGames(array $filters = []): int
    {
        [$where, $params] = self::where($filters);
        return (int) self::safe(static fn () => Database::fetchValue("SELECT COUNT(*) FROM games g {$where}", $params), 0);
    }

    /** Seasons with games (newest first): season_id => ['label', 'games'] (played games). */
    public function seasonOptions(): array
    {
        $rows = self::safe(static fn () => Database::fetchAll(
            "SELECT season_id, COUNT(*) AS games FROM games WHERE participation = 'played' GROUP BY season_id ORDER BY season_id DESC"
        ), []);
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['season_id']] = ['label' => TransfermarktMapper::seasonLabel((int) $row['season_id']), 'games' => (int) $row['games']];
        }
        return $out;
    }

    /** Competitions with played games (most games first): id => ['name', 'games']. */
    public function competitionOptions(): array
    {
        $rows = self::safe(static fn () => Database::fetchAll(
            "SELECT g.competition_id, MAX(c.name) AS name, COUNT(*) AS games FROM games g LEFT JOIN competitions c ON c.id = g.competition_id
             WHERE g.participation = 'played' AND g.competition_id IS NOT NULL GROUP BY g.competition_id ORDER BY games DESC, g.competition_id"
        ), []);
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['competition_id']] = ['name' => self::competitionName((string) $row['competition_id'], $row['name'] ?? null), 'games' => (int) $row['games']];
        }
        return $out;
    }

    /** Transfers, chronological, with club names/logos/slugs. @return list<array<string, mixed>> */
    public function transfers(): array
    {
        $rows = self::safe(static fn () => Database::fetchAll('SELECT * FROM transfers ORDER BY transfer_date, id'), []);
        $clubs = $this->clubs->many([...array_column($rows, 'from_club_id'), ...array_column($rows, 'to_club_id')]);
        return array_map(static function (array $r) use ($clubs): array {
            $from = $clubs[(string) ($r['from_club_id'] ?? '')] ?? null;
            $to = $clubs[(string) ($r['to_club_id'] ?? '')] ?? null;
            return [
                'id' => (string) $r['id'],
                'transfer_date' => self::dateString($r['transfer_date']),
                'season_label' => $r['season_label'],
                'from_club_id' => $r['from_club_id'],
                'from_name' => (string) ($from['name'] ?? '—'),
                'from_slug' => $from['slug'] ?? null,
                'from_logo' => ClubRepository::logo($from['logo_url'] ?? null, 'xs'),
                'to_club_id' => $r['to_club_id'],
                'to_name' => (string) ($to['name'] ?? '—'),
                'to_slug' => $to['slug'] ?? null,
                'to_logo' => ClubRepository::logo($to['logo_url'] ?? null, 'xs'),
                'fee_value' => isset($r['fee_value']) ? (int) $r['fee_value'] : null,
                'fee_label' => $r['fee_label'],
                'market_value' => isset($r['market_value']) ? (int) $r['market_value'] : null,
                'age' => isset($r['age']) ? (int) $r['age'] : null,
                'transfer_type' => $r['transfer_type'],
                'contract_until' => self::dateString($r['contract_until']),
            ];
        }, $rows);
    }

    /** Market value history, chronological. @return list<array{value_date: string, value: int, club_id: ?string, age: ?int}> */
    public function marketValues(): array
    {
        $rows = self::safe(static fn () => Database::fetchAll('SELECT value_date, value, club_id, age FROM market_values ORDER BY value_date'), []);
        return array_map(static fn (array $r): array => [
            'value_date' => (string) self::dateString($r['value_date']),
            'value' => (int) $r['value'],
            'club_id' => isset($r['club_id']) ? (string) $r['club_id'] : null,
            'age' => isset($r['age']) ? (int) $r['age'] : null,
        ], $rows);
    }

    /** All Portugal teams (A, U21, U20, U17, U15, Olympic), most games first. @return list<array<string, mixed>> */
    public function nationalCareer(): array
    {
        $rows = self::safe(static fn () => Database::fetchAll(
            'SELECT n.*, c.name, c.slug, c.logo_url FROM national_career n LEFT JOIN clubs c ON c.id = n.club_id ORDER BY n.games DESC, n.club_id'
        ), []);
        return array_map(static fn (array $r): array => [
            'club_id' => (string) $r['club_id'],
            'name' => (string) ($r['name'] ?? $r['club_id']),
            'slug' => $r['slug'] ?? null,
            'logo_url' => ClubRepository::logo($r['logo_url'] ?? null, 'xs'),
            'games' => (int) $r['games'],
            'goals' => (int) $r['goals'],
            'debut_date' => self::dateString($r['debut_date']),
            'last_game_date' => self::dateString($r['last_game_date']),
            'shirt_number' => isset($r['shirt_number']) ? (int) $r['shirt_number'] : null,
            'career_state' => $r['career_state'],
        ], $rows);
    }

    /** Injury history, newest first. @return list<array<string, mixed>> */
    public function injuries(): array
    {
        $rows = self::safe(static fn () => Database::fetchAll('SELECT * FROM injuries ORDER BY start_date DESC, id DESC'), []);
        return array_map(static fn (array $r): array => [
            'name' => (string) $r['name'],
            'season_id' => isset($r['season_id']) ? (int) $r['season_id'] : null,
            'season_label' => isset($r['season_id']) ? TransfermarktMapper::seasonLabel((int) $r['season_id']) : '',
            'start_date' => self::dateString($r['start_date']),
            'end_date' => self::dateString($r['end_date']),
            'days' => isset($r['days']) ? (int) $r['days'] : null,
            'missed_games' => isset($r['missed_games']) ? (int) $r['missed_games'] : null,
        ], $rows);
    }

    // ------------------------------------------------------------------ internals

    /**
     * WHERE clause for games filters (always starts with "WHERE").
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private static function where(array $filters): array
    {
        $sql = ['1 = 1'];
        $params = [];
        if (!empty($filters['played'])) {
            $sql[] = "g.participation = 'played'";
        }
        if (isset($filters['season_id'])) {
            $sql[] = 'g.season_id = ?';
            $params[] = (int) $filters['season_id'];
        }
        foreach (['competition_id', 'club_id', 'result'] as $key) {
            if (isset($filters[$key]) && $filters[$key] !== '') {
                $sql[] = "g.{$key} = ?";
                $params[] = (string) $filters[$key];
            }
        }
        if (isset($filters['from'])) {
            $sql[] = 'g.date_utc >= ?';
            $params[] = (string) $filters['from'];
        }
        if (isset($filters['to'])) {
            $sql[] = 'g.date_utc <= ?';
            $params[] = (string) $filters['to'];
        }
        if (isset($filters['min_goals'])) {
            $sql[] = 'g.goals >= ?';
            $params[] = (int) $filters['min_goals'];
        }
        if (isset($filters['is_national'])) {
            $sql[] = 'g.is_national = ?';
            $params[] = $filters['is_national'] ? 1 : 0;
        }
        return ['WHERE ' . implode(' AND ', $sql), $params];
    }

    /** "SUM(s.appearances) AS appearances, …" for every stat column. */
    private static function sumColumns(string $alias): string
    {
        return implode(', ', array_map(
            static fn (string $c): string => "SUM({$alias}.{$c}) AS {$c}",
            TransfermarktMapper::STAT_COLUMNS
        ));
    }

    /** STATCOLS of a row as ints (zeros for missing). */
    private static function statInts(array $row): array
    {
        $out = [];
        foreach (TransfermarktMapper::STAT_COLUMNS as $col) {
            $out[$col] = (int) ($row[$col] ?? 0);
        }
        return $out;
    }

    /** Spell key for a game of $clubId in $year: the latest spell of that club whose years contain it. */
    private static function spellFor(array $spells, string $clubId, int $year): ?string
    {
        $match = null;
        $nearest = null;
        $distance = PHP_INT_MAX;
        foreach ($spells as $key => $spell) {
            if ($spell['club_id'] !== $clubId) {
                continue;
            }
            $from = $spell['from_year'] ?? 0;
            $to = $spell['to_year'] ?? 9999;
            if ($year >= $from && $year <= $to) {
                $match = (string) $key;
            }
            $d = $year < $from ? $from - $year : max(0, $year - $to);
            if ($d < $distance) {
                $distance = $d;
                $nearest = (string) $key;
            }
        }
        return $match ?? $nearest;
    }

    /** Fallback spells (no content file): senior clubs from stats_club. */
    private function spellsFromStats(): array
    {
        $current = (string) ($this->player()['current_club_id'] ?? '');
        $spells = [];
        foreach ($this->clubStats() as $row) {
            if (!in_array($row['club_id'], TransfermarktSync::SENIOR_CLUB_IDS, true)) {
                continue;
            }
            $from = $row['first_season_id'];
            $to = $row['club_id'] === $current ? null : ($row['last_season_id'] !== null ? $row['last_season_id'] + 1 : null);
            $spells[$row['club_id']] = [
                'club_id' => $row['club_id'], 'name' => $row['name'], 'slug' => (string) ($row['slug'] ?? ''),
                'from_year' => $from, 'to_year' => $to, 'period' => '', 'country' => '', 'summary' => '',
            ];
        }
        return $spells;
    }

    private static function period(?int $from, ?int $to): string
    {
        if ($from === null) {
            return '';
        }
        return $to === null ? $from . '–' : ($from === $to ? (string) $from : $from . '–' . $to);
    }

    private static function age(mixed $birthDate): ?int
    {
        if (!is_string($birthDate) || preg_match('/^\d{4}-\d{2}-\d{2}/', $birthDate) !== 1) {
            return null;
        }
        try {
            $birth = new \DateTimeImmutable(substr($birthDate, 0, 10), Date::timezone());
        } catch (\Exception) {
            return null;
        }
        return $birth->diff(Date::local())->y;
    }

    private static function dateString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? substr($value, 0, 10) : null;
    }

    /** Run a query; $default when the schema is missing / DB unreachable (never fatal on public pages). */
    private static function safe(callable $fn, mixed $default): mixed
    {
        try {
            return $fn() ?? $default;
        } catch (\PDOException $e) {
            \App\Support\Log::error('stats', 'Query failed', ['error' => $e->getMessage()]);
            return $default;
        }
    }
}
