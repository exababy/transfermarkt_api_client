<?php

declare(strict_types=1);

namespace App\Services\News;

use App\Core\Config;
use App\Core\Database;
use App\Core\Settings;
use App\Repositories\NewsRepository;
use App\Support\Date;
use App\Support\Format;
use App\Support\Log;
use App\Support\OgImage;

/**
 * Publish step of the news pipeline (spec §9b contract, also used by the admin queue).
 *
 * publishNext() respects the daily limit (news.daily_limit, counted per Europe/Istanbul calendar
 * day), the minimum interval (news.min_interval_minutes) and the publish window (news.publish_window,
 * Istanbul hours [from, to)); it picks the best pending story primary — bigger cluster, more recent,
 * title naming him, full feed text available — and publishes it. publishItem($id, true) ignores
 * limit/interval/window ("Şimdi yayınla") but still requires the uniqueness threshold.
 */
final class NewsPublisher
{
    /** Candidates tried per publishNext() call (each failure is recorded on the item). */
    private const TRIES = 3;
    /** Turkish labels of the failure reasons stored in news_items.note (shown in the admin queue). */
    public const REASONS = [
        'no-text' => 'kaynak metni alınamadı',
        'low-uniqueness' => 'özgünlük eşiğin altında',
        'too-short' => 'metin çok kısa',
        'title-too-similar' => 'başlık kaynağa çok benziyor',
        'off-topic' => 'kaynak metni Ronaldo ile ilgili değil',
        'copy-check' => 'kaynakla aynen örtüşen bölüm çok fazla',
        'error' => 'beklenmeyen hata',
    ];

    /** Publishing is retried this many times for 'no-text' (network) failures. */
    private const MAX_ATTEMPTS = 3;

    private string $message = '';

    public function __construct(
        private ?ArticleComposer $composer = null,
        private ?NewsRepository $posts = null,
    ) {
        $this->composer ??= new ArticleComposer();
        $this->posts ??= new NewsRepository();
    }

    /** Human readable result of the last call (Turkish, for cron/admin messages). */
    public function lastMessage(): string
    {
        return $this->message;
    }

    public function publishNext(): ?int
    {
        $gate = $this->gate();
        if (!$gate['ok']) {
            $this->message = $gate['reason'];
            return null;
        }
        $candidates = $this->candidates(self::TRIES * 2);
        if ($candidates === []) {
            $this->message = 'Yayınlanacak uygun haber yok.';
            return null;
        }
        $reasons = [];
        foreach (array_slice($candidates, 0, self::TRIES) as $item) {
            $postId = $this->publish($item, $this->members($item));
            if ($postId !== null) {
                return $postId;
            }
            $reasons[] = '#' . $item['id'] . ' ' . $this->message;
        }
        $this->message = 'Yayınlanamadı: ' . implode('; ', $reasons);
        return null;
    }

    public function publishItem(int $newsItemId, bool $force = false): ?int
    {
        $item = Database::fetchOne('SELECT * FROM news_items WHERE id = ?', [$newsItemId]);
        if ($item === null) {
            $this->message = 'Haber kaydı bulunamadı.';
            return null;
        }
        if ($item['status'] === 'published' && !empty($item['post_id'])) {
            $this->message = 'Bu haber zaten yayında.';
            return (int) $item['post_id'];
        }
        // Another outlet's copy of a story that is already live: a second post would be a duplicate
        // (and publishing would move the whole cluster away from the live post).
        $storyPost = $this->storyPost($item);
        if ($storyPost !== null) {
            $this->message = 'Bu hikâye zaten yayında (yazı #' . $storyPost . '); aynı olay için ikinci bir yazı oluşturulmadı.';
            return $storyPost;
        }
        if (!$force) {
            $gate = $this->gate();
            if (!$gate['ok']) {
                $this->message = $gate['reason'];
                return null;
            }
        }
        if (!SourceRegistry::hasText((string) $item['source'])) {
            // Google evidence item: publish its story through a member with text, if any.
            foreach ($this->members($item) as $member) {
                if (SourceRegistry::hasText((string) $member['source']) && $member['status'] !== 'published') {
                    return $this->publish($member, $this->members($member));
                }
            }
            $this->message = 'Bu kaynağın metni alınamıyor (yalnızca başlık kanıtı).';
            return null;
        }
        if ($force) {
            ArticleExtractor::forgetFailure((string) $item['url']); // an admin retry fetches the page again
        }
        return $this->publish($item, $this->members($item));
    }

