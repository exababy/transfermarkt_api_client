<?php
/**
 * Edit a video.
 *
 * @var array<string, mixed> $video
 * @var array<string, string> $errors
 * @var array<string, string> $categories
 * @var array<string, string> $statuses
 * @var string|null $publicUrl
 */

$err = static fn (string $name): string => isset($errors[$name]) ? '<p class="form-error" id="' . e($name) . '-error">' . e($errors[$name]) . '</p>' : '';
$aria = static fn (string $name): string => isset($errors[$name]) ? ' aria-invalid="true" aria-describedby="' . e($name) . '-error"' : '';
$thumb = $video['thumbnail_url'] ?: 'https://i.ytimg.com/vi/' . $video['youtube_id'] . '/hqdefault.jpg';
$sourceLabels = ['manual' => 'Elle eklendi', 'rss' => 'YouTube RSS', 'api' => 'YouTube API'];
?>
<?php if ($errors !== []): ?>
<div class="notice notice-error" role="alert"><strong>Video kaydedilemedi.</strong> Lütfen işaretli alanları düzeltin.</div>
<?php endif; ?>

<div class="post-layout">
  <form class="post-main admin-card form" method="post" action="<?= e(url('/admin/videolar/' . (int) $video['id'] . '/duzenle')) ?>">
    <?= csrf_field() ?>
    <div class="field">
      <label for="title">Başlık <span class="req" aria-hidden="true">*</span></label>
      <input class="input input-lg" id="title" name="title" type="text" value="<?= e($video['title']) ?>" maxlength="255" required data-counter="255"<?= $aria('title') ?>>
      <?= $err('title') ?>
      <p class="form-help">Türkçe olmayan başlıklı videolar (kanallardan otomatik gelenler) <code>noindex</code> olur ve video site haritasına girmez; Türkçe bir başlık yazınca indekslenir.</p>
    </div>
    <div class="form-row">
      <div class="field">
        <label for="category">Kategori</label>
        <select class="select" id="category" name="category"<?= $aria('category') ?>>
          <?php foreach ($categories as $key => $label): ?>
          <option value="<?= e($key) ?>"<?= $video['category'] === $key ? ' selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
        <?= $err('category') ?>
      </div>
      <div class="field">
        <label for="status">Durum</label>
        <select class="select" id="status" name="status"<?= $aria('status') ?>>
          <?php foreach ($statuses as $key => $label): ?>
          <option value="<?= e($key) ?>"<?= $video['status'] === $key ? ' selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
        <?= $err('status') ?>
      </div>
    </div>
    <label class="checkbox">
      <input type="checkbox" name="is_featured" value="1"<?= (int) $video['is_featured'] === 1 ? ' checked' : '' ?>>
      <span>Öne çıkan video<span class="form-help block">Ana sayfada ve video sayfasının üst bölümünde gösterilir.</span></span>
    </label>
    <div class="field">
      <label for="description">Açıklama</label>
      <textarea class="textarea" id="description" name="description" rows="8" maxlength="5000" data-counter="5000"<?= $aria('description') ?>><?= e($video['description'] ?? '') ?></textarea>
      <p class="form-help">Düz metin; satır sonları korunur. Video sayfasında ve VideoObject yapılandırılmış verisinde kullanılır.</p>
      <?= $err('description') ?>
    </div>
    <div class="form-actions">
      <button class="btn btn-gold" type="submit">Değişiklikleri kaydet</button>
      <a class="btn" href="<?= e(url('/admin/videolar')) ?>">Listeye dön</a>
    </div>
  </form>

  <aside class="post-side">
    <section class="admin-card side-card" aria-labelledby="preview-title">
      <h2 id="preview-title">Önizleme</h2>
      <img class="og-preview" src="<?= e($thumb) ?>" width="480" height="360" alt="“<?= e($video['title']) ?>” videosunun küçük resmi" loading="lazy">
      <div class="form-actions">
        <a class="btn btn-sm" href="https://www.youtube.com/watch?v=<?= e(rawurlencode((string) $video['youtube_id'])) ?>" target="_blank" rel="noopener noreferrer">YouTube'da aç ↗</a>
        <?php if ($publicUrl !== null): ?><a class="btn btn-sm" href="<?= e($publicUrl) ?>" target="_blank" rel="noopener">Sitede görüntüle ↗</a><?php endif; ?>
      </div>
    </section>
    <section class="admin-card side-card" aria-labelledby="vinfo-title">
      <h2 id="vinfo-title">Bilgiler</h2>
      <dl class="meta-list">
        <dt>Video kimliği</dt><dd class="mono"><?= e($video['youtube_id']) ?></dd>
        <dt>Adres</dt><dd class="mono break">/video/<?= e($video['slug']) ?></dd>
        <dt>Kanal</dt><dd><?= e($video['channel_title'] ?: '—') ?></dd>
        <dt>Kaynak</dt><dd><?= e($sourceLabels[$video['source']] ?? $video['source']) ?></dd>
        <dt>YouTube tarihi</dt><dd><?= !empty($video['published_at']) ? e(fmt_date((string) $video['published_at'], 'j M Y H:i')) : '—' ?></dd>
        <?php if ($video['view_count'] !== null): ?><dt>İzlenme</dt><dd><?= e(fmt_number((int) $video['view_count'])) ?></dd><?php endif; ?>
        <dt>Eklenme</dt><dd><?= e(fmt_date((string) $video['created_at'], 'j M Y H:i')) ?></dd>
      </dl>
    </section>
    <section class="admin-card side-card danger-zone" aria-labelledby="vdelete-title">
      <h2 id="vdelete-title">Videoyu sil</h2>
      <p class="muted text-sm">Otomatik senkronizasyonla gelen videolar sonraki senkronizasyonda yeniden eklenebilir; kalıcı olarak gizlemek için durumu “Gizli” yapın.</p>
      <form method="post" action="<?= e(url('/admin/videolar/' . (int) $video['id'] . '/sil')) ?>" data-confirm="Bu video silinsin mi?">
        <?= csrf_field() ?>
        <button class="btn btn-danger btn-block" type="submit">Videoyu sil</button>
      </form>
    </section>
  </aside>
</div>
