<?php

declare(strict_types=1);

namespace App\Cron;

use App\Core\App;
use App\Core\Database;
use App\Cron\Tasks\NewsFetchTask;
use App\Cron\Tasks\NewsPublishTask;
use App\Cron\Tasks\TransfermarktSyncTask;
use App\Cron\Tasks\VideoSyncTask;
use App\Support\Date;
use App\Support\Log;

/**
 * Runs due tasks under a non-blocking file lock (storage/locks/cron.lock) and
 * records every run in `cron_runs`. Entry points: bin/cron.php, GET /cron/{token},
 * admin "islem" actions (Scheduler::run('tm-sync', true)).
 */
final class Scheduler
{
    /** Tolerance so a 10-minute task is not skipped by a cron tick a few seconds early. */
    private const GRACE_SECONDS = 60;

    /** @return array<string, class-string<Task>> task name => class */
    public static function tasks(): array
    {
        return [
            'tm-sync' => TransfermarktSyncTask::class,
            'news-fetch' => NewsFetchTask::class,
            'news-publish' => NewsPublishTask::class,
            'videos-sync' => VideoSyncTask::class,
        ];
    }

    /**
     * Run due tasks ($only = task name, or null/'all' for every task); $force ignores intervals.
     *
     * @return list<array{task: string, status: string, message: string, duration_ms: int}>
     *         status: ok | error | skipped | locked
     */
    public static function run(?string $only = null, bool $force = false): array
    {
        $only = ($only === null || $only === '' || $only === 'all') ? null : $only;
        $tasks = self::tasks();
        if ($only !== null && !isset($tasks[$only])) {
            return [self::result($only, 'error', 'Bilinmeyen görev. Geçerli görevler: ' . implode(', ', array_keys($tasks)))];
        }

        $lockFile = App::basePath('storage/locks/cron.lock');
        if (!is_dir(dirname($lockFile))) {
            @mkdir(dirname($lockFile), 0775, true);
        }
        $handle = @fopen($lockFile, 'c');
        if ($handle === false) {
            return [self::result($only ?? 'all', 'error', 'Kilit dosyası açılamadı: storage/locks/cron.lock')];
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return [self::result($only ?? 'all', 'locked', 'Başka bir zamanlanmış görev hâlâ çalışıyor.')];
        }

        try {
            $results = [];
            foreach ($tasks as $name => $class) {
                if ($only !== null && $name !== $only) {
                    continue;
                }
                $task = new $class();
                if (!$force && !self::isDue($name, $task->intervalMinutes())) {
                    $results[] = self::result($name, 'skipped', 'Henüz zamanı gelmedi.');
                    continue;
                }
                $results[] = self::runTask($name, $task);
            }
            return $results;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @return array<string, array<string, mixed>> cron_runs rows keyed by task (for the admin dashboard) */
    public static function status(): array
    {
        $rows = [];
        try {
            foreach (Database::fetchAll('SELECT * FROM cron_runs ORDER BY task') as $row) {
                $rows[(string) $row['task']] = $row;
            }
        } catch (\Throwable) {
            // not installed yet
        }
        return $rows;
    }

    private static function isDue(string $name, int $intervalMinutes): bool
    {
        $last = Database::fetchValue('SELECT last_started_at FROM cron_runs WHERE task = ?', [$name]);
        if (!is_string($last) || $last === '') {
            return true;
        }
        $lastTs = strtotime($last . ' UTC');
        return $lastTs === false || $lastTs + $intervalMinutes * 60 <= time() + self::GRACE_SECONDS;
    }

    private static function runTask(string $name, Task $task): array
    {
        $started = microtime(true);
        Database::upsert('cron_runs', [
            'task' => $name,
            'last_started_at' => Date::now(),
            'last_status' => 'running',
        ], ['task']);

        try {
            $message = $task->run();
            $status = 'ok';
            Log::info('cron', "{$name}: {$message}");
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            $status = 'error';
            Log::error('cron', "{$name} failed: {$message}", ['exception' => $e]);
        }

        Database::update('cron_runs', [
            'last_finished_at' => Date::now(),
            'last_status' => $status,
            'last_message' => mb_substr($message, 0, 2000),
        ], 'task = ?', [$name]);

        return self::result($name, $status, $message, (int) round((microtime(true) - $started) * 1000));
    }

    private static function result(string $task, string $status, string $message, int $durationMs = 0): array
    {
        return ['task' => $task, 'status' => $status, 'message' => $message, 'duration_ms' => $durationMs];
    }
}
