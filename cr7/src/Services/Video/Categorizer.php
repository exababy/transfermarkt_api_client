<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Repositories\VideoRepository;
use App\Support\Str;

/**
 * Keyword based video categories (TR + EN, plus the PT/ES/IT/AR words official channels use)
 * and the "is this about Cristiano Ronaldo?" filter for club/federation channels.
 *
 * Matching runs on title + description lowercased twice (Turkish-aware, I => ı, and plain
 * mb_strtolower, I => i) so both "MAÇ ÖZETİ" and "INTERVIEW" match. Latin keywords must
 * start at a word boundary and match as prefixes, so Turkish suffixes work (gol => golü, goller).
 */
final class Categorizer
{
    /** UR · Cristiano, Ronaldo's own channel: everything from it goes to 'ur-cristiano'. */
    public const UR_CHANNEL_ID = 'UCtxD0x6AuNNqdXO9Wp5GHew';

    /** Checked in this order; the first category with a hit wins. */
    private const RULES = [
        'belgesel' => ['belgesel', 'documentary', 'documental', 'documentário', 'documentario', 'docuseries', 'film', 'hikâyesi', 'hikayesi', 'his story', 'the story of', 'inside story', 'وثائقي'],
        'roportajlar' => ['röportaj', 'roportaj', 'interview', 'basın toplantısı', 'press conference', 'açıklama', 'konuştu', 'entrevista', 'conferência de imprensa', 'conferencia de prensa', 'rueda de prensa', 'conferenza stampa', 'intervista', 'take questions', 'q&a', 'speaks', 'speech', 'مؤتمر صحفي', 'مقابلة', 'لقاء', 'تصريح'],
        'antrenman' => ['antrenman', 'idman', 'training', 'workout', 'gym', 'treino', 'entrenamiento', 'allenamento', 'تدريب', 'تدريبات', 'التدريب'],
        'mac-ozetleri' => ['özet', 'ozet', 'maç özeti', 'highlights', 'highlight', 'extended', 'full match', 'resumo', 'resumen', 'melhores momentos', 'sintesi', 'ملخص'],
        'goller' => ['gol', 'goal', 'golazo', 'frikik', 'serbest vuruş', 'free kick', 'free-kick', 'freekick', 'hat-trick', 'hattrick', 'hat trick', 'rövaşata', 'bicycle kick', 'overhead kick', 'golo', 'golos', 'goles', 'هدف', 'أهداف', 'اهداف', 'هاتريك'],
    ];

    /** Prefix matches that mean something else ("Golden Boot", "goalkeeper", "filmed by fans"). */
    private const FALSE_FRIENDS = [
        'goller' => ['golden', 'golf', 'goalkeeper', 'goalkeeping', 'goleiro', 'golpe'],
        'belgesel' => ['filmed', 'filming', 'filmagem', 'filmación'],
    ];

    private const RONALDO_TERMS = ['ronaldo', 'cr7', 'cristiano', 'رونالدو', 'كريستيانو', 'роналду'];

    /** Other famous "Ronaldo"s (R9, Ronaldinho is covered by the boundary rule). */
    private const OTHER_RONALDOS = ['nazário', 'nazario', 'r9', 'fenômeno', 'fenomeno', 'o fenômeno'];

    /** Non-football uploads: UR · Cristiano also streams MMA events and eSports promos. */
    private const OFF_TOPIC_TERMS = ['mma', 'ufc', 'esports', 'e-sports', 'boxing', 'boks', 'poker'];

    /**
     * Category key from VideoRepository::categories().
     *
     * @param string|null $channelId YouTube channel id ('UC…')
     * @param string|null $channelTitle used when the id is unknown (oEmbed gives only the name)
     */
    public static function categorize(string $title, string $description = '', ?string $channelId = null, ?string $channelTitle = null): string
    {
        if (self::isUrCristiano($channelId, $channelTitle)) {
            return 'ur-cristiano';
        }
        // The title decides first; the description (often channel boilerplate) only breaks ties.
        foreach ([$title, $title . "\n" . self::firstParagraph($description)] as $text) {
            $haystack = self::haystack($text);
            foreach (self::RULES as $category => $keywords) {
                foreach ($keywords as $keyword) {
                    if (self::matches($haystack, $keyword, self::FALSE_FRIENDS[$category] ?? [])) {
                        return $category;
                    }
                }
            }
        }
        return 'genel';
    }

