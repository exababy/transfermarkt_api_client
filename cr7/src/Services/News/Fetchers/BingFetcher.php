<?php

declare(strict_types=1);

namespace App\Services\News\Fetchers;

/**
 * Bing News RSS search. Item links are bing.com/news/apiclick.aspx redirects with the real
 * article in the `url` query parameter; the publisher is in <News:Source>.
 */
final class BingFetcher extends RssFetcher
{
    protected function normalize(string $key, array $source, array $raw): ?array
    {
        $raw['link'] = self::decodeLink((string) $raw['link']);
        $item = parent::normalize($key, $source, $raw);
        if ($item === null) {
            return null;
        }
        if (!empty($raw['source_name'])) {
            $item['source_name'] = (string) $raw['source_name'];
        }
        if ($item['image_url'] === null && !empty($raw['image']) && str_starts_with((string) $raw['image'], '/')) {
            $item['image_url'] = 'https://www.bing.com' . $raw['image'];
        }
        // Bing descriptions are cut mid-word at ~250 chars: drop the broken last word.
        if (mb_strlen($item['description']) >= 200 && preg_match('/[.!?…"”]$/u', $item['description']) !== 1) {
            $item['description'] = (string) preg_replace('/\s+\S*$/u', '', $item['description']) . '…';
        }
        return $item;
    }

    /** apiclick.aspx?…&url=https%3a%2f%2f… => the decoded article URL (other links unchanged). */
    public static function decodeLink(string $link): string
    {
        $link = html_entity_decode(trim($link), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $host = strtolower((string) parse_url($link, PHP_URL_HOST));
        if ($host !== '' && str_ends_with($host, 'bing.com')) {
            parse_str((string) parse_url($link, PHP_URL_QUERY), $query);
            if (isset($query['url']) && is_string($query['url']) && preg_match('#^https?://#i', $query['url']) === 1) {
                return $query['url'];
            }
        }
        return $link;
    }
}
