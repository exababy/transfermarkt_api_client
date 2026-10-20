<?php

declare(strict_types=1);

namespace Tests;

use App\Controllers\Admin\AdminPage;
use App\Controllers\Admin\PostsController;
use App\Controllers\Admin\QueueController;
use App\Controllers\Admin\VideosController;
use App\Core\App;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Session;
use App\Core\Settings;
use App\Services\News\SourceRegistry;
use App\Support\Date;
use App\Support\OgImage;

final class AdminTest extends TestCase
{
    private const PASSWORD = 'admin-sifre-123';

    private ?Router $router = null;

    protected function setUp(): void
    {
        Database::insert('users', ['username' => 'admin', 'password_hash' => password_hash(self::PASSWORD, PASSWORD_DEFAULT), 'created_at' => Date::now()]);
    }

    public function testAdminRoutesRequireLogin(): void
    {
        foreach (['/admin', '/admin/haberler', '/admin/haberler/yeni', '/admin/haber-kuyrugu', '/admin/videolar', '/admin/ayarlar', '/admin/sifre'] as $path) {
            $response = $this->request('GET', $path);
            $this->assertSame(302, $response->status(), $path);
            $this->assertSame(url('/admin/giris'), $response->header('Location'), $path);
        }
        foreach (['/admin/islem/cache-clear', '/admin/haberler/1/sil', '/admin/ayarlar', '/admin/videolar/ekle', '/admin/haber-kuyrugu/1/atla'] as $path) {
            $this->assertSame(302, $this->request('POST', $path)->status(), 'POST ' . $path);
        }
    }

    public function testLoginPageAndGenericErrors(): void
    {
        $page = $this->request('GET', '/admin/giris');
        $this->assertSame(200, $page->status());
        $this->assertSame('noindex, nofollow', $page->header('X-Robots-Tag'));
        foreach (['<meta name="robots" content="noindex,nofollow">', 'name="_token"', 'autocomplete="current-password"', 'Yönetici Girişi'] as $needle) {
            $this->assertContains($needle, $page->body());
        }

        $expired = $this->request('POST', '/admin/giris', ['_token' => 'eski', 'username' => 'admin', 'password' => self::PASSWORD], false);
        $this->assertSame(419, $expired->status(), 'bad CSRF re-renders the form');
        $this->assertContains('Oturum süreniz doldu', $expired->body());
        $this->assertFalse(Auth::check());

        $wrong = $this->request('POST', '/admin/giris', ['username' => 'admin', 'password' => 'yanlis']);
        $this->assertSame(422, $wrong->status());
        $this->assertContains('Kullanıcı adı veya şifre hatalı.', $wrong->body());
        $unknown = $this->request('POST', '/admin/giris', ['username' => 'kimse', 'password' => 'yanlis']);
        $this->assertContains('Kullanıcı adı veya şifre hatalı.', $unknown->body(), 'same message for unknown users');
        $this->assertContains('value="kimse"', $unknown->body(), 'username kept');
    }

    public function testLoginThrottlesAfterFiveFailures(): void
    {
        $server = ['REMOTE_ADDR' => '10.9.8.7'];
        $statuses = [];
        for ($i = 1; $i <= Auth::MAX_ATTEMPTS; $i++) {
            $statuses[] = $this->request('POST', '/admin/giris', ['username' => 'admin', 'password' => 'yanlis' . $i], true, $server)->status();
        }
        $this->assertSame([422, 422, 422, 422, 429], $statuses);
        $blocked = $this->request('POST', '/admin/giris', ['username' => 'admin', 'password' => self::PASSWORD], true, $server);
        $this->assertSame(429, $blocked->status(), 'correct password is refused while throttled');
        $this->assertContains('Çok fazla başarısız deneme', $blocked->body());
        $this->assertFalse(Auth::check());
        $this->assertContains('Çok fazla başarısız deneme', $this->request('GET', '/admin/giris', [], true, $server)->body());

        // Another IP can still log in.
        $ok = $this->request('POST', '/admin/giris', ['username' => 'admin', 'password' => self::PASSWORD], true, ['REMOTE_ADDR' => '10.9.8.8']);
        $this->assertSame(302, $ok->status());
        $this->assertTrue(Auth::check());
    }

