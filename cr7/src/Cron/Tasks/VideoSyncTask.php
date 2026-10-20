<?php

declare(strict_types=1);

namespace App\Cron\Tasks;

use App\Cron\Task;

/**
 * Syncs videos from YouTube RSS/API (videos module).
 * Stub created by the foundation; replaced by the owning module.
 */
final class VideoSyncTask implements Task
{
    public function name(): string
    {
        return 'videos-sync';
    }

    public function intervalMinutes(): int
    {
        return 180;
    }

    public function run(): string
    {
        return 'not implemented';
    }
}
