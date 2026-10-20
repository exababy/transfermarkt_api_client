<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Seo;
use App\Repositories\StatsRepository;
use App\Services\Transfermarkt\TransfermarktSync;
use App\Support\Format;

/**
 * /istatistikler (overview, tabs, charts) and /istatistikler/maclar (filterable match log).
 */
final class StatsController
{
    public const PER_PAGE = 50;

    public function index(Request $request): Response
    {
        $stats = new StatsRepository();
        $clubStats = $stats->clubStats();

        // ?kulup={slug} filters the "Sezonlara göre" table (canonical stays /istatistikler).
        $clubFilter = null;
        $slug = $request->query('kulup');
        if (is_string($slug) && $slug !== '') {
            foreach ($clubStats as $row) {
                if ($row['slug'] === $slug) {
                    $clubFilter = $row;
                    break;
                }
            }
            if ($clubFilter === null) {
                return Response::redirect(url('/istatistikler') . '#tab-sezonlar', 301);
            }
        }

        $career = $stats->summary('career');
        $lastSync = $stats->lastSyncedAt();
        $description = sprintf(
            'Cristiano Ronaldo istatistikleri: %s maçta %s gol ve %s asist. Kulüp, turnuva ve sezon bazında tablolar, yıllara göre goller, piyasa değeri ve milli takım.',
            Format::number($career['appearances']),
            Format::number($career['goals']),
            Format::number($career['assists'])
        );
        $seo = new Seo(
            title: 'Cristiano Ronaldo İstatistikleri: Gol, Asist, Maç Sayıları',
            description: $description,
            jsonLd: [
                [
                    '@type' => 'WebPage',
                    'name' => 'Cristiano Ronaldo İstatistikleri',
                    'url' => url('/istatistikler'),
                    'inLanguage' => 'tr-TR',
                    'about' => ['@type' => 'Person', 'name' => 'Cristiano Ronaldo', 'sameAs' => CareerController::SAME_AS],
                ] + ($lastSync !== null ? ['dateModified' => Format::iso($lastSync)] : []),
                [
                    '@type' => 'Dataset',
                    'name' => 'Cristiano Ronaldo kariyer istatistikleri',
                    'description' => 'Cristiano Ronaldo’nun kulüp ve milli takım kariyerine ait maç, gol, asist, dakika, kart, piyasa değeri, transfer ve sakatlık verileri (sezon, kulüp ve turnuva bazında).',
                    'url' => url('/istatistikler'),
                    'inLanguage' => 'tr-TR',
                    'keywords' => ['Cristiano Ronaldo', 'CR7', 'Ronaldo istatistikleri', 'gol', 'asist', 'futbol'],
                    'isBasedOn' => 'https://www.transfermarkt.com.tr/cristiano-ronaldo/leistungsdatendetails/spieler/8198',
                    'creator' => ['@type' => 'Organization', 'name' => 'CR7.com.tr', 'url' => url('/')],
                    'temporalCoverage' => '2001/..',
                ] + ($lastSync !== null ? ['dateModified' => Format::iso($lastSync)] : []),
            ],
            breadcrumbs: [['name' => 'İstatistikler']],
        );

        return view('pages/stats', [
            'seo' => $seo,
            'activeNav' => 'istatistikler',
            'bodyClass' => 'page-stats',
            'career' => $career,
            'clubTotals' => $stats->summary('clubs'),
            'national' => $stats->nationalTotals(),
            'gameTotals' => $stats->gameTotals(),
            'clubStats' => $clubStats,
            'competitionStats' => $stats->competitionStats(),
            'seasonStats' => $stats->seasonStats($clubFilter['club_id'] ?? null),
            'clubFilter' => $clubFilter,
            'years' => $stats->goalsByYear(),
            'marketValues' => $stats->marketValues(),
            'nationalCareer' => $stats->nationalCareer(),
            'nationalCompetitions' => $stats->clubCompetitions(TransfermarktSync::NATIONAL_TEAM_ID),
            'injuries' => $stats->injuries(),
            'opponents' => $stats->topOpponents(15),
            'latest' => $stats->latestGames(10),
            'player' => $stats->player(),
            'lastSync' => $lastSync,
        ]);
    }

