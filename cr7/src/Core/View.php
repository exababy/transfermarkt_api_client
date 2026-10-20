<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Plain-PHP template renderer. Templates live in templates/{name}.php and always escape with e().
 *
 * render('pages/home', $data) renders the page, then the layout with $content (string) + all $data.
 * Shared data (View::share) is available everywhere: $cspNonce, $currentPath.
 */
final class View
{
    /** @var array<string, mixed> */
    private static array $shared = [];

    public static function render(string $template, array $data = [], ?string $layout = 'layouts/main'): string
    {
        $data = array_replace(self::$shared, $data);
        $content = self::renderFile(self::resolve($template), $data);
        if ($layout === null) {
            return $content;
        }
        return self::renderFile(self::resolve($layout), ['content' => $content] + $data);
    }

    /** Render a template without layout (partials). */
    public static function partial(string $template, array $data = []): string
    {
        return self::renderFile(self::resolve($template), array_replace(self::$shared, $data));
    }

    /** Make a value available to every template. */
    public static function share(string $key, mixed $value): void
    {
        self::$shared[$key] = $value;
    }

    private static function resolve(string $template): string
    {
        if (preg_match('#^[a-z0-9_/-]+$#i', $template) !== 1 || str_contains($template, '..')) {
            throw new \InvalidArgumentException("Invalid template name: {$template}");
        }
        $file = App::basePath('templates/' . $template . '.php');
        if (!is_file($file)) {
            throw new \RuntimeException("Template not found: {$template}");
        }
        return $file;
    }

    private static function renderFile(string $__file, array $__data): string
    {
        $level = ob_get_level();
        ob_start();
        try {
            (static function (string $__file, array $__data): void {
                extract($__data, EXTR_SKIP);
                require $__file;
            })($__file, $__data);
            return (string) ob_get_clean();
        } catch (\Throwable $e) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            throw $e;
        }
    }
}
