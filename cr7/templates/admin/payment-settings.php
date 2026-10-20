<?php
/**
 * Shop + payment settings (/admin/odeme-ayarlari).
 *
 * @var array<string, mixed> $current effective values by input name
 * @var array<string, mixed> $old posted values after a validation error ([] otherwise)
 * @var array<string, string> $errors
 * @var array<string, string> $masked secret input name => masked stored value ('' = none)
 * @var array<string, array{label: string, available: bool, configured: bool, enabled: bool}> $gateways
 * @var bool $ordersOpen
 * @var array<string, string> $callbacks gateway => absolute callback URL
 * @var array<string, string> $companyFields
 */

$submitted = $old !== [];
$val = static function (string $name) use ($old, $current, $submitted): string {
    $value = $submitted ? ($old[$name] ?? '') : ($current[$name] ?? '');
    if (is_bool($value)) {
        return $value ? '1' : '';
    }
    if (is_float($value) || is_int($value)) {
        return fmod((float) $value, 1.0) === 0.0 ? (string) (int) $value : number_format((float) $value, 2, ',', '');
    }
    return is_scalar($value) ? (string) $value : '';
};
$isChecked = static function (string $name) use ($old, $current, $submitted): bool {
    if ($submitted) {
        return in_array($old[$name] ?? '', ['1', 'on'], true);
    }
    $value = $current[$name] ?? false;
    return !in_array($value, [false, 0, '0', 'false', '', null], true);
};
$err = static fn (string $name): string => isset($errors[$name]) ? '<p class="form-error" id="' . e($name) . '-error">' . e($errors[$name]) . '</p>' : '';
$aria = static fn (string $name): string => isset($errors[$name]) ? ' aria-invalid="true" aria-describedby="' . e($name) . '-error"' : '';
$check = static fn (string $name, string $label, string $help = '') => '<label class="checkbox"><input type="checkbox" name="' . e($name) . '" value="1"' . ($isChecked($name) ? ' checked' : '') . '><span>' . e($label)
    . ($help !== '' ? '<span class="form-help block">' . e($help) . '</span>' : '') . '</span></label>';
$secret = static function (string $name, string $label, string $placeholder) use ($masked, $err, $aria): string {
    $stored = $masked[$name] ?? '';
    return '<div class="field"><label for="' . e($name) . '">' . e($label) . '</label>'
        . '<div class="password-field"><input class="input mono" id="' . e($name) . '" name="' . e($name) . '" type="password" value="" maxlength="200" autocomplete="off" spellcheck="false" placeholder="' . e($stored !== '' ? 'Kayıtlı: ' . $stored : $placeholder) . '"' . $aria($name) . '>'
        . '<button class="btn btn-sm btn-ghost" type="button" data-toggle-password="#' . e($name) . '" aria-controls="' . e($name) . '">Göster</button></div>'
        . '<p class="form-help">' . ($stored !== '' ? 'Kayıtlı. Değiştirmek için yenisini yazın; boş bırakırsanız mevcut değer korunur.' : 'Henüz kayıtlı değil.') . '</p>'
        . ($stored !== '' ? '<label class="checkbox"><input type="checkbox" name="' . e($name) . '_clear" value="1"><span>Kayıtlı değeri sil</span></label>' : '')
        . $err($name) . '</div>';
};
$state = static function (array $g): string {
    if ($g['available']) {
        return '<span class="badge badge-success">Aktif · ödeme sayfasında görünür</span>';
    }
    if ($g['enabled'] && !$g['configured']) {
        return '<span class="badge badge-warning">Açık ama bilgiler eksik</span>';
    }
    return '<span class="badge badge-muted">Kapalı</span>';
};
$copy = static fn (string $id, string $value): string => '<div class="input-group"><input class="input mono" id="' . e($id) . '" type="text" value="' . e($value) . '" readonly><button class="btn" type="button" data-copy="#' . e($id) . '">Kopyala</button></div>';
?>
<?php if ($errors !== []): ?>
<div class="notice notice-error" role="alert">
  <strong>Ayarlar kaydedilemedi.</strong> Lütfen işaretli alanları düzeltin:
  <ul class="error-list"><?php foreach ($errors as $name => $message): ?><li><a href="#<?= e($name) ?>"><?= e($message) ?></a></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<div class="notice <?= $ordersOpen ? 'notice-success' : 'notice-info' ?>">
  <?= $ordersOpen
      ? '<strong>Siparişler açık.</strong> Ürün sayfalarında sipariş formu görünüyor.'
      : '<strong>Siparişler kapalı.</strong> Mağaza herkese açık ve arama motorlarında görünür; ürün sayfalarında “Yakında satışta” yazar, sipariş isteği 403 ile reddedilir.' ?>
