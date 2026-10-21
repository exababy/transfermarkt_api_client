<?php

declare(strict_types=1);

namespace App\Install\Seeders;

use App\Core\Database;
use App\Core\Settings;
use App\Install\Seeder;
use App\Services\Shop\KitIllustrator;
use App\Support\Date;

/**
 * Jersey catalogue: at least one kit for every club/spell of Cristiano Ronaldo's senior career
 * (Sporting CP, Manchester United ×2, Real Madrid, Juventus, Al-Nassr, Portekiz). Idempotent by slug:
 * missing products are inserted, existing rows (admin edits) are never overwritten, and a catalogue slug
 * is inserted only once (setting shop.seeded_slugs), so products the admin deleted or renamed do not come
 * back when the installer runs again. Kit illustrations
 * (public/assets/img/kits/{slug}.svg + {slug}-on.svg) and share images (public/og/forma-{slug}.png)
 * are generated when missing.
 */
final class ProductSeeder implements Seeder
{
    private const SIZES = 'S,M,L,XL,XXL';
    private const KIDS = '6-7 Yaş,8-9 Yaş,10-11 Yaş,12-13 Yaş';
    private const PRINT_PRICE = 250.0;

    /** Settings key listing catalogue slugs already seeded once (deleted/renamed products are not re-added). */
    public const SEEDED_KEY = 'shop.seeded_slugs';

