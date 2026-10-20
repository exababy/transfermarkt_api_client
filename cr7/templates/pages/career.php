<?php
/**
 * /kariyer — career page.
 *
 * @var array $narrative content/career.php
 * @var list<array<string, mixed>> $spells StatsRepository::careerClubs()
 * @var array<string, array<string, mixed>> $pages ClubRepository::pages()
 * @var array|null $player
 * @var array $career careerTotals()
 * @var array $clubTotals
 * @var array $national nationalTotals()
 * @var list<array<string, mixed>> $nationalCareer
 * @var list<array<string, mixed>> $transfers
 * @var list<array<string, mixed>> $marketValues
 * @var array<string, string> $clubNames club id => name (market value clubs)
 * @var string|null $lastSync
 */

use App\Repositories\ClubRepository;
use App\Support\Format;
use App\Support\Svg;

$title = (string) ($narrative['title'] ?? 'Cristiano Ronaldo’nun Kariyeri');
$youth = (array) ($narrative['youth'] ?? []);
$nationalContent = (array) ($narrative['national'] ?? []);
$portugal = $pages['portekiz'] ?? null;
$goalsPerGame = $career['appearances'] > 0 ? $career['goals'] / $career['appearances'] : 0;

$points = [];
foreach ($marketValues as $mv) {
    $club = $clubNames[$mv['club_id'] ?? ''] ?? '';
    $points[] = ['x' => $mv['value_date'], 'y' => $mv['value'], 'tip' => trim($club . ($mv['age'] !== null ? ' · ' . $mv['age'] . ' yaş' : ''), ' ·')];
}
$peak = null;
foreach ($marketValues as $mv) {
    if ($peak === null || $mv['value'] > $peak['value']) {
        $peak = $mv;
    }
}
$currentMv = $marketValues !== [] ? $marketValues[count($marketValues) - 1] : null;
$mvSvg = Svg::line($points, [
    'id' => 'kariyer-piyasa',
    'title' => 'Cristiano Ronaldo’nun piyasa değeri gelişimi',
    'desc' => $peak !== null
        ? sprintf('%s ile %s arasında Transfermarkt piyasa değeri. Zirve: %s (%s). Son değer: %s.', Format::date($marketValues[0]['value_date'], 'Y'), Format::date($currentMv['value_date'], 'Y'), Format::money($peak['value']), Format::date($peak['value_date'], 'F Y'), Format::money($currentMv['value']))
        : '',
    'format' => static fn ($v): string => Format::money((int) $v),
    'axisFormat' => static fn ($v): string => $v > 0 ? Format::number($v / 1_000_000) . ' mil.' : '0',
]);
$mvRows = array_map(static fn (array $mv): array => [Format::date($mv['value_date'], 'j F Y'), $clubNames[$mv['club_id'] ?? ''] ?? '–', $mv['age'] !== null ? (string) $mv['age'] : '–', Format::money($mv['value'])], array_reverse($marketValues));
$feeText = static function (array $t): string {
    if ($t['fee_value'] !== null) {
        return Format::money($t['fee_value']);
    }
    if (!empty($t['fee_label'])) {
        return (string) $t['fee_label'];
    }
    return $t['transfer_type'] === 'INTERNAL_TRANSFER' ? 'Kulüp içi terfi' : '–';
};
?>
<header class="page-header">
  <div class="container">
    <span class="eyebrow">Kariyer</span>
    <h1><?= e($title) ?></h1>
    <p class="lead">Madeira’daki ilk adımlardan Sporting CP, Manchester United, Real Madrid, Juventus ve Al-Nassr’a; kulüp kulüp istatistikler, transferler ve Portekiz Milli Takımı.</p>
  </div>
</header>

