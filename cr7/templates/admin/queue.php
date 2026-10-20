<?php
/**
 * News queue (news_items).
 *
 * @var list<array<string, mixed>> $items
 * @var array{page: int, pages: int, offset: int, total: int, perPage: int} $pager
 * @var array{durum: string, kaynak: string, q: string, kume: string} $filters
 * @var array<string, int> $counts
 * @var int $total
 * @var array<string, string> $statuses
 * @var array<string, string> $sources
 * @var array<int, int> $clusterSizes
 * @var array<int, array<string, mixed>> $posts
 * @var array<string, mixed>|null $result last "Şimdi yayınla" result
 * @var bool $publisherAvailable
 * @var string $back
 */

use App\Controllers\Admin\QueueController;

$badges = ['pending' => 'badge-info', 'failed' => 'badge-danger', 'duplicate' => 'badge-muted', 'skipped' => 'badge-muted', 'published' => 'badge-success'];
$listUrl = static fn (array $params): string => url('/admin/haber-kuyrugu') . (($qs = http_build_query(array_filter($params, static fn ($v): bool => $v !== '' && $v !== null))) !== '' ? '?' . $qs : '');
$baseUrl = $listUrl($filters);
$hasFilters = $filters['durum'] !== '' || $filters['kaynak'] !== '' || $filters['q'] !== '' || $filters['kume'] !== '';
?>
<?php if (is_array($result)): ?>
<div class="notice <?= !empty($result['ok']) ? 'notice-success' : 'notice-error' ?>" role="<?= !empty($result['ok']) ? 'status' : 'alert' ?>">
  <?php if (!empty($result['ok']) && is_array($result['post'] ?? null)): ?>
  <p><strong>Yayınlandı:</strong> <?= e($result['post']['title']) ?></p>
  <p class="cluster">
    <a class="btn btn-sm btn-primary" href="<?= e(url('/haber/' . $result['post']['slug'])) ?>" target="_blank" rel="noopener">Haberi görüntüle ↗</a>
    <a class="btn btn-sm" href="<?= e(url('/admin/haberler/' . (int) $result['post']['id'] . '/duzenle')) ?>">Düzenle</a>
    <span class="muted text-sm"><?= e($result['message']) ?></span>
  </p>
  <?php else: ?>
  <p><strong>#<?= (int) $result['item_id'] ?> yayınlanamadı:</strong> <?= e($result['message']) ?></p>
  <p class="muted text-sm mb-0"><?= e(mb_strimwidth((string) $result['item_title'], 0, 140, '…')) ?></p>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if (!$publisherAvailable): ?>
<div class="notice notice-warning" role="status">Haber modülü (NewsPublisher) bulunamadı: kuyruk görüntülenebilir ancak “Şimdi yayınla” çalışmaz.</div>
<?php endif; ?>

<div class="toolbar">
  <nav class="status-tabs" aria-label="Duruma göre filtrele">
    <a href="<?= e($listUrl(['kaynak' => $filters['kaynak'], 'q' => $filters['q']])) ?>"<?= $filters['durum'] === '' ? ' class="active" aria-current="true"' : '' ?>>Tümü <span class="count"><?= e(fmt_number($total)) ?></span></a>
    <?php foreach ($statuses as $key => $label): ?>
    <a href="<?= e($listUrl(['durum' => $key, 'kaynak' => $filters['kaynak'], 'q' => $filters['q']])) ?>"<?= $filters['durum'] === $key ? ' class="active" aria-current="true"' : '' ?>><?= e($label) ?> <span class="count"><?= e(fmt_number($counts[$key] ?? 0)) ?></span></a>
    <?php endforeach; ?>
  </nav>
  <form method="post" action="<?= e(url('/admin/islem/news-fetch')) ?>" data-busy="Haberler toplanıyor…">
    <?= csrf_field() ?>
    <input type="hidden" name="_back" value="<?= e($back) ?>">
    <button class="btn" type="submit">Haberleri şimdi topla</button>
  </form>
</div>

<form class="filter-form" method="get" action="<?= e(url('/admin/haber-kuyrugu')) ?>" role="search">
  <?php if ($filters['durum'] !== ''): ?><input type="hidden" name="durum" value="<?= e($filters['durum']) ?>"><?php endif; ?>
  <div class="field grow">
    <label for="f-q">Ara</label>
    <input class="input" id="f-q" name="q" type="search" value="<?= e($filters['q']) ?>" placeholder="Başlık, açıklama ya da adres">
  </div>
  <div class="field">
    <label for="f-kaynak">Kaynak</label>
    <select class="select" id="f-kaynak" name="kaynak" data-autosubmit>
      <option value="">Tüm kaynaklar</option>
      <?php foreach ($sources as $key => $name): ?>
      <option value="<?= e($key) ?>"<?= $filters['kaynak'] === (string) $key ? ' selected' : '' ?>><?= e($name) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php if ($filters['kume'] !== ''): ?>
  <input type="hidden" name="kume" value="<?= e($filters['kume']) ?>">
  <?php endif; ?>
  <div class="filter-actions">
    <button class="btn btn-primary" type="submit">Filtrele</button>
    <?php if ($hasFilters): ?><a class="btn btn-ghost" href="<?= e(url('/admin/haber-kuyrugu')) ?>">Temizle</a><?php endif; ?>
  </div>
</form>

