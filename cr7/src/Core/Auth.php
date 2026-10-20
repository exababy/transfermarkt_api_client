<?php

declare(strict_types=1);

namespace App\Core;

use App\Support\Date;

/**
 * Admin authentication: password_hash/verify, session regeneration on login,
 * idle timeout and IP throttling (max 5 failed attempts per 15 minutes).
 */
final class Auth
{
    public const MAX_ATTEMPTS = 5;
    public const WINDOW_MINUTES = 15;

    private const SESSION_USER = 'auth.user_id';
    private const SESSION_SEEN = 'auth.last_seen';
    private const SESSION_INTENDED = 'auth.intended';
    /** Hash of a random string: verified against when the username is unknown (constant-ish timing). */
    private const DUMMY_HASH = '$2y$10$L3j7MmOf/yrYRpIWsJ0P5erMPfQ9MTEUNm5ig4T4IAUb.bDZe0FOq';

    private static ?array $user = null;
    private static bool $resolved = false;

    public static function attempt(string $username, string $password, string $ip): bool
    {
        if (self::tooManyAttempts($ip)) {
            return false;
        }
        $username = trim($username);
        $row = $username === '' ? null : Database::fetchOne('SELECT id, password_hash FROM users WHERE username = ?', [$username]);
        $valid = password_verify($password, (string) ($row['password_hash'] ?? self::DUMMY_HASH)) && $row !== null;
        if (!$valid) {
            Database::insert('login_attempts', [
                'ip' => $ip,
                'username' => mb_substr($username, 0, 50),
                'attempted_at' => Date::now(),
            ]);
            Database::execute('DELETE FROM login_attempts WHERE attempted_at < ?', [Date::ago(86400)]);
            return false;
        }

        $update = ['last_login_at' => Date::now()];
        if (password_needs_rehash((string) $row['password_hash'], PASSWORD_DEFAULT)) {
            $update['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        }
        Database::update('users', $update, 'id = ?', [(int) $row['id']]);
        Database::execute('DELETE FROM login_attempts WHERE ip = ?', [$ip]);

        Session::regenerate(); // session fixation protection
        Session::set(self::SESSION_USER, (int) $row['id']);
        Session::set(self::SESSION_SEEN, time());
        Csrf::regenerate();
        self::$user = null;
        self::$resolved = false;
        return true;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    /** @return array{id: int, username: string, created_at: string, last_login_at: ?string}|null */
    public static function user(): ?array
    {
        if (self::$resolved) {
            return self::$user;
        }
        self::$resolved = true;
        self::$user = null;
        if (!Session::active() && !Session::hasCookie()) {
            return null; // anonymous visitor: do not create a session
        }
        $id = Session::get(self::SESSION_USER);
        if (!is_int($id)) {
            return null;
        }
        $idle = max(5, (int) Config::get('app.session_idle_minutes', 240)) * 60;
        if (time() - (int) Session::get(self::SESSION_SEEN, 0) > $idle) {
            self::logout();
            return null;
        }
        $row = Database::fetchOne('SELECT id, username, created_at, last_login_at FROM users WHERE id = ?', [$id]);
        if ($row === null) {
            self::logout();
            return null;
        }
        Session::set(self::SESSION_SEEN, time());
        $row['id'] = (int) $row['id'];
        return self::$user = $row;
    }

    public static function logout(): void
    {
        Session::invalidate();
        Csrf::regenerate();
        self::$user = null;
        self::$resolved = true;
    }

    /** null when logged in, otherwise a redirect to /admin/giris (remembers the requested admin page). */
    public static function require(): ?Response
    {
        if (self::check()) {
            return null;
        }
        $request = App::request();
        if ($request !== null && $request->method() === 'GET' && str_starts_with($request->path(), '/admin')) {
            Session::set(self::SESSION_INTENDED, $request->path());
        }
        return Response::redirect(url('/admin/giris'));
    }

    /** Admin path remembered by require() (for the post-login redirect). */
    public static function pullIntended(string $default = '/admin'): string
    {
        $path = Session::pull(self::SESSION_INTENDED);
        return is_string($path) && str_starts_with($path, '/admin') && !str_starts_with($path, '/admin/giris') ? $path : $default;
    }

    public static function tooManyAttempts(string $ip): bool
    {
        $count = (int) Database::fetchValue(
            'SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND attempted_at >= ?',
            [$ip, Date::ago(self::WINDOW_MINUTES * 60)]
        );
        return $count >= self::MAX_ATTEMPTS;
    }

    /** Reset cached user (tests). */
    public static function reset(): void
    {
        self::$user = null;
        self::$resolved = false;
    }
}
