<?php
/**
 * /iletisim — contact information (no form: e-mail + optional WhatsApp).
 *
 * @var string|null $email app.contact_email
 * @var array{digits: string, url: string, display: string}|null $whatsapp setting shop.whatsapp
 * @var bool $shopEnabled
 */
?>
<header class="page-header">
  <div class="container container-narrow">
    <span class="eyebrow">İletişim</span>
    <h1>Bize ulaşın</h1>
    <p class="lead">Düzeltme önerileriniz, telif ve kaldırma talepleriniz, forma siparişleriniz ya da KVKK başvurularınız için bize yazabilirsiniz.</p>
  </div>
</header>

<section class="section-sm static-page">
  <div class="container container-narrow">
    <div class="contact-grid">
      <div class="contact-card">
        <h2>E-posta</h2>
        <?php if ($email !== null): ?>
        <p>Tüm konular için bize e-postayla ulaşabilirsiniz; mesajınızda ilgili sayfanın adresini belirtmeniz yanıtı hızlandırır.</p>
        <a class="btn btn-gold" href="mailto:<?= e($email) ?>"><?= e($email) ?></a>
        <?php else: ?>
        <p>E-posta adresi henüz tanımlanmadı.</p>
        <?php endif; ?>
      </div>
      <?php if ($whatsapp !== null): ?>
      <div class="contact-card">
        <h2>WhatsApp</h2>
        <p>Forma siparişleri ve mağaza soruları için WhatsApp üzerinden yazabilirsiniz.</p>
        <a class="btn btn-outline" href="<?= e($whatsapp['url']) ?>" rel="nofollow noopener" target="_blank">WhatsApp: <?= e($whatsapp['display']) ?></a>
      </div>
      <?php endif; ?>
    </div>

    <div class="prose">
      <h2>Hangi konularda yazabilirsiniz?</h2>
      <ul>
        <li><strong>Düzeltme:</strong> Bir haberde, istatistikte ya da biyografide hata gördüyseniz ilgili sayfanın adresini ve doğru bilgiyi (mümkünse kaynağıyla) iletin.</li>
        <li><strong>Telif ve kaldırma:</strong> Hak sahibi olduğunuz bir içeriğin kaldırılmasını istiyorsanız içeriğin adresini ve hak sahipliğinizi gösteren bilgileri paylaşın.</li>
        <?php if ($shopEnabled): ?><li><strong>Forma Mağazası:</strong> Sipariş numaranızı belirterek sipariş, ödeme ve teslimat sorularınızı iletin.</li><?php endif; ?>
        <li><strong>KVKK başvuruları:</strong> Kişisel verilerinize ilişkin taleplerinizi <a href="<?= e(url('/gizlilik-politikasi#haklar')) ?>">Gizlilik Politikası</a>’nda açıklanan şekilde gönderin.</li>
      </ul>

      <h2>Cristiano Ronaldo’ya ulaşmak mı istiyorsunuz?</h2>
      <p>CR7.com.tr bağımsız bir hayran sitesidir; Cristiano Ronaldo, temsilcileri veya kulübüyle bir bağlantımız yoktur ve mesajları kendisine iletemiyoruz. Ronaldo’nun resmî hesapları: <a href="https://www.instagram.com/cristiano/" rel="nofollow noopener" target="_blank">Instagram</a>, <a href="https://x.com/Cristiano" rel="nofollow noopener" target="_blank">X</a>, <a href="https://www.facebook.com/Cristiano" rel="nofollow noopener" target="_blank">Facebook</a> ve <a href="https://www.youtube.com/@cristiano" rel="nofollow noopener" target="_blank">YouTube</a>.</p>
      <p>Site hakkında daha fazla bilgi için <a href="<?= e(url('/hakkinda')) ?>">Hakkında</a> sayfasına göz atabilirsiniz.</p>
    </div>
  </div>
</section>
