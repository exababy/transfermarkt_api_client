<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Services\Video\VideoText;
use App\Support\Date;

/**
 * Videos (table `videos`). Public reads only return status = 'published'.
 *
 * Row shape of latest()/featured()/search()/related()/findBySlug():
 * ['id','youtube_id','slug','title','description','thumbnail_url','published_at','category',
 *  'channel_title','duration_seconds','url' ('/video/slug')] plus 'channel_id','view_count',
 * 'is_featured','status','source','created_at','updated_at','category_name','date' (published_at ?? created_at).
 */
final class VideoRepository
{
    public const STATUS_PUBLISHED = 'published';

    /** Video categories: key => Turkish label. */
    public static function categories(): array
    {
        return [
            'genel' => 'Genel',
            'goller' => 'Goller',
            'mac-ozetleri' => 'Maç Özetleri',
            'roportajlar' => 'Röportajlar',
            'antrenman' => 'Antrenman',
            'belgesel' => 'Belgesel',
            'ur-cristiano' => 'UR · Cristiano',
        ];
    }

    public static function categoryName(?string $key): string
    {
        return self::categories()[(string) $key] ?? self::categories()['genel'];
    }

    /** Newest published videos (optionally one category). */
    public function latest(int $limit = 12, ?string $category = null, int $offset = 0): array
    {
        [$where, $params] = self::filter($category);
        $params[] = max(1, $limit);
        $params[] = max(0, $offset);
        return self::map(Database::fetchAll(
            'SELECT * FROM videos WHERE ' . $where . ' ORDER BY ' . self::ORDER . ' LIMIT ? OFFSET ?',
            $params
        ));
    }

    /** Featured (is_featured = 1) published videos, newest first. */
    public function featured(int $limit = 4): array
    {
        return self::map(Database::fetchAll(
            'SELECT * FROM videos WHERE status = ? AND is_featured = 1 ORDER BY ' . self::ORDER . ' LIMIT ?',
            [self::STATUS_PUBLISHED, max(1, $limit)]
        ));
    }

    public function count(?string $category = null): int
    {
        [$where, $params] = self::filter($category);
        return (int) Database::fetchValue('SELECT COUNT(*) FROM videos WHERE ' . $where, $params);
    }

    /** @return array<string, int> published videos per category key (only non-empty categories) */
    public function categoryCounts(): array
    {
        $rows = Database::fetchAll('SELECT category, COUNT(*) AS n FROM videos WHERE status = ? GROUP BY category', [self::STATUS_PUBLISHED]);
        $counts = [];
        foreach (self::categories() as $key => $label) {
            foreach ($rows as $row) {
                if ((string) $row['category'] === $key && (int) $row['n'] > 0) {
                    $counts[$key] = (int) $row['n'];
                }
            }
        }
        return $counts;
    }

    /** Published video by slug, or null. */
    public function findBySlug(string $slug): ?array
    {
        $row = Database::fetchOne('SELECT * FROM videos WHERE slug = ? AND status = ?', [$slug, self::STATUS_PUBLISHED]);
        return $row === null ? null : self::row($row);
    }

    /**
     * Published video whose slug ends with the same id fragment as $slug (the title part
     * changed, e.g. after an edit) — used for 301 redirects of old URLs.
     */
    public function findMovedSlug(string $slug): ?array
    {
        $pos = strrpos($slug, '-');
        $fragment = $pos === false ? '' : substr($slug, $pos + 1);
        if (preg_match('/^[a-z0-9]{3,11}$/', $fragment) !== 1) {
            return null;
        }
        $rows = Database::fetchAll(
            "SELECT * FROM videos WHERE status = ? AND slug LIKE ? ESCAPE '\\' ORDER BY id LIMIT 2",
            [self::STATUS_PUBLISHED, '%-' . Database::likeEscape($fragment)]
        );
        return count($rows) === 1 ? self::row($rows[0]) : null;
    }

    /** Any status (admin). */
    public function find(int $id): ?array
    {
        $row = Database::fetchOne('SELECT * FROM videos WHERE id = ?', [$id]);
        return $row === null ? null : self::row($row);
    }

    /** Any status. */
    public function findByYoutubeId(string $youtubeId): ?array
    {
        $row = Database::fetchOne('SELECT * FROM videos WHERE youtube_id = ?', [$youtubeId]);
        return $row === null ? null : self::row($row);
    }

    /** Published videos whose title or description contains every word of $q. */
    public function search(string $q, int $limit = 20): array
    {
        $words = array_slice(array_values(array_filter(
            preg_split('/\s+/u', trim($q)) ?: [],
            static fn (string $w): bool => mb_strlen($w) >= 2
        )), 0, 6);
        if ($words === []) {
            return [];
        }
        $where = ['status = ?'];
        $params = [self::STATUS_PUBLISHED];
        foreach ($words as $word) {
            $like = '%' . Database::likeEscape($word) . '%';
            $where[] = "(title LIKE ? ESCAPE '\\' OR description LIKE ? ESCAPE '\\' OR channel_title LIKE ? ESCAPE '\\')";
            array_push($params, $like, $like, $like);
        }
        $params[] = max(1, $limit);
        return self::map(Database::fetchAll(
            'SELECT * FROM videos WHERE ' . implode(' AND ', $where) . ' ORDER BY ' . self::ORDER . ' LIMIT ?',
            $params
        ));
    }

