<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Server-rendered, dependency-free SVG charts (stats module).
 *
 * Accessible: role="img" + <title>/<desc> (aria-labelledby); every chart should be paired with a
 * table view in the page. Responsive: each call renders three variants (viewBox widths 360 / 600 /
 * 960) and CSS container queries in app.css (".chart .st-s/.st-m/.st-l") show the one that keeps
 * axis text at ~11–16px — readable from 360px phones to 1440px desktops. Without container-query
 * support the small variant is shown.
 *
 * Marks follow the dataviz spec: single-series gold (#b39029, validated against the dark card
 * surface #1b1b21: OKLCH L 0.64, contrast ≥ 3:1), columns ≤ 24px with a 4px rounded data end and
 * ≥ 2px surface gap, 2px line, 10% area wash, ≥ 8px end dots with a 2px surface ring, solid 1px
 * recessive grid, selective direct labels (extreme + latest) in text tokens. Hover/focus details
 * come from data-tip-* attributes (see templates/partials/stats-chart-script.php); hit targets are
 * the full column band / the nearest-point slice, never only the painted pixels.
 */
final class Svg
{
    public const MARK = '#b39029';
    public const SURFACE = '#1b1b21';
    public const CROSS = '#6b6b78';

    /** variant => [viewBox width, height, max x-axis labels] */
    private const VARIANTS = ['s' => [360, 240], 'm' => [600, 280], 'l' => [960, 320]];
    private const FONT_PX = 13;      // keep in sync with ".chart .st text" in app.css
    private const CHAR_W = 7.4;      // average glyph width at 13px (digits / Latin)

    /**
     * Column chart for one series.
     *
     * @param list<array{label: string|int, value: int|float, tip?: string}> $data ordered columns
     * @param array{title: string, desc?: string, id?: string, unit?: string, format?: callable(int|float): string,
     *              labelMax?: bool, labelLast?: bool, variants?: list<string>} $options
     *        unit: shown in tooltips ("69 gol"); tip: secondary tooltip line per column
     */
    public static function bar(array $data, array $options = []): string
    {
        $data = array_values(array_filter($data, static fn ($d): bool => is_array($d) && isset($d['label'])));
        if ($data === []) {
            return '';
        }
        $out = '';
        foreach (self::variants($options) as $variant) {
            $out .= self::barVariant($data, $options, $variant);
        }
        return $out;
    }

    /**
     * Line + area chart over time for one series.
     *
     * @param list<array{x: string, y: int|float, tip?: string}> $points x = 'Y-m-d' (chronological)
     * @param array{title: string, desc?: string, id?: string, format?: callable(int|float): string,
     *              axisFormat?: callable(int|float): string, variants?: list<string>} $options
     *        format: values in labels/tooltips; axisFormat: y tick labels (e.g. millions without unit)
     */
    public static function line(array $points, array $options = []): string
    {
        $clean = [];
        foreach ($points as $p) {
            $ts = is_array($p) && isset($p['x']) ? strtotime((string) $p['x'] . ' 12:00:00 UTC') : false;
            if ($ts !== false && isset($p['y']) && is_numeric($p['y'])) {
                $clean[] = ['ts' => $ts, 'y' => (float) $p['y'], 'x' => (string) $p['x'], 'tip' => (string) ($p['tip'] ?? '')];
            }
        }
        usort($clean, static fn (array $a, array $b): int => $a['ts'] <=> $b['ts']);
        if (count($clean) < 2) {
            return '';
        }
        $out = '';
        foreach (self::variants($options) as $variant) {
            $out .= self::lineVariant($clean, $options, $variant);
        }
        return $out;
    }

    /**
     * "Nice" axis: [top, step] with 4–6 ticks (steps 1, 2, 2.5, 3, 5 × 10^n).
     *
     * @return array{0: float, 1: float}
     */
    public static function niceScale(float $max, int $targetTicks = 4): array
    {
        if ($max <= 0) {
            return [1.0, 1.0];
        }
        $raw = $max / max(1, $targetTicks);
        $magnitude = 10 ** floor(log10($raw));
        $step = $magnitude;
        foreach ([1, 2, 2.5, 3, 5, 10] as $factor) {
            if ($raw <= $factor * $magnitude + 1e-9) {
                $step = $factor * $magnitude;
                break;
            }
        }
        if ($step < 1 && $max >= 1) {
            $step = 1.0;
        }
        $top = ceil($max / $step - 1e-9) * $step;
        return [(float) $top, (float) $step];
    }

    // ---------------------------------------------------------------- bar

    private static function barVariant(array $data, array $options, string $variant): string
    {
        [$width, $height] = self::VARIANTS[$variant];
        $format = self::formatter($options['format'] ?? null);
        $unit = (string) ($options['unit'] ?? '');
        $values = array_map(static fn (array $d): float => (float) $d['value'], $data);
        $max = max($values);
        [$top, $step] = self::niceScale($max);

        $tickLabels = [];
        for ($v = 0.0; $v <= $top + 1e-9; $v += $step) {
            $tickLabels[] = $format($v);
        }
        $left = (int) ceil(max(array_map(static fn (string $t): float => self::textWidth($t), $tickLabels)) + 10);
        $right = 6;
        $pTop = 26;
        $pBottom = 30;
        $plotW = $width - $left - $right;
        $plotH = $height - $pTop - $pBottom;
        $n = count($data);
        $band = $plotW / $n;
        $barW = min(24.0, max(2.0, $band - max(2.0, $band * 0.28)));
        $y = static fn (float $v): float => $pTop + $plotH - ($top > 0 ? $v / $top * $plotH : 0);

        [$id, $titleId, $descId] = self::ids($options, $variant, 'bar');
        $svg = self::open($variant, $width, $height, $titleId, $descId, $options);

        // Grid + y ticks.
        $svg .= '<g aria-hidden="true">';
        foreach ($tickLabels as $i => $label) {
            $ty = round($y($i * $step), 1);
            $class = $i === 0 ? 'chart-axis' : 'chart-grid';
            $svg .= '<line class="' . $class . '" x1="' . $left . '" x2="' . ($width - $right) . '" y1="' . $ty . '" y2="' . $ty . '" stroke-width="1"/>';
            $svg .= '<text x="' . ($left - 8) . '" y="' . round($ty + 4.5, 1) . '" text-anchor="end">' . self::esc($label) . '</text>';
        }
        $svg .= '</g>';

        // X labels: every k-th, always including the last column.
        $labelW = max(array_map(static fn (array $d): float => self::textWidth((string) $d['label']), $data)) + 10;
        $k = max(1, (int) ceil($labelW / $band));
        $svg .= '<g aria-hidden="true">';
        foreach ($data as $i => $d) {
            if (($n - 1 - $i) % $k !== 0) {
                continue;
            }
            $cx = round($left + $band * ($i + .5), 1);
            $svg .= '<text x="' . $cx . '" y="' . ($height - 9) . '" text-anchor="middle">' . self::esc((string) $d['label']) . '</text>';
        }
        $svg .= '</g>';

        // Columns + hit areas.
        $maxIndex = array_search($max, $values, true);
        $svg .= '<g>';
        foreach ($data as $i => $d) {
            $value = (float) $d['value'];
            $x0 = $left + $band * $i;
            $bx = round($x0 + ($band - $barW) / 2, 2);
            $tipValue = $format($value) . ($unit !== '' ? ' ' . $unit : '');
            $tipLabel = trim((string) $d['label'] . (isset($d['tip']) && $d['tip'] !== '' ? ' · ' . $d['tip'] : ''));
            $svg .= '<g class="st-hit" data-tip-v="' . self::esc($tipValue) . '" data-tip-l="' . self::esc($tipLabel) . '">';
            $svg .= '<rect x="' . round($x0, 2) . '" y="' . $pTop . '" width="' . round($band, 2) . '" height="' . $plotH . '" fill="transparent"/>';
            if ($value > 0) {
                $svg .= '<path class="st-mark" fill="' . self::MARK . '" d="' . self::columnPath($bx, $y($value), $barW, $y(0)) . '"/>';
            }
            $svg .= '</g>';
        }
        $svg .= '</g>';

        // Selective direct labels: the maximum and the latest column.
        $labels = [];
        if (($options['labelMax'] ?? true) && $maxIndex !== false && $max > 0) {
            $labels[(int) $maxIndex] = true;
        }
        if (($options['labelLast'] ?? true) && $values[$n - 1] > 0) {
            $labels[$n - 1] = true;
        }
        if (count($labels) === 2) {
            $a = (int) $maxIndex;
            $gap = abs(($n - 1) - $a) * $band;
            $need = (self::textWidth($format($values[$a])) + self::textWidth($format($values[$n - 1]))) / 2 + 6;
            if ($gap < $need) {
                unset($labels[$n - 1]);
            }
        }
        $svg .= '<g aria-hidden="true">';
        foreach (array_keys($labels) as $i) {
            $cx = round($left + $band * ($i + .5), 1);
            $anchor = 'middle';
            if ($cx + self::textWidth($format($values[$i])) / 2 > $width) {
                $anchor = 'end';
                $cx = $width - 1;
            }
            $svg .= '<text class="chart-label" x="' . $cx . '" y="' . round($y($values[$i]) - 7, 1) . '" text-anchor="' . $anchor . '">' . self::esc($format($values[$i])) . '</text>';
        }
        $svg .= '</g>';
        return $svg . '</svg>';
    }

    /** Column with a 4px rounded top (data end) and a square base. */
    private static function columnPath(float $x, float $top, float $w, float $base): string
    {
        $h = $base - $top;
        $r = min(4.0, $w / 2, max(0.0, $h));
        $f = static fn (float $v): string => (string) round($v, 2);
        return 'M' . $f($x) . ' ' . $f($base) . 'V' . $f($top + $r)
            . 'Q' . $f($x) . ' ' . $f($top) . ' ' . $f($x + $r) . ' ' . $f($top)
            . 'H' . $f($x + $w - $r)
            . 'Q' . $f($x + $w) . ' ' . $f($top) . ' ' . $f($x + $w) . ' ' . $f($top + $r)
            . 'V' . $f($base) . 'Z';
    }

    // ---------------------------------------------------------------- line

    private static function lineVariant(array $points, array $options, string $variant): string
    {
        [$width, $height] = self::VARIANTS[$variant];
        $format = self::formatter($options['format'] ?? null);
        $axisFormat = self::formatter($options['axisFormat'] ?? $options['format'] ?? null);
        $max = max(array_column($points, 'y'));
        [$top, $step] = self::niceScale($max);
        $tickLabels = [];
        for ($v = 0.0; $v <= $top + 1e-9; $v += $step) {
            $tickLabels[] = $axisFormat($v);
        }
        $left = (int) ceil(max(array_map(static fn (string $t): float => self::textWidth($t), $tickLabels)) + 10);
        $right = 14;
        $pTop = 28;
        $pBottom = 30;
        $plotW = $width - $left - $right;
        $plotH = $height - $pTop - $pBottom;

        // X domain: from Jan 1 of the first year to the last point (whole years read naturally).
        $firstYear = (int) gmdate('Y', $points[0]['ts']);
        $lastTs = $points[count($points) - 1]['ts'];
        $startTs = (int) gmmktime(0, 0, 0, 1, 1, $firstYear);
        $span = max(1, $lastTs - $startTs);
        $x = static fn (int $ts): float => $left + ($ts - $startTs) / $span * $plotW;
        $y = static fn (float $v): float => $pTop + $plotH - ($top > 0 ? $v / $top * $plotH : 0);

        [$id, $titleId, $descId] = self::ids($options, $variant, 'line');
        $svg = self::open($variant, $width, $height, $titleId, $descId, $options);

        $svg .= '<g aria-hidden="true">';
        foreach ($tickLabels as $i => $label) {
            $ty = round($y($i * $step), 1);
            $svg .= '<line class="' . ($i === 0 ? 'chart-axis' : 'chart-grid') . '" x1="' . $left . '" x2="' . ($width - $right) . '" y1="' . $ty . '" y2="' . $ty . '" stroke-width="1"/>';
            $svg .= '<text x="' . ($left - 8) . '" y="' . round($ty + 4.5, 1) . '" text-anchor="end">' . self::esc($label) . '</text>';
        }
        // Year ticks.
        $lastYear = (int) gmdate('Y', $lastTs);
        $years = max(1, $lastYear - $firstYear);
        $maxLabels = max(2, (int) floor($plotW / (self::textWidth('2000') + 18)));
        $yearStep = 10;
        foreach ([1, 2, 3, 4, 5, 10] as $candidate) {
            if ($years / $candidate <= $maxLabels) {
                $yearStep = $candidate;
                break;
            }
        }
        for ($year = $firstYear; $year <= $lastYear; $year++) {
            if ($year % $yearStep !== 0) {
                continue;
            }
            $tx = round($x((int) gmmktime(0, 0, 0, 1, 1, $year)), 1);
            if ($tx < $left - 0.5 || $tx > $width - $right + 0.5) {
                continue;
            }
            $svg .= '<line class="chart-axis" x1="' . $tx . '" x2="' . $tx . '" y1="' . ($pTop + $plotH) . '" y2="' . ($pTop + $plotH + 5) . '" stroke-width="1"/>';
            $svg .= '<text x="' . $tx . '" y="' . ($height - 9) . '" text-anchor="middle">' . $year . '</text>';
        }
        $svg .= '</g>';

        // Area wash + 2px line.
        $coords = array_map(static fn (array $p): array => [round($x($p['ts']), 2), round($y($p['y']), 2)], $points);
        $linePath = 'M' . implode('L', array_map(static fn (array $c): string => $c[0] . ' ' . $c[1], $coords));
        $base = round($y(0), 2);
        $area = $linePath . 'L' . $coords[count($coords) - 1][0] . ' ' . $base . 'L' . $coords[0][0] . ' ' . $base . 'Z';
        $svg .= '<path d="' . $area . '" fill="' . self::MARK . '" fill-opacity=".1" aria-hidden="true"/>';
        $svg .= '<path d="' . $linePath . '" fill="none" stroke="' . self::MARK . '" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" aria-hidden="true"/>';

        // Crosshair + hover dot (moved by the tooltip script), then nearest-point hit slices.
        $svg .= '<line class="st-cross" x1="0" x2="0" y1="' . $pTop . '" y2="' . ($pTop + $plotH) . '" stroke="' . self::CROSS . '" stroke-width="1" visibility="hidden" aria-hidden="true"/>';
        $svg .= '<g>';
        $count = count($coords);
        foreach ($coords as $i => [$cx, $cy]) {
            $x0 = $i === 0 ? $left : ($coords[$i - 1][0] + $cx) / 2;
            $x1 = $i === $count - 1 ? $width - $right : ($cx + $coords[$i + 1][0]) / 2;
            $label = Format::date($points[$i]['x'], 'j M Y') . ($points[$i]['tip'] !== '' ? ' · ' . $points[$i]['tip'] : '');
            $svg .= '<rect class="st-hit" x="' . round($x0, 2) . '" y="' . $pTop . '" width="' . round(max(1, $x1 - $x0), 2) . '" height="' . $plotH . '" fill="transparent"'
                . ' data-cx="' . $cx . '" data-cy="' . $cy . '" data-tip-v="' . self::esc($format($points[$i]['y'])) . '" data-tip-l="' . self::esc($label) . '"/>';
        }
        $svg .= '</g>';
        $svg .= '<circle class="st-hdot" cx="0" cy="0" r="5" fill="' . self::MARK . '" stroke="' . self::SURFACE . '" stroke-width="2" visibility="hidden" pointer-events="none" aria-hidden="true"/>';

        // Selective labels: peak + latest value, with ringed dots.
        $peak = 0;
        foreach ($points as $i => $p) {
            if ($p['y'] > $points[$peak]['y']) {
                $peak = $i;
            }
        }
        $last = $count - 1;
        $marks = [$peak => 'peak'];
        $peakText = $format($points[$peak]['y']);
        $lastText = $format($points[$last]['y']);
        if ($peak !== $last) {
            $dx = abs($coords[$last][0] - $coords[$peak][0]);
            $dy = abs($coords[$last][1] - $coords[$peak][1]);
            if ($dx > (self::textWidth($peakText) + self::textWidth($lastText)) / 2 + 8 || $dy > 22) {
                $marks[$last] = 'last';
            }
        }
        $svg .= '<g aria-hidden="true" pointer-events="none">';
        foreach ($marks as $i => $kind) {
            [$cx, $cy] = $coords[$i];
            $text = $kind === 'peak' ? $peakText : $lastText;
            $tw = self::textWidth($text);
            $anchor = 'middle';
            $tx = $cx;
            if ($cx + $tw / 2 > $width - 2) {
                $anchor = 'end';
                $tx = $width - 2;
            } elseif ($cx - $tw / 2 < $left) {
                $anchor = 'start';
                $tx = $left;
            }
            $svg .= '<circle cx="' . $cx . '" cy="' . $cy . '" r="4" fill="' . self::MARK . '" stroke="' . self::SURFACE . '" stroke-width="2"/>';
            $svg .= '<text class="chart-label" x="' . round($tx, 1) . '" y="' . round(max(14, $cy - 10), 1) . '" text-anchor="' . $anchor . '">' . self::esc($text) . '</text>';
        }
        $svg .= '</g>';
        return $svg . '</svg>';
    }

    // ---------------------------------------------------------------- shared

    private static function open(string $variant, int $width, int $height, string $titleId, string $descId, array $options): string
    {
        $title = (string) ($options['title'] ?? 'Grafik');
        $desc = (string) ($options['desc'] ?? '');
        return '<svg class="st st-' . $variant . '" viewBox="0 0 ' . $width . ' ' . $height . '" width="' . $width . '" height="' . $height . '"'
            . ' role="img" aria-labelledby="' . $titleId . ($desc !== '' ? ' ' . $descId : '') . '" xmlns="http://www.w3.org/2000/svg" focusable="false">'
            . '<title id="' . $titleId . '">' . self::esc($title) . '</title>'
            . ($desc !== '' ? '<desc id="' . $descId . '">' . self::esc($desc) . '</desc>' : '');
    }

    /** @return array{0: string, 1: string, 2: string} */
    private static function ids(array $options, string $variant, string $kind): array
    {
        static $counter = 0;
        $base = isset($options['id']) && preg_match('/^[a-z][a-z0-9-]*$/i', (string) $options['id']) === 1
            ? (string) $options['id']
            : 'chart-' . $kind . '-' . (++$counter);
        $id = $base . '-' . $variant;
        return [$id, $id . '-t', $id . '-d'];
    }

    /** @return list<string> */
    private static function variants(array $options): array
    {
        $wanted = $options['variants'] ?? array_keys(self::VARIANTS);
        return array_values(array_filter((array) $wanted, static fn ($v): bool => isset(self::VARIANTS[$v])));
    }

    private static function formatter(mixed $format): callable
    {
        return is_callable($format) ? $format : static fn (int|float $v): string => Format::number($v, fmod((float) $v, 1.0) === 0.0 ? 0 : 1);
    }

    private static function textWidth(string $text): float
    {
        return mb_strlen($text) * self::CHAR_W;
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_XML1, 'UTF-8');
    }
}
