<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Lazy, hardened PHP session (cookie "cr7sess": HttpOnly, SameSite=Lax, Secure on HTTPS,
 * strict mode). Started only when needed (CSRF tokens, admin auth, flash messages).
 * In CLI a plain in-memory $_SESSION array is used.
 */
final class Session
{
    public const NAME = 'cr7sess';
    private const FLASH_KEY = '_flash';

    private static bool $started = false;

    public static function start(): void
    {
        if (self::$started) {
            return;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            self::$started = true;
            return;
        }
        if (PHP_SAPI === 'cli' || headers_sent()) {
            $_SESSION ??= [];
            self::$started = true;
            return;
        }
        $secure = App::request()?->isHttps() ?? false;
        session_name(self::NAME);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => App::urlPrefix() === '' ? '/' : App::urlPrefix() . '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start([
            'use_strict_mode' => 1,
            'use_only_cookies' => 1,
            'use_trans_sid' => 0,
            'cookie_httponly' => 1,
            'gc_maxlifetime' => 86400,
        ]);
        self::$started = true;
    }

    /** True when a session is running in this request. */
    public static function active(): bool
    {
        return self::$started || session_status() === PHP_SESSION_ACTIVE;
    }

    /** True when the client sent a session cookie (a session may be resumed). */
    public static function hasCookie(): bool
    {
        return isset($_COOKIE[self::NAME]) && is_string($_COOKIE[self::NAME]);
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        self::start();
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        self::start();
        $_SESSION[$key] = $value;
    }

    public static function forget(string $key): void
    {
        self::start();
        unset($_SESSION[$key]);
    }

    /** Read and remove a value. */
    public static function pull(string $key, mixed $default = null): mixed
    {
        $value = self::get($key, $default);
        self::forget($key);
        return $value;
    }

    /** Queue a one-time message for the next page: type = success|error|info|warning. */
    public static function flash(string $type, string $message): void
    {
        self::start();
        $_SESSION[self::FLASH_KEY][] = ['type' => $type, 'message' => $message];
    }

    /** @return list<array{type: string, message: string}> queued flash messages (removed once read) */
    public static function flashes(): array
    {
        if (!self::active() && !self::hasCookie()) {
            return [];
        }
        $messages = self::pull(self::FLASH_KEY, []);
        return is_array($messages) ? $messages : [];
    }

    /** New session id, old one destroyed (call on privilege change). */
    public static function regenerate(): void
    {
        self::start();
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    /** Clear all data and rotate the id. */
    public static function invalidate(): void
    {
        self::start();
        $_SESSION = [];
        self::regenerate();
    }
}
