<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;

/**
 * Statistics overview and match list.
 * Stub created by the foundation ("Bu bölüm hazırlanıyor"); replaced by the stats module.
 */
final class StatsController
{
    public function index(Request $request): Response
    {
        return Placeholder::page('İstatistikler', 'istatistikler');
    }

    public function matches(Request $request): Response
    {
        return Placeholder::page('Maçlar', 'istatistikler', [['name' => 'İstatistikler', 'url' => '/istatistikler'], ['name' => 'Maçlar']]);
    }
}
