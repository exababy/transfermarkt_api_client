<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\App;

/**
 * JSON-lines logger: storage/logs/YYYY-MM-DD.log (UTC dates), one JSON object per line:
 * {"ts":"…","level":"info","channel":"news","message":"…","context":{…}}. Never throws.
 * Files older than 30 days are pruned when a new day's file is started.
 */
final class Log
{
    private const KEEP_DAYS = 30;

    public static function info(string $channel, string $message, array $context = []): void
    {
        self::write('info', $channel, $message, $context);
    }

    public static function warning(string $channel, string $message, array $context = []): void
    {
        self::write('warning', $channel, $message, $context);
    }

    public static function error(string $channel, string $message, array $context = []): void
    {
        self::write('error', $channel, $message, $context);
    }

    /** @return list<array{ts: string, level: string, channel: string, message: string, context: array}> newest first */
    public static function tail(int $lines = 100): array
    {
        $files = glob(self::dir() . '/[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9].log') ?: [];
        rsort($files);
        $out = [];
        foreach ($files as $file) {
            foreach (self::reverseLines($file) as $line) {
                $entry = json_decode($line, true);
                if (is_array($entry)) {
                    $out[] = $entry + ['ts' => '', 'level' => '', 'channel' => '', 'message' => '', 'context' => []];
                    if (count($out) >= $lines) {
                        return $out;
                    }
                }
            }
        }
        return $out;
    }

    private static function write(string $level, string $channel, string $message, array $context): void
    {
        try {
            $dir = self::dir();
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            $file = $dir . '/' . gmdate('Y-m-d') . '.log';
            $isNew = !is_file($file);
            $line = json_encode([
                'ts' => gmdate('Y-m-d\TH:i:s\Z'),
                'level' => $level,
                'channel' => $channel,
                'message' => $message,
                'context' => self::normalize($context),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            if (@file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX) === false) {
                error_log("[{$level}] {$channel}: {$message}");
                return;
            }
            if ($isNew) {
                self::prune($dir);
            }
        } catch (\Throwable) {
            // Logging must never break the request.
        }
    }

    private static function normalize(array $context): array
    {
        foreach ($context as $key => $value) {
            if ($value instanceof \Throwable) {
                $context[$key] = [
                    'class' => get_class($value),
                    'message' => $value->getMessage(),
                    'file' => $value->getFile() . ':' . $value->getLine(),
                    'trace' => array_slice(explode("\n", $value->getTraceAsString()), 0, 12),
                ];
            } elseif (is_array($value)) {
                $context[$key] = self::normalize($value);
            } elseif (is_object($value)) {
                $context[$key] = $value instanceof \Stringable ? (string) $value : get_class($value);
            } elseif (is_resource($value)) {
                $context[$key] = 'resource';
            }
        }
        return $context;
    }

    private static function prune(string $dir): void
    {
        $limit = gmdate('Y-m-d', time() - self::KEEP_DAYS * 86400);
        foreach (glob($dir . '/[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9].log') ?: [] as $file) {
            if (basename($file, '.log') < $limit) {
                @unlink($file);
            }
        }
    }

    /** @return \Generator<string> lines of $file from last to first, read in chunks */
    private static function reverseLines(string $file): \Generator
    {
        $handle = @fopen($file, 'rb');
        if ($handle === false) {
            return;
        }
        try {
            fseek($handle, 0, SEEK_END);
            $position = ftell($handle);
            $buffer = '';
            while ($position > 0) {
                $read = min(8192, $position);
                $position -= $read;
                fseek($handle, $position);
                $buffer = fread($handle, $read) . $buffer;
                $parts = explode("\n", $buffer);
                $buffer = array_shift($parts);
                for ($i = count($parts) - 1; $i >= 0; $i--) {
                    if (trim($parts[$i]) !== '') {
                        yield $parts[$i];
                    }
                }
            }
            if (trim($buffer) !== '') {
                yield $buffer;
            }
        } finally {
            fclose($handle);
        }
    }

    private static function dir(): string
    {
        return App::basePath('storage/logs');
    }
}