    public function testLoginRedirectsToIntendedPageAndLogout(): void
    {
        $this->request('GET', '/admin/haber-kuyrugu?durum=failed');
        $login = $this->request('POST', '/admin/giris', ['username' => 'admin', 'password' => self::PASSWORD]);
        $this->assertSame(302, $login->status());
        $this->assertSame(url('/admin/haber-kuyrugu'), $login->header('Location'));
        $this->assertTrue(Auth::check());
        $this->assertSame(302, $this->request('GET', '/admin/giris')->status(), 'logged-in users skip the form');

        $this->assertThrows(HttpException::class, fn () => $this->request('POST', '/admin/cikis', ['_token' => 'yanlis'], false), 'logout needs CSRF');
        $this->assertTrue(Auth::check());
        $logout = $this->request('POST', '/admin/cikis');
        $this->assertSame(url('/admin/giris'), $logout->header('Location'));
        Auth::reset();
        $this->assertFalse(Auth::check());
    }

    public function testDashboardShowsTilesCronAndLogs(): void
    {
        $this->login();
        Settings::set('news.daily_limit', 7);
        $now = Date::now();
        $this->insertPost(['slug' => 'oto-haber', 'is_spun' => 1, 'news_item_id' => 1, 'published_at' => $now]);
        $this->insertItem(['status' => 'pending']);
        $this->insertItem(['status' => 'failed']);
        Database::insert('cron_runs', ['task' => 'news-fetch', 'last_started_at' => $now, 'last_finished_at' => $now, 'last_status' => 'error', 'last_message' => 'Kaynak <hata>']);

        $html = $this->request('GET', '/admin')->body();
        foreach (['Günlük limit', '1 / 7', 'Kuyrukta bekleyen', 'Başarısız', 'Zamanlanmış görevler', 'Şimdi çalıştır', 'Haber toplama', 'Transfermarkt senkronizasyonu', 'Hiç çalışmadı', 'Kaynak &lt;hata&gt;', 'Son günlük kayıtları', 'action="' . url('/admin/islem/tm-sync') . '"', 'Hızlı bağlantılar'] as $needle) {
            $this->assertContains($needle, $html, 'dashboard');
        }
        $this->assertNotContains('Kaynak <hata>', $html, 'cron messages are escaped');
        $this->assertSame(1, substr_count($html, '<h1'), 'one h1 (layout top bar)');
    }

