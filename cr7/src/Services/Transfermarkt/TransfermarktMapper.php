<?php

declare(strict_types=1);

namespace App\Services\Transfermarkt;

use App\Support\Date;
use App\Support\Str;

/**
 * Pure functions that turn Transfermarkt API payloads into rows of our schema (§5).
 * No I/O here, so every mapping is unit-testable with small fixtures.
 */
final class TransfermarktMapper
{
    /** Shared stat columns ("STATCOLS"). */
    public const STAT_COLUMNS = [
        'appearances', 'goals', 'assists', 'own_goals', 'minutes', 'starts', 'sub_in', 'sub_out',
        'yellow', 'yellow_red', 'red', 'penalty_goals', 'penalty_attempts',
    ];

    /** English fallbacks (when the API ignores Accept-Language) => Turkish. */
    private const POSITIONS_TR = [
        'goalkeeper' => 'Kaleci', 'sweeper' => 'Libero', 'centre-back' => 'Stoper', 'left-back' => 'Sol Bek',
        'right-back' => 'Sağ Bek', 'defensive midfield' => 'Ön Libero', 'central midfield' => 'Merkez Orta Saha',
        'right midfield' => 'Sağ Orta Saha', 'left midfield' => 'Sol Orta Saha', 'attacking midfield' => 'Ofansif Orta Saha',
        'left winger' => 'Sol Kanat', 'right winger' => 'Sağ Kanat', 'second striker' => 'Gizli Forvet',
        'centre-forward' => 'Santrafor', 'forward' => 'Forvet', 'midfield' => 'Orta Saha', 'defender' => 'Defans',
    ];
    private const FOOT_TR = ['right' => 'Sağ ayak', 'left' => 'Sol ayak', 'both' => 'Her iki ayak'];

    /** STATCOLS from a {goalStatistics, cardStatistics, playingTimeStatistics} block (aggregated sums). */
    public static function statCols(array $statistics): array
    {
        $g = (array) ($statistics['goalStatistics'] ?? []);
        $c = (array) ($statistics['cardStatistics'] ?? []);
        $p = (array) ($statistics['playingTimeStatistics'] ?? []);
        return [
            'appearances' => self::int($p['appearancesCount'] ?? 0),
            'goals' => self::int($g['goalsSum'] ?? 0),
            'assists' => self::int($g['assistsSum'] ?? 0),
            'own_goals' => self::int($g['ownGoalsSum'] ?? 0),
            'minutes' => self::int($p['playedMinutesSum'] ?? 0),
            'starts' => self::int($p['startingCount'] ?? 0),
            'sub_in' => self::int($p['substitutedInCount'] ?? 0),
            'sub_out' => self::int($p['substitutedOutCount'] ?? 0),
            'yellow' => self::int($c['yellowCardNetSum'] ?? $c['yellowCardGrossSum'] ?? 0),
            'yellow_red' => self::int($c['yellowRedCardsCount'] ?? 0),
            'red' => self::int($c['redCardsCount'] ?? 0),
            'penalty_goals' => self::int($g['penaltyShooterGoalsScored'] ?? 0),
            'penalty_attempts' => self::int($g['penaltyShooterAttempts'] ?? 0),
        ];
    }

    /** All-zero STATCOLS. */
    public static function zeroStats(): array
    {
        return array_fill_keys(self::STAT_COLUMNS, 0);
    }

    /** 2009 => '2009/10' (TM season ids are the starting year). */
    public static function seasonLabel(int $seasonId): string
    {
        return sprintf('%d/%02d', $seasonId, ($seasonId + 1) % 100);
    }

    /**
     * stats_season rows from player/{id}/performance-season.
     *
     * @return list<array<string, mixed>>
     */
    public static function seasonRows(array $data, string $now): array
    {
        $rows = [];
        foreach ((array) ($data['performance'] ?? []) as $entry) {
            $info = (array) ($entry['generalInformation'] ?? []);
            $seasonId = self::int($info['seasonId'] ?? 0);
            $competition = trim((string) ($info['competitionId'] ?? ''));
            $club = trim((string) ($info['clubId'] ?? ''));
            if ($seasonId <= 0 || $competition === '' || $club === '') {
                continue;
            }
            $key = $seasonId . '|' . $competition . '|' . $club;
            $stats = self::statCols((array) ($entry['statistics'] ?? []));
            if (isset($rows[$key])) { // defensive: merge duplicates instead of violating the unique key
                foreach (self::STAT_COLUMNS as $col) {
                    $rows[$key][$col] += $stats[$col];
                }
                continue;
            }
            $rows[$key] = ['season_id' => $seasonId, 'season_label' => self::seasonLabel($seasonId), 'competition_id' => $competition, 'club_id' => $club]
                + $stats + ['updated_at' => $now];
        }
        return array_values($rows);
    }