    /** Most watched videos of the same category first, then the newest others; never the video itself. */
    public function related(array $video, int $limit = 6): array
    {
        $id = (int) ($video['id'] ?? 0);
        $out = self::map(Database::fetchAll(
            'SELECT * FROM videos WHERE status = ? AND category = ? AND id <> ? ORDER BY is_featured DESC, COALESCE(view_count, 0) DESC, ' . self::ORDER . ' LIMIT ?',
            [self::STATUS_PUBLISHED, (string) ($video['category'] ?? 'genel'), $id, max(1, $limit)]
        ));
        if (count($out) < $limit) {
            $seen = array_merge([$id], array_map(static fn (array $v): int => (int) $v['id'], $out));
            $more = Database::fetchAll(
                'SELECT * FROM videos WHERE status = ? AND id NOT IN (' . implode(', ', array_fill(0, count($seen), '?')) . ') ORDER BY ' . self::ORDER . ' LIMIT ?',
                [self::STATUS_PUBLISHED, ...$seen, $limit - count($out)]
            );
            $out = array_merge($out, self::map($more));
        }
        return $out;
    }

    /** [['url','lastmod','youtube_id','title','description','thumbnail_url','published_at','duration_seconds']] */
    public function forSitemap(): array
    {
        $rows = Database::fetchAll(
            'SELECT * FROM videos WHERE status = ? ORDER BY ' . self::ORDER . ' LIMIT 50000',
            [self::STATUS_PUBLISHED]
        );
        $out = [];
        foreach ($rows as $row) {
            $video = self::row($row);
            $out[] = [
                'url' => url($video['url']),
                'lastmod' => (string) ($video['updated_at'] ?: $video['date']),
                'youtube_id' => $video['youtube_id'],
                'title' => $video['title'],
                'description' => $video['description'] !== '' ? $video['description'] : $video['title'],
                'thumbnail_url' => $video['thumbnail_url'],
                'published_at' => $video['date'],
                'duration_seconds' => $video['duration_seconds'],
                'player_url' => VideoText::embedUrl($video['youtube_id']),
                'content_url' => VideoText::watchUrl($video['youtube_id']),
            ];
        }
        return $out;
    }

    // ------------------------------------------------------------------ writes

    /**
     * Insert a new video (unique slug generated when missing). Required: youtube_id, title.
     * Returns the new id.
     */
    public function create(array $data): int
    {
        $now = Date::now();
        $youtubeId = (string) $data['youtube_id'];
        $title = trim((string) $data['title']);
        $row = [
            'youtube_id' => $youtubeId,
            'slug' => isset($data['slug']) && $data['slug'] !== '' ? (string) $data['slug'] : $this->uniqueSlug($title, $youtubeId),
            'title' => mb_substr($title !== '' ? $title : 'Cristiano Ronaldo videosu', 0, 255),
            'description' => $data['description'] ?? null,
            'channel_id' => $data['channel_id'] ?? null,
            'channel_title' => isset($data['channel_title']) ? mb_substr((string) $data['channel_title'], 0, 150) : null,
            'category' => array_key_exists((string) ($data['category'] ?? ''), self::categories()) ? (string) $data['category'] : 'genel',
            'thumbnail_url' => $data['thumbnail_url'] ?? VideoText::thumbnail($youtubeId),
            'duration_seconds' => isset($data['duration_seconds']) ? (int) $data['duration_seconds'] : null,
            'view_count' => isset($data['view_count']) ? (int) $data['view_count'] : null,
            'published_at' => $data['published_at'] ?? null,
            'source' => in_array($data['source'] ?? '', ['rss', 'api', 'manual'], true) ? (string) $data['source'] : 'manual',
            'is_featured' => !empty($data['is_featured']) ? 1 : 0,
            'status' => in_array($data['status'] ?? '', ['published', 'draft', 'hidden'], true) ? (string) $data['status'] : self::STATUS_PUBLISHED,
            'created_at' => $data['created_at'] ?? $now,
            'updated_at' => $data['updated_at'] ?? $now,
        ];
        return Database::insert('videos', $row);
    }

    /**
     * Update columns of a video (unknown keys are ignored; updated_at is set). A changed
     * 'slug' is made unique. Returns true when a row changed.
     */
    public function update(int $id, array $data): bool
    {
        $allowed = ['slug', 'title', 'description', 'channel_id', 'channel_title', 'category', 'thumbnail_url', 'duration_seconds',
            'view_count', 'published_at', 'source', 'is_featured', 'status'];
        $fields = array_intersect_key($data, array_flip($allowed));
        if (isset($fields['category']) && !array_key_exists((string) $fields['category'], self::categories())) {
            $fields['category'] = 'genel';
        }
        if (isset($fields['is_featured'])) {
            $fields['is_featured'] = !empty($fields['is_featured']) ? 1 : 0;
        }
        if (isset($fields['slug'])) {
            $fields['slug'] = $this->ensureUniqueSlug((string) $fields['slug'], $id);
        }
        if ($fields === []) {
            return false;
        }
        $fields['updated_at'] = Date::now();
        return Database::update('videos', $fields, 'id = ?', [$id]) > 0;
    }

