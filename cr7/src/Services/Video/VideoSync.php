<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Core\Config;
use App\Core\Database;
use App\Core\Settings;
use App\Support\Cache;
use App\Support\Date;
use App\Support\Log;

/**
 * Periodic import (cron task 'videos-sync'):
 *  1. Atom feeds of the channels below (merged with config/setting `youtube.channels`);
 *     channels with filter=true only contribute videos whose title (or the first paragraph
 *     of the description) mentions Cristiano Ronaldo / CR7.
 *  2. With a Data API key: search.list for `youtube.search_query` (last 14 days) and
 *     videos.list for duration, views and embeddability of every new video.
 *  3. Without a key every new video is checked via oEmbed; videos that cannot be embedded
 *     are skipped (and remembered for a week so they are not re-checked every run).
 * Existing rows only get their view count / missing duration refreshed — titles and
 * categories edited in the admin panel are never overwritten.
 */
final class VideoSync
{
    /** Official channels verified to serve a feed (2026-10). 'handle' maps oEmbed author URLs to ids. */
    public const DEFAULT_CHANNELS = [
        ['id' => 'UCtxD0x6AuNNqdXO9Wp5GHew', 'name' => 'UR · Cristiano', 'handle' => '@cristiano', 'filter' => false],
        ['id' => 'UCHEQtltsiDd3p8ga-5nC-ow', 'name' => 'Al Nassr FC', 'handle' => '@AlNassrSaudi', 'filter' => true],
        ['id' => 'UCsIoK3XP-cVcpoCC_SdjZdg', 'name' => 'Federação Portuguesa de Futebol', 'handle' => '@FPF.Oficial', 'filter' => true],
        ['id' => 'UCWV3obpZVGgJ3j9FVhEjF2Q', 'name' => 'Real Madrid', 'handle' => '@realmadrid', 'filter' => true],
        ['id' => 'UC6yW44UGJJBvYTlfC7CRg2Q', 'name' => 'Manchester United', 'handle' => '@manutd', 'filter' => true],
        ['id' => 'UCLzKhsxrExAC6yAdtZ-BOWw', 'name' => 'Juventus', 'handle' => '@Juventus', 'filter' => true],
        ['id' => 'UCnJj6L93JX3Jrhzv81ayywA', 'name' => 'Sporting CP', 'handle' => '@SportingCP', 'filter' => true],
        ['id' => 'UCyGa1YEx9ST66rYrJTGIKOw', 'name' => 'UEFA', 'handle' => '@UEFA', 'filter' => true],
        ['id' => 'UCpcTrCXblq78GZrTUTLWeBw', 'name' => 'FIFA', 'handle' => '@fifa', 'filter' => true],
    ];

    /** Upper bound of new videos per run (a first run on a fresh install stays small). */
    public const MAX_NEW_PER_RUN = 60;

    private const REJECTED_CACHE = 'videos.rejected';
    private const REJECTED_TTL = 7 * 86400;

    private YouTubeFeed $feed;
    private YouTubeApi $api;
    private VideoImporter $importer;

    public function __construct(?YouTubeFeed $feed = null, ?YouTubeApi $api = null, ?VideoImporter $importer = null)
    {
        $this->feed = $feed ?? new YouTubeFeed();
        $this->api = $api ?? new YouTubeApi();
        $this->importer = $importer ?? new VideoImporter($this->api);
    }

    /**
     * Channel list: defaults + `youtube.channels` (setting, else config). Entries with the same
     * id override defaults; 'enabled' => false removes a channel.
     *
     * @return list<array{id: string, name: string, handle: string, filter: bool}>
     */
    public static function channels(): array
    {
        $byId = [];
        foreach (self::DEFAULT_CHANNELS as $channel) {
            $byId[$channel['id']] = $channel;
        }
        $configured = Settings::get('youtube.channels', Config::get('youtube.channels', []));
        foreach (is_array($configured) ? $configured : [] as $channel) {
            $id = is_array($channel) ? trim((string) ($channel['id'] ?? '')) : '';
            if (!YouTubeFeed::isChannelId($id)) {
                continue;
            }
            if (array_key_exists('enabled', $channel) && !$channel['enabled']) {
                unset($byId[$id]);
                continue;
            }
            $byId[$id] = [
                'id' => $id,
                'name' => trim((string) ($channel['name'] ?? ($byId[$id]['name'] ?? $id))),
                'handle' => trim((string) ($channel['handle'] ?? ($byId[$id]['handle'] ?? ''))),
                'filter' => array_key_exists('filter', $channel) ? (bool) $channel['filter'] : (bool) ($byId[$id]['filter'] ?? true),
            ];
        }
        return array_values(array_map(static fn (array $c): array => [
            'id' => $c['id'], 'name' => $c['name'], 'handle' => (string) ($c['handle'] ?? ''), 'filter' => (bool) $c['filter'],
        ], $byId));
    }