<section class="section-sm" aria-labelledby="kariyer-ozet">
  <div class="container stack">
    <h2 class="visually-hidden" id="kariyer-ozet">Kariyer özeti</h2>
    <div class="stat-tiles">
      <?= partial('partials/stat-tile', ['value' => $career['appearances'], 'label' => 'Resmî maç', 'sub' => 'Tüm takımlar']) ?>
      <?= partial('partials/stat-tile', ['value' => $career['goals'], 'label' => 'Gol', 'sub' => 'Maç başına ' . Format::number($goalsPerGame, 2)]) ?>
      <?= partial('partials/stat-tile', ['value' => $career['assists'], 'label' => 'Asist']) ?>
      <?= partial('partials/stat-tile', ['value' => $clubTotals['goals'], 'label' => 'Kulüp golü', 'sub' => Format::number($clubTotals['appearances']) . ' maçta']) ?>
      <?= partial('partials/stat-tile', ['value' => $national['goals'], 'label' => 'Milli gol', 'sub' => Format::number($national['games']) . ' maçta']) ?>
    </div>
    <p class="meta"><span>Kaynak: Transfermarkt</span><?php if ($lastSync): ?><span>Son güncelleme: <time datetime="<?= e(Format::iso($lastSync)) ?>"><?= e(Format::date($lastSync, 'j F Y H:i')) ?></time></span><?php endif; ?><span>Toplamlara genç milli takımlar dahildir</span></p>
    <?php if (!empty($narrative['intro'])): ?>
    <div class="prose"><?= $narrative['intro'] ?></div>
    <?php endif; ?>
    <nav class="tags" aria-label="Takım sayfaları">
      <?php foreach ($pages as $page): ?>
      <a class="tag" href="<?= e(url($page['url'])) ?>"><?= e($page['name']) ?></a>
      <?php endforeach; ?>
      <a class="tag" href="<?= e(url('/istatistikler')) ?>">Tüm istatistikler</a>
    </nav>
  </div>
</section>

<section class="section section-alt" id="kulup-kulup" aria-labelledby="kulup-kulup-baslik">
  <div class="container">
    <div class="section-header">
      <h2 class="section-title" id="kulup-kulup-baslik">Kulüp Kulüp Kariyer Yolculuğu</h2>
      <p class="lead">Altyapıdan A takımlara, her dönemin maç, gol ve asist sayıları (Transfermarkt maç kayıtlarından).</p>
    </div>
    <ol class="timeline">
      <?php if ($youth !== []): ?>
      <li class="timeline-item">
        <span class="timeline-year"><?= e((string) ($youth['period'] ?? '1992–2002')) ?></span>
        <div>
          <h3><?= e((string) ($youth['title'] ?? 'Altyapı Yılları')) ?></h3>
          <p class="meta"><span>CF Andorinha</span><span>CD Nacional</span><span>Sporting CP Akademi</span></p>
          <?php if (!empty($youth['html'])): ?><div class="prose"><?= $youth['html'] ?></div><?php endif; ?>
          <?php if (!empty($youth['highlights'])): ?>
          <ul class="prose">
            <?php foreach ($youth['highlights'] as $h): ?><li><?= e($h) ?></li><?php endforeach; ?>
          </ul>
          <?php endif; ?>
        </div>
      </li>
      <?php endif; ?>
      <?php foreach ($spells as $spell): ?>
      <li class="timeline-item">
        <span class="timeline-year"><?= e($spell['period']) ?></span>
        <div class="card">
          <div class="card-body">
            <div class="cluster">
              <?php if ($spell['logo_url']): ?><img class="logo-md" src="<?= e($spell['logo_url']) ?>" alt="<?= e($spell['name']) ?> logosu" width="44" height="44" loading="lazy" decoding="async"><?php endif; ?>
              <div>
                <h3 class="card-title"><a href="<?= e(url($spell['url'])) ?>"><?= e($spell['name']) ?></a></h3>
                <p class="meta mb-0"><?php if ($spell['country'] !== ''): ?><span><?= e($spell['country']) ?></span><?php endif; ?><span><?= $spell['to_year'] === null ? 'Hâlâ forma giyiyor' : e($spell['period']) ?></span></p>
              </div>
            </div>
            <?php if ($spell['summary'] !== ''): ?><p class="card-text"><?= e($spell['summary']) ?></p><?php endif; ?>
            <div class="club-card-stats" style="justify-content:flex-start">
              <span><strong><?= e(Format::number($spell['appearances'])) ?></strong>Maç</span>
              <span><strong><?= e(Format::number($spell['goals'])) ?></strong>Gol</span>
              <span><strong><?= e(Format::number($spell['assists'])) ?></strong>Asist</span>
              <span><strong><?= e(Format::number($spell['minutes'])) ?></strong>Dakika</span>
            </div>
          </div>
        </div>
      </li>
      <?php endforeach; ?>
      <?php if ($nationalContent !== []): ?>
      <li class="timeline-item">
        <span class="timeline-year"><?= e((string) ($nationalContent['period'] ?? '2003–')) ?></span>
        <div class="card">
          <div class="card-body">
            <div class="cluster">
              <?php $flag = ClubRepository::logo($portugal['logo_url'] ?? null, 'md'); if ($flag): ?><img class="logo-md" src="<?= e($flag) ?>" alt="Portekiz bayrağı" width="44" height="44" loading="lazy" decoding="async"><?php endif; ?>
              <div>
                <h3 class="card-title"><a href="<?= e(url('/takimlar/portekiz')) ?>"><?= e((string) ($nationalContent['name'] ?? 'Portekiz Milli Takımı')) ?></a></h3>
                <p class="meta mb-0"><span>A Milli Takım</span><?php if ($national['debut_date']): ?><span>İlk maç: <?= e(Format::date($national['debut_date'])) ?></span><?php endif; ?></p>
              </div>
            </div>
            <?php if (!empty($nationalContent['summary'])): ?><p class="card-text"><?= e($nationalContent['summary']) ?></p><?php endif; ?>
            <div class="club-card-stats" style="justify-content:flex-start">
              <span><strong><?= e(Format::number($national['games'])) ?></strong>Maç</span>
              <span><strong><?= e(Format::number($national['goals'])) ?></strong>Gol</span>
            </div>
          </div>
        </div>
      </li>
      <?php endif; ?>
    </ol>
  </div>