    /** Re-render the OG image of a post (e.g. after a title edit); returns the new path or null. */
    public function regenerateImage(int $postId): ?string
    {
        $post = Database::fetchOne('SELECT id, slug, title, published_at FROM posts WHERE id = ?', [$postId]);
        if ($post === null) {
            return null;
        }
        $old = Database::fetchValue('SELECT image_path FROM posts WHERE id = ?', [$postId]);
        $path = OgImage::generate((string) $post['title'], Format::date((string) $post['published_at'], 'j F Y'), self::imageName((string) $post['slug'], (string) $post['title'], $postId));
        if ($path !== null) {
            Database::update('posts', ['image_path' => $path, 'updated_at' => Date::now()], 'id = ?', [$postId]);
            if (is_string($old) && $old !== $path) {
                self::deleteImage($old, $postId); // a new title => new file name, so share caches refresh
            }
        }
        return $path;
    }

    /**
     * Delete the OG image file of post $postId unless another post still uses it (names made
     * before they carried the post id could be shared by posts with the same long title).
     */
    public static function deleteImage(?string $path, int $postId): void
    {
        if ($path === null || $path === '' || Database::fetchValue('SELECT 1 FROM posts WHERE image_path = ? AND id <> ?', [$path, $postId]) !== null) {
            return;
        }
        OgImage::delete($path);
    }

    /**
     * spinner.enabled / daily limit / interval / window check (automatic publishing only;
     * an admin "Şimdi yayınla" bypasses it).
     *
     * @return array{ok: bool, reason: string}
     */
    public function gate(): array
    {
        $spinner = Settings::get('spinner.enabled', Config::get('spinner.enabled', true));
        if (in_array($spinner, [false, 0, '0', 'false'], true)) {
            return ['ok' => false, 'reason' => 'Otomatik yayın kapalı (spinner.enabled = false).'];
        }
        $limit = (int) Settings::get('news.daily_limit', Config::get('news.daily_limit', 10));
        [$from, $to] = self::dayBoundsUtc();
        $today = $this->posts->countSpunBetween($from, $to);
        if ($limit <= 0 || $today >= $limit) {
            return ['ok' => false, 'reason' => "Günlük limit doldu ({$today}/{$limit})."];
        }
        $interval = (int) Settings::get('news.min_interval_minutes', Config::get('news.min_interval_minutes', 60));
        $last = $this->posts->lastSpunAt();
        if ($last !== null && $interval > 0) {
            $wait = strtotime($last . ' UTC') + $interval * 60 - time();
            if ($wait > 0) {
                return ['ok' => false, 'reason' => 'Asgari yayın aralığı dolmadı (' . (int) ceil($wait / 60) . ' dk kaldı).'];
            }
        }
        $window = Settings::get('news.publish_window', Config::get('news.publish_window', [7, 23]));
        if (is_array($window) && count($window) === 2) {
            $hour = (int) Date::local()->format('G');
            [$start, $end] = [(int) $window[0], (int) $window[1]];
            $inside = $start <= $end ? ($hour >= $start && $hour < $end) : ($hour >= $start || $hour < $end);
            if (!$inside && $start !== $end) {
                return ['ok' => false, 'reason' => sprintf('Yayın penceresi dışında (%02d:00–%02d:00, şu an %02d).', $start, $end, $hour)];
            }
        }
        return ['ok' => true, 'reason' => ''];
    }

