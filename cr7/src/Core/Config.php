<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Static configuration repository (config/config.php) with dot-notation access.
 */
final class Config
{
    /** Environment variable => config key. */
    private const ENV_MAP = [
        'CR7_DB_DRIVER' => 'db.driver',
        'CR7_DB_PATH' => 'db.sqlite_path',
        'CR7_DB_HOST' => 'db.host',
        'CR7_DB_PORT' => 'db.port',
        'CR7_DB_NAME' => 'db.database',
        'CR7_DB_USER' => 'db.username',
        'CR7_DB_PASS' => 'db.password',
        'CR7_APP_URL' => 'app.url',
        'CR7_DEBUG' => 'app.debug',
    ];

    /** @var array<string, mixed> */
    private static array $items = [];

    /** Load (and merge) a PHP file that returns a config array. */
    public static function load(string $file): void
    {
        $data = require $file;
        if (!is_array($data)) {
            throw new \RuntimeException("Config file must return an array: {$file}");
        }
        self::$items = array_replace_recursive(self::$items, $data);
    }

    /** Apply CR7_* environment overrides (see ENV_MAP). */
    public static function applyEnvironment(): void
    {
        foreach (self::ENV_MAP as $env => $key) {
            $value = getenv($env);
            if ($value === false || $value === '') {
                continue;
            }
            $value = match ($key) {
                'db.port' => (int) $value,
                'app.debug' => filter_var($value, FILTER_VALIDATE_BOOL),
                'app.url' => rtrim($value, '/'),
                default => $value,
            };
            self::set($key, $value);
        }
    }

    /** Read a value with dot notation ('news.daily_limit'). */
    public static function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, self::$items)) {
            return self::$items[$key];
        }
        $node = self::$items;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return $default;
            }
            $node = $node[$segment];
        }
        return $node;
    }

    /** Set a value with dot notation (used by tests and env overrides). */
    public static function set(string $key, mixed $value): void
    {
        $node = &self::$items;
        foreach (explode('.', $key) as $segment) {
            if (!isset($node[$segment]) || !is_array($node[$segment])) {
                $node[$segment] = [];
            }
            $node = &$node[$segment];
        }
        $node = $value;
        unset($node);
    }

    /** @return array<string, mixed> Whole configuration tree. */
    public static function all(): array
    {
        return self::$items;
    }

    /** Replace the whole configuration tree (tests restore snapshots with it). */
    public static function replace(array $items): void
    {
        self::$items = $items;
    }
}