    public function delete(int $id): bool
    {
        return Database::execute('DELETE FROM videos WHERE id = ?', [$id]) > 0;
    }

    /** Admin list (any status), newest first; $q filters title/channel/youtube id. */
    public function adminList(int $limit = 50, int $offset = 0, ?string $q = null, ?string $category = null): array
    {
        [$where, $params] = self::adminFilter($q, $category);
        $params[] = max(1, $limit);
        $params[] = max(0, $offset);
        return self::map(Database::fetchAll(
            'SELECT * FROM videos WHERE ' . $where . ' ORDER BY ' . self::ORDER . ' LIMIT ? OFFSET ?',
            $params
        ));
    }

    public function adminCount(?string $q = null, ?string $category = null): int
    {
        [$where, $params] = self::adminFilter($q, $category);
        return (int) Database::fetchValue('SELECT COUNT(*) FROM videos WHERE ' . $where, $params);
    }

    /** Slug for a new video: Str::slug(title) + '-' + id fragment; the fragment grows on collision. */
    public function uniqueSlug(string $title, string $youtubeId, ?int $exceptId = null): string
    {
        foreach ([5, 8, 11] as $length) {
            $slug = VideoText::slug($title, $youtubeId, $length);
            if (!$this->slugTaken($slug, $exceptId)) {
                return $slug;
            }
        }
        return $this->ensureUniqueSlug(VideoText::slug($title, $youtubeId, 11), $exceptId);
    }

    private function ensureUniqueSlug(string $slug, ?int $exceptId): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9-]+/', '-', strtolower($slug)), '-');
        $slug = $slug !== '' ? substr($slug, 0, 180) : 'video';
        $candidate = $slug;
        for ($i = 2; $this->slugTaken($candidate, $exceptId); $i++) {
            $candidate = $slug . '-' . $i;
        }
        return $candidate;
    }

    private function slugTaken(string $slug, ?int $exceptId): bool
    {
        return $exceptId === null
            ? Database::fetchValue('SELECT 1 FROM videos WHERE slug = ?', [$slug]) !== null
            : Database::fetchValue('SELECT 1 FROM videos WHERE slug = ? AND id <> ?', [$slug, $exceptId]) !== null;
    }

    // ------------------------------------------------------------------ helpers

    /** Newest first; videos without a YouTube date fall back to when they were added. */
    private const ORDER = 'COALESCE(published_at, created_at) DESC, id DESC';

    /** @return array{0: string, 1: list<mixed>} */
    private static function filter(?string $category): array
    {
        $where = 'status = ?';
        $params = [self::STATUS_PUBLISHED];
        if ($category !== null && $category !== '') {
            $where .= ' AND category = ?';
            $params[] = $category;
        }
        return [$where, $params];
    }

    /** @return array{0: string, 1: list<mixed>} */
    private static function adminFilter(?string $q, ?string $category): array
    {
        $where = ['1 = 1'];
        $params = [];
        $q = trim((string) $q);
        if ($q !== '') {
            $like = '%' . Database::likeEscape($q) . '%';
            $where[] = "(title LIKE ? ESCAPE '\\' OR channel_title LIKE ? ESCAPE '\\' OR youtube_id = ?)";
            array_push($params, $like, $like, $q);
        }
        if ($category !== null && $category !== '') {
            $where[] = 'category = ?';
            $params[] = $category;
        }
        return [implode(' AND ', $where), $params];
    }

    /** @param list<array<string, mixed>> $rows */
    private static function map(array $rows): array
    {
        return array_map(self::row(...), $rows);
    }

    /** Normalise DB types (MySQL returns strings) and add url/category_name/date. */
    private static function row(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['youtube_id'] = (string) $row['youtube_id'];
        $row['slug'] = (string) $row['slug'];
        $row['title'] = (string) $row['title'];
        $row['description'] = (string) ($row['description'] ?? '');
        $row['category'] = (string) ($row['category'] ?? 'genel');
        $row['duration_seconds'] = $row['duration_seconds'] !== null ? (int) $row['duration_seconds'] : null;
        $row['view_count'] = $row['view_count'] !== null ? (int) $row['view_count'] : null;
        $row['is_featured'] = (int) $row['is_featured'];
        $row['thumbnail_url'] = !empty($row['thumbnail_url']) ? (string) $row['thumbnail_url'] : VideoText::thumbnail($row['youtube_id']);
        $row['url'] = '/video/' . $row['slug'];
        $row['category_name'] = self::categoryName($row['category']);
        $row['date'] = (string) (($row['published_at'] ?? '') !== '' ? $row['published_at'] : $row['created_at']);
        return $row;
    }
}
