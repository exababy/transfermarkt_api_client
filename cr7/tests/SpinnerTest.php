<?php

declare(strict_types=1);

namespace Tests;

use App\Services\News\ArticleComposer;
use App\Services\News\Spinner;
use App\Support\Str;

final class SpinnerTest extends TestCase
{
    /** Small, fully controlled data set. */
    private function spinner(): Spinner
    {
        return new Spinner([
            'groups' => [
                ['maçta', 'karşılaşmada'],
                ['maçın', 'karşılaşmanın'],
                ['gol attı', 'gol kaydetti'],
                ['sona erdi', 'son buldu'],
                ['sözleşme', 'kontrat'],
                ['zafer', 'galibiyet'],
                ['tartışma başlattı', 'tartışmalara neden oldu'],
                ['ilk yarıda', 'ilk devrede'],
                ['Portekizli yıldız', 'Portekizli süperstar'],
                ['açıklama yaptı', 'açıklamada bulundu'],
            ],
            'protected' => ['Cristiano Ronaldo', 'Ronaldo', 'Al-Nassr', 'Al-Hilal'],
        ], [
            'intros' => ['{{player}} {yine|bir kez daha} {gündemde|haberlere konu oldu}.'],
            'connectors' => ['Öte yandan,'],
        ]);
    }

    public function testRealContentDataLoads(): void
    {
        Spinner::reset();
        $spinner = Spinner::instance();
        $this->assertTrue($spinner->groupCount() >= 600, 'synonym groups loaded: ' . $spinner->groupCount());
        $this->assertContains('Cristiano Ronaldo', $spinner->protectedTerms());
        $this->assertTrue(count($spinner->templates('intros')) >= 10, 'intro templates');
        $this->assertTrue(count($spinner->templates('title_patterns')) >= 10, 'title patterns');
        $out = $spinner->spin('Portekizli yıldız Cristiano Ronaldo, Al-Hilal maçında iki gol attı ve takımını sırtladı.', 'seed', 1.0);
        $this->assertTrue($out !== 'Portekizli yıldız Cristiano Ronaldo, Al-Hilal maçında iki gol attı ve takımını sırtladı.', 'real data rewrites');
        $this->assertContains('Cristiano Ronaldo', $out);
        $this->assertContains('Al-Hilal', $out);
    }

    public function testReplacesLongestMatchAndKeepsProtectedTerms(): void
    {
        $out = $this->spinner()->spin('Ronaldo maçta gol attı.', 'a', 1.0);
        $this->assertSame('Ronaldo karşılaşmada gol kaydetti.', $out);
        $out = $this->spinner()->spin("Cristiano Ronaldo, Al-Nassr'ın maçın sonunda gol attı.", 'a', 1.0);
        $this->assertContains("Cristiano Ronaldo, Al-Nassr'ın", $out, 'protected term + suffix untouched');
        $this->assertContains('karşılaşmanın', $out);
    }

    public function testQuotesNumbersAndScoresAreNeverChanged(): void
    {
        $text = 'Ronaldo “maçta gol attı ve zafer kazandık” dedi. Al-Nassr maçta 2-1\'lik zafer ile 30 Eylül\'de 950. golünü attı; "ilk yarıda sözleşme" ifadesi geçti.';
        $out = $this->spinner()->spin($text, 'q', 1.0);
        $this->assertContains('“maçta gol attı ve zafer kazandık”', $out, 'curly quote verbatim');
        $this->assertContains('"ilk yarıda sözleşme"', $out, 'straight quote verbatim');
        $this->assertContains("2-1'lik", $out);
        $this->assertContains("30 Eylül'de", $out);
        $this->assertContains('950.', $out);
        $this->assertContains('karşılaşmada', $out, 'text outside quotes is spun');
    }

