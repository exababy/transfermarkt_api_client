<?php
/**
 * /gizlilik-politikasi — KVKK aydınlatma metni, çerez politikası, order/payment data processing.
 *
 * @var string|null $email
 * @var string $siteUrl
 * @var bool $analytics GA4 configured
 * @var string $updated 'Y-m-d'
 */

use App\Controllers\PageController;

$mail = $email !== null ? '<a href="mailto:' . e($email) . '">' . e($email) . '</a>' : 'iletişim sayfasındaki adres';
?>
<header class="page-header">
  <div class="container container-narrow">
    <span class="eyebrow">KVKK</span>
    <h1>Gizlilik Politikası ve KVKK Aydınlatma Metni</h1>
    <p class="lead">Kişisel verilerinizi hangi amaçlarla işlediğimizi, kimlerle paylaştığımızı, çerezleri nasıl kullandığımızı ve 6698 sayılı Kişisel Verilerin Korunması Kanunu (KVKK) kapsamındaki haklarınızı açıklıyoruz.</p>
    <p class="meta page-meta"><span>Son güncelleme: <time datetime="<?= e($updated) ?>"><?= e(PageController::modifiedLabel($updated)) ?></time></span></p>
  </div>
</header>

<section class="section-sm static-page">
  <div class="container container-narrow">
    <nav class="toc" aria-label="Bu sayfada">
      <p class="toc-title">Bu sayfada</p>
      <ol>
        <li><a href="#veri-sorumlusu">1. Veri sorumlusu</a></li>
        <li><a href="#islenen-veriler">2. İşlenen kişisel veriler</a></li>
        <li><a href="#amaclar">3. İşleme amaçları ve hukuki sebepler</a></li>
        <li><a href="#siparis-odeme">4. Sipariş ve ödeme verileri</a></li>
        <li><a href="#aktarim">5. Verilerin aktarılması</a></li>
        <li><a href="#saklama">6. Saklama süreleri ve güvenlik</a></li>
        <li><a href="#haklar">7. KVKK kapsamındaki haklarınız</a></li>
        <li><a href="#cerezler">8. Çerez politikası</a></li>
        <li><a href="#degisiklikler">9. Değişiklikler</a></li>
      </ol>
    </nav>

    <div class="prose">
      <h2 id="veri-sorumlusu">1. Veri sorumlusu</h2>
      <p>Bu aydınlatma metni, <a href="<?= e($siteUrl) ?>"><?= e($siteUrl) ?></a> adresinde yayın yapan CR7.com.tr hayran sitesinin işletmecisi tarafından, KVKK’nın 10. maddesi uyarınca veri sorumlusu sıfatıyla hazırlanmıştır. Kişisel verilerinizle ilgili her türlü soru ve başvuru için <?= $mail ?> üzerinden bize ulaşabilirsiniz.</p>

      <h2 id="islenen-veriler">2. İşlenen kişisel veriler</h2>
      <ul>
        <li><strong>Ziyaret ve güvenlik verileri:</strong> IP adresi, tarayıcı ve cihaz bilgisi, ziyaret edilen sayfa, tarih ve saat gibi sunucu kayıtları.</li>
        <li><strong>Çerez ve benzeri teknolojiler:</strong> Aşağıdaki <a href="#cerezler">çerez politikasında</a> açıklanan zorunlu çerezler ve yalnızca onayınızla çalışan analitik çerezler.</li>
        <li><strong>İletişim verileri:</strong> Bize e-posta veya WhatsApp ile yazdığınızda paylaştığınız ad, e-posta adresi, telefon numarası ve mesaj içeriği.</li>
        <li><strong>Sipariş verileri:</strong> Forma Mağazası’ndan sipariş vermeniz hâlinde ad soyad, telefon, e-posta, il, ilçe, teslimat adresi, sipariş notu, beden ve forma baskısı (isim/numara) tercihleri, sipariş tutarı, sipariş ve ödeme durumu, IP adresi ve KVKK onay kaydı.</li>
      </ul>
      <p>Sitemizde üyelik sistemi yoktur; haberleri, istatistikleri ve diğer içerikleri okumak için kişisel veri vermeniz gerekmez.</p>

      <h2 id="amaclar">3. İşleme amaçları ve hukuki sebepler</h2>
      <ul>
        <li>Sitenin güvenli ve kesintisiz çalışması, kötüye kullanımın ve saldırıların önlenmesi, hataların tespiti — KVKK m. 5/2-f (meşru menfaat) ve m. 5/2-ç (hukuki yükümlülük).</li>
        <li>Siparişlerin alınması, ödemenin tamamlanması, ürünün hazırlanıp teslim edilmesi, iade ve değişim taleplerinin yürütülmesi — KVKK m. 5/2-c (sözleşmenin kurulması ve ifası).</li>
        <li>Fatura düzenlenmesi, muhasebe kayıtlarının tutulması ve yetkili kurumların taleplerinin karşılanması — KVKK m. 5/2-ç (hukuki yükümlülük).</li>
        <li>İletişim taleplerinize, düzeltme ve kaldırma isteklerine yanıt verilmesi — KVKK m. 5/2-f (meşru menfaat).</li>
        <li>Ziyaret istatistiklerinin ölçülmesi (Google Analytics) — yalnızca KVKK m. 5/1 uyarınca açık rızanız olması hâlinde.</li>
      </ul>

      <h2 id="siparis-odeme">4. Sipariş ve ödeme verileri</h2>
      <p>Sipariş formunda verdiğiniz bilgiler yalnızca siparişinizin işlenmesi, ödemenin doğrulanması, kargolanması ve sizinle sipariş hakkında iletişim kurulması amacıyla kullanılır; pazarlama amacıyla kullanılmaz ve satılmaz.</p>
      <ul>
        <li><strong>Kart bilgileri sitemizde işlenmez ve saklanmaz.</strong> Kartla ödemeler, lisanslı ödeme kuruluşlarının güvenli ödeme sayfalarında gerçekleşir. Ödeme sağlayıcısı olarak <strong>PayTR</strong>, <strong>iyzico</strong> ve <strong>Shopier</strong> kullanılabilir; seçtiğiniz sağlayıcı, ödemeyi kendi gizlilik politikası ve mevzuat kapsamında veri sorumlusu olarak işler.</li>
        <li>Ödeme işlemi için seçtiğiniz sağlayıcıya ad soyad, e-posta, telefon, adres, IP adresi, sipariş numarası ve tutarı aktarılır. Sağlayıcıdan yalnızca ödemenin başarılı olup olmadığı ve işlem referans numarası tarafımıza iletilir.</li>
        <li><strong>Havale/EFT</strong> ile ödemede, banka hesabımıza gönderdiğiniz tutar ve gönderen bilgisi, ödemenin siparişle eşleştirilmesi için kullanılır.</li>
        <li>Siparişinizin teslimi için ad soyad, telefon ve adres bilgileriniz anlaşmalı kargo şirketiyle paylaşılır.</li>
      </ul>

      <h2 id="aktarim">5. Verilerin aktarılması</h2>
      <p>Kişisel verileriniz, yukarıdaki amaçlarla sınırlı olarak ve KVKK’nın 8. ve 9. maddelerine uygun şekilde şu alıcılarla paylaşılabilir:</p>
      <ul>
        <li>Ödeme kuruluşları: PayTR, iyzico, Shopier (yalnızca ilgili ödeme yöntemini seçmeniz hâlinde) ve bankalar.</li>
        <li>Kargo ve lojistik firmaları (yalnızca sipariş teslimi için).</li>
        <li>Barındırma (hosting), e-posta ve teknik altyapı hizmeti aldığımız tedarikçiler.</li>
        <li>Talep edilmesi hâlinde yetkili kamu kurum ve kuruluşları ile yargı mercileri.</li>
        <li>Açık rızanız olması hâlinde Google LLC (Google Analytics). Video sayfalarındaki YouTube oynatıcısı, Google’ın gizlilik geliştirilmiş youtube-nocookie.com alan adından yüklenir; bu sırada IP adresiniz ve tarayıcı bilgileriniz Google’a iletilir. Bu hizmetler verileri yurt dışındaki sunucularda işleyebilir; analitik çerezler yalnızca açık rızanızla çalıştırılır.</li>
      </ul>

      <h2 id="saklama">6. Saklama süreleri ve güvenlik</h2>
      <ul>
        <li>Sipariş ve fatura kayıtları, vergi ve ticaret mevzuatının öngördüğü süre boyunca (genel olarak 10 yıl) saklanır.</li>
        <li>İletişim yazışmaları, talebiniz sonuçlandıktan sonra makul bir süre (en fazla 2 yıl) saklanır.</li>
        <li>Güvenlik amaçlı sunucu kayıtları ve hata günlükleri sınırlı bir süre tutulur ve düzenli olarak silinir.</li>
        <li>Saklama süresi dolan veriler silinir, yok edilir veya anonim hâle getirilir.</li>
      </ul>
      <p>Verilerinizi korumak için HTTPS şifrelemesi, erişim yetkilendirmesi, güçlü parola özetleme (hash), saldırı ve kötüye kullanım koruması gibi teknik ve idari tedbirler uygularız.</p>

      <h2 id="haklar">7. KVKK kapsamındaki haklarınız</h2>
      <p>KVKK’nın 11. maddesi uyarınca veri sorumlusuna başvurarak:</p>
      <ul>
        <li>Kişisel verilerinizin işlenip işlenmediğini öğrenme, işlenmişse buna ilişkin bilgi talep etme,</li>
        <li>İşlenme amacını ve bunların amacına uygun kullanılıp kullanılmadığını öğrenme,</li>
        <li>Yurt içinde veya yurt dışında aktarıldığı üçüncü kişileri bilme,</li>
        <li>Eksik veya yanlış işlenmişse düzeltilmesini isteme,</li>
        <li>KVKK’nın 7. maddesindeki şartlar çerçevesinde silinmesini veya yok edilmesini isteme,</li>
        <li>Düzeltme, silme ve yok etme işlemlerinin verilerin aktarıldığı üçüncü kişilere bildirilmesini isteme,</li>
        <li>İşlenen verilerin münhasıran otomatik sistemler vasıtasıyla analiz edilmesi suretiyle aleyhinize bir sonucun ortaya çıkmasına itiraz etme,</li>
        <li>Kanuna aykırı işleme nedeniyle zarara uğramanız hâlinde zararın giderilmesini talep etme</li>
      </ul>
      <p>haklarına sahipsiniz. Başvurunuzu kimliğinizi doğrulamamıza imkân verecek bilgilerle birlikte <?= $mail ?> adresine iletebilirsiniz. Başvurular, niteliğine göre en geç 30 gün içinde ücretsiz olarak sonuçlandırılır. Başvurunuzun reddedilmesi veya yanıtı yetersiz bulmanız hâlinde Kişisel Verileri Koruma Kurulu’na şikâyette bulunma hakkınız saklıdır.</p>

      <h2 id="cerezler">8. Çerez politikası</h2>
      <p>Çerezler, ziyaret ettiğiniz sitelerin tarayıcınıza kaydettiği küçük metin dosyalarıdır. CR7.com.tr çerezleri olabildiğince az kullanır:</p>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr><th scope="col">Ad</th><th scope="col">Tür</th><th scope="col">Amaç</th><th scope="col">Süre</th></tr>
          </thead>
          <tbody>
            <tr><td>cr7sess</td><td>Zorunlu</td><td class="wrap">Oturum çerezi: sipariş formu ve yönetim paneli güvenliği (CSRF koruması). Yalnızca form içeren sayfalarda oluşturulur.</td><td>Oturum</td></tr>
            <tr><td>cr7-consent</td><td>Zorunlu (tercih)</td><td class="wrap">Çerez tercihinizin tarayıcınızın yerel depolama alanında saklanması.</td><td>180 gün</td></tr>
            <?php if ($analytics): ?>
            <tr><td>_ga, _ga_*</td><td>Analitik (onaya bağlı)</td><td class="wrap">Google Analytics ile anonimleştirilmiş ziyaret istatistikleri. Yalnızca “Kabul Et” seçeneğini işaretlemeniz hâlinde yüklenir.</td><td>En fazla 2 yıl</td></tr>
            <?php endif; ?>
            <tr><td>YouTube (Google)</td><td>Üçüncü taraf</td><td class="wrap">Video sayfalarında youtube-nocookie.com üzerinden yüklenen oynatıcı. Gizlilik geliştirilmiş modda, videoyu oynatmadığınız sürece YouTube çerezi kullanılmaz.</td><td>Google politikasına göre</td></tr>
          </tbody>
        </table>
      </div>
      <p><?php if ($analytics): ?>Çerez tercihinizi sayfanın altındaki “Çerez tercihleri” bağlantısıyla dilediğiniz zaman değiştirebilirsiniz. <?php endif; ?>Tarayıcınızın ayarlarından çerezleri silebilir veya engelleyebilirsiniz; zorunlu çerezlerin engellenmesi hâlinde sipariş formu gibi bazı özellikler çalışmayabilir.</p>

      <h2 id="degisiklikler">9. Değişiklikler</h2>
      <p>Bu metin, mevzuattaki veya sitedeki değişikliklere göre güncellenebilir. Güncel sürüm her zaman bu sayfada yayımlanır; sayfanın başındaki “Son güncelleme” tarihi en son değişikliği gösterir.</p>
    </div>
  </div>
</section>
