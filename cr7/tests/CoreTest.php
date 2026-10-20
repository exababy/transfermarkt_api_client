<?php

declare(strict_types=1);

namespace Tests;

use App\Core\App;
use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Seo;
use App\Core\Settings;
use App\Core\View;
use App\Install\Installer;
use App\Support\Cache;
use App\Support\Date;
use App\Support\Format;
use App\Support\Html;
use App\Support\Str;

final class CoreTest extends TestCase
{
    public function testSlugHandlesTurkishCharacters(): void
    {
        $this->assertSame('cristiano-ronaldonun-inanilmaz-golu', Str::slug("Cristiano Ronaldo'nun İnanılmaz Golü!"));
        $this->assertSame('sampiyonlar-liginde-cilgin-gece', Str::slug('Şampiyonlar Ligi’nde ÇILGIN Gece'));
        $this->assertSame('joao-felix-ve-ronaldo', Str::slug('João Félix ve Ronaldo'));
        $this->assertSame('al-nassr-2-1-al-hilal', Str::slug('  Al-Nassr 2–1 Al Hilal  '));
        $this->assertSame('bir-iki-uc', Str::slug('bir iki üç dört beş', 12), 'cut on word boundary');
        $this->assertSame('', Str::slug('!!!'));
    }

    public function testTurkishCaseMapping(): void
    {
        $this->assertSame('ığdır istanbul', Str::lower('IĞDIR İSTANBUL'));
        $this->assertSame('İSTANBUL ILIK', Str::upper('istanbul ılık'));
    }

    public function testLimitNormalizeAndSimilarity(): void
    {
        $limited = Str::limit('Cristiano Ronaldo kariyerinin 900. golünü attı ve tarihe geçti', 30);
        $this->assertTrue(mb_strlen($limited) <= 30, 'limit length');
        $this->assertSame('Cristiano Ronaldo kariyerinin…', $limited);
        $this->assertSame('kisa', Str::limit('kisa', 10));
        $this->assertSame('ronaldonun golu', Str::normalize('Ronaldo’nun GOLÜ'));
        $this->assertSame(1.0, Str::similarity('Ronaldo yeni rekor kırdı', 'RONALDO YENİ REKOR KIRDI'));
        $this->assertSame(0.0, Str::similarity('Ronaldo gol attı', 'Messi asist yaptı'));
        $sim = Str::similarity('Ronaldo Al-Nassr ile sözleşme uzattı', 'Ronaldo Al-Nassr ile sözleşmesini uzattı');
        $this->assertTrue($sim > 0.5 && $sim < 1.0, 'partial similarity ' . $sim);
        $this->assertSame(32, strlen(Str::random()));
        $this->assertTrue(preg_match('/^[A-Za-z0-9]{12}$/', Str::random(12)) === 1);
    }

    public function testHtmlSanitizeStripsDangerousMarkup(): void
    {
        $dirty = '<h1>Başlık</h1><p onclick="x()" style="color:red">Merhaba <strong>dünya</strong><script>alert(1)</script></p>'
            . '<img src=x onerror="alert(1)"><a href="javascript:alert(1)">kötü</a><a href=" JaVa&#x0A;script:alert(1)">gizli</a>'
            . '<a href="https://example.com/x" onclick="y()">dış</a><a href="/haberler">iç</a><div>blok <em>metin</em></div>'
            . '<iframe src="https://evil.test"></iframe><!-- yorum --><style>p{}</style>';
        $clean = Html::sanitize($dirty);
        foreach (['<script', 'alert', 'onerror', 'onclick', 'javascript:', 'style', '<img', '<iframe', '<div', 'yorum'] as $bad) {
            $this->assertNotContains($bad, $clean, 'sanitized output');
        }
        $this->assertContains('<h2>Başlık</h2>', $clean);
        $this->assertContains('<p>Merhaba <strong>dünya</strong></p>', $clean);
        $this->assertContains('<a href="https://example.com/x" rel="nofollow noopener" target="_blank">dış</a>', $clean);
        $this->assertContains('<a href="/haberler">iç</a>', $clean);
        $this->assertContains('kötü', $clean, 'unsafe links are unwrapped, text kept');
        $this->assertContains('<em>metin</em>', $clean);
        $this->assertSame("Başlık\nMerhaba dünya & co", Html::toText('<h2>Başlık</h2><p>Merhaba <b>dünya</b> &amp; co</p><script>x</script>'));
    }

