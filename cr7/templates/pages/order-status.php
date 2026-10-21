<?php
/**
 * /siparis/{key} — order + payment status for the customer (noindex, private URL).
 *
 * @var array<string, mixed> $order
 * @var array<string, mixed>|null $product
 * @var string $methodLabel
 * @var bool $canPay
 * @var string|null $whatsappLink
 */

use App\Services\Shop\OrderService;
use App\Services\Shop\Shop;

$flow = ['yeni', 'onaylandi', 'hazirlaniyor', 'kargoda', 'tamamlandi'];
$cancelled = $order['status'] === 'iptal';
$position = array_search($order['status'], $flow, true);
$position = $position === false ? -1 : $position;
?>
<header class="page-header">
  <div class="container">
    <span class="eyebrow">Sipariş durumu</span>
    <h1>Sipariş <?= e($order['order_no']) ?></h1>
    <p class="lead"><?= e(fmt_date((string) $order['created_at'], 'j F Y H:i')) ?> tarihinde oluşturuldu.</p>
  </div>
</header>

<div class="container section-sm">
  <div class="pay-layout">
    <div class="stack">
      <section class="order-box" aria-labelledby="st-title">
        <h2 id="st-title" class="section-title">Durum</h2>
        <p>
          <span class="badge <?= e(Shop::paymentBadge((string) $order['payment_status'])) ?>"><?= e(Shop::paymentStatusLabel((string) $order['payment_status'])) ?></span>
          <span class="badge<?= $cancelled ? ' badge-red' : ' badge-muted' ?>"><?= e(Shop::statusLabel((string) $order['status'])) ?></span>
        </p>
        <?php if ($cancelled): ?>
        <div class="notice notice-error">Bu sipariş iptal edildi. Ödeme yaptıysanız iadeniz aynı ödeme yöntemiyle yapılır.</div>
        <?php else: ?>
        <ol class="status-steps">
          <?php foreach ($flow as $i => $step): ?>
          <li class="<?= $i < $position ? 'done' : ($i === $position ? 'done current' : '') ?>"><?= e(Shop::statusLabel($step)) ?></li>
          <?php endforeach; ?>
        </ol>
        <?php endif; ?>
        <?php if (!empty($order['cargo_tracking'])): ?>
        <p><strong>Kargo takip numarası:</strong> <span class="text-gold"><?= e($order['cargo_tracking']) ?></span></p>
        <?php endif; ?>
        <?php if ($canPay): ?>
        <a class="btn btn-gold" href="<?= e(url('/odeme/' . $order['access_key'])) ?>"><?= $order['payment_method'] === 'havale' ? 'Havale bilgilerini göster' : 'Ödemeyi tamamla' ?></a>
        <?php endif; ?>
      </section>

      <section class="order-box" aria-labelledby="ship-title">
        <h2 id="ship-title" class="section-title">Teslimat</h2>
        <dl class="summary-list">
          <div><dt>Alıcı</dt><dd><?= e($order['customer_name']) ?></dd></div>
          <div><dt>Telefon</dt><dd><?= e(OrderService::formatPhone((string) $order['phone'])) ?></dd></div>
          <div><dt>E-posta</dt><dd><?= e($order['email']) ?></dd></div>
          <div><dt>Adres</dt><dd><?= e($order['address']) ?><br><?= e(trim(($order['district'] ?? '') . ' / ' . $order['city'], ' /')) ?></dd></div>
          <?php if (!empty($order['note'])): ?><div><dt>Not</dt><dd><?= e($order['note']) ?></dd></div><?php endif; ?>
        </dl>
      </section>
    </div>

    <aside class="summary-box" aria-labelledby="sum-title">
      <h2 id="sum-title" class="section-title">Özet</h2>
      <dl class="summary-list">
        <div><dt>Ürün</dt><dd><?php if ($product !== null && $product['is_active']): ?><a href="<?= e(url($product['url'])) ?>"><?= e($order['product_title']) ?></a><?php else: ?><?= e($order['product_title']) ?><?php endif; ?></dd></div>
        <div><dt>Beden / Adet</dt><dd><?= e($order['size']) ?> · <?= (int) $order['quantity'] ?></dd></div>
        <?php if (!empty($order['print_name']) || !empty($order['print_number'])): ?><div><dt>Baskı</dt><dd><?= e(trim($order['print_name'] . ' ' . $order['print_number'])) ?></dd></div><?php endif; ?>
        <div><dt>Birim fiyat</dt><dd><?= e(fmt_price($order['unit_price'])) ?></dd></div>
        <div><dt>Kargo</dt><dd><?= $order['shipping_fee'] > 0 ? e(fmt_price($order['shipping_fee'])) : 'Ücretsiz' ?></dd></div>
        <div class="summary-total"><dt>Toplam</dt><dd><?= e(fmt_price($order['total'])) ?></dd></div>
        <div><dt>Ödeme</dt><dd><?= e($methodLabel) ?></dd></div>
        <?php if (!empty($order['paid_at'])): ?><div><dt>Ödeme tarihi</dt><dd><?= e(fmt_date((string) $order['paid_at'], 'j M Y H:i')) ?></dd></div><?php endif; ?>
      </dl>
      <p class="form-help">Sorunuz mu var? <?php if ($whatsappLink !== null): ?><a href="<?= e($whatsappLink) ?>" target="_blank" rel="nofollow noopener">WhatsApp’tan yazın</a> veya <?php endif; ?><a href="<?= e(url('/iletisim')) ?>">iletişim sayfasını</a> kullanın; sipariş numaranızı belirtin.</p>
    </aside>
  </div>
</div>
