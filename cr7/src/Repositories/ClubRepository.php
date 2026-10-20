<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\App;
use App\Core\Database;
use App\Services\Transfermarkt\TransfermarktSync;

/**
 * Clubs (Transfermarkt `clubs` table) + the editorial career club pages from content/career.php.
 *
 * Club pages exist only for the senior career clubs and Portekiz (/takimlar/{slug});
 * Manchester United has two spells ('985' and '985-2') on ONE page.
 */
final class ClubRepository
{
    /** Senior spells in career order (content/career.php keys). */
    public const SPELL_KEYS = ['336', '985', '418', '506', '985-2', '18544'];

    /** @var array<string, mixed>|null */
    private static ?array $content = null;

    /** content/career.php (empty structure when missing). */
    public static function content(): array
    {
        if (self::$content === null) {
            $file = App::basePath('content/career.php');
            $data = is_file($file) ? require $file : [];
            self::$content = is_array($data) ? $data : [];
        }
        return self::$content;
    }

    /**
     * Size variant of a Transfermarkt crest/flag URL.
     * 'xs' (≤ 22px, tables) | 'sm' (≤ 44px) | 'md' (cards, ≤ 90px) | 'lg' (original).
     */
    public static function logo(?string $url, string $size = 'sm'): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }
        if (str_contains($url, '/wappen/')) {
            $variant = ['xs' => 'small', 'sm' => 'medium', 'md' => 'head', 'lg' => 'big'][$size] ?? 'medium';
            return (string) preg_replace('#/wappen/[a-z]+/#', '/wappen/' . $variant . '/', $url);
        }
        if (str_contains($url, '/flagge/')) {
            $variant = $size === 'xs' ? 'small' : 'head';
            return (string) preg_replace('#/flagge/[a-z]+/#', '/flagge/' . $variant . '/', $url);
        }
        return $url;
    }

    /** @return array<string, mixed>|null clubs row */
    public function find(string $id): ?array
    {
        return self::safe(static fn () => Database::fetchOne('SELECT * FROM clubs WHERE id = ?', [$id]), null);
    }

    /** @return array<string, mixed>|null clubs row */
    public function findBySlug(string $slug): ?array
    {
        return self::safe(static fn () => Database::fetchOne('SELECT * FROM clubs WHERE slug = ? ORDER BY id LIMIT 1', [$slug]), null);
    }

    /**
     * @param list<string> $ids
     * @return array<string, array<string, mixed>> id => clubs row
     */
    public function many(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('strval', $ids), static fn (string $id): bool => $id !== '')));
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $rows = self::safe(static fn () => Database::fetchAll(
                'SELECT * FROM clubs WHERE id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')',
                $chunk
            ), []);
            foreach ($rows as $row) {
                $out[(string) $row['id']] = $row;
            }
        }
        return $out;
    }

    /**
     * Career club pages in career order (senior clubs, then Portekiz).
     *
     * @return array<string, array{slug: string, club_id: string, name: string, url: string, is_national: bool,
     *         spells: array<string, array<string, mixed>>, logo_url: ?string, color1: ?string, color2: ?string,
     *         country: string, updated_at: ?string}>
     */
    public function pages(): array
    {
        $content = self::content();
        $entries = [];
        foreach (self::SPELL_KEYS as $key) {
            if (isset($content['clubs'][$key]) && is_array($content['clubs'][$key])) {
                $entries[$key] = $content['clubs'][$key] + ['club_id' => explode('-', $key)[0]];
            }
        }
        foreach ((array) ($content['clubs'] ?? []) as $key => $entry) { // any extra club entries
            if (!isset($entries[(string) $key]) && is_array($entry)) {
                $entries[(string) $key] = $entry + ['club_id' => explode('-', (string) $key)[0]];
            }
        }
        if (isset($content['national']) && is_array($content['national'])) {
            $entries['national'] = $content['national'] + ['club_id' => TransfermarktSync::NATIONAL_TEAM_ID, 'slug' => 'portekiz'];
        }
        if ($entries === []) { // no editorial content: fall back to the fixed slugs
            foreach (TransfermarktSync::FIXED_SLUGS as $id => $slug) {
                $entries[$id] = ['club_id' => (string) $id, 'slug' => $slug, 'name' => ucwords(str_replace('-', ' ', $slug))];
            }
        }

        $clubs = $this->many(array_map(static fn (array $e): string => (string) $e['club_id'], $entries));
        $pages = [];
        foreach ($entries as $key => $entry) {
            $clubId = (string) $entry['club_id'];
            $slug = (string) ($entry['slug'] ?? (TransfermarktSync::FIXED_SLUGS[$clubId] ?? ''));
            if ($slug === '' || preg_match('/^[a-z0-9-]+$/', $slug) !== 1) {
                continue;
            }
            $club = $clubs[$clubId] ?? null;
            if (!isset($pages[$slug])) {
                $pages[$slug] = [
                    'slug' => $slug,
                    'club_id' => $clubId,
                    'name' => (string) ($entry['name'] ?? $club['name'] ?? ($key === 'national' ? 'Portekiz Milli Takımı' : $slug)),
                    'url' => '/takimlar/' . $slug,
                    'is_national' => $key === 'national',
                    'spells' => [],
                    'logo_url' => $club['logo_url'] ?? null,
                    'color1' => $club['color1'] ?? null,
                    'color2' => $club['color2'] ?? null,
                    'country' => (string) ($entry['country'] ?? ''),
                    'updated_at' => $club['updated_at'] ?? null,
                ];
            }
            $pages[$slug]['spells'][(string) $key] = $entry;
        }
        return $pages;
    }

    /** @return array<string, mixed>|null one entry of pages() */
    public function page(string $slug): ?array
    {
        return $this->pages()[$slug] ?? null;
    }

    /** @return list<array{url: string, lastmod: ?string, image: ?string, name: string}> club pages for sitemap-clubs.xml */
    public function forSitemap(): array
    {
        $lastSync = self::safe(static fn () => Database::fetchValue('SELECT MAX(updated_at) FROM stats_summary'), null);
        $out = [['url' => url('/takimlar'), 'lastmod' => is_string($lastSync) ? $lastSync : null, 'image' => null, 'name' => 'Oynadığı Takımlar']];
        foreach ($this->pages() as $page) {
            $out[] = [
                'url' => url($page['url']),
                'lastmod' => is_string($lastSync) ? $lastSync : $page['updated_at'],
                'image' => self::logo($page['logo_url'], 'md'),
                'name' => $page['name'],
            ];
        }
        return $out;
    }

    /** Run a query; return $default when the schema is missing or the DB is unreachable. */
    private static function safe(callable $fn, mixed $default): mixed
    {
        try {
            return $fn() ?? $default;
        } catch (\PDOException) {
            return $default;
        }
    }
}
