<?php

declare(strict_types=1);

namespace App\Services\News;

use App\Core\Config;
use App\Support\Str;

/**
 * Decides whether a feed item is about Cristiano Ronaldo.
 *
 * A keyword (config 'news.keywords', default ronaldo / cr7) must start a word in the title or
 * the description (Turkish-aware lowercase, so "RONALDO'YA" and "Ronaldoya" both match).
 * Other Ronaldos (Nazário, "Brezilyalı Ronaldo", R9, Ronaldinho …) are excluded unless
 * "Cristiano" / "CR7" is present as well.
 */
final class RelevanceFilter
{
    /** Phrases that point to another Ronaldo. */
    private const OTHER_RONALDOS = [
        'nazário', 'nazario', 'brezilyalı ronaldo', 'brezilyali ronaldo', 'ronaldo luís', 'ronaldo luis', 'ronaldo luiz',
        'fenomeno', 'fenômeno', 'r9', 'ronaldinho', 'gaúcho', 'gaucho', 'ronaldo de assis', 'ronaldo fenomen',
    ];

    /** Phrases that make sure it is Cristiano. */
    private const CRISTIANO = ['cristiano', 'cr7', 'portekizli yıldız', 'portekizli süperstar', 'al-nassr', 'al nassr'];

    /** 2 = title mentions him, 1 = only the description does, 0 = not relevant. */
    public static function score(string $title, string $description = ''): int
    {
        $titleLower = Str::lower($title);
        $all = $titleLower . ' ' . Str::lower($description);
        if (!self::mentions($all)) {
            return 0;
        }
        if (self::pointsToOtherRonaldo($all) && !self::containsAny($all, self::CRISTIANO)) {
            return 0;
        }
        return self::mentions($titleLower) ? 2 : 1;
    }

    public static function isRelevant(string $title, string $description = ''): bool
    {
        return self::score($title, $description) > 0;
    }

    /** True when one of the keywords starts a word in the (already lowercased or raw) text. */
    public static function mentions(string $text): bool
    {
        $text = Str::lower($text);
        foreach (self::keywords() as $keyword) {
            if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($keyword, '/') . '(?![\p{N}])/u', $text) === 1) {
                return true;
            }
        }
        return false;
    }

    /** @return list<string> lowercase keywords */
    public static function keywords(): array
    {
        $keywords = Config::get('news.keywords', ['ronaldo', 'cr7']);
        $keywords = is_array($keywords) && $keywords !== [] ? $keywords : ['ronaldo', 'cr7'];
        return array_values(array_filter(array_map(static fn ($k): string => Str::lower(trim((string) $k)), $keywords)));
    }

    private static function pointsToOtherRonaldo(string $lower): bool
    {
        foreach (self::OTHER_RONALDOS as $marker) {
            if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($marker, '/') . '(?![\p{L}\p{N}])/u', $lower) === 1) {
                return true;
            }
        }
        return false;
    }

    private static function containsAny(string $lower, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }
        return false;
    }
}
