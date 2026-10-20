<?php

declare(strict_types=1);

namespace App\Core;

use App\Controllers\ErrorController;
use App\Support\Log;

/**
 * Front-controller kernel: captures the request, loads routes.php, dispatches and sends.
 */
final class App
{
    private static ?Router $router = null;
    private static ?Request $request = null;
    /** @var array{0: string, 1: string}|null [app.url, prefix] memo */
    private static ?array $urlPrefix = null;
    private static ?string $storagePath = null;

    public static function run(): void
    {
        $request = Request::capture();
        self::$request = $request;
        View::share('cspNonce', Response::nonce());
        View::share('currentPath', $request->path());

        try {
            $response = self::router()->dispatch($request);
        } catch (HttpException $e) {
            $response = ErrorController::render($e->status(), $e->getMessage());
            foreach ($e->headers() as $name => $value) {
                $response = $response->withHeader($name, $value);
            }
        } catch (\Throwable $e) {
            Log::error('app', get_class($e) . ': ' . $e->getMessage(), [
                'exception' => $e,
                'method' => $request->method(),
                'path' => $request->path(),
            ]);
            $response = ErrorController::render(500, '', $e);
        }

        $response = self::prefixHtml($response)->conditional($request);
        if ($request->method() === 'HEAD') {
            $response->sendHeaders();
            return;
        }
        $response->send();
    }

    /**
     * Sub-folder installs (app.url = https://host/cr7): root-relative links in HTML responses
     * ("/hayati" in trusted content files, stored news bodies, partials) get the folder prefix.
     * Root installs (prefix '') are returned unchanged.
     */
    public static function prefixHtml(Response $response): Response
    {
        $prefix = self::urlPrefix();
        if ($prefix === '' || !str_starts_with(strtolower((string) $response->header('Content-Type')), 'text/html')) {
            return $response;
        }
        $fix = static fn (string $path): string => ($path === $prefix || str_starts_with($path, $prefix . '/') || str_starts_with($path, $prefix . '?') || str_starts_with($path, $prefix . '#'))
            ? $path : $prefix . $path;
        $html = (string) preg_replace_callback(
            '~(\s(?:href|src|action|poster|data-gallery)=")(/(?!/)[^"]*)"~',
            static fn (array $m): string => $m[1] . $fix($m[2]) . '"',
            $response->body()
        );
        $html = (string) preg_replace_callback(
            '~(\ssrcset=")([^"]*)"~',
            static fn (array $m): string => $m[1] . implode(', ', array_map(
                static fn (string $part): string => str_starts_with($part = trim($part), '/') && !str_starts_with($part, '//') ? $fix($part) : $part,
                explode(',', $m[2])
            )) . '"',
            $html
        );
        return $response->withBody($html);
    }

    /** Router with routes.php loaded. */
    public static function router(): Router
    {
        if (self::$router === null) {
            $router = new Router();
            $register = require self::basePath('routes.php');
            $register($router);
            self::$router = $router;
        }
        return self::$router;
    }

    /** Current request (null in CLI). */
    public static function request(): ?Request
    {
        return self::$request;
    }

    /** Swap the current request (tests). */
    public static function setRequest(?Request $request): void
    {
        self::$request = $request;
    }

    /** Absolute filesystem path inside cr7/ ('storage/logs' => /…/cr7/storage/logs). */
    public static function basePath(string $p = ''): string
    {
        $root = dirname(__DIR__, 2);
        return $p === '' ? $root : $root . '/' . ltrim($p, '/');
    }

    /**
     * Absolute path inside the runtime storage directory (logs, cache, locks):
     * storagePath('logs') => /…/cr7/storage/logs. Tests point it at a temporary directory.
     */
    public static function storagePath(string $p = ''): string
    {
        $root = self::$storagePath ?? self::basePath('storage');
        return $p === '' ? $root : $root . '/' . ltrim($p, '/');
    }

    /** Redirect logs/cache/locks to another directory (null restores storage/). Used by bin/test.php. */
    public static function useStoragePath(?string $path): void
    {
        self::$storagePath = $path !== null ? rtrim($path, '/') : null;
    }

    /** Path part of app.url without trailing slash ('' for root installs, '/cr7' for sub-folder installs). */
    public static function urlPrefix(): string
    {
        $url = (string) Config::get('app.url', '');
        if (self::$urlPrefix === null || self::$urlPrefix[0] !== $url) {
            $path = parse_url($url, PHP_URL_PATH);
            self::$urlPrefix = [$url, is_string($path) ? rtrim($path, '/') : ''];
        }
        return self::$urlPrefix[1];
    }
}
