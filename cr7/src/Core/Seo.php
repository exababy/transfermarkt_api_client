<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Per-page SEO data, passed to every page view as $seo; layouts/main.php prints
 * title, meta description, canonical, robots, hreflang, OG, Twitter and JSON-LD.
 *
 * Breadcrumbs: pass the trail WITHOUT the home item (it is prepended
 * automatically); the last item's 'url' may be omitted (defaults to canonical).
 */
final class Seo
{
    public const DEFAULT_IMAGE = '/assets/img/og-default.png';

    public function __construct(
        public string $title,
        public string $description = '',
        public ?string $canonical = null,
        public ?string $image = null,
        public string $type = 'website',
        public string $robots = 'index,follow,max-image-preview:large',
        public array $jsonLd = [],
        public array $breadcrumbs = [],
        public bool $rawTitle = false,
        public ?string $publishedTime = null,
        public ?string $modifiedTime = null,
        public array $extraMeta = [],
        public ?string $prev = null,
        public ?string $next = null,
    ) {
    }

    public static function siteName(): string
    {
        return (string) Config::get('app.name', 'CR7.com.tr');
    }

    /** "<title> | CR7.com.tr" unless rawTitle (or empty title => site name). */
    public function fullTitle(): string
    {
        if ($this->title === '') {
            return self::siteName();
        }
        return $this->rawTitle ? $this->title : $this->title . ' | ' . self::siteName();
    }

    /** Absolute canonical URL (default: current path without query). */
    public function canonicalUrl(): string
    {
        return $this->canonical ?? url(App::request()?->path() ?? '/');
    }

    /** Absolute share image URL (default og-default.png, 1200x630). */
    public function imageUrl(): string
    {
        return url($this->image ?? self::DEFAULT_IMAGE);
    }

    public function usesDefaultImage(): bool
    {
        return $this->image === null || $this->image === '' || str_ends_with($this->image, self::DEFAULT_IMAGE);
    }

    public function isIndexable(): bool
    {
        return !str_contains(strtolower($this->robots), 'noindex');
    }

    /** @return list<array{name: string, url: string}> breadcrumb trail with home first and absolute URLs */
    public function breadcrumbTrail(): array
    {
        if ($this->breadcrumbs === []) {
            return [];
        }
        $home = url('/');
        $trail = [];
        $first = $this->breadcrumbs[0];
        if (!isset($first['url']) || url((string) $first['url']) !== $home) {
            $trail[] = ['name' => 'Ana Sayfa', 'url' => $home];
        }
        $last = array_key_last($this->breadcrumbs);
        foreach ($this->breadcrumbs as $i => $crumb) {
            $link = $crumb['url'] ?? null;
            $trail[] = [
                'name' => (string) ($crumb['name'] ?? ''),
                'url' => $link !== null && $link !== '' ? url((string) $link) : ($i === $last ? $this->canonicalUrl() : $home),
            ];
        }
        return $trail;
    }

    /** @return list<array<string, mixed>> JSON-LD objects incl. automatic BreadcrumbList */
    public function structuredData(): array
    {
        $items = [];
        foreach ($this->jsonLd as $schema) {
            if (is_array($schema) && $schema !== []) {
                $items[] = ['@context' => 'https://schema.org'] + $schema;
            }
        }
        $trail = $this->breadcrumbTrail();
        if (count($trail) > 1) {
            $list = [];
            foreach ($trail as $i => $crumb) {
                $list[] = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $crumb['name'], 'item' => $crumb['url']];
            }
            $items[] = ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $list];
        }
        return $items;
    }
}