    /**
     * stats_club rows from player/{id}/performance-club; first/last season from the season rows.
     *
     * @param list<array<string, mixed>> $seasonRows
     * @return list<array<string, mixed>>
     */
    public static function clubRows(array $data, array $seasonRows, string $now): array
    {
        $range = [];
        foreach ($seasonRows as $row) {
            $club = (string) $row['club_id'];
            $season = (int) $row['season_id'];
            $range[$club] = [min($range[$club][0] ?? $season, $season), max($range[$club][1] ?? $season, $season)];
        }
        $rows = [];
        foreach ((array) ($data['performance'] ?? []) as $entry) {
            $info = (array) ($entry['generalInformation'] ?? []);
            $club = trim((string) ($info['clubId'] ?? ''));
            if ($club === '') {
                continue;
            }
            $stats = self::statCols((array) ($entry['statistics'] ?? []));
            if (isset($rows[$club])) {
                foreach (self::STAT_COLUMNS as $col) {
                    $rows[$club][$col] += $stats[$col];
                }
                continue;
            }
            $first = $range[$club][0] ?? (self::int($info['seasonId'] ?? 0) ?: null);
            $rows[$club] = ['club_id' => $club] + $stats + [
                'first_season_id' => $first,
                'last_season_id' => $range[$club][1] ?? $first,
                'updated_at' => $now,
            ];
        }
        return array_values($rows);
    }

    /**
     * stats_competition rows from player/{id}/performance-competition.
     *
     * @return list<array<string, mixed>>
     */
    public static function competitionRows(array $data, string $now): array
    {
        $rows = [];
        foreach ((array) ($data['performance'] ?? []) as $entry) {
            $competition = trim((string) ($entry['generalInformation']['competitionId'] ?? ''));
            if ($competition === '') {
                continue;
            }
            $stats = self::statCols((array) ($entry['statistics'] ?? []));
            if (isset($rows[$competition])) {
                foreach (self::STAT_COLUMNS as $col) {
                    $rows[$competition][$col] += $stats[$col];
                }
                continue;
            }
            $rows[$competition] = ['competition_id' => $competition] + $stats + ['updated_at' => $now];
        }
        return array_values($rows);
    }

    /**
     * Sum STATCOLS of the given rows (optionally only rows whose $keyColumn is in $only).
     *
     * @param list<array<string, mixed>> $rows
     * @param list<string>|null $only
     */
    public static function sumStats(array $rows, ?array $only = null, string $keyColumn = 'club_id'): array
    {
        $sum = self::zeroStats();
        foreach ($rows as $row) {
            if ($only !== null && !in_array((string) ($row[$keyColumn] ?? ''), $only, true)) {
                continue;
            }
            foreach (self::STAT_COLUMNS as $col) {
                $sum[$col] += (int) ($row[$col] ?? 0);
            }
        }
        return $sum;
    }

    /**
     * 'career' summary: TM aggregated block; penalties are not aggregated by TM, so they are summed
     * from the season rows (and anything else the aggregate lacks).
     *
     * @param list<array<string, mixed>> $seasonRows
     */
    public static function careerSummary(?array $aggregated, array $seasonRows): array
    {
        $fromSeasons = self::sumStats($seasonRows);
        if ($aggregated === null || $aggregated === []) {
            return $fromSeasons;
        }
        $stats = self::statCols($aggregated);
        foreach (['penalty_goals', 'penalty_attempts'] as $col) {
            if ($stats[$col] === 0) {
                $stats[$col] = $fromSeasons[$col];
            }
        }
        return $stats;
    }

