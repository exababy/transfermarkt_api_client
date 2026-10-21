<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Core\Seo;
use App\Core\View;

/**
 * "Bu bölüm hazırlanıyor" pages used by stub controllers until their module is built.
 * Placeholder pages are noindex so unfinished sections never reach search results.
 */
final class Placeholder
{
    /**
     * @param list<array{name: string, url?: string}>|null $breadcrumbs null => [current page]; [] => none (home)
     */
    public static function page(string $title, string $activeNav = '', ?array $breadcrumbs = null): Response
    {
        $seo = new Seo(
            title: $title,
            description: $title . ' bölümü CR7.com.tr üzerinde çok yakında yayında. Cristiano Ronaldo hakkında her şey burada.',
            robots: 'noindex,follow',
            breadcrumbs: $breadcrumbs ?? [['name' => $title]],
        );
        return Response::html(View::render('pages/coming-soon', [
            'seo' => $seo,
            'title' => $title,
            'activeNav' => $activeNav,
        ]));
    }

    /** Admin variant rendered inside layouts/admin ($bare: no sidebar, e.g. login). */
    public static function admin(string $title, bool $bare = false): Response
    {
        return Response::html(View::render('pages/coming-soon', ['title' => $title, 'admin' => true, 'bare' => $bare], 'layouts/admin'));
    }
}
