<?php

declare(strict_types=1);

namespace App\Services\Transfermarkt;

use App\Core\Config;
use App\Core\Database;
use App\Support\Date;
use App\Support\Log;
use App\Support\Str;

/**
 * Pulls Cristiano Ronaldo's data from the Transfermarkt API into our tables:
 * player, stats_season, stats_club, stats_competition, stats_summary, games, transfers,
 * market_values, national_career, injuries, clubs and competitions (names/logos/slugs of
 * every referenced id).
 *
 * All requests happen first (cached in api_cache, stale cache as fallback), then every
 * table is written inside ONE transaction, so readers never see a half-synced state.
 * Idempotent: re-running with the same API data leaves the database unchanged
 * (except updated_at); rows that disappeared from the API are removed.
 */
final class TransfermarktSync
{
    /** Senior clubs (chronological) — the 'clubs' summary scope. */
    public const SENIOR_CLUB_IDS = ['336', '985', '418', '506', '18544'];
    /** Portugal A national team — the 'national' summary scope. */
    public const NATIONAL_TEAM_ID = '3300';
    /** Stable URL slugs of the career clubs (/takimlar/{slug}). */
    public const FIXED_SLUGS = [
        '336' => 'sporting-cp',
        '985' => 'manchester-united',
        '418' => 'real-madrid',
        '506' => 'juventus',
        '18544' => 'al-nassr',
        '3300' => 'portekiz',
    ];

    private const STATS_TTL = 1800;          // player statistics: reuse a response for 30 min
    private const META_TTL = 7 * 86400;      // clubs/competitions batch responses
    private const META_REFRESH_DAYS = 30;    // re-fetch club/competition rows older than this
    private const BATCH_SIZE = 25;           // API limit for clubs?ids[]= / competitions?ids[]=
    private const SINGLE_FETCH_LIMIT = 10;   // club/{id} fallback for ids a batch did not return

    private TransfermarktClient $client;
    private int $playerId;

    public function __construct(?TransfermarktClient $client = null, ?int $playerId = null)
    {
        $this->client = $client ?? new TransfermarktClient();
        $this->playerId = $playerId ?? (int) Config::get('transfermarkt.player_id', 8198);
    }