    public function testCreateEditAndDeletePost(): void
    {
        $this->login();
        $invalid = $this->request('POST', '/admin/haberler/yeni', ['title' => '', 'body' => '<script>x()</script>', 'status' => 'published', 'published_at' => '2026-02-31T10:00']);
        $this->assertSame(422, $invalid->status());
        foreach (['Başlık zorunludur.', 'Haber metni zorunludur.', 'Geçerli bir tarih ve saat girin.'] as $message) {
            $this->assertContains($message, $invalid->body());
        }

        $created = $this->request('POST', '/admin/haberler/yeni', [
            'title' => 'Ronaldo’dan Şampiyonlar Ligi’nde İnanılmaz Gol',
            'slug' => '',
            'summary' => '',
            'body' => '<h1>Ara başlık</h1><p onclick="x()">Cristiano Ronaldo <strong>bugün</strong> gol attı.<script>alert(1)</script></p>',
            'tags' => 'Al-Nassr, rekor , al-nassr,,Suudi Pro Lig',
            'status' => 'published',
            'is_indexable' => '1',
            'published_at' => '2026-10-20T14:30',
            'source_name' => 'Örnek Kaynak',
            'source_url' => 'https://example.com/haber',
        ]);
        $this->assertSame(302, $created->status());
        $post = Database::fetchOne('SELECT * FROM posts');
        $this->assertSame('ronaldodan-sampiyonlar-liginde-inanilmaz-gol', $post['slug']);
        $this->assertSame(url('/admin/haberler/' . $post['id'] . '/duzenle'), $created->header('Location'));
        $this->assertSame('<h2>Ara başlık</h2><p>Cristiano Ronaldo <strong>bugün</strong> gol attı.</p>', $post['body'], 'body sanitised');
        $this->assertSame('Cristiano Ronaldo bugün gol attı.', $post['summary'], 'summary generated from paragraphs');
        $this->assertSame('Al-Nassr, rekor, Suudi Pro Lig', $post['tags']);
        $this->assertSame('2026-10-20 11:30:00', $post['published_at'], 'Istanbul input stored as UTC');
        $this->assertSame(0, (int) $post['is_spun']);
        $this->assertSame(1, (int) $post['is_indexable']);
        $this->removeImage($post['image_path']);

        // Same title again: automatic slug gets a suffix; a manual duplicate slug is rejected.
        $this->request('POST', '/admin/haberler/yeni', ['title' => 'Ronaldo’dan Şampiyonlar Ligi’nde İnanılmaz Gol', 'body' => '<p>İkinci</p>', 'status' => 'draft']);
        $second = Database::fetchOne('SELECT * FROM posts WHERE id <> ?', [$post['id']]);
        $this->assertSame('ronaldodan-sampiyonlar-liginde-inanilmaz-gol-2', $second['slug']);
        $this->removeImage($second['image_path']);
        $conflict = $this->request('POST', '/admin/haberler/' . $second['id'] . '/duzenle', ['title' => 'İkinci', 'slug' => $post['slug'], 'body' => '<p>x</p>', 'status' => 'draft']);
        $this->assertSame(422, $conflict->status());
        $this->assertContains('Bu adres başka bir haberde kullanılıyor.', $conflict->body());
        $long = $this->request('POST', '/admin/haberler/' . $second['id'] . '/duzenle', ['title' => 'İkinci', 'summary' => str_repeat('ş', 501), 'body' => '<p>x</p>', 'status' => 'draft']);
        $this->assertContains('Özet en fazla 500 karakter olabilir', $long->body());

        $edit = $this->request('GET', '/admin/haberler/' . $post['id'] . '/duzenle');
        $this->assertSame(200, $edit->status());
        foreach (['value="ronaldodan-sampiyonlar-liginde-inanilmaz-gol"', 'Sitede görüntüle', 'data-counter="500"', 'Haberi sil'] as $needle) {
            $this->assertContains($needle, $edit->body());
        }
        $this->assertSame(404, $this->request('GET', '/admin/haberler/999/duzenle')->status());

        $updated = $this->request('POST', '/admin/haberler/' . $post['id'] . '/duzenle', [
            'title' => 'Ronaldo yeni rekor kırdı', 'slug' => 'ronaldo-rekor', 'summary' => 'Kısa özet.', 'body' => '<p>Yeni metin</p>',
            'tags' => '', 'status' => 'draft', 'published_at' => '',
        ]);
        $this->assertSame(302, $updated->status());
        $row = Database::fetchOne('SELECT * FROM posts WHERE id = ?', [$post['id']]);
        $this->assertSame('ronaldo-rekor', $row['slug']);
        $this->assertSame('draft', $row['status']);
        $this->assertSame(0, (int) $row['is_indexable'], 'unchecked checkbox');
        $this->assertNull($row['tags']);
        $this->assertSame('2026-10-20 11:30:00', $row['published_at'], 'empty date keeps the old value');
        $this->removeImage($row['image_path']);

        $list = $this->request('GET', '/admin/haberler?durum=draft&q=rekor');
        $this->assertContains('Ronaldo yeni rekor kırdı', $list->body());
        $this->assertNotContains('/haber/ronaldodan-sampiyonlar-liginde-inanilmaz-gol-2<', $list->body(), 'search filter');

        $this->assertThrows(HttpException::class, fn () => $this->request('POST', '/admin/haberler/' . $post['id'] . '/sil', [], false), 'delete needs CSRF');
        $deleted = $this->request('POST', '/admin/haberler/' . $post['id'] . '/sil', ['_back' => '/admin/haberler/' . $post['id'] . '/duzenle']);
        $this->assertSame(url('/admin/haberler'), $deleted->header('Location'));
        $this->assertNull(Database::fetchOne('SELECT id FROM posts WHERE id = ?', [$post['id']]));
    }

