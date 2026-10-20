<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Csrf;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Seo;
use App\Core\View;
use App\Support\Date;

/**
 * Shared helpers of the admin controllers: page rendering inside layouts/admin (noindex),
 * safe "back" redirects, pagination maths, LIKE patterns and Istanbul <-> UTC conversion
 * for <input type="datetime-local">.
 */
final class AdminPage
{
    /**
     * Render templates/admin/{$template}.php in the admin layout.
     *
     * @param list<array{name: string, url?: string}> $crumbs visual breadcrumb trail after "Panel"
     */
    public static function render(string $template, string $title, array $data = [], int $status = 200, array $crumbs = []): Response
    {
        $seo = new Seo(title: $title, description: $title . ' · CR7.com.tr yönetim paneli', robots: 'noindex,nofollow', breadcrumbs: $crumbs);
        $html = View::render('admin/' . $template, $data + ['title' => $title, 'seo' => $seo, 'crumbs' => $crumbs], 'layouts/admin');
        return Response::html($html, $status)->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    /** Csrf::check() that returns false instead of throwing (forms that re-render with a message). */
    public static function csrfValid(Request $request): bool
    {
        try {
            Csrf::check($request);
            return true;
        } catch (HttpException) {
            return false;
        }
    }

    /** Redirect to the posted `_back` admin path (when safe) or $default. */
    public static function back(Request $request, string $default = '/admin'): Response
    {
        return Response::redirect(url(self::safePath($request->input('_back'), $default)));
    }

    /** An /admin… path from user input (no scheme/host, no CR/LF), else $default. */
    public static function safePath(mixed $path, string $default = '/admin'): string
    {
        if (!is_string($path) || $path === '' || preg_match('#^/admin(?:[/?][^\s\\\\]*)?$#', $path) !== 1 || str_contains($path, '//')) {
            return $default;
        }
        return $path;
    }

    /** Current admin path incl. query (for `_back` fields). */
    public static function currentPath(Request $request): string
    {
        $qs = $request->queryString();
        return $request->path() . ($qs !== '' ? '?' . $qs : '');
    }

    /** Page number from ?sayfa (>= 1). */
    public static function page(Request $request): int
    {
        $page = $request->query('sayfa', '1');
        return is_string($page) && ctype_digit($page) ? max(1, (int) $page) : 1;
    }

    /** @return array{page: int, pages: int, offset: int, total: int, perPage: int} */
    public static function paginate(int $total, int $perPage, int $page): array
    {
        $pages = max(1, (int) ceil($total / max(1, $perPage)));
        $page = min(max(1, $page), $pages);
        return ['page' => $page, 'pages' => $pages, 'offset' => ($page - 1) * $perPage, 'total' => $total, 'perPage' => $perPage];
    }

    /** '%term%' for `LIKE ? ESCAPE '\'`. */
    public static function like(string $term): string
    {
        return '%' . Database::likeEscape($term) . '%';
    }

    /** Trimmed string query parameter ('' when missing or not a string). */
    public static function queryString(Request $request, string $key, int $max = 200): string
    {
        $value = $request->query($key, '');
        return is_string($value) ? mb_substr(trim($value), 0, $max) : '';
    }

    /** Trimmed string POST field ('' when missing or not a string). */
    public static function input(Request $request, string $key): string
    {
        $value = $request->input($key, '');
        return is_string($value) ? trim(str_replace("\0", '', $value)) : '';
    }

    /** Checkbox POST field. */
    public static function checked(Request $request, string $key): bool
    {
        $value = $request->input($key);
        return is_string($value) && $value !== '' && $value !== '0';
    }

    /** UTC 'Y-m-d H:i:s' => Istanbul 'Y-m-d\TH:i' for datetime-local inputs ('' for null). */
    public static function toLocalInput(?string $utc): string
    {
        if ($utc === null || trim($utc) === '') {
            return '';
        }
        try {
            return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->setTimezone(Date::timezone())->format('Y-m-d\TH:i');
        } catch (\Exception) {
            return '';
        }
    }

    /** Istanbul datetime-local value ('2026-10-20T14:30') => UTC 'Y-m-d H:i:s', null when invalid. */
    public static function fromLocalInput(string $value): ?string
    {
        $value = trim($value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(?::\d{2})?$/', $value) !== 1) {
            return null;
        }
        $value = str_replace(' ', 'T', $value);
        $format = strlen($value) === 16 ? 'Y-m-d\TH:i' : 'Y-m-d\TH:i:s';
        $date = \DateTimeImmutable::createFromFormat('!' . $format, $value, Date::timezone());
        if ($date === false || $date->format($format) !== $value) {
            return null;
        }
        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** "••••abcd" — last 4 characters of a secret (nothing for short values). */
    public static function mask(?string $secret): string
    {
        $secret = (string) $secret;
        if ($secret === '') {
            return '';
        }
        return str_repeat('•', 8) . (strlen($secret) >= 12 ? substr($secret, -4) : '');
    }
}
