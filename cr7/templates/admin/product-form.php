<?php
/**
 * Create / edit a product (/admin/urunler/yeni, /admin/urunler/{id}/duzenle).
 *
 * @var array<string, mixed>|null $product existing product (null => new)
 * @var array<string, mixed> $values form values
 * @var array<string, string> $errors
 * @var array<string, string> $clubs club_id => name
 * @var array<string, string> $kitTypes
 * @var bool $catalogKit product uses one of the designed catalogue kits
 */

$val = static fn (string $key): string => is_bool($values[$key] ?? null) ? ($values[$key] ? '1' : '') : (string) ($values[$key] ?? '');
$checked = static fn (string $key): string => !empty($values[$key]) ? ' checked' : '';
$err = static fn (string $key): string => isset($errors[$key]) ? '<p class="form-error" id="' . e($key) . '-error">' . e($errors[$key]) . '</p>' : '';
$aria = static fn (string $key): string => isset($errors[$key]) ? ' aria-invalid="true" aria-describedby="' . e($key) . '-error"' : '';
$action = $product === null ? url('/admin/urunler/yeni') : url('/admin/urunler/' . (int) $product['id'] . '/duzenle');
$knownClub = isset($clubs[$val('club_id')]);
?>
<style>.kit-preview{padding:12px;border-radius:10px;background:radial-gradient(circle at 50% 32%,#26262f,#111115 72%);object-fit:contain}</style>
<?php if ($errors !== []): ?>
<div class="notice notice-error" role="alert"><strong>Ürün kaydedilemedi.</strong> Lütfen işaretli alanları düzeltin.</div>
<?php endif; ?>

<div class="post-layout">
  <form class="post-main admin-card form" method="post" action="<?= e($action) ?>" enctype="multipart/form-data" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="MAX_FILE_SIZE" value="2097152">
    <div class="field">
      <label for="title">Ürün adı <span class="req" aria-hidden="true">*</span></label>
      <input class="input input-lg" id="title" name="title" type="text" maxlength="255" value="<?= e($val('title')) ?>" required data-slug-source="#slug"<?= $aria('title') ?>>
      <?= $err('title') ?>
    </div>
    <div class="field">
      <label for="slug">Adres (slug)</label>
      <input class="input mono" id="slug" name="slug" type="text" maxlength="190" value="<?= e($val('slug')) ?>" spellcheck="false"<?= $aria('slug') ?>>
      <p class="form-help">Sayfa adresi: <span class="mono" data-slug-preview data-prefix="/forma/"><?= e('/forma/' . $val('slug')) ?></span><?= $product !== null ? ' · Yayındaki bir ürünün adresini değiştirmek eski bağlantıları kırar.' : '' ?></p>
      <?= $err('slug') ?>
    </div>
    <div class="form-row">
      <div class="field">
        <label for="club_id">Kulüp</label>
        <select class="select" id="club_id" name="club_id"<?= $aria('club_id') ?>>
          <option value="">Diğer / kulüpsüz</option>
          <?php foreach ($clubs as $id => $name): ?>
          <option value="<?= e((string) $id) ?>"<?= $val('club_id') === (string) $id ? ' selected' : '' ?>><?= e($name) ?></option>
          <?php endforeach; ?>
          <?php if ($val('club_id') !== '' && !$knownClub): ?><option value="<?= e($val('club_id')) ?>" selected><?= e($val('club_id')) ?></option><?php endif; ?>
        </select>
        <p class="form-help">Mağazadaki kulüp filtresi bu alana göre çalışır.</p>
        <?= $err('club_id') ?>
      </div>
      <div class="field">
        <label for="club_name">Kulüp adı (marka) <span class="req" aria-hidden="true">*</span></label>
        <input class="input" id="club_name" name="club_name" type="text" maxlength="150" value="<?= e($val('club_name')) ?>" placeholder="Boş bırakılırsa seçilen kulübün adı"<?= $aria('club_name') ?>>
        <?= $err('club_name') ?>
      </div>
    </div>
    <div class="form-row">
      <div class="field">
        <label for="season">Sezon</label>
        <input class="input" id="season" name="season" type="text" maxlength="20" value="<?= e($val('season')) ?>" placeholder="2025–26">
      </div>
      <div class="field">
        <label for="kit_type">Forma türü</label>
        <select class="select" id="kit_type" name="kit_type"<?= $aria('kit_type') ?>>
          <?php foreach ($kitTypes as $key => $label): ?>
          <option value="<?= e($key) ?>"<?= $val('kit_type') === $key ? ' selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
        <?= $err('kit_type') ?>
      </div>
    </div>
    <div class="field">
      <label for="description">Açıklama</label>
      <textarea class="textarea" id="description" name="description" rows="9" maxlength="5000" data-counter="5000"<?= $aria('description') ?>><?= e($val('description')) ?></textarea>
      <p class="form-help">Düz metin; paragrafları boş satırla ayırın. “Orijinal” veya “lisanslı” gibi ifadeleri yalnızca ürün gerçekten öyleyse kullanın.</p>
      <?= $err('description') ?>
    </div>
    <div class="form-row">
      <div class="field">
        <label for="price">Satış fiyatı (TL) <span class="req" aria-hidden="true">*</span></label>
        <input class="input" id="price" name="price" type="text" inputmode="decimal" value="<?= e($val('price')) ?>" placeholder="1.499,90" required<?= $aria('price') ?>>
        <?= $err('price') ?>
      </div>
      <div class="field">
        <label for="old_price">Eski fiyat (TL)</label>
        <input class="input" id="old_price" name="old_price" type="text" inputmode="decimal" value="<?= e($val('old_price')) ?>" placeholder="İndirim yoksa boş"<?= $aria('old_price') ?>>
        <?= $err('old_price') ?>
      </div>
    </div>
    <div class="form-row">
      <div class="field">
        <label for="sizes">Yetişkin bedenleri</label>
        <input class="input" id="sizes" name="sizes" type="text" maxlength="190" value="<?= e($val('sizes')) ?>"<?= $aria('sizes') ?>>
        <p class="form-help">Virgülle ayırın: S,M,L,XL,XXL</p>
        <?= $err('sizes') ?>
      </div>
      <div class="field">
        <label for="kids_sizes">Çocuk bedenleri</label>
        <input class="input" id="kids_sizes" name="kids_sizes" type="text" maxlength="190" value="<?= e($val('kids_sizes')) ?>">
        <p class="form-help">Örn. 6-7 Yaş,8-9 Yaş (boş bırakılabilir)</p>
      </div>
    </div>
    <div class="form-row">
      <label class="checkbox"><input type="checkbox" name="allow_print" value="1"<?= $checked('allow_print') ?>><span>İsim-numara baskısı sunulsun</span></label>
      <div class="field">
        <label for="print_price">Baskı ücreti (TL)</label>
        <input class="input" id="print_price" name="print_price" type="text" inputmode="decimal" value="<?= e($val('print_price')) ?>"<?= $aria('print_price') ?>>
        <?= $err('print_price') ?>
      </div>
    </div>
    <div class="form-row">
      <div class="field">
        <label for="stock">Stok</label>
        <input class="input" id="stock" name="stock" type="text" inputmode="numeric" value="<?= e($val('stock')) ?>" placeholder="Boş = sipariş üzerine"<?= $aria('stock') ?>>
        <p class="form-help">Boş bırakılırsa ürün “sipariş üzerine” satılır; sayı girilirse her siparişte düşülür.</p>
        <?= $err('stock') ?>
      </div>
      <div class="field">
        <label for="sort_order">Sıra</label>
        <input class="input" id="sort_order" name="sort_order" type="number" step="1" value="<?= e($val('sort_order')) ?>"<?= $aria('sort_order') ?>>
        <p class="form-help">Küçük sayı önce listelenir.</p>
        <?= $err('sort_order') ?>
      </div>
    </div>
    <label class="checkbox"><input type="checkbox" name="is_active" value="1"<?= $checked('is_active') ?>><span>Yayında (mağazada ve site haritasında görünsün)</span></label>

    <section class="settings-group" aria-labelledby="img-title">
      <h2 id="img-title">Görsel</h2>
      <div class="field">
        <label for="image">Ürün fotoğrafı yükle</label>
        <input class="input" id="image" name="image" type="file" accept="image/jpeg,image/png,image/webp"<?= $aria('image') ?>>
        <p class="form-help">JPG, PNG veya WebP, en fazla 2 MB. Yüklenmezse renklerden üretilen forma çizimi kullanılır. Kulüp arması/sponsor logosu içeren telif hakkı korumalı fotoğraflar kullanmayın.</p>
        <?= $err('image') ?>
      </div>
      <?php if ($product !== null && !empty($product['image_path']) && str_starts_with((string) $product['image_path'], '/uploads/')): ?>
      <label class="checkbox"><input type="checkbox" name="remove_image" value="1"<?= $checked('remove_image') ?>><span>Yüklenen fotoğrafı kaldır, forma çizimine dön</span></label>
      <?php endif; ?>
      <div class="form-row">
        <div class="field">
          <label for="color1">Ana renk</label>
          <input class="input" id="color1" name="color1" type="color" value="<?= e($val('color1') !== '' ? $val('color1') : '#c8102e') ?>"<?= $aria('color1') ?>>
          <?= $err('color1') ?>
        </div>
        <div class="field">
          <label for="color2">İkinci renk (yaka, kol, desen)</label>
          <input class="input" id="color2" name="color2" type="color" value="<?= e($val('color2') !== '' ? $val('color2') : '#ffffff') ?>"<?= $aria('color2') ?>>
          <?= $err('color2') ?>
        </div>
      </div>
      <div class="form-row">
        <div class="field">
          <label for="kit_pattern">Çizim deseni</label>
          <select class="select" id="kit_pattern" name="kit_pattern"<?= $catalogKit ? ' disabled' : '' ?>>
            <?php foreach (['solid' => 'Düz', 'hoops' => 'Yatay çizgili', 'stripes' => 'Dikey çizgili', 'halves' => 'İki renkli'] as $key => $label): ?>
            <option value="<?= e($key) ?>"<?= $val('kit_pattern') === $key ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if ($catalogKit): ?><p class="form-help">Bu ürün kataloğun özel çizimini kullanır.</p><?php endif; ?>
        </div>
        <label class="checkbox"><input type="checkbox" name="generate_kit" value="1"<?= $checked('generate_kit') ?>><span>Forma çizimini (arka + ön) ve paylaşım görselini yeniden oluştur</span></label>
      </div>
    </section>
    <div class="form-actions">
      <button class="btn btn-gold" type="submit"><?= $product === null ? 'Ürünü ekle' : 'Değişiklikleri kaydet' ?></button>
      <a class="btn" href="<?= e(url('/admin/urunler')) ?>">Listeye dön</a>
    </div>
  </form>

  <aside class="post-side">
    <section class="admin-card side-card" aria-labelledby="pv-title">
      <h2 id="pv-title">Önizleme</h2>
      <?php if ($product !== null && $product['image_url'] !== ''): ?>
      <img class="og-preview kit-preview" src="<?= e($product['image_url']) ?>" width="300" height="310" alt="<?= e($product['title']) ?>" loading="lazy">
      <?php else: ?>
      <p class="muted text-sm">Kaydettikten sonra görsel burada görünür.</p>
      <?php endif; ?>
      <?php if ($product !== null && $product['is_active']): ?>
      <div class="form-actions"><a class="btn btn-sm" href="<?= e(url($product['url'])) ?>" target="_blank" rel="noopener">Sitede görüntüle ↗</a></div>
      <?php endif; ?>
    </section>
    <?php if ($product !== null): ?>
    <section class="admin-card side-card" aria-labelledby="pi-title">
      <h2 id="pi-title">Bilgiler</h2>
      <dl class="meta-list">
        <dt>Ürün no</dt><dd class="mono">#<?= (int) $product['id'] ?></dd>
        <dt>Görsel</dt><dd class="mono break"><?= e((string) ($product['image_path'] ?: 'çizim')) ?></dd>
        <dt>Eklenme</dt><dd><?= e(fmt_date((string) $product['created_at'], 'j M Y H:i')) ?></dd>
        <dt>Güncelleme</dt><dd><?= e(fmt_date((string) $product['updated_at'], 'j M Y H:i')) ?></dd>
      </dl>
    </section>
    <section class="admin-card side-card danger-zone" aria-labelledby="pd-title">
      <h2 id="pd-title">Ürünü sil</h2>
      <p class="muted text-sm">Geçmiş siparişlerde ürün adı korunur. Satışı geçici olarak durdurmak için “Yayında” kutusunu kaldırmanız yeterli.</p>
      <form method="post" action="<?= e(url('/admin/urunler/' . (int) $product['id'] . '/sil')) ?>" data-confirm="Bu ürün kalıcı olarak silinsin mi?">
        <?= csrf_field() ?>
        <button class="btn btn-danger btn-block" type="submit">Ürünü sil</button>
      </form>
    </section>
    <?php endif; ?>
  </aside>
</div>