    /** Channel id for an oEmbed author (handle URL 'https://www.youtube.com/@manutd' or exact name), or null. */
    public static function channelIdFor(?string $channelTitle, ?string $authorUrl = null): ?string
    {
        if ($authorUrl !== null && preg_match('#youtube\.com/channel/(UC[A-Za-z0-9_-]{22})#', $authorUrl, $m) === 1) {
            return $m[1];
        }
        $handle = $authorUrl !== null && preg_match('#youtube\.com/(@[^/?\#]+)#', $authorUrl, $m) === 1 ? strtolower(rawurldecode($m[1])) : '';
        $name = mb_strtolower(trim((string) $channelTitle), 'UTF-8');
        foreach (self::channels() as $channel) {
            if (($handle !== '' && strtolower($channel['handle']) === $handle) || ($name !== '' && mb_strtolower($channel['name'], 'UTF-8') === $name)) {
                return $channel['id'];
            }
        }
        return null;
    }

    /**
     * @return array{channels: int, fetched: int, new: int, updated: int, skipped: int, errors: list<string>, new_ids: list<int>}
     */
    public function run(int $maxNew = self::MAX_NEW_PER_RUN): array
    {
        $stats = ['channels' => 0, 'fetched' => 0, 'new' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => [], 'new_ids' => []];
        $candidates = [];
        $failedChannels = 0;

        foreach (self::channels() as $channel) {
            $stats['channels']++;
            $items = $this->feed->fetch($channel['id']);
            if ($items === [] && $this->feed->lastError() !== null) {
                $failedChannels++;
                $stats['errors'][] = $channel['name'] . ': ' . $this->feed->lastError();
                continue;
            }
            foreach ($items as $video) {
                $stats['fetched']++;
                $video['channel_id'] ??= $channel['id'];
                // Known channels get their configured (Latin-script) name: "نادي النصر السعودي - AlNassr FC" => "Al Nassr FC".
                $video['channel_title'] = $video['channel_id'] === $channel['id'] || ($video['channel_title'] ?? '') === ''
                    ? $channel['name']
                    : $video['channel_title'];
                if ($channel['filter'] && !Categorizer::mentionsRonaldo((string) $video['title'], (string) $video['description'])) {
                    $stats['skipped']++;
                    continue;
                }
                $candidates[$video['youtube_id']] ??= $video;
            }
        }

        if ($this->api->enabled()) {
            $query = trim((string) Settings::get('youtube.search_query', Config::get('youtube.search_query', 'Cristiano Ronaldo')));
            $ids = $this->api->search($query !== '' ? $query : 'Cristiano Ronaldo', 25, Date::ago(14 * 86400));
            if ($this->api->lastError() !== null) {
                $stats['errors'][] = $this->api->lastError();
            }
            foreach ($ids as $id) {
                $candidates[$id] ??= ['youtube_id' => $id, 'from_search' => true];
            }
        }

        if ($candidates === []) {
            if ($failedChannels > 0 && $failedChannels === $stats['channels']) {
                throw new \RuntimeException('Hiçbir YouTube kanal akışı alınamadı: ' . implode(' | ', array_slice($stats['errors'], 0, 3)));
            }
            return $stats;
        }

        $existing = $this->existing(array_map('strval', array_keys($candidates)));
        $new = array_diff_key($candidates, $existing);
        $details = [];
        $useApi = false;
        if ($this->api->enabled()) {
            $details = $this->api->videos(array_map('strval', array_keys($candidates)));
            $useApi = $this->api->lastError() === null;
            if (!$useApi) {
                $stats['errors'][] = (string) $this->api->lastError(); // quota/key problem => oEmbed checks below
            }
        }

        foreach ($existing as $youtubeId => $row) {
            $fresh = $details[$youtubeId] ?? $candidates[$youtubeId];
            $changes = [];
            if (isset($fresh['view_count']) && $fresh['view_count'] !== null && (int) $fresh['view_count'] !== (int) $row['view_count']) {
                $changes['view_count'] = (int) $fresh['view_count'];
            }
            if ($row['duration_seconds'] === null && !empty($fresh['duration_seconds'])) {
                $changes['duration_seconds'] = (int) $fresh['duration_seconds'];
            }
            if ($changes !== []) {
                $changes['updated_at'] = Date::now();
                Database::update('videos', $changes, 'id = ?', [(int) $row['id']]);
                $stats['updated']++;
            }
        }

        $rejected = $this->rejected();
        $rejectedChanged = false;
        foreach ($new as $youtubeId => $video) {
            if ($stats['new'] >= $maxNew) {
                break;
            }
            $youtubeId = (string) $youtubeId;
            if (isset($rejected[$youtubeId])) {
                $stats['skipped']++;
                continue;
            }
            $fromSearch = !empty($video['from_search']);
            unset($video['from_search']);
            if ($fromSearch && !$useApi) {
                continue; // search hits carry no metadata without a working API
            }
            if ($useApi) {
                if (!isset($details[$youtubeId])) { // private, not embeddable, live/upcoming
                    $stats['skipped']++;
                    $rejected[$youtubeId] = time();
                    $rejectedChanged = true;
                    continue;
                }
                $video = array_merge($video, $details[$youtubeId], ['source' => $fromSearch ? 'api' : 'rss']);
            }
            if (!self::acceptable($video, $fromSearch)) {
                $stats['skipped']++;
                continue;
            }
            if (!$useApi && $this->importer->oembed($youtubeId) === null) {
                $stats['skipped']++;
                if ($this->importer->lastLookupWasFinal()) { // not embeddable / gone: do not re-check for a week
                    $rejected[$youtubeId] = time();
                    $rejectedChanged = true;
                }
                continue;
            }
            try {
                $stats['new_ids'][] = $this->importer->save($video);
                $stats['new']++;
            } catch (\Throwable $e) {
                $stats['errors'][] = $youtubeId . ': ' . $e->getMessage();
                Log::error('videos', 'Video insert failed', ['youtube_id' => $youtubeId, 'exception' => $e]);
            }
        }
        if ($rejectedChanged) {
            Cache::set(self::REJECTED_CACHE, $rejected, self::REJECTED_TTL);
        }

        if ($stats['new_ids'] !== []) {
            VideoImporter::pingIndexNow($stats['new_ids']);
        }
        Log::info('videos', 'Video sync finished', array_diff_key($stats, ['new_ids' => true]));
        return $stats;
    }

