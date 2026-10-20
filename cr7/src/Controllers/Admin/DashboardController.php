<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;
use App\Cron\Scheduler;
use App\Repositories\OrderRepository;
use App\Repositories\StatsRepository;
use App\Support\Date;
use App\Support\Log;

/**
 * Admin dashboard: content tiles, cron status with "Şimdi çalıştır" buttons, last
 * Transfermarkt sync, recent log lines and quick links.
 */
final class DashboardController
{
    /** Turkish labels of the scheduler tasks (+ the cache action). */
    public const TASK_LABELS = [
        'tm-sync' => 'Transfermarkt senkronizasyonu',
        'news-fetch' => 'Haber toplama',
        'news-publish' => 'Haber yayınlama',
        'videos-sync' => 'Video senkronizasyonu',
    ];

    /** Fallback intervals (minutes) when a task class cannot be instantiated. */
    private const DEFAULT_INTERVALS = ['tm-sync' => 360, 'news-fetch' => 30, 'news-publish' => 10, 'videos-sync' => 180];

    public function index(Request $request): Response
    {
        if ($redirect = Auth::require()) {
            return $redirect;
        }

        return AdminPage::render('dashboard', 'Panel', [
            'tiles' => $this->tiles(),
            'tasks' => self::tasks(),
            'lastTmSync' => $this->lastTmSync(),
            'logs' => Log::tail(50),
            'back' => AdminPage::currentPath($request),
        ]);
    }

    /**
     * Scheduler tasks with their last run (cron_runs) and interval.
     *
     * @return list<array{name: string, label: string, interval: int, started: ?string, finished: ?string, status: ?string, message: string, next: ?string, stale: bool}>
     */
    public static function tasks(): array
    {
        $status = Scheduler::status();
        $out = [];
        foreach (Scheduler::tasks() as $name => $class) {
            $interval = self::DEFAULT_INTERVALS[$name] ?? 60;
            try {
                $interval = (new $class())->intervalMinutes();
            } catch (\Throwable) {
                // keep the default
            }
            $row = $status[$name] ?? [];
            $started = isset($row['last_started_at']) && $row['last_started_at'] !== '' ? (string) $row['last_started_at'] : null;
            $next = $started !== null ? gmdate('Y-m-d H:i:s', (int) strtotime($started . ' UTC') + $interval * 60) : null;
            $state = isset($row['last_status']) ? (string) $row['last_status'] : null;
            $out[] = [
                'name' => $name,
                'label' => self::TASK_LABELS[$name] ?? $name,
                'interval' => $interval,
                'started' => $started,
                'finished' => isset($row['last_finished_at']) && $row['last_finished_at'] !== '' ? (string) $row['last_finished_at'] : null,
                'status' => $state,
                'message' => (string) ($row['last_message'] ?? ''),
                'next' => $next,
                // "running" for more than 30 minutes: the process most likely died.
                'stale' => $state === 'running' && $started !== null && strtotime($started . ' UTC') < time() - 1800,
            ];
        }
        return $out;
    }

