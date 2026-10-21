<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Support\Date;
use App\Support\Http;

/**
 * Channel uploads via the public Atom feed (no API key, latest 15 videos):
 * https://www.youtube.com/feeds/videos.xml?channel_id=UC…
 *
 * Normalised video shape (shared with YouTubeApi / VideoImporter):
 * ['youtube_id','title','description','channel_id','channel_title','thumbnail_url',
 *  'published_at' (UTC 'Y-m-d H:i:s'|null),'duration_seconds' (?int),'view_count' (?int),
 *  'is_short' (bool),'source' ('rss'|'api'|'manual')]
 */
final class YouTubeFeed
{
    public const URL = 'https://www.youtube.com/feeds/videos.xml?channel_id=';

    private const NS_ATOM = 'http://www.w3.org/2005/Atom';
    private const NS_YT = 'http://www.youtube.com/xml/schemas/2015';
    private const NS_MEDIA = 'http://search.yahoo.com/mrss/';

    /** @var callable(string): array{status: int, body: string, error: ?string} */
    private $http;
    private ?string $lastError = null;

    /** @param callable(string): array|null $http fetcher returning Http::get()-shaped arrays (tests inject fakes) */
    public function __construct(?callable $http = null)
    {
        $this->http = $http ?? static fn (string $url): array => Http::get($url, ['Accept' => 'application/atom+xml, application/xml;q=0.9, */*;q=0.5'], 20);
    }

    public static function isChannelId(string $id): bool
    {
        return preg_match('/^UC[A-Za-z0-9_-]{22}$/', $id) === 1;
    }

    /**
     * Latest uploads of a channel; [] on failure (see lastError()).
     *
     * @return list<array<string, mixed>>
     */
    public function fetch(string $channelId): array
    {
        $this->lastError = null;
        if (!self::isChannelId($channelId)) {
            $this->lastError = "Geçersiz kanal kimliği: {$channelId}";
            return [];
        }
        $response = ($this->http)(self::URL . $channelId);
        if (($response['error'] ?? null) !== null || (int) ($response['status'] ?? 0) !== 200) {
            $this->lastError = sprintf('Kanal akışı alınamadı (%s): HTTP %d %s', $channelId, (int) ($response['status'] ?? 0), (string) ($response['error'] ?? ''));
            return [];
        }
        $videos = self::parse((string) ($response['body'] ?? ''));
        if ($videos === [] && !str_contains((string) ($response['body'] ?? ''), '<feed')) {
            $this->lastError = "Kanal akışı okunamadı ({$channelId}).";
        }
        return $videos;
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Parse feed XML (pure; no network, no external entities).
     *
     * @return list<array<string, mixed>>
     */
    public static function parse(string $xml): array
    {
        $xml = trim($xml);
        if ($xml === '' || stripos($xml, '<!DOCTYPE') !== false) {
            return []; // YouTube feeds never carry a DTD; refuse entity tricks outright
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $doc = new \DOMDocument();
            if (!$doc->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_COMPACT)) {
                return [];
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('a', self::NS_ATOM);
        $xpath->registerNamespace('yt', self::NS_YT);
        $xpath->registerNamespace('media', self::NS_MEDIA);

        $feedTitle = self::text($xpath, '/a:feed/a:author/a:name') ?: self::text($xpath, '/a:feed/a:title');
        $feedChannel = self::text($xpath, '/a:feed/yt:channelId');
        $videos = [];
        foreach ($xpath->query('/a:feed/a:entry') ?: [] as $entry) {
            $id = self::text($xpath, 'yt:videoId', $entry);
            if (preg_match(VideoText::ID_PATTERN, $id) !== 1) {
                continue;
            }
            $channelId = self::text($xpath, 'yt:channelId', $entry) ?: $feedChannel;
            if ($channelId !== '' && !str_starts_with($channelId, 'UC')) {
                $channelId = 'UC' . $channelId; // feed-level <yt:channelId> omits the prefix
            }
            $link = '';
            foreach ($xpath->query('a:link[@rel="alternate"]', $entry) ?: [] as $node) {
                $link = $node instanceof \DOMElement ? $node->getAttribute('href') : '';
            }
            $title = self::text($xpath, 'media:group/media:title', $entry) ?: self::text($xpath, 'a:title', $entry);
            $views = self::attr($xpath, 'media:group/media:community/media:statistics', 'views', $entry);
            $thumb = self::attr($xpath, 'media:group/media:thumbnail', 'url', $entry);
            $published = self::text($xpath, 'a:published', $entry);
            try {
                $publishedAt = $published !== '' ? Date::toUtc($published) : null;
            } catch (\InvalidArgumentException) {
                $publishedAt = null;
            }
            $videos[] = [
                'youtube_id' => $id,
                'title' => VideoText::cleanTitle($title),
                'description' => VideoText::cleanDescription(self::text($xpath, 'media:group/media:description', $entry)),
                'channel_id' => $channelId !== '' ? $channelId : null,
                'channel_title' => (self::text($xpath, 'a:author/a:name', $entry) ?: $feedTitle) ?: null,
                'thumbnail_url' => self::thumbnail($id, $thumb),
                'published_at' => $publishedAt,
                'duration_seconds' => null,
                'view_count' => is_numeric($views) ? (int) $views : null,
                'is_short' => Categorizer::isShort(null, $link),
                'source' => 'rss',
            ];
        }
        return $videos;
    }

    /** Feed thumbnails come from i1–i4.ytimg.com; store the canonical host. */
    private static function thumbnail(string $id, string $url): string
    {
        if ($url !== '' && preg_match('#^https://i\d?\.ytimg\.com/vi/[A-Za-z0-9_-]{11}/(\w+)\.jpg$#', $url, $m) === 1) {
            return VideoText::thumbnail($id, $m[1]);
        }
        return VideoText::thumbnail($id);
    }

    private static function text(\DOMXPath $xpath, string $query, ?\DOMNode $context = null): string
    {
        $nodes = $context === null ? $xpath->query($query) : $xpath->query($query, $context);
        $node = $nodes !== false ? $nodes->item(0) : null;
        return $node !== null ? trim((string) $node->textContent) : '';
    }

    private static function attr(\DOMXPath $xpath, string $query, string $attribute, \DOMNode $context): string
    {
        $nodes = $xpath->query($query, $context);
        $node = $nodes !== false ? $nodes->item(0) : null;
        return $node instanceof \DOMElement ? trim($node->getAttribute($attribute)) : '';
    }
}
