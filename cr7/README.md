# CR7.com.tr

Cristiano Ronaldo hakkında Türkçe, mobil öncelikli, masaüstünde de tam uyumlu ve SEO kurallarına göre
hazırlanmış bağımsız hayran sitesi.

| Bölüm | Adres | İçerik |
| --- | --- | --- |
| Hayatı | `/hayati` | Biyografi, hızlı bilgiler, zaman çizelgesi, sık sorulan sorular |
| Kariyer | `/kariyer` | Altyapıdan Al-Nassr'a kulüp kulüp kariyer, transferler, piyasa değeri, milli takım |
| Takımlar | `/takimlar`, `/takimlar/{kulüp}` | Oynadığı her kulüp ve Portekiz için ayrı sayfa |
| İstatistikler | `/istatistikler`, `/istatistikler/maclar` | Transfermarkt verisiyle sezon, kulüp, turnuva ve maç maç istatistikler |
| Kupalar | `/kupalar-ve-oduller` | Takım kupaları, bireysel ödüller, rekorlar |
| Videolar | `/videolar`, `/video/{slug}` | Resmî YouTube kanallarından otomatik ve elle eklenen videolar |
| Haberler | `/haberler`, `/haber/{slug}` | Otomatik haberler: günde en fazla 10, eş anlamlı değiştirme + çoklu kaynak karıştırma, **yapay zekâ yok**, birebir kopya yok |
| Forma Mağazası | `/forma-magazasi`, `/forma/{slug}` | Herkese açık ve indekslenen mağaza; **sipariş alma varsayılan olarak kapalı**; PayTR, iyzico, Shopier, Havale/EFT |
| Yönetim paneli | `/admin` | Haberler, haber kuyruğu, videolar, siparişler, ürünler, ödeme ve site ayarları |

Teknik özet:

- **PHP 8.1+**, veritabanı erişimi yalnızca **PDO** ile (canlıda MySQL/MariaDB, geliştirmede SQLite).
- **Composer paketi yok**, ön yüz çatısı yok, derleme adımı yok; kendi otomatik yükleyicisi vardır.
- Paylaşımlı hosting (cPanel, Apache + `.htaccess`, cron) için tasarlanmıştır.

## İçindekiler

