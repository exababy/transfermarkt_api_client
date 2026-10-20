<?php
/**
 * All application routes. Handlers: [Controller::class, 'method'] => (new Controller())->method(Request): Response.
 * Param types: {id} digits, {slug}/{task} [a-z0-9-], {token} [A-Za-z0-9_-], {type} [a-z], {key} [A-Za-z0-9-].
 * CSRF is verified inside POST handlers (Csrf::check); payment callbacks verify gateway signatures instead.
 */

declare(strict_types=1);

use App\Controllers\Admin;
use App\Controllers\CareerController;
use App\Controllers\ClubsController;
use App\Controllers\CronController;
use App\Controllers\FeedController;
use App\Controllers\HomeController;
use App\Controllers\HonoursController;
use App\Controllers\InstallController;
use App\Controllers\LifeController;
use App\Controllers\NewsController;
use App\Controllers\PageController;
use App\Controllers\PaymentController;
use App\Controllers\SearchController;
use App\Controllers\ShopController;
use App\Controllers\SitemapController;
use App\Controllers\StatsController;
use App\Controllers\VideosController;
use App\Core\Router;

return static function (Router $r): void {
    // Public site
    $r->get('/', [HomeController::class, 'index']);
    $r->get('/hayati', [LifeController::class, 'index']);
    $r->get('/kariyer', [CareerController::class, 'index']);
    $r->get('/takimlar', [ClubsController::class, 'index']);
    $r->get('/takimlar/{slug}', [ClubsController::class, 'show']);
    $r->get('/istatistikler', [StatsController::class, 'index']);
    $r->get('/istatistikler/maclar', [StatsController::class, 'matches']);
    $r->get('/kupalar-ve-oduller', [HonoursController::class, 'index']);
    $r->get('/videolar', [VideosController::class, 'index']);
    $r->get('/video/{slug}', [VideosController::class, 'show']);
    $r->get('/haberler', [NewsController::class, 'index']);
    $r->get('/haberler/etiket/{slug}', [NewsController::class, 'tag']);
    $r->get('/haber/{slug}', [NewsController::class, 'show']);
    $r->get('/rss.xml', [FeedController::class, 'rss']);
    $r->get('/indexnow-key.txt', [FeedController::class, 'indexNowKey']);
    $r->get('/arama', [SearchController::class, 'index']);
    $r->get('/hakkinda', [PageController::class, 'about']);
    $r->get('/gizlilik-politikasi', [PageController::class, 'privacy']);
    $r->get('/iletisim', [PageController::class, 'contact']);
    $r->get('/sitemap.xml', [SitemapController::class, 'index']);
    $r->get('/sitemap-{type}.xml', [SitemapController::class, 'show']);
    $r->get('/robots.txt', [SitemapController::class, 'robots']);

    // Shop (public, indexable; ordering gated by shop.orders_enabled)
    $r->get('/forma-magazasi', [ShopController::class, 'index']);
    $r->get('/forma/{slug}', [ShopController::class, 'show']);
    $r->post('/forma/{slug}/siparis', [ShopController::class, 'order']);
    $r->get('/siparis/{key}', [ShopController::class, 'status']);

    // Payment (callbacks are CSRF-exempt by design: verified by gateway signatures)
    $r->post('/odeme/paytr/bildirim', [PaymentController::class, 'paytrCallback']);
    $r->post('/odeme/iyzico/donus', [PaymentController::class, 'iyzicoCallback']);
    $r->post('/odeme/shopier/donus', [PaymentController::class, 'shopierCallback']);
    $r->get('/odeme/sonuc/{key}', [PaymentController::class, 'result']);
    $r->get('/odeme/{key}', [PaymentController::class, 'start']);

    // Operations
    $r->get('/cron/{token}', [CronController::class, 'run']);
    $r->get('/kurulum', [InstallController::class, 'index']);
    $r->post('/kurulum', [InstallController::class, 'install']);

    // Admin
    $r->get('/admin/giris', [Admin\AuthController::class, 'showLogin']);
    $r->post('/admin/giris', [Admin\AuthController::class, 'login']);
    $r->post('/admin/cikis', [Admin\AuthController::class, 'logout']);
    $r->get('/admin/sifre', [Admin\AuthController::class, 'password']);
    $r->post('/admin/sifre', [Admin\AuthController::class, 'updatePassword']);
    $r->get('/admin', [Admin\DashboardController::class, 'index']);

    $r->get('/admin/haberler', [Admin\PostsController::class, 'index']);
    $r->get('/admin/haberler/yeni', [Admin\PostsController::class, 'create']);
    $r->post('/admin/haberler/yeni', [Admin\PostsController::class, 'store']);
    $r->get('/admin/haberler/{id}/duzenle', [Admin\PostsController::class, 'edit']);
    $r->post('/admin/haberler/{id}/duzenle', [Admin\PostsController::class, 'update']);
    $r->post('/admin/haberler/{id}/sil', [Admin\PostsController::class, 'delete']);

    $r->get('/admin/haber-kuyrugu', [Admin\QueueController::class, 'index']);
    $r->post('/admin/haber-kuyrugu/{id}/yayinla', [Admin\QueueController::class, 'publish']);
    $r->post('/admin/haber-kuyrugu/{id}/atla', [Admin\QueueController::class, 'skip']);

    $r->get('/admin/videolar', [Admin\VideosController::class, 'index']);
    $r->post('/admin/videolar/ekle', [Admin\VideosController::class, 'store']);
    $r->get('/admin/videolar/{id}/duzenle', [Admin\VideosController::class, 'edit']);
    $r->post('/admin/videolar/{id}/duzenle', [Admin\VideosController::class, 'update']);
    $r->post('/admin/videolar/{id}/sil', [Admin\VideosController::class, 'delete']);

    $r->get('/admin/ayarlar', [Admin\SettingsController::class, 'index']);
    $r->post('/admin/ayarlar', [Admin\SettingsController::class, 'update']);
    $r->post('/admin/islem/{task}', [Admin\ActionsController::class, 'run']);

    // Admin shop
    $r->get('/admin/siparisler', [Admin\OrdersController::class, 'index']);
    $r->get('/admin/siparisler/{id}', [Admin\OrdersController::class, 'show']);
    $r->post('/admin/siparisler/{id}/durum', [Admin\OrdersController::class, 'updateStatus']);

    $r->get('/admin/urunler', [Admin\ProductsController::class, 'index']);
    $r->get('/admin/urunler/yeni', [Admin\ProductsController::class, 'create']);
    $r->post('/admin/urunler/yeni', [Admin\ProductsController::class, 'store']);
    $r->get('/admin/urunler/{id}/duzenle', [Admin\ProductsController::class, 'edit']);
    $r->post('/admin/urunler/{id}/duzenle', [Admin\ProductsController::class, 'update']);
    $r->post('/admin/urunler/{id}/sil', [Admin\ProductsController::class, 'delete']);

    $r->get('/admin/odeme-ayarlari', [Admin\ProductsController::class, 'paymentSettings']);
    $r->post('/admin/odeme-ayarlari', [Admin\ProductsController::class, 'savePaymentSettings']);

    // --- shop legal pages ---
    $r->get('/mesafeli-satis-sozlesmesi', [ShopController::class, 'distanceSales']);
    $r->get('/on-bilgilendirme-formu', [ShopController::class, 'preInfo']);
    $r->get('/iptal-ve-iade', [ShopController::class, 'returns']);
    // --- /shop legal pages ---
};
