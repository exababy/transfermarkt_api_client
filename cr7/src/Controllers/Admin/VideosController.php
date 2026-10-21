<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Repositories\VideoRepository;
use App\Services\News\IndexNow;
use App\Services\Video\VideoImporter;
use App\Support\Date;
use App\Support\Log;

/**
 * Videos management: list with filters, add by YouTube URL (VideoImporter::importByUrl),
 * edit title / description / category / featured / status, delete.
 */
final class VideosController
{
    private const PER_PAGE = 24;

    public const STATUSES = ['published' => 'Yayında', 'draft' => 'Taslak', 'hidden' => 'Gizli'];

    /** Fallback when the videos module is missing (spec §9 VideoRepository::categories()). */
    private const CATEGORIES = [
        'genel' => 'Genel', 'goller' => 'Goller', 'mac-ozetleri' => 'Maç Özetleri', 'roportajlar' => 'Röportajlar',
        'antrenman' => 'Antrenman', 'belgesel' => 'Belgesel', 'ur-cristiano' => 'UR · Cristiano',
    ];

    public function index(Request $request): Response
    {
        if ($redirect = Auth::require()) {
            return $redirect;
        }
        $categories = self::categories();
        $q = AdminPage::queryString($request, 'q', 100);
        $category = AdminPage::queryString($request, 'kategori', 40);
        $status = AdminPage::queryString($request, 'durum', 20);
        $featured = AdminPage::queryString($request, 'one-cikan', 2);

        $where = ['1 = 1'];
        $params = [];
        if ($q !== '') {
            [$sql, $likeParams] = Database::containsAny(['title', 'channel_title'], $q);
            $where[] = '(' . $sql . ' OR youtube_id = ?)';
            array_push($params, ...$likeParams);
            $params[] = $q;
        }
        if (array_key_exists($category, $categories)) {
            $where[] = 'category = ?';
            $params[] = $category;
        } else {
            $category = '';
        }
        if ($status !== '' && preg_match('/^[a-z]{1,20}$/', $status) === 1) {
            $where[] = 'status = ?';
            $params[] = $status;
        } else {
            $status = '';
        }
        if ($featured === '1') {
            $where[] = 'is_featured = 1';
        } else {
            $featured = '';
        }
        $whereSql = implode(' AND ', $where);
        $total = (int) Database::fetchValue('SELECT COUNT(*) FROM videos WHERE ' . $whereSql, $params);
        $pager = AdminPage::paginate($total, self::PER_PAGE, AdminPage::page($request));
        $videos = Database::fetchAll(
            'SELECT id, youtube_id, slug, title, channel_title, category, thumbnail_url, duration_seconds, published_at, source, is_featured, status, created_at
             FROM videos WHERE ' . $whereSql . ' ORDER BY COALESCE(published_at, created_at) DESC, id DESC LIMIT ? OFFSET ?',
            [...$params, $pager['perPage'], $pager['offset']]
        );

        return AdminPage::render('videos', 'Videolar', [
            'videos' => $videos,
            'pager' => $pager,
            'filters' => ['q' => $q, 'kategori' => $category, 'durum' => $status, 'one-cikan' => $featured],
            'categories' => $categories,
            'statuses' => self::statuses(),
            'importerAvailable' => class_exists(VideoImporter::class),
            'old' => Session::pull('admin.video_old', []),
            'back' => AdminPage::currentPath($request),
        ], 200, [['name' => 'Videolar']]);
    }

