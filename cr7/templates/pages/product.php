<?php
/**
 * /forma/{slug} — product page. The order form is rendered ONLY while ordering is open; otherwise a
 * "Yakında satışta" box (+ optional WhatsApp "Haber ver" link) is shown and no form exists in the HTML.
 *
 * @var array<string, mixed> $product decorated ProductRepository row
 * @var bool $ordersOpen
 * @var bool $ordersSwitchedOn admin switch (true but no payment method => still closed)
 * @var array<string, \App\Services\Payment\PaymentGateway> $gateways available payment methods
 * @var array<string, mixed> $quote OrderService::quote() for 1 piece with default printing
 * @var int $basePriceKurus
 * @var int $shippingKurus
 * @var array<string, string> $errors
 * @var array<string, string> $old
 * @var list<array<string, mixed>> $related
 * @var list<string> $cities
 * @var string|null $whatsappLink
 * @var string|null $askLink
 * @var string $defaultNumber default back number for the print option
 * @var string $cspNonce
 */

use App\Services\Shop\OrderService;
use App\Services\Shop\Shop;

$p = $product;
$submitted = $old !== [];
$val = static fn (string $key, string $default = ''): string => $submitted ? (string) ($old[$key] ?? '') : $default;
$err = static fn (string $key): string => isset($errors[$key]) ? '<p class="form-error" id="err-' . e($key) . '">' . e($errors[$key]) . '</p>' : '';
$aria = static fn (string $key): string => isset($errors[$key]) ? ' aria-invalid="true" aria-describedby="err-' . e($key) . '"' : '';
$defaultNumber = $defaultNumber ?? '7';
$printChecked = $submitted ? ($old['print'] ?? '') === '1' : $p['allow_print'];
$selectedSize = $val('size');
$selectedMethod = $val('payment_method', (string) (array_key_first($gateways) ?? ''));
$hasSale = $p['old_price'] !== null && $p['old_price'] > $p['price'];
$paragraphs = array_values(array_filter(array_map('trim', preg_split("/\n\s*\n/", (string) $p['description']) ?: [])));
$labels = Shop::KIT_TYPES;
?>
<div class="container section-sm">
  <div class="product-layout">
    <div>
      <div class="product-gallery">
        <?php if ($p['gallery'] !== []): ?>
        <img id="product-main" src="<?= e($p['gallery'][0]['url']) ?>" alt="<?= e($p['gallery'][0]['alt']) ?>" width="600" height="620" fetchpriority="high" decoding="async">
        <?php endif; ?>
      </div>
      <?php if (count($p['gallery']) > 1): ?>
      <div class="gallery-thumbs" role="group" aria-label="Ürün görselleri">
        <?php foreach ($p['gallery'] as $i => $image): ?>
        <a href="<?= e($image['url']) ?>" data-gallery="<?= e($image['url']) ?>" data-alt="<?= e($image['alt']) ?>" aria-current="<?= $i === 0 ? 'true' : 'false' ?>">
          <img src="<?= e($image['url']) ?>" alt="<?= e($i === 0 ? 'Arkadan görünüm' : 'Önden görünüm') ?>" width="72" height="74" loading="lazy">
        </a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <p class="form-help gallery-note">Görseller temsilidir; kulüp arması ve sponsor logoları gösterilmez.</p>
    </div>

    <div class="product-info">
      <div class="product-meta">
        <?php if ($p['club_id'] !== null): ?><a class="tag" href="<?= e(url('/forma-magazasi?takim=' . rawurlencode((string) $p['club_id']))) ?>"><?= e($p['club_name']) ?></a><?php else: ?><span class="tag"><?= e($p['club_name']) ?></span><?php endif; ?>
        <?php if (!empty($p['season'])): ?><span class="tag"><?= e($p['season']) ?></span><?php endif; ?>
        <span class="badge"><?= e($labels[$p['kit_type']] ?? $p['kit_type']) ?></span>
      </div>
      <h1><?= e(Shop::nobr($p['title'])) ?></h1>
      <div class="price-row">
        <span class="price"><?= e(fmt_price($p['price'])) ?></span>
        <?php if ($hasSale): ?>
        <del class="price-old"><span class="visually-hidden">Eski fiyat: </span><?= e(fmt_price($p['old_price'])) ?></del>
        <span class="badge badge-red">%<?= (int) round((1 - $p['price'] / $p['old_price']) * 100) ?> indirim</span>
        <?php endif; ?>
      </div>
      <p class="text-muted">
        <?= $p['allow_print'] ? 'İsim-numara baskısı <span class="nowrap">+' . e(fmt_price($p['print_price'])) . '</span> · ' : '' ?><?= $shippingKurus > 0 ? 'Kargo <span class="nowrap">' . e(fmt_price($shippingKurus / 100)) . '</span>' : 'Ücretsiz kargo' ?>
        <?= $p['stock'] !== null && $p['stock'] > 0 && $p['stock'] <= 5 ? ' · Son ' . (int) $p['stock'] . ' ürün' : '' ?>
      </p>

      <?php if ($ordersOpen): ?>
      <form class="order-box form" id="order-form" method="post" action="<?= e(url($p['url'] . '/siparis')) ?>" novalidate
            data-base="<?= (int) $basePriceKurus ?>" data-print="<?= (int) Shop::kurus($p['print_price']) ?>" data-shipping="<?= (int) $shippingKurus ?>">
        <?= csrf_field() ?>
        <div class="hp" aria-hidden="true">
          <label for="hp-website">Web siteniz (boş bırakın)</label>
          <input type="text" id="hp-website" name="<?= e(OrderService::HONEYPOT) ?>" value="" tabindex="-1" autocomplete="off">
        </div>
        <?php if ($errors !== []): ?>
        <div class="notice notice-error" role="alert">
          <strong class="notice-title">Sipariş oluşturulamadı</strong>
          <?= isset($errors['form']) ? e($errors['form']) : 'Lütfen işaretli alanları kontrol edin.' ?>
        </div>
        <?php endif; ?>

        <fieldset class="size-group">
          <legend>Beden <span class="text-gold" aria-hidden="true">*</span></legend>
          <div class="size-options">
            <?php foreach ($p['sizes'] as $size): ?>
            <label class="size-option"><input type="radio" name="size" value="<?= e($size) ?>"<?= $selectedSize === $size ? ' checked' : '' ?> required><span><?= e($size) ?></span></label>
            <?php endforeach; ?>
          </div>
          <?php if ($p['kids_sizes'] !== []): ?>
          <span class="size-sub">Çocuk bedenleri</span>
          <div class="size-options">
            <?php foreach ($p['kids_sizes'] as $size): ?>
            <label class="size-option"><input type="radio" name="size" value="<?= e($size) ?>"<?= $selectedSize === $size ? ' checked' : '' ?>><span><?= e($size) ?></span></label>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
          <?= $err('size') ?>
        </fieldset>

        <?php if ($p['allow_print']): ?>
        <div class="field">
          <label class="checkbox"><input type="checkbox" name="print" value="1" id="print-toggle"<?= $printChecked ? ' checked' : '' ?>><span>Sırtına isim ve numara bastır (+<?= e(fmt_price($p['print_price'])) ?>)</span></label>
          <div class="print-fields" id="print-fields">
            <div class="field">
              <label for="print_name">İsim</label>
              <input class="input" id="print_name" name="print_name" type="text" maxlength="<?= Shop::PRINT_NAME_MAX ?>" value="<?= e($val('print_name', 'RONALDO')) ?>" autocapitalize="characters" spellcheck="false"<?= $aria('print_name') ?>>
            </div>
            <div class="field">
              <label for="print_number">Numara</label>
              <input class="input" id="print_number" name="print_number" type="text" inputmode="numeric" maxlength="2" pattern="\d{1,2}" value="<?= e($val('print_number', $defaultNumber)) ?>"<?= $aria('print_number') ?>>
            </div>
          </div>
          <?= $err('print_name') ?><?= $err('print_number') ?>
        </div>
        <?php endif; ?>

        <div class="field">
          <label for="quantity">Adet</label>
          <input class="input qty-input" id="quantity" name="quantity" type="number" min="1" max="<?= Shop::MAX_QUANTITY ?>" step="1" inputmode="numeric" value="<?= e($val('quantity', '1')) ?>"<?= $aria('quantity') ?>>
          <?= $err('quantity') ?>
        </div>

        <div class="order-section">
          <h2>Teslimat bilgileri</h2>
          <div class="field required">
            <label for="customer_name">Ad Soyad</label>
            <input class="input" id="customer_name" name="customer_name" type="text" maxlength="150" autocomplete="name" value="<?= e($val('customer_name')) ?>" required<?= $aria('customer_name') ?>>
            <?= $err('customer_name') ?>
          </div>
          <div class="form-row">
            <div class="field required">
              <label for="phone">Cep telefonu</label>
              <input class="input" id="phone" name="phone" type="tel" maxlength="20" autocomplete="tel" inputmode="tel" placeholder="05XX XXX XX XX" value="<?= e($val('phone')) ?>" required<?= $aria('phone') ?>>
              <?= $err('phone') ?>
            </div>
            <div class="field required">
              <label for="email">E-posta</label>
              <input class="input" id="email" name="email" type="email" maxlength="190" autocomplete="email" value="<?= e($val('email')) ?>" required<?= $aria('email') ?>>
              <?= $err('email') ?>
            </div>
          </div>
          <div class="form-row">
            <div class="field required">
              <label for="city">İl</label>
              <select class="select" id="city" name="city" autocomplete="address-level1" required<?= $aria('city') ?>>
                <option value="">İl seçin</option>
                <?php foreach ($cities as $city): ?>
                <option value="<?= e($city) ?>"<?= $val('city') === $city ? ' selected' : '' ?>><?= e($city) ?></option>
                <?php endforeach; ?>
              </select>
              <?= $err('city') ?>
            </div>
            <div class="field required">
              <label for="district">İlçe</label>
              <input class="input" id="district" name="district" type="text" maxlength="80" autocomplete="address-level2" value="<?= e($val('district')) ?>" required<?= $aria('district') ?>>
              <?= $err('district') ?>
            </div>
          </div>
          <div class="field required">
            <label for="address">Açık adres</label>
            <textarea class="textarea" id="address" name="address" rows="3" maxlength="500" autocomplete="street-address" placeholder="Mahalle, cadde/sokak, bina ve daire no" required<?= $aria('address') ?>><?= e($val('address')) ?></textarea>
            <?= $err('address') ?>
          </div>
          <div class="field">
            <label for="note">Sipariş notu (isteğe bağlı)</label>
            <textarea class="textarea" id="note" name="note" rows="2" maxlength="500"<?= $aria('note') ?>><?= e($val('note')) ?></textarea>
            <?= $err('note') ?>
          </div>
        </div>

        <div class="order-section">
          <h2 id="pay-title">Ödeme yöntemi</h2>
          <div class="pay-options" role="radiogroup" aria-labelledby="pay-title">
            <?php foreach ($gateways as $key => $gateway): ?>
            <label class="pay-option">
              <input type="radio" name="payment_method" value="<?= e($key) ?>"<?= $selectedMethod === $key ? ' checked' : '' ?> required>
              <span><strong><?= e($gateway->label()) ?></strong><small><?= e($gateway->description()) ?></small></span>
            </label>
            <?php endforeach; ?>
          </div>
          <?= $err('payment_method') ?>
        </div>

        <div class="order-section">
          <dl class="summary-list" aria-live="polite">
            <div><dt>Forma</dt><dd><?= e(fmt_price($basePriceKurus / 100)) ?></dd></div>
            <?php if ($p['allow_print']): ?><div id="sum-print-row"><dt>Baskı</dt><dd id="sum-print"><?= e(fmt_price($quote['print_kurus'] / 100)) ?></dd></div><?php endif; ?>
            <div><dt>Adet</dt><dd id="sum-qty">1</dd></div>
            <div><dt>Kargo</dt><dd><?= $shippingKurus > 0 ? e(fmt_price($shippingKurus / 100)) : 'Ücretsiz' ?></dd></div>
            <div class="summary-total"><dt>Toplam</dt><dd id="sum-total"><?= e(fmt_price($quote['total_kurus'] / 100)) ?></dd></div>
          </dl>
          <p class="form-help">Tutar sunucuda yeniden hesaplanır; ödeme sayfasında kesin toplamı görürsünüz.</p>
        </div>

        <div class="order-section">
          <label class="checkbox"><input type="checkbox" name="kvkk" value="1"<?= $val('kvkk') === '1' ? ' checked' : '' ?> required<?= $aria('kvkk') ?>>
            <span>Kişisel verilerimin siparişin işlenmesi ve teslimatı amacıyla işlenmesine ilişkin <a href="<?= e(url('/gizlilik-politikasi')) ?>" target="_blank" rel="noopener">KVKK Aydınlatma Metni</a>’ni okudum.</span></label>
          <?= $err('kvkk') ?>
          <label class="checkbox"><input type="checkbox" name="contract" value="1"<?= $val('contract') === '1' ? ' checked' : '' ?> required<?= $aria('contract') ?>>
            <span><a href="<?= e(url('/on-bilgilendirme-formu')) ?>" target="_blank" rel="noopener">Ön Bilgilendirme Formu</a>’nu ve <a href="<?= e(url('/mesafeli-satis-sozlesmesi')) ?>" target="_blank" rel="noopener">Mesafeli Satış Sözleşmesi</a>’ni okudum, kabul ediyorum. Kişiye özel baskılı ürünlerde cayma hakkının bulunmadığını biliyorum.</span></label>
          <?= $err('contract') ?>
          <button class="btn btn-gold btn-lg btn-block" type="submit">Siparişi tamamla ve ödemeye geç</button>
        </div>
      </form>
      <?php else: ?>
      <div class="soon-box" id="yakinda">
        <span class="badge">Yakında satışta</span>
        <h2>Bu forma çok yakında satışta</h2>
        <p><?= $ordersSwitchedOn ? 'Ödeme altyapımız hazırlanıyor; sipariş formu kısa süre içinde açılacak.' : 'Online sipariş henüz açılmadı. Fiyat ve beden bilgileri bilgilendirme amaçlıdır; satış başladığında bu sayfadan sipariş verebileceksiniz.' ?></p>
        <?php if ($whatsappLink !== null): ?>
        <div><a class="btn btn-gold" href="<?= e($whatsappLink) ?>" target="_blank" rel="nofollow noopener">WhatsApp ile haber ver</a></div>
        <?php endif; ?>
        <dl class="summary-list">
          <div><dt>Bedenler</dt><dd><?= implode(', ', array_map(static fn (string $s): string => '<span class="nowrap">' . e($s) . '</span>', $p['sizes'])) ?></dd></div>
          <?php if ($p['kids_sizes'] !== []): ?><div><dt>Çocuk</dt><dd><?= implode(', ', array_map(static fn (string $s): string => '<span class="nowrap">' . e($s) . '</span>', $p['kids_sizes'])) ?></dd></div><?php endif; ?>
          <?php if ($p['allow_print']): ?><div><dt>Baskı</dt><dd>RONALDO <?= e($defaultNumber) ?> (+<?= e(fmt_price($p['print_price'])) ?>)</dd></div><?php endif; ?>
        </dl>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<section class="section-sm section-alt" aria-labelledby="desc-title">
  <div class="container layout-sidebar">
    <div class="product-desc">
      <h2 id="desc-title" class="section-title">Ürün açıklaması</h2>
      <?php foreach ($paragraphs as $paragraph): ?>
      <p><?= nl2br(e($paragraph)) ?></p>
      <?php endforeach; ?>
      <p>Ronaldo’nun bu dönemdeki performansını <a href="<?= e(url('/istatistikler')) ?>">istatistikler</a> ve <a href="<?= e(url('/kariyer')) ?>">kariyer</a> sayfalarında inceleyebilirsiniz.</p>
    </div>
    <aside aria-label="Satış bilgileri">
      <ul class="info-list">
        <li><strong>Bedenler:</strong> <?= e(implode(', ', array_merge($p['sizes'], $p['kids_sizes']))) ?></li>
        <?php if ($p['allow_print']): ?><li><strong>Baskı:</strong> İsim (en fazla <?= Shop::PRINT_NAME_MAX ?> harf) ve 0–99 arası numara, +<?= e(fmt_price($p['print_price'])) ?></li><?php endif; ?>
        <li><strong>Kargo:</strong> <?= $shippingKurus > 0 ? 'Sabit ' . e(fmt_price($shippingKurus / 100)) : 'Ücretsiz' ?>, ödeme onayından sonra hazırlanır.</li>
        <li><strong>İade:</strong> Baskısız ürünlerde 14 gün cayma hakkı. <a href="<?= e(url('/iptal-ve-iade')) ?>">İptal ve iade koşulları</a></li>
        <?php if ($askLink !== null): ?><li><strong>Soru:</strong> <a href="<?= e($askLink) ?>" target="_blank" rel="nofollow noopener">WhatsApp’tan sorun</a></li><?php endif; ?>
      </ul>
    </aside>
  </div>
