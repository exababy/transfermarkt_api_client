<?php

declare(strict_types=1);

namespace App\Services\Shop;

use App\Core\App;

/**
 * Original jersey artwork in club colours (no crests, logos or sponsor marks):
 *   svg()  — back view with name + number, or front view (public/assets/img/kits/{slug}.svg / {slug}-on.svg)
 *   png()  — 1200x630 share image (public/og/forma-{slug}.png) drawn with GD for og:image / Product schema
 *
 * Kit spec keys: pattern solid|hoops|stripes|halves, base, alt (pattern colour), sleeves (default base),
 * trim (collar/cuffs), panel (optional side panels), text (name/number fill), outline (text stroke),
 * neck crew|v, name (default RONALDO), number (default 7).
 */
final class KitIllustrator
{
    private const W = 600;
    private const H = 620;

    // Silhouette (shared by SVG paths and GD polygons).
    private const L_SHOULDER = [148, 92];
    private const R_SHOULDER = [452, 92];
    private const L_ARMPIT = [138, 238];
    private const R_ARMPIT = [462, 238];
    private const L_HEM = [134, 552];
    private const R_HEM = [466, 552];
    private const L_SLEEVE = [[148, 92], [46, 192], [96, 264], [138, 238]];
    private const R_SLEEVE = [[452, 92], [554, 192], [504, 264], [462, 238]];

    /** Normalised spec with defaults. */
    public static function spec(array $spec): array
    {
        $base = self::color($spec['base'] ?? '#c8102e', '#c8102e');
        return [
            'pattern' => in_array($spec['pattern'] ?? 'solid', ['solid', 'hoops', 'stripes', 'halves'], true) ? $spec['pattern'] : 'solid',
            'base' => $base,
            'alt' => self::color($spec['alt'] ?? '#ffffff', '#ffffff'),
            'sleeves' => self::color($spec['sleeves'] ?? $base, $base),
            'trim' => self::color($spec['trim'] ?? '#ffffff', '#ffffff'),
            'panel' => isset($spec['panel']) ? self::color($spec['panel'], '') : '',
            'text' => self::color($spec['text'] ?? '#ffffff', '#ffffff'),
            'outline' => self::color($spec['outline'] ?? '#111111', '#111111'),
            'neck' => ($spec['neck'] ?? 'crew') === 'v' ? 'v' : 'crew',
            'name' => mb_substr((string) ($spec['name'] ?? 'RONALDO'), 0, 12),
            'number' => mb_substr((string) ($spec['number'] ?? '7'), 0, 2),
            'stripe' => max(20, min(80, (int) ($spec['stripe'] ?? 42))),
        ];
    }