    public function testQueueListSkipAndPublishGuards(): void
    {
        $this->login();
        $primary = $this->insertItem(['title' => 'Ronaldo 950. golünü attı', 'source' => 'sozcu']);
        $member = $this->insertItem(['title' => 'Ronaldo tarihe geçti', 'source' => 'trthaber', 'status' => 'duplicate', 'note' => 'cluster:' . $primary]);
        $failed = $this->insertItem(['title' => 'Sözleşme açıklaması', 'source' => 'ntvspor', 'status' => 'failed', 'note' => 'cluster:' . $primary . '; low-uniqueness (31.5%)']);
        $published = $this->insertItem(['title' => 'Yayınlanmış haber', 'status' => 'published']);

        $html = $this->request('GET', '/admin/haber-kuyrugu')->body();
        foreach (['Ronaldo 950. golünü attı', 'Ana kayıt · +2', '#' . $primary . ' kümesi', 'Not: low-uniqueness (31.5%)', 'Şimdi yayınla', 'Atla'] as $needle) {
            $this->assertContains($needle, $html, 'queue');
        }
        $cluster = $this->request('GET', '/admin/haber-kuyrugu?kume=' . $primary)->body();
        $this->assertNotContains('Yayınlanmış haber', $cluster, 'cluster filter');
        $this->assertContains('Sözleşme açıklaması', $this->request('GET', '/admin/haber-kuyrugu?durum=failed&kaynak=ntvspor')->body());
        $this->assertNotContains('Ronaldo tarihe geçti', $this->request('GET', '/admin/haber-kuyrugu?durum=failed')->body());

        $skip = $this->request('POST', '/admin/haber-kuyrugu/' . $failed . '/atla', ['_back' => '/admin/haber-kuyrugu?durum=failed']);
        $this->assertSame(url('/admin/haber-kuyrugu?durum=failed'), $skip->header('Location'));
        $this->assertSame('skipped', Database::fetchValue('SELECT status FROM news_items WHERE id = ?', [$failed]));
        $this->request('POST', '/admin/haber-kuyrugu/' . $published . '/atla');
        $this->assertSame('published', Database::fetchValue('SELECT status FROM news_items WHERE id = ?', [$published]), 'published items cannot be skipped');
        $flashes = array_column(Session::flashes(), 'message');
        $this->assertContains('Yayınlanmış bir kayıt atlanamaz.', $flashes);

        $evil = $this->request('POST', '/admin/haber-kuyrugu/' . $member . '/atla', ['_back' => '//evil.example/admin']);
        $this->assertSame(url('/admin/haber-kuyrugu'), $evil->header('Location'), 'open redirect blocked');

        $this->assertSame($primary, QueueController::clusterOf('cluster:' . $primary . '; no-text'));
        $this->assertNull(QueueController::clusterOf('no-text'));
        $this->assertSame('low-uniqueness (31.5%)', QueueController::noteText('cluster:12; low-uniqueness (31.5%)'));
        $this->assertSame('', QueueController::noteText('cluster:12'));
    }

