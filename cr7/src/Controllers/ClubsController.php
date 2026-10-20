<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Seo;
use App\Repositories\ClubRepository;
use App\Repositories\StatsRepository;
use App\Services\Transfermarkt\TransfermarktSync;
use App\Support\Format;
use App\Support\Str;

/**
 * /takimlar (club cards) and /takimlar/{slug} (one career club or Portekiz).
 */
final class ClubsController
{
    public function index(Request $request): Response
    {
        $stats = new StatsRepository();
        $repo = new ClubRepository();
        $cards = $this->cards($stats, $repo->pages());

        $itemList = [];
        foreach (array_values($cards) as $i => $card) {
            $itemList[] = ['@type' => 'ListItem', 'position' => $i + 1, 'url' => url($card['url']), 'name' => $card['name']];
        }
        $youth = array_values(array_filter($stats->nationalCareer(), static fn (array $r): bool => $r['club_id'] !== TransfermarktSync::NATIONAL_TEAM_ID));

        $seo = new Seo(
            title: 'Cristiano Ronaldo’nun Oynadığı Takımlar ve İstatistikleri',
            description: 'Cristiano Ronaldo’nun forma giydiği Sporting CP, Manchester United, Real Madrid, Juventus, Al-Nassr ve Portekiz’deki maç, gol ve asist sayıları.',
            jsonLd: [[
                '@type' => 'ItemList',
                'name' => 'Cristiano Ronaldo’nun oynadığı takımlar',
                'itemListOrder' => 'https://schema.org/ItemListOrderAscending',
                'numberOfItems' => count($itemList),
                'itemListElement' => $itemList,
            ]],
            breadcrumbs: [['name' => 'Oynadığı Takımlar']],
        );
        return view('pages/clubs', [
            'seo' => $seo,
            'activeNav' => 'takimlar',
            'bodyClass' => 'page-stats',
            'cards' => $cards,
            'youth' => $youth,
            'lastSync' => $stats->lastSyncedAt(),
        ]);
    }

    public function show(Request $request): Response
    {
        $slug = (string) $request->param('slug', '');
        $repo = new ClubRepository();
        $page = $repo->page($slug);
        if ($page === null) {
            return Response::notFound();
        }
        $stats = new StatsRepository($repo);
        $clubId = $page['club_id'];
        $club = $repo->find($clubId);
        $filters = ['club_id' => $clubId];
        $spells = array_values(array_filter($stats->careerClubs(), static fn (array $s): bool => $s['club_id'] === $clubId));
        $totals = $stats->gameTotals($filters);
        $seasons = array_reverse($stats->seasonStats($clubId));
        $period = self::period($page, $spells);
        $name = $page['name'];
        $logo = ClubRepository::logo($page['logo_url'], 'md');

        $transfers = array_values(array_filter(
            $stats->transfers(),
            static fn (array $t): bool => ($t['to_club_id'] === $clubId || $t['from_club_id'] === $clubId) && $t['transfer_type'] !== 'INTERNAL_TRANSFER'
        ));

        $all = array_values($repo->pages());
        $index = array_search($slug, array_column($all, 'slug'), true);
        $prev = $index !== false && $index > 0 ? $all[$index - 1] : null;
        $next = $index !== false && $index < count($all) - 1 ? $all[$index + 1] : null;

        $seo = new Seo(
            title: self::title($name),
            description: self::description($name, $period, $totals),
            jsonLd: [self::teamSchema($page, $club, $logo)],
            breadcrumbs: [['name' => 'Oynadığı Takımlar', 'url' => '/takimlar'], ['name' => $name]],
        );

        return view('pages/club', [
            'seo' => $seo,
            'activeNav' => 'takimlar',
            'bodyClass' => 'page-stats',
            'page' => $page,
            'club' => $club,
            'logo' => $logo,
            'period' => $period,
            'spells' => $spells,
            'totals' => $totals,
            'seasons' => $seasons,
            'competitions' => $stats->clubCompetitions($clubId),
            'opponents' => $stats->topOpponents(10, $filters),
            'wins' => $stats->biggestWins(8, $filters),
            'hatTricks' => $stats->hatTricks($filters),
            'latest' => ($page['is_national'] || $clubId === (string) ($stats->player()['current_club_id'] ?? '')) ? $stats->games(['played' => true] + $filters, 5) : [],
            'transfers' => $transfers,
            'nationalCareer' => $page['is_national'] ? $stats->nationalCareer() : [],
            'prev' => $prev,
            'next' => $next,
            'lastSync' => $stats->lastSyncedAt(),
        ]);
    }

