<?php

declare(strict_types=1);

namespace App\Cron\Tasks;

use App\Cron\Task;
use App\Services\Video\VideoSync;

/**
 * Imports new Cristiano Ronaldo videos from official YouTube channels (RSS) and,
 * when `youtube.api_key` is set, from YouTube Data API search (every 3 hours).
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

    /** Throws when no channel feed could be fetched at all (Scheduler records 'error'). */
    public function run(): string
    {
        return VideoSync::summary((new VideoSync())->run());
    }
}