    /** games row from one performance-game entry (null when the entry is unusable). */
    public static function gameRow(array $entry, string $now): ?array
    {
        $info = (array) ($entry['gameInformation'] ?? []);
        $gameId = trim((string) ($info['gameId'] ?? ''));
        $dateRaw = (string) ($info['date']['dateTimeUTC'] ?? '');
        $seasonId = self::int($info['seasonId'] ?? ($info['season']['id'] ?? 0));
        if ($gameId === '' || $dateRaw === '' || $seasonId <= 0) {
            return null;
        }
        try {
            $date = Date::toUtc($dateRaw);
        } catch (\InvalidArgumentException) {
            return null;
        }
        $club = (array) ($entry['clubsInformation']['club'] ?? []);
        $opponent = (array) ($entry['clubsInformation']['opponent'] ?? []);
        $stats = (array) ($entry['statistics'] ?? []);
        $general = (array) ($stats['generalStatistics'] ?? []);
        $goals = (array) ($stats['goalStatistics'] ?? []);
        $cards = (array) ($stats['cardStatistics'] ?? []);
        $time = (array) ($stats['playingTimeStatistics'] ?? []);
        $distribution = (array) ($stats['distributionStatistics'] ?? []);

        $for = self::nullableInt($club['goalsTotal'] ?? null);
        $against = self::nullableInt($club['opponentGoalsTotal'] ?? null);
        $result = ($for === null || $against === null) ? null : ($for > $against ? 'W' : ($for < $against ? 'L' : 'D'));
        $venue = strtolower((string) ($club['venue'] ?? ''));
        $participation = trim((string) ($general['participationState'] ?? ''));
        $played = $participation === 'played';
        $yellow = self::nullableInt($cards['yellowCardNet'] ?? null) ?? (isset($cards['yellowCard']) ? 1 : 0);
        $red = (isset($cards['redCard']) || isset($cards['yellowRedCard'])) ? 1 : 0;
        $accuracy = $distribution['passesReachedRatio'] ?? null;

        return [
            'game_id' => $gameId,
            'date_utc' => $date,
            'season_id' => $seasonId,
            'season_label' => self::seasonLabel($seasonId),
            'competition_id' => self::nullableString($info['competitionId'] ?? null),
            'club_id' => self::nullableString($club['clubId'] ?? null),
            'opponent_id' => self::nullableString($opponent['clubId'] ?? null),
            'venue' => in_array($venue, ['home', 'away', 'neutral'], true) ? $venue : null,
            'goals_for' => $for,
            'goals_against' => $against,
            'result' => $result,
            'participation' => $participation !== '' ? mb_substr($participation, 0, 40) : null,
            'is_starting' => $played && !empty($time['isStarting']) ? 1 : 0,
            'minutes' => self::int($time['playedMinutes'] ?? 0),
            'goals' => self::int($goals['goalsScoredTotal'] ?? 0),
            'assists' => self::int($goals['assists'] ?? 0),
            'penalty_goals' => self::int($goals['penaltyShooterGoalsScored'] ?? 0),
            'yellow' => $yellow,
            'red' => $red,
            'is_captain' => !empty($general['isCaptain']) ? 1 : 0,
            'shirt_number' => self::nullableInt($general['shirtNumber'] ?? null),
            'is_national' => !empty($info['isNationalGame']) ? 1 : 0,
            'shots' => self::nullableInt($goals['scoringAttempts'] ?? null),
            'shots_on_target' => self::nullableInt($goals['scoringAttemptsOnGoal'] ?? null),
            'passes' => self::nullableInt($distribution['passes'] ?? null),
            'pass_accuracy' => is_numeric($accuracy) ? round((float) $accuracy, 1) : null,
            'updated_at' => $now,
        ];
    }

    /** transfers row from one transfer/history entry. */
    public static function transferRow(array $entry, string $now): ?array
    {
        $id = trim((string) ($entry['id'] ?? ''));
        if ($id === '') {
            return null;
        }
        $details = (array) ($entry['details'] ?? []);
        $fee = (array) ($details['fee'] ?? []);
        $feeValue = self::nullableInt($fee['value'] ?? null);
        $type = strtoupper(trim((string) ($entry['typeDetails']['type'] ?? '')));
        $feeLabel = null;
        if ($feeValue === null) {
            $content = trim((string) ($fee['compact']['content'] ?? ''));
            $lower = mb_strtolower($content);
            if (in_array($lower, ['bedelsiz', 'free transfer', 'ablösefrei'], true)) {
                $feeLabel = 'Bedelsiz';
            } elseif (str_contains($type, 'LOAN')) {
                $feeLabel = 'Kiralık';
            } elseif ($content !== '' && $content !== '-' && $content !== '?') {
                $feeLabel = mb_substr($content, 0, 60);
            }
        }
        return [
            'id' => $id,
            'transfer_date' => self::dateOnly($details['date'] ?? null),
            'season_label' => isset($details['seasonId']) ? self::seasonLabel(self::int($details['seasonId'])) : null,
            'from_club_id' => self::nullableString($entry['transferSource']['clubId'] ?? null),
            'to_club_id' => self::nullableString($entry['transferDestination']['clubId'] ?? null),
            'fee_value' => $feeValue,
            'fee_label' => $feeLabel,
            'market_value' => self::nullableInt($details['marketValue']['value'] ?? null),
            'age' => self::nullableInt($details['age'] ?? null),
            'transfer_type' => $type !== '' ? substr($type, 0, 30) : null,
            'contract_until' => self::dateOnly($details['contractUntilDate'] ?? null),
            'updated_at' => $now,
        ];
    }

