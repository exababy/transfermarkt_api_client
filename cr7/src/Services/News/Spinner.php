<?php

declare(strict_types=1);

namespace App\Services\News;

use App\Core\App;
use App\Support\Str;

/**
 * Turkish synonym spinner (no AI): rewrites news text with the meaning-preserving surface-form
 * groups of content/synonyms-tr.php and expands the spintax templates of content/news-templates.php.
 *
 * Engine (follows the synonym author's guidance):
 *  1. Mask, in this order, with letter-free private-use markers: quoted text (“…” "…" ‘…’ «…» '…'),
 *     protected terms longest first incl. an apostrophe suffix ("Al-Nassr’ın"), URLs/handles,
 *     numbers/scores/dates with their suffixes ("2-1'lik", "900.", "%50"), ALL-CAPS words.
 *  2. Tokenise into letter runs (\p{L}, inner hyphen allowed) and non-letter runs.
 *  3. At each word try windows of up to 8 words (longest first) joined by single spaces, never across
 *     punctuation or masks; a hit is replaced by another member of its group chosen with a RNG seeded
 *     by the caller's seed (the item's url_hash), then the whole span is skipped (no re-scan).
 *  4. Skip a match directly followed by an apostrophe or directly after one (a suffix); a match that
 *     is capitalised mid-sentence is replaced only when the data form is capitalised too (protects
 *     names like Zafer, Sevinç, Doğan).
 *  5. Restore sentence-initial capitalisation the Turkish way (i => İ, ı => I).
 *  6. Re-harmonise a separately written clitic after a replaced span: mı/mi/mu/mü (+ -ydı, -sın …)
 *     and de/da ("sona erdi mi" => "son buldu mu", "sözleşme de" => "kontrat da").
 * Output is deterministic for the same text + seed + rate.
 */
final class Spinner
{
    private const MASK_OPEN = "\u{E000}";
    private const MASK_CLOSE = "\u{E001}";
    private const MASK_BASE = 0xF0000;

    /** @var list<list<string>> */
    private array $groups = [];
    /** @var array<string, int> lowercase form => group index */
    private array $map = [];
    /** @var array<string, string> lowercase form => form as written in the data */
    private array $forms = [];
    private int $maxWords = 1;
    /** @var list<string> protected terms, longest first */
    private array $protected = [];
    private ?string $protectedRegex = null;
    /** @var array<string, list<string>> */
    private array $templates = [];

    /** @var list<string> masks of the current call */
    private array $masks = [];
    private int $rngState = 1;

    private static ?self $instance = null;

