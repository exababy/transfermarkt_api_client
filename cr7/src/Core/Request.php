<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Immutable-ish HTTP request (query, POST body, server vars, route params).
 */
final class Request
{
    /**
     * Default app.trusted_proxies: Cloudflare's edge ranges (https://www.cloudflare.com/ips-v4 and /ips-v6,
     * checked 2026-10). Proxy headers are honoured only when REMOTE_ADDR is in the trusted list.
     */
    public const CLOUDFLARE_RANGES = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18', '108.162.192.0/18',
        '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
    ];

    /** @var array<string, mixed> */
    private array $params = [];
    private ?string $rawPath = null;

    public function __construct(
        private string $method,
        private string $uri,
        private array $queryParams = [],
        private array $post = [],
        private array $server = [],
        private array $cookies = [],
    ) {
        $this->method = strtoupper($method);
    }

    /** Build from PHP superglobals. */
    public static function capture(): self
    {
        return new self(
            (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            (string) ($_SERVER['REQUEST_URI'] ?? '/'),
            $_GET,
            $_POST,
            $_SERVER,
            $_COOKIE
        );
    }

    /** Build a synthetic request (tests, internal calls). Query string is parsed from $uri. */
    public static function create(string $method, string $uri, array $post = [], array $server = []): self
    {
        $query = [];
        $qs = parse_url('http://x' . $uri, PHP_URL_QUERY);
        if (is_string($qs)) {
            parse_str($qs, $query);
        }
        return new self($method, $uri, $query, $post, $server + ['REQUEST_METHOD' => strtoupper($method), 'REQUEST_URI' => $uri, 'REMOTE_ADDR' => '127.0.0.1']);
    }

    /** GET / POST / HEAD ... */
    public function method(): string
    {
        return $this->method;
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    /** Decoded path as received (app URL prefix removed), may contain a trailing slash. */
    public function rawPath(): string
    {
        if ($this->rawPath !== null) {
            return $this->rawPath;
        }
        $uri = preg_replace('#^https?://[^/?]+#i', '', $this->uri) ?? $this->uri; // absolute-form request target
        $path = rawurldecode(explode('?', $uri, 2)[0]);
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . $path;
        }
        $prefix = App::urlPrefix();
        if ($prefix !== '' && ($path === $prefix || str_starts_with($path, $prefix . '/'))) {
            $path = substr($path, strlen($prefix));
            $path = $path === '' ? '/' : $path;
        }
        return $this->rawPath = $path;
    }

    /** Normalised path: duplicate slashes collapsed, no trailing slash except '/'. */
    public function path(): string
    {
        $path = (string) preg_replace('#/{2,}#', '/', $this->rawPath());
        return $path === '/' ? '/' : (rtrim($path, '/') ?: '/');
    }

    /** Raw query string (without '?'). */
    public function queryString(): string
    {
        return explode('?', $this->uri, 2)[1] ?? '';
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->queryParams[$key] ?? $default;
    }

    /** POST body value. */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $default;
    }

    /** @return array<string, mixed> POST body */
    public function all(): array
    {
        return $this->post;
    }

    /** Raw request body (webhooks/JSON callbacks). */
    public function rawBody(): string
    {
        return (string) file_get_contents('php://input');
    }

    /**
     * Client IP: REMOTE_ADDR, unless app.trust_proxy is true AND REMOTE_ADDR is a trusted proxy
     * (app.trusted_proxies, default Cloudflare). Then X-Forwarded-For is read from the right and the first hop
     * that is not a trusted proxy wins (left-most entries are client-supplied); CF-Connecting-IP is the fallback.
     */
    public function ip(): string
    {
        $remote = (string) ($this->server['REMOTE_ADDR'] ?? '');
        $remote = filter_var($remote, FILTER_VALIDATE_IP) !== false ? $remote : '0.0.0.0';
        if (!$this->fromTrustedProxy()) {
            return $remote;
        }
        $forwarded = $this->header('X-Forwarded-For');
        if ($forwarded !== null) {
            foreach (array_reverse(explode(',', $forwarded)) as $hop) {
                $hop = trim($hop);
                if (filter_var($hop, FILTER_VALIDATE_IP) === false) {
                    break; // malformed chain: trust nothing further left
                }
                if (!self::isTrustedProxy($hop)) {
                    return $hop;
                }
            }
        }
        $connecting = trim((string) $this->header('CF-Connecting-IP'));
        return filter_var($connecting, FILTER_VALIDATE_IP) !== false ? $connecting : $remote;
    }

    public function isHttps(): bool
    {
        $https = strtolower((string) ($this->server['HTTPS'] ?? ''));
        if ($https !== '' && $https !== 'off') {
            return true;
        }
        if ((string) ($this->server['SERVER_PORT'] ?? '') === '443') {
            return true;
        }
        return $this->fromTrustedProxy() && strtolower((string) $this->header('X-Forwarded-Proto')) === 'https';
    }

    /** app.trust_proxy is on and the direct peer (REMOTE_ADDR) is one of the trusted proxies. */
    private function fromTrustedProxy(): bool
    {
        $remote = (string) ($this->server['REMOTE_ADDR'] ?? '');
        return Config::get('app.trust_proxy', false) && filter_var($remote, FILTER_VALIDATE_IP) !== false && self::isTrustedProxy($remote);
    }

    /** Is $ip inside app.trusted_proxies (IPs or CIDRs; empty/missing => Cloudflare's ranges)? */
    public static function isTrustedProxy(string $ip): bool
    {
        $ranges = Config::get('app.trusted_proxies', []);
        $ranges = is_array($ranges) && $ranges !== [] ? $ranges : self::CLOUDFLARE_RANGES;
        foreach ($ranges as $range) {
            if (is_string($range) && self::ipInRange($ip, trim($range))) {
                return true;
            }
        }
        return false;
    }

    /** '203.0.113.7' in '203.0.113.0/24' (IPv4 or IPv6; a bare IP matches only itself). */
    public static function ipInRange(string $ip, string $range): bool
    {
        [$subnet, $bits] = str_contains($range, '/') ? explode('/', $range, 2) : [$range, null];
        if (filter_var($ip, FILTER_VALIDATE_IP) === false || filter_var($subnet, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        $ipBin = (string) inet_pton($ip);
        $subnetBin = (string) inet_pton($subnet);
        if (strlen($ipBin) !== strlen($subnetBin)) {
            return false; // IPv4 vs IPv6
        }
        $max = strlen($ipBin) * 8;
        if ($bits !== null && !ctype_digit($bits)) {
            return false;
        }
        $bits = $bits === null ? $max : (int) $bits;
        if ($bits > $max) {
            return false;
        }
        $bytes = intdiv($bits, 8);
        if (strncmp($ipBin, $subnetBin, $bytes) !== 0) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;
        return (ord($ipBin[$bytes]) & $mask) === (ord($subnetBin[$bytes]) & $mask);
    }

    /** Request header by name (case-insensitive), or null. */
    public function header(string $name): ?string
    {
        $key = strtoupper(str_replace('-', '_', $name));
        $value = $this->server['HTTP_' . $key] ?? (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true) ? ($this->server[$key] ?? null) : null);
        return $value === null ? null : (string) $value;
    }

    public function userAgent(): string
    {
        return (string) ($this->server['HTTP_USER_AGENT'] ?? '');
    }

    public function cookie(string $name): ?string
    {
        $value = $this->cookies[$name] ?? null;
        return is_string($value) ? $value : null;
    }

    /** Route parameter set by the Router. */
    public function param(string $name, mixed $default = null): mixed
    {
        return $this->params[$name] ?? $default;
    }

    public function setParams(array $params): void
    {
        $this->params = $params;
    }
}
