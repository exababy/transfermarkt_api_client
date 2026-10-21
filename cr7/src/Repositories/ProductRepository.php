<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Services\Shop\Shop;
use App\Support\Date;

/**
 * Jersey products (table `products`). Public contract (spec §9b): all(), findBySlug(), clubs(), forSitemap().
 *
 * Every returned product row is decorated with:
 *   'price'/'old_price'/'print_price' floats (MySQL returns DECIMAL as strings), 'allow_print'/'is_active' bools,
 *   'stock' ?int, 'sizes' list<string>, 'kids_sizes' list<string>, 'image_url' (root-relative), 'url' ('/forma/slug'),
 *   'gallery' list<array{url, alt}> (main image + front view illustration when available).
 */
final class ProductRepository
{
    /** Columns writable through create()/update() (admin form). */
    public const FIELDS = [
        'slug', 'club_id', 'club_name', 'season', 'kit_type', 'title', 'description', 'price', 'old_price', 'sizes',
        'kids_sizes', 'allow_print', 'print_price', 'image_path', 'color1', 'color2', 'stock', 'sort_order', 'is_active',
    ];

    /** @return list<array<string, mixed>> products ordered by sort_order, newest first within a sort position */
    public function all(?string $clubId = null, bool $activeOnly = true): array
    {
        $where = [];
        $params = [];
        if ($activeOnly) {
            $where[] = 'is_active = 1';
        }
        if ($clubId !== null && $clubId !== '') {
            $where[] = 'club_id = ?';
            $params[] = $clubId;
        }
        $sql = 'SELECT * FROM products' . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY sort_order ASC, id ASC';
        return array_map(self::decorate(...), Database::fetchAll($sql, $params));
    }

    /** Active product by slug (null when missing or inactive). */
    public function findBySlug(string $slug): ?array
    {
        $row = Database::fetchOne('SELECT * FROM products WHERE slug = ? AND is_active = 1', [$slug]);
        return $row === null ? null : self::decorate($row);
    }

    /** Current slug of the active product that used $slug before it was renamed (=> 301), or null. */
    public function movedSlug(string $slug): ?string
    {
        $id = SlugRedirectRepository::target(SlugRedirectRepository::PRODUCT, $slug);
        if ($id === null) {
            return null;
        }
        $current = Database::fetchValue('SELECT slug FROM products WHERE id = ? AND is_active = 1', [$id]);
        return is_string($current) && $current !== $slug ? $current : null;
    }

    /** Any product by id (admin; inactive included). */
    public function find(int $id): ?array
    {
        $row = Database::fetchOne('SELECT * FROM products WHERE id = ?', [$id]);
        return $row === null ? null : self::decorate($row);
    }

    /** Any product by slug (admin uniqueness checks; inactive included). */
    public function findAnyBySlug(string $slug): ?array
    {
        $row = Database::fetchOne('SELECT * FROM products WHERE slug = ?', [$slug]);
        return $row === null ? null : self::decorate($row);
    }

    /** @return array<string, string> distinct club_id => club_name of active products (shop order) */
    public function clubs(): array
    {
        $rows = Database::fetchAll(
            'SELECT club_id, club_name, MIN(sort_order) AS first_sort, MIN(id) AS first_id FROM products '
            . 'WHERE is_active = 1 AND club_id IS NOT NULL AND club_id <> \'\' GROUP BY club_id, club_name ORDER BY first_sort ASC, first_id ASC'
        );
        $out = [];
        foreach ($rows as $row) {
            $id = (string) $row['club_id'];
            $out[$id] ??= (string) $row['club_name'];
        }
        return $out;
    }

    /** @return list<array{url: string, lastmod: string, image: ?string}> active products, absolute URLs */
    public function forSitemap(): array
    {
        $out = [];
        foreach ($this->all() as $product) {
            $out[] = [
                'url' => url($product['url']),
                'lastmod' => (string) $product['updated_at'],
                'image' => $product['image_url'] !== '' ? url($product['image_url']) : null,
            ];
        }
        return $out;
    }

    /** @return list<array<string, mixed>> other active products: same club first, then the rest (shop order) */
    public function related(array $product, int $limit = 4): array
    {
        $others = array_values(array_filter($this->all(), static fn (array $p): bool => (int) $p['id'] !== (int) $product['id']));
        usort($others, static function (array $a, array $b) use ($product): int {
            $sameA = (string) $a['club_id'] === (string) $product['club_id'] ? 0 : 1;
            $sameB = (string) $b['club_id'] === (string) $product['club_id'] ? 0 : 1;
            return [$sameA, (int) $a['sort_order'], (int) $a['id']] <=> [$sameB, (int) $b['sort_order'], (int) $b['id']];
        });
        return array_slice($others, 0, max(0, $limit));
    }

