<?php

declare(strict_types=1);

namespace App\Cron\Tasks;

use App\Cron\Task;
use App\Services\Transfermarkt\TransfermarktSync;

/**
 * Syncs Cristiano Ronaldo's profile and statistics from the Transfermarkt API
 * (player, seasons, clubs, competitions, games, transfers, market values, national
 * teams, injuries + club/competition names and logos). Runs every 6 hours.
 */
final class TransfermarktSyncTask implements Task
{
    public function name(): string
    {
        return 'tm-sync';
    }

    public function intervalMinutes(): int
    {
        return 360;
    }

    public function run(): string
    {
        return (new TransfermarktSync())->run();
    }
}
