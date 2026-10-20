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

> TODO (stats modülü): API uç noktaları, `tm-sync` görevinin yaptıkları, elle senkronizasyon ve
> sorun giderme.

## Haber Sistemi (Spin)

> TODO (news modülü): haber kaynakları, günlük limit (varsayılan 10) ve yayın aralığı, eş anlamlı
> kelime değiştirici (spinner) ve çoklu kaynak karıştırma kuralları, özgünlük eşiği, IndexNow.

## Videolar

> TODO (videos modülü): YouTube RSS/API senkronizasyonu, kategoriler, elle video ekleme.

## Forma Mağazası ve Ödeme

Mağaza sayfaları herkese açıktır ve arama motorlarında listelenir; **sipariş alma varsayılan olarak
kapalıdır** (ürün sayfalarında “Yakında satışta” gösterilir). Siparişler yönetim panelindeki
**Ödeme Ayarları** sayfasından açılır. Ödeme yöntemleri: **PayTR**, **iyzico**, **Shopier** ve
**Havale/EFT**.

> TODO (shop modülü): ürün yönetimi, ödeme sağlayıcılarının yapılandırılması (bildirim/geri dönüş
> adresleri, test modu), sipariş durumları.

## Yönetim Paneli

> TODO (admin modülü): giriş, haber/video yönetimi, haber kuyruğu, ayarlar, elle çalıştırılan işlemler.

## SEO

> TODO (site modülü): site haritaları, robots.txt, yapılandırılmış veriler (JSON-LD), Open Graph,
> kanonik adresler ve sayfalama kuralları.
