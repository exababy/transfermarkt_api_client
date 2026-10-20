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
    private static ?string $urlPrefix = null;

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

        if ($request->method() === 'HEAD') {
            $response->sendHeaders();
            return;
        }
        $response->send();
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

    /** Path part of app.url without trailing slash ('' for root installs, '/cr7' for sub-folder installs). */
    public static function urlPrefix(): string
    {
        if (self::$urlPrefix === null) {
            $path = parse_url((string) Config::get('app.url', ''), PHP_URL_PATH);
            self::$urlPrefix = is_string($path) ? rtrim($path, '/') : '';
        }
        return self::$urlPrefix;
    }
}
