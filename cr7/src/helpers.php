<?php
/**
 * Global helper functions (thin shortcuts over App\Core and App\Support).
 */

declare(strict_types=1);

use App\Core\App;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\HttpException;
use App\Core\Response;
use App\Core\Settings;
use App\Core\View;
use App\Support\Format;

/** HTML-escape any scalar for output (null/arrays => ''). */
function e(mixed $value): string
{
    if ($value === null || is_array($value) || (is_object($value) && !$value instanceof Stringable)) {
        return '';
    }
    if (is_bool($value)) {
        $value = $value ? '1' : '';
    }
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
}

/** Absolute URL for an app path ('/haberler' => 'https://cr7.com.tr/haberler'); absolute URLs pass through. */
function url(string $path = '/'): string
{
    if (preg_match('#^https?://#i', $path) === 1) {
        return $path;
    }
    return rtrim((string) Config::get('app.url', ''), '/') . '/' . ltrim($path, '/');
}

/** Root-relative, cache-busted URL of a file in public/assets ('css/app.css' => '/assets/css/app.css?v=1729...'). */
function asset(string $path): string
{
    static $versions = [];
    $path = ltrim($path, '/');
    if (str_starts_with($path, 'assets/')) {
        $path = substr($path, 7);
    }
    if (!array_key_exists($path, $versions)) {
        $file = App::basePath('public/assets/' . $path);
        $versions[$path] = is_file($file) ? (string) filemtime($file) : '';
    }
    return App::urlPrefix() . '/assets/' . $path . ($versions[$path] !== '' ? '?v=' . $versions[$path] : '');
}

/** Static configuration value (dot notation). */
function config(string $key, mixed $default = null): mixed
{
    return Config::get($key, $default);
}

/** Admin-editable setting with fallback to config (dot notation). */
function setting(string $key, mixed $default = null): mixed
{
    return Settings::get($key, $default);
}

/** Hidden CSRF input for forms. */
function csrf_field(): string
{
    return Csrf::field();
}

/** Turkish number format: 1234 => '1.234'. */
function fmt_number(int|float|null $n, int $decimals = 0): string
{
    return Format::number($n, $decimals);
}

/** Turkish date from a UTC datetime string (displayed in Europe/Istanbul). */
function fmt_date(?string $utc, string $pattern = 'j F Y'): string
{
    return Format::date($utc, $pattern);
}

/** Relative Turkish time: '3 saat önce'. */
function fmt_relative(?string $utc): string
{
    return Format::relative($utc);
}

/** Market value in euros: 120000000 => '120 mil. €'. */
function fmt_money(?int $eur): string
{
    return Format::money($eur);
}

/** Shop price in Turkish lira: 1299.9 => '1.299,90 TL'. */
function fmt_price(int|float|string|null $amount): string
{
    return Format::price($amount);
}

/** Render a template inside the main layout and wrap it in an HTML response. */
function view(string $tpl, array $data = [], int $status = 200): Response
{
    return Response::html(View::render($tpl, $data), $status);
}

/** Render a template without layout. */
function partial(string $tpl, array $data = []): string
{
    return View::partial($tpl, $data);
}

/** Redirect response shortcut. */
function redirect(string $url, int $status = 302): Response
{
    return Response::redirect($url, $status);
}

/** Abort the current request with an HTTP error page (404, 403, 419, ...). */
function abort(int $status, string $message = ''): never
{
    throw new HttpException($status, $message);
}

/** URL of page N of a paginated list: page 1 => base URL, otherwise adds ?sayfa=N (keeps existing query). */
function page_url(string $baseUrl, int $page, string $param = 'sayfa'): string
{
    $parts = explode('#', $baseUrl, 2);
    $base = $parts[0];
    $query = [];
    if (str_contains($base, '?')) {
        [$base, $qs] = explode('?', $base, 2);
        parse_str($qs, $query);
    }
    unset($query[$param]);
    if ($page > 1) {
        $query[$param] = $page;
    }
    $qs = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    return $base . ($qs !== '' ? '?' . $qs : '') . (isset($parts[1]) ? '#' . $parts[1] : '');
}