    /** market_values row from one market-value-history entry. */
    public static function marketValueRow(array $entry): ?array
    {
        $value = self::nullableInt($entry['marketValue']['value'] ?? null);
        $date = self::dateOnly($entry['marketValue']['determined'] ?? null);
        if ($value === null || $date === null) {
            return null;
        }
        return [
            'value_date' => $date,
            'value' => $value,
            'club_id' => self::nullableString($entry['clubId'] ?? null),
            'age' => self::nullableInt($entry['age'] ?? null),
            'season_id' => self::nullableInt($entry['seasonId'] ?? null),
        ];
    }

    /** national_career row from one national-career-history entry. */
    public static function nationalRow(array $entry, string $now): ?array
    {
        $club = self::nullableString($entry['clubId'] ?? null);
        if ($club === null) {
            return null;
        }
        return [
            'club_id' => $club,
            'games' => self::int($entry['gamesPlayed'] ?? 0),
            'goals' => self::int($entry['goalsScored'] ?? 0),
            'debut_date' => self::dateOnly($entry['debut'] ?? null),
            'last_game_date' => self::dateOnly($entry['lastGame'] ?? null),
            'shirt_number' => self::nullableInt($entry['shirtNumber'] ?? null),
            'career_state' => self::nullableString($entry['careerState'] ?? null),
            'updated_at' => $now,
        ];
    }

    /** injuries row from one player/{id}/injury entry (injury ids repeat, so the key includes the start date). */
    public static function injuryRow(array $entry): ?array
    {
        $name = trim((string) ($entry['name'] ?? ''));
        $start = self::dateOnly($entry['start'] ?? null);
        if ($name === '' && $start === null) {
            return null;
        }
        $key = substr(implode('-', [self::int($entry['injuryId'] ?? 0), $start ?? 'x', self::int($entry['seasonId'] ?? 0)]), 0, 64);
        return [
            'injury_key' => $key,
            'season_id' => self::nullableInt($entry['seasonId'] ?? null),
            'name' => mb_substr(self::sentenceCase($name !== '' ? $name : 'Bilinmeyen sakatlık'), 0, 190),
            'start_date' => $start,
            'end_date' => self::dateOnly($entry['end'] ?? null),
            'days' => self::nullableInt($entry['durationDetails']['days'] ?? null),
            'missed_games' => self::nullableInt($entry['missedGamesCount'] ?? null),
        ];
    }

    /** player row from player/{id}. */
    public static function playerRow(array $data, string $rawPayload, string $now): ?array
    {
        $id = self::int($data['id'] ?? 0);
        if ($id <= 0) {
            return null;
        }
        $attributes = (array) ($data['attributes'] ?? []);
        $market = (array) ($data['marketValueDetails'] ?? []);
        $height = $attributes['height'] ?? null;
        $heightCm = is_numeric($height) ? (int) round((float) $height < 3 ? (float) $height * 100 : (float) $height) : null;

        $sides = [];
        foreach (['firstSidePosition', 'secondSidePosition'] as $key) {
            $name = self::position($attributes[$key]['name'] ?? null);
            if ($name !== null) {
                $sides[] = $name;
            }
        }
        $current = null;
        foreach ((array) ($data['clubAssignments'] ?? []) as $assignment) {
            if (($assignment['type'] ?? '') === 'current') {
                $current = $assignment;
                break;
            }
        }
        $currentClub = self::nullableString($current['clubId'] ?? null);
        if ($currentClub === null && self::int($data['lastClubId'] ?? 0) > 0) {
            $currentClub = (string) self::int($data['lastClubId']);
        }
        $foot = self::nullableString($attributes['preferredFoot']['name'] ?? null);
        if ($foot !== null && isset(self::FOOT_TR[strtolower($foot)])) {
            $foot = self::FOOT_TR[strtolower($foot)];
        }

        return [
            'id' => $id,
            'name' => mb_substr((string) ($data['name'] ?? $data['shortName'] ?? 'Cristiano Ronaldo'), 0, 120),
            'full_name' => self::nullableString($data['displayName'] ?? null),
            'birth_date' => self::dateOnly($data['lifeDates']['dateOfBirth'] ?? null),
            'birth_place' => self::nullableString($data['birthPlaceDetails']['placeOfBirth'] ?? null),
            'height_cm' => $heightCm,
            'foot' => $foot,
            'position' => self::position($attributes['position']['name'] ?? null),
            'side_positions' => $sides !== [] ? implode(', ', $sides) : null,
            'shirt_number' => self::nullableInt($current['shirtNumber'] ?? null),
            'current_club_id' => $currentClub,
            'contract_until' => self::dateOnly($attributes['contractUntil'] ?? null),
            'agent' => self::nullableString($attributes['consultantAgency']['name'] ?? null),
            'outfitter' => self::nullableString($attributes['outfitter']['name'] ?? null),
            'market_value' => self::nullableInt($market['current']['value'] ?? null),
            'market_value_date' => self::dateOnly($market['current']['determined'] ?? null),
            'highest_market_value' => self::nullableInt($market['highest']['value'] ?? null),
            'highest_market_value_date' => self::dateOnly($market['highest']['determined'] ?? null),
            'portrait_url' => self::httpsUrl($data['portraitUrl'] ?? null),
            'payload' => $rawPayload,
            'updated_at' => $now,
        ];
    }

