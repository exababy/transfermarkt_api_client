<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Turkish display formatting (numbers, dates in Europe/Istanbul, money).
 */
final class Format
{
    public const MONTHS = [1 => 'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];
    public const MONTHS_SHORT = [1 => 'Oca', 'Şub', 'Mar', 'Nis', 'May', 'Haz', 'Tem', 'Ağu', 'Eyl', 'Eki', 'Kas', 'Ara'];
    /** ISO-8601 day number (1 = Monday). */
    public const DAYS = [1 => 'Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma', 'Cumartesi', 'Pazar'];
    public const DAYS_SHORT = [1 => 'Pzt', 'Sal', 'Çar', 'Per', 'Cum', 'Cmt', 'Paz'];

    /** 1234567 => '1.234.567'; 12.5 (1 decimal) => '12,5'; null => '-'. */
    public static function number(int|float|null $n, int $decimals = 0): string
    {
        if ($n === null) {
            return '-';
        }
        return number_format((float) $n, $decimals, ',', '.');
    }

    /**
     * UTC datetime/date string => Turkish date in Europe/Istanbul.
     * Tokens: j d m n Y y F M l D H i G s ; prefix a character with '\' to print it literally.
     */
    public static function date(?string $utc, string $pattern = 'j F Y'): string
    {
        $date = self::parse($utc);
        if ($date === null) {
            return '';
        }
        $out = '';
        $length = strlen($pattern);
        for ($i = 0; $i < $length; $i++) {
            $char = $pattern[$i];
            if ($char === '\\' && $i + 1 < $length) {
                $out .= $pattern[++$i];
                continue;
            }
            $out .= match ($char) {
                'j' => $date->format('j'),
                'd' => $date->format('d'),
                'm' => $date->format('m'),
                'n' => $date->format('n'),
                'Y' => $date->format('Y'),
                'y' => $date->format('y'),
                'F' => self::MONTHS[(int) $date->format('n')],
                'M' => self::MONTHS_SHORT[(int) $date->format('n')],
                'l' => self::DAYS[(int) $date->format('N')],
                'D' => self::DAYS_SHORT[(int) $date->format('N')],
                'H' => $date->format('H'),
                'G' => $date->format('G'),
                'i' => $date->format('i'),
                's' => $date->format('s'),
                default => $char,
            };
        }
        return $out;
    }

    /** '3 saat önce', 'az önce', '2 gün sonra' … */
    public static function relative(?string $utc): string
    {
        $date = self::parse($utc);
        if ($date === null) {
            return '';
        }
        $diff = time() - $date->getTimestamp();
        $suffix = $diff >= 0 ? 'önce' : 'sonra';
        $diff = abs($diff);
        if ($diff < 60) {
            return $suffix === 'önce' ? 'az önce' : 'birazdan';
        }
        foreach ([[31536000, 'yıl'], [2592000, 'ay'], [604800, 'hafta'], [86400, 'gün'], [3600, 'saat'], [60, 'dakika']] as [$seconds, $unit]) {
            if ($diff >= $seconds) {
                return intdiv($diff, $seconds) . ' ' . $unit . ' ' . $suffix;
            }
        }
        return 'az önce';
    }

    /** Market values in euros: 120000000 => '120 mil. €', 12500000 => '12,5 mil. €', 500000 => '500 bin €', null => '-'. */
    public static function money(?int $eur): string
    {
        if ($eur === null) {
            return '-';
        }
        $abs = abs($eur);
        return match (true) {
            $abs >= 1_000_000_000 => self::trimmed($eur / 1_000_000_000, 2) . ' mlr. €',
            $abs >= 1_000_000 => self::trimmed($eur / 1_000_000, 2) . ' mil. €',
            $abs >= 1_000 => self::trimmed($eur / 1_000, 1) . ' bin €',
            default => self::number($eur) . ' €',
        };
    }

    /** Shop prices in Turkish lira: 1299.9 => '1.299,90 TL', null => '-'. */
    public static function price(int|float|string|null $amount): string
    {
        if ($amount === null || $amount === '' || !is_numeric($amount)) {
            return '-';
        }
        return number_format((float) $amount, 2, ',', '.') . ' TL';
    }

    /** ISO 8601 in Europe/Istanbul ('2026-10-20T13:00:00+03:00'); '' for null. */
    public static function iso(?string $utc): string
    {
        return self::parse($utc)?->format('c') ?? '';
    }

    private static function parse(?string $utc): ?\DateTimeImmutable
    {
        if ($utc === null || trim($utc) === '' || str_starts_with($utc, '0000-00-00')) {
            return null;
        }
        try {
            return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->setTimezone(Date::timezone());
        } catch (\Exception) {
            return null;
        }
    }

    private static function trimmed(float $value, int $decimals): string
    {
        $formatted = number_format($value, $decimals, ',', '.');
        return str_contains($formatted, ',') ? rtrim(rtrim($formatted, '0'), ',') : $formatted;
    }
}
