<?php

declare(strict_types=1);

namespace App\Cron\Tasks;

use App\Cron\Task;

/**
 * Fetches Cristiano Ronaldo news candidates from the configured sources (news module).
 * Stub created by the foundation; replaced by the owning module.
 */
final class NewsFetchTask implements Task
{
    public function name(): string
    {
        return 'news-fetch';
    }

    public function intervalMinutes(): int
    {
        return 30;
    }

    public function run(): string
    {
        return 'not implemented';
    }
}
