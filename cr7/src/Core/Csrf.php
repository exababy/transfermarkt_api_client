<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Session-bound CSRF token. Forms print csrf_field(); POST handlers call Csrf::check($request).
 * The token is read from the `_token` field or the X-CSRF-Token header.
 */
final class Csrf
{
    private const SESSION_KEY = '_csrf';
    public const FIELD = '_token';

    public static function token(): string
    {
        $token = Session::get(self::SESSION_KEY);
        if (!is_string($token) || strlen($token) !== 64) {
            $token = bin2hex(random_bytes(32));
            Session::set(self::SESSION_KEY, $token);
        }
        return $token;
    }

    public static function field(): string
    {
        return '<input type="hidden" name="' . self::FIELD . '" value="' . e(self::token()) . '">';
    }

    public static function verify(?string $token): bool
    {
        if ($token === null || $token === '') {
            return false;
        }
        $expected = Session::get(self::SESSION_KEY);
        return is_string($expected) && $expected !== '' && hash_equals($expected, $token);
    }

    /** Abort with 419 when the request carries no valid token. */
    public static function check(Request $r): void
    {
        $token = $r->input(self::FIELD);
        if (!is_string($token)) {
            $token = $r->header('X-CSRF-Token');
        }
        if (!self::verify($token)) {
            throw new HttpException(419, 'Güvenlik doğrulaması başarısız oldu. Lütfen sayfayı yenileyip tekrar deneyin.');
        }
    }

    /** Issue a fresh token (after login/logout). */
    public static function regenerate(): void
    {
        Session::set(self::SESSION_KEY, bin2hex(random_bytes(32)));
    }
}
