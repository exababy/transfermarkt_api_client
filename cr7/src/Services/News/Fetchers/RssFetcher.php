<?php

declare(strict_types=1);

namespace App\Services\News\Fetchers;

use App\Services\News\FeedParser;
use App\Support\Http;

/**
 * Generic RSS 2.0 / Atom source (Turkish sports feeds). Subclasses adapt links/titles.
 */
class RssFetcher implements Fetcher
{
    public function fetch(string $key, array $source): array
    {
        $response = Http::getPublic($source['url'], [ // feed hosts may redirect anywhere: public hosts only
            'Accept' => 'application/rss+xml, application/atom+xml, application/xml;q=0.9, text/xml;q=0.8, */*;q=0.5',
            'Accept-Language' => 'tr-TR,tr;q=0.9',
        ], 25);
        if ($response['error'] !== null || $response['status'] >= 400 || $response['body'] === '') {
            return ['items' => [], 'error' => $response['error'] ?? ('HTTP ' . $response['status']), 'status' => $response['status']];
        }
        return [
            'items' => $this->normalizeAll($key, $source, $response['body'], $response['headers']['content-type'] ?? null),
            'error' => null,
            'status' => $response['status'],
        ];
    }

    /** Parse a feed body into normalised items (public for tests/fixtures). */
    public function normalizeAll(string $key, array $source, string $body, ?string $contentType = null): array
    {
        $items = [];
        foreach (FeedParser::parse($body, $contentType) as $raw) {
            $item = $this->normalize($key, $source, $raw);
            if ($item !== null) {
                $items[] = $item;
            }
        }
        return $items;
    }

    /** @param array<string, mixed> $raw FeedParser item */
    protected function normalize(string $key, array $source, array $raw): ?array
    {
        $url = self::cleanUrl((string) $raw['link']);
        if ($url === null) {
            return null;
        }
        return [
            'source' => $key,
            'source_name' => $source['name'],
            'url' => $url,
            'title' => (string) $raw['title'],
            'description' => self::trimTruncated((string) $raw['description']),
            'content' => (string) $raw['content'],
            'image_url' => self::cleanUrl((string) ($raw['image'] ?? ''), $url),
            'published_at' => $raw['published_at'],
            'related_titles' => [],
        ];
    }

    /** "… derbi maç saat 21.30'da oy..." => "… derbi maç saat 21.30'da…" (drops a word cut by the feed). */
    public static function trimTruncated(string $text): string
    {
        $text = trim($text);
        // Only a short lowercase fragment is treated as a cut word ("oy...", not "11'i..." or "verdi...").
        if (preg_match('/\s\p{Ll}{1,4}(?:\.\.\.|…)$/u', $text) === 1) {
            $text = rtrim((string) preg_replace('/\s+\p{Ll}{1,4}(?:\.\.\.|…)$/u', '', $text), " ,;:-–") . '…';
        }
        return $text === '…' ? '' : $text;
    }

    /** Absolute http(s) URL without tracking parameters/fragment, or null. */
    public static function cleanUrl(string $url, ?string $base = null): ?string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($url === '') {
            return null;
        }
        if (str_starts_with($url, '//')) {
            $url = 'https:' . $url;
        } elseif ($base !== null && str_starts_with($url, '/')) {
            $parts = parse_url($base);
            if (!isset($parts['scheme'], $parts['host'])) {
                return null;
            }
            $url = $parts['scheme'] . '://' . $parts['host'] . $url;
        }
        if (preg_match('#^https?://[^\s/?\#]+#i', $url) !== 1) {
            return null;
        }
        $url = (string) preg_replace('/#.*$/', '', $url);
        if (str_contains($url, '?')) {
            [$path, $query] = explode('?', $url, 2);
            $keep = [];
            foreach (explode('&', $query) as $pair) {
                $name = strtolower(explode('=', $pair, 2)[0]);
                if ($pair === '' || str_starts_with($name, 'utm_') || in_array($name, ['ref', 'fbclid', 'gclid', 'ocid', 'cmpid', 'from'], true)) {
                    continue;
                }
                $keep[] = $pair;
            }
            $url = $path . ($keep !== [] ? '?' . implode('&', $keep) : '');
        }
        return mb_substr($url, 0, 1000);
    }
}
