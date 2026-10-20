<?php

declare(strict_types=1);

namespace App\Services\News;

use App\Core\App;
use App\Support\Str;

/**
 * Turns the clubs, competitions and people found in a story (protected terms of the synonym
 * data and their aliases) into Turkish tag names + slugs.
 *
 * Tags are stored in posts.tags as a comma-separated list of names; the tag page URL is
 * /haberler/etiket/{Str::slug(name)}.
 */
final class Tagger
{
    /** canonical tag name => [type, aliases (exact, case-sensitive, matched at word boundaries)] */
    private const TAGS = [
        // clubs (type 'club' => linked to /takimlar/{slug} when he played there)
        'Al-Nassr' => ['club', ['Al-Nassr', 'Al Nassr', 'AL NASSR', 'AL-NASSR']],
        'Manchester United' => ['club', ['Manchester United', 'Man United', 'Man Utd', 'MANCHESTER UNITED']],
        'Real Madrid' => ['club', ['Real Madrid', 'REAL MADRID']],
        'Juventus' => ['club', ['Juventus', 'JUVENTUS']],
        'Sporting CP' => ['club', ['Sporting CP', 'Sporting Lizbon', 'Sporting']],
        'Al-Hilal' => ['club', ['Al-Hilal', 'Al Hilal']],
        'Al-Ittihad' => ['club', ['Al-Ittihad', 'Al Ittihad']],
        'Al-Ahli' => ['club', ['Al-Ahli', 'Al Ahli']],
        'Barcelona' => ['club', ['Barcelona']],
        'Atlético Madrid' => ['club', ['Atlético Madrid', 'Atletico Madrid']],
        'Manchester City' => ['club', ['Manchester City']],
        'Bayern Münih' => ['club', ['Bayern Münih']],
        'Paris Saint-Germain' => ['club', ['Paris Saint-Germain', 'PSG']],
        'Liverpool' => ['club', ['Liverpool']],
        'Chelsea' => ['club', ['Chelsea']],
        'Arsenal' => ['club', ['Arsenal']],
        'Inter Miami' => ['club', ['Inter Miami']],
        'Galatasaray' => ['club', ['Galatasaray']],
        'Fenerbahçe' => ['club', ['Fenerbahçe']],
        'Beşiktaş' => ['club', ['Beşiktaş']],
        // national team
        'Portekiz Milli Takımı' => ['national', ['Portekiz Milli Takımı', 'Portekiz Milli Futbol Takımı', 'Portekiz milli takımı', 'Portekiz Futbol Federasyonu', 'Portekiz Milli Takım']],
        // competitions
        'UEFA Şampiyonlar Ligi' => ['competition', ['UEFA Şampiyonlar Ligi', 'Şampiyonlar Ligi']],
        'AFC Şampiyonlar Ligi' => ['competition', ['AFC Şampiyonlar Ligi', 'AFC Champions League', 'AFC Champions League Elite']],
        'Suudi Pro Lig' => ['competition', ['Suudi Pro Lig', 'Saudi Pro League', 'Roshn Saudi League', 'Suudi Arabistan Ligi']],
        'Kral Kupası' => ['competition', ['Kral Kupası', 'King\'s Cup']],
        'Dünya Kupası' => ['competition', ['Dünya Kupası', 'FIFA Dünya Kupası']],
        'Avrupa Şampiyonası' => ['competition', ['Avrupa Şampiyonası', 'UEFA EURO', 'EURO 2024', 'EURO 2016']],
        'UEFA Uluslar Ligi' => ['competition', ['UEFA Uluslar Ligi', 'Uluslar Ligi', 'UEFA Nations League', 'Nations League']],
        'Premier Lig' => ['competition', ['Premier Lig']],
        'La Liga' => ['competition', ['La Liga']],
        'Serie A' => ['competition', ['Serie A']],
        // awards
        'Ballon d’Or' => ['award', ['Ballon d’Or', 'Ballon d\'Or', 'Altın Top']],
        'Altın Ayakkabı' => ['award', ['Altın Ayakkabı']],
        // people
        'Lionel Messi' => ['person', ['Lionel Messi', 'Messi']],
        'Georgina Rodríguez' => ['person', ['Georgina Rodríguez', 'Georgina Rodriguez', 'Georgina']],
        'Cristiano Ronaldo Jr.' => ['person', ['Cristiano Jr.', 'Cristiano Ronaldo Jr', 'Cristianinho']],
        'Jorge Jesus' => ['person', ['Jorge Jesus']],
        'Roberto Martínez' => ['person', ['Roberto Martínez', 'Roberto Martinez']],
        'Erik ten Hag' => ['person', ['Erik ten Hag', 'Ten Hag']],
        'Sir Alex Ferguson' => ['person', ['Sir Alex Ferguson', 'Alex Ferguson']],
        'José Mourinho' => ['person', ['José Mourinho', 'Jose Mourinho', 'Mourinho']],
        'Piers Morgan' => ['person', ['Piers Morgan']],
        'Kylian Mbappé' => ['person', ['Kylian Mbappé', 'Mbappé']],
        'Erling Haaland' => ['person', ['Erling Haaland', 'Haaland']],
        'Luís Figo' => ['person', ['Luís Figo', 'Luis Figo']],
        'Sadio Mané' => ['person', ['Sadio Mané']],
        'Jorge Mendes' => ['person', ['Jorge Mendes']],
        'Arda Güler' => ['person', ['Arda Güler']],
        'Kenan Yıldız' => ['person', ['Kenan Yıldız']],
    ];