    /** SVG markup; $view 'back' (name + number) or 'front'. */
    public static function svg(array $spec, string $view = 'back', string $title = ''): string
    {
        $s = self::spec($spec);
        $front = $view === 'front';
        $neck = $front
            ? ($s['neck'] === 'v' ? 'L300,132 L372,58' : 'C246,106 354,106 372,58')
            : 'C252,76 348,76 372,58';
        $body = 'M228,58 ' . $neck . ' L452,92 L462,238 L466,552 Q300,576 134,552 L138,238 L148,92 Z';
        $sleeveL = self::svgPoly(self::L_SLEEVE);
        $sleeveR = self::svgPoly(self::R_SLEEVE);
        $id = 'k' . substr(md5(json_encode($s) . $view), 0, 6);

        $pattern = '';
        if ($s['pattern'] === 'hoops') {
            for ($y = 40, $i = 0; $y < 600; $y += $s['stripe'], $i++) {
                if ($i % 2 === 1) {
                    $pattern .= '<rect x="0" y="' . $y . '" width="600" height="' . $s['stripe'] . '" fill="' . $s['alt'] . '"/>';
                }
            }
        } elseif ($s['pattern'] === 'stripes') {
            $w = $s['stripe'];
            for ($x = 300 - $w / 2 - 6 * $w, $i = 0; $x < 600; $x += $w, $i++) {
                if ($i % 2 === 1) {
                    $pattern .= '<rect x="' . $x . '" y="0" width="' . $w . '" height="620" fill="' . $s['alt'] . '"/>';
                }
            }
        } elseif ($s['pattern'] === 'halves') {
            $pattern = '<rect x="' . ($front ? 300 : 0) . '" y="0" width="300" height="620" fill="' . $s['alt'] . '"/>';
        }
        $panels = $s['panel'] !== ''
            ? '<rect x="120" y="230" width="44" height="360" fill="' . $s['panel'] . '"/><rect x="436" y="230" width="44" height="360" fill="' . $s['panel'] . '"/>'
            : '';
        $sleevePattern = in_array($s['pattern'], ['hoops', 'stripes'], true) && $s['sleeves'] === $s['base'] ? $pattern : '';

        $text = '';
        if (!$front) {
            $nameSize = mb_strlen($s['name']) > 8 ? 40 : 50;
            $text = '<g font-family="\'Arial Black\',\'Helvetica Neue\',Arial,sans-serif" font-weight="900" text-anchor="middle" fill="' . $s['text']
                . '" stroke="' . $s['outline'] . '" stroke-linejoin="round" paint-order="stroke">'
                . '<text x="300" y="192" font-size="' . $nameSize . '" letter-spacing="5" stroke-width="7">' . self::esc($s['name']) . '</text>'
                . '<text x="300" y="440" font-size="' . (mb_strlen($s['number']) > 1 ? 200 : 230) . '" stroke-width="10">' . self::esc($s['number']) . '</text>'
                . '</g>';
        }
        $collar = $front
            ? ($s['neck'] === 'v'
                ? '<path d="M228,58 L300,132 L372,58 L384,66 L300,150 L216,66 Z" fill="' . $s['trim'] . '"/>'
                : '<path d="M228,58 C246,106 354,106 372,58 L384,66 C364,124 236,124 216,66 Z" fill="' . $s['trim'] . '"/>')
            : '<path d="M228,58 C252,76 348,76 372,58 L382,66 C352,92 248,92 218,66 Z" fill="' . $s['trim'] . '"/>';
        $inner = $front
            ? '<path d="M228,58 C252,76 348,76 372,58 ' . ($s['neck'] === 'v' ? 'L300,132 Z' : 'C354,106 246,106 228,58 Z') . '" fill="' . self::shade($s['base'], -0.45) . '"/>'
            : '';
        $cuffs = '<g clip-path="url(#' . $id . 's)" stroke="' . $s['trim'] . '" stroke-width="26">'
            . '<line x1="46" y1="192" x2="96" y2="264"/><line x1="554" y1="192" x2="504" y2="264"/></g>';

        $titleTag = $title !== '' ? '<title>' . self::esc($title) . '</title>' : '';
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . self::W . ' ' . self::H . '" width="' . self::W . '" height="' . self::H . '" role="img">'
            . $titleTag
            . '<defs>'
            . '<clipPath id="' . $id . 'b"><path d="' . $body . '"/></clipPath>'
            . '<clipPath id="' . $id . 's"><path d="' . $sleeveL . '"/><path d="' . $sleeveR . '"/></clipPath>'
            . '<linearGradient id="' . $id . 'g" x1="0" y1="0" x2="1" y2="0">'
            . '<stop offset="0" stop-color="#000" stop-opacity=".34"/><stop offset=".2" stop-color="#000" stop-opacity="0"/>'
            . '<stop offset=".5" stop-color="#fff" stop-opacity=".09"/><stop offset=".8" stop-color="#000" stop-opacity="0"/>'
            . '<stop offset="1" stop-color="#000" stop-opacity=".36"/></linearGradient>'
            . '<linearGradient id="' . $id . 'v" x1="0" y1="0" x2="0" y2="1">'
            . '<stop offset="0" stop-color="#fff" stop-opacity=".1"/><stop offset=".55" stop-color="#fff" stop-opacity="0"/>'
            . '<stop offset="1" stop-color="#000" stop-opacity=".22"/></linearGradient>'
            . '<filter id="' . $id . 'f" x="-20%" y="-50%" width="140%" height="200%"><feGaussianBlur stdDeviation="12"/></filter>'
            . '</defs>'
            . '<ellipse cx="300" cy="586" rx="190" ry="18" fill="#000" opacity=".55" filter="url(#' . $id . 'f)"/>'
            . '<path d="' . $sleeveL . '" fill="' . $s['sleeves'] . '"/><path d="' . $sleeveR . '" fill="' . $s['sleeves'] . '"/>'
            . ($sleevePattern !== '' ? '<g clip-path="url(#' . $id . 's)">' . $sleevePattern . '</g>' : '')
            . $cuffs
            . '<path d="' . $body . '" fill="' . $s['base'] . '"/>'
            . '<g clip-path="url(#' . $id . 'b)">' . $pattern . $panels . '</g>'
            . $inner . $collar
            . '<g fill="none" stroke="#000" stroke-opacity=".12" stroke-width="3" stroke-linecap="round">'
            . '<path d="M150,95 Q166,170 140,236"/><path d="M450,95 Q434,170 460,236"/>'
            . ($front ? '<path d="M210,300 Q230,420 205,530"/><path d="M392,320 Q370,430 398,532"/>' : '<path d="M220,470 Q260,520 230,548"/><path d="M380,470 Q340,520 372,548"/>')
            . '</g>'
            . $text
            . '<g clip-path="url(#' . $id . 'b)"><rect width="600" height="620" fill="url(#' . $id . 'g)"/><rect width="600" height="620" fill="url(#' . $id . 'v)"/></g>'
            . '<g clip-path="url(#' . $id . 's)"><rect width="600" height="620" fill="url(#' . $id . 'v)"/><rect width="600" height="620" fill="#000" opacity=".08"/></g>'
            . '<g fill="none" stroke="#fff" stroke-opacity=".22" stroke-width="2" stroke-linejoin="round">'
            . '<path d="' . $body . '"/><path d="' . $sleeveL . '"/><path d="' . $sleeveR . '"/></g>'
            . '</svg>' . "\n";
    }

