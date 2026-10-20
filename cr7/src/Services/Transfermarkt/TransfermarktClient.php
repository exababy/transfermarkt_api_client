<?php

declare(strict_types=1);

namespace App\Services\Transfermarkt;

use App\Core\Config;
use App\Core\Database;
use App\Support\Date;
use App\Support\Http;
use App\Support\Log;

/**
 * Minimal Transfermarkt API client (https://tmapi-alpha.transfermarkt.technology/).
 *
 * Responses look like {"success": true, "message": "OK", "data": …}; get() returns `data`.
 * Every successful raw JSON body is cached in `api_cache` (key = endpoint). A fresh cache
 * entry (younger than $ttl seconds) is served without a request; when the API fails
 * (timeout, 5xx, invalid JSON, success=false) the stale cache entry is returned instead.
 */
final class TransfermarktClient
{
    /** Default cache lifetime for player statistics (seconds). */
    public const DEFAULT_TTL = 1800;

    private const CACHE_KEY_MAX = 191;

    private string $baseUrl;
    private string $language;
    private int $timeout;
    private int $retries;
    /** @var array<string, string> */
    private array $headers;
    /** @var callable(string, array, int): array */
    private $transport;
    /** @var array{api: int, cache: int, stale: int, failed: int} */
    private array $stats = ['api' => 0, 'cache' => 0, 'stale' => 0, 'failed' => 0];

    /**
     * @param array<string, mixed>|null $config defaults to config('transfermarkt')
     * @param callable|null $transport fn(string $url, array $headers, int $timeout): array in the
     *        App\Support\Http::get() result shape (tests inject fixtures here)
     */
    public function __construct(?array $config = null, ?callable $transport = null)
    {
        $config ??= (array) Config::get('transfermarkt', []);
        $this->baseUrl = rtrim((string) ($config['base_url'] ?? 'https://tmapi-alpha.transfermarkt.technology/'), '/') . '/';
        $this->language = (string) ($config['language'] ?? 'tr-TR');
        $this->timeout = max(5, (int) ($config['timeout'] ?? 30));
        $this->retries = max(0, (int) ($config['retries'] ?? 1));
        $headers = [];
        foreach ((array) ($config['headers'] ?? []) as $name => $value) {
            if (is_string($name) && is_scalar($value)) {
                $headers[$name] = (string) $value;
            }
        }
        $this->headers = $headers + [
            'Accept' => 'application/json',
            'Accept-Language' => $this->language,
        ];
        $this->transport = $transport ?? static fn (string $url, array $headers, int $timeout): array => Http::get($url, $headers, $timeout);
    }

    /**
     * Fetch an endpoint ('player/8198/performance-game', 'clubs?ids[]=1&ids[]=2') and return its `data`.
     * $ttl <= 0 forces a request (the cache is still used as a fallback when the request fails).
     *
     * @return array<mixed>|null null when neither the API nor the cache has usable data
     */
    public function get(string $endpoint, int $ttl = self::DEFAULT_TTL): ?array
    {
        $endpoint = ltrim(trim($endpoint), '/');
        $key = self::cacheKey($endpoint);
        $cached = $this->readCache($key);

        if ($cached !== null && $ttl > 0) {
            $age = time() - (int) strtotime($cached['fetched_at'] . ' UTC');
            if ($age >= 0 && $age < $ttl) {
                $data = self::decode($cached['payload']);
                if ($data !== null) {
                    $this->stats['cache']++;
                    return $data;
                }
            }
        }

        [$body, $status, $error] = $this->request($endpoint);
        if ($body !== null) {
            $data = self::decode($body);
            if ($data !== null) {
                $this->writeCache($key, $body, $status);
                $this->stats['api']++;
                return $data;
            }
            $error = 'invalid response (success=false or malformed JSON)';
        }

        if ($cached !== null) {
            $data = self::decode($cached['payload']);
            if ($data !== null) {
                $this->stats['stale']++;
                Log::warning('transfermarkt', "Serving stale cache for {$endpoint}", ['error' => $error, 'status' => $status, 'fetched_at' => $cached['fetched_at']]);
                return $data;
            }
        }

        $this->stats['failed']++;
        Log::error('transfermarkt', "Request failed for {$endpoint}", ['error' => $error, 'status' => $status]);
        return null;
    }

    /** @return array{api: int, cache: int, stale: int, failed: int} counters since construction */
    public function stats(): array
    {
        return $this->stats;
    }

    /** api_cache primary key for an endpoint (long batch URLs are shortened with a hash; VARCHAR(191)). */
    public static function cacheKey(string $endpoint): string
    {
        if (strlen($endpoint) <= self::CACHE_KEY_MAX) {
            return $endpoint;
        }
        return substr($endpoint, 0, self::CACHE_KEY_MAX - 41) . '#' . sha1($endpoint);
    }

    /** `data` of a valid {"success":true,"data":[…]} body, else null. */
    public static function decode(string $body): ?array
    {
        if ($body === '') {
            return null;
        }
        try {
            $json = json_decode($body, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($json) || ($json['success'] ?? false) !== true || !is_array($json['data'] ?? null)) {
            return null;
        }
        return $json['data'];
    }

    /** @return array{0: ?string, 1: int, 2: ?string} [body on HTTP 200, status, error] */
    private function request(string $endpoint): array
    {
        $url = $this->baseUrl . $endpoint;
        $status = 0;
        $error = null;
        for ($attempt = 0; $attempt <= $this->retries; $attempt++) {
            if ($attempt > 0) {
                usleep(400000 * $attempt);
            }
            $started = microtime(true);
            $response = ($this->transport)($url, $this->headers, $this->timeout);
            $status = (int) ($response['status'] ?? 0);
            $error = $response['error'] ?? null;
            $body = (string) ($response['body'] ?? '');
            if ($error === null && $status === 200 && $body !== '') {
                Log::info('transfermarkt', "GET {$endpoint}", ['ms' => (int) round((microtime(true) - $started) * 1000), 'bytes' => strlen($body)]);
                return [$body, $status, null];
            }
            $error ??= 'HTTP ' . $status . ($body !== '' ? ': ' . substr(strip_tags($body), 0, 160) : '');
            // Client errors (except 408/429) will not succeed on retry.
            if ($status >= 400 && $status < 500 && $status !== 408 && $status !== 429) {
                break;
            }
        }
        return [null, $status, $error];
    }

    /** @return array{payload: string, fetched_at: string}|null */
    private function readCache(string $key): ?array
    {
        try {
            $row = Database::fetchOne('SELECT payload, fetched_at FROM api_cache WHERE endpoint = ?', [$key]);
        } catch (\Throwable $e) {
            Log::error('transfermarkt', 'api_cache read failed', ['error' => $e->getMessage()]);
            return null;
        }
        if ($row === null) {
            return null;
        }
        return ['payload' => (string) $row['payload'], 'fetched_at' => (string) $row['fetched_at']];
    }

    private function writeCache(string $key, string $body, int $status): void
    {
        try {
            Database::upsert('api_cache', [
                'endpoint' => $key,
                'payload' => $body,
                'status' => $status,
                'fetched_at' => Date::now(),
            ], ['endpoint']);
        } catch (\Throwable $e) {
            Log::error('transfermarkt', 'api_cache write failed', ['endpoint' => $key, 'error' => $e->getMessage()]);
        }
    }
}
