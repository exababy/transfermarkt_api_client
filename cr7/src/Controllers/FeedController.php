<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Seo;

/**
 * RSS feed and IndexNow key file.
 * Stub created by the foundation (empty but valid feed); replaced by the news module.
 */
final class FeedController
{
    public function rss(Request $request): Response
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<rss version="2.0"><channel>'
            . '<title>' . e(Seo::siteName() . ' Haberler') . '</title>'
            . '<link>' . e(url('/haberler')) . '</link>'
            . '<description>Cristiano Ronaldo hakkında en güncel haberler</description>'
            . '<language>tr</language>'
            . '</channel></rss>' . "\n";
        return Response::xml($xml);
    }

    public function indexNowKey(Request $request): Response
    {
        $key = (string) setting('indexnow.key', '');
        if ($key === '' || preg_match('/^[A-Za-z0-9-]{8,128}$/', $key) !== 1) {
            return Response::notFound();
        }
        return Response::text($key);
    }
}
