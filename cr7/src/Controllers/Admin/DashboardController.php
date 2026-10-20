<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Placeholder;
use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;

/**
 * Admin dashboard.
 * Stub created by the foundation ("Bu bölüm hazırlanıyor"); replaced by the admin module.
 */
final class DashboardController
{
    public function index(Request $request): Response
    {
        if ($redirect = Auth::require()) {
            return $redirect;
        }
        return Placeholder::admin('Panel');
    }
}