    /** @return array<string, array{value: string, label: string, meta?: string, url?: string, tone?: string, progress?: int}> */
    private function tiles(): array
    {
        [$from, $to] = self::todayBoundsUtc();
        $now = Date::now();
        $limit = (int) Settings::get('news.daily_limit', Config::get('news.daily_limit', 10));
        $today = self::count('SELECT COUNT(*) FROM posts WHERE status = ? AND published_at >= ? AND published_at < ? AND published_at <= ?', ['published', $from, $to, $now]);
        $todayAuto = self::count('SELECT COUNT(*) FROM posts WHERE is_spun = 1 AND news_item_id IS NOT NULL AND published_at >= ? AND published_at < ?', [$from, $to]);
        $published = self::count('SELECT COUNT(*) FROM posts WHERE status = ?', ['published']);
        $drafts = self::count('SELECT COUNT(*) FROM posts WHERE status = ?', ['draft']);
        $pending = self::count('SELECT COUNT(*) FROM news_items WHERE status = ?', ['pending']);
        $failed = self::count('SELECT COUNT(*) FROM news_items WHERE status = ?', ['failed']);
        $videos = self::count('SELECT COUNT(*) FROM videos');
        $featured = self::count('SELECT COUNT(*) FROM videos WHERE is_featured = 1');

        $tiles = [
            'today' => [
                'value' => $todayAuto . ' / ' . $limit,
                'label' => 'Günlük limit',
                'meta' => 'Bugün ' . $todayAuto . ' otomatik, toplam ' . $today . ' haber',
                'url' => '/admin/haberler',
                'tone' => $limit > 0 && $todayAuto >= $limit ? 'warning' : 'gold',
                'progress' => $limit > 0 ? (int) min(100, round($todayAuto / $limit * 100)) : 0,
            ],
            'posts' => [
                'value' => fmt_number($published + $drafts),
                'label' => 'Toplam haber',
                'meta' => fmt_number($published) . ' yayında · ' . fmt_number($drafts) . ' taslak',
                'url' => '/admin/haberler',
            ],
            'pending' => [
                'value' => fmt_number($pending),
                'label' => 'Kuyrukta bekleyen',
                'meta' => 'Yayın sırası bekleyen kaynak haber',
                'url' => '/admin/haber-kuyrugu?durum=pending',
                'tone' => 'info',
            ],
            'failed' => [
                'value' => fmt_number($failed),
                'label' => 'Başarısız',
                'meta' => $failed > 0 ? 'İncelemeniz gereken kayıtlar' : 'Sorunlu kayıt yok',
                'url' => '/admin/haber-kuyrugu?durum=failed',
                'tone' => $failed > 0 ? 'danger' : '',
            ],
            'videos' => [
                'value' => fmt_number($videos),
                'label' => 'Video',
                'meta' => fmt_number($featured) . ' öne çıkan',
                'url' => '/admin/videolar',
            ],
        ];

        $orders = self::newOrders();
        if ($orders !== null) {
            $tiles['orders'] = [
                'value' => fmt_number($orders),
                'label' => 'Yeni sipariş',
                'meta' => $orders > 0 ? 'Onay bekleyen siparişler' : 'Bekleyen sipariş yok',
                'url' => '/admin/siparisler',
                'tone' => $orders > 0 ? 'success' : '',
            ];
        }
        return $tiles;
    }

    /** New orders via the shop module's OrderRepository::countByStatus() (null when the module is missing). */
    private static function newOrders(): ?int
    {
        if (!class_exists(OrderRepository::class) || !method_exists(OrderRepository::class, 'countByStatus')) {
            return null;
        }
        try {
            $counts = (new OrderRepository())->countByStatus();
            return (int) ($counts['yeni'] ?? 0);
        } catch (\Throwable $e) {
            Log::error('admin', 'order counts failed: ' . $e->getMessage());
            return null;
        }
    }

    /** UTC time of the last successful Transfermarkt sync, or null. */
    private function lastTmSync(): ?string
    {
        if (class_exists(StatsRepository::class) && method_exists(StatsRepository::class, 'lastSyncedAt')) {
            try {
                $value = (new StatsRepository())->lastSyncedAt();
                if (is_string($value) && $value !== '') {
                    return $value;
                }
            } catch (\Throwable) {
                // fall back to cron_runs
            }
        }
        $row = Scheduler::status()['tm-sync'] ?? null;
        return $row !== null && ($row['last_status'] ?? '') === 'ok' && !empty($row['last_finished_at']) ? (string) $row['last_finished_at'] : null;
    }

    /** [start, end) of today's Europe/Istanbul calendar day in UTC. */
    public static function todayBoundsUtc(): array
    {
        $start = Date::local()->setTime(0, 0, 0);
        $utc = new \DateTimeZone('UTC');
        return [$start->setTimezone($utc)->format('Y-m-d H:i:s'), $start->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s')];
    }

    private static function count(string $sql, array $params = []): int
    {
        try {
            return (int) Database::fetchValue($sql, $params);
        } catch (\Throwable) {
            return 0;
        }
    }
}
