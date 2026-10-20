<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Seo;
use App\Repositories\NewsRepository;
use App\Services\News\IndexNow;
use App\Services\News\Tagger;

/**
 * RSS 2.0 feed (/rss.xml) and the IndexNow key file (/indexnow-key.txt).
 */
final class FeedController
{
    public const FEED_SIZE = 30;

    public function rss(Request $request): Response
    {
        $posts = [];
        try {
            $posts = (new NewsRepository())->forFeed(self::FEED_SIZE);
        } catch (\Throwable) {
            // not installed yet: an empty but valid feed
        }
        $self = url('/rss.xml');
        $lastBuild = $posts !== [] ? (string) ($posts[0]['updated_at'] ?: $posts[0]['published_at']) : gmdate('Y-m-d H:i:s');

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:media="http://search.yahoo.com/mrss/" xmlns:dc="http://purl.org/dc/elements/1.1/">' . "\n"
            . "<channel>\n"
            . '  <title>' . self::x(Seo::siteName() . ' – Cristiano Ronaldo Haberleri') . "</title>\n"
            . '  <link>' . self::x(url('/haberler')) . "</link>\n"
            . '  <atom:link href="' . self::x($self) . '" rel="self" type="application/rss+xml"/>' . "\n"
            . '  <description>' . self::x('Cristiano Ronaldo hakkında en güncel Türkçe haberler: maçlar, goller, transferler ve açıklamalar.') . "</description>\n"
            . "  <language>tr-TR</language>\n"
            . '  <copyright>' . self::x('© ' . gmdate('Y') . ' ' . Seo::siteName()) . "</copyright>\n"
            . '  <lastBuildDate>' . self::rfc822($lastBuild) . "</lastBuildDate>\n"
            . "  <ttl>30</ttl>\n"
            . "  <image>\n"
            . '    <url>' . self::x(url('/assets/img/icon-192.png')) . "</url>\n"
            . '    <title>' . self::x(Seo::siteName() . ' – Cristiano Ronaldo Haberleri') . "</title>\n"
            . '    <link>' . self::x(url('/haberler')) . "</link>\n"
            . "  </image>\n";

        foreach ($posts as $post) {
            $link = url('/haber/' . $post['slug']);
            $xml .= "  <item>\n"
                . '    <title>' . self::x((string) $post['title']) . "</title>\n"
                . '    <link>' . self::x($link) . "</link>\n"
                . '    <guid isPermaLink="true">' . self::x($link) . "</guid>\n"
                . '    <pubDate>' . self::rfc822((string) $post['published_at']) . "</pubDate>\n"
                . '    <dc:creator>' . self::x(Seo::siteName()) . "</dc:creator>\n"
                . '    <description>' . self::x((string) $post['summary']) . "</description>\n";
            foreach (Tagger::fromColumn($post['tags'] ?? '') as $tag) {
                $xml .= '    <category>' . self::x($tag['name']) . "</category>\n";
            }
            if (!empty($post['image_url'])) {
                $xml .= '    <media:content url="' . self::x((string) $post['image_url']) . '" medium="image" type="image/png" width="1200" height="630"/>' . "\n";
                $file = \App\Core\App::basePath('public' . (string) $post['image_path']);
                $length = is_file($file) ? (int) filesize($file) : 0;
                $xml .= '    <enclosure url="' . self::x((string) $post['image_url']) . '" length="' . $length . '" type="image/png"/>' . "\n";
            }
            $xml .= "  </item>\n";
        }
        $xml .= "</channel>\n</rss>\n";

        return new Response($xml, 200, [
            'Content-Type' => 'application/rss+xml; charset=utf-8',
            'Cache-Control' => 'public, max-age=900',
        ]);
    }

    public function indexNowKey(Request $request): Response
    {
        $key = IndexNow::key();
        if ($key === '') {
            return Response::notFound();
        }
        return Response::text($key);
    }

    /** XML text/attribute escaping (also strips characters that are illegal in XML 1.0). */
    public static function x(string $value): string
    {
        $value = (string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value);
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** UTC 'Y-m-d H:i:s' => RFC 822 date for RSS. */
    private static function rfc822(string $utc): string
    {
        $ts = strtotime($utc . ' UTC');
        return gmdate('D, d M Y H:i:s', $ts === false ? time() : $ts) . ' +0000';
    }
}
