<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Repositories\NewsRepository;
use App\Repositories\SlugRedirectRepository;
use App\Services\News\IndexNow;
use App\Services\News\NewsPublisher;
use App\Support\Date;
use App\Support\Format;
use App\Support\Html;
use App\Support\Log;
use App\Support\OgImage;
use App\Support\Str;

/**
 * News posts management: list (search / status / type filters, pagination), create, edit, delete.
 * Bodies are sanitised with Html::sanitize() before storage; the OG image is (re)generated
 * through NewsPublisher::regenerateImage() (or OgImage directly).
 */
final class PostsController
{
    private const PER_PAGE = 20;
    public const SUMMARY_MAX = 500;
    public const STATUSES = ['published' => 'Yayında', 'draft' => 'Taslak'];

    public function index(Request $request): Response
    {
        if ($redirect = Auth::require()) {
            return $redirect;
        }
        $q = AdminPage::queryString($request, 'q', 100);
        $status = AdminPage::queryString($request, 'durum', 20);
        $type = AdminPage::queryString($request, 'tur', 20);

        $where = ['1 = 1'];
        $params = [];
        if ($q !== '') {
            [$sql, $likeParams] = Database::containsAny(['title', 'slug', 'tags'], $q);
            $where[] = $sql;
            array_push($params, ...$likeParams);
        }
        $now = Date::now();
        if ($status === 'published') {
            $where[] = 'status = ? AND published_at <= ?';
            array_push($params, 'published', $now);
        } elseif ($status === 'scheduled') {
            $where[] = 'status = ? AND published_at > ?';
            array_push($params, 'published', $now);
        } elseif ($status === 'draft') {
            $where[] = 'status = ?';
            $params[] = 'draft';
        } else {
            $status = '';
        }
        if ($type === 'otomatik') {
            $where[] = 'is_spun = 1';
        } elseif ($type === 'elle') {
            $where[] = 'is_spun = 0';
        } else {
            $type = '';
        }
        $whereSql = implode(' AND ', $where);
        $total = (int) Database::fetchValue('SELECT COUNT(*) FROM posts WHERE ' . $whereSql, $params);
        $pager = AdminPage::paginate($total, self::PER_PAGE, AdminPage::page($request));
        $posts = Database::fetchAll(
            'SELECT id, slug, title, status, is_spun, is_indexable, uniqueness, source_count, source_name, views, published_at, updated_at, image_path
             FROM posts WHERE ' . $whereSql . ' ORDER BY published_at DESC, id DESC LIMIT ? OFFSET ?',
            [...$params, $pager['perPage'], $pager['offset']]
        );

        $counts = ['all' => 0, 'published' => 0, 'scheduled' => 0, 'draft' => 0];
        foreach (Database::fetchAll('SELECT status, CASE WHEN published_at > ? THEN 1 ELSE 0 END AS future, COUNT(*) AS n FROM posts GROUP BY status, future', [$now]) as $row) {
            $n = (int) $row['n'];
            $counts['all'] += $n;
            if ($row['status'] === 'draft') {
                $counts['draft'] += $n;
            } elseif ($row['status'] === 'published') {
                $counts[(int) $row['future'] === 1 ? 'scheduled' : 'published'] += $n;
            }
        }

        return AdminPage::render('posts', 'Haberler', [
            'posts' => $posts,
            'pager' => $pager,
            'filters' => ['q' => $q, 'durum' => $status, 'tur' => $type],
            'counts' => $counts,
            'now' => $now,
            'back' => AdminPage::currentPath($request),
        ], 200, [['name' => 'Haberler']]);
    }

    public function create(Request $request): Response
    {
        if ($redirect = Auth::require()) {
            return $redirect;
        }
        $post = [
            'id' => null, 'title' => '', 'slug' => '', 'meta_title' => '', 'summary' => '', 'body' => '', 'tags' => '',
            'status' => 'published', 'is_indexable' => 1, 'published_at' => Date::now(), 'is_spun' => 0,
            'source_name' => '', 'source_url' => '',
        ];
        return $this->form($post, [], 200);
    }

