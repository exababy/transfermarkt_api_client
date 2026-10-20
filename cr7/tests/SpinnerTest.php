<?php

declare(strict_types=1);

namespace Tests;

use App\Services\News\Spinner;

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
}
