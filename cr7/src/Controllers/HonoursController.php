<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Seo;
use App\Repositories\ClubRepository;
use App\Support\Format;
use App\Support\Str;

/**
 * /kupalar-ve-oduller — team trophies grouped by club, individual awards and records
 * (content/honours.php, rendered as-is).
 */
final class HonoursController
{
    /** Fallback slugs of the club pages (/takimlar/{slug}) by Transfermarkt id. */
    private const CLUB_SLUGS = ['336' => 'sporting-cp', '985' => 'manchester-united', '418' => 'real-madrid', '506' => 'juventus', '18544' => 'al-nassr', '3300' => 'portekiz'];

    public function index(Request $request): Response
    {
        $honours = LifeController::content('honours');
        $summary = self::summary($honours);
        $groups = self::groups($honours);
        $individual = array_values(array_filter((array) ($honours['individual'] ?? []), static fn ($a): bool => is_array($a) && !empty($a['title'])));
        $records = array_values(array_filter((array) ($honours['records'] ?? []), static fn ($r): bool => is_array($r) && !empty($r['title'])));
        $sources = array_values(array_filter((array) ($honours['sources'] ?? []), static fn ($s): bool => is_array($s) && !empty($s['url'])));
        $reviewed = LifeController::reviewedDate($honours['last_reviewed'] ?? null);

        $teamItems = [];
        $position = 0;
        foreach ($groups as $group) {
            foreach ($group['items'] as $item) {
                $teamItems[] = [
                    '@type' => 'ListItem',
                    'position' => ++$position,
                    'name' => $item['title'] . ' – ' . $group['club'] . ' (' . $item['count'] . ' kez: ' . implode(', ', $item['seasons']) . ')',
                    'url' => url('/kupalar-ve-oduller') . '#' . $group['anchor'],
                ];
            }
        }
        $awardItems = [];
        foreach ($individual as $i => $award) {
            $years = array_map('strval', (array) ($award['years'] ?? []));
            $awardItems[] = [
                '@type' => 'ListItem',
                'position' => $i + 1,
                'name' => $award['title'] . ' (' . (int) ($award['count'] ?? count($years)) . ' kez' . ($years !== [] ? ': ' . implode(', ', $years) : '') . ')',
                'url' => url('/kupalar-ve-oduller') . '#bireysel-oduller',
            ];
        }
        $jsonLd = [];
        if ($teamItems !== []) {
            $jsonLd[] = ['@type' => 'ItemList', 'name' => 'Cristiano Ronaldo’nun kazandığı takım kupaları', 'numberOfItems' => count($teamItems), 'itemListElement' => $teamItems];
        }
        if ($awardItems !== []) {
            $jsonLd[] = ['@type' => 'ItemList', 'name' => 'Cristiano Ronaldo’nun bireysel ödülleri', 'numberOfItems' => count($awardItems), 'itemListElement' => $awardItems];
        }
        $person = LifeController::personSchema(LifeController::player());
        $person['award'] = array_map(static fn (array $a): string => (string) $a['title'], array_slice($individual, 0, 25));
        if ($person['award'] === []) {
            unset($person['award']);
        }
        $jsonLd[] = $person;

        $description = $summary['team_total'] > 0
            ? sprintf(
                'Ronaldo kupaları tam liste: %d takım kupası, %d Ballon d’Or, %d Şampiyonlar Ligi ve %d Altın Ayakkabı; CR7’nin tüm bireysel ödülleri, gol krallıkları ve rekorları.',
                $summary['team_total'],
                $summary['ballon_dor'],
                $summary['champions_league'],
                $summary['golden_shoe']
            )
            : 'Cristiano Ronaldo’nun kazandığı tüm kupalar, Ballon d’Or ve Altın Ayakkabı gibi bireysel ödülleri, gol krallıkları ve kırdığı rekorların güncel listesi.';

        $seo = new Seo(
            title: 'Cristiano Ronaldo’nun Kupaları ve Ödülleri: Tam Liste',
            description: Str::limit($description, 160),
            canonical: url('/kupalar-ve-oduller'),
            jsonLd: $jsonLd,
            breadcrumbs: [['name' => 'Kupalar ve Ödüller', 'url' => '/kupalar-ve-oduller']],
            modifiedTime: $reviewed !== null ? Format::iso($reviewed) : null,
        );

        return view('pages/honours', [
            'seo' => $seo,
            'activeNav' => 'kupalar',
            'extraHead' => partial('partials/home-styles', ['page' => 'honours']),
            'summary' => $summary,
            'groups' => $groups,
            'individual' => $individual,
            'records' => $records,
            'sources' => $sources,
            'reviewed' => $reviewed,
        ]);
    }

