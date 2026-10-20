<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Core\Config;
use App\Core\Settings;
use App\Support\Date;
use App\Support\Http;
use App\Support\Log;

/**
 * Optional YouTube Data API v3 client (only used when the admin setting / config
 * `youtube.api_key` is set). search.list costs 100 quota units, videos.list 1 unit per
 * 50 ids; the default daily quota (10 000) easily covers a 3-hour sync.
 */
final class YouTubeApi
{
    public const BASE = 'https://www.googleapis.com/youtube/v3/';

    private string $apiKey;
    /** @var callable(string): array{status: int, body: string, error: ?string} */
    private $http;
    private ?string $lastError = null;

    /** @param callable(string): array|null $http fetcher returning Http::get()-shaped arrays */
    public function __construct(?string $apiKey = null, ?callable $http = null)
    {
        $this->apiKey = trim((string) ($apiKey ?? Settings::get('youtube.api_key', Config::get('youtube.api_key', ''))));
        $this->http = $http ?? static fn (string $url): array => Http::get($url, ['Accept' => 'application/json'], 20);
    }

    public function enabled(): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{20,80}$/', $this->apiKey) === 1;
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /** contentDetails.duration ('PT1H2M3S') => seconds, or null. */
    public static function parseDuration(?string $iso): ?int
    {
        return VideoText::parseIsoDuration($iso);
    }

    /**
     * search.list: video ids for a query (Turkish relevance, Türkiye region, embeddable only).
     *
     * @return list<string>
     */
    public function search(string $query, int $max = 25, ?string $publishedAfterUtc = null): array
    {
        if (!$this->enabled() || trim($query) === '') {
            return [];
        }
        $params = [
            'part' => 'snippet',
            'type' => 'video',
            'q' => $query,
            'maxResults' => max(1, min(50, $max)),
            'order' => 'date',
            'relevanceLanguage' => 'tr',
            'regionCode' => 'TR',
            'videoEmbeddable' => 'true',
            'safeSearch' => 'moderate',
        ];
        if ($publishedAfterUtc !== null) {
            $params['publishedAfter'] = gmdate('Y-m-d\TH:i:s\Z', strtotime($publishedAfterUtc . ' UTC') ?: time());
        }
        $json = $this->request('search', $params);
        $ids = [];
        foreach ((array) ($json['items'] ?? []) as $item) {
            $id = $item['id']['videoId'] ?? null;
            if (is_string($id) && preg_match(VideoText::ID_PATTERN, $id) === 1) {
                $ids[] = $id;
            }
        }
        return array_values(array_unique($ids));
    }

    /**
     * videos.list (snippet, contentDetails, statistics, status) in batches of 50.
     *
     * @param list<string> $ids
     * @return array<string, array<string, mixed>> youtube_id => normalised video (see YouTubeFeed)
     */
    public function videos(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn ($id): bool => is_string($id) && preg_match(VideoText::ID_PATTERN, $id) === 1)));
        if (!$this->enabled() || $ids === []) {
            return [];
        }
        $out = [];
        foreach (array_chunk($ids, 50) as $chunk) {
            $json = $this->request('videos', ['part' => 'snippet,contentDetails,statistics,status', 'id' => implode(',', $chunk), 'maxResults' => 50]);
            foreach ((array) ($json['items'] ?? []) as $item) {
                $video = self::normalize((array) $item);
                if ($video !== null) {
                    $out[$video['youtube_id']] = $video;
                }
            }
        }
        return $out;
    }

    /**
     * One videos.list item => normalised video, or null when it cannot be shown on the
     * site (private, not embeddable, upcoming/live).
     */
    public static function normalize(array $item): ?array
    {
        $id = $item['id'] ?? null;
        if (!is_string($id) || preg_match(VideoText::ID_PATTERN, $id) !== 1) {
            return null;
        }
        $snippet = (array) ($item['snippet'] ?? []);
        $status = (array) ($item['status'] ?? []);
        if (($status['privacyStatus'] ?? 'public') !== 'public' && ($status['privacyStatus'] ?? '') !== 'unlisted') {
            return null;
        }
        if (isset($status['embeddable']) && $status['embeddable'] === false) {
            return null;
        }
        if (in_array($snippet['liveBroadcastContent'] ?? 'none', ['live', 'upcoming'], true)) {
            return null;
        }
        $thumbs = (array) ($snippet['thumbnails'] ?? []);
        $thumb = VideoText::thumbnail($id, isset($thumbs['high']) ? 'hqdefault' : 'mqdefault');
        try {
            $published = isset($snippet['publishedAt']) ? Date::toUtc((string) $snippet['publishedAt']) : null;
        } catch (\InvalidArgumentException) {
            $published = null;
        }
        $duration = self::parseDuration(isset($item['contentDetails']['duration']) ? (string) $item['contentDetails']['duration'] : null);
        $views = $item['statistics']['viewCount'] ?? null;
        return [
            'youtube_id' => $id,
            'title' => VideoText::cleanTitle((string) ($snippet['title'] ?? '')),
            'description' => VideoText::cleanDescription((string) ($snippet['description'] ?? '')),
            'channel_id' => isset($snippet['channelId']) ? (string) $snippet['channelId'] : null,
            'channel_title' => isset($snippet['channelTitle']) ? (string) $snippet['channelTitle'] : null,
            'thumbnail_url' => $thumb,
            'published_at' => $published,
            'duration_seconds' => $duration,
            'view_count' => is_numeric($views) ? (int) $views : null,
            'is_short' => Categorizer::isShort($duration),
            'source' => 'api',
        ];
    }

    private function request(string $endpoint, array $params): array
    {
        $this->lastError = null;
        $params['key'] = $this->apiKey;
        $response = ($this->http)(self::BASE . $endpoint . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986));
        $json = json_decode((string) ($response['body'] ?? ''), true);
        if (($response['error'] ?? null) !== null || (int) ($response['status'] ?? 0) !== 200 || !is_array($json)) {
            $reason = is_array($json) ? (string) ($json['error']['message'] ?? '') : (string) ($response['error'] ?? '');
            $this->lastError = sprintf('YouTube API %s hatası: HTTP %d %s', $endpoint, (int) ($response['status'] ?? 0), mb_substr(strip_tags($reason), 0, 200));
            Log::error('videos', 'YouTube API request failed', ['endpoint' => $endpoint, 'status' => $response['status'] ?? 0, 'reason' => mb_substr($reason, 0, 300)]);
            return [];
        }
        return $json;
    }
}
