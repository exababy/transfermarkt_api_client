<?php
/**
 * Site footer: navigation, disclaimer, data credit, legal links, copyright.
 *
 * @var string $ga4 GA4 id (non-empty => show "Çerez tercihleri" link)
 */

use App\Support\Date;
use App\Support\Format;

$shopEnabled = (bool) setting('shop.enabled', true);
?>
<footer class="site-footer">
  <div class="container footer-grid">
    <div class="footer-about">
      <a class="brand" href="<?= e(url('/')) ?>" aria-label="CR7.com.tr ana sayfa">
        <img class="brand-logo" src="<?= e(asset('img/logo.svg')) ?>" width="77" height="32" alt="CR7" loading="lazy">
        <span class="brand-tld">.com.tr</span>
      </a>
      <p class="footer-disclaimer">Bu site Cristiano Ronaldo veya kulüpleriyle resmi bir bağlantısı olmayan bağımsız bir hayran sitesidir.</p>
      <p class="footer-credit">İstatistik verileri: Transfermarkt</p>
    </div>
    <nav class="footer-col" aria-label="Keşfet">
      <h2 class="footer-title">Keşfet</h2>
      <ul class="footer-links">
        <li><a href="<?= e(url('/hayati')) ?>">Hayatı</a></li>
        <li><a href="<?= e(url('/kariyer')) ?>">Kariyer</a></li>
        <li><a href="<?= e(url('/takimlar')) ?>">Oynadığı Takımlar</a></li>
        <li><a href="<?= e(url('/istatistikler')) ?>">İstatistikler</a></li>
        <li><a href="<?= e(url('/kupalar-ve-oduller')) ?>">Kupalar ve Ödüller</a></li>
      </ul>
    </nav>
    <nav class="footer-col" aria-label="Medya">
      <h2 class="footer-title">Medya</h2>
      <ul class="footer-links">
        <li><a href="<?= e(url('/haberler')) ?>">Haberler</a></li>
        <li><a href="<?= e(url('/videolar')) ?>">Videolar</a></li>
        <?php if ($shopEnabled): ?>
        <li><a href="<?= e(url('/forma-magazasi')) ?>">Forma Mağazası</a></li>
        <?php endif; ?>
        <li><a href="<?= e(url('/rss.xml')) ?>">RSS</a></li>
      </ul>
    </nav>
    <nav class="footer-col" aria-label="Site">
      <h2 class="footer-title">Site</h2>
      <ul class="footer-links">
        <li><a href="<?= e(url('/hakkinda')) ?>">Hakkında</a></li>
        <li><a href="<?= e(url('/iletisim')) ?>">İletişim</a></li>
        <li><a href="<?= e(url('/gizlilik-politikasi')) ?>">Gizlilik Politikası</a></li>
        <?php if (!empty($ga4)): ?>
        <li><button type="button" class="link-button" data-consent-open>Çerez tercihleri</button></li>
        <?php endif; ?>
      </ul>
    </nav>
  </div>
  <div class="container footer-bottom">
    <p>© <?= e(Format::date(Date::now(), 'Y')) ?> CR7.com.tr · Tüm hakları saklıdır.</p>
    <p>Bağımsız hayran sitesi · İstatistik verileri: Transfermarkt</p>
  </div>
</footer>
