<?php

declare(strict_types=1);

namespace App\Services\News;

use App\Core\Config;
use App\Core\Settings;
use App\Support\Str;

/**
 * Duplicate detection and story clustering for news items.
 *
 * Two titles describe the same story when
 *   - Str::similarity() >= news.similarity_threshold (spec rule; near-identical titles), or
 *   - their "story stems" overlap strongly: tokens without the player's name and stop words,
 *     cut to 4-letter Turkish stems ("cezası"/"cezayı" => "ceza", "kampını" => "kamp"), with
 *     Jaccard >= 0.4 (and at least 2 shared stems) or overlap >= 0.75 with at least 4 shared stems.
 * The second rule catches the many headline variants Turkish outlets write for one story
 * ("Ronaldo'nun cezası belli oldu" / "Kampı terk eden Ronaldo'ya 255 euro ceza").
 *
 * Google News items carry Google's own story cluster (other outlets' titles, one per line in
 * the description), which is used as additional titles of that item.
 */
final class Clusterer
{
    private const STOP = [
        'cristiano', 'cr7', 'ile', 've', 'veya', 'bir', 'bu', 'su', 'o', 'icin', 'gibi', 'son', 'dakika', 'iste', 'mi', 'mu',
        'da', 'de', 'ki', 'olan', 'oldu', 'olarak', 'daha', 'cok', 'en', 'haber', 'haberi', 'haberleri', 'spor', 'futbol', 'flas',
        'yeni', 'sok', 'buyuk', 'iddia', 'aciklandi', 'acikladi', 'aciklama', 'geldi', 'neden', 'nasil', 'ne', 'kim', 'hangi',
        'galeri', 'video', 'izle', 'tum', 'ayrintilar', 'detaylar',
    ];

    /** UTC window in which items/posts are compared. */
    public const WINDOW_HOURS = 72;

    public static function threshold(): float
    {
        return (float) Settings::get('news.similarity_threshold', Config::get('news.similarity_threshold', 0.55));
    }

    /** Stable hash of an article URL (scheme, "www." and trailing slash ignored). */
    public static function urlHash(string $url): string
    {
        $url = trim($url);
        $url = (string) preg_replace('#^https?://(www\.)?#i', '', $url);
        $parts = explode('?', $url, 2);
        $parts[0] = strtolower(rtrim($parts[0], '/'));
        return sha1(implode('?', $parts));
    }

    /** True when two titles describe the same story. */
    public static function sameStory(string $a, string $b, ?float $threshold = null): bool
    {
        if (Str::similarity($a, $b) >= ($threshold ?? self::threshold())) {
            return true;
        }
        [$jaccard, $overlap, $shared] = self::stemScores($a, $b);
        return ($jaccard >= 0.4 && $shared >= 2) || ($overlap >= 0.75 && $shared >= 4);
    }

    /** Best combined similarity (0..1) between two titles, used to rank matches. */
    public static function score(string $a, string $b): float
    {
        [$jaccard, $overlap] = self::stemScores($a, $b);
        return max(Str::similarity($a, $b), $jaccard, $overlap * 0.8);
    }

    /**
     * Same story judged on title + description: the titles match (sameStory) or the stems of
     * "title + first 45 description words" overlap (Jaccard >= 0.27 with at least 8 shared stems;
     * agency copy and lead paragraphs of one story share most of their content words).
     */
    public static function sameText(string $a, string $b): bool
    {
        $sa = self::stems(self::head($a));
        $sb = self::stems(self::head($b));
        if (count($sa) < 8 || count($sb) < 8) {
            return false;
        }
        $shared = count(array_intersect($sa, $sb));
        return $shared >= 8 && $shared / count(array_unique([...$sa, ...$sb])) >= 0.27;
    }

    /**
     * Matching candidates for an item, best first.
     *
     * @param list<string> $titles title + optional related titles (Google story cluster)
     * @param list<array{id: int, titles: list<string>, text?: string}> $candidates
     * @return list<array{id: int, score: float}>
     */
    public static function matches(array $titles, string $text, array $candidates, ?float $threshold = null): array
    {
        $out = [];
        foreach ($candidates as $candidate) {
            $best = null;
            foreach ($titles as $title) {
                foreach ($candidate['titles'] as $other) {
                    if ($title === '' || $other === '' || !self::sameStory($title, $other, $threshold)) {
                        continue;
                    }
                    $best = max($best ?? 0.0, self::score($title, $other));
                }
            }
            if ($best === null && $text !== '' && ($candidate['text'] ?? '') !== '' && self::sameText($text, (string) $candidate['text'])) {
                $best = 0.5;
            }
            if ($best !== null) {
                $out[] = ['id' => (int) $candidate['id'], 'score' => $best];
            }
        }
        usort($out, static fn (array $x, array $y): int => $y['score'] <=> $x['score']);
        return $out;
    }

    /**
     * Best matching candidate for $titles (title + optional related titles).
     *
     * @param list<string> $titles
     * @param list<array{id: int, titles: list<string>, text?: string}> $candidates
     * @return array{id: int, score: float}|null
     */
    public static function bestMatch(array $titles, array $candidates, ?float $threshold = null, string $text = ''): ?array
    {
        return self::matches($titles, $text, $candidates, $threshold)[0] ?? null;
    }

    /** Title + first 45 words of the description (the comparable "story head"). */
    public static function head(string $text): string
    {
        $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return implode(' ', array_slice($words, 0, 60));
    }

    /** Primary item id referenced by a note 'cluster:<id>' (null when the item is a primary). */
    public static function primaryFromNote(?string $note): ?int
    {
        if ($note !== null && preg_match('/(?:^|\s|;)cluster:(\d+)/', $note, $m) === 1) {
            return (int) $m[1];
        }
        return null;
    }

    /** @return list<string> 4-letter stems of the meaningful title words */
    public static function stems(string $title): array
    {
        static $stop = null;
        $stop ??= array_flip(self::STOP);
        $stems = [];
        foreach (explode(' ', Str::normalize($title)) as $word) {
            if ($word === '' || strlen($word) < 3 || isset($stop[$word]) || ctype_digit($word) || str_starts_with($word, 'ronaldo')) {
                continue;
            }
            $stems[substr($word, 0, 4)] = true;
        }
        return array_keys($stems);
    }

    /** @return array{0: float, 1: float, 2: int} [jaccard, overlap coefficient, shared stems] */
    private static function stemScores(string $a, string $b): array
    {
        $sa = self::stems($a);
        $sb = self::stems($b);
        if ($sa === [] || $sb === []) {
            return [0.0, 0.0, 0];
        }
        $shared = count(array_intersect($sa, $sb));
        $union = count(array_unique([...$sa, ...$sb]));
        return [$shared / $union, $shared / min(count($sa), count($sb)), $shared];
    }
}
