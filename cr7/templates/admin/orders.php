<?php
/**
 * Admin orders list (/admin/siparisler?durum=&odeme=&yontem=&q=).
 *
 * @var list<array<string, mixed>> $orders
 * @var int $total
 * @var int $page
 * @var int $pages
 * @var array{status: string, payment_status: string, payment_method: string, q: string} $filters
 * @var array<string, int> $statusCounts
 * @var array<string, int> $paymentCounts
 * @var array<string, string> $methods
 * @var bool $ordersOpen
 */

use App\Services\Shop\OrderService;
use App\Services\Shop\Shop;

$params = ['durum' => $filters['status'], 'odeme' => $filters['payment_status'], 'yontem' => $filters['payment_method'], 'q' => $filters['q']];
$listUrl = static fn (array $p): string => url('/admin/siparisler') . (($qs = http_build_query(array_filter($p, static fn ($v): bool => $v !== ''))) !== '' ? '?' . $qs : '');
$hasFilters = implode('', $params) !== '';
$statusBadge = ['yeni' => 'badge-info', 'onaylandi' => 'badge-success', 'hazirlaniyor' => 'badge-warning', 'kargoda' => 'badge-info', 'tamamlandi' => 'badge-muted', 'iptal' => 'badge-danger'];
$payBadge = ['bekliyor' => 'badge-warning', 'odendi' => 'badge-success', 'basarisiz' => 'badge-danger', 'iade' => 'badge-muted'];
?>
<?php if (!$ordersOpen): ?>
<div class="notice notice-info">Sipariş alma şu anda kapalı (ürün sayfalarında “Yakında satışta” görünüyor). Açmak için <a href="<?= e(url('/admin/odeme-ayarlari')) ?>">Ödeme Ayarları</a>.</div>
<?php endif; ?>

<div class="toolbar">
  <nav class="status-tabs" aria-label="Sipariş durumuna göre filtrele">
    <a href="<?= e($listUrl(['durum' => ''] + $params)) ?>"<?= $filters['status'] === '' ? ' class="active" aria-current="true"' : '' ?>>Tümü <span class="count"><?= e(fmt_number(array_sum($statusCounts))) ?></span></a>
    <?php foreach (Shop::STATUSES as $key => $label): ?>
    <a href="<?= e($listUrl(['durum' => $key] + $params)) ?>"<?= $filters['status'] === $key ? ' class="active" aria-current="true"' : '' ?>><?= e($label) ?> <span class="count"><?= e(fmt_number($statusCounts[$key] ?? 0)) ?></span></a>
    <?php endforeach; ?>
  </nav>
</div>

<form class="filter-form" method="get" action="<?= e(url('/admin/siparisler')) ?>" role="search">
  <?php if ($filters['status'] !== ''): ?><input type="hidden" name="durum" value="<?= e($filters['status']) ?>"><?php endif; ?>
  <div class="field grow">
    <label for="f-q">Ara</label>
    <input class="input" id="f-q" name="q" type="search" value="<?= e($filters['q']) ?>" placeholder="Sipariş no, telefon, ad soyad veya e-posta">
  </div>
  <div class="field">
    <label for="f-pay">Ödeme durumu</label>
    <select class="select" id="f-pay" name="odeme" data-autosubmit>
      <option value="">Tümü</option>
      <?php foreach (Shop::PAYMENT_STATUSES as $key => $label): ?>
      <option value="<?= e($key) ?>"<?= $filters['payment_status'] === $key ? ' selected' : '' ?>><?= e($label) ?> (<?= (int) ($paymentCounts[$key] ?? 0) ?>)</option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="field">
    <label for="f-method">Ödeme yöntemi</label>
    <select class="select" id="f-method" name="yontem" data-autosubmit>
      <option value="">Tümü</option>
      <?php foreach ($methods as $key => $label): ?>
      <option value="<?= e($key) ?>"<?= $filters['payment_method'] === $key ? ' selected' : '' ?>><?= e($label) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="filter-actions">
    <button class="btn btn-primary" type="submit">Filtrele</button>
    <?php if ($hasFilters): ?><a class="btn btn-ghost" href="<?= e(url('/admin/siparisler')) ?>">Temizle</a><?php endif; ?>
  </div>
</form>

<?php if ($orders === []): ?>
<div class="empty-state">
  <span class="empty-state-icon" aria-hidden="true">7</span>
  <h2><?= $hasFilters ? 'Bu filtreye uyan sipariş yok' : 'Henüz sipariş yok' ?></h2>
  <p><?= $hasFilters ? 'Filtreleri değiştirip tekrar deneyin.' : 'Siparişler açıldığında gelen siparişler burada listelenir.' ?></p>
</div>
<?php else: ?>
<p class="muted text-sm"><?= e(fmt_number($total)) ?> sipariş<?= $pages > 1 ? ' · sayfa ' . (int) $page . ' / ' . (int) $pages : '' ?></p>
<div class="table-wrap">
  <table class="table table-stack">
    <thead>
      <tr>
        <th scope="col">Sipariş</th>
        <th scope="col">Müşteri</th>
        <th scope="col">Ürün</th>
        <th scope="col">Tutar</th>
        <th scope="col">Ödeme</th>
        <th scope="col">Durum</th>
        <th scope="col"><span class="visually-hidden">İşlemler</span></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($orders as $o): $showUrl = url('/admin/siparisler/' . (int) $o['id']); ?>
      <tr>
        <td data-label="Sipariş"><a class="mono" href="<?= e($showUrl) ?>"><strong><?= e($o['order_no']) ?></strong></a><span class="cell-sub"><?= e(fmt_date((string) $o['created_at'], 'j M Y H:i')) ?></span></td>
        <td data-label="Müşteri"><?= e($o['customer_name']) ?><span class="cell-sub"><?= e(OrderService::formatPhone((string) $o['phone'])) ?> · <?= e($o['city']) ?></span></td>
        <td data-label="Ürün"><?= e($o['product_title']) ?><span class="cell-sub"><?= e($o['size']) ?> · <?= (int) $o['quantity'] ?> adet<?= !empty($o['print_name']) ? ' · ' . e(trim($o['print_name'] . ' ' . $o['print_number'])) : '' ?></span></td>
        <td data-label="Tutar" class="nowrap"><strong><?= e(fmt_price($o['total'])) ?></strong></td>
        <td data-label="Ödeme"><span class="badge <?= e($payBadge[$o['payment_status']] ?? 'badge-muted') ?>"><?= e(Shop::paymentStatusLabel((string) $o['payment_status'])) ?></span><span class="cell-sub"><?= e($methods[$o['payment_method']] ?? $o['payment_method']) ?></span></td>
        <td data-label="Durum"><span class="badge <?= e($statusBadge[$o['status']] ?? 'badge-muted') ?>"><?= e(Shop::statusLabel((string) $o['status'])) ?></span><?= !empty($o['cargo_tracking']) ? '<span class="cell-sub mono">' . e($o['cargo_tracking']) . '</span>' : '' ?></td>
        <td class="cell-actions"><div class="actions"><a class="btn btn-sm" href="<?= e($showUrl) ?>">Detay</a></div></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?= partial('partials/pagination', ['current' => $page, 'total' => $pages, 'baseUrl' => $listUrl($params)]) ?>
<?php endif; ?>
