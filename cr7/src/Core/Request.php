<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Immutable-ish HTTP request (query, POST body, server vars, route params).
 */
final class Request
{
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

    /** Client IP (REMOTE_ADDR; proxy headers only when app.trust_proxy is true). */
    public function ip(): string
    {
        $candidates = [];
        if (Config::get('app.trust_proxy', false)) {
            $candidates[] = $this->header('CF-Connecting-IP');
            $forwarded = $this->header('X-Forwarded-For');
            if ($forwarded !== null) {
                $candidates[] = trim(explode(',', $forwarded)[0]);
            }
        }
        $candidates[] = $this->server['REMOTE_ADDR'] ?? null;
        foreach ($candidates as $ip) {
            if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                return $ip;
            }
        }
        return '0.0.0.0';
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
        return Config::get('app.trust_proxy', false) && strtolower((string) $this->header('X-Forwarded-Proto')) === 'https';
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