    /**
     * Run the full sync; returns a short Turkish status line. Throws when no data is available at all.
     * $now (UTC 'Y-m-d H:i:s') is only passed by tests; it stamps updated_at and drives pruning.
     */
    public function run(?string $now = null): string
    {
        @set_time_limit(300);
        $started = microtime(true);
        $now ??= Date::now();
        $p = 'player/' . $this->playerId;

        // ---- 1. Fetch (network or cache) ------------------------------------------------
        $profile = $this->client->get($p, self::STATS_TTL);
        $seasonData = $this->client->get($p . '/performance-season', self::STATS_TTL);
        $clubData = $this->client->get($p . '/performance-club', self::STATS_TTL);
        $competitionData = $this->client->get($p . '/performance-competition', self::STATS_TTL);
        $gameData = $this->client->get($p . '/performance-game', self::STATS_TTL);
        $nationalData = $this->client->get($p . '/national-career-history', self::STATS_TTL);
        $transferData = $this->client->get('transfer/history/player/' . $this->playerId, self::STATS_TTL);
        $marketData = $this->client->get($p . '/market-value-history', self::STATS_TTL);
        $injuryData = $this->client->get($p . '/injury', self::STATS_TTL);

        if ($profile === null && $seasonData === null && $gameData === null) {
            throw new \RuntimeException('Transfermarkt API yanıt vermedi ve önbellekte kullanılabilir veri yok.');
        }

        // ---- 2. Map ---------------------------------------------------------------------
        $playerRow = $profile !== null
            ? TransfermarktMapper::playerRow($profile, (string) json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $now)
            : null;

        $seasonRows = $seasonData !== null ? TransfermarktMapper::seasonRows($seasonData, $now) : [];
        $clubRows = $clubData !== null ? TransfermarktMapper::clubRows($clubData, $seasonRows, $now) : [];
        if ($clubRows === [] && $seasonRows !== []) {
            $clubRows = self::aggregate($seasonRows, 'club_id', $now, true);
        }
        $competitionRows = $competitionData !== null ? TransfermarktMapper::competitionRows($competitionData, $now) : [];
        if ($competitionRows === [] && $seasonRows !== []) {
            $competitionRows = self::aggregate($seasonRows, 'competition_id', $now, false);
        }

        $summaryRows = [];
        if ($seasonRows !== [] || $clubRows !== []) {
            $aggregated = $seasonData['aggregated'] ?? $clubData['aggregated'] ?? null;
            $summaryRows = [
                ['scope' => 'career'] + TransfermarktMapper::careerSummary(is_array($aggregated) ? $aggregated : null, $seasonRows) + ['updated_at' => $now],
                ['scope' => 'clubs'] + TransfermarktMapper::sumStats($clubRows, self::SENIOR_CLUB_IDS) + ['updated_at' => $now],
                ['scope' => 'national'] + TransfermarktMapper::sumStats($clubRows, [self::NATIONAL_TEAM_ID]) + ['updated_at' => $now],
            ];
        }

        $gameRows = [];
        foreach ((array) ($gameData['performance'] ?? []) as $entry) {
            $row = is_array($entry) ? TransfermarktMapper::gameRow($entry, $now) : null;
            if ($row !== null) {
                $gameRows[$row['game_id']] = $row;
            }
        }
        $gameRows = array_values($gameRows);
        unset($gameData);

        $transferRows = self::mapList((array) ($transferData['history']['terminated'] ?? []), static fn (array $e): ?array => TransfermarktMapper::transferRow($e, $now), 'id');
        $marketRows = self::mapList((array) ($marketData['history'] ?? []), static fn (array $e): ?array => TransfermarktMapper::marketValueRow($e), 'value_date');
        $nationalRows = self::mapList((array) ($nationalData['history'] ?? []), static fn (array $e): ?array => TransfermarktMapper::nationalRow($e, $now), 'club_id');
        $injuryRows = self::mapList((array) ($injuryData['injuries'] ?? []), static fn (array $e): ?array => TransfermarktMapper::injuryRow($e), 'injury_key');

        // ---- 3. Resolve names/logos of every referenced club and competition -------------
        $clubIds = array_keys(self::FIXED_SLUGS);
        foreach ([$seasonRows, $clubRows, $nationalRows, $marketRows] as $rows) {
            foreach ($rows as $row) {
                $clubIds[] = (string) ($row['club_id'] ?? '');
            }
        }
        foreach ($gameRows as $row) {
            $clubIds[] = (string) $row['club_id'];
            $clubIds[] = (string) $row['opponent_id'];
        }
        foreach ($transferRows as $row) {
            $clubIds[] = (string) $row['from_club_id'];
            $clubIds[] = (string) $row['to_club_id'];
        }
        if ($playerRow !== null) {
            $clubIds[] = (string) $playerRow['current_club_id'];
        }
        $competitionIds = [];
        foreach ([$seasonRows, $competitionRows, $gameRows] as $rows) {
            foreach ($rows as $row) {
                $competitionIds[] = (string) ($row['competition_id'] ?? '');
            }
        }
        $clubMetaRows = $this->resolveClubs(self::uniqueIds($clubIds), $now);
        $competitionMetaRows = $this->resolveCompetitions(self::uniqueIds($competitionIds), $now);

        // ---- 4. Write everything atomically ----------------------------------------------
        $existingGames = (int) (Database::fetchValue('SELECT COUNT(*) FROM games') ?? 0);
        Database::transaction(function () use (
            $now, $playerRow, $clubMetaRows, $competitionMetaRows, $seasonRows, $clubRows, $competitionRows,
            $summaryRows, $gameRows, $existingGames, $transferRows, $marketRows, $nationalRows, $injuryRows
        ): void {
            if ($playerRow !== null) {
                Database::upsert('player', $playerRow, ['id']);
            }
            Database::upsertMany('clubs', $clubMetaRows, ['id']);
            Database::upsertMany('competitions', $competitionMetaRows, ['id']);
            self::replace('stats_season', $seasonRows, ['season_id', 'competition_id', 'club_id'], $now);
            self::replace('stats_club', $clubRows, ['club_id'], $now);
            self::replace('stats_competition', $competitionRows, ['competition_id'], $now);
            self::replace('stats_summary', $summaryRows, ['scope'], $now);
            // Guard against a truncated API response wiping the match log.
            $pruneGames = count($gameRows) >= (int) floor($existingGames * 0.5);
            self::replace('games', $gameRows, ['game_id'], $now, $pruneGames);
            if (!$pruneGames) {
                Log::warning('transfermarkt', 'performance-game returned far fewer games than stored; old games kept', ['new' => count($gameRows), 'stored' => $existingGames]);
            }
            self::replace('transfers', $transferRows, ['id'], $now);
            self::replace('national_career', $nationalRows, ['club_id'], $now);
            self::replaceAll('market_values', $marketRows, ['value_date']);
            self::replaceAll('injuries', $injuryRows, ['injury_key']);
        });

        $stats = $this->client->stats();
        $missing = [];
        foreach ([
            'profil' => $profile, 'sezon' => $seasonData, 'kulüp' => $clubData, 'turnuva' => $competitionData,
            'transfer' => $transferData, 'piyasa değeri' => $marketData, 'milli takım' => $nationalData, 'sakatlık' => $injuryData,
        ] as $label => $value) {
            if ($value === null) {
                $missing[] = $label;
            }
        }
        if ($gameRows === []) {
            $missing[] = 'maçlar';
        }
        $message = sprintf(
            '%s maç, %d sezon satırı, %d takım, %d turnuva, %d transfer, %d piyasa değeri, %d milli takım, %d sakatlık; %d kulüp ve %d turnuva bilgisi güncellendi (API %d, önbellek %d, eski önbellek %d, hata %d) – %s sn',
            number_format(count($gameRows), 0, ',', '.'),
            count($seasonRows),
            count($clubRows),
            count($competitionRows),
            count($transferRows),
            count($marketRows),
            count($nationalRows),
            count($injuryRows),
            count($clubMetaRows),
            count($competitionMetaRows),
            $stats['api'],
            $stats['cache'],
            $stats['stale'],
            $stats['failed'],
            number_format(microtime(true) - $started, 1, ',', '.')
        );
        if ($missing !== []) {
            $message .= '. Eksik veri: ' . implode(', ', $missing);
        }
        Log::info('transfermarkt', 'Sync finished', ['games' => count($gameRows), 'seasons' => count($seasonRows), 'clubs' => count($clubMetaRows), 'requests' => $stats, 'missing' => $missing, 'peak_mb' => round(memory_get_peak_usage(true) / 1048576, 1)]);
        return $message;
    }

