<?php
/**
 * /hakkinda — about the site, unofficial fan-site disclaimer, data sources and editorial policy.
 *
 * @var list<string> $newsSources names from SourceRegistry::all()
 * @var int $dailyLimit
 * @var string|null $email
 * @var bool $shopEnabled
 * @var string $updated 'Y-m-d'
 */

use App\Controllers\PageController;
?>
<header class="page-header">
  <div class="container container-narrow">
    <span class="eyebrow">Hakkımızda</span>
    <h1>CR7.com.tr hakkında</h1>
    <p class="lead">Cristiano Ronaldo’ya duyulan hayranlıkla hazırlanan, Türkçe ve bağımsız bir hayran sitesi.</p>
  </div>
</header>

<section class="section-sm static-page">
  <div class="container container-narrow">
    <div class="notice notice-warning" role="note">
      <strong class="notice-title">Resmî olmayan hayran sitesi</strong>
      <p>CR7.com.tr; Cristiano Ronaldo, temsilcileri, CR7 markası, oynadığı kulüpler (Sporting CP, Manchester United, Real Madrid, Juventus, Al-Nassr), Portekiz Futbol Federasyonu veya sponsorlarıyla hiçbir resmî bağlantısı olmayan, onlar tarafından onaylanmamış ya da desteklenmemiş bağımsız bir hayran sitesidir. Ronaldo’nun resmî hesaplarına <a href="https://www.instagram.com/cristiano/" rel="nofollow noopener" target="_blank">Instagram</a>, <a href="https://x.com/Cristiano" rel="nofollow noopener" target="_blank">X</a>, <a href="https://www.facebook.com/Cristiano" rel="nofollow noopener" target="_blank">Facebook</a> ve <a href="https://www.youtube.com/@cristiano" rel="nofollow noopener" target="_blank">YouTube</a> üzerinden ulaşabilirsiniz.</p>
    </div>

    <div class="prose">
      <h2>Bu site ne sunuyor?</h2>
      <p>CR7.com.tr, Cristiano Ronaldo hakkında Türkçe olarak bulunabilecek en kapsamlı arşivlerden biri olmayı hedefler. Sitede şunları bulabilirsiniz:</p>
      <ul>
        <li><a href="<?= e(url('/hayati')) ?>">Hayatı</a>: Madeira’daki çocukluğundan bugüne, kaynaklarla doğrulanmış ayrıntılı bir biyografi.</li>
        <li><a href="<?= e(url('/kariyer')) ?>">Kariyeri</a> ve <a href="<?= e(url('/takimlar')) ?>">oynadığı takımlar</a>: kulüp kulüp maç, gol ve asist sayıları, transferler.</li>
        <li><a href="<?= e(url('/istatistikler')) ?>">İstatistikler</a>: sezon, turnuva ve maç bazında güncel veriler.</li>
        <li><a href="<?= e(url('/kupalar-ve-oduller')) ?>">Kupalar ve ödüller</a>: takım kupaları, bireysel ödüller ve rekorlar.</li>
        <li><a href="<?= e(url('/haberler')) ?>">Haberler</a> ve <a href="<?= e(url('/videolar')) ?>">videolar</a>: Ronaldo ile ilgili güncel gelişmeler.</li>
        <?php if ($shopEnabled): ?><li><a href="<?= e(url('/forma-magazasi')) ?>">Forma Mağazası</a>: Ronaldo’nun forma giydiği takımların formaları.</li><?php endif; ?>
      </ul>

      <h2>Veri kaynaklarımız</h2>
      <ul>
        <li><strong>İstatistikler:</strong> Maç, gol, asist, dakika, transfer, piyasa değeri ve sakatlık verileri <a href="https://www.transfermarkt.com.tr/cristiano-ronaldo/profil/spieler/8198" rel="nofollow noopener" target="_blank">Transfermarkt</a> verilerinden otomatik olarak alınır ve gün içinde düzenli aralıklarla güncellenir. Transfermarkt’ın sayımı genç milli takım maçlarını da içerdiği için toplamlar başka kaynaklardaki sayılardan birkaç maç ya da gol farklı olabilir.</li>
        <li><strong>Biyografi, kupalar ve rekorlar:</strong> Wikipedia, UEFA, FIFA, Guinness Dünya Rekorları, kulüplerin ve federasyonların resmî açıklamaları ile güvenilir haber kuruluşları kullanılarak hazırlanır; her sayfanın sonunda kaynak listesi ve son gözden geçirme tarihi yer alır.</li>
        <li><strong>Videolar:</strong> YouTube üzerinde yayımlanan videolar, YouTube’un yerleştirme özelliğiyle gösterilir. Videoların tüm hakları sahiplerine aittir; videolar sunucularımızda barındırılmaz.</li>
        <li><strong>Portre fotoğrafı:</strong> Ana sayfadaki portre, aksi belirtilmedikçe Transfermarkt profilinden alınır (Fotoğraf: IMAGO / Transfermarkt).</li>
      </ul>

      <h2>Haber yayın politikası</h2>
      <p>Sitedeki haberler, kamuya açık haber kaynaklarından otomatik olarak toplanır; <strong>haberler kaynak gösterilerek derlenir ve yeniden yazılır.</strong> Bu süreçte nasıl çalıştığımızı açıkça paylaşmak istiyoruz:</p>
      <ul>
        <li>Haberler, editör tarafından tek tek yazılmaz; Türkçe eş anlamlı kelime ve kalıplara dayanan otomatik bir yeniden yazım sistemiyle hazırlanır. Aynı konuyu işleyen birden fazla kaynak birleştirilebilir.</li>
        <li>Doğrudan alıntılar, sayılar, tarihler, skorlar ve özel isimler değiştirilmez.</li>
        <li>Her haberin sonunda, haberin dayandığı kaynakların bağlantıları yer alır. Kaynağına göre yeterince özgünleştirilemeyen içerikler yayımlanmaz.</li>
        <li>Günde en fazla <?= (int) $dailyLimit ?> haber yayımlanır; amaç her gelişmeyi değil, Ronaldo ile ilgili önemli gelişmeleri aktarmaktır.</li>
        <li>Otomatik yeniden yazım nedeniyle anlatımda hatalar olabilir. Önemli bir bilgi için lütfen haberin sonundaki özgün kaynağa başvurun.</li>
      </ul>
      <?php if ($newsSources !== []): ?>
      <p>Haber taramasında kullanılan kaynaklar: <?= e(implode(', ', $newsSources)) ?>.</p>
      <?php endif; ?>

      <h2>Düzeltme ve kaldırma talepleri</h2>
      <p>Bir bilgide hata olduğunu düşünüyorsanız ya da hak sahibi olduğunuz bir içeriğin sitede yer almasını istemiyorsanız <a href="<?= e(url('/iletisim')) ?>">iletişim sayfasındaki</a> adres<?php if ($email !== null): ?> (<a href="mailto:<?= e($email) ?>"><?= e($email) ?></a>)<?php endif; ?> üzerinden bize yazın. Talepleri en kısa sürede inceler, haklı bulunan düzeltmeleri ve kaldırmaları gecikmeden yaparız.</p>

      <?php if ($shopEnabled): ?>
      <h2>Forma Mağazası hakkında</h2>
      <p>Forma Mağazası, sitenin bağımsız bir bölümüdür. Mağazadaki ürün görselleri temsilîdir; site, kulüplerin, Cristiano Ronaldo’nun ya da spor markalarının resmî satış noktası değildir. Sipariş ve ödeme süreçlerinde işlenen kişisel veriler için <a href="<?= e(url('/gizlilik-politikasi')) ?>">Gizlilik Politikası</a>’nı inceleyebilirsiniz.</p>
      <?php endif; ?>

      <h2>Marka ve telif hakları</h2>
      <p>Cristiano Ronaldo’nun adı ve “CR7”, ilgili hak sahiplerine ait olabilir. Kulüp adları, logolar ve diğer markalar yalnızca bilgilendirme amacıyla, sahiplerine atıf yapılarak kullanılır. Sitedeki özgün metinler ve tasarım CR7.com.tr’ye aittir.</p>

      <p class="meta">Son güncelleme: <time datetime="<?= e($updated) ?>"><?= e(PageController::modifiedLabel($updated)) ?></time></p>
    </div>
  </div>
</section>
