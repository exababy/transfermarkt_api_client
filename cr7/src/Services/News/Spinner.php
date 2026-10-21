<?php

declare(strict_types=1);

namespace App\Services\News;

use App\Core\App;
use App\Support\Str;

/**
 * Turkish synonym spinner (no AI): rewrites news text with the meaning-preserving surface-form
 * rules of content/synonyms-tr.php, applies safe sentence-level transformations and expands the
 * spintax templates of content/news-templates.php.
 *
 * Word level (spin(), rewrite()):
 *  1. Mask, in this order, with letter-free private-use markers: quoted text (“…” "…" ‘…’ «…» '…'),
 *     protected terms longest first incl. an apostrophe suffix ("Al-Nassr’ın"), URLs/handles,
 *     numbers/scores/dates with their suffixes ("2-1'lik", "900.", "%50"), ALL-CAPS words.
 *  2. Tokenise into letter runs (\p{L}, inner hyphen allowed) and non-letter runs.
 *  3. At each word try windows of up to 8 words (longest first) joined by single spaces, never across
 *     punctuation or masks. A window can be
 *       - a 'groups' form: replaced by another member of its group,
 *       - a 'contextual' form: like a group, but only where its context holds (after a quotation,
 *         after a "-dığını/-acağını" complement, at the start of a sentence, …),
 *       - a 'directed' source form: replaced by one of its targets (never the other way round),
 *       - a 'fixed' collocation: kept as it is (it blocks the shorter forms inside it).
 *     The member is chosen with a RNG seeded by the caller's seed (the item's url_hash); the whole
 *     span is then skipped (no re-scan).
 *  4. Skip a match directly followed by an apostrophe or directly after one (a suffix); a match that
 *     is capitalised mid-sentence is replaced only when the data form is capitalised too (protects
 *     names like Zafer, Sevinç, Doğan).
 *  5. Restore sentence-initial capitalisation the Turkish way (i => İ, ı => I).
 *  6. Re-harmonise a separately written clitic after a replaced span: mı/mi/mu/mü (+ -ydı, -sın …)
 *     and de/da ("sona erdi mi" => "son buldu mu", "sözleşme de" => "kontrat da").
 *
 * Sentence level (rewrite(), on the masked sentence, so quotes, numbers and names stay atomic):
 *   front adverbial <-> subject ("Maçın 45. dakikasında Ronaldo, …" <-> "Ronaldo, maçın 45. dakikasında …"),
 *   quotation frames ("X şunları söyledi: “…”" <-> "X, “…” diye konuştu."; "“…” diyen X, …" <-> "X, “…” diyerek …"),
 *   name order in symmetric coordinations ("Messi ile Ronaldo arasındaki" <-> "Ronaldo ile Messi arasındaki"),
 *   splitting at ";" and at clause-final verbs before "ve" / ", ancak|ama|fakat".
 * Every transformation only moves or re-joins existing material (no word of a quote, no number and no
 * name is changed, nothing is added that is not a fixed grammatical frame).
 *
 * Output is deterministic for the same text + seed + rate.
 */
final class Spinner
{
    /** Complete quotations: “…”, "…", «…», ''…'', ‘…’ and '…' (an apostrophe followed by a letter is a suffix, not a quote mark). */
    private const QUOTE_REGEX = '/“[^”]{0,800}”|"[^"\n]{1,800}"|«[^»]{0,800}»|\'\'[^\'\n]{1,800}\'\'|(?<![\p{L}\p{N}])[‘\'](?=\p{L}|\s?\p{Lu})(?:[^\'’\n]|[\'’](?=\p{L}))*[’\'](?![\p{L}\p{N}])/u';

    private const MASK_OPEN = "\u{E000}";
    private const MASK_CLOSE = "\u{E001}";
    private const MASK_BASE = 0xF0000;

    /** Contexts a 'contextual' rule can require (see contextHolds()). */
    public const CONTEXTS = ['quote', 'complement_acc', 'announcement', 'complement_nom', 'start', 'duration', 'number_dative', 'not_dative', 'score', 'verbal_noun', 'infinitive'];

    /** Reported-speech frames that introduce a quotation after a colon. */
    private const COLON_FRAMES = [
        'şunları söyledi', 'şu ifadeleri kullandı', 'şöyle konuştu', 'şu sözleri sarf etti', 'şunları kaydetti',
        'şu açıklamayı yaptı', 'şu ifadelere yer verdi', 'şunları dile getirdi', 'şu açıklamada bulundu',
        'şu sözlerle konuştu', 'şu sözlere yer verdi', 'şunları ifade etti', 'şöyle dedi',
    ];
    /** Verbs that close a quotation: "“…” dedi." */
    private const QUOTE_CLOSERS = ['dedi', 'diye konuştu', 'ifadelerini kullandı', 'şeklinde konuştu', 'sözlerini kullandı'];
    /** Time adverbials that can move in front of the subject. */
    private const TIME_PHRASES = [
        'dün akşam', 'dün gece', 'dün', 'bugün', 'bu akşam', 'bu sabah', 'bu gece', 'yarın akşam', 'yarın',
        'geçtiğimiz hafta', 'geçen hafta', 'geçtiğimiz günlerde', 'geçtiğimiz hafta sonu', 'geçen hafta sonu',
        'geçtiğimiz sezon', 'geçen sezon', 'bu sezon', 'geçtiğimiz yıl', 'geçen yıl', 'hafta sonu',
    ];
    /** Heads of "maçın 45. dakikasında", "ligin 7. haftasında" adverbials. */
    private const ORDINAL_HEADS = ['dakikasında', 'dakikada', 'haftasında', 'haftada', 'turunda', 'dakikalarında'];
    /** Last words of a fronted adverbial besides locatives. */
    private const ADVERBIAL_TAILS = [
        'sonra', 'önce', 'ardından', 'sonrasında', 'öncesinde', 'boyunca', 'sırasında', 'esnasında', 'itibarıyla',
        'dün', 'bugün', 'akşam', 'sabah', 'gece', 'karşısında',
    ];
    /** Words ending in -dı/-di/… that are not finite verbs. */
    private const NOT_FINITE = ['kendi', 'şimdi', 'adı', 'soyadı', 'yedi', 'ordu', 'altı', 'üstü', 'kötü', 'artı', 'batı', 'katı', 'ütü', 'sütü', 'ödü', 'kadı', 'hadi',
        'ayrıntı', 'tartı', 'çıktı', 'gelecek', 'geçmiş', 'yeterince', 'mükemmel'];