    /** clubs row from a clubs?ids[]= / club/{id} entry. */
    public static function clubMetaRow(array $club, string $slug, string $now): ?array
    {
        $id = trim((string) ($club['id'] ?? ''));
        $name = trim((string) ($club['name'] ?? ''));
        if ($id === '' || $name === '') {
            return null;
        }
        $base = (array) ($club['baseDetails'] ?? []);
        $colors = (array) ($base['superiorClub']['colors'] ?? $club['colors'] ?? []);
        $logo = self::httpsUrl($club['crestUrl'] ?? null);
        if ($logo === null) {
            foreach ((array) ($club['historical']['images'] ?? []) as $image) {
                $logo = self::httpsUrl($image['url'] ?? null);
                if ($logo !== null) {
                    break;
                }
            }
        }
        return [
            'id' => $id,
            'name' => mb_substr($name, 0, 190),
            'short_name' => self::nullableString($base['shortName'] ?? null),
            'slug' => $slug,
            'country_id' => self::nullableInt($base['countryId'] ?? null),
            'is_national_team' => !empty($base['isNationalTeam']) ? 1 : 0,
            'logo_url' => $logo,
            'color1' => self::color($colors['firstColor'] ?? null),
            'color2' => self::color($colors['secondColor'] ?? null),
            'updated_at' => $now,
        ];
    }

    /** competitions row from a competitions?ids[]= entry. */
    public static function competitionMetaRow(array $competition, string $now): ?array
    {
        $id = trim((string) ($competition['id'] ?? ''));
        $name = trim((string) ($competition['name'] ?? ''));
        if ($id === '' || $name === '') {
            return null;
        }
        $logo = self::httpsUrl($competition['logoUrl'] ?? null);
        if ($logo === null) {
            foreach ((array) ($competition['historical']['images'] ?? []) as $image) {
                $logo = self::httpsUrl($image['url'] ?? null);
                if ($logo !== null) {
                    break;
                }
            }
        }
        return [
            'id' => $id,
            'name' => mb_substr($name, 0, 190),
            'short_name' => self::nullableString($competition['shortName'] ?? ($competition['baseDetails']['shortName'] ?? null)),
            'logo_url' => $logo,
            'type_id' => self::nullableInt($competition['typeId'] ?? null),
            'country_id' => self::nullableInt($competition['originDetails']['countryId'] ?? null),
            'updated_at' => $now,
        ];
    }

    /** '2003-08-12T00:00:00+02:00' => '2003-08-12' (calendar date as published, no timezone shift). */
    public static function dateOnly(mixed $value): ?string
    {
        if (!is_string($value) || preg_match('/^(\d{4}-\d{2}-\d{2})/', trim($value), $m) !== 1 || str_starts_with($m[1], '0000')) {
            return null;
        }
        return $m[1];
    }

    private static function position(mixed $name): ?string
    {
        $name = self::nullableString($name);
        if ($name === null) {
            return null;
        }
        return self::POSITIONS_TR[strtolower($name)] ?? $name;
    }

    private static function sentenceCase(string $text): string
    {
        return Str::upper(mb_substr($text, 0, 1)) . mb_substr($text, 1);
    }

    private static function color(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^#[0-9a-f]{3,8}$/i', trim($value)) === 1 ? strtoupper(trim($value)) : null;
    }

    private static function httpsUrl(mixed $value): ?string
    {
        if (!is_string($value) || preg_match('#^https?://#i', trim($value)) !== 1) {
            return null;
        }
        $url = (string) preg_replace('#^http://#i', 'https://', trim($value));
        return strlen($url) <= 500 ? $url : null;
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private static function nullableString(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);
        return $value === '' || $value === '0' ? null : $value;
    }
}
