<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Response;
use App\Core\Seo;
use App\Core\View;
use App\Support\Log;

/**
 * Error pages (noindex): 404 => errors/404, >= 500 => errors/500, others => errors/error.
 */
final class ErrorController
{
    private const TITLES = [
        400 => 'Geçersiz istek',
        403 => 'Erişim engellendi',
        404 => 'Sayfa bulunamadı',
        405 => 'Bu işleme izin verilmiyor',
        419 => 'Oturum süresi doldu',
        422 => 'Gönderilen bilgiler geçersiz',
        429 => 'Çok fazla istek',
        500 => 'Sunucu hatası',
        503 => 'Bakım çalışması',
    ];

    public static function render(int $status, string $message = '', ?\Throwable $error = null): Response
    {
        $title = self::TITLES[$status] ?? ($status >= 500 ? self::TITLES[500] : 'Bir sorun oluştu');
        $template = match (true) {
            $status === 404 => 'errors/404',
            $status >= 500 => 'errors/500',
            default => 'errors/error',
        };
        try {
            $html = View::render($template, [
                'seo' => new Seo(title: $title, robots: 'noindex,follow'),
                'status' => $status,
                'title' => $title,
                'message' => $message,
                'error' => Config::get('app.debug', false) ? $error : null,
                'errorPage' => true,
            ]);
        } catch (\Throwable $e) {
            Log::error('app', 'Error page rendering failed: ' . $e->getMessage(), ['exception' => $e]);
            $html = '<!doctype html><html lang="tr"><meta charset="utf-8"><meta name="robots" content="noindex">'
                . '<title>' . e($title) . '</title><h1>' . e($title) . '</h1><p>Lütfen daha sonra tekrar deneyin.</p></html>';
        }
        return new Response($html, $status, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store, private']);
    }
}
