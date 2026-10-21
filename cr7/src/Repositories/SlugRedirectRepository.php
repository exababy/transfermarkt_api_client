<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Support\Date;
use App\Support\Log;

/**
 * Old slugs of renamed posts and products (table slug_redirects), so a URL that was already in
 * sitemaps, RSS, IndexNow pings and other sites' links answers 301 to the current address
 * instead of 404 (spec §11 "301 for moved slugs"). Videos keep their own id-suffix lookup.
 *
 * Every call is guarded: before the installer has created the table (an older install that has
 * not re-run `php bin/install.php`) recording is skipped and lookups find nothing.
 */
final class SlugRedirectRepository
{
    public const POST = 'post';
    public const PRODUCT = 'product';

    /** Remember that $oldSlug of $entity #$id was replaced by $newSlug. */
    public static function record(string $entity, string $oldSlug, string $newSlug, int $id): void
    {
        if ($oldSlug === '' || $oldSlug === $newSlug) {
            return;
        }
        try {
            Database::transaction(static function () use ($entity, $oldSlug, $newSlug, $id): void {
                // The new slug is live again: an older redirect from it would never be reached.
                Database::execute('DELETE FROM slug_redirects WHERE entity = ? AND old_slug = ?', [$entity, $newSlug]);
                Database::upsert('slug_redirects', [
                    'entity' => $entity, 'old_slug' => mb_substr($oldSlug, 0, 190), 'target_id' => $id, 'created_at' => Date::now(),
                ], ['entity', 'old_slug'], ['target_id', 'created_at']);
            });
        } catch (\Throwable $e) {
            Log::error('site', 'Slug redirect not stored: ' . $e->getMessage(), ['entity' => $entity, 'slug' => $oldSlug]);
        }
    }

    /** Id of the post/product that used $slug before it was renamed, or null. */
    public static function target(string $entity, string $slug): ?int
    {
        if ($slug === '') {
            return null;
        }
        try {
            $id = Database::fetchValue('SELECT target_id FROM slug_redirects WHERE entity = ? AND old_slug = ?', [$entity, $slug]);
        } catch (\Throwable) {
            return null;
        }
        return $id !== null ? (int) $id : null;
    }

    /** Drop the redirects of a deleted post/product (its old URLs then answer 404). */
    public static function forget(string $entity, int $id): void
    {
        try {
            Database::execute('DELETE FROM slug_redirects WHERE entity = ? AND target_id = ?', [$entity, $id]);
        } catch (\Throwable) {
        }
    }
}
