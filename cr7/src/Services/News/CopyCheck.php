<?php

declare(strict_types=1);

namespace App\Services\News;

use App\Core\Config;
use App\Core\Settings;
use App\Support\Str;

/**
 * Publish gate against "scraped content" (Google spam policy: text copied from other sites with
 * light modifications such as synonym substitution). Measures a spun post against its source texts:
 *
 *  - shared: share (0..1) of the post's word 6-grams that also occur in a source (union of sources);
 *  - run:    the longest run of consecutive post words found verbatim in one source.
 *
 * Quotations of 4+ words (“…”, "…", «…», ‘…’) are excluded: they stay verbatim by design and are
 * attributed as quotes. A post passes when shared <= spinner.max_shared_ngrams (default 0.10) and
 * run <= spinner.max_verbatim_words (default 12); NewsPublisher publishes failing posts noindex.
 */
final class CopyCheck
{
    public const NGRAM = 6;
    public const DEFAULT_MAX_SHARED = 0.10;
    public const DEFAULT_MAX_RUN = 12;

    /** Quoted speech: “…”, "…", «…», ‘…’ (’ alone is the Turkish apostrophe, never an opener). */
    private const QUOTES = '/“[^”]*”|"[^"\n]*"|«[^»]*»|‘[^’]*’/u';

    /**
     * @param list<string> $sources plain source texts
     * @return array{shared: float, run: int, run_text: string, ngrams: int, ok: bool}
     */
    public static function measure(string $text, array $sources): array
    {
        $segments = [];
        foreach (explode("\x1E", self::unquote(self::plain($text), true)) as $segment) {
            $tokens = self::tokens($segment);
            if ($tokens !== []) {
                $segments[] = $tokens;
            }
        }
        $sourceTokens = [];
        $sourceGrams = [];
        foreach ($sources as $source) {
            $tokens = self::tokens(self::unquote(self::plain((string) $source), false));
            if ($tokens === []) {
                continue;
            }
            $sourceTokens[] = $tokens;
            $sourceGrams += self::grams($tokens);
        }

        $total = 0;
        $shared = 0;
        $run = [];
        foreach ($segments as $tokens) {
            foreach (self::grams($tokens) as $gram => $_) {
                $total++;
                if (isset($sourceGrams[$gram])) {
                    $shared++;
                }
            }
            foreach ($sourceTokens as $source) {
                $candidate = self::longestRun($tokens, $source);
                if (count($candidate) > count($run)) {
                    $run = $candidate;
                }
            }
        }
        $report = [
            'shared' => $total > 0 ? round($shared / $total, 4) : 0.0,
            'run' => count($run),
            'run_text' => implode(' ', $run),
            'ngrams' => $total,
            'ok' => true,
        ];
        $report['ok'] = $report['shared'] <= self::maxShared() && $report['run'] <= self::maxRun();
        return $report;
    }

    public static function maxShared(): float
    {
        return (float) Settings::get('spinner.max_shared_ngrams', Config::get('spinner.max_shared_ngrams', self::DEFAULT_MAX_SHARED));
    }

    public static function maxRun(): int
    {
        return (int) Settings::get('spinner.max_verbatim_words', Config::get('spinner.max_verbatim_words', self::DEFAULT_MAX_RUN));
    }

    /** Short Turkish summary for logs and the admin queue note. */
    public static function describe(array $report): string
    {
        return sprintf('kaynakla ortak 6’lı kelime grubu %%%s, en uzun aynen dizi %d kelime',
            number_format($report['shared'] * 100, 1, ',', ''), $report['run']);
    }

    /** HTML => text (tags become word breaks, entities decoded). */
    private static function plain(string $html): string
    {
        return html_entity_decode(strip_tags(str_replace('<', ' <', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Drops quotation marks so post and source tokenise alike ('"RedBird Capital"in' and
     * 'RedBird Capital’in' both give "capitalin"). With $breakLong a quotation of 4+ words becomes
     * a segment break (\x1E) instead: reported speech is not counted as copied text.
     */
    private static function unquote(string $text, bool $breakLong): string
    {
        return (string) preg_replace_callback(self::QUOTES, static function (array $m) use ($breakLong): string {
            $inner = mb_substr($m[0], 1, -1);
            return $breakLong && count(self::tokens($inner)) >= 4 ? "\x1E" : $inner;
        }, $text);
    }

    /** @return list<string> normalised word tokens (same normalisation as Spinner::shingles) */
    private static function tokens(string $text): array
    {
        return array_values(array_filter(explode(' ', Str::normalize($text)), static fn (string $t): bool => $t !== ''));
    }

    /** @return array<string, true> */
    private static function grams(array $tokens): array
    {
        $out = [];
        for ($i = 0, $n = count($tokens) - self::NGRAM; $i <= $n; $i++) {
            $out[implode(' ', array_slice($tokens, $i, self::NGRAM))] = true;
        }
        return $out;
    }

    /**
     * Longest common run of consecutive tokens (longest common substring over words, O(n·m)).
     *
     * @return list<string>
     */
    private static function longestRun(array $a, array $b): array
    {
        $best = 0;
        $end = 0;
        $prev = array_fill(0, count($b) + 1, 0);
        foreach ($a as $i => $word) {
            $row = [0];
            foreach ($b as $j => $other) {
                $row[$j + 1] = $word === $other ? $prev[$j] + 1 : 0;
                if ($row[$j + 1] > $best) {
                    $best = $row[$j + 1];
                    $end = $i;
                }
            }
            $prev = $row;
        }
        return $best > 0 ? array_slice($a, $end - $best + 1, $best) : [];
    }
}
