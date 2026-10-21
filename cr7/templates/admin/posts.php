<?php
/**
 * Posts list.
 *
 * @var list<array<string, mixed>> $posts
 * @var array{page: int, pages: int, offset: int, total: int, perPage: int} $pager
 * @var array{q: string, durum: string, tur: string} $filters
 * @var array{all: int, published: int, scheduled: int, draft: int} $counts
 * @var string $now UTC
 * @var string $back
 */

$tabs = ['' => ['Tümü', $counts['all']], 'published' => ['Yayında', $counts['published']], 'scheduled' => ['Zamanlanmış', $counts['scheduled']], 'draft' => ['Taslak', $counts['draft']]];
$listUrl = static fn (array $params): string => url('/admin/haberler') . (($qs = http_build_query(array_filter($params, static fn ($v): bool => $v !== '' && $v !== null))) !== '' ? '?' . $qs : '');
$baseUrl = $listUrl($filters);
$hasFilters = $filters['q'] !== '' || $filters['durum'] !== '' || $filters['tur'] !== '';
?>
<div class="toolbar">
  <nav class="status-tabs" aria-label="Duruma göre filtrele">
    <?php foreach ($tabs as $key => [$label, $count]): ?>
    <a href="<?= e($listUrl(['q' => $filters['q'], 'tur' => $filters['tur'], 'durum' => $key])) ?>"<?= $filters['durum'] === $key ? ' class="active" aria-current="true"' : '' ?>><?= e($label) ?> <span class="count"><?= e(fmt_number($count)) ?></span></a>
    <?php endforeach; ?>
  </nav>
  <a class="btn btn-gold" href="<?= e(url('/admin/haberler/yeni')) ?>">+ Yeni haber</a>
</div>

<form class="filter-form" method="get" action="<?= e(url('/admin/haberler')) ?>" role="search">
  <?php if ($filters['durum'] !== ''): ?><input type="hidden" name="durum" value="<?= e($filters['durum']) ?>"><?php endif; ?>
  <div class="field grow">
    <label for="f-q">Ara</label>
    <input class="input" id="f-q" name="q" type="search" value="<?= e($filters['q']) ?>" placeholder="Başlık, adres ya da etiket">
  </div>
  <div class="field">
    <label for="f-tur">Tür</label>
    <select class="select" id="f-tur" name="tur" data-autosubmit>
      <option value="">Tümü</option>
      <option value="otomatik"<?= $filters['tur'] === 'otomatik' ? ' selected' : '' ?>>Otomatik (spin)</option>
      <option value="elle"<?= $filters['tur'] === 'elle' ? ' selected' : '' ?>>Elle yazılan</option>
    </select>
  </div>
  <div class="filter-actions">
    <button class="btn btn-primary" type="submit">Filtrele</button>
    <?php if ($hasFilters): ?><a class="btn btn-ghost" href="<?= e(url('/admin/haberler')) ?>">Temizle</a><?php endif; ?>
  </div>
</form>

<?php if ($posts === []): ?>
<div class="empty-state">
  <span class="empty-state-icon" aria-hidden="true">7</span>
  <h2><?= $hasFilters ? 'Aramanıza uyan haber yok' : 'Henüz haber yok' ?></h2>
  <p><?= $hasFilters ? 'Filtreleri değiştirip tekrar deneyin.' : 'Otomatik haberler kuyruktan yayınlandıkça burada listelenir. İsterseniz ilk haberi elle yazabilirsiniz.' ?></p>
  <div class="cluster center">
    <a class="btn btn-gold" href="<?= e(url('/admin/haberler/yeni')) ?>">Yeni haber yaz</a>
    <a class="btn" href="<?= e(url('/admin/haber-kuyrugu')) ?>">Haber kuyruğuna git</a>
  </div>
</div>
<?php else: ?>
<p class="muted text-sm"><?= e(fmt_number($pager['total'])) ?> haber · sayfa <?= $pager['page'] ?> / <?= $pager['pages'] ?></p>
<div class="table-wrap">
  <table class="table table-stack">
    <thead>
      <tr>
        <th scope="col">Başlık</th>
        <th scope="col">Durum</th>
        <th scope="col">Tür</th>
        <th scope="col" class="num">Özgünlük</th>
        <th scope="col">Yayın tarihi</th>
        <th scope="col" class="num">Okunma</th>
        <th scope="col"><span class="visually-hidden">İşlemler</span></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($posts as $post):
          $scheduled = $post['status'] === 'published' && (string) $post['published_at'] > $now;
          $live = $post['status'] === 'published' && !$scheduled;
          $editUrl = url('/admin/haberler/' . (int) $post['id'] . '/duzenle');
      ?>
      <tr>
        <td data-label="Başlık" class="cell-title">
          <a href="<?= e($editUrl) ?>"><?= e($post['title']) ?></a>
          <span class="cell-sub mono">/haber/<?= e($post['slug']) ?></span>
        </td>
        <td data-label="Durum">
          <?php if ($scheduled): ?><span class="badge badge-info">Zamanlanmış</span>
          <?php elseif ($live): ?><span class="badge badge-success">Yayında</span>
          <?php else: ?><span class="badge badge-muted">Taslak</span><?php endif; ?>
          <?php if ((int) $post['is_indexable'] !== 1): ?><span class="badge badge-warning" title="Arama motorlarına kapalı (noindex)">noindex</span><?php endif; ?>
        </td>
        <td data-label="Tür"><?= (int) $post['is_spun'] === 1 ? '<span class="badge badge-info">Otomatik</span>' : '<span class="badge">Elle</span>' ?><?php if ((int) $post['source_count'] > 1): ?><span class="cell-sub"><?= (int) $post['source_count'] ?> kaynak</span><?php endif; ?></td>
        <td data-label="Özgünlük" class="num"><?= $post['uniqueness'] !== null ? '%' . e(fmt_number((float) $post['uniqueness'], 1)) : '—' ?></td>
        <td data-label="Yayın tarihi" class="nowrap"><?= e(fmt_date((string) $post['published_at'], 'j M Y H:i')) ?></td>
        <td data-label="Okunma" class="num"><?= e(fmt_number((int) $post['views'])) ?></td>
        <td class="cell-actions">
          <div class="actions">
            <a class="btn btn-sm" href="<?= e($editUrl) ?>">Düzenle</a>
            <?php if ($live): ?><a class="btn btn-sm btn-ghost" href="<?= e(url('/haber/' . $post['slug'])) ?>" target="_blank" rel="noopener">Görüntüle ↗</a><?php endif; ?>
            <form method="post" action="<?= e(url('/admin/haberler/' . (int) $post['id'] . '/sil')) ?>" data-confirm="“<?= e(mb_strimwidth((string) $post['title'], 0, 80, '…')) ?>” kalıcı olarak silinsin mi? Bu işlem geri alınamaz.">
              <?= csrf_field() ?>
              <input type="hidden" name="_back" value="<?= e($back) ?>">
              <button class="btn btn-sm btn-danger" type="submit">Sil</button>
            </form>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?= partial('partials/pagination', ['current' => $pager['page'], 'total' => $pager['pages'], 'baseUrl' => $baseUrl]) ?>
<?php endif; ?>
