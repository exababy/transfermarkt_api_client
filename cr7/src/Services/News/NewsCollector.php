<?php

declare(strict_types=1);

namespace App\Services\News;

use App\Core\Config;
use App\Core\Database;
use App\Core\Settings;
use App\Services\News\Fetchers\BingFetcher;
use App\Services\News\Fetchers\Fetcher;
use App\Services\News\Fetchers\GoogleFetcher;
use App\Services\News\Fetchers\RssFetcher;
use App\Support\Cache;
use App\Support\Date;
use App\Support\Log;
use App\Support\Str;

/**
 * Fetch step of the news pipeline: downloads every enabled source, keeps items about Cristiano
 * Ronaldo that are recent enough, de-duplicates them (url_hash) and clusters same-story items
 * from different outlets (title similarity vs items/posts of the last 72 h).
 *
 * Statuses written: 'pending' (story primary with fetchable text), 'duplicate' + note
 * 'cluster:<primary id>' (same story, used for mixing), 'skipped' + note 'evidence-only'
 * (Google News item without a text source yet; promoted to a cluster member when one arrives).
 */
final class NewsCollector
{
    /** Max items considered per source and run. */
    private const MAX_PER_SOURCE = 80;
    /** Full feed text is kept this long for the publisher (seconds). */
    public const FEED_TEXT_TTL = 4 * 86400;

    /** @var list<array{id: int, titles: list<string>, text: string, source: string, note: ?string, status: string}>|null */
    private ?array $recent = null;

    /** @var array<string, Fetcher> */
    private array $fetchers = [];

    /**
     * Fetch all enabled sources (text sources first, Google last).
     *
     * @return array<string, array{fetched: int, relevant: int, new: int, clustered: int, error: ?string}>
     */
    public function run(?array $sources = null): array
    {
        $sources ??= SourceRegistry::all();
        uasort($sources, static fn (array $a, array $b): int => ($a['type'] === 'google') <=> ($b['type'] === 'google'));
        $stats = [];
        foreach ($sources as $key => $source) {
            $started = microtime(true);
            $result = $this->fetcher($source['type'])->fetch((string) $key, $source);
            $stats[$key] = $this->storeAll($result['items'], $source) + ['error' => $result['error']];
            if ($result['error'] !== null) {
                Log::error('news', "fetch {$key} failed: {$result['error']}", ['url' => $source['url']]);
            }
            $stats[$key]['ms'] = (int) round((microtime(true) - $started) * 1000);
        }
        $this->cleanup();
        return $stats;
    }

    /**
     * Store normalised items of one source.
     *
     * @return array{fetched: int, relevant: int, new: int, clustered: int}
     */
    public function storeAll(array $items, array $source): array
    {
        $counts = ['fetched' => count($items), 'relevant' => 0, 'new' => 0, 'clustered' => 0];
        foreach (array_slice($items, 0, self::MAX_PER_SOURCE) as $item) {
            $result = $this->store($item, $source);
            if (in_array($result, ['pending', 'duplicate', 'evidence', 'exists', 'promoted'], true)) {
                $counts['relevant']++;
            }
            if (in_array($result, ['pending', 'duplicate', 'evidence', 'promoted'], true)) {
                $counts['new']++;
            }
            if (in_array($result, ['duplicate', 'promoted'], true)) {
                $counts['clustered']++;
            }
        }
        return $counts;
    }