    public function __construct(?array $synonyms = null, ?array $templates = null)
    {
        $synonyms ??= self::loadContent('content/synonyms-tr.php');
        $templates ??= self::loadContent('content/news-templates.php');
        foreach ((array) ($synonyms['groups'] ?? []) as $group) {
            $group = array_values(array_unique(array_filter(array_map(static fn ($f): string => trim((string) $f), (array) $group))));
            if (count($group) < 2) {
                continue;
            }
            $index = count($this->groups);
            $this->groups[] = $group;
            foreach ($group as $form) {
                $lower = Str::lower($form);
                if (!isset($this->map[$lower])) {
                    $this->map[$lower] = $index;
                    $this->forms[$lower] = $form;
                }
                $this->maxWords = max($this->maxWords, substr_count($form, ' ') + 1);
            }
        }
        $this->maxWords = min(8, $this->maxWords);
        $protected = array_values(array_unique(array_filter(array_map(static fn ($t): string => trim((string) $t), (array) ($synonyms['protected'] ?? [])))));
        usort($protected, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
        $this->protected = $protected;
        foreach ((array) $templates as $key => $list) {
            if (is_array($list)) {
                $this->templates[(string) $key] = array_values(array_map('strval', $list));
            }
        }
    }

    /** Shared instance built from the content files. */
    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /** Drop the shared instance (tests). */
    public static function reset(): void
    {
        self::$instance = null;
    }

    /** @return list<string> */
    public function templates(string $key): array
    {
        return $this->templates[$key] ?? [];
    }

    /** @return list<string> protected terms (longest first) */
    public function protectedTerms(): array
    {
        return $this->protected;
    }

    public function groupCount(): int
    {
        return count($this->groups);
    }

    /**
     * Rewrite $text. $rate = probability that an eligible match is replaced; $maxShare caps the
     * replacements per word (0 = no cap).
     */
    public function spin(string $text, string $seed, float $rate = 0.75, float $maxShare = 0.0): string
    {
        return $this->spinDetailed($text, $seed, $rate, $maxShare)['text'];
    }

    /**
     * @return array{text: string, replaced: int, hits: int}
     */
    public function spinDetailed(string $text, string $seed, float $rate = 0.75, float $maxShare = 0.0): array
    {
        if (trim($text) === '' || $this->map === []) {
            return ['text' => $text, 'replaced' => 0, 'hits' => 0];
        }
        $this->seed($seed . '|' . $text);
        $this->masks = [];
        $masked = $this->mask($text);

        preg_match_all('/\p{L}+(?:-\p{L}+)*|[^\p{L}]+/u', $masked, $m);
        $tokens = $m[0];
        $n = count($tokens);
        $words = 0;
        foreach ($tokens as $t) {
            if (self::isWord($t)) {
                $words++;
            }
        }
        $cap = $maxShare > 0 ? max(1, (int) ceil($words * $maxShare)) : PHP_INT_MAX;

        $out = '';
        $replaced = 0;
        $hits = 0;
        $atStart = true;
        for ($i = 0; $i < $n;) {
            $token = $tokens[$i];
            if (!self::isWord($token)) {
                $out .= $token;
                $atStart = self::updateStart($token, $atStart);
                $i++;
                continue;
            }
            $hit = $this->matchAt($tokens, $i);
            if ($hit === null) {
                $out .= $token;
                $atStart = false;
                $i++;
                continue;
            }
            [$source, $end] = $hit;
            $hits++;
            $prev = $i > 0 ? $tokens[$i - 1] : '';
            $next = $end < $n ? $tokens[$end] : '';
            $eligible = !preg_match('/[\'’`]$/u', $prev) && !preg_match('/^[\'’`]/u', $next) && $replaced < $cap;
            $first = mb_substr($source, 0, 1);
            $capitalised = $first !== Str::lower($first);
            if ($eligible && $capitalised && !$atStart) {
                // Capitalised mid-sentence: only when the data form itself is capitalised (proper noun).
                $dataForm = $this->forms[Str::lower($source)] ?? '';
                $eligible = $dataForm !== '' && mb_substr($dataForm, 0, 1) !== Str::lower(mb_substr($dataForm, 0, 1));
            }
            if (!$eligible || $this->random() >= $rate) {
                $out .= $source;
                $atStart = false;
                $i = $end;
                continue;
            }
            $prevWord = ($i >= 2 && $tokens[$i - 1] === ' ' && self::isWord($tokens[$i - 2])) ? Str::lower($tokens[$i - 2]) : '';
            $replacement = $this->alternative($source, $prevWord);
            if ($replacement === null) {
                $out .= $source;
                $atStart = false;
                $i = $end;
                continue;
            }
            if ($capitalised && $atStart) {
                $replacement = self::ucfirst($replacement);
            }
            $out .= $replacement;
            $replaced++;
            $atStart = false;
            $i = $end;
            // Separately written clitic right after the replaced span: " mi?", " da".
            if ($i + 1 < $n && $tokens[$i] === ' ' && preg_match('/^(?:m[ıiuü](?:y?[dt][ıiuü]|s[ıiuü]n(?:[ıiuü]z)?|y[ıiuü]m|y[ıiuü]z|d[ıiuü]r|ş)?|d[ae])$/u', $tokens[$i + 1]) === 1) {
                $out .= ' ' . self::harmonize($replacement, $tokens[$i + 1]);
                $i += 2;
            }
        }
        return ['text' => $this->unmask($out), 'replaced' => $replaced, 'hits' => $hits];
    }

    /**
     * Expand a template: {{placeholders}} first (exact /\{\{[a-z_]+\}\}/; values are inserted
     * verbatim after spintax expansion so they are never parsed), then nested spintax {a|b|c}.
     */
    public function expand(string $template, array $vars, string $seed): string
    {
        $this->seed($seed . '|' . $template);
        $markers = [];
        $text = (string) preg_replace_callback('/\{\{([a-z_]+)\}\}/', static function (array $m) use ($vars, &$markers): string {
            $markers[] = (string) ($vars[$m[1]] ?? '');
            return "\u{E002}" . (count($markers) - 1) . "\u{E003}";
        }, $template);
        $guard = 0;
        while (preg_match('/\{([^{}]*)\}/u', $text) === 1 && $guard++ < 200) {
            $text = (string) preg_replace_callback('/\{([^{}]*)\}/u', function (array $m): string {
                $options = explode('|', $m[1]);
                return $options[$this->randomInt(0, count($options) - 1)];
            }, $text, 1);
        }
        $text = (string) preg_replace_callback("/\u{E002}(\\d+)\u{E003}/u", static fn (array $m): string => $markers[(int) $m[1]] ?? '', $text);
        $text = (string) preg_replace('/[ \t]{2,}/', ' ', $text);
        $text = (string) preg_replace('/\s+([,.;:!?])/u', '$1', $text);
        return trim($text);
    }

    /** Deterministic choice from a list. */
    public function pick(array $options, string $seed): mixed
    {
        if ($options === []) {
            return null;
        }
        $options = array_values($options);
        $this->seed($seed);
        return $options[$this->randomInt(0, count($options) - 1)];
    }

    /** Deterministic order of a list (Fisher–Yates with the seeded RNG). */
    public function shuffle(array $items, string $seed): array
    {
        $items = array_values($items);
        $this->seed($seed);
        for ($i = count($items) - 1; $i > 0; $i--) {
            $j = $this->randomInt(0, $i);
            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
        }
        return $items;
    }

    /** True when $text starts with a protected term (proper noun: keep its capital letter). */
    public function startsWithProtected(string $text): bool
    {
        foreach ($this->protected as $term) {
            if (str_starts_with($text, $term)) {
                $next = substr($text, strlen($term), 1);
                if ($next === '' || preg_match('/[^\p{L}\p{N}]/u', $next) === 1) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Split text into sentences (never inside quotes, not after ordinals like "3." or abbreviations).
     *
     * @return list<string>
     */
    public static function sentences(string $text): array
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if ($text === '') {
            return [];
        }
        // Protect quotes from splitting.
        $quotes = [];
        $masked = (string) preg_replace_callback('/“[^”]{0,800}”|"[^"]{0,800}"|«[^»]{0,800}»/u', static function (array $m) use (&$quotes): string {
            $quotes[] = $m[0];
            return "\u{E004}" . (count($quotes) - 1) . "\u{E005}";
        }, $text);
        $abbr = ['dr', 'prof', 'doç', 'av', 'st', 'sn', 'no', 'vb', 'vs', 'bkz', 'yy', 'mr', 'mrs', 'jr', 'sr', 'op', 'yrd', 'org', 'alb'];
        $sentences = [];
        $start = 0;
        if (preg_match_all('/[.!?…]+["”’»)\x{E005}\d]*\s+/u', $masked, $matches, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($matches[0] as [$match, $offset]) {
                $end = $offset + strlen($match);
                $before = substr($masked, $start, $offset - $start);
                $lastWord = preg_match('/(\S+)$/u', $before, $w) === 1 ? $w[1] : '';
                if (preg_match('/^\d{1,3}$/', $lastWord) === 1 && $match[0] === '.') {
                    continue; // "3. haftasında", "1. Lig"
                }
                if (in_array(Str::lower(trim($lastWord, '(')), $abbr, true) && $match[0] === '.') {
                    continue;
                }
                if (preg_match('/\G[\p{Lu}\d"“‘\'«(\x{E004}]/u', $masked, $next, 0, $end) !== 1) {
                    continue;
                }
                $sentences[] = trim(substr($masked, $start, $end - $start));
                $start = $end;
            }
        }
        $rest = trim(substr($masked, $start));
        if ($rest !== '') {
            $sentences[] = $rest;
        }
        return array_values(array_filter(array_map(
            static fn (string $s): string => trim((string) preg_replace_callback('/\x{E004}(\d+)\x{E005}/u', static fn (array $m): string => $quotes[(int) $m[1]], $s)),
            $sentences
        ), static fn (string $s): bool => $s !== ''));
    }

    /**
     * Word 3-shingles of normalised text.
     *
     * @return array<string, true>
     */
    public static function shingles(string $text, int $size = 3): array
    {
        $tokens = array_values(array_filter(explode(' ', Str::normalize(strip_tags($text))), static fn (string $t): bool => $t !== ''));
        $out = [];
        $count = count($tokens);
        if ($count === 0) {
            return [];
        }
        if ($count < $size) {
            $out[implode(' ', $tokens)] = true;
            return $out;
        }
        for ($i = 0; $i <= $count - $size; $i++) {
            $out[implode(' ', array_slice($tokens, $i, $size))] = true;
        }
        return $out;
    }

    /**
     * Uniqueness of $text against its sources: share (0..1) of its word 3-shingles that do not
     * occur in a source. 'min' = against EACH source (the lowest value), 'union' = shingles found in
     * none of the sources.
     *
     * @param list<string> $sources
     * @return array{min: float, union: float, per_source: list<float>}
     */
    public static function uniquenessReport(string $text, array $sources): array
    {
        $post = self::shingles($text);
        if ($post === []) {
            return ['min' => 0.0, 'union' => 0.0, 'per_source' => []];
        }
        $total = count($post);
        $per = [];
        $union = [];
        foreach ($sources as $source) {
            $s = self::shingles((string) $source);
            if ($s === []) {
                continue;
            }
            $per[] = (float) (count(array_diff_key($post, $s)) / $total);
            $union += $s;
        }
        if ($per === []) {
            return ['min' => 1.0, 'union' => 1.0, 'per_source' => []];
        }
        return ['min' => (float) min($per), 'union' => (float) (count(array_diff_key($post, $union)) / $total), 'per_source' => $per];
    }

    /** Share (0..1) of $text's 3-shingles absent from every source (the 'min' of uniquenessReport). */
    public static function uniqueness(string $text, array $sources): float
    {
        return self::uniquenessReport($text, $sources)['min'];
    }

    /** Turkish ucfirst (i => İ, ı => I). */
    public static function ucfirst(string $s): string
    {
        if ($s === '') {
            return $s;
        }
        return Str::upper(mb_substr($s, 0, 1)) . mb_substr($s, 1);
    }

    /** Turkish lcfirst (İ => i, I => ı). */
    public static function lcfirst(string $s): string
    {
        if ($s === '') {
            return $s;
        }
        return Str::lower(mb_substr($s, 0, 1)) . mb_substr($s, 1);
    }

    /** Re-harmonise a clitic (mı/mi/mu/mü…, da/de) to the last vowel of $prev. */
    public static function harmonize(string $prev, string $clitic): string
    {
        if (preg_match('/([aıouâûeiöüî])[^aıouâûeiöüî]*$/u', Str::lower($prev), $m) !== 1) {
            return $clitic;
        }
        $vowel = $m[1];
        $back = in_array($vowel, ['a', 'ı', 'o', 'u', 'â', 'û'], true);
        $round = in_array($vowel, ['o', 'u', 'ö', 'ü', 'û'], true);
        if (preg_match('/^(d)([ae])$/u', $clitic, $c) === 1) {
            return $c[1] . ($back ? 'a' : 'e');
        }
        if (preg_match('/^m([ıiuü])(.*)$/u', $clitic, $c) === 1) {
            $high = $back ? ($round ? 'u' : 'ı') : ($round ? 'ü' : 'i');
            $rest = (string) preg_replace('/[ıiuü]/u', $high, $c[2]);
            $rest = (string) preg_replace('/[ae]/u', $back ? 'a' : 'e', $rest);
            return 'm' . $high . $rest;
        }
        return $clitic;
    }

    // ---------------------------------------------------------------- internals

    private static function loadContent(string $file): array
    {
        $path = App::basePath($file);
        $data = is_file($path) ? require $path : [];
        return is_array($data) ? $data : [];
    }

    private static function isWord(string $token): bool
    {
        return preg_match('/^\p{L}/u', $token) === 1;
    }

    /** Sentence-start tracking across a non-word token. */
    private static function updateStart(string $token, bool $atStart): bool
    {
        if (str_contains($token, "\n")) {
            return true;
        }
        $core = (string) preg_replace('/[\s"“”‘’\'«»()\[\]]+/u', '', $token);
        if ($core === '') {
            return $atStart;
        }
        if (preg_match('/[.!?…:]$/u', $core) === 1) {
            return true;
        }
        return false;
    }

    /** Longest synonym form starting at token $i: [matched text, index after the span] or null. */
    private function matchAt(array $tokens, int $i): ?array
    {
        $n = count($tokens);
        for ($w = $this->maxWords; $w >= 1; $w--) {
            $parts = [];
            $j = $i;
            $ok = true;
            for ($k = 0; $k < $w; $k++) {
                if ($j >= $n || !self::isWord($tokens[$j])) {
                    $ok = false;
                    break;
                }
                $parts[] = $tokens[$j];
                $j++;
                if ($k < $w - 1) {
                    if ($j >= $n || $tokens[$j] !== ' ') {
                        $ok = false;
                        break;
                    }
                    $j++;
                }
            }
            if (!$ok) {
                continue;
            }
            $phrase = implode(' ', $parts);
            if (isset($this->map[Str::lower($phrase)])) {
                return [$phrase, $j];
            }
        }
        return null;
    }

    /**
     * Another member of the source form's group, or null. After the indefinite article "bir" a
     * plural first word is not allowed ("bir tartışma başlattı" must not become "bir tartışmalara …").
     */
    private function alternative(string $source, string $prevWord = ''): ?string
    {
        $lower = Str::lower($source);
        $group = $this->groups[$this->map[$lower]];
        $alternatives = array_values(array_filter($group, static fn (string $f): bool => Str::lower($f) !== $lower));
        if ($prevWord === 'bir') {
            $isPlural = static fn (string $form): bool => preg_match('/^\p{L}+l[ae]r/u', explode(' ', Str::lower($form))[0]) === 1;
            if (!$isPlural($source)) {
                $alternatives = array_values(array_filter($alternatives, static fn (string $f): bool => !$isPlural($f)));
            }
        }
        if ($alternatives === []) {
            return null;
        }
        return $alternatives[$this->randomInt(0, count($alternatives) - 1)];
    }

    private function mask(string $text): string
    {
        $mask = function (array $m): string {
            $this->masks[] = $m[0];
            return self::MASK_OPEN . mb_chr(self::MASK_BASE + count($this->masks) - 1) . self::MASK_CLOSE;
        };
        // 1. Quotes (verbatim).
        $text = (string) preg_replace_callback('/“[^”]{0,800}”|"[^"\n]{1,800}"|«[^»]{0,800}»|(?<![\p{L}\p{N}])‘[^’\n]{1,400}’(?![\p{L}])|(?<![\p{L}\p{N}])\'[^\'\n]{2,300}\'(?![\p{L}\p{N}])/u', $mask, $text);
        // 2. Protected terms (longest first) incl. an apostrophe suffix; also their ALL-CAPS spelling.
        $regex = $this->protectedRegex();
        if ($regex !== null) {
            $text = (string) preg_replace_callback($regex, $mask, $text);
        }
        // 3. URLs, e-mails, handles, hashtags.
        $text = (string) preg_replace_callback('~https?://\S+|[\w.+-]+@[\w-]+\.[\w.]+|[@#][\p{L}\p{N}_]+~u', $mask, $text);
        // 4. Numbers, scores, dates, percentages with their suffixes ("2-1'lik", "900.", "%50", "21.30'da").
        $text = (string) preg_replace_callback('/[%#]?\d+(?:[.,:\/\-–]\d+)*(?:[\'’]\p{L}+|\.(?=\s+\p{Ll}))?/u', $mask, $text);
        // 5. ALL-CAPS words (acronyms, shouted headlines): never rewritten.
        $text = (string) preg_replace_callback('/(?<![\p{L}\p{N}])\p{Lu}{2,}(?:[\'’]\p{L}+)?(?![\p{L}\p{N}])/u', $mask, $text);
        return $text;
    }

    private function unmask(string $text): string
    {
        $pattern = '/' . self::MASK_OPEN . '(.)' . self::MASK_CLOSE . '/u';
        for ($guard = 0; $guard < 3 && str_contains($text, self::MASK_OPEN); $guard++) {
            $text = (string) preg_replace_callback($pattern, fn (array $m): string => $this->masks[mb_ord($m[1]) - self::MASK_BASE] ?? '', $text);
        }
        return $text;
    }

    private function protectedRegex(): ?string
    {
        if ($this->protectedRegex !== null) {
            return $this->protectedRegex === '' ? null : $this->protectedRegex;
        }
        if ($this->protected === []) {
            $this->protectedRegex = '';
            return null;
        }
        $terms = [];
        foreach ($this->protected as $term) {
            $terms[] = preg_quote($term, '/');
            $upper = Str::upper($term);
            if ($upper !== $term) {
                $terms[] = preg_quote($upper, '/');
            }
        }
        usort($terms, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        $this->protectedRegex = '/(?<![\p{L}\p{N}])(?:' . implode('|', $terms) . ')(?:[\'’`]\p{L}+)?(?![\p{L}\p{N}])/u';
        return $this->protectedRegex;
    }

    private function seed(string $seed): void
    {
        $hash = hash('sha256', $seed, true);
        $state = unpack('N', substr($hash, 0, 4))[1] ?? 1;
        $this->rngState = $state === 0 ? 0x9E3779B9 : $state;
    }

    /** xorshift32 in [0, 1). */
    private function random(): float
    {
        $x = $this->rngState;
        $x ^= ($x << 13) & 0xFFFFFFFF;
        $x ^= $x >> 17;
        $x ^= ($x << 5) & 0xFFFFFFFF;
        $this->rngState = $x & 0xFFFFFFFF;
        return $this->rngState / 4294967296;
    }

    private function randomInt(int $min, int $max): int
    {
        if ($max <= $min) {
            return $min;
        }
        return $min + (int) floor($this->random() * ($max - $min + 1));
    }
}
