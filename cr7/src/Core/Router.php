<?php

declare(strict_types=1);

namespace App\Core;

use App\Controllers\ErrorController;

/**
 * Minimal router: GET/POST routes with typed {params}, HEAD handled as GET,
 * 301 from trailing-slash / double-slash paths, 405 with Allow header.
 *
 * CSRF is not enforced globally: controllers call Csrf::check($request) on their
 * own POST handlers, so payment gateway callbacks (/odeme/* /bildirim|donus) are
 * exempt by design and verify gateway signatures instead.
 */
final class Router
{
    /** Parameter name => regex. Unknown names match one path segment. */
    private const PARAM_PATTERNS = [
        'id' => '\d+',
        'slug' => '[a-z0-9-]+',
        'task' => '[a-z0-9-]+',
        'token' => '[A-Za-z0-9_-]+',
        'type' => '[a-z]+',
        'key' => '[A-Za-z0-9-]+',
    ];

    /** @var list<array{method: string, pattern: string, regex: string, handler: array|callable}> */
    private array $routes = [];

    public function get(string $pattern, array|callable $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, array|callable $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    /** @return list<array{method: string, pattern: string, handler: array|callable}> registered routes */
    public function routes(): array
    {
        return array_map(static fn (array $r): array => ['method' => $r['method'], 'pattern' => $r['pattern'], 'handler' => $r['handler']], $this->routes);
    }

    public function dispatch(Request $request): Response
    {
        $method = $request->method() === 'HEAD' ? 'GET' : $request->method();
        $path = $request->path();

        // No route has a backslash or control character; echoing them into the redirect below would make
        // "/%5Chost/" or "/%09/host/" an off-site redirect (browsers read "/\host" and "/<TAB>/host" as "//host").
        if (preg_match('#[\\\\\x00-\x1F\x7F]#', $request->rawPath()) === 1) {
            return Response::notFound();
        }

        if ($method === 'GET' && $request->rawPath() !== $path) {
            $query = $request->queryString();
            return Response::redirect(App::urlPrefix() . $path . ($query !== '' ? '?' . $query : ''), 301);
        }

        $allowed = [];
        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $path, $matches) !== 1) {
                continue;
            }
            if ($route['method'] !== $method) {
                $allowed[] = $route['method'];
                continue;
            }
            $request->setParams(array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY));
            return $this->invoke($route['handler'], $request);
        }

        if ($allowed !== []) {
            if (in_array('GET', $allowed, true)) {
                $allowed[] = 'HEAD';
            }
            return ErrorController::render(405)->withHeader('Allow', implode(', ', array_unique($allowed)));
        }
        return Response::notFound();
    }

    private function add(string $method, string $pattern, array|callable $handler): void
    {
        $this->routes[] = [
            'method' => $method,
            'pattern' => $pattern,
            'regex' => $this->compile($pattern),
            'handler' => $handler,
        ];
    }

    private function compile(string $pattern): string
    {
        $regex = preg_replace_callback(
            '/\{([a-z_][a-z0-9_]*)\}|[^{]+/i',
            static function (array $m): string {
                if (($m[1] ?? '') !== '') {
                    return '(?P<' . $m[1] . '>' . (self::PARAM_PATTERNS[$m[1]] ?? '[^/]+') . ')';
                }
                return preg_quote($m[0], '#');
            },
            $pattern
        );
        return '#^' . $regex . '$#';
    }

    private function invoke(array|callable $handler, Request $request): Response
    {
        if (is_array($handler) && count($handler) === 2 && is_string($handler[0])) {
            [$class, $method] = $handler;
            $result = (new $class())->{$method}($request);
        } else {
            $result = $handler($request);
        }
        if (is_string($result)) {
            return Response::html($result);
        }
        if (!$result instanceof Response) {
            throw new \UnexpectedValueException('Route handler must return a Response.');
        }
        return $result;
    }
}