    /**
     * Store one normalised item.
     *
     * @return string 'exists' | 'irrelevant' | 'old' | 'pending' | 'duplicate' | 'promoted' | 'evidence'
     */
    public function store(array $item, array $source): string
    {
        $url = (string) $item['url'];
        $title = trim((string) $item['title']);
        if ($url === '' || $title === '') {
            return 'irrelevant';
        }
        $hash = Clusterer::urlHash($url);
        if (Database::fetchValue('SELECT 1 FROM news_items WHERE url_hash = ?', [$hash]) !== null) {
            return 'exists';
        }
        $isEvidence = !($source['text'] ?? true);
        $related = array_values(array_filter((array) ($item['related_titles'] ?? [])));
        $relevanceText = $isEvidence ? implode(' ', $related) : (string) $item['description'];
        if (RelevanceFilter::score($title, $relevanceText) === 0) {
            return 'irrelevant';
        }
        $publishedAt = $item['published_at'] ?? null;
        $maxAge = (int) Settings::get('news.max_age_hours', Config::get('news.max_age_hours', 48));
        if ($publishedAt !== null && strtotime($publishedAt . ' UTC') < time() - $maxAge * 3600) {
            return 'old';
        }

        $storyText = $title . ' ' . ($isEvidence ? implode(' ', $related) : (string) $item['description']);
        $matches = Clusterer::matches([$title, ...$related], $storyText, $this->recentCandidates());
        $status = $isEvidence ? 'skipped' : 'pending';
        $note = $isEvidence ? 'evidence-only' : null;
        $result = $isEvidence ? 'evidence' : 'pending';
        $promoteFrom = null;
        $mergeInto = null;
        if ($matches !== []) {
            // Story primaries the item matches (a manual post counts as a published primary).
            $primaries = [];
            foreach ($matches as $match) {
                $pid = $match['id'] < 0 ? $match['id'] : $this->primaryOf($match['id']);
                $primaries[$pid] ??= $pid < 0 ? ['id' => $pid, 'source' => 'post', 'status' => 'published', 'note' => null] : ($this->recentById($pid) ?? ['id' => $pid, 'source' => '', 'status' => 'pending', 'note' => null]);
            }
            // Target: a published story first, else the oldest primary with text, else the first match.
            $target = null;
            foreach ($primaries as $p) {
                if ($p['status'] === 'published') {
                    $target = $p;
                    break;
                }
            }
            if ($target === null) {
                $withText = array_filter($primaries, static fn (array $p): bool => $p['source'] !== '' && SourceRegistry::hasText($p['source']) && $p['status'] !== 'failed');
                ksort($withText);
                $target = $withText !== [] ? reset($withText) : reset($primaries);
            }
            $primaryId = (int) $target['id'];
            $weakPrimary = $primaryId > 0 && $target['status'] !== 'published'
                && (!SourceRegistry::hasText((string) $target['source']) || $target['status'] === 'failed');
            if ($primaryId < 0) {
                $status = 'duplicate';
                $note = 'post:' . (-$primaryId);
                $result = 'duplicate';
            } elseif ($weakPrimary && !$isEvidence) {
                // First text source of a story only Google knew so far, or a new outlet for a story
                // whose primary failed (no text / too little rewriting): it becomes the primary.
                $promoteFrom = $primaryId;
                $result = 'promoted';
            } else {
                $status = 'duplicate';
                $note = 'cluster:' . $primaryId;
                $result = 'duplicate';
            }
            // Other unpublished clusters of the same story are merged into the target.
            $mergeInto = [];
            foreach ($primaries as $pid => $p) {
                if ($pid > 0 && $pid !== $primaryId && $p['status'] !== 'published') {
                    $mergeInto[] = (int) $pid;
                }
            }
        }

        $id = Database::insert('news_items', [
            'source' => mb_substr((string) $item['source'], 0, 60),
            'source_name' => mb_substr(trim((string) ($item['source_name'] ?? '')) ?: (string) $source['name'], 0, 150),
            'url' => mb_substr($url, 0, 1000),
            'url_hash' => $hash,
            'title' => mb_substr($title, 0, 500),
            'title_norm' => mb_substr(Str::normalize($title), 0, 500),
            'description' => $item['description'] !== '' ? (string) $item['description'] : null,
            'image_url' => isset($item['image_url']) && $item['image_url'] !== null ? mb_substr((string) $item['image_url'], 0, 1000) : null,
            'published_at' => $publishedAt,
            'fetched_at' => Date::now(),
            'status' => $status,
            'post_id' => null,
            'attempts' => 0,
            'note' => $note,
        ]);
        $finalPrimary = $promoteFrom !== null ? $id : Clusterer::primaryFromNote($note);
        if ($finalPrimary !== null && $mergeInto !== null) {
            foreach ($mergeInto as $pid) {
                $this->repoint($pid, $finalPrimary);
            }
        }
        if ($promoteFrom !== null) {
            // The new text item becomes the story primary; the Google item(s) become members.
            Database::execute('UPDATE news_items SET note = ? WHERE note = ?', ['cluster:' . $id, 'cluster:' . $promoteFrom]);
            Database::update('news_items', ['status' => 'duplicate', 'note' => 'cluster:' . $id], 'id = ?', [$promoteFrom]);
            foreach ($this->recent ?? [] as $i => $row) {
                if ($row['id'] === $promoteFrom || $row['note'] === 'cluster:' . $promoteFrom) {
                    $this->recent[$i]['note'] = 'cluster:' . $id;
                    $this->recent[$i]['status'] = 'duplicate';
                }
            }
        }
        $content = trim((string) ($item['content'] ?? ''));
        if ($content !== '' && !$isEvidence) {
            Cache::set('news:feedtext:' . $hash, $content, self::FEED_TEXT_TTL);
        }
        $this->recent[] = [
            'id' => $id,
            'titles' => [$title, ...$related],
            'text' => $storyText,
            'source' => (string) $item['source'],
            'note' => $note,
            'status' => $status,
        ];
        return $result;
    }

