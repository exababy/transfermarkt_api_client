<?php

declare(strict_types=1);

namespace App\Services\News;

use App\Core\App;
use App\Core\Config;
use App\Core\Settings;

/**
 * News source list: defaults from config/news-sources.php, merged with config 'news.sources'
 * (same shape, may override a default by key), minus the keys listed in the admin setting
 * 'news.sources_disabled'.
 */
final class SourceRegistry
{
    private const TYPES = ['rss', 'atom', 'bing', 'google'];

    /**
     * Enabled sources.
     *
     * @return array<string, array{name: string, type: string, url: string, filter: bool, text: bool}>
     */
    public static function all(): array
    {
        $disabled = self::disabledKeys();
        return array_filter(self::defined(), static fn (string $key): bool => !in_array($key, $disabled, true), ARRAY_FILTER_USE_KEY);
    }

    /**
     * Every defined source (also disabled ones), normalised.
     *
     * @return array<string, array{name: string, type: string, url: string, filter: bool, text: bool}>
     */
    public static function defined(): array
    {
        $file = App::basePath('config/news-sources.php');
        $defaults = is_file($file) ? require $file : [];
        $extra = Config::get('news.sources', []);
        $merged = array_replace(is_array($defaults) ? $defaults : [], is_array($extra) ? $extra : []);

        $sources = [];
        foreach ($merged as $key => $source) {
            if (!is_string($key) || preg_match('/^[a-z0-9_-]{1,60}$/', $key) !== 1 || !is_array($source)) {
                continue;
            }
            $url = trim((string) ($source['url'] ?? ''));
            $type = strtolower((string) ($source['type'] ?? 'rss'));
            if (preg_match('#^https?://#i', $url) !== 1 || !in_array($type, self::TYPES, true)) {
                continue;
            }
            $sources[$key] = [
                'name' => trim((string) ($source['name'] ?? $key)) ?: $key,
                'type' => $type,
                'url' => $url,
                'filter' => (bool) ($source['filter'] ?? ($type === 'rss' || $type === 'atom')),
                'text' => (bool) ($source['text'] ?? ($type !== 'google')),
            ];
        }
        return $sources;
    }

    /** One source by key (also when disabled), or null. */
    public static function get(string $key): ?array
    {
        return self::defined()[$key] ?? null;
    }

    /** True when items of this source key can be fetched for article text (not Google redirects). */
    public static function hasText(string $key): bool
    {
        $source = self::get($key);
        return $source === null ? $key !== 'google' : $source['text'];
    }

    /** @return list<string> */
    public static function disabledKeys(): array
    {
        $value = Settings::get('news.sources_disabled', []);
        if (is_string($value)) {
            $value = preg_split('/[\s,]+/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
        return is_array($value) ? array_values(array_map('strval', $value)) : [];
    }
}
