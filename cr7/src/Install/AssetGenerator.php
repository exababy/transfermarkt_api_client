<?php

declare(strict_types=1);

namespace App\Install;

/**
 * Generates brand raster images with GD (original artwork, no third-party marks):
 * og-default.png (1200x630), apple-touch-icon.png (180), icon-192/512.png and favicon.ico.
 */
final class AssetGenerator
{
    private const GOLD = [217, 180, 74];
    private const BG = [10, 10, 12];

    public function __construct(private string $publicDir, private string $fontFile)
    {
    }

    /** @return list<string> files created (relative to public/) */
    public function generateMissing(): array
    {
        if (!function_exists('imagecreatetruecolor')) {
            throw new \RuntimeException('GD eklentisi yüklü değil.');
        }
        $targets = [
            'assets/img/og-default.png' => fn (string $f) => $this->ogDefault($f),
            'assets/img/apple-touch-icon.png' => fn (string $f) => $this->icon($f, 180),
            'assets/img/icon-192.png' => fn (string $f) => $this->icon($f, 192),
            'assets/img/icon-512.png' => fn (string $f) => $this->icon($f, 512),
            'favicon.ico' => fn (string $f) => $this->favicon($f),
        ];
        $created = [];
        foreach ($targets as $relative => $generate) {
            $file = $this->publicDir . '/' . $relative;
            if (is_file($file)) {
                continue;
            }
            if (!is_dir(dirname($file))) {
                mkdir(dirname($file), 0775, true);
            }
            $generate($file);
            $created[] = $relative;
        }
        return $created;
    }

    /** Default Open Graph image: dark gradient, gold glow, "CR7.com.tr" wordmark and tagline. */
    public function ogDefault(string $file): void
    {
        $w = 1200;
        $h = 630;
        $img = imagecreatetruecolor($w, $h);
        imagealphablending($img, true);
        $this->background($img, $w, $h);

        $gold = $this->color($img, self::GOLD);
        $white = $this->color($img, [242, 242, 245]);
        $muted = $this->color($img, [163, 163, 178]);
        $watermark = imagecolorallocatealpha($img, self::GOLD[0], self::GOLD[1], self::GOLD[2], 116);

        $hasFont = is_file($this->fontFile) && function_exists('imagettftext');
        if ($hasFont) {
            imagettftext($img, 430, 0, 800, 610, $watermark, $this->fontFile, '7');
        }
        imagefilledrectangle($img, 100, 180, 108, 500, $gold);

        if ($hasFont) {
            $box = imagettftext($img, 150, 0, 136, 330, $gold, $this->fontFile, 'CR7');
            imagettftext($img, 58, 0, $box[2] + 14, 330, $muted, $this->fontFile, '.com.tr');
            imagettftext($img, 38, 0, 140, 412, $white, $this->fontFile, 'Cristiano Ronaldo Hayran Sitesi');
            imagettftext($img, 22, 0, 142, 466, $muted, $this->fontFile, 'Hayatı · Kariyeri · İstatistikleri · Haberleri · Videoları');
        } else {
            imagestring($img, 5, 140, 300, 'CR7.com.tr - Cristiano Ronaldo Hayran Sitesi', $gold);
        }
        imagefilledrectangle($img, 140, 500, 340, 504, $gold);

        imagepng($img, $file, 9);
    }

    /** Square app icon ($size px): gold "C" ring with a "7" on a dark background. */
    public function icon(string $file, int $size): void
    {
        $img = $this->iconImage($size);
        imagepng($img, $file, 9);
    }

    /** favicon.ico containing 16x16 and 32x32 PNG images. */
    public function favicon(string $file): void
    {
        $images = [];
        foreach ([16, 32] as $size) {
            ob_start();
            imagepng($this->iconImage($size), null, 9);
            $images[$size] = (string) ob_get_clean();
        }
        $ico = pack('vvv', 0, 1, count($images));
        $offset = 6 + 16 * count($images);
        $data = '';
        foreach ($images as $size => $png) {
            $ico .= pack('CCCCvvVV', $size, $size, 0, 0, 1, 32, strlen($png), $offset);
            $offset += strlen($png);
            $data .= $png;
        }
        file_put_contents($file, $ico . $data);
    }

    private function iconImage(int $size): \GdImage
    {
        $s = 1024;
        $big = imagecreatetruecolor($s, $s);
        $bg = $this->color($big, self::BG);
        $gold = $this->color($big, self::GOLD);
        imagefilledrectangle($big, 0, 0, $s, $s, $bg);
        // "C" ring: outer r=400, inner r=300, opening of ±48° on the right.
        imagefilledarc($big, 512, 512, 800, 800, 48, 312, $gold, IMG_ARC_PIE);
        imagefilledellipse($big, 512, 512, 600, 600, $bg);
        // "7" glyph as a polygon, its bar reaching into the ring opening.
        imagefilledpolygon($big, [400, 300, 790, 300, 790, 390, 600, 760, 495, 760, 680, 390, 400, 390], $gold);

        $img = imagecreatetruecolor($size, $size);
        imagecopyresampled($img, $big, 0, 0, 0, 0, $size, $size, $s, $s);
        return $img;
    }

    /** Vertical dark gradient with a smooth gold glow (top right) and a faint red glow (bottom left). */
    private function background(\GdImage $img, int $w, int $h): void
    {
        $glows = [[1080, 40, 760.0, [236, 170, 52], 0.42], [30, 650, 520.0, [200, 16, 46], 0.30]];
        for ($y = 0; $y < $h; $y++) {
            $t = $y / $h;
            $base = [11 + 9 * $t, 11 + 9 * $t, 14 + 11 * $t];
            for ($x = 0; $x < $w; $x++) {
                $rgb = $base;
                foreach ($glows as [$cx, $cy, $radius, $color, $strength]) {
                    $d = sqrt(($x - $cx) ** 2 + ($y - $cy) ** 2);
                    if ($d < $radius) {
                        $k = $strength * (1 - $d / $radius) ** 2;
                        $rgb = [$rgb[0] + ($color[0] - $rgb[0]) * $k, $rgb[1] + ($color[1] - $rgb[1]) * $k, $rgb[2] + ($color[2] - $rgb[2]) * $k];
                    }
                }
                imagesetpixel($img, $x, $y, ((int) $rgb[0] << 16) | ((int) $rgb[1] << 8) | (int) $rgb[2]);
            }
        }
    }

    private function color(\GdImage $img, array $rgb): int
    {
        return (int) imagecolorallocate($img, $rgb[0], $rgb[1], $rgb[2]);
    }
}
