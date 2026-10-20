<?php

declare(strict_types=1);

namespace App\Install;

/**
 * Data seeder. Every class in src/Install/Seeders/ implementing this interface is run by
 * the installer on each install (so run() MUST be idempotent, e.g. via Database::upsert).
 */
interface Seeder
{
    /** Seed data and return a short human-readable status line. */
    public function run(): string;
}
