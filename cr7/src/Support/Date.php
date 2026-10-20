<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Config;

/**
 * Date helpers. Storage format is always UTC 'Y-m-d H:i:s'.
 */
final class Date
{
    /** Current UTC datetime 'Y-m-d H:i:s'. */
    public static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    /** Today's date in Europe/Istanbul ('Y-m-d'). */
    public static function today(): string
    {
        return self::local()->format('Y-m-d');
    }

    /**
     * Convert a timestamp or date string to UTC 'Y-m-d H:i:s'. Strings without a timezone
     * are treated as UTC. Throws \InvalidArgumentException for unparsable input.
     */
    public static function toUtc(string|int $time): string
    {
        if (is_int($time)) {
            return gmdate('Y-m-d H:i:s', $time);
        }
        try {
            $date = new \DateTimeImmutable(trim($time), new \DateTimeZone('UTC'));
        } catch (\Exception $e) {
            throw new \InvalidArgumentException("Unparsable date: {$time}", 0, $e);
        }
        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** UTC datetime $seconds ago (for "WHERE created_at >= ?" windows). */
    public static function ago(int $seconds): string
    {
        return gmdate('Y-m-d H:i:s', time() - $seconds);
    }

    /** Current time in the display timezone (Europe/Istanbul), e.g. for publish windows. */
    public static function local(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', self::timezone());
    }

    /** Display timezone (app.timezone, default Europe/Istanbul). */
    public static function timezone(): \DateTimeZone
    {
        static $zone = null;
        return $zone ??= new \DateTimeZone((string) Config::get('app.timezone', 'Europe/Istanbul'));
    }
}
