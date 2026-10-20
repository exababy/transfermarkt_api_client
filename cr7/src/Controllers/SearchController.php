<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Seo;
use App\Repositories\NewsRepository;
use App\Repositories\VideoRepository;
use App\Support\Html;
use App\Support\Str;

/**
 * /arama?q= — site search over news posts, videos and the static editorial sections
 * (biography, career, honours, main pages). noindex,follow; minimum 2 characters.
 */
final class SearchController
{
    public const MIN_LENGTH = 2;
    public const MAX_LENGTH = 100;
    private const MAX_STATIC = 12;

    /** Characters folded for accent/case-insensitive matching (after Turkish lowercasing). */
    private const FOLD = [
        'ç' => 'c', 'ğ' => 'g', 'ı' => 'i', 'ö' => 'o', 'ş' => 's', 'ü' => 'u', 'â' => 'a', 'î' => 'i', 'û' => 'u',
        'á' => 'a', 'à' => 'a', 'ã' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o',
        'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ñ' => 'n', 'č' => 'c', '’' => "'", '‘' => "'", 'ʼ' => "'",
    ];

    public function index(Request $request): Response
    {
        $raw = $request->query('q', '');
        $q = is_string($raw) ? trim((string) preg_replace('/\s+/u', ' ', $raw)) : '';
        if (mb_strlen($q) > self::MAX_LENGTH) {
            $q = mb_substr($q, 0, self::MAX_LENGTH);
        }
        $terms = self::terms($q);
        $tooShort = $q !== '' && $terms === [];

        $sections = [];
        $posts = [];
        $videos = [];
        if ($terms !== []) {
            $sections = self::searchStatic($terms);
            if (class_exists(NewsRepository::class)) {
                $posts = (array) LifeController::safely(static fn () => (new NewsRepository())->search($q, 20), []);
            }
            if (class_exists(VideoRepository::class)) {
                $videos = (array) LifeController::safely(static fn () => (new VideoRepository())->search($q, 12), []);
            }
        }
        $total = count($sections) + count($posts) + count($videos);

        $seo = new Seo(
            title: $q !== '' ? '“' . Str::limit($q, 40) . '” için arama sonuçları' : 'Sitede Ara',
            description: 'CR7.com.tr içinde Cristiano Ronaldo haberleri, videoları, hayatı, kariyeri, kupaları ve istatistikleri arasında arama yapın.',
            canonical: url('/arama'),
            robots: 'noindex,follow',
            breadcrumbs: [['name' => 'Arama', 'url' => '/arama']],
        );

        return view('pages/search', [
            'seo' => $seo,
            'extraHead' => partial('partials/home-styles', ['page' => 'search']),
            'q' => $q,
            'terms' => $terms,
            'tooShort' => $tooShort,
            'sections' => $sections,
            'posts' => $posts,
            'videos' => $videos,
            'total' => $total,
        ]);
    }

    // ------------------------------------------------------------------ matching

