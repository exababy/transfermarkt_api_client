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
 * Builds an ORIGINAL post from a story cluster (no AI): template intro, rewritten sentences of the
 * primary source mixed (paragraph by paragraph) with non-redundant rewritten sentences of up to
 * spinner.max_sources other sources (joined with neutral connectors), a club background paragraph, a
 * stats context paragraph (StatsRepository), a template outro and a "Kaynak:" line linking every source.
 *
 * Every source sentence goes through Spinner::rewrite() (sentence-level transformation + synonyms); the
 * best of REWRITE_TRIES seeded rewrites is used and a sentence is dropped when too little of it changed.
 * Never used: quoted speech without quotation marks (first person), fragments of a longer quotation,
 * teasers ("İşte tüm detaylar…") and page boilerplate. Short neighbouring sentences are sometimes joined.
 *
 * Uniqueness = share of word 3-shingles of the post that do not occur in any source. Below
 * spinner.min_change_ratio extra passes are applied; if it is still too low the result is rejected
 * ('low-uniqueness') — near copies are never published. The headline must stay below
 * TITLE_MAX_SIMILARITY to every title of the story (see title()).
 */
final class ArticleComposer
{
    /** A headline clause after "…sözler:", "…itirafı:", "…mesajı:" is reported speech (kept verbatim, never mixed). */
    private const SPEECH_NOUN = '/(?:^|\s)(?:sert |ağır |çarpıcı |net |flaş )?(?:söz(?:ler|leri|ü)?|itiraf[ıi]?|açıklama(?:sı|lar|ları)?|tepki(?:si|ler|leri)?|gönderme(?:si)?|yanıt(?:ı)?|cevab[ıi]|cevap|çıkış(?:ı)?|mesaj(?:ı|lar|ları)?|eleştiri(?:si|ler|leri)?|iddia(?:sı|lar|ları)?|uyarı(?:sı)?|sitem(?:i)?|övgü(?:sü|ler)?|yorum(?:u|lar|ları)?|ifade(?:ler|leri)|paylaşım(?:ı)?|çağrı(?:sı)?|müjde(?:si)?|tavsiye(?:si)?)$/u';

    /** Headline boilerplate that is never generated ("Bütün ayrıntılar haberimizde", "İşte detaylar" …). */
    private const BOILERPLATE = '/(?:ayrıntılar|detaylar)\s+(?:haberimizde|haberde|burada)|(?:^|[:,!]\s*)işte\s+(?:ayrıntılar|detaylar|tüm|bütün)|tüm ayrıntılar|bütün ayrıntılar|^(?:gündem|futbol gündemi|spor gündemi|dikkat çeken gelişme|yeni gelişme|son dakika)\s*:|hakkında (?:konuşulan|merak edilen) gelişme|gözler .* üzerinde:|(?:yine|bir kez daha) gündemde:/u';

    /** Words that only look like first-person verbs. */
    private const NOT_FIRST_PERSON = ['artık', 'mantık', 'taktik', 'plastik', 'kritik', 'politik', 'pratik', 'estetik', 'otantik', 'romantik',
        'fantastik', 'elektrik', 'istatistik', 'akrobatik', 'matematik', 'atletik', 'yardım', 'kadim', 'nadim', 'bölüm', 'ölçüm', 'üretim',
        'iletişim', 'yönetim', 'eğitim', 'gelişim', 'değişim', 'kesim', 'seçim', 'biçim', 'görüşüm', 'tutum', 'durum', 'sonuç', 'yorum',
        'organizasyonum', 'program', 'problem', 'sistem', 'tatum', 'ritim', 'kazım', 'yazım', 'dağılım', 'kuşak', 'atılım', 'alım', 'satım',
        'yatırım', 'katılım', 'sınırım', 'mesajım', 'takım', 'akım', 'adım', 'kişisel', 'oyuncum', 'çağım', 'ağım', 'dönem', 'kadem',
        'itibarıyla', 'anlamıyla', 'yıldızım', 'teşvik', 'tahkik', 'tetkik', 'tedbirim', 'mendik', 'gerdik', 'çabuk', 'kayık', 'bıçak',
        'bütçem', 'erkek', 'ördek', 'kömür', 'yüksek', 'gönüllük', 'lüks', 'tekrarım', 'evrim', 'devrim', 'zirve', 'özetim', 'taktiği',
        'teknik', 'kimyam', 'ekim', 'makam', 'akşam', 'bayram', 'haram', 'kıyam', 'merhum', 'malum', 'mazlum', 'ilham', 'hesap', 'şampiyonluk'];

