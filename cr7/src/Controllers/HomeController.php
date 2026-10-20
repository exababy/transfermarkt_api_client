<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;

/**
 * Home page.
 * Stub created by the foundation ("Bu bölüm hazırlanıyor"); replaced by the site module.
 */
final class HomeController
{
    public function index(Request $request): Response
    {
        return Placeholder::page('Cristiano Ronaldo Hayran Sitesi', 'home', []);
    }
}