    public function store(Request $request): Response
    {
        if ($redirect = Auth::require()) {
            return $redirect;
        }
        Csrf::check($request);
        [$data, $errors] = $this->validate($request, null);
        if ($errors !== []) {
            return $this->form($data + ['id' => null, 'is_spun' => 0], $errors, 422);
        }
        $now = Date::now();
        $id = Database::insert('posts', $data + [
            'news_item_id' => null,
            'category' => 'haber',
            'is_spun' => 0,
            'uniqueness' => null,
            'source_count' => 1,
            'views' => 0,
            'updated_at' => $now,
            'created_at' => $now,
        ]);
        $image = self::regenerateImage($id);
        $this->pingIndexNow($id);
        Log::info('admin', 'post created', ['post' => $id, 'slug' => $data['slug'], 'user' => Auth::user()['id'] ?? null]);
        Session::flash('success', 'Haber kaydedildi.' . ($image === null ? ' (Paylaşım görseli oluşturulamadı; GD eklentisini kontrol edin.)' : ''));
        return Response::redirect(url('/admin/haberler/' . $id . '/duzenle'));
    }

    public function edit(Request $request): Response
    {
        if ($redirect = Auth::require()) {
            return $redirect;
        }
        $post = self::find((int) $request->param('id'));
        if ($post === null) {
            return Response::notFound();
        }
        return $this->form($post, [], 200);
    }

    public function update(Request $request): Response
    {
        if ($redirect = Auth::require()) {
            return $redirect;
        }
        Csrf::check($request);
        $id = (int) $request->param('id');
        $post = self::find($id);
        if ($post === null) {
            return Response::notFound();
        }
        [$data, $errors] = $this->validate($request, $post);
        if ($errors !== []) {
            return $this->form(array_replace($post, $data), $errors, 422);
        }
        Database::update('posts', $data + ['updated_at' => Date::now()], 'id = ?', [$id]);
        // The old URL is already in sitemaps, RSS and other sites' links: it answers 301 from now on.
        SlugRedirectRepository::record(SlugRedirectRepository::POST, (string) $post['slug'], (string) $data['slug'], $id);

        $messages = ['Değişiklikler kaydedildi.'];
        // Also when the stored image file has gone missing (imageUrl() is null for empty or missing files).
        if (AdminPage::checked($request, 'regenerate_image') || $data['title'] !== $post['title'] || NewsRepository::imageUrl($post['image_path'] ?? null) === null) {
            $messages[] = self::regenerateImage($id) !== null ? 'Paylaşım görseli yeniden oluşturuldu.' : 'Paylaşım görseli oluşturulamadı.';
        }
        $this->pingIndexNow($id);
        Log::info('admin', 'post updated', ['post' => $id, 'user' => Auth::user()['id'] ?? null]);
        Session::flash('success', implode(' ', $messages));
        return Response::redirect(url('/admin/haberler/' . $id . '/duzenle'));
    }

    public function delete(Request $request): Response
    {
        if ($redirect = Auth::require()) {
            return $redirect;
        }
        Csrf::check($request);
        $id = (int) $request->param('id');
        $post = self::find($id);
        if ($post === null) {
            Session::flash('error', 'Haber bulunamadı.');
            return AdminPage::back($request, '/admin/haberler');
        }
        Database::transaction(static function () use ($id): void {
            Database::execute('DELETE FROM posts WHERE id = ?', [$id]);
            SlugRedirectRepository::forget(SlugRedirectRepository::POST, $id);
            // The source item must not be published again automatically.
            Database::execute("UPDATE news_items SET status = 'skipped', post_id = NULL, note = ? WHERE post_id = ?", ['admin: yazı silindi', $id]);
        });
        if (!empty($post['image_path'])) {
            NewsPublisher::deleteImage((string) $post['image_path'], $id); // kept while another post still shows it
        }
        Log::info('admin', 'post deleted', ['post' => $id, 'slug' => $post['slug'], 'user' => Auth::user()['id'] ?? null]);
        Session::flash('success', '“' . Str::limit((string) $post['title'], 80) . '” silindi.');
        $back = AdminPage::safePath($request->input('_back'), '/admin/haberler');
        return Response::redirect(url(str_contains($back, '/' . $id . '/') ? '/admin/haberler' : $back));
    }

