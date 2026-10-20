<?php
/**
 * Application bootstrap: autoloading, configuration, error handling, runtime defaults.
 *
 * Included by public/index.php, bin/*.php and the test runner. Sessions are NOT
 * started here; App\Core\Session::start() starts one lazily (with secure cookie
 * settings) only when a feature needs it (CSRF, admin auth).
 */

declare(strict_types=1);

use App\Core\Config;
use App\Support\Log;

if (defined('CR7_BOOTSTRAPPED')) {
    return;
}
define('CR7_BOOTSTRAPPED', true);

(static function (): void {
    $root = dirname(__DIR__);

    // PSR-4 autoloader for App\ => src/ (no Composer dependency required).
    spl_autoload_register(static function (string $class): void {
        if (!str_starts_with($class, 'App\\')) {
            return;
        }
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    });
    if (is_file($root . '/vendor/autoload.php')) {
        require_once $root . '/vendor/autoload.php';
    }
    require_once __DIR__ . '/helpers.php';

    $configFile = is_file($root . '/config/config.php') ? $root . '/config/config.php' : $root . '/config/config.example.php';
    Config::load($configFile);
    Config::applyEnvironment();

    // All stored DATETIME values are UTC; display conversion happens in App\Support\Format.
    date_default_timezone_set('UTC');
    mb_internal_encoding('UTF-8');

    $debug = (bool) Config::get('app.debug', false);
    error_reporting(E_ALL);
    ini_set('display_errors', $debug ? '1' : '0');
    ini_set('log_errors', '1');
    if (is_dir($root . '/storage/logs') && is_writable($root . '/storage/logs')) {
        ini_set('error_log', $root . '/storage/logs/php-error.log');
    }

    // Debug: every warning/notice becomes an exception so bugs surface early.
    // Production: warnings are logged and execution continues.
    set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0) use ($debug): bool {
        if (!(error_reporting() & $severity)) {
            return false; // silenced with @
        }
        $deprecation = in_array($severity, [E_DEPRECATED, E_USER_DEPRECATED], true);
        if ($debug && !$deprecation) {
            throw new ErrorException($message, 0, $severity, $file, $line);
        }
        Log::error('php', $message, ['file' => $file, 'line' => $line, 'severity' => $severity]);
        return true;
    });

    set_exception_handler(static function (Throwable $e) use ($debug): void {
        Log::error('php', 'Uncaught ' . get_class($e) . ': ' . $e->getMessage(), ['exception' => $e]);
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . "\n" . ($debug ? $e->getTraceAsString() . "\n" : ''));
            exit(1);
        }
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=utf-8');
        }
        echo '<!doctype html><html lang="tr"><meta charset="utf-8"><title>Sunucu hatası</title>'
            . '<h1>Beklenmeyen bir hata oluştu</h1><p>Lütfen daha sonra tekrar deneyin.</p></html>';
    });

    register_shutdown_function(static function (): void {
        $error = error_get_last();
        if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            return;
        }
        Log::error('php', 'Fatal: ' . $error['message'], ['file' => $error['file'], 'line' => $error['line']]);
        if (PHP_SAPI !== 'cli' && !headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=utf-8');
            echo '<!doctype html><html lang="tr"><meta charset="utf-8"><title>Sunucu hatası</title>'
                . '<h1>Beklenmeyen bir hata oluştu</h1><p>Lütfen daha sonra tekrar deneyin.</p></html>';
        }
    });
})();
