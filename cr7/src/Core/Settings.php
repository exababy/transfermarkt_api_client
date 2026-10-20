<?php

declare(strict_types=1);

namespace App\Core;

use App\Support\Date;

/**
 * Admin-editable settings stored as JSON in the `settings` table, overriding Config.
 *
 * Settings::get('payment.paytr') returns the config array with any stored
 * 'payment.paytr.*' overrides merged in. Never throws: if the database is not
 * reachable/installed, Config values are returned.
 */
final class Settings
{
    /** @var array<string, mixed>|null decoded settings, loaded once per request */
    private static ?array $items = null;

    public static function get(string $key, mixed $default = null): mixed
    {
        $items = self::load();
        if (array_key_exists($key, $items)) {
            return $items[$key];
        }
        $value = Config::get($key, $default);
        if (!is_array($value)) {
            return $value;
        }
        $prefix = $key . '.';
        foreach ($items as $name => $override) {
            if (str_starts_with($name, $prefix)) {
                $value = self::setPath($value, explode('.', substr($name, strlen($prefix))), $override);
            }
        }
        return $value;
    }

    /** JSON-encode and upsert a value. */
    public static function set(string $key, mixed $value): void
    {
        Database::upsert('settings', [
            'setting_key' => $key,
            'setting_value' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'updated_at' => Date::now(),
        ], ['setting_key']);
        if (self::$items !== null) {
            self::$items[$key] = $value;
        }
    }

    /** Remove a stored override (the config value applies again). */
    public static function forget(string $key): void
    {
        Database::execute('DELETE FROM settings WHERE setting_key = ?', [$key]);
        unset(self::$items[$key]);
    }

    /** @return array<string, mixed> all stored overrides (decoded) */
    public static function all(): array
    {
        return self::load();
    }

    /** Drop the per-request cache (tests, after bulk writes). */
    public static function flush(): void
    {
        self::$items = null;
    }

    private static function load(): array
    {
        if (self::$items !== null) {
            return self::$items;
        }
        self::$items = [];
        try {
            foreach (Database::fetchAll('SELECT setting_key, setting_value FROM settings') as $row) {
                $raw = $row['setting_value'];
                $decoded = $raw === null ? null : json_decode((string) $raw, true);
                self::$items[(string) $row['setting_key']] = ($decoded === null && $raw !== null && $raw !== 'null') ? $raw : $decoded;
            }
        } catch (\Throwable) {
            // Not installed / DB unavailable: fall back to config for this request.
        }
        return self::$items;
    }

    private static function setPath(array $array, array $path, mixed $value): array
    {
        $segment = array_shift($path);
        if ($path === []) {
            $array[$segment] = $value;
            return $array;
        }
        $array[$segment] = self::setPath(is_array($array[$segment] ?? null) ? $array[$segment] : [], $path, $value);
        return $array;
    }
}
