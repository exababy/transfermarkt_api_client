<?php
/**
 * Home page (/).
 *
 * @var array|null $player StatsRepository::player()
 * @var array{url: string, credit: ?string, width: int, height: int}|null $portrait
 * @var string $h1
 * @var string|null $kicker
 * @var string $subtitle
 * @var array<string, int> $career careerTotals() ([] when unavailable)
 * @var array{games: int, goals: int, name: string, url: string, logo_url: ?string, period: string} $national
 * @var array<string, int> $honourSummary HonoursController::summary()
 * @var list<array{value: int, label: string, sub: string}> $highlights
 * @var string $firstParagraph trusted HTML (biography lead, first paragraph)
 * @var list<array{label: string, value: string}> $facts
 * @var list<array{q: string, a: string}> $faq
 * @var list<array<string, mixed>> $news
 * @var list<array<string, mixed>> $videos
 * @var list<array<string, mixed>> $games
 * @var list<array<string, mixed>> $clubs
 * @var string|null $lastSync
 * @var bool $shopEnabled
 * @var bool $ordersEnabled
 * @var list<array<string, mixed>> $products
 */

use App\Support\Format;

$hasStats = (int) ($career['appearances'] ?? 0) > 0;
$resultLabels = defined('App\Repositories\StatsRepository::RESULT_LABELS')
    ? \App\Repositories\StatsRepository::RESULT_LABELS
    : ['W' => ['G', 'Galibiyet'], 'D' => ['B', 'Beraberlik'], 'L' => ['M', 'Mağlubiyet']];
$metaBits = [];
if (!empty($player['age'])) {
    $metaBits[] = (int) $player['age'] . ' yaşında';
}
$currentClub = \App\Controllers\LifeController::currentClub($player);
if ($currentClub !== null) {
    $metaBits[] = $currentClub;
}
if (!empty($player['position'])) {
    $metaBits[] = (string) $player['position'];
}
if (!empty($player['shirt_number'])) {
    $metaBits[] = 'Forma no: ' . (int) $player['shirt_number'];
}
?>
<section class="hero home-hero" aria-labelledby="hero-title">
  <div class="container">
    <div class="hero-text">
      <span class="eyebrow">CR7 · Bağımsız hayran sitesi</span>
      <?php if ($kicker !== null): ?><p class="home-kicker"><?= e($kicker) ?></p><?php endif; ?>
      <h1 id="hero-title"><?= e($h1) ?></h1>
      <p class="lead"><?= e($subtitle) ?></p>
      <?php if ($metaBits !== []): ?>
      <ul class="home-hero-meta" aria-label="Kısa bilgi">
        <?php foreach ($metaBits as $bit): ?><li><?= e($bit) ?></li><?php endforeach; ?>
      </ul>
      <?php endif; ?>
      <div class="hero-actions">
        <a class="btn btn-gold btn-lg" href="<?= e(url('/hayati')) ?>">Hayatını Keşfet</a>
        <a class="btn btn-outline btn-lg" href="<?= e(url('/istatistikler')) ?>">İstatistikler</a>
        <a class="btn btn-ghost btn-lg home-hero-news" href="<?= e(url('/haberler')) ?>">Son Haberler</a>
      </div>
    </div>
    <?php if ($portrait !== null): ?>
    <figure class="hero-media home-portrait<?= $portrait['credit'] !== null ? ' is-tm' : '' ?>">
      <img src="<?= e($portrait['url']) ?>" alt="Cristiano Ronaldo portresi" width="<?= (int) $portrait['width'] ?>" height="<?= (int) $portrait['height'] ?>" fetchpriority="high" decoding="async">
      <?php if ($portrait['credit'] !== null): ?><figcaption class="home-credit"><?= e($portrait['credit']) ?></figcaption><?php endif; ?>
    </figure>
    <?php else: ?>
    <div class="hero-media home-portrait is-empty" aria-hidden="true"><span>CR7</span></div>
    <?php endif; ?>
  </div>
