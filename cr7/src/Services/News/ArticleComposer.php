<?php

declare(strict_types=1);

namespace App\Services\News;

use App\Core\App;
use App\Core\Config;
use App\Core\Settings;
use App\Repositories\StatsRepository;
use App\Support\Cache;
use App\Support\Format;
use App\Support\Html;
use App\Support\Str;

/**
 * Builds an ORIGINAL post from a story cluster (no AI): template intro, spun sentences of the
 * primary source followed by non-redundant spun sentences of up to spinner.max_sources other
 * sources (joined with neutral connectors), a stats context paragraph (StatsRepository), a
 * template outro and a "Kaynak:" line linking every source.
 *
 * Uniqueness = share of word 3-shingles of the post that do not occur in a source, measured against
 * EACH source (the lowest value counts). Below spinner.min_change_ratio extra passes are applied;
 * if it is still too low the result is rejected ('low-uniqueness') — near copies are never published.
 */
final class ArticleComposer
{
    /** Candidate pool sizes (words) taken from the lead source / per secondary source. */
    private const LEAD_POOL_WORDS = 420;
    private const SECONDARY_POOL_WORDS = 220;

    /**
     * Per pass: spin rate, words used from the lead / each secondary source, minimum change of a
     * sentence (share of its 3-shingles rewritten) to be used, context and outro sentence counts.
     * Later passes rewrite harder and use less (but better rewritten) source text.
     */
    private const PASSES = [
        ['rate' => 0.85, 'lead' => 170, 'secondary' => 70, 'min_change' => 0.2, 'context' => 2, 'outros' => 1],
        ['rate' => 1.0, 'lead' => 150, 'secondary' => 60, 'min_change' => 0.25, 'context' => 2, 'outros' => 2],
        ['rate' => 1.0, 'lead' => 125, 'secondary' => 55, 'min_change' => 0.34, 'context' => 3, 'outros' => 2],
    ];

    /** Sentence openers that refer back to context we may not have (skipped for secondary sources). */
    private const ANAPHORIC = '/^(?:bu|bunun|buna|bunu|bunlar\p{L}*|şu|o|onun|ona|onu|söz konusu|ardından|sonrasında|ise|ancak|fakat|ama|çünkü|zira|dolayısıyla|böylece|ayrıca|öte|bunun üzerine|aynı|diğer|son olarak|hatta|yine)(?![\p{L}])/iu';

    /** Opening words that already work as a connector (no extra connector is added). */
    private const OPENER = '/^(?:öte yandan|bunun yanı sıra|ayrıca|diğer yandan|diğer taraftan|bu arada|buna ek olarak|ek olarak|ancak|fakat|ama|öte taraftan|bunun dışında)(?![\p{L}])/iu';

    public function __construct(
        private ?Spinner $spinner = null,
        private ?ArticleExtractor $extractor = null,
    ) {
        $this->spinner ??= Spinner::instance();
        $this->extractor ??= new ArticleExtractor();
    }

