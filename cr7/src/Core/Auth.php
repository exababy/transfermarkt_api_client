<?php

declare(strict_types=1);

namespace App\Core;

use App\Support\Date;

/**
 * Admin authentication: password_hash/verify, session regeneration on login,
 * idle timeout, sessions bound to the current password (a change logs out other sessions)
 * and atomic IP throttling (max 5 password checks per 15 minutes per IPv4 address / IPv6 /64).
 */
final class Auth
{
    public const MAX_ATTEMPTS = 5;
    public const WINDOW_MINUTES = 15;

    private const SESSION_USER = 'auth.user_id';
    private const SESSION_SEEN = 'auth.last_seen';
    private const SESSION_INTENDED = 'auth.intended';
    /** Fingerprint of the password hash the session logged in with (see user()). */
    private const SESSION_PASSWORD = 'auth.password';
    /** Hash of a random string: verified against when the username is unknown (constant-ish timing). */
    private const DUMMY_HASH = '$2y$10$L3j7MmOf/yrYRpIWsJ0P5erMPfQ9MTEUNm5ig4T4IAUb.bDZe0FOq';

    private static ?array $user = null;
    private static bool $resolved = false;

    public static function attempt(string $username, string $password, string $ip): bool
    {
        $username = trim($username);
        if (self::reserveAttempt($ip, $username) === null) {
            return false;
        }
        $row = $username === '' ? null : Database::fetchOne('SELECT id, password_hash FROM users WHERE username = ?', [$username]);
        $valid = password_verify($password, (string) ($row['password_hash'] ?? self::DUMMY_HASH)) && $row !== null;
        if (!$valid) {
            // The reserved row stays as the failed attempt.
            Database::execute('DELETE FROM login_attempts WHERE attempted_at < ?', [Date::ago(86400)]);
            return false;
        }

        $update = ['last_login_at' => Date::now()];
        if (password_needs_rehash((string) $row['password_hash'], PASSWORD_DEFAULT)) {
            $update['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        }
        Database::update('users', $update, 'id = ?', [(int) $row['id']]);
        self::clearAttempts($ip);

        Session::regenerate(); // session fixation protection
        Session::set(self::SESSION_USER, (int) $row['id']);
        Session::set(self::SESSION_SEEN, time());
        self::rememberPassword((string) ($update['password_hash'] ?? $row['password_hash']));
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
        $row = Database::fetchOne('SELECT id, username, created_at, last_login_at, password_hash FROM users WHERE id = ?', [$id]);
        // A password change ends every session that logged in with the old password.
        if ($row === null || !hash_equals(self::passwordFingerprint((string) $row['password_hash']), (string) Session::get(self::SESSION_PASSWORD, ''))) {
            self::logout();
            return null;
        }
        unset($row['password_hash']);
        Session::set(self::SESSION_SEEN, time());
        $row['id'] = (int) $row['id'];
        return self::$user = $row;
    }

    /** Bind the current session to the user's current password hash (login, password change). */
    public static function rememberPassword(string $passwordHash): void
    {
        Session::set(self::SESSION_PASSWORD, self::passwordFingerprint($passwordHash));
        self::$user = null;
        self::$resolved = false;
    }

    private static function passwordFingerprint(string $passwordHash): string
    {
        return hash('sha256', 'cr7-session|' . $passwordHash);
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
        return self::recentAttempts(self::throttleKey($ip)) >= self::MAX_ATTEMPTS;
    }

    /**
     * Record a password check BEFORE it runs (insert first, then count), so parallel requests cannot all pass
     * the limit check: at most MAX_ATTEMPTS checks per window. Returns the row id (it stands for a failure until
     * releaseAttempt()/clearAttempts()), or null when the limit is reached.
     */
    public static function reserveAttempt(string $ip, string $username = ''): ?int
    {
        $key = self::throttleKey($ip);
        $id = Database::insert('login_attempts', ['ip' => $key, 'username' => mb_substr($username, 0, 50), 'attempted_at' => Date::now()]);
        if (self::recentAttempts($key) > self::MAX_ATTEMPTS) {
            Database::execute('DELETE FROM login_attempts WHERE id = ?', [$id]); // refused: not checked, not counted
            return null;
        }
        return $id;
    }

    /** The checked password was right: the reserved attempt is not a failure. */
    public static function releaseAttempt(int $id): void
    {
        Database::execute('DELETE FROM login_attempts WHERE id = ?', [$id]);
    }

    public static function clearAttempts(string $ip): void
    {
        Database::execute('DELETE FROM login_attempts WHERE ip = ?', [self::throttleKey($ip)]);
    }

    /** Throttle bucket: the IPv4 address, or the /64 network of an IPv6 address (one subscriber line). */
    public static function throttleKey(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return $ip;
        }
        $network = inet_ntop(substr((string) inet_pton($ip), 0, 8) . str_repeat("\0", 8));
        return ($network !== false ? $network : $ip) . '/64';
    }

    private static function recentAttempts(string $key): int
    {
        return (int) Database::fetchValue(
            'SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND attempted_at >= ?',
            [$key, Date::ago(self::WINDOW_MINUTES * 60)]
        );
    }

    /** Reset cached user (tests). */
    public static function reset(): void
    {
        self::$user = null;
        self::$resolved = false;
    }
}