</section>

<section class="section-sm home-numbers" aria-labelledby="rakamlar-baslik">
  <div class="container">
    <h2 class="visually-hidden" id="rakamlar-baslik">Rakamlarla Cristiano Ronaldo</h2>
    <div class="stat-tiles home-tiles">
      <?php if ($hasStats): ?>
      <?= partial('partials/stat-tile', ['value' => (int) $career['goals'], 'label' => 'Kariyer golü', 'sub' => 'Genç milli takımlar dahil']) ?>
      <?= partial('partials/stat-tile', ['value' => (int) $career['appearances'], 'label' => 'Resmî maç', 'sub' => 'Kulüp + milli takım']) ?>
      <?= partial('partials/stat-tile', ['value' => (int) ($career['assists'] ?? 0), 'label' => 'Asist']) ?>
      <?php endif; ?>
      <?php if ($national['games'] > 0): ?>
      <?= partial('partials/stat-tile', ['value' => $national['goals'], 'label' => 'Milli gol', 'sub' => 'Portekiz A Milli Takımı']) ?>
      <?= partial('partials/stat-tile', ['value' => $national['games'], 'label' => 'Milli maç', 'sub' => 'Erkeklerde dünya rekoru']) ?>
      <?php endif; ?>
      <?php if ($honourSummary['ballon_dor'] > 0): ?>
      <?= partial('partials/stat-tile', ['value' => $honourSummary['ballon_dor'], 'label' => 'Ballon d’Or']) ?>
      <?php endif; ?>
      <?php if ($honourSummary['champions_league'] > 0): ?>
      <?= partial('partials/stat-tile', ['value' => $honourSummary['champions_league'], 'label' => 'Şampiyonlar Ligi']) ?>
      <?php endif; ?>
      <?php if (!$hasStats && $honourSummary['golden_shoe'] > 0): ?>
      <?= partial('partials/stat-tile', ['value' => $honourSummary['golden_shoe'], 'label' => 'Avrupa Altın Ayakkabı']) ?>
      <?php endif; ?>
      <?php if ($honourSummary['team_total'] > 0): ?>
      <?= partial('partials/stat-tile', ['value' => $honourSummary['team_total'], 'label' => 'Takım kupası', 'sub' => $honourSummary['clubs'] . ' takımla']) ?>
      <?php endif; ?>
    </div>
    <p class="meta home-source">
      <?php if ($hasStats || $national['games'] > 0): ?><span>İstatistik verileri: Transfermarkt</span><?php endif; ?>
      <?php if ($lastSync !== null): ?><span>Son güncelleme: <time datetime="<?= e(Format::iso($lastSync)) ?>"><?= e(Format::date($lastSync, 'j F Y H:i')) ?></time></span><?php endif; ?>
      <?php if (!$hasStats): ?><span>Canlı istatistikler ilk senkronizasyondan sonra burada görünecek.</span><?php endif; ?>
      <a class="link-more" href="<?= e(url('/istatistikler')) ?>">Tüm istatistikler</a>
    </p>
  </div>
</section>

<section class="section section-alt" aria-labelledby="kimdir-baslik">
  <div class="container home-about">
    <div>
      <span class="eyebrow">Biyografi</span>
      <h2 class="section-title" id="kimdir-baslik">Ronaldo kimdir?</h2>
      <?php if ($firstParagraph !== ''): ?>
      <div class="prose"><?= $firstParagraph ?></div>
      <?php else: ?>
      <p class="lead">Cristiano Ronaldo, 5 Şubat 1985’te Madeira’nın Funchal kentinde doğan Portekizli futbolcudur.</p>
      <?php endif; ?>
      <div class="cluster">
        <a class="btn btn-gold" href="<?= e(url('/hayati')) ?>">Ronaldo’nun hayatını oku</a>
        <a class="btn btn-outline" href="<?= e(url('/kariyer')) ?>">Kariyeri</a>
      </div>
    </div>
    <?php if ($facts !== []): ?>
    <aside aria-label="Hızlı bilgiler">
      <h3 class="home-aside-title">Hızlı bilgiler</h3>
      <dl class="facts">
        <?php foreach ($facts as $fact): ?>
        <dt><?= e($fact['label']) ?></dt>
        <dd><?= e($fact['value']) ?></dd>
        <?php endforeach; ?>
      </dl>
    </aside>
    <?php endif; ?>
  </div>