1. [Gereksinimler](#gereksinimler)
2. [Klasör yapısı](#klasör-yapısı)
3. [cPanel paylaşımlı hostinge kurulum](#cpanel-paylaşımlı-hostinge-kurulum)
4. [Yapılandırma (config.php ve panel ayarları)](#yapılandırma-configphp-ve-panel-ayarları)
5. [Zamanlanmış görevler (cron)](#zamanlanmış-görevler-cron)
6. [Hayatı, Kariyer ve Kupalar içerik dosyaları](#hayatı-kariyer-ve-kupalar-içerik-dosyaları)
7. [Transfermarkt senkronizasyonu ve istatistikler](#transfermarkt-senkronizasyonu-ve-istatistikler)
8. [Haber sistemi (spin)](#haber-sistemi-spin)
9. [Videolar](#videolar)
10. [Forma Mağazası ve ödeme](#forma-mağazası-ve-ödeme)
11. [Yönetim paneli](#yönetim-paneli)
12. [SEO](#seo)
13. [Arama motorlarına kayıt (Google, Bing, Yandex) ve IndexNow](#arama-motorlarına-kayıt-google-bing-yandex-ve-indexnow)
14. [Güvenlik notları](#güvenlik-notları)
15. [Klasör izinleri](#klasör-izinleri)
16. [Güncelleme ve yedekleme](#güncelleme-ve-yedekleme)
17. [Yerel geliştirme ve testler](#yerel-geliştirme-ve-testler)
18. [Sorun giderme](#sorun-giderme)

## Gereksinimler

| Bileşen | Sürüm / not |
| --- | --- |
| PHP | 8.1 veya üstü (8.3 ile test edildi) |
| PHP eklentileri | `pdo`, `pdo_mysql` (canlı) veya `pdo_sqlite` (yerel), `mbstring`, `dom`, `json`, `curl`, `gd` (FreeType desteğiyle), `fileinfo` (önerilen) |
| Veritabanı | MySQL 5.7+ veya MariaDB 10.3+ (`utf8mb4`); MariaDB 10.11 ile test edildi |
| Web sunucusu | Apache 2.4 + `mod_rewrite` (önerilen: `mod_headers`, `mod_expires`, `mod_deflate`) |
| Cron | cPanel “Cron Jobs” ya da harici bir web cron servisi |
| Dış bağlantı | Sunucu dışarıya HTTPS isteği yapabilmeli (Transfermarkt API, haber akışları, YouTube) |

## Klasör yapısı

```
cr7/
  public/        Belge kök dizini (document root): index.php, .htaccess, assets/, og/, uploads/
  src/           Uygulama kodu (App\ isim alanı): Core, Controllers, Repositories, Services, Support, Cron, Install
  templates/     PHP şablonları (layouts, partials, pages, admin, errors, install)
  config/        config.example.php (şablon), news-sources.php (haber kaynakları), config.php (gerçek ayarlar, git dışı)
  database/      schema.mysql.sql, schema.sqlite.sql
  content/       Editoryal içerik: biography.php, career.php, honours.php, synonyms-tr.php, news-templates.php
  storage/       cache/, logs/, locks/ (PHP yazabilmeli)
  bin/           install.php, cron.php, test.php (yalnızca komut satırı)
  tests/         Testler ve test verileri
  routes.php     Tüm adresler
  .htaccess      Yalnızca belge kökü public/ yapılamıyorsa kullanılır
```

## cPanel paylaşımlı hostinge kurulum

1. **PHP sürümünü seçin.** cPanel → **MultiPHP Manager** (ya da “Select PHP Version”) bölümünde alan adı
   için PHP 8.1 veya üstünü seçin. “Extensions” listesinde `pdo_mysql`, `mbstring`, `dom`, `curl`, `gd`,
   `fileinfo` işaretli olmalıdır.
2. **Dosyaları yükleyin.** `cr7/` klasörünün tamamını hesabınıza yükleyin (ör. `/home/KULLANICI/cr7`).
   Dosya Yöneticisi ile zip olarak yükleyip açabilir ya da Terminal/SSH ile `git clone` kullanabilirsiniz.
3. **Belge kökünü ayarlayın.**
   - *Önerilen:* cPanel → **Alan Adları** (Domains) → `cr7.com.tr` → belge kökünü
     `/home/KULLANICI/cr7/public` yapın.
   - *Belge kökü değiştirilemiyorsa:* `cr7/` klasörünün içeriğini `public_html/` içine koyun. Kökteki
     `.htaccess` tüm istekleri `public/` klasörüne yönlendirir; `src/`, `config/`, `storage/`, `database/`,
     `bin/`, `templates/`, `content/`, `tests/` klasörlerine ve `routes.php`, `README.md` dosyalarına
     dışarıdan erişimi engeller, `/public/...` adreslerini 301 ile köke taşır.
4. **MySQL veritabanı oluşturun.** cPanel → **MySQL Veritabanları**: yeni bir veritabanı (ör.
   `KULLANICI_cr7`) ve kullanıcı oluşturun, kullanıcıyı veritabanına **TÜM YETKİLER** ile ekleyin.
   Karşılaştırma (collation) olarak `utf8mb4_unicode_ci` önerilir; tablolar zaten bu ayarla oluşturulur.
5. **Ayar dosyasını hazırlayın** (Dosya Yöneticisi'nde kopyalayıp düzenleyebilir ya da Terminal'de):
   ```bash
   cd ~/cr7
   cp config/config.example.php config/config.php
   php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'   # app.secret için rastgele değer üretir
   ```
   `config/config.php` içinde en az şunları düzenleyin:
   - `app.url` → `https://cr7.com.tr` (sonunda `/` olmadan; alt klasör kurulumunda `https://alan.com/cr7`)
   - `app.secret` → yukarıdaki komutun ürettiği değer (en az 16, önerilen 64 karakter; web kurulum anahtarıdır)
   - `app.contact_email` → iletişim adresi (iletişim sayfası ve yasal metinlerde görünür)
   - `db.host`, `db.database`, `db.username`, `db.password` → 4. adımdaki bilgiler (`db.driver` = `mysql`;
     cPanel'de `host` genellikle `localhost`'tur)
6. **Kurulumu çalıştırın.** Kurulum tabloları oluşturur, yöneticiyi ekler, 11 forma ve 30 seçkin videoyu
   yükler, paylaşım görsellerini üretir. Tekrar çalıştırmak güvenlidir; mevcut verilere dokunmaz.
   - **Terminal/SSH ile (önerilen):**
     ```bash
     cd ~/cr7
     php bin/install.php --admin=admin --password='GÜÇLÜ-BİR-ŞİFRE'
     ```
     `--password` verilmezse rastgele bir şifre üretilip ekrana yazılır. Var olan yöneticinin şifresini
     değiştirmek için: `php bin/install.php --reset-password --password='YENİ-ŞİFRE'`.
   - **Tarayıcıdan (SSH yoksa):** `https://cr7.com.tr/kurulum?token=APP_SECRET_DEĞERİ` adresini açın,
     sunucu kontrollerini inceleyin, yönetici kullanıcı adını ve şifresini (en az 10 karakter) girin. Bu sayfa
     yalnızca hiç yönetici yokken ve doğru `app.secret` ile açılır; `app.secret` hâlâ `CHANGE_ME` ise 403,
     kurulumdan sonra 404 döner.
7. **İlk verileri çekin** (isteğe bağlı; cron zaten birkaç dakika içinde çalıştırır):
   ```bash
   php bin/cron.php all --force
   ```
   Transfermarkt istatistikleri (≈10 sn), haberler, ilk otomatik haber ve YouTube videoları gelir.
8. **Cron'u kurun:** [Zamanlanmış görevler](#zamanlanmış-görevler-cron).
9. **Giriş yapın:** `https://cr7.com.tr/admin/giris`. Ayarlar sayfasında Analytics, IndexNow, arama
   motoru doğrulama kodları ve web cron anahtarını; Ödeme Ayarları sayfasında satıcı bilgilerini girin.
10. **HTTPS:** cPanel → **SSL/TLS Status** ile AutoSSL sertifikasını etkinleştirin. `public/.htaccess`
    (ve kökteki `.htaccess`) tüm trafiği `https://` ve `www` olmayan adrese 301 ile yönlendirir. SSL henüz
    hazır değilse bu iki kural bloğunu geçici olarak `#` ile devre dışı bırakın.

## Yapılandırma (config.php ve panel ayarları)

Ayarlar iki katmanlıdır:

1. **`config/config.php`** — sunucuya özel ve gizli değerler (veritabanı, `app.secret`, `app.url` …). Dosya
   yoksa `config/config.example.php` kullanılır. Ortam değişkenleri `CR7_DB_DRIVER`, `CR7_DB_PATH`,
   `CR7_DB_HOST`, `CR7_DB_PORT`, `CR7_DB_NAME`, `CR7_DB_USER`, `CR7_DB_PASS`, `CR7_APP_URL`, `CR7_DEBUG`
   ilgili değerleri ezer.
2. **Yönetim paneli** — `config.example.php` içinde `[panel]` ile işaretli anahtarlar panelden de
   değiştirilebilir. Panelde kaydedilen değer veritabanındaki `settings` tablosuna yazılır ve
   `config.php`'deki değerin önüne geçer.

| Bölüm | Önemli anahtarlar |
| --- | --- |
| `app` | `url`, `secret`, `debug` (canlıda `false`), `timezone` (`Europe/Istanbul`), `contact_email`, `trust_proxy` (Cloudflare/vekil sunucu arkasında `true`), `trusted_proxies` (vekil başlıklarına yalnızca bu IP/CIDR'lerden gelen isteklerde güvenilir; boş = Cloudflare IP aralıkları; aynı sunucudaki nginx için `['127.0.0.1', '::1']`), `session_idle_minutes` |
| `db` | `driver` (`mysql`/`sqlite`), `host`, `port`, `database`, `username`, `password`, `charset`, `sqlite_path` |
| `transfermarkt` | `base_url`, `player_id` (8198), `language` (`tr-TR`), `timeout`, `retries` |
| `news` | `daily_limit` (10), `min_interval_minutes` (60), `publish_window` ([7, 23]), `max_age_hours` (48), `keywords`, `similarity_threshold`, `index_spun_posts`, `sources`, `sources_disabled` |
| `spinner` | `enabled`, `min_change_ratio` (0,40), `mix_sources`, `max_sources` (3), `min_words` (180) |
| `shop` | `enabled`, `orders_enabled` (**false**), `shipping_fee`, `whatsapp`, `notify_email`, `payment_methods`, `company_*` (satıcı bilgileri) |
| `payment` | `paytr`, `iyzico`, `shopier`, `havale` alt bölümleri |
| `youtube` | `api_key` (isteğe bağlı), `channels`, `search_query` |
| `site` | `hero_title`, `hero_subtitle`, `portrait_url` |
| `seo` | `google_verification`, `bing_verification`, `yandex_verification` |
| `sitemap` | `cache_ttl` (3600 sn; 0 = önbellek yok) |
| `indexnow`, `cron`, `analytics` | `key`, `web_token`, `ga4_id` |

## Zamanlanmış görevler (cron)

| Görev | Aralık | Ne yapar |
| --- | --- | --- |
| `tm-sync` | 6 saat | Transfermarkt istatistiklerini günceller |
| `news-fetch` | 30 dk | Haber kaynaklarını tarar, kuyruğa ekler, hikâyeleri kümeler |
| `news-publish` | 10 dk | Uygunsa bir haber yazıp yayınlar (günlük limit, aralık ve yayın penceresi içinde) |
| `videos-sync` | 3 saat | YouTube kanallarından yeni videoları alır |
| `shop-expire` | 30 dk | Stoğu sınırlı ürünlerde ödenmemiş siparişleri iptal edip stoğu geri ekler (kartla ödeme 2 saat, havale/EFT 72 saat sonra; Shopier kontrolü bekleyenler hariç) |

Tek bir cron satırı yeterlidir: her çalışmada yalnızca zamanı gelen görevler çalışır, eşzamanlı çalışmayı
bir kilit dosyası (`storage/locks/cron.lock`) engeller.

**cPanel → Cron Jobs** → “Common Settings: Once Per Ten Minutes (*/10)” seçip komuta şunu yazın
(`KULLANICI` yerine hesap adınızı; PHP yolu farklıysa cPanel'in gösterdiği yolu, ör.
`/usr/local/bin/php` veya `/opt/cpanel/ea-php83/root/usr/bin/php`, kullanın):

```
*/10 * * * * /usr/bin/php /home/KULLANICI/cr7/bin/cron.php >/dev/null 2>&1
```

Panelde Ayarlar → **Zamanlanmış görevler** bölümü bu satırı sunucudaki gerçek yolla hazır gösterir.

Komut satırından elle çalıştırma:

```bash
php bin/cron.php              # zamanı gelen tüm görevler
php bin/cron.php news-fetch   # yalnızca bir görev (aralığı dolmuşsa)
php bin/cron.php all --force  # aralığı beklemeden hepsi
php bin/cron.php --help       # görev listesi
```

**Web cron (komut satırı cron'u olmayan sunucular için).** Yönetim paneli → Ayarlar → Zamanlanmış görevler
→ **Anahtar üret** düğmesine basın; tam adres yalnızca o anda gösterilir:

```
https://cr7.com.tr/cron/WEB_CRON_ANAHTARI                    # zamanı gelen görevler
https://cr7.com.tr/cron/WEB_CRON_ANAHTARI?task=news-fetch    # tek görev
https://cr7.com.tr/cron/WEB_CRON_ANAHTARI?task=all&force=1   # aralığı beklemeden hepsi
```

Bu adresi cron-job.org gibi bir servise 10 dakikada bir çağrılacak şekilde verin ya da başka bir sunucuda
`*/10 * * * * curl -fsS "https://cr7.com.tr/cron/WEB_CRON_ANAHTARI" >/dev/null 2>&1` satırını kullanın.
Anahtar `config.php` içindeki `cron.web_token` ile de verilebilir (panelde kaydedilen anahtar önceliklidir).
Anahtar tanımlı değilse ya da yanlışsa adres 404 döner; paneldeki “Web cron'u kapat” kutusu anahtarı siler.

Son çalışmalar panelin ana sayfasında ve `cron_runs` tablosunda, ayrıntılar
`storage/logs/YYYY-AA-GG.log` dosyalarında görülür.

## Hayatı, Kariyer ve Kupalar içerik dosyaları

Editoryal metinler veritabanında değil, `content/` altındaki PHP dosyalarındadır (kaynakları belirtilmiş,
Ekim 2026 itibarıyla doğrulanmış Türkçe içerik):

| Dosya | Kullanıldığı sayfalar |
| --- | --- |
| `content/biography.php` | `/hayati`: giriş (`lead`), hızlı bilgiler, bölümler, zaman çizelgesi, 27 soruluk SSS (`FAQPage`), kaynaklar |
| `content/career.php` | `/kariyer`, `/takimlar/{kulüp}`: kulüp dönemleri, altyapı, milli takım anlatıları; haberlerin kulüp arka plan paragrafları |
| `content/honours.php` | `/kupalar-ve-oduller`: takım kupaları, bireysel ödüller, rekorlar |
| `content/synonyms-tr.php` | Haber spinner'ının Türkçe eş anlamlı grupları (1.077 grup) ve korunan isimler |
| `content/news-templates.php` | Haberlerin giriş, bağlam, kapanış ve başlık kalıpları |

Bir bilgiyi güncellediğinizde dosyadaki `last_reviewed` tarihini de değiştirin; sayfaların “Son güncelleme”
tarihi ve site haritasındaki `lastmod` buradan gelir. HTML alanlarında yalnızca `p`, `h3`, `ul`, `li`, `strong`,
`em`, `a`, `blockquote`, `table` kullanın. Değişiklikten sonra `php -l content/biography.php` ile sözdizimini
kontrol edin. Hakkında, İletişim ve Gizlilik sayfalarının revizyon tarihleri `PageController::REVISED`
sabitindedir.

Site içi arama (`/arama?q=`) bu içerik bölümlerinde, haberlerde ve videolarda arar; Türkçe büyük/küçük harf
farkını gözetmez (“şampiyonlar” → “Şampiyonlar Ligi”). Arama sonuçları `noindex,follow` olarak işaretlidir.

## Transfermarkt senkronizasyonu ve istatistikler

İstatistik, kariyer ve takım sayfalarının tüm verisi **Transfermarkt API**'sinden
(`transfermarkt.base_url`, varsayılan `https://tmapi-alpha.transfermarkt.technology/`) gelir.
İstekler `Accept-Language: tr-TR` başlığıyla yapılır; böylece turnuva, mevki ve sakatlık adları
Türkçe döner (ör. “UEFA Şampiyonlar Ligi”, “Santrafor”).

**`tm-sync` görevi** (6 saatte bir) sırasıyla şu uç noktaları okur ve tabloları doldurur:

| Uç nokta | Tablo |
|---|---|
| `player/8198` | `player` (mevki, ayak, boy, sözleşme, piyasa değerleri, portre) |
| `player/8198/performance-season` · `-club` · `-competition` | `stats_season`, `stats_club`, `stats_competition`, `stats_summary` (`career` = TM toplamı, `clubs` = 5 A takım kulübü, `national` = Portekiz A) |
| `player/8198/performance-game` (≈1.650 kayıt, oynamadığı maçlar dahil) | `games` |
| `transfer/history/player/8198` | `transfers` |
| `player/8198/market-value-history` | `market_values` |
| `player/8198/national-career-history` | `national_career` |
| `player/8198/injury` | `injuries` |
| `clubs?ids[]=…` (25'erli), `club/{id}`, `competitions?ids[]=…` | `clubs`, `competitions` (ad, logo, renk, slug) |

- Ham JSON yanıtları `api_cache` tablosunda saklanır: istatistikler 30 dk, kulüp/turnuva bilgileri 7 gün taze
  sayılır. API hata verirse (zaman aşımı, 5xx, bozuk yanıt) **eski önbellek** kullanılır; hiç veri yoksa görev
  hata verir ve mevcut veriler korunur.
- Tüm yazma işlemleri tek bir veritabanı işleminde yapılır; görev tekrar tekrar çalıştırılabilir (idempotent).
  Maç listesi yarıdan fazla küçülürse (kesik yanıt) eski maçlar korunur.
- Kulüp sayfalarının adresleri sabittir: `/takimlar/sporting-cp`, `manchester-united`, `real-madrid`,
  `juventus`, `al-nassr`, `portekiz`. Manchester United'ın iki dönemi aynı sayfada ayrı gösterilir.
- `/istatistikler/maclar` filtreleri: `?sezon=2016`, `?turnuva=CL`, `?sonuc=galibiyet|beraberlik|maglubiyet`,
  `?durum=tumu` (oynamadığı maçlar dahil), `?sayfa=N`. Filtresiz liste ve sezon sayfaları indekslenir,
  diğer filtre birleşimleri `noindex,follow` olur; geçersiz filtreler 301 ile temiz adrese yönlenir.
- Sayfalar veri yokken de açılır (sıfırlar ve “ilk senkronizasyondan sonra” notu gösterilir).
- Kulüp armaları ve portre `img.a.transfermarkt.technology` adresinden yüklenir; portre gösterildiğinde
  “Fotoğraf: IMAGO / Transfermarkt” kaynak satırı eklenir.

Elle senkronizasyon: panelde **Şimdi çalıştır** (Transfermarkt senkronizasyonu) ya da
`php bin/cron.php tm-sync --force`. Örnek çıktı: `1.653 maç, 179 sezon satırı, 11 takım, 41 turnuva,
12 transfer, 51 piyasa değeri, 6 milli takım, 30 sakatlık; 351 kulüp ve 41 turnuva bilgisi güncellendi
(API 26, önbellek 0, eski önbellek 0, hata 0) – 9,2 sn`.

## Haber sistemi (spin)

Haberler tamamen otomatik hazırlanır; **yapay zekâ kullanılmaz**. Kaynak metinler Türkçe eş anlamlı
kelime/ifade değiştirici (spinner) ve çoklu kaynak karıştırma ile yeniden yazılır, şablon cümlelerle
zenginleştirilir ve **asla birebir kopya yayınlanmaz**. Günde en fazla 10 haber yayınlanır.

**Akış**

1. `news-fetch` (30 dakikada bir): `config/news-sources.php` içindeki 14 kaynak okunur — Bing Haberler
   (3 arama) ve Google Haberler aramaları ile TRT Haber, Habertürk, CNN Türk, Sözcü, Ajansspor, Sporx
   (windows-1254), A Spor, Star, Takvim, NTV Spor (Atom). Başlığında ya da özetinde Cristiano Ronaldo geçen
   (Brezilyalı Ronaldo/Ronaldinho hariç), `news.max_age_hours` (48 saat) içindeki haberler `news_items`
   tablosuna `pending` olarak yazılır. Aynı adres ikinci kez alınmaz; son 72 saatte aynı hikâyeyi anlatanlar
   `duplicate` + `cluster:<ana kayıt>` notuyla bir **kümeye** bağlanır. Google Haberler bağlantıları metin
   için açılamadığından yalnızca kümeleme kanıtı olarak tutulur (`skipped`, `evidence-only`).
2. `news-publish` (10 dakikada bir, her çalışmada en fazla 1 haber): `spinner.enabled`, günlük limit
   `news.daily_limit` (**10**, İstanbul takvim günü), iki haber arası en az `news.min_interval_minutes` (60 dk)
   ve yayın penceresi `news.publish_window` (İstanbul saatiyle 07:00–23:00; başlangıç = bitiş ise gün boyu)
   kontrol edilir. Büyük, yeni ve metni alınabilen küme önce seçilir.
3. Metin: önce akıştaki tam metin, yoksa haber sayfası (JSON-LD `articleBody`, `<article>` paragrafları);
   reklam, “İlgili haber”, ara başlık ve anahtar kelime listeleri ayıklanır. Kaynak görselleri kullanılmaz; her
   yazı için 1200×630 OG görseli (GD, `public/og/`) üretilir.
4. Yazı: şablon giriş cümlesi + ana kaynağın cümleleri + kümedeki en fazla `spinner.max_sources` (3)
   kaynaktan tekrar etmeyen cümleler + oynadığı kulüp için arka plan paragrafı (`content/career.php`) +
   Transfermarkt istatistik cümleleri + kapanış cümlesi + tüm kaynaklara bağlantı veren **“Kaynak:”** satırı.
   Başlık eş anlamlılar ve kalıplarla yeniden kurulur ve her kaynak başlığından farklı olmalıdır (benzerlik < 0,7).

**Spinner kuralları** (`src/Services/News/Spinner.php`): tırnak içleri (“…”, "…", ‘…’), korunan isimler
(kulüpler, turnuvalar, kişiler; ek alsa bile: Al-Nassr'ın), sayılar, skorlar ve tarihler hiçbir zaman
değiştirilmez; en uzun eşleşme önce aranır; cümle başı büyük harfi Türkçe kurallarla korunur; “mı/mi/mu/mü”
ve “da/de” ekleri yeni kelimeye göre uyumlanır; rastgelelik haberin adres özetiyle tohumlandığı için aynı haber
her seferinde aynı sonucu verir.

**Özgünlük eşiği**: yazının kelime 3'lülerinden hiçbir kaynakta geçmeyenlerin oranı `spinner.min_change_ratio`
(varsayılan **%40**) altındaysa daha sert iki deneme daha yapılır; yine de altında kalırsa kayıt `failed` +
`low-uniqueness` olur ve yayınlanmaz. En az `spinner.min_words` (180) kelime şartı vardır (`too-short`);
Ronaldo'dan söz etmeyen kaynak metinleri `off-topic`, kaynağa fazla benzeyen başlıklar `title-too-similar`,
metni alınamayanlar `no-text` (3 kez yeniden denenir) notuyla elenir. Panelin Haber Kuyruğu sayfası bu
nedenleri Türkçe açıklamalarıyla gösterir. Özgünlük yüzdesi `posts.uniqueness` alanında tutulur.

**Kopya içerik kapısı** (`src/Services/News/CopyCheck.php`): Google'ın spam politikası, eş anlamlı kelime
değiştirilerek başka sitelerden alınan metni "kazınmış içerik" sayar ve bu tüm alan adının sıralamasını
etkileyebilir. Bu yüzden yayın öncesi her yazı kaynaklarıyla yeniden ölçülür: kaynakla ortak kelime 6'lılarının
oranı `spinner.max_shared_ngrams` (varsayılan **%10**) üstündeyse veya kaynaktan aynen alınmış en uzun dizi
`spinner.max_verbatim_words` (varsayılan **12** kelime; 4+ kelimelik tırnak içi alıntılar sayılmaz) üstündeyse
yazı yine yayınlanır ama **noindex** olur (site haritalarına ve IndexNow'a girmez). Haber Kuyruğu'ndaki notta
ölçüm yazar; editör yazıya özgün bilgi ekleyip yazı formundaki "Arama motorlarında indekslensin" kutusunu işaretleyebilir.

**Ayarlar (panel → Ayarlar)**: günlük limit, iki yayın arası süre, yayın penceresi, en fazla haber yaşı,
asgari özgünlük oranı, otomatik haberlerin indekslenmesi (kapatılırsa yeni otomatik yazılar `noindex` olur ve
site haritasına girmez), haber kaynaklarını tek tek açma/kapama (`news.sources_disabled`). Yeni kaynak eklemek
için `config/config.php` içinde şunu tanımlayın (aynı anahtar varsayılanı ezer):

```php
'news' => ['sources' => ['ornek' => ['name' => 'Örnek Spor', 'type' => 'rss', 'url' => 'https://ornek.com/spor.rss', 'filter' => true]]],
```

**Sayfalar**: `/haberler` (12'li sayfalama, `?sayfa=2`), `/haber/{slug}` (`NewsArticle` JSON-LD, OG görseli,
etiketler, kaynak kutusu, ilgili haberler), `/haberler/etiket/{slug}` (3'ten az yazılı etiketler `noindex`),
`/rss.xml` (son 30 indekslenebilir yazı).

**Elle çalıştırma**: `php bin/cron.php news-fetch --force` ve `php bin/cron.php news-publish --force`
(`--force` yalnızca görev aralığını atlar; günlük limit, yayın aralığı ve pencere yine uygulanır). Paneldeki
“Şimdi yayınla” limitleri ve pencereyi atlar ama özgünlük eşiğini atlamaz.

## Videolar

Video galerisi `/videolar` (kategori filtresi `?kategori=goller|mac-ozetleri|roportajlar|antrenman|belgesel|ur-cristiano|genel`,
sayfalama `?sayfa=`, sayfa başına 24 video) ve video sayfaları `/video/{slug}` adresindedir. Videolar
YouTube'da kalır; sitede yalnızca küçük resim gösterilir, oynat düğmesine basıldığında **youtube-nocookie.com**
oynatıcısı yüklenir (tıklamadan önce YouTube'a çerez gitmez). Video sayfalarında `VideoObject`, galeride
`ItemList` yapılandırılmış verisi bulunur.

- **Kurulumda gelen videolar:** 30 ikonik video Türkçe başlık ve açıklamalarla eklenir
  (`src/Install/Seeders/VideoSeeder.php`); kurulumu tekrar çalıştırmak yalnızca eksikleri ekler.
- **Otomatik senkronizasyon (`videos-sync`, 3 saatte bir):** aşağıdaki resmî kanalların RSS akışları okunur
  (API anahtarı gerekmez, kanal başına son 15 video). *Filtre = Evet* olan kanallardan yalnızca başlığında
  (veya açıklamanın ilk paragrafında) Ronaldo / CR7 / Cristiano geçen videolar alınır; yalnızca Arapça başlıklı
  videolar ve başka sitelerde oynatılmasına izin verilmeyenler atlanır.

| Kanal | Kanal kimliği | Filtre |
|---|---|---|
| UR · Cristiano | `UCtxD0x6AuNNqdXO9Wp5GHew` | Hayır |
| Al Nassr FC | `UCHEQtltsiDd3p8ga-5nC-ow` | Evet |
| Federação Portuguesa de Futebol | `UCsIoK3XP-cVcpoCC_SdjZdg` | Evet |
| Real Madrid | `UCWV3obpZVGgJ3j9FVhEjF2Q` | Evet |
| Manchester United | `UC6yW44UGJJBvYTlfC7CRg2Q` | Evet |
| Juventus | `UCLzKhsxrExAC6yAdtZ-BOWw` | Evet |
| Sporting CP | `UCnJj6L93JX3Jrhzv81ayywA` | Evet |
| UEFA | `UCyGa1YEx9ST66rYrJTGIKOw` | Evet |
| FIFA | `UCpcTrCXblq78GZrTUTLWeBw` | Evet |

- **Kanal eklemek / kapatmak:** `config/config.php` içindeki `youtube.channels` listesi bu varsayılanlarla
  birleştirilir; aynı kimlik varsayılanı ezer, `['id' => 'UC…', 'enabled' => false]` kanalı kapatır,
  `'filter' => true` yalnızca Ronaldo videolarını alır. Kanal kimliğini
  `https://www.youtube.com/feeds/videos.xml?channel_id=UC…` adresinin video listesi döndürmesiyle doğrulayın.
- **Kategoriler** başlıktaki anahtar kelimelerle (Türkçe, İngilizce, Portekizce, İspanyolca, İtalyanca, Arapça)
  otomatik belirlenir: Goller, Maç Özetleri, Röportajlar, Antrenman, Belgesel, UR · Cristiano, Genel.
- **İsteğe bağlı YouTube Data API v3:** panel → Ayarlar → YouTube API anahtarı girilirse senkronizasyon
  `youtube.search_query` ile son 14 günü de arar ve süre/izlenme bilgilerini alır (bir çalıştırma ≈ 100–110
  kota birimi; günlük ücretsiz kota 10.000). Kota dolarsa RSS + oEmbed yöntemine dönülür.
- **Elle video eklemek:** panel → Videolar → “Video ekle” alanına YouTube bağlantısını (`watch?v=`,
  `youtu.be/`, `/shorts/`, `/embed/`, `/live/`) ya da 11 karakterlik kimliği yapıştırın. Senkronizasyonla
  gelen bir videoyu kalıcı kaldırmak için silmek yerine “Gizli” durumuna alın.
- **Adresler** başlıktan üretilir ve sonuna video kimliğinden kısa bir parça eklenir
  (`/video/…-cjlcr`); başlık değişse bile eski adres yeni adrese 301 ile yönlenir.

## Forma Mağazası ve ödeme

Mağaza sayfaları herkese açıktır ve arama motorlarında listelenir; **sipariş alma varsayılan olarak
kapalıdır**: ürün sayfalarında sipariş formu yerine “Yakında satışta” gösterilir ve sipariş isteği 403 döner.

> **Önemli:** Formalar kulüplerin renklerinden esinlenen özgün çizimlerdir. Kulüp arması, sponsor logosu veya
> telifli fotoğraf kullanılmaz; açıklamalarda “orijinal” ya da “lisanslı” ifadesi geçmez. Ürünleriniz gerçekten
> öyleyse bunu panelden kendiniz ekleyin.

### Ürünler

- Kurulum Ronaldo'nun oynadığı her kulüp ve dönemden 11 forma ekler (Sporting CP 2002–03; Manchester United
  2003–04, 2007–08, 2021–22; Real Madrid 2013–14, 2016–17; Juventus 2018–19; Al-Nassr 2025–26 iç saha ve
  deplasman; Portekiz Euro 2016 ve 2026). Fiyatlar 1.499,90–2.299,90 TL, bedenler S–XXL ve 6–13 yaş, RONALDO 7
  baskı seçeneği +250 TL. Panelden silinen/yeniden adlandırılan ürünler kurulum tekrarlanınca geri gelmez.
- Panel → **Ürünler** (`/admin/urunler`): fiyat, eski fiyat, bedenler, baskı ücreti, stok (boş = sipariş üzerine),
  fotoğraf yükleme (JPG/PNG/WebP, en fazla 2 MB; `public/uploads/products/`, betik çalıştırma kapalı) ya da
  renklerden forma çizimi üretme.

### Siparişleri açmak (panel → Ödeme Ayarları, `/admin/odeme-ayarlari`)

1. **Mağaza** bölümü: kargo ücreti, WhatsApp numarası (“Haber ver” bağlantısı), bildirim e-postası.
2. **Satıcı bilgileri**: unvan, adres, telefon, e-posta, vergi dairesi/no, MERSİS. Bu bilgiler
   `/mesafeli-satis-sozlesmesi`, `/on-bilgilendirme-formu` ve `/iptal-ve-iade` sayfalarında görünür (mağaza
   açıkken bu sayfalar alt bilgide ve site haritasında da yer alır). Boş alanlar [köşeli parantez] ile
   gösterilir. Metinler şablondur; satışa başlamadan önce bir hukuk danışmanına kontrol ettirin.
3. En az bir ödeme yöntemini **etkinleştirin ve bilgilerini eksiksiz girin** (aşağıdaki tablo). Bir yöntem
   ancak hem açık hem yapılandırılmış olduğunda ödeme seçeneği olarak görünür.
4. **Sipariş alınsın** kutusunu işaretleyip kaydedin. Ürün sayfasında sipariş formu belirir; kullanılabilir hiçbir
   ödeme yöntemi yoksa panel uyarır ve sayfalar “Yakında satışta” olarak kalır. Kapatmak için kutunun işaretini
   kaldırıp kaydedin.

Gizli anahtarlar panelde maskeli (`••••••••abcd`) gösterilir; alan boş bırakılırsa kayıtlı değer korunur,
“Kayıtlı değeri sil” kutusu değeri kaldırır. Ödeme seçeneklerinin sırası `shop.payment_methods` ile belirlenir.

| Yöntem | Panelden girilecek bilgiler | Sağlayıcı paneline girilecek geri dönüş (callback) adresi | Test |
|---|---|---|---|
| **PayTR** (iFrame API) | Mağaza no, Mağaza parolası, Mağaza gizli anahtarı | **Bildirim URL:** `https://cr7.com.tr/odeme/paytr/bildirim` (PayTR Paneli › Destek & Kurulum › Ayarlar) | “Test modu” (varsayılan kapalı; açıkken panelde uyarı görünür, kapalıyken `test_mode=1` bildirimleri ödeme sayılmaz) |
| **iyzico** (Ödeme Formu) | API anahtarı, Güvenlik anahtarı | Girmeye gerek yok: `https://cr7.com.tr/odeme/iyzico/donus` her istekte `callbackUrl` olarak gönderilir | “Sandbox” (sandbox-merchant.iyzipay.com anahtarlarıyla) |
| **Shopier** | API kullanıcı, API şifre, web sitesi indeksi (Entegrasyonlar › Modül Yönetimi) | **Geri Dönüş URL:** `https://cr7.com.tr/odeme/shopier/donus` (seçtiğiniz indeks için) | Test ortamı yok; düşük tutarlı ürünle deneyin |
| **Havale / EFT** | Banka, alıcı adı, IBAN (TR, mod-97 doğrulamalı) | — | — |

Müşteri sonuç sayfası `https://cr7.com.tr/odeme/sonuc/{anahtar}`, sipariş takibi
`https://cr7.com.tr/siparis/{anahtar}` adresindedir (ikisi de `noindex`, önbelleğe alınmaz).

### Sipariş akışı

1. Ürün sayfasındaki form beden, baskı, adet, teslimat bilgileri, ödeme yöntemi ve iki onay ister (KVKK
   aydınlatma metni; Ön Bilgilendirme Formu + Mesafeli Satış Sözleşmesi).
2. `POST /forma/{slug}/siparis`: CSRF denetimi, görünmez tuzak alanı (honeypot), aynı IP'den saatte en fazla
   5 sipariş. Fiyat sunucuda yeniden hesaplanır: (ürün + baskı) × adet + kargo.
3. Sipariş `CR7-XXXXXX` numarası ve 32 karakterlik gizli erişim anahtarıyla kaydedilir, müşteri
   `/odeme/{anahtar}` sayfasına yönlenir (PayTR iframe, iyzico formu, Shopier yönlendirmesi ya da IBAN bilgisi).
4. Ödeme bildirimleri imzayla doğrulanır, tek seferliktir ve tutar sipariş toplamıyla karşılaştırılır; ödenmiş
   bir sipariş sonradan “başarısız” sayılmaz. Bildirim e-postası (ayarlıysa) PHP `mail()` ile gönderilir.
5. Panel → **Siparişler**: filtreleme, arama, durum değiştirme (Yeni → Onaylandı → Hazırlanıyor → Kargoda →
   Tamamlandı / İptal), kargo takip numarası, “Havale ödemesi alındı” onayı.

Teknik ayrıntılar (SDK kullanılmaz; düz curl + `hash_hmac`, karşılaştırmalar `hash_equals`):

- **PayTR:** token `POST https://www.paytr.com/odeme/api/get-token`; iframe
  `https://www.paytr.com/odeme/guvenli/{token}`; bildirim imzası
  `base64(HMAC-SHA256(merchant_oid.merchant_salt.status.total_amount, merchant_key))`; yanıt düz metin `OK`.
- **iyzico:** `Authorization: IYZWSv2 …` (resmî SDK'daki `IyziAuthV2Generator` ile aynı);
  `POST /payment/iyzipos/checkoutform/initialize/auth/ecom`; dönüşte sonuç
  `…/checkoutform/auth/ecom/detail` ile sorgulanır, `paymentStatus=SUCCESS`, `fraudStatus=1`, `basketId`,
  `price` ve yanıt imzası doğrulanır. `identityNumber` için `11111111111` gönderilir (gerçek üye işyeri hesabıyla
  teyit edin).
- **Shopier:** “Shopier ile Ödeme Al” API modülü (API kullanıcı/şifre); imzalı form
  `https://www.shopier.com/ShowProduct/api_pay4.php` adresine gönderilir; dönüş imzası
  `base64(HMAC-SHA256(random_nr.platform_order_id, api_secret))`. Bu imza `status` alanını kapsamaz ve dönüş
  müşterinin tarayıcısından gelir; bu yüzden Shopier siparişleri **otomatik “Ödendi” olmaz**: “başarılı” dönüşte
  sipariş “Bekliyor”da kalır, size “Ödeme kontrolü gerekiyor” e-postası gider. Ödemeyi Shopier satıcı panelinde
  gördükten sonra sipariş sayfasında “Ödeme alındı olarak işaretle” ile onaylayın. Her imzalı dönüş bir kez işlenir.
- **CSP:** ödeme sayfası yalnızca ilgili sağlayıcıya (ve bankanın 3-D Secure sayfasına) izin veren ek kurallarla açılır.

## Yönetim paneli

Adres: `https://cr7.com.tr/admin` (giriş `/admin/giris`). Panel arama motorlarına kapalıdır (`noindex` +
`X-Robots-Tag`, robots.txt'de `/admin` engelli).

| Bölüm | Adres | Ne yapılır |
|---|---|---|
| Panel | `/admin` | Bugünkü otomatik yayın / günlük limit, haber, kuyruk, video ve yeni sipariş sayıları; görevlerin son çalışması ve **Şimdi çalıştır** düğmeleri (tm-sync, news-fetch, news-publish, videos-sync, shop-expire); ödeme test modu açıksa uyarı; son günlük satırları; **Önbelleği temizle** |
| Haberler | `/admin/haberler` | Arama ve filtreler; yeni haber / düzenleme (başlık, adres, özet, güvenli HTML metin, etiketler, durum, indekslenme, yayın tarihi, SEO başlığı); OG görseli kayıtta üretilir, başlık değişince yenilenir; silme |
| Haber Kuyruğu | `/admin/haber-kuyrugu` | Toplanan kaynak haberler; durum, kaynak, arama ve hikâye kümesi filtreleri; notların Türkçe açıklaması (küme, yalnızca kanıt, başarısızlık nedeni); **Şimdi yayınla** (sonuç ve özgünlük oranı gösterilir), **Atla** |
| Videolar | `/admin/videolar` | YouTube bağlantısıyla ekleme; kategori, durum, öne çıkan filtreleri; düzenleme; silme |
| Siparişler | `/admin/siparisler` | Bkz. [Forma Mağazası](#forma-mağazası-ve-ödeme) |
| Ürünler | `/admin/urunler` | Ürün ekleme, düzenleme, fotoğraf/çizim, silme |
| Ödeme Ayarları | `/admin/odeme-ayarlari` | Mağaza açık/kapalı, **sipariş alma açık/kapalı**, kargo, satıcı bilgileri, PayTR / iyzico / Shopier / Havale |
| Ayarlar | `/admin/ayarlar` | Haber yayını (günlük limit 1–50, aralık, yayın penceresi, haber yaşı, özgünlük oranı, indekslenme), haber kaynakları, ana sayfa başlığı/alt başlığı/portre, GA4, IndexNow (**Anahtar üret**), Google/Bing/Yandex doğrulama kodları, YouTube API anahtarı, cron satırı ve web cron anahtarı |
| Şifre | `/admin/sifre` | Şifre değiştirme (mevcut şifre gerekir, yeni şifre en az 10 karakter) |

- Hatalı girişler genel bir mesajla reddedilir; aynı IP'den 15 dakikada 5 hatalı denemeden sonra giriş geçici
  olarak kilitlenir. Oturum 240 dakika işlem yapılmazsa kapanır (`app.session_idle_minutes`).
- Şifre unutulursa sunucuda: `php bin/install.php --reset-password --password='YENİ-ŞİFRE'`.
- Panel işlemleri (`POST /admin/islem/{görev}`) görevleri aralık beklemeden çalıştırır; başka bir cron çalışması
  sürüyorsa “şu an çalıştırılamadı” uyarısı verilir. Transfermarkt senkronizasyonu ilk seferde ≈10 sn sürer.

## SEO

Her sayfa denetleyicisi bir `App\Core\Seo` nesnesi üretir; `templates/layouts/main.php` bu nesneden `<title>`,
açıklama, kanonik adres, `robots`, `hreflang` (tr + x-default), Open Graph, Twitter Card ve JSON-LD etiketlerini basar.

**Uygulanan kurallar (kontrol listesi)**

- [x] Her sayfada tek `<h1>`, benzersiz Türkçe başlık (site eki hariç ≤ 60 karakter), 120–160 karakter açıklama
- [x] Mutlak kanonik adres; sayfalı listelerde `?sayfa=N` kendi kanoniği + `rel="prev/next"`
- [x] Filtreli/arama sayfaları `noindex,follow`; panel, ödeme ve sipariş sayfaları `noindex,nofollow`
- [x] Bilinmeyen adresler gerçek **404** (soft-404 yok); sondaki `/`, eski video adresleri ve panelde adresi (slug)
      değiştirilen haber/ürünlerin eski adresleri **301** (`slug_redirects` tablosu; eski kurulumlarda bir kez
      `php bin/install.php` çalıştırın); `?sayfa=1` → sayfasız adrese 301; tüm site HTTPS + www'suz 301
- [x] Filtreli listelerde tek ince içerik kuralı: 3'ten az öğeli etiket, video kategorisi ve kulüp formaları sayfası `noindex,follow`
- [x] Koşullu istekler: herkese açık 200 yanıtlarında zayıf `ETag`; `If-None-Match` eşleşirse gövdesiz **304**
- [x] `BreadcrumbList` (görsel + JSON-LD) tüm iç sayfalarda
- [x] Yapılandırılmış veri: `WebSite` (+ arama eylemi), `Organization`, `Person` (sitenin her yerinde aynı `@id`:
      `/hayati#cristiano-ronaldo`), `FAQPage`, `ItemList`, `SportsTeam`, `Dataset`, `NewsArticle`, `VideoObject`, `Product`
- [x] 1200×630 Open Graph görselleri (her haber, forma ve bölüm sayfası — Hayatı, Kariyer, Takımlar, İstatistikler,
      Kupalar, Videolar — için ayrı kart; seçkin videolarda 1280×720 `maxresdefault`)
- [x] XML site haritaları (görsel, video ve Google Haberler uzantılarıyla), dinamik `robots.txt`, RSS
- [x] IndexNow ile Bing/Yandex'e anında bildirim
- [x] Mobil öncelikli, duyarlı tasarım; yatay kaydırma yok; görseller boyutlu ve tembel yüklemeli (video sayfasının ana görseli hariç)
- [x] Video sayfalarında oynatıcı (`youtube-nocookie.com` iframe) sunucu tarafında basılır: Google videoyu tıklama
      olmadan bulur. Türkçe başlığı olmayan (kanallardan otomatik gelen) videolar Türkçe başlık verilene kadar `noindex`
- [x] Gizlilik odaklı: YouTube gizlilik geliştirilmiş modda (oynatılana kadar çerez yok), Analytics yalnızca çerez onayıyla
- [x] Güvenlik başlıkları ve nonce tabanlı CSP; HTML yanıtlarında doğru `Content-Type` ve `lang="tr"`

**Site haritaları**

| Adres | İçerik |
|---|---|
| `https://cr7.com.tr/sitemap.xml` | Dizin; URL içeren alt haritaları `lastmod` ile listeler |
| `https://cr7.com.tr/sitemap-pages.xml` | Ana sayfa, bölüm sayfaları, Hakkında/İletişim/Gizlilik; mağaza açıkken `/forma-magazasi` ve üç yasal sayfa; indekslenebilir listeler (sezon ve ana turnuva maç listeleri, video kategorileri, etiketler, kulüp formaları) |
| `https://cr7.com.tr/sitemap-posts.xml` | İndekslenebilir tüm haberler (`image:image`) |
| `https://cr7.com.tr/sitemap-news.xml` | Google Haberler: son 48 saatin haberleri |
| `https://cr7.com.tr/sitemap-videos.xml` | Videolar (`video:video`: küçük resim, başlık, açıklama, oynatıcı adresi, süre, tarih) |
| `https://cr7.com.tr/sitemap-clubs.xml` | `/takimlar` ve kulüp sayfaları (arma görseliyle) |
| `https://cr7.com.tr/sitemap-products.xml` | Formalar; mağaza kapalıyken 404 |

Haritalar 1 saat önbelleklenir (dizin ve Google Haberler haritası en fazla 15 dk; `sitemap.cache_ttl`); önbellek
anahtarı haber/video/ürün tablolarının parmak izini içerdiği için yayınlanan, düzenlenen, yayından kaldırılan veya
silinen içerik haritalara hemen yansır. Dizin ve Google Haberler haritası CDN'de en fazla 5 dk tutulur
(`Cache-Control: max-age=300`). Paneldeki “Önbelleği temizle” haritaları da hemen yeniler. `lastmod` gerçek içerik
değişikliğini gösterir (izlenme sayısı güncellemesi veya senkronizasyon zamanı değil; istatistiklerde son maç). `robots.txt` her şeye izin verir; `/admin`, `/cron`, `/kurulum`,
`/arama`, `/odeme`, `/siparis` yollarını engeller ve `Sitemap: https://cr7.com.tr/sitemap.xml` satırını içerir —
bu yüzden `app.url` canlı alan adıyla aynı olmalıdır. Alt klasör kurulumunda (`https://alan.com/cr7`) arama motorları
yalnızca alan adının kökündeki `robots.txt` dosyasını okur; köke `Sitemap: https://alan.com/cr7/sitemap.xml` satırını
içeren bir `robots.txt` koyun ya da site haritasını webmaster araçlarına elle gönderin.

## Arama motorlarına kayıt (Google, Bing, Yandex) ve IndexNow

**Google Search Console** (https://search.google.com/search-console)

1. “Mülk ekle” → **Alan adı** türünü seçip `cr7.com.tr` yazın. Verilen `google-site-verification=…` TXT
   kaydını cPanel → **Zone Editor** → alan adı → “+ TXT Kaydı” ile ekleyin, birkaç dakika sonra “Doğrula”ya basın.
   *Alternatif:* “URL ön eki” türünde `https://cr7.com.tr/` ekleyip **HTML etiketi** yöntemini seçin; etiketteki
   kodu panel → Ayarlar → **Google Search Console doğrulama kodu** alanına yapıştırıp kaydedin (etiketin tamamını
   yapıştırmak da olur), sonra “Doğrula”ya basın.
2. Sol menü → **Site haritaları** → `sitemap.xml` gönderin (alt haritalar dizinden otomatik bulunur).
   İsterseniz `sitemap-news.xml` ve `sitemap-videos.xml` adreslerini ayrıca ekleyin.
3. **URL denetimi** ile ana sayfa, `/hayati` ve bir haber sayfası için “Dizine eklenmesini iste”yi kullanın.
4. Yapılandırılmış verileri https://search.google.com/test/rich-results ile, mobil görünümü URL denetimindeki
   “Canlı URL'yi test et” ile kontrol edin. Haberlerin Google Haberler'de görünmesi için ayrıca
   https://publishercenter.google.com adresinden yayıncı profili oluşturabilirsiniz.

**Bing Webmaster Tools** (https://www.bing.com/webmasters)

1. “Google Search Console'dan içe aktar” seçeneğiyle siteyi ve site haritalarını tek adımda alın, ya da siteyi elle
   ekleyip **HTML Meta Etiketi** yöntemindeki `msvalidate.01` kodunu panel → Ayarlar → **Bing Webmaster doğrulama
   kodu** alanına girin ve “Doğrula”ya basın.
2. **Site Haritaları** → `https://cr7.com.tr/sitemap.xml` gönderin.
3. **IndexNow** bölümünde bildirimlerin geldiğini görebilirsiniz (aşağıya bakın).

**Yandex Webmaster** (https://webmaster.yandex.com)

1. “Site ekle” → `https://cr7.com.tr`. **Meta etiketi** yöntemindeki `yandex-verification` kodunu panel →
   Ayarlar → **Yandex Webmaster doğrulama kodu** alanına girip “Kontrol et”e basın (DNS TXT yöntemi de kullanılabilir).
2. **Dizinleme → Site haritası dosyaları** → `https://cr7.com.tr/sitemap.xml` ekleyin.
3. **Bölge** olarak Türkiye'yi seçin.

Doğrulama kodları yalnızca ana sayfaya `<meta>` etiketi olarak eklenir ve geçersiz karakter içeren kodlar
kaydedilmez. Doğrulamadan sonra kodları silmeyin; araçlar sahipliği zaman zaman yeniden kontrol eder.

**IndexNow** (Bing, Yandex, Seznam, Naver): panel → Ayarlar → IndexNow → **Anahtar üret** (32 karakter) ve
kaydedin. Anahtar `https://cr7.com.tr/indexnow-key.txt` adresinden sunulur (anahtar yokken 404). Bundan sonra
her yeni/güncellenen haber ve yeni video `api.indexnow.org` üzerinden otomatik bildirilir; yerel/test
adreslerinden bildirim yapılmaz. Sonuçlar `storage/logs` günlüğünde “IndexNow ping ok / failed” satırlarıyla görünür.

## Güvenlik notları

- `app.debug` canlıda **false** olmalıdır; hatalar ziyaretçiye gösterilmez, `storage/logs/` altına yazılır.
- `app.secret` uzun ve rastgele olmalıdır; kurulumdan sonra `/kurulum` zaten 404 döner.
- `config/config.php` dosyasının izni 640/600 olmalı ve asla herkese açık bir depoya konmamalıdır (`.gitignore`
  içindedir). Belge kökü `public/` olmalıdır; olamıyorsa kökteki `.htaccess` uygulama klasörlerini engeller.
- Tüm SQL sorguları hazırlıklı ifadelerle çalışır; şablon çıktıları `e()` ile kaçışlanır; haber/ürün HTML'i güvenli
  etiket listesiyle temizlenir.
- Tüm formlar CSRF belirteci taşır. Yönetici girişi IP başına 15 dakikada 5 hatalı denemeyle sınırlıdır; girişte
  oturum kimliği yenilenir; oturum çerezi `HttpOnly` + `SameSite=Lax` (+ HTTPS'te `Secure`).
- Her HTML yanıtında güvenlik başlıkları (`X-Content-Type-Options`, `Referrer-Policy`, `X-Frame-Options`,
  `Permissions-Policy`) ve nonce tabanlı Content-Security-Policy gönderilir.
- Ödeme bildirimleri yalnızca sağlayıcı imzasıyla kabul edilir; imzasız/yanlış bildirim 400 döner ve sipariş
  adresini açığa çıkarmaz. Kart bilgisi sitede hiç işlenmez veya saklanmaz.
- Web cron adresi gizli bir anahtar içerir; sızdıysa panelden yeni anahtar üretin. Gizli değerler (API anahtarları,
  ödeme anahtarları, cron anahtarı) panelde hiçbir zaman tam gösterilmez.
- Yüklenen ürün fotoğraflarının türü içerikten kontrol edilir, rastgele adla kaydedilir; klasörde betik çalışmaz.
- Google Analytics yalnızca `analytics.ga4_id` ayarlıysa ve ziyaretçi çerez onayı verirse yüklenir.
- Yönetici şifresini en az 12 karakter, benzersiz seçin; cPanel hesabında iki adımlı doğrulamayı açın.

## Klasör izinleri

| Yol | İzin |
| --- | --- |
| `storage/`, `storage/cache/`, `storage/logs/`, `storage/locks/` | 755 veya 775 (PHP yazabilmeli) |
| `public/og/` | 755 veya 775 (haber ve forma OG görselleri) |
| `public/uploads/products/` | 755 veya 775 (ürün fotoğrafları) |
| `config/config.php` | 640 veya 600 |
| Diğer dosyalar | dosyalar 644, klasörler 755 |

cPanel'de PHP genellikle hesap kullanıcısıyla çalıştığı için 755/644 yeterlidir.

## Güncelleme ve yedekleme

1. **Yedek alın:** veritabanı (cPanel → phpMyAdmin → Dışa Aktar, ya da
   `mysqldump -u KULLANICI -p VERITABANI > yedek.sql`), `config/config.php`, `public/uploads/` ve `public/og/`.
   cPanel → **Yedekleme** ile tam hesap yedeği de alabilirsiniz.
2. **Yeni dosyaları yükleyin** (`config/config.php`, `storage/`, `public/uploads/` ve `public/og/` içeriğinin
   üzerine yazmayın) ya da `git pull` yapın.
3. **Kurulumu yeniden çalıştırın:** `php bin/install.php`. Eksik tabloları oluşturur, eksik seed verilerini ve
   görselleri ekler; mevcut verilere ve yönetici şifresine dokunmaz.
4. **Önbelleği temizleyin:** panel → Panel → **Önbelleği temizle** ya da `rm -f storage/cache/*.cache`.
5. **Kontrol edin:** `php bin/cron.php all --force` hatasız bitmeli; panelin ana sayfasında görevler “ok” görünmeli.

## Yerel geliştirme ve testler

```bash
cd cr7
export CR7_DB_DRIVER=sqlite CR7_DB_PATH=/tmp/cr7.sqlite CR7_APP_URL=http://127.0.0.1:8080 CR7_DEBUG=1
php bin/install.php --password=admin12345
php bin/cron.php all --force
php -S 127.0.0.1:8080 -t public public/index.php   # http://127.0.0.1:8080 , panel: /admin (admin / admin12345)
php bin/test.php                                    # tüm testler (bellek içi SQLite, geçici günlük/önbellek klasörü)
php bin/test.php Shop                               # adı "Shop" içeren testler (ör. ShopTest)
php bin/test.php CoreTest::testRouter               # tek test
```

- `CR7_DEBUG=1` her PHP uyarısını hataya çevirir; geliştirme sırasında açık tutun.
- Testler gerçek `storage/logs` ve `storage/cache` klasörlerine yazmaz; her test boş bir veritabanıyla başlar.
- MySQL ile denemek için: `CR7_DB_DRIVER=mysql CR7_DB_HOST=127.0.0.1 CR7_DB_NAME=cr7 CR7_DB_USER=cr7 CR7_DB_PASS=…`.

## Sorun giderme

- **Beyaz sayfa / 500:** `storage/logs/YYYY-AA-GG.log` ve `storage/logs/php-error.log` dosyalarına bakın;
  `storage/` yazılabilir mi, PHP sürümü 8.1+ mı, `config/config.php` sözdizimi doğru mu (`php -l config/config.php`)?
- **“Transfermarkt API yanıt vermedi ve önbellekte kullanılabilir veri yok”:** sunucunun dışarıya HTTPS isteği
  yapabildiğini kontrol edin: `curl -I https://tmapi-alpha.transfermarkt.technology/player/8198`. Bazı paylaşımlı
  hostinglerde giden bağlantılar için destek talebi gerekir. Önbelleği sıfırlamak için phpMyAdmin'de
  `DELETE FROM api_cache;` çalıştırıp `php bin/cron.php tm-sync --force` ile yeniden başlatın.
- **Haber yayınlanmıyor:** panel → Haber Kuyruğu'nda kayıtların notlarına bakın (özgünlük, kısa metin, konu dışı);
  panelin ana sayfasında `news-publish` mesajı limit/aralık/pencere durumunu söyler (“Yayın penceresi dışında”,
  “Günlük limit doldu” …).
- **Video gelmiyor:** `https://www.youtube.com/feeds/videos.xml?channel_id=UC…` adresi sunucudan açılabiliyor mu?
  Gömmeye kapalı videolar bilerek atlanır.
- **Cron çalışmıyor:** panelin ana sayfasındaki “son çalışma” zamanına bakın; komutu cPanel'deki PHP yoluyla elle
  çalıştırıp çıktısını görün: `/usr/bin/php /home/KULLANICI/cr7/bin/cron.php`. “Başka bir zamanlanmış görev hâlâ
  çalışıyor” mesajı bir önceki çalışmanın sürdüğünü gösterir.
- **Görseller (OG) oluşmuyor:** `gd` eklentisinin FreeType desteğiyle kurulu olduğunu ve `public/og/` klasörünün
  yazılabilir olduğunu kontrol edin; `php bin/install.php` eksik görselleri yeniden üretir.
