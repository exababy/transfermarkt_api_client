<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\News\NewsPublisher;
use App\Services\News\SourceRegistry;
use App\Support\Log;

/**
 * News queue (news_items): filter by status / source / search / story cluster, publish an item
 * immediately ("Şimdi yayınla" => NewsPublisher::publishItem($id, true)) or skip it.
 */
final class QueueController
{
    private const PER_PAGE = 30;
    private const RESULT_KEY = 'admin.queue_result';

    public const STATUSES = [
        'pending' => 'Bekliyor',
        'failed' => 'Başarısız',
        'duplicate' => 'Aynı hikâye',
        'skipped' => 'Atlandı',
        'published' => 'Yayınlandı',
    ];

    public function index(Request $request): Response
    {
        if ($redirect = Auth::require()) {
            return $redirect;
        }
        $status = AdminPage::queryString($request, 'durum', 20);
        $source = AdminPage::queryString($request, 'kaynak', 60);
        $q = AdminPage::queryString($request, 'q', 100);
        $clusterRaw = AdminPage::queryString($request, 'kume', 12);
        $cluster = ctype_digit($clusterRaw) ? (int) $clusterRaw : 0;

        $where = ['1 = 1'];
        $params = [];
        if (array_key_exists($status, self::STATUSES)) {
            $where[] = 'status = ?';
            $params[] = $status;
        } else {
            $status = '';
        }
        if ($source !== '') {
            $where[] = 'source = ?';
            $params[] = $source;
        }
        if ($q !== '') {
            $where[] = "(title LIKE ? ESCAPE '\\' OR description LIKE ? ESCAPE '\\' OR url LIKE ? ESCAPE '\\')";
            $like = AdminPage::like($q);
            array_push($params, $like, $like, $like);
        }
        if ($cluster > 0) {
            $where[] = '(id = ? OR note = ? OR note LIKE ?)';
            array_push($params, $cluster, 'cluster:' . $cluster, 'cluster:' . $cluster . ';%');
        }
        $whereSql = implode(' AND ', $where);
        $total = (int) Database::fetchValue('SELECT COUNT(*) FROM news_items WHERE ' . $whereSql, $params);
        $pager = AdminPage::paginate($total, self::PER_PAGE, AdminPage::page($request));
        $items = Database::fetchAll(
            'SELECT id, source, source_name, url, title, description, image_url, published_at, fetched_at, status, post_id, attempts, note
             FROM news_items WHERE ' . $whereSql . ' ORDER BY COALESCE(published_at, fetched_at) DESC, id DESC LIMIT ? OFFSET ?',
            [...$params, $pager['perPage'], $pager['offset']]
        );

        $counts = array_fill_keys(array_keys(self::STATUSES), 0);
        foreach (Database::fetchAll('SELECT status, COUNT(*) AS n FROM news_items GROUP BY status') as $row) {
            $counts[(string) $row['status']] = (int) $row['n'];
        }

        return AdminPage::render('queue', 'Haber Kuyruğu', [
            'items' => $items,
            'pager' => $pager,
            'filters' => ['durum' => $status, 'kaynak' => $source, 'q' => $q, 'kume' => $cluster > 0 ? (string) $cluster : ''],
            'counts' => $counts,
            'total' => array_sum($counts),
            'statuses' => self::STATUSES,
            'sources' => self::sourceOptions(),
            'clusterSizes' => self::clusterSizes(),
            'posts' => self::postsFor($items),
            'result' => Session::pull(self::RESULT_KEY),
            'publisherAvailable' => class_exists(NewsPublisher::class),
            'back' => AdminPage::currentPath($request),
        ], 200, [['name' => 'Haber Kuyruğu']]);
    }