    /** True for Ronaldo's own channel (by id, or by name when only the name is known). */
    public static function isUrCristiano(?string $channelId, ?string $channelTitle = null): bool
    {
        if ($channelId !== null && $channelId !== '') {
            return $channelId === self::UR_CHANNEL_ID;
        }
        $name = Str::lower(trim((string) $channelTitle));
        return $name !== '' && (str_starts_with($name, 'ur · cristiano') || $name === 'ur cristiano' || $name === 'ur·cristiano');
    }

    /**
     * Does the video concern Cristiano Ronaldo? Title, or the first paragraph of the
     * description (later paragraphs are usually channel boilerplate that mentions him on
     * every upload). "Ronaldo Nazário"-only videos are rejected.
     */
    public static function mentionsRonaldo(string $title, string $description = ''): bool
    {
        $haystack = self::haystack($title . "\n" . self::firstParagraph($description));
        $hit = false;
        foreach (self::RONALDO_TERMS as $term) {
            if (self::matches($haystack, $term)) {
                $hit = true;
                break;
            }
        }
        if (!$hit) {
            return false;
        }
        $cristiano = self::matches($haystack, 'cristiano') || self::matches($haystack, 'cr7')
            || str_contains($haystack, 'كريستيانو');
        if (!$cristiano) {
            foreach (self::OTHER_RONALDOS as $other) {
                if (self::matches($haystack, $other)) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Off-topic for a football fan site (MMA, boxing, eSports, poker promos): judged by the title
     * only, since channel descriptions are boilerplate. VideoSync neither imports nor lists these.
     */
    public static function isOffTopic(string $title): bool
    {
        if (str_contains($title, '🥊')) {
            return true;
        }
        $haystack = self::haystack($title);
        foreach (self::OFF_TOPIC_TERMS as $term) {
            if (self::matches($haystack, $term)) {
                return true;
            }
        }
        return false;
    }

    /** YouTube Shorts: /shorts/ link, or duration under 61 seconds when known. */
    public static function isShort(?int $durationSeconds, string $link = ''): bool
    {
        if (str_contains($link, '/shorts/')) {
            return true;
        }
        return $durationSeconds !== null && $durationSeconds > 0 && $durationSeconds < 61;
    }

    /** Known category key or 'genel'. */
    public static function normalize(?string $category): string
    {
        $category = (string) $category;
        return array_key_exists($category, VideoRepository::categories()) ? $category : 'genel';
    }

    private static function haystack(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(['’', '‘', 'ʼ', '`'], "'", $text);
        // Turkish-aware and plain lowercase side by side: "INTERVIEW" => "ınterview" + "interview".
        return ' ' . Str::lower($text) . "\n" . mb_strtolower($text, 'UTF-8') . ' ';
    }

    /** @param list<string> $falseFriends words that start with $keyword but must not count */
    private static function matches(string $haystack, string $keyword, array $falseFriends = []): bool
    {
        $keyword = mb_strtolower($keyword, 'UTF-8');
        if (preg_match('/^\p{Latin}/u', $keyword) !== 1) {
            return str_contains($haystack, $keyword); // Arabic/Cyrillic: plain substring (prefixes like ال)
        }
        $pattern = '/(?<![\p{L}\p{N}])' . preg_quote($keyword, '/') . '/u';
        if (preg_match_all($pattern, $haystack, $m, PREG_OFFSET_CAPTURE) < 1) {
            return false;
        }
        if ($falseFriends === []) {
            return true;
        }
        foreach ($m[0] as [, $pos]) {
            $word = (string) preg_replace('/[^\p{L}\p{N}-].*$/su', '', mb_strcut($haystack, $pos, 40, 'UTF-8'));
            $falseFriend = false;
            foreach ($falseFriends as $bad) {
                if (str_starts_with($word, $bad)) {
                    $falseFriend = true;
                    break;
                }
            }
            if (!$falseFriend) {
                return true;
            }
        }
        return false;
    }

    private static function firstParagraph(string $description): string
    {
        $description = trim(str_replace(["\r\n", "\r"], "\n", $description));
        if ($description === '') {
            return '';
        }
        $first = preg_split("/\n\s*\n/", $description, 2)[0] ?? '';
        return mb_substr($first, 0, 400);
    }
}
