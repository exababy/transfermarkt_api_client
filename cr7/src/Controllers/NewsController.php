<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;

/**
 * News list, article and tag pages.
 * Stub created by the foundation ("Bu bölüm hazırlanıyor"); replaced by the news module.
 */
final class NewsController
{
    public function index(Request $request): Response
    {
        return Placeholder::page('Haberler', 'haberler');
    }

    public function show(Request $request): Response
    {
        return Placeholder::page('Haber', 'haberler', [['name' => 'Haberler', 'url' => '/haberler'], ['name' => 'Haber']]);
    }

    public function tag(Request $request): Response
    {
        return Placeholder::page('Etiket', 'haberler', [['name' => 'Haberler', 'url' => '/haberler'], ['name' => 'Etiket']]);
    }
}