    public function store(Request $request): Response
    {
        if ($redirect = Auth::require()) {
            return $redirect;
        }
        Csrf::check($request);
        $url = mb_substr(AdminPage::input($request, 'url'), 0, 500);
        $category = AdminPage::input($request, 'category');
        $category = array_key_exists($category, self::categories()) ? $category : null;
        $featured = AdminPage::checked($request, 'is_featured');
        $back = AdminPage::safePath($request->input('_back'), '/admin/videolar');

        if ($url === '') {
            Session::flash('error', 'YouTube bağlantısı ya da video kimliği girin.');
            return Response::redirect(url($back));
        }
        if (!class_exists(VideoImporter::class) || !method_exists(VideoImporter::class, 'importByUrl')) {
            Session::flash('error', 'Video modülü (VideoImporter) kurulu değil; video eklenemiyor.');
            return Response::redirect(url($back));
        }
        $youtubeId = self::youtubeId($url);
        $existing = $youtubeId !== null ? Database::fetchValue('SELECT id FROM videos WHERE youtube_id = ?', [$youtubeId]) : null;

        @set_time_limit(60);
        $error = null;
        try {
            $importer = new VideoImporter();
            $id = $importer->importByUrl($url, $category, $featured);
            if ($id === null && method_exists($importer, 'lastError')) {
                $error = $importer->lastError();
            }
        } catch (\Throwable $e) {
            Log::error('admin', 'video import failed: ' . $e->getMessage(), ['url' => $url, 'exception' => $e]);
            $id = null;
            $error = 'Beklenmeyen hata: ' . $e->getMessage();
        }
        if ($id === null) {
            Session::set('admin.video_old', ['url' => $url, 'category' => $category ?? '', 'is_featured' => $featured]);
            Session::flash('error', 'Video eklenemedi. ' . ($error ?? 'Bağlantıyı kontrol edip tekrar deneyin.'));
            return Response::redirect(url($back));
        }
        $title = (string) Database::fetchValue('SELECT title FROM videos WHERE id = ?', [$id]);
        if ($existing !== null) {
            Session::flash('info', 'Bu video zaten ekliydi: “' . $title . '”. Kategori/öne çıkarma seçimi uygulandı.');
        } else {
            Log::info('admin', 'video added', ['video' => $id, 'url' => $url, 'user' => Auth::user()['id'] ?? null]);
            Session::flash('success', 'Video eklendi: “' . $title . '”.');
        }
        return Response::redirect(url('/admin/videolar/' . (int) $id . '/duzenle'));
    }

    public function edit(Request $request): Response
    {
        if ($redirect = Auth::require()) {
            return $redirect;
        }
        $video = self::find((int) $request->param('id'));
        if ($video === null) {
            return Response::notFound();
        }
        return $this->form($video, [], 200);
    }

    public function update(Request $request): Response
    {
        if ($redirect = Auth::require()) {
            return $redirect;
        }
        Csrf::check($request);
        $id = (int) $request->param('id');
        $video = self::find($id);
        if ($video === null) {
            return Response::notFound();
        }
        $errors = [];
        $title = (string) preg_replace('/\s+/u', ' ', AdminPage::input($request, 'title'));
        if ($title === '') {
            $errors['title'] = 'Başlık zorunludur.';
        } elseif (mb_strlen($title) > 255) {
            $errors['title'] = 'Başlık en fazla 255 karakter olabilir.';
        }
        $description = str_replace("\r\n", "\n", AdminPage::input($request, 'description'));
        if (mb_strlen($description) > 5000) {
            $errors['description'] = 'Açıklama en fazla 5000 karakter olabilir.';
        }
        $category = AdminPage::input($request, 'category');
        if (!array_key_exists($category, self::categories())) {
            $errors['category'] = 'Geçerli bir kategori seçin.';
        }
        $status = AdminPage::input($request, 'status');
        if (!array_key_exists($status, self::statuses((string) $video['status']))) {
            $errors['status'] = 'Geçersiz durum.';
        }
        $data = [
            'title' => $title,
            'description' => $description !== '' ? $description : null,
            'category' => $category,
            'status' => $status,
            'is_featured' => AdminPage::checked($request, 'is_featured') ? 1 : 0,
        ];
        if ($errors !== []) {
            return $this->form(array_replace($video, $data), $errors, 422);
        }
        Database::update('videos', $data + ['updated_at' => Date::now()], 'id = ?', [$id]);
        if ($status === 'published' && class_exists(IndexNow::class) && method_exists(IndexNow::class, 'ping')) {
            try {
                IndexNow::ping([url('/video/' . $video['slug'])]);
            } catch (\Throwable $e) {
                Log::error('admin', 'IndexNow ping failed: ' . $e->getMessage());
            }
        }
        Log::info('admin', 'video updated', ['video' => $id, 'user' => Auth::user()['id'] ?? null]);
        Session::flash('success', 'Video güncellendi.');
        return Response::redirect(url('/admin/videolar/' . $id . '/duzenle'));
    }