    public function run(): string
    {
        $inserted = 0;
        $images = 0;
        $now = Date::now();
        $seeded = Settings::get(self::SEEDED_KEY, []);
        $seeded = is_array($seeded) ? array_values(array_filter($seeded, 'is_string')) : [];
        $before = $seeded;
        foreach (self::products() as $i => $p) {
            $exists = Database::fetchValue('SELECT id FROM products WHERE slug = ?', [$p['slug']]) !== null;
            if (!$exists && !in_array($p['slug'], $seeded, true)) {
                Database::insert('products', [
                    'slug' => $p['slug'],
                    'club_id' => $p['club_id'],
                    'club_name' => $p['club_name'],
                    'season' => $p['season'],
                    'kit_type' => $p['kit_type'],
                    'title' => $p['title'],
                    'description' => $p['description'],
                    'price' => $p['price'],
                    'old_price' => $p['old_price'] ?? null,
                    'sizes' => self::SIZES,
                    'kids_sizes' => self::KIDS,
                    'allow_print' => 1,
                    'print_price' => self::PRINT_PRICE,
                    'image_path' => '/assets/img/kits/' . $p['slug'] . '.svg',
                    'color1' => $p['kit']['base'],
                    'color2' => $p['kit']['trim'],
                    'stock' => null,
                    'sort_order' => ($i + 1) * 10,
                    'is_active' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $inserted++;
            }
            if (!in_array($p['slug'], $seeded, true)) {
                $seeded[] = $p['slug'];
            }
            $images += $this->ensureImages($p);
        }
        if ($seeded !== $before) {
            Settings::set(self::SEEDED_KEY, $seeded);
        }
        return sprintf('%d forma eklendi, %d forma zaten vardı veya yönetimden kaldırılmıştı, %d görsel oluşturuldu.', $inserted, count(self::products()) - $inserted, $images);
    }

    /** Write missing SVG/PNG artwork for a catalogue product; returns number of files written. */
    private function ensureImages(array $p): int
    {
        $written = 0;
        $kitDir = dirname(__DIR__, 3) . '/public/assets/img/kits/';
        if (!is_file($kitDir . $p['slug'] . '.svg') || !is_file($kitDir . $p['slug'] . '-on.svg')) {
            $written += KitIllustrator::writeSvgs($p['slug'], $p['kit'], $p['title']) !== null ? 2 : 0;
        }
        if (KitIllustrator::pngPath($p['slug']) === null) {
            $written += KitIllustrator::png($p['kit'], $p['slug'], $p['title'], $p['club_name'] . ' · ' . $p['season'] . ' · RONALDO ' . ($p['kit']['number'] ?? '7')) !== null ? 1 : 0;
        }
        return $written;
    }

    /** Catalogue (also used to regenerate artwork). */
    public static function products(): array
    {
        $printNote = 'İsteğe bağlı olarak sırtına RONALDO yazısı ve forma numarası basılabilir; isim ve numara siparişte değiştirilebilir.';
        return [
            [
                'slug' => 'sporting-cp-2002-03-retro-ic-saha-formasi',
                'club_id' => '336', 'club_name' => 'Sporting CP', 'season' => '2002–03', 'kit_type' => 'retro',
                'title' => 'Sporting CP 2002–03 Retro İç Saha Forması',
                'price' => 1499.90, 'old_price' => 1799.90,
                'description' => "Cristiano Ronaldo’nun profesyonel kariyerine başladığı Sporting CP’deki ilk sezonunu, 2002–03’ü hatırlatan yeşil-beyaz yatay çizgili retro iç saha forması. 17 yaşındaki Ronaldo bu sezon Lizbon ekibinin A takımına yükseldi ve 28 numaralı formayı giydi.\n\n"
                    . "Tasarım, kulübün geleneksel yeşil-beyaz çizgilerinden ilham alır; kulüp arması ve sponsor logoları yer almaz. Sırt baskısı varsayılan olarak RONALDO 28 şeklindedir.\n\n" . $printNote,
                'kit' => ['pattern' => 'hoops', 'base' => '#ffffff', 'alt' => '#00703c', 'trim' => '#00703c', 'text' => '#0b3d24', 'outline' => '#ffffff', 'number' => '28', 'stripe' => 44],
            ],
            [
                'slug' => 'manchester-united-2003-04-retro-formasi',
                'club_id' => '985', 'club_name' => 'Manchester United', 'season' => '2003–04', 'kit_type' => 'retro',
                'title' => 'Manchester United 2003–04 Retro Forması',
                'price' => 1599.90,
                'description' => "Ronaldo’nun Manchester United’a transfer olduğu ve 7 numarayı devraldığı 2003–04 sezonunu anımsatan kırmızı retro forma. Bu sezon FA Cup finalinde gol atarak İngiltere’deki ilk kupasını kazandı.\n\n"
                    . "Klasik kırmızı gövde, siyah yaka ve kol detaylarıyla sade bir taraftar forması tasarımı; arma ve sponsor logosu bulunmaz.\n\n" . $printNote,
                'kit' => ['pattern' => 'solid', 'base' => '#da291c', 'trim' => '#1a1a1a', 'text' => '#ffffff', 'outline' => '#1a1a1a', 'neck' => 'v'],
            ],
            [
                'slug' => 'manchester-united-2007-08-retro-ic-saha-formasi',
                'club_id' => '985', 'club_name' => 'Manchester United', 'season' => '2007–08', 'kit_type' => 'retro',
                'title' => 'Manchester United 2007–08 Retro İç Saha Forması',
                'price' => 1699.90, 'old_price' => 1899.90,
                'description' => "Moskova’daki Şampiyonlar Ligi finalinin oynandığı 2007–08 sezonundan ilham alan retro iç saha forması. Ronaldo o sezon tüm kulvarlarda 42 gol attı, Premier Lig ve Şampiyonlar Ligi’ni kazandı; finalde kafa golüyle skoru açtı.\n\n"
                    . "Kırmızı gövde ve beyaz yuvarlak yaka ile döneminin sade çizgilerini yansıtır; kulüp arması ve sponsor logoları kullanılmaz.\n\n" . $printNote,
                'kit' => ['pattern' => 'solid', 'base' => '#d0021b', 'trim' => '#ffffff', 'text' => '#ffffff', 'outline' => '#7a0010'],
            ],
            [
                'slug' => 'real-madrid-2013-14-retro-formasi',
                'club_id' => '418', 'club_name' => 'Real Madrid', 'season' => '2013–14', 'kit_type' => 'retro',
                'title' => 'Real Madrid 2013–14 Retro Forması (La Décima)',
                'price' => 1749.90,
                'description' => "Real Madrid’in onuncu Avrupa kupasını, “La Décima”yı kazandığı 2013–14 sezonuna ithafen hazırlanan beyaz retro forma. Ronaldo bu sezon Şampiyonlar Ligi’nde 17 golle tek sezon rekorunu kırdı ve Lizbon’daki finalde de gol attı.\n\n"
                    . "Beyaz gövde ve lacivert detaylar kulüp renklerine gönderme yapar; arma ve sponsor logoları yer almaz.\n\n" . $printNote,
                'kit' => ['pattern' => 'solid', 'base' => '#f7f7f9', 'trim' => '#23285e', 'text' => '#23285e', 'outline' => '#ffffff'],
            ],
            [
                'slug' => 'real-madrid-2016-17-ic-saha-formasi',
                'club_id' => '418', 'club_name' => 'Real Madrid', 'season' => '2016–17', 'kit_type' => 'ic-saha',
                'title' => 'Real Madrid 2016–17 İç Saha Forması',
                'price' => 1799.90,
                'description' => "Real Madrid’in La Liga ve Şampiyonlar Ligi’ni birlikte kazandığı 2016–17 sezonundan ilham alan beyaz iç saha forması. Ronaldo Cardiff’teki finalde iki gol attı ve sezonun sonunda beşinci Ballon d’Or’unu kazandı.\n\n"
                    . "Beyaz gövde, altın sarısı yaka ve kol detaylarıyla şık bir taraftar forması; kulüp arması ve sponsor logoları kullanılmaz.\n\n" . $printNote,
                'kit' => ['pattern' => 'solid', 'base' => '#ffffff', 'trim' => '#c9a227', 'text' => '#1f2a44', 'outline' => '#e8d58a', 'neck' => 'v'],
            ],
            [
                'slug' => 'juventus-2018-19-ic-saha-formasi',
                'club_id' => '506', 'club_name' => 'Juventus', 'season' => '2018–19', 'kit_type' => 'ic-saha',
                'title' => 'Juventus 2018–19 İç Saha Forması',
                'price' => 1799.90,
                'description' => "Ronaldo’nun Juventus’taki ilk sezonu 2018–19’u hatırlatan siyah-beyaz dikey çizgili iç saha forması. Torino ekibiyle ilk sezonunda Serie A şampiyonluğu ve İtalya Süper Kupası’nı kazandı.\n\n"
                    . "Kulübün klasik siyah-beyaz çizgilerinden ilham alan tasarım; arma ve sponsor logoları yer almaz.\n\n" . $printNote,
                'kit' => ['pattern' => 'stripes', 'base' => '#ffffff', 'alt' => '#111111', 'sleeves' => '#111111', 'trim' => '#111111', 'text' => '#ffffff', 'outline' => '#111111', 'stripe' => 46],
            ],
            [
                'slug' => 'manchester-united-2021-22-ic-saha-formasi',
                'club_id' => '985', 'club_name' => 'Manchester United', 'season' => '2021–22', 'kit_type' => 'ic-saha',
                'title' => 'Manchester United 2021–22 İç Saha Forması',
                'price' => 1899.90,
                'description' => "Ronaldo’nun Old Trafford’a dönüş yaptığı 2021–22 sezonunu anımsatan kırmızı iç saha forması. Dönüş sezonunda tüm kulvarlarda 24 gol atarak takımının en golcü ismi oldu.\n\n"
                    . "Kırmızı gövde ve siyah detaylar kulüp renklerine dayanır; kulüp arması ve sponsor logoları kullanılmaz.\n\n" . $printNote,
                'kit' => ['pattern' => 'solid', 'base' => '#c70101', 'trim' => '#111111', 'text' => '#ffffff', 'outline' => '#111111', 'neck' => 'v'],
            ],
            [
                'slug' => 'al-nassr-2025-26-ic-saha-formasi',
                'club_id' => '18544', 'club_name' => 'Al-Nassr', 'season' => '2025–26', 'kit_type' => 'ic-saha',
                'title' => 'Al-Nassr 2025–26 İç Saha Forması',
                'price' => 2199.90,
                'description' => "Ronaldo’nun kaptanlığını yaptığı Al-Nassr’ın sarı-mavi renklerinden ilham alan 2025–26 iç saha forması. Ronaldo, 2023’ten bu yana forma giydiği Riyad ekibiyle Arap Kulüpler Şampiyonası’nı kazandı ve Suudi Pro Lig’de gol krallığı yaşadı.\n\n"
                    . "Sarı gövde, mavi yaka, kol ve yan panel detayları; arma ve sponsor logoları yer almaz.\n\n" . $printNote,
                'kit' => ['pattern' => 'solid', 'base' => '#ffd200', 'trim' => '#0b3b8c', 'panel' => '#0b3b8c', 'text' => '#0b3b8c', 'outline' => '#ffffff'],
            ],
            [
                'slug' => 'al-nassr-2025-26-deplasman-formasi',
                'club_id' => '18544', 'club_name' => 'Al-Nassr', 'season' => '2025–26', 'kit_type' => 'deplasman',
                'title' => 'Al-Nassr 2025–26 Deplasman Forması',
                'price' => 2199.90,
                'description' => "Al-Nassr’ın lacivert ve sarı tonlarını bir araya getiren 2025–26 sezonu deplasman forması tasarımı. Koyu gövde üzerindeki sarı yaka ve kol detayları, sarı RONALDO 7 baskısıyla uyum içindedir.\n\n"
                    . "Tasarım kulüp renklerinden ilham alır; arma ve sponsor logoları kullanılmaz.\n\n" . $printNote,
                'kit' => ['pattern' => 'solid', 'base' => '#0b2a5b', 'trim' => '#ffd200', 'text' => '#ffd200', 'outline' => '#06142e'],
            ],
            [
                'slug' => 'portekiz-euro-2016-retro-formasi',
                'club_id' => '3300', 'club_name' => 'Portekiz', 'season' => 'Euro 2016', 'kit_type' => 'retro',
                'title' => 'Portekiz Euro 2016 Retro Forması',
                'price' => 1899.90, 'old_price' => 2099.90,
                'description' => "Portekiz’in tarihindeki ilk büyük turnuva zaferi olan EURO 2016’yı hatırlatan kırmızı retro forma. Kaptan Ronaldo turnuvada 3 gol attı ve Paris’teki finalde Fransa karşısında kupayı kaldıran takımın lideri oldu.\n\n"
                    . "Kırmızı gövde ve yeşil detaylar milli takım renklerine dayanır; federasyon arması ve sponsor logoları yer almaz.\n\n" . $printNote,
                'kit' => ['pattern' => 'solid', 'base' => '#c8102e', 'trim' => '#046a38', 'text' => '#f3d36b', 'outline' => '#5e0714'],
            ],
            [
                'slug' => 'portekiz-2026-ic-saha-formasi',
                'club_id' => '3300', 'club_name' => 'Portekiz', 'season' => '2026', 'kit_type' => 'ic-saha',
                'title' => 'Portekiz 2026 İç Saha Forması',
                'price' => 2299.90,
                'description' => "2026 Dünya Kupası yılı için Portekiz milli takımının kırmızı-yeşil renklerinden ilham alan iç saha forması. Ronaldo, 200’ü aşan maçıyla milli takım tarihinde en çok forma giyen ve en çok gol atan oyuncudur.\n\n"
                    . "Kırmızı gövde, yeşil yaka, kol ve yan panel detayları; federasyon arması ve sponsor logoları kullanılmaz.\n\n" . $printNote,
                'kit' => ['pattern' => 'solid', 'base' => '#b5121b', 'trim' => '#046a38', 'panel' => '#046a38', 'text' => '#ffffff', 'outline' => '#046a38'],
            ],
        ];
    }

    /** Kit spec by catalogue slug (admin "regenerate artwork"). */
    public static function kitFor(string $slug): ?array
    {
        foreach (self::products() as $p) {
            if ($p['slug'] === $slug) {
                return $p['kit'];
            }
        }
        return null;
    }
}
