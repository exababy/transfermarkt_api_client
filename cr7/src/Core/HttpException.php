<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Thrown to abort a request with an HTTP error status; App::run() renders the matching error page.
 * Use the abort() helper: abort(404), abort(419, 'Oturum süresi doldu').
 */
final class HttpException extends \RuntimeException
{
    /** @param array<string, string> $headers */
    public function __construct(private int $status, string $message = '', private array $headers = [])
    {
        parent::__construct($message, $status);
    }

    public function status(): int
    {
        return $this->status;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }
}
