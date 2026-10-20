<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Repositories\VideoRepository;
use App\Support\Date;
use App\Support\Http;
use App\Support\Log;

/**
 * Adds YouTube videos to the `videos` table.
 *
 * importByUrl() (spec §9b, used by the admin "Video ekle" form) accepts any YouTube URL or a
 * bare id. Metadata comes from the Data API when a key is configured, otherwise from the
 * public oEmbed endpoint (title, channel name, thumbnail; oEmbed answers 401 for videos
 * that cannot be embedded and 400/404 for missing ones — both are rejected).
 */
final class VideoImporter
{
    public const OEMBED = 'https://www.youtube.com/oembed?format=json&url=';

    private YouTubeApi $api;
    private VideoRepository $videos;
    /** @var callable(string): array{status: int, body: string, error: ?string} */
    private $http;
    private ?string $lastError = null;
    private int $lastStatus = 0;

    /** @param callable(string): array|null $http fetcher for oEmbed (tests inject fakes) */
    public function __construct(?YouTubeApi $api = null, ?callable $http = null, ?VideoRepository $videos = null)
    {
        $this->api = $api ?? new YouTubeApi();
        $this->http = $http ?? static fn (string $url): array => Http::get($url, ['Accept' => 'application/json'], 15);
        $this->videos = $videos ?? new VideoRepository();
    }

    /**
     * YouTube URL/ID => videos row id, or null (invalid URL, unavailable video; see lastError()).
     * An already imported video is not duplicated: its id is returned and, when given,
     * its category / featured flag are updated.
     */
    public function importByUrl(string $url, ?string $category = null, bool $featured = false): ?int
    {
        $this->lastError = null;
        $youtubeId = VideoText::parseId($url);
        if ($youtubeId === null) {
            $this->lastError = 'Geçerli bir YouTube bağlantısı ya da video kimliği girin.';
            return null;
        }
        $existing = $this->videos->findByYoutubeId($youtubeId);
        if ($existing !== null) {
            $changes = [];
            if ($category !== null && $category !== '' && $category !== $existing['category']) {
                $changes['category'] = Categorizer::normalize($category);
            }
            if ($featured && (int) $existing['is_featured'] !== 1) {
                $changes['is_featured'] = 1;
            }
            if ($changes !== []) {
                $this->videos->update($existing['id'], $changes);
            }
            return $existing['id'];
        }
        $video = $this->fetchMetadata($youtubeId);
        if ($video === null) {
            return null;
        }
        $video['source'] = 'manual';
        $id = $this->save($video, $category, $featured);
        self::pingIndexNow([$id]);
        return $id;
    }

    /**
     * Tell IndexNow (Bing/Yandex) about new video pages + the gallery. Never throws; a no-op
     * without an IndexNow key, on local hosts or when the news module is missing.
     *
     * @param list<int> $ids
     */
    public static function pingIndexNow(array $ids): void
    {
        if ($ids === [] || !class_exists(\App\Services\News\IndexNow::class)) {
            return;
        }
        try {
            if (\App\Services\News\IndexNow::key() === '') {
                return;
            }
            $repo = new VideoRepository();
            $urls = [];
            foreach ($ids as $id) {
                $row = $repo->find((int) $id);
                if ($row !== null && $row['status'] === VideoRepository::STATUS_PUBLISHED) {
                    $urls[] = url($row['url']);
                }
            }
            if ($urls !== []) {
                $urls[] = url('/videolar');
                \App\Services\News\IndexNow::ping($urls);
            }
        } catch (\Throwable $e) {
            Log::error('videos', 'IndexNow ping failed', ['exception' => $e]);
        }
    }

    /** Metadata of one video (API, else oEmbed) in the normalised shape, or null. */
    public function fetchMetadata(string $youtubeId): ?array
    {
        if ($this->api->enabled()) {
            $found = $this->api->videos([$youtubeId]);
            if (isset($found[$youtubeId])) {
                return $found[$youtubeId];
            }
            if ($this->api->lastError() === null) {
                $this->lastError = 'Video bulunamadı, gizli ya da sitelere yerleştirilmesine izin verilmiyor.';
                return null;
            }
            // API failed (quota, key) => fall back to oEmbed below.
        }
        return $this->oembed($youtubeId);
    }