    /** A headline must stay below this word-set similarity (Str::similarity) to EVERY title of the story. */
    public const TITLE_MAX_SIMILARITY = 0.6;

    /** The first source sentence is used only when at least this share of its 3-shingles changed. */
    private const LEAD_MIN_CHANGE = 0.2;

    /** Seeded rewrites tried per source sentence (the most changed one is used). */
    private const REWRITE_TRIES = 4;

    /** Candidate pool sizes (words) taken from the lead source / per secondary source. */
    private const LEAD_POOL_WORDS = 420;
    private const SECONDARY_POOL_WORDS = 220;

    /**
     * Per pass: spin rate, words used from the lead / each secondary source, minimum change of a
     * sentence (share of its 3-shingles rewritten) to be used, context and outro sentence counts.
     * Later passes rewrite harder and use less (but better rewritten) source text.
     */
    private const PASSES = [
        ['rate' => 0.9, 'structure' => 0.8, 'lead' => 190, 'secondary' => 80, 'min_change' => 0.33, 'context' => 1, 'outros' => 1],
        ['rate' => 1.0, 'structure' => 0.9, 'lead' => 170, 'secondary' => 70, 'min_change' => 0.37, 'context' => 2, 'outros' => 1],
        ['rate' => 1.0, 'structure' => 1.0, 'lead' => 150, 'secondary' => 60, 'min_change' => 0.42, 'context' => 2, 'outros' => 2],
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
        // The rewritten first sentence may serve as a last-resort headline only when it is well rewritten.
        $leadSentence = (string) (Spinner::sentences($best['lead_text'])[0] ?? '');
        if ($leadSentence !== '' && Spinner::uniquenessReport($leadSentence, $sourceTexts)['union'] < 0.5) {
            $leadSentence = '';
        }
        $title = $this->title((string) $sources[0]['item']['title'], $titles, $seed, array_values(array_unique($otherTitles)), $leadSentence, implode("\n", $sourceTexts));
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
        // Sentence-level transformations + synonyms; a capitalised word may only be lowercased after it
        // moved when the sources also write it in lowercase.
        $options = ['structure' => $cfg['structure'], 'common' => $lexicon['common'] ?? []];
        // Best of several seeded rewrites: the one that changes the most of the sentence's word 3-shingles;
        // a split that leaves one part (nearly) unchanged counts less.
        $rewrite = static function (string $sentence, string $key) use ($sp, $cfg, $options): string {
            $best = '';
            $bestScore = -1.0;
            for ($try = 0; $try < self::REWRITE_TRIES; $try++) {
                $text = $sp->rewrite($sentence, $key . '|' . $try, $cfg['rate'], $options)['text'];
                $score = self::change($text, $sentence);
                $parts = Spinner::sentences($text);
                if (count($parts) > 1) {
                    foreach ($parts as $part) {
                        if (self::change($part, $sentence) < 0.15) {
                            $score -= 0.2;
                            break;
                        }
                    }
                }
                if ($score > $bestScore + 0.001) {
                    $best = $text;
                    $bestScore = $score;
                }
            }
            return $best;
        };

        $blocks[] = ['type' => 'p', 'text' => $sp->expand((string) $sp->pick($sp->templates('intros') ?: ['{{player}} ile ilgili yeni bir gelişme yaşandı.'], $seed . '|intro|' . $pass), $vars, $seed . '|intro')];

        // Lead source: the first sentence (key fact) always, others only when rewritten enough.
        // A sentence opening with a back-reference ("Bu karar …") needs its predecessor.
        $leadText = '';
        $words = 0;
        $quotes = 0;
        $k = 0;
        $previousKept = true;
        $paragraph = [];
        $paragraphFirst = '';
        $leadBlocks = [];
        $common = $lexicon['common'] ?? [];
        $flush = function () use (&$paragraph, &$leadBlocks, &$leadText, &$paragraphFirst, $sp, $seed, $pass, $common): void {
            if ($paragraph === []) {
                return;
            }
            // Join two short neighbouring sentences with "ve" now and then (seeded).
            $joined = [];
            foreach ($paragraph as $i => $sentence) {
                $last = array_key_last($joined);
                if ($last !== null && $sp->pick([true, false], $seed . '|merge|' . $pass . '|' . count($leadBlocks) . '|' . $i)) {
                    $merged = $sp->merge($joined[$last], $sentence, $common);
                    if ($merged !== null) {
                        $joined[$last] = $merged;
                        continue;
                    }
                }
                $joined[] = $sentence;
            }
            $text = implode(' ', $joined);
            if ($leadText === '') {
                $leadText = $text;
            }
            $leadBlocks[] = ['type' => 'p', 'text' => $text, 'first' => $paragraphFirst];
            $paragraph = [];
        };
        foreach ($leadParagraphs as $sentences) {
            foreach ($sentences as $sentence) {
                $index = $k++;
                $count = self::words($sentence);
                $keep = $index === 0;
                $spun = '';
                if (!$keep && $words + $count <= $cfg['lead'] && ($previousKept || preg_match(self::ANAPHORIC, $sentence) !== 1)) {
                    $spun = $rewrite($sentence, $seed . '|' . $pass . '|' . $index);
                    $quote = self::hasQuote($sentence);
                    // One quotation per post may stay (verbatim) even when little else changed — but its
                    // attribution must be rewritten (a bare quotation is never copied).
                    $change = self::change($spun, $sentence);
                    $keep = $change >= $cfg['min_change'] || ($quote && $quotes === 0 && $change >= 0.15);
                } elseif ($keep) {
                    // The key-fact sentence leads the post, but never (nearly) verbatim.
                    $spun = $rewrite($sentence, $seed . '|' . $pass . '|' . $index);
                    $keep = self::change($spun, $sentence) >= self::LEAD_MIN_CHANGE;
                }
                $previousKept = $keep;
                if (!$keep) {
                    continue;
                }
                $quotes += self::hasQuote($sentence) ? 1 : 0;
                if ($paragraph === []) {
                    $paragraphFirst = $sentence;
                }
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
        $secondaryBlocks = [];
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
                $spun = $rewrite($sentence, $seed . '|' . $pass . '|s' . $i . '|' . $j);
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
            $secondaryBlocks[] = ['type' => 'p', 'text' => implode(' ', $kept)];
            $used[] = (int) $group['source'];
        }
        // Mix the outlets: a paragraph of another outlet may follow any lead paragraph after the first
        // one, unless the next lead paragraph refers back to the previous one ("Bu karar …").
        $slots = [];
        foreach ($leadBlocks as $i => $block) {
            $next = (string) ($leadBlocks[$i + 1]['first'] ?? '');
            // "Bu karar …" or "… cezanın bu iki karşılaşmayı …": the paragraph refers back to the previous one.
            $refersBack = preg_match(self::ANAPHORIC, $next) === 1 || preg_match('/(?<![\p{L}])(?:bu|şu|söz konusu|aynı|sözkonusu)\s/iu', mb_substr($next, 0, 90)) === 1;
            if ($i >= 1 && isset($leadBlocks[$i + 1]) && !$refersBack) {
                $slots[] = $i;
            }
        }
        $interleave = $slots !== [] && $secondaryBlocks !== [] && (bool) $sp->pick([true, true, false], $seed . '|mix|' . $pass);
        foreach ($leadBlocks as $i => $block) {
            unset($block['first']);
            $blocks[] = $block;
            if ($interleave && in_array($i, $slots, true) && $secondaryBlocks !== []) {
                $blocks[] = array_shift($secondaryBlocks);
            }
        }
        foreach ($secondaryBlocks as $block) {
            $blocks[] = $block;
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
                if (self::isRedundant($sentence, [...$selected, ...$out]) || self::conflictsWith($sentence, $selected)) {
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
        if ($words < 5 || $words > 70 || preg_match('#https?://|www\.|@\w+|tıkla|abone|reklam|instagram\.com|kaynaklar arasına ekle|tercih edilen kaynak|haberlerimizin daha fazlası|bizi takip edin|iktibas edilemez|telif hakk|tüm hakları saklı#iu', $sentence) === 1) {
            return false;
        }
        if (ArticleExtractor::isKeywordList($sentence)) {
            return false;
        }
        // Teasers of the source page ("İşte tüm detaylar...", "Tüm merak edilenler haberimizin devamında").
        if (preg_match('/^(?:işte\b.*(?:detay|ayrıntı)|.*(?:haberimizin devamında|haberin devamında|detaylar haberimizde|ayrıntılar haberimizde))/iu', Str::lower($sentence)) === 1) {
            return false;
        }
        // Part of a longer quotation (unbalanced quotation marks) or quoted speech without quotation marks
        // (first person, "…yaptık", "bana göre"): it must not be rewritten, and is not used at all.
        if (!self::balancedQuotes($sentence) || self::firstPerson($sentence)) {
            return false;
        }
        // Must end like a sentence.
        return preg_match('/[.!?…"”’»)]$/u', $sentence) === 1;
    }

    /** Opening and closing quotation marks pair up (apostrophes followed by a letter are suffixes). */
    private static function balancedQuotes(string $text): bool
    {
        if (substr_count($text, '“') !== substr_count($text, '”') || substr_count($text, '«') !== substr_count($text, '»') || substr_count($text, '"') % 2 === 1) {
            return false;
        }
        $open = preg_match_all('/(?<![\p{L}\p{N}])[‘\'](?=\p{L}|\s?\p{Lu}|\')/u', $text);
        $close = preg_match_all('/(?<=[\p{L}.!?…,\'])[’\'](?![\p{L}\'])/u', $text);
        return $open === $close;
    }

    /** First-person speech outside quotation marks ("…yaptık", "attığım", "bana göre", "eminim"). */
    public static function firstPerson(string $text): bool
    {
        $plain = Str::lower((string) preg_replace('/“[^”]*”|"[^"]*"|«[^»]*»|\'\'[^\']*\'\'|(?<![\p{L}\p{N}])[‘\'](?:[^\'’]|[\'’](?=\p{L}))*[’\'](?![\p{L}])/u', ' ', $text));
        if (preg_match('/(?<![\p{L}])(?:ben|biz|bana|beni|benim|bende|benden|bize|bizi|bizim|bizde|bizden|kendim|kendimi|kendime|kendimden)(?![\p{L}])/u', $plain) === 1) {
            return true;
        }
        foreach (preg_split('/[^\p{L}]+/u', $plain, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            if (mb_strlen($word) < 5 || in_array($word, self::NOT_FIRST_PERSON, true)) {
                continue;
            }
            if (preg_match('/(?:[ıiuü]yor(?:um|uz|dum|duk)|[dt][ıiuü](?:m|k)|[ae]c[ae]ğ[ıi](?:m|z)|m[ıiuü]ş(?:ım|im|um|üm|ız|iz|uz|üz)|m[ae]l[ıi]y[ıi](?:m|z)|[ae]bil[ıi]r[ıi](?:m|z)|ğ[ıiuü]m(?:[ıiuü]z)?|[ae]r[ıi]m|[ıiuü]r[ıiuü]m|[ae]m[ae]m|y[ıiuü]m|s[ae]m)$/u', $word) === 1) {
                return true;
            }
        }
        return false;
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

    /**
     * Same amount with a different multiplier in another outlet's text ("255 euro" vs "255 bin euro"): one of
     * the outlets is wrong, so the other outlet's sentence is not mixed in.
     */
    private static function conflictsWith(string $sentence, array $selected): bool
    {
        $amounts = static function (string $text): array {
            $out = [];
            if (preg_match_all('/(\d+(?:[.,]\d+)?)\s+(bin\s+|milyon\s+|milyar\s+)?(euro|avro|dolar|sterlin|lira|tl|riyal|pound)/iu', Str::lower($text), $m, PREG_SET_ORDER) > 0) {
                foreach ($m as $hit) {
                    $out[$hit[1] . '|' . str_replace('avro', 'euro', $hit[3])][trim($hit[2])] = true;
                }
            }
            return $out;
        };
        $mine = $amounts($sentence);
        if ($mine === []) {
            return false;
        }
        $theirs = $amounts(implode(' ', $selected));
        foreach ($mine as $key => $multipliers) {
            if (isset($theirs[$key]) && array_diff_key($multipliers, $theirs[$key]) !== []) {
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
     * Headline that differs from every title of the story (Str::similarity < 0.6, at most 0.7 as a last
     * resort) without boilerplate tails: structural variants of the lead headline (clause order swapped,
     * "X'dan Y'ye" order swapped, his clause + an information clause of another outlet's headline), each
     * spun with synonyms, optionally behind a permanent epithet frame ("Portekizli yıldız Ronaldo'dan …").
     * Nothing is invented: every candidate is made of the source headlines' own material.
     *
     * @param list<string> $sourceTitles every title of the story (incl. evidence-only items)
     * @return array{title: string, meta_title: string}|null
     */
    public function title(string $base, array $sourceTitles, string $seed, array $otherTitles = [], string $lead = '', string $support = ''): ?array
    {
        $base = $this->cleanTitle($base);
        if ($base === '') {
            return null;
        }
        $sp = $this->spinner;
        $compare = [$base];
        foreach ($sourceTitles as $t) {
            $compare[] = (string) $t;
            $compare[] = $this->cleanTitle((string) $t);
        }
        $compare = array_values(array_unique(array_filter($compare, static fn (string $t): bool => trim($t) !== '')));
        $mentionsHim = RelevanceFilter::mentions($base);

        // 1. structures (before synonyms). Reported speech after "…sözler:" is kept verbatim as a tail.
        $tail = '';
        $head = $base;
        if (preg_match('/^(.+?):\s+(.+)$/u', $base, $hm) === 1 && preg_match(self::SPEECH_NOUN, Str::lower(trim($hm[1]))) === 1 && !self::hasQuote($hm[2])) {
            $head = trim($hm[1]);
            $tail = ': ' . $hm[2];
        }
        $structures = [['text' => $head, 'penalty' => 0.0, 'tail' => $tail]];
        if ($tail === '') {
            foreach (self::clauseSwaps($base) as $t) {
                $structures[] = ['text' => $t, 'penalty' => 0.15];
            }
        } else {
            $structures[] = ['text' => $head, 'penalty' => 0.3]; // the speech dropped
        }
        $swapped = self::caseSwap($head);
        if ($swapped !== null) {
            $structures[] = ['text' => $swapped, 'penalty' => 0.1, 'tail' => $tail];
        }
        foreach ($this->titleMixes($base, $otherTitles) as $t) {
            $structures[] = ['text' => $t, 'penalty' => -0.2]; // two outlets' information in one headline
        }
        // Another outlet's headline of the same story — only when the texts we publish carry its facts
        // (a story cluster can contain an item about a neighbouring story).
        if ($support !== '') {
            $supportStems = self::contentStems($support);
            $alternates = 0;
            foreach ($sourceTitles as $other) {
                $other = $this->cleanTitle((string) $other);
                if ($other === '' || $other === $base || mb_strlen($other) > 110 || self::words($other) > 16 || Str::similarity($other, $base) >= 0.9 || ($mentionsHim && !RelevanceFilter::mentions($other))
                    || self::hasQuote($other) || preg_match(self::BOILERPLATE, Str::lower($other)) === 1) {
                    continue;
                }
                $stems = self::contentStems($other);
                if ($stems === [] || count(array_intersect_key($stems, $supportStems)) / count($stems) < 0.75) {
                    continue;
                }
                $structures[] = ['text' => $other, 'penalty' => 0.25];
                if (++$alternates >= 8) {
                    break;
                }
            }
        }
        // One clause of a two-part headline ("Ricardo Quaresma'dan Cristiano Ronaldo'ya gönderme"); a quotation
        // is never kept without its quotation marks.
        $clauses = self::headlineParts($base);
        if (count($clauses) > 1) {
            foreach ($clauses as $clause) {
                // The kept clause must say who/what it is about (a name), not only "Canlı izleme seçenekleri".
                $named = preg_match('/(?<!^)(?<![.!?:]\s)\b\p{Lu}/u', $clause['text']) === 1 || $this->spinner->startsWithProtected($clause['text']);
                if (!$clause['quoted'] && $named && self::words($clause['text']) >= 3 && (!$mentionsHim || RelevanceFilter::mentions($clause['text']))) {
                    $structures[] = ['text' => $clause['text'], 'penalty' => 0.3];
                }
            }
        }
        // His short or full name ("Ronaldo" <-> "Cristiano Ronaldo"): same person, different headline tokens.
        foreach ($structures as $structure) {
            $text = $structure['text'];
            $alt = preg_match('/^Cristiano Ronaldo(?![\p{L}])/u', $text) === 1 ? (string) preg_replace('/^Cristiano Ronaldo/u', 'Ronaldo', $text)
                : (preg_match('/^Ronaldo(?![\p{L}])/u', $text) === 1 ? 'Cristiano ' . $text : null);
            if ($alt !== null) {
                $structures[] = ['text' => $alt, 'penalty' => $structure['penalty'] + 0.05, 'tail' => $structure['tail'] ?? ''];
            }
        }

        // 2. synonyms (several seeds) and epithet frames
        $candidates = [];
        foreach ($structures as $si => $structure) {
            $variants = [$structure['text']];
            for ($k = 0; $k < 6; $k++) {
                $variants[] = self::tidy($sp->spin($structure['text'], $seed . '|title|' . $si . '|' . $k, 1.0));
            }
            $tailText = (string) ($structure['tail'] ?? '');
            foreach (array_values(array_unique($variants)) as $vi => $variant) {
                $this->addTitle($candidates, $variant . $tailText, $structure['penalty'], $base, $compare, $mentionsHim, $seed);
                foreach ($this->framed($variant, $seed . '|frame|' . $si . '|' . $vi) as $framed) {
                    // The first frames ("Portekizli yıldız …") read most naturally.
                    $this->addTitle($candidates, $framed['text'] . $tailText, $structure['penalty'] + 0.35 + 0.03 * $framed['rank'], $base, $compare, $mentionsHim, $seed);
                }
            }
        }
        // Last resort: the rewritten first sentence of the post (accurate, never boilerplate) when it is short.
        $lead = rtrim(trim($lead), '.');
        if ($lead !== '' && mb_strlen($lead) <= 110 && !self::hasQuote($lead)) {
            $this->addTitle($candidates, $lead, 0.8, $base, $compare, $mentionsHim, $seed);
        }
        $candidates = array_filter($candidates, static fn (array $c): bool => $c['similarity'] < self::TITLE_MAX_SIMILARITY);
        if ($candidates === []) {
            return null;
        }
        uasort($candidates, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        $best = reset($candidates);
        // <title>: the headline itself when it is short enough (Google shows ~60–65 chars), else the best short candidate.
        $meta = $best['length'] <= 65 ? $best['title'] : null;
        foreach ($candidates as $c) {
            if ($meta === null && $c['length'] <= 60 && $c['similarity'] < 0.7 && RelevanceFilter::mentions($c['title'])) {
                $meta = $c['title'];
            }
        }
        return ['title' => $best['title'], 'meta_title' => $meta ?? $best['title']];
    }

    /** Score one headline candidate (or drop it). */
    private function addTitle(array &$candidates, string $title, float $penalty, string $base, array $compare, bool $mentionsHim, string $seed): void
    {
        $title = self::tidy($title);
        $length = mb_strlen($title);
        if ($length < 25 || $length > 110 || isset($candidates[$title]) || substr_count($title, ':') > 1 || preg_match('/[:,;\-–]$/u', $title) === 1) {
            return;
        }
        if (preg_match(self::BOILERPLATE, Str::lower($title)) === 1 || substr_count($title, '"') % 2 === 1 || substr_count($title, '“') !== substr_count($title, '”')) {
            return;
        }
        if ($mentionsHim && !RelevanceFilter::mentions($title)) {
            return;
        }
        $similarity = 0.0;
        foreach ($compare as $other) {
            $similarity = max($similarity, Str::similarity($title, $other));
        }
        if ($similarity >= 0.7) {
            return;
        }
        $baseWords = max(1, self::words($base));
        $score = ($similarity < 0.6 ? 3.0 : 0.0)
            + min(0.6, (0.6 - $similarity) * 1.5)
            + ($length >= 40 && $length <= 70 ? 1.2 : ($length <= 80 ? 0.4 : -0.8))
            + min(1.0, self::words($title) / $baseWords)          // keep the information of the headline
            + (preg_match('/^(?:Cristiano Ronaldo|Ronaldo|CR7)/u', $title) === 1 ? 0.2 : 0.0)
            - $penalty
            + (crc32($seed . $title) % 1000) / 5000;
        $candidates[$title] = ['title' => $title, 'score' => $score, 'length' => $length, 'similarity' => $similarity];
    }

    /**
     * "A! B" / "A: B" / "A? B" with two clauses => "B: A" (or "B? A?" for two questions).
     *
     * @return list<string>
     */
    private static function clauseSwaps(string $title): array
    {
        $parts = self::headlineParts($title);
        if (count($parts) !== 2) {
            return [];
        }
        [$a, $b] = $parts;
        if (self::words($a['text']) < 2 || self::words($b['text']) < 2) {
            return [];
        }
        $out = str_ends_with($b['text'], '?') ? $b['text'] . ' ' . $a['text'] : $b['text'] . ': ' . $a['text'];
        return [rtrim($out, ' :')];
    }

    /** "Ten Hag'dan Ronaldo'ya sert sözler" <=> "Ronaldo'ya Ten Hag'dan sert sözler". */
    private static function caseSwap(string $title): ?string
    {
        $name = '(?:\p{Lu}[\p{L}\-]*\s){0,2}\p{Lu}[\p{L}\-]*';
        if (preg_match('/^(' . $name . '[\'’](?:dan|den|tan|ten))\s+(' . $name . '[\'’](?:y?[ae]|n[ae]))\s+(\p{Ll}.+)$/u', $title, $m) === 1
            || preg_match('/^(' . $name . '[\'’](?:y?[ae]|n[ae]))\s+(' . $name . '[\'’](?:dan|den|tan|ten))\s+(\p{Ll}.+)$/u', $title, $m) === 1) {
            return $m[2] . ' ' . $m[1] . ' ' . $m[3];
        }
        return null;
    }

    /**
     * His clause + an information clause of another outlet's headline ("Ronaldo kampı terk etti: 30 gün
     * men cezası").
     *
     * @return list<string>
     */
    private function titleMixes(string $base, array $otherTitles): array
    {
        $info = [];
        foreach ($otherTitles as $other) {
            foreach (self::headlineParts($this->cleanTitle((string) $other)) as $clause) {
                $text = $clause['text'];
                $words = self::words($text);
                if (!$clause['quoted'] && $words >= 3 && $words <= 8 && !RelevanceFilter::mentions($text) && preg_match(self::BOILERPLATE, Str::lower($text)) !== 1
                    && preg_match('/^(?:işte|tüm|bütün|flaş|video|galeri|o sözler|bu|şu)(?![\p{L}])/iu', Str::lower($text)) !== 1) {
                    $info[] = self::rtrimPunct($text);
                }
            }
        }
        $info = array_slice(array_values(array_unique($info)), 0, 6);
        $own = [['text' => $base, 'quoted' => false], ...self::headlineParts($base)];
        $out = [];
        foreach ($own as $clause) {
            $his = self::rtrimPunct($clause['text']);
            if ($clause['quoted'] || self::hasQuote($his) || !RelevanceFilter::mentions($his) || self::words($his) > 9 || str_contains($his, ':') || str_ends_with($his, '?')) {
                continue;
            }
            foreach ($info as $text) {
                if (Str::similarity($his, $text) < 0.3) {
                    $out[] = $his . ': ' . Spinner::ucfirst($text);
                }
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Clauses of a headline split at ": ", "! ", "? ", " | ", " - ", "…" and at a comma after a finite verb
     * ("Ronaldo kaç gol oldu, …"): ['text' => clause (a question keeps its "?"), 'quoted' => whole clause is a quotation].
     *
     * @return list<array{text: string, quoted: bool}>
     */
    private static function headlineParts(string $title): array
    {
        $parts = preg_split('/(\s*[:!?|]+\s+|\s*\.{3}\s*|\s*…\s*|\s+[-–]\s+|[!?]+$)/u', trim($title), -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
        $clauses = [];
        for ($i = 0; $i < count($parts); $i += 2) {
            $text = trim($parts[$i]);
            $sep = $parts[$i + 1] ?? '';
            if ($text === '') {
                continue;
            }
            if (count($parts) <= 2 && preg_match('/^(.{10,}?\p{L}+),\s+(.{10,})$/u', $text, $cm) === 1 && preg_match('/(\p{L}+)$/u', $cm[1], $lw) === 1 && Spinner::isFinite($lw[1])) {
                $clauses[] = [$cm[1], ''];
                $clauses[] = [$cm[2], $sep];
                continue;
            }
            $clauses[] = [$text, $sep];
        }
        $out = [];
        $previous = null;
        foreach ($clauses as [$text, $sep]) {
            $text = trim($text, " \t.,;:");
            $quoted = preg_match('/^(?:“[^”]*”|"[^"]*"|\'(?:[^\']|\'(?=\p{L}))*\'(?!\p{L}))$/u', $text) === 1;
            // "Ronaldo itirafı: Takımı yüzüstü bıraktı" — the second clause is reported speech.
            $speech = $previous !== null && preg_match(self::SPEECH_NOUN, Str::lower($previous)) === 1;
            $question = str_contains($sep, '?') || preg_match('/(?:^|\s)(?:kaç|ne|neyi|neden|niçin|niye|nasıl|nerede|nereden|hangi|kim|kimi|kime)(?:\s|$)|\sm[ıiuü](?:[dysn]\p{L}*)?$/u', Str::lower($text)) === 1;
            if ($text !== '' && self::words($text) >= 2) {
                $out[] = ['text' => Spinner::ucfirst($text) . ($question && !$quoted ? '?' : ''), 'quoted' => $quoted || $speech];
            }
            $previous = $text;
        }
        return $out;
    }

    /**
     * Epithet frames of the 'title_patterns' templates for a headline that opens with his name:
     * "Portekizli yıldız Ronaldo'dan …". "{{subject}}, {{rest}}" only for a nominative subject.
     *
     * @return list<array{text: string, rank: int}>
     */
    private function framed(string $title, string $seed): array
    {
        if (preg_match('/^(Cristiano Ronaldo|Ronaldo)((?:[\'’]\p{L}+)?)((?:,\s|\s).+)$/u', $title, $m) !== 1) {
            return [];
        }
        $nominative = $m[2] === '';
        $comma = str_starts_with($m[3], ',');
        $rest = trim(ltrim($m[3], ','));
        $out = [];
        $patterns = $this->spinner->templates('title_patterns');
        $order = array_flip($patterns);
        foreach ($this->spinner->shuffle($patterns, $seed) as $pattern) {
            if (!str_contains($pattern, '{{subject}}') || !str_contains($pattern, '{{rest}}')) {
                continue;
            }
            $prefix = trim((string) strstr($pattern, '{{subject}}', true));
            if ($prefix === '' || str_contains($pattern, '{{subject}},')) {
                continue;
            }
            $subject = 'Ronaldo' . $m[2] . ($comma ? ',' : ''); // "Portekizli yıldız Ronaldo'dan": the epithet replaces the first name
            $text = $this->spinner->expand(str_replace(['{{subject}}', '{{rest}}'], ["\u{E010}", "\u{E011}"], $pattern), [], $seed . '|' . $pattern);
            $out[] = ['text' => str_replace(["\u{E010}", "\u{E011}"], [$subject, $rest], $text), 'rank' => (int) ($order[$pattern] ?? 0)];
            if (count($out) >= 4) {
                break;
            }
        }
        return $out;
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
        // (trim() is byte-wise: "–" in its list would cut the first bytes of a leading "“")
        return (string) preg_replace('/^[\s\-–|]+|[\s\-–|]+$/u', '', $title);
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

    /** First five letters of the content words (≥ 4 letters, not his name) of a text. */
    private static function contentStems(string $text): array
    {
        $out = [];
        foreach (explode(' ', Str::normalize($text)) as $word) {
            if (strlen($word) >= 4 && !in_array($word, ['cristiano', 'ronaldo', 'ronaldonun', 'ronaldoya', 'ronaldodan', 'ronaldoyu', 'icin', 'olarak', 'gibi', 'daha', 'sonra', 'once', 'kadar', 'iste'], true)) {
                $out[substr($word, 0, 5)] = true;
            }
        }
        return $out;
    }

    /** Strip trailing "!", "." and "…" (multibyte-safe). */
    private static function rtrimPunct(string $text): string
    {
        return (string) preg_replace('/[!.…\s]+$/u', '', $text);
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
        return preg_match('/[“”"«»]|(?<![\p{L}\p{N}])[\'‘](?:[^\'’\n]|[\'’](?=\p{L}))+[\'’](?![\p{L}])/u', $sentence) === 1;
    }

    private static function words(string $text): int
    {
        return count(preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }
}