    /**
     * @param array<string, mixed> $primary news_items row
     * @param list<array<string, mixed>> $members other news_items rows of the cluster
     * @return array<string, mixed> ['ok' => true, title, meta_title, summary, body, tags, uniqueness (0..100),
     *         uniqueness_union, source_count, sources, lead, words, passes] or ['ok' => false, 'reason' => …]
     */
    public function compose(array $primary, array $members = []): array
    {
        $seed = (string) ($primary['url_hash'] ?? sha1((string) ($primary['url'] ?? '')));
        $maxSources = max(1, (int) Config::get('spinner.max_sources', 3));
        $mix = (bool) Config::get('spinner.mix_sources', true);
        $minWords = max(50, (int) Config::get('spinner.min_words', 180));
        $minRatio = (float) Settings::get('spinner.min_change_ratio', Config::get('spinner.min_change_ratio', 0.40));

        // ---- 1. source texts: primary first, then other outlets of the cluster
        $candidates = [$primary];
        usort($members, static fn (array $a, array $b): int => strcmp((string) ($b['published_at'] ?? ''), (string) ($a['published_at'] ?? '')));
        foreach ($members as $member) {
            $candidates[] = $member;
        }
        $titles = [];
        foreach ($candidates as $item) {
            $titles[] = (string) $item['title'];
            if (!SourceRegistry::hasText((string) $item['source']) && !empty($item['description'])) {
                foreach (explode("\n", (string) $item['description']) as $line) {
                    $titles[] = trim($line);
                }
            }
        }
        $titles = array_values(array_unique(array_filter($titles)));

        $sources = [];
        $seenHosts = [];
        $tries = 0;
        $offTopic = 0;
        foreach ($candidates as $item) {
            if (count($sources) >= ($mix ? $maxSources + 1 : 1) || $tries >= $maxSources + 4) {
                break;
            }
            if (!SourceRegistry::hasText((string) $item['source'])) {
                continue;
            }
            $host = strtolower((string) parse_url((string) $item['url'], PHP_URL_HOST));
            if ($host !== '' && isset($seenHosts[$host]) && $sources !== []) {
                continue; // one text per outlet
            }
            $tries++;
            $paragraphs = $this->sourceParagraphs($item);
            if ($paragraphs === []) {
                continue;
            }
            if (!RelevanceFilter::mentions(implode(' ', $paragraphs))) {
                $offTopic++; // the article text itself never names him (only a search snippet did)
                continue;
            }
            $seenHosts[$host] = true;
            $sources[] = ['item' => $item, 'paragraphs' => $paragraphs];
        }
        // The primary leads, unless its text is short and another outlet has a much fuller one.
        if (count($sources) > 1 && ArticleExtractor::wordCount($sources[0]['paragraphs']) < 100) {
            $longest = 0;
            foreach ($sources as $i => $source) {
                if (ArticleExtractor::wordCount($source['paragraphs']) > ArticleExtractor::wordCount($sources[$longest]['paragraphs'])) {
                    $longest = $i;
                }
            }
            if ($longest > 0 && ArticleExtractor::wordCount($sources[$longest]['paragraphs']) >= 150) {
                $lead = $sources[$longest];
                unset($sources[$longest]);
                $sources = [$lead, ...array_values($sources)];
            }
        }
        $sources = array_slice($sources, 0, $mix ? $maxSources : 1); // at most spinner.max_sources texts
        if ($sources === [] || ArticleExtractor::wordCount($sources[0]['paragraphs']) < 35) {
            return ['ok' => false, 'reason' => $sources === [] && $offTopic > 0 ? 'off-topic' : 'no-text'];
        }
        // A lead whose headline does not name him must at least talk about him twice.
        $leadMentions = preg_match_all('/(?<![\p{L}\p{N}])(?:ronaldo|cr7)/u', Str::lower(implode(' ', $sources[0]['paragraphs'])));
        if (RelevanceFilter::score((string) $sources[0]['item']['title'], '') < 2 && $leadMentions < 2) {
            return ['ok' => false, 'reason' => 'off-topic'];
        }
        $sourceTexts = array_map(static fn (array $s): string => $s['item']['title'] . "\n" . implode("\n", $s['paragraphs']), $sources);
        $lexicon = self::lexicon($sourceTexts);

        // ---- 2. pick sentences
        $leadParagraphs = $this->leadParagraphs($sources[0]['paragraphs']);
        $selected = [];
        foreach ($leadParagraphs as $p) {
            foreach ($p as $sentence) {
                $selected[] = $sentence;
            }
        }
        $secondary = [];
        foreach (array_slice($sources, 1) as $index => $source) {
            $picked = $this->secondarySentences($source['paragraphs'], $selected);
            if ($picked !== []) {
                $secondary[] = ['sentences' => $picked, 'source' => $index + 1];
                array_push($selected, ...$picked);
            }
        }

        // ---- 3. passes until unique enough (uniqueness = 3-shingles found in NO source)
        $context = $this->contextVars();
        $background = $this->background(Tagger::tags((string) $sources[0]['item']['title'], implode("\n", $sourceTexts)), implode("\n", $sourceTexts));
        $best = null;
        foreach (array_keys(self::PASSES) as $pass) {
            $draft = $this->draft($pass, $seed, $leadParagraphs, $secondary, $context, $lexicon, $background, $minWords);
            $draft['report'] = Spinner::uniquenessReport($draft['text'], $sourceTexts);
            $draft['pass'] = $pass;
            $long = $draft['words'] >= $minWords;
            if ($best === null
                || ($long && ($best['words'] < $minWords || $draft['report']['union'] > $best['report']['union']))
                || (!$long && $best['words'] < $minWords && $draft['words'] > $best['words'])) {
                $best = $draft;
            }
            if ($long && $draft['report']['union'] >= $minRatio) {
                $best = $draft;
                break;
            }
        }
        if ($best['source_mentions'] === 0) {
            return ['ok' => false, 'reason' => 'off-topic']; // none of the used source sentences is about him
        }
        if ($best['words'] < $minWords) {
            return ['ok' => false, 'reason' => 'too-short', 'words' => $best['words'], 'uniqueness' => round($best['report']['union'] * 100, 2), 'text' => $best['text']];
        }
        if ($best['report']['union'] < $minRatio) {
            return ['ok' => false, 'reason' => 'low-uniqueness', 'uniqueness' => round($best['report']['union'] * 100, 2), 'words' => $best['words'], 'text' => $best['text']];
        }
        $usedSecondary = array_values(array_unique($best['secondary_used']));

        // ---- 4. title, summary, tags, body
        $otherTitles = [];
        foreach (array_slice($sources, 1) as $source) {
            $otherTitles[] = (string) $source['item']['title'];
        }
        foreach ($titles as $t) {
            if (count($otherTitles) >= 8) {
                break;
            }
            $otherTitles[] = $t;
        }
        $title = $this->title((string) $sources[0]['item']['title'], $titles, $seed, array_values(array_unique($otherTitles)));
        if ($title === null) {
            return ['ok' => false, 'reason' => 'title-too-similar'];
        }
        $usedItems = [$sources[0]['item']];
        foreach ($usedSecondary as $sourceIndex) {
            $usedItems[] = $sources[$sourceIndex]['item'];
        }
        $links = [];
        foreach ($usedItems as $item) {
            $links[] = [
                'name' => self::sourceName($item),
                'url' => (string) $item['url'],
            ];
        }
        $body = $this->renderBody($best['blocks'], $links);
        $tags = Tagger::tags((string) $sources[0]['item']['title'] . ' ' . $title['title'], implode("\n", $sourceTexts));

        return [
            'ok' => true,
            'title' => $title['title'],
            'meta_title' => $title['meta_title'],
            'summary' => self::summary($best['lead_text']),
            'body' => $body,
            'tags' => $tags,
            'uniqueness' => round($best['report']['union'] * 100, 2),
            'uniqueness_per_source' => round($best['report']['min'] * 100, 2),
            'source_count' => count($usedItems),
            'sources' => $links,
            'lead' => $sources[0]['item'],
            'words' => $best['words'],
            'passes' => $best['pass'] + 1,
            'text' => $best['text'],
            'source_texts' => $sourceTexts,
        ];
    }