    public function testSettingsValidateSaveAndKeepSecrets(): void
    {
        $this->login();
        $page = $this->request('GET', '/admin/ayarlar');
        foreach (['Günlük yayın limiti', 'Haber kaynakları', 'Ana sayfa başlığı', 'G-XXXXXXXXXX', 'Anahtar üret', 'bin/cron.php', '*/10 * * * *'] as $needle) {
            $this->assertContains($needle, $page->body(), 'settings page');
        }

        $invalid = $this->request('POST', '/admin/ayarlar', $this->settingsForm([
            'news_daily_limit' => '51', 'spinner_min_change_ratio' => '0,95', 'analytics_ga4_id' => 'UA-123', 'indexnow_key' => 'kısa', 'site_portrait_url' => 'javascript:alert(1)', 'youtube_api_key' => 'x',
        ]));
        $this->assertSame(422, $invalid->status());
        foreach (['1 ile 50 arasında', '0,2 ile 0,9 arasında', '“G-” ile başlamalı', 'IndexNow anahtarı 8–128', 'site içi bir yol', 'API anahtarı geçersiz'] as $message) {
            $this->assertContains($message, $invalid->body());
        }
        $this->assertSame([], Settings::all(), 'nothing stored on error');

        $valid = $this->request('POST', '/admin/ayarlar', $this->settingsForm([
            'news_daily_limit' => '12', 'news_min_interval_minutes' => '30', 'spinner_min_change_ratio' => '0,55', 'news_index_spun_posts' => '1',
            'site_hero_title' => 'CR7: <b>Efsane</b>', 'site_portrait_url' => '/assets/img/og-default.png', 'analytics_ga4_id' => 'g-ab12cd34ef',
            'indexnow_key' => 'abcdef0123456789', 'youtube_api_key' => 'AIzaSyA1234567890abcdefghijklmnop',
        ]));
        $this->assertSame(302, $valid->status());
        Settings::flush();
        $this->assertSame(12, Settings::get('news.daily_limit'));
        $this->assertSame(30, Settings::get('news.min_interval_minutes'));
        $this->assertSame(0.55, Settings::get('spinner.min_change_ratio'));
        $this->assertSame(true, Settings::get('news.index_spun_posts'));
        $this->assertSame('CR7: Efsane', Settings::get('site.hero_title'), 'tags stripped');
        $this->assertSame('G-AB12CD34EF', Settings::get('analytics.ga4_id'));
        $this->assertSame('AIzaSyA1234567890abcdefghijklmnop', Settings::get('youtube.api_key'));
        $this->assertFalse(array_key_exists('site.hero_subtitle', Settings::all()), 'empty optional text is not stored');

        $html = $this->request('GET', '/admin/ayarlar')->body();
        $this->assertNotContains('AIzaSyA1234567890abcdefghijklmnop', $html, 'secret never printed');
        $this->assertContains('Kayıtlı: ••••••••mnop', $html);

        // Empty secret keeps the stored key; the clear checkbox removes it; empty text forgets the override.
        $this->request('POST', '/admin/ayarlar', $this->settingsForm(['news_daily_limit' => '12', 'youtube_api_key' => '', 'site_hero_title' => '']));
        Settings::flush();
        $this->assertSame('AIzaSyA1234567890abcdefghijklmnop', Settings::get('youtube.api_key'));
        $this->assertFalse(array_key_exists('site.hero_title', Settings::all()));
        $this->request('POST', '/admin/ayarlar', $this->settingsForm(['youtube_api_key_clear' => '1']));
        Settings::flush();
        $this->assertSame('', Settings::get('youtube.api_key'));

        $this->request('POST', '/admin/ayarlar', ['_action' => 'generate_indexnow']);
        $this->request('POST', '/admin/ayarlar', ['_action' => 'generate_cron']);
        Settings::flush();
        $this->assertTrue(preg_match('/^[0-9a-f]{32}$/', (string) Settings::get('indexnow.key')) === 1, 'IndexNow key: 32 hex chars');
        $token = (string) Settings::get('cron.web_token');
        $this->assertTrue(preg_match('/^[0-9a-f]{40}$/', $token) === 1, 'cron token');
        $this->assertContains('/cron/' . $token, $this->request('GET', '/admin/ayarlar')->body(), 'shown once after generating');
        $this->assertNotContains('/cron/' . $token, $this->request('GET', '/admin/ayarlar')->body(), 'masked afterwards');

        if (class_exists(SourceRegistry::class) && method_exists(SourceRegistry::class, 'defined') && count(SourceRegistry::defined()) > 1) {
            $keys = array_keys(SourceRegistry::defined());
            $this->request('POST', '/admin/ayarlar', $this->settingsForm(['sources_present' => '1', 'sources_enabled' => [$keys[0]]]));
            Settings::flush();
            $this->assertSame(array_slice($keys, 1), Settings::get('news.sources_disabled'));
            $none = $this->request('POST', '/admin/ayarlar', $this->settingsForm(['sources_present' => '1']));
            $this->assertContains('En az bir haber kaynağı etkin olmalıdır.', $none->body());
        }
    }

