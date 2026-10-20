<?php

declare(strict_types=1);

namespace App\Cron\Tasks;

use App\Cron\Task;

/**
 * Publishes spun news posts within the daily limit, interval and window (news module).
 * Stub created by the foundation; replaced by the owning module.
 */
final class NewsPublishTask implements Task
{
    public function name(): string
    {
        return 'news-publish';
    }

    public function intervalMinutes(): int
    {
        return 10;
    }

    public function run(): string
    {
        return 'not implemented';
    }
}
