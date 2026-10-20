<?php
/**
 * Default news sources for the automatic news pipeline (App\Services\News\SourceRegistry).
 *
 * key => [
 *   'name'   => display name used when the feed item does not carry its own publisher,
 *   'type'   => 'rss' | 'atom' | 'bing' | 'google'   (rss/atom are parsed by the same FeedParser),
 *   'url'    => feed URL,
 *   'filter' => true for general sports feeds: only items whose title/description mention
 *               Cristiano Ronaldo are kept (the relevance filter runs for every source anyway,
 *               search feeds simply already match most of the time),
 *   'text'   => false when the item links cannot be fetched for article text (Google News
 *               redirect URLs): such items are only used as clustering / title evidence,
 * ]
 *
 * Extra or overriding sources can be added in config.php under 'news.sources' (same shape);
 * single sources are switched off from the admin panel (setting 'news.sources_disabled').
 */

declare(strict_types=1);

return [
    'bing' => [
        'name' => 'Bing Haberler',
        'type' => 'bing',
        'url' => 'https://www.bing.com/news/search?q=Cristiano+Ronaldo&format=rss&setlang=tr&cc=TR',
        'filter' => false,
    ],
    'bing-haber' => [
        'name' => 'Bing Haberler',
        'type' => 'bing',
        'url' => 'https://www.bing.com/news/search?q=Cristiano+Ronaldo+haber&format=rss&setlang=tr&cc=TR',
        'filter' => false,
    ],
    'bing-futbol' => [
        'name' => 'Bing Haberler',
        'type' => 'bing',
        'url' => 'https://www.bing.com/news/search?q=Ronaldo+futbol&format=rss&setlang=tr&cc=TR',
        'filter' => false,
    ],
    'google' => [
        'name' => 'Google Haberler',
        'type' => 'google',
        'url' => 'https://news.google.com/rss/search?q=Cristiano+Ronaldo&hl=tr&gl=TR&ceid=TR:tr',
        'filter' => false,
        'text' => false,
    ],
    'trthaber' => [
        'name' => 'TRT Haber',
        'type' => 'rss',
        'url' => 'https://www.trthaber.com/spor_articles.rss',
        'filter' => true,
    ],
    'haberturk' => [
        'name' => 'Habertürk',
        'type' => 'rss',
        'url' => 'https://www.haberturk.com/rss/spor.xml',
        'filter' => true,
    ],
    'cnnturk' => [
        'name' => 'CNN Türk',
        'type' => 'rss',
        'url' => 'https://www.cnnturk.com/feed/rss/spor/news',
        'filter' => true,
    ],
    'sozcu' => [
        'name' => 'Sözcü',
        'type' => 'rss',
        'url' => 'https://www.sozcu.com.tr/feeds-rss-category-spor',
        'filter' => true,
    ],
    'ajansspor' => [
        'name' => 'Ajansspor',
        'type' => 'rss',
        'url' => 'https://www.ajansspor.com/rss',
        'filter' => true,
    ],
    'sporx' => [
        'name' => 'Sporx',
        'type' => 'rss',
        'url' => 'https://www.sporx.com/_xml/rss.php',   // windows-1254 / ISO-8859-9 encoded
        'filter' => true,
    ],
    'aspor' => [
        'name' => 'A Spor',
        'type' => 'rss',
        'url' => 'https://www.aspor.com.tr/rss/anasayfa.xml',
        'filter' => true,
    ],
    'star' => [
        'name' => 'Star',
        'type' => 'rss',
        'url' => 'https://www.star.com.tr/rss/spor.xml',
        'filter' => true,
    ],
    'takvim' => [
        'name' => 'Takvim',
        'type' => 'rss',
        'url' => 'https://www.takvim.com.tr/rss/spor.xml',
        'filter' => true,
    ],
    'ntvspor' => [
        'name' => 'NTV Spor',
        'type' => 'atom',
        'url' => 'https://www.ntvspor.net/rss/kategori/futbol',
        'filter' => true,
    ],
];
