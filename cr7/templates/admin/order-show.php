<?php
/**
 * Admin order detail (/admin/siparisler/{id}).
 *
 * @var array<string, mixed> $order
 * @var array<string, mixed>|null $product
 * @var string $methodLabel
 * @var string $statusUrl
 * @var string $payUrl
 */

use App\Services\Shop\OrderService;
use App\Services\Shop\Shop;

$o = $order;
$payBadge = ['bekliyor' => 'badge-warning', 'odendi' => 'badge-success', 'basarisiz' => 'badge-danger', 'iade' => 'badge-muted'];
$phoneIntl = '90' . ltrim((string) preg_replace('/\D+/', '', (string) $o['phone']), '0');
$printed = !empty($o['print_name']) || !empty($o['print_number']);
$unitBase = $o['unit_price'];
?>
<div class="toolbar">
  <p class="mb-0">
    <span class="badge <?= e($payBadge[$o['payment_status']] ?? 'badge-muted') ?>"><?= e(Shop::paymentStatusLabel((string) $o['payment_status'])) ?></span>
    <span class="badge badge-info"><?= e(Shop::statusLabel((string) $o['status'])) ?></span>
    <span class="muted text-sm"><?= e(fmt_date((string) $o['created_at'], 'j F Y H:i')) ?></span>
  </p>
  <div class="actions">
    <a class="btn btn-sm" href="<?= e($statusUrl) ?>" target="_blank" rel="noopener">Müşteri sayfası ↗</a>
    <a class="btn btn-sm btn-ghost" href="<?= e(url('/admin/siparisler')) ?>">‹ Siparişler</a>
  </div>
</div>

