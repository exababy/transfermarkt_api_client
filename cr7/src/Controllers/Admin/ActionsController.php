<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Cron\Scheduler;
use App\Support\Cache;
use App\Support\Log;

/**
 * POST /admin/islem/{task}: run a scheduler task now (tm-sync, news-fetch, news-publish,
 * videos-sync => Scheduler::run($task, true)) or clear the file cache (cache-clear).
 */
final class ActionsController
{
    public const ACTIONS = [
        'tm-sync' => 'Transfermarkt senkronizasyonu',
        'news-fetch' => 'Haber toplama',
        'news-publish' => 'Haber yayınlama',
        'videos-sync' => 'Video senkronizasyonu',
        'shop-expire' => 'Ödenmemiş siparişleri iptal (stok iadesi)',
        'cache-clear' => 'Önbellek temizleme',
    ];

    public function run(Request $request): Response
    {
        if ($redirect = Auth::require()) {
            return $redirect;
        }
        Csrf::check($request);
        $task = (string) $request->param('task', '');
        $label = self::ACTIONS[$task] ?? null;
        if ($label === null) {
            Session::flash('error', 'Bilinmeyen işlem: ' . $task);
            return AdminPage::back($request, '/admin');
        }

        $user = Auth::user()['id'] ?? null;
        if ($task === 'cache-clear') {
            Cache::flush();
            Log::info('admin', 'cache cleared', ['user' => $user]);
            Session::flash('success', 'Önbellek temizlendi.');
            return AdminPage::back($request, '/admin');
        }

        ignore_user_abort(true);
        @set_time_limit(600);
        try {
            $results = Scheduler::run($task, true);
        } catch (\Throwable $e) {
            Log::error('admin', "manual task {$task} failed: " . $e->getMessage(), ['exception' => $e]);
            $results = [['task' => $task, 'status' => 'error', 'message' => $e->getMessage(), 'duration_ms' => 0]];
        }
        foreach ($results as $result) {
            [$type, $prefix] = match ($result['status']) {
                'ok' => ['success', 'tamamlandı'],
                'locked' => ['warning', 'şu an çalıştırılamadı'],
                'skipped' => ['info', 'atlandı'],
                default => ['error', 'hata verdi'],
            };
            $seconds = number_format(((int) ($result['duration_ms'] ?? 0)) / 1000, 1, ',', '');
            Session::flash($type, sprintf('%s %s (%s sn): %s', self::ACTIONS[$result['task']] ?? $result['task'], $prefix, $seconds, mb_substr((string) $result['message'], 0, 400)));
        }
        Log::info('admin', 'manual task run', ['task' => $task, 'status' => $results[0]['status'] ?? null, 'user' => $user]);
        return AdminPage::back($request, '/admin');
    }
}