</section>

<section class="section" id="transferler" aria-labelledby="transferler-baslik">
  <div class="container">
    <div class="section-header">
      <h2 class="section-title" id="transferler-baslik">Transfer Geçmişi</h2>
      <p class="lead">Altyapı terfilerinden dünya rekoru transferlere, bonservis bedelleri ve transfer anındaki piyasa değerleri.</p>
    </div>
    <?php if ($transfers === []): ?>
    <div class="empty-state"><p>Transfer verileri ilk senkronizasyondan sonra burada yer alacak.</p></div>
    <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <caption>Kaynak: Transfermarkt. Bedeller euro cinsindendir; “mil.” milyon, “bin” bin euro anlamına gelir.</caption>
        <thead>
          <tr><th scope="col">Tarih</th><th scope="col">Sezon</th><th scope="col">Ayrıldığı takım</th><th scope="col">Katıldığı takım</th><th scope="col" class="num">Bonservis</th><th scope="col" class="num">Piyasa değeri</th><th scope="col" class="num">Yaş</th></tr>
        </thead>
        <tbody>
          <?php foreach (array_reverse($transfers) as $t): ?>
          <tr>
            <td><?= $t['transfer_date'] ? '<time datetime="' . e($t['transfer_date']) . '">' . e(Format::date($t['transfer_date'], 'j M Y')) . '</time>' : '–' ?></td>
            <td><?= e((string) ($t['season_label'] ?? '–')) ?></td>
            <?php foreach (['from', 'to'] as $side): ?>
            <td>
              <span class="cell-club">
                <?php if ($t[$side . '_logo']): ?><img class="logo-xs" src="<?= e($t[$side . '_logo']) ?>" alt="" width="18" height="18" loading="lazy" decoding="async"><?php endif; ?>
                <?php $page = $pages[$t[$side . '_slug'] ?? ''] ?? null; ?>
                <?php if ($page): ?><a href="<?= e(url($page['url'])) ?>"><?= e($t[$side . '_name']) ?></a><?php else: ?><?= e($t[$side . '_name']) ?><?php endif; ?>
              </span>
            </td>
            <?php endforeach; ?>
            <td class="num"><?= e($feeText($t)) ?></td>
            <td class="num"><?= e(Format::money($t['market_value'])) ?></td>
            <td class="num"><?= e($t['age'] !== null ? (string) $t['age'] : '–') ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</section>

<section class="section section-alt" id="piyasa-degeri" aria-labelledby="piyasa-degeri-baslik">
  <div class="container stack">
    <div class="section-header">
      <h2 class="section-title" id="piyasa-degeri-baslik">Piyasa Değeri Gelişimi</h2>
      <p class="lead">Transfermarkt’ın Cristiano Ronaldo için açıkladığı piyasa değerleri, 2004’ten bugüne.</p>
    </div>
    <?php if ($player !== null): ?>
    <div class="stat-tiles">
      <?= partial('partials/stat-tile', ['value' => Format::money($player['market_value']), 'label' => 'Güncel değer', 'sub' => $player['market_value_date'] ? Format::date($player['market_value_date'], 'j F Y') : null]) ?>
      <?= partial('partials/stat-tile', ['value' => Format::money($player['highest_market_value']), 'label' => 'En yüksek değer', 'sub' => $player['highest_market_value_date'] ? Format::date($player['highest_market_value_date'], 'j F Y') : null]) ?>
      <?php if (!empty($player['contract_until'])): ?>
      <?= partial('partials/stat-tile', ['value' => Format::date($player['contract_until'], 'Y'), 'label' => 'Sözleşme bitişi', 'sub' => Format::date($player['contract_until'], 'j F Y') . ($player['current_club_name'] ? ' · ' . $player['current_club_name'] : '')]) ?>
      <?php endif; ?>
    </div>
    <?php endif; ?>
    <?= partial('partials/stats-chart', [
        'id' => 'piyasa-degeri-grafik',
        'title' => 'Piyasa değeri (euro)',
        'svg' => $mvSvg,
        'caption' => 'Kaynak: Transfermarkt. Çizgi, her değerlendirme tarihindeki piyasa değerini gösterir; işaretli noktalar zirve ve güncel değerdir.',
        'headers' => ['Tarih', 'Kulüp', 'Yaş', 'Piyasa değeri'],
        'rows' => $mvRows,
        'numeric' => [2, 3],
    ]) ?>
  </div>
