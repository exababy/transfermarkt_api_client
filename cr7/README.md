# CR7.com.tr

Cristiano Ronaldo hakkında Türkçe, mobil öncelikli ve SEO uyumlu hayran sitesi: hayatı, kariyeri,
oynadığı takımlar, Transfermarkt istatistikleri, otomatik haberler, videolar, kupalar ve forma mağazası.

- **PHP 8.1+**, yalnızca **PDO** (MySQL/MariaDB canlıda, SQLite geliştirmede)
- **Hiçbir Composer bağımlılığı yoktur**, derleme adımı yoktur (kendi otomatik yükleyicisi vardır)
- Paylaşımlı hosting (cPanel, Apache + `.htaccess`, cron) için tasarlanmıştır

## İçindekiler

1. [Gereksinimler](#gereksinimler)
2. [Klasör yapısı](#klasör-yapısı)
3. [cPanel paylaşımlı hostinge kurulum](#cpanel-paylaşımlı-hostinge-kurulum)
4. [Zamanlanmış görevler (cron)](#zamanlanmış-görevler-cron)
5. [Klasör izinleri](#klasör-izinleri)
6. [Güncelleme](#güncelleme)
7. [Yerel geliştirme ve testler](#yerel-geliştirme-ve-testler)
8. [Güvenlik notları](#güvenlik-notları)

## Gereksinimler

| Bileşen | Sürüm / not |
| --- | --- |
| PHP | 8.1 veya üstü (8.3 ile test edildi) |
| PHP eklentileri | `pdo`, `pdo_mysql` (canlı) veya `pdo_sqlite` (yerel), `mbstring`, `dom`, `json`, `curl`, `gd` (FreeType ile), önerilen: `intl` |
| Veritabanı | MySQL 5.7+ veya MariaDB 10.3+ (`utf8mb4`) |
| Web sunucusu | Apache 2.4 + `mod_rewrite` (önerilen: `mod_headers`, `mod_expires`, `mod_deflate`) |
| Cron | cPanel “Cron Jobs” veya eşdeğeri |

## Klasör yapısı

```
cr7/
  public/        Belge kök dizini (document root): index.php, .htaccess, assets/, og/
  src/           Uygulama kodu (App\ isim alanı)
  templates/     PHP şablonları (layouts, partials, pages, admin, errors)
  config/        config.example.php (şablon) ve config.php (gerçek ayarlar, git dışı)
  database/      schema.mysql.sql, schema.sqlite.sql
  content/       Editoryal içerik dosyaları (biyografi, kariyer, kupalar …)
  storage/       cache/, logs/, locks/ (yazılabilir olmalı)
  bin/           install.php, cron.php, test.php (yalnızca komut satırı)
  tests/         Testler
  .htaccess      Belge kökü public/ yapılamıyorsa kullanılır
```

## cPanel paylaşımlı hostinge kurulum

1. **Dosyaları yükleyin.** `cr7/` klasörünün tamamını hesabınıza yükleyin (ör. `/home/KULLANICI/cr7`).
   Dosya Yöneticisi ile zip olarak yükleyip açabilir ya da SSH ile `git clone` kullanabilirsiniz.
2. **Belge kökünü ayarlayın.**
   - *Önerilen:* cPanel → **Alan Adları** (Domains) bölümünde `cr7.com.tr` için belge kökünü
     `/home/KULLANICI/cr7/public` olarak değiştirin.
   - *Belge kökü değiştirilemiyorsa:* `cr7/` klasörünün içeriğini `public_html/` içine koyun. Kökteki
     `.htaccess` tüm istekleri `public/` klasörüne yönlendirir ve `src/`, `config/`, `storage/`,
     `database/`, `bin/`, `templates/`, `content/`, `tests/` klasörlerine erişimi engeller.
3. **MySQL veritabanı oluşturun.** cPanel → **MySQL Veritabanları**: yeni bir veritabanı ve kullanıcı
   oluşturun, kullanıcıya veritabanı üzerinde **TÜM YETKİLER**i verin. Karşılaştırma (collation)
   olarak `utf8mb4_unicode_ci` önerilir.
4. **Ayar dosyasını hazırlayın.**
   ```bash
   cp config/config.example.php config/config.php
   ```
   `config/config.php` içinde en az şunları düzenleyin:
   - `app.url` → `https://cr7.com.tr` (sonunda `/` olmadan)
   - `app.secret` → en az 32 karakterlik rastgele bir değer (web kurulum anahtarı olarak da kullanılır)
   - `db.*` → 3. adımda oluşturduğunuz veritabanı bilgileri (`driver` = `mysql`)
   - isteğe bağlı: `cron.web_token`, `analytics.ga4_id`, `youtube.api_key`, `indexnow.key`
5. **Kurulumu çalıştırın** (tekrar çalıştırmak güvenlidir; mevcut verilere dokunmaz):
   - **SSH ile (önerilen):**
     ```bash
     cd ~/cr7
     php bin/install.php --admin=admin --password='GÜÇLÜ-BİR-ŞİFRE'
     ```
     `--password` verilmezse rastgele bir şifre üretilip ekrana yazılır. Mevcut yöneticinin şifresini
     sıfırlamak için `--reset-password` ekleyin.
   - **Tarayıcıdan:** `https://cr7.com.tr/kurulum?token=APP_SECRET_DEĞERİ` adresini açın, sunucu
     kontrollerini inceleyin, yönetici kullanıcı adı ve şifresini girin. Bu sayfa yalnızca hiç yönetici
     yokken ve doğru `app.secret` ile açılır; kurulumdan sonra 404 döner.
6. **Giriş yapın:** `https://cr7.com.tr/admin/giris`.
7. **HTTPS:** cPanel → **SSL/TLS Status** ile AutoSSL sertifikasını etkinleştirin. `public/.htaccess`
   (ve kökteki `.htaccess`) tüm trafiği `https://` ve `www` olmayan adrese 301 ile yönlendirir. SSL henüz
   hazır değilse bu iki kural bloğunu geçici olarak `#` ile devre dışı bırakın.

## Zamanlanmış görevler (cron)

Görevler: `tm-sync` (Transfermarkt, 6 saatte bir), `news-fetch` (30 dk), `news-publish` (10 dk),
`videos-sync` (3 saat). Tek bir cron satırı yeterlidir; zamanı gelen görevler otomatik çalışır ve
eşzamanlı çalışmayı engelleyen bir kilit (`storage/locks/cron.lock`) kullanılır.

cPanel → **Cron Jobs** → “Her 10 dakikada bir”:

```
*/10 * * * * /usr/bin/php /home/KULLANICI/cr7/bin/cron.php >/dev/null 2>&1
```

Elle çalıştırma:

```bash
php bin/cron.php              # zamanı gelen tüm görevler
php bin/cron.php news-fetch   # yalnızca bir görev (aralığı dolmuşsa)
php bin/cron.php all --force  # aralığı beklemeden hepsi
```

Komut satırı cron'u olmayan sunucularda `config.php` içinde `cron.web_token` değerini (32+ rastgele
karakter) ayarlayıp harici bir cron servisine şu adresi verin:
`https://cr7.com.tr/cron/WEB_TOKEN` (isteğe bağlı `?task=news-fetch`). Token ayarlı değilse adres 404 döner.

Son çalışmalar `cron_runs` tablosunda tutulur; günlükler `storage/logs/YYYY-AA-GG.log` dosyalarındadır.

## Klasör izinleri

| Yol | İzin |
| --- | --- |
| `storage/`, `storage/cache/`, `storage/logs/`, `storage/locks/` | 755 veya 775 (PHP yazabilmeli) |
| `public/og/` | 755 veya 775 (haber OG görselleri) |
| `config/config.php` | 640 veya 600 |
| Diğer dosyalar | dosyalar 644, klasörler 755 |

cPanel'de PHP genellikle hesap kullanıcısıyla çalıştığı için 755/644 yeterlidir.

## Güncelleme

1. Yedek alın: veritabanı (cPanel → phpMyAdmin → Dışa Aktar) ve `config/config.php`.
2. Yeni dosyaları yükleyin (`config/config.php` ve `storage/` içeriğinin üzerine yazmayın) ya da
   `git pull` yapın.
3. Şemayı ve seed verilerini güncellemek için kurulumu yeniden çalıştırın (idempotenttir):
   `php bin/install.php`
4. Gerekirse önbelleği temizleyin: yönetim paneli → İşlemler → “Önbelleği temizle” ya da
   `rm -f storage/cache/*.cache`.

## Yerel geliştirme ve testler

```bash
cd cr7
export CR7_DB_DRIVER=sqlite CR7_DB_PATH=/tmp/cr7.sqlite CR7_APP_URL=http://127.0.0.1:8080 CR7_DEBUG=1
php bin/install.php --password=admin12345
php -S 127.0.0.1:8080 -t public public/index.php
php bin/test.php            # tüm testler (bellek içi SQLite)
php bin/test.php Router     # adı eşleşen testler
```

`config/config.php` yoksa `config.example.php` kullanılır; ortam değişkenleri (`CR7_DB_DRIVER`,
`CR7_DB_PATH`, `CR7_DB_HOST`, `CR7_DB_PORT`, `CR7_DB_NAME`, `CR7_DB_USER`, `CR7_DB_PASS`,
`CR7_APP_URL`, `CR7_DEBUG`) ilgili ayarları ezer.

## Güvenlik notları

- Tüm SQL sorguları hazırlıklı ifadelerle (prepared statements) çalışır; şablonlarda çıktı `e()` ile kaçışlanır.
- Formlar CSRF belirteci taşır; yönetici girişi IP başına 15 dakikada 5 hatalı denemeyle sınırlandırılır,
  girişte oturum kimliği yenilenir, oturum çerezi `HttpOnly` + `SameSite=Lax` (+ HTTPS'te `Secure`).
- Her HTML yanıtında güvenlik başlıkları ve nonce tabanlı Content-Security-Policy gönderilir.
- Google Analytics yalnızca `analytics.ga4_id` ayarlıysa ve ziyaretçi KVKK çerez onayı verirse yüklenir.
- `app.debug` canlı ortamda **kapalı** olmalıdır; hatalar `storage/logs/` altına yazılır.

## Transfermarkt Senkronizasyonu

İstatistik, kariyer ve takım sayfalarının tüm verisi **Transfermarkt API**’sinden
(`transfermarkt.base_url`, varsayılan `https://tmapi-alpha.transfermarkt.technology/`) gelir.
İstekler `Accept-Language: tr-TR` başlığıyla yapılır; böylece turnuva, mevki ve sakatlık adları
Türkçe döner (ör. “UEFA Şampiyonlar Ligi”, “Santrafor”). Ayarlar `config/config.php` içindeki
`transfermarkt` bölümündedir: `player_id` (8198), `language`, `timeout` (sn), isteğe bağlı
`retries` (varsayılan 1) ve ek `headers`.

**`tm-sync` görevi** (6 saatte bir; `src/Cron/Tasks/TransfermarktSyncTask.php`) sırasıyla şu uç
noktaları okur ve tabloları doldurur:

| Uç nokta | Tablo |
|---|---|
| `player/8198` | `player` (mevki, ayak, boy, sözleşme, piyasa değerleri, portre) |
| `player/8198/performance-season` · `-club` · `-competition` | `stats_season`, `stats_club`, `stats_competition`, `stats_summary` (`career` = TM toplamı, `clubs` = 5 A takım kulübü, `national` = Portekiz A) |
| `player/8198/performance-game` (≈1.650 kayıt, oynamadığı maçlar dahil) | `games` |
| `transfer/history/player/8198` | `transfers` |
| `player/8198/market-value-history` | `market_values` |
| `player/8198/national-career-history` | `national_career` |
| `player/8198/injury` | `injuries` |
| `clubs?ids[]=…` (25’erli), `club/{id}`, `competitions?ids[]=…` | `clubs`, `competitions` (ad, logo, renk, slug) |

- Ham JSON yanıtları `api_cache` tablosunda saklanır: istatistikler 30 dk, kulüp/turnuva bilgileri
  7 gün taze sayılır; kulüp/turnuva satırları 30 günde bir yenilenir. API hata verirse (zaman
  aşımı, 5xx, bozuk yanıt) **eski önbellek** kullanılır; hiç veri yoksa görev hata verir ve mevcut
  veriler korunur.
- Tüm yazma işlemleri tek bir veritabanı işleminde (`Database::upsertMany`) yapılır; görev tekrar
  tekrar çalıştırılabilir (idempotent). API’den kalkan satırlar silinir; maç listesi yarıdan fazla
  küçülürse (kesik yanıt) eski maçlar korunur.
- Kariyer kulüplerinin adresleri sabittir: `/takimlar/sporting-cp`, `manchester-united`,
  `real-madrid`, `juventus`, `al-nassr`, `portekiz`. Manchester United’ın iki dönemi
  (2003–2009, 2021–2022) aynı sayfada ayrı ayrı gösterilir; dönem istatistikleri maç
  kayıtlarından hesaplanır. Anlatı metinleri `content/career.php` dosyasından gelir.
- İlk senkronizasyon yaklaşık 10 sn sürer (≈350 kulüp, 41 turnuva); sonraki çalıştırmalar
  önbellek sayesinde çok daha kısadır.

**Elle senkronizasyon:** Yönetim panelindeki Transfermarkt senkronizasyonu düğmesi (`POST /admin/islem/tm-sync`) ya da komut satırı:

```bash
php bin/cron.php tm-sync --force
```

Çıktı örneği: `1.653 maç, 179 sezon satırı, 11 takım, 41 turnuva, 12 transfer, 51 piyasa
değeri, 6 milli takım, 30 sakatlık; 351 kulüp ve 41 turnuva bilgisi güncellendi (API 26,
önbellek 0, eski önbellek 0, hata 0) – 9,2 sn`.

**Sorun giderme**

- *“Transfermarkt API yanıt vermedi ve önbellekte kullanılabilir veri yok”*: sunucunun dışarıya
  HTTPS isteği yapabildiğini kontrol edin (`curl -I https://tmapi-alpha.transfermarkt.technology/player/8198`);
  bazı paylaşımlı hostinglerde giden bağlantılar için destek talebi gerekir.
- *“Eksik veri: …”* ile biten mesaj: ilgili uç nokta geçici olarak yanıt vermedi; diğer
  tablolar güncellendi, eksik kısım bir sonraki çalıştırmada tamamlanır.
- Ayrıntılar `storage/logs/YYYY-AA-GG.log` dosyasında `transfermarkt` kanalıyla kayıtlıdır
  (her istek, süre ve boyut). Önbelleği tamamen sıfırlamak için `DELETE FROM api_cache;`
  çalıştırıp görevi `--force` ile yeniden başlatın.
- Sayfalar veri yokken de açılır (sıfırlar ve “ilk senkronizasyondan sonra” notu gösterilir).

## Haber Sistemi (Spin)

Haberler tamamen otomatik hazırlanır; **yapay zekâ kullanılmaz**. Kaynak metinler Türkçe eş anlamlı
kelime/ifade değiştirici (spinner) ve çoklu kaynak karıştırma ile yeniden yazılır, şablon cümlelerle
zenginleştirilir ve **asla birebir kopya yayınlanmaz**.

**Akış**

1. `news-fetch` (30 dakikada bir): `config/news-sources.php` içindeki kaynaklar okunur — Bing Haberler
   ve Google Haberler aramaları ile TRT Haber, Habertürk, CNN Türk, Sözcü, Ajansspor, Sporx
   (windows-1254), A Spor, Star, Takvim, NTV Spor (Atom) spor akışları. Başlığında ya da özetinde
   Cristiano Ronaldo geçen (Brezilyalı Ronaldo/Ronaldinho hariç), `news.max_age_hours` (48 saat) içindeki
   haberler `news_items` tablosuna `pending` olarak yazılır. Aynı adres (url_hash) ikinci kez alınmaz;
   son 72 saatteki haber/yazılarla aynı hikâyeyi anlatanlar (başlık benzerliği ≥ `news.similarity_threshold`
   veya başlık+özet kök örtüşmesi) `duplicate` + `cluster:<ana kayıt>` notuyla bir **kümeye** bağlanır.
   Google Haberler bağlantıları metin için açılamadığından yalnızca kümeleme kanıtı olarak tutulur
   (`skipped`, `evidence-only`).
2. `news-publish` (10 dakikada bir, her çalışmada en fazla 1 haber): günlük limit `news.daily_limit`
   (varsayılan **10**, İstanbul takvim günü), iki haber arası en az `news.min_interval_minutes` (60 dk) ve
   yayın penceresi `news.publish_window` (İstanbul saatiyle 07–23) kontrol edilir. Büyük, yeni ve metni
   alınabilen küme önce seçilir.
3. Metin: önce akıştaki tam metin, yoksa haber sayfası (JSON-LD `articleBody`, `<article>` paragrafları);
   reklam, "İlgili haber", büyük harfli ara başlık ve anahtar kelime listeleri ayıklanır. Görseller asla
   kaynaktan kullanılmaz; her yazı için 1200×630 OG görseli (GD, `public/og/`) üretilir.
4. Yazı: şablon giriş cümlesi + ana kaynağın cümleleri + kümedeki en fazla `spinner.max_sources` (3)
   kaynaktan tekrar etmeyen cümleler (bağlaçlarla) + oynadığı kulüp için arka plan paragrafı
   (`content/career.php`) + İstatistik cümleleri (Transfermarkt verisi) + kapanış cümlesi + tüm
   kaynaklara bağlantı veren **"Kaynak:"** satırı. Başlık eş anlamlılar ve başlık kalıplarıyla yeniden
   kurulur ve her kaynak başlığından farklı olmalıdır (benzerlik < 0,7).

**Spinner kuralları** (`src/Services/News/Spinner.php`, veri: `content/synonyms-tr.php`,
`content/news-templates.php`): tırnak içleri (“…”, "…", ‘…’), korunan isimler (kulüpler, turnuvalar,
kişiler; ek alsa bile: Al-Nassr’ın), sayılar, skorlar ve tarihler hiçbir zaman değiştirilmez; en uzun
eşleşme önce (8 kelimeye kadar) aranır; cümle başı büyük harfi Türkçe kurallarla korunur; cümle
ortasında büyük harfle yazılmış kelimeler (özel isim olabilir) ve kesme işaretli ekler atlanır;
"mı/mi/mu/mü" ve "da/de" ekleri yeni kelimeye göre uyumlanır; rastgelelik haberin url_hash değeriyle
tohumlandığı için aynı haber her seferinde aynı sonucu verir.

**Özgünlük eşiği**: yazının kelime 3'lülerinden (shingle) hiçbir kaynakta geçmeyenlerin oranı
`spinner.min_change_ratio` (varsayılan **%40**) altındaysa daha sert iki deneme daha yapılır (daha
fazla eş anlamlı, yalnızca yeterince değişmiş cümleler); yine de altında kalırsa kayıt `failed` +
`low-uniqueness` olur ve yayınlanmaz. En az `spinner.min_words` (180) kelime şartı vardır. Ronaldo'dan
söz etmeyen kaynak metinleri `off-topic` ile elenir. Özgünlük yüzdesi `posts.uniqueness` alanında tutulur.

**Ayarlar (yönetim paneli)**: `news.daily_limit`, `news.min_interval_minutes`, `news.index_spun_posts`
(kapatılırsa otomatik yazılar `noindex` olur ve site haritasına girmez), `spinner.min_change_ratio`,
`news.sources_disabled` (kapatılacak kaynak anahtarları), `indexnow.key`. Yeni kaynak eklemek için
`config/config.php` içinde `'news' => ['sources' => ['anahtar' => ['name' => '…', 'type' => 'rss',
'url' => 'https://…', 'filter' => true]]]` tanımlayın (aynı anahtar varsayılanı ezer).

**IndexNow**: `indexnow.key` (8–128 karakter, harf/rakam/tire) girildiğinde anahtar
`https://alanadiniz/indexnow-key.txt` adresinden sunulur ve her yeni yazı `api.indexnow.org` üzerinden
Bing/Yandex'e bildirilir. Yerel/test adreslerinden bildirim yapılmaz.

**Sayfalar**: `/haberler` (12'li sayfalama, `?sayfa=2`), `/haber/{slug}` (NewsArticle JSON-LD, OG görseli,
etiketler, kaynak kutusu, ilgili haberler, istatistik ve kulüp sayfası bağlantıları),
`/haberler/etiket/{slug}` (3'ten az yazılı etiketler `noindex`), `/rss.xml` (son 30 indekslenebilir yazı).

**Elle çalıştırma**: `php bin/cron.php news-fetch --force` ve `php bin/cron.php news-publish --force`
(`--force` görev aralığını atlar; günlük limit/aralık/pencere yine uygulanır). Yönetim panelindeki
"Şimdi yayınla" limitleri atlar ama özgünlük eşiğini atlamaz.

## Videolar

Video galerisi `/videolar` (kategori filtresi `?kategori=`, sayfalama `?sayfa=`, sayfa başına 24 video) ve
video sayfaları `/video/{slug}` adresinde yayınlanır. Videolar YouTube’da kalır; sitede yalnızca küçük resim
gösterilir, oynat düğmesine basıldığında **youtube-nocookie.com** oynatıcısı yüklenir (tıklamadan önce YouTube’a
çerez/istek gitmez). Her video sayfasında `VideoObject` yapılandırılmış verisi, galeri sayfalarında `ItemList`
ve tüm sayfalarda sayfa yolu (breadcrumb) bulunur.

**Kurulumda gelen videolar.** Yükleyici (`php bin/install.php`) UEFA, FIFA, Real Madrid, Manchester United,
Juventus, Al Nassr ve UR · Cristiano kanallarından seçilmiş 30 ikonik videoyu Türkçe başlık ve açıklamalarla
ekler (`src/Install/Seeders/VideoSeeder.php`). Tekrar çalıştırmak güvenlidir: yalnızca eksik videolar eklenir,
yönetim panelinde düzenlediğiniz videolara dokunulmaz.

**Otomatik senkronizasyon (`videos-sync`, 3 saatte bir).** Cron görevi aşağıdaki resmî kanalların herkese açık
RSS akışlarını okur (API anahtarı gerekmez, kanal başına son 15 video):

| Kanal | Kanal kimliği | Filtre |
|---|---|---|
| UR · Cristiano | `UCtxD0x6AuNNqdXO9Wp5GHew` | Hayır (tüm videolar) |
| Al Nassr FC | `UCHEQtltsiDd3p8ga-5nC-ow` | Evet |
| Federação Portuguesa de Futebol | `UCsIoK3XP-cVcpoCC_SdjZdg` | Evet |
| Real Madrid | `UCWV3obpZVGgJ3j9FVhEjF2Q` | Evet |
| Manchester United | `UC6yW44UGJJBvYTlfC7CRg2Q` | Evet |
| Juventus | `UCLzKhsxrExAC6yAdtZ-BOWw` | Evet |
| Sporting CP | `UCnJj6L93JX3Jrhzv81ayywA` | Evet |
| UEFA | `UCyGa1YEx9ST66rYrJTGIKOw` | Evet |
| FIFA | `UCpcTrCXblq78GZrTUTLWeBw` | Evet |

- *Filtre = Evet* olan kanallardan yalnızca başlığında (veya açıklamanın ilk paragrafında) Ronaldo / CR7 /
  Cristiano geçen videolar alınır; “Ronaldo Nazário” videoları elenir. Yalnızca Arapça başlıklı videolar alınmaz.
- Başka sitelerde oynatılmasına izin verilmeyen videolar (YouTube oEmbed 401) atlanır ve bir hafta boyunca tekrar
  denenmez.
- Kategoriler başlık ve açıklamadaki anahtar kelimelerle otomatik belirlenir (Türkçe, İngilizce, Portekizce,
  İspanyolca, İtalyanca, Arapça): **Goller** (gol, goal, frikik, hat-trick, rövaşata…), **Maç Özetleri** (özet,
  highlights, resumo…), **Röportajlar** (röportaj, interview, basın toplantısı…), **Antrenman** (antrenman,
  training, idman…), **Belgesel** (belgesel, documentary, film…); UR · Cristiano kanalının tüm videoları
  **UR · Cristiano** kategorisine, eşleşmeyenler **Genel** kategorisine girer. Shorts videoları da alınır.
- Var olan videolarda yalnızca izlenme sayısı (ve eksikse süre) güncellenir; panelde değiştirdiğiniz başlık,
  açıklama ve kategoriler korunur. Yeni videolar IndexNow anahtarı tanımlıysa Bing/Yandex’e bildirilir.

Elle çalıştırmak için: `php bin/cron.php videos-sync --force` (veya yönetim panelindeki “Video
senkronizasyonu” düğmesi).

**Kanal eklemek / kapatmak.** `config/config.php` içindeki `youtube.channels` listesi varsayılanlarla
birleştirilir; aynı kimlik varsayılanı ezer, `'enabled' => false` kanalı kapatır. Örnek:

```php
'youtube' => [
    'api_key' => '',
    'channels' => [
        ['id' => 'UCtxD0x6AuNNqdXO9Wp5GHew', 'name' => 'UR · Cristiano', 'filter' => false],
        ['id' => 'UCnJj6L93JX3Jrhzv81ayywA', 'name' => 'Sporting CP', 'filter' => true],
        ['id' => 'UCpcTrCXblq78GZrTUTLWeBw', 'enabled' => false],   // FIFA kanalını kapat
    ],
    'search_query' => 'Cristiano Ronaldo',
],
```

Bir kanalın kimliğini (`UC…`) bulmak için kanal sayfasının kaynağında `"externalId"` değerine bakabilir ya da
`https://www.youtube.com/feeds/videos.xml?channel_id=UC…` adresinin video listesi döndürdüğünü kontrol edebilirsiniz.

**İsteğe bağlı YouTube Data API v3.** Yönetim paneli → Ayarlar’dan (veya `youtube.api_key`) bir API anahtarı
girerseniz senkronizasyon ayrıca `youtube.search_query` ile son 14 günün videolarını arar (Türkçe sonuçlar,
Türkiye bölgesi, yalnızca yerleştirilebilir videolar) ve tüm yeni videoların süresini, izlenme sayısını ve
yerleştirme iznini API’den alır. Anahtar Google Cloud Console’da “YouTube Data API v3” etkinleştirilerek
oluşturulur; günlük ücretsiz kota (10.000 birim) 3 saatlik senkronizasyon için fazlasıyla yeterlidir
(bir çalıştırma ≈ 100–110 birim). Kota dolarsa sistem otomatik olarak RSS + oEmbed yöntemine döner.

**Elle video eklemek.** Yönetim paneli → Videolar → “Video ekle” alanına herhangi bir YouTube bağlantısını
(`watch?v=`, `youtu.be/`, `/shorts/`, `/embed/`, `/live/`) ya da 11 karakterlik video kimliğini yapıştırın.
API anahtarı yoksa başlık, kanal ve küçük resim YouTube oEmbed’den alınır (açıklamayı panelden ekleyebilirsiniz;
yayın tarihi yerine eklenme tarihi gösterilir). Aynı video ikinci kez eklenmez.

**Adresler.** Video adresleri başlıktan üretilir ve sonuna video kimliğinden kısa bir parça eklenir
(`/video/ronaldonun-juventusa-attigi-efsane-rovasata-golu-2018-cjlcr`). Başlık sonradan değişse bile kimlik parçası
aynı kaldığı için eski adresler yeni adrese 301 ile yönlenir.

## Forma Mağazası ve Ödeme

Mağaza sayfaları herkese açıktır ve arama motorlarında listelenir; **sipariş alma varsayılan olarak
kapalıdır** (ürün sayfalarında “Yakında satışta” gösterilir). Siparişler yönetim panelindeki
**Ödeme Ayarları** sayfasından açılır. Ödeme yöntemleri: **PayTR**, **iyzico**, **Shopier** ve
**Havale/EFT**.

> **Önemli:** Bu sitedeki formalar kulüplerin renklerinden esinlenen özgün çizimlerdir. Kulüp arması,
> sponsor logosu veya telifli fotoğraf kullanılmaz. Açıklamalarda “orijinal” ya da “lisanslı” ifadesi
> geçmez; ürünleriniz gerçekten öyleyse bunu yönetim panelinden kendiniz ekleyin.

### Ürünler

- **Örnek katalog:** Kurulum, `ProductSeeder` ile Ronaldo’nun oynadığı her kulübün ve dönemin formasını
  ekler. Toplam 11 forma vardır:
  - Sporting CP 2002–03 (#28)
  - Manchester United 2003–04, 2007–08 ve 2021–22
  - Real Madrid 2013–14 ve 2016–17
  - Juventus 2018–19
  - Al-Nassr 2025–26 iç saha ve deplasman
  - Portekiz Euro 2016 ve 2026
- **Ortak özellikler:** Fiyatlar 1.499,90 ile 2.299,90 TL arasındadır. Bedenler S–XXL ve 6–13 yaş
  çocuk bedenleridir. Her formada RONALDO 7 isim-numara baskısı seçeneği vardır (+250 TL).
- **Kurulumu tekrar çalıştırmak:** Seeder ürünleri adreslerine (slug) göre ekler. Var olan bir ürüne
  dokunmaz, bu yüzden panelde yaptığınız değişiklikler korunur.
- **Forma çizimleri:** Çizimler `public/assets/img/kits/{slug}.svg` (arka, RONALDO 7 baskılı) ve
  `{slug}-on.svg` (ön) dosyalarıdır. Paylaşım görselleri `public/og/forma-{slug}.png` olarak GD ile
  üretilir; eksik olanlar kurulumda yeniden oluşturulur.
- **Yönetim** (`/admin/urunler`): Ürünleri ekleyip düzenleyebilir, yayından kaldırabilir, sıralayabilir
  ve silebilirsiniz. Ürün formunda şunlar bulunur:
  - fiyat ve eski fiyat
  - yetişkin ve çocuk bedenleri
  - baskı ücreti
  - stok (boş bırakılırsa “sipariş üzerine” satılır, sayı girilirse her siparişte düşer)
  - fotoğraf yükleme (JPG/PNG/WebP, en fazla 2 MB)
  - renklerden forma çizimi üretme
- **Yüklenen fotoğraflar:** `public/uploads/products/` klasörüne rastgele bir adla kaydedilir. Dosya
  türü içeriğinden kontrol edilir. Klasörün `.htaccess` dosyası betik çalıştırılmasını engeller.
  Klasörün yazılabilir olması gerekir (`chmod 775`).

### Sipariş akışı

1. Ürün sayfasındaki form beden, baskı, adet, teslimat bilgileri, ödeme yöntemi ve iki onay ister:
   KVKK aydınlatma metni ile Ön Bilgilendirme Formu + Mesafeli Satış Sözleşmesi.
2. Form gönderildiğinde (`POST /forma/{slug}/siparis`) şu korumalar uygulanır:
   - CSRF denetimi
   - görünmez bir tuzak alanı (honeypot)
   - aynı IP’den saatte en fazla 5 sipariş
3. Fiyat sunucuda yeniden hesaplanır; formdan gelen tutarlar dikkate alınmaz. Toplam şöyle bulunur:
   (ürün fiyatı + baskı) × adet + kargo.
4. Sipariş `CR7-XXXXXX` numarasıyla kaydedilir ve 32 karakterlik gizli bir erişim anahtarı üretilir.
   Müşteri `/odeme/{anahtar}` adresine yönlendirilir.
5. Ödeme sonucu `/odeme/sonuc/{anahtar}` sayfasında, sipariş takibi `/siparis/{anahtar}` sayfasında
   görünür. Bu sayfalar `noindex` olarak işaretlenir ve önbelleğe alınmaz.
6. Sipariş alınınca ve ödeme onaylanınca, ayarlarda bir bildirim adresi girdiyseniz e-posta gönderilir
   (PHP `mail()`).
7. Siparişleri `/admin/siparisler` sayfasında yönetirsiniz:
   - durum, ödeme durumu ve yönteme göre filtreleme
   - sipariş numarası, telefon, ad ya da e-posta ile arama
   - durum değiştirme (Yeni → Onaylandı → Hazırlanıyor → Kargoda → Tamamlandı / İptal)
   - kargo takip numarası girme (müşteri sayfasında görünür)
   - “Havale ödemesi alındı” ile onaylama ve iade işaretleme

### Siparişleri açmak (`/admin/odeme-ayarlari`)

1. **Mağaza** bölümünü doldurun:
   - kargo ücreti
   - WhatsApp numarası (“Haber ver” bağlantısı için)
   - bildirim e-postası
2. **Satıcı bilgileri** bölümünü doldurun: unvan, adres, telefon, e-posta, vergi dairesi/no ve MERSİS.
   Bu bilgiler yasal sayfalarda görünür:
   - `/mesafeli-satis-sozlesmesi`
   - `/on-bilgilendirme-formu`
   - `/iptal-ve-iade`

   Boş bıraktığınız alanlar sayfalarda [köşeli parantez] içinde görünür. Metinler şablondur;
   yayına almadan önce bir hukuk danışmanına kontrol ettirin.
3. En az bir ödeme yöntemini **etkinleştirin ve bilgilerini girin**. Bir yöntem, ödeme sayfasında ancak
   hem açık hem de eksiksiz yapılandırılmış olduğunda görünür.
4. **Sipariş alınsın** kutusunu işaretleyin. Kullanılabilir hiçbir ödeme yöntemi yoksa mağaza kapalı
   kalır.

Gizli anahtarlar panelde maskelenmiş olarak (`••••••••abcd`) gösterilir. Alanı boş bırakarak
kaydederseniz kayıtlı değer korunur; silmek için “Kayıtlı değeri sil” kutusunu işaretleyin. Ödeme
yöntemlerinin ödeme sayfasındaki sırası `config.php` içindeki `shop.payment_methods` ile belirlenir.

| Yöntem | Panelden alınacak bilgiler | Sağlayıcı paneline girilecek adres | Test |
|---|---|---|---|
| **PayTR** (iFrame API) | Mağaza no, Mağaza parolası, Mağaza gizli anahtarı | **Bildirim URL:** `https://cr7.com.tr/odeme/paytr/bildirim` (PayTR Paneli › Destek & Kurulum › Ayarlar) | “Test modu” (`test_mode=1`) |
| **iyzico** (Ödeme Formu) | API anahtarı, Güvenlik anahtarı | Gerekmez. `callbackUrl` her istekte `https://cr7.com.tr/odeme/iyzico/donus` olarak gönderilir. | “Sandbox”: `sandbox-api.iyzipay.com` (sandbox-merchant.iyzipay.com anahtarlarıyla) |
| **Shopier** | API kullanıcı, API şifre, web sitesi indeksi (Entegrasyonlar › Modül Yönetimi) | **Geri Dönüş URL:** `https://cr7.com.tr/odeme/shopier/donus` (seçtiğiniz indeks için) | Shopier’de test ortamı yoktur; düşük tutarlı bir ürünle deneyin. |
| **Havale / EFT** | Banka, alıcı adı, IBAN (TR, mod-97 ile doğrulanır) | — | — |

### Ödeme sağlayıcıları — teknik ayrıntılar

Hiçbir SDK kullanılmaz; bağlantılar düz curl ve `hash_hmac` ile kurulur. Tüm imza karşılaştırmaları
`hash_equals` ile yapılır. Ödeme bildirimleri başka bir siteden gelen POST istekleridir: oturum
çerezi gelmediği için CSRF denetiminden muaftırlar ve siparişi oturumdan değil sipariş numarasından
bulurlar. Kimlikleri ödeme sağlayıcısının imzasıyla doğrulanır. Ödeme kaydı tek seferliktir: aynı
bildirim tekrar gelirse bir şey değişmez, ödenmiş bir sipariş sonradan “başarısız” sayılmaz. Gelen
tutar sipariş toplamıyla karşılaştırılır; uyuşmazsa sipariş ödenmiş sayılmaz. `payment_payload`
alanında sağlayıcı yanıtının yalnızca güvenli alanları saklanır; kart bilgisi ve imzalar saklanmaz.

- **PayTR iFrame API** — [1. adım](https://dev.paytr.com/iframe-api/iframe-api-1-adim),
  [2. adım](https://dev.paytr.com/iframe-api/iframe-api-2-adim)
  - **Token isteği:** `POST https://www.paytr.com/odeme/api/get-token` adresine gönderilir.
  - **paytr_token:**
    `base64(HMAC-SHA256(merchant_id.user_ip.merchant_oid.email.payment_amount.user_basket.no_installment.max_installment.currency.test_mode.merchant_salt, merchant_key))`
  - **user_basket:** `[[ad, "fiyat", adet]]` dizisinin JSON’unun base64 hâlidir.
  - **Tutar:** kuruş olarak gönderilir (`payment_amount`).
  - **Sipariş numarası:** `merchant_oid` yalnızca harf ve rakam içerebildiği için `CR7AB12CD`
    biçiminde gönderilir.
  - **Ödeme formu:** iframe `https://www.paytr.com/odeme/guvenli/{token}` adresinden açılır.
  - **Bildirim doğrulaması:** `base64(HMAC-SHA256(merchant_oid.merchant_salt.status.total_amount, merchant_key))`
  - **Bildirim yanıtı:** Sunucu yalnızca düz metin olarak `OK` döner; aynı bildirim tekrar gelirse de `OK`.
  - **Tutar kontrolü:** `payment_amount` sipariş toplamıyla karşılaştırılır.
- **iyzico Ödeme Formu** —
  [kimlik doğrulama](https://docs.iyzico.com/on-hazirliklar/kimlik-dogrulama/hmacsha256-kimlik-dogrulama),
  [başlatma](https://docs.iyzico.com/odeme-metotlari/odeme-formu/cf-entegrasyonu/cf-baslatma),
  [sorgulama](https://docs.iyzico.com/odeme-metotlari/odeme-formu/cf-entegrasyonu/cf-sorgulama),
  [yanıt imzası](https://docs.iyzico.com/en/advanced/response-signature-validation)
  - **Yetkilendirme başlığı:**
    `Authorization: IYZWSv2 base64("apiKey:…&randomKey:…&signature:" + hex(HMAC-SHA256(randomKey + uriPath + jsonBody, secretKey)))`
    Rastgele anahtar ayrıca `x-iyzi-rnd` başlığında gönderilir. Bu başlık, iyzico’nun resmi PHP
    SDK’sındaki `IyziAuthV2Generator` ile aynı sonucu verir.
  - **Başlatma isteği:** `POST /payment/iyzipos/checkoutform/initialize/auth/ecom`. `basketId` ve
    `conversationId` olarak sipariş numarası kullanılır.
  - **Fiyat biçimi:** SDK ile aynıdır, örneğin `1499.9`.
  - **Sepet:** Ürünler ve kargo satır olarak eklenir; satırların toplamı `price` değerine eşittir.
  - **TC kimlik:** TC kimlik numarası istenmez; `identityNumber` alanına iyzico’nun kabul ettiği
    `11111111111` yazılır.
  - **Ödeme formu:** `checkoutFormContent` betiği CSP nonce’u ile sayfaya basılır. Yedek olarak
    `paymentPageUrl` bağlantısı da gösterilir.
  - **Dönüş:** iyzico `token` değerini `/odeme/iyzico/donus` adresine gönderir. Sonuç sunucudan
    `POST /payment/iyzipos/checkoutform/auth/ecom/detail` ile sorgulanır. Ödeme kabulü için:
    - `paymentStatus=SUCCESS`
    - `fraudStatus=1`
    - `basketId` siparişle aynı
    - `price` sipariş toplamıyla aynı
    - yanıt imzası doğru: `paymentStatus:paymentId:currency:basketId:conversationId:paidPrice:price:token`
- **Shopier** — Shopier’in yeni REST API’si ([developer.shopier.com](https://developer.shopier.com/),
  Kişisel Erişim Anahtarı ile çalışır) yalnızca kendi Shopier mağazanızı yönetir (ürün, sipariş,
  webhook); harici bir sitedeki sipariş için ödeme sayfası oluşturamaz. Bu nedenle Shopier’in resmi
  PHP SDK’sında ([github.com/shopier/api](https://github.com/shopier/api) →
  [erkineren/shopier](https://github.com/erkineren/shopier)) kullanılan “Shopier ile Ödeme Al” API
  modülü tercih edildi. Bu modül API kullanıcı ve API şifre ile çalışır.
  - **Ödeme formu:** İmzalı form `https://www.shopier.com/ShowProduct/api_pay4.php` adresine
    otomatik gönderilir.
  - **Form imzası:** `base64(HMAC-SHA256(random_nr.platform_order_id.total_order_value.currency, api_secret))`.
    Para birimi kodu: 0 = TL.
  - **Dönüş imzası:** `base64(HMAC-SHA256(random_nr.platform_order_id, api_secret))`. Durum
    (`status`) değeri `success` ya da `failed` olur.
  - **Tutar:** Dönüş bilgisinde tutar yer almaz, ancak tutar giden formun imzasına dahil olduğu için
    değiştirilemez.
- **CSP:** Ödeme sayfaları, ilgili sağlayıcıya izin veren ek kurallarla (`Response::withCsp`) açılır:
  - PayTR: `frame-src https://www.paytr.com` ve bankanın 3-D Secure sayfası için `https:`
  - iyzico: `script/style/connect/font-src https://*.iyzipay.com`; ayrıca 3-D Secure için
    `frame-src` ve `form-action https:`
  - Shopier: `form-action https://www.shopier.com`

### Ayar anahtarları (`settings` tablosu, `config.php` değerlerini geçersiz kılar)

- **Mağaza:**
  - `shop.enabled` · `shop.orders_enabled` · `shop.shipping_fee` · `shop.whatsapp` · `shop.notify_email`
  - Yasal sayfalar için: `shop.company_name` · `shop.company_address` · `shop.company_phone` ·
    `shop.company_email` · `shop.company_tax_office` · `shop.company_tax_number` · `shop.company_mersis`
- **Ödeme:**
  - PayTR: `payment.paytr.{enabled,merchant_id,merchant_key,merchant_salt,test_mode}`
  - iyzico: `payment.iyzico.{enabled,api_key,secret_key,sandbox}`
  - Shopier: `payment.shopier.{enabled,api_key,api_secret,website_index}`
  - Havale/EFT: `payment.havale.{enabled,bank_name,account_holder,iban}`

### SEO

- **Mağaza sayfası:** `/forma-magazasi` ve kulüp filtreleri (`?takim=336` gibi) arama motorlarında
  listelenir; her birinin kendine ait başlığı, açıklaması, canonical adresi ve ItemList yapılandırılmış
  verisi vardır. Bilinmeyen bir kulüp filtresi 404 döner.
- **Ürün sayfaları:** Product yapılandırılmış verisi içerir:
  - marka olarak kulüp adı
  - Offer (fiyat, TRY)
  - stok durumu: sipariş kapalıyken `OutOfStock`, açıkken `PreOrder`/`InStock`
  - 14 günlük iade politikası
  - PNG ve SVG görseller

  Ayrıca `og:type=product` ve `product:price:*` etiketleri bulunur.
- **Site haritası:** Ürün site haritası için hazır olan `ProductRepository::forSitemap()` kullanılır.

Test: `php bin/test.php Shop` (PayTR, iyzico ve Shopier imzalarını openssl ile bağımsız hesaplanmış
örneklerle sınar; fiyatlandırma, doğrulama, sipariş kapalıyken 403 ve IP sınırını da kontrol eder).

## Yönetim Paneli

Yönetim paneli `https://cr7.com.tr/admin` adresindedir (arama motorlarına kapalıdır: `noindex` +
`X-Robots-Tag`, robots.txt'de `/admin` engelli). İlk yönetici `php bin/install.php --password=…` ile oluşturulur.

**Giriş ve güvenlik**

- Giriş: `/admin/giris`. Hata mesajları geneldir (“Kullanıcı adı veya şifre hatalı.”). Aynı IP'den
  15 dakikada 5 hatalı denemeden sonra giriş geçici olarak kilitlenir. Başarılı girişte oturum kimliği ve
  CSRF anahtarı yenilenir; giriş öncesi açılmak istenen panel sayfasına geri dönülür.
- Oturum 240 dakika işlem yapılmazsa kapanır (`app.session_idle_minutes`). Çıkış menüdeki **Çıkış** düğmesiyledir.
- Şifre değiştirme: **Şifre** (`/admin/sifre`) — mevcut şifre gerekir, yeni şifre en az 10 karakter.
  Şifre unutulursa sunucuda: `php bin/install.php --reset-password --password=YENI_SIFRE`.
- Tüm formlar CSRF korumalıdır; silme ve “Şimdi yayınla” işlemleri onay ister.

**Bölümler**

| Bölüm | Adres | Ne yapılır |
|---|---|---|
| Panel | `/admin` | Bugünkü otomatik yayın / günlük limit, toplam haber, kuyrukta bekleyen ve başarısız kayıtlar, video ve yeni sipariş sayıları; zamanlanmış görevlerin son çalışması, durumu ve mesajı (**Şimdi çalıştır**), son Transfermarkt senkronizasyonu, son 50 günlük satırı, **Önbelleği temizle**. |
| Haberler | `/admin/haberler` | Arama, durum (yayında / zamanlanmış / taslak) ve tür (otomatik / elle) filtreleri. Yeni haber ve düzenleme: başlık, adres (başlıktan otomatik), özet (≤ 500), HTML metin (kayıtta güvenli etiketlere temizlenir), etiketler, durum, indekslenme, yayın tarihi (İstanbul saati; ileri tarih = zamanlanmış), SEO başlığı. Paylaşım (OG) görseli kayıtta oluşturulur, başlık değişince yenilenir. Otomatik haberlerin özgünlük oranı, kaynak sayısı ve kaynak bağlantıları salt okunur gösterilir. |
| Haber Kuyruğu | `/admin/haber-kuyrugu` | Toplanan kaynak haberler: durum (bekliyor / başarısız / aynı hikâye / atlandı / yayınlandı), kaynak ve arama filtresi, hikâye kümeleri. **Şimdi yayınla** günlük limit, aralık ve yayın penceresini beklemeden yayınlar (özgünlük eşiği yine uygulanır) ve sonucu bağlantısıyla gösterir; **Atla** kaydı otomatik yayından çıkarır. |
| Videolar | `/admin/videolar` | YouTube bağlantısı (watch, youtu.be, shorts, embed) ya da video kimliğiyle ekleme; kategori, durum, öne çıkan filtreleri; başlık, açıklama, kategori, öne çıkarma ve durum (yayında / taslak / gizli) düzenleme; silme. Senkronizasyonla gelen bir videoyu kalıcı kaldırmak için “Gizli” durumunu kullanın. |
| Ayarlar | `/admin/ayarlar` | Günlük yayın limiti (1–50), iki yayın arası süre, otomatik haberlerin indekslenmesi, asgari özgünlük oranı (0,20–0,90), haber kaynaklarını açma/kapama, ana sayfa başlık/alt başlık/portre görseli, GA4 ölçüm kimliği (`G-…`), IndexNow anahtarı (**Anahtar üret**: 32 karakter), YouTube API anahtarı, web cron anahtarı ve cron satırları. |
| Mağaza | `/admin/siparisler`, `/admin/urunler`, `/admin/odeme-ayarlari` | Forma Mağazası ve Ödeme bölümüne bakın. |

Ayarlar veritabanındaki `settings` tablosunda tutulur ve `config/config.php` değerlerini geçersiz kılar;
boş bırakılan ana sayfa metinleri varsayılana döner. Gizli değerler (YouTube API anahtarı, web cron anahtarı)
hiçbir zaman tam gösterilmez: alan boş bırakılırsa kayıtlı değer korunur, “sil” kutusu değeri kaldırır.
Yeni üretilen web cron adresi yalnızca üretildiği anda tam gösterilir; kaybedilirse yeni anahtar üretin.

**Elle çalıştırılan işlemler** (`POST /admin/islem/{görev}`): `tm-sync`, `news-fetch`, `news-publish`,
`videos-sync` görevleri aralıkları beklenmeden (`Scheduler::run($görev, true)`) çalıştırılır; `cache-clear`
`storage/cache` önbelleğini temizler. Başka bir cron çalışması sürüyorsa görev “şu an çalıştırılamadı”
uyarısıyla döner. Uzun süren görevler (Transfermarkt senkronizasyonu) birkaç dakika sürebilir.

## SEO

Site, arama motorları için baştan uyumlu olacak şekilde kurgulanmıştır. Her sayfa denetleyicisi bir
`App\Core\Seo` nesnesi üretir; `templates/layouts/main.php` bu nesneden `<title>`, açıklama, kanonik adres,
`robots`, `hreflang` (tr + x-default), Open Graph, Twitter Card ve JSON-LD etiketlerini basar.

**Sayfa kuralları**

- Her sayfada **tek bir `<h1>`**, benzersiz Türkçe başlık (site eki ` | CR7.com.tr` hariç en fazla 60 karakter)
  ve 120–160 karakterlik açıklama bulunur (`tests/SiteTest.php` ana sayfa, Hayatı, Kupalar ve yasal sayfalar
  için bunu otomatik olarak denetler).
- **Kanonik adres** her zaman mutlak ve sorgu parametresizdir; sayfalı listeler kendi `?sayfa=N` adresine
  kanoniklenir ve `rel="prev/next"` bağlantıları verir.
- **Sayfa yolu (breadcrumb)** iç sayfalarda hem görsel olarak hem de `BreadcrumbList` JSON-LD olarak otomatik
  basılır (`Seo::$breadcrumbs`, "Ana Sayfa" kendiliğinden eklenir).
- Arama sonuçları (`/arama`), hata sayfaları ve hazırlık sayfaları `noindex,follow` olarak işaretlenir;
  bilinmeyen adresler gerçek **404** döner (soft-404 yoktur), sondaki `/` 301 ile temizlenir.
- Paylaşım görseli varsayılan olarak `public/assets/img/og-default.png` (1200×630); haberler kendi
  OG görsellerini kullanır.

**Yapılandırılmış veriler (JSON-LD)**

| Sayfa | Şemalar |
|---|---|
| Ana sayfa `/` | `WebSite` (+ `/arama?q={search_term_string}` arama eylemi), `Organization` (logo), `Person` |
| Hayatı `/hayati` | Ayrıntılı `Person` (doğum yeri, boy, kulüpler, ödüller, sameAs), `FAQPage` (tüm SSS), `BreadcrumbList` |
| Kupalar `/kupalar-ve-oduller` | İki `ItemList` (takım kupaları, bireysel ödüller), `Person`, `BreadcrumbList` |
| Hakkında / İletişim / Gizlilik | `AboutPage`, `ContactPage` (+ `ContactPoint`), `WebPage` |
| Haber, video, takım, ürün sayfaları | `NewsArticle`, `VideoObject`, `SportsTeam`, `Product` (ilgili modüller) |

`Person.sameAs`: Wikipedia (TR/EN), Wikidata (Q11571), Instagram, X, Facebook ve YouTube resmî hesapları.
Ana sayfadaki portre `site.portrait_url` ayarından, boşsa Transfermarkt profilinden gelir (bu durumda
"Fotoğraf: IMAGO / Transfermarkt" kaynak satırı gösterilir).

**Site haritaları**

| Adres | İçerik |
|---|---|
| `/sitemap.xml` | Dizin; URL içeren alt haritaları her biri için `lastmod` ile listeler |
| `/sitemap-pages.xml` | Ana sayfa, bölüm sayfaları (`/istatistikler/maclar` dahil), mağaza (açıksa) ve yasal sayfalar |
| `/sitemap-posts.xml` | İndekslenebilir tüm haberler, `image:image` ile |
| `/sitemap-news.xml` | Google Haberler: son 48 saatin haberleri (`news:publication` = CR7.com.tr, dil `tr`) |
| `/sitemap-videos.xml` | `video:video` (küçük resim, başlık, açıklama, `player_loc` gömme adresi, süre, yayın tarihi) |
| `/sitemap-clubs.xml` | `/takimlar` ve tüm `/takimlar/{slug}` sayfaları (logo görseliyle) |
| `/sitemap-products.xml` | Forma Mağazası ürünleri; mağaza kapalıyken **404** |

Haritalar `storage/cache` içinde 1 saat önbelleklenir (dizin ve Google Haberler haritası en fazla 15 dakika).
Süre `config.php` içinde `'sitemap' => ['cache_ttl' => 3600]` ile değiştirilebilir (`0` = önbellek yok);
yönetim panelindeki "Önbelleği temizle" işlemi haritaları anında yeniler. Bir modül eksikse ya da tablo
boşsa ilgili harita boş (ama geçerli) bir `<urlset>` döner ve dizinde listelenmez.

**robots.txt** (`/robots.txt`, dinamik): her şeye izin verir; `/admin`, `/cron`, `/kurulum`, `/arama`,
`/odeme`, `/siparis` yollarını engeller ve mutlak `Sitemap:` adresini (`app.url` + `/sitemap.xml`) bildirir.
`app.url` değerinin canlı alan adıyla (`https://cr7.com.tr`) aynı olması bu yüzden önemlidir.

**Yayına aldıktan sonra**

1. Google Search Console ve Bing Webmaster Tools'a `https://cr7.com.tr/sitemap.xml` adresini gönderin
   (Yandex Webmaster için de aynı adres kullanılır).
2. Google Haberler için `https://cr7.com.tr/sitemap-news.xml` adresini ayrıca ekleyebilirsiniz.
3. Yeni/güncellenen haber ve videolar IndexNow ile Bing ve Yandex'e otomatik bildirilir (`indexnow.key`).
4. Yapılandırılmış verileri https://search.google.com/test/rich-results ile kontrol edin.