    /**
     * Headline numbers from content/honours.php.
     *
     * @return array{team_total: int, ballon_dor: int, golden_shoe: int, champions_league: int, individual_total: int, records: int, clubs: int}
     */
    public static function summary(array $honours): array
    {
        $team = array_filter((array) ($honours['team'] ?? []), 'is_array');
        $individual = array_filter((array) ($honours['individual'] ?? []), 'is_array');
        $count = static fn (array $row): int => (int) ($row['count'] ?? count((array) ($row['seasons'] ?? $row['years'] ?? [])));
        $out = ['team_total' => 0, 'ballon_dor' => 0, 'golden_shoe' => 0, 'champions_league' => 0, 'individual_total' => 0, 'records' => count(array_filter((array) ($honours['records'] ?? []), 'is_array')), 'clubs' => 0];
        $clubs = [];
        foreach ($team as $row) {
            $out['team_total'] += $count($row);
            $clubs[(string) ($row['club_id'] ?? $row['club'] ?? '')] = true;
            if (self::key((string) ($row['title'] ?? '')) === 'uefa sampiyonlar ligi') {
                $out['champions_league'] += $count($row);
            }
        }
        $out['clubs'] = count($clubs);
        foreach ($individual as $row) {
            $out['individual_total'] += $count($row);
            $key = self::key((string) ($row['title'] ?? ''));
            if ($key === 'ballon dor') {
                $out['ballon_dor'] += $count($row);
            } elseif ($key === 'avrupa altin ayakkabi') {
                $out['golden_shoe'] += $count($row);
            }
        }
        return $out;
    }

    /**
     * Team trophies grouped by club in content order.
     *
     * @return list<array{club: string, club_id: string, anchor: string, slug: ?string, url: ?string, logo: ?string, total: int, items: list<array{title: string, count: int, seasons: list<string>, note: string}>}>
     */
    public static function groups(array $honours): array
    {
        $groups = [];
        foreach ((array) ($honours['team'] ?? []) as $row) {
            if (!is_array($row) || empty($row['title'])) {
                continue;
            }
            $clubId = (string) ($row['club_id'] ?? '');
            $club = (string) ($row['club'] ?? $clubId);
            $key = $clubId !== '' ? $clubId : $club;
            if (!isset($groups[$key])) {
                $groups[$key] = ['club' => $club, 'club_id' => $clubId, 'anchor' => 'kupalar-' . Str::slug($club), 'slug' => null, 'url' => null, 'logo' => null, 'total' => 0, 'items' => []];
            }
            $seasons = array_map('strval', (array) ($row['seasons'] ?? []));
            $count = (int) ($row['count'] ?? count($seasons));
            $groups[$key]['total'] += $count;
            $groups[$key]['items'][] = ['title' => (string) $row['title'], 'count' => $count, 'seasons' => $seasons, 'note' => (string) ($row['note'] ?? '')];
        }

        $slugs = self::CLUB_SLUGS;
        $logos = [];
        if (class_exists(ClubRepository::class)) {
            $repo = new ClubRepository();
            foreach ((array) LifeController::safely(static fn () => $repo->pages(), []) as $page) {
                if (is_array($page) && !empty($page['club_id']) && !empty($page['slug'])) {
                    $slugs[(string) $page['club_id']] = (string) $page['slug'];
                    if (!empty($page['logo_url'])) {
                        $logos[(string) $page['club_id']] = ClubRepository::logo((string) $page['logo_url'], 'md');
                    }
                }
            }
            $missing = array_diff(array_column($groups, 'club_id'), array_keys($logos));
            foreach ((array) LifeController::safely(static fn () => $repo->many(array_values($missing)), []) as $id => $club) {
                if (!empty($club['logo_url'])) {
                    $logos[(string) $id] = ClubRepository::logo((string) $club['logo_url'], 'md');
                }
            }
        }
        foreach ($groups as &$group) {
            $slug = $slugs[$group['club_id']] ?? null;
            $group['slug'] = $slug;
            $group['url'] = $slug !== null ? '/takimlar/' . $slug : null;
            $group['logo'] = $logos[$group['club_id']] ?? null;
        }
        unset($group);
        return array_values($groups);
    }

    /** Accent/case-folded comparison key ('Ballon d’Or' => 'ballon dor'). */
    private static function key(string $title): string
    {
        return Str::normalize($title);
    }
}
