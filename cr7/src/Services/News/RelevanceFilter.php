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
 * "Cristiano" / "CR7" is present as well. Before publishing, aboutCristiano() runs on the
 * whole story text, which must name Cristiano explicitly.
 */
final class RelevanceFilter
{
    /** Phrases that point to another Ronaldo. */
    private const OTHER_RONALDOS = [
        'nazário', 'nazario', 'brezilyalı ronaldo', 'brezilyali ronaldo', 'ronaldo luís', 'ronaldo luis', 'ronaldo luiz',
        'fenomeno', 'fenômeno', 'r9', 'ronaldinho', 'gaúcho', 'gaucho', 'ronaldo de assis', 'ronaldo fenomen',
        // Nazário's federation bid and the clubs he owned
        'cbf', 'brezilya futbol federasyonu', 'cruzeiro', 'valladolid',
    ];

    /**
     * "Brezilyalı (efsane golcü) Ronaldo", "Brezilya'nın efsanesi Ronaldo": up to two words in
     * between, but not a list ("Brezilyalı Neymar ile Ronaldo").
     */
    private const OTHER_RONALDO_PATTERN = '/(?<![\p{L}\p{N}])brezilya(?:l[ıi]|[\'’]?n[ıi]n)\s+(?:(?!(?:ile|ve|veya|ya)\s)\p{L}+\s+){0,2}ronaldo/u';

    /** Phrases that make sure it is Cristiano. */
    private const CRISTIANO = [
        'cristiano', 'christiano', 'cr7', 'portekiz', 'al-nassr', 'al nassr', 'al-nasr', 'al nasr',
        'juventus', 'manchester united', 'georgina',
    ];

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

    /**
     * Publish-time check on a whole story (title + description + extracted article text): it must
     * name Cristiano unambiguously (Cristiano, CR7, Portekiz, Al-Nassr …). A full article about
     * him always does; one about Ronaldo Nazário ("Brezilya'nın efsane golcüsü Ronaldo", CBF,
     * Cruzeiro) never does, although it says "Ronaldo" in every paragraph.
     */
    public static function aboutCristiano(string $text): bool
    {
        $lower = Str::lower($text);
        return self::mentions($lower) && self::containsAny($lower, self::CRISTIANO);
    }

    /**
     * True when an article text is about another Ronaldo: it carries an other-Ronaldo marker
     * (Nazário, "Brezilya'nın efsane golcüsü Ronaldo", CBF, Cruzeiro …) and names Cristiano
     * nowhere itself. Applied to the lead source text, which becomes the post body, so a feed
     * snippet that happens to say "Portekizli" cannot carry a Nazário article through.
     */
    public static function aboutOtherRonaldo(string $text): bool
    {
        $lower = Str::lower($text);
        return self::pointsToOtherRonaldo($lower) && !self::containsAny($lower, self::CRISTIANO);
    }

    private static function pointsToOtherRonaldo(string $lower): bool
    {
        if (preg_match(self::OTHER_RONALDO_PATTERN, $lower) === 1) {
            return true;
        }
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
