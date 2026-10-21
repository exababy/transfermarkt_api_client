<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Turkish-aware string helpers.
 */
final class Str
{
    /** Turkish + common Latin (Portuguese, Spanish, French, German …) => ASCII. */
    private const TRANSLIT = [
        'ç' => 'c', 'Ç' => 'c', 'ğ' => 'g', 'Ğ' => 'g', 'ı' => 'i', 'I' => 'i', 'İ' => 'i', 'ö' => 'o', 'Ö' => 'o',
        'ş' => 's', 'Ş' => 's', 'ü' => 'u', 'Ü' => 'u',
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'Á' => 'a', 'À' => 'a', 'Â' => 'a', 'Ã' => 'a', 'Ä' => 'a', 'Å' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'É' => 'e', 'È' => 'e', 'Ê' => 'e', 'Ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'Í' => 'i', 'Ì' => 'i', 'Î' => 'i', 'Ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ø' => 'o', 'Ó' => 'o', 'Ò' => 'o', 'Ô' => 'o', 'Õ' => 'o', 'Ø' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'Ú' => 'u', 'Ù' => 'u', 'Û' => 'u',
        'ñ' => 'n', 'Ñ' => 'n', 'ß' => 'ss', 'æ' => 'ae', 'Æ' => 'ae', 'œ' => 'oe', 'Œ' => 'oe', 'ý' => 'y', 'ÿ' => 'y', 'Ý' => 'y',
        'ć' => 'c', 'č' => 'c', 'Č' => 'c', 'š' => 's', 'Š' => 's', 'ž' => 'z', 'Ž' => 'z', 'ł' => 'l', 'Ł' => 'l', 'đ' => 'd', 'ă' => 'a', 'ș' => 's', 'ț' => 't',
    ];

    /** Apostrophes are dropped (Ronaldo'nun => ronaldonun), not turned into dashes. */
    private const APOSTROPHES = ["'", '’', '‘', 'ʼ', '`', '´'];

    /**
     * URL slug: Turkish-aware transliteration, lowercase, non-alphanumerics => '-',
     * collapsed/trimmed dashes, cut on a word boundary to $maxLength (<= 0: no limit).
     */
    public static function slug(string $text, int $maxLength = 90): string
    {
        $text = str_replace(self::APOSTROPHES, '', $text);
        $text = strtr($text, self::TRANSLIT);
        if (preg_match('/[^\x00-\x7F]/', $text) === 1) {
            $text = self::toAscii($text);
        }
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($text)), '-');
        if ($maxLength > 0 && strlen($slug) > $maxLength) {
            $cut = substr($slug, 0, $maxLength + 1);
            $pos = strrpos($cut, '-');
            $slug = $pos !== false && $pos > 0 ? substr($cut, 0, $pos) : substr($slug, 0, $maxLength);
            $slug = trim($slug, '-');
        }
        return $slug;
    }

    /** Multibyte-safe truncation on a word boundary; the result (incl. $end) is at most $length chars. */
    public static function limit(string $text, int $length, string $end = '…'): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if (mb_strlen($text) <= $length) {
            return $text;
        }
        $room = max(1, $length - mb_strlen($end));
        $cut = mb_substr($text, 0, $room + 1);
        $space = mb_strrpos($cut, ' ');
        $cut = ($space !== false && $space >= (int) ($room * 0.5)) ? mb_substr($cut, 0, $space) : mb_substr($cut, 0, $room);
        return rtrim($cut, " \t,.;:-–—") . $end;
    }

    /** Turkish lowercase: I => ı, İ => i. */
    public static function lower(string $s): string
    {
        return mb_strtolower(str_replace(['I', 'İ'], ['ı', 'i'], $s), 'UTF-8');
    }

    /** Turkish uppercase: i => İ, ı => I. */
    public static function upper(string $s): string
    {
        return mb_strtoupper(str_replace(['i', 'ı'], ['İ', 'I'], $s), 'UTF-8');
    }

    /** Lowercase ASCII tokens separated by single spaces (for dedupe/comparison). */
    public static function normalize(string $text): string
    {
        return str_replace('-', ' ', self::slug($text, 0));
    }

    /** Jaccard similarity (0..1) over normalized word tokens of length >= 3. */
    public static function similarity(string $a, string $b): float
    {
        $tokens = static fn (string $s): array => array_values(array_unique(array_filter(
            explode(' ', self::normalize($s)),
            static fn (string $t): bool => strlen($t) >= 3
        )));
        $ta = $tokens($a);
        $tb = $tokens($b);
        $union = count(array_unique([...$ta, ...$tb]));
        if ($union === 0) {
            return 0.0;
        }
        return count(array_intersect($ta, $tb)) / $union;
    }

    /** Cryptographically random [A-Za-z0-9] string. */
    public static function random(int $length = 32): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $max = strlen($alphabet) - 1;
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }
        return $out;
    }

    private static function toAscii(string $text): string
    {
        if (class_exists(\Transliterator::class)) {
            $t = \Transliterator::create('Any-Latin; Latin-ASCII');
            if ($t !== null) {
                $converted = $t->transliterate($text);
                if (is_string($converted)) {
                    return $converted;
                }
            }
        }
        return (string) preg_replace('/[^\x00-\x7F]/', '', $text);
    }
}