    public function testDeterministicPerSeed(): void
    {
        $spinner = new Spinner([
            'groups' => [['bir', 'tek'], ['güzel', 'hoş', 'şahane', 'harika'], ['maç', 'karşılaşma', 'müsabaka', 'mücadele']],
            'protected' => [],
        ], []);
        $text = str_repeat('güzel maç oldu. ', 12);
        $a = $spinner->spin($text, 'hash-1', 0.8);
        $this->assertSame($a, $spinner->spin($text, 'hash-1', 0.8), 'same seed => same output');
        $outputs = [];
        foreach (['hash-1', 'hash-2', 'hash-3', 'hash-4'] as $seed) {
            $outputs[$spinner->spin($text, $seed, 0.8)] = true;
        }
        $this->assertTrue(count($outputs) > 1, 'different seeds vary the output');
    }

    public function testCapitalisationIsTurkishAware(): void
    {
        $out = $this->spinner()->spin('Maçın ardından Ronaldo konuştu. İlk yarıda gol yoktu.', 's', 1.0);
        $this->assertContains('Karşılaşmanın ardından', $out, 'sentence-initial capital restored');
        $this->assertContains('İlk devrede', $out, 'İ handled');
        $this->assertSame('İlk', Spinner::ucfirst('ilk'));
        $this->assertSame('Isparta', Spinner::ucfirst('ısparta'));
        $this->assertSame('ılık', Spinner::lcfirst('Ilık'));
        $out = $this->spinner()->spin('Portekizli yıldız ve portekizli yıldız', 'c', 1.0);
        $this->assertContains('Portekizli süperstar', $out, 'capitalised data form replaced mid-sentence');
    }

    public function testCapitalisedNameMidSentenceAndApostrophesAreSkipped(): void
    {
        $out = $this->spinner()->spin('Takım Zafer ile anlaştı.', 'n', 1.0);
        $this->assertSame('Takım Zafer ile anlaştı.', $out, 'mid-sentence capital = proper name');
        $this->assertSame("Biz zafer'i bekliyoruz.", $this->spinner()->spin("Biz zafer'i bekliyoruz.", 'n', 1.0), 'followed by apostrophe');
        $this->assertSame('Takım galibiyet kazandı.', $this->spinner()->spin('Takım zafer kazandı.', 'n', 1.0));
    }

    public function testCliticHarmonyAndIndefiniteArticle(): void
    {
        $this->assertSame('Sezon son buldu mu?', $this->spinner()->spin('Sezon sona erdi mi?', 'h', 1.0));
        $this->assertSame('Yeni kontrat da hazır.', $this->spinner()->spin('Yeni sözleşme de hazır.', 'h', 1.0));
        $this->assertSame('mu', Spinner::harmonize('son buldu', 'mi'));
        $this->assertSame('mıydı', Spinner::harmonize('kazandı', 'miydi'));
        $this->assertSame('de', Spinner::harmonize('kontrat imzaladı ve gelecek', 'da'));
        // "bir tartışma başlattı" must not become "bir tartışmalara neden oldu".
        $this->assertSame('Ronaldo bir tartışma başlattı.', $this->spinner()->spin('Ronaldo bir tartışma başlattı.', 'b', 1.0));
        $this->assertSame('Ronaldo tartışmalara neden oldu.', $this->spinner()->spin('Ronaldo tartışma başlattı.', 'b', 1.0));
    }

    public function testRateAndCapLimitReplacements(): void
    {
        $spinner = $this->spinner();
        $text = 'Maçta gol attı. Maçta gol attı. Maçta gol attı. Maçta gol attı.';
        $this->assertSame($text, $spinner->spin($text, 'r', 0.0), 'rate 0 changes nothing');
        $capped = $spinner->spinDetailed($text, 'r', 1.0, 0.1);
        $this->assertSame(2, $capped['replaced'], 'cap = ceil(12 words * 0.1)');
        $this->assertSame(8, $spinner->spinDetailed($text, 'r', 1.0)['hits']);
    }