    public function publish(Request $request): Response
    {
        if ($redirect = Auth::require()) {
            return $redirect;
        }
        Csrf::check($request);
        $id = (int) $request->param('id');
        $item = Database::fetchOne('SELECT id, title, status, post_id FROM news_items WHERE id = ?', [$id]);
        if ($item === null) {
            Session::flash('error', 'Kuyruk kaydı bulunamadı.');
            return AdminPage::back($request, '/admin/haber-kuyrugu');
        }
        if (!class_exists(NewsPublisher::class) || !method_exists(NewsPublisher::class, 'publishItem')) {
            Session::flash('error', 'Haber modülü (NewsPublisher) kurulu değil; yayınlama yapılamıyor.');
            return AdminPage::back($request, '/admin/haber-kuyrugu');
        }

        ignore_user_abort(true);
        @set_time_limit(180);
        $message = '';
        try {
            $publisher = new NewsPublisher();
            $postId = $publisher->publishItem($id, true);
            if (method_exists($publisher, 'lastMessage')) {
                $message = (string) $publisher->lastMessage();
            }
        } catch (\Throwable $e) {
            Log::error('admin', 'manual publish failed: ' . $e->getMessage(), ['item' => $id, 'exception' => $e]);
            $postId = null;
            $message = 'Beklenmeyen hata: ' . $e->getMessage();
        }

        $post = $postId !== null ? Database::fetchOne('SELECT id, slug, title, status FROM posts WHERE id = ?', [$postId]) : null;
        Session::set(self::RESULT_KEY, [
            'ok' => $post !== null,
            'item_id' => $id,
            'item_title' => (string) $item['title'],
            'message' => $message !== '' ? $message : ($post !== null ? 'Haber yayınlandı.' : 'Haber yayınlanamadı.'),
            'post' => $post,
        ]);
        Log::info('admin', 'manual publish', ['item' => $id, 'post' => $postId, 'message' => $message, 'user' => Auth::user()['id'] ?? null]);
        return AdminPage::back($request, '/admin/haber-kuyrugu');
    }

    public function skip(Request $request): Response
    {
        if ($redirect = Auth::require()) {
            return $redirect;
        }
        Csrf::check($request);
        $id = (int) $request->param('id');
        $item = Database::fetchOne('SELECT id, title, status FROM news_items WHERE id = ?', [$id]);
        if ($item === null) {
            Session::flash('error', 'Kuyruk kaydı bulunamadı.');
        } elseif ($item['status'] === 'published') {
            Session::flash('error', 'Yayınlanmış bir kayıt atlanamaz.');
        } elseif ($item['status'] === 'skipped') {
            Session::flash('info', 'Bu kayıt zaten atlanmış.');
        } else {
            Database::update('news_items', ['status' => 'skipped'], 'id = ?', [$id]);
            Log::info('admin', 'queue item skipped', ['item' => $id, 'user' => Auth::user()['id'] ?? null]);
            Session::flash('success', '#' . $id . ' atlandı; otomatik yayına alınmayacak.');
        }
        return AdminPage::back($request, '/admin/haber-kuyrugu');
    }

    /** Primary item id from a note 'cluster:<id>[; …]' (null for primaries). */
    public static function clusterOf(?string $note): ?int
    {
        if ($note !== null && preg_match('/(?:^|\s|;)cluster:(\d+)/', $note, $m) === 1) {
            return (int) $m[1];
        }
        return null;
    }

    /** Note text without the cluster reference ('cluster:12; low-uniqueness (31%)' => 'low-uniqueness (31%)'). */
    public static function noteText(?string $note): string
    {
        return trim((string) preg_replace('/(?:^|;)\s*cluster:\d+\s*;?/', '', (string) $note), " ;");
    }

    /** @return array<int, int> primary item id => number of other items in its story cluster */
    private static function clusterSizes(): array
    {
        $sizes = [];
        foreach (Database::fetchAll('SELECT note, COUNT(*) AS n FROM news_items WHERE note LIKE ? GROUP BY note', ['cluster:%']) as $row) {
            $primary = self::clusterOf((string) $row['note']);
            if ($primary !== null) {
                $sizes[$primary] = ($sizes[$primary] ?? 0) + (int) $row['n'];
            }
        }
        return $sizes;
    }

    /** @return array<string, string> source key => display name (sources seen in the queue + registry) */
    private static function sourceOptions(): array
    {
        $options = [];
        if (class_exists(SourceRegistry::class)) {
            try {
                $defined = method_exists(SourceRegistry::class, 'defined') ? SourceRegistry::defined() : SourceRegistry::all();
                foreach ($defined as $key => $source) {
                    $options[(string) $key] = (string) ($source['name'] ?? $key);
                }
            } catch (\Throwable) {
                // registry unavailable: queue values only
            }
        }
        foreach (Database::fetchAll('SELECT source, MAX(source_name) AS name FROM news_items GROUP BY source') as $row) {
            $key = (string) $row['source'];
            $options[$key] ??= (string) ($row['name'] ?: $key);
        }
        asort($options, SORT_NATURAL | SORT_FLAG_CASE);
        return $options;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return array<int, array{id: int, slug: string, title: string, status: string}>
     */
    private static function postsFor(array $items): array
    {
        $ids = array_values(array_unique(array_filter(array_map(static fn (array $i): int => (int) ($i['post_id'] ?? 0), $items))));
        if ($ids === []) {
            return [];
        }
        $out = [];
        $rows = Database::fetchAll('SELECT id, slug, title, status FROM posts WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids);
        foreach ($rows as $row) {
            $out[(int) $row['id']] = $row;
        }
        return $out;
    }
}
