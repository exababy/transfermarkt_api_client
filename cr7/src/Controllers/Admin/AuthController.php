<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Placeholder;
use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;

/**
 * Admin login/logout and password change.
 * Stub created by the foundation ("Bu bölüm hazırlanıyor"); replaced by the admin module.
 */
final class AuthController
{
    public function showLogin(Request $request): Response
    {
        return Placeholder::admin('Yönetici Girişi', bare: true);
    }

    public function login(Request $request): Response
    {
        return Placeholder::admin('Yönetici Girişi', bare: true);
    }

    public function logout(Request $request): Response
    {
        return Placeholder::admin('Çıkış', bare: true);
    }

    public function password(Request $request): Response
    {
        if ($redirect = Auth::require()) {
            return $redirect;
        }
        return Placeholder::admin('Şifre Değiştir');
    }

    public function updatePassword(Request $request): Response
    {
        if ($redirect = Auth::require()) {
            return $redirect;
        }
        return Placeholder::admin('Şifre Değiştir');
    }
}