    public function testTemplateExpansion(): void
    {
        $spinner = $this->spinner();
        $template = '{{player}} {yine|bir kez daha} {gündemde|haberlere konu oldu}.';
        $out = $spinner->expand($template, ['player' => 'Cristiano Ronaldo'], 'seed');
        $this->assertTrue(in_array($out, [
            'Cristiano Ronaldo yine gündemde.', 'Cristiano Ronaldo yine haberlere konu oldu.',
            'Cristiano Ronaldo bir kez daha gündemde.', 'Cristiano Ronaldo bir kez daha haberlere konu oldu.',
        ], true), $out);
        $this->assertSame($out, $spinner->expand($template, ['player' => 'Cristiano Ronaldo'], 'seed'), 'deterministic');
        $nested = $spinner->expand('{a|{b|c}} {{x}}', ['x' => '{değer|ham}'], 'n');
        $this->assertTrue(str_ends_with($nested, '{değer|ham}'), 'placeholder values are never parsed as spintax: ' . $nested);
        $this->assertTrue(in_array(explode(' ', $nested)[0], ['a', 'b', 'c'], true), 'nested spintax');
        $this->assertSame('Gol: 3, asist 2.', $spinner->expand('Gol: {{g}} , asist {{a}} .', ['g' => '3', 'a' => '2'], 'z'), 'spacing tidied');
    }

    public function testSentenceSplitting(): void
    {
        $sentences = Spinner::sentences('Kriz 27 Eylül\'de başladı. Portekiz 3. sırada! Süper Lig ve 1. Lig ertelendi. “Bu bir. Test.” dedi Dr. Silva. Son?');
        $this->assertSame([
            "Kriz 27 Eylül'de başladı.",
            'Portekiz 3. sırada!',
            'Süper Lig ve 1. Lig ertelendi.',
            '“Bu bir. Test.” dedi Dr. Silva.',
            'Son?',
        ], $sentences);
        $this->assertSame(['Karşılaşma öncesi ısındı.', 'Çok iyiydi.'], Spinner::sentences('Karşılaşma öncesi ısındı. Çok iyiydi.'), 'multibyte capital after the stop');
    }

    public function testShinglesAndUniqueness(): void
    {
        $source = 'Ronaldo bu akşam oynanan maçta iki gol attı ve takımını galibiyete taşıdı';
        $this->assertSame(0.0, Spinner::uniqueness($source, [$source]), 'copy = 0 % unique');
        $report = Spinner::uniquenessReport('Tamamen farklı bir cümle yazdık burada bugün', [$source]);
        $this->assertSame(1.0, $report['union']);
        $half = Spinner::uniquenessReport('Ronaldo bu akşam oynanan maçta iki gol attı. Sonra sahadan alkışlarla ayrıldı ve taraftarları selamladı', [$source]);
        $this->assertTrue($half['union'] > 0.3 && $half['union'] < 0.9, 'partial copy: ' . $half['union']);
        $two = Spinner::uniquenessReport('alfa beta gama delta epsilon zeta', ['alfa beta gama delta', 'gama delta epsilon zeta']);
        $this->assertTrue($two['union'] < $two['min'], 'union is stricter than the per-source minimum');
        $this->assertCount(4, Spinner::shingles('bir iki üç dört beş altı'));
    }

    // ------------------------------------------------------------------ rule types (contextual / directed / fixed)

    /** Data set with every rule type. */
    private function ruleSpinner(): Spinner
    {
        return new Spinner([
            'groups' => [
                ['maçta', 'karşılaşmada'],
                ['şu sözlerle', 'şu ifadelerle'],
                ['dile getirdi', 'ifade etti', 'belirtti'],
                ['ilk yarı', 'ilk devre'],
            ],
            'contextual' => [
                'quote' => [['dedi', 'diye konuştu']],
                'announcement' => [['açıkladı', 'duyurdu']],
                'start' => [['ayrıca', 'bunun yanı sıra']],
                'duration' => [['devam ediyor', 'sürüyor']],
                'not_dative' => [['dikkat çekti', 'göze çarptı']],
                'score' => [['kazandı', 'galip geldi']],
                'number_dative' => [['yükseltti', 'çıkardı']],
                'unknown_context' => [['asla', 'hiç']],
            ],
            'directed' => [['futbolcu', 'oyuncu']],
            'fixed' => ['yarı final', 'ilk yarı final', 'ilk yarı finalde'],
            'protected' => ['Cristiano Ronaldo', 'Ronaldo', 'Al-Nassr', 'Erik ten Hag'],
        ], []);
    }

