<?php
/**
 * Sticky site header with main navigation (hamburger menu under 960px).
 *
 * @var string|null $activeNav home|hayati|kariyer|takimlar|istatistikler|kupalar|videolar|haberler|magaza
 * @var string $currentPath used to detect the active item when $activeNav is empty
 */

$currentPath = $currentPath ?? '/';
$items = [
    ['key' => 'home', 'label' => 'Ana Sayfa', 'url' => '/', 'match' => ['/']],
    ['key' => 'hayati', 'label' => 'Hayatı', 'url' => '/hayati', 'match' => ['/hayati']],
    ['key' => 'kariyer', 'label' => 'Kariyer', 'url' => '/kariyer', 'match' => ['/kariyer']],
    ['key' => 'takimlar', 'label' => 'Takımlar', 'url' => '/takimlar', 'match' => ['/takimlar']],
    ['key' => 'istatistikler', 'label' => 'İstatistikler', 'url' => '/istatistikler', 'match' => ['/istatistikler']],
    ['key' => 'kupalar', 'label' => 'Kupalar', 'url' => '/kupalar-ve-oduller', 'match' => ['/kupalar-ve-oduller']],
    ['key' => 'videolar', 'label' => 'Videolar', 'url' => '/videolar', 'match' => ['/videolar', '/video']],
    ['key' => 'haberler', 'label' => 'Haberler', 'url' => '/haberler', 'match' => ['/haberler', '/haber']],
];
if (setting('shop.enabled', true)) {
    $items[] = ['key' => 'magaza', 'label' => 'Forma Mağazası', 'url' => '/forma-magazasi', 'match' => ['/forma-magazasi', '/forma', '/siparis', '/odeme'], 'class' => 'nav-shop'];
}
if (empty($activeNav)) {
    $activeNav = '';
    foreach ($items as $item) {
        foreach ($item['match'] as $prefix) {
            $hit = $prefix === '/' ? $currentPath === '/' : ($currentPath === $prefix || str_starts_with($currentPath, $prefix . '/'));
            if ($hit) {
                $activeNav = $item['key'];
                break 2;
            }
        }
    }
}
?>
<header class="site-header">
  <div class="container header-inner">
    <a class="brand" href="<?= e(url('/')) ?>" aria-label="CR7.com.tr ana sayfa">
      <img class="brand-logo" src="<?= e(asset('img/logo.svg')) ?>" width="77" height="32" alt="CR7">
      <span class="brand-tld">.com.tr</span>
    </a>
    <nav class="site-nav" id="site-nav" aria-label="Ana menü">
      <ul>
        <?php foreach ($items as $item): ?>
        <li<?= isset($item['class']) ? ' class="' . e($item['class']) . '"' : '' ?>><a href="<?= e(url($item['url'])) ?>"<?= $activeNav === $item['key'] ? ' aria-current="page"' : '' ?>><?= e($item['label']) ?></a></li>
        <?php endforeach; ?>
      </ul>
    </nav>
    <div class="header-actions">
      <a class="icon-btn" href="<?= e(url('/arama')) ?>" aria-label="Sitede ara">
        <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M10.5 18a7.5 7.5 0 1 0 0-15 7.5 7.5 0 0 0 0 15Zm5.3-2.2L21 21"/></svg>
      </a>
      <button class="nav-toggle" type="button" aria-expanded="false" aria-controls="site-nav" aria-label="Menüyü aç">
        <span class="nav-toggle-bar"></span><span class="nav-toggle-bar"></span><span class="nav-toggle-bar"></span>
      </button>
    </div>
  </div>
</header>