    public function testFormatHelpers(): void
    {
        $this->assertSame('120 mil. €', Format::money(120000000));
        $this->assertSame('12,5 mil. €', Format::money(12500000));
        $this->assertSame('500 bin €', Format::money(500000));
        $this->assertSame('1,5 mlr. €', Format::money(1500000000));
        $this->assertSame('-', Format::money(null));
        $this->assertSame('1.234.567', Format::number(1234567));
        $this->assertSame('12,5', Format::number(12.5, 1));
        $this->assertSame('-', Format::number(null));
        $this->assertSame('1.299,90 TL', Format::price(1299.9));
        $this->assertSame('20 Ekim 2026 Salı', Format::date('2026-10-20 10:00:00', 'j F Y l'));
        $this->assertSame('1 Ocak 2027 01:30', Format::date('2026-12-31 22:30:00', 'j F Y H:i'), 'UTC => Istanbul');
        $this->assertSame('05 Şub 1985', Format::date('1985-02-05', 'd M Y'));
        $this->assertSame('2026-10-20T13:00:00+03:00', Format::iso('2026-10-20 10:00:00'));
        $this->assertSame('', Format::date(null));
        $this->assertSame('3 saat önce', Format::relative(gmdate('Y-m-d H:i:s', time() - 3 * 3600 - 5)));
        $this->assertSame('az önce', Format::relative(Date::now()));
        $this->assertSame('2026-10-20 10:00:00', Date::toUtc('2026-10-20 13:00:00+03:00'));
        $this->assertSame('1970-01-01 00:00:10', Date::toUtc(10));
        $this->assertThrows(\InvalidArgumentException::class, static fn () => Date::toUtc('not a date at all'));
    }

    public function testRouterMatchesParamsAndRedirectsTrailingSlash(): void
    {
        $router = new Router();
        $router->get('/haber/{slug}', static fn (Request $r): Response => Response::text('slug=' . $r->param('slug')));
        $router->get('/admin/haberler/{id}/duzenle', static fn (Request $r): Response => Response::text('id=' . $r->param('id')));
        $router->get('/sitemap-{type}.xml', static fn (Request $r): Response => Response::text('type=' . $r->param('type')));
        $router->post('/form', static fn (): Response => Response::text('posted'));

        $ok = $router->dispatch(Request::create('GET', '/haber/ronaldo-gol-attı'));
        $this->assertSame(404, $ok->status(), 'non-ascii slug does not match');
        $this->assertSame('slug=ronaldo-gol', $router->dispatch(Request::create('GET', '/haber/ronaldo-gol?x=1'))->body());
        $this->assertSame('id=42', $router->dispatch(Request::create('GET', '/admin/haberler/42/duzenle'))->body());
        $this->assertSame(404, $router->dispatch(Request::create('GET', '/admin/haberler/abc/duzenle'))->status());
        $this->assertSame('type=posts', $router->dispatch(Request::create('GET', '/sitemap-posts.xml'))->body());
        $this->assertSame(404, $router->dispatch(Request::create('GET', '/sitemap-postsxxml'))->status(), 'dot is literal');

        $redirect = $router->dispatch(Request::create('GET', '/haber/ronaldo-gol/?sayfa=2'));
        $this->assertSame(301, $redirect->status());
        $this->assertSame('/haber/ronaldo-gol?sayfa=2', $redirect->header('Location'));
        $this->assertSame('/haber/x', $router->dispatch(Request::create('GET', '//haber//x'))->header('Location'));
        foreach (['/%5Cevil.example/', '/%09/evil.example/', '/%5C%5Cevil.example', '/haber/x%0D%0A/'] as $uri) {
            $this->assertSame(404, $router->dispatch(Request::create('GET', $uri))->status(), $uri . ' is no off-site redirect');
        }
        $this->assertSame('/evil.example', $router->dispatch(Request::create('GET', '/%2F%2Fevil.example/'))->header('Location'));

        $head = $router->dispatch(Request::create('HEAD', '/haber/x'));
        $this->assertSame(200, $head->status(), 'HEAD handled as GET');

        $this->assertSame('posted', $router->dispatch(Request::create('POST', '/form'))->body());
        $notAllowed = $router->dispatch(Request::create('GET', '/form'));
        $this->assertSame(405, $notAllowed->status());
        $this->assertSame('POST', $notAllowed->header('Allow'));

        $missing = $router->dispatch(Request::create('GET', '/yok-boyle-sayfa'));
        $this->assertSame(404, $missing->status());
        $this->assertContains('noindex', $missing->body());
        $this->assertContains('Aradığınız sayfa bulunamadı', $missing->body());
    }