    public function testContextualRulesOnlyApplyInTheirContext(): void
    {
        $sp = $this->ruleSpinner();
        $this->assertSame('Ronaldo, “Takımı bırakmadım.” diye konuştu.', $sp->spin('Ronaldo, “Takımı bırakmadım.” dedi.', 'q', 1.0), 'after a quotation');
        $this->assertSame('Ronaldo teklife evet dedi.', $sp->spin('Ronaldo teklife evet dedi.', 'q', 1.0), '"evet dedi" is not speech');
        $this->assertSame('Federasyon cezanın 30 gün olduğunu duyurdu.', $sp->spin('Federasyon cezanın 30 gün olduğunu açıkladı.', 'a', 1.0), 'announcement after "-dığını"');
        $this->assertSame('Ronaldo sırrını açıkladı.', $sp->spin('Ronaldo sırrını açıkladı.', 'a', 1.0), '"sırrını açıkladı" = revealed');
        $this->assertSame('Ronaldo, nasıl oynaması gerektiğini açıkladı.', $sp->spin('Ronaldo, nasıl oynaması gerektiğini açıkladı.', 'a', 1.0), 'explained, not announced');
        $this->assertSame('Bunun yanı sıra, Ronaldo geldi. Takım ayrıca hazır.', $sp->spin('Ayrıca, Ronaldo geldi. Takım ayrıca hazır.', 's', 1.0), 'sentence start only');
        $this->assertSame('Rekabet yıllardır sürüyor. Ronaldo devam ediyor.', $sp->spin('Rekabet yıllardır devam ediyor. Ronaldo devam ediyor.', 'd', 1.0), 'after a duration only ("Ronaldo sürüyor" = is driving)');
        $this->assertSame('Performansı göze çarptı. Soruna dikkat çekti.', $sp->spin('Performansı dikkat çekti. Soruna dikkat çekti.', 'n', 1.0), 'never after a dative');
        $this->assertSame("Al-Nassr 2-1 galip geldi ama kupayı kazandı.", $sp->spin("Al-Nassr 2-1 kazandı ama kupayı kazandı.", 'k', 1.0), 'after a bare score only');
        $this->assertSame("Gol sayısını 981'e çıkardı, sesini yükseltti.", $sp->spin("Gol sayısını 981'e yükseltti, sesini yükseltti.", 'y', 1.0), 'after a number in the dative only');
        $this->assertSame('Asla vazgeçmedi.', $sp->spin('Asla vazgeçmedi.', 'u', 1.0), 'unknown contexts are ignored');
    }

    public function testDirectedAndFixedRules(): void
    {
        $sp = $this->ruleSpinner();
        $this->assertSame('Yıldız oyuncu maçı kazandı.', $sp->spin('Yıldız futbolcu maçı kazandı.', 'x', 1.0), 'futbolcu => oyuncu');
        $this->assertSame('Basketbol oyuncusu ve dizi oyuncu.', $sp->spin('Basketbol oyuncusu ve dizi oyuncu.', 'x', 1.0), 'oyuncu never => futbolcu');
        $this->assertSame('İlk yarı finalde ilk devre bitti.', $sp->spin('İlk yarı finalde ilk yarı bitti.', 'x', 1.0), '"ilk yarı final" (semi-final) is fixed');
        $this->assertSame(0, $sp->spinDetailed('İlk yarı finalde.', 'x', 1.0)['hits'], 'a fixed collocation is not a hit');
        $this->assertTrue($sp->groupCount() >= 9, 'groups + contextual + directed counted');
    }

