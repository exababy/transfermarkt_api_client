<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Placeholder;
use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;

/**
 * News posts management.
 * Stub created by the foundation ("Bu bölüm hazırlanıyor"); replaced by the admin module.
 */
final class PostsController
{
    public function index(Request $request): Response
    {
        if ($redirect = Auth::require()) {
            return $redirect;
        }
        return Placeholder::admin('Haberler');
    }

    public function create(Request $request): Response
    {
        if ($redirect = Auth::require()) {
            return $redirect;
        }
        return Placeholder::admin('Yeni Haber');
    }

    public function store(Request $request): Response
    {
        if ($redirect = Auth::require()) {
            return $redirect;
        }
        return Placeholder::admin('Yeni Haber');
    }

    public function edit(Request $request): Response
    {
        if ($redirect = Auth::require()) {
            return $redirect;
        }
        return Placeholder::admin('Haberi Düzenle');
    }

    public function update(Request $request): Response
    {
        if ($redirect = Auth::require()) {
            return $redirect;
        }
        return Placeholder::admin('Haberi Düzenle');
    }

    public function delete(Request $request): Response
    {
        if ($redirect = Auth::require()) {
            return $redirect;
        }
        return Placeholder::admin('Haberi Sil');
    }
}