    public function testVideosListEditDelete(): void
    {
        $this->login();
        $now = Date::now();
        $id = Database::insert('videos', [
            'youtube_id' => 'YjerZUwzffQ', 'slug' => 'ronaldo-gol-yjerz', 'title' => 'Ronaldo’nun en güzel golleri', 'category' => 'goller',
            'source' => 'rss', 'status' => 'published', 'is_featured' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $list = $this->request('GET', '/admin/videolar?kategori=goller');
        $this->assertContains('Ronaldo’nun en güzel golleri', $list->body());
        $this->assertContains('dan video ekle', $list->body());
        $this->assertNotContains('Ronaldo’nun en güzel golleri', $this->request('GET', '/admin/videolar?kategori=belgesel')->body());

        $invalid = $this->request('POST', '/admin/videolar/' . $id . '/duzenle', ['title' => '', 'category' => 'yok', 'status' => 'garip']);
        $this->assertSame(422, $invalid->status());
        $this->assertContains('Başlık zorunludur.', $invalid->body());
        $this->assertContains('Geçerli bir kategori seçin.', $invalid->body());

        $this->request('POST', '/admin/videolar/' . $id . '/duzenle', ['title' => 'Yeni başlık', 'category' => 'belgesel', 'status' => 'hidden', 'is_featured' => '1', 'description' => "Satır 1\r\nSatır 2"]);
        $row = Database::fetchOne('SELECT * FROM videos WHERE id = ?', [$id]);
        $this->assertSame(['Yeni başlık', 'belgesel', 'hidden', 1, "Satır 1\nSatır 2"], [$row['title'], $row['category'], $row['status'], (int) $row['is_featured'], $row['description']]);
        $this->assertContains('Yeni başlık', $this->request('GET', '/admin/videolar?durum=hidden&one-cikan=1')->body());

        $this->request('POST', '/admin/videolar/ekle', ['url' => 'https://vimeo.com/123']);
        $this->assertNull(Database::fetchOne("SELECT id FROM videos WHERE youtube_id <> 'YjerZUwzffQ'"), 'invalid URL imports nothing');
        $messages = implode(' ', array_column(Session::flashes(), 'message'));
        $this->assertTrue(str_contains($messages, 'Video eklenemedi') || str_contains($messages, 'kurulu değil'), 'import error flashed: ' . $messages);

        $this->request('POST', '/admin/videolar/' . $id . '/sil');
        $this->assertNull(Database::fetchOne('SELECT id FROM videos WHERE id = ?', [$id]));

        $this->assertSame('YjerZUwzffQ', VideosController::youtubeId('https://www.youtube.com/watch?v=YjerZUwzffQ&t=10s'));
        $this->assertSame('YjerZUwzffQ', VideosController::youtubeId('https://youtu.be/YjerZUwzffQ?si=x'));
        $this->assertSame('YjerZUwzffQ', VideosController::youtubeId('https://www.youtube.com/shorts/YjerZUwzffQ'));
        $this->assertSame('YjerZUwzffQ', VideosController::youtubeId('YjerZUwzffQ'));
        $this->assertNull(VideosController::youtubeId('https://example.com/watch?v=YjerZUwzffQ'));
    }

    public function testActionsAndPasswordChange(): void
    {
        $this->login();
        // (cache-clear itself is not run here: storage/cache is shared with running dev servers)
        $this->assertThrows(HttpException::class, fn () => $this->request('POST', '/admin/islem/cache-clear', [], false), 'actions need CSRF');
        $response = $this->request('POST', '/admin/islem/bilinmeyen', ['_back' => '/admin/ayarlar']);
        $this->assertSame(url('/admin/ayarlar'), $response->header('Location'));
        $this->assertContains('Bilinmeyen işlem: bilinmeyen', implode(' ', array_column(Session::flashes(), 'message')));

        $errors = $this->request('POST', '/admin/sifre', ['current_password' => 'yanlis', 'new_password' => 'kisa', 'new_password_confirmation' => 'kisa']);
        $this->assertSame(422, $errors->status());
        $this->assertContains('Mevcut şifre hatalı.', $errors->body());
        $this->assertContains('en az 10 karakter', $errors->body());
        $mismatch = $this->request('POST', '/admin/sifre', ['current_password' => self::PASSWORD, 'new_password' => 'YeniSifre-2026', 'new_password_confirmation' => 'Baska-2026']);
        $this->assertContains('eşleşmiyor', $mismatch->body());
        $ok = $this->request('POST', '/admin/sifre', ['current_password' => self::PASSWORD, 'new_password' => 'YeniSifre-2026', 'new_password_confirmation' => 'YeniSifre-2026']);
        $this->assertSame(302, $ok->status());
        $this->assertTrue(password_verify('YeniSifre-2026', (string) Database::fetchValue('SELECT password_hash FROM users')));
    }

    public function testAdminPageHelpers(): void
    {
        $this->assertSame('/admin/haberler?durum=draft', AdminPage::safePath('/admin/haberler?durum=draft'));
        foreach (['//evil.com', 'https://evil.com/admin', '/adminx', "/admin\r\nX: y", '/admin//x', '/haberler'] as $bad) {
            $this->assertSame('/admin', AdminPage::safePath($bad), 'unsafe path ' . json_encode($bad));
        }
        $this->assertSame('2026-10-20 11:30:00', AdminPage::fromLocalInput('2026-10-20T14:30'));
        $this->assertNull(AdminPage::fromLocalInput('2026-02-30T10:00'));
        $this->assertNull(AdminPage::fromLocalInput('dün'));
        $this->assertSame('2026-10-20T14:30', AdminPage::toLocalInput('2026-10-20 11:30:00'));
        $this->assertSame('', AdminPage::toLocalInput(null));
        $this->assertSame('••••••••mnop', AdminPage::mask('abcdefghijklmnop'));
        $this->assertSame('••••••••', AdminPage::mask('short'));
        $this->assertSame('', AdminPage::mask(''));
        $this->assertSame(['page' => 3, 'pages' => 3, 'offset' => 40, 'total' => 45, 'perPage' => 20], AdminPage::paginate(45, 20, 9));
        $this->assertSame('Al-Nassr, rekor, Şampiyonlar Ligi', PostsController::parseTags(' Al-Nassr ,rekor,REKOR, ,<b>Şampiyonlar</b>  Ligi'));
    }

    // ------------------------------------------------------------------ helpers

    private function login(): void
    {
        $this->assertTrue(Auth::attempt('admin', self::PASSWORD, '127.0.0.1'), 'login');
    }

    /** Dispatch through routes.php; POSTs get the session CSRF token unless $withToken is false. */
    private function request(string $method, string $uri, array $post = [], bool $withToken = true, array $server = []): Response
    {
        if ($this->router === null) {
            $this->router = new Router();
            (require App::basePath('routes.php'))($this->router);
        }
        if ($method === 'POST' && $withToken && !isset($post['_token'])) {
            $post['_token'] = Csrf::token();
        }
        $request = Request::create($method, $uri, $post, $server);
        App::setRequest($request);
        Auth::reset();
        return $this->router->dispatch($request);
    }

    /** @param array<string, mixed> $overrides */
    private function settingsForm(array $overrides = []): array
    {
        return $overrides + [
            'news_daily_limit' => '10', 'news_min_interval_minutes' => '60', 'spinner_min_change_ratio' => '0,4',
            'site_hero_title' => '', 'site_hero_subtitle' => '', 'site_portrait_url' => '', 'analytics_ga4_id' => '', 'indexnow_key' => '',
            'youtube_api_key' => '', 'cron_web_token' => '',
        ];
    }

    private function insertPost(array $data): int
    {
        $now = Date::now();
        return Database::insert('posts', $data + [
            'slug' => 'haber-' . bin2hex(random_bytes(3)), 'title' => 'Test haberi', 'summary' => 'Özet', 'body' => '<p>Metin</p>',
            'status' => 'published', 'published_at' => $now, 'updated_at' => $now, 'created_at' => $now,
        ]);
    }

    private function insertItem(array $data): int
    {
        $url = 'https://example.com/' . bin2hex(random_bytes(4));
        $title = (string) ($data['title'] ?? 'Ronaldo haberi');
        return Database::insert('news_items', $data + [
            'source' => 'sozcu', 'source_name' => 'Sözcü', 'url' => $url, 'url_hash' => sha1($url), 'title' => $title,
            'title_norm' => mb_strtolower($title), 'published_at' => Date::now(), 'fetched_at' => Date::now(), 'status' => 'pending',
        ]);
    }

    private function removeImage(mixed $path): void
    {
        if (is_string($path) && $path !== '') {
            if (class_exists(OgImage::class) && method_exists(OgImage::class, 'delete')) {
                OgImage::delete($path);
            } else {
                @unlink(App::basePath('public' . $path));
            }
        }
    }
}