    public function testRoutesFileReferencesExistingHandlers(): void
    {
        $router = new Router();
        (require App::basePath('routes.php'))($router);
        $routes = $router->routes();
        $this->assertTrue(count($routes) >= 60, 'route count ' . count($routes));
        foreach ($routes as $route) {
            [$class, $method] = $route['handler'];
            $this->assertTrue(method_exists($class, $method), "{$class}::{$method} exists");
        }
    }

    public function testDatabaseUpsertAndHelpers(): void
    {
        $now = Date::now();
        Database::upsert('clubs', ['id' => '418', 'name' => 'Real Madrid', 'slug' => 'real-madrid', 'updated_at' => $now], ['id']);
        Database::upsert('clubs', ['id' => '418', 'name' => 'Real Madrid CF', 'slug' => 'real-madrid', 'updated_at' => $now], ['id']);
        $this->assertSame('Real Madrid CF', Database::fetchValue('SELECT name FROM clubs WHERE id = ?', ['418']));
        $this->assertSame(1, (int) Database::fetchValue('SELECT COUNT(*) FROM clubs'));

        // Only selected columns are updated on conflict.
        Database::upsert('clubs', ['id' => '418', 'name' => 'Ignored', 'slug' => 'rm', 'updated_at' => $now], ['id'], ['slug']);
        $row = Database::fetchOne('SELECT name, slug FROM clubs WHERE id = :id', ['id' => '418']);
        $this->assertSame(['name' => 'Real Madrid CF', 'slug' => 'rm'], $row);

        // upsertMany: chunking (1000 rows x 4 columns) + updates of existing keys.
        $rows = [];
        for ($i = 1; $i <= 1000; $i++) {
            $rows[] = ['id' => 'c' . $i, 'name' => 'Club ' . $i, 'slug' => 'club-' . $i, 'updated_at' => $now];
        }
        Database::upsertMany('clubs', $rows, ['id']);
        $rows[0]['name'] = 'Changed';
        Database::upsertMany('clubs', array_slice($rows, 0, 5), ['id']);
        $this->assertSame(1001, (int) Database::fetchValue('SELECT COUNT(*) FROM clubs'));
        $this->assertSame('Changed', Database::fetchValue('SELECT name FROM clubs WHERE id = ?', ['c1']));

        // Composite conflict keys.
        $stat = ['season_id' => 2024, 'season_label' => '24/25', 'competition_id' => 'SA1', 'club_id' => '18544', 'goals' => 10, 'updated_at' => $now];
        Database::upsertMany('stats_season', [$stat, ['goals' => 25] + $stat], ['season_id', 'competition_id', 'club_id']);
        $this->assertSame(25, (int) Database::fetchValue('SELECT goals FROM stats_season'));

        $id = Database::insert('users', ['username' => 'tester', 'password_hash' => 'x', 'created_at' => $now]);
        $this->assertTrue($id > 0, 'insert id');
        $this->assertSame(1, Database::update('users', ['last_login_at' => $now], 'id = ?', [$id]));
        $this->assertSame(1, Database::update('users', ['username' => 'tester2'], 'id = :id', ['id' => $id]));
        $this->assertNull(Database::fetchOne('SELECT * FROM users WHERE username = ?', ['nobody']));
        $this->assertNull(Database::fetchValue('SELECT id FROM users WHERE username = ?', ['nobody']));

        $this->assertThrows(\RuntimeException::class, static function () use ($now): void {
            Database::transaction(static function () use ($now): void {
                Database::insert('users', ['username' => 'rolled-back', 'password_hash' => 'x', 'created_at' => $now]);
                throw new \RuntimeException('boom');
            });
        });
        $this->assertNull(Database::fetchValue('SELECT id FROM users WHERE username = ?', ['rolled-back']), 'transaction rolled back');

        Database::insert('users', ['username' => 'a_b%c', 'password_hash' => 'x', 'created_at' => $now]);
        Database::insert('users', ['username' => 'aXbYc', 'password_hash' => 'x', 'created_at' => $now]);
        $like = Database::fetchAll("SELECT username FROM users WHERE username LIKE ? ESCAPE '\\'", ['%' . Database::likeEscape('_b%') . '%']);
        $this->assertSame([['username' => 'a_b%c']], $like);

        $this->assertSame('"clubs"', Database::quoteIdent('clubs'));
        $this->assertThrows(\InvalidArgumentException::class, static fn () => Database::quoteIdent('clubs; DROP TABLE x'));
        $this->assertThrows(\InvalidArgumentException::class, static fn () => Database::upsert('clubs', ['id' => '1'], ['missing']));
    }

