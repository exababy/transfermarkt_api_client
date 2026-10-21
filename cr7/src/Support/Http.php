<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Request;

/**
 * Small curl HTTP client. Never throws: failures are reported in 'error'.
 * Result: ['status' => int, 'body' => string, 'headers' => array<lowercase name, string>, 'error' => ?string, 'final_url' => string]
 * Headers may be passed as ['Accept' => 'application/json'] or ['Accept: application/json'].
 */
final class Http
{
    public const USER_AGENT = 'Mozilla/5.0 (compatible; CR7Bot/1.0; +https://cr7.com.tr)';
    private const MAX_BYTES = 15 * 1024 * 1024;
    private const MAX_REDIRECTS = 5;

    /** Non-public ranges FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE let through (PHP 8.1 has no GLOBAL_RANGE). */
    private const NON_PUBLIC_RANGES = [
        '100.64.0.0/10', '192.0.0.0/24', '198.18.0.0/15', '224.0.0.0/4',
        '::ffff:0:0/96', '64:ff9b::/96', '2002::/16', 'fc00::/7', 'fe80::/10', 'ff00::/8',
    ];

    public static function get(string $url, array $headers = [], int $timeout = 20): array
    {
        return self::request('GET', $url, null, $headers, $timeout);
    }

    /**
     * GET a URL that comes from third-party content (feed items, article links) without letting it reach the
     * server's own network (SSRF): http(s) on port 80/443 only, every address of the host must be public, curl is
     * pinned to the checked addresses (CURLOPT_RESOLVE), and redirects are followed by hand with each hop checked.
     */
    public static function getPublic(string $url, array $headers = [], int $timeout = 20): array
    {
        $result = ['status' => 0, 'body' => '', 'headers' => [], 'error' => null, 'final_url' => $url];
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            [$blocked, $pins] = self::publicTarget($url);
            if ($blocked !== null) {
                return ['status' => 0, 'body' => '', 'headers' => [], 'error' => 'Blocked URL: ' . $blocked, 'final_url' => $url];
            }
            $result = self::request('GET', $url, null, $headers, $timeout, false, $pins);
            $location = (string) ($result['headers']['location'] ?? '');
            if ($result['error'] !== null || !in_array($result['status'], [301, 302, 303, 307, 308], true) || $location === '') {
                return $result;
            }
            $url = self::absoluteUrl($url, $location);
        }
        $result['error'] = 'Too many redirects';
        return $result;
    }

    /**
     * Why $url may not be fetched by getPublic() (null = allowed) and the CURLOPT_RESOLVE pins for it.
     *
     * @return array{0: ?string, 1: list<string>}
     */
    public static function publicTarget(string $url): array
    {
        // Strict shape (no user-info, backslashes, spaces, odd ports) so PHP and curl agree on the host.
        if (preg_match('#^(https?)://(\[[0-9a-f:.]+\]|[a-z0-9.-]+)(?::(\d{1,5}))?(?:[/?\#][^\s\\\\]*)?$#i', $url, $m) !== 1) {
            return ['not a plain http(s) URL', []];
        }
        $scheme = strtolower($m[1]);
        $host = strtolower(rtrim($m[2], '.'));
        $port = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : ($scheme === 'https' ? 443 : 80);
        if (!in_array($port, [80, 443], true)) {
            return ['port ' . $port . ' is not allowed', []];
        }
        $literal = trim($host, '[]');
        $isLiteral = filter_var($literal, FILTER_VALIDATE_IP) !== false;
        $ips = $isLiteral ? [$literal] : (gethostbynamel($host) ?: []);
        if (!$isLiteral) {
            $v6 = @dns_get_record($host, DNS_AAAA);
            foreach (is_array($v6) ? $v6 : [] as $record) {
                if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }
        if ($ips === []) {
            return ['host ' . $host . ' does not resolve', []];
        }
        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                return ['host ' . $host . ' has the non-public address ' . $ip, []];
            }
        }
        if ($isLiteral) {
            return [null, []];
        }
        $pins = array_map(static fn (string $ip): string => str_contains($ip, ':') ? '[' . $ip . ']' : $ip, $ips);
        return [null, [$host . ':' . $port . ':' . implode(',', $pins)]];
    }

    /** Globally routable unicast address (no loopback, private, link-local, CGNAT, reserved or multicast). */
    public static function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        foreach (self::NON_PUBLIC_RANGES as $range) {
            if (Request::ipInRange($ip, $range)) {
                return false;
            }
        }
        return true;
    }

    /** Resolve a Location header against the URL that sent it. */
    private static function absoluteUrl(string $base, string $location): string
    {
        $location = trim($location);
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $location) === 1) {
            return $location;
        }
        $parts = parse_url($base);
        $scheme = (string) ($parts['scheme'] ?? 'https');
        if (str_starts_with($location, '//')) {
            return $scheme . ':' . $location;
        }
        $origin = $scheme . '://' . (string) ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $path = (string) ($parts['path'] ?? '/');
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }
        if (str_starts_with($location, '?')) {
            return $origin . $path . $location;
        }
        return $origin . (string) preg_replace('#/[^/]*$#', '/', $path) . $location;
    }

    /** POST; array bodies are form-encoded (application/x-www-form-urlencoded), strings are sent as-is. */
    public static function post(string $url, string|array $body, array $headers = [], int $timeout = 20): array
    {
        return self::request('POST', $url, is_array($body) ? http_build_query($body) : $body, $headers, $timeout);
    }

    /** @param list<string> $resolve CURLOPT_RESOLVE entries ("host:port:ip,…") */
    private static function request(string $method, string $url, ?string $body, array $headers, int $timeout, bool $follow = true, array $resolve = []): array
    {
        $result = ['status' => 0, 'body' => '', 'headers' => [], 'error' => null, 'final_url' => $url];
        if (!function_exists('curl_init')) {
            $result['error'] = 'curl extension is not available';
            return $result;
        }
        if (preg_match('#^https?://#i', $url) !== 1) {
            $result['error'] = 'Only http(s) URLs are allowed';
            return $result;
        }
        try {
            $lines = [];
            $hasUserAgent = false;
            foreach ($headers as $name => $value) {
                $line = is_int($name) ? (string) $value : $name . ': ' . $value;
                $hasUserAgent = $hasUserAgent || stripos($line, 'user-agent:') === 0;
                $lines[] = str_replace(["\r", "\n"], '', $line);
            }
            if (!$hasUserAgent) {
                $lines[] = 'User-Agent: ' . self::USER_AGENT;
            }

            $responseHeaders = [];
            $buffer = '';
            $tooLarge = false;
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_FOLLOWLOCATION => $follow,
                CURLOPT_MAXREDIRS => self::MAX_REDIRECTS,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_ENCODING => '',
                CURLOPT_CONNECTTIMEOUT => min(10, max(1, $timeout)),
                CURLOPT_TIMEOUT => max(1, $timeout),
                CURLOPT_HTTPHEADER => $lines,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HEADERFUNCTION => static function ($ch, string $header) use (&$responseHeaders): int {
                    $trimmed = trim($header);
                    if (preg_match('#^HTTP/\S+\s+\d+#', $trimmed) === 1) {
                        $responseHeaders = []; // new response in a redirect chain
                    } elseif (str_contains($trimmed, ':')) {
                        [$name, $value] = explode(':', $trimmed, 2);
                        $responseHeaders[strtolower(trim($name))] = trim($value);
                    }
                    return strlen($header);
                },
                CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$buffer, &$tooLarge): int {
                    if (strlen($buffer) + strlen($chunk) > self::MAX_BYTES) {
                        $tooLarge = true;
                        return 0; // abort transfer
                    }
                    $buffer .= $chunk;
                    return strlen($chunk);
                },
            ]);
            if ($resolve !== []) {
                curl_setopt($ch, CURLOPT_RESOLVE, $resolve);
            }
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
            $ok = curl_exec($ch);
            $result['status'] = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $result['final_url'] = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) ?: $url;
            if ($ok === false) {
                $result['error'] = $tooLarge ? 'Response exceeds ' . self::MAX_BYTES . ' bytes' : (curl_error($ch) ?: 'Request failed');
            }
            $result['body'] = $buffer;
            $result['headers'] = $responseHeaders;
        } catch (\Throwable $e) {
            $result['error'] = $e->getMessage();
        }
        return $result;
    }
}