<?php if ($filters['kume'] !== ''): ?>
<p class="notice notice-info">Yalnızca <strong>#<?= e($filters['kume']) ?></strong> numaralı hikâye kümesinin kayıtları gösteriliyor. <a href="<?= e($listUrl(['durum' => $filters['durum'], 'kaynak' => $filters['kaynak'], 'q' => $filters['q']])) ?>">Tüm kuyruğa dön</a></p>
<?php endif; ?>

<?php if ($items === []): ?>
<div class="empty-state">
  <span class="empty-state-icon" aria-hidden="true">7</span>
  <h2><?= $hasFilters ? 'Bu filtreye uyan kayıt yok' : 'Kuyruk boş' ?></h2>
  <p><?= $hasFilters ? 'Filtreleri değiştirip tekrar deneyin.' : 'Haber toplama görevi (her 30 dakikada) yeni kaynak haberleri buraya ekler. Hemen denemek için “Haberleri şimdi topla” düğmesini kullanın.' ?></p>
</div>
<?php else: ?>
<p class="muted text-sm"><?= e(fmt_number($pager['total'])) ?> kayıt · sayfa <?= $pager['page'] ?> / <?= $pager['pages'] ?></p>
<div class="table-wrap">
  <table class="table table-stack queue-table">
    <thead>
      <tr>
        <th scope="col">Haber</th>
        <th scope="col">Kaynak</th>
        <th scope="col">Tarih</th>
        <th scope="col">Durum</th>
        <th scope="col">Küme</th>
        <th scope="col"><span class="visually-hidden">İşlemler</span></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($items as $item):
          $id = (int) $item['id'];
          $primary = QueueController::clusterOf($item['note'] ?? null);
          $members = $clusterSizes[$id] ?? 0;
          $note = QueueController::noteText($item['note'] ?? null);
          $post = !empty($item['post_id']) ? ($posts[(int) $item['post_id']] ?? null) : null;
          $date = $item['published_at'] ?: $item['fetched_at'];
      ?>
      <tr>
        <td data-label="Haber" class="cell-title">
          <a href="<?= e($item['url']) ?>" target="_blank" rel="noopener noreferrer nofollow" title="Kaynağı yeni sekmede aç"><?= e($item['title']) ?> <span aria-hidden="true">↗</span></a>
          <?php if (!empty($item['description'])): ?><span class="cell-sub clamp-2"><?= e(mb_strimwidth(strip_tags((string) $item['description']), 0, 220, '…')) ?></span><?php endif; ?>
          <?php if ($note !== ''): ?><span class="cell-note">Not: <?= e($note) ?></span><?php endif; ?>
        </td>
        <td data-label="Kaynak"><?= e($sources[$item['source']] ?? ($item['source_name'] ?: $item['source'])) ?><span class="cell-sub mono">#<?= $id ?></span></td>
        <td data-label="Tarih" class="nowrap"><span title="<?= e(fmt_date((string) $date, 'j F Y H:i')) ?>"><?= e(fmt_date((string) $date, 'j M H:i')) ?></span><span class="cell-sub"><?= e(fmt_relative((string) $date)) ?></span></td>
        <td data-label="Durum">
          <span class="badge <?= e($badges[$item['status']] ?? 'badge-muted') ?>"><?= e($statuses[$item['status']] ?? $item['status']) ?></span>
          <?php if ((int) $item['attempts'] > 0): ?><span class="cell-sub"><?= (int) $item['attempts'] ?> deneme</span><?php endif; ?>
        </td>
        <td data-label="Küme" class="nowrap">
          <?php if ($primary !== null): ?>
          <a href="<?= e($listUrl(['kume' => $primary])) ?>">#<?= $primary ?> kümesi</a>
          <?php elseif ($members > 0): ?>
          <a href="<?= e($listUrl(['kume' => $id])) ?>">Ana kayıt · +<?= $members ?></a>
          <?php else: ?>
          <span class="muted">Tek kaynak</span>
          <?php endif; ?>
        </td>
        <td class="cell-actions">
          <div class="actions">
            <?php if ($post !== null): ?>
            <a class="btn btn-sm" href="<?= e(url('/admin/haberler/' . (int) $post['id'] . '/duzenle')) ?>">Yazıyı düzenle</a>
            <?php if ($post['status'] === 'published'): ?><a class="btn btn-sm btn-ghost" href="<?= e(url('/haber/' . $post['slug'])) ?>" target="_blank" rel="noopener">Görüntüle ↗</a><?php endif; ?>
            <?php else: ?>
            <?php if ($item['status'] !== 'published'): ?>
            <form method="post" action="<?= e(url('/admin/haber-kuyrugu/' . $id . '/yayinla')) ?>" data-busy="Yayınlanıyor…" data-confirm="Bu haber günlük limit ve yayın aralığı beklenmeden şimdi yayınlansın mı?">
              <?= csrf_field() ?>
              <input type="hidden" name="_back" value="<?= e($back) ?>">
              <button class="btn btn-sm btn-primary" type="submit"<?= $publisherAvailable ? '' : ' disabled' ?>>Şimdi yayınla</button>
            </form>
            <?php endif; ?>
            <?php if (in_array($item['status'], ['pending', 'failed', 'duplicate'], true)): ?>
            <form method="post" action="<?= e(url('/admin/haber-kuyrugu/' . $id . '/atla')) ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="_back" value="<?= e($back) ?>">
              <button class="btn btn-sm" type="submit">Atla</button>
            </form>
            <?php endif; ?>
            <?php endif; ?>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?= partial('partials/pagination', ['current' => $pager['page'], 'total' => $pager['pages'], 'baseUrl' => $baseUrl]) ?>
<?php endif; ?>
