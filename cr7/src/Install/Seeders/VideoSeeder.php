<?php

declare(strict_types=1);

namespace App\Install\Seeders;

use App\Core\Database;
use App\Core\Settings;
use App\Install\Seeder;
use App\Repositories\VideoRepository;
use App\Services\Video\VideoText;

/**
 * Curated iconic Cristiano Ronaldo videos from official channels (UEFA, FIFA, Real Madrid,
 * Manchester United, Juventus, Al Nassr, UR · Cristiano). Every id was checked on 2026-10-20:
 * YouTube oEmbed answered 200 (exists and may be embedded); dates/durations/views come
 * from YouTube. Titles and descriptions are original Turkish texts.
 *
 * Idempotent: only missing youtube_ids are inserted; existing rows (possibly edited in the
 * admin panel) are left untouched, and an id is inserted only once (setting videos.seeded_ids),
 * so videos the admin deleted do not come back when the installer runs again (--reset-password).
 */
final class VideoSeeder implements Seeder
{
    /** Settings key listing curated youtube_ids already seeded once. */
    public const SEEDED_KEY = 'videos.seeded_ids';

    private const CHANNELS = [
        'uefa' => ['UCyGa1YEx9ST66rYrJTGIKOw', 'UEFA'],
        'fifa' => ['UCpcTrCXblq78GZrTUTLWeBw', 'FIFA'],
        'rm' => ['UCWV3obpZVGgJ3j9FVhEjF2Q', 'Real Madrid'],
        'mu' => ['UC6yW44UGJJBvYTlfC7CRg2Q', 'Manchester United'],
        'juve' => ['UCLzKhsxrExAC6yAdtZ-BOWw', 'Juventus'],
        'nassr' => ['UCHEQtltsiDd3p8ga-5nC-ow', 'Al Nassr FC'],
        'ur' => ['UCtxD0x6AuNNqdXO9Wp5GHew', 'UR · Cristiano'],
    ];

