<?php
/**
 * Videos list + "add by YouTube URL" form.
 *
 * @var list<array<string, mixed>> $videos
 * @var array{page: int, pages: int, offset: int, total: int, perPage: int} $pager
 * @var array{q: string, kategori: string, durum: string, one-cikan: string} $filters
 * @var array<string, string> $categories
 * @var array<string, string> $statuses
 * @var bool $importerAvailable
 * @var array<string, mixed> $old values of a failed add attempt
 * @var string $back
 */

$listUrl = static fn (array $params): string => url('/admin/videolar') . (($qs = http_build_query(array_filter($params, static fn ($v): bool => $v !== '' && $v !== null))) !== '' ? '?' . $qs : '');
$baseUrl = $listUrl($filters);
$hasFilters = implode('', $filters) !== '';
$statusBadges = ['published' => 'badge-success', 'draft' => 'badge-muted', 'hidden' => 'badge-warning'];
$sourceLabels = ['manual' => 'Elle', 'rss' => 'RSS', 'api' => 'API'];
$clock = static function (?int $seconds): string {
    if ($seconds === null || $seconds <= 0) {
        return '';
    }
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    $s = $seconds % 60;
    return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%d:%02d', $m, $s);
};
?>
<section class="admin-card" aria-labelledby="add-title">
  <h2 id="add-title">YouTube'dan video ekle</h2>
  <?php if (!$importerAvailable): ?>
  <div class="notice notice-warning">Video modülü (VideoImporter) bulunamadı; şu an video eklenemiyor.</div>
  <?php endif; ?>
  <form class="add-video-form" method="post" action="<?= e(url('/admin/videolar/ekle')) ?>" data-busy="Video bilgileri alınıyor…">
    <?= csrf_field() ?>
    <input type="hidden" name="_back" value="<?= e($back) ?>">
    <div class="field grow">
      <label for="video-url">YouTube bağlantısı veya video kimliği</label>
      <input class="input" id="video-url" name="url" type="text" inputmode="url" value="<?= e($old['url'] ?? '') ?>" placeholder="https://www.youtube.com/watch?v=…" required spellcheck="false" autocomplete="off">
    </div>
    <div class="field">
      <label for="video-category">Kategori</label>
      <select class="select" id="video-category" name="category">
        <option value="">Otomatik belirle</option>
        <?php foreach ($categories as $key => $label): ?>
        <option value="<?= e($key) ?>"<?= ($old['category'] ?? '') === $key ? ' selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <label class="checkbox add-video-featured">
      <input type="checkbox" name="is_featured" value="1"<?= !empty($old['is_featured']) ? ' checked' : '' ?>>
      <span>Öne çıkar</span>
    </label>
    <button class="btn btn-gold" type="submit"<?= $importerAvailable ? '' : ' disabled' ?>>Videoyu ekle</button>
  </form>
  <p class="form-help">youtube.com/watch, youtu.be, /shorts/ ve /embed/ bağlantıları desteklenir. Başlık ve küçük resim YouTube'dan otomatik alınır.</p>
</section>

<form class="filter-form" method="get" action="<?= e(url('/admin/videolar')) ?>" role="search">
  <div class="field grow">
    <label for="f-q">Ara</label>
    <input class="input" id="f-q" name="q" type="search" value="<?= e($filters['q']) ?>" placeholder="Başlık, kanal ya da video kimliği">
  </div>
  <div class="field">
    <label for="f-kategori">Kategori</label>
    <select class="select" id="f-kategori" name="kategori" data-autosubmit>
      <option value="">Tümü</option>
      <?php foreach ($categories as $key => $label): ?>
      <option value="<?= e($key) ?>"<?= $filters['kategori'] === $key ? ' selected' : '' ?>><?= e($label) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="field">
    <label for="f-durum">Durum</label>
    <select class="select" id="f-durum" name="durum" data-autosubmit>
      <option value="">Tümü</option>
      <?php foreach ($statuses as $key => $label): ?>
      <option value="<?= e($key) ?>"<?= $filters['durum'] === $key ? ' selected' : '' ?>><?= e($label) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <label class="checkbox filter-check">
    <input type="checkbox" name="one-cikan" value="1"<?= $filters['one-cikan'] === '1' ? ' checked' : '' ?> data-autosubmit>
    <span>Yalnızca öne çıkanlar</span>
  </label>
  <div class="filter-actions">
    <button class="btn btn-primary" type="submit">Filtrele</button>
    <?php if ($hasFilters): ?><a class="btn btn-ghost" href="<?= e(url('/admin/videolar')) ?>">Temizle</a><?php endif; ?>
  </div>
