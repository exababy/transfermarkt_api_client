<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\App;
use App\Core\Database;
use App\Services\News\Tagger;
use App\Support\Date;
use App\Support\Str;

/**
 * Read access to published news posts (+ a few helpers used by the news pipeline).
 *
 * "Published" = posts.status 'published' and published_at <= now (UTC). Listing rows:
 * ['id','slug','title','summary','image_url' (absolute URL or null),'published_at','source_name','tags','url' ('/haber/slug')].
 */
final class NewsRepository
{
    private const LIST_COLUMNS = 'id, slug, title, summary, image_path, published_at, updated_at, source_name, tags, is_indexable, source_count';

    /** Published posts, newest first. */
    public function latest(int $limit = 6, int $offset = 0): array
    {
        $rows = Database::fetchAll(
            'SELECT ' . self::LIST_COLUMNS . ' FROM posts WHERE status = ? AND published_at <= ? ORDER BY published_at DESC, id DESC LIMIT ? OFFSET ?',
            ['published', Date::now(), max(0, $limit), max(0, $offset)]
        );
        return array_map(self::listRow(...), $rows);
    }

    public function count(): int
    {
        return (int) Database::fetchValue('SELECT COUNT(*) FROM posts WHERE status = ? AND published_at <= ?', ['published', Date::now()]);
    }

    /** Full published post (+ 'image_url', 'url', 'tag_list'), or null. */
    public function findBySlug(string $slug): ?array
    {
        $row = Database::fetchOne('SELECT * FROM posts WHERE slug = ? AND status = ? AND published_at <= ?', [$slug, 'published', Date::now()]);
        return $row === null ? null : self::fullRow($row);
    }

    /** Current slug of the visible post that used $slug before it was renamed (=> 301), or null. */
    public function movedSlug(string $slug): ?string
    {
        $id = SlugRedirectRepository::target(SlugRedirectRepository::POST, $slug);
        if ($id === null) {
            return null;
        }
        $current = Database::fetchValue('SELECT slug FROM posts WHERE id = ? AND status = ? AND published_at <= ?', [$id, 'published', Date::now()]);
        return is_string($current) && $current !== $slug ? $current : null;
    }

    /** Any post by id (also drafts), for the pipeline/admin. */
    public function findById(int $id): ?array
    {
        $row = Database::fetchOne('SELECT * FROM posts WHERE id = ?', [$id]);
        return $row === null ? null : self::fullRow($row);
    }

    /** Title/summary/tag search (all words must match), newest first; same shape as latest(). */
    public function search(string $q, int $limit = 20): array
    {
        $terms = array_slice(array_values(array_filter(
            preg_split('/\s+/u', trim($q)) ?: [],
            static fn (string $t): bool => mb_strlen($t) >= 2
        )), 0, 6);
        if ($terms === []) {
            return [];
        }
        $where = ['status = ?', 'published_at <= ?'];
        $params = ['published', Date::now()];
        foreach ($terms as $term) {
            [$sql, $termParams] = Database::containsAny(['title', 'summary', 'tags'], $term);
            $where[] = $sql;
            array_push($params, ...$termParams);
        }
        $params[] = max(1, $limit);
        $rows = Database::fetchAll(
            'SELECT ' . self::LIST_COLUMNS . ' FROM posts WHERE ' . implode(' AND ', $where) . ' ORDER BY published_at DESC, id DESC LIMIT ?',
            $params
        );
        return array_map(self::listRow(...), $rows);
    }

    /** @return list<array{url: string, lastmod: string, image: ?string}> indexable posts only, newest first */
    public function forSitemap(int $limit = 5000, int $offset = 0): array
    {
        $rows = Database::fetchAll(
            'SELECT slug, image_path, published_at, updated_at FROM posts WHERE status = ? AND is_indexable = 1 AND published_at <= ? ORDER BY published_at DESC, id DESC LIMIT ? OFFSET ?',
            ['published', Date::now(), max(1, $limit), max(0, $offset)]
        );
        return array_map(static fn (array $r): array => [
            'url' => url('/haber/' . $r['slug']),
            'lastmod' => (string) ($r['updated_at'] ?: $r['published_at']),
            'image' => self::imageUrl($r['image_path'] ?? null),
        ], $rows);
    }

    /** Number of posts forSitemap() can list (splits the posts sitemap into files). */
    public function countForSitemap(): int
    {
        return (int) Database::fetchValue('SELECT COUNT(*) FROM posts WHERE status = ? AND is_indexable = 1 AND published_at <= ?', ['published', Date::now()]);
    }

    /** @return list<array{url: string, title: string, published_at: string}> last 48 h, indexable only */
    public function forNewsSitemap(): array
    {
        $rows = Database::fetchAll(
            'SELECT slug, title, published_at FROM posts WHERE status = ? AND is_indexable = 1 AND published_at >= ? AND published_at <= ? ORDER BY published_at DESC LIMIT 1000',
            ['published', Date::ago(48 * 3600), Date::now()]
        );
        return array_map(static fn (array $r): array => [
            'url' => url('/haber/' . $r['slug']),
            'title' => (string) $r['title'],
            'published_at' => (string) $r['published_at'],
        ], $rows);
    }