</section>

<section class="section" aria-labelledby="haberler-baslik">
  <div class="container">
    <div class="section-header">
      <div>
        <span class="eyebrow">Gündem</span>
        <h2 class="section-title" id="haberler-baslik">Cristiano Ronaldo’dan son haberler</h2>
      </div>
      <a class="link-more" href="<?= e(url('/haberler')) ?>">Tüm haberler</a>
    </div>
    <?php if ($news !== []): ?>
    <div class="grid grid-3">
      <?php foreach ($news as $post): ?>
      <?= partial('partials/news-card', ['post' => $post, 'headingTag' => 'h3']) ?>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="empty-state">
      <p class="mb-0">Henüz yayımlanmış haber yok. Yeni haberler otomatik olarak derlenip burada yayımlanacak.</p>
    </div>
    <?php endif; ?>
  </div>
</section>

<section class="section section-alt" aria-labelledby="maclar-baslik">
  <div class="container home-split">
    <div>
      <div class="section-header">
        <div>
          <span class="eyebrow">Form durumu</span>
          <h2 class="section-title" id="maclar-baslik">Son maçları</h2>
        </div>
        <a class="link-more" href="<?= e(url('/istatistikler/maclar')) ?>">Tüm maçlar</a>
      </div>
      <?php if ($games !== []): ?>
      <ol class="match-list list-unstyled">
        <?php foreach ($games as $g): ?>
        <?php
          $res = $resultLabels[$g['result'] ?? ''] ?? null;
          $hasScore = isset($g['goals_for'], $g['goals_against']) && $g['goals_for'] !== null;
          $contrib = [];
          if ((int) ($g['goals'] ?? 0) > 0) {
              $contrib[] = (int) $g['goals'] . ' gol';
          }
          if ((int) ($g['assists'] ?? 0) > 0) {
              $contrib[] = (int) $g['assists'] . ' asist';
          }
          if ((int) ($g['minutes'] ?? 0) > 0) {
              $contrib[] = (int) $g['minutes'] . ' dk';
          }
        ?>
        <li class="match">
          <div class="match-when">
            <time datetime="<?= e(Format::iso((string) $g['date_utc'])) ?>"><?= e(Format::date((string) $g['date_utc'], 'j M Y')) ?></time>
            <span><?= e((string) ($g['competition_name'] ?? '')) ?></span>
          </div>
          <div class="match-teams">
            <span class="cell-club"><?php if (!empty($g['club_logo'])): ?><img class="logo-sm" src="<?= e($g['club_logo']) ?>" alt="" width="26" height="26" loading="lazy" decoding="async"><?php endif; ?><?= e((string) ($g['club_name'] ?? '')) ?></span>
            <strong class="match-score"><?= $hasScore ? e($g['goals_for'] . '–' . $g['goals_against']) : '–' ?></strong>
            <span class="cell-club"><?php if (!empty($g['opponent_logo'])): ?><img class="logo-sm" src="<?= e($g['opponent_logo']) ?>" alt="" width="26" height="26" loading="lazy" decoding="async"><?php endif; ?><?= e((string) ($g['opponent_name'] ?? '')) ?></span>
          </div>
          <div class="match-result">
            <?php if ($res !== null): ?><span class="result-<?= e($g['result']) ?>" title="<?= e($res[1]) ?>"><span aria-hidden="true"><?= e($res[0]) ?></span><span class="visually-hidden"><?= e($res[1]) ?></span></span><?php endif; ?>
            <?php if ($contrib !== []): ?><span class="match-contrib"><?= e(implode(' · ', $contrib)) ?></span><?php endif; ?>
          </div>
        </li>
        <?php endforeach; ?>
      </ol>
      <?php else: ?>
      <div class="empty-state"><p class="mb-0">Maç verileri Transfermarkt senkronizasyonundan sonra burada listelenecek.</p></div>
      <?php endif; ?>
    </div>
    <div>
      <div class="section-header">
        <div>
          <span class="eyebrow">Kariyer</span>
          <h2 class="section-title" id="takimlar-baslik">Oynadığı takımlar</h2>
        </div>
        <a class="link-more" href="<?= e(url('/takimlar')) ?>">Tüm takımlar</a>
      </div>
      <?php if ($clubs !== []): ?>
      <ol class="club-strip list-unstyled" aria-labelledby="takimlar-baslik">
        <?php foreach ($clubs as $club): ?>
        <?php
          $years = (string) ($club['period'] ?? '');
          if ($years === '' && !empty($club['from_year'])) {
              $years = $club['from_year'] . '–' . ($club['to_year'] ?? '');
          }
        ?>
        <li>
          <a class="club-chip" href="<?= e(url((string) ($club['url'] ?? ('/takimlar/' . $club['slug'])))) ?>">
            <?php if (!empty($club['logo_url'])): ?>
            <img class="logo-md" src="<?= e($club['logo_url']) ?>" alt="<?= e($club['name'] . ' logosu') ?>" width="44" height="44" loading="lazy" decoding="async">
            <?php else: ?>
            <span class="club-chip-initial" aria-hidden="true"><?= e(mb_substr((string) $club['name'], 0, 1)) ?></span>
            <?php endif; ?>
            <span class="club-chip-text">
              <strong><?= e($club['name']) ?></strong>
              <span><?= e($years) ?><?php if ((int) ($club['appearances'] ?? 0) > 0): ?> · <?= e(fmt_number((int) $club['appearances'])) ?> maç, <?= e(fmt_number((int) $club['goals'])) ?> gol<?php endif; ?></span>
            </span>
          </a>
        </li>
        <?php endforeach; ?>
        <li>
          <a class="club-chip" href="<?= e(url($national['url'])) ?>">
            <?php if (!empty($national['logo_url'])): ?>
            <img class="logo-md" src="<?= e($national['logo_url']) ?>" alt="Portekiz Milli Takımı logosu" width="44" height="44" loading="lazy" decoding="async">
            <?php else: ?>
            <span class="club-chip-initial" aria-hidden="true">P</span>
            <?php endif; ?>
            <span class="club-chip-text">
              <strong>Portekiz Milli Takımı</strong>
              <span><?= e($national['period']) ?><?php if ($national['games'] > 0): ?> · <?= e(fmt_number($national['games'])) ?> maç, <?= e(fmt_number($national['goals'])) ?> gol<?php endif; ?></span>
            </span>
          </a>
        </li>
      </ol>
      <?php endif; ?>
    </div>
  </div>
