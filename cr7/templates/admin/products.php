<?php
/**
 * Admin product list (/admin/urunler).
 *
 * @var list<array<string, mixed>> $products
 * @var int $total
 * @var int $page
 * @var int $pages
 * @var array{q: string, takim: string} $filters
 * @var array<string, string> $clubs
 * @var bool $ordersOpen
 */

use App\Services\Shop\Shop;

$listUrl = static fn (array $params): string => url('/admin/urunler') . (($qs = http_build_query(array_filter($params, static fn ($v): bool => $v !== ''))) !== '' ? '?' . $qs : '');
$hasFilters = $filters['q'] !== '' || $filters['takim'] !== '';
?>
<style>.product-thumb{display:block;flex:none;width:64px;height:66px;padding:4px;border-radius:8px;background:#1b1b21}.product-thumb img{width:100%;height:100%;object-fit:contain}.cell-product{display:flex;gap:12px;align-items:flex-start}</style>
<?php if (!$ordersOpen): ?>
<div class="notice notice-info">Mağaza vitrin modunda: ürünler sitede görünür, sipariş alınmaz (“Yakında satışta”). Siparişleri <a href="<?= e(url('/admin/odeme-ayarlari')) ?>">Ödeme Ayarları</a> sayfasından açabilirsiniz.</div>
<?php endif; ?>

<div class="toolbar">
  <p class="muted text-sm mb-0"><?= e(fmt_number($total)) ?> ürün<?= $pages > 1 ? ' · sayfa ' . (int) $page . ' / ' . (int) $pages : '' ?></p>
  <div class="actions">
    <a class="btn" href="<?= e(url('/forma-magazasi')) ?>" target="_blank" rel="noopener">Mağazayı görüntüle ↗</a>
    <a class="btn btn-gold" href="<?= e(url('/admin/urunler/yeni')) ?>">+ Yeni ürün</a>
  </div>
</div>

<form class="filter-form" method="get" action="<?= e(url('/admin/urunler')) ?>" role="search">
  <div class="field grow">
    <label for="f-q">Ara</label>
    <input class="input" id="f-q" name="q" type="search" value="<?= e($filters['q']) ?>" placeholder="Ürün adı, adres, kulüp veya sezon">
  </div>
  <div class="field">
    <label for="f-club">Kulüp</label>
    <select class="select" id="f-club" name="takim" data-autosubmit>
      <option value="">Tümü</option>
      <?php foreach ($clubs as $id => $name): ?>
      <option value="<?= e((string) $id) ?>"<?= $filters['takim'] === (string) $id ? ' selected' : '' ?>><?= e($name) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="filter-actions">
    <button class="btn btn-primary" type="submit">Filtrele</button>
    <?php if ($hasFilters): ?><a class="btn btn-ghost" href="<?= e(url('/admin/urunler')) ?>">Temizle</a><?php endif; ?>
  </div>
</form>

<?php if ($products === []): ?>
<div class="empty-state">
  <span class="empty-state-icon" aria-hidden="true">7</span>
  <h2><?= $hasFilters ? 'Bu filtreye uyan ürün yok' : 'Henüz ürün yok' ?></h2>
  <p><?= $hasFilters ? 'Filtreleri değiştirip tekrar deneyin.' : 'Kurulum sihirbazı örnek formaları ekler; “Yeni ürün” ile kendiniz de ekleyebilirsiniz.' ?></p>
</div>
<?php else: ?>
<div class="table-wrap">
  <table class="table table-stack">
    <thead>
      <tr>
        <th scope="col">Ürün</th>
        <th scope="col">Kulüp / Sezon</th>
        <th scope="col">Fiyat</th>
        <th scope="col">Stok</th>
        <th scope="col">Durum</th>
        <th scope="col">Sıra</th>
        <th scope="col"><span class="visually-hidden">İşlemler</span></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($products as $p): $editUrl = url('/admin/urunler/' . (int) $p['id'] . '/duzenle'); ?>
      <tr>
        <td data-label="Ürün"><div class="cell-product">
          <a class="product-thumb" href="<?= e($editUrl) ?>" tabindex="-1" aria-hidden="true">
            <?php if ($p['image_url'] !== ''): ?><img src="<?= e($p['image_url']) ?>" width="72" height="74" alt="" loading="lazy"><?php endif; ?>
          </a>
          <span class="cell-title">
            <a href="<?= e($editUrl) ?>"><?= e($p['title']) ?></a>
            <span class="cell-sub mono">/forma/<?= e($p['slug']) ?></span>
            <span class="cell-sub"><?= e(Shop::kitTypeLabel((string) $p['kit_type'])) ?></span>
          </span>
        </div></td>
        <td data-label="Kulüp / Sezon"><?= e($p['club_name']) ?><?= !empty($p['season']) ? '<span class="cell-sub">' . e($p['season']) . '</span>' : '' ?></td>
        <td data-label="Fiyat" class="nowrap">
          <strong><?= e(fmt_price($p['price'])) ?></strong>
          <?php if ($p['old_price'] !== null): ?><span class="cell-sub"><del><?= e(fmt_price($p['old_price'])) ?></del></span><?php endif; ?>
        </td>
        <td data-label="Stok"><?= $p['stock'] === null ? '<span class="muted">Sipariş üzerine</span>' : e((string) $p['stock']) ?></td>
        <td data-label="Durum"><span class="badge <?= $p['is_active'] ? 'badge-success' : 'badge-muted' ?>"><?= $p['is_active'] ? 'Yayında' : 'Gizli' ?></span></td>
        <td data-label="Sıra" class="mono"><?= (int) $p['sort_order'] ?></td>
        <td class="cell-actions">
          <div class="actions">
            <a class="btn btn-sm" href="<?= e($editUrl) ?>">Düzenle</a>
            <?php if ($p['is_active']): ?><a class="btn btn-sm btn-ghost" href="<?= e(url($p['url'])) ?>" target="_blank" rel="noopener">Görüntüle ↗</a><?php endif; ?>
            <form method="post" action="<?= e(url('/admin/urunler/' . (int) $p['id'] . '/sil')) ?>" data-confirm="“<?= e(mb_strimwidth((string) $p['title'], 0, 80, '…')) ?>” silinsin mi? Bu işlem geri alınamaz.">
              <?= csrf_field() ?>
              <button class="btn btn-sm btn-danger" type="submit">Sil</button>
            </form>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?= partial('partials/pagination', ['current' => $page, 'total' => $pages, 'baseUrl' => $listUrl($filters)]) ?>
<?php endif; ?>