    // ------------------------------------------------------------------ helpers

    /** Indexable posts for the RSS feed (full rows incl. body), newest first. */
    public function forFeed(int $limit = 30): array
    {
        $rows = Database::fetchAll(
            'SELECT * FROM posts WHERE status = ? AND is_indexable = 1 AND published_at <= ? ORDER BY published_at DESC, id DESC LIMIT ?',
            ['published', Date::now(), max(1, $limit)]
        );
        return array_map(self::fullRow(...), $rows);
    }

    /** Tag name for a tag slug (dictionary tags, else tags used by published posts), or null. */
    public function tagName(string $slug): ?string
    {
        $name = Tagger::nameForSlug($slug);
        if ($name !== null) {
            return $name;
        }
        // Every post, not just the newest few thousand (streamed: only the tags column is read).
        $statement = Database::query("SELECT tags FROM posts WHERE status = ? AND tags IS NOT NULL AND tags <> '' ORDER BY published_at DESC, id DESC", ['published']);
        while (($row = $statement->fetch(\PDO::FETCH_ASSOC)) !== false) {
            foreach (Tagger::fromColumn((string) $row['tags']) as $tag) {
                if ($tag['slug'] === $slug) {
                    $statement->closeCursor();
                    return $tag['name'];
                }
            }
        }
        return null;
    }

    /** Published posts carrying tag $slug, newest first (paged in SQL, no cap on the archive size). */
    public function byTag(string $slug, int $limit = 12, int $offset = 0): array
    {
        $match = $this->tagWhere($slug);
        if ($match === null || $limit <= 0) {
            return [];
        }
        $rows = Database::fetchAll(
            'SELECT ' . self::LIST_COLUMNS . ' FROM posts WHERE status = ? AND published_at <= ? AND ' . $match[0] . ' ORDER BY published_at DESC, id DESC LIMIT ? OFFSET ?',
            ['published', Date::now(), ...$match[1], $limit, max(0, $offset)]
        );
        return array_map(self::listRow(...), $rows);
    }

    public function countByTag(string $slug): int
    {
        $match = $this->tagWhere($slug);
        return $match === null ? 0 : (int) Database::fetchValue(
            'SELECT COUNT(*) FROM posts WHERE status = ? AND published_at <= ? AND ' . $match[0],
            ['published', Date::now(), ...$match[1]]
        );
    }

    /**
     * Tags of visible posts with their post count and newest post time (sitemap of tag pages).
     * Every visible post is counted (streamed: only the tag and date columns are read).
     *
     * @return array<string, array{name: string, count: int, lastmod: string}> slug => tag
     */
    public function tagCounts(): array
    {
        $statement = Database::query(
            "SELECT tags, published_at, updated_at FROM posts WHERE status = ? AND published_at <= ? AND tags IS NOT NULL AND tags <> '' ORDER BY published_at DESC, id DESC",
            ['published', Date::now()]
        );
        $out = [];
        while (($row = $statement->fetch(\PDO::FETCH_ASSOC)) !== false) {
            $lastmod = (string) ($row['updated_at'] ?: $row['published_at']);
            foreach (Tagger::fromColumn((string) $row['tags']) as $tag) {
                $entry = $out[$tag['slug']] ?? ['name' => $tag['name'], 'count' => 0, 'lastmod' => $lastmod];
                $entry['count']++;
                $entry['lastmod'] = max($entry['lastmod'], $lastmod);
                $out[$tag['slug']] = $entry;
            }
        }
        return $out;
    }

