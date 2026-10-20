<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;

/**
 * XML sitemaps and robots.txt.
 * Stub created by the foundation (valid but minimal output); replaced by the site module.
 */
final class SitemapController
{
    private const TYPES = ['pages', 'posts', 'news', 'videos', 'clubs', 'products'];

    public function index(Request $request): Response
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
            . '<sitemap><loc>' . e(url('/sitemap-pages.xml')) . '</loc></sitemap>'
            . '</sitemapindex>' . "\n";
        return Response::xml($xml);
    }

    public function show(Request $request): Response
    {
        if (!in_array($request->param('type'), self::TYPES, true)) {
            return Response::notFound();
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
            . ($request->param('type') === 'pages' ? '<url><loc>' . e(url('/')) . '</loc></url>' : '')
            . '</urlset>' . "\n";
        return Response::xml($xml);
    }

    public function robots(Request $request): Response
    {
        $lines = [
            'User-agent: *',
            'Disallow: /admin',
            'Disallow: /cron',
            'Disallow: /kurulum',
            'Disallow: /arama',
            'Disallow: /siparis/',
            'Disallow: /odeme/',
            '',
            'Sitemap: ' . url('/sitemap.xml'),
        ];
        return Response::text(implode("\n", $lines) . "\n");
    }
}
