<?php
/**
 * /odeme/{key} — payment step for an order: PayTR iframe, iyzico checkout form, Shopier auto-submit form,
 * Havale/EFT instructions or an error notice. noindex; CSP extended per gateway by the controller.
 *
 * @var array<string, mixed> $order decorated order row
 * @var array<string, mixed> $payment PaymentGateway::start() result
 * @var string $methodLabel
 * @var string $statusUrl
 * @var string $cspNonce
 */

use App\Services\Shop\Shop;

$type = (string) ($payment['type'] ?? 'error');
$nonce = e($cspNonce);
?>
<header class="page-header">
  <div class="container">
    <span class="eyebrow">Sipariş <?= e($order['order_no']) ?></span>
    <h1><?= $type === 'bank' ? 'Havale / EFT ile ödeme' : 'Güvenli ödeme' ?></h1>
    <p class="lead">Siparişiniz oluşturuldu. <?= $type === 'bank' ? 'Aşağıdaki hesaba ödemeyi yaptıktan sonra siparişiniz hazırlanmaya başlar.' : 'Ödemeyi tamamladığınızda siparişiniz onaylanır.' ?></p>
  </div>
</header>

<div class="container section-sm">
  <div class="pay-layout">
    <section aria-labelledby="pay-area-title">
      <h2 id="pay-area-title" class="visually-hidden">Ödeme</h2>
      <?php if ($type === 'iframe'): ?>
      <iframe class="pay-frame" src="<?= e($payment['url']) ?>" title="<?= e($payment['title'] ?? 'Ödeme formu') ?>" height="<?= (int) ($payment['height'] ?? 760) ?>" allow="payment" referrerpolicy="origin"></iframe>
      <p class="form-help">Ödeme formu PayTR tarafından sağlanır; kart bilgileriniz CR7.com.tr sunucularına iletilmez.</p>

      <?php elseif ($type === 'checkout'): ?>
      <div class="order-box">
        <div id="iyzipay-checkout-form" class="responsive"></div>
        <?= preg_replace('/<script\b(?![^>]*\bnonce=)/i', '<script nonce="' . $nonce . '"', (string) $payment['html']) ?>
        <noscript><p class="notice notice-warning">iyzico ödeme formu için JavaScript gereklidir.</p></noscript>
        <?php if (!empty($payment['page_url'])): ?>
        <p class="form-help">Form görünmüyor mu? <a href="<?= e($payment['page_url']) ?>" rel="noopener">iyzico ödeme sayfasında açın</a>.</p>
        <?php endif; ?>
      </div>

      <?php elseif ($type === 'form'): ?>
      <div class="order-box">
        <form id="gateway-form" method="post" action="<?= e($payment['action']) ?>">
          <?php foreach ((array) $payment['fields'] as $name => $value): ?>
          <input type="hidden" name="<?= e((string) $name) ?>" value="<?= e((string) $value) ?>">
          <?php endforeach; ?>
          <p>Shopier güvenli ödeme sayfasına yönlendiriliyorsunuz…</p>
          <button class="btn btn-gold btn-lg" type="submit"><?= e($payment['button'] ?? 'Ödemeye geç') ?></button>
        </form>
        <script nonce="<?= $nonce ?>">setTimeout(function () { var f = document.getElementById('gateway-form'); if (f) f.submit(); }, 600);</script>
      </div>

      <?php elseif ($type === 'bank'): ?>
      <div class="order-box">
        <dl class="bank-details">
          <?php if ($payment['bank_name'] !== ''): ?><div><dt>Banka</dt><dd><?= e($payment['bank_name']) ?></dd></div><?php endif; ?>
          <div><dt>Alıcı</dt><dd><?= e($payment['account_holder']) ?></dd></div>
          <div><dt>IBAN</dt><dd class="copy-row"><span id="iban"><?= e($payment['iban']) ?></span><button class="btn btn-sm btn-outline" type="button" data-copy="<?= e($payment['iban_raw']) ?>">Kopyala</button></dd></div>
          <div><dt>Tutar</dt><dd><?= e(fmt_price($payment['amount'])) ?></dd></div>
          <div><dt>Açıklama (zorunlu)</dt><dd class="copy-row"><span><?= e($payment['reference']) ?></span><button class="btn btn-sm btn-outline" type="button" data-copy="<?= e($payment['reference']) ?>">Kopyala</button></dd></div>
        </dl>
        <div class="notice notice-info">
          Havale/EFT açıklamasına mutlaka <strong><?= e($payment['reference']) ?></strong> sipariş numaranızı yazın. Ödemeniz hesabımıza ulaştığında siparişiniz onaylanır ve durum sayfanızda görünür. 3 iş günü içinde ödemesi yapılmayan siparişler iptal edilebilir.
        </div>
        <a class="btn btn-gold" href="<?= e($statusUrl) ?>">Sipariş durumunu görüntüle</a>
      </div>
      <script nonce="<?= $nonce ?>">
      Array.prototype.forEach.call(document.querySelectorAll('[data-copy]'), function (b) {
        b.addEventListener('click', function () {
          var t = b.getAttribute('data-copy'), done = function () { var o = b.textContent; b.textContent = 'Kopyalandı'; setTimeout(function () { b.textContent = o; }, 1500); };
          if (navigator.clipboard) { navigator.clipboard.writeText(t).then(done, function () {}); }
        });
      });
      </script>

      <?php else: ?>
      <div class="notice notice-error" role="alert">
        <strong class="notice-title">Ödeme sayfası açılamadı</strong>
        <?= e($payment['message'] ?? 'Lütfen daha sonra tekrar deneyin.') ?>
      </div>
      <div class="cluster">
        <a class="btn btn-gold" href="<?= e(url('/odeme/' . $order['access_key'])) ?>">Tekrar dene</a>
        <a class="btn btn-outline" href="<?= e($statusUrl) ?>">Sipariş durumu</a>
      </div>
      <?php endif; ?>
    </section>

    <aside class="summary-box" aria-labelledby="sum-title">
      <h2 id="sum-title" class="section-title">Sipariş özeti</h2>
      <dl class="summary-list">
        <div><dt>Sipariş no</dt><dd><?= e($order['order_no']) ?></dd></div>
        <div><dt>Ürün</dt><dd><?= e($order['product_title']) ?></dd></div>
        <div><dt>Beden / Adet</dt><dd><?= e($order['size']) ?> · <?= (int) $order['quantity'] ?> adet</dd></div>
        <?php if (!empty($order['print_name']) || !empty($order['print_number'])): ?>
        <div><dt>Baskı</dt><dd><?= e(trim($order['print_name'] . ' ' . $order['print_number'])) ?></dd></div>
        <?php endif; ?>
        <div><dt>Birim fiyat</dt><dd><?= e(fmt_price($order['unit_price'])) ?></dd></div>
        <div><dt>Kargo</dt><dd><?= $order['shipping_fee'] > 0 ? e(fmt_price($order['shipping_fee'])) : 'Ücretsiz' ?></dd></div>
        <div class="summary-total"><dt>Toplam</dt><dd><?= e(fmt_price($order['total'])) ?></dd></div>
        <div><dt>Ödeme yöntemi</dt><dd><?= e($methodLabel) ?></dd></div>
        <div><dt>Durum</dt><dd><span class="badge <?= e(Shop::paymentBadge((string) $order['payment_status'])) ?>"><?= e(Shop::paymentStatusLabel((string) $order['payment_status'])) ?></span></dd></div>
      </dl>
      <p class="form-help">Bu sayfanın adresi size özeldir; siparişinizi daha sonra <a href="<?= e($statusUrl) ?>">sipariş durumu</a> sayfasından takip edebilirsiniz.</p>
    </aside>
  </div>
</div>
