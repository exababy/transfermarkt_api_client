<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;

/**
 * Site search (/arama).
 * Stub created by the foundation ("Bu bölüm hazırlanıyor"); replaced by the site module.
 */
final class SearchController
{
    public function index(Request $request): Response
    {
        return Placeholder::page('Arama', '');
    }
}
