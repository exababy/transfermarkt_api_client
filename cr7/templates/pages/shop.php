<?php
/**
 * /forma-magazasi — jersey listing with club filter chips (?takim=club_id).
 *
 * @var list<array<string, mixed>> $products ProductRepository::all() rows
 * @var array<string, string> $clubs club_id => club_name (active products)
 * @var string|null $clubId active filter
 * @var string|null $clubName
 * @var bool $ordersOpen
 * @var int $shippingKurus
 * @var float|null $printPrice cheapest name/number printing price
 * @var string|null $whatsappLink "haber ver" link while ordering is closed
 */

use App\Services\Shop\Shop;

$kitLabels = Shop::KIT_TYPES;
?>
<header class="shop-hero">
  <div class="container">
    <span class="eyebrow">Forma Mağazası</span>
    <h1><?= $clubName !== null ? e($clubName) . ' Ronaldo Formaları' : 'Cristiano Ronaldo Formaları' ?></h1>
    <p class="lead">
      <?php if ($clubName !== null): ?>
      Cristiano Ronaldo’nun <?= e($clubName) ?> dönemini hatırlatan formalar. Tüm kulüplerin formaları için filtreyi kaldırabilirsiniz.
      <?php else: ?>
      Sporting CP’deki ilk sezonundan Manchester United, Real Madrid, Juventus ve Al-Nassr’a, Portekiz milli takımına kadar Ronaldo’nun kariyerindeki her durağın formasını tek yerde topladık.
      <?php endif; ?>
    </p>
    <ul class="shop-facts" aria-label="Mağaza bilgileri">
      <li>S–XXL ve çocuk bedenleri</li>
      <li><?= $printPrice !== null ? 'İsim ve numara baskısı (+' . e(fmt_price($printPrice)) . ')' : 'İsim ve numara baskısı' ?></li>
      <li><?= $shippingKurus > 0 ? 'Kargo ' . e(fmt_price($shippingKurus / 100)) : 'Kargo ücretsiz' ?></li>
      <li>Kartla, Shopier ile veya havale/EFT ile ödeme</li>
    </ul>
  </div>
</header>

<section class="section-sm" aria-labelledby="products-title">
  <div class="container">
    <?php if (!$ordersOpen): ?>
    <div class="notice notice-warning" role="status">
      <strong class="notice-title">Yakında satışta</strong>
      Formalarımızı şimdiden inceleyebilirsiniz; online sipariş çok yakında açılacak. Fiyatlar ve bedenler bilgilendirme amaçlıdır.
      <?php if ($whatsappLink !== null): ?>
      <a href="<?= e($whatsappLink) ?>" rel="nofollow noopener" target="_blank">Satışa açıldığında WhatsApp’tan haber alın</a>.
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <h2 id="products-title" class="visually-hidden">Formalar</h2>
    <?php if (count($clubs) > 1): ?>
    <nav class="shop-chips" aria-label="Kulübe göre filtrele">
      <a class="tag<?= $clubId === null ? ' active' : '' ?>" href="<?= e(url('/forma-magazasi')) ?>"<?= $clubId === null ? ' aria-current="page"' : '' ?>>Tümü</a>
      <?php foreach ($clubs as $id => $name): ?>
      <a class="tag<?= $clubId === (string) $id ? ' active' : '' ?>" href="<?= e(url('/forma-magazasi?takim=' . rawurlencode((string) $id))) ?>"<?= $clubId === (string) $id ? ' aria-current="page"' : '' ?>><?= e($name) ?></a>
      <?php endforeach; ?>
    </nav>
    <?php endif; ?>

    <?php if ($products === []): ?>
    <div class="empty-state">
      <span class="empty-state-icon" aria-hidden="true">7</span>
      <h2>Henüz forma eklenmedi</h2>
      <p>Mağazamız hazırlanıyor. Kısa süre sonra tekrar ziyaret edin.</p>
    </div>
    <?php else: ?>
    <div class="product-grid">
      <?php foreach ($products as $i => $product): ?>
      <article class="card product-card">
        <a class="card-media" href="<?= e(url($product['url'])) ?>" tabindex="-1" aria-hidden="true">
          <?php if ($product['image_url'] !== ''): ?>
          <img src="<?= e($product['image_url']) ?>" alt="<?= e(Shop::seoTitle((string) $product['title'])) ?>" width="600" height="620"<?= $i < 4 ? ' fetchpriority="' . ($i === 0 ? 'high' : 'auto') . '"' : ' loading="lazy"' ?> decoding="async">
          <?php endif; ?>
        </a>
        <span class="badge"><?= e($kitLabels[$product['kit_type']] ?? $product['kit_type']) ?></span>
        <?php if ($product['old_price'] !== null && $product['old_price'] > $product['price']): ?>
        <span class="badge badge-red badge-sale">%<?= (int) round((1 - $product['price'] / $product['old_price']) * 100) ?> indirim</span>
        <?php endif; ?>
        <div class="card-body">
          <p class="card-kicker"><?= e($product['club_name'] . (!empty($product['season']) ? ' · ' . $product['season'] : '')) ?></p>
          <h3 class="card-title"><a href="<?= e(url($product['url'])) ?>"><?= e(Shop::nobr($product['title'])) ?></a></h3>
          <div class="price-row">
            <span class="price"><?= e(fmt_price($product['price'])) ?></span>
            <?php if ($product['old_price'] !== null && $product['old_price'] > $product['price']): ?>
            <del class="price-old"><span class="visually-hidden">Eski fiyat: </span><?= e(fmt_price($product['old_price'])) ?></del>
            <?php endif; ?>
          </div>
          <?php if (!$ordersOpen): ?><span class="soon-tag">Yakında satışta</span><?php endif; ?>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</section>

