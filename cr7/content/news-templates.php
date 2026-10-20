<?php

declare(strict_types=1);

/*
 * Spintax templates for automatically rewritten news posts (App\Services\News\Spinner).
 *
 * Syntax: {a|b|c} picks one option (nestable, no empty options); {{name}} is a placeholder
 * filled by the publisher BEFORE/independently of spintax expansion.
 *   intros / outros : generic sentences that are true for ANY Cristiano Ronaldo news item.
 *                     Only {{player}} is used. Facts mentioned are permanent career history
 *                     (five Ballon d’Or awards, EURO 2016, clubs he played for, Madeira).
 *   context         : stat sentences; every number comes from a placeholder:
 *                     {{player}} {{age}} {{apps}} {{goals}} {{assists}} {{club}} {{national_goals}} {{national_caps}}
 *                     Placeholders are never followed by a suffix, so any value reads correctly.
 *   connectors      : neutral, additive sentence openers (no contrastive/causal ones, so mixing
 *                     sentences from different sources never creates a false logical link).
 *   title_patterns  : {{subject}} = subject of the (spun) source title, {{rest}} = remainder.
 *   source_line     : {{source}} = source name(s)/link(s).
 * Typographic apostrophe (’) is used for Turkish proper-noun suffixes. Linguist QA 2026-10-20: fixed
 * unidiomatic collocations ("bir haber yaşandı", "bir gelişme geldi"), implicit subjects in outros and
 * connectors that presuppose earlier news ("Hatırlanacağı üzere").
 */