    /** Readable paragraphs of one item: feed full text, else the article page, else a long description. */
    public function sourceParagraphs(array $item): array
    {
        $hash = (string) ($item['url_hash'] ?? sha1((string) $item['url']));
        $feed = Cache::get('news:feedtext:' . $hash);
        if (is_string($feed) && $feed !== '') {
            $paragraphs = ArticleExtractor::paragraphsFromText($feed);
            if (ArticleExtractor::wordCount($paragraphs) >= ArticleExtractor::MIN_WORDS) {
                return $paragraphs;
            }
        }
        $extracted = $this->extractor->extract((string) $item['url']);
        if ($extracted !== null && $extracted['words'] >= 35) {
            return $extracted['paragraphs'];
        }
        $description = trim((string) ($item['description'] ?? ''));
        if ($description !== '' && self::words($description) >= 30) {
            return [$description];
        }
        return [];
    }

    // ------------------------------------------------------------------ drafting

    /**
     * One rewrite pass.
     *
     * @return array{blocks: list<array{type: string, text: string}>, text: string, lead_text: string, words: int, secondary_used: list<int>}
     */
    private function draft(int $pass, string $seed, array $leadParagraphs, array $secondary, array $context, array $lexicon, ?array $background = null, int $minWords = 180): array
    {
        $cfg = self::PASSES[$pass];
        $sp = $this->spinner;
        $blocks = [];
        $vars = ['player' => 'Cristiano Ronaldo'] + $context;

        $blocks[] = ['type' => 'p', 'text' => $sp->expand((string) $sp->pick($sp->templates('intros') ?: ['{{player}} ile ilgili yeni bir gelişme yaşandı.'], $seed . '|intro|' . $pass), $vars, $seed . '|intro')];

        // Lead source: the first sentence (key fact) always, others only when rewritten enough.
        // A sentence opening with a back-reference ("Bu karar …") needs its predecessor.
        $leadText = '';
        $words = 0;
        $quotes = 0;
        $k = 0;
        $previousKept = true;
        $paragraph = [];
        $flush = static function () use (&$paragraph, &$blocks, &$leadText): void {
            if ($paragraph === []) {
                return;
            }
            $text = implode(' ', $paragraph);
            if ($leadText === '') {
                $leadText = $text;
            }
            $blocks[] = ['type' => 'p', 'text' => $text];
            $paragraph = [];
        };
        foreach ($leadParagraphs as $sentences) {
            foreach ($sentences as $sentence) {
                $index = $k++;
                $count = self::words($sentence);
                $keep = $index === 0;
                $spun = '';
                if (!$keep && $words + $count <= $cfg['lead'] && ($previousKept || preg_match(self::ANAPHORIC, $sentence) !== 1)) {
                    $spun = $sp->spin($sentence, $seed . '|' . $pass . '|' . $index, $cfg['rate']);
                    $quote = self::hasQuote($sentence);
                    $keep = self::change($spun, $sentence) >= $cfg['min_change'] || ($quote && $quotes === 0);
                } elseif ($keep) {
                    $spun = $sp->spin($sentence, $seed . '|' . $pass . '|' . $index, $cfg['rate']);
                }
                $previousKept = $keep;
                if (!$keep) {
                    continue;
                }
                $quotes += self::hasQuote($sentence) ? 1 : 0;
                $paragraph[] = $spun;
                $words += $count;
                if (count($paragraph) >= 3) {
                    $flush();
                }
            }
            if (count($paragraph) >= 2) {
                $flush(); // keep the source's paragraph breaks when the paragraph is not a single sentence
            }
        }
        $flush();

        // Other outlets: non-redundant, well rewritten sentences behind a neutral connector.
        $connectors = $sp->shuffle($sp->templates('connectors') ?: ['Öte yandan,'], $seed . '|conn');
        $used = [];
        // A short lead leaves its unused word budget to the other outlets (max 120 words each).
        $secondaryBudget = $secondary === [] ? 0 : min(120, $cfg['secondary'] + intdiv(max(0, $cfg['lead'] - $words), count($secondary)));
        foreach ($secondary as $i => $group) {
            $kept = [];
            $groupWords = 0;
            foreach ($group['sentences'] as $j => $sentence) {
                $count = self::words($sentence);
                if ($groupWords + $count > $secondaryBudget) {
                    continue;
                }
                $spun = $sp->spin($sentence, $seed . '|' . $pass . '|s' . $i . '|' . $j, $cfg['rate']);
                if (self::change($spun, $sentence) < $cfg['min_change'] || (self::hasQuote($sentence) && $quotes > 0)) {
                    continue;
                }
                $quotes += self::hasQuote($sentence) ? 1 : 0;
                $kept[] = $spun;
                $groupWords += $count;
            }
            if ($kept === []) {
                continue;
            }
            $kept[0] = $this->withConnector((string) ($connectors[count($used) % max(1, count($connectors))] ?? ''), $kept[0], $lexicon);
            $blocks[] = ['type' => 'p', 'text' => implode(' ', $kept)];
            $used[] = (int) $group['source'];
        }

        if ($background !== null) {
            $blocks[] = ['type' => 'h2', 'text' => $background['heading']];
            $blocks[] = ['type' => 'background', 'text' => $background['text'], 'club' => $background['club'], 'url' => $background['url']];
        }

        // Stats context + closing sentences; with short source material up to three more stat
        // sentences and one more closing sentence are added (never more).
        $contextPool = $this->contextSentences($vars, $seed . '|ctx', $cfg['context'] + 3);
        $outros = $sp->shuffle($sp->templates('outros') ?: ['{{player}} ile ilgili gelişmeleri takip etmeye devam edeceğiz.'], $seed . '|outro');
        $outroPool = [];
        foreach (array_slice($outros, 0, $cfg['outros'] + 1) as $o => $template) {
            $outroPool[] = $sp->expand((string) $template, $vars, $seed . '|outro' . $o);
        }
        $contextCount = min(count($contextPool), $cfg['context']);
        $outroCount = min(count($outroPool), $cfg['outros']);
        $baseWords = self::words(implode(' ', array_map(static fn (array $b): string => $b['text'], $blocks)));
        $total = static fn (int $c, int $o): int => $baseWords
            + self::words(implode(' ', array_slice($contextPool, 0, $c)))
            + self::words(implode(' ', array_slice($outroPool, 0, $o)));
        while ($total($contextCount, $outroCount) < $minWords) {
            if ($contextCount < count($contextPool)) {
                $contextCount++;
            } elseif ($outroCount < count($outroPool)) {
                $outroCount++;
            } else {
                break;
            }
        }
        if ($contextCount > 0) {
            $blocks[] = ['type' => 'h2', 'text' => (string) $sp->pick(['Rakamlarla Cristiano Ronaldo', 'Cristiano Ronaldo’nun kariyer rakamları', 'Kariyerinde öne çıkan rakamlar'], $seed . '|h2')];
            $blocks[] = ['type' => 'context', 'text' => implode(' ', array_slice($contextPool, 0, $contextCount))];
        }
        $blocks[] = ['type' => 'outro', 'text' => implode(' ', array_slice($outroPool, 0, max(1, $outroCount)))];

        $sourceMentions = 0;
        foreach ($blocks as $i => $block) {
            if ($i > 0 && $block['type'] === 'p' && RelevanceFilter::mentions($block['text'])) { // lead + secondary paragraphs
                $sourceMentions++;
            }
        }
        $plain = implode("\n", array_map(static fn (array $b): string => $b['text'], $blocks));
        return [
            'source_mentions' => $sourceMentions,
            'blocks' => $blocks,
            'text' => $plain,
            'lead_text' => $leadText,
            'words' => count(preg_split('/\s+/u', trim($plain), -1, PREG_SPLIT_NO_EMPTY) ?: []),
            'secondary_used' => $used,
        ];
    }

