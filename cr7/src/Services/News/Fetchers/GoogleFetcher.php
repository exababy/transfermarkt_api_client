<?php

declare(strict_types=1);

namespace App\Services\News\Fetchers;

use App\Services\News\FeedParser;

/**
 * Google News RSS search. Titles end with " - Publisher" and links are Google redirect URLs that
 * cannot be fetched for article text, so these items are only clustering / title evidence. The
 * description lists Google's own story cluster (other outlets' titles) which is kept as
 * 'related_titles' and stored one per line in news_items.description.
 */
final class GoogleFetcher extends RssFetcher
{
    protected function normalize(string $key, array $source, array $raw): ?array
    {
        $publisher = !empty($raw['source_name']) ? (string) $raw['source_name'] : null;
        [$title, $fromTitle] = self::splitTitle((string) $raw['title'], $publisher);
        $raw['title'] = $title;
        $item = parent::normalize($key, $source, $raw);
        if ($item === null) {
            return null;
        }
        $item['source_name'] = $publisher ?? $fromTitle ?? $source['name'];
        $item['related_titles'] = self::relatedTitles((string) ($raw['description_html'] ?? ''), $item['description'], $title);
        $item['description'] = implode("\n", $item['related_titles']);
        $item['content'] = '';
        $item['image_url'] = null;
        return $item;
    }

    /** "Başlık - Yayıncı" => ['Başlık', 'Yayıncı'] (publisher null when not detectable). */
    public static function splitTitle(string $title, ?string $publisher = null): array
    {
        $title = trim($title);
        if ($publisher !== null && $publisher !== '') {
            $suffix = ' - ' . $publisher;
            if (str_ends_with($title, $suffix)) {
                return [trim(substr($title, 0, -strlen($suffix))), $publisher];
            }
        }
        $pos = strrpos($title, ' - ');
        if ($pos !== false && $pos > 15 && mb_strlen(substr($title, $pos + 3)) <= 40) {
            return [trim(substr($title, 0, $pos)), trim(substr($title, $pos + 3))];
        }
        return [$title, null];
    }

    /**
     * Titles of Google's story cluster. The parsed description is plain text where each entry is
     * "Title  Publisher" (two non-breaking spaces in the original HTML).
     *
     * @return list<string>
     */
    public static function relatedTitles(string $html, string $text, string $ownTitle): array
    {
        $titles = [];
        if ($html !== '' && preg_match_all('~<a\b[^>]*>(.*?)</a>~is', html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $m) > 0) {
            foreach ($m[1] as $t) {
                $titles[] = trim(html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            }
        }
        $out = [];
        foreach ($titles as $t) {
            $t = FeedParser::cleanText($t);
            if ($t !== '' && $t !== $ownTitle && !in_array($t, $out, true)) {
                $out[] = mb_substr($t, 0, 300);
            }
        }
        return array_slice($out, 0, 12);
    }
}
