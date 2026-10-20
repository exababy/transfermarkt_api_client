<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Support\Log;

/**
 * Admin login/logout (/admin/giris, /admin/cikis) and password change (/admin/sifre).
 *
 * Login errors are deliberately generic; Auth::attempt() throttles by IP (5 failures / 15 min),
 * regenerates the session id and the CSRF token on success.
 */
final class AuthController
{
    public const MIN_PASSWORD_LENGTH = 10;

    public function showLogin(Request $request): Response
    {
        if (Auth::check()) {
            return Response::redirect(url('/admin'));
        }
        return $this->loginPage('', Auth::tooManyAttempts($request->ip()) ? self::throttleMessage() : null);
    }

    public function login(Request $request): Response
    {
        $username = mb_substr(AdminPage::input($request, 'username'), 0, 50);
        if (!AdminPage::csrfValid($request)) {
            // Expired form (e.g. session timed out): show the form again with a fresh token.
            return $this->loginPage($username, 'Oturum süreniz doldu. Lütfen tekrar giriş yapın.', 419);
        }
        $ip = $request->ip();
        if (Auth::tooManyAttempts($ip)) {
            return $this->loginPage($username, self::throttleMessage(), 429);
        }
        $password = $request->input('password', '');
        if ($username === '' || !is_string($password) || $password === '') {
            return $this->loginPage($username, 'Kullanıcı adı ve şifre gereklidir.', 422);
        }
        if (!Auth::attempt($username, $password, $ip)) {
            Log::info('admin', 'failed login', ['ip' => $ip, 'username' => $username]);
            if (Auth::tooManyAttempts($ip)) {
                return $this->loginPage($username, self::throttleMessage(), 429);
            }
            return $this->loginPage($username, 'Kullanıcı adı veya şifre hatalı.', 422);
        }
        Log::info('admin', 'login', ['ip' => $ip, 'username' => $username]);
        $target = Auth::pullIntended('/admin');
        Session::flash('success', 'Hoş geldiniz, ' . $username . '.');
        return Response::redirect(url($target));
    }

    public function logout(Request $request): Response
    {
        if (!Auth::check()) {
            return Response::redirect(url('/admin/giris'));
        }
        Csrf::check($request);
        Auth::logout();
        Session::flash('success', 'Güvenli bir şekilde çıkış yaptınız.');
        return Response::redirect(url('/admin/giris'));
    }

    public function password(Request $request): Response
    {
        if ($redirect = Auth::require()) {
            return $redirect;
        }
        return $this->passwordPage([]);
    }

    public function updatePassword(Request $request): Response
    {
        if ($redirect = Auth::require()) {
            return $redirect;
        }
        Csrf::check($request);
        $user = Auth::user();
        $ip = $request->ip();
        $attempt = Auth::reserveAttempt($ip, (string) $user['username']); // counted before the check (no race)
        if ($attempt === null) {
            return $this->passwordPage(['current_password' => self::throttleMessage()], 429);
        }

        $current = $request->input('current_password', '');
        $new = $request->input('new_password', '');
        $confirm = $request->input('new_password_confirmation', '');
        $current = is_string($current) ? $current : '';
        $new = is_string($new) ? $new : '';
        $confirm = is_string($confirm) ? $confirm : '';

        $errors = [];
        $hash = (string) Database::fetchValue('SELECT password_hash FROM users WHERE id = ?', [(int) $user['id']]);
        if ($current === '' || !password_verify($current, $hash)) {
            $errors['current_password'] = 'Mevcut şifre hatalı.'; // the reserved attempt stays as a failure
        } else {
            Auth::releaseAttempt($attempt);
        }
        if (mb_strlen($new) < self::MIN_PASSWORD_LENGTH) {
            $errors['new_password'] = 'Yeni şifre en az ' . self::MIN_PASSWORD_LENGTH . ' karakter olmalıdır.';
        } elseif (mb_strlen($new) > 200) {
            $errors['new_password'] = 'Yeni şifre en fazla 200 karakter olabilir.';
        } elseif ($current !== '' && hash_equals($current, $new)) {
            $errors['new_password'] = 'Yeni şifre mevcut şifreden farklı olmalıdır.';
        }
        if (!isset($errors['new_password']) && !hash_equals($new, $confirm)) {
            $errors['new_password_confirmation'] = 'Şifre tekrarı yeni şifreyle eşleşmiyor.';
        }
        if ($errors !== []) {
            return $this->passwordPage($errors, 422);
        }

        $newHash = password_hash($new, PASSWORD_DEFAULT);
        Database::update('users', ['password_hash' => $newHash], 'id = ?', [(int) $user['id']]);
        Auth::clearAttempts($ip);
        Session::regenerate();
        Auth::rememberPassword($newHash); // keeps this session; every other session of the user is logged out
        Csrf::regenerate();
        Log::info('admin', 'password changed', ['user' => (int) $user['id'], 'ip' => $ip]);
        Session::flash('success', 'Şifreniz güncellendi.');
        return Response::redirect(url('/admin/sifre'));
    }

    private function loginPage(string $username, ?string $error, int $status = 200): Response
    {
        return AdminPage::render('login', 'Yönetici Girişi', [
            'bare' => true,
            'username' => $username,
            'error' => $error,
        ], $status);
    }

    /** @param array<string, string> $errors */
    private function passwordPage(array $errors, int $status = 200): Response
    {
        return AdminPage::render('password', 'Şifre Değiştir', [
            'errors' => $errors,
            'minLength' => self::MIN_PASSWORD_LENGTH,
            'user' => Auth::user(),
        ], $status, [['name' => 'Şifre Değiştir']]);
    }

    private static function throttleMessage(): string
    {
        return 'Çok fazla başarısız deneme yapıldı. Güvenliğiniz için lütfen ' . Auth::WINDOW_MINUTES . ' dakika sonra tekrar deneyin.';
    }
}