    /**
     * Fetch clubs that are new, stale (> META_REFRESH_DAYS) or career clubs, in batches of 25.
     *
     * @param list<string> $ids
     * @return list<array<string, mixed>> clubs rows to upsert
     */
    private function resolveClubs(array $ids, string $now): array
    {
        $existing = [];
        foreach (Database::fetchAll('SELECT id, slug, updated_at FROM clubs') as $row) {
            $existing[(string) $row['id']] = $row;
        }
        $staleBefore = gmdate('Y-m-d H:i:s', time() - self::META_REFRESH_DAYS * 86400);
        $needed = array_values(array_filter($ids, static fn (string $id): bool => isset(self::FIXED_SLUGS[$id])
            || !isset($existing[$id]) || (string) $existing[$id]['updated_at'] < $staleBefore));

        $fetched = [];
        foreach (array_chunk($needed, self::BATCH_SIZE) as $chunk) {
            $data = $this->client->get('clubs?' . self::idQuery($chunk), self::META_TTL);
            foreach ((array) $data as $club) {
                if (is_array($club) && isset($club['id'])) {
                    $fetched[(string) $club['id']] = $club;
                }
            }
        }
        $missing = array_values(array_diff($needed, array_keys($fetched)));
        foreach (array_slice($missing, 0, self::SINGLE_FETCH_LIMIT) as $id) {
            $club = $this->client->get('club/' . rawurlencode($id), self::META_TTL);
            if (is_array($club) && isset($club['id'])) {
                $fetched[(string) $club['id']] = $club;
            }
        }
        if (count($missing) > self::SINGLE_FETCH_LIMIT) {
            Log::warning('transfermarkt', 'Some clubs could not be resolved', ['count' => count($missing) - self::SINGLE_FETCH_LIMIT]);
        }

        // Slugs: fixed for career clubs, stable once assigned, unique otherwise.
        $used = [];
        foreach ($existing as $id => $row) {
            $used[(string) $row['slug']] = (string) $id;
        }
        foreach (self::FIXED_SLUGS as $id => $slug) {
            $used[$slug] = (string) $id;
        }
        $rows = [];
        ksort($fetched, SORT_STRING);
        foreach ($fetched as $id => $club) {
            $id = (string) $id;
            $slug = self::FIXED_SLUGS[$id] ?? null;
            if ($slug === null) {
                $current = isset($existing[$id]) ? (string) $existing[$id]['slug'] : '';
                $slug = ($current !== '' && ($used[$current] ?? $id) === $id) ? $current : null;
            }
            if ($slug === null) {
                $slug = Str::slug((string) ($club['name'] ?? ''), 120);
                if ($slug === '') {
                    $slug = 'kulup-' . Str::slug($id);
                }
                if (isset($used[$slug]) && $used[$slug] !== $id) {
                    $slug .= '-' . Str::slug($id);
                }
            }
            $used[$slug] = $id;
            $row = TransfermarktMapper::clubMetaRow($club, $slug, $now);
            if ($row !== null) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    /**
     * @param list<string> $ids
     * @return list<array<string, mixed>> competitions rows to upsert
     */
    private function resolveCompetitions(array $ids, string $now): array
    {
        $existing = [];
        foreach (Database::fetchAll('SELECT id, updated_at FROM competitions') as $row) {
            $existing[(string) $row['id']] = (string) $row['updated_at'];
        }
        $staleBefore = gmdate('Y-m-d H:i:s', time() - self::META_REFRESH_DAYS * 86400);
        $needed = array_values(array_filter($ids, static fn (string $id): bool => !isset($existing[$id]) || $existing[$id] < $staleBefore));
        $rows = [];
        foreach (array_chunk($needed, self::BATCH_SIZE) as $chunk) {
            foreach ((array) $this->client->get('competitions?' . self::idQuery($chunk), self::META_TTL) as $competition) {
                $row = is_array($competition) ? TransfermarktMapper::competitionMetaRow($competition, $now) : null;
                if ($row !== null) {
                    $rows[$row['id']] = $row;
                }
            }
        }
        ksort($rows, SORT_STRING);
        return array_values($rows);
    }

    /**
     * Upsert $rows and delete rows of $table that were not part of this sync (updated_at <> $now).
     * Empty $rows leave the table untouched (a failed endpoint never wipes data).
     */
    private static function replace(string $table, array $rows, array $conflict, string $now, bool $prune = true): void
    {
        if ($rows === []) {
            return;
        }
        Database::upsertMany($table, $rows, $conflict);
        if ($prune) {
            Database::execute('DELETE FROM ' . Database::quoteIdent($table) . ' WHERE updated_at IS NULL OR updated_at <> ?', [$now]);
        }
    }

    /** Small tables without updated_at: replace the whole content (inside the caller's transaction). */
    private static function replaceAll(string $table, array $rows, array $conflict): void
    {
        if ($rows === []) {
            return;
        }
        Database::execute('DELETE FROM ' . Database::quoteIdent($table));
        Database::upsertMany($table, $rows, $conflict);
    }

    /**
     * Fallback when performance-club/-competition is unavailable: aggregate season rows.
     *
     * @param list<array<string, mixed>> $seasonRows
     * @return list<array<string, mixed>>
     */
    private static function aggregate(array $seasonRows, string $key, string $now, bool $withSeasons): array
    {
        $out = [];
        foreach ($seasonRows as $row) {
            $id = (string) $row[$key];
            if (!isset($out[$id])) {
                $out[$id] = [$key => $id] + TransfermarktMapper::zeroStats();
                if ($withSeasons) {
                    $out[$id]['first_season_id'] = (int) $row['season_id'];
                    $out[$id]['last_season_id'] = (int) $row['season_id'];
                }
            }
            foreach (TransfermarktMapper::STAT_COLUMNS as $col) {
                $out[$id][$col] += (int) $row[$col];
            }
            if ($withSeasons) {
                $out[$id]['first_season_id'] = min($out[$id]['first_season_id'], (int) $row['season_id']);
                $out[$id]['last_season_id'] = max($out[$id]['last_season_id'], (int) $row['season_id']);
            }
        }
        foreach ($out as $id => $row) {
            $out[$id]['updated_at'] = $now;
        }
        return array_values($out);
    }

    /**
     * Map a list with $fn, dropping nulls and de-duplicating on $key (last one wins).
     *
     * @return list<array<string, mixed>>
     */
    private static function mapList(array $entries, callable $fn, string $key): array
    {
        $rows = [];
        foreach ($entries as $entry) {
            $row = is_array($entry) ? $fn($entry) : null;
            if ($row !== null) {
                $rows[(string) $row[$key]] = $row;
            }
        }
        return array_values($rows);
    }

    /** @return list<string> sorted unique non-empty ids */
    private static function uniqueIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('strval', $ids), static fn (string $id): bool => $id !== '' && $id !== '0')));
        sort($ids, SORT_STRING);
        return $ids;
    }

    /** ['1', '2'] => 'ids[]=1&ids[]=2' */
    private static function idQuery(array $ids): string
    {
        return implode('&', array_map(static fn (string $id): string => 'ids[]=' . rawurlencode($id), $ids));
    }
}
