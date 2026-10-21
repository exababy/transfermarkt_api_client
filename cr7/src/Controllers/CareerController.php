<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Seo;
use App\Repositories\ClubRepository;
use App\Repositories\StatsRepository;
use App\Support\Format;
use App\Support\OgImage;

/**
 * /kariyer — career overview: youth → senior clubs → national team timeline, transfers,
 * market value chart, national career and year-by-year milestones.
 */
final class CareerController
{
    /** Public profiles of Cristiano Ronaldo used as Person.sameAs (one list site-wide). */
    public const SAME_AS = LifeController::SAME_AS;

    public function index(Request $request): Response
    {
        $stats = new StatsRepository();
        $clubs = new ClubRepository();
        $content = ClubRepository::content();
        $spells = $stats->careerClubs();
        $pages = $clubs->pages();
        $player = $stats->player();

        $itemList = [];
        foreach (array_values($pages) as $i => $page) {
            $itemList[] = ['@type' => 'ListItem', 'position' => $i + 1, 'url' => url($page['url']), 'name' => $page['name']];
        }

        $card = OgImage::page('kariyer', 'Cristiano Ronaldo’nun Kariyeri', 'Kulüpler ve transferler', 'KARİYER');
        $seo = new Seo(
            title: 'Cristiano Ronaldo Kariyeri: Kulüpler, Transferler, Goller',
            description: 'Cristiano Ronaldo’nun Sporting CP’den Al-Nassr’a uzanan kariyeri: kulüp kulüp maç, gol ve asistleri, transfer bedelleri, piyasa değeri ve milli takım yolculuğu.',
            type: 'profile',
            jsonLd: [
                self::personSchema($player),
                ['@type' => 'ItemList', 'name' => 'Cristiano Ronaldo’nun oynadığı takımlar', 'itemListOrder' => 'https://schema.org/ItemListOrderAscending', 'numberOfItems' => count($itemList), 'itemListElement' => $itemList],
            ],
            breadcrumbs: [['name' => 'Kariyer']],
            modifiedTime: Format::iso($stats->lastSyncedAt()) ?: null,
            image: $card,
            extraMeta: OgImage::meta($card),
        );

        return view('pages/career', [
            'seo' => $seo,
            'activeNav' => 'kariyer',
            'bodyClass' => 'page-stats',
            'narrative' => $content,
            'spells' => $spells,
            'pages' => $pages,
            'player' => $player,
            'career' => $stats->careerTotals(),
            'clubTotals' => $stats->clubTotals(),
            'national' => $stats->nationalTotals(),
            'nationalCareer' => $stats->nationalCareer(),
            'transfers' => $stats->transfers(),
            'marketValues' => $stats->marketValues(),
            'clubNames' => array_map(static fn (array $c): string => (string) $c['name'], $clubs->many(array_column($stats->marketValues(), 'club_id'))),
            'lastSync' => $stats->lastSyncedAt(),
        ]);
    }

    /** Person schema for Cristiano Ronaldo: the same entity (@id, sameAs) as /hayati and the home page. */
    public static function personSchema(?array $player = null): array
    {
        return LifeController::personSchema($player);
    }
}
