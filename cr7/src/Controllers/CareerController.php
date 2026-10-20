<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Seo;
use App\Repositories\ClubRepository;
use App\Repositories\StatsRepository;
use App\Support\Format;

/**
 * /kariyer — career overview: youth → senior clubs → national team timeline, transfers,
 * market value chart, national career and year-by-year milestones.
 */
final class CareerController
{
    /** Public profiles of Cristiano Ronaldo used as Person.sameAs. */
    public const SAME_AS = [
        'https://tr.wikipedia.org/wiki/Cristiano_Ronaldo',
        'https://en.wikipedia.org/wiki/Cristiano_Ronaldo',
        'https://www.wikidata.org/wiki/Q11571',
        'https://www.instagram.com/cristiano/',
        'https://x.com/Cristiano',
        'https://www.youtube.com/@cristiano',
        'https://www.transfermarkt.com.tr/cristiano-ronaldo/profil/spieler/8198',
    ];

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

    /** Brief Person schema for Cristiano Ronaldo (shared by the stats pages). */
    public static function personSchema(?array $player = null): array
    {
        $schema = [
            '@type' => 'Person',
            'name' => 'Cristiano Ronaldo',
            'alternateName' => ['CR7', 'Cristiano Ronaldo dos Santos Aveiro'],
            'birthDate' => '1985-02-05',
            'birthPlace' => ['@type' => 'Place', 'name' => 'Funchal, Madeira, Portekiz'],
            'nationality' => ['@type' => 'Country', 'name' => 'Portekiz'],
            'jobTitle' => 'Profesyonel futbolcu',
            'url' => url('/hayati'),
            'sameAs' => self::SAME_AS,
        ];
        if ($player !== null) {
            if (!empty($player['full_name'])) {
                $schema['alternateName'] = ['CR7', (string) $player['full_name']];
            }
            if (!empty($player['height_cm'])) {
                $schema['height'] = ['@type' => 'QuantitativeValue', 'value' => (int) $player['height_cm'], 'unitCode' => 'CMT'];
            }
            if (!empty($player['portrait_url'])) {
                $schema['image'] = (string) $player['portrait_url'];
            }
            if (!empty($player['current_club_name'])) {
                $schema['memberOf'] = ['@type' => 'SportsTeam', 'name' => (string) $player['current_club_name'], 'sport' => 'Soccer'];
            }
        }
        return $schema;
    }
}