    /** @return list<string> folded search terms (>= MIN_LENGTH chars, max 6) */
    public static function terms(string $q): array
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/u', self::fold($q)) ?: [] as $term) {
            $term = trim($term, " \t\"'.:!?()[]{}");
            if (mb_strlen($term) >= self::MIN_LENGTH && !in_array($term, $out, true)) {
                $out[] = $term;
            }
        }
        return array_slice($out, 0, 6);
    }

    /** Turkish-aware lowercase + accent folding (one output char per input char). */
    public static function fold(string $text): string
    {
        return implode('', self::foldChars($text));
    }

    /** True when every term occurs in $text (folded) at the start of a word (Turkish suffixes allowed). */
    public static function matches(string $text, array $terms): bool
    {
        if ($terms === []) {
            return false;
        }
        $folded = self::fold($text);
        foreach ($terms as $term) {
            if (self::positions($folded, (string) $term, 1) === []) {
                return false;
            }
        }
        return true;
    }

    /**
     * Character offsets of $term in the folded $haystack where it starts a word
     * ('ligi' matches "Ligi’nde" but not "Krallığı").
     *
     * @return list<int>
     */
    private static function positions(string $haystack, string $term, int $max = 0): array
    {
        $out = [];
        $len = mb_strlen($term);
        $offset = 0;
        while ($len > 0 && ($pos = mb_strpos($haystack, $term, $offset)) !== false) {
            $prev = $pos > 0 ? mb_substr($haystack, $pos - 1, 1) : '';
            if ($prev === '' || preg_match('/[\p{L}\p{N}]/u', $prev) !== 1) {
                $out[] = $pos;
                if ($max > 0 && count($out) >= $max) {
                    break;
                }
            }
            $offset = $pos + 1;
        }
        return $out;
    }

    /**
     * Escaped HTML of $text with every term occurrence wrapped in <mark>.
     * Matching is done on the folded text; offsets map 1:1 to the original characters.
     */
    public static function highlight(string $text, array $terms): string
    {
        $chars = mb_str_split($text);
        if ($chars === [] || $terms === []) {
            return e($text);
        }
        $folded = self::foldChars($text);
        $haystack = implode('', $folded);
        $marked = array_fill(0, count($chars), false);
        foreach ($terms as $term) {
            $len = mb_strlen((string) $term);
            foreach (self::positions($haystack, (string) $term) as $pos) {
                for ($i = $pos; $i < $pos + $len; $i++) {
                    $marked[$i] = true;
                }
            }
        }
        $out = '';
        $open = false;
        foreach ($chars as $i => $char) {
            if ($marked[$i] && !$open) {
                $out .= '<mark class="site-mark">';
                $open = true;
            } elseif (!$marked[$i] && $open) {
                $out .= '</mark>';
                $open = false;
            }
            $out .= e($char);
        }
        return $out . ($open ? '</mark>' : '');
    }

    /** ~$length chars of $text around the first term occurrence (plain text). */
    public static function snippet(string $text, array $terms, int $length = 200): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if (mb_strlen($text) <= $length) {
            return $text;
        }
        $haystack = self::fold($text);
        $first = null;
        foreach ($terms as $term) {
            $pos = self::positions($haystack, (string) $term, 1)[0] ?? null;
            if ($pos !== null && ($first === null || $pos < $first)) {
                $first = $pos;
            }
        }
        $start = $first === null ? 0 : max(0, $first - (int) ($length / 3));
        if ($start > 0) {
            $space = mb_strpos($text, ' ', $start);
            $start = $space !== false && $space < $start + 20 ? $space + 1 : $start;
        }
        $piece = Str::limit(mb_substr($text, $start), $length);
        return ($start > 0 ? '…' : '') . $piece;
    }

    /** @return list<string> */
    private static function foldChars(string $text): array
    {
        $out = [];
        foreach (mb_str_split($text) as $char) {
            $lower = Str::lower($char);
            $lower = self::FOLD[$lower] ?? $lower;
            $out[] = mb_strlen($lower) === 1 ? $lower : mb_substr($lower, 0, 1);
        }
        return $out;
    }

    // ------------------------------------------------------------------ static index

    /**
     * Searchable static entries: [['title','url','text','type']].
     *
     * @return list<array{title: string, url: string, text: string, type: string}>
     */
    public static function staticIndex(): array
    {
        $entries = [];
        $add = static function (string $type, string $title, string $url, string $text = '') use (&$entries): void {
            $entries[] = ['type' => $type, 'title' => $title, 'url' => $url, 'text' => $text];
        };

        $add('Sayfa', 'Cristiano Ronaldo’nun Hayatı', '/hayati', 'Biyografi, ailesi, çocukluğu, özel hayatı, serveti ve hakkında merak edilen her şey.');
        $add('Sayfa', 'Kariyer', '/kariyer', 'Sporting CP, Manchester United, Real Madrid, Juventus ve Al-Nassr kariyeri, transferler ve piyasa değeri.');
        $add('Sayfa', 'Oynadığı Takımlar', '/takimlar', 'Ronaldo’nun forma giydiği kulüpler ve Portekiz Milli Takımı.');
        $add('Sayfa', 'İstatistikler', '/istatistikler', 'Gol, asist, maç ve dakika istatistikleri; sezon sezon ve turnuva turnuva veriler.');
        $add('Sayfa', 'Maç Arşivi', '/istatistikler/maclar', 'Ronaldo’nun oynadığı tüm maçlar, skorlar, goller ve asistler.');
        $add('Sayfa', 'Kupalar ve Ödüller', '/kupalar-ve-oduller', 'Takım kupaları, Ballon d’Or, Altın Ayakkabı, bireysel ödüller ve rekorlar.');
        $add('Sayfa', 'Videolar', '/videolar', 'Goller, maç özetleri, röportajlar ve belgeseller.');
        $add('Sayfa', 'Haberler', '/haberler', 'Cristiano Ronaldo ile ilgili son dakika haberleri.');
        if ((bool) setting('shop.enabled', true)) {
            $add('Sayfa', 'Forma Mağazası', '/forma-magazasi', 'Ronaldo’nun oynadığı tüm takımların formaları.');
        }
        $add('Sayfa', 'Hakkında', '/hakkinda', 'Bağımsız hayran sitesi, veri kaynakları ve yayın ilkeleri.');
        $add('Sayfa', 'İletişim', '/iletisim', 'Bize ulaşın: düzeltme, telif ve sipariş talepleri.');
        $add('Sayfa', 'Gizlilik Politikası ve KVKK', '/gizlilik-politikasi', 'Kişisel verilerin korunması, çerez politikası ve ödeme verileri.');

        $bio = LifeController::content('biography');
        foreach ((array) ($bio['sections'] ?? []) as $section) {
            if (!is_array($section) || empty($section['id']) || empty($section['title'])) {
                continue;
            }
            $html = (string) ($section['html'] ?? '');
            $headings = [];
            if (preg_match_all('~<h3[^>]*>(.*?)</h3>~is', $html, $m) > 0) {
                $headings = array_map(static fn (string $h): string => trim(Html::toText($h)), $m[1]);
            }
            $add('Hayatı', (string) $section['title'], '/hayati#' . $section['id'], trim(implode(' · ', $headings) . ' ' . Html::toText($html)));
        }
        foreach ((array) ($bio['faq'] ?? []) as $item) {
            if (is_array($item) && !empty($item['q'])) {
                $add('Sık sorulan soru', (string) $item['q'], '/hayati#sss', (string) ($item['a'] ?? ''));
            }
        }
        foreach ((array) ($bio['quick_facts'] ?? []) as $fact) {
            if (is_array($fact) && !empty($fact['label'])) {
                $add('Hızlı bilgi', 'Ronaldo: ' . $fact['label'], '/hayati', (string) ($fact['value'] ?? ''));
            }
        }

        $career = LifeController::content('career');
        foreach ((array) ($career['clubs'] ?? []) as $club) {
            if (!is_array($club) || empty($club['slug']) || empty($club['name'])) {
                continue;
            }
            $title = $club['name'] . (!empty($club['period']) ? ' (' . $club['period'] . ')' : '');
            $text = trim((string) ($club['summary'] ?? '') . ' ' . implode(' ', array_map('strval', (array) ($club['honours'] ?? []))) . ' ' . Html::toText((string) ($club['html'] ?? '')));
            $add('Kariyer', $title, '/takimlar/' . $club['slug'], $text);
        }
        if (isset($career['national']) && is_array($career['national'])) {
            $national = $career['national'];
            $add('Kariyer', (string) ($national['name'] ?? 'Portekiz Milli Takımı'), '/takimlar/' . ($national['slug'] ?? 'portekiz'), trim((string) ($national['summary'] ?? '') . ' ' . Html::toText((string) ($national['html'] ?? ''))));
        }
        if (isset($career['youth']) && is_array($career['youth'])) {
            $add('Kariyer', (string) ($career['youth']['title'] ?? 'Altyapı yılları'), '/kariyer', Html::toText((string) ($career['youth']['html'] ?? '')));
        }

        $honours = LifeController::content('honours');
        foreach (HonoursController::groups($honours) as $group) {
            foreach ($group['items'] as $item) {
                $add('Kupa', $item['title'] . ' – ' . $group['club'], '/kupalar-ve-oduller#' . $group['anchor'], $item['count'] . ' kez: ' . implode(', ', $item['seasons']) . '. ' . $item['note']);
            }
        }
        foreach ((array) ($honours['individual'] ?? []) as $award) {
            if (is_array($award) && !empty($award['title'])) {
                $add('Ödül', (string) $award['title'], '/kupalar-ve-oduller#bireysel-oduller', (int) ($award['count'] ?? 0) . ' kez: ' . implode(', ', array_map('strval', (array) ($award['years'] ?? []))) . '. ' . ($award['note'] ?? ''));
            }
        }
        foreach ((array) ($honours['records'] ?? []) as $record) {
            if (is_array($record) && !empty($record['title'])) {
                $add('Rekor', (string) $record['title'], '/kupalar-ve-oduller#rekorlar', (string) ($record['detail'] ?? ''));
            }
        }
        return $entries;
    }

    /**
     * Static entries matching every term (title matches first).
     *
     * @return list<array{title: string, url: string, text: string, type: string, snippet: string}>
     */
    public static function searchStatic(array $terms): array
    {
        $titleHits = [];
        $textHits = [];
        foreach (self::staticIndex() as $entry) {
            if (self::matches($entry['title'], $terms)) {
                $entry['snippet'] = self::snippet($entry['text'], $terms);
                $titleHits[] = $entry;
            } elseif (self::matches($entry['title'] . ' ' . $entry['text'], $terms)) {
                $entry['snippet'] = self::snippet($entry['text'], $terms);
                $textHits[] = $entry;
            }
        }
        return array_slice(array_merge($titleHits, $textHits), 0, self::MAX_STATIC);
    }
}
