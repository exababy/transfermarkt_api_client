<?php
/**
 * /odeme/sonuc/{key} — landing page after a payment attempt (noindex).
 *
 * @var array<string, mixed> $order
 * @var string $state paid|failed|pending|transfer
 * @var string $methodLabel
 * @var string $payUrl
 * @var string $statusUrl
 */

$icon = match ($state) {
    'paid' => ['result-ok', '✓'],
    'failed' => ['result-fail', '!'],
    default => ['result-wait', '…'],
};
?>
<section class="section">
  <div class="container container-narrow">
    <div class="order-box center">
      <span class="result-icon <?= e($icon[0]) ?>" aria-hidden="true"><?= e($icon[1]) ?></span>
      <?php if ($state === 'paid'): ?>
      <h1>Ödemeniz alındı, teşekkürler!</h1>
      <p class="lead">Sipariş numaranız <strong><?= e($order['order_no']) ?></strong>. Forma siparişiniz onaylandı ve hazırlanmaya başlanacak. Kargo bilgisi sipariş durumu sayfanızda paylaşılacak.</p>
      <?php elseif ($state === 'failed'): ?>
      <h1>Ödeme tamamlanamadı</h1>
      <p class="lead">Kartınızdan herhangi bir tutar çekilmediyse ödemeyi tekrar deneyebilirsiniz. Sorun devam ederse farklı bir kartla deneyin veya bizimle iletişime geçin. Sipariş numaranız: <strong><?= e($order['order_no']) ?></strong>.</p>
      <?php elseif ($state === 'transfer'): ?>
      <h1>Siparişiniz alındı</h1>
      <p class="lead">Havale/EFT ödemeniz hesabımıza ulaştığında siparişiniz onaylanacak. Açıklamaya <strong><?= e($order['order_no']) ?></strong> yazmayı unutmayın.</p>
      <?php else: ?>
      <h1>Ödemeniz kontrol ediliyor</h1>
      <p class="lead">Ödeme sağlayıcısından onay bekleniyor; bu birkaç saniye sürebilir ve sayfa kendiliğinden yenilenir. Sipariş numaranız: <strong><?= e($order['order_no']) ?></strong>.</p>
      <?php endif; ?>

      <dl class="summary-list">
        <div><dt>Ürün</dt><dd><?= e($order['product_title']) ?> (<?= e($order['size']) ?>)</dd></div>
        <div><dt>Ödeme yöntemi</dt><dd><?= e($methodLabel) ?></dd></div>
        <div class="summary-total"><dt>Toplam</dt><dd><?= e(fmt_price($order['total'])) ?></dd></div>
      </dl>

      <div class="cluster center result-actions">
        <?php if ($state === 'failed' || $state === 'transfer'): ?>
        <a class="btn btn-gold" href="<?= e($payUrl) ?>"><?= $state === 'failed' ? 'Ödemeyi tekrar dene' : 'Hesap bilgilerini göster' ?></a>
        <?php endif; ?>
        <a class="btn btn-outline" href="<?= e($statusUrl) ?>">Sipariş durumu</a>
        <a class="btn btn-ghost" href="<?= e(url('/forma-magazasi')) ?>">Mağazaya dön</a>
      </div>
    </div>
  </div>
</section>
