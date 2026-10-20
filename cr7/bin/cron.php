<?php
/**
 * CLI cron entry point.
 *
 *   php bin/cron.php [task|all] [--force]
 *
 * Tasks: tm-sync, news-fetch, news-publish, videos-sync, shop-expire. Without --force a task runs
 * only when its interval has elapsed. Recommended crontab (every 10 minutes):
 *   * /10 * * * * /usr/bin/php /home/USER/cr7/bin/cron.php >/dev/null 2>&1   (without the space)
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Cron\Scheduler;
use App\Support\Date;

$args = array_slice($argv, 1);
if (in_array('--help', $args, true) || in_array('-h', $args, true)) {
    echo "Kullanım: php bin/cron.php [görev|all] [--force]\nGörevler: " . implode(', ', array_keys(Scheduler::tasks())) . "\n";
    exit(0);
}
$force = in_array('--force', $args, true);
$positional = array_values(array_filter($args, static fn (string $a): bool => !str_starts_with($a, '-')));

set_time_limit(0);
$results = Scheduler::run($positional[0] ?? null, $force);

foreach ($results as $r) {
    printf("[%s] %-13s %-8s %s (%d ms)\n", Date::now(), $r['task'], $r['status'], $r['message'], $r['duration_ms']);
}
exit(in_array('error', array_column($results, 'status'), true) ? 1 : 0);