    public function testInstallerSchemaCreatesEveryTable(): void
    {
        $expected = ['settings', 'users', 'login_attempts', 'api_cache', 'cron_runs', 'player', 'clubs', 'competitions',
            'stats_season', 'stats_club', 'stats_competition', 'stats_summary', 'games', 'transfers', 'market_values',
            'national_career', 'injuries', 'news_items', 'posts', 'videos', 'products', 'orders', 'slug_redirects'];
        $tables = array_column(Database::fetchAll("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"), 'name');
        foreach ($expected as $table) {
            $this->assertContains($table, $tables, 'table');
        }
        $this->assertCount(count($expected), $tables, 'no unexpected tables');
        $mysql = Installer::schemaStatements('mysql');
        $this->assertCount(count($expected), $mysql, 'mysql statements');
        $this->assertFalse(Installer::isInstalled());
    }

    public function testCsrfTokenVerification(): void
    {
        $token = Csrf::token();
        $this->assertSame(64, strlen($token));
        $this->assertSame($token, Csrf::token(), 'stable within a session');
        $this->assertTrue(Csrf::verify($token));
        $this->assertFalse(Csrf::verify('wrong'));
        $this->assertFalse(Csrf::verify(null));
        $this->assertContains('name="_token"', Csrf::field());

        Csrf::check(Request::create('POST', '/x', ['_token' => $token]));
        Csrf::check(Request::create('POST', '/x', [], ['HTTP_X_CSRF_TOKEN' => $token]));
        $this->assertThrows(HttpException::class, static fn () => Csrf::check(Request::create('POST', '/x', ['_token' => 'bad'])));
    }

    public function testSettingsFallBackToConfig(): void
    {
        Config::set('news.daily_limit', 10);
        $this->assertSame(10, Settings::get('news.daily_limit'));
        $this->assertSame('varsayılan', Settings::get('yok.boyle.ayar', 'varsayılan'));

        Settings::set('news.daily_limit', 7);
        $this->assertSame(7, Settings::get('news.daily_limit'));
        Settings::flush();
        $this->assertSame(7, Settings::get('news.daily_limit'), 'persisted as JSON');
        $this->assertSame('7', Database::fetchValue('SELECT setting_value FROM settings WHERE setting_key = ?', ['news.daily_limit']));

        Settings::set('payment.paytr.merchant_id', 'M123');
        Settings::set('shop.orders_enabled', true);
        $paytr = Settings::get('payment.paytr');
        $this->assertSame('M123', $paytr['merchant_id'], 'stored override merged into config array');
        $this->assertSame(false, $paytr['test_mode'], 'config value kept (PayTR test mode is off by default)');
        $this->assertSame(true, setting('shop.orders_enabled'));
        $this->assertSame(['news.daily_limit', 'payment.paytr.merchant_id', 'shop.orders_enabled'], array_keys(Settings::all()));

        Settings::forget('news.daily_limit');
        $this->assertSame(10, Settings::get('news.daily_limit'));
    }

