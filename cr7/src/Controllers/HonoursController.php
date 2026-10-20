<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;

/**
 * Trophies and awards (/kupalar-ve-oduller).
 * Stub created by the foundation ("Bu bölüm hazırlanıyor"); replaced by the site module.
 */
final class HonoursController
{
    public function index(Request $request): Response
    {
        return Placeholder::page('Kupalar ve Ödüller', 'kupalar');
    }
}
