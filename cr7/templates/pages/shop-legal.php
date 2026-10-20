<?php
/**
 * Shop legal pages (6502 sayılı Tüketicinin Korunması Hakkında Kanun + Mesafeli Sözleşmeler Yönetmeliği):
 * /mesafeli-satis-sozlesmesi, /on-bilgilendirme-formu, /iptal-ve-iade. Seller identity comes from the
 * settings shop.company_* (admin › Ödeme Ayarları); missing values are shown as highlighted placeholders.
 *
 * @var string $doc mesafeli|on-bilgi|iade
 * @var string $title
 * @var array<string, string> $company
 * @var int $shippingKurus
 * @var array<string, string> $methods available payment method labels
 * @var string $contactEmail
 */

$ph = static fn (string $key, string $placeholder): string => ($company[$key] ?? '') !== ''
    ? e($company[$key])
    : '<span class="ph">[' . e($placeholder) . ']</span>';
$email = ($company['company_email'] ?? '') !== '' ? $company['company_email'] : $contactEmail;
$emailHtml = $email !== '' ? '<a href="mailto:' . e($email) . '">' . e($email) . '</a>' : $ph('company_email', 'E-posta adresi');
$shipping = $shippingKurus > 0 ? fmt_price($shippingKurus / 100) . ' (sabit, sipariş özetinde ayrıca gösterilir)' : 'ücretsiz';
$methodText = $methods !== [] ? implode(', ', $methods) : 'kredi/banka kartı (PayTR, iyzico, Shopier) ve havale/EFT';
$seller = static function () use ($ph, $emailHtml): string {
    return '<ul>'
        . '<li><strong>Unvan / Ad Soyad:</strong> ' . $ph('company_name', 'Satıcı unvanı') . '</li>'
        . '<li><strong>Adres:</strong> ' . $ph('company_address', 'Satıcı açık adresi') . '</li>'
        . '<li><strong>Telefon:</strong> ' . $ph('company_phone', 'Telefon numarası') . '</li>'
        . '<li><strong>E-posta:</strong> ' . $emailHtml . '</li>'
        . '<li><strong>Vergi dairesi / numarası:</strong> ' . $ph('company_tax_office', 'Vergi dairesi') . ' / ' . $ph('company_tax_number', 'Vergi veya T.C. kimlik no') . '</li>'
        . '<li><strong>MERSİS no:</strong> ' . $ph('company_mersis', 'MERSİS numarası (varsa)') . '</li>'
        . '<li><strong>Web sitesi:</strong> ' . e(url('/')) . '</li>'
        . '</ul>';
};
?>
<header class="page-header">
  <div class="container container-narrow">
    <span class="eyebrow">Forma Mağazası</span>
    <h1><?= e($title) ?></h1>
    <p class="lead">CR7.com.tr Forma Mağazası üzerinden verilen siparişlere uygulanan koşullar.</p>
  </div>
</header>

