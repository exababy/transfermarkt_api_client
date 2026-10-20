<?php
/**
 * Admin layout (noindex). Sidebar navigation, top bar with the page title (h1) and flash messages.
 * Admin page templates should start their own headings at h2.
 *
 * @var string $content rendered page
 * @var string|null $title page title (top bar h1 and <title>)
 * @var bool|null $bare true => no sidebar/top bar (login, installer)
 * @var string|null $adminNav active sidebar URL (auto-detected from the path when omitted)
 */

use App\Core\App;
use App\Core\Auth;
use App\Core\Session;

$title = (string) ($title ?? 'Yönetim Paneli');
$bare = (bool) ($bare ?? false);
$currentPath = $currentPath ?? (App::request()?->path() ?? '/admin');
$user = $bare ? null : Auth::user();
$flashes = Session::flashes();
$icons = [
    'panel' => 'M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4zM13 13h7v7h-7z',
    'news' => 'M5 4h11v16H5zM16 8h3v10a2 2 0 0 1-2 2M8 8h5M8 12h5M8 16h3',
    'queue' => 'M4 6h16M4 12h16M4 18h10',
    'video' => 'M3 6h18v12H3zM10 9.5v5l4.5-2.5z',
    'orders' => 'M6 8h12l-1 12H7zM9 8a3 3 0 0 1 6 0',
    'products' => 'M8 4 3 7l2 4 3-1v10h8V10l3 1 2-4-5-3a4 4 0 0 1-8 0Z',
    'payment' => 'M3 6h18v12H3zM3 10h18M7 15h4',
    'settings' => 'M4 7h10M18 7h2M4 17h4M12 17h8M16 5v4M10 15v4',
    'password' => 'M6 11h12v9H6zM9 11V8a3 3 0 0 1 6 0v3',
    'logout' => 'M10 5H5v14h5M14 8l4 4-4 4M18 12H9',
];
$nav = [
    ['/admin', 'Panel', 'panel'],
    ['/admin/haberler', 'Haberler', 'news'],
    ['/admin/haber-kuyrugu', 'Haber Kuyruğu', 'queue'],
    ['/admin/videolar', 'Videolar', 'video'],
    ['/admin/siparisler', 'Siparişler', 'orders'],
    ['/admin/urunler', 'Ürünler', 'products'],
    ['/admin/odeme-ayarlari', 'Ödeme Ayarları', 'payment'],
    ['/admin/ayarlar', 'Ayarlar', 'settings'],
    ['/admin/sifre', 'Şifre', 'password'],
];
$activeUrl = $adminNav ?? '';
if ($activeUrl === '') {
    foreach ($nav as [$href]) {
        if ($currentPath === $href || ($href !== '/admin' && str_starts_with($currentPath, $href . '/'))) {
            $activeUrl = $href;
        }
    }
}
$icon = static fn (string $name): string => '<svg class="admin-icon" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path d="' . $icons[$name] . '"/></svg>';
?><!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= e($title) ?> · CR7.com.tr Yönetim</title>
<link rel="icon" href="<?= e(App::urlPrefix()) ?>/favicon.ico" sizes="32x32">
<link rel="icon" href="<?= e(asset('img/favicon.svg')) ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= e(asset('css/admin.css')) ?>">
<script src="<?= e(asset('js/admin.js')) ?>" defer></script>
</head>
<body class="admin<?= $bare ? ' admin-bare' : '' ?>">
<?php if ($bare): ?>
<main class="admin-bare-main" id="main">
  <a class="admin-bare-brand" href="<?= e(url('/')) ?>"><img src="<?= e(asset('img/logo.svg')) ?>" width="77" height="32" alt="CR7.com.tr"></a>
  <?php foreach ($flashes as $flash): ?>
  <div class="notice notice-<?= e($flash['type']) ?>" role="status"><?= e($flash['message']) ?></div>
  <?php endforeach; ?>
  <?= $content ?>
</main>
<?php else: ?>
<div class="admin-shell">
  <aside class="admin-sidebar" id="admin-sidebar">
    <a class="admin-brand" href="<?= e(url('/admin')) ?>">
      <img src="<?= e(asset('img/logo.svg')) ?>" width="62" height="26" alt="CR7"><span>Yönetim</span>
    </a>
    <nav aria-label="Yönetim menüsü">
      <ul class="admin-nav">
        <?php foreach ($nav as [$href, $label, $iconName]): ?>
        <li><a href="<?= e(url($href)) ?>"<?= $activeUrl === $href ? ' class="active" aria-current="page"' : '' ?>><?= $icon($iconName) ?><span><?= e($label) ?></span></a></li>
        <?php endforeach; ?>
        <li>
          <form method="post" action="<?= e(url('/admin/cikis')) ?>">
            <?= csrf_field() ?>
            <button type="submit"><?= $icon('logout') ?><span>Çıkış</span></button>
          </form>
        </li>
      </ul>
    </nav>
  </aside>
  <div class="admin-main">
    <header class="admin-topbar">
      <button class="admin-menu-toggle" type="button" aria-expanded="false" aria-controls="admin-sidebar" aria-label="Menüyü aç">
        <span></span><span></span><span></span>
      </button>
      <h1 class="admin-title"><?= e($title) ?></h1>
      <div class="admin-user">
        <?php if ($user !== null): ?><span><?= e($user['username']) ?></span><?php endif; ?>
        <a href="<?= e(url('/')) ?>" target="_blank" rel="noopener">Siteyi görüntüle ↗</a>
      </div>
    </header>
    <main class="admin-content" id="main">
      <?php foreach ($flashes as $flash): ?>
      <div class="notice notice-<?= e($flash['type']) ?>" role="status"><?= e($flash['message']) ?></div>
      <?php endforeach; ?>
      <?= $content ?>
    </main>
  </div>
</div>
<?php endif; ?>
</body>
</html>