</section>

<section class="section" id="milli-takim" aria-labelledby="milli-takim-baslik">
  <div class="container stack">
    <div class="section-header">
      <h2 class="section-title" id="milli-takim-baslik">Portekiz Milli Takımı Kariyeri</h2>
      <?php if (!empty($nationalContent['summary'])): ?><p class="lead"><?= e($nationalContent['summary']) ?></p><?php endif; ?>
    </div>
    <?php if ($nationalCareer !== []): ?>
    <div class="table-wrap">
      <table class="table">
        <caption>Genç milli takımlar dahil tüm Portekiz takımları (Kaynak: Transfermarkt)</caption>
        <thead><tr><th scope="col">Takım</th><th scope="col" class="num">Maç</th><th scope="col" class="num">Gol</th><th scope="col">İlk maç</th><th scope="col">Son maç</th></tr></thead>
        <tbody>
          <?php foreach ($nationalCareer as $row): ?>
          <tr>
            <th scope="row"><span class="cell-club"><?php if ($row['logo_url']): ?><img class="logo-xs" src="<?= e($row['logo_url']) ?>" alt="" width="18" height="18" loading="lazy" decoding="async"><?php endif; ?><?php if ($row['club_id'] === '3300'): ?><a href="<?= e(url('/takimlar/portekiz')) ?>"><?= e($row['name']) ?></a><?php else: ?><?= e($row['name']) ?><?php endif; ?></span></th>
            <td class="num"><?= e(Format::number($row['games'])) ?></td>
            <td class="num"><?= e(Format::number($row['goals'])) ?></td>
            <td><?= e($row['debut_date'] ? Format::date($row['debut_date'], 'j M Y') : '–') ?></td>
            <td><?= e($row['last_game_date'] ? Format::date($row['last_game_date'], 'j M Y') : '–') ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
    <?php if (!empty($nationalContent['honours'])): ?>
    <div>
      <h3>Milli takımla kazandığı kupalar</h3>
      <ul class="prose"><?php foreach ($nationalContent['honours'] as $h): ?><li><?= e($h) ?></li><?php endforeach; ?></ul>
    </div>
    <?php endif; ?>
    <p><a class="link-more" href="<?= e(url('/takimlar/portekiz')) ?>">Portekiz Milli Takımı sayfasında tüm ayrıntılar</a></p>
  </div>
</section>

<?php if (!empty($narrative['timeline'])): ?>
<section class="section section-alt" id="yil-yil" aria-labelledby="yil-yil-baslik">
  <div class="container">
    <div class="section-header">
      <h2 class="section-title" id="yil-yil-baslik">Yıl Yıl Kariyerinin Dönüm Noktaları</h2>
    </div>
    <ol class="timeline">
      <?php foreach ($narrative['timeline'] as $item): ?>
      <li class="timeline-item"><span class="timeline-year"><?= e((string) $item['year']) ?></span><p><?= e((string) $item['text']) ?></p></li>
      <?php endforeach; ?>
    </ol>
  </div>
</section>
<?php endif; ?>

<section class="section-sm">
  <div class="container stack">
    <div class="cluster">
      <a class="btn btn-gold" href="<?= e(url('/istatistikler')) ?>">Ayrıntılı istatistikler</a>
      <a class="btn btn-outline" href="<?= e(url('/takimlar')) ?>">Oynadığı takımlar</a>
      <a class="btn btn-ghost" href="<?= e(url('/istatistikler/maclar')) ?>">Maç maç kayıtlar</a>
    </div>
    <?php if (!empty($narrative['sources'])): ?>
    <details>
      <summary class="btn btn-sm btn-ghost">Kaynaklar (<?= count($narrative['sources']) ?>)</summary>
      <ul class="prose">
        <?php foreach ($narrative['sources'] as $source): ?>
        <li><a href="<?= e($source['url']) ?>" rel="nofollow noopener" target="_blank"><?= e($source['title']) ?></a></li>
        <?php endforeach; ?>
      </ul>
    </details>
    <?php endif; ?>
  </div>
</section>
<?= partial('partials/stats-chart-script', ['cspNonce' => $cspNonce ?? '']) ?>