    /** Other published posts sharing tags with $post (most shared tags, then newest); tops up with latest posts. */
    public function related(array $post, int $limit = 3): array
    {
        $tags = array_column(Tagger::fromColumn($post['tags'] ?? ''), 'name');
        $scores = [];
        if ($tags !== []) {
            $where = [];
            $params = ['published', Date::now(), (int) $post['id']];
            foreach ($tags as $tag) {
                $where[] = "tags LIKE ? ESCAPE '\\'";
                $params[] = '%' . Database::likeEscape($tag) . '%';
            }
            $rows = Database::fetchAll(
                'SELECT id, tags, published_at FROM posts WHERE status = ? AND published_at <= ? AND id <> ? AND (' . implode(' OR ', $where) . ') ORDER BY published_at DESC LIMIT 200',
                $params
            );
            foreach ($rows as $row) {
                $shared = count(array_intersect($tags, array_column(Tagger::fromColumn((string) $row['tags']), 'name')));
                if ($shared > 0) {
                    $scores[(int) $row['id']] = [$shared, (string) $row['published_at']];
                }
            }
            uasort($scores, static fn (array $a, array $b): int => [$b[0], $b[1]] <=> [$a[0], $a[1]]);
        }
        $ids = array_slice(array_keys($scores), 0, $limit);
        $out = [];
        if ($ids !== []) {
            $rows = Database::fetchAll('SELECT ' . self::LIST_COLUMNS . ' FROM posts WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids);
            $byId = [];
            foreach ($rows as $row) {
                $byId[(int) $row['id']] = self::listRow($row);
            }
            foreach ($ids as $id) {
                if (isset($byId[$id])) {
                    $out[] = $byId[$id];
                }
            }
        }
        if (count($out) < $limit) {
            $have = array_merge([(int) $post['id']], array_column($out, 'id'));
            foreach ($this->latest($limit + count($have), 0) as $row) {
                if (count($out) >= $limit) {
                    break;
                }
                if (!in_array((int) $row['id'], $have, true)) {
                    $out[] = $row;
                }
            }
        }
        return $out;
    }

    public function incrementViews(int $id): void
    {
        Database::execute('UPDATE posts SET views = views + 1 WHERE id = ?', [$id]);
    }

    /** Is the slug free (optionally ignoring post $exceptId)? */
    public function slugExists(string $slug, ?int $exceptId = null): bool
    {
        $sql = 'SELECT 1 FROM posts WHERE slug = ?' . ($exceptId !== null ? ' AND id <> ?' : '');
        return Database::fetchValue($sql, $exceptId !== null ? [$slug, $exceptId] : [$slug]) !== null;
    }

    /** Unique slug from a title: Str::slug + numeric suffix when taken. */
    public function uniqueSlug(string $title, ?int $exceptId = null): string
    {
        $base = Str::slug($title, 80);
        if ($base === '') {
            $base = 'haber';
        }
        $slug = $base;
        for ($i = 2; $this->slugExists($slug, $exceptId); $i++) {
            $slug = $base . '-' . $i;
        }
        return $slug;
    }

    /** Number of automatically published (spun) posts between two UTC datetimes (unpublished ones do not count). */
    public function countSpunBetween(string $fromUtc, string $toUtc): int
    {
        return (int) Database::fetchValue(
            "SELECT COUNT(*) FROM posts WHERE is_spun = 1 AND news_item_id IS NOT NULL AND status = 'published' AND published_at >= ? AND published_at < ?",
            [$fromUtc, $toUtc]
        );
    }

    /**
     * UTC publish time of the latest automatically published post that is already live, or null
     * (a post the admin scheduled for tomorrow must not hold back automatic publishing until then).
     */
    public function lastSpunAt(): ?string
    {
        $value = Database::fetchValue(
            "SELECT MAX(published_at) FROM posts WHERE is_spun = 1 AND news_item_id IS NOT NULL AND status = 'published' AND published_at <= ?",
            [Date::now()]
        );
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * SQL condition + params matching posts that carry tag $slug: its name (case-insensitive
     * LIKE) as a whole element of the ', '-separated tags column; null for an unknown tag.
     *
     * @return array{0: string, 1: list<string>}|null
     */
    private function tagWhere(string $slug): ?array
    {
        $name = $this->tagName($slug);
        if ($name === null) {
            return null;
        }
        $like = Database::likeEscape($name);
        $sql = [];
        foreach ([1, 2, 3, 4] as $_) {
            $sql[] = "REPLACE(tags, ', ', ',') LIKE ? ESCAPE '\\'";
        }
        return ['(' . implode(' OR ', $sql) . ')', [$like, $like . ',%', '%,' . $like, '%,' . $like . ',%']];
    }

    private static function listRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'slug' => (string) $row['slug'],
            'title' => (string) $row['title'],
            'summary' => (string) $row['summary'],
            'image_url' => self::imageUrl($row['image_path'] ?? null),
            'published_at' => (string) $row['published_at'],
            'updated_at' => (string) ($row['updated_at'] ?? $row['published_at']),
            'source_name' => $row['source_name'] !== null ? (string) $row['source_name'] : null,
            'tags' => (string) ($row['tags'] ?? ''),
            'url' => '/haber/' . $row['slug'],
        ];
    }

    private static function fullRow(array $row): array
    {
        foreach (['id', 'news_item_id', 'is_spun', 'source_count', 'is_indexable', 'views'] as $int) {
            if (isset($row[$int])) {
                $row[$int] = (int) $row[$int];
            }
        }
        $row['uniqueness'] = isset($row['uniqueness']) ? (float) $row['uniqueness'] : null;
        $row['image_url'] = self::imageUrl($row['image_path'] ?? null);
        $row['url'] = '/haber/' . $row['slug'];
        $row['tag_list'] = Tagger::fromColumn($row['tags'] ?? '');
        return $row;
    }

    /**
     * Absolute URL of a post image, or null when it is a local file (e.g. a generated /og/*.png)
     * that no longer exists: cards then render their placeholder and og:image falls back to the
     * default image instead of pointing at a 404.
     */
    public static function imageUrl(mixed $path): ?string
    {
        $path = trim((string) $path);
        if ($path === '') {
            return null;
        }
        $local = str_starts_with($path, '/') && !str_starts_with($path, '//');
        if ($local && !is_file(App::basePath('public/' . ltrim((string) parse_url($path, PHP_URL_PATH), '/')))) {
            return null;
        }
        return url($path);
    }
}