    /** Share (0..1) of a sentence's 3-shingles changed by spinning. */
    private static function change(string $spun, string $original): float
    {
        return Spinner::uniquenessReport($spun, [$original])['min'];
    }

    /** Lead source sentences grouped into paragraphs (≤ 3 sentences, ≤ LEAD_POOL_WORDS words in total). */
    private function leadParagraphs(array $paragraphs): array
    {
        $out = [];
        $words = 0;
        $seen = [];
        foreach ($paragraphs as $paragraph) {
            $group = [];
            foreach (Spinner::sentences($paragraph) as $sentence) {
                if (!self::usableSentence($sentence) || self::isRedundant($sentence, $seen)) {
                    continue;
                }
                $count = self::words($sentence);
                if ($words + $count > self::LEAD_POOL_WORDS && $words > 0) {
                    break 2;
                }
                $group[] = $sentence;
                $seen[] = $sentence;
                $words += $count;
                if (count($group) === 3) {
                    $out[] = $group;
                    $group = [];
                }
            }
            if ($group !== []) {
                $out[] = $group;
            }
        }
        // Avoid one-sentence paragraphs where possible.
        $merged = [];
        foreach ($out as $group) {
            $last = array_key_last($merged);
            if ($last !== null && (count($merged[$last]) === 1 || count($group) === 1) && count($merged[$last]) + count($group) <= 3) {
                $merged[$last] = [...$merged[$last], ...$group];
            } else {
                $merged[] = $group;
            }
        }
        return $merged;
    }

    /** Non-redundant sentences of a secondary source (≤ SECONDARY_POOL_WORDS words, max 8). */
    private function secondarySentences(array $paragraphs, array $selected): array
    {
        $out = [];
        $words = 0;
        foreach ($paragraphs as $paragraph) {
            foreach (Spinner::sentences($paragraph) as $sentence) {
                if (!self::usableSentence($sentence) || self::words($sentence) < 8 || preg_match(self::ANAPHORIC, $sentence) === 1) {
                    continue;
                }
                if (self::isRedundant($sentence, [...$selected, ...$out])) {
                    continue;
                }
                $count = self::words($sentence);
                if ($words + $count > self::SECONDARY_POOL_WORDS) {
                    continue;
                }
                $out[] = $sentence;
                $words += $count;
                if (count($out) >= 8) {
                    return $out;
                }
            }
        }
        return $out;
    }

    private static function usableSentence(string $sentence): bool
    {
        $words = self::words($sentence);
        if ($words < 5 || $words > 70 || preg_match('#https?://|www\.|@\w+|tıkla|abone|reklam|instagram\.com#iu', $sentence) === 1) {
            return false;
        }
        if (ArticleExtractor::isKeywordList($sentence)) {
            return false;
        }
        // Must end like a sentence.
        return preg_match('/[.!?…"”’»)]$/u', $sentence) === 1;
    }

    /** Same information as an already selected sentence? */
    private static function isRedundant(string $sentence, array $selected): bool
    {
        $stems = Clusterer::stems($sentence);
        foreach ($selected as $other) {
            if (Str::similarity($sentence, $other) >= 0.4) {
                return true;
            }
            $otherStems = Clusterer::stems($other);
            if ($stems !== [] && $otherStems !== [] && count(array_intersect($stems, $otherStems)) / count(array_unique([...$stems, ...$otherStems])) >= 0.55) {
                return true;
            }
            $a = Spinner::shingles($sentence);
            $b = Spinner::shingles($other);
            if ($a !== [] && count(array_intersect_key($a, $b)) / count($a) >= 0.5) {
                return true;
            }
        }
        return false;
    }

