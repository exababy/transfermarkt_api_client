<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Placeholder;
use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;

/**
 * Manual task runs (tm-sync, news-fetch, news-publish, videos-sync, cache-clear).
 * Stub created by the foundation ("Bu bölüm hazırlanıyor"); replaced by the admin module.
 */
final class ActionsController
{
    public function run(Request $request): Response
    {
        if ($redirect = Auth::require()) {
            return $redirect;
        }
        return Placeholder::admin('İşlemler');
    }
}
