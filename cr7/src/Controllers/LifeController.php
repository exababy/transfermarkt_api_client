<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Core\Seo;
use App\Repositories\StatsRepository;
use App\Support\Format;
use App\Support\Html;
use App\Support\Log;
use App\Support\OgImage;
use App\Support\Str;

/**
 * /hayati — long-form biography (content/biography.php) with quick facts, table of contents,
 * timeline, FAQ (FAQPage JSON-LD) and sources.
 *
 * Also hosts the helpers shared by the site module's controllers: content loading,
 * guarded repository calls and the Cristiano Ronaldo Person schema.
 */
final class LifeController
{
    /** Public profiles of Cristiano Ronaldo (Person.sameAs). */
    public const SAME_AS = [
        'https://tr.wikipedia.org/wiki/Cristiano_Ronaldo',
        'https://en.wikipedia.org/wiki/Cristiano_Ronaldo',
        'https://www.wikidata.org/wiki/Q11571',
        'https://www.instagram.com/cristiano/',
        'https://x.com/Cristiano',
        'https://www.facebook.com/Cristiano',
        'https://www.youtube.com/@cristiano',
        'https://www.transfermarkt.com.tr/cristiano-ronaldo/profil/spieler/8198',
    ];

    /** Credit line required when the Transfermarkt portrait is shown. */
    public const TM_PORTRAIT_CREDIT = 'Fotoğraf: IMAGO / Transfermarkt';

    /** @var array<string, array<string, mixed>> */
    private static array $content = [];

    public function index(Request $request): Response
    {
        $bio = self::content('biography');
        $player = self::player();
        $title = (string) ($bio['title'] ?? 'Cristiano Ronaldo’nun Hayatı');
        $sections = array_values(array_filter(
            (array) ($bio['sections'] ?? []),
            static fn ($s): bool => is_array($s) && !empty($s['id']) && !empty($s['title'])
        ));
        $faq = array_values(array_filter((array) ($bio['faq'] ?? []), static fn ($f): bool => is_array($f) && !empty($f['q']) && !empty($f['a'])));
        $timeline = array_values(array_filter((array) ($bio['timeline'] ?? []), static fn ($t): bool => is_array($t) && isset($t['year'], $t['text'])));
        $sources = array_values(array_filter((array) ($bio['sources'] ?? []), static fn ($s): bool => is_array($s) && !empty($s['url'])));
        $reviewed = self::reviewedDate($bio['last_reviewed'] ?? null);

        $toc = [];
        foreach ($sections as $section) {
            $toc[] = ['id' => (string) $section['id'], 'title' => (string) $section['title']];
        }
        if ($timeline !== []) {
            $toc[] = ['id' => 'zaman-cizelgesi', 'title' => 'Yıl Yıl Ronaldo’nun Hayatı'];
        }
        if ($faq !== []) {
            $toc[] = ['id' => 'sss', 'title' => 'Sıkça Sorulan Sorular'];
        }
        if ($sources !== []) {
            $toc[] = ['id' => 'kaynaklar', 'title' => 'Kaynaklar'];
        }

        $words = count(preg_split('/\s+/u', Html::toText((string) ($bio['lead'] ?? '') . ' ' . implode(' ', array_column($sections, 'html'))), -1, PREG_SPLIT_NO_EMPTY) ?: []);
        $faqSchema = [];
        foreach ($faq as $item) {
            $faqSchema[] = [
                '@type' => 'Question',
                'name' => (string) $item['q'],
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => trim(Html::toText((string) $item['a']))],
            ];
        }

        $jsonLd = [self::personSchema($player, true)];
        if ($faqSchema !== []) {
            $jsonLd[] = ['@type' => 'FAQPage', 'mainEntity' => $faqSchema];
        }

