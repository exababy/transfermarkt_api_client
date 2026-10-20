<?php

declare(strict_types=1);

/*
 * Editorial content for the career pages (/kariyer and /takimlar/{slug}).
 * Trusted HTML (allowed tags: p, h3, ul, ol, li, strong, em, a, blockquote, table).
 * Keys of 'clubs' are Transfermarkt club ids; the second Manchester United spell uses
 * the key '985-2' and carries 'club_id' => '985'. Closed spells quote headline totals
 * only where they match Transfermarkt; Al-Nassr and Portugal totals are rendered live.
 * All facts verified against the sources listed at the bottom; last review 2026-10-20.
 * Typographic apostrophe (’) is used for Turkish proper-noun suffixes.
 */

return [
    'title' => 'Cristiano Ronaldo’nun Kariyeri',

    'last_reviewed' => '2026-10-20',

    'intro' => <<<'HTML'
<p><strong>Cristiano Ronaldo</strong>’nun profesyonel kariyeri, 2002’de Lizbon’da Sporting CP formasıyla başlayıp İngiltere, İspanya, İtalya ve Suudi Arabistan’a uzanan, yirmi yılı aşkın bir gol yolculuğudur. Madeira’da CF Andorinha ve CD Nacional’ın altyapılarında top koşturan Ronaldo, 12 yaşında Sporting akademisine katıldı; 18 yaşında Manchester United’a transfer olduğunda sağ kanatta çalımlarıyla göz dolduran bir gençti. Sir Alex Ferguson’ın yanında olgunlaşan, Real Madrid’de kulüp tarihinin en golcü oyuncusuna dönüşen, Juventus’ta İtalya’yı fetheden ve 2023’ten bu yana Al-Nassr’ın kaptanlığını yapan Ronaldo, kariyerinin her durağında gol rekorlarının sınırlarını zorladı.</p>
<p>Kulüp düzeyinde beş UEFA Şampiyonlar Ligi kupası (biri Manchester United, dördü Real Madrid ile), dört farklı ülkede toplam sekiz lig şampiyonluğu (Premier Lig, La Liga, Serie A ve Suudi Pro Lig) ve beş Ballon d’Or ödülü (2008, 2013, 2014, 2016, 2017) bu yolculuğun en parlak duraklarıdır. Şampiyonlar Ligi tarihinin en golcü oyuncusu olan Ronaldo, İngiltere, İspanya ve İtalya liglerinin üçünde de gol kralı olan ilk futbolcudur; Ağustos 2025’te ise dört farklı kulüpte (Manchester United, Real Madrid, Juventus ve Al-Nassr) 100’er gol barajını aşan ilk oyuncu unvanını aldı.</p>
<p>Portekiz Milli Takımı’ndaki serüveni de en az kulüp kariyeri kadar zengindir: EURO 2016 şampiyonluğu, iki UEFA Uluslar Ligi kupası (2019 ve 2025), altı Avrupa Şampiyonası ve altı Dünya Kupası’na katılım, erkek futbolunda milli maçlarda en çok gol atan ve en çok forma giyen oyuncu unvanları. Bu sayfada her kulüpteki dönemini; transfer hikâyesini, forma numarasını, teknik direktörlerini, unutulmaz maçlarını ve kazandığı kupaları ayrı ayrı bulabilirsiniz. Maç, gol ve asist sayılarının güncel hâli için <a href="/istatistikler">istatistikler</a> sayfamıza, kazandığı tüm kupalar ve bireysel ödüller için <a href="/kupalar-ve-oduller">Kupalar ve Ödüller</a> bölümüne göz atabilirsiniz.</p>
HTML,

    'clubs' => [

        // ------------------------------------------------------------------
        '336' => [
            'club_id' => '336',
            'slug' => 'sporting-cp',
            'name' => 'Sporting CP',
            'period' => '2002–2003',
            'from' => 2002,
            'to' => 2003,
            'country' => 'Portekiz',
            'summary' => 'Ronaldo, 2002–03 sezonunda 17 yaşında Sporting CP’nin A takımına yükseldi ve 6 Ağustos 2003’te Manchester United’a karşı oynanan hazırlık maçındaki performansıyla Old Trafford’un kapısını araladı.',
            'html' => <<<'HTML'
<p>Cristiano Ronaldo’nun profesyonel kariyeri, beş yıl boyunca altyapısında yetiştiği <strong>Sporting CP</strong>’de başladı. 2002 yazında teknik direktör <strong>László Bölöni</strong> tarafından A takıma alınan 17 yaşındaki kanat oyuncusu, kısa sürede Lizbon’un en çok konuşulan genç yeteneği hâline geldi. Sporting’deki forma numarası, bir yıl sonra Manchester United’da da talep edeceği <strong>28</strong>’di.</p>
<h3>İlk maç ve ilk goller</h3>
<p>Ronaldo, A takımdaki ilk resmî maçına 14 Ağustos 2002’de çıktı: UEFA Şampiyonlar Ligi ön eleme turunda Inter’in eski José Alvalade’ye konuk olduğu ve 0–0 biten karşılaşmada, ikinci yarının ortasında Tonito’nun yerine oyuna girerek yaklaşık yarım saat forma giydi. Sporting, rövanşı kaybederek elendi. Ronaldo, 19 Eylül 2002’de UEFA Kupası’nda Partizan’a 3–1 yenildikleri maçta 45 dakika forma giydi; Portekiz Ligi’ndeki (Primeira Liga) ilk maçına ise 29 Eylül 2002’de Braga deplasmanında çıktı.</p>
<p>Asıl çıkış 7 Ekim 2002’de geldi. Moreirense karşısında ilk kez lig maçında ilk 11’de başlayan ve 90 dakika sahada kalan Ronaldo, 3–0 kazanılan maçta takımının ikinci ve üçüncü gollerini kaydetti. Vitaliy Kutuzov’un açılış golünden yalnızca üç dakika sonra, 34. dakikada farkı ikiye çıkardı; ikinci golünü ise maçın uzatma dakikalarında serbest vuruştan gelen topa kafayla vurarak attı. Bu iki gol, kariyerinde yüzlerce kez tekrarlanacak bir sahnenin ilk perdesiydi: profesyonel futboldaki ilk gollerini 17 yaşında, yetiştiği kulübün formasıyla atmıştı.</p>
<h3>Bir kanat oyuncusunun doğuşu</h3>
<p>Sezon boyunca hem sağ hem sol kanatta görev alan Ronaldo; hızı, art arda yaptığı çalımları ve iki ayağını da kullanabilmesiyle takımın en heyecan verici oyuncularından biri oldu. Ancak 17–18 yaşındaki bir oyuncu için beklenebileceği gibi istikrarı ve skor katkısı henüz sınırlıydı. Transfermarkt verilerine göre Ronaldo, Sporting A takımında <strong>31 maçta 5 gol</strong> attı; aynı sezon Sporting B takımında da iki kez forma giydi.</p>
<p>Takım düzeyinde ise kariyerinin ilk kupası bu dönemde geldi: Sporting, 18 Ağustos 2002’de Leixões’i 5–1 yenerek 2002 Portekiz Süper Kupası’nı (Supertaça Cândido de Oliveira) kazandı; Ronaldo bu maçta kadroda yer aldı ancak oyuna girmedi. Ronaldo’nun Sporting’deki gelişimi, Portekiz’in genç milli takımlarına da yansıdı; 2001–2003 arasında U17, U20 ve U21 takımlarında forma giydi.</p>
<h3>Manchester United’ın radarında</h3>
<p>Avrupa’nın büyük kulüplerinin gözlemcileri Lizbon’daki bu genç yeteneği yakından izliyordu. BBC’nin aktardığına göre Manchester United’ın Sporting ile bir antrenörlük iş birliği anlaşması vardı ve İngiliz kulübü, Ronaldo’yu iki yıl daha Portekiz’de bırakıp ardından İngiltere’ye getirmeyi öngören gevşek bir ön anlaşmaya varmıştı. Bu planı değiştiren, tek bir hazırlık maçı oldu.</p>
<h3>Alvalade’nin açılış gecesi</h3>
<p>6 Ağustos 2003’te Sporting, yeni stadı Estádio José Alvalade’nin açılışını dolu tribünler önünde Manchester United ile oynadığı hazırlık maçıyla yaptı. Fernando Santos yönetimindeki Sporting, Luís Filipe’nin ve iki kez ağları sarsan João Pinto’nun golleriyle 3–1 kazandı. Gol atamasa da gecenin yıldızı Ronaldo’ydu: ABD turnesinden dönen United savunmasına defalarca zor anlar yaşatan 18 yaşındaki oyuncu, Sir Alex Ferguson’ı derinden etkiledi. BBC’nin aktardığına göre Ferguson, devre arasında kulübün CEO’su Peter Kenyon’a o oyuncuyu imzalatmadan stattan ayrılmayacaklarını söyledi ve United bekleme planından vazgeçip transferi hemen tamamlamaya karar verdi. Ferguson, transferi duyuran resmî açıklamada da Ronaldo’yu gördüğü en heyecan verici genç oyunculardan biri olarak nitelendirdi.</p>
<p>Yalnızca altı gün sonra, 12 Ağustos 2003’te Manchester United, Ronaldo’nun transferini <strong>12,24 milyon sterlin</strong> bedelle tamamladığını Londra Borsası’na bildirdi; Transfermarkt bu bedeli 19 milyon euro olarak kaydeder. Bu rakam, o dönemde İngiltere’de bir genç (20 yaş altı) oyuncu için ödenen en yüksek bonservis bedeliydi. Böylece Ronaldo’nun Sporting’deki A takım serüveni tek sezonla sınırlı kaldı; ancak hikâyenin geri kalanı bu kısa dönemin üzerine inşa edildi. Devamı için <a href="/takimlar/manchester-united">Manchester United</a> sayfasına göz atabilirsiniz.</p>
<h3>Kulüple süren bağ</h3>
<p>Sporting, altyapısından yetişen en ünlü oyuncuyla bağını kalıcı hâle getirmek için Alcochete’deki antrenman tesislerinin adını 21 Eylül 2020’de “Academia Cristiano Ronaldo” olarak değiştirdi. Bugün Sporting’in altyapısında yetişen genç futbolcular, Ronaldo’nun adını taşıyan bu tesislerde çalışıyor. Altyapı yıllarının ayrıntıları için <a href="/kariyer">kariyer</a> sayfamızdaki altyapı bölümüne bakabilirsiniz.</p>
HTML,
            'highlights' => [
                '14 Ağustos 2002: 17 yaşında Inter’e karşı Şampiyonlar Ligi ön elemesinde A takım çıkışı',
                '7 Ekim 2002: Moreirense’yi 3–0 yendikleri maçta profesyonel kariyerindeki ilk iki gol',
                'Forma numarası: 28',
                'A takıma yükselten teknik direktör: László Bölöni',
                'Transfermarkt’a göre A takımda 31 maç, 5 gol',
                '2002 Portekiz Süper Kupası: kariyerinin ilk takım kupası',
                '29 Eylül 2002: Braga deplasmanında Portekiz Ligi’ndeki ilk maçı',
                '6 Ağustos 2003: Yeni José Alvalade’nin açılışında Manchester United’ı 3–1 yenen takımda parladı',
                '12 Ağustos 2003: 12,24 milyon sterlin bedelle Manchester United’a transfer',
                '2020: Sporting’in Alcochete’deki tesisleri “Academia Cristiano Ronaldo” adını aldı',
            ],
            'honours' => [
                'Portekiz Süper Kupası (Supertaça Cândido de Oliveira) (1): 2002',
            ],
        ],

        // ------------------------------------------------------------------
        '985' => [
            'club_id' => '985',
            'slug' => 'manchester-united',
            'name' => 'Manchester United',
            'period' => '2003–2009',
            'from' => 2003,
            'to' => 2009,
            'country' => 'İngiltere',
            'summary' => 'Ronaldo, 2003–2009 arasında Sir Alex Ferguson yönetiminde gelişerek dünyanın en iyi oyuncusu oldu; Manchester United ile üç Premier Lig, bir UEFA Şampiyonlar Ligi kupası ve 2008 Ballon d’Or ödülünü kazandı.',
            'html' => <<<'HTML'
<p>12 Ağustos 2003’te 12,24 milyon sterlin karşılığında Sporting CP’den gelen Ronaldo, Manchester United forması giyen ilk Portekizli futbolcu oldu ve beş yıllık sözleşme imzaladı. Kendisi Sporting’deki 28 numarayı istemişti; ancak Sir Alex Ferguson ona George Best, Eric Cantona ve David Beckham’ın sırtında efsaneleşen <strong>7 numaralı</strong> formayı verdi. 18 yaşındaki bir oyuncuya bu formanın teslim edilmesi, kulübün ona duyduğu güvenin açık bir göstergesiydi.</p>
<h3>Uyum yılları (2003–2006)</h3>
<p>Ronaldo, 16 Ağustos 2003’te Old Trafford’da Bolton Wanderers’ın 4–0 yenildiği sezon açılış maçında 61. dakikada Nicky Butt’ın yerine oyuna girdi ve yarım saatlik bölümde sergilediği çalımlarla tribünleri ayağa kaldırdı. İlk golünü 1 Kasım 2003’te Portsmouth’a karşı serbest vuruştan attı. İlk sezonunun zirvesi ise 22 Mayıs 2004’teki FA Cup finali oldu: Millwall’ı 3–0 yenen United’ın ilk golünü kafayla atan Ronaldo, İngiltere’deki ilk kupasını kaldırdı. 2005–06 sezonunda Wigan Athletic’in 4–0 yenildiği Lig Kupası finalinde de gol attı.</p>
<p>Bu yıllarda Ronaldo’nun göz alıcı çalımları ve gösterişli hareketleri hem hayranlık hem eleştiri topluyordu. Ferguson’ın ve 2004’te yeniden yardımcılığa dönen Carlos Queiroz’un yanında geçen bu dönem, yeteneğini sonuç üreten bir oyuna dönüştürdüğü bir okul oldu. 2006 Dünya Kupası çeyrek finalinde Portekiz’in İngiltere’yi penaltılarla elediği maçta, kulüp arkadaşı Wayne Rooney’nin kırmızı kart gördüğü pozisyona karışması Ronaldo’yu İngiltere’de yoğun eleştirilerin hedefi yaptı. Ronaldo yaz aylarında ayrılmak istediğini kamuoyu önünde dile getirdi; United ise onu satmadı.</p>
<h3>Zirveye tırmanış (2006–2008)</h3>
<p>Eleştiriler, Ronaldo’nun kariyerindeki en büyük sıçramanın yakıtı oldu. 2006–07 sezonunda kariyerinde ilk kez bir sezonda 20 gol barajını aşan Ronaldo, United’ın Premier Lig şampiyonluğunda başrol oynadı. Sezon sonunda PFA Yılın Oyuncusu, PFA Yılın Genç Oyuncusu, PFA Taraftarların Yılın Oyuncusu ve FWA Yılın Futbolcusu ödüllerini topladı; Premier Lig de onu sezonun oyuncusu seçti. Artık yalnızca çalımlarıyla değil, gol ve asist sayılarıyla da maçların kaderini belirleyen bir oyuncu olmuştu.</p>
<p>2007–08 ise Ronaldo’nun Manchester United’daki en parlak sezonu oldu. Tüm kulvarlarda attığı <strong>42 golle</strong> bir orta saha oyuncusu olarak George Best’e ait olan kulüp rekorunu geride bıraktı; Premier Lig’de 31 golle gol kralı oldu ve Avrupa Altın Ayakkabı ödülünü kazanan ilk kanat oyuncusu unvanını aldı. 12 Ocak 2008’de Newcastle United’ın 6–0 yenildiği maçta United formasıyla ilk hat-trick’ini yaptı. Sezonun final perdesi 21 Mayıs 2008’de Moskova’daki Luzhniki Stadı’nda oynandı: Chelsea karşısındaki Şampiyonlar Ligi finalinde 26. dakikada Wes Brown’ın ortasına yükselerek kafayla ilk golü atan Ronaldo, seri penaltılarda vuruşunu Petr Čech’e kaptırsa da United 1–1’lik beraberliğin ardından penaltılarda 6–5 kazanarak kupayı aldı.</p>
<p>Bu performans Ronaldo’ya 2008 Ballon d’Or ödülünü getirdi; böylece 1968’deki George Best’ten bu yana ödülü kazanan ilk Manchester United oyuncusu oldu. Ocak 2009’da FIFA Yılın Oyuncusu seçilerek bu ödülü alan ilk Premier Lig oyuncusu unvanını da kazandı. UEFA ise onu 2007–08 sezonunun Yılın Kulüp Futbolcusu seçti. Aynı sezon hem Şampiyonlar Ligi’nin gol kralı oldu hem de Premier Lig’de üst üste ikinci kez sezonun oyuncusu ödülünü aldı; 2007’den 2021’e kadar her yıl FIFA FIFPro Dünya 11’inde yer alacaktı.</p>
<h3>Son sezon ve Puskás golü (2008–09)</h3>
<p>2008 yazında Real Madrid ile adı sıkça anılan Ronaldo, ayak bileği ameliyatı nedeniyle sezona geç başlasa da United’da kaldı ve 26 golle takımın en golcü oyuncusu olarak üst üste üçüncü Premier Lig şampiyonluğuna katkı verdi. Aralık 2008’de Japonya’da FIFA Kulüpler Dünya Kupası, Mart 2009’da ise Tottenham’ın penaltılarla geçildiği Lig Kupası finali kazanıldı. 15 Nisan 2009’da Porto’nun Estádio do Dragão’sunda, Şampiyonlar Ligi çeyrek final rövanşının 6. dakikasında yaklaşık 40 yarddan (37 metre civarı) attığı füze gibi şut United’ı 1–0’lık galibiyetle yarı finale taşıdı; bu gol, FIFA’nın ilk kez verdiği <strong>Puskás Ödülü</strong>’nü kazandı. Sezon, 27 Mayıs 2009’da Roma’daki Şampiyonlar Ligi finalinde Barcelona’ya 2–0 yenilgiyle kapandı.</p>
<h3>Rekor bedelle veda</h3>
<p>United, Real Madrid’in teklifini oyuncunun ayrılma isteğini yinelemesi üzerine 11 Haziran 2009’da kabul ettiğini açıkladı. <strong>80 milyon sterlinlik</strong> (yaklaşık 94 milyon euro) transfer 1 Temmuz 2009’da resmîleşti ve dönemin dünya rekoru oldu. Transfermarkt verilerine göre Ronaldo, ilk Manchester United döneminde <strong>292 maçta 118 gol</strong> attı. Hikâyenin devamı için <a href="/takimlar/real-madrid">Real Madrid</a> sayfasına göz atabilirsiniz.</p>
HTML,
            'highlights' => [
                '12 Ağustos 2003: 12,24 milyon sterlinle transfer; kulübün ilk Portekizli oyuncusu',
                'Forma numarası: 7 (George Best, Eric Cantona ve David Beckham’ın ardından)',
                'Teknik direktör: Sir Alex Ferguson (2004–2008 arasında yardımcısı Carlos Queiroz)',
                '22 Mayıs 2004: FA Cup finalinde Millwall’a karşı açılış golü',
                '2006–07: PFA Yılın Oyuncusu, PFA Yılın Genç Oyuncusu ve FWA Yılın Futbolcusu',
                '2007–08: Tüm kulvarlarda 42 gol, Premier Lig’de 31 golle gol krallığı ve Avrupa Altın Ayakkabı',
                '21 Mayıs 2008: Moskova’daki Şampiyonlar Ligi finalinde Chelsea’ye kafa golü',
                '2008 Ballon d’Or ve 2008 FIFA Yılın Oyuncusu ödülleri',
                '15 Nisan 2009: Porto’ya attığı uzak mesafe golüyle ilk FIFA Puskás Ödülü',
                'Transfermarkt’a göre ilk dönem: 292 maç, 118 gol',
            ],
            'honours' => [
                'Premier Lig (3): 2006–07, 2007–08, 2008–09',
                'FA Cup (1): 2003–04',
                'İngiltere Lig Kupası (2): 2005–06, 2008–09',
                'FA Community Shield (1): 2007',
                'UEFA Şampiyonlar Ligi (1): 2007–08',
                'FIFA Kulüpler Dünya Kupası (1): 2008',
            ],
        ],

        // ------------------------------------------------------------------
        '418' => [
            'club_id' => '418',
            'slug' => 'real-madrid',
            'name' => 'Real Madrid',
            'period' => '2009–2018',
            'from' => 2009,
            'to' => 2018,
            'country' => 'İspanya',
            'summary' => 'Ronaldo, 2009’da dönemin dünya rekoru bedeliyle geldiği Real Madrid’de dokuz sezonda kulüp tarihinin en golcü oyuncusu oldu; dört UEFA Şampiyonlar Ligi, iki La Liga ve dört Ballon d’Or kazandı.',
            'html' => <<<'HTML'
<p>Florentino Pérez’in Haziran 2009’da Real Madrid başkanlığına dönmesiyle başlayan büyük transfer atağının yıldızı Cristiano Ronaldo’ydu. Manchester United’ın 11 Haziran’da kabul ettiği <strong>80 milyon sterlinlik</strong> (yaklaşık 94 milyon euro) teklifle Ronaldo, dönemin dünya rekoru bonservis bedeliyle altı yıllık sözleşme imzaladı. 6 Temmuz 2009’da Santiago Bernabéu’da düzenlenen tanıtım törenine yaklaşık 80 bin taraftar katıldı; törende Ronaldo’nun yanında kulüp efsanesi Alfredo Di Stéfano ve Portekiz futbolunun efsane ismi Eusébio da vardı. İlk sezonunda <strong>9 numarayı</strong> giyen Ronaldo, Raúl’un kulüpten ayrılmasının ardından 2010–11’de <strong>7 numaraya</strong> geçti.</p>
<h3>Mourinho dönemi: Kanattan gol makinesine</h3>
<p>Manuel Pellegrini yönetimindeki ilk sezonunu bir ayak bileği sakatlığına rağmen 30’un üzerinde golle tamamlayan Ronaldo, 2010’da José Mourinho’nun gelişiyle sahanın her bölgesinden gol tehdidi yaratan bir hücum oyuncusuna dönüştü. 2010–11 sezonunda La Liga tarihinde bir sezonda 40 gole ulaşan ilk oyuncu oldu; Pichichi Ödülü’nü ve ikinci Avrupa Altın Ayakkabı ödülünü kazandı. Aynı sezon, 20 Nisan 2011’de Valencia’daki Mestalla’da oynanan Kral Kupası finalinde Barcelona karşısında uzatmaların 103. dakikasında attığı kafa golüyle Real Madrid’e 1–0’lık zaferi ve İspanya’daki ilk kupasını getirdi.</p>
<p>2011–12’de Real Madrid, rekor 100 puanla La Liga’yı kazanarak Barcelona’nın üstünlüğüne son verdi; Ronaldo tüm kulvarlarda 60 gol attı. Nisan 2012’de Camp Nou’da 2–1 kazanılan ve şampiyonluk yarışını büyük ölçüde belirleyen El Clásico’da galibiyet golü de onun imzasını taşıyordu. Ağustos 2012’de İspanya Süper Kupası da yine Barcelona karşısında kazanıldı.</p>
<h3>Ancelotti ve “La Décima”</h3>
<p>Carlo Ancelotti’nin 2013’te göreve gelmesiyle Ronaldo kariyerinin en etkileyici dönemlerinden birine girdi. 2013–14 Şampiyonlar Ligi’nde attığı <strong>17 golle</strong> tek sezonda turnuva rekoru kırdı; 24 Mayıs 2014’te Lizbon’da Atlético Madrid’in uzatmalarda 4–1 yenildiği finalde son golü penaltıdan attı ve kulübün 12 yıllık bekleyişine son veren onuncu Avrupa kupası, yani “La Décima” geldi. 2013 ve 2014 Ballon d’Or ödüllerini üst üste kazanan Ronaldo, 2014–15’te tüm kulvarlarda 61 gol (48’i ligde) atarak Real Madrid formasıyla en golcü sezonunu yaşadı. 2014’te UEFA Süper Kupa, Sevilla karşısında attığı iki golle kazanıldı; aynı yıl Fas’ta FIFA Kulüpler Dünya Kupası da müzeye girdi.</p>
<h3>Kulüp tarihinin en golcü oyuncusu</h3>
<p>17 Ekim 2015’te Bernabéu’da Levante’yi 3–0 yendikleri maçta attığı golle Raúl’un 323 gollük rekorunu geçen Ronaldo, Real Madrid tarihinin en golcü oyuncusu oldu. Raúl bu sayıya 741 maçta ulaşmışken Ronaldo’nun aynı noktayı geçmesi yalnızca 310 maç sürmüştü. Real Madrid formasıyla La Liga’da 34, tüm kulvarlarda ise toplam 44 hat-trick yaptı. Şampiyonlar Ligi’nde ise Real Madrid ile 105 gol attı.</p>
<h3>Zidane ile üç Şampiyonlar Ligi</h3>
<p>Ocak 2016’da Rafael Benítez’in yerine Zinedine Zidane’ın göreve gelmesi, eşi benzeri görülmemiş bir seriyi başlattı. 28 Mayıs 2016’da Milano’daki finalde Atlético Madrid ile 1–1 berabere kalındıktan sonra seri penaltılarda kupayı getiren son vuruşu Ronaldo yaptı. 2016–17’de Real Madrid hem La Liga’yı hem Şampiyonlar Ligi’ni kazandı: Ronaldo çeyrek finalde Bayern Münih’e, yarı finalde Atlético Madrid’e hat-trick yaptı; 3 Haziran 2017’de Cardiff’teki finalde Juventus’a attığı iki golle takımını 4–1’lik zafere taşıdı. Aralık 2016’da Japonya’daki Kulüpler Dünya Kupası finalinde Kashima Antlers’a hat-trick yaparak turnuvanın en iyi oyuncusu seçildi. 2016 ve 2017 Ballon d’Or ödülleri de ona gitti.</p>
<p>Real Madrid’deki son sezonunda da Avrupa sahnesindeki üstünlüğü sürdü. 3 Nisan 2018’de Torino’da Juventus’a karşı oynanan çeyrek final ilk maçında attığı rövaşata golü, rakip taraftarların bile ayakta alkışladığı, futbol tarihinin en ikonik anlarından biri oldu. 15 golle turnuvanın gol kralı olan Ronaldo, 26 Mayıs 2018’de Kiev’de Liverpool’un 3–1 yenildiği finalin ardından beşinci Şampiyonlar Ligi kupasını kaldırdı. Real Madrid böylece turnuvanın Şampiyonlar Ligi adını aldığı 1992’den bu yana üst üste üç kez kazanan ilk takım oldu.</p>
<h3>Veda</h3>
<p>Kiev finalinin hemen ardından geleceğine dair imalı sözleri gündeme oturan Ronaldo, 10 Temmuz 2018’de Juventus’a transfer oldu. Real Madrid, oyuncunun isteğini dikkate alarak transfere onay verdiğini açıkladı ve “Real Madrid her zaman senin evin olacak” mesajıyla veda etti. Transfermarkt verilerine göre Ronaldo, Real Madrid’de <strong>438 maçta 450 gol</strong> attı; maç başına bir golün üzerindeki bu ortalama, onu kulübün bugüne kadarki en golcü oyuncusu yaptı. Torino’daki yeni dönemi için <a href="/takimlar/juventus">Juventus</a> sayfasına göz atabilirsiniz.</p>
HTML,
            'highlights' => [
                '2009: 80 milyon sterlin (94 milyon euro) ile dönemin dünya rekoru transferi',
                '6 Temmuz 2009: Bernabéu’da yaklaşık 80 bin taraftar önünde tanıtıldı',
                'Forma numarası: 9 (2009–10), ardından 7',
                'Teknik direktörler: Manuel Pellegrini, José Mourinho, Carlo Ancelotti, Rafael Benítez, Zinedine Zidane',
                '20 Nisan 2011: Kral Kupası finalinde Barcelona’ya uzatmada kafa golü',
                '2010–11: La Liga tarihinde bir sezonda 40 gole ulaşan ilk oyuncu',
                '2013–14: Şampiyonlar Ligi’nde tek sezonda rekor 17 gol ve La Décima',
                '17 Ekim 2015: Raúl’u geçerek kulübün tüm zamanların en golcü oyuncusu oldu',
                '3 Nisan 2018: Torino’da Juventus’a efsanevi rövaşata golü',
                'Transfermarkt’a göre 438 maç, 450 gol; kulüpte 44 hat-trick',
            ],
            'honours' => [
                'La Liga (2): 2011–12, 2016–17',
                'İspanya Kral Kupası (2): 2010–11, 2013–14',
                'İspanya Süper Kupası (2): 2012, 2017',
                'UEFA Şampiyonlar Ligi (4): 2013–14, 2015–16, 2016–17, 2017–18',
                'UEFA Süper Kupa (2): 2014, 2017',
                'FIFA Kulüpler Dünya Kupası (3): 2014, 2016, 2017',
            ],
        ],

        // ------------------------------------------------------------------
        '506' => [
            'club_id' => '506',
            'slug' => 'juventus',
            'name' => 'Juventus',
            'period' => '2018–2021',
            'from' => 2018,
            'to' => 2021,
            'country' => 'İtalya',
            'summary' => 'Ronaldo, 2018’de 100 milyon euroya Juventus’a transfer oldu; üç sezonda iki Serie A şampiyonluğu yaşadı ve 2020–21’de Serie A gol kralı olarak İngiltere, İspanya ve İtalya’da gol krallığı kazanan ilk oyuncu oldu.',
            'html' => <<<'HTML'
<p>Real Madrid’den ayrılma kararı, Ronaldo’yu kariyerinin üçüncü büyük Avrupa ligine taşıdı. Yalnızca üç ay önce Torino’da attığı rövaşata golüyle Juventus taraftarlarının ayakta alkışladığı oyuncu, artık o tribünlerin kendi oyuncusuydu. Juventus, 10 Temmuz 2018’de yaptığı resmî açıklamada Real Madrid ile iki mali yıla yayılan <strong>100 milyon euroluk</strong> bonservis bedelinde anlaştığını, buna FIFA dayanışma katkısı ve 12 milyon euroluk ek masrafların ekleneceğini duyurdu. 33 yaşındaki Ronaldo, 30 Haziran 2022’ye kadar sürecek dört yıllık bir sözleşme imzaladı. Bu bedel, o güne kadar 30 yaşını geçmiş bir oyuncu için ödenen ve bir İtalyan kulübünün ödediği en yüksek bonservis olarak kayıtlara geçti. Ronaldo, Torino’da da <strong>7 numaralı</strong> formayı giydi.</p>
<h3>Allegri ile ilk sezon (2018–19)</h3>
<p>Massimiliano Allegri yönetimindeki ilk üç lig maçında gol bulamayan Ronaldo, 16 Eylül 2018’de Sassuolo karşısında attığı iki golle Juventus’taki hesabını açtı. Üç gün sonra Valencia deplasmanında oynanan Şampiyonlar Ligi maçında ise kırmızı kart gördü. 16 Ocak 2019’da Suudi Arabistan’ın Cidde kentinde oynanan İtalya Süper Kupası maçında Milan’a karşı attığı kafa golü, Juventus’a 1–0’lık galibiyeti ve Ronaldo’ya İtalya’daki ilk kupasını getirdi.</p>
<p>Sezonun en unutulmaz gecesi 12 Mart 2019’da yaşandı. Şampiyonlar Ligi son 16 turunun ilk maçında Atlético Madrid’e 2–0 yenilen Juventus, Torino’daki rövanşta Ronaldo’nun hat-trick’iyle 3–0 kazanarak tur atladı. Takım çeyrek finalde Ajax’a elendi; Ronaldo bu eşleşmenin Amsterdam ve Torino’daki iki maçında da gol atsa da sürpriz yenilginin önüne geçemedi. Juventus, Serie A’yı ise rahat bir farkla kazandı. Ronaldo böylece İngiltere, İspanya ve İtalya’da lig şampiyonluğu yaşayan ilk futbolcu oldu ve Serie A’da sezonun en değerli oyuncusu (MVP) seçildi.</p>
<h3>Sarri dönemi (2019–20)</h3>
<p>Maurizio Sarri’nin göreve geldiği 2019–20 sezonunda Ronaldo, Serie A’da <strong>31 gol</strong> atarak İtalya’daki en golcü lig sezonunu yaşadı. Üst üste 11 Serie A maçında gol atarak Gabriel Batistuta ve Fabio Quagliarella’nın elindeki lig rekoruna ortak oldu. COVID-19 salgını nedeniyle uzun bir aranın ardından tamamlanan sezonda Juventus üst üste dokuzuncu kez şampiyon oldu. Kupalarda ise şans yüzüne gülmedi: Aralık 2019’da Riyad’da oynanan İtalya Süper Kupası’nda Lazio’ya 3–1 yenildiler, Haziran 2020’deki İtalya Kupası finalini de Napoli’ye penaltılarla kaybettiler. Şampiyonlar Ligi’nde de son 16 turunda Lyon’a elendiler; Ronaldo’nun rövanşta attığı iki gol tur için yeterli olmadı.</p>
<h3>Pirlo dönemi ve gol krallığı (2020–21)</h3>
<p>Andrea Pirlo’nun teknik direktör olduğu 2020–21 sezonu kulüp için daha çalkantılı geçti. Juventus, dokuz yıllık şampiyonluk serisini Inter’e kaptırdı, ligi dördüncü sırada tamamlayarak Şampiyonlar Ligi biletini son haftada alabildi ve Avrupa’da son 16 turunda Porto’ya elendi. Buna karşın sezon kupasız geçmedi: 20 Ocak 2021’de Napoli’nin 2–0 yenildiği İtalya Süper Kupası maçında Ronaldo gol attı; 19 Mayıs 2021’de Atalanta’nın 2–1 yenildiği finalle de İtalya Kupası kazanıldı. Ronaldo bu sezon <strong>29 golle Serie A gol kralı</strong> oldu ve İngiltere, İspanya ve İtalya liglerinin üçünde de gol krallığı yaşayan ilk futbolcu unvanını aldı. Aynı dönemde Juventus formasıyla 100. golüne 131. maçında ulaşarak bu barajı kulüp tarihinde en hızlı geçen oyuncu oldu.</p>
<h3>Ayrılık</h3>
<p>2021–22 sezonuna Juventus oyuncusu olarak başlayan Ronaldo, yaz transfer döneminin son günlerinde eski kulübü Manchester United’a döndü. Juventus’un açıkladığı bonservis bedeli 15 milyon euro artı performansa bağlı 8 milyon euroluk bonuslardı. Transfermarkt verilerine göre Ronaldo, Juventus’ta <strong>134 maçta 101 gol</strong> attı ve üç sezonda beş kupa kazandı. Old Trafford’a dönüş hikâyesi için <a href="/takimlar/manchester-united">Manchester United</a> sayfasına bakabilirsiniz.</p>
<h3>Maaş anlaşmazlığı</h3>
<p>Ayrılığın ardından taraflar arasında hukuki bir anlaşmazlık da yaşandı. Ronaldo, COVID-19 salgını döneminde ertelenen maaşlarına ilişkin yaklaşık 19,6 milyon euroluk bir alacak talebiyle eski kulübüne karşı süreç başlattı. Nisan 2024’te bir tahkim heyeti, oy çokluğuyla Juventus’un Ronaldo’ya yaklaşık 9,8 milyon euro artı faiz ödemesine karar verdi; heyet, kulübün oyuncuyu yanıltma kastı taşımadığını da kayda geçirdi. Juventus’un bu karara itirazı Ocak 2026’da Torino İş Mahkemesi tarafından reddedildi. Kulüp, 27 Mart 2026’da İtalya’nın en yüksek yargı mercii olan Yargıtay’a (Corte di Cassazione) başvurdu ve Ekim 2026 itibarıyla duruşma tarihi bekleniyordu.</p>
HTML,
            'highlights' => [
                '10 Temmuz 2018: 100 milyon euro (artı 12 milyon euro ek masraf) ile transfer',
                'Forma numarası: 7',
                'Teknik direktörler: Massimiliano Allegri, Maurizio Sarri, Andrea Pirlo',
                '16 Ocak 2019: İtalya Süper Kupası’nda Milan’a karşı tek gol',
                '12 Mart 2019: Atlético Madrid’e hat-trick ile ilk maçtaki 0–2’lik farkı çevirdi',
                '2019: İngiltere, İspanya ve İtalya’da lig şampiyonu olan ilk futbolcu',
                '2019–20: Serie A’da 31 gol ve üst üste 11 maçta gol',
                '2020–21: 29 golle Serie A gol kralı; üç büyük ligde gol krallığı yaşayan ilk oyuncu',
                'Kulüp tarihinde 100 gole en hızlı ulaşan oyuncu (131 maç)',
                'Transfermarkt’a göre 134 maç, 101 gol',
            ],
            'honours' => [
                'Serie A (2): 2018–19, 2019–20',
                'İtalya Kupası (1): 2020–21',
                'İtalya Süper Kupası (2): 2018, 2020',
            ],
        ],

        // ------------------------------------------------------------------
        '985-2' => [
            'club_id' => '985',
            'slug' => 'manchester-united',
            'name' => 'Manchester United',
            'period' => '2021–2022',
            'from' => 2021,
            'to' => 2022,
            'country' => 'İngiltere',
            'summary' => 'Ronaldo, Ağustos 2021’de Old Trafford’a dönerek ilk sezonunda 24 gol attı; ancak 2022–23 sezonunda yaşanan gerginlikler, sözleşmesinin Kasım 2022’de karşılıklı anlaşmayla feshedilmesiyle sonuçlandı.',
            'html' => <<<'HTML'
<p>Ağustos 2021’in son haftası, Ronaldo’nun kariyerindeki en sürpriz dönüşlerden birine sahne oldu. Manchester United, 27 Ağustos 2021’de Juventus ile anlaştığını duyurdu; transfer 31 Ağustos’ta sağlık kontrolü, vize işlemleri ve kişisel şartların tamamlanmasının ardından resmîleşti. United, Juventus’a <strong>15 milyon euro</strong> sabit bedel ve performansa bağlı 8 milyon euroya kadar bonus ödemeyi kabul etti. 36 yaşındaki oyuncu, bir yıl uzatma opsiyonlu iki yıllık sözleşme imzaladı ve bu dönüşü hocası Sir Alex Ferguson’a adadı. Kulübün açıklamasında Manchester United’ın kalbinde her zaman özel bir yeri olduğunu, transferin duyurulmasının ardından aldığı mesajlar karşısında çok duygulandığını söyledi; sosyal medyadaki mesajında ise rakip olarak Old Trafford’a geldiği yıllarda bile tribünlerden gördüğü sevgi ve saygı nedeniyle bu dönüşün bir hayalin gerçekleşmesi olduğunu yazdı. Edinson Cavani’nin 21 numaraya geçmeyi kabul etmesiyle ikonik <strong>7 numara</strong> yeniden Ronaldo’nun oldu.</p>
<h3>Görkemli “ikinci ilk maç”</h3>
<p>11 Eylül 2021’de Old Trafford’da Newcastle United’ın 4–1 yenildiği maçta iki gol atan Ronaldo, dönüşünü unutulmaz kıldı. 29 Eylül’de Villarreal’e karşı son dakikada attığı golle 2–1’lik galibiyeti getirirken Iker Casillas’ı geçerek Şampiyonlar Ligi tarihinde en çok maça çıkan oyuncu oldu. Grup aşamasının ilk beş maçının tamamında gol atan Ronaldo, özellikle Atalanta’ya karşı oynanan iki maçta son dakikalara yakın attığı kritik gollerle takımın gruptan çıkmasında belirleyici rol üstlendi. Ekim 2021’de Liverpool’a evinde 5–0 yenilen takım, birkaç gün sonra Tottenham deplasmanında 3–0 kazandı; Ronaldo bu maçta hem gol attı hem de asist yaptı. 2 Aralık 2021’de Arsenal’in 3–2 yenildiği maçta attığı iki golle kariyerinde 800 resmî gol barajını aştı.</p>
<p>Ancak takımın genel gidişatı Ronaldo’nun bireysel performansıyla örtüşmüyordu. Ole Gunnar Solskjær Kasım 2021’de görevden alındı; Michael Carrick’in kısa geçici döneminin ardından takımı geçici teknik direktör Ralf Rangnick devraldı. Ronaldo, Manchester City’ye 4–1 yenildikleri derbinin ardından oynanan 12 Mart 2022’deki Tottenham maçında 3–2’lik galibiyeti getiren hat-trick’i yaptı; 16 Nisan 2022’de Norwich City’ye karşı da üç gol attı ve galibiyet golünü serbest vuruştan kaydetti. Nisan ayında Arsenal deplasmanında 3–1 kaybedilen maçta da Premier Lig’deki 100. golünü attı. Eylül 2021 ve Nisan 2022’de Premier Lig’in Ayın Oyuncusu seçildi.</p>
<p>Ronaldo sezonu tüm kulvarlarda <strong>24 golle</strong> (18’i Premier Lig’de) takımın en golcü oyuncusu olarak tamamladı; ligde yalnızca Mohamed Salah ve Son Heung-min ondan fazla gol attı. Taraftar oylamasıyla belirlenen Sir Matt Busby Yılın Oyuncusu ödülünü kazandı ve PFA Premier Lig Yılın Takımı’na seçildi. Buna karşın United ligi altıncı sırada bitirdi, Şampiyonlar Ligi’nde son 16 turunda Atlético Madrid’e elendi ve Ronaldo 2009–10’dan bu yana ilk kez kupasız bir sezon geçirdi.</p>
<h3>Ten Hag dönemi ve gerginlik</h3>
<p>2022 yazında Erik ten Hag’ın göreve gelmesiyle Ronaldo’nun takımdaki rolü küçüldü. Temmuz 2022’de ailevi nedenleri gerekçe göstererek takımın Tayland ve Avustralya’daki hazırlık turnesine katılmadı. Basına yansıyan haberlere göre Şampiyonlar Ligi oynayan bir kulübe gitmek istediğini kulübe iletse de tatmin edici bir teklif gelmedi. Yedek kulübesinde daha fazla zaman geçiren Ronaldo, 9 Ekim 2022’de Everton deplasmanında kariyerindeki 700. kulüp golünü attı. 19 Ekim’deki Tottenham maçı bitmeden soyunma odasına gitmesinin ardından bir maç kadro dışı bırakıldı; 27 Ekim’de kadroya döndüğü ve Sheriff Tiraspol’ün 3–0 yenildiği UEFA Avrupa Ligi maçında gol attı.</p>
<p>Kasım 2022’de Piers Morgan’a TalkTV için verdiği röportajda kulüp tarafından “ihanete uğramış” hissettiğini söyleyen ve teknik direktöre saygı duymadığını belirten Ronaldo’nun açıklamaları büyük yankı uyandırdı. Kulüp, röportaja karşılık “uygun adımları” başlattığını duyurdu. 22 Kasım 2022’de, Ronaldo Katar’da Dünya Kupası kampındayken, tarafların karşılıklı anlaşmayla sözleşmeyi derhâl sona erdirdiği açıklandı. Kulüp açıklamasında Ronaldo’ya iki dönemde 346 maçta attığı 145 golle verdiği büyük katkı için teşekkür etti; Ronaldo ise Manchester United’ı ve taraftarlarını her zaman seveceğini, ancak yeni bir meydan okuma zamanının geldiğini söyledi.</p>
<h3>Rakamlarla ikinci dönem</h3>
<p>Transfermarkt verilerine göre Ronaldo, ikinci Manchester United döneminde <strong>54 maçta 27 gol</strong> attı; iki dönemin toplamı ise <strong>346 maç ve 145 gol</strong>dür. Bu dönem kupasız geçse de 2021–22’deki 24 gol, 36–37 yaşlarındaki bir oyuncunun Premier Lig’de hâlâ fark yaratabildiğini gösterdi. Ronaldo’nun bir sonraki durağı Suudi Arabistan oldu: <a href="/takimlar/al-nassr">Al-Nassr</a>.</p>
HTML,
            'highlights' => [
                '31 Ağustos 2021: 15 milyon euro ve 8 milyon euroya kadar bonusla Juventus’tan dönüş',
                'Forma numarası: 7 (Edinson Cavani 21 numaraya geçti)',
                'Teknik direktörler: Ole Gunnar Solskjær, Michael Carrick (geçici), Ralf Rangnick (geçici), Erik ten Hag',
                '11 Eylül 2021: Newcastle United’a karşı iki golle dönüş maçı',
                '29 Eylül 2021: Villarreal’e son dakika golü; Şampiyonlar Ligi’nde en çok maça çıkan oyuncu',
                '12 Mart 2022: Tottenham’a, 16 Nisan 2022: Norwich City’ye hat-trick',
                '2021–22: 24 gol (18’i Premier Lig’de), Sir Matt Busby Yılın Oyuncusu ve PFA Yılın Takımı',
                '9 Ekim 2022: Everton’a karşı kariyerindeki 700. kulüp golü',
                '22 Kasım 2022: Sözleşme karşılıklı anlaşmayla feshedildi',
                'Transfermarkt’a göre ikinci dönem: 54 maç, 27 gol (iki dönem toplamı 346 maç, 145 gol)',
            ],
            'honours' => [],
        ],

        // ------------------------------------------------------------------
        '18544' => [
            'club_id' => '18544',
            'slug' => 'al-nassr',
            'name' => 'Al-Nassr',
            'period' => '2023–',
            'from' => 2023,
            'to' => null,
            'country' => 'Suudi Arabistan',
            'summary' => 'Ronaldo, Ocak 2023’ten bu yana Suudi Arabistan’ın Al-Nassr kulübünde forma giyiyor ve takımın kaptanlığını yapıyor; 2025–26’da Suudi Pro Lig şampiyonluğunu kazanarak dört farklı ülkede lig şampiyonu oldu. Transfermarkt’a göre sözleşmesi 30 Haziran 2027’ye kadar sürüyor.',
            'html' => <<<'HTML'
<p>Manchester United’dan ayrılmasının ve 2022 Dünya Kupası’nın ardından Ronaldo’nun yeni adresi, Avrupa futbolunda pek az kişinin öngördüğü bir rota oldu. Riyad ekibi <strong>Al-Nassr</strong>, 30 Aralık 2022’de Ronaldo ile Haziran 2025’e kadar sürecek iki buçuk yıllık bir sözleşme imzaladığını duyurdu. Serbest oyuncu statüsünde gerçekleşen transferde bonservis ödenmedi; basına yansıyan haberlere göre sözleşmenin yıllık toplam değeri 200 milyon euro civarındaydı. 3 Ocak 2023’te Mrsool Park’ta taraftarlara tanıtılan Ronaldo, burada da <strong>7 numarayı</strong> giydi ve takımın kaptanı oldu.</p>
<h3>Suudi Arabistan’a uyum (2023)</h3>
<p>Ronaldo, Al-Nassr formasıyla ilk resmî maçına 22 Ocak 2023’te Al-Ettifaq karşısında kaptan olarak çıktı; ilk golünü son dakikada kullandığı penaltıyla Al-Fateh ile 2–2 berabere kalınan maçta attı. 9 Şubat 2023’te Al-Wehda’nın 4–0 yenildiği maçta dört golün tamamını attı; bu gollerden biri kariyerindeki 500. lig golüydü. Rudi Garcia ile başlayan ilk yarım sezon, Al-Nassr’ın ligi ikinci sırada bitirmesiyle sona erdi.</p>
<p>2023 yazında kulüp Sadio Mané, Marcelo Brozović ve Aymeric Laporte gibi isimleri kadrosuna katarken Ronaldo da Suudi Arabistan’daki ilk kupasını kaldırdı. 12 Ağustos 2023’te oynanan Arap Kulüpler Şampiyonlar Kupası finalinde ezeli rakip Al-Hilal’e karşı uzatmalara giden maçta iki gol atan Ronaldo, Al-Nassr’ı 2–1’lik zafere taşıdı ve turnuvayı altı golle gol kralı olarak tamamladı. Bu turnuva Arap Futbol Birlikleri Birliği (UAFA) tarafından düzenlenir; Asya Futbol Konfederasyonu’nun (AFC) kulüp turnuvalarından biri değildir. Ronaldo, 2023 takvim yılını kulübü ve milli takımıyla attığı (bu turnuvadaki goller dâhil) 54 golle dünyanın en golcü futbolcusu olarak kapattı.</p>
<h3>Rekorlar ve kaçan kupalar (2023–2025)</h3>
<p>Luís Castro yönetimindeki 2023–24 sezonunda Ronaldo, Suudi Pro Lig’de <strong>35 gol</strong> atarak Abderrazak Hamdallah’ın 34 gollük tek sezon rekorunu kırdı ve dört farklı ülkenin liginde gol krallığı yaşayan ilk oyuncu oldu. Ancak Al-Nassr ligi Al-Hilal’in ardından ikinci sırada bitirdi; 31 Mayıs 2024’teki Kral Kupası finalinde de 1–1’lik beraberliğin ardından Al-Hilal’e penaltılarla kaybetti. Stefano Pioli’nin çalıştırdığı 2024–25 sezonunda Ronaldo 25 lig golüyle üst üste ikinci kez gol kralı oldu; takım AFC Şampiyonlar Ligi Elite’te yarı finale yükselse de 30 Nisan 2025’te Kawasaki Frontale’ye 3–2 yenildi. 26 Haziran 2025’te Ronaldo, Al-Nassr ile sözleşmesini iki yıl uzatarak <strong>30 Haziran 2027</strong>’ye kadar kulüpte kalmayı kabul etti.</p>
<h3>Jorge Jesus ile gelen şampiyonluk (2025–26)</h3>
<p>Temmuz 2025’te takımın başına Portekizli teknik direktör Jorge Jesus geçti. Sezon, 23 Ağustos 2025’te Hong Kong’da oynanan Suudi Süper Kupası finaliyle açıldı: Ronaldo, Al-Ahli karşısında penaltıdan attığı golle Al-Nassr formasıyla 100. golüne ulaştı ve dört farklı kulüpte 100’er gol atan ilk futbolcu oldu. Ancak 2–2 biten maçın ardından Al-Ahli penaltılarda 5–3 kazandı.</p>
<p>Şubat 2026’nın başında ESPN ve BBC’nin haberlerine göre Ronaldo, kulübün çoğunluk hissedarı olan Suudi Kamu Yatırım Fonu’nun (PIF) kış transfer döneminde Al-Nassr’a yeterli destek vermediğini düşünerek iki lig maçında forma giymedi. Suudi Pro Lig, bu süreçte hiçbir bireyin kendi kulübünün ötesindeki kararları belirleyemeyeceğini açıkladı. ESPN’in aktardığına göre gecikmiş maaşların ödenmesi ve kulüp yöneticilerinin yetkilerinin iade edilmesinin ardından Ronaldo, 14 Şubat 2026’da Al-Fateh deplasmanında takıma döndü ve 18. dakikada gol attı.</p>
<p>Sezonun sonu iki farklı duyguyu bir araya getirdi. 16 Mayıs 2026’da Riyad’da oynanan AFC Şampiyonlar Ligi İki (ACL Two) finalinde Al-Nassr, Japon ekibi Gamba Osaka’ya 1–0 yenildi. Beş gün sonra, 21 Mayıs 2026’daki son hafta maçında ise Damac’ı 4–1 yenen Al-Nassr, Al-Hilal’in iki puan önünde Suudi Pro Lig şampiyonluğunu ilan etti. Ronaldo bu maçta biri serbest vuruştan iki gol attı. Bu, Al-Nassr’ın tarihindeki 11. ve 2018–19’dan bu yana ilk lig şampiyonluğuydu; Ronaldo için ise İngiltere, İspanya ve İtalya’nın ardından dördüncü ülkede kazanılan, kariyerindeki sekizinci lig şampiyonluğuydu. 41 yaşındaki Ronaldo, sezonu 28 lig golüyle Julián Quiñones ve Ivan Toney’nin ardından gol krallığı yarışında üçüncü sırada tamamladı.</p>
<h3>2026–27 ve “son sezon” sinyali</h3>
<p>Sezon sonunda Jorge Jesus’un kulüpten ayrılmasının ardından Al-Nassr, 3 Temmuz 2026’da eski Tottenham teknik direktörü Ange Postecoglou’yu iki yıllık sözleşmeyle göreve getirdi; Jesus ise 10 Temmuz’da Portekiz Milli Takımı’nın başına geçti. Ağustos 2026’da Georgina Rodríguez ile evlenen Ronaldo, düğünüyle ilgili olarak Vogue’a verdiği röportajda “Bu muhtemelen futboldaki son yılım ve muhteşem bir miras bırakmak istiyorum” diyerek 2026–27 sezonunun kariyerinin son sezonu olabileceğini dile getirdi. Transfermarkt’a göre Ronaldo’nun Al-Nassr ile sözleşmesi 30 Haziran 2027’de sona eriyor. Al-Nassr’daki güncel maç, gol ve asist sayıları için <a href="/istatistikler">istatistikler</a> sayfamıza bakabilirsiniz.</p>
HTML,
            'highlights' => [
                '30 Aralık 2022: Serbest oyuncu olarak imza; ilk sözleşme Haziran 2025’e kadar',
                'Forma numarası: 7; takım kaptanı',
                'Başlıca teknik direktörler: Rudi Garcia, Luís Castro, Stefano Pioli, Jorge Jesus, Ange Postecoglou',
                '12 Ağustos 2023: Arap Kulüpler Şampiyonlar Kupası finalinde Al-Hilal’e iki gol',
                '2023–24: 35 golle Suudi Pro Lig’de tek sezon gol rekoru',
                '2023–24 ve 2024–25: Üst üste iki sezon Suudi Pro Lig gol kralı',
                '26 Haziran 2025: Sözleşme 30 Haziran 2027’ye uzatıldı',
                '23 Ağustos 2025: Al-Nassr’daki 100. golü; dört farklı kulüpte 100’er gol atan ilk futbolcu',
                '21 Mayıs 2026: Damac’a iki golle Suudi Pro Lig şampiyonluğu; dördüncü ülkede lig şampiyonluğu',
            ],
            'honours' => [
                'Suudi Pro Lig (1): 2025–26',
                'Arap Kulüpler Şampiyonlar Kupası (1): 2023 (UAFA turnuvası)',
            ],
        ],
    ],

    'youth' => [
        'title' => 'Altyapı Yılları',
        'period' => '1992–2002',
        'html' => <<<'HTML'
<p>Cristiano Ronaldo’nun futbolla tanışması, doğup büyüdüğü Funchal’ın Santo António semtinde oldu. Babası José Dinis Aveiro’nun yarı zamanlı malzemeci olarak çalıştığı mahalle kulübü <strong>CF Andorinha</strong>, 1992–1995 yılları arasında küçük Cristiano’nun ilk takımıydı; Ronaldo futbolu ilk kez bir kulüp çatısı altında burada oynadı.</p>
<p>1995’te Madeira’nın köklü kulüplerinden <strong>CD Nacional</strong>’ın altyapısına geçen Ronaldo, iki yıl boyunca burada forma giydi. Adadaki yeteneğinin haberi kısa sürede Lizbon’a ulaştı ve 1997’de, 12 yaşındayken Sporting CP’de üç günlük bir deneme sürecine çağrıldı. Denemeyi başarıyla geçen Ronaldo’yu Sporting mütevazı bir bedel karşılığında kadrosuna kattı; Cristiano da ailesinden ayrılarak tek başına Lizbon’a taşındı ve kulübün altyapısına katıldı.</p>
<p><strong>Sporting akademisindeki</strong> yıllar hem sportif hem kişisel açıdan zorlu geçti. Lizbon’un Telheiras semtindeki okula devam eden Ronaldo, 14 yaşında annesi ve Sporting’deki eğitmeni Leonel Pontes ile birlikte okulu bırakıp tüm enerjisini futbola verme kararı aldı. Bir yıl sonra kendisine kariyerini tehdit eden bir kalp ritmi bozukluğu (taşikardi) teşhisi konuldu; sorun, kalp ablasyonu işlemiyle giderildi ve Ronaldo birkaç saat içinde taburcu edilerek kısa sürede antrenmanlara döndü.</p>
<p>Akademinin yaş gruplarında hızla yükselen Ronaldo, 2001’den itibaren Portekiz’in U15 ve U17 milli takımlarında da forma giydi. 2002 yazında teknik direktör László Bölöni tarafından A takıma yükseltildiğinde henüz 17 yaşındaydı. Sporting, Alcochete’deki antrenman tesislerine 2020’de onun adını vererek “Academia Cristiano Ronaldo” yaptı. A takım dönemine dair ayrıntılar için <a href="/takimlar/sporting-cp">Sporting CP</a> sayfasına, ailesi ve çocukluğu için <a href="/hayati">Hayatı</a> bölümüne bakabilirsiniz.</p>
HTML,
        'highlights' => [
            '1992–1995: Babasının malzemeci olarak çalıştığı CF Andorinha’da ilk takım deneyimi',
            '1995–1997: Madeira’nın köklü kulübü CD Nacional’ın altyapısı',
            '1997: 12 yaşında Sporting CP’nin üç günlük denemesini geçerek Lizbon’a taşındı',
            '15 yaşında teşhis edilen taşikardi, kalp ablasyonu ile tedavi edildi',
            '2001–2002: Portekiz U15 ve U17 milli takımlarında forma giydi',
            '2002: László Bölöni tarafından 17 yaşında Sporting A takımına yükseltildi',
            '2020: Sporting’in Alcochete’deki tesisleri “Academia Cristiano Ronaldo” adını aldı',
        ],
    ],

    'national' => [
        'club_id' => '3300',
        'slug' => 'portekiz',
        'name' => 'Portekiz Milli Takımı',
        'period' => '2003–',
        'from' => 2003,
        'to' => null,
        'country' => 'Portekiz',
        'summary' => 'Ronaldo, 20 Ağustos 2003’ten bu yana Portekiz A Milli Takımı’nda forma giyiyor; EURO 2016 ve iki UEFA Uluslar Ligi şampiyonluğu yaşayan kaptan, erkek futbolunda milli maçlarda en çok gol atan ve en çok forma giyen oyuncudur.',
        'html' => <<<'HTML'
<p>Cristiano Ronaldo’nun Portekiz formasıyla hikâyesi genç milli takımlarda başladı. 2001’de U15 takımında ilk kez forma giyen Ronaldo, ardından U17, U20 ve U21 takımlarında oynadı. 2004 yazında Atina Olimpiyatları’nda Portekiz Olimpik Takımı’nda yer aldı ve Fas’ın 2–1 yenildiği maçta gol attı.</p>
<h3>İlk maç ve EURO 2004</h3>
<p>A milli takımdaki ilk maçına 20 Ağustos 2003’te, henüz 18 yaşındayken Chaves’te Kazakistan’ın 1–0 yenildiği hazırlık maçında, devre arasında Luís Figo’nun yerine oyuna girerek çıktı. Teknik direktör Luiz Felipe Scolari, onu bir yıl sonra Portekiz’in ev sahipliğini yaptığı EURO 2004 kadrosuna aldı. Ronaldo, 12 Haziran 2004’te Yunanistan’a 2–1 yenilinen açılış maçında milli formayla ilk golünü attı; yarı finalde Hollanda’ya karşı kafayla bir gol daha kaydetti. Portekiz finalde yine Yunanistan’a 1–0 yenildi. İki gol ve iki asistle turnuvayı tamamlayan 19 yaşındaki Ronaldo, turnuvanın en iyi takımına seçildi.</p>
<h3>Dünya Kupaları ve kaptanlık</h3>
<p>2006 Dünya Kupası’nda İran’a karşı penaltıdan attığı golle Portekiz’in Dünya Kupası’nda gol atan en genç oyuncusu oldu (21 yaş 132 gün). Portekiz çeyrek finalde İngiltere’yi penaltılarla eledi, yarı finalde ise Fransa’ya 1–0 yenildi. Ronaldo ilk kez 6 Şubat 2007’de Brezilya ile oynanan hazırlık maçında kaptanlık bandını taktı; EURO 2008’in ardından göreve gelen Carlos Queiroz onu takımın kalıcı kaptanı yaptı. Portekiz EURO 2008’de çeyrek finalde Almanya’ya, 2010 Dünya Kupası’nda son 16 turunda İspanya’ya elendi. EURO 2012’de üç golle turnuvanın ortak gol kralları arasında yer alan Ronaldo’nun takımı, yarı finalde İspanya’ya penaltılarla elendi.</p>
<p>2014 Dünya Kupası’na giden yol, Ronaldo’nun milli takım kariyerindeki en etkileyici performanslarından birine sahne oldu: İsveç ile oynanan baraj maçlarında Portekiz’in dört golünün tamamını atan Ronaldo, 19 Kasım 2013’te Solna’daki rövanşta hat-trick yaparak takımını 3–2’lik galibiyetle Brezilya’ya taşıdı. Turnuvada Portekiz grupta elendi; Ronaldo, Gana karşısında attığı galibiyet golüyle üçüncü Dünya Kupası’nda da ağları sarstı.</p>
<h3>EURO 2016: Paris’te tarihî gece</h3>
<p>Fernando Santos yönetimindeki Portekiz, EURO 2016’nın grup aşamasını üç beraberlikle geçti. Ronaldo, Macaristan ile 3–3 biten maçta attığı iki golle dört farklı Avrupa Şampiyonası’nda gol atan ilk futbolcu oldu; yarı finalde Galler’e karşı attığı kafa golü ve Nani’ye yaptığı asistle takımını finale taşıdı. 10 Temmuz 2016’da Stade de France’ta ev sahibi Fransa ile oynanan finalde dizinden sakatlanan Ronaldo, 25. dakikada gözyaşları içinde oyundan çıktı, ancak uzatmalarda kenardan takım arkadaşlarını yönlendirmeyi sürdürdü. Éder’in 109. dakikadaki golüyle kazanılan 1–0’lık zafer, Portekiz’e tarihindeki ilk büyük turnuva şampiyonluğunu getirdi.</p>
<h3>Uluslar Ligi ve gol rekoru</h3>
<p>Portekiz, 2017 Konfederasyonlar Kupası’nı üçüncü sırada tamamladı. 2018 Dünya Kupası’nda İspanya ile 3–3 biten maçta hat-trick yapan Ronaldo, o güne kadar Dünya Kupası’nda hat-trick yapan en yaşlı oyuncu oldu (bu rekoru 2026’da Lionel Messi geride bıraktı); Fas’a attığı kafa golüyle de Ferenc Puskás’ı geçerek milli maçlarda en çok gol atan Avrupalı futbolcu unvanını aldı. Portekiz o turnuvada son 16 turunda Uruguay’a elendi. 2019’da ilk UEFA Uluslar Ligi finallerine Portekiz ev sahipliği yaptı: Ronaldo yarı finalde İsviçre’ye hat-trick yaptı, Portekiz de 9 Haziran 2019’da Porto’da Hollanda’yı 1–0 yenerek kupayı kazandı.</p>
<p>EURO 2020’de beş golle Altın Ayakkabı’yı kazanan ve beş ayrı Avrupa Şampiyonası’nda gol atan ilk futbolcu olan Ronaldo, 23 Haziran 2021’de Fransa’ya attığı iki golle Ali Daei’nin 109 gollük milli maç rekoruna ortak oldu. Rekoru tek başına ele geçirmesi 1 Eylül 2021’de geldi: İrlanda Cumhuriyeti karşısında son dakikalarda attığı iki kafa golüyle hem 2–1’lik galibiyeti getirdi hem de 111 golle erkek futbolunda milli maçlarda en çok gol atan oyuncu oldu. 23 Mart 2023’te Lihtenştayn karşısında 197. kez forma giyerek en çok milli maça çıkan erkek futbolcu unvanını aldı; 20 Haziran 2023’te İzlanda’ya karşı çıktığı 200. milli maçında galibiyet golünü attı.</p>
<h3>2022 Dünya Kupası ve EURO 2024</h3>
<p>2022 Dünya Kupası’nda 24 Kasım’da Gana’ya penaltıdan attığı golle beş farklı Dünya Kupası’nda gol atan ilk erkek futbolcu oldu. Ancak teknik direktör Fernando Santos ile yaşandığı bildirilen gerginliğin ardından İsviçre ile oynanan son 16 turu maçında ilk 11’de yer almadı; Portekiz çeyrek finalde Fas’a 1–0 yenilerek turnuvaya veda etti. Ocak 2023’te Roberto Martínez dönemi başladı. EURO 2024’te altı farklı Avrupa Şampiyonası’nda forma giyen ilk oyuncu olan Ronaldo, Türkiye’nin 3–0 yenildiği Dortmund’daki grup maçında Bruno Fernandes’in golünde asisti yaptı. Slovenya ile oynanan son 16 turunda uzatmalarda kaçırdığı penaltının ardından seri penaltılarda ilk vuruşu gole çevirdi; Portekiz çeyrek finalde Fransa’ya penaltılarla elendi.</p>
<h3>İkinci Uluslar Ligi ve altıncı Dünya Kupası</h3>
<p>5 Eylül 2024’te Lizbon’da Hırvatistan’a attığı golle kariyerindeki 900. resmî gole ulaşan Ronaldo, 2024–25 Uluslar Ligi’nde de başrol oynadı. Almanya’da düzenlenen finallerde, 4 Haziran 2025’te Münih’te ev sahibi Almanya’ya karşı galibiyet golünü attı; 8 Haziran 2025’te yine Münih’te İspanya ile oynanan ve uzatmalar sonunda 2–2 biten finalde beraberlik golünü kaydetti. Portekiz seri penaltılarda 5–3 kazanarak ikinci Uluslar Ligi kupasını aldı. 14 Ekim 2025’te Macaristan’a attığı iki golle Dünya Kupası elemelerinde 41 golle tüm zamanların en golcü oyuncusu oldu. 13 Kasım 2025’te Dublin’de İrlanda Cumhuriyeti’ne 2–0 yenilen maçta Dara O’Shea’ya dirsek attığı gerekçesiyle, VAR incelemesinin ardından milli takım kariyerindeki ilk kırmızı kartı gördü; FIFA verdiği üç maçlık cezanın iki maçını bir yıllık deneme süresine bağlayarak ertelediği için Dünya Kupası’nın başından itibaren forma giyebildi.</p>
<p>19 Mayıs 2026’da Dünya Kupası kadrosuna seçilen Ronaldo, altıncı Dünya Kupası’na katıldı. 23 Haziran 2026’da Houston’da Özbekistan’ın 5–0 yenildiği maçta attığı iki golle altı farklı Dünya Kupası’nda gol atan ilk futbolcu oldu ve 10 golle Eusébio’yu (9) geçerek Portekiz’in Dünya Kupası tarihindeki en golcü oyuncusu unvanını aldı; FIFA’ya göre bu gollerle Roger Milla’nın ardından turnuva tarihinin en yaşlı ikinci golcüsü oldu. Portekiz grubu Kolombiya’nın ardından ikinci tamamladı; son 32 turunda Hırvatistan’ın 2–1 yenildiği Toronto’daki maçta Ronaldo penaltıdan gol attı; bu, onun Dünya Kupası’nda grup aşaması sonrasındaki turlarda attığı ilk goldü. 6 Temmuz 2026’da ise Arlington’daki son 16 turu maçında İspanya’ya, Mikel Merino’nun uzatma dakikalarındaki golüyle 1–0 yenilen Portekiz turnuvaya veda etti. Ronaldo, Kasım 2025’te 2026 Dünya Kupası’nın son Dünya Kupası olacağını açıklamıştı.</p>
<h3>Jorge Jesus dönemi</h3>
<p>Dünya Kupası’nın ardından Roberto Martínez’in görevi sona erdi ve Portekiz Futbol Federasyonu 10 Temmuz 2026’da, Ronaldo’yu Al-Nassr’da çalıştıran Jorge Jesus’u 2030 Dünya Kupası’na kadar sürecek dört yıllık sözleşmeyle göreve getirdi. Ronaldo milli takımı bırakmadı ve Eylül 2026’daki Uluslar Ligi kadrosunda yer aldı. 24 Eylül’de Galler karşısında ilk 11’de başladı; ancak 27 Eylül’de Oslo’da 2–1 kazanılan Norveç maçında ısınmasına rağmen oyuna alınmadı. Jesus’un Danimarka maçında da ilk 11’de Gonçalo Ramos’a şans vereceğini açıkladığı basın toplantısının ardından Ronaldo, 30 Eylül’de takımın Kopenhag’daki kampından ayrıldı. Jesus, aralarında bir sorun olmadığını, Ronaldo’ya Norveç maçında oyuna girebileceğini söylediğini ancak maçın gidişatı nedeniyle fikrini değiştirdiğini belirtmişti. Ronaldo ise sonraki açıklamalarında teknik direktörün kendisine verdiği sözleri tutmadığını öne sürdü; 6 Ekim’deki açıklamasında hatasını kabul ederek özür diledi ve verilecek cezayı kabul edeceğini söyledi. Federasyonun disiplin kurulu 19 Ekim 2026’da Ronaldo’ya yönetmelikteki alt sınır olan 30 günlük men ve 255 euro para cezası verdi; ceza 1 Ekim’den itibaren geçerli sayıldığı için Ronaldo, Kasım 2026’daki Uluslar Ligi maçlarında yeniden forma giyebilecek durumdaydı. Milli takımdaki güncel maç ve gol sayıları için <a href="/istatistikler">istatistikler</a> sayfamıza bakabilirsiniz.</p>
HTML,
        'highlights' => [
            '20 Ağustos 2003: 18 yaşında Kazakistan’a karşı A milli takım çıkışı',
            '12 Haziran 2004: EURO 2004’te Yunanistan’a ilk milli gol',
            '2008’den bu yana takımın kalıcı kaptanı (ilk kez Şubat 2007’de kaptanlık yaptı)',
            '10 Temmuz 2016: Paris’te Fransa’yı yenen takımın kaptanı olarak EURO 2016 şampiyonluğu',
            '2019 ve 2025: İki UEFA Uluslar Ligi şampiyonluğu',
            '1 Eylül 2021: Ali Daei’yi geçerek milli maçlarda en çok gol atan erkek futbolcu oldu',
            '23 Mart 2023: En çok milli maça çıkan erkek futbolcu; 20 Haziran 2023’te 200. milli maç',
            'Altı Avrupa Şampiyonası (2004–2024) ve altı Dünya Kupası’nda (2006–2026) forma giydi',
            '23 Haziran 2026: Altı farklı Dünya Kupası’nda gol atan ilk futbolcu',
            'EURO 2020 Altın Ayakkabı; Dünya Kupası elemelerinde rekor 41 gol (Ekim 2025)',
        ],
        'honours' => [
            'UEFA Avrupa Şampiyonası (1): 2016',
            'UEFA Uluslar Ligi (2): 2018–19, 2024–25',
        ],
    ],

    'timeline' => [
        ['year' => '1992', 'text' => 'Funchal’da babasının malzemeci olarak çalıştığı CF Andorinha’da futbola başladı.'],
        ['year' => '1995', 'text' => 'CD Nacional’ın altyapısına katıldı.'],
        ['year' => '1997', 'text' => '12 yaşında Sporting CP’nin denemesini geçerek Lizbon’a taşındı.'],
        ['year' => '2002', 'text' => '14 Ağustos’ta Sporting A takımında ilk maçına çıktı; 7 Ekim’de Moreirense’ye ilk profesyonel gollerini attı.'],
        ['year' => '2003', 'text' => '12 Ağustos’ta 12,24 milyon sterlinle Manchester United’a transfer oldu; 20 Ağustos’ta Portekiz A Milli Takımı’nda ilk maçına çıktı.'],
        ['year' => '2004', 'text' => 'FA Cup finalinde gol attı; EURO 2004’te finale yükselen Portekiz’de iki gol kaydetti.'],
        ['year' => '2008', 'text' => 'Manchester United ile Premier Lig ve Şampiyonlar Ligi’ni kazandı; Ballon d’Or’a uzandı ve Portekiz’in kalıcı kaptanı oldu.'],
        ['year' => '2009', 'text' => 'İlk FIFA Puskás Ödülü’nü kazandı; 80 milyon sterlinlik dünya rekoru transferle Real Madrid’e geçti.'],
        ['year' => '2011', 'text' => 'Kral Kupası finalinde Barcelona’ya attığı kafa golüyle İspanya’daki ilk kupasını kazandı.'],
        ['year' => '2014', 'text' => 'Ocak’ta 2013 Ballon d’Or ödülünü aldı; Mayıs’ta Real Madrid’in onuncu Avrupa kupası “La Décima”yı rekor 17 golle kazandı.'],
        ['year' => '2015', 'text' => '17 Ekim’de Raúl’u geçerek Real Madrid tarihinin en golcü oyuncusu oldu.'],
        ['year' => '2016', 'text' => 'Real Madrid ile Şampiyonlar Ligi’ni, Portekiz ile EURO 2016’yı kazandı; dördüncü Ballon d’Or’unu aldı.'],
        ['year' => '2017', 'text' => 'La Liga ve Şampiyonlar Ligi’ni kazandı; Cardiff’teki finalde iki gol attı ve beşinci Ballon d’Or’una ulaştı.'],
        ['year' => '2018', 'text' => 'Beşinci Şampiyonlar Ligi kupasını kazandı; 10 Temmuz’da 100 milyon euroya Juventus’a transfer oldu.'],
        ['year' => '2019', 'text' => 'Juventus ile Serie A’yı kazanarak üç büyük ligde şampiyon olan ilk futbolcu oldu; Portekiz ile ilk Uluslar Ligi’ni kaldırdı.'],
        ['year' => '2021', 'text' => 'Serie A gol kralı oldu ve İtalya Kupası’nı kazandı; Manchester United’a döndü; 1 Eylül’de Ali Daei’nin milli gol rekorunu geçti.'],
        ['year' => '2022', 'text' => '22 Kasım’da Manchester United’dan karşılıklı anlaşmayla ayrıldı; 30 Aralık’ta Al-Nassr’a imza attı.'],
        ['year' => '2023', 'text' => 'Al-Nassr ile Arap Kulüpler Şampiyonlar Kupası’nı kazandı; en çok milli maça çıkan erkek futbolcu oldu.'],
        ['year' => '2024', 'text' => 'Suudi Pro Lig’de 35 golle tek sezon rekoru kırdı; 5 Eylül’de kariyerindeki 900. gole ulaştı.'],
        ['year' => '2025', 'text' => 'Portekiz ile ikinci Uluslar Ligi’ni kazandı; Al-Nassr sözleşmesini 2027’ye uzattı; dört kulüpte 100’er gol atan ilk oyuncu oldu.'],
        ['year' => '2026', 'text' => 'Al-Nassr ile Suudi Pro Lig şampiyonu oldu; altı Dünya Kupası’nda gol atan ilk futbolcu oldu; Eylül sonunda milli takım kampından ayrıldığı için Ekim’de federasyondan 30 günlük men cezası aldı.'],
    ],

    'sources' => [
        ['title' => 'Wikipedia (EN): Cristiano Ronaldo', 'url' => 'https://en.wikipedia.org/wiki/Cristiano_Ronaldo'],
        ['title' => 'Transfermarkt: Cristiano Ronaldo profili', 'url' => 'https://www.transfermarkt.com.tr/cristiano-ronaldo/profil/spieler/8198'],
        ['title' => 'Transfermarkt: Cristiano Ronaldo transfer geçmişi', 'url' => 'https://www.transfermarkt.com.tr/cristiano-ronaldo/transfers/spieler/8198'],
        ['title' => 'Sporting CP: Estádio José Alvalade', 'url' => 'https://www.sporting.pt/en/club/infrastructures/estadio-jose-alvalade'],
        ['title' => 'Wikipedia (EN): Academia Cristiano Ronaldo', 'url' => 'https://en.wikipedia.org/wiki/Academia_Cristiano_Ronaldo'],
        ['title' => 'Sporting News: Cristiano Ronaldo’s debut and first goals', 'url' => 'https://www.sportingnews.com/us/soccer/news/cristiano-ronaldo-debut-year-first-game-opponents-cr7-career/3cc536107e94eec06e2219f4'],
        ['title' => 'BBC Sport: Man Utd sign Ronaldo (12 Ağustos 2003)', 'url' => 'http://news.bbc.co.uk/sport1/hi/football/teams/m/man_utd/3142959.stm'],
        ['title' => 'BBC Sport: Twenty years on from Ronaldo’s Manchester United debut', 'url' => 'https://www.bbc.com/sport/football/66502158'],
        ['title' => 'BBC Sport: Man Utd 6-0 Newcastle (12 Ocak 2008)', 'url' => 'http://news.bbc.co.uk/sport1/hi/football/eng_prem/7172817.stm'],
        ['title' => 'Sky Sports: FC Porto 0-1 Man Utd (15 Nisan 2009)', 'url' => 'https://www.skysports.com/football/fc-porto-vs-manchester-united/17744'],
        ['title' => 'Puskas.com: FIFA Puskás Award – Cristiano Ronaldo', 'url' => 'https://www.puskas.com/en/fifa-puskas-award/1-cristiano-ronaldo.html'],
        ['title' => 'BBC Sport: Ronaldo completes £80m Real move', 'url' => 'http://news.bbc.co.uk/sport1/hi/football/teams/m/man_utd/8121951.stm'],
        ['title' => 'BBC Sport: Ronaldo welcomed by 80,000 fans', 'url' => 'http://news.bbc.co.uk/sport2/hi/football/europe/8135671.stm'],
        ['title' => 'UEFA.com: Ronaldo completes record Madrid switch', 'url' => 'https://www.uefa.com/news-media/news/01da-0e724ecc82ec-05f7b7b5fb93-1000--ronaldo-completes-record-madrid-switch/'],
        ['title' => 'Real Madrid: Cristiano Ronaldo (kulüp efsaneleri)', 'url' => 'https://www.realmadrid.com/en-US/the-club/history/football-legends/cristiano-ronaldo-dos-santos-aveiro'],
        ['title' => 'Real Madrid: 15 years since the 18th Copa del Rey', 'url' => 'https://www.realmadrid.com/en-US/news/club/latest-news/se-cumplen-15-anos-de-la-18a-copa-del-rey-20-04-2026'],
        ['title' => 'Al Jazeera: Ronaldo breaks Raúl’s record (17 Ekim 2015)', 'url' => 'https://www.aljazeera.com/sports/2015/10/17/ronaldo-breaks-rauls-record-in-real-madrids-win/'],
        ['title' => 'BBC Sport: Juventus sign Real Madrid forward (Real Madrid istatistikleri)', 'url' => 'https://www.bbc.co.uk/sport/football/44785173'],
        ['title' => 'Juventus: Cristiano Ronaldo signs for Juventus', 'url' => 'https://www.juventus.com/en/news/articles/cristiano-ronaldo-signs-for-juventus'],
        ['title' => 'ESPN: Cristiano Ronaldo leaves Real Madrid to sign with Juventus', 'url' => 'https://www.espn.com/soccer/story/_/id/37557982/cristiano-ronaldo-leaves-real-madrid-join-juventus'],
        ['title' => 'Gazzetta dello Sport: Juve appeals CR7 unpaid wages case', 'url' => 'https://www.gazzetta.it/en/football/teams/juventus/news/13-10-2026/cr7-case-juve-refuses-to-back-down-appeals-to-supreme-court-over-unpaid-wages.shtml'],
        ['title' => 'The Athletic: Juventus lose legal dispute against Cristiano Ronaldo', 'url' => 'https://www.nytimes.com/athletic/6985670/2026/01/20/cristiano-ronaldo-juventus-legal-dispute'],
        ['title' => 'Sky Sports: Cristiano Ronaldo re-signs for Manchester United', 'url' => 'https://www.skysports.com/football/news/12393260/cristiano-ronaldo-re-signs-for-manchester-united-from-juventus'],
        ['title' => 'The Athletic: Cristiano Ronaldo completes return to Manchester United', 'url' => 'https://www.nytimes.com/athletic/4201344/2021/08/31/cristiano-ronaldo-completes-return-to-manchester-united/'],
        ['title' => 'Manchester United: Ronaldo wins Sir Matt Busby Player of the Year 2021/22', 'url' => 'https://www.manutd.com/en/news/detail/cristiano-ronaldo-wins-man-utd-sir-matt-busby-player-of-the-year-award-2021-22-06-june-2022'],
        ['title' => 'ESPN: Cristiano Ronaldo leaves Man United by mutual agreement', 'url' => 'https://www.espn.com/soccer/story/_/id/37634146/cristiano-ronaldo-leaves-man-united-mutual-agreement'],
        ['title' => 'Reuters: Ronaldo extends Al Nassr contract until 2027', 'url' => 'https://www.reuters.com/sports/soccer/ronaldo-renews-al-nassr-contract-until-2027-2025-06-26/'],
        ['title' => 'Reuters: Al-Ahli win Saudi Super Cup as Ronaldo nets 100th Al-Nassr goal', 'url' => 'https://www.reuters.com/sports/soccer/al-ahli-win-saudi-super-cup-ronaldo-nets-100th-al-nassr-goal-2025-08-23/'],
        ['title' => 'ESPN: Cristiano Ronaldo ends protest, scores for Al Nassr', 'url' => 'https://www.espn.com/soccer/story/_/id/47924095/cristiano-ronaldo-returns-al-nassr-lineup-protest-saudi-pro-league'],
        ['title' => 'BBC Sport: Al-Nassr lose AFC Champions League Two final to Gamba Osaka', 'url' => 'https://www.bbc.com/sport/football/articles/cm2pe6d9prvo'],
        ['title' => 'BBC Sport: Cristiano Ronaldo wins first Saudi Pro League title', 'url' => 'https://www.bbc.com/sport/football/articles/c232g9lpdpgo'],
        ['title' => 'Reuters: Ronaldo’s brace fires Al Nassr to Saudi league title', 'url' => 'https://www.reuters.com/sports/soccer/ronaldos-brace-fires-al-nassr-saudi-league-title-with-4-1-win-over-damac-2026-05-21/'],
        ['title' => 'Wikipedia (EN): 2025–26 Al-Nassr FC season', 'url' => 'https://en.wikipedia.org/wiki/2025%E2%80%9326_Al-Nassr_FC_season'],
        ['title' => 'Wikipedia (EN): 2025–26 Saudi Pro League', 'url' => 'https://en.wikipedia.org/wiki/2025%E2%80%9326_Saudi_Pro_League'],
        ['title' => 'Reuters: Postecoglou appointed new coach of Al-Nassr', 'url' => 'https://www.reuters.com/sports/soccer/postecoglou-appointed-new-coach-al-nassr-two-year-deal-2026-07-03/'],
        ['title' => 'BBC Sport: ‘Probably my last year’ – Ronaldo hints at retirement', 'url' => 'https://www.bbc.com/sport/football/articles/c3300lxjz7lo'],
        ['title' => 'ESPN: Ronaldo, Al Nassr lose AFC CL2 final', 'url' => 'https://www.espn.com/soccer/story/_/id/48789219/cristiano-ronaldo-al-nassr-afc-champions-league-2-gamba-osaka-final'],
        ['title' => 'Wikipedia (EN): Portugal at the 2004 Summer Olympics', 'url' => 'https://en.wikipedia.org/wiki/Portugal_at_the_2004_Summer_Olympics'],
        ['title' => 'ESPN: Portugal have more history than Cristiano Ronaldo (Queiroz ve kaptanlık)', 'url' => 'https://www.espn.com/soccer/story/_/id/37557371/portugal-more-history-cristiano-ronaldo-carlos-queiroz'],
        ['title' => 'UEFA.com: Türkiye 0-3 Portugal (EURO 2024)', 'url' => 'https://www.uefa.com/uefaeuro/history/news/028e-1b31d4eb7621-609f1b58058d-1000--turkiye-0-3-portugal-selecao-sweep-into-last-16/'],
        ['title' => 'TFF: Türkiye 0-3 Portekiz', 'url' => 'https://www.tff.org/default.aspx?pageID=471&ftxtID=44679'],
        ['title' => 'ESPN: Ronaldo turns missed penalty tears into shootout joy (EURO 2024)', 'url' => 'https://africa.espn.com/football/story/_/id/40476624/euro-2024-cristiano-ronaldo-tears-missed-penalty-portugal'],
        ['title' => 'BBC Sport: Ronaldo scores 900th career goal as Portugal beat Croatia', 'url' => 'https://www.bbc.com/sport/football/live/c70jvq8zy04t'],
        ['title' => 'BBC Sport: Portugal 2-2 Hungary – Ronaldo sets qualifying record', 'url' => 'https://www.bbc.co.uk/sport/football/live/c4g2nmexvmzt'],
        ['title' => 'Reuters: Ronaldo cleared to play opening matches at World Cup', 'url' => 'https://www.reuters.com/sports/soccer/ronaldo-cleared-play-opening-matches-world-cup-2025-11-25/'],
        ['title' => 'FIFA: Portugal 5-0 Uzbekistan match report', 'url' => 'https://www.fifa.com/en/tournaments/mens/worldcup/canadamexicousa2026/articles/portugal-uzbekistan-match-report-highlights'],
        ['title' => 'UEFA.com: Portugal at the World Cup 2026', 'url' => 'https://www.uefa.com/european-qualifiers/news/02a6-20d15939ace8-5cc9254d0851-1000--portugal-at-the-world-cup-2026-squad-fixtures-group-and-hi/'],
        ['title' => 'Wikipedia (EN): Portugal at the FIFA World Cup', 'url' => 'https://en.wikipedia.org/wiki/Portugal_at_the_FIFA_World_Cup'],
        ['title' => 'BBC Sport: Jorge Jesus named Portugal’s new head coach', 'url' => 'https://www.bbc.co.uk/sport/football/articles/c3eyl9pvxj1o'],
        ['title' => 'AP: Portugal hires Jorge Jesus as its new coach', 'url' => 'https://apnews.com/article/portugal-coach-jorge-jesus-e4cba102a945766b907e6864dfc3c7f7'],
        ['title' => 'AFP: Ronaldo handed 30-day suspension after walking out on Portugal', 'url' => 'https://www.afp.com/en/ronaldo-handed-30-day-suspension-after-walking-out-portugal'],
        ['title' => 'ESPN: Cristiano Ronaldo fined €255, suspended 30 days', 'url' => 'https://www.espn.com/soccer/story/_/id/50220261/cristiano-ronaldo-fined-255-suspended-30-days-portugal-walk-out'],
        ['title' => 'Sporting CP: 18 Ağustos 2002 Supertaça zaferi', 'url' => 'https://www.sporting.pt/pt/sabia-que-18-ago'],
        ['title' => 'ESPN: Cristiano Ronaldo to end 2023 as world’s top goalscorer', 'url' => 'https://www.espn.com/soccer/story/_/id/39211354/cristiano-ronaldo-end-2023-world-top-goalscorer'],
        ['title' => 'AP: Ronaldo ends Saudi trophy drought with 2 goals (21 Mayıs 2026)', 'url' => 'https://abcnews.com/Sports/wireStory/ronaldo-ends-saudi-trophy-drought-2-goals-al-133203345'],
        ['title' => 'BBC Sport: Ronaldo confirms 2026 World Cup will be his last', 'url' => 'https://www.bbc.com/sport/football/articles/cpv13ly9j2xo'],
        ['title' => 'FIFA: Lionel Messi’s World Cup records', 'url' => 'https://www.fifa.com/en/tournaments/mens/worldcup/canadamexicousa2026/articles/lionel-messi-argentina-stats-records'],
        ['title' => 'Al Jazeera: Ramos fires holders Portugal to 2-1 win in Norway', 'url' => 'https://www.aljazeera.com/sports/2026/9/27/ramos-fires-holders-portugal-to-2-1-nations-league-win-in-norway'],
        ['title' => 'Reuters: Ronaldo leaves Portugal camp after coach Jesus confirms rift', 'url' => 'https://www.reuters.com/sports/soccer/ronaldo-misses-portugal-training-after-jesus-denies-rift-2026-09-30/'],
        ['title' => 'ESPN: Cristiano Ronaldo leaves Portugal camp after coach says he’d be benched again', 'url' => 'https://www.espn.com/soccer/story/_/id/50069045/cristiano-ronaldo-portugal-coach-leave-camp-jorge-jesus-bench-denmark'],
    ],
];