    /** "Öte yandan," + sentence with a Turkish-aware lowercase first letter (unless a proper noun). */
    private function withConnector(string $connector, string $sentence, array $lexicon): string
    {
        $connector = trim($connector);
        if ($connector === '' || preg_match(self::OPENER, $sentence) === 1) {
            return $sentence;
        }
        $first = (string) preg_replace('/^[^\p{L}]*(\p{L}+).*$/su', '$1', $sentence);
        $startsWithLetter = preg_match('/^\p{L}/u', $sentence) === 1;
        // Keep the capital letter for names: protected terms, acronyms and words the sources also
        // write with a capital letter in the middle of a sentence.
        $second = (string) preg_replace('/^[^\p{L}]*\p{L}+[^\p{L}]+(\p{L}+).*$/su', '$1', $sentence);
        $proper = !$startsWithLetter
            || $this->spinner->startsWithProtected($sentence)
            || ($second !== $sentence && preg_match('/^\p{Lu}/u', $second) === 1) // "Disiplin Kurulu", "Portekiz Futbol …"
            || preg_match('/^\p{Lu}{2,}/u', $first) === 1
            || (isset($lexicon['proper'][$first]) && !isset($lexicon['common'][Str::lower($first)]));
        return $connector . ' ' . ($proper ? $sentence : Spinner::lcfirst($sentence));
    }

    /**
     * Words of the sources: 'common' = written in lowercase somewhere, 'proper' = capitalised in
     * the middle of a sentence (names). Used to decide whether a sentence-initial word may be
     * lowercased after a connector.
     */
    private static function lexicon(array $texts): array
    {
        $lexicon = ['common' => [], 'proper' => []];
        foreach ($texts as $text) {
            if (preg_match_all('/(?<![\p{L}])\p{Ll}+/u', $text, $m) > 0) {
                foreach ($m[0] as $w) {
                    $lexicon['common'][$w] = true;
                }
            }
            if (preg_match_all('/(?<=[\p{Ll},;] )\p{Lu}\p{Ll}+/u', $text, $m) > 0) {
                foreach ($m[0] as $w) {
                    $lexicon['proper'][$w] = true;
                }
            }
        }
        return $lexicon;
    }

    // ------------------------------------------------------------------ stats context

    /**
     * Background paragraph about the club the story is about (trusted text of content/career.php),
     * only for clubs he played for; links to the club page.
     *
     * @param list<array{name: string, slug: string, type: string}> $tags
     * @return array{heading: string, text: string, club: string, url: string}|null
     */
    public function background(array $tags, string $text): ?array
    {
        $pages = Tagger::clubPages();
        $file = App::basePath('content/career.php');
        $career = is_file($file) ? require $file : [];
        $clubs = (array) ($career['clubs'] ?? []);
        foreach ($tags as $tag) {
            if ($tag['type'] !== 'club' || !isset($pages[$tag['name']])) {
                continue;
            }
            $slug = substr($pages[$tag['name']], strlen('/takimlar/'));
            $matches = [];
            foreach ($clubs as $key => $club) {
                if (is_array($club) && ($club['slug'] ?? '') === $slug && !empty($club['summary'])) {
                    $matches[(string) $key] = $club;
                }
            }
            if ($matches === []) {
                continue;
            }
            // Manchester United: the 2021–22 spell when the story is about it.
            $chosen = reset($matches);
            if (count($matches) > 1 && preg_match('/Ten Hag|Solskj|Rangnick|2021|2022/u', $text) === 1) {
                $chosen = end($matches);
            }
            return [
                'heading' => 'Cristiano Ronaldo ve ' . $tag['name'],
                'text' => trim(strip_tags((string) $chosen['summary'])),
                'club' => $tag['name'],
                'url' => $pages[$tag['name']],
            ];
        }
        return null;
    }

    /** Placeholder values for the context templates (empty when unknown). */
    public function contextVars(): array
    {
        $vars = ['age' => '', 'apps' => '', 'goals' => '', 'assists' => '', 'club' => '', 'national_goals' => '', 'national_caps' => ''];
        if (class_exists(StatsRepository::class)) {
            try {
                $stats = new StatsRepository();
                $player = $stats->player();
                $career = $stats->careerTotals();
                $national = $stats->nationalTotals();
                if (is_array($player)) {
                    $vars['age'] = !empty($player['age']) ? (string) (int) $player['age'] : '';
                    $vars['club'] = trim((string) ($player['current_club_name'] ?? ''));
                }
                foreach (['apps' => 'appearances', 'goals' => 'goals', 'assists' => 'assists'] as $var => $key) {
                    $value = (int) ($career[$key] ?? 0);
                    $vars[$var] = $value > 0 ? Format::number($value) : '';
                }
                $vars['national_caps'] = (int) ($national['games'] ?? 0) > 0 ? Format::number((int) $national['games']) : '';
                $vars['national_goals'] = (int) ($national['goals'] ?? 0) > 0 ? Format::number((int) $national['goals']) : '';
            } catch (\Throwable) {
                // stats module not installed / not synced yet
            }
        }
        if ($vars['age'] === '') {
            $vars['age'] = (string) (new \DateTimeImmutable('1985-02-05'))->diff(new \DateTimeImmutable('now'))->y;
        }
        if ($vars['club'] === '') {
            $vars['club'] = self::currentClubFromContent();
        }
        return $vars;
    }

    /** Up to $count context sentences whose placeholders all have values. */
    private function contextSentences(array $vars, string $seed, int $count): array
    {
        $out = [];
        $usedStats = [];
        foreach ($this->spinner->shuffle($this->spinner->templates('context'), $seed) as $template) {
            if (preg_match_all('/\{\{([a-z_]+)\}\}/', $template, $m) === 0) {
                continue;
            }
            $needed = array_diff(array_unique($m[1]), ['player']);
            $ok = true;
            foreach ($needed as $name) {
                if (($vars[$name] ?? '') === '' || (in_array($name, ['apps', 'goals', 'assists', 'national_goals', 'national_caps'], true) && $vars[$name] === '0')) {
                    $ok = false;
                    break;
                }
            }
            // Only the age/club are known (no synced stats): skip templates that would only repeat them.
            if (!$ok || $needed === [] || array_intersect($needed, $usedStats) !== []) {
                continue;
            }
            $sentence = $this->spinner->expand($template, $vars, $seed . '|' . count($out));
            if (preg_match('/^(?:Bilindiği gibi|Hatırlanacağı üzere)/u', $sentence) === 1) {
                continue; // presupposes earlier news
            }
            $out[] = $sentence;
            $usedStats = [...$usedStats, ...$needed];
            if (count($out) >= $count) {
                break;
            }
        }
        return $out;
    }