    /** @var list<list<string>> group members (plain and contextual groups) */
    private array $groups = [];
    /** @var array<int, string|null> group index => required context (null = none) */
    private array $groupContext = [];
    /** @var list<list<string>> directed rules: [source, target, target, …] */
    private array $directed = [];
    /** @var array<string, list<array{0: string, 1: int}>> lowercase form => rules ['g'|'d'|'f', index] */
    private array $rules = [];
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
        $clean = static fn (array $list): array => array_values(array_unique(array_filter(array_map(static fn ($f): string => trim((string) $f), $list), static fn (string $f): bool => $f !== '')));
        foreach ((array) ($synonyms['groups'] ?? []) as $group) {
            $this->addGroup($clean((array) $group), null);
        }
        foreach ((array) ($synonyms['contextual'] ?? []) as $context => $groups) {
            if (!in_array((string) $context, self::CONTEXTS, true)) {
                continue;
            }
            foreach ((array) $groups as $group) {
                $this->addGroup($clean((array) $group), (string) $context);
            }
        }
        foreach ((array) ($synonyms['directed'] ?? []) as $rule) {
            $rule = $clean((array) $rule);
            if (count($rule) < 2) {
                continue;
            }
            $index = count($this->directed);
            $this->directed[] = $rule;
            $this->addForm($rule[0], 'd', $index);
        }
        foreach ((array) ($synonyms['fixed'] ?? []) as $phrase) {
            $phrase = trim((string) $phrase);
            if ($phrase !== '') {
                $this->addForm($phrase, 'f', 0);
            }
        }
        $this->maxWords = min(8, $this->maxWords);
        $protected = $clean((array) ($synonyms['protected'] ?? []));
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

    /** Number of synonym groups (plain + contextual) and directed rules. */
    public function groupCount(): int
    {
        return count($this->groups) + count($this->directed);
    }

    /**
     * Rewrite $text word by word. $rate = probability that an eligible match is replaced; $maxShare caps
     * the replacements per word (0 = no cap).
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
        if (trim($text) === '' || $this->rules === []) {
            return ['text' => $text, 'replaced' => 0, 'hits' => 0];
        }
        $this->seed($seed . '|' . $text);
        $this->masks = [];
        $result = $this->spinMasked($this->mask($text), $rate, $maxShare);
        $result['text'] = $this->unmask($result['text']);
        return $result;
    }

    /**
     * Sentence-level transformation (at most two per sentence, chosen by the seed with probability
     * $structure) followed by the word-level rewrite of spin().
     *
     * Options: 'structure' (0..1, default 0.9), 'common' (array<lowercase word, true>: words the sources
     * write in lowercase — a capitalised sentence-initial word is lowercased after it moved only when it
     * is listed here or is a plain time word).
     *
     * @return array{text: string, replaced: int, hits: int, ops: list<string>}
     */
    public function rewrite(string $text, string $seed, float $rate = 1.0, array $options = []): array
    {
        if (trim($text) === '') {
            return ['text' => $text, 'replaced' => 0, 'hits' => 0, 'ops' => []];
        }
        $this->seed($seed . '|rw|' . $text);
        $this->masks = [];
        $masked = $this->mask($text);
        $ops = [];
        $masked = $this->restructure($masked, (float) ($options['structure'] ?? 0.9), (array) ($options['common'] ?? []), $ops);
        $result = $this->rules === [] ? ['text' => $masked, 'replaced' => 0, 'hits' => 0] : $this->spinMasked($masked, $rate, 0.0);
        return ['text' => $this->unmask($result['text']), 'replaced' => $result['replaced'], 'hits' => $result['hits'], 'ops' => $ops];
    }

