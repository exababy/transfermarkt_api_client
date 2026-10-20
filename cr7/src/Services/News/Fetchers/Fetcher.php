<?php

declare(strict_types=1);

namespace App\Services\News\Fetchers;

/**
 * Downloads one news source and returns normalised candidate items.
 *
 * Item shape: ['source' => key, 'source_name' => publisher, 'url' => article URL, 'title', 'description',
 *              'content' => feed full text (paragraphs separated by blank lines) or '', 'image_url' => ?string,
 *              'published_at' => ?UTC, 'related_titles' => list<string> (Google story cluster titles)]
 */
interface Fetcher
{
    /**
     * @param array{name: string, type: string, url: string, filter: bool, text: bool} $source
     * @return array{items: list<array<string, mixed>>, error: ?string, status: int}
     */
    public function fetch(string $key, array $source): array;
}