    /** Re-render the OG image of a post; returns the new image path or null. */
    public static function regenerateImage(int $postId): ?string
    {
        try {
            if (class_exists(NewsPublisher::class) && method_exists(NewsPublisher::class, 'regenerateImage')) {
                return (new NewsPublisher())->regenerateImage($postId);
            }
            if (!class_exists(OgImage::class)) {
                return null;
            }
            $post = Database::fetchOne('SELECT slug, title, published_at, image_path FROM posts WHERE id = ?', [$postId]);
            if ($post === null) {
                return null;
            }
            $path = OgImage::generate((string) $post['title'], Format::date((string) $post['published_at'], 'j F Y'), 'haber-' . $postId . '-' . substr((string) $post['slug'], 0, 80) . '-' . substr(sha1((string) $post['title']), 0, 6));
            if ($path !== null) {
                Database::update('posts', ['image_path' => $path], 'id = ?', [$postId]);
                if (!empty($post['image_path']) && $post['image_path'] !== $path && method_exists(OgImage::class, 'delete')) {
                    OgImage::delete((string) $post['image_path']);
                }
            }
            return $path;
        } catch (\Throwable $e) {
            Log::error('admin', 'OG image generation failed: ' . $e->getMessage(), ['post' => $postId]);
            return null;
        }
    }

    /** Post row (any status) or null. */
    public static function find(int $id): ?array
    {
        return $id > 0 ? Database::fetchOne('SELECT * FROM posts WHERE id = ?', [$id]) : null;
    }

    /**
     * News items the post was composed from (its own item + the story cluster).
     *
     * @return list<array<string, mixed>>
     */
    public static function sourceItems(array $post): array
    {
        $itemId = (int) ($post['news_item_id'] ?? 0);
        if ($itemId <= 0) {
            return [];
        }
        return Database::fetchAll(
            'SELECT id, source, source_name, url, title, status, published_at FROM news_items
             WHERE id = ? OR note = ? OR note LIKE ? OR post_id = ? ORDER BY id LIMIT 30',
            [$itemId, 'cluster:' . $itemId, 'cluster:' . $itemId . ';%', (int) $post['id']]
        );
    }

    /**
     * Validate the form; returns [column data, field errors].
     *
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    private function validate(Request $request, ?array $post): array
    {
        $errors = [];
        $title = (string) preg_replace('/\s+/u', ' ', AdminPage::input($request, 'title'));
        if ($title === '') {
            $errors['title'] = 'Başlık zorunludur.';
        } elseif (mb_strlen($title) > 255) {
            $errors['title'] = 'Başlık en fazla 255 karakter olabilir.';
        }

        $slugInput = AdminPage::input($request, 'slug');
        $slug = Str::slug($slugInput !== '' ? $slugInput : $title, 190);
        $exceptId = $post !== null ? (int) $post['id'] : null;
        if ($slug === '') {
            if ($title !== '' || $slugInput !== '') {
                $errors['slug'] = 'Geçerli bir adres (slug) girin.';
            }
        } elseif (self::slugTaken($slug, $exceptId)) {
            if ($slugInput === '') {
                $base = Str::slug($slug, 180);
                $i = 2;
                while (self::slugTaken($base . '-' . $i, $exceptId)) {
                    $i++;
                }
                $slug = $base . '-' . $i;
            } else {
                $errors['slug'] = 'Bu adres başka bir haberde kullanılıyor.';
            }
        }

        $body = Html::sanitize(AdminPage::input($request, 'body'));
        if (trim(Html::toText($body)) === '') {
            $errors['body'] = 'Haber metni zorunludur.';
        }

        $summary = trim((string) preg_replace('/\s+/u', ' ', strip_tags(AdminPage::input($request, 'summary'))));
        if (mb_strlen($summary) > self::SUMMARY_MAX) {
            $errors['summary'] = 'Özet en fazla ' . self::SUMMARY_MAX . ' karakter olabilir (şu an ' . mb_strlen($summary) . ').';
        } elseif ($summary === '' && $body !== '') {
            // Auto summary from the paragraphs (headings skipped).
            $text = Html::toText((string) preg_replace('~<h[2-4]\b[^>]*>.*?</h[2-4]>~is', '', $body));
            $summary = Str::limit(str_replace("\n", ' ', $text !== '' ? $text : Html::toText($body)), 300);
        }

        $metaTitle = AdminPage::input($request, 'meta_title');
        if (mb_strlen($metaTitle) > 255) {
            $errors['meta_title'] = 'SEO başlığı en fazla 255 karakter olabilir.';
        }

        $tags = self::parseTags(AdminPage::input($request, 'tags'));
        if (mb_strlen($tags) > 500) {
            $errors['tags'] = 'Etiketler toplamda en fazla 500 karakter olabilir.';
        }

        $status = AdminPage::input($request, 'status');
        if (!array_key_exists($status, self::STATUSES)) {
            $errors['status'] = 'Geçersiz durum.';
            $status = 'draft';
        }

        $publishedInput = AdminPage::input($request, 'published_at');
        $publishedAt = $publishedInput === '' ? ($post['published_at'] ?? Date::now()) : AdminPage::fromLocalInput($publishedInput);
        if ($publishedAt === null) {
            $errors['published_at'] = 'Geçerli bir tarih ve saat girin.';
            $publishedAt = $post['published_at'] ?? Date::now();
        }

        $data = [
            'title' => $title,
            'slug' => $slug !== '' ? $slug : ($post['slug'] ?? ''),
            'meta_title' => $metaTitle !== '' ? $metaTitle : null,
            'summary' => mb_substr($summary, 0, self::SUMMARY_MAX),
            'body' => $body,
            'tags' => $tags !== '' ? $tags : null,
            'status' => $status,
            'is_indexable' => AdminPage::checked($request, 'is_indexable') ? 1 : 0,
            'published_at' => $publishedAt,
        ];

        // Source attribution is editable for hand-written posts only (automatic posts keep their sources).
        if ($post === null || (int) ($post['is_spun'] ?? 0) === 0) {
            $sourceName = AdminPage::input($request, 'source_name');
            $sourceUrl = AdminPage::input($request, 'source_url');
            if (mb_strlen($sourceName) > 150) {
                $errors['source_name'] = 'Kaynak adı en fazla 150 karakter olabilir.';
            }
            if ($sourceUrl !== '' && (preg_match('#^https?://[^\s/$.?\#].[^\s]*$#i', $sourceUrl) !== 1 || strlen($sourceUrl) > 1000)) {
                $errors['source_url'] = 'Kaynak adresi http:// veya https:// ile başlayan geçerli bir URL olmalıdır.';
            }
            $data['source_name'] = $sourceName !== '' ? $sourceName : null;
            $data['source_url'] = $sourceUrl !== '' ? $sourceUrl : null;
        }
        return [$data, $errors];
    }

    /** 'a, b ,A,, c' => 'a, b, c' (trimmed, de-duplicated case-insensitively, max 15 tags of 60 chars). */
    public static function parseTags(string $input): string
    {
        $out = [];
        foreach (explode(',', $input) as $tag) {
            $tag = trim((string) preg_replace('/\s+/u', ' ', strip_tags($tag)));
            if ($tag === '' || Str::slug($tag) === '') {
                continue;
            }
            $key = Str::slug($tag);
            if (!isset($out[$key])) {
                $out[$key] = mb_substr($tag, 0, 60);
            }
            if (count($out) >= 15) {
                break;
            }
        }
        return implode(', ', $out);
    }

