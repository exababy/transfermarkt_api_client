<?php

declare(strict_types=1);

namespace Tests;

/**
 * Base class for tests run by bin/test.php. Each test method gets a fresh object and a
 * fresh in-memory SQLite database with the full schema (Database::pdo()).
 */
abstract class TestCase
{
    public int $assertions = 0;

    /** Runs before each test method. */
    protected function setUp(): void
    {
    }

    /** Runs after each test method. */
    protected function tearDown(): void
    {
    }

    protected function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->assertions++;
        if ($expected !== $actual) {
            $this->fail(($message !== '' ? $message . ': ' : '') . 'expected ' . self::export($expected) . ', got ' . self::export($actual));
        }
    }

    protected function assertTrue(bool $condition, string $message = 'expected true'): void
    {
        $this->assertions++;
        if (!$condition) {
            $this->fail($message);
        }
    }

    protected function assertFalse(bool $condition, string $message = 'expected false'): void
    {
        $this->assertTrue(!$condition, $message);
    }

    protected function assertNull(mixed $value, string $message = ''): void
    {
        $this->assertSame(null, $value, $message);
    }

    /** $haystack: string (substring) or array (strict element). */
    protected function assertContains(mixed $needle, string|array $haystack, string $message = ''): void
    {
        $this->assertions++;
        $found = is_string($haystack) ? str_contains($haystack, (string) $needle) : in_array($needle, $haystack, true);
        if (!$found) {
            $this->fail(($message !== '' ? $message . ': ' : '') . self::export($needle) . ' not found in ' . self::export($haystack));
        }
    }

    protected function assertNotContains(mixed $needle, string|array $haystack, string $message = ''): void
    {
        $this->assertions++;
        $found = is_string($haystack) ? str_contains($haystack, (string) $needle) : in_array($needle, $haystack, true);
        if ($found) {
            $this->fail(($message !== '' ? $message . ': ' : '') . self::export($needle) . ' unexpectedly found in ' . self::export($haystack));
        }
    }

    protected function assertCount(int $expected, array|\Countable $value, string $message = ''): void
    {
        $this->assertSame($expected, count($value), $message !== '' ? $message : 'count');
    }

    /** @param class-string<\Throwable> $class */
    protected function assertThrows(string $class, callable $fn, string $message = ''): void
    {
        $this->assertions++;
        try {
            $fn();
        } catch (\Throwable $e) {
            if ($e instanceof $class) {
                return;
            }
            $this->fail(($message !== '' ? $message . ': ' : '') . "expected {$class}, got " . get_class($e) . ': ' . $e->getMessage());
        }
        $this->fail(($message !== '' ? $message . ': ' : '') . "expected {$class} to be thrown");
    }

    protected function fail(string $message): never
    {
        throw new AssertionFailed($message);
    }

    private static function export(mixed $value): string
    {
        $out = var_export($value, true);
        return strlen($out) > 300 ? substr($out, 0, 300) . '…' : $out;
    }
}

final class AssertionFailed extends \RuntimeException
{
}