    public function testAuthThrottlesFailedLogins(): void
    {
        Database::insert('users', ['username' => 'admin', 'password_hash' => password_hash('dogru-sifre', PASSWORD_DEFAULT), 'created_at' => Date::now()]);
        $this->assertFalse(Auth::check());
        for ($i = 0; $i < Auth::MAX_ATTEMPTS; $i++) {
            $this->assertFalse(Auth::attempt('admin', 'yanlis', '10.0.0.1'));
        }
        $this->assertTrue(Auth::tooManyAttempts('10.0.0.1'));
        $this->assertFalse(Auth::attempt('admin', 'dogru-sifre', '10.0.0.1'), 'blocked while throttled');
        $this->assertFalse(Auth::tooManyAttempts('10.0.0.2'));
        $this->assertFalse(Auth::attempt('yok', 'dogru-sifre', '10.0.0.2'), 'unknown user');
        $this->assertTrue(Auth::attempt('admin', 'dogru-sifre', '10.0.0.2'));
        $this->assertTrue(Auth::check());
        $this->assertSame('admin', Auth::user()['username']);
        $this->assertFalse(isset(Auth::user()['password_hash']), 'hash not exposed');
        $this->assertNull(Auth::require());
        Auth::logout();
        $this->assertFalse(Auth::check());
        $this->assertSame(302, Auth::require()?->status());
    }

    public function testThrottleIsAtomicPerIpv6NetworkAndPasswordChangeEndsOtherSessions(): void
    {
        Database::insert('users', ['username' => 'admin', 'password_hash' => password_hash('dogru-sifre', PASSWORD_DEFAULT), 'created_at' => Date::now()]);
        // Reservations are counted before the password check: no more than MAX_ATTEMPTS checks per window.
        $reserved = [];
        for ($i = 0; $i < Auth::MAX_ATTEMPTS + 3; $i++) {
            $reserved[] = Auth::reserveAttempt('10.0.0.9', 'admin');
        }
        $this->assertCount(Auth::MAX_ATTEMPTS, array_filter($reserved, 'is_int'), 'parallel checks capped');
        $this->assertSame(Auth::MAX_ATTEMPTS, (int) Database::fetchValue("SELECT COUNT(*) FROM login_attempts WHERE ip = '10.0.0.9'"), 'refused ones are not stored');
        $this->assertFalse(Auth::attempt('admin', 'dogru-sifre', '10.0.0.9'), 'even the right password is not checked');

        $this->assertSame('2001:db8:1:2::/64', Auth::throttleKey('2001:db8:1:2:aaaa:bbbb:cccc:dddd'));
        $this->assertSame('88.247.1.2', Auth::throttleKey('88.247.1.2'));
        for ($i = 1; $i <= Auth::MAX_ATTEMPTS; $i++) {
            $this->assertFalse(Auth::attempt('admin', 'yanlis', '2001:db8:1:2::' . dechex($i)));
        }
        $this->assertTrue(Auth::tooManyAttempts('2001:db8:1:2:ffff::1'), 'rotating addresses inside one /64 does not help');
        $this->assertFalse(Auth::tooManyAttempts('2001:db8:1:3::1'));

        $this->assertTrue(Auth::attempt('admin', 'dogru-sifre', '10.0.0.10'));
        Auth::reset();
        $this->assertTrue(Auth::check());
        Database::update('users', ['password_hash' => password_hash('yeni-sifre-123', PASSWORD_DEFAULT)], 'username = ?', ['admin']);
        Auth::reset();
        $this->assertFalse(Auth::check(), 'a session from before the password change is logged out');
        $this->assertTrue(Auth::attempt('admin', 'yeni-sifre-123', '10.0.0.10'));
        $hash = password_hash('ucuncu-sifre-1', PASSWORD_DEFAULT);
        Database::update('users', ['password_hash' => $hash], 'username = ?', ['admin']);
        Auth::rememberPassword($hash);
        $this->assertTrue(Auth::check(), 'the session that changed the password stays logged in');
    }