</section>

<?php if ($related !== []): ?>
<section class="section-sm" aria-labelledby="related-title">
  <div class="container">
    <div class="section-header">
      <h2 id="related-title" class="section-title">Diğer Ronaldo formaları</h2>
      <a class="link-more" href="<?= e(url('/forma-magazasi')) ?>">Tüm formalar</a>
    </div>
    <div class="product-grid">
      <?php foreach ($related as $item): ?>
      <article class="card product-card">
        <a class="card-media" href="<?= e(url($item['url'])) ?>" tabindex="-1" aria-hidden="true">
          <?php if ($item['image_url'] !== ''): ?><img src="<?= e($item['image_url']) ?>" alt="" width="600" height="620" loading="lazy" decoding="async"><?php endif; ?>
        </a>
        <span class="badge"><?= e($labels[$item['kit_type']] ?? $item['kit_type']) ?></span>
        <div class="card-body">
          <p class="card-kicker"><?= e($item['club_name'] . (!empty($item['season']) ? ' · ' . $item['season'] : '')) ?></p>
          <h3 class="card-title"><a href="<?= e(url($item['url'])) ?>"><?= e(Shop::nobr($item['title'])) ?></a></h3>
          <div class="price-row"><span class="price"><?= e(fmt_price($item['price'])) ?></span></div>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<script nonce="<?= e($cspNonce) ?>">