    /**
     * One card per club page: games-based totals (Man Utd spells summed), periods, summary.
     *
     * @param array<string, array<string, mixed>> $pages
     * @return list<array<string, mixed>>
     */
    private function cards(StatsRepository $stats, array $pages): array
    {
        $spells = $stats->careerClubs();
        $cards = [];
        foreach ($pages as $page) {
            $mine = array_values(array_filter($spells, static fn (array $s): bool => $s['club_id'] === $page['club_id']));
            $totals = $stats->gameTotals(['club_id' => $page['club_id']]);
            $first = reset($page['spells']) ?: [];
            $cards[] = [
                'slug' => $page['slug'],
                'club_id' => $page['club_id'],
                'name' => $page['name'],
                'url' => $page['url'],
                'logo_url' => ClubRepository::logo($page['logo_url'], 'md'),
                'color' => $page['color1'],
                'country' => $page['country'],
                'is_national' => $page['is_national'],
                'period' => self::period($page, $mine),
                'summary' => (string) ($first['summary'] ?? ''),
                'honours' => self::trophyCount($page['spells']),
            ] + $totals;
        }
        return $cards;
    }

    /** Number of team trophies in the content honours lines ("Premier Lig (3): …" => 3). */
    public static function trophyCount(array $spells): int
    {
        $count = 0;
        foreach ($spells as $spell) {
            foreach ((array) ($spell['honours'] ?? []) as $line) {
                $count += preg_match('/\((\d+)\)/u', (string) $line, $m) === 1 ? (int) $m[1] : 1;
            }
        }
        return $count;
    }

    /** "2003–2009, 2021–2022" from the content spells (fallback: data spells). */
    private static function period(array $page, array $spells): string
    {
        $periods = [];
        foreach ($page['spells'] as $entry) {
            if (!empty($entry['period'])) {
                $periods[] = (string) $entry['period'];
            }
        }
        if ($periods === []) {
            $periods = array_values(array_filter(array_map(static fn (array $s): string => (string) $s['period'], $spells)));
        }
        return implode(', ', $periods);
    }

    /** Title ≤ 60 characters (before the site suffix). */
    private static function title(string $name): string
    {
        foreach ([
            "Cristiano Ronaldo {$name} Kariyeri ve İstatistikleri",
            "Cristiano Ronaldo {$name}: Goller ve İstatistikler",
            "Ronaldo {$name}: Goller ve İstatistikler",
            "Ronaldo {$name} İstatistikleri",
        ] as $candidate) {
            if (mb_strlen($candidate) <= 60) {
                return $candidate;
            }
        }
        return Str::limit("Ronaldo {$name}", 60);
    }

    private static function description(string $name, string $period, array $totals): string
    {
        $text = sprintf(
            'Cristiano Ronaldo’nun %s kariyeri%s: %s maçta %s gol, %s asist; sezon sezon istatistikler, en çok gol attığı rakipler, hat-trickler ve kupalar.',
            $name,
            $period !== '' ? ' (' . $period . ')' : '',
            Format::number($totals['appearances']),
            Format::number($totals['goals']),
            Format::number($totals['assists'])
        );
        if ($totals['appearances'] === 0) {
            $text = sprintf('Cristiano Ronaldo’nun %s kariyeri%s: maç, gol ve asist istatistikleri, en çok gol attığı rakipler, hat-trickler, kupalar ve unutulmaz anlar.', $name, $period !== '' ? ' (' . $period . ')' : '');
        }
        if (mb_strlen($text) > 160) {
            $text = str_replace(', en çok gol attığı rakipler', '', $text);
        }
        return mb_strlen($text) > 160 ? Str::limit($text, 160) : $text;
    }

    private static function teamSchema(array $page, ?array $club, ?string $logo): array
    {
        $schema = [
            '@type' => 'SportsTeam',
            'name' => $page['is_national'] ? 'Portekiz Milli Futbol Takımı' : (string) ($club['name'] ?? $page['name']),
            'sport' => 'Soccer',
            'url' => url($page['url']),
            'athlete' => ['@type' => 'Person', 'name' => 'Cristiano Ronaldo', 'url' => url('/hayati')],
        ];
        if (!$page['is_national'] && !empty($club['name']) && $club['name'] !== $page['name']) {
            $schema['alternateName'] = $page['name'];
        }
        if ($logo !== null) {
            $schema['logo'] = $logo;
        }
        if ($page['country'] !== '') {
            $schema['location'] = ['@type' => 'Country', 'name' => $page['country']];
        }
        return $schema;
    }
}