    /** [start, end) of the current Europe/Istanbul calendar day as UTC 'Y-m-d H:i:s'. */
    public static function dayBoundsUtc(): array
    {
        $start = Date::local()->setTime(0, 0, 0);
        $utc = new \DateTimeZone('UTC');
        return [$start->setTimezone($utc)->format('Y-m-d H:i:s'), $start->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s')];
    }

    /**
     * Best pending story primaries first.
     *
     * @return list<array<string, mixed>>
     */
    public function candidates(int $limit = 10): array
    {
        $maxAge = (int) Settings::get('news.max_age_hours', Config::get('news.max_age_hours', 48));
        $since = Date::ago($maxAge * 3600);
        $rows = Database::fetchAll(
            "SELECT * FROM news_items WHERE status = 'pending' AND attempts < ? AND (note IS NULL OR note NOT LIKE ?)
             AND ((published_at IS NOT NULL AND published_at >= ?) OR (published_at IS NULL AND fetched_at >= ?))
             ORDER BY id DESC LIMIT 300",
            [self::MAX_ATTEMPTS, 'cluster:%', $since, $since]
        );
        if ($rows === []) {
            return [];
        }
        $sizes = [];
        foreach (Database::fetchAll("SELECT note, COUNT(*) AS n FROM news_items WHERE note LIKE ? GROUP BY note", ['cluster:%']) as $row) {
            $id = Clusterer::primaryFromNote((string) $row['note']);
            if ($id !== null) {
                $sizes[$id] = (int) $row['n'];
            }
        }
        $scored = [];
        foreach ($rows as $row) {
            if (!SourceRegistry::hasText((string) $row['source'])) {
                continue;
            }
            if ((int) $row['attempts'] > 0 && str_contains((string) $row['note'], 'no-text') && ArticleExtractor::failedRecently((string) $row['url'])) {
                continue; // retried once the cached fetch failure expires: MAX_ATTEMPTS counts real fetches
            }
            $age = max(0.0, (time() - (int) strtotime(($row['published_at'] ?? $row['fetched_at']) . ' UTC')) / 3600);
            $score = 3.0 * min(5, $sizes[(int) $row['id']] ?? 0)
                + max(0.0, ($maxAge - $age) / max(1, $maxAge)) * 4.0
                + (RelevanceFilter::score((string) $row['title'], '') === 2 ? 3.0 : 0.0)
                + (\App\Support\Cache::get('news:feedtext:' . $row['url_hash']) !== null ? 1.0 : 0.0)
                - 1.5 * (int) $row['attempts'];
            $scored[] = ['score' => $score, 'row' => $row];
        }
        usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        return array_map(static fn (array $s): array => $s['row'], array_slice($scored, 0, $limit));
    }

    /**
     * Id of the live post that already covers $item's story: the post of its published cluster
     * primary ('cluster:N'), or the manual post a 'post:N' note names; null otherwise.
     */
    public function storyPost(array $item): ?int
    {
        $note = (string) ($item['note'] ?? '');
        if (preg_match('/^post:(\d+)/', $note, $m) === 1) {
            $postId = Database::fetchValue("SELECT id FROM posts WHERE id = ? AND status = 'published'", [(int) $m[1]]);
        } elseif (($primary = Clusterer::primaryFromNote($note)) !== null && $primary !== (int) $item['id']) {
            $postId = Database::fetchValue(
                "SELECT p.id FROM news_items n JOIN posts p ON p.id = n.post_id WHERE n.id = ? AND n.status = 'published' AND p.status = 'published'",
                [$primary]
            );
        } else {
            return null;
        }
        return $postId !== null ? (int) $postId : null;
    }

    /** Other items of the same story cluster (excluding $item). */
    public function members(array $item): array
    {
        $primaryId = Clusterer::primaryFromNote($item['note'] ?? null) ?? (int) $item['id'];
        return Database::fetchAll(
            'SELECT * FROM news_items WHERE (id = ? OR note = ?) AND id <> ? ORDER BY id',
            [$primaryId, 'cluster:' . $primaryId, (int) $item['id']]
        );
    }

    /** Compose + store one post; records failures on the item. */
    private function publish(array $item, array $members): ?int
    {
        Database::update('news_items', ['attempts' => (int) $item['attempts'] + 1], 'id = ?', [(int) $item['id']]);
        try {
            $result = $this->composer->compose($item, $members);
        } catch (\Throwable $e) {
            Log::error('news', 'compose failed: ' . $e->getMessage(), ['item' => $item['id'], 'exception' => $e]);
            $result = ['ok' => false, 'reason' => 'error'];
        }
        if (!$result['ok']) {
            $this->recordFailure($item, (string) $result['reason'], $result);
            return null;
        }
        // "Ronaldo" alone is ambiguous: the story (feed title/description + lead article text) must
        // name Cristiano, otherwise it is another Ronaldo's (Nazário …) and would be published as his.
        // The lead text becomes the body: when it points to another Ronaldo it must name Cristiano itself.
        if (isset($result['source_texts'][0])) {
            $lead = (array) ($result['lead'] ?? $item);
            $story = implode("\n", [$item['title'], $item['description'] ?? '', $lead['title'] ?? '', $lead['description'] ?? '', (string) $result['source_texts'][0]]);
            if (!RelevanceFilter::aboutCristiano($story) || RelevanceFilter::aboutOtherRonaldo((string) $result['source_texts'][0])) {
                $this->recordFailure($item, 'off-topic', []);
                return null;
            }
        }

        // Scraped-content gate (Google spam policy; the owner never wants copies published): a post
        // that still shares too many word 6-grams with a source, or copies a long run verbatim, is
        // not published at all.
        $copy = isset($result['source_texts']) ? CopyCheck::measure((string) $result['body'], (array) $result['source_texts']) : null;
        if ($copy !== null && !$copy['ok']) {
            $this->recordFailure($item, 'copy-check', ['detail' => CopyCheck::describe($copy)]);
            return null;
        }
        $indexable = self::indexSpunPosts();

        $postId = Database::transaction(function () use ($item, $members, $result, $indexable): int {
            $now = Date::now();
            $slug = $this->posts->uniqueSlug((string) $result['title']);
            $names = array_values(array_unique(array_column($result['sources'], 'name')));
            $postId = Database::insert('posts', [
                'news_item_id' => (int) $item['id'],
                'slug' => $slug,
                'title' => mb_substr((string) $result['title'], 0, 255),
                'meta_title' => mb_substr(PostMeta::title((string) ($result['meta_title'] ?? ''), (string) $result['title']), 0, 255),
                'summary' => mb_substr((string) $result['summary'], 0, 500),
                'body' => (string) $result['body'],
                'tags' => Tagger::toColumn($result['tags']) ?: null,
                'category' => 'haber',
                'source_name' => mb_substr(implode(', ', $names), 0, 150) ?: null,
                'source_url' => mb_substr((string) ($result['sources'][0]['url'] ?? $item['url']), 0, 1000),
                'image_path' => null,
                'is_spun' => 1,
                'uniqueness' => (float) $result['uniqueness'],
                'source_count' => (int) $result['source_count'],
                'is_indexable' => $indexable ? 1 : 0,
                'status' => 'published',
                'views' => 0,
                'published_at' => $now,
                'updated_at' => $now,
                'created_at' => $now,
            ]);
            Database::update('news_items', ['status' => 'published', 'post_id' => $postId, 'note' => null], 'id = ?', [(int) $item['id']]);
            // Every other item of the story is covered now.
            foreach ($members as $member) {
                if (in_array($member['status'], ['pending', 'failed'], true)) {
                    Database::update('news_items', ['status' => 'duplicate', 'note' => 'cluster:' . (int) $item['id']], 'id = ?', [(int) $member['id']]);
                }
            }
            Database::execute('UPDATE news_items SET note = ? WHERE note = ?', ['cluster:' . (int) $item['id'], 'cluster:' . (Clusterer::primaryFromNote($item['note'] ?? null) ?? (int) $item['id'])]);
            return $postId;
        });

        $post = Database::fetchOne('SELECT slug, title, published_at FROM posts WHERE id = ?', [$postId]);
        $image = OgImage::generate((string) $result['title'], Format::date((string) $post['published_at'], 'j F Y'), self::imageName((string) $post['slug'], (string) $result['title'], (int) $postId));
        if ($image !== null) {
            Database::update('posts', ['image_path' => $image], 'id = ?', [$postId]);
        }
        if ($indexable) {
            IndexNow::ping([url('/haber/' . $post['slug'])]);
        }
        Log::info('news', 'published post', [
            'post' => $postId, 'item' => (int) $item['id'], 'slug' => $post['slug'],
            'uniqueness' => $result['uniqueness'], 'sources' => $result['source_count'], 'words' => $result['words'],
            'indexable' => $indexable, 'shared_6grams' => $copy['shared'] ?? null, 'verbatim_run' => $copy['run'] ?? null,
        ]);
        $this->message = sprintf('Yayınlandı: %s (özgünlük %%%s, %d kaynak).', $post['title'], number_format((float) $result['uniqueness'], 1, ',', ''), $result['source_count']);
        return (int) $postId;
    }

    private function recordFailure(array $item, string $reason, array $result): void
    {
        $attempts = (int) $item['attempts'] + 1;
        // Network/extraction problems are retried; low uniqueness etc. are final.
        $final = $reason !== 'no-text' && $reason !== 'error' || $attempts >= self::MAX_ATTEMPTS;
        $cluster = Clusterer::primaryFromNote($item['note'] ?? null);
        $note = ($cluster !== null ? 'cluster:' . $cluster . '; ' : '') . $reason
            . (isset($result['uniqueness']) ? ' (' . $result['uniqueness'] . '%)' : '')
            . (isset($result['detail']) ? ' (' . $result['detail'] . ')' : '');
        Database::update('news_items', [
            'status' => $final ? 'failed' : (string) $item['status'],
            'note' => mb_substr($note, 0, 255),
        ], 'id = ?', [(int) $item['id']]);
        $this->message = (self::REASONS[$reason] ?? $reason) . (isset($result['uniqueness']) ? ' (%' . $result['uniqueness'] . ')' : '')
            . (isset($result['detail']) ? ' (' . $result['detail'] . ')' : '');
        Log::info('news', 'item not published', ['item' => (int) $item['id'], 'reason' => $reason, 'uniqueness' => $result['uniqueness'] ?? null, 'words' => $result['words'] ?? null]);
    }

    /**
     * OG image file name: post id (unique even when two long slugs share their first 80
     * characters) + slug + short title hash (changes when the title is edited).
     */
    public static function imageName(string $slug, string $title, int $postId): string
    {
        return 'haber-' . $postId . '-' . substr($slug, 0, 80) . '-' . substr(sha1($title), 0, 6);
    }

    private static function indexSpunPosts(): bool
    {
        $value = Settings::get('news.index_spun_posts', Config::get('news.index_spun_posts', true));
        return !in_array($value, [false, 0, '0', 'false', '', null], true);
    }
}