    /** Move cluster $from (its primary and members) under primary $to. */
    private function repoint(int $from, int $to): void
    {
        if ($from === $to) {
            return;
        }
        Database::execute('UPDATE news_items SET note = ? WHERE note = ?', ['cluster:' . $to, 'cluster:' . $from]);
        Database::execute("UPDATE news_items SET status = 'duplicate', note = ? WHERE id = ? AND status <> 'published'", ['cluster:' . $to, $from]);
        foreach ($this->recent ?? [] as $i => $row) {
            if ($row['id'] === $from || $row['note'] === 'cluster:' . $from) {
                $this->recent[$i]['note'] = 'cluster:' . $to;
                if ($row['status'] !== 'published') {
                    $this->recent[$i]['status'] = $row['id'] === $from ? 'duplicate' : $row['status'];
                }
            }
        }
    }

    /** Remove old non-published queue rows (keeps the table small). */
    public function cleanup(int $days = 30): int
    {
        return Database::execute(
            "DELETE FROM news_items WHERE status <> 'published' AND fetched_at < ?",
            [Date::ago($days * 86400)]
        );
    }

    private function fetcher(string $type): Fetcher
    {
        return $this->fetchers[$type] ??= match ($type) {
            'bing' => new BingFetcher(),
            'google' => new GoogleFetcher(),
            default => new RssFetcher(),
        };
    }

    /** Items (and posts without items, as negative ids) of the clustering window. */
    private function recentCandidates(): array
    {
        if ($this->recent !== null) {
            return $this->recent;
        }
        $since = Date::ago(Clusterer::WINDOW_HOURS * 3600);
        $this->recent = [];
        $rows = Database::fetchAll(
            'SELECT id, source, title, description, note, status FROM news_items WHERE fetched_at >= ? OR published_at >= ? ORDER BY id',
            [$since, $since]
        );
        foreach ($rows as $row) {
            $titles = [(string) $row['title']];
            if (!SourceRegistry::hasText((string) $row['source']) && !empty($row['description'])) {
                array_push($titles, ...array_filter(array_map('trim', explode("\n", (string) $row['description']))));
            }
            $this->recent[] = [
                'id' => (int) $row['id'],
                'titles' => $titles,
                'text' => (string) $row['title'] . ' ' . str_replace("\n", ' ', (string) ($row['description'] ?? '')),
                'source' => (string) $row['source'],
                'note' => $row['note'] !== null ? (string) $row['note'] : null,
                'status' => (string) $row['status'],
            ];
        }
        foreach (Database::fetchAll('SELECT id, title FROM posts WHERE news_item_id IS NULL AND published_at >= ?', [$since]) as $post) {
            $this->recent[] = ['id' => -(int) $post['id'], 'titles' => [(string) $post['title']], 'text' => '', 'source' => 'post', 'note' => null, 'status' => 'published'];
        }
        return $this->recent;
    }

    private function primaryOf(int $id): int
    {
        $row = $this->recentById($id);
        $primary = $row !== null ? Clusterer::primaryFromNote($row['note']) : null;
        return $primary ?? $id;
    }

    private function recentById(int $id): ?array
    {
        foreach ($this->recent ?? [] as $row) {
            if ($row['id'] === $id) {
                return $row;
            }
        }
        return null;
    }
}
