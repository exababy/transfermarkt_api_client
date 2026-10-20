<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;

/**
 * Video gallery and video detail pages.
 * Stub created by the foundation ("Bu bölüm hazırlanıyor"); replaced by the videos module.
 */
final class VideosController
{
    public function index(Request $request): Response
    {
        return Placeholder::page('Videolar', 'videolar');
    }

    public function show(Request $request): Response
    {
        return Placeholder::page('Video', 'videolar', [['name' => 'Videolar', 'url' => '/videolar'], ['name' => 'Video']]);
    }
}