    public function testNoNewRepetitionsAreCreated(): void
    {
        $sp = $this->ruleSpinner();
        foreach (['a', 'b', 'c', 'd', 'e', 'f'] as $seed) {
            $out = $sp->spin('Ten Hag, hayal kırıklığını şu sözlerle dile getirdi.', $seed, 1.0);
            $this->assertFalse(str_contains($out, 'ifadelerle ifade') || str_contains($out, 'ifadelerle dile'), 'no "şu ifadelerle ifade etti": ' . $out);
        }
    }

    public function testQuotationFragmentsAreNeverRewritten(): void
    {
        $sp = $this->ruleSpinner();
        // A sentence cut out of a longer quotation: the quotation opens (or closes) outside of it.
        $open = "Ronaldo şu sözlerle konuştu: 'Leo, maçta dile getirdi ve gol attı.";
        $this->assertContains("'Leo, maçta dile getirdi ve gol attı.", $sp->spin($open, 'f', 1.0));
        $close = 'Maçta oynamaya devam edeceğim." şu sözlerle dile getirdi.';
        $out = $sp->spin($close, 'f', 1.0);
        $this->assertTrue(str_starts_with($out, 'Maçta oynamaya devam edeceğim."'), 'closing fragment kept: ' . $out);
        $this->assertContains("''Maçta kaldım''", $sp->spin("Ronaldo ''Maçta kaldım'' dedi.", 'f', 1.0), "''…'' quotation");
    }

    // ------------------------------------------------------------------ sentence-level transformations

    public function testAdverbialAndSubjectReordering(): void
    {
        $sp = $this->ruleSpinner();
        $out = $sp->rewrite('Maçın 45. dakikasında Cristiano Ronaldo, penaltıdan golü attı.', 't', 0.0, ['structure' => 1.0]);
        $this->assertSame('Cristiano Ronaldo, maçın 45. dakikasında penaltıdan golü attı.', $out['text']);
        $this->assertSame(['frontToSubject'], $out['ops']);
        $back = $sp->rewrite('Cristiano Ronaldo, maçın 45. dakikasında penaltıdan golü attı.', 't', 0.0, ['structure' => 1.0]);
        $this->assertSame('Maçın 45. dakikasında Cristiano Ronaldo, penaltıdan golü attı.', $back['text']);
        // Never: a list after the comma, a compound subject, a time phrase that goes on.
        foreach ([
            'Al-Nassr kadrosunda Cristiano Ronaldo, Sadio Mané ve Jhon Duran yer aldı.',
            "Portekiz'de Ronaldo krizi büyüyor.",
            'Cristiano Ronaldo, bugün çarşamba günü Messi için bir mesaj paylaştı.',
            'Cristiano Ronaldo, dün akşam oynanan maçta iki gol attı.',
        ] as $sentence) {
            $this->assertSame($sentence, $sp->rewrite($sentence, 't', 0.0, ['structure' => 1.0])['text'], 'unchanged: ' . $sentence);
        }
    }