    /**
     * Final content checks for a new video: a title with Latin-script words (Arabic-only
     * uploads are unreadable for a Turkish audience) and, for API search hits, a Ronaldo mention.
     */
    private static function acceptable(array $video, bool $fromSearch): bool
    {
        $title = (string) ($video['title'] ?? '');
        if ($title === '' || !VideoText::hasLatinText($title)) {
            return false;
        }
        return !$fromSearch || Categorizer::mentionsRonaldo($title, (string) ($video['description'] ?? ''));
    }

    /** One-line Turkish summary for cron logs / admin. */
    public static function summary(array $stats): string
    {
        $line = sprintf(
            '%d kanal tarandı, %d video incelendi: %d yeni, %d güncellendi, %d atlandı.',
            $stats['channels'],
            $stats['fetched'],
            $stats['new'],
            $stats['updated'],
            $stats['skipped']
        );
        if ($stats['errors'] !== []) {
            $line .= ' Hatalar (' . count($stats['errors']) . '): ' . mb_substr(implode(' | ', $stats['errors']), 0, 400);
        }
        return $line;
    }

    /**
     * @param list<string> $ids
     * @return array<string, array{id: int, view_count: ?int, duration_seconds: ?int}>
     */
    private function existing(array $ids): array
    {
        $out = [];
        foreach (array_chunk($ids, 200) as $chunk) {
            $rows = Database::fetchAll(
                'SELECT id, youtube_id, view_count, duration_seconds FROM videos WHERE youtube_id IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ')',
                array_values($chunk)
            );
            foreach ($rows as $row) {
                $out[(string) $row['youtube_id']] = [
                    'id' => (int) $row['id'],
                    'view_count' => $row['view_count'] !== null ? (int) $row['view_count'] : null,
                    'duration_seconds' => $row['duration_seconds'] !== null ? (int) $row['duration_seconds'] : null,
                ];
            }
        }
        return $out;
    }

    /** @return array<string, int> youtube_id => time rejected (not embeddable / unavailable) */
    private function rejected(): array
    {
        try {
            $value = Cache::get(self::REJECTED_CACHE);
        } catch (\Throwable) {
            $value = null;
        }
        if (!is_array($value)) {
            return [];
        }
        $cutoff = time() - self::REJECTED_TTL;
        return array_filter($value, static fn ($t): bool => is_int($t) && $t >= $cutoff);
    }
}