</section>

<section class="section" aria-labelledby="videolar-baslik">
  <div class="container">
    <div class="section-header">
      <div>
        <span class="eyebrow">İzle</span>
        <h2 class="section-title" id="videolar-baslik">Öne çıkan Ronaldo videoları</h2>
      </div>
      <a class="link-more" href="<?= e(url('/videolar')) ?>">Tüm videolar</a>
    </div>
    <?php if ($videos !== []): ?>
    <div class="grid grid-4">
      <?php foreach ($videos as $video): ?>
      <?= partial('partials/video-card', ['video' => $video, 'headingTag' => 'h3']) ?>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="empty-state"><p class="mb-0">Videolar yakında burada olacak.</p></div>
    <?php endif; ?>
  </div>
</section>

<?php if ($highlights !== []): ?>
<section class="section section-alt" aria-labelledby="kupalar-baslik">
  <div class="container">
    <div class="section-header">
      <div>
        <span class="eyebrow">Başarılar</span>
        <h2 class="section-title" id="kupalar-baslik">Kupalar ve ödüller</h2>
      </div>
      <a class="link-more" href="<?= e(url('/kupalar-ve-oduller')) ?>">Tüm kupalar ve ödüller</a>
    </div>
    <ul class="honour-grid list-unstyled">
      <?php foreach ($highlights as $h): ?>
      <li class="honour-card">
        <span class="honour-count"><?= e((string) $h['value']) ?><span aria-hidden="true">×</span></span>
        <span class="honour-text"><strong><?= e($h['label']) ?></strong><span><?= e($h['sub']) ?></span></span>
      </li>
      <?php endforeach; ?>
    </ul>
  </div>
