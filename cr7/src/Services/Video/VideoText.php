<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Support\Str;

/**
 * Text helpers for videos: YouTube id parsing, title cleanup, safe description HTML,
 * duration formatting (ISO 8601 / clock) and thumbnail URLs.
 */
final class VideoText
{
    public const ID_PATTERN = '/^[A-Za-z0-9_-]{11}$/';

    /**
     * Extract the 11-character video id from any YouTube URL form or a bare id:
     * watch?v=, youtu.be/, /shorts/, /embed/, /live/, /v/, /e/, m./music./www. hosts,
     * youtube-nocookie.com, attribution_link?u=… Returns null for anything else.
     */
    public static function parseId(string $input): ?string
    {
        $input = trim(html_entity_decode($input, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($input === '') {
            return null;
        }
        if (preg_match(self::ID_PATTERN, $input) === 1) {
            return $input;
        }
        if (preg_match('#^(?:https?:)?//#i', $input) !== 1) {
            $input = 'https://' . ltrim($input, '/');
        }
        $parts = parse_url($input);
        if (!is_array($parts) || empty($parts['host'])) {
            return null;
        }
        $host = strtolower((string) $parts['host']);
        $host = (string) preg_replace('/^(www|m|music|gaming)\./', '', $host);
        $path = (string) ($parts['path'] ?? '');
        parse_str((string) ($parts['query'] ?? ''), $query);

        $candidate = null;
        if ($host === 'youtu.be') {
            $candidate = explode('/', trim($path, '/'))[0] ?? null;
        } elseif (in_array($host, ['youtube.com', 'youtube-nocookie.com'], true)) {
            if (isset($query['v']) && is_string($query['v'])) {
                $candidate = $query['v'];
            } elseif (preg_match('#^/(?:shorts|embed|live|v|e|watch)/([A-Za-z0-9_-]{11})(?:[/?&]|$)#', $path, $m) === 1) {
                $candidate = $m[1];
            } elseif ($path === '/attribution_link' && isset($query['u']) && is_string($query['u'])) {
                return self::parseId('https://www.youtube.com' . $query['u']);
            }
        }
        return is_string($candidate) && preg_match(self::ID_PATTERN, $candidate) === 1 ? $candidate : null;
    }

    /** Canonical watch URL. */
    public static function watchUrl(string $youtubeId): string
    {
        return 'https://www.youtube.com/watch?v=' . rawurlencode($youtubeId);
    }

    /** Privacy-enhanced embed URL (used in VideoObject.embedUrl and the facade iframe). */
    public static function embedUrl(string $youtubeId): string
    {
        return 'https://www.youtube-nocookie.com/embed/' . rawurlencode($youtubeId);
    }

    /** i.ytimg.com thumbnail: hqdefault (480x360), mqdefault (320x180), sddefault (640x480), maxresdefault. */
    public static function thumbnail(string $youtubeId, string $size = 'hqdefault'): string
    {
        $size = in_array($size, ['default', 'mqdefault', 'hqdefault', 'sddefault', 'maxresdefault'], true) ? $size : 'hqdefault';
        return 'https://i.ytimg.com/vi/' . rawurlencode($youtubeId) . '/' . $size . '.jpg';
    }

    /**
     * Tidy a channel-provided title: decode entities, drop trailing hashtag runs
     * ("… #shorts #alnassr"), unwrap inline hashtags ("#Ronaldo" => "Ronaldo"), collapse whitespace.
     */
    public static function cleanTitle(string $title): string
    {
        $title = html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $title = (string) preg_replace('/[\x{0000}-\x{001F}\x{007F}]+/u', ' ', $title);
        $stripped = trim((string) preg_replace('/(?:\s*#[\p{L}\p{N}_]+)+\s*$/u', '', $title));
        if ($stripped !== '') {
            $title = $stripped;
        }
        $title = (string) preg_replace('/(?<![\p{L}\p{N}&])#(\p{L}[\p{L}\p{N}_]*)/u', '$1', $title);
        $title = trim((string) preg_replace('/\s+/u', ' ', $title), " \t\n\r\0\x0B|-–—");
        return mb_substr($title, 0, 255);
    }

    /** Normalise a description: decode entities, unify newlines, trim, cap length. */
    public static function cleanDescription(?string $text, int $max = 5000): string
    {
        $text = html_entity_decode((string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = (string) preg_replace('/[\x{0000}-\x{0008}\x{000B}-\x{001F}\x{007F}]/u', '', $text);
        $text = (string) preg_replace("/\n{3,}/", "\n\n", $text);
        $text = trim($text);
        return mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max)) . '…' : $text;
    }

    /**
     * Plain text => safe HTML: every piece is escaped, http(s) URLs become external
     * nofollow links, newlines become <br>. No other markup is ever produced.
     */
    public static function descriptionHtml(?string $text): string
    {
        $text = (string) $text;
        if (trim($text) === '') {
            return '';
        }
        $pattern = '~https?://[^\s<>"\'`]+~iu';
        $out = '';
        $offset = 0;
        if (preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE) !== false) {
            foreach ($matches[0] as [$url, $pos]) {
                $out .= self::escape(substr($text, $offset, $pos - $offset));
                $trimmed = rtrim($url, '.,;:!?)]}»”’');
                $tail = substr($url, strlen($trimmed));
                if (filter_var($trimmed, FILTER_VALIDATE_URL) !== false) {
                    $label = mb_strlen($trimmed) > 60 ? mb_substr($trimmed, 0, 57) . '…' : $trimmed;
                    $out .= '<a href="' . self::escape($trimmed) . '" rel="nofollow noopener ugc" target="_blank">' . self::escape($label) . '</a>';
                } else {
                    $out .= self::escape($trimmed);
                }
                $out .= self::escape($tail);
                $offset = $pos + strlen($url);
            }
        }
        $out .= self::escape(substr($text, $offset));
        return nl2br($out, false);
    }

    /** 3723 => 'PT1H2M3S' (null/0 => null). */
    public static function isoDuration(?int $seconds): ?string
    {
        if ($seconds === null || $seconds <= 0) {
            return null;
        }
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;
        return 'PT' . ($h > 0 ? $h . 'H' : '') . ($m > 0 ? $m . 'M' : '') . ($s > 0 || ($h === 0 && $m === 0) ? $s . 'S' : '');
    }

    /** 'PT1H2M3S' / 'P1DT2H' => seconds; null when unparsable. */
    public static function parseIsoDuration(?string $iso): ?int
    {
        if ($iso === null || preg_match('/^P(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+(?:\.\d+)?)S)?)?$/', trim($iso), $m) !== 1) {
            return null;
        }
        $seconds = (int) ($m[1] ?? 0) * 86400 + (int) ($m[2] ?? 0) * 3600 + (int) ($m[3] ?? 0) * 60 + (int) floor((float) ($m[4] ?? 0));
        return $seconds > 0 ? $seconds : null;
    }

    /** 3723 => '1:02:03', 95 => '1:35'; '' when unknown. */
    public static function clock(?int $seconds): string
    {
        if ($seconds === null || $seconds <= 0) {
            return '';
        }
        return $seconds >= 3600
            ? sprintf('%d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60)
            : sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    }

    /** Turkish spoken duration: 3723 => '1 sa 2 dk', 95 => '1 dk 35 sn'. */
    public static function humanDuration(?int $seconds): string
    {
        if ($seconds === null || $seconds <= 0) {
            return '';
        }
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;
        if ($h > 0) {
            return $h . ' sa' . ($m > 0 ? ' ' . $m . ' dk' : '');
        }
        if ($m > 0) {
            return $m . ' dk' . ($s > 0 ? ' ' . $s . ' sn' : '');
        }
        return $s . ' sn';
    }

    /**
     * URL slug: Turkish-aware slug of the title + '-' + a short lowercase fragment of the
     * YouTube id ('Ronaldo’nun Golü', 'c_JlCriK3fY' => 'ronaldonun-golu-cjlcr').
     */
    public static function slug(string $title, string $youtubeId, int $fragmentLength = 5): string
    {
        if (preg_match('/(?=\p{L})\P{Latin}/u', $title) === 1) {
            // Arabic/Cyrillic… titles: transliteration is unreadable here, keep the Latin words only.
            $words = array_values(array_filter(
                preg_split('/[^\p{Latin}\p{N}\'’]+/u', $title) ?: [],
                static fn (string $w): bool => preg_match('/\p{Latin}/u', $w) === 1
            ));
            $title = count($words) >= 2 ? implode(' ', $words) : trim('Cristiano Ronaldo video ' . implode(' ', $words));
        }
        $base = Str::slug($title, 80);
        if ($base === '') {
            $base = 'video';
        }
        return $base . '-' . self::fragment($youtubeId, $fragmentLength);
    }

    /** True when the title has at least one Latin-script word (Turkish/English/Portuguese…). */
    public static function hasLatinText(string $title): bool
    {
        return preg_match('/\p{Latin}{2,}/u', $title) === 1;
    }

    /** Lowercase alphanumeric part of the id ('c_JlCriK3fY', 5 => 'cjlcr'). */
    public static function fragment(string $youtubeId, int $length = 5): string
    {
        $clean = strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', $youtubeId));
        if ($clean === '') {
            $clean = substr(sha1($youtubeId), 0, 8);
        }
        return substr($clean, 0, max(3, $length));
    }

    private static function escape(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
