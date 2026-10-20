<?php
/**
 * Front controller (document root: cr7/public).
 * Under `php -S host:port -t public public/index.php` existing static files are served directly.
 */

declare(strict_types=1);

if (PHP_SAPI === 'cli-server') {
    $path = rawurldecode((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH));
    $file = realpath(__DIR__ . $path);
    if ($path !== '/' && $file !== false && is_file($file) && str_starts_with($file, __DIR__ . DIRECTORY_SEPARATOR)
        && !str_starts_with(basename($file), '.') && $file !== __FILE__) {
        return false;
    }
}

require dirname(__DIR__) . '/src/bootstrap.php';

App\Core\App::run();