</div>

<nav class="settings-nav" aria-label="Ayar bölümleri">
  <a href="#magaza">Mağaza</a>
  <a href="#satici">Satıcı bilgileri</a>
  <a href="#paytr">PayTR</a>
  <a href="#iyzico">iyzico</a>
  <a href="#shopier">Shopier</a>
  <a href="#havale">Havale / EFT</a>
</nav>

<form class="settings-form" method="post" action="<?= e(url('/admin/odeme-ayarlari')) ?>" novalidate>
  <?= csrf_field() ?>

  <section class="admin-card settings-group" id="magaza" aria-labelledby="g-shop">
    <div class="settings-head">
      <h2 id="g-shop">Mağaza</h2>
      <p class="muted text-sm">Mağazanın görünürlüğü, sipariş alma ve teslimat ayarları.</p>
    </div>
    <?= $check('shop_enabled', 'Forma Mağazası sitede görünsün', 'Kapatılırsa mağaza sayfaları 404 döner ve menüden kalkar.') ?>
    <?= $check('shop_orders_enabled', 'Sipariş alınsın', 'Açık olsa bile en az bir ödeme yöntemi aktif değilse ürün sayfalarında “Yakında satışta” görünür.') ?>
    <div class="form-row">
      <div class="field">
        <label for="shop_shipping_fee">Kargo ücreti (TL)</label>
        <input class="input" id="shop_shipping_fee" name="shop_shipping_fee" type="text" inputmode="decimal" value="<?= e($val('shop_shipping_fee')) ?>"<?= $aria('shop_shipping_fee') ?>>
        <p class="form-help">Sipariş başına sabit ücret; 0 = ücretsiz kargo.</p>
        <?= $err('shop_shipping_fee') ?>
      </div>
      <div class="field">
        <label for="shop_whatsapp">WhatsApp numarası</label>
        <input class="input" id="shop_whatsapp" name="shop_whatsapp" type="text" inputmode="tel" value="<?= e($val('shop_whatsapp')) ?>" placeholder="905321234567"<?= $aria('shop_whatsapp') ?>>
        <p class="form-help">“Haber ver” ve “Soru sor” bağlantıları için (ülke koduyla).</p>
        <?= $err('shop_whatsapp') ?>
      </div>
    </div>
    <div class="field">
      <label for="shop_notify_email">Sipariş bildirim e-postası</label>
      <input class="input" id="shop_notify_email" name="shop_notify_email" type="email" value="<?= e($val('shop_notify_email')) ?>" placeholder="siparis@cr7.com.tr"<?= $aria('shop_notify_email') ?>>
      <p class="form-help">Yeni sipariş ve ödeme onayında PHP mail() ile bildirim gönderilir (boş = gönderilmez).</p>
      <?= $err('shop_notify_email') ?>
    </div>
  </section>

  <section class="admin-card settings-group" id="satici" aria-labelledby="g-company">
    <div class="settings-head">
      <h2 id="g-company">Satıcı bilgileri (yasal sayfalar)</h2>
      <p class="muted text-sm"><a href="<?= e(url('/mesafeli-satis-sozlesmesi')) ?>" target="_blank" rel="noopener">Mesafeli Satış Sözleşmesi</a>, <a href="<?= e(url('/on-bilgilendirme-formu')) ?>" target="_blank" rel="noopener">Ön Bilgilendirme Formu</a> ve <a href="<?= e(url('/iptal-ve-iade')) ?>" target="_blank" rel="noopener">İptal ve İade</a> sayfalarında gösterilir. Boş alanlar sayfalarda [köşeli parantez] ile işaretlenir. Satışa başlamadan önce metinleri bir hukuk danışmanına kontrol ettirin.</p>
    </div>
    <div class="form-row">
      <?php foreach ($companyFields as $key => $label): $name = 'shop_' . $key; ?>
      <div class="field">
        <label for="<?= e($name) ?>"><?= e($label) ?></label>
        <?php if ($key === 'company_address'): ?>
        <textarea class="textarea textarea-sm" id="<?= e($name) ?>" name="<?= e($name) ?>" rows="2" maxlength="400"><?= e($val($name)) ?></textarea>
        <?php else: ?>
        <input class="input" id="<?= e($name) ?>" name="<?= e($name) ?>" type="<?= $key === 'company_email' ? 'email' : 'text' ?>" maxlength="190" value="<?= e($val($name)) ?>"<?= $aria($name) ?>>
        <?php endif; ?>
        <?= $err($name) ?>
      </div>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="admin-card settings-group" id="paytr" aria-labelledby="g-paytr">
    <div class="settings-head">
      <h2 id="g-paytr">PayTR (iFrame API) <?= $state($gateways['paytr']) ?></h2>
      <p class="muted text-sm">Bilgiler PayTR Mağaza Paneli › Bilgi sayfasındadır. Kart formu sitenizde iframe içinde açılır.</p>
    </div>
    <?= $check('paytr_enabled', 'PayTR ile ödeme alınsın') ?>
    <?= $check('paytr_test_mode', 'Test modu', 'Mağazanız canlıdayken test işlemleri yapar (test_mode=1). Gerçek satışa geçerken kapatın.') ?>
    <div class="form-row">
      <div class="field">
        <label for="paytr_merchant_id">Mağaza no (merchant_id)</label>
        <input class="input mono" id="paytr_merchant_id" name="paytr_merchant_id" type="text" inputmode="numeric" value="<?= e($val('paytr_merchant_id')) ?>"<?= $aria('paytr_merchant_id') ?>>
        <?= $err('paytr_merchant_id') ?>
      </div>
      <?= $secret('paytr_merchant_key', 'Mağaza parolası (merchant_key)', 'merchant_key') ?>
    </div>
    <div class="form-row">
      <?= $secret('paytr_merchant_salt', 'Mağaza gizli anahtarı (merchant_salt)', 'merchant_salt') ?>
      <div class="field">
        <label for="cb-paytr" class="label">Bildirim URL (PayTR paneline girin)</label>
        <?= $copy('cb-paytr', $callbacks['paytr']) ?>
        <p class="form-help">PayTR Mağaza Paneli › Destek &amp; Kurulum › Ayarlar › Bildirim URL. Sunucu bu adrese “OK” yanıtı verir.</p>
      </div>
    </div>
  </section>

  <section class="admin-card settings-group" id="iyzico" aria-labelledby="g-iyzico">
    <div class="settings-head">
      <h2 id="g-iyzico">iyzico (Ödeme Formu) <?= $state($gateways['iyzico']) ?></h2>
      <p class="muted text-sm">API anahtarları iyzico Üye İşyeri Paneli › Ayarlar › Firma Ayarları’ndadır. Sandbox anahtarları sandbox-merchant.iyzipay.com’dan alınır.</p>
    </div>
    <?= $check('iyzico_enabled', 'iyzico ile ödeme alınsın') ?>
    <?= $check('iyzico_sandbox', 'Sandbox (test ortamı)', 'Açıkken istekler sandbox-api.iyzipay.com’a gider; canlı anahtarlarla kapatın.') ?>
    <div class="form-row">
      <?= $secret('iyzico_api_key', 'API anahtarı', 'sandbox-… / API key') ?>
      <?= $secret('iyzico_secret_key', 'Güvenlik anahtarı (secret key)', 'secret key') ?>
    </div>
    <div class="field">
      <label for="cb-iyzico" class="label">Geri dönüş adresi (otomatik gönderilir)</label>
      <?= $copy('cb-iyzico', $callbacks['iyzico']) ?>
      <p class="form-help">Her ödemede callbackUrl olarak iyzico’ya iletilir; panelde ayrıca girmeniz gerekmez. Geçerli bir SSL sertifikası zorunludur.</p>
    </div>
  </section>

  <section class="admin-card settings-group" id="shopier" aria-labelledby="g-shopier">
    <div class="settings-head">
      <h2 id="g-shopier">Shopier <?= $state($gateways['shopier']) ?></h2>
      <p class="muted text-sm">Shopier Paneli › Entegrasyonlar › Modül Yönetimi’nden “API Kullanıcı” ve “API Şifre” bilgilerini alın (Kişisel Erişim Anahtarı değil).</p>
    </div>
    <?= $check('shopier_enabled', 'Shopier ile ödeme alınsın') ?>
    <div class="form-row">
      <?= $secret('shopier_api_key', 'API kullanıcı (API key)', 'API key') ?>
      <?= $secret('shopier_api_secret', 'API şifre (API secret)', 'API secret') ?>
    </div>
    <div class="form-row">
      <div class="field">
        <label for="shopier_website_index">Web sitesi indeksi</label>
        <select class="select" id="shopier_website_index" name="shopier_website_index"<?= $aria('shopier_website_index') ?>>
          <?php foreach (['1', '2', '3', '4', '5'] as $i): ?>
          <option value="<?= $i ?>"<?= $val('shopier_website_index') === $i ? ' selected' : '' ?>><?= $i ?></option>
          <?php endforeach; ?>
        </select>
        <p class="form-help">Shopier modül ayarlarında bu siteye verdiğiniz sıra (genelde 1).</p>
        <?= $err('shopier_website_index') ?>
      </div>
      <div class="field">
        <label for="cb-shopier" class="label">Geri dönüş URL’si (Shopier paneline girin)</label>
        <?= $copy('cb-shopier', $callbacks['shopier']) ?>
        <p class="form-help">Modül Yönetimi’nde seçtiğiniz web sitesi indeksinin “Geri Dönüş URL” alanına yapıştırın.</p>
      </div>
    </div>
  </section>

  <section class="admin-card settings-group" id="havale" aria-labelledby="g-havale">
    <div class="settings-head">
      <h2 id="g-havale">Havale / EFT <?= $state($gateways['havale']) ?></h2>
      <p class="muted text-sm">Müşteri, sipariş numarasını açıklamaya yazarak ödeme yapar; ödemeyi sipariş detayından “Havale ödemesi alındı” ile onaylarsınız.</p>
    </div>
    <?= $check('havale_enabled', 'Havale / EFT ile ödeme alınsın') ?>
    <div class="form-row">
      <div class="field">
        <label for="havale_bank_name">Banka</label>
        <input class="input" id="havale_bank_name" name="havale_bank_name" type="text" maxlength="100" value="<?= e($val('havale_bank_name')) ?>">
      </div>
      <div class="field">
        <label for="havale_account_holder">Alıcı adı / unvanı</label>
        <input class="input" id="havale_account_holder" name="havale_account_holder" type="text" maxlength="150" value="<?= e($val('havale_account_holder')) ?>">
      </div>
    </div>
    <div class="field">
      <label for="havale_iban">IBAN</label>
      <input class="input mono" id="havale_iban" name="havale_iban" type="text" maxlength="40" value="<?= e($val('havale_iban')) ?>" placeholder="TR00 0000 0000 0000 0000 0000 00" spellcheck="false"<?= $aria('havale_iban') ?>>
      <?= $err('havale_iban') ?>
    </div>
  </section>

  <div class="save-bar">
    <span class="muted text-sm">Değişiklikler kaydedildiğinde hemen uygulanır.</span>
    <button class="btn btn-gold" type="submit">Ayarları kaydet</button>
  </div>
</form>
