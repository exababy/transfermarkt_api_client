<?php

declare(strict_types=1);

namespace App\Core;

use App\Controllers\ErrorController;

/**
 * HTTP response. send() adds security headers to every response and a strict
 * Content-Security-Policy (with the per-request nonce) to HTML responses.
 *
 * Pages that embed third parties (payment iframes/forms) extend the policy per
 * response: $response->withCsp('frame-src', 'https://www.paytr.com').
 */
final class Response
{
    /** Default browser cache lifetime for anonymous HTML pages (seconds). */
    private const HTML_MAX_AGE = 300;

    private static ?string $nonce = null;

    /** @var array<string, array{0: string, 1: string}> lower-case name => [name, value] */
    private array $headers = [];

    /** @var array<string, list<string>> extra CSP sources per directive */
    private array $csp = [];

    public function __construct(private string $body = '', private int $status = 200, array $headers = [])
    {
        foreach ($headers as $name => $value) {
            $this->setHeader((string) $name, (string) $value);
        }
        if (!isset($this->headers['content-type'])) {
            $this->setHeader('Content-Type', 'text/html; charset=utf-8');
        }
    }

    public static function html(string $body, int $status = 200, array $headers = []): self
    {
        return new self($body, $status, ['Content-Type' => 'text/html; charset=utf-8'] + $headers);
    }

    public static function json(mixed $data, int $status = 200, array $headers = []): self
    {
        $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return new self($body, $status, ['Content-Type' => 'application/json; charset=utf-8'] + $headers);
    }

    public static function xml(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'application/xml; charset=utf-8']);
    }

    public static function text(string $body, int $status = 200, string $contentType = 'text/plain; charset=utf-8'): self
    {
        return new self($body, $status, ['Content-Type' => $contentType]);
    }

    public static function redirect(string $url, int $status = 302): self
    {
        $url = str_replace(["\r", "\n", "\0"], '', $url);
        return new self('', $status, ['Location' => $url, 'Content-Type' => 'text/html; charset=utf-8']);
    }

    /** 404 page (templates/errors/404.php inside the main layout). */
    public static function notFound(): self
    {
        return ErrorController::render(404);
    }

    /** Per-request CSP nonce (base64), shared to views as $cspNonce. */
    public static function nonce(): string
    {
        return self::$nonce ??= base64_encode(random_bytes(16));
    }

    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->setHeader($name, $value);
        return $clone;
    }

    /** Allow extra sources for one CSP directive on this response (e.g. 'frame-src', 'https://www.paytr.com'). */
    public function withCsp(string $directive, string ...$sources): self
    {
        $clone = clone $this;
        $clone->csp[strtolower($directive)] = array_values(array_unique([...($clone->csp[strtolower($directive)] ?? []), ...$sources]));
        return $clone;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    /** @return array<string, string> name => value */
    public function headers(): array
    {
        $out = [];
        foreach ($this->headers as [$name, $value]) {
            $out[$name] = $value;
        }
        return $out;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)][1] ?? null;
    }

    public function send(): void
    {
        $this->sendHeaders();
        echo $this->body;
    }

    /** Send status line + headers only (used for HEAD requests). */
    public function sendHeaders(): void
    {
        if (headers_sent()) {
            return;
        }
        http_response_code($this->status);
        foreach ($this->finalHeaders() as $name => $value) {
            header($name . ': ' . $value, true);
        }
    }

    /** @return array<string, string> headers including security defaults */
    public function finalHeaders(): array
    {
        $isHtml = str_starts_with(strtolower((string) $this->header('Content-Type')), 'text/html');
        $defaults = [
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
        ];
        if ($isHtml) {
            $defaults += [
                'X-Frame-Options' => 'SAMEORIGIN',
                'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), interest-cohort=()',
                'Content-Security-Policy' => $this->contentSecurityPolicy(),
                'Cache-Control' => $this->defaultCacheControl(),
            ];
        }
        $request = App::request();
        if ($request !== null && $request->isHttps()) {
            $defaults['Strict-Transport-Security'] = 'max-age=31536000';
        }
        $headers = $this->headers();
        foreach ($defaults as $name => $value) {
            if ($value !== null && !isset($this->headers[strtolower($name)])) {
                $headers[$name] = $value;
            }
        }
        return $headers;
    }

    private function contentSecurityPolicy(): string
    {
        $policy = [
            'default-src' => ["'self'"],
            'img-src' => ["'self'", 'data:', 'https:'],
            'frame-src' => ['https://www.youtube-nocookie.com', 'https://www.youtube.com'],
            'script-src' => ["'self'", 'https://www.googletagmanager.com', "'nonce-" . self::nonce() . "'"],
            'style-src' => ["'self'", "'unsafe-inline'"],
            'connect-src' => ["'self'", 'https://www.google-analytics.com', 'https://*.google-analytics.com', 'https://www.googletagmanager.com'],
            'font-src' => ["'self'", 'data:'],
            'object-src' => ["'none'"],
            'base-uri' => ["'self'"],
            'form-action' => ["'self'"],
            'frame-ancestors' => ["'self'"],
        ];
        foreach ($this->csp as $directive => $sources) {
            $policy[$directive] = array_values(array_unique([...($policy[$directive] ?? ["'self'"]), ...$sources]));
        }
        $parts = [];
        foreach ($policy as $directive => $sources) {
            $parts[] = $directive . ' ' . implode(' ', $sources);
        }
        return implode('; ', $parts);
    }

    private function defaultCacheControl(): ?string
    {
        if ($this->status === 301 || $this->status === 308) {
            return null;
        }
        if ($this->status >= 300 || session_status() === PHP_SESSION_ACTIVE || isset($_SESSION)) {
            return 'no-store, private';
        }
        return 'public, max-age=' . self::HTML_MAX_AGE;
    }

    private function setHeader(string $name, string $value): void
    {
        $name = str_replace(["\r", "\n", ':'], '', $name);
        $value = str_replace(["\r", "\n", "\0"], '', $value);
        $this->headers[strtolower($name)] = [$name, $value];
    }
}