    /** oEmbed lookup (no key): title, channel name, thumbnail. */
    public function oembed(string $youtubeId): ?array
    {
        $response = ($this->http)(self::OEMBED . rawurlencode(VideoText::watchUrl($youtubeId)));
        $status = (int) ($response['status'] ?? 0);
        $this->lastStatus = $status;
        if ($status === 401 || $status === 403) {
            $this->lastError = 'Bu videonun başka sitelere yerleştirilmesine izin verilmiyor.';
            return null;
        }
        if ($status === 400 || $status === 404) {
            $this->lastError = 'Video bulunamadı (silinmiş ya da gizli olabilir).';
            return null;
        }
        $json = json_decode((string) ($response['body'] ?? ''), true);
        if (($response['error'] ?? null) !== null || $status !== 200 || !is_array($json) || !isset($json['title'])) {
            $this->lastError = 'YouTube bilgileri alınamadı, lütfen daha sonra tekrar deneyin.';
            Log::error('videos', 'oEmbed lookup failed', ['id' => $youtubeId, 'status' => $status, 'error' => $response['error'] ?? null]);
            return null;
        }
        $channelTitle = isset($json['author_name']) ? trim((string) $json['author_name']) : null;
        return [
            'youtube_id' => $youtubeId,
            'title' => VideoText::cleanTitle((string) $json['title']),
            'description' => '',
            'channel_id' => VideoSync::channelIdFor($channelTitle, isset($json['author_url']) ? (string) $json['author_url'] : null),
            'channel_title' => $channelTitle !== '' ? $channelTitle : null,
            'thumbnail_url' => VideoText::thumbnail($youtubeId),
            'published_at' => null,
            'duration_seconds' => null,
            'view_count' => null,
            'is_short' => false,
            'source' => 'manual',
        ];
    }

    /**
     * Insert a normalised video (or return the id of an existing one). Category: given
     * one if valid, else Categorizer. Returns the row id.
     */
    public function save(array $video, ?string $category = null, ?bool $featured = null): int
    {
        $existing = $this->videos->findByYoutubeId((string) $video['youtube_id']);
        if ($existing !== null) {
            return $existing['id'];
        }
        $category = ($category !== null && $category !== '')
            ? Categorizer::normalize($category)
            : Categorizer::categorize((string) $video['title'], (string) ($video['description'] ?? ''), $video['channel_id'] ?? null, $video['channel_title'] ?? null);
        $now = Date::now();
        $id = $this->videos->create([
            'youtube_id' => (string) $video['youtube_id'],
            'title' => (string) $video['title'],
            'description' => ($video['description'] ?? '') !== '' ? (string) $video['description'] : null,
            'channel_id' => $video['channel_id'] ?? null,
            'channel_title' => $video['channel_title'] ?? null,
            'category' => $category,
            'thumbnail_url' => $video['thumbnail_url'] ?? VideoText::thumbnail((string) $video['youtube_id']),
            'duration_seconds' => $video['duration_seconds'] ?? null,
            'view_count' => $video['view_count'] ?? null,
            'published_at' => $video['published_at'] ?? null,
            'source' => (string) ($video['source'] ?? 'manual'),
            'is_featured' => $featured ? 1 : 0,
            'status' => VideoRepository::STATUS_PUBLISHED,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Log::info('videos', 'Video imported', ['id' => $id, 'youtube_id' => $video['youtube_id'], 'source' => $video['source'] ?? 'manual', 'category' => $category]);
        return $id;
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /** HTTP status of the last oEmbed lookup (0 = network error). */
    public function lastStatus(): int
    {
        return $this->lastStatus;
    }

    /** True when the last oEmbed answer means "never embeddable here" (missing, private or embedding disabled). */
    public function lastLookupWasFinal(): bool
    {
        return in_array($this->lastStatus, [400, 401, 403, 404], true);
    }
}