    public function testQuotationFramesAndNameOrder(): void
    {
        $sp = $this->ruleSpinner();
        $colon = $sp->rewrite('Ronaldo, maçtan sonra şunları söyledi: “Takımı bırakmadım.”', 'c', 0.0, ['structure' => 1.0])['text'];
        $this->assertTrue(in_array($colon, [
            'Ronaldo, maçtan sonra “Takımı bırakmadım.” diye konuştu.', 'Ronaldo, maçtan sonra “Takımı bırakmadım.” ifadelerini kullandı.', 'Ronaldo, maçtan sonra “Takımı bırakmadım.” dedi.',
        ], true), $colon);
        $this->assertContains('şunları söyledi: “Takımı bırakmadım.”', str_replace(['şu ifadeleri kullandı', 'şöyle konuştu'], 'şunları söyledi', $sp->rewrite('Ronaldo, “Takımı bırakmadım.” dedi.', 'c', 0.0, ['structure' => 1.0])['text']));
        $this->assertSame('Erik ten Hag, “Takımı yüzüstü bıraktı” diyerek Ronaldo’yu eleştirdi.', $sp->rewrite('“Takımı yüzüstü bıraktı” diyen Erik ten Hag, Ronaldo’yu eleştirdi.', 'c', 0.0, ['structure' => 1.0])['text']);
        $named = new Spinner(['groups' => [], 'protected' => ['Lionel Messi', 'Cristiano Ronaldo', 'Real Madrid', 'Barcelona', 'Portekiz', 'İspanya']], []);
        $this->assertSame('Cristiano Ronaldo ile Lionel Messi arasındaki rekabet sürüyor.', $named->rewrite('Lionel Messi ile Cristiano Ronaldo arasındaki rekabet sürüyor.', 'n', 0.0, ['structure' => 1.0])['text']);
        foreach ([
            'Portekiz ile İspanya maçında gol sesi çıkmadı.',                                        // home team first
            'Cristiano Ronaldo ve Lionel Messi, Real Madrid ve Barcelona ile tarih yazdı.',          // respectively
        ] as $sentence) {
            $this->assertSame($sentence, $named->rewrite($sentence, 'n', 0.0, ['structure' => 1.0])['text'], 'unchanged: ' . $sentence);
        }
    }

    public function testSafeSplittingAndMerging(): void
    {
        $sp = $this->ruleSpinner();
        $split = $sp->rewrite('Portekiz Futbol Federasyonu dün kararını resmen açıkladı ve oyuncuya otuz gün ceza verdiğini duyurdu.', 's', 0.0, ['structure' => 1.0]);
        $this->assertSame('Portekiz Futbol Federasyonu dün kararını resmen açıkladı. Oyuncuya otuz gün ceza verdiğini duyurdu.', $split['text']);
        $this->assertSame('Ronaldo bu akşam iki gol attı. Ancak takımı bu kez sahadan yenik ayrıldı.', $sp->rewrite('Ronaldo bu akşam iki gol attı, ancak takımı bu kez sahadan yenik ayrıldı.', 's', 0.0, ['structure' => 1.0])['text']);
        $this->assertSame('Adı ve soyadı açıklanmadı.', $sp->rewrite('Adı ve soyadı açıklanmadı.', 's', 0.0, ['structure' => 1.0])['text'], 'no split inside a noun phrase');
        $this->assertSame('Al-Nassr deplasmanda 2-1 kazandı ve Ronaldo iki gol attı.', $sp->merge('Al-Nassr deplasmanda 2-1 kazandı.', 'Ronaldo iki gol attı.'));
        $this->assertNull($sp->merge('Ronaldo “Hazırım” dedi.', 'Takım kazandı.'), 'never with a quotation');
        $this->assertNull($sp->merge('Al-Nassr kazandı.', 'Ancak Ronaldo sakatlandı.'), 'never before a connector');
        $this->assertTrue(Spinner::isFinite('kazandılar') && Spinner::isFinite('sürüyor') && !Spinner::isFinite('kendi') && !Spinner::isFinite('ayrıntı'));
    }