    /** Write both views to public/assets/img/kits/; returns ['/assets/img/kits/x.svg', '/assets/img/kits/x-on.svg'] or null. */
    public static function writeSvgs(string $slug, array $spec, string $title = ''): ?array
    {
        $slug = trim((string) preg_replace('/[^a-z0-9-]+/', '-', strtolower($slug)), '-');
        if ($slug === '') {
            return null;
        }
        $dir = App::basePath('public/assets/img/kits');
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return null;
        }
        $back = $dir . '/' . $slug . '.svg';
        $front = $dir . '/' . $slug . '-on.svg';
        $ok = @file_put_contents($back, self::svg($spec, 'back', $title !== '' ? $title . ' – arkadan görünüm' : '')) !== false;
        $ok = $ok && @file_put_contents($front, self::svg($spec, 'front', $title !== '' ? $title . ' – önden görünüm' : '')) !== false;
        return $ok ? ['/assets/img/kits/' . $slug . '.svg', '/assets/img/kits/' . $slug . '-on.svg'] : null;
    }

    /**
     * 1200x630 PNG share image in public/og/forma-{slug}.png; returns '/og/forma-{slug}.png' or null (no GD/FreeType).
     */
    public static function png(array $spec, string $slug, string $title, string $subtitle): ?string
    {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagettftext')) {
            return null;
        }
        $font = App::basePath('public/assets/fonts/DejaVuSans-Bold.ttf');
        $dir = App::basePath('public/og');
        $slug = trim((string) preg_replace('/[^a-z0-9-]+/', '-', strtolower($slug)), '-');
        if (!is_file($font) || $slug === '' || (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir))) {
            return null;
        }
        $s = self::spec($spec);
        $W = 1200;
        $H = 630;
        $img = imagecreatetruecolor($W, $H);
        imagealphablending($img, true);
        // Background: vertical dark gradient + gold glow on the right.
        for ($y = 0; $y < $H; $y++) {
            $t = $y / $H;
            imageline($img, 0, $y, $W, $y, self::alloc($img, self::mix('#141418', '#0a0a0c', $t)));
        }
        $glow = imagecolorallocatealpha($img, 212, 175, 55, 125);
        for ($r = 440; $r > 0; $r -= 11) {
            imagefilledellipse($img, 900, 300, $r * 2, $r * 2, $glow);
        }

        // Jersey (scaled from the 600x620 artboard) drawn through a mask.
        $scale = 0.86;
        $ox = 640;
        $oy = 28;
        $map = static fn (array $p): array => [(int) round($ox + $p[0] * $scale), (int) round($oy + $p[1] * $scale)];
        $bodyPts = array_map($map, self::bodyPoints());
        $sleeveL = array_map($map, self::L_SLEEVE);
        $sleeveR = array_map($map, self::R_SLEEVE);

        $layer = imagecreatetruecolor($W, $H);
        imagealphablending($layer, false);
        imagesavealpha($layer, true);
        imagefill($layer, 0, 0, imagecolorallocatealpha($layer, 0, 0, 0, 127));
        imagefilledpolygon($layer, self::flat($sleeveL), self::alloc($layer, $s['sleeves']));
        imagefilledpolygon($layer, self::flat($sleeveR), self::alloc($layer, $s['sleeves']));

        $pattern = imagecreatetruecolor($W, $H);
        imagefill($pattern, 0, 0, self::alloc($pattern, $s['base']));
        $alt = self::alloc($pattern, $s['alt']);
        $step = (int) round($s['stripe'] * $scale);
        if ($s['pattern'] === 'hoops') {
            for ($y = $oy + (int) (40 * $scale), $i = 0; $y < $H; $y += $step, $i++) {
                if ($i % 2 === 1) {
                    imagefilledrectangle($pattern, 0, $y, $W, $y + $step - 1, $alt);
                }
            }
        } elseif ($s['pattern'] === 'stripes') {
            $center = $ox + (int) (300 * $scale);
            for ($x = $center - (int) ($step / 2) - 6 * $step, $i = 0; $x < $W; $x += $step, $i++) {
                if ($i % 2 === 1) {
                    imagefilledrectangle($pattern, $x, 0, $x + $step - 1, $H, $alt);
                }
            }
        } elseif ($s['pattern'] === 'halves') {
            imagefilledrectangle($pattern, $ox, 0, $ox + (int) (300 * $scale), $H, $alt);
        }
        if ($s['panel'] !== '') {
            $panel = self::alloc($pattern, $s['panel']);
            imagefilledrectangle($pattern, $ox + (int) (120 * $scale), $oy + (int) (230 * $scale), $ox + (int) (164 * $scale), $H, $panel);
            imagefilledrectangle($pattern, $ox + (int) (436 * $scale), $oy + (int) (230 * $scale), $ox + (int) (480 * $scale), $H, $panel);
        }
        $mask = imagecreatetruecolor($W, $H);
        imagefill($mask, 0, 0, 0);
        imagefilledpolygon($mask, self::flat($bodyPts), 0xFFFFFF);
        $maskPts = $bodyPts;
        if (in_array($s['pattern'], ['hoops', 'stripes'], true) && $s['sleeves'] === $s['base']) {
            imagefilledpolygon($mask, self::flat($sleeveL), 0xFFFFFF);
            imagefilledpolygon($mask, self::flat($sleeveR), 0xFFFFFF);
            $maskPts = array_merge($bodyPts, $sleeveL, $sleeveR);
        }
        $xs = array_column($maskPts, 0);
        $ys = array_column($maskPts, 1);
        for ($y = max(0, min($ys)); $y <= min($H - 1, max($ys)); $y++) {
            for ($x = max(0, min($xs)); $x <= min($W - 1, max($xs)); $x++) {
                if (imagecolorat($mask, $x, $y) !== 0) {
                    imagesetpixel($layer, $x, $y, imagecolorat($pattern, $x, $y));
                }
            }
        }
        imagedestroy($pattern);
        imagedestroy($mask);
        imagesetthickness($layer, (int) round(13 * $scale));
        imageline($layer, $sleeveL[1][0], $sleeveL[1][1], $sleeveL[2][0], $sleeveL[2][1], self::alloc($layer, $s['trim']));
        imageline($layer, $sleeveR[1][0], $sleeveR[1][1], $sleeveR[2][0], $sleeveR[2][1], self::alloc($layer, $s['trim']));
        imagesetthickness($layer, 1);
        $collar = array_map($map, [[218, 66], [228, 58], [262, 70], [300, 74], [338, 70], [372, 58], [382, 66], [340, 84], [300, 88], [260, 84]]);
        imagefilledpolygon($layer, self::flat($collar), self::alloc($layer, $s['trim']));
        imagealphablending($layer, true);
        // Name + number.
        $text = self::alloc($layer, $s['text']);
        $outline = self::alloc($layer, $s['outline']);
        self::centeredText($layer, $font, 36 * $scale, $ox + 300 * $scale, $oy + 192 * $scale, $s['name'], $text, $outline, 3);
        self::centeredText($layer, $font, (mb_strlen($s['number']) > 1 ? 150 : 170) * $scale, $ox + 300 * $scale, $oy + 440 * $scale, $s['number'], $text, $outline, 5);
        // Shadow + composite.
        imagefilledellipse($img, $ox + (int) (300 * $scale), $oy + (int) (588 * $scale), (int) (380 * $scale), 26, imagecolorallocatealpha($img, 0, 0, 0, 70));
        imagecopy($img, $layer, 0, 0, 0, 0, $W, $H);
        imagedestroy($layer);
        imagesetthickness($img, 2);
        $edge = imagecolorallocatealpha($img, 255, 255, 255, 96);
        imagepolygon($img, self::flat($bodyPts), $edge);
        imagepolygon($img, self::flat($sleeveL), $edge);
        imagepolygon($img, self::flat($sleeveR), $edge);

        // Left column texts.
        $gold = imagecolorallocate($img, 212, 175, 55);
        $white = imagecolorallocate($img, 242, 242, 245);
        $muted = imagecolorallocate($img, 163, 163, 178);
        $dark = imagecolorallocate($img, 10, 10, 12);
        $pill = imagettfbbox(20, 0, $font, 'FORMA MAĞAZASI');
        $pillW = ($pill[2] - $pill[0]) + 36;
        imagefilledrectangle($img, 72, 78, 72 + $pillW, 120, $gold);
        imagettftext($img, 20, 0, 90, 109, $dark, $font, 'FORMA MAĞAZASI');
        $size = 46;
        $lines = self::wrap($title, $font, $size, 540);
        while (count($lines) > 4 && $size > 30) {
            $size -= 4;
            $lines = self::wrap($title, $font, $size, 540);
        }
        $y = 200;
        foreach (array_slice($lines, 0, 4) as $line) {
            imagettftext($img, $size, 0, 72, $y, $white, $font, $line);
            $y += (int) ($size * 1.32);
        }
        foreach (array_slice(self::wrap($subtitle, $font, 22, 520), 0, 2) as $i => $line) {
            imagettftext($img, 22, 0, 72, $y + 18 + $i * 34, $muted, $font, $line);
        }
        imagettftext($img, 30, 0, 72, 572, $gold, $font, 'CR7');
        imagettftext($img, 30, 0, 72 + (imagettfbbox(30, 0, $font, 'CR7')[2] ?? 70) + 2, 572, $white, $font, '.com.tr');

        $file = $dir . '/forma-' . $slug . '.png';
        $ok = imagepng($img, $file, 8);
        imagedestroy($img);
        return $ok ? '/og/forma-' . $slug . '.png' : null;
    }

    /** '/og/forma-{slug}.png' if the share image exists. */
    public static function pngPath(string $slug): ?string
    {
        return is_file(App::basePath('public/og/forma-' . $slug . '.png')) ? '/og/forma-' . $slug . '.png' : null;
    }

    /** Back-view body outline as points (curves sampled) for GD. */
    private static function bodyPoints(): array
    {
        $pts = [[228, 58]];
        foreach (self::cubic([228, 58], [252, 76], [348, 76], [372, 58], 12) as $p) {
            $pts[] = $p;
        }
        $pts[] = self::R_SHOULDER;
        $pts[] = self::R_ARMPIT;
        $pts[] = self::R_HEM;
        foreach (self::quad(self::R_HEM, [300, 576], self::L_HEM, 14) as $p) {
            $pts[] = $p;
        }
        $pts[] = self::L_ARMPIT;
        $pts[] = self::L_SHOULDER;
        return $pts;
    }

    private static function cubic(array $a, array $b, array $c, array $d, int $n): array
    {
        $out = [];
        for ($i = 1; $i <= $n; $i++) {
            $t = $i / $n;
            $u = 1 - $t;
            $out[] = [
                $u ** 3 * $a[0] + 3 * $u * $u * $t * $b[0] + 3 * $u * $t * $t * $c[0] + $t ** 3 * $d[0],
                $u ** 3 * $a[1] + 3 * $u * $u * $t * $b[1] + 3 * $u * $t * $t * $c[1] + $t ** 3 * $d[1],
            ];
        }
        return $out;
    }

    private static function quad(array $a, array $b, array $c, int $n): array
    {
        $out = [];
        for ($i = 1; $i <= $n; $i++) {
            $t = $i / $n;
            $u = 1 - $t;
            $out[] = [$u * $u * $a[0] + 2 * $u * $t * $b[0] + $t * $t * $c[0], $u * $u * $a[1] + 2 * $u * $t * $b[1] + $t * $t * $c[1]];
        }
        return $out;
    }

    private static function svgPoly(array $points): string
    {
        $d = '';
        foreach ($points as $i => [$x, $y]) {
            $d .= ($i === 0 ? 'M' : 'L') . $x . ',' . $y . ' ';
        }
        return $d . 'Z';
    }

    /** @return list<int> flat x,y list for imagefilledpolygon */
    private static function flat(array $points): array
    {
        $out = [];
        foreach ($points as [$x, $y]) {
            $out[] = (int) round($x);
            $out[] = (int) round($y);
        }
        return $out;
    }

    private static function centeredText(\GdImage $img, string $font, float $size, float $cx, float $baseline, string $text, int $fill, int $outline, int $stroke): void
    {
        $box = imagettfbbox($size, 0, $font, $text);
        $x = (int) round($cx - ($box[2] - $box[0]) / 2);
        $y = (int) round($baseline);
        for ($dx = -$stroke; $dx <= $stroke; $dx++) {
            for ($dy = -$stroke; $dy <= $stroke; $dy++) {
                if ($dx * $dx + $dy * $dy <= $stroke * $stroke) {
                    imagettftext($img, $size, 0, $x + $dx, $y + $dy, $outline, $font, $text);
                }
            }
        }
        imagettftext($img, $size, 0, $x, $y, $fill, $font, $text);
    }

    /** @return list<string> */
    private static function wrap(string $text, string $font, float $size, int $maxWidth): array
    {
        $lines = [];
        $line = '';
        foreach (preg_split('/\s+/u', trim($text)) ?: [] as $word) {
            $try = $line === '' ? $word : $line . ' ' . $word;
            $box = imagettfbbox($size, 0, $font, $try);
            if ($line !== '' && ($box[2] - $box[0]) > $maxWidth) {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $try;
            }
        }
        if ($line !== '') {
            $lines[] = $line;
        }
        return $lines;
    }

    private static function alloc(\GdImage $img, string $hex): int
    {
        [$r, $g, $b] = self::rgb($hex);
        return imagecolorallocate($img, $r, $g, $b);
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    private static function mix(string $a, string $b, float $t): string
    {
        $ca = self::rgb($a);
        $cb = self::rgb($b);
        return sprintf('#%02x%02x%02x', (int) ($ca[0] + ($cb[0] - $ca[0]) * $t), (int) ($ca[1] + ($cb[1] - $ca[1]) * $t), (int) ($ca[2] + ($cb[2] - $ca[2]) * $t));
    }

    /** Lighten (+) / darken (-) a colour. */
    private static function shade(string $hex, float $amount): string
    {
        [$r, $g, $b] = self::rgb($hex);
        $f = static fn (int $c): int => (int) max(0, min(255, $amount < 0 ? $c * (1 + $amount) : $c + (255 - $c) * $amount));
        return sprintf('#%02x%02x%02x', $f($r), $f($g), $f($b));
    }

    /** '#abc'/'#aabbcc' (lower-case) or $fallback. */
    public static function color(mixed $value, string $fallback): string
    {
        $value = is_string($value) ? strtolower(trim($value)) : '';
        return preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/', $value) === 1 ? $value : $fallback;
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
