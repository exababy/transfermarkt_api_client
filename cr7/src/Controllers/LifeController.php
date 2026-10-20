<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;

/**
 * Biography page (/hayati).
 * Stub created by the foundation ("Bu bölüm hazırlanıyor"); replaced by the site module.
 */
final class LifeController
{
    public function index(Request $request): Response
    {
        return Placeholder::page('Cristiano Ronaldo’nun Hayatı', 'hayati');
    }
}
