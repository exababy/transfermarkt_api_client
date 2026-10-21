<?php

declare(strict_types=1);

namespace App\Services\News;

use App\Core\Config;
use App\Core\Settings;
use App\Support\Http;
use App\Support\Log;

/**
 * IndexNow ping (Bing, Yandex, Seznam, Naver …) for new/updated URLs. The key is the admin
 * setting 'indexnow.key' and is served at /indexnow-key.txt (FeedController::indexNowKey).
 */
final class IndexNow
{
    public const ENDPOINT = 'https://api.indexnow.org/indexnow';

    /** Valid key or '' (8–128 chars of [A-Za-z0-9-]). */
    public static function key(): string
    {
        $key = trim((string) Settings::get('indexnow.key', Config::get('indexnow.key', '')));
        return preg_match('/^[A-Za-z0-9-]{8,128}$/', $key) === 1 ? $key : '';
    }

    /**
     * Submit absolute URLs of this site. Never throws; returns true on HTTP 200/202.
     * Skipped (false) without a key or when app.url is a local/development host.
     *
     * @param list<string> $urls
     */
    public static function ping(array $urls): bool
    {
        $key = self::key();
        $host = (string) parse_url((string) Config::get('app.url', ''), PHP_URL_HOST);
        $urls = array_values(array_filter($urls, static fn (string $u): bool => parse_url($u, PHP_URL_HOST) === $host));
        if ($key === '' || $urls === [] || self::isLocalHost($host)) {
            return false;
        }
        $payload = json_encode([
            'host' => $host,
            'key' => $key,
            'keyLocation' => url('/indexnow-key.txt'),
            'urlList' => array_slice($urls, 0, 10000),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $response = Http::post(self::ENDPOINT, (string) $payload, ['Content-Type' => 'application/json; charset=utf-8'], 15);
        $ok = $response['error'] === null && in_array($response['status'], [200, 202], true);
        if ($ok) {
            Log::info('news', 'IndexNow ping ok', ['urls' => count($urls), 'status' => $response['status']]);
        } else {
            Log::error('news', 'IndexNow ping failed', ['status' => $response['status'], 'error' => $response['error'], 'body' => mb_substr($response['body'], 0, 300)]);
        }
        return $ok;
    }

    public static function isLocalHost(string $host): bool
    {
        $host = strtolower($host);
        return $host === '' || $host === 'localhost' || str_starts_with($host, '127.') || $host === '::1' || $host === '[::1]'
            || str_ends_with($host, '.local') || str_ends_with($host, '.test') || str_ends_with($host, '.localhost')
            || filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false && filter_var($host, FILTER_VALIDATE_IP) !== false;
    }
}