    public function testRequestIpTrustsProxyHeadersOnlyFromTrustedProxies(): void
    {
        $headers = ['HTTP_X_FORWARDED_FOR' => '6.6.6.6, 88.247.1.2', 'HTTP_CF_CONNECTING_IP' => '88.247.1.2', 'HTTP_X_FORWARDED_PROTO' => 'https'];
        $direct = Request::create('GET', '/', [], ['REMOTE_ADDR' => '198.51.100.20'] + $headers);
        $viaCloudflare = Request::create('GET', '/', [], ['REMOTE_ADDR' => '162.158.10.1'] + $headers);
        $this->assertSame('198.51.100.20', $direct->ip(), 'trust_proxy off');
        $this->assertSame('162.158.10.1', $viaCloudflare->ip());

        Config::set('app.trust_proxy', true);
        $this->assertSame('198.51.100.20', $direct->ip(), 'headers from a non-proxy client are ignored');
        $this->assertFalse($direct->isHttps());
        $this->assertSame('88.247.1.2', $viaCloudflare->ip(), 'right-most hop that is not a trusted proxy');
        $this->assertTrue($viaCloudflare->isHttps());
        $cfOnly = Request::create('GET', '/', [], ['REMOTE_ADDR' => '2606:4700:10::6816:1', 'HTTP_CF_CONNECTING_IP' => '2a02:4e0:1::5']);
        $this->assertSame('2a02:4e0:1::5', $cfOnly->ip(), 'CF-Connecting-IP from a Cloudflare IPv6 edge');
        $spoofed = Request::create('GET', '/', [], ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '10.9.1.1', 'HTTP_CF_CONNECTING_IP' => '203.0.113.1']);
        $this->assertSame('127.0.0.1', $spoofed->ip(), 'loopback is not trusted unless configured');