<section class="section-sm section-alt" aria-labelledby="shop-info-title">
  <div class="container">
    <h2 id="shop-info-title" class="section-title">Baskı, kargo ve ödeme</h2>
    <div class="grid grid-3">
      <div>
        <h3>RONALDO baskısı</h3>
        <p class="text-muted">Her formanın sırtına isteğe bağlı olarak isim ve numara basılır. Varsayılan baskı <strong>RONALDO 7</strong> (Sporting CP retro formasında 28); siparişte dilediğiniz ismi ve numarayı yazabilirsiniz.</p>
      </div>
      <div>
        <h3>Kargo ve teslimat</h3>
        <p class="text-muted"><?= $shippingKurus > 0 ? 'Türkiye’nin her yerine sabit ' . e(fmt_price($shippingKurus / 100)) . ' kargo ücreti uygulanır.' : 'Türkiye’nin her yerine kargo ücretsizdir.' ?> Siparişler ödeme onayından sonra hazırlanır; kargo takip numarası sipariş durumu sayfanızda paylaşılır.</p>
      </div>
      <div>
        <h3>Güvenli ödeme</h3>
        <p class="text-muted">Kart ödemeleri PayTR, iyzico veya Shopier’in güvenli ödeme sayfalarında yapılır; kart bilgileriniz sitemizde saklanmaz. Dilerseniz havale/EFT ile de ödeyebilirsiniz.</p>
      </div>
    </div>
    <p class="shop-note">Görseller temsilidir; kulüp armaları ve sponsor logoları gösterilmez. Satış koşulları: <a href="<?= e(url('/on-bilgilendirme-formu')) ?>">Ön Bilgilendirme Formu</a> · <a href="<?= e(url('/mesafeli-satis-sozlesmesi')) ?>">Mesafeli Satış Sözleşmesi</a> · <a href="<?= e(url('/iptal-ve-iade')) ?>">İptal ve İade</a>. Ronaldo’nun kulüp kariyerini <a href="<?= e(url('/takimlar')) ?>">Oynadığı Takımlar</a> sayfasında inceleyebilirsiniz.</p>
  </div>
</section>