    /** [youtube_id, channel, category, featured, published (UTC date), seconds, views, title, description] */
    public static function videos(): array
    {
        return [
            ['c_JlCriK3fY', 'rm', 'goller', true, '2020-04-01', 104, 1591566,
                'Ronaldo’nun Juventus’a Attığı Efsane Rövaşata Golü (2018)',
                "3 Nisan 2018’de Torino’da oynanan UEFA Şampiyonlar Ligi çeyrek final ilk maçında Cristiano Ronaldo, Juventus filelerine unutulmaz bir rövaşata golü gönderdi.\n\nReal Madrid’in 3-0 kazandığı karşılaşmada Juventus taraftarları bile Portekizli yıldızı ayakta alkışladı. Görüntüler Real Madrid’in resmî kanalından."],
            ['1NzNzpWdAz8', 'fifa', 'goller', true, '2020-08-25', 143, 1739497,
                'Ronaldo’nun İspanya’ya Hat-trick’i | 2018 Dünya Kupası',
                "2018 FIFA Dünya Kupası B Grubu’nda Portekiz ile İspanya 3-3 berabere kaldı. Cristiano Ronaldo penaltıdan, De Gea’nın hatasıyla gelen şuttan ve 88. dakikadaki muhteşem serbest vuruştan attığı gollerle hat-trick yaptı.\n\nÜç golün tamamı FIFA’nın resmî kanalından."],
            ['eLJ5MoSPjVE', 'fifa', 'mac-ozetleri', false, '2020-06-08', 776, 12743071,
                'Portekiz 3-3 İspanya Geniş Maç Özeti | 2018 Dünya Kupası',
                "Soçi’de oynanan ve turnuvanın en çok konuşulan maçlarından biri olan Portekiz – İspanya karşılaşmasının geniş özeti.\n\nDiego Costa’nın iki golüne ve Nacho’nun voleyle attığı gole karşılık Cristiano Ronaldo hat-trick yaparak Portekiz’e bir puan kazandırdı."],
            ['1r0YfOkNhJ8', 'fifa', 'genel', false, '2014-01-13', 224, 11822650,
                'Ronaldo’nun 2013 Ballon d’Or Gecesindeki Gözyaşları',
                "Cristiano Ronaldo, 13 Ocak 2014’te Zürih’te düzenlenen törende 2013 FIFA Ballon d’Or ödülünü kazandı ve sahnede gözyaşlarını tutamadı.\n\nOğlu Cristiano Jr.’ın da yanında olduğu bu duygusal an FIFA’nın resmî kanalından."],
            ['3PmLTPGhaOQ', 'fifa', 'roportajlar', false, '2017-10-23', 95, 3638941,
                'Ronaldo’nun The Best FIFA 2017 Ödülü Sonrası Açıklaması',
                "Cristiano Ronaldo, 23 Ekim 2017’de Londra’da düzenlenen The Best FIFA Futbol Ödülleri’nde yılın en iyi erkek futbolcusu seçildi.\n\nPortekizli yıldızın ödülün ardından duygu ve düşüncelerini paylaştığı kısa röportaj."],
            ['cLfSpFg6Pxg', 'juve', 'mac-ozetleri', true, '2019-03-15', 409, 16167939,
                'Juventus 3-0 Atlético Madrid | Ronaldo’dan Tarihî Hat-trick',
                "UEFA Şampiyonlar Ligi son 16 turunun ilk maçını 2-0 kaybeden Juventus, 12 Mart 2019’daki rövanşta Cristiano Ronaldo’nun iki kafa golü ve bir penaltı golüyle yaptığı hat-trick sayesinde Atlético Madrid’i 3-0 yenerek çeyrek finale yükseldi.\n\nMaçın özeti Juventus’un resmî kanalından."],
            ['OhlmggeaTrs', 'nassr', 'genel', false, '2023-01-03', 932, 1434275,
                'Cristiano Ronaldo’nun Al Nassr’a Tanıtım Töreni',
                "Cristiano Ronaldo, 3 Ocak 2023’te Riyad’daki Mrsool Park Stadyumu’nda düzenlenen törenle Al Nassr taraftarlarına tanıtıldı.\n\nPortekizli yıldızın Suudi Arabistan macerasının ilk gününden görüntüler Al Nassr’ın resmî kanalında."],
            ['9sVfR44ZcoU', 'rm', 'antrenman', false, '2016-05-02', 227, 2009030,
                'Ronaldo Real Madrid Antrenmanında Takımla Çalıştı (2016)',
                "Real Madrid’in Valdebebas’taki antrenman tesislerinden görüntüler: Cristiano Ronaldo, Mayıs 2016’daki pazartesi idmanını takım arkadaşlarıyla birlikte tamamladı.\n\nŞampiyonlar Ligi yarı final rövanşı öncesi yapılan çalışmadan kesitler."],
            ['bjWDGx6Kd_0', 'rm', 'antrenman', false, '2017-08-06', 63, 339483,
                'Ronaldo’nun 2017-18 Sezonundaki İlk Real Madrid Antrenmanı',
                "Konfederasyonlar Kupası ve tatilin ardından dönen Cristiano Ronaldo, Ağustos 2017’de Real Madrid’le yeni sezonun ilk antrenmanına çıktı. Valdebebas’tan kısa görüntüler."],
            ['9KXHnqrXZhk', 'ur', 'ur-cristiano', false, '2024-09-07', 113, 4911051,
                'Ronaldo’nun Kariyerindeki 900. Gol: Tüm Açılardan',
                "Cristiano Ronaldo, 5 Eylül 2024’te Lizbon’da Portekiz’in Hırvatistan’ı 2-1 yendiği UEFA Uluslar Ligi maçında resmî kariyerinin 900. golünü attı.\n\nTarihî anın farklı açılardan görüntüleri Ronaldo’nun kendi kanalı UR · Cristiano’dan."],
            ['UK5cu3LJ9qk', 'uefa', 'goller', true, '2023-07-06', 908, 20300530,
                'Ronaldo’nun Şampiyonlar Ligi’nde Attığı Tüm Goller',
                "UEFA Şampiyonlar Ligi’nin tüm zamanların en golcü oyuncusu Cristiano Ronaldo’nun Manchester United, Real Madrid ve Juventus formalarıyla attığı 140 golün tamamı.\n\nBeş kez Şampiyonlar Ligi kazanan Portekizli yıldızın Avrupa’daki gol şovu UEFA’nın resmî kanalından."],
            ['Ahnby2vUlxM', 'mu', 'mac-ozetleri', false, '2021-09-11', 213, 24242100,
                'Ronaldo Old Trafford’a Döndü: Man United 4-1 Newcastle',
                "Cristiano Ronaldo, 11 Eylül 2021’de 12 yıl aradan sonra yeniden Manchester United formasıyla Old Trafford’a çıktı ve Newcastle United’ın 4-1 yenildiği maçta iki gol attı.\n\nDönüş maçının özeti Manchester United’ın resmî kanalından."],
            ['1oM9RQa5SOQ', 'juve', 'mac-ozetleri', false, '2018-11-15', 349, 339551,
                'Juventus 2-1 Sassuolo | Ronaldo’nun Juventus’ta İlk Golleri',
                "Cristiano Ronaldo, 16 Eylül 2018’de Serie A’nın 4. haftasında Sassuolo’ya karşı iki gol atarak Juventus formasıyla ilk gollerini kaydetti.\n\nTorino’da oynanan maçın özeti Juventus’un resmî kanalından."],
            ['KewakhYqrts', 'ur', 'ur-cristiano', false, '2025-11-07', 4703, 2696767,
                'Cristiano Ronaldo’nun Piers Morgan’la Uzun Röportajı',
                "Cristiano Ronaldo, kendi YouTube kanalında yayımlanan bu uzun röportajda Piers Morgan’ın sorularını yanıtlıyor; kariyeri, ailesi ve geleceği hakkında konuşuyor.\n\nRöportajın tamamı UR · Cristiano kanalından."],
            ['t40HXEc6c84', 'ur', 'ur-cristiano', false, '2024-09-20', 2618, 3277244,
                'Ronaldo, Rio Ferdinand’a Her Şeyi Anlattı',
                "Eski Manchester United takım arkadaşı Rio Ferdinand ile sohbet eden Cristiano Ronaldo; Real Madrid ve Manchester United yıllarını, EURO 2024’ü ve kariyerinin dönüm noktalarını anlatıyor.\n\nUzun sohbetin tamamı UR · Cristiano kanalından."],
            ['2Cq-3ywYGpk', 'nassr', 'goller', false, '2023-02-09', 402, 1802059,
                'Ronaldo’dan Al Wehda’ya 4 Gol | Al Nassr’da Süper Hat-trick',
                "Cristiano Ronaldo, 9 Şubat 2023’te Suudi Pro Lig’de Al Wehda deplasmanında dört gol atarak Al Nassr’ın 4-0’lık galibiyetine imza attı.\n\nPortekizli yıldızın Suudi Arabistan’daki ilk dört gollü maçı Al Nassr’ın resmî kanalından."],
            ['4rwH_PRlUtM', 'nassr', 'goller', false, '2024-04-02', 310, 1002653,
                'Ronaldo’dan Abha’ya Bir Yarıda Hat-trick ve 2 Asist',
                "Cristiano Ronaldo, 2 Nisan 2024’te Al Nassr’ın Abha’yı 8-0 yendiği Suudi Pro Lig maçında daha ilk yarıda hat-trick yaptı ve iki de asist verdi.\n\nRekor gecenin görüntüleri Al Nassr’ın resmî kanalından."],
            ['XLTC9NfTiRs', 'rm', 'genel', false, '2009-07-08', 149, 4541056,
                'Cristiano Ronaldo’nun Bernabéu’daki Tanıtımı (2009)',
                "Manchester United’dan dönemin dünya rekoru bonservisiyle Real Madrid’e transfer olan Cristiano Ronaldo, 6 Temmuz 2009’da yaklaşık 80 bin taraftarın doldurduğu Santiago Bernabéu’da tanıtıldı.\n\nUnutulmaz tanıtım gecesinden görüntüler Real Madrid’in resmî kanalından."],
            ['62jKF_eCPqc', 'juve', 'goller', false, '2024-02-05', 1002, 2423583,
                'Ronaldo’nun Juventus’ta Attığı 101 Golün Tamamı',
                "Cristiano Ronaldo, 2018-2021 yılları arasında Juventus formasıyla 134 resmî maçta 101 gol attı.\n\nAtlético Madrid’e karşı yaptığı hat-trick’ten Camp Nou’daki golüne kadar tüm goller Juventus’un resmî kanalından."],
            ['b-uoZI2f-4Q', 'juve', 'goller', false, '2021-02-05', 404, 4434574,
                'Juventus Formasıyla En İyi 10 Ronaldo Golü',
                "Juventus, Cristiano Ronaldo’nun siyah-beyazlı formayla o güne kadar attığı en güzel 10 golü derledi. Uzaktan şutlar, kafa golleri ve frikikler bu derlemede."],
            ['j7t5j-sTysk', 'fifa', 'goller', false, '2026-03-09', 482, 1933126,
                'Cristiano Ronaldo’nun Dünya Kupası’ndaki Tüm Golleri',
                "Cristiano Ronaldo’nun 2006’dan bu yana Portekiz formasıyla FIFA Dünya Kupası’nda attığı tüm goller.\n\nBeş farklı Dünya Kupası’nda gol atan ilk futbolcu olan Ronaldo’nun turnuvadaki golleri FIFA’nın resmî kanalından."],
            ['-Wh5SSCnmL0', 'fifa', 'roportajlar', false, '2026-07-05', 2847, 688874,
                'Ronaldo ve Martínez’in İspanya Maçı Öncesi Basın Toplantısı',
                "Portekiz Milli Takımı’nın kaptanı Cristiano Ronaldo ve teknik direktör Roberto Martínez, 2026 FIFA Dünya Kupası’ndaki İspanya maçı öncesinde basın toplantısında gazetecilerin sorularını yanıtladı.\n\nBasın toplantısının tamamı FIFA’nın resmî kanalından."],
            ['aeapZzsujqc', 'rm', 'goller', true, '2026-02-05', 5772, 2288336,
                'Ronaldo’nun Real Madrid’de Attığı 451 Golün Tamamı',
                "Real Madrid’in tüm zamanların en golcü oyuncusu Cristiano Ronaldo, 2009-2018 yılları arasında 438 resmî maçta 451 gol attı.\n\nKulübün resmî kanalında yayımlanan bu uzun derlemede Portekizli yıldızın Real Madrid’deki bütün golleri yer alıyor."],
            ['5hFd6zGkxLE', 'rm', 'belgesel', false, '2018-07-10', 397, 75271721,
                'Teşekkürler Cristiano: Real Madrid’in Veda Filmi',
                "Cristiano Ronaldo’nun Juventus’a transferinin açıklandığı 10 Temmuz 2018’de Real Madrid, dokuz yıllık birlikteliği anlatan bu veda filmini yayımladı.\n\n4 Şampiyonlar Ligi, 2 La Liga şampiyonluğu ve 451 golle dolu bir dönemin hikâyesi."],
            ['hWtp3ZlvHZc', 'ur', 'ur-cristiano', false, '2024-11-07', 226, 2240929,
                'Cristiano Ronaldo Nasıl Futbolcu Oldu?',
                "Madeira’nın Funchal şehrinde doğan Cristiano Ronaldo’nun Andorinha ve Nacional’dan Sporting CP akademisine uzanan futbolculuk hikâyesi.\n\nRonaldo’nun kendi kanalı UR · Cristiano’da yayımlanan kısa belgesel."],
            ['TpLKhDURaho', 'ur', 'ur-cristiano', false, '2024-09-24', 917, 5386812,
                'Cristiano Ronaldo ile Bir Gün: Şampiyonlar Ligi Ödül Gecesi',
                "Şampiyonlar Ligi ödül gecesine eşlik eden bu özel videoda Cristiano Ronaldo, zirveye uzanan yolculuğunu kendi ağzından anlatıyor.\n\nKamera arkası görüntüler UR · Cristiano kanalından."],
            ['Z7prW6qpflQ', 'uefa', 'goller', false, '2021-08-30', 122, 1130101,
                'Ronaldo’nun Manchester United’daki Şampiyonlar Ligi Golleri',
                "Cristiano Ronaldo’nun Manchester United’a dönüşünün ardından UEFA, Portekizli yıldızın ilk döneminde kırmızı formayla attığı en güzel Şampiyonlar Ligi gollerini derledi.\n\nBu dönemin zirvesi, 2008’de Moskova’da kazanılan Şampiyonlar Ligi kupasıydı."],
            ['PMdKpX_l7sA', 'uefa', 'goller', false, '2025-11-13', 499, 283865,
                'Ronaldo’nun Rekor Kırdığı Şampiyonlar Ligi Sezonu (2013-14)',
                "Cristiano Ronaldo, 2013-14 sezonunda Real Madrid formasıyla Şampiyonlar Ligi’nde 17 gol atarak tek sezon rekorunu kırdı ve takımının La Décima’yı, yani 10. Avrupa kupasını kazanmasına önderlik etti.\n\nRekor sezonun bütün golleri UEFA’nın resmî kanalından."],
            ['bLDSjWkZ72o', 'fifa', 'genel', false, '2014-01-13', 222, 13032223,
                'CR7 ve Pelé’nin Duygusal Gecesi | 2013 Ballon d’Or',
                "2013 FIFA Ballon d’Or töreninde Cristiano Ronaldo yılın en iyi futbolcusu ödülünü alırken futbolun efsanesi Pelé de onursal Ballon d’Or ile ödüllendirildi.\n\nZürih’teki gecenin en duygusal anları FIFA’nın resmî kanalından."],
            ['EJjR71XgQpI', 'rm', 'antrenman', false, '2016-09-05', 181, 3073424,
                'Cristiano ve Benzema Takım Antrenmanına Döndü',
                "EURO 2016 finalinde dizinden sakatlanan Cristiano Ronaldo, Eylül 2016’da Karim Benzema ile birlikte Real Madrid’in takım antrenmanlarına geri döndü. Valdebebas’tan görüntüler."],
        ];
    }

