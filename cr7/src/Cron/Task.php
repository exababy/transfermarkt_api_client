<?php

declare(strict_types=1);

namespace App\Cron;

/**
 * A scheduled task run by App\Cron\Scheduler.
 */
interface Task
{
    /** Registry key, e.g. 'news-fetch'. */
    public function name(): string;

    /** Minimum minutes between two runs. */
    public function intervalMinutes(): int;

    /** Do the work; return a short status message. Throw on failure (logged, last_status = 'error'). */
    public function run(): string;
}
