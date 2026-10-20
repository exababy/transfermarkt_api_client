<?php

declare(strict_types=1);

namespace App\Cron\Tasks;

use App\Cron\Task;

/**
 * Syncs player data and statistics from the Transfermarkt API (stats module).
 * Stub created by the foundation; replaced by the owning module.
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
        return 'not implemented';
    }
}
