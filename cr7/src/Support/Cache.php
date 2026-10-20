<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\App;

/**
 * File cache in storage/cache (serialize, atomic writes). Store arrays/scalars only:
 * objects are not restored (unserialize allowed_classes=false).
 *
 * Keys starting with a KEEP prefix are pipeline state that cannot be rebuilt from the database
 * (feed full texts the publisher still needs, the rejected-videos list, open PayTR tokens): they
 * live in storage/cache/keep/ and survive flush() ("Önbelleği temizle"), still expiring by TTL.
 */
final class Cache
{
    private const KEEP = ['news:feedtext:', 'videos.rejected', 'paytr-iframe-'];

    /** Cached value, or null when missing/expired. */
    public static function get(string $key): mixed
    {
        [$hit, $value] = self::read($key);
        return $hit ? $value : null;
    }

    /** Store a value; $ttl in seconds (<= 0 = no expiry). */
    public static function set(string $key, mixed $value, int $ttl): void
    {
        $dir = dirname(self::file($key));
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }
        $payload = serialize(['e' => $ttl > 0 ? time() + $ttl : 0, 'v' => $value]);
        $tmp = $dir . '/.' . bin2hex(random_bytes(6)) . '.tmp';
        if (@file_put_contents($tmp, $payload, LOCK_EX) === false) {
            return;
        }
        if (!@rename($tmp, self::file($key))) {
            @unlink($tmp);
        }
    }

    /** Return the cached value or compute, store and return it (null results are cached too). */
    public static function remember(string $key, int $ttl, callable $fn): mixed
    {
        [$hit, $value] = self::read($key);
        if ($hit) {
            return $value;
        }
        $value = $fn();
        self::set($key, $value, $ttl);
        return $value;
    }

    public static function forget(string $key): void
    {
        @unlink(self::file($key));
        @unlink(self::dir() . '/' . sha1($key) . '.cache'); // a KEEP key written before keep/ existed
    }

    /** Delete every cache entry except the KEEP pipeline state (storage/cache/keep/). */
    public static function flush(): void
    {
        foreach (glob(self::dir() . '/*.cache') ?: [] as $file) {
            @unlink($file);
        }
    }

    /** @return array{0: bool, 1: mixed} [hit, value] */
    private static function read(string $key): array
    {
        $file = self::file($key);
        if (!is_file($file)) {
            $file = self::dir() . '/' . sha1($key) . '.cache'; // KEEP key written before keep/ existed
        }
        $raw = is_file($file) ? @file_get_contents($file) : false;
        if ($raw === false) {
            return [false, null];
        }
        $data = @unserialize($raw, ['allowed_classes' => false]);
        if (!is_array($data) || !array_key_exists('v', $data)) {
            return [false, null];
        }
        if (($data['e'] ?? 0) !== 0 && $data['e'] < time()) {
            @unlink($file);
            return [false, null];
        }
        return [true, $data['v']];
    }

    private static function file(string $key): string
    {
        foreach (self::KEEP as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return self::dir() . '/keep/' . sha1($key) . '.cache';
            }
        }
        return self::dir() . '/' . sha1($key) . '.cache';
    }

    private static function dir(): string
    {
        return App::storagePath('cache');
    }
}