</form>

<?php if ($videos === []): ?>
<div class="empty-state">
  <span class="empty-state-icon" aria-hidden="true">7</span>
  <h2><?= $hasFilters ? 'Bu filtreye uyan video yok' : 'Henüz video yok' ?></h2>
  <p><?= $hasFilters ? 'Filtreleri değiştirip tekrar deneyin.' : 'Yukarıdaki formdan YouTube bağlantısıyla video ekleyin ya da video senkronizasyonunu panelden çalıştırın.' ?></p>
</div>
<?php else: ?>
<p class="muted text-sm"><?= e(fmt_number($pager['total'])) ?> video · sayfa <?= $pager['page'] ?> / <?= $pager['pages'] ?></p>
<div class="table-wrap">
  <table class="table table-stack video-table">
    <thead>
      <tr>
        <th scope="col">Video</th>
        <th scope="col">Kategori</th>
        <th scope="col">Durum</th>
        <th scope="col">Kaynak</th>
        <th scope="col">Tarih</th>
        <th scope="col"><span class="visually-hidden">İşlemler</span></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($videos as $video):
          $editUrl = url('/admin/videolar/' . (int) $video['id'] . '/duzenle');
          $duration = $clock(isset($video['duration_seconds']) ? (int) $video['duration_seconds'] : null);
      ?>
      <tr>
        <td data-label="Video" class="cell-video">
          <a class="video-thumb" href="<?= e($editUrl) ?>" tabindex="-1" aria-hidden="true">
            <img src="<?= e($video['thumbnail_url'] ?: 'https://i.ytimg.com/vi/' . $video['youtube_id'] . '/mqdefault.jpg') ?>" width="120" height="68" alt="" loading="lazy">
            <?php if ($duration !== ''): ?><span class="video-duration"><?= e($duration) ?></span><?php endif; ?>
          </a>
          <span class="cell-title">
            <a href="<?= e($editUrl) ?>"><?= e($video['title']) ?></a>
            <span class="cell-sub"><?= e($video['channel_title'] ?: 'Kanal bilinmiyor') ?> · <span class="mono"><?= e($video['youtube_id']) ?></span></span>
            <?php if ((int) $video['is_featured'] === 1): ?><span class="badge badge-warning">★ Öne çıkan</span><?php endif; ?>
            <?php if (!\App\Services\Video\VideoText::looksTurkish((string) $video['title'])): ?><span class="badge badge-muted" title="Türkçe başlık verilene kadar arama motorlarına kapalı ve video site haritasının dışında">noindex · Türkçe başlık yok</span><?php endif; ?>
          </span>
        </td>
        <td data-label="Kategori" class="nowrap"><?= e($categories[$video['category']] ?? $video['category']) ?></td>
        <td data-label="Durum"><span class="badge <?= e($statusBadges[$video['status']] ?? 'badge-muted') ?>"><?= e($statuses[$video['status']] ?? $video['status']) ?></span></td>
        <td data-label="Kaynak"><?= e($sourceLabels[$video['source']] ?? $video['source']) ?></td>
        <td data-label="Tarih" class="nowrap"><?= e(fmt_date((string) ($video['published_at'] ?: $video['created_at']), 'j M Y')) ?></td>
        <td class="cell-actions">
          <div class="actions">
            <a class="btn btn-sm" href="<?= e($editUrl) ?>">Düzenle</a>
            <?php if ($video['status'] === 'published'): ?><a class="btn btn-sm btn-ghost" href="<?= e(url('/video/' . $video['slug'])) ?>" target="_blank" rel="noopener">Görüntüle ↗</a><?php endif; ?>
            <form method="post" action="<?= e(url('/admin/videolar/' . (int) $video['id'] . '/sil')) ?>" data-confirm="“<?= e(mb_strimwidth((string) $video['title'], 0, 80, '…')) ?>” silinsin mi?">
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
