<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\App;

/**
 * Generated 1200x630 Open Graph images for news posts (GD + bundled DejaVu Sans Bold), so source
 * images are never hotlinked: dark brand background with gold/red glows, "7" watermark, gold
 * eyebrow, the wrapped Turkish title, a subtitle (date) and the "CR7.com.tr" wordmark.
 */
final class OgImage
{
    public const WIDTH = 1200;
    public const HEIGHT = 630;
    /** Width of the card thumbnail used in listings (srcset); the 1200 px PNG stays for og:image. */
    public const CARD_WIDTH = 640;
    private const GOLD = [212, 175, 55];
    private const GOLD_SOFT = [241, 215, 122];

    /**
     * Render the image to public/og/<fileName>.png and return '/og/<fileName>.png' (null when GD /
     * FreeType are unavailable or the file cannot be written).
     */
    public static function generate(string $title, string $subtitle, string $fileName, string $eyebrow = 'HABER'): ?string
    {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagettftext')) {
            return null;
        }
        $font = App::basePath('public/assets/fonts/DejaVuSans-Bold.ttf');
        if (!is_file($font)) {
            return null;
        }
        $name = trim((string) preg_replace('/[^a-z0-9-]+/', '-', strtolower($fileName)), '-');
        $name = substr($name !== '' ? $name : 'haber-' . substr(sha1($title), 0, 10), 0, 120);
        $dir = App::basePath('public/og');
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return null;
        }

        $img = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        imagealphablending($img, true);
        imagecopy($img, self::background(), 0, 0, 0, 0, self::WIDTH, self::HEIGHT);

        $gold = self::color($img, self::GOLD);
        $goldSoft = self::color($img, self::GOLD_SOFT);
        $white = self::color($img, [242, 242, 245]);
        $muted = self::color($img, [163, 163, 178]);
        $watermark = imagecolorallocatealpha($img, self::GOLD[0], self::GOLD[1], self::GOLD[2], 118);

        imagettftext($img, 470, 0, 800, 640, $watermark, $font, '7');

        // Eyebrow: gold pill ("HABER" for news, the section name for page cards).
        $pill = imagettfbbox(22, 0, $font, $eyebrow);
        $pillWidth = ($pill[2] - $pill[0]) + 40;
        imagefilledrectangle($img, 80, 70, 80 + $pillWidth, 116, $gold);
        imagettftext($img, 22, 0, 100, 104, self::color($img, [10, 10, 12]), $font, $eyebrow);
        imagettftext($img, 22, 0, 80 + $pillWidth + 22, 104, $goldSoft, $font, 'Cristiano Ronaldo');

        // Title: largest font size that fits in 4 lines.
        $title = trim((string) preg_replace('/\s+/u', ' ', $title));
        $maxWidth = 1030;
        $lines = [];
        $size = 60;
        for (; $size >= 36; $size -= 4) {
            $lines = self::wrap($title, $font, $size, $maxWidth);
            if (count($lines) <= 4) {
                break;
            }
        }
        if (count($lines) > 4) {
            $lines = array_slice($lines, 0, 4);
            $lines[3] = rtrim(mb_substr($lines[3], 0, max(1, mb_strlen($lines[3]) - 2)), ' ,.;:') . '…';
        }
        $lineHeight = (int) round($size * 1.32);
        $blockHeight = $lineHeight * count($lines);
        $top = 150 + (int) max(0, (330 - $blockHeight) / 2);
        imagefilledrectangle($img, 80, $top - 6, 87, $top + $blockHeight - (int) ($lineHeight * 0.25), $gold);
        $y = $top + $size;
        foreach ($lines as $line) {
            imagettftext($img, $size, 0, 112, $y, $white, $font, $line);
            $y += $lineHeight;
        }

        // Footer: gold rule, subtitle (date) and wordmark.
        imagefilledrectangle($img, 80, 522, 300, 526, $gold);
        if ($subtitle !== '') {
            imagettftext($img, 24, 0, 80, 578, $muted, $font, mb_substr($subtitle, 0, 60));
        }
        $box = imagettfbbox(34, 0, $font, 'CR7');
        $tld = imagettfbbox(24, 0, $font, '.com.tr');
        $wordWidth = ($box[2] - $box[0]) + 6 + ($tld[2] - $tld[0]);
        $x = self::WIDTH - 80 - $wordWidth;
        $end = imagettftext($img, 34, 0, $x, 580, $gold, $font, 'CR7');
        imagettftext($img, 24, 0, $end[2] + 6, 580, $muted, $font, '.com.tr');

        $file = $dir . '/' . $name . '.png';
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $ok = imagepng($img, $tmp, 8);
        if (!$ok || !@rename($tmp, $file)) {
            @unlink($tmp);
            return null;
        }
        self::variant('/og/' . $name . '.png');
        return '/og/' . $name . '.png';
    }

    /**
     * '/og/x-640.jpg' — a 640x336 JPEG of a generated '/og/x.png' (about a third of the PNG's
     * bytes), created on first use; null when $path is not a generated image or GD cannot write it.
     */
    public static function variant(?string $path): ?string
    {
        if ($path === null || preg_match('#^/og/([a-z0-9-]+)\.png$#', $path, $m) !== 1) {
            return null;
        }
        $variant = '/og/' . $m[1] . '-' . self::CARD_WIDTH . '.jpg';
        $target = App::basePath('public' . $variant);
        if (is_file($target)) {
            return $variant;
        }
        $source = App::basePath('public' . $path);
        if (!is_file($source) || !function_exists('imagecreatefrompng') || !function_exists('imagejpeg')) {
            return null;
        }
        $src = @imagecreatefrompng($source);
        if ($src === false) {
            return null;
        }
        $height = (int) round(self::CARD_WIDTH * imagesy($src) / max(1, imagesx($src)));
        $img = imagecreatetruecolor(self::CARD_WIDTH, $height);
        imagecopyresampled($img, $src, 0, 0, 0, 0, self::CARD_WIDTH, $height, imagesx($src), imagesy($src));
        imageinterlace($img, true);
        $tmp = $target . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $ok = @imagejpeg($img, $tmp, 82);
        if (!$ok || !@rename($tmp, $target)) {
            @unlink($tmp);
            return null;
        }
        return $variant;
    }

    /**
     * Share card of a section page (/hayati, /kariyer, /takimlar/real-madrid …) so these pages do not
     * all share og-default.png: '/og/sayfa-<key>-<hash>.png', generated once (a new title gets a new
     * file; older cards of the same key are removed). Null without GD: the page keeps the default.
     */
    public static function page(string $key, string $title, string $subtitle, string $eyebrow): ?string
    {
        $key = trim((string) preg_replace('/[^a-z0-9-]+/', '-', strtolower($key)), '-');
        if ($key === '') {
            return null;
        }
        $name = 'sayfa-' . substr($key, 0, 80) . '-' . substr(sha1($title . '|' . $subtitle . '|' . $eyebrow), 0, 6);
        if (is_file(App::basePath('public/og/' . $name . '.png'))) {
            return '/og/' . $name . '.png';
        }
        foreach (glob(App::basePath('public/og/sayfa-' . $key . '-*.png')) ?: [] as $old) {
            if (preg_match('#/sayfa-' . preg_quote($key, '#') . '-[0-9a-f]{6}\.png$#', $old) === 1) {
                self::delete('/og/' . basename($old));
            }
        }
        return self::generate($title, $subtitle, $name, $eyebrow);
    }

    /** og:image:width/height for a generated 1200x630 card (none for null). */
    public static function meta(?string $path): array
    {
        return $path === null ? [] : [
            ['property' => 'og:image:width', 'content' => (string) self::WIDTH],
            ['property' => 'og:image:height', 'content' => (string) self::HEIGHT],
        ];
    }

    /** Remove a generated image ('/og/x.png', with its card variant); ignores anything outside public/og. */
    public static function delete(?string $path): void
    {
        if ($path === null || preg_match('#^/og/([a-z0-9-]+)\.png$#', $path, $m) !== 1) {
            return;
        }
        foreach ([$path, '/og/' . $m[1] . '-' . self::CARD_WIDTH . '.jpg'] as $generated) {
            $file = App::basePath('public' . $generated);
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    /**
     * Word-wrap $text for imagettftext.
     *
     * @return list<string>
     */
    public static function wrap(string $text, string $font, float $size, int $maxWidth): array
    {
        $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $lines = [];
        $line = '';
        foreach ($words as $word) {
            $candidate = $line === '' ? $word : $line . ' ' . $word;
            $box = imagettfbbox($size, 0, $font, $candidate);
            if ($box !== false && ($box[2] - $box[0]) > $maxWidth && $line !== '') {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $candidate;
            }
        }
        if ($line !== '') {
            $lines[] = $line;
        }
        return $lines;
    }

    /** Brand background (rendered once at low resolution, upscaled smoothly, cached in storage/cache). */
    private static function background(): \GdImage
    {
        static $bg = null;
        if ($bg instanceof \GdImage) {
            return $bg;
        }
        $cache = App::storagePath('cache/og-news-bg.png');
        if (is_file($cache)) {
            $loaded = @imagecreatefrompng($cache);
            if ($loaded instanceof \GdImage && imagesx($loaded) === self::WIDTH) {
                return $bg = $loaded;
            }
        }
        $w = 300;
        $h = 158;
        $small = imagecreatetruecolor($w, $h);
        $glows = [[270, 10, 190.0, [236, 170, 52], 0.40], [8, 162, 130.0, [200, 16, 46], 0.26]];
        for ($y = 0; $y < $h; $y++) {
            $t = $y / $h;
            for ($x = 0; $x < $w; $x++) {
                $rgb = [11 + 9 * $t, 11 + 9 * $t, 14 + 11 * $t];
                foreach ($glows as [$cx, $cy, $radius, $color, $strength]) {
                    $d = sqrt(($x - $cx) ** 2 + ($y - $cy) ** 2);
                    if ($d < $radius) {
                        $k = $strength * (1 - $d / $radius) ** 2;
                        $rgb = [$rgb[0] + ($color[0] - $rgb[0]) * $k, $rgb[1] + ($color[1] - $rgb[1]) * $k, $rgb[2] + ($color[2] - $rgb[2]) * $k];
                    }
                }
                imagesetpixel($small, $x, $y, ((int) $rgb[0] << 16) | ((int) $rgb[1] << 8) | (int) $rgb[2]);
            }
        }
        $bg = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        imagecopyresampled($bg, $small, 0, 0, 0, 0, self::WIDTH, self::HEIGHT, $w, $h);
        if (is_dir(dirname($cache)) && is_writable(dirname($cache))) {
            @imagepng($bg, $cache, 6);
        }
        return $bg;
    }

    private static function color(\GdImage $img, array $rgb): int
    {
        return (int) imagecolorallocate($img, $rgb[0], $rgb[1], $rgb[2]);
    }
}