    private static function slugTaken(string $slug, ?int $exceptId): bool
    {
        $sql = 'SELECT 1 FROM posts WHERE slug = ?' . ($exceptId !== null ? ' AND id <> ?' : '');
        return Database::fetchValue($sql, $exceptId !== null ? [$slug, $exceptId] : [$slug]) !== null;
    }

    private function pingIndexNow(int $postId): void
    {
        $post = Database::fetchOne('SELECT slug, status, is_indexable, published_at FROM posts WHERE id = ?', [$postId]);
        if ($post === null || $post['status'] !== 'published' || (int) $post['is_indexable'] !== 1 || (string) $post['published_at'] > Date::now()) {
            return;
        }
        if (class_exists(IndexNow::class) && method_exists(IndexNow::class, 'ping')) {
            try {
                IndexNow::ping([url('/haber/' . $post['slug'])]);
            } catch (\Throwable $e) {
                Log::error('admin', 'IndexNow ping failed: ' . $e->getMessage());
            }
        }
    }

    /** @param array<string, string> $errors */
    private function form(array $post, array $errors, int $status): Response
    {
        $isNew = empty($post['id']);
        $title = $isNew ? 'Yeni Haber' : 'Haberi Düzenle';
        $published = !$isNew && ($post['status'] ?? '') === 'published' && (string) ($post['published_at'] ?? '') <= Date::now();
        return AdminPage::render('post-form', $title, [
            'post' => $post,
            'errors' => $errors,
            'isNew' => $isNew,
            'statuses' => self::STATUSES,
            'summaryMax' => self::SUMMARY_MAX,
            'publicUrl' => $published ? url('/haber/' . $post['slug']) : null,
            'sources' => $isNew ? [] : self::sourceItems($post),
            'publishedLocal' => AdminPage::toLocalInput(isset($post['published_at']) ? (string) $post['published_at'] : null),
        ], $status, [['name' => 'Haberler', 'url' => '/admin/haberler'], ['name' => $title]]);
    }
}