    private static function currentClubFromContent(): string
    {
        $file = App::basePath('content/career.php');
        $career = is_file($file) ? require $file : [];
        foreach ((array) ($career['clubs'] ?? []) as $club) {
            if (is_array($club) && array_key_exists('to', $club) && $club['to'] === null && !empty($club['name'])) {
                return (string) $club['name'];
            }
        }
        return '';
    }

    // ------------------------------------------------------------------ title / summary / body

    /**
     * Spun title that differs from every source title (Str::similarity < 0.7).
     *
     * @param list<string> $sourceTitles
     * @return array{title: string, meta_title: string}|null
     */
    public function title(string $base, array $sourceTitles, string $seed, array $otherTitles = []): ?array
    {
        $base = $this->cleanTitle($base);
        if ($base === '') {
            return null;
        }
        $sp = $this->spinner;

        // Cores: spun full title, spun clauses, and "his clause: clause of another outlet's title".
        $cores = [];
        for ($k = 0; $k < 6; $k++) {
            $cores[] = ['text' => $sp->spin($base, $seed . '|title|' . $k, 1.0), 'bonus' => 0.0];
        }
        $ownClauses = self::titleClauses($base);
        foreach ($ownClauses as $ci => $clause) {
            if ((count($ownClauses) > 1 || $clause !== $base) && self::words($clause) >= 3) {
                $cores[] = ['text' => $sp->spin($clause, $seed . '|clause|' . $ci, 1.0), 'bonus' => RelevanceFilter::mentions($clause) ? 0.0 : -0.6];
            }
        }
        $mixClauses = [];
        foreach ($otherTitles as $other) {
            foreach (self::titleClauses($this->cleanTitle((string) $other)) as $clause) {
                if (self::words($clause) >= 3 && !RelevanceFilter::mentions($clause)) {
                    $mixClauses[] = $clause;
                }
            }
        }
        foreach ([$base, ...$ownClauses] as $ri => $his) {
            if (!RelevanceFilter::mentions($his) || self::words($his) > 9) {
                continue;
            }
            foreach (array_slice(array_values(array_unique($mixClauses)), 0, 8) as $mi => $info) {
                if (Str::similarity($his, $info) >= 0.3) {
                    continue;
                }
                $cores[] = ['text' => rtrim($sp->spin($his, $seed . '|mix|' . $ri, 1.0), '!.…') . ': ' . Spinner::ucfirst(rtrim($sp->spin($info, $seed . '|mixinfo|' . $mi, 1.0), '.…')), 'bonus' => 0.4];
            }
        }

        $patterns = $sp->templates('title_patterns') ?: ['{{subject}} {{rest}}'];
        $candidates = [];
        $add = function (string $candidate, float $bonus, int $coreWords) use (&$candidates, $sourceTitles, $seed): void {
            $length = mb_strlen($candidate);
            if ($length < 25 || $length > 110 || isset($candidates[$candidate]) || substr_count($candidate, ':') > 1) {
                return;
            }
            $similarity = 0.0;
            foreach ($sourceTitles as $sourceTitle) {
                $similarity = max($similarity, Str::similarity($candidate, $sourceTitle));
            }
            if ($similarity >= 0.7) {
                return;
            }
            $score = (RelevanceFilter::mentions($candidate) ? 2.0 : 0.0)
                + ($length >= 40 && $length <= 90 ? 1.0 : 0.0)
                + ($similarity < 0.6 ? 0.4 : 0.0)
                + ($similarity > 0.15 ? 0.5 : 0.0)      // keep the story recognisable
                + min(1.5, $coreWords / 8)              // keep the information of the headline
                + $bonus
                + (crc32($seed . $candidate) % 1000) / 2500;
            $candidates[$candidate] = ['title' => $candidate, 'score' => $score, 'length' => $length];
        };
        $prefixes = [];
        $suffixes = [];
        foreach ($cores as $vi => $core) {
            $variant = trim((string) preg_replace('/\s+/u', ' ', $core['text']));
            $coreWords = self::words($variant);
            $split = $this->subjectSplit($variant);
            $mentionsAtStart = $split !== null || preg_match('/^(?:Cristiano Ronaldo|Ronaldo|CR7)/u', $variant) === 1;
            foreach ($patterns as $pi => $pattern) {
                $expanded = $sp->expand(str_replace(['{{subject}}', '{{rest}}'], ["\u{E010}", "\u{E011}"], $pattern), [], $seed . '|tp|' . $vi . '|' . $pi);
                $plain = trim($expanded) === "\u{E010} \u{E011}";
                $candidate = $this->applyPattern($expanded, $variant, $split);
                if ($candidate !== null) {
                    $add($candidate, $core['bonus'] + ($plain ? 0.6 : ($split !== null && !str_contains($expanded, "\u{E010} \u{E011}") ? 0.3 : 0.0)), $coreWords);
                } elseif (!$mentionsAtStart && preg_match("/\u{E010}[^\u{E011}]*\\p{L}[^\u{E011}]*:\\s*\u{E011}/u", $expanded) === 1) {
                    // Only "Ronaldo gündemde: …", "Ronaldo cephesinde …: …" — never "Ronaldo: …" (reads as his quote).
                    // Headline about him that does not start with his name: "Ronaldo gündemde: …".
                    $candidate = $this->applyPattern($expanded, $variant, ['Cristiano Ronaldo', $variant]);
                    if ($candidate !== null) {
                        $add($candidate, $core['bonus'] - 0.2, $coreWords);
                    }
                }
                if (str_contains($expanded, "\u{E010} \u{E011}")) {
                    [$before, $after] = explode("\u{E010} \u{E011}", $expanded, 2);
                    if (trim($before) !== '') {
                        $prefixes[trim($before)] = true;
                    }
                    if (trim($after) !== '') {
                        $suffixes[trim($after)] = true;
                    }
                }
            }
        }
        if ($candidates === []) {
            // Last resort: decoration before and after the headline.
            foreach ($cores as $core) {
                $variant = rtrim(trim($core['text']), '!.…');
                if (str_contains($variant, ':') || str_ends_with($variant, '?')) {
                    continue;
                }
                foreach (array_keys($prefixes) as $prefix) {
                    foreach (array_keys($suffixes) as $suffix) {
                        if (str_contains($prefix, ':') && str_contains($suffix, ':')) {
                            continue;
                        }
                        $add(self::tidy($prefix . ' ' . Spinner::ucfirst($variant) . $suffix), $core['bonus'] - 0.5, self::words($variant));
                    }
                }
            }
        }
        if ($candidates === []) {
            return null;
        }
        uasort($candidates, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        $best = reset($candidates);
        // <title>: the headline itself when it is short enough (Google shows ~60–65 chars), else the best short candidate.
        $meta = $best['length'] <= 65 ? $best['title'] : null;
        foreach ($candidates as $c) {
            if ($meta === null && $c['length'] <= 60 && RelevanceFilter::mentions($c['title'])) {
                $meta = $c['title'];
            }
        }
        return ['title' => $best['title'], 'meta_title' => $meta ?? $best['title']];
    }

    /**
     * Headline clauses: split at "! ", "? ", ": ", " | ", " - ", "..." with leading/trailing quotes removed.
     *
     * @return list<string>
     */
    private static function titleClauses(string $title): array
    {
        $out = [];
        foreach (preg_split('/(?<=[!?])\s+|:\s+|\s+\|\s+|\s+[-–]\s+|\.{3}\s*|…\s*/u', $title) ?: [] as $clause) {
            $clause = trim($clause, " \t.,;:");
            // A headline that opens with a quote: keep the part after it.
            $clause = trim((string) preg_replace('/^(?:“[^”]*”|"[^"]*"|\'[^\']*\')\s*:?\s*/u', '', $clause), " \t.,;:");
            $clause = trim($clause, '"“”\'‘’');
            if ($clause !== '' && self::words($clause) >= 2) {
                $out[] = Spinner::ucfirst($clause);
            }
        }
        return array_values(array_unique($out));
    }

    /** Remove "SON DAKİKA |", publisher suffixes and shouting from a source title. */
    public function cleanTitle(string $title): string
    {
        $title = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[\x{200B}-\x{200F}\x{2060}\x{FEFF}\x{00AD}]/u', '', $title)));
        // Prefixes are matched on the Turkish lowercase copy (PCRE /i does not pair İ with i).
        if (preg_match('/^(?:son dakika|flaş haber|flaş|sıcak gelişme|özel haber|video|galeri|foto galeri)\s*[|:!\-–]+\s*/u', Str::lower($title), $m) === 1) {
            $title = mb_substr($title, mb_strlen($m[0]));
        }
        $title = (string) preg_replace('/\s+[-–|]\s+(?:son dakika[^-–|]*|spor haberleri?|futbol haberleri?)$/iu', '', $title);
        // Shouted runs of 3+ ALL-CAPS words ("CRISTIANO RONALDO MİLLİ TAKIMI BIRAKTI MI?") => sentence case.
        $title = (string) preg_replace_callback(
            '/(?<![\p{L}\p{N}])\p{Lu}[\p{Lu}\'’]+(?:[\s,]+[\p{Lu}\d][\p{Lu}\d\'’]*){2,}(?![\p{L}\p{N}])/u',
            fn (array $m): string => $this->restoreProperNouns(Str::lower($m[0])),
            $title
        );
        $title = (string) preg_replace_callback('/(^|[.!?:]\s+)(\p{Ll})/u', static fn (array $m): string => $m[1] . Str::upper($m[2]), $title);
        $title = Spinner::ucfirst($title);
        return trim($title, " \t-–|");
    }

    /** Lowercased text with protected names (Cristiano Ronaldo, Portekiz Milli Takımı …) capitalised again. */
    private function restoreProperNouns(string $lower): string
    {
        foreach ($this->spinner->protectedTerms() as $term) {
            // "CRISTIANO" lowercases to "crıstıano" in Turkish: let i/ı match each other.
            $pattern = strtr(preg_quote(Str::lower($term), '/'), ['i' => '[iı]', 'ı' => '[iı]']);
            $lower = (string) preg_replace('/(?<![\p{L}])' . $pattern . '(?![\p{L}])/u', $term, $lower);
        }
        return $lower;
    }

    /**
     * [subject, rest] when the title starts with his name in the nominative ("Ronaldo kampı terk etti")
     * or with any protected name followed by a comma ("Al-Nassr, …"); a name used as a modifier
     * ("Portekiz kampını terk eden …") is never split off.
     */
    private function subjectSplit(string $title): ?array
    {
        foreach ($this->spinner->protectedTerms() as $term) {
            if (!str_starts_with($title, $term)) {
                continue;
            }
            $after = substr($title, strlen($term));
            $isPlayer = in_array($term, ['Cristiano Ronaldo', 'Ronaldo', 'CR7'], true);
            if (preg_match('/^(,?)\s+(\p{Ll}.*)$/u', $after, $m) === 1 && self::words($m[2]) >= 2 && ($isPlayer || $m[1] === ',')) {
                return [$term, trim($m[2])];
            }
            return null;
        }
        return null;
    }

    private function applyPattern(string $pattern, string $variant, ?array $split): ?string
    {
        $variant = trim($variant);
        $hasColon = str_contains($variant, ':');
        if (str_contains($pattern, "\u{E010} \u{E011}")) {
            [$before, $after] = explode("\u{E010} \u{E011}", $pattern, 2);
            if (($hasColon && (str_contains($before, ':') || str_contains($after, ':'))) || (trim($after) !== '' && preg_match('/[?]$/u', $variant) === 1)) {
                return null;
            }
            $core = trim($after) !== '' ? rtrim($variant, '!.…') : $variant;
            if (str_ends_with(trim($before), ':')) {
                $core = Spinner::ucfirst($core);
            }
            return self::tidy($before . $core . $after);
        }
        if ($split === null || !str_contains($pattern, "\u{E010}") || !str_contains($pattern, "\u{E011}")) {
            return null;
        }
        [$subject, $rest] = $split;
        if (str_contains($rest, ':') && substr_count($pattern, ':') > 0) {
            return null;
        }
        $restPos = mb_strpos($pattern, "\u{E011}");
        $beforeRest = rtrim(mb_substr($pattern, 0, (int) $restPos));
        $rest = str_ends_with($beforeRest, ':') ? Spinner::ucfirst($rest) : $rest;
        return self::tidy(str_replace(["\u{E010}", "\u{E011}"], [$subject, $rest], $pattern));
    }

    private static function tidy(string $title): string
    {
        $title = (string) preg_replace('/\s+([,:;!?])/u', '$1', $title);
        $title = (string) preg_replace('/([!?.])[,:]/u', '$1', $title);
        $title = (string) preg_replace('/\s{2,}/u', ' ', $title);
        return Spinner::ucfirst(trim($title, " \t,;"));
    }

    /** Meta description (≤ 160 chars) from the first spun paragraph. */
    public static function summary(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        // Whole sentences when they fit (90–160 chars), otherwise a word-boundary cut.
        $out = '';
        foreach (Spinner::sentences($text) as $sentence) {
            $candidate = $out === '' ? $sentence : $out . ' ' . $sentence;
            if (mb_strlen($candidate) > 160) {
                break;
            }
            $out = $candidate;
        }
        return mb_strlen($out) >= 90 ? $out : Str::limit($text, 160);
    }

    /** Escaped, sanitized HTML body. */
    private function renderBody(array $blocks, array $links): string
    {
        $html = '';
        foreach ($blocks as $block) {
            $text = e($block['text']);
            if ($block['type'] === 'h2') {
                $html .= '<h2>' . $text . '</h2>';
                continue;
            }
            if ($block['type'] === 'outro') {
                $text = self::linkInternal($text);
            }
            if ($block['type'] === 'background' && !empty($block['club']) && !empty($block['url'])) {
                $club = e($block['club']);
                $link = '<a href="' . e(App::urlPrefix() . $block['url']) . '">' . $club . '</a>';
                $pos = strpos($text, $club);
                if ($pos !== false) {
                    $text = substr_replace($text, $link, $pos, strlen($club));
                } else {
                    $text .= ' ' . str_replace('{club}', $link, e('Kulüple ilgili ayrıntılar için {club} sayfamıza göz atabilirsiniz.'));
                }
            }
            $html .= '<p>' . $text . '</p>';
        }
        $anchors = [];
        foreach ($links as $link) {
            $anchors[] = '<a href="' . e($link['url']) . '" rel="nofollow noopener" target="_blank">' . e($link['name']) . '</a>';
        }
        $html .= '<p><strong>Kaynak:</strong> ' . implode(', ', $anchors) . '</p>';
        return Html::sanitize($html);
    }

    /** Link the site sections mentioned by outro templates ("İstatistikler sayfamızdan" => /istatistikler). */
    private static function linkInternal(string $escaped): string
    {
        $map = [
            'Kariyer' => '/kariyer', 'İstatistikler' => '/istatistikler', 'Hayatı' => '/hayati',
            'Kupalar ve Ödüller' => '/kupalar-ve-oduller', 'Oynadığı Takımlar' => '/takimlar', 'Takımlar' => '/takimlar',
            'Videolar' => '/videolar', 'video' => '/videolar', 'Haberler' => '/haberler', 'haber' => '/haberler',
        ];
        $prefix = App::urlPrefix();
        return (string) preg_replace_callback(
            '/(?<![\p{L}])(Kupalar ve Ödüller|Oynadığı Takımlar|Kariyer|İstatistikler|Hayatı|Takımlar|Videolar|video|Haberler|haber) (sayfamız|bölümümüz)(\p{L}*)/u',
            static fn (array $m): string => '<a href="' . e($prefix . $map[$m[1]]) . '">' . $m[0] . '</a>',
            $escaped,
            1
        );
    }

    private static function sourceName(array $item): string
    {
        $name = trim((string) ($item['source_name'] ?? ''));
        if ($name === '' || in_array(strtolower($name), ['bing haberler', 'google haberler'], true)) {
            $host = (string) parse_url((string) $item['url'], PHP_URL_HOST);
            $name = (string) preg_replace('/^www\./', '', $host);
        }
        return $name;
    }

    private static function hasQuote(string $sentence): bool
    {
        return preg_match('/[“”"«»]/u', $sentence) === 1;
    }

    private static function words(string $text): int
    {
        return count(preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }
}