        Config::set('app.trusted_proxies', ['127.0.0.1', '10.0.0.0/8']);
        $chain = Request::create('GET', '/', [], ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '1.1.1.1, 88.247.1.2, 10.2.3.4']);
        $this->assertSame('88.247.1.2', $chain->ip(), 'own proxies skipped from the right');
        $this->assertSame('127.0.0.1', Request::create('GET', '/', [], ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => 'bozuk'])->ip());

        $this->assertTrue(Request::ipInRange('2a06:98c7::1', '2a06:98c0::/29'));
        $this->assertFalse(Request::ipInRange('2a06:98c8::1', '2a06:98c0::/29'));
        $this->assertTrue(Request::ipInRange('172.71.255.255', '172.64.0.0/13'));
        $this->assertFalse(Request::ipInRange('172.72.0.0', '172.64.0.0/13'));
        $this->assertFalse(Request::ipInRange('10.0.0.1', '2606:4700::/32'));
        $this->assertFalse(Request::ipInRange('10.0.0.1', '10.0.0.0/x'));
        $this->assertTrue(Request::ipInRange('10.0.0.1', '10.0.0.1'));
    }

    public function testSeoAndLayoutOutput(): void
    {
        $seo = new Seo(title: 'İstatistikler', description: 'Ronaldo istatistikleri', breadcrumbs: [['name' => 'İstatistikler']]);
        $this->assertSame('İstatistikler | CR7.com.tr', $seo->fullTitle());
        $this->assertTrue($seo->isIndexable());
        $this->assertFalse((new Seo('Taslak', robots: 'noindex,follow'))->isIndexable());
        $this->assertSame('CR7.com.tr', (new Seo(''))->fullTitle());
        $trail = $seo->breadcrumbTrail();
        $this->assertSame('Ana Sayfa', $trail[0]['name']);
        $this->assertSame(url('/'), $trail[0]['url']);
        $data = $seo->structuredData();
        $this->assertSame('BreadcrumbList', $data[0]['@type']);
        $this->assertSame(2, $data[0]['itemListElement'][1]['position']);

        $html = View::render('pages/coming-soon', ['seo' => $seo, 'title' => 'İstatistikler']);
        foreach (['<html lang="tr"', '<title>İstatistikler | CR7.com.tr</title>', 'og:title', 'twitter:card', 'application/ld+json', 'Bağımsız hayran sitesi', 'aria-current="page"'] as $needle) {
            $this->assertContains($needle, $html, 'layout');
        }
        $this->assertSame('/haberler?sayfa=2', page_url('/haberler', 2));
        $this->assertSame('/videolar?kategori=goller', page_url('/videolar?kategori=goller&sayfa=3', 1));
    }

    public function testSubFolderInstallPrefixesUrlsAndLinks(): void
    {
        Config::set('app.url', 'https://cr7.com.tr');
        $root = Response::html('<a href="/hayati">x</a>');
        $this->assertSame('<a href="/hayati">x</a>', App::prefixHtml($root)->body(), 'root installs are untouched');
        $this->assertSame('https://cr7.com.tr/hayati', url('/hayati'));

        Config::set('app.url', 'https://ornek.com/cr7');
        $this->assertSame('/cr7', App::urlPrefix(), 'prefix follows app.url');
        $this->assertSame('https://ornek.com/cr7/hayati', url('/hayati'));
        $this->assertSame('https://ornek.com/cr7/assets/img/logo.svg', url('/cr7/assets/img/logo.svg'), 'already-prefixed paths are not doubled');
        $this->assertSame('https://ornek.com/cr7/cr7-golleri', url('/cr7-golleri'));
        $this->assertTrue(str_starts_with(asset('css/app.css'), '/cr7/assets/css/app.css'));

        $html = '<a href="/hayati">a</a> <a href="/cr7/kariyer">b</a> <a href="//cdn.example/x">c</a> <a href="https://x.test/">d</a>'
            . ' <img src="/og/a.png" srcset="/og/a.png 1x, /cr7/og/b.png 2x" alt=""> <form action="/arama"></form> <a href="#top">e</a>';
        $fixed = App::prefixHtml(Response::html($html))->body();
        $this->assertSame('<a href="/cr7/hayati">a</a> <a href="/cr7/kariyer">b</a> <a href="//cdn.example/x">c</a> <a href="https://x.test/">d</a>'
            . ' <img src="/cr7/og/a.png" srcset="/cr7/og/a.png 1x, /cr7/og/b.png 2x" alt=""> <form action="/cr7/arama"></form> <a href="#top">e</a>', $fixed);
        $xml = Response::xml('<loc>/hayati</loc><a href="/x"/>');
        $this->assertSame('<loc>/hayati</loc><a href="/x"/>', App::prefixHtml($xml)->body(), 'only HTML responses are rewritten');
        Config::set('app.url', 'https://cr7.com.tr');
        $this->assertSame('', App::urlPrefix());
    }

    public function testFailedCronTaskIsRetriedWithBackoff(): void
    {
        $isDue = new \ReflectionMethod(\App\Cron\Scheduler::class, 'isDue');
        $runTask = new \ReflectionMethod(\App\Cron\Scheduler::class, 'runTask');
        $failing = new class () implements \App\Cron\Task {
            public function name(): string { return 'tm-sync'; }
            public function intervalMinutes(): int { return 360; }
            public function run(): string { throw new \RuntimeException('Transfermarkt API: zaman aşımı'); }
        };
        $due = static function (int $minutesAgo) use ($isDue): bool {
            Database::update('cron_runs', ['last_started_at' => Date::ago($minutesAgo * 60)], 'task = ?', ['tm-sync']);
            return $isDue->invoke(null, 'tm-sync', 360);
        };
        $this->assertSame('error', $runTask->invoke(null, 'tm-sync', $failing)['status']);
        $this->assertFalse($due(10));
        $this->assertTrue($due(16), 'first retry after 15 minutes, not 6 hours');
        $runTask->invoke(null, 'tm-sync', $failing);
        $this->assertFalse($due(20));
        $this->assertTrue($due(31), 'second retry after 30 minutes');
        for ($i = 0; $i < 6; $i++) {
            $runTask->invoke(null, 'tm-sync', $failing);
        }
        $this->assertFalse($due(300), 'backoff never exceeds the interval');
        $this->assertTrue($due(361));
        $ok = new class () implements \App\Cron\Task {
            public function name(): string { return 'tm-sync'; }
            public function intervalMinutes(): int { return 360; }
            public function run(): string { return 'tamam'; }
        };
        $this->assertSame('ok', $runTask->invoke(null, 'tm-sync', $ok)['status']);
        $this->assertSame(0, Settings::get('cron.failures.tm-sync'), 'success resets the backoff');
        $this->assertFalse($due(20), 'after a success: the normal interval');
    }

    public function testCacheRoundTrip(): void
    {
        $key = 'test:' . Str::random(8);
        $this->assertNull(Cache::get($key));
        Cache::set($key, ['a' => 1], 60);
        $this->assertSame(['a' => 1], Cache::get($key));
        $calls = 0;
        $fn = static function () use (&$calls): string {
            $calls++;
            return 'değer';
        };
        $this->assertSame('değer', Cache::remember($key . 'r', 60, $fn));
        $this->assertSame('değer', Cache::remember($key . 'r', 60, $fn));
        $this->assertSame(1, $calls, 'remember computes once');
        Cache::forget($key);
        Cache::forget($key . 'r');
        $this->assertNull(Cache::get($key));

        // "Önbelleği temizle" drops derived caches only; pipeline state (feed full texts the
        // publisher still needs, rejected videos, open PayTR tokens) survives until its TTL.
        $feed = 'news:feedtext:' . Str::random(8);
        Cache::set($feed, 'Cristiano Ronaldo tam metin', 60);
        Cache::set('paytr-iframe-' . $key, ['type' => 'iframe'], 60);
        Cache::set($key, 'sayfa', 60);
        Cache::flush();
        $this->assertSame('Cristiano Ronaldo tam metin', Cache::get($feed));
        $this->assertSame(['type' => 'iframe'], Cache::get('paytr-iframe-' . $key));
        $this->assertNull(Cache::get($key), 'derived entry flushed');
        Cache::forget($feed);
        Cache::forget('paytr-iframe-' . $key);
        $this->assertNull(Cache::get($feed));
    }

    public function testConditionalGetAnswers304(): void
    {
        $page = static fn (): Response => Response::html('<script nonce="' . Response::nonce() . '">1</script><p>Sayfa</p>')
            ->withHeader('Cache-Control', 'public, max-age=300');
        $first = $page()->conditional(Request::create('GET', '/hayati'));
        $etag = (string) $first->header('ETag');
        $this->assertSame(1, preg_match('#^W/"[0-9a-f]{32}"$#', $etag), 'weak ETag: ' . $etag);
        $this->assertSame(200, $first->status());

        $hit = $page()->conditional(Request::create('GET', '/hayati', [], ['HTTP_IF_NONE_MATCH' => $etag]));
        $this->assertSame(304, $hit->status());
        $this->assertSame('', $hit->body());
        $headers = array_change_key_case($hit->finalHeaders());
        $this->assertSame($etag, $headers['etag'] ?? null);
        $this->assertSame('public, max-age=300', $headers['cache-control'] ?? null);
        $this->assertFalse(isset($headers['content-security-policy']), 'no new CSP nonce for the cached body');
        $this->assertSame(304, $page()->conditional(Request::create('GET', '/hayati', [], ['HTTP_IF_NONE_MATCH' => '"x", ' . rtrim($etag, '"') . '-gzip"']))->status(), 'mod_deflate suffix');
        $this->assertSame(304, $page()->conditional(Request::create('HEAD', '/hayati', [], ['HTTP_IF_NONE_MATCH' => $etag]))->status());

        $this->assertSame(200, $page()->conditional(Request::create('GET', '/hayati', [], ['HTTP_IF_NONE_MATCH' => 'W/"eski"']))->status(), 'changed body');
        $this->assertSame(200, $page()->withBody('<p>Yeni</p>')->conditional(Request::create('GET', '/hayati', [], ['HTTP_IF_NONE_MATCH' => $etag]))->status());
        $private = Response::html('<p>Panel</p>')->withHeader('Cache-Control', 'no-store, private');
        $this->assertNull($private->conditional(Request::create('GET', '/admin', [], ['HTTP_IF_NONE_MATCH' => '*']))->header('ETag'), 'private pages get no validator');
        $this->assertSame(200, $page()->conditional(Request::create('POST', '/hayati', [], ['HTTP_IF_NONE_MATCH' => $etag]))->status());

        // If-Modified-Since only counts when the controller set Last-Modified (and no If-None-Match was sent).
        $dated = $page()->withHeader('Last-Modified', 'Tue, 20 Oct 2026 10:00:00 GMT');
        $this->assertSame(304, $dated->conditional(Request::create('GET', '/x', [], ['HTTP_IF_MODIFIED_SINCE' => 'Tue, 20 Oct 2026 11:00:00 GMT']))->status());
        $this->assertSame(200, $dated->conditional(Request::create('GET', '/x', [], ['HTTP_IF_MODIFIED_SINCE' => 'Tue, 20 Oct 2026 09:00:00 GMT']))->status());
        $this->assertSame(200, $page()->conditional(Request::create('GET', '/x', [], ['HTTP_IF_MODIFIED_SINCE' => 'Wed, 21 Oct 2026 00:00:00 GMT']))->status());
    }
}
