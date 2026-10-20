<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;

/**
 * Career page (/kariyer).
 * Stub created by the foundation ("Bu bölüm hazırlanıyor"); replaced by the stats module.
 */
final class CareerController
{
    public function index(Request $request): Response
    {
        return Placeholder::page('Kariyer', 'kariyer');
    }
}
