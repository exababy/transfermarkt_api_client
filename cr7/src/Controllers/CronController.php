<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;
use App\Cron\Scheduler;

/**
 * GET /cron/{token}[?task=news-fetch][&force=1] — web cron for hosts without CLI cron.
 * Disabled (404) unless cron.web_token is set (admin › Ayarlar stores it in the settings table;
 * config/config.php is the fallback); token compared in constant time.
 */
final class CronController
{
    public function run(Request $request): Response
    {
        $expected = (string) Settings::get('cron.web_token', Config::get('cron.web_token', ''));
        $given = (string) $request->param('token', '');
        if ($expected === '' || !hash_equals($expected, $given)) {
            return Response::notFound();
        }

        ignore_user_abort(true);
        @set_time_limit(300);
        $task = $request->query('task');
        $results = Scheduler::run(is_string($task) ? $task : null, $request->query('force') === '1');

        $lines = array_map(
            static fn (array $r): string => sprintf('%s %s %s (%d ms)', $r['task'], $r['status'], $r['message'], $r['duration_ms']),
            $results
        );
        return Response::text(implode("\n", $lines) . "\n")
            ->withHeader('X-Robots-Tag', 'noindex, nofollow')
            ->withHeader('Cache-Control', 'no-store');
    }
}