</section>
<?php endif; ?>

<?php if ($shopEnabled): ?>
<section class="section" aria-labelledby="magaza-baslik">
  <div class="container">
    <div class="shop-teaser">
      <div class="shop-teaser-text">
        <span class="eyebrow">Forma Mağazası</span>
        <h2 class="section-title" id="magaza-baslik">Ronaldo’nun giydiği formalar</h2>
        <p class="lead">Sporting CP’den Manchester United’a, Real Madrid’den Juventus’a, Al-Nassr’dan Portekiz Milli Takımı’na; CR7’nin kariyerindeki tüm takımların formaları tek sayfada.</p>
        <?php if (!$ordersEnabled): ?><p class="badge">Yakında satışta</p><?php endif; ?>
        <div class="cluster"><a class="btn btn-gold" href="<?= e(url('/forma-magazasi')) ?>">Formaları incele</a></div>
      </div>
      <?php if ($products !== []): ?>
      <ul class="shop-teaser-items list-unstyled" aria-label="Öne çıkan formalar">
        <?php foreach ($products as $product): ?>
        <li>
          <a href="<?= e(url((string) ($product['url'] ?? ('/forma/' . ($product['slug'] ?? ''))))) ?>">
            <?php if (!empty($product['image_url'])): ?><img src="<?= e($product['image_url']) ?>" alt="<?= e((string) ($product['title'] ?? 'Forma')) ?>" width="240" height="240" loading="lazy" decoding="async"><?php endif; ?>
            <span><?= e((string) ($product['title'] ?? '')) ?></span>
          </a>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php else: ?>
      <?php $crests = array_values(array_filter(array_merge($clubs, [$national]), static fn (array $c): bool => !empty($c['logo_url']))); ?>
      <?php if ($crests !== []): ?>
      <ul class="shop-teaser-crests list-unstyled" aria-label="Formaları bulunan takımlar">
        <?php $seenCrest = []; foreach ($crests as $c): if (isset($seenCrest[$c['logo_url']])) { continue; } $seenCrest[$c['logo_url']] = true; ?>
        <li><img src="<?= e($c['logo_url']) ?>" alt="<?= e(($c['name'] ?? '') . ' logosu') ?>" width="64" height="64" loading="lazy" decoding="async"></li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if ($faq !== []): ?>
<section class="section<?= $shopEnabled ? ' section-alt' : '' ?>" aria-labelledby="sss-baslik">
  <div class="container container-narrow">
    <span class="eyebrow">Merak edilenler</span>
    <h2 class="section-title" id="sss-baslik">Ronaldo hakkında sık sorulanlar</h2>
    <div class="faq">
      <?php foreach ($faq as $item): ?>
      <details>
        <summary><?= e($item['q']) ?></summary>
        <p><?= e($item['a']) ?></p>
      </details>
      <?php endforeach; ?>
    </div>
    <p class="home-faq-more"><a class="link-more" href="<?= e(url('/hayati#sss')) ?>">Tüm soruları gör</a></p>
  </div>
</section>
<?php endif; ?>