<article class="section-sm">
  <div class="container container-narrow prose legal-doc">
    <?php if ($doc === 'mesafeli'): ?>
    <h2>1. Taraflar</h2>
    <p><strong>SATICI</strong></p>
    <?= $seller() ?>
    <p><strong>ALICI</strong>: Sipariş formunda adı, soyadı, teslimat adresi, telefonu ve e-posta adresi belirtilen kişi.</p>

    <h2>2. Sözleşmenin konusu</h2>
    <p>Bu sözleşmenin konusu, ALICI’nın SATICI’ya ait <?= e(url('/forma-magazasi')) ?> adresindeki Forma Mağazası’ndan elektronik ortamda siparişini verdiği ürünün satışı ve teslimidir. Tarafların hak ve yükümlülükleri 6502 sayılı Tüketicinin Korunması Hakkında Kanun ve Mesafeli Sözleşmeler Yönetmeliği hükümlerine göre belirlenir.</p>

    <h2>3. Ürün, fiyat ve ödeme</h2>
    <p>Ürünün adı, bedeni, adedi, isim-numara baskısı, vergiler dahil satış fiyatı, kargo ücreti ve toplam tutar; sipariş formunda, ödeme sayfasında ve ALICI’ya özel sipariş durumu sayfasında gösterildiği gibidir. Fiyatlar Türk lirası cinsindendir ve vergiler dahildir. Kargo ücreti: <?= e($shipping) ?>.</p>
    <p>Ödeme yöntemleri: <?= e($methodText) ?>. Kart ödemeleri ilgili ödeme kuruluşunun güvenli ödeme sayfasında yapılır; kart bilgileri SATICI tarafından saklanmaz. Havale/EFT ödemelerinde açıklamaya sipariş numarasının yazılması gerekir; ödemesi 3 iş günü içinde yapılmayan siparişler iptal edilebilir.</p>

    <h2>4. Teslimat</h2>
    <p>Ürün, ödemenin onaylanmasından sonra hazırlanır ve yasal 30 günlük süreyi aşmamak kaydıyla ALICI’nın sipariş formunda belirttiği adrese kargo ile teslim edilir. Kargo takip numarası sipariş durumu sayfasında paylaşılır. Teslimat sırasında hasarlı olduğu görülen paketler için kargo görevlisine tutanak tutturulması önerilir.</p>

    <h2>5. Cayma hakkı</h2>
    <p>ALICI, ürünün kendisine veya gösterdiği kişiye teslim edildiği günden itibaren <strong>14 gün</strong> içinde herhangi bir gerekçe göstermeksizin ve cezai şart ödemeksizin sözleşmeden cayma hakkına sahiptir. Cayma bildirimi <?= $emailHtml ?> adresine yazılı olarak veya kalıcı veri saklayıcısı ile yapılabilir.</p>
    <p>Mesafeli Sözleşmeler Yönetmeliği’nin 15. maddesi uyarınca <strong>ALICI’nın istekleri veya kişisel ihtiyaçları doğrultusunda hazırlanan ürünlerde</strong> (ALICI’nın belirlediği isim ve/veya numara baskısı yapılmış formalar) cayma hakkı kullanılamaz. Ürünün ayıplı olması hâlinde ALICI’nın kanundan doğan hakları saklıdır.</p>

    <h2>6. İade ve geri ödeme</h2>
    <p>Cayma hakkının kullanılması hâlinde ALICI ürünü cayma bildiriminden itibaren 10 gün içinde, kullanılmamış ve yeniden satılabilir durumda SATICI’ya geri gönderir. SATICI, cayma bildiriminin kendisine ulaştığı tarihten itibaren 14 gün içinde ürün bedelini ALICI’nın ödemede kullandığı yöntemle iade eder. Ayrıntılar <a href="<?= e(url('/iptal-ve-iade')) ?>">İptal ve İade Koşulları</a> sayfasındadır.</p>

    <h2>7. Kişisel veriler</h2>
    <p>ALICI’nın sipariş sırasında verdiği kişisel veriler yalnızca siparişin işlenmesi, ödeme, teslimat ve yasal yükümlülükler için 6698 sayılı KVKK’ya uygun olarak işlenir. Ayrıntılar: <a href="<?= e(url('/gizlilik-politikasi')) ?>">Gizlilik Politikası ve KVKK Aydınlatma Metni</a>.</p>

    <h2>8. Uyuşmazlıkların çözümü</h2>
    <p>Bu sözleşmeden doğan uyuşmazlıklarda, Ticaret Bakanlığı’nca her yıl ilan edilen parasal sınırlar dahilinde ALICI’nın yerleşim yerindeki veya işlemin yapıldığı yerdeki <strong>tüketici hakem heyetleri</strong>, bu sınırları aşan uyuşmazlıklarda <strong>tüketici mahkemeleri</strong> yetkilidir.</p>

    <h2>9. Yürürlük</h2>
    <p>ALICI, sipariş formundaki onay kutusunu işaretleyip siparişi tamamladığında bu sözleşmenin ve <a href="<?= e(url('/on-bilgilendirme-formu')) ?>">Ön Bilgilendirme Formu</a>’nun tüm koşullarını kabul etmiş sayılır. Sözleşme, sipariş tarihinde elektronik ortamda kurulur ve sipariş kaydıyla birlikte saklanır.</p>

    <?php elseif ($doc === 'on-bilgi'): ?>
    <p>Bu form, Mesafeli Sözleşmeler Yönetmeliği’nin 5. maddesi uyarınca sözleşme kurulmadan önce ALICI’nın bilgilendirilmesi amacıyla hazırlanmıştır.</p>
    <h2>1. Satıcı bilgileri</h2>
    <?= $seller() ?>

    <h2>2. Ürünün temel nitelikleri</h2>
    <p>Satışa sunulan ürünler, Cristiano Ronaldo’nun oynadığı kulüplerin ve Portekiz milli takımının renklerinden ilham alan futbol formalarıdır. Her ürünün adı, sezonu, bedenleri, isim-numara baskı seçeneği ve fiyatı ürün sayfasında yer alır. Ürün görselleri temsilidir.</p>

    <h2>3. Fiyat, kargo ve ödeme</h2>
    <ul>
      <li>Fiyatlar Türk lirası cinsinden ve vergiler dahildir; toplam tutar sipariş özetinde gösterilir.</li>
      <li>İsim-numara baskısı ürün sayfasında belirtilen ek ücrete tabidir.</li>
      <li>Kargo ücreti: <?= e($shipping) ?>.</li>
      <li>Ödeme yöntemleri: <?= e($methodText) ?>.</li>
    </ul>

    <h2>4. Teslimat</h2>
    <p>Ürün, ödemenin onaylanmasından itibaren en geç 30 gün içinde ALICI’nın belirttiği adrese kargo ile gönderilir. Sipariş ve kargo durumu, sipariş sonrasında ALICI’ya özel olarak verilen sipariş durumu adresinden takip edilebilir.</p>

    <h2>5. Cayma hakkı</h2>
    <p>ALICI, teslimden itibaren 14 gün içinde gerekçe göstermeden cayma hakkını kullanabilir. Kişiye özel isim ve/veya numara baskısı yapılan formalarda, Yönetmelik’in 15/1-ç maddesi gereğince cayma hakkı bulunmamaktadır. Cayma bildirimi için: <?= $emailHtml ?>.</p>

    <h2>6. Şikâyet ve itirazlar</h2>
    <p>Şikâyetlerinizi <?= $emailHtml ?> adresine iletebilirsiniz. Uyuşmazlık hâlinde, parasal sınırlar dahilinde tüketici hakem heyetlerine, aşan durumlarda tüketici mahkemelerine başvurabilirsiniz.</p>
    <p>Sözleşmenin tamamı için <a href="<?= e(url('/mesafeli-satis-sozlesmesi')) ?>">Mesafeli Satış Sözleşmesi</a>’ni inceleyiniz.</p>

    <?php else: ?>
    <h2>Siparişin iptali</h2>
    <p>Kargoya verilmemiş siparişlerinizi, sipariş numaranızla birlikte <?= $emailHtml ?> adresine yazarak iptal edebilirsiniz. Ödemesi alınmış siparişlerde bedel, ödeme yaptığınız yöntemle 14 gün içinde iade edilir. Kart iadelerinin hesabınıza yansıma süresi bankanıza göre değişebilir.</p>

    <h2>Cayma hakkı ve iade</h2>
    <ul>
      <li>Baskısız ürünlerde, ürünü teslim aldığınız tarihten itibaren <strong>14 gün</strong> içinde cayma hakkınızı kullanabilirsiniz.</li>
      <li>Ürün; kullanılmamış, yıkanmamış, etiketi sökülmemiş ve yeniden satılabilir durumda olmalıdır.</li>
      <li>Cayma bildiriminden sonra ürünü 10 gün içinde, sipariş numaranızı ekleyerek tarafımıza gönderin. İade adresi: <?= $ph('company_address', 'İade adresi') ?>.</li>
      <li>İade kargo ücreti, ürün ayıplı değilse alıcıya aittir.</li>
      <li>Ürün bedeli, iade bildiriminizin bize ulaşmasından itibaren en geç 14 gün içinde ödeme yönteminize iade edilir.</li>
    </ul>

    <h2>Kişiye özel baskılı formalar</h2>
    <p>Sizin belirlediğiniz isim ve/veya numara ile baskı yapılan formalar kişiye özel üretildiğinden, Mesafeli Sözleşmeler Yönetmeliği’nin 15. maddesi uyarınca cayma hakkı kapsamı dışındadır. Bu ürünlerde yalnızca üretim veya baskı hatası (ayıp) durumunda değişim ya da iade yapılır.</p>

    <h2>Beden değişimi ve ayıplı ürün</h2>
    <p>Baskısız ürünlerde beden değişimi, stok durumuna göre 14 gün içinde yapılabilir. Ayıplı veya hatalı gönderilen ürünlerde kargo ücreti satıcıya aittir; ürünün fotoğrafıyla birlikte bize ulaşmanız yeterlidir.</p>

    <h2>İletişim</h2>
    <?= $seller() ?>
    <?php endif; ?>
    <p class="text-muted">İlgili belgeler: <a href="<?= e(url('/on-bilgilendirme-formu')) ?>">Ön Bilgilendirme Formu</a> · <a href="<?= e(url('/mesafeli-satis-sozlesmesi')) ?>">Mesafeli Satış Sözleşmesi</a> · <a href="<?= e(url('/iptal-ve-iade')) ?>">İptal ve İade</a></p>
  </div>
</article>
