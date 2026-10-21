<?php

declare(strict_types=1);

namespace App\Services\News;

use App\Support\Str;

/**
 * <title> and meta description of a news post (spec §11: title ≤ 60 characters before the site
 * suffix, description 120–160 characters). Used by NewsController (display, so stored or admin
 * values are always capped) and NewsPublisher (stored meta_title).
 */
final class PostMeta
{
    public const TITLE_MAX = 60;
    public const DESCRIPTION_MIN = 120;
    public const DESCRIPTION_MAX = 160;

    /** Generic headline frames that only cost characters ("Spor gündemi: …"). */
    private const FILLER = '/^(?:spor gündemi|futbol gündemi|gündem|son dakika|dikkat çeken gelişme|yeni gelişme)\s*:\s*/iu';

    /**
     * meta_title (else the headline), at most 60 characters: the filler frame dropped, else the
     * clause before ':' when it still names him, else cut at a word boundary.
     */
    public static function title(?string $metaTitle, string $title): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) ($metaTitle ?: $title)));
        $rest = (string) preg_replace(self::FILLER, '', $text);
        if ($rest !== $text && mb_strlen($rest) >= 25 && mb_strlen($rest) <= self::TITLE_MAX && self::namesHim($rest)) {
            return self::ucfirst($rest);
        }
        if (mb_strlen($text) <= self::TITLE_MAX) {
            return $text;
        }
        $colon = mb_strpos($rest, ':');
        if ($colon !== false && $colon >= 25 && $colon <= self::TITLE_MAX && self::namesHim(mb_substr($rest, 0, $colon))) {
            return rtrim(mb_substr($rest, 0, $colon));
        }
        return Str::limit(self::namesHim($rest) ? self::ucfirst($rest) : $text, self::TITLE_MAX);
    }

    /**
     * Whole sentences, 120–160 characters: the summary's sentences (completed from the body when
     * the stored summary was cut with '…'), then the following body sentences while they fit.
     * Falls back to a word-boundary cut when the first sentence alone is longer than 160.
     */
    public static function description(string $summary, string $body = ''): string
    {
        $summary = trim((string) preg_replace('/\s+/u', ' ', $summary));
        $bodySentences = [];
        foreach (preg_split('#</(?:p|li|h[2-4])>#i', $body) ?: [] as $block) {
            $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($block), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            if ($text === '' || preg_match('/^(?:Kaynak|Kaynaklar)\s*:/u', $text) === 1) {
                continue;
            }
            array_push($bodySentences, ...self::sentences($text));
        }
        $lead = rtrim($summary, '… ');
        $start = null;
        foreach ($bodySentences as $i => $sentence) {
            if ($lead !== '' && str_starts_with($sentence, mb_substr($lead, 0, min(40, mb_strlen($lead))))) {
                $start = $i;
                break;
            }
        }
        $pool = $start !== null
            ? array_slice($bodySentences, $start)
            : [...array_filter(self::sentences($summary), static fn (string $s): bool => !str_ends_with($s, '…')), ...$bodySentences];

        // From the lead sentence on; when that one alone is too long, from one of the next two.
        foreach ([0, 1, 2] as $offset) {
            $out = '';
            foreach (array_slice($pool, $offset) as $sentence) {
                $next = $out === '' ? $sentence : $out . ' ' . $sentence;
                if (mb_strlen($next) > self::DESCRIPTION_MAX) {
                    break;
                }
                $out = $next;
                if (mb_strlen($out) >= self::DESCRIPTION_MIN && ($offset === 0 || self::namesHim($out))) {
                    return $out;
                }
            }
            if ($offset === 0 && $out !== '') {
                return $out; // whole sentences, just short of 120
            }
        }
        return Str::limit($pool[0] ?? $summary, self::DESCRIPTION_MAX);
    }

    /** @return list<string> */
    private static function sentences(string $text): array
    {
        $parts = preg_split('/(?<=[.!?…])\s+(?=[\p{Lu}\d“"‘])/u', $text) ?: [];
        return array_values(array_filter(array_map('trim', $parts), static fn (string $s): bool => $s !== ''));
    }

    /** Turkish ucfirst (i => İ). */
    private static function ucfirst(string $text): string
    {
        return Str::upper(mb_substr($text, 0, 1)) . mb_substr($text, 1);
    }

    private static function namesHim(string $text): bool
    {
        return preg_match('/ronaldo|cr7/iu', $text) === 1;
    }
}