return [
    'intros' => [
        '{Portekizli yıldız|Portekizli süperstar|Dünyaca ünlü futbolcu} {{player}} {ile ilgili|hakkında} {yeni bir gelişme yaşandı|yeni bir gelişme gündeme geldi|dikkat çeken bir haber gündeme geldi}.',
        '{{player}} {yine|bir kez daha} {gündemde|haberlere konu oldu}.',
        '{Futbol dünyasının|Dünya futbolunun} en çok konuşulan isimlerinden {{player}} {ile ilgili|hakkında} {yeni bir haber geldi|dikkat çeken bir gelişme yaşandı}.',
        '{{player}} {cephesinde|ile ilgili} {yeni bir gelişme|dikkat çeken bir haber} var.',
        '{Portekizli yıldız|Portekizli süperstar} {{player}}, {bu kez|bu defa} {yeni bir|farklı bir} {haberle|gelişmeyle} {gündeme geldi|adından söz ettirdi}.',
        '{Kariyeri boyunca|Uzun yıllardır süren kariyerinde} {pek çok|sayısız} rekora imza atan {{player}} {ile ilgili|hakkında} {yeni bir gelişme yaşandı|dikkat çeken bir haber geldi}.',
        '{Futbolun en büyük yıldızları arasında gösterilen|Dünya futbolunun en tanınmış isimlerinden} {{player}}, {yine|bir kez daha} {gündemde|haberlere konu oldu}.',
        '{{player}} ile ilgili {gelişmeler|haberler} {milyonlarca hayranı|dünyanın dört bir yanındaki hayranları} tarafından {yakından|ilgiyle} takip ediliyor.',
        'İşte {{player}} {ile ilgili|hakkında} {son gelişme|en yeni haber|son gelişmeler}.',
        '{{player}} {hakkında|ile ilgili} {gündeme gelen|ortaya çıkan} {son|yeni} {gelişmeyi|haberi} {derledik|sizler için derledik}.',
        '{CR7 olarak da bilinen|CR7 lakaplı} {{player}} {ile ilgili|hakkında} {yeni bir haber gündeme geldi|yeni bir gelişme yaşandı}.',
        '{Futbol tarihinin en golcü isimlerinden|Tüm zamanların en golcü futbolcularından} {{player}}, {yine|bir kez daha} {haberlere konu oldu|gündeme geldi}.',
        '{Sosyal medyada en çok takip edilen isimlerden|Dünyanın en çok takip edilen sporcularından} {{player}} {ile ilgili|hakkında} {yeni bir gelişme yaşandı|dikkat çeken bir haber geldi}.',
        '{Beş kez Ballon d’Or ödülünü kazanan|Kariyerinde beş Ballon d’Or ödülü bulunan} {{player}} {ile ilgili|hakkında} {yeni bir gelişme|dikkat çeken bir haber} {var|gündemde}.',
        '{Madeira’da doğan|Madeira doğumlu} {Portekizli yıldız|Portekizli süperstar} {{player}} {ile ilgili|hakkında} {yeni bir haber geldi|yeni bir gelişme gündeme geldi}.',
        '{Profesyonel kariyerine Sporting CP’de başlayan|Sporting CP altyapısından yetişen} {{player}}, {yeni bir|farklı bir} {haberle|gelişmeyle} {gündemde|adından söz ettiriyor}.',
        '{Manchester United, Real Madrid ve Juventus formalarını giyen|Kariyerinde Manchester United, Real Madrid ve Juventus formalarını terleten} {{player}} {ile ilgili|hakkında} {yeni bir gelişme yaşandı|dikkat çeken bir haber geldi}.',
        '{EURO 2016’da Portekiz’i şampiyonluğa taşıyan|Portekiz ile EURO 2016 şampiyonluğu yaşayan} {{player}}, {yine|bir kez daha} {gündemde|haberlere konu oldu}.',
        '{Beş kez Şampiyonlar Ligi kupasını kaldıran|Kariyerinde beş Şampiyonlar Ligi şampiyonluğu bulunan} {{player}} {ile ilgili|hakkında} {yeni bir haber|yeni bir gelişme} {var|gündemde}.',
        '{Futbolseverlerin|Taraftarların} yakından takip ettiği {{player}} {ile ilgili|hakkında} yeni bir gelişme {yaşandı|var}.',
        '{Gündemden düşmeyen|Adından sıkça söz ettiren} {{player}}, {yeni bir|farklı bir} {haberle|gelişmeyle} {karşımızda|gündemde}.',
        '{{player}} {ile ilgili|hakkında} {dikkat çeken|merak uyandıran} {bir gelişme|bir haber} {var|gündemde}.',
        '{Kariyerinde|Futbol kariyeri boyunca} {yüzlerce gole|sayısız gole} imza atan {{player}}, {yine|bir kez daha} {konuşulan isimler arasında|gündemin ilk sıralarında}.',
        '{Futbol gündeminde|Spor gündeminde} {bu kez|bu defa} {{player}} {var|ile ilgili bir gelişme var}.',
        '{{player}} {ile ilgili|hakkında} {yeni bir gelişme|yeni bir haber} {sizlerle|okurlarımızla}.',
        '{Dünyanın en tanınmış sporcularından|Dünyanın en popüler futbolcularından} {{player}} ile ilgili {haberin|gelişmenin} {ayrıntıları|detayları} {haberimizde|bu haberde}.',
        '{Efsane futbolcu|Efsanevi futbolcu|Portekizli efsane} {{player}} {yine|bir kez daha} haber oldu.',
        '{{player}} {ile ilgili|hakkında} {hayranlarını|sevenlerini} {yakından ilgilendiren|heyecanlandıran} {bir gelişme|bir haber} gündeme geldi.',
        '{Yeşil sahalarda|Futbol sahalarında} {uzun yıllar boyunca|yıllarca} {boy gösteren|ter döken} {{player}}, {yine|bir kez daha} {gündemde|haberlere konu oldu}.',
        '{{player}} {hakkında|ile ilgili} {merak edilen|konuşulan} {gelişmenin|haberin} {ayrıntıları|detayları} {şöyle|şu şekilde}.',
        '{Dünya futbolunun|Futbol dünyasının} {ikonik|en tanınan} isimlerinden {{player}} ile ilgili {yeni bir gelişme var|yeni bir haber geldi|dikkat çeken bir haber geldi}.',
        '{Ronaldo hayranlarının|CR7 hayranlarının} {ilgiyle|merakla} takip edeceği {bir gelişme yaşandı|bir haber gündeme geldi}.',
    ],

    'context' => [
        '{{age}} yaşındaki {{player}}, {kariyeri boyunca|profesyonel kariyerinde} {{apps}} {maçta|karşılaşmada} {{goals}} gol {kaydetti|attı}.',
        '{Transfermarkt verilerine göre|Transfermarkt istatistiklerine göre}, {{player}} {kariyerinde|profesyonel kariyeri boyunca} {{goals}} gol atıp {{assists}} asist yaptı.',
        '{Halen|Şu anda} {{club}} {forması giyen|formasını terleten} {{player}}, {{age}} yaşında.',
        '{{player}}, Portekiz Milli Takımı {formasıyla|ile} {{national_caps}} {maça çıktı|kez sahaya çıktı} ve {{national_goals}} gol {attı|kaydetti}.',
        '{Milli formayla|Ulusal takım formasıyla} {{national_caps}} {karşılaşmaya|maça} çıkan {{player}}, bu {maçlarda|karşılaşmalarda} {{national_goals}} {kez fileleri havalandırdı|gol kaydetti}.',
        '{Kariyerinde|Futbol kariyeri boyunca} {{apps}} {resmi maça|resmi karşılaşmaya} çıkan {{player}}, {{goals}} gole imza attı.',
        '{{player}}, {kariyeri boyunca|profesyonel futbol hayatında} {{assists}} {asist yaptı|gol pası verdi}.',
        '{{age}} yaşındaki {{player}}, {{club}} {formasıyla|ile} {kariyerine devam ediyor|kariyerini sürdürüyor}.',
        '{İstatistiklere göre|Verilere göre}, {{player}} {kariyerinde|profesyonel kariyerinde} {{apps}} maça çıktı; bu maçlarda {{goals}} gol ve {{assists}} asist {kaydetti|üretti}.',
        '{{player}} {ile ilgili|hakkında} {bazı rakamları|bazı istatistikleri} hatırlatalım: {kariyerinde|profesyonel kariyerinde} {{apps}} maç, {{goals}} gol ve {{assists}} asist.',
        '{Milli takım kariyerinde|Portekiz formasıyla} {{national_goals}} gole ulaşan {{player}}, {bu süreçte|uluslararası arenada} {{national_caps}} {maça çıktı|kez forma giydi}.',
        '{Sahalardaki|Futboldaki} uzun kariyerinde {{apps}} {maçı|karşılaşmayı} geride bırakan {{player}}, {{goals}} {golle|gol ile} {dikkat çekiyor|göz dolduruyor}.',
        '{Halihazırda|Şu sıralar} {{club}} {kadrosunda yer alan|bünyesinde bulunan} {{player}}, {kariyerinde|kariyeri boyunca} {{goals}} gole ulaştı.',
        '{{player}}, {kariyerinde|profesyonel kariyerinde} {toplam|toplamda} {{goals}} gole ulaşmış durumda.',
        '{{age}} yaşındaki {yıldız|süperstar} {{player}}, Portekiz Milli Takımı {formasıyla|ile} {{national_goals}} gol {kaydetti|attı}.',
        '{Rakamlar|İstatistikler} {{player}} için {etkileyici|dikkat çekici}: {{apps}} maç, {{goals}} gol, {{assists}} asist.',
        '{{player}}, {milli takımda|Portekiz formasıyla} {{national_caps}} {maçta|karşılaşmada} {{national_goals}} {gole imza attı|gol kaydetti}.',
        '{Kariyeri boyunca|Profesyonel kariyerinde} {{apps}} maça çıkan {{player}}, {{goals}} gol ve {{assists}} asistle {takımlarına|forma giydiği takımlara} {büyük|önemli} katkı {sağladı|verdi}.',
        '{{age}} yaşındaki {{player}}, {{club}} {formasıyla sahaya çıkmayı sürdürüyor|forması giymeye devam ediyor}.',
        '{{club}} {forması giyen|formasını terleten} {{player}}, {kariyerinde|profesyonel kariyerinde} {{goals}} gole {ulaştı|imza attı}.',
        '{Bilindiği gibi|Hatırlanacağı üzere}, {{player}} {Portekiz Milli Takımı|milli takım} formasıyla {{national_caps}} maça çıktı.',
        '{{player}} {profesyonel kariyerinde|kariyeri boyunca} {toplamda|toplam olarak} {{goals}} gol attı.',
        '{Kariyer istatistiklerine bakıldığında|İstatistiklere göz atıldığında}, {{player}} {{apps}} {maçta|karşılaşmada} {{goals}} gol {attı|kaydetti}.',
        '{{player}}, {asist konusunda da|takım arkadaşlarına gol hazırlamakta da} {etkili|başarılı}: {kariyerinde|kariyeri boyunca} {{assists}} asist.',
        '{{age}} yaşındaki {{player}}, {Portekiz Milli Takımı’nda|milli takımda} {{national_caps}} maça {ulaştı|çıktı}.',
        '{Transfermarkt verilerine göre|Güncel verilere göre}, {{player}} {milli takımda|Portekiz formasıyla} {{national_goals}} gol {kaydetti|attı}.',
        '{{club}} {formasını terleten|forması giyen} {{player}}, {kariyerinde|profesyonel kariyerinde} {{apps}} {maça|resmi maça} ulaştı.',
        '{Kariyer rakamlarına göre|Kariyer istatistiklerine göre} {{player}}, {{goals}} gol ve {{assists}} asistlik {bir skor katkısına|bir gol katkısına} sahip.',
    ],

    'outros' => [
        '{{player}} {ile ilgili|hakkında} {gelişmeleri|yeni haberleri} {takip etmeye devam edeceğiz|sizlere aktarmaya devam edeceğiz}.',
        '{Portekizli yıldızın|Ronaldo’nun} {kariyerine|kariyer yolculuğuna} dair {tüm ayrıntılara|ayrıntılı bilgilere} {Kariyer sayfamızdan|Kariyer bölümümüzden} ulaşabilirsiniz.',
        '{Güncel|En güncel} {istatistiklere|rakamlara} {İstatistikler sayfamızdan|İstatistikler bölümümüzden} göz atabilirsiniz.',
        '{Ronaldo’nun hayatına|Portekizli yıldızın hayatına} dair {merak edilen her şeyi|bilinmesi gereken her şeyi} {Hayatı sayfamızda|Hayatı bölümümüzde} bulabilirsiniz.',
        '{Konuyla ilgili|Bu konuyla ilgili} {yeni gelişmeler|gelişmeler} {sitemizde yer almaya devam edecek|takip edilmeye devam edecek}.',
        '{En son|Güncel} {Ronaldo haberleri|CR7 haberleri} için {Haberler bölümümüzü|haber sayfamızı} takip edebilirsiniz.',
        '{Ronaldo’nun kazandığı kupa ve ödüllerin|Ronaldo’nun kupalarının ve ödüllerinin} {tam listesine|tamamına} {Kupalar ve Ödüller sayfamızdan|Kupalar ve Ödüller bölümümüzden} ulaşabilirsiniz.',
        '{Ronaldo’nun oynadığı takımlar|Ronaldo’nun forma giydiği kulüpler} hakkında {ayrıntılı bilgi|detaylı bilgi} için {Oynadığı Takımlar sayfamıza|Takımlar bölümümüze} göz atabilirsiniz.',
        '{Ronaldo’nun|Portekizli yıldızın} {en güzel golleri|unutulmaz anları} {Videolar bölümümüzde|video sayfamızda} sizleri bekliyor.',
        '{Peki|Sizce} bu gelişme {{player}} {için ne ifade ediyor|açısından ne anlama geliyor}?',
        '{{player}} ile ilgili {yeni gelişmeler|sonraki gelişmeler} {merakla bekleniyor|merak konusu}.',
        '{Haberin devamı geldikçe|Konunun ayrıntıları netleştikçe} {sizlerle paylaşacağız|sitemizde yer alacak}.',
        '{{player}} ile ilgili {tüm haberler|bütün gelişmeler} için {sitemizi|CR7.com.tr’yi} takip etmeyi unutmayın.',
        '{Uzun yıllardır|Yıllardır} {futbolseverlerin|futbol dünyasının} {gündeminden düşmeyen|ilgi odağı olan} {{player}}, {konuşulmaya devam ediyor|gündemdeki yerini koruyor}.',
        '{CR7 dünyasındaki|Ronaldo dünyasındaki} {tüm gelişmeler|son gelişmeler} {sitemizde|CR7.com.tr’de}.',
        '{Ronaldo’nun kariyerine|Portekizli yıldızın kariyerine} {damga vuran|yön veren} {anları|dönüm noktalarını} {Hayatı sayfamızda|Kariyer sayfamızda} {okuyabilirsiniz|inceleyebilirsiniz}.',
        '{Ronaldo’nun|Portekizli yıldızın} {son maçlarını|son maçlarındaki performansını} {İstatistikler sayfamızda|İstatistikler bölümümüzde} {inceleyebilirsiniz|görebilirsiniz}.',
        '{{player}} hakkında {daha fazla bilgi|ayrıntılı bilgi} için {Hayatı|Kariyer} sayfamızı ziyaret edebilirsiniz.',
        '{Bu haberi|Bu gelişmeyi} {sosyal medyada|arkadaşlarınızla} paylaşmayı unutmayın.',
        '{Futbol dünyasının|Spor dünyasının} {en çok konuşulan|en çok ilgi gören} isimlerinden {{player}} ile ilgili {haberlerin|gelişmelerin} {takipçisi olmaya devam edeceğiz|peşini bırakmayacağız}.',
        '{İlerleyen günlerde|Önümüzdeki günlerde} {konuya dair|konuyla ilgili} {yeni ayrıntılar|yeni bilgiler} {ortaya çıkabilir|gün yüzüne çıkabilir}.',
        '{Konuya ilişkin|Konuyla ilgili} {resmi bir açıklama|yeni bir açıklama} {yapılması halinde|gelmesi durumunda} {sizlere aktaracağız|okurlarımızla paylaşacağız}.',
        '{Tüm zamanların|Bütün zamanların} en {büyük|önemli} futbolcularından biri {olarak gösterilen|kabul edilen} {{player}}, {hayranlarının|sevenlerinin} {ilgi odağı|gündeminde} olmayı sürdürüyor.',
        '{Siz|Sizler} de {{player}} ile ilgili {görüşlerinizi|düşüncelerinizi} sosyal medyada paylaşabilirsiniz.',
        '{Haberler|CR7 haberleri} {sayfamızda|bölümümüzde} {{player}} ile ilgili {diğer haberlere|son haberlere} {ulaşabilirsiniz|göz atabilirsiniz}.',
        '{Yeni gelişmeler|Yeni ayrıntılar} {ortaya çıktıkça|geldikçe} {haberlerimizde|sitemizde} yer alacak.',
        '{{player}} ile ilgili {her şey|tüm ayrıntılar} {CR7.com.tr’de|sitemizde}.',
    ],

    'connectors' => [
        'Öte yandan,',
        'Bunun yanı sıra,',
        'Ayrıca,',
        'Diğer taraftan,',
        'Buna ek olarak,',
        'Diğer yandan,',
        'Bunun yanında,',
        'Ek olarak,',
        'Bu arada,',
        'Bunlara ek olarak,',
        'Yeri gelmişken,',
        'Konuyla ilgili olarak,',
        'Haberin devamında,',
        'Bu gelişmenin yanı sıra,',
        'Belirtmek gerekir ki',
        'Bunun dışında,',
        'Öbür yandan,',
        'Öte taraftan,',
        'Bu noktada,',
        'Aynı zamanda',
        'Ayrıntılara bakıldığında,',
        'Konuya ilişkin olarak,',
    ],

    'title_patterns' => [
        '{{subject}} {{rest}}',
        '{{subject}}, {{rest}}',
        '{{subject}}: {{rest}}',
        '{{subject}} {{rest}}: {İşte ayrıntılar|İşte detaylar|Ayrıntılar haberimizde}',
        '{{subject}} {{rest}}, {işte ayrıntılar|işte detaylar}',
        '{{subject}} cephesinde {yeni|dikkat çeken} gelişme: {{rest}}',
        '{{subject}} {hakkında|ile ilgili} {dikkat çeken|yeni} gelişme: {{rest}}',
        '{Dikkat çeken|Yeni} gelişme: {{subject}} {{rest}}',
        '{{subject}} gündemde: {{rest}}',
        '{Tüm gözler|Gözler} {{subject}} üzerinde: {{rest}}',
        '{{subject}} {{rest}}: {Tüm ayrıntılar|Bütün ayrıntılar} haberimizde',
        '{{subject}} hakkında {merak edilen|konuşulan} gelişme: {{rest}}',
        '{Gündem|Gündemde}: {{subject}} {{rest}}',
        '{{subject}} {için|açısından} {yeni|dikkat çeken} gelişme: {{rest}}',
        '{Futbol gündemi|Spor gündemi}: {{subject}} {{rest}}',
        '{{subject}} {yine|bir kez daha} gündemde: {{rest}}',
    ],

    'source_line' => [
        '{Haberin kaynağı|Kaynak}: {{source}}',
        '{Haber kaynağı|Kaynak bilgisi}: {{source}}',
        'Bu haber {{source}} {kaynaklı bilgilerden|kaynaklı haberlerden} derlenmiştir.',
        '{Haberin|Bu haberin} {orijinal kaynağı|asıl kaynağı}: {{source}}',
        '{Daha fazla bilgi için|Ayrıntılı bilgi için}: {{source}}',
        'Haberin ayrıntıları için kaynağa göz atabilirsiniz: {{source}}',
        '{İlgili haberin kaynağı|Derlenen haberin kaynağı}: {{source}}',
    ],
];
