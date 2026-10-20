<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Small curl HTTP client. Never throws: failures are reported in 'error'.
 * Result: ['status' => int, 'body' => string, 'headers' => array<lowercase name, string>, 'error' => ?string, 'final_url' => string]
 * Headers may be passed as ['Accept' => 'application/json'] or ['Accept: application/json'].
 */
final class Http
{
    public const USER_AGENT = 'Mozilla/5.0 (compatible; CR7Bot/1.0; +https://cr7.com.tr)';
    private const MAX_BYTES = 15 * 1024 * 1024;

    public static function get(string $url, array $headers = [], int $timeout = 20): array
    {
        return self::request('GET', $url, null, $headers, $timeout);
    }

    /** POST; array bodies are form-encoded (application/x-www-form-urlencoded), strings are sent as-is. */
    public static function post(string $url, string|array $body, array $headers = [], int $timeout = 20): array
    {
        return self::request('POST', $url, is_array($body) ? http_build_query($body) : $body, $headers, $timeout);
    }

    private static function request(string $method, string $url, ?string $body, array $headers, int $timeout): array
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
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
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