    /**
     * Join two short consecutive sentences of one paragraph with "ve" ("Al-Nassr 2-1 kazandı. Ronaldo
     * gol attı." => "Al-Nassr 2-1 kazandı ve Ronaldo gol attı."), or null when that is not safe: both
     * ≤ 14 words, no quotation, the first ends with a finite past verb + ".", the second does not open
     * with a connector/back-reference and its first word can be lowercased (or is a name).
     *
     * @param array<string, true> $common lowercase words of the sources
     */
    public function merge(string $first, string $second, array $common = []): ?string
    {
        $first = trim($first);
        $second = trim($second);
        if (self::wordCount($first) > 14 || self::wordCount($second) > 14 || self::wordCount($first) < 3 || self::wordCount($second) < 3) {
            return null;
        }
        if (preg_match('/["“”«»]|(?<![\p{L}\p{N}])[‘\'’]/u', $first . ' ' . $second) === 1 || !str_ends_with($first, '.') || str_ends_with($first, '..')) {
            return null;
        }
        $body = mb_substr($first, 0, -1);
        if (preg_match('/(\p{L}+)$/u', $body, $m) !== 1 || !self::isFinite($m[1], true)) {
            return null;
        }
        if (preg_match('/^(?:ve|ama|ancak|fakat|ayrıca|öte|bu|bunun|buna|bunu|o|onun|şu|ise|çünkü|zira|ardından|sonrasında|böylece|dolayısıyla|hatta|yine|aynı|diğer|bununla|üstelik)(?![\p{L}])/iu', Str::lower($second)) === 1) {
            return null;
        }
        if (!preg_match('/[.!?]$/u', $second) || preg_match('/[;:]/u', $first . $second) === 1) {
            return null;
        }
        $firstWord = (string) preg_replace('/^(\p{L}+).*$/su', '$1', $second);
        if ($firstWord === $second || preg_match('/^\p{L}/u', $second) !== 1) {
            return null; // starts with a number or a sign
        }
        $isName = $this->startsWithProtected($second) || preg_match('/^\p{Lu}{2,}/u', $firstWord) === 1 || preg_match('/^\p{L}+[\'’]/u', $second) === 1;
        if (!$isName) {
            $lower = Str::lower($firstWord);
            if (!isset($common[$lower]) && !isset($this->rules[$lower])) {
                return null; // unknown capitalised word: may be a name, keep the sentences apart
            }
            $second = self::lcfirst($second);
        }
        return $body . ' ve ' . $second;
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
        $masked = (string) preg_replace_callback(self::QUOTE_REGEX, static function (array $m) use (&$quotes): string {
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

    /**
     * Finite verb form at the end of a clause ("attı", "kazandılar", "sürüyor", "ayrılacak", "etmişti")?
     * $pastOnly: only simple past -dı/-ti (+ plural).
     */
    public static function isFinite(string $word, bool $pastOnly = false): bool
    {
        $w = Str::lower($word);
        if (mb_strlen($w) < 4 || in_array($w, self::NOT_FINITE, true)) {
            return false;
        }
        if (preg_match('/[^aıeiouöü][dt][ıiuü](?:l[ae]r)?$/u', $w) === 1 || preg_match('/[aıeiouöü]d[ıiuü](?:l[ae]r)?$/u', $w) === 1) {
            return true;
        }
        if ($pastOnly) {
            return false;
        }
        return preg_match('/(?:[ıiuü]yor(?:lar)?(?:d[ıu])?|m[ıiuü]ş(?:t[ıiuü])?(?:l[ae]r)?|[ae]c[ae]k(?:l[ae]r)?(?:t[ıi])?)$/u', $w) === 1;
    }

    // ---------------------------------------------------------------- internals

    private static function loadContent(string $file): array
    {
        $path = App::basePath($file);
        $data = is_file($path) ? require $path : [];
        return is_array($data) ? $data : [];
    }

    private function addGroup(array $group, ?string $context): void
    {
        if (count($group) < 2) {
            return;
        }
        $index = count($this->groups);
        $this->groups[] = $group;
        $this->groupContext[$index] = $context;
        foreach ($group as $form) {
            $this->addForm($form, 'g', $index);
        }
    }

    private function addForm(string $form, string $type, int $index): void
    {
        $lower = Str::lower($form);
        $this->rules[$lower][] = [$type, $index];
        $this->forms[$lower] ??= $form;
        $this->maxWords = max($this->maxWords, substr_count($form, ' ') + 1);
    }

    private static function isWord(string $token): bool
    {
        return preg_match('/^\p{L}/u', $token) === 1;
    }

    private static function wordCount(string $text): int
    {
        return count(preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: []);
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

    /** Word-level rewrite of an already masked text. */
    private function spinMasked(string $masked, float $rate, float $maxShare): array
    {
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
            $hit = $this->matchAt($tokens, $i, $atStart);
            if ($hit === null) {
                $out .= $token;
                $atStart = false;
                $i++;
                continue;
            }
            [$source, $end, $rule] = $hit;
            if ($rule === null) { // fixed collocation
                $out .= $source;
                $atStart = false;
                $i = $end;
                continue;
            }
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
            // Neighbouring words (already written output before, source text after): no new repetitions
            // such as "şu ifadelerle ifade etti" or "dış sahada sahaya çıkacak".
            $near = [];
            if (preg_match_all('/\p{L}+/u', mb_substr($out, -80), $nm) > 0) {
                $near = array_slice($nm[0], -6);
            }
            for ($k = $end, $count = 0; $k < $n && $count < 6; $k++) {
                if (self::isWord($tokens[$k])) {
                    $near[] = $tokens[$k];
                    $count++;
                }
            }
            $replacement = $this->alternative($source, $rule, $prevWord, $near);
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
        return ['text' => $out, 'replaced' => $replaced, 'hits' => $hits];
    }

    /**
     * Longest applicable form starting at token $i: [matched text, index after the span, rule] where rule
     * is ['g'|'d', index] or null for a fixed collocation; null when nothing applies. A contextual form whose
     * context does not hold falls back to shorter windows.
     */
    private function matchAt(array $tokens, int $i, bool $atStart): ?array
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
            $lower = Str::lower($phrase);
            if (!isset($this->rules[$lower])) {
                continue;
            }
            foreach ($this->rules[$lower] as $rule) {
                if ($rule[0] === 'f') {
                    return [$phrase, $j, null];
                }
                if ($rule[0] === 'g' && $this->groupContext[$rule[1]] !== null && !$this->contextHolds($this->groupContext[$rule[1]], $tokens, $i, $j, $atStart)) {
                    continue;
                }
                return [$phrase, $j, $rule];
            }
        }
        return null;
    }

    /**
     * Context of a contextual rule at tokens[$i .. $end):
     *   quote          right after a quotation ("“…” dedi")
     *   complement_acc after a "-dığını/-acağını/-dıklarını" complement ("ayrılacağını söyledi")
     *   announcement   like complement_acc, but not after an opinion or an explanation ("… gerektiğini açıkladı",
     *                  "nasıl … olduğunu açıkladı" = explained, never "duyurdu")
     *   complement_nom after a "-dığı/-acağı" complement ("ayrılacağı açıklandı")
     *   start          at the start of a sentence ("Ayrıca, …")
     *   duration       after a "-dır" duration or "beri" ("yıllardır devam eden")
     *   number_dative  after a number in the dative ("981'e yükseltti")
     *   not_dative     the previous word/name is not (possibly) a dative and is not a speech word
     *                  ("performansı dikkat çekti", never "soruna dikkat çekti")
     *   score          right after a bare score ("Al-Nassr 2-1 kazandı" <-> "Al-Nassr 2-1 galip geldi")
     *   verbal_noun    after a "-masının/-mesinin" verbal noun ("ayrılmasının ardından" <-> "ayrılmasının akabinde")
     *   infinitive     after a "-ma/-mak" infinitive ("kazanma hedefiyle" <-> "kazanma amacıyla")
     */
    private function contextHolds(string $context, array $tokens, int $i, int $end, bool $atStart): bool
    {
        if ($context === 'start') {
            return $atStart;
        }
        $prev = $i > 0 ? $tokens[$i - 1] : '';
        $prevMask = null;
        if (preg_match('/' . self::MASK_OPEN . '(.)' . self::MASK_CLOSE . '([\s,]*)$/u', $prev, $mm) === 1) {
            $prevMask = $this->masks[mb_ord($mm[1]) - self::MASK_BASE] ?? null;
        }
        $prevWord = null;
        if ($prev === ' ' && $i >= 2 && self::isWord($tokens[$i - 2])) {
            $prevWord = Str::lower($tokens[$i - 2]);
        }
        switch ($context) {
            case 'quote':
                return $prevMask !== null && preg_match('/^["“«‘\']/u', $prevMask) === 1 && !str_contains($mm[2] ?? '', ',');
            case 'complement_acc':
                return $prevWord !== null && preg_match('/(?:[dt][ıiuü]ğ[ıiuü]n[ıiuü]|[ae]c[ae]ğ[ıi]n[ıi]|[dt][ıiuü]kl[ae]r[ıi]n[ıi]|[ae]c[ae]kl[ae]r[ıi]n[ıi])$/u', $prevWord) === 1;
            case 'announcement':
                if ($prevWord === null || preg_match('/(?:[dt][ıiuü]ğ[ıiuü]n[ıiuü]|[ae]c[ae]ğ[ıi]n[ıi]|[dt][ıiuü]kl[ae]r[ıi]n[ıi]|[ae]c[ae]kl[ae]r[ıi]n[ıi])$/u', $prevWord) !== 1
                    || preg_match('/^(?:gerek|lazım)/u', $prevWord) === 1) {
                    return false;
                }
                $before = '';
                for ($k = max(0, $i - 14); $k < $i; $k++) {
                    $before .= $tokens[$k];
                }
                return preg_match('/(?<![\p{L}])(?:nasıl|neden|niçin|niye|ne|neyi|kim|kimin|hangi|nerede|nereye|kaç)(?![\p{L}])/u', Str::lower($before)) !== 1;
            case 'complement_nom':
                return $prevWord !== null && preg_match('/(?:[dt][ıiuü]ğ[ıiuü]|[ae]c[ae]ğ[ıi]|[dt][ıiuü]kl[ae]r[ıi]|[ae]c[ae]kl[ae]r[ıi])$/u', $prevWord) === 1;
            case 'duration':
                return $prevWord !== null && ($prevWord === 'beri' || (mb_strlen($prevWord) >= 5 && preg_match('/[dt][ıiuü]r$/u', $prevWord) === 1));
            case 'score':
                return $prevMask !== null && trim($mm[2] ?? '') === '' && preg_match('/^\d{1,2}\s?[-–]\s?\d{1,2}$/u', $prevMask) === 1;
            case 'infinitive':
                return $prevWord !== null && preg_match('/[^aeıioöuü]m[ae]k?$/u', $prevWord) === 1;
            case 'verbal_noun':
                return $prevWord !== null && preg_match('/m[ae]s[ıi]n[ıi]n$/u', $prevWord) === 1;
            case 'number_dative':
                return $prevMask !== null && preg_match('/^[%#]?\d.*[\'’](?:y?[ae]|n[ae])$/u', $prevMask) === 1;
            case 'not_dative':
                if ($prevMask !== null) {
                    return trim($mm[2] ?? '') === '' && preg_match('/^["“«‘\']/u', $prevMask) !== 1 && preg_match('/[aeâ]$/u', Str::lower($prevMask)) !== 1;
                }
                return $prevWord !== null && preg_match('/[aeâ]$/u', $prevWord) !== 1 && !in_array($prevWord, ['diye', 'şöyle', 'böyle', 'şu', 'bu', 'o', 'ne', 'kime', 'neye'], true);
        }
        return false;
    }

    /**
     * Another form for the matched source, or null. After the indefinite article "bir" a plural first word
     * is not allowed ("bir tartışma başlattı" must not become "bir tartışmalara …").
     */
    private function alternative(string $source, array $rule, string $prevWord = '', array $near = []): ?string
    {
        $lower = Str::lower($source);
        $candidates = $rule[0] === 'd' ? array_slice($this->directed[$rule[1]], 1) : $this->groups[$rule[1]];
        $alternatives = array_values(array_filter($candidates, static fn (string $f): bool => Str::lower($f) !== $lower));
        if ($prevWord === 'bir') {
            $isPlural = static fn (string $form): bool => preg_match('/^\p{L}+l[ae]r/u', explode(' ', Str::lower($form))[0]) === 1;
            if (!$isPlural($source)) {
                $alternatives = array_values(array_filter($alternatives, static fn (string $f): bool => !$isPlural($f)));
            }
        }
        if ($near !== [] && $alternatives !== []) {
            $nearStems = self::stems(implode(' ', $near));
            $own = self::stems($source);
            $alternatives = array_values(array_filter($alternatives, static fn (string $f): bool => array_diff_key(array_intersect_key(self::stems($f), $nearStems), $own) === []));
        }
        if ($alternatives === []) {
            return null;
        }
        return $alternatives[$this->randomInt(0, count($alternatives) - 1)];
    }

    /** First four letters of each content word ("ifadelerle" => "ifad"), for the repetition guard. */
    private static function stems(string $text): array
    {
        $out = [];
        foreach (preg_split('/[^\p{L}]+/u', Str::lower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            if (str_starts_with($word, 'maç')) {
                $out['maç'] = true; // short root: "maçı … maçtan"
            } elseif (mb_strlen($word) >= 4 && !in_array($word, ['daha', 'olarak', 'kadar', 'sonra', 'önce', 'için', 'gibi', 'olan', 'oldu', 'ilgili', 'ancak', 'bile', 'şekilde'], true)) {
                $out[mb_substr($word, 0, 4)] = true;
            }
        }
        return $out;
    }

    // ---------------------------------------------------------------- sentence-level transformations

    /**
     * Apply up to two sentence transformations (seeded) to a masked sentence.
     *
     * @param array<string, true> $common
     * @param list<string> $ops names of the applied transformations (out)
     */
    private function restructure(string $masked, float $probability, array $common, array &$ops): string
    {
        if ($probability <= 0) {
            return $masked;
        }
        $inverse = ['frontToSubject' => 'subjectToFront', 'subjectToFront' => 'frontToSubject', 'colonToQuote' => 'quoteToColon',
            'quoteToColon' => 'colonToQuote', 'quoteLed' => 'subjectLed', 'subjectLed' => 'quoteLed', 'swapNames' => 'swapNames'];
        for ($round = 0; $round < 2; $round++) {
            $tokens = $this->structTokens($masked);
            $candidates = [];
            foreach (['frontToSubject', 'subjectToFront', 'colonToQuote', 'quoteToColon', 'quoteLed', 'subjectLed', 'swapNames', 'split'] as $name) {
                if (in_array($name, $ops, true) || (isset($inverse[$name]) && in_array($inverse[$name], $ops, true))) {
                    continue;
                }
                $out = $this->{'t' . ucfirst($name)}($tokens, $common);
                if ($out !== null && $out !== $masked) {
                    $candidates[$name] = $out;
                }
            }
            if ($candidates === [] || $this->random() >= ($round === 0 ? $probability : $probability * 0.5)) {
                break;
            }
            $names = array_keys($candidates);
            $name = $names[$this->randomInt(0, count($names) - 1)];
            $ops[] = $name;
            $masked = $candidates[$name];
        }
        return $masked;
    }

    /**
     * Tokens of a masked sentence: ['t' => 'm' (mask) | 'w' (word, incl. an apostrophe suffix) | 's' (space) | 'p' (punctuation), 'v' => text].
     *
     * @return list<array{t: string, v: string}>
     */
    private function structTokens(string $masked): array
    {
        preg_match_all('/' . self::MASK_OPEN . '.' . self::MASK_CLOSE . '|\p{L}+(?:-\p{L}+)*(?:[\'’]\p{L}+)?|\s+|./su', $masked, $m);
        $out = [];
        foreach ($m[0] as $v) {
            $t = str_starts_with($v, self::MASK_OPEN) ? 'm' : (preg_match('/^\p{L}/u', $v) === 1 ? 'w' : (trim($v) === '' ? 's' : 'p'));
            $out[] = ['t' => $t, 'v' => $v];
        }
        return $out;
    }

    /** Original text of a mask token. */
    private function maskText(array $token): string
    {
        return $this->masks[mb_ord(mb_substr($token['v'], 1, 1)) - self::MASK_BASE] ?? '';
    }

    /** 'quote' (a complete quotation) | 'fragment' (part of a quotation) | 'number' | 'name' | 'other' */
    private function maskKind(array $token): string
    {
        $text = $this->maskText($token);
        if (preg_match('/^(?:“.*”|".*"|«.*»|\'\'.*\'\'|[‘\'].*[’\'])$/su', $text) === 1) {
            return 'quote';
        }
        if (preg_match('/["“”«»‘’\']/u', $text) === 1 && preg_match('/^[%#]?\d/u', $text) !== 1 && preg_match('/^\p{Lu}[\p{L}\-\s]*[\'’]\p{L}+$/u', $text) !== 1) {
            return 'fragment';
        }
        if (preg_match('/^[%#]?\d/u', $text) === 1) {
            return 'number';
        }
        return preg_match('/^\p{Lu}/u', $text) === 1 ? 'name' : 'other';
    }

    /** A name in the nominative (protected term / capitalised word without an apostrophe suffix). */
    private function isNominativeName(array $token, bool $first): bool
    {
        if ($token['t'] === 'm') {
            return $this->maskKind($token) === 'name' && preg_match('/[\'’]/u', $this->maskText($token)) !== 1;
        }
        return $token['t'] === 'w' && !$first && preg_match('/^\p{Lu}/u', $token['v']) === 1 && preg_match('/[\'’]/u', $token['v']) !== 1;
    }

    private function isNameToken(array $token, bool $first): bool
    {
        if ($token['t'] === 'm') {
            return $this->maskKind($token) === 'name';
        }
        return $token['t'] === 'w' && !$first && preg_match('/^\p{Lu}/u', $token['v']) === 1;
    }

    /** Index list of non-space tokens. */
    private static function solid(array $tokens): array
    {
        $out = [];
        foreach ($tokens as $i => $t) {
            if ($t['t'] !== 's') {
                $out[] = $i;
            }
        }
        return $out;
    }

    /** Join tokens [$from, $to) back into text. */
    private static function join(array $tokens, int $from, int $to): string
    {
        $s = '';
        for ($i = $from; $i < $to; $i++) {
            $s .= $tokens[$i]['v'];
        }
        return trim($s);
    }

    /** Lowercase words of tokens[$from, $to) joined by single spaces (null if a non-word is inside). */
    private static function lowerPhrase(array $tokens, array $solid, int $from, int $count): ?string
    {
        $words = [];
        for ($k = $from; $k < $from + $count; $k++) {
            if (!isset($solid[$k]) || $tokens[$solid[$k]]['t'] !== 'w') {
                return null;
            }
            $words[] = Str::lower($tokens[$solid[$k]]['v']);
        }
        return implode(' ', $words);
    }

    /** Sentence-initial word may be lowercased (it is a common word, not a name). */
    private function lowerable(array $token, array $common): bool
    {
        if ($token['t'] !== 'w' || preg_match('/[\'’]/u', $token['v']) === 1 || preg_match('/^\p{Lu}{2,}/u', $token['v']) === 1) {
            return false;
        }
        $lower = Str::lower($token['v']);
        return isset($common[$lower]) || isset($this->rules[$lower]) || in_array($lower, ['dün', 'bugün', 'yarın', 'bu', 'geçtiğimiz', 'geçen', 'maçın', 'karşılaşmanın', 'ligin', 'sezonun', 'mücadelenin', 'ilk', 'ikinci', 'son', 'hafta', 'akşam', 'sabah'], true);
    }

    /** End of the sentence body: index of the final punctuation token (or count). */
    private static function bodyEnd(array $tokens): int
    {
        $end = count($tokens);
        while ($end > 0 && ($tokens[$end - 1]['t'] === 's' || ($tokens[$end - 1]['t'] === 'p' && preg_match('/^[.!?…]$/u', $tokens[$end - 1]['v']) === 1))) {
            $end--;
        }
        return $end;
    }

    /** "Maçın 45. dakikasında Ronaldo, penaltıdan golü attı." => "Ronaldo, maçın 45. dakikasında penaltıdan golü attı." */
    private function tFrontToSubject(array $tokens, array $common): ?string
    {
        $solid = self::solid($tokens);
        $comma = null;
        foreach ($solid as $k => $idx) {
            if ($tokens[$idx]['t'] === 'p') {
                if ($tokens[$idx]['v'] === ',') {
                    $comma = $k;
                }
                break;
            }
        }
        if ($comma === null || $comma < 2 || $comma > 13) {
            return null;
        }
        // Subject: names right before the comma.
        $subjectStart = $comma;
        while ($subjectStart > 0 && $comma - $subjectStart < 4 && $this->isNominativeName($tokens[$solid[$subjectStart - 1]], $subjectStart - 1 === 0)) {
            $subjectStart--;
        }
        if ($subjectStart === $comma || $subjectStart === 0 || $subjectStart > 10) {
            return null;
        }
        // Adverbial: everything before the subject; no quotation inside; ends like an adverbial.
        for ($k = 0; $k < $subjectStart; $k++) {
            $t = $tokens[$solid[$k]];
            if ($t['t'] === 'm' && $this->maskKind($t) === 'quote') {
                return null;
            }
        }
        $last = $tokens[$solid[$subjectStart - 1]];
        $lastText = $last['t'] === 'm' ? $this->maskText($last) : $last['v'];
        $lastLower = Str::lower($lastText);
        // A locative ("maçında", "Riyad'da", "30 Eylül'de") or a time postposition ("maçtan sonra").
        $adverbial = (mb_strlen($lastLower) >= 4 && preg_match('/[dt][ae]$/u', $lastLower) === 1) || in_array($lastLower, self::ADVERBIAL_TAILS, true);
        if (!$adverbial || preg_match('/(?:ken)$/u', $lastLower) === 1) {
            return null;
        }
        // Not a list ("…, Sadio Mané ve …"): the word after the comma is not a name/quote/conjunction.
        if (!isset($solid[$comma + 1])) {
            return null;
        }
        $after = $tokens[$solid[$comma + 1]];
        if ($this->isNameToken($after, false) || $after['t'] === 'p' || ($after['t'] === 'm' && $this->maskKind($after) === 'quote')
            || in_array(Str::lower($after['v']), ['ve', 'ile', 'ya', 'veya'], true)) {
            return null;
        }
        $first = $tokens[$solid[0]];
        $adverbialText = self::join($tokens, $solid[0], $solid[$subjectStart - 1] + 1);
        if ($first['t'] === 'w' && preg_match('/[\'’]/u', $first['v']) !== 1) { // "Riyad'da …" keeps its capital
            if (!$this->lowerable($first, $common)) {
                return null;
            }
            $adverbialText = self::lcfirst($adverbialText);
        }
        $subject = self::join($tokens, $solid[$subjectStart], $solid[$comma - 1] + 1);
        $rest = self::join($tokens, $solid[$comma] + 1, count($tokens));
        if (self::wordCount($rest) < 2) {
            return null;
        }
        return $subject . ', ' . $adverbialText . ' ' . $rest;
    }

    /** "Ronaldo, maçın 45. dakikasında penaltıdan golü attı." => "Maçın 45. dakikasında Ronaldo, penaltıdan golü attı." */
    private function tSubjectToFront(array $tokens, array $common): ?string
    {
        $solid = self::solid($tokens);
        $k = 0;
        while ($k < count($solid) && $k < 4 && $this->isNominativeName($tokens[$solid[$k]], false) && ($k > 0 || $tokens[$solid[$k]]['t'] === 'm' || preg_match('/^\p{Lu}/u', $tokens[$solid[$k]]['v']) === 1)) {
            $k++;
        }
        if ($k === 0 || !isset($solid[$k]) || $tokens[$solid[$k]]['v'] !== ',') {
            return null;
        }
        // The first word may be an ordinary capitalised word ("Takım, …"): require a protected name there.
        if ($tokens[$solid[0]]['t'] !== 'm' && $k === 1) {
            return null;
        }
        $start = $k + 1;
        $length = 0;
        foreach (self::TIME_PHRASES as $phrase) {
            $count = substr_count($phrase, ' ') + 1;
            if (self::lowerPhrase($tokens, $solid, $start, $count) === $phrase) {
                $length = $count;
                break;
            }
        }
        if ($length === 0) {
            // [genitive word] <ordinal number> <head>: "maçın 45. dakikasında", "ligin 7. haftasında", "78. dakikada"
            $p = $start;
            if (isset($solid[$p]) && $tokens[$solid[$p]]['t'] === 'w' && preg_match('/^\p{Ll}+(?:n[ıiuü]n|[ıiuü]n)$/u', $tokens[$solid[$p]]['v']) === 1) {
                $p++;
            }
            if (isset($solid[$p], $solid[$p + 1]) && $tokens[$solid[$p]]['t'] === 'm' && preg_match('/^\d+\.$/u', $this->maskText($tokens[$solid[$p]])) === 1
                && $tokens[$solid[$p + 1]]['t'] === 'w' && in_array(Str::lower($tokens[$solid[$p + 1]]['v']), self::ORDINAL_HEADS, true)) {
                $length = $p + 2 - $start;
            }
        }
        if ($length === 0 || !isset($solid[$start + $length])) {
            return null;
        }
        $next = $tokens[$solid[$start + $length]];
        // The adverbial must not belong to a participle ("dün akşam oynanan maçta …") nor go on ("bugün çarşamba günü").
        if ($next['t'] !== 'w' && $next['t'] !== 'm') {
            return null;
        }
        if ($next['t'] === 'w' && preg_match('/^(?:pazartesi|salı|çarşamba|perşembe|cuma|cumartesi|pazar|ocak|şubat|mart|nisan|mayıs|haziran|temmuz|ağustos|eylül|ekim|kasım|aralık|gün|akşam|sabah|gece|öğle|saat|hafta|itibar|sonu)/u', Str::lower($next['v'])) === 1) {
            return null;
        }
        if ($next['t'] === 'w' && preg_match('/(?:(?<![dt])[ae]n|ğ[ıiuü]|[ae]c[ae]k|m[ıiuü]ş|k[ıiuü]|[dt][ıiuü]k)$/u', Str::lower($next['v'])) === 1) {
            return null;
        }
        $rest = self::join($tokens, $solid[$start + $length], count($tokens));
        if (self::wordCount($rest) < 3) {
            return null;
        }
        $subject = self::join($tokens, $solid[0], $solid[$k - 1] + 1);
        $adverbial = self::join($tokens, $solid[$start], $solid[$start + $length - 1] + 1);
        return self::ucfirst($adverbial) . ' ' . $subject . ', ' . $rest;
    }

    /** "Ronaldo, maçtan sonra şunları söyledi: “…”" => "Ronaldo, maçtan sonra “…” diye konuştu." */
    private function tColonToQuote(array $tokens, array $common): ?string
    {
        $solid = self::solid($tokens);
        $n = count($solid);
        if ($n < 4) {
            return null;
        }
        // … FRAME : QUOTE [.]
        $q = $n - 1;
        if ($tokens[$solid[$q]]['t'] === 'p' && $tokens[$solid[$q]]['v'] === '.') {
            $q--;
        }
        if ($q < 3 || $tokens[$solid[$q]]['t'] !== 'm' || $this->maskKind($tokens[$solid[$q]]) !== 'quote' || $tokens[$solid[$q - 1]]['v'] !== ':') {
            return null;
        }
        foreach (self::COLON_FRAMES as $frame) {
            $count = substr_count($frame, ' ') + 1;
            $from = $q - 1 - $count;
            if ($from < 1 || self::lowerPhrase($tokens, $solid, $from, $count) !== $frame) {
                continue;
            }
            $pre = self::join($tokens, $solid[0], $solid[$from]);
            $quote = $tokens[$solid[$q]]['v'];
            $closer = ['diye konuştu', 'ifadelerini kullandı', 'dedi'][$this->randomInt(0, 2)];
            return rtrim($pre) . ' ' . $quote . ' ' . $closer . '.';
        }
        return null;
    }

    /** "Ronaldo, “Takımı bırakmadım.” dedi." => "Ronaldo şunları söyledi: “Takımı bırakmadım.”" */
    private function tQuoteToColon(array $tokens, array $common): ?string
    {
        $solid = self::solid($tokens);
        $n = count($solid);
        if ($n < 4 || $tokens[$solid[$n - 1]]['v'] !== '.') {
            return null;
        }
        foreach (self::QUOTE_CLOSERS as $closer) {
            $count = substr_count($closer, ' ') + 1;
            $from = $n - 1 - $count;
            if ($from < 2 || self::lowerPhrase($tokens, $solid, $from, $count) !== $closer) {
                continue;
            }
            $quoteToken = $tokens[$solid[$from - 1]];
            if ($quoteToken['t'] !== 'm' || $this->maskKind($quoteToken) !== 'quote') {
                return null;
            }
            $quote = $this->maskText($quoteToken);
            // A full sentence in quotes: capital start, final punctuation inside the quotation marks.
            if (preg_match('/^["“«‘\']\p{Lu}.*[.!?…]["”»’\']$/u', $quote) !== 1) {
                return null;
            }
            $pre = rtrim(self::join($tokens, $solid[0], $solid[$from - 1]), " ,");
            if ($pre === '' || self::wordCount($pre) < 1 || preg_match('/[:;]$/u', $pre) === 1) {
                return null;
            }
            $frame = ['şunları söyledi', 'şu ifadeleri kullandı', 'şöyle konuştu'][$this->randomInt(0, 2)];
            return $pre . ' ' . $frame . ': ' . $quoteToken['v'];
        }
        return null;
    }

    /** "“…” diyen Ten Hag, Ronaldo'yu eleştirdi." => "Ten Hag, “…” diyerek Ronaldo'yu eleştirdi." */
    private function tQuoteLed(array $tokens, array $common): ?string
    {
        $solid = self::solid($tokens);
        if (count($solid) < 6 || $tokens[$solid[0]]['t'] !== 'm' || $this->maskKind($tokens[$solid[0]]) !== 'quote') {
            return null;
        }
        $verbs = ['diyen' => 'diyerek', 'ifadelerini kullanan' => 'ifadelerini kullanarak'];
        foreach ($verbs as $from => $to) {
            $count = substr_count($from, ' ') + 1;
            if (self::lowerPhrase($tokens, $solid, 1, $count) !== $from) {
                continue;
            }
            $k = 1 + $count;
            $s = $k;
            while ($s < count($solid) && $s - $k < 4 && $this->isNominativeName($tokens[$solid[$s]], false)) {
                $s++;
            }
            if ($s === $k || !isset($solid[$s]) || $tokens[$solid[$s]]['v'] !== ',') {
                return null;
            }
            $rest = self::join($tokens, $solid[$s] + 1, count($tokens));
            if (self::wordCount($rest) < 2) {
                return null;
            }
            $subject = self::join($tokens, $solid[$k], $solid[$s - 1] + 1);
            return $subject . ', ' . $tokens[$solid[0]]['v'] . ' ' . $to . ' ' . $rest;
        }
        return null;
    }

    /** "Ten Hag, “…” diyerek Ronaldo'yu eleştirdi." => "“…” diyen Ten Hag, Ronaldo'yu eleştirdi." */
    private function tSubjectLed(array $tokens, array $common): ?string
    {
        $solid = self::solid($tokens);
        $k = 0;
        while ($k < count($solid) && $k < 4 && $this->isNominativeName($tokens[$solid[$k]], false)) {
            $k++;
        }
        if ($k === 0 || !isset($solid[$k + 1]) || $tokens[$solid[$k]]['v'] !== ',' || $tokens[$solid[$k + 1]]['t'] !== 'm' || $this->maskKind($tokens[$solid[$k + 1]]) !== 'quote') {
            return null;
        }
        if ($tokens[$solid[0]]['t'] !== 'm' && $k === 1) {
            return null; // a single capitalised word at the start may be an ordinary word
        }
        $verbs = ['diyerek' => 'diyen', 'ifadelerini kullanarak' => 'ifadelerini kullanan'];
        foreach ($verbs as $from => $to) {
            $count = substr_count($from, ' ') + 1;
            if (self::lowerPhrase($tokens, $solid, $k + 2, $count) !== $from) {
                continue;
            }
            $restStart = $k + 2 + $count;
            if (!isset($solid[$restStart])) {
                return null;
            }
            $rest = self::join($tokens, $solid[$restStart], count($tokens));
            if (self::wordCount($rest) < 2) {
                return null;
            }
            $subject = self::join($tokens, $solid[0], $solid[$k - 1] + 1);
            return $tokens[$solid[$k + 1]]['v'] . ' ' . $to . ' ' . $subject . ', ' . $rest;
        }
        return null;
    }

    /** "Messi ile Ronaldo arasındaki …" => "Ronaldo ile Messi arasındaki …" (symmetric coordination of two names). */
    private function tSwapNames(array $tokens, array $common): ?string
    {
        $count = count($tokens);
        // Two coordinations of names ("Ronaldo ve Messi …, Real Madrid ve Barcelona ile …") pair up
        // respectively: swapping one of them would break the pairing.
        $pairs = 0;
        for ($i = 0; $i + 4 < $count; $i++) {
            if (($tokens[$i]['t'] === 'm' || $this->isNameToken($tokens[$i], false)) && in_array(Str::lower($tokens[$i + 2]['v']), ['ve', 'ile'], true)
                && ($tokens[$i + 4]['t'] === 'm' || $this->isNameToken($tokens[$i + 4], false))) {
                $pairs++;
            }
        }
        if ($pairs !== 1) {
            return null;
        }
        for ($i = 0; $i + 4 < $count; $i++) {
            $a = $tokens[$i];
            if ($a['t'] !== 'm' || $this->maskKind($a) !== 'name' || preg_match('/[\'’]/u', $this->maskText($a)) === 1) {
                continue;
            }
            if ($tokens[$i + 1]['t'] !== 's' || !in_array(Str::lower($tokens[$i + 2]['v']), ['ve', 'ile'], true) || $tokens[$i + 3]['t'] !== 's') {
                continue;
            }
            $b = $tokens[$i + 4];
            if ($b['t'] !== 'm' || $this->maskKind($b) !== 'name' || preg_match('/[\'’]/u', $this->maskText($b)) === 1 || $this->maskText($a) === $this->maskText($b)) {
                continue;
            }
            // Not a fixture ("Portekiz ile İspanya maçında": home team first) and not a longer list ("A, B ve C").
            $next = $tokens[$i + 6] ?? null;
            if ($next !== null && $next['t'] === 'w' && preg_match('/^(?:maç|karşılaş|derbi|müsabaka|mücadele|eşleşme|finali|final)/u', Str::lower($next['v'])) === 1) {
                return null;
            }
            if (($tokens[$i - 1]['v'] ?? '') === ',' || (($tokens[$i - 2]['v'] ?? '') === ',' && ($tokens[$i - 1]['t'] ?? '') === 's')) {
                return null;
            }
            $out = $tokens;
            [$out[$i], $out[$i + 4]] = [$b, $a];
            return self::join($out, 0, $count);
        }
        return null;
    }

    /**
     * Split at "; ", at ", ancak|ama|fakat " after a finite verb, or at " ve " after a finite past verb when
     * both clauses are full sentences (≥ 5 words, the second ends with a finite verb).
     */
    private function tSplit(array $tokens, array $common): ?string
    {
        $solid = self::solid($tokens);
        $n = count($solid);
        if ($n < 10) {
            return null;
        }
        $end = self::bodyEnd($tokens);
        $lastWord = null;
        for ($i = $end - 1; $i >= 0; $i--) {
            if ($tokens[$i]['t'] === 'w') {
                $lastWord = $tokens[$i]['v'];
                break;
            }
            if ($tokens[$i]['t'] !== 's') {
                break;
            }
        }
        $wordsBefore = 0;
        $depth = 0;
        for ($k = 0; $k < $n - 2; $k++) {
            $t = $tokens[$solid[$k]];
            if ($t['t'] === 'p' && in_array($t['v'], ['(', '['], true)) {
                $depth++;
            } elseif ($t['t'] === 'p' && in_array($t['v'], [')', ']'], true)) {
                $depth--;
            }
            if ($t['t'] === 'w' || $t['t'] === 'm') {
                $wordsBefore++;
            }
            if ($depth !== 0 || $wordsBefore < 5) {
                continue;
            }
            $wordsAfter = 0;
            for ($r = $k + 1; $r < $n; $r++) {
                if (in_array($tokens[$solid[$r]]['t'], ['w', 'm'], true)) {
                    $wordsAfter++;
                }
            }
            $prevWord = $t['t'] === 'w' ? $t['v'] : null;
            $next = $tokens[$solid[$k + 1]];
            // "…; …"
            if ($next['t'] === 'p' && $next['v'] === ';' && $wordsAfter >= 4) {
                return $this->splitAt($tokens, $solid[$k] + 1, $solid[$k + 1] + 1, '');
            }
            // "…attı, ancak …"
            if ($prevWord !== null && self::isFinite($prevWord) && $next['v'] === ',' && isset($solid[$k + 2]) && in_array(Str::lower($tokens[$solid[$k + 2]]['v']), ['ancak', 'ama', 'fakat'], true) && $wordsAfter >= 5) {
                return $this->splitAt($tokens, $solid[$k] + 1, $solid[$k + 1] + 1, '');
            }
            // "…attı ve …kazandı."
            if ($prevWord !== null && self::isFinite($prevWord, true) && $next['t'] === 'w' && Str::lower($next['v']) === 've' && $wordsAfter >= 6
                && $lastWord !== null && self::isFinite($lastWord) && $wordsBefore >= 6) {
                $afterVe = $tokens[$solid[$k + 2]] ?? null;
                if ($afterVe === null || !in_array($afterVe['t'], ['w', 'm'], true)) {
                    continue;
                }
                return $this->splitAt($tokens, $solid[$k] + 1, $solid[$k + 1] + 1, '');
            }
        }
        return null;
    }

    /** Text of tokens[0, $cut) + ". " + capitalised text of tokens[$resume, …). */
    private function splitAt(array $tokens, int $cut, int $resume, string $glue): string
    {
        $left = rtrim(self::join($tokens, 0, $cut), " ,;");
        $right = ltrim(self::join($tokens, $resume, count($tokens)));
        if (str_starts_with($right, self::MASK_OPEN)) {
            return $left . '. ' . $glue . $right;
        }
        return $left . '. ' . $glue . self::ucfirst($right);
    }

    // ---------------------------------------------------------------- masking

    private function mask(string $text): string
    {
        $mask = function (array $m): string {
            $this->masks[] = $m[0];
            return self::MASK_OPEN . mb_chr(self::MASK_BASE + count($this->masks) - 1) . self::MASK_CLOSE;
        };
        // 1. Quotes (verbatim), then the fragment of a quotation that opens or closes outside this text
        //    (a sentence cut out of a longer quotation): everything from the opening mark to the end, or
        //    from the start to the closing mark.
        $text = (string) preg_replace_callback(self::QUOTE_REGEX, $mask, $text);
        $text = (string) preg_replace_callback('/(?:“|«|(?<![\p{L}\p{N}])(?:\'\'|[‘\']|")(?=\p{L}|\s?\p{Lu}))[^\n]*$/mu', $mask, $text);
        $text = (string) preg_replace_callback('/^[^“«\n]*?(?:”|»|(?<=[\p{L}.!?…,])(?:\'\'|[’\']|")(?![\p{L}]))/mu', $mask, $text);
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