<div class="post-layout">
  <div class="post-main">
    <section class="admin-card" aria-labelledby="o-items">
      <h2 id="o-items">Ürün ve tutar</h2>
      <dl class="meta-list">
        <dt>Ürün</dt><dd><?php if ($product !== null): ?><a href="<?= e(url('/admin/urunler/' . (int) $product['id'] . '/duzenle')) ?>"><?= e($o['product_title']) ?></a><?php else: ?><?= e($o['product_title']) ?> <span class="muted">(ürün silinmiş)</span><?php endif; ?></dd>
        <dt>Beden</dt><dd><strong><?= e($o['size']) ?></strong></dd>
        <dt>Adet</dt><dd><?= (int) $o['quantity'] ?></dd>
        <dt>Baskı</dt><dd><?= $printed ? '<strong class="mono">' . e(trim($o['print_name'] . ' ' . $o['print_number'])) . '</strong>' : '<span class="muted">Yok</span>' ?></dd>
        <dt>Birim fiyat</dt><dd><?= e(fmt_price($unitBase)) ?><?= $printed ? ' <span class="muted">(baskı dahil)</span>' : '' ?></dd>
        <dt>Kargo</dt><dd><?= e(fmt_price($o['shipping_fee'])) ?></dd>
        <dt>Toplam</dt><dd><strong><?= e(fmt_price($o['total'])) ?></strong></dd>
      </dl>
    </section>

    <section class="admin-card" aria-labelledby="o-customer">
      <h2 id="o-customer">Müşteri ve teslimat</h2>
      <dl class="meta-list">
        <dt>Ad Soyad</dt><dd><?= e($o['customer_name']) ?></dd>
        <dt>Telefon</dt><dd><a href="tel:+<?= e($phoneIntl) ?>"><?= e(OrderService::formatPhone((string) $o['phone'])) ?></a> · <a href="https://wa.me/<?= e($phoneIntl) ?>" target="_blank" rel="noopener noreferrer">WhatsApp ↗</a></dd>
        <dt>E-posta</dt><dd><a href="mailto:<?= e($o['email']) ?>"><?= e($o['email']) ?></a></dd>
        <dt>İl / İlçe</dt><dd><?= e($o['city']) ?><?= !empty($o['district']) ? ' / ' . e($o['district']) : '' ?></dd>
        <dt>Adres</dt><dd class="break"><?= nl2br(e($o['address'])) ?></dd>
        <dt>Not</dt><dd class="break"><?= !empty($o['note']) ? nl2br(e($o['note'])) : '<span class="muted">—</span>' ?></dd>
        <dt>KVKK onayı</dt><dd><?= $o['kvkk_consent'] ? 'Verildi' : 'Yok' ?></dd>
        <dt>IP</dt><dd class="mono"><?= e((string) ($o['ip'] ?? '')) ?></dd>
      </dl>
    </section>

    <section class="admin-card" aria-labelledby="o-payment">
      <h2 id="o-payment">Ödeme</h2>
      <dl class="meta-list">
        <dt>Yöntem</dt><dd><?= e($methodLabel) ?></dd>
        <dt>Durum</dt><dd><span class="badge <?= e($payBadge[$o['payment_status']] ?? 'badge-muted') ?>"><?= e(Shop::paymentStatusLabel((string) $o['payment_status'])) ?></span></dd>
        <dt>Ödeme tarihi</dt><dd><?= !empty($o['paid_at']) ? e(fmt_date((string) $o['paid_at'], 'j M Y H:i')) : '—' ?></dd>
        <dt>Referans</dt><dd class="mono break"><?= e((string) ($o['payment_ref'] ?? '—')) ?></dd>
      </dl>
      <?php if ($o['payload'] !== []): ?>
      <details>
        <summary>Ödeme sağlayıcısı yanıtı</summary>
        <dl class="meta-list">
          <?php foreach ($o['payload'] as $k => $v): ?>
          <dt class="mono"><?= e((string) $k) ?></dt><dd class="mono break"><?= e(is_scalar($v) ? (string) $v : (string) json_encode($v, JSON_UNESCAPED_UNICODE)) ?></dd>
          <?php endforeach; ?>
        </dl>
      </details>
      <?php endif; ?>
      <p class="form-help">Müşterinin ödeme sayfası: <a class="break" href="<?= e($payUrl) ?>" target="_blank" rel="noopener"><?= e($payUrl) ?></a></p>
    </section>
  </div>

  <aside class="post-side">
    <?php if ($o['payment_status'] !== 'odendi'): ?>
    <section class="admin-card side-card" aria-labelledby="o-paid">
      <h2 id="o-paid"><?= $o['payment_method'] === 'havale' ? 'Havale ödemesi' : 'Ödemeyi elle onayla' ?></h2>
      <p class="muted text-sm"><?= $o['payment_method'] === 'havale'
          ? 'Hesabınıza ' . e(fmt_price($o['total'])) . ' tutarında, açıklamasında ' . e($o['order_no']) . ' yazan ödeme geldiyse işaretleyin.'
          : 'Ödemeyi ödeme sağlayıcısının panelinden doğruladıysanız elle onaylayabilirsiniz.' ?></p>
      <?php if (!empty($o['payload']['review']) && $o['payment_status'] === 'bekliyor'): ?>
      <p class="notice notice-warning text-sm">Müşteri “başarılı” dönüşüyle geldi<?= !empty($o['payload']['payment_id']) ? ' (ödeme no ' . e((string) $o['payload']['payment_id']) . ')' : '' ?>, ancak bu dönüş ödemeyi kanıtlamaz. Shopier satıcı panelinde <?= e($o['order_no']) ?> için <?= e(fmt_price($o['total'])) ?> tutarında ödemeyi gördükten sonra onaylayın.</p>
      <?php endif; ?>
      <form class="form" method="post" action="<?= e(url('/admin/siparisler/' . (int) $o['id'] . '/durum')) ?>" data-confirm="<?= e($o['order_no']) ?> siparişinin ödemesi alındı olarak işaretlensin mi?">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="mark_paid">
        <div class="field">
          <label for="payment_note">Not (isteğe bağlı)</label>
          <input class="input" id="payment_note" name="payment_note" type="text" maxlength="200" placeholder="Örn. dekont no / gönderen">
        </div>
        <button class="btn btn-gold btn-block" type="submit"><?= $o['payment_method'] === 'havale' ? 'Havale ödemesi alındı' : 'Ödeme alındı olarak işaretle' ?></button>
      </form>
    </section>
    <?php endif; ?>

    <section class="admin-card side-card" aria-labelledby="o-status">
      <h2 id="o-status">Sipariş durumu</h2>
      <form class="form" method="post" action="<?= e(url('/admin/siparisler/' . (int) $o['id'] . '/durum')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="status">
        <div class="field">
          <label for="status">Durum</label>
          <select class="select" id="status" name="status">
            <?php foreach (Shop::STATUSES as $key => $label): ?>
            <option value="<?= e($key) ?>"<?= $o['status'] === $key ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="cargo_tracking">Kargo takip no</label>
          <input class="input mono" id="cargo_tracking" name="cargo_tracking" type="text" maxlength="100" value="<?= e((string) ($o['cargo_tracking'] ?? '')) ?>" placeholder="Firma + takip numarası">
          <p class="form-help">Müşterinin sipariş durumu sayfasında gösterilir.</p>
        </div>
        <button class="btn btn-primary btn-block" type="submit">Durumu kaydet</button>
      </form>
    </section>

    <section class="admin-card side-card" aria-labelledby="o-pstatus">
      <h2 id="o-pstatus">Ödeme durumunu değiştir</h2>
      <form class="form" method="post" action="<?= e(url('/admin/siparisler/' . (int) $o['id'] . '/durum')) ?>" data-confirm="Ödeme durumu değiştirilsin mi?">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="payment">
        <div class="field">
          <label for="payment_status">Ödeme durumu</label>
          <select class="select" id="payment_status" name="payment_status">
            <?php foreach (Shop::PAYMENT_STATUSES as $key => $label): ?>
            <option value="<?= e($key) ?>"<?= $o['payment_status'] === $key ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
          <p class="form-help">İade ve düzeltmeler için. Kart iadelerini ödeme sağlayıcısının panelinden yapın.</p>
        </div>
        <button class="btn btn-block" type="submit">Ödeme durumunu kaydet</button>
      </form>
    </section>
  </aside>
</div>