    /** Seeded videos without a 1280x720 maxresdefault.jpg on i.ytimg.com (checked 2026-10-20). */
    private const NO_MAXRES = ['XLTC9NfTiRs'];

    /** Largest thumbnail: maxresdefault (1280 px, large share/Discover previews) else hqdefault. */
    public static function thumbnail(string $id): string
    {
        return VideoText::thumbnail($id, in_array($id, self::NO_MAXRES, true) ? 'hqdefault' : 'maxresdefault');
    }

    public function run(): string
    {
        $repo = new VideoRepository();
        $added = 0;
        $existing = 0;
        $removed = 0;
        $seeded = Settings::get(self::SEEDED_KEY);
        if (!is_array($seeded)) {
            // Installs from before the setting existed seeded the whole list at once: a curated
            // video missing there now was deleted by the admin.
            $ids = array_column(self::videos(), 0);
            $seeded = (int) Database::fetchValue('SELECT COUNT(*) FROM videos WHERE youtube_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids) > 0 ? $ids : [];
        }
        $seeded = array_values(array_filter($seeded, 'is_string'));
        $before = Settings::get(self::SEEDED_KEY);
        foreach (self::videos() as [$id, $channel, $category, $featured, $date, $seconds, $views, $title, $description]) {
            $row = $repo->findByYoutubeId($id);
            if ($row !== null) {
                $existing++;
                // Earlier installs stored hqdefault (480x360): upgrade, keeping any admin-set image.
                if ($row['thumbnail_url'] === VideoText::thumbnail($id) && self::thumbnail($id) !== $row['thumbnail_url']) {
                    Database::update('videos', ['thumbnail_url' => self::thumbnail($id)], 'id = ?', [(int) $row['id']]);
                }
                if (!in_array($id, $seeded, true)) {
                    $seeded[] = $id;
                }
                continue;
            }
            if (in_array($id, $seeded, true)) {
                $removed++; // deleted in the admin panel: not re-added
                continue;
            }
            $seeded[] = $id;
            [$channelId, $channelTitle] = self::CHANNELS[$channel];
            $repo->create([
                'youtube_id' => $id,
                'title' => $title,
                'description' => $description,
                'channel_id' => $channelId,
                'channel_title' => $channelTitle,
                'category' => $category,
                'thumbnail_url' => self::thumbnail($id),
                'duration_seconds' => $seconds,
                'view_count' => $views,
                'published_at' => $date . ' 12:00:00',
                'source' => 'manual',
                'is_featured' => $featured,
            ]);
            $added++;
        }
        if ($seeded !== $before) {
            Settings::set(self::SEEDED_KEY, $seeded);
        }
        $deleted = $removed > 0 ? ", {$removed} video yönetimden silindiği için yeniden eklenmedi" : '';
        return $added > 0
            ? "{$added} seçkin Cristiano Ronaldo videosu eklendi" . ($existing > 0 ? ", {$existing} video zaten mevcut" : '') . $deleted . '.'
            : "Seçkin videolar zaten mevcut ({$existing}){$deleted}.";
    }
}