    /** @return array{0: list<array<string, mixed>>, 1: int} admin list: [rows, total] filtered by search term/club */
    public function adminList(string $q = '', string $clubId = '', int $limit = 50, int $offset = 0): array
    {
        $where = [];
        $params = [];
        if ($q !== '') {
            [$sql, $likeParams] = Database::containsAny(['title', 'slug', 'club_name', 'season'], $q);
            $where[] = $sql;
            array_push($params, ...$likeParams);
        }
        if ($clubId !== '') {
            $where[] = 'club_id = ?';
            $params[] = $clubId;
        }
        $sqlWhere = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';
        $total = (int) Database::fetchValue('SELECT COUNT(*) FROM products' . $sqlWhere, $params);
        $rows = Database::fetchAll(
            'SELECT * FROM products' . $sqlWhere . ' ORDER BY sort_order ASC, id ASC LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset),
            $params
        );
        return [array_map(self::decorate(...), $rows), $total];
    }

    /** @return array<string, string> club_id => club_name over ALL products (admin filter) */
    public function allClubs(): array
    {
        $out = [];
        foreach (Database::fetchAll('SELECT DISTINCT club_id, club_name FROM products WHERE club_id IS NOT NULL ORDER BY club_name') as $row) {
            $out[(string) $row['club_id']] ??= (string) $row['club_name'];
        }
        return $out;
    }

    /** Insert a product (only FIELDS are written); returns the new id. */
    public function create(array $data): int
    {
        $now = Date::now();
        $row = array_intersect_key($data, array_flip(self::FIELDS));
        $row['created_at'] = $now;
        $row['updated_at'] = $now;
        return Database::insert('products', $row);
    }

    public function update(int $id, array $data): void
    {
        $row = array_intersect_key($data, array_flip(self::FIELDS));
        $row['updated_at'] = Date::now();
        $oldSlug = isset($row['slug']) ? Database::fetchValue('SELECT slug FROM products WHERE id = ?', [$id]) : null;
        Database::update('products', $row, 'id = ?', [$id]);
        if (is_string($oldSlug)) {
            SlugRedirectRepository::record(SlugRedirectRepository::PRODUCT, $oldSlug, (string) $row['slug'], $id); // old URL => 301
        }
    }

    public function delete(int $id): void
    {
        Database::execute('DELETE FROM products WHERE id = ?', [$id]);
        SlugRedirectRepository::forget(SlugRedirectRepository::PRODUCT, $id);
    }

    /** Number of orders referencing a product (admin delete warning). */
    public function orderCount(int $id): int
    {
        return (int) Database::fetchValue('SELECT COUNT(*) FROM orders WHERE product_id = ?', [$id]);
    }

    /** Atomically take $quantity from a limited stock (no-op for "on order" products with stock NULL). */
    public function reserveStock(int $id, int $quantity): bool
    {
        $stock = Database::fetchValue('SELECT stock FROM products WHERE id = ?', [$id]);
        if ($stock === null) {
            return true;
        }
        return Database::execute('UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?', [$quantity, $id, $quantity]) === 1;
    }

    /** Normalise a DB row (types, size lists, image + URLs). */
    public static function decorate(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['price'] = (float) $row['price'];
        $row['old_price'] = $row['old_price'] !== null && $row['old_price'] !== '' ? (float) $row['old_price'] : null;
        $row['print_price'] = (float) ($row['print_price'] ?? 0);
        $row['allow_print'] = (int) ($row['allow_print'] ?? 0) === 1;
        $row['is_active'] = (int) ($row['is_active'] ?? 0) === 1;
        $row['stock'] = $row['stock'] !== null && $row['stock'] !== '' ? (int) $row['stock'] : null;
        $row['sort_order'] = (int) ($row['sort_order'] ?? 0);
        $row['club_id'] = $row['club_id'] !== null ? (string) $row['club_id'] : null;
        $row['sizes'] = self::sizeList((string) ($row['sizes'] ?? ''));
        $row['kids_sizes'] = self::sizeList((string) ($row['kids_sizes'] ?? ''));
        $slug = (string) $row['slug'];
        $image = trim((string) ($row['image_path'] ?? ''));
        if ($image === '') {
            $image = (string) (Shop::kitPath($slug) ?? '');
        }
        $row['image_url'] = $image !== '' ? Shop::imageUrl($image) : '';
        $row['url'] = '/forma/' . $slug;
        $gallery = [];
        if ($row['image_url'] !== '') {
            $isKit = str_contains($image, '/assets/img/kits/');
            $gallery[] = ['url' => $row['image_url'], 'alt' => $row['title'] . ($isKit ? ' – arkadan görünüm, RONALDO baskılı' : '')];
        }
        $front = Shop::kitPath($slug, '-on');
        if ($front !== null && str_contains($image, '/assets/img/kits/' . $slug . '.svg')) {
            $gallery[] = ['url' => Shop::imageUrl($front), 'alt' => $row['title'] . ' – önden görünüm'];
        }
        $row['gallery'] = $gallery;
        return $row;
    }

    /** "S, M,L" => ['S','M','L'] (trimmed, unique, max 20 chars each). */
    public static function sizeList(string $sizes): array
    {
        $out = [];
        foreach (explode(',', $sizes) as $size) {
            $size = trim($size);
            if ($size !== '' && mb_strlen($size) <= 20 && !in_array($size, $out, true)) {
                $out[] = $size;
            }
        }
        return $out;
    }
}