(function () {
  var main = document.getElementById('product-main');
  Array.prototype.forEach.call(document.querySelectorAll('[data-gallery]'), function (link, i, all) {
    link.addEventListener('click', function (ev) {
      if (!main) return;
      ev.preventDefault();
      main.src = link.getAttribute('data-gallery');
      main.alt = link.getAttribute('data-alt');
      Array.prototype.forEach.call(all, function (l) { l.setAttribute('aria-current', l === link ? 'true' : 'false'); });
    });
  });
  var form = document.getElementById('order-form');
  if (!form) return;
  var base = +form.getAttribute('data-base'), print = +form.getAttribute('data-print'), ship = +form.getAttribute('data-shipping');
  var toggle = document.getElementById('print-toggle'), fields = document.getElementById('print-fields'), qty = document.getElementById('quantity');
  function money(k) { return (k / 100).toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' TL'; }
  function update() {
    var withPrint = toggle ? toggle.checked : false;
    var q = Math.max(1, Math.min(<?= Shop::MAX_QUANTITY ?>, parseInt(qty.value, 10) || 1));
    if (fields) fields.hidden = !withPrint;
    var sp = document.getElementById('sum-print');
    if (sp) sp.textContent = withPrint ? money(print) : '—';
    document.getElementById('sum-qty').textContent = q;
    document.getElementById('sum-total').textContent = money((base + (withPrint ? print : 0)) * q + ship);
  }
  if (toggle) toggle.addEventListener('change', update);
  qty.addEventListener('input', update);
  var name = document.getElementById('print_name');
  if (name) name.addEventListener('input', function () { var p = name.selectionStart; name.value = name.value.toLocaleUpperCase('tr-TR'); try { name.setSelectionRange(p, p); } catch (e) {} });
  update();
})();
</script>