        $card = OgImage::page('hayati', 'Cristiano Ronaldo’nun Hayatı', 'Biyografi ve aile', 'HAYATI');
        $seo = new Seo(
            title: 'Cristiano Ronaldo’nun Hayatı: Biyografi, Ailesi, Kariyeri',
            description: 'Cristiano Ronaldo’nun hayatı: Madeira’daki çocukluğu, ailesi, kalp ameliyatı, çocukları, serveti, rekorları ve CR7 hakkında merak edilen tüm sorular.',
            canonical: url('/hayati'),
            image: $card,
            type: 'profile',
            jsonLd: $jsonLd,
            breadcrumbs: [['name' => 'Hayatı', 'url' => '/hayati']],
            modifiedTime: $reviewed !== null ? Format::iso($reviewed) : null,
            extraMeta: [
                ['property' => 'profile:first_name', 'content' => 'Cristiano'],
                ['property' => 'profile:last_name', 'content' => 'Ronaldo'],
                ['property' => 'profile:gender', 'content' => 'male'],
                ...OgImage::meta($card),
            ],
        );

        return view('pages/life', [
            'seo' => $seo,
            'activeNav' => 'hayati',
            'extraHead' => partial('partials/home-styles', ['page' => 'life']),
            'bio' => $bio,
            'title' => $title,
            'lead' => self::localLinks((string) ($bio['lead'] ?? '')),
            'facts' => array_values(array_filter((array) ($bio['quick_facts'] ?? []), static fn ($f): bool => is_array($f) && isset($f['label'], $f['value']))),
            'sections' => array_map(static fn (array $s): array => ['html' => self::localLinks((string) ($s['html'] ?? ''))] + $s, $sections),
            'toc' => $toc,
            'timeline' => $timeline,
            'faq' => $faq,
            'sources' => $sources,
            'reviewed' => $reviewed,
            'readingMinutes' => max(1, (int) round($words / 200)),
        ]);
    }

    // ------------------------------------------------------------------ shared helpers (site module)

    /** content/{name}.php as an array ([] when missing or invalid); memoized per request. */
    public static function content(string $name): array
    {
        if (!array_key_exists($name, self::$content)) {
            $data = [];
            if (preg_match('/^[a-z0-9-]+$/', $name) === 1) {
                $file = App::basePath('content/' . $name . '.php');
                try {
                    $loaded = is_file($file) ? require $file : [];
                    $data = is_array($loaded) ? $loaded : [];
                } catch (\Throwable $e) {
                    Log::error('site', 'Content file failed to load', ['file' => $name, 'error' => $e->getMessage()]);
                }
            }
            self::$content[$name] = $data;
        }
        return self::$content[$name];
    }

    /** Run $fn; on any error log it and return $default (public pages must never fatal). */
    public static function safely(callable $fn, mixed $default = null): mixed
    {
        try {
            return $fn() ?? $default;
        } catch (\Throwable $e) {
            Log::error('site', 'Data block failed: ' . $e->getMessage(), ['exception' => get_class($e)]);
            return $default;
        }
    }

    /** StatsRepository::player() or null (module missing / not synced yet). */
    public static function player(): ?array
    {
        if (!class_exists(StatsRepository::class)) {
            return null;
        }
        $player = self::safely(static fn () => (new StatsRepository())->player());
        return is_array($player) ? $player : null;
    }

    /**
     * Portrait to show: setting site.portrait_url, else the Transfermarkt portrait (with credit).
     *
     * @return array{url: string, credit: ?string, width: int, height: int}|null
     */
    public static function portrait(?array $player): ?array
    {
        $custom = trim((string) setting('site.portrait_url', ''));
        if ($custom !== '' && (preg_match('#^https?://#i', $custom) === 1 || str_starts_with($custom, '/'))) {
            return ['url' => $custom, 'credit' => null, 'width' => 600, 'height' => 750];
        }
        $tm = (string) ($player['portrait_url'] ?? '');
        if ($tm !== '' && preg_match('#^https?://#i', $tm) === 1) {
            return ['url' => $tm, 'credit' => self::TM_PORTRAIT_CREDIT, 'width' => 300, 'height' => 390];
        }
        return null;
    }

    /** Name of the current club (TM player row, else the open-ended spell of content/career.php). */
    public static function currentClub(?array $player): ?string
    {
        if (!empty($player['current_club_name'])) {
            return (string) $player['current_club_name'];
        }
        foreach ((array) (self::content('career')['clubs'] ?? []) as $club) {
            if (is_array($club) && array_key_exists('to', $club) && $club['to'] === null && !empty($club['name'])) {
                return (string) $club['name'];
            }
        }
        return null;
    }

    /** Stable @id of the Cristiano Ronaldo Person entity (defined in full on /hayati). */
    public static function personId(): string
    {
        return url('/hayati') . '#cristiano-ronaldo';
    }

    /**
     * Compact reference to the same Person entity for `about` / `athlete` properties on other pages.
     *
     * @return array<string, mixed>
     */
    public static function personRef(): array
    {
        return ['@type' => 'Person', '@id' => self::personId(), 'name' => 'Cristiano Ronaldo', 'url' => url('/hayati'), 'sameAs' => self::SAME_AS];
    }

    /**
     * schema.org Person for Cristiano Ronaldo. $rich adds description, address, awards and teams (/hayati).
     *
     * @return array<string, mixed>
     */
    public static function personSchema(?array $player, bool $rich = false): array
    {
        $height = !empty($player['height_cm']) ? (int) $player['height_cm'] : 187;
        $schema = [
            '@type' => 'Person',
            '@id' => self::personId(),
            'name' => 'Cristiano Ronaldo',
            'alternateName' => ['CR7', !empty($player['full_name']) ? (string) $player['full_name'] : 'Cristiano Ronaldo dos Santos Aveiro'],
            'birthDate' => !empty($player['birth_date']) ? substr((string) $player['birth_date'], 0, 10) : '1985-02-05',
            'birthPlace' => ['@type' => 'Place', 'name' => 'Funchal, Madeira, Portekiz'],
            'nationality' => ['@type' => 'Country', 'name' => 'Portekiz'],
            'height' => ['@type' => 'QuantitativeValue', 'value' => $height, 'unitCode' => 'CMT', 'unitText' => 'cm'],
            'gender' => 'https://schema.org/Male',
            'jobTitle' => 'Profesyonel futbolcu',
            'url' => url('/hayati'),
            'sameAs' => self::SAME_AS,
        ];
        $club = self::currentClub($player);
        $teams = [];
        if ($club !== null) {
            $teams[] = ['@type' => 'SportsTeam', 'name' => $club, 'sport' => 'Futbol'];
        }
        $portrait = self::portrait($player);
        if ($portrait !== null) {
            $schema['image'] = url($portrait['url']);
        }
        if ($rich) {
            $lead = trim(Html::toText((string) (self::content('biography')['lead'] ?? '')));
            if ($lead !== '') {
                $schema['description'] = Str::limit($lead, 300);
            }
            $schema['birthPlace']['address'] = [
                '@type' => 'PostalAddress',
                'addressLocality' => 'Funchal',
                'addressRegion' => 'Madeira',
                'addressCountry' => 'PT',
            ];
            $teams[] = ['@type' => 'SportsTeam', 'name' => 'Portekiz Milli Futbol Takımı', 'sport' => 'Futbol'];
            if (!empty($player['position'])) {
                $schema['jobTitle'] = 'Profesyonel futbolcu (' . $player['position'] . ')';
            }
            $awards = [];
            foreach ((array) (self::content('honours')['individual'] ?? []) as $award) {
                if (is_array($award) && !empty($award['title'])) {
                    $years = array_map('strval', (array) ($award['years'] ?? []));
                    $awards[] = $award['title'] . ($years !== [] ? ' (' . implode(', ', $years) . ')' : '');
                }
            }
            if ($awards !== []) {
                $schema['award'] = array_slice($awards, 0, 25);
            }
            $schema['mainEntityOfPage'] = url('/hayati');
        }
        if ($teams !== []) {
            $schema['memberOf'] = count($teams) === 1 ? $teams[0] : $teams;
        }
        return $schema;
    }

    /** Root-relative links in trusted content HTML => app URLs (works for sub-folder installs too). */
    public static function localLinks(string $html): string
    {
        return (string) preg_replace_callback(
            '~href="(/(?!/)[^"]*)"~',
            static fn (array $m): string => 'href="' . e(url(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'))) . '"',
            $html
        );
    }

    /** 'Y-m-d' (content last_reviewed) => 'Y-m-d 12:00:00' UTC-ish datetime for Format, or null. */
    public static function reviewedDate(mixed $date): ?string
    {
        return is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date . ' 09:00:00' : null;
    }
}