    /** Topic tags found by keywords (lowercase stems at word start). */
    private const TOPICS = [
        'Transfer' => ['transfer', 'sözleşme', 'kontrat', 'bonservis'],
        'Rekor' => ['rekor'],
        'Sakatlık' => ['sakatl', 'sakatlan'],
        'Disiplin Cezası' => ['cezası', 'cezayı', 'cezaya', 'cezalandır', 'hak mahrumiyeti'],
    ];

    public const MAX_TAGS = 6;

    /**
     * Tags for a story, most relevant first (title mentions weigh more than body mentions).
     *
     * @return list<array{name: string, slug: string, type: string}>
     */
    public static function tags(string $title, string $text, int $max = self::MAX_TAGS): array
    {
        $scores = [];
        foreach (self::TAGS as $name => [$type, $aliases]) {
            $score = 0;
            foreach ($aliases as $alias) {
                $pattern = '/(?<![\p{L}\p{N}])' . preg_quote($alias, '/') . '(?![\p{L}\p{N}])/u';
                $score += 3 * preg_match_all($pattern, $title) + preg_match_all($pattern, $text);
            }
            if ($score >= 2) { // a title mention or at least two mentions in the text
                $scores[$name] = ['score' => $score, 'type' => $type];
            }
        }
        $lowerTitle = Str::lower($title);
        $lowerText = Str::lower($text);
        foreach (self::TOPICS as $name => $stems) {
            $score = 0;
            foreach ($stems as $stem) {
                $pattern = '/(?<![\p{L}\p{N}])' . preg_quote($stem, '/') . '/u';
                $score += 3 * preg_match_all($pattern, $lowerTitle) + preg_match_all($pattern, $lowerText);
            }
            if ($score >= 2) { // a topic must be more than a passing mention
                $scores[$name] = ['score' => $score, 'type' => 'topic'];
            }
        }
        uasort($scores, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        $out = [];
        foreach (array_slice($scores, 0, $max, true) as $name => $info) {
            $out[] = ['name' => (string) $name, 'slug' => Str::slug((string) $name), 'type' => $info['type']];
        }
        return $out;
    }

    /** Tag names as stored in posts.tags ("Al-Nassr, Suudi Pro Lig"). */
    public static function toColumn(array $tags): string
    {
        return mb_substr(implode(', ', array_map(static fn (array $t): string => $t['name'], $tags)), 0, 500);
    }

    /**
     * posts.tags column => tag list (name + slug).
     *
     * @return list<array{name: string, slug: string, type: string}>
     */
    public static function fromColumn(?string $column): array
    {
        $out = [];
        foreach (explode(',', (string) $column) as $name) {
            $name = trim($name);
            if ($name === '' || Str::slug($name) === '') {
                continue;
            }
            $out[] = ['name' => $name, 'slug' => Str::slug($name), 'type' => self::TAGS[$name][0] ?? (isset(self::TOPICS[$name]) ? 'topic' : 'other')];
        }
        return $out;
    }

    /** Canonical tag name for a slug (dictionary tags only), or null. */
    public static function nameForSlug(string $slug): ?string
    {
        foreach ([...array_keys(self::TAGS), ...array_keys(self::TOPICS)] as $name) {
            if (Str::slug($name) === $slug) {
                return $name;
            }
        }
        return null;
    }

    /**
     * /takimlar/{slug} for clubs he played for (from content/career.php), keyed by tag name.
     *
     * @return array<string, string> tag name => club page path
     */
    public static function clubPages(): array
    {
        static $pages = null;
        if ($pages !== null) {
            return $pages;
        }
        $pages = [];
        $file = App::basePath('content/career.php');
        $career = is_file($file) ? require $file : [];
        foreach ((array) ($career['clubs'] ?? []) as $club) {
            if (!is_array($club) || empty($club['slug']) || empty($club['name'])) {
                continue;
            }
            foreach (self::TAGS as $name => [$type, $aliases]) {
                if ($type === 'club' && (Str::slug($name) === $club['slug'] || in_array($club['name'], $aliases, true))) {
                    $pages[$name] = '/takimlar/' . $club['slug'];
                }
            }
        }
        return $pages;
    }
}