    public function testRewriteKeepsQuotesNumbersNamesAndIsDeterministic(): void
    {
        Spinner::reset();
        $sp = Spinner::instance();
        $text = 'Maçın 78. dakikasında Cristiano Ronaldo, “Bu gol taraftarlarımız için.” dedi ve Al-Nassr’ın 3-1’lik galibiyetinde kariyerindeki gol sayısını 981’e yükseltti.';
        $a = $sp->rewrite($text, 'det', 1.0, ['structure' => 1.0]);
        $this->assertSame($a, $sp->rewrite($text, 'det', 1.0, ['structure' => 1.0]), 'same seed => same output');
        foreach (['“Bu gol taraftarlarımız için.”', 'Cristiano Ronaldo', 'Al-Nassr’ın', '3-1’lik', '981’e', '78.'] as $kept) {
            $this->assertContains($kept, $a['text'], 'kept verbatim: ' . $kept);
        }
        $this->assertTrue($a['text'] !== $text, 'rewritten');
        $outputs = [];
        foreach (['s1', 's2', 's3', 's4', 's5'] as $seed) {
            $outputs[$sp->rewrite($text, $seed, 1.0, ['structure' => 1.0])['text']] = true;
        }
        $this->assertTrue(count($outputs) > 1, 'seeds vary the output');
    }

    // ------------------------------------------------------------------ headlines

    public function testHeadlinesDifferFromEverySourceTitleWithoutBoilerplate(): void
    {
        Spinner::reset();
        $composer = new ArticleComposer();
        $cases = [
            ["Ten Hag'dan Ronaldo'ya sert sözler: 'Takımı da, beni de yüzüstü bıraktı'", ['Yıllar sonra gelen Ronaldo itirafı: Takımı yüzüstü bıraktı']],
            ['Ronaldo kaç gol oldu, Ronaldo 1000 gol oldu mu?', []],
            ["Cristiano Ronaldo'dan futbol dünyasını karıştıracak açıklama! 1000 gol için konuştu", []],
            ['Milli takımdan emekli mi oldu? Ronaldo suskunluğunu bozdu', []],
            ["Ricardo Quaresma'dan Cristiano Ronaldo'ya gönderme: \"Bazen insan kendi yaptığı yatağa uzanır\"", []],
        ];
        foreach ($cases as [$base, $others]) {
            $titles = [$base, ...$others];
            $result = $composer->title($base, $titles, 'seed-' . md5($base), $others);
            $this->assertTrue($result !== null, 'headline for: ' . $base);
            $title = $result['title'];
            foreach ($titles as $t) {
                $this->assertTrue(Str::similarity($title, $t) < ArticleComposer::TITLE_MAX_SIMILARITY, "similar to source title: $title ~ $t");
            }
            $this->assertTrue(mb_strlen($title) <= 90, 'length: ' . $title);
            $this->assertTrue(preg_match('/ayrıntılar|detaylar|haberimizde|gündem:|dikkat çeken gelişme/iu', $title) !== 1, 'no boilerplate: ' . $title);
            $this->assertTrue(str_contains($title, 'Ronaldo'), 'names him: ' . $title);
            $this->assertFalse(str_contains($title, 'Ekibi yüzüstü'), 'reported speech is never reworded: ' . $title);
            $this->assertSame($result, $composer->title($base, $titles, 'seed-' . md5($base), $others), 'deterministic');
        }
        $this->assertNull($composer->title("'Ronaldo devre arasında Galatasaray'a geliyor!'", ["'Ronaldo devre arasında Galatasaray'a geliyor!'"], 's'), 'a quotation-only headline cannot be reworded: no post rather than a copy');
    }

    public function testFirstPersonSpeechDetection(): void
    {
        $this->assertTrue(ArticleComposer::firstPerson('Messi ile 15 yıl boyunca aynı sahneyi paylaştık.'));
        $this->assertTrue(ArticleComposer::firstPerson('1000 gole ulaşmalıyım demeyeceğim ama ulaşırsam harika olur.'));
        $this->assertTrue(ArticleComposer::firstPerson('Bana sunulan şeyi kabul ederim.'));
        $this->assertFalse(ArticleComposer::firstPerson('Takımın taktik anlayışı artık değişti ve teknik adam kritik bir karar verdi.'));
        $this->assertFalse(ArticleComposer::firstPerson('Ronaldo, "Ben buradayım" dedi.'), 'inside a quotation it is a quote');
    }
}