    public function matches(Request $request): Response
    {
        $stats = new StatsRepository();
        $seasons = $stats->seasonOptions();
        $competitions = $stats->competitionOptions();

        [$selected, $valid] = self::parseFilters($request, $seasons, $competitions);
        $base = '/istatistikler/maclar';
        if (!$valid) {
            return Response::redirect(url($base . self::query($selected)), 301);
        }

        $page = $selected['sayfa'] ?? 1;
        $filters = ['played' => ($selected['durum'] ?? null) !== 'tumu'];
        if (isset($selected['sezon'])) {
            $filters['season_id'] = $selected['sezon'];
        }
        if (isset($selected['turnuva'])) {
            $filters['competition_id'] = $selected['turnuva'];
        }
        if (isset($selected['sonuc'])) {
            $filters['result'] = StatsRepository::RESULT_SLUGS[$selected['sonuc']];
        }
        $total = $stats->countGames($filters);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        if ($page > $pages) {
            return Response::notFound();
        }
        $games = $stats->games($filters, self::PER_PAGE, ($page - 1) * self::PER_PAGE);
        $totals = $stats->gameTotals($filters);

        $listSelected = $selected;
        unset($listSelected['sayfa']);
        $listUrl = $base . self::query($listSelected);
        $canonical = $base . self::query($selected);
        $onlySeason = !isset($selected['turnuva']) && !isset($selected['sonuc']) && !isset($selected['durum']);
        $indexable = $total > 0 && $onlySeason && (isset($selected['sezon']) || $page === 1);

        $seasonLabel = isset($selected['sezon']) ? $seasons[$selected['sezon']]['label'] : null;
        $competitionName = isset($selected['turnuva']) ? $competitions[$selected['turnuva']]['name'] : null;
        $title = 'Cristiano Ronaldo’nun Tüm Maçları';
        if ($seasonLabel !== null && $competitionName !== null) {
            $title = "Ronaldo {$seasonLabel} {$competitionName} Maçları";
        } elseif ($seasonLabel !== null) {
            $title = "Cristiano Ronaldo {$seasonLabel} Sezonu Maçları";
        } elseif ($competitionName !== null) {
            $title = "Cristiano Ronaldo {$competitionName} Maçları";
        }
        if (mb_strlen($title) > 50) {
            $title = $seasonLabel !== null ? "Cristiano Ronaldo {$seasonLabel} Sezonu Maçları" : 'Cristiano Ronaldo’nun Maçları';
        }
        if ($page > 1) {
            $title .= ' – Sayfa ' . $page;
        }
        $description = sprintf(
            'Cristiano Ronaldo%s maç maç performansı: %s maç, %s gol, %s asist. Tarih, rakip, skor, dakika ve gol bilgileriyle eksiksiz maç listesi.',
            $seasonLabel !== null ? '’nun ' . $seasonLabel . ' sezonu' : '’nun kariyeri boyunca',
            Format::number($totals['appearances']),
            Format::number($totals['goals']),
            Format::number($totals['assists'])
        );

        $breadcrumbs = [['name' => 'İstatistikler', 'url' => '/istatistikler'], ['name' => 'Maçlar', 'url' => $base]];
        if ($seasonLabel !== null) {
            $breadcrumbs[] = ['name' => $seasonLabel . ' sezonu', 'url' => $base . self::query(['sezon' => $selected['sezon']])];
        }
        if (count($breadcrumbs) === 2) {
            $breadcrumbs[1] = ['name' => 'Maçlar'];
        }

        $seo = new Seo(
            title: $title,
            description: $description,
            canonical: url($canonical),
            robots: $indexable ? 'index,follow,max-image-preview:large' : 'noindex,follow',
            jsonLd: [[
                '@type' => 'WebPage',
                'name' => $title,
                'url' => url($canonical),
                'inLanguage' => 'tr-TR',
                'about' => ['@type' => 'Person', 'name' => 'Cristiano Ronaldo', 'sameAs' => CareerController::SAME_AS],
            ]],
            breadcrumbs: $breadcrumbs,
            prev: $page > 1 ? page_url($listUrl, $page - 1) : null,
            next: $page < $pages ? page_url($listUrl, $page + 1) : null,
        );

        return view('pages/matches', [
            'seo' => $seo,
            'activeNav' => 'istatistikler',
            'bodyClass' => 'page-stats',
            'games' => $games,
            'totals' => $totals,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'listUrl' => $listUrl,
            'selected' => $selected,
            'seasons' => $seasons,
            'competitions' => $competitions,
            'seasonLabel' => $seasonLabel,
            'competitionName' => $competitionName,
            'heading' => $seasonLabel !== null ? "Cristiano Ronaldo {$seasonLabel} Sezonu Maçları" : 'Cristiano Ronaldo’nun Tüm Maçları',
            'lastSync' => $stats->lastSyncedAt(),
        ]);
    }

    /**
     * Validate ?sezon= &turnuva= &sonuc= &durum= &sayfa=.
     *
     * @return array{0: array<string, int|string>, 1: bool} [normalized filters, false => redirect to the normalized URL]
     */
    public static function parseFilters(Request $request, array $seasons, array $competitions): array
    {
        $selected = [];
        $valid = true;
        $raw = static fn (string $key): ?string => is_string($v = $request->query($key)) ? trim($v) : ($request->query($key) === null ? null : '');

        $season = $raw('sezon');
        if ($season !== null) {
            if (preg_match('/^\d{4}$/', $season) === 1 && isset($seasons[(int) $season])) {
                $selected['sezon'] = (int) $season;
            } else {
                $valid = false;
            }
        }
        $competition = $raw('turnuva');
        if ($competition !== null) {
            if (preg_match('/^[A-Za-z0-9]{1,20}$/', $competition) === 1 && isset($competitions[strtoupper($competition)])) {
                $selected['turnuva'] = strtoupper($competition);
                $valid = $valid && $competition === $selected['turnuva'];
            } else {
                $valid = false;
            }
        }
        $result = $raw('sonuc');
        if ($result !== null) {
            if (isset(StatsRepository::RESULT_SLUGS[$result])) {
                $selected['sonuc'] = $result;
            } else {
                $valid = false;
            }
        }
        $scope = $raw('durum');
        if ($scope !== null) {
            if ($scope === 'tumu') {
                $selected['durum'] = 'tumu';
            } else {
                $valid = false;
            }
        }
        $page = $raw('sayfa');
        if ($page !== null) {
            if (preg_match('/^[1-9]\d{0,3}$/', $page) === 1 && (int) $page > 1) {
                $selected['sayfa'] = (int) $page;
            } else {
                $valid = false;
            }
        }
        return [$selected, $valid];
    }

    /** Normalized query string in a fixed order ('' when empty). */
    public static function query(array $selected): string
    {
        $ordered = [];
        foreach (['sezon', 'turnuva', 'sonuc', 'durum', 'sayfa'] as $key) {
            if (isset($selected[$key])) {
                $ordered[$key] = $selected[$key];
            }
        }
        return $ordered === [] ? '' : '?' . http_build_query($ordered, '', '&', PHP_QUERY_RFC3986);
    }
}