    public function delete(Request $request): Response
    {
        if ($redirect = Auth::require()) {
            return $redirect;
        }
        Csrf::check($request);
        $id = (int) $request->param('id');
        $video = self::find($id);
        if ($video === null) {
            Session::flash('error', 'Video bulunamadı.');
        } else {
            Database::execute('DELETE FROM videos WHERE id = ?', [$id]);
            Log::info('admin', 'video deleted', ['video' => $id, 'youtube_id' => $video['youtube_id'], 'user' => Auth::user()['id'] ?? null]);
            Session::flash('success', '“' . $video['title'] . '” silindi.');
            if ($video['source'] !== 'manual') {
                Session::flash('info', 'Bu video otomatik senkronizasyonla gelmişti ve sonraki senkronizasyonda yeniden eklenebilir. Kalıcı olarak kaldırmak için silmek yerine durumunu “Gizli” yapın.');
            }
        }
        $back = AdminPage::safePath($request->input('_back'), '/admin/videolar');
        return Response::redirect(url(str_contains($back, '/' . $id . '/') ? '/admin/videolar' : $back));
    }

    /** @return array<string, string> category key => label */
    public static function categories(): array
    {
        if (class_exists(VideoRepository::class) && method_exists(VideoRepository::class, 'categories')) {
            $categories = VideoRepository::categories();
            if (is_array($categories) && $categories !== []) {
                return $categories;
            }
        }
        return self::CATEGORIES;
    }

    /** @return array<string, string> status => label (an unknown current status is kept selectable) */
    public static function statuses(string $current = ''): array
    {
        $statuses = self::STATUSES;
        if ($current !== '' && !isset($statuses[$current])) {
            $statuses[$current] = $current;
        }
        return $statuses;
    }

    /** YouTube video id from a watch/short/embed/youtu.be URL or a bare 11-character id. */
    public static function youtubeId(string $input): ?string
    {
        $input = trim($input);
        if (preg_match('/^[A-Za-z0-9_-]{11}$/', $input) === 1) {
            return $input;
        }
        $patterns = [
            '#^(?:https?://)?(?:www\.|m\.|music\.)?youtube(?:-nocookie)?\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/|live/|v/)([A-Za-z0-9_-]{11})#i',
            '#^(?:https?://)?youtu\.be/([A-Za-z0-9_-]{11})#i',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $input, $m) === 1) {
                return $m[1];
            }
        }
        return null;
    }

    private static function find(int $id): ?array
    {
        return $id > 0 ? Database::fetchOne('SELECT * FROM videos WHERE id = ?', [$id]) : null;
    }

    /** @param array<string, string> $errors */
    private function form(array $video, array $errors, int $status): Response
    {
        return AdminPage::render('video-form', 'Videoyu Düzenle', [
            'video' => $video,
            'errors' => $errors,
            'categories' => self::categories(),
            'statuses' => self::statuses((string) $video['status']),
            'publicUrl' => $video['status'] === 'published' ? url('/video/' . $video['slug']) : null,
        ], $status, [['name' => 'Videolar', 'url' => '/admin/videolar'], ['name' => 'Videoyu Düzenle']]);
    }
}
