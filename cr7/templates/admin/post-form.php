<?php
/**
 * Create / edit a news post.
 *
 * @var array<string, mixed> $post
 * @var array<string, string> $errors
 * @var bool $isNew
 * @var array<string, string> $statuses
 * @var int $summaryMax
 * @var string|null $publicUrl
 * @var list<array<string, mixed>> $sources news_items the post was composed from
 * @var string $publishedLocal datetime-local value (Istanbul)
 */

$action = $isNew ? url('/admin/haberler/yeni') : url('/admin/haberler/' . (int) $post['id'] . '/duzenle');
$isSpun = (int) ($post['is_spun'] ?? 0) === 1;
$err = static fn (string $name): string => isset($errors[$name]) ? '<p class="form-error" id="' . e($name) . '-error">' . e($errors[$name]) . '</p>' : '';
$aria = static fn (string $name, string $help = ''): string => (isset($errors[$name]) ? ' aria-invalid="true"' : '')
    . (($ids = trim(($help !== '' ? $help . ' ' : '') . (isset($errors[$name]) ? $name . '-error' : ''))) !== '' ? ' aria-describedby="' . e($ids) . '"' : '');
$imageUrl = \App\Repositories\NewsRepository::imageUrl($post['image_path'] ?? null);
$imageMissing = $imageUrl === null && !empty($post['image_path']);
$statusLabels = ['pending' => 'Bekliyor', 'failed' => 'Başarısız', 'duplicate' => 'Aynı hikâye', 'skipped' => 'Atlandı', 'published' => 'Yayınlandı'];
?>
<?php if ($errors !== []): ?>
<div class="notice notice-error" role="alert">
  <strong>Haber kaydedilemedi.</strong> Lütfen işaretli alanları düzeltin:
  <ul class="error-list"><?php foreach ($errors as $name => $message): ?><li><a href="#<?= e($name) ?>"><?= e($message) ?></a></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<form class="post-layout" method="post" action="<?= e($action) ?>" id="post-form">
  <?= csrf_field() ?>
  <div class="post-main">
    <section class="admin-card form" aria-label="Haber içeriği">
      <div class="field">
        <label for="title">Başlık <span class="req" aria-hidden="true">*</span></label>
        <input class="input input-lg" id="title" name="title" type="text" value="<?= e($post['title']) ?>" maxlength="255" required<?= $isNew ? ' data-slug-source="#slug"' : '' ?> data-counter="255"<?= $aria('title') ?>>
        <?= $err('title') ?>
      </div>
      <div class="field">
        <label for="slug">Adres (slug)</label>
        <input class="input mono" id="slug" name="slug" type="text" value="<?= e($post['slug']) ?>" maxlength="190" pattern="[a-z0-9\-]*" spellcheck="false"<?= $aria('slug', 'slug-help') ?>>
        <p class="form-help" id="slug-help">Boş bırakırsanız başlıktan üretilir. <span class="slug-preview" data-slug-preview data-prefix="<?= e(url('/haber/')) ?>"><?= e(url('/haber/' . $post['slug'])) ?></span><?php if (!$isNew && ($post['status'] ?? '') === 'published'): ?><br>Yayındaki bir haberin adresini değiştirmek eski bağlantıları ve arama motoru sıralamasını etkiler.<?php endif; ?></p>
        <?= $err('slug') ?>
      </div>
      <div class="field">
        <label for="summary">Özet</label>
        <textarea class="textarea textarea-sm" id="summary" name="summary" rows="3" maxlength="<?= (int) $summaryMax ?>" data-counter="<?= (int) $summaryMax ?>"<?= $aria('summary', 'summary-help') ?>><?= e($post['summary']) ?></textarea>
        <p class="form-help" id="summary-help">Haber listelerinde ve meta açıklamada kullanılır (ideal 120–160 karakter). Boş bırakılırsa metnin başından üretilir.</p>
        <?= $err('summary') ?>
      </div>
      <div class="field">
        <label for="body">Haber metni (HTML) <span class="req" aria-hidden="true">*</span></label>
        <textarea class="textarea code textarea-xl" id="body" name="body" rows="18" spellcheck="true"<?= $aria('body', 'body-help') ?>><?= e($post['body']) ?></textarea>
        <p class="form-help" id="body-help">İzin verilen etiketler: <code>&lt;p&gt; &lt;h2&gt; &lt;h3&gt; &lt;h4&gt; &lt;ul&gt; &lt;ol&gt; &lt;li&gt; &lt;strong&gt; &lt;em&gt; &lt;blockquote&gt; &lt;a&gt; &lt;table&gt; &lt;figure&gt;</code>. Diğer etiketler, stiller ve betikler kayıtta temizlenir.</p>
        <?= $err('body') ?>
      </div>
      <div class="field">
        <label for="tags">Etiketler</label>
        <input class="input" id="tags" name="tags" type="text" value="<?= e($post['tags'] ?? '') ?>" maxlength="500" placeholder="Al-Nassr, Suudi Pro Lig, Rekor"<?= $aria('tags', 'tags-help') ?>>
        <p class="form-help" id="tags-help">Virgülle ayırın (en fazla 15). Her etiketin kendi sayfası olur: /haberler/etiket/…</p>
        <?= $err('tags') ?>
      </div>
    </section>

    <section class="admin-card" aria-labelledby="sources-title">
      <h2 id="sources-title">Kaynaklar</h2>
      <?php if ($isSpun): ?>
      <p class="muted text-sm">Bu haber otomatik olarak aşağıdaki kaynaklardan derlenip yeniden yazıldı; kaynak bilgileri değiştirilemez.</p>
      <dl class="meta-list">
        <dt>Kaynak adı</dt><dd><?= e($post['source_name'] ?: '—') ?></dd>
        <dt>Ana kaynak</dt><dd><?php if (!empty($post['source_url'])): ?><a href="<?= e($post['source_url']) ?>" target="_blank" rel="noopener noreferrer nofollow" class="break"><?= e($post['source_url']) ?></a><?php else: ?>—<?php endif; ?></dd>
      </dl>
      <?php if ($sources !== []): ?>
      <ul class="source-items">
        <?php foreach ($sources as $item): ?>
        <li>
          <span class="badge<?= $item['status'] === 'published' ? ' badge-success' : '' ?>"><?= e($statusLabels[$item['status']] ?? $item['status']) ?></span>
          <a href="<?= e($item['url']) ?>" target="_blank" rel="noopener noreferrer nofollow"><?= e($item['title']) ?></a>
          <span class="cell-sub"><?= e($item['source_name'] ?: $item['source']) ?><?php if (!empty($item['published_at'])): ?> · <?= e(fmt_date((string) $item['published_at'], 'j M Y H:i')) ?><?php endif; ?> · <a href="<?= e(url('/admin/haber-kuyrugu?kume=' . (int) $post['news_item_id'])) ?>">kuyrukta gör</a></span>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
      <?php else: ?>
      <div class="form-row">
        <div class="field">
          <label for="source_name">Kaynak adı</label>
          <input class="input" id="source_name" name="source_name" type="text" value="<?= e($post['source_name'] ?? '') ?>" maxlength="150" placeholder="ör. Al-Nassr resmi sitesi"<?= $aria('source_name') ?>>
          <?= $err('source_name') ?>
        </div>
        <div class="field">
          <label for="source_url">Kaynak adresi</label>
          <input class="input" id="source_url" name="source_url" type="url" value="<?= e($post['source_url'] ?? '') ?>" maxlength="1000" placeholder="https://"<?= $aria('source_url') ?>>
          <?= $err('source_url') ?>
        </div>
      </div>
      <p class="form-help">İsteğe bağlı. Doldurulursa haberin sonunda kaynak bağlantısı olarak gösterilir.</p>
      <?php endif; ?>
    </section>
  </div>

  <aside class="post-side">
    <section class="admin-card side-card" aria-labelledby="publish-title">
      <h2 id="publish-title">Yayın</h2>
      <div class="form">
        <div class="field">
          <label for="status">Durum</label>
          <select class="select" id="status" name="status"<?= $aria('status') ?>>
            <?php foreach ($statuses as $value => $label): ?>
            <option value="<?= e($value) ?>"<?= ($post['status'] ?? '') === $value ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
          <?= $err('status') ?>
        </div>
        <div class="field">
          <label for="published_at">Yayın tarihi (İstanbul saati)</label>
          <input class="input" id="published_at" name="published_at" type="datetime-local" value="<?= e($publishedLocal) ?>"<?= $aria('published_at', 'published-help') ?>>
          <p class="form-help" id="published-help">İleri bir tarih seçilirse haber o saatte yayına girer.</p>
          <?= $err('published_at') ?>
        </div>
        <label class="checkbox">
          <input type="checkbox" name="is_indexable" value="1"<?= (int) ($post['is_indexable'] ?? 1) === 1 ? ' checked' : '' ?>>
          <span>Arama motorlarında indekslensin<span class="form-help block">Kapalıysa sayfa <code>noindex</code> olur ve site haritasına girmez.</span></span>
        </label>
        <div class="form-actions">
          <button class="btn btn-gold btn-block" type="submit"><?= $isNew ? 'Haberi kaydet' : 'Değişiklikleri kaydet' ?></button>
          <?php if ($publicUrl !== null): ?><a class="btn btn-block" href="<?= e($publicUrl) ?>" target="_blank" rel="noopener">Sitede görüntüle ↗</a><?php endif; ?>
        </div>
      </div>
    </section>

    <section class="admin-card side-card" aria-labelledby="seo-title">
      <h2 id="seo-title">SEO</h2>
      <div class="field">
        <label for="meta_title">SEO başlığı</label>
        <input class="input" id="meta_title" name="meta_title" type="text" value="<?= e($post['meta_title'] ?? '') ?>" maxlength="255" data-counter="60" data-counter-soft<?= $aria('meta_title', 'meta-help') ?>>
        <p class="form-help" id="meta-help">Boşsa haber başlığı kullanılır. Google'da tam görünmesi için 60 karakteri geçmemesi önerilir.</p>
        <?= $err('meta_title') ?>
      </div>
    </section>

    <section class="admin-card side-card" aria-labelledby="image-title">
      <h2 id="image-title">Paylaşım görseli</h2>
      <?php if ($imageUrl !== null): ?>
      <img class="og-preview" src="<?= e($imageUrl) ?>?v=<?= e(substr(sha1((string) ($post['updated_at'] ?? '')), 0, 8)) ?>" width="1200" height="630" alt="“<?= e($post['title']) ?>” haberinin paylaşım görseli" loading="lazy">
      <?php elseif ($imageMissing): ?>
      <p class="notice notice-warning text-sm">Görsel dosyası bulunamadı; sitede yer tutucu gösteriliyor. Kaydettiğinizde görsel yeniden oluşturulur.</p>
      <?php else: ?>
      <p class="muted text-sm">Kaydettiğinizde başlıktan 1200×630 boyutunda bir Open Graph görseli otomatik oluşturulur.</p>
      <?php endif; ?>
      <?php if (!$isNew): ?>
      <label class="checkbox">
        <input type="checkbox" name="regenerate_image" value="1">
        <span>Kaydederken görseli yeniden oluştur<span class="form-help block">Başlık değişirse görsel zaten yenilenir.</span></span>
      </label>
      <?php endif; ?>
    </section>

    <?php if (!$isNew): ?>
    <section class="admin-card side-card" aria-labelledby="info-title">
      <h2 id="info-title">Bilgiler</h2>
      <dl class="meta-list">
        <dt>Tür</dt><dd><?= $isSpun ? '<span class="badge badge-info">Otomatik (spin)</span>' : '<span class="badge">Elle yazıldı</span>' ?></dd>
        <dt>Özgünlük</dt><dd><?= $post['uniqueness'] !== null ? '%' . e(fmt_number((float) $post['uniqueness'], 1)) : '—' ?></dd>
        <dt>Kaynak sayısı</dt><dd><?= e((string) (int) ($post['source_count'] ?? 0)) ?></dd>
        <dt>Okunma</dt><dd><?= e(fmt_number((int) ($post['views'] ?? 0))) ?></dd>
        <?php if (!empty($post['news_item_id'])): ?><dt>Kuyruk kaydı</dt><dd><a href="<?= e(url('/admin/haber-kuyrugu?kume=' . (int) $post['news_item_id'])) ?>">#<?= (int) $post['news_item_id'] ?></a></dd><?php endif; ?>
        <dt>Oluşturulma</dt><dd><?= e(fmt_date((string) ($post['created_at'] ?? ''), 'j M Y H:i')) ?></dd>
        <dt>Son güncelleme</dt><dd><?= e(fmt_date((string) ($post['updated_at'] ?? ''), 'j M Y H:i')) ?></dd>
      </dl>
    </section>
    <?php endif; ?>
  </aside>
</form>

<?php if (!$isNew): ?>
<section class="admin-card danger-zone" aria-labelledby="danger-title">
  <div class="card-header">
    <div>
      <h2 id="danger-title">Haberi sil</h2>
      <p class="muted text-sm mb-0">Haber ve paylaşım görseli kalıcı olarak silinir; kaynak kaydı kuyrukta “Atlandı” olarak işaretlenir.</p>
    </div>
    <form method="post" action="<?= e(url('/admin/haberler/' . (int) $post['id'] . '/sil')) ?>" data-confirm="Bu haber kalıcı olarak silinsin mi? Bu işlem geri alınamaz.">
      <?= csrf_field() ?>
      <button class="btn btn-danger" type="submit">Haberi sil</button>
    </form>
  </div>
</section>
<?php endif; ?>
