<?php
/**
 * /istatistikler — overview: tiles, tabs (clubs / competitions / seasons), charts, national team,
 * opponents, injuries, latest matches.
 *
 * @var array $career summary('career') STATCOLS
 * @var array $clubTotals summary('clubs')
 * @var array $national nationalTotals()
 * @var array $gameTotals gameTotals() over all played games
 * @var list<array<string, mixed>> $clubStats
 * @var list<array<string, mixed>> $competitionStats
 * @var list<array<string, mixed>> $seasonStats
 * @var array|null $clubFilter clubStats row selected with ?kulup=
 * @var array<int, array{goals: int, appearances: int, assists: int}> $years
 * @var list<array<string, mixed>> $marketValues
 * @var list<array<string, mixed>> $nationalCareer
 * @var list<array<string, mixed>> $nationalCompetitions
 * @var list<array<string, mixed>> $injuries
 * @var list<array<string, mixed>> $opponents
 * @var list<array<string, mixed>> $latest
 * @var array|null $player
 * @var string|null $lastSync
 * @var array<int, array> $matchSeasons seasonOptions(): seasons the match list can filter by
 * @var array<string, array> $matchCompetitions competitionOptions(): competitions the match list can filter by
 */

use App\Support\Format;
use App\Support\Svg;

$ratio = static fn (int $goals, int $apps): string => $apps > 0 ? Format::number($goals / $apps, 2) : '–';
$cards = static fn (array $r): int => (int) $r['yellow'] + (int) $r['yellow_red'] + (int) $r['red'];

// Goals per calendar year (bar chart).
$yearData = [];
$bestYear = null;
foreach ($years as $year => $row) {
    $yearData[] = ['label' => (string) $year, 'value' => $row['goals'], 'tip' => $row['appearances'] . ' maç, ' . $row['assists'] . ' asist'];
    if ($bestYear === null || $row['goals'] > $years[$bestYear]['goals']) {
        $bestYear = $year;
    }
}
$yearSvg = Svg::bar($yearData, [
    'id' => 'yil-gol',
    'title' => 'Cristiano Ronaldo’nun takvim yılına göre golleri',
    'desc' => $bestYear !== null ? sprintf('%d–%d yılları arasında her takvim yılında attığı resmî goller (kulüp ve milli takım). En golcü yılı %d: %d maçta %d gol.', array_key_first($years), array_key_last($years), $bestYear, $years[$bestYear]['appearances'], $years[$bestYear]['goals']) : '',
    'unit' => 'gol',
]);
$yearRows = [];
foreach (array_reverse($years, true) as $year => $row) {
    $yearRows[] = [(string) $year, Format::number($row['appearances']), Format::number($row['goals']), Format::number($row['assists']), $ratio($row['goals'], $row['appearances'])];
}

// Market value (line chart).
$clubNames = [];
foreach ($clubStats as $row) {
    $clubNames[$row['club_id']] = $row['name'];
}
$points = array_map(static fn (array $mv): array => ['x' => $mv['value_date'], 'y' => $mv['value'], 'tip' => trim(($clubNames[$mv['club_id'] ?? ''] ?? '') . ($mv['age'] !== null ? ' · ' . $mv['age'] . ' yaş' : ''), ' ·')], $marketValues);
$mvSvg = Svg::line($points, [
    'id' => 'istatistik-piyasa',
    'title' => 'Cristiano Ronaldo’nun piyasa değeri gelişimi',
    'desc' => $marketValues !== [] ? sprintf('Transfermarkt piyasa değeri, %s ile %s arası. En yüksek değer %s; güncel değer %s.', Format::date($marketValues[0]['value_date'], 'F Y'), Format::date($marketValues[count($marketValues) - 1]['value_date'], 'F Y'), Format::money(max(array_column($marketValues, 'value'))), Format::money($marketValues[count($marketValues) - 1]['value'])) : '',
    'format' => static fn ($v): string => Format::money((int) $v),
    'axisFormat' => static fn ($v): string => $v > 0 ? Format::number($v / 1_000_000) . ' mil.' : '0',
]);
$mvRows = array_map(static fn (array $mv): array => [Format::date($mv['value_date'], 'j F Y'), $clubNames[$mv['club_id'] ?? ''] ?? '–', $mv['age'] !== null ? (string) $mv['age'] : '–', Format::money($mv['value'])], array_reverse($marketValues));

$seniorFilters = array_values(array_filter($clubStats, static fn (array $r): bool => $r['is_senior'] && $r['slug'] !== null));
$portugalA = null;
foreach ($nationalCareer as $row) {
    if ($row['club_id'] === '3300') {
        $portugalA = $row;
    }
}
$injuryDays = array_sum(array_map(static fn (array $i): int => (int) $i['days'], $injuries));
$injuryGames = array_sum(array_map(static fn (array $i): int => (int) $i['missed_games'], $injuries));
// Before the first sync every total is 0: show one empty state instead of zero tiles and tables.
$hasStats = (int) $career['appearances'] > 0 || $clubStats !== [] || $latest !== [];
?>
<header class="page-header">
  <div class="container">
    <span class="eyebrow">İstatistikler</span>
    <h1>Cristiano Ronaldo İstatistikleri</h1>
    <p class="lead">Kariyerinin tüm resmî maçları: kulüp, turnuva ve sezon bazında gol, asist, dakika ve kart sayıları; yıllara göre goller, piyasa değeri ve milli takım.</p>
    <p class="meta"><?php if ($lastSync): ?><span>Son güncelleme: <time datetime="<?= e(Format::iso($lastSync)) ?>"><?= e(Format::date($lastSync, 'j F Y H:i')) ?></time></span><?php else: ?><span>Veriler henüz senkronize edilmedi</span><?php endif; ?><span>Kaynak: Transfermarkt</span></p>
  </div>
</header>

<?php if (!$hasStats): ?>
<section class="section-sm" aria-labelledby="genel-baslik">
  <div class="container">
    <h2 class="visually-hidden" id="genel-baslik">Genel kariyer istatistikleri</h2>
    <?= partial('partials/stats-empty', ['text' => 'Kulüp, turnuva ve sezon bazında maç, gol, asist ve dakika istatistikleri Transfermarkt ile ilk senkronizasyondan sonra burada görünecek.']) ?>
  </div>
</section>
<?php else: ?>
<section class="section-sm" aria-labelledby="genel-baslik">
  <div class="container stack">
    <h2 class="visually-hidden" id="genel-baslik">Genel kariyer istatistikleri</h2>
    <div class="stat-tiles">
      <?= partial('partials/stat-tile', ['value' => $career['appearances'], 'label' => 'Maç', 'sub' => Format::number($career['starts']) . ' kez ilk 11']) ?>
      <?= partial('partials/stat-tile', ['value' => $career['goals'], 'label' => 'Gol', 'sub' => Format::number($clubTotals['goals']) . ' kulüp, ' . Format::number($national['goals']) . ' milli']) ?>
      <?= partial('partials/stat-tile', ['value' => $career['assists'], 'label' => 'Asist']) ?>
      <?= partial('partials/stat-tile', ['value' => $career['minutes'], 'label' => 'Dakika', 'sub' => 'Yaklaşık ' . Format::number($career['minutes'] / 90) . ' tam maç']) ?>
      <?= partial('partials/stat-tile', ['value' => $ratio($career['goals'], $career['appearances']), 'label' => 'Gol / maç', 'sub' => $career['goals'] > 0 ? 'Her ' . Format::number($career['minutes'] / max(1, $career['goals'])) . ' dakikada bir gol' : null]) ?>
    </div>
    <div class="stat-tiles">
      <?= partial('partials/stat-tile', ['value' => $national['games'], 'label' => 'Milli maç', 'sub' => 'Portekiz A Milli Takımı']) ?>
      <?= partial('partials/stat-tile', ['value' => $national['goals'], 'label' => 'Milli gol', 'sub' => $national['games'] > 0 ? 'Maç başına ' . $ratio($national['goals'], $national['games']) : null]) ?>
      <?= partial('partials/stat-tile', ['value' => $gameTotals['hat_tricks'], 'label' => 'Hat-trick', 'sub' => 'Maçta 3+ gol']) ?>
      <?= partial('partials/stat-tile', ['value' => $career['penalty_goals'], 'label' => 'Penaltı golü', 'sub' => $career['penalty_attempts'] > 0 ? Format::number($career['penalty_attempts']) . ' atıştan (' . Format::percent($career['penalty_goals'] / $career['penalty_attempts'] * 100) . ')' : null]) ?>
    </div>
    <p class="meta"><span>Toplamlar Transfermarkt’ın kariyer verisidir ve genç milli takımları da içerir.</span><a href="<?= e(url('/istatistikler/maclar')) ?>">Maç maç kayıtlar</a></p>
  </div>
</section>

<section class="section section-alt" id="tablolar" aria-labelledby="tablolar-baslik">
  <div class="container">
    <div class="section-header">
      <h2 class="section-title" id="tablolar-baslik">Kulüp, Turnuva ve Sezon Bazında</h2>
      <p class="lead">Tablo başlıklarına dokunarak sıralayabilirsiniz.</p>
    </div>
    <div class="tabs">
      <a class="tab active" href="#tab-kulupler" data-tab-target="#tab-kulupler">Kulüplere göre</a>
      <a class="tab" href="#tab-turnuvalar" data-tab-target="#tab-turnuvalar">Turnuvalara göre</a>
      <a class="tab" href="#tab-sezonlar" data-tab-target="#tab-sezonlar">Sezonlara göre</a>
    </div>

    <div class="tab-panel" id="tab-kulupler">
      <h3 class="visually-hidden">Kulüplere göre istatistikler</h3>
      <div class="table-wrap">
        <table class="table table-sortable">
          <thead><tr><th scope="col">Takım</th><th scope="col" class="num">Maç</th><th scope="col" class="num">Gol</th><th scope="col" class="num">Asist</th><th scope="col" class="num">Dakika</th><th scope="col" class="num">Gol/Maç</th><th scope="col" class="num">Penaltı</th><th scope="col" class="num">Kart</th></tr></thead>
          <tbody>
            <?php foreach ($clubStats as $row): ?>
            <tr>
              <th scope="row"><span class="cell-club"><?php if ($row['logo_url']): ?><img class="logo-xs" src="<?= e($row['logo_url']) ?>" alt="" width="18" height="18" loading="lazy" decoding="async"><?php endif; ?><?php if ($row['is_senior'] && $row['slug']): ?><a href="<?= e(url('/takimlar/' . $row['slug'])) ?>"><?= e($row['name']) ?></a><?php else: ?><?= e($row['name']) ?><?php endif; ?></span></th>
              <td class="num"><?= e(Format::number($row['appearances'])) ?></td>
              <td class="num"><strong><?= e(Format::number($row['goals'])) ?></strong></td>
              <td class="num"><?= e(Format::number($row['assists'])) ?></td>
              <td class="num"><?= e(Format::number($row['minutes'])) ?></td>
              <td class="num"><?= e($ratio($row['goals'], $row['appearances'])) ?></td>
              <td class="num"><?= e(Format::number($row['penalty_goals'])) ?></td>
              <td class="num"><?= e(Format::number($cards($row))) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot><tr><th scope="row">Toplam</th><td class="num"><?= e(Format::number($career['appearances'])) ?></td><td class="num"><?= e(Format::number($career['goals'])) ?></td><td class="num"><?= e(Format::number($career['assists'])) ?></td><td class="num"><?= e(Format::number($career['minutes'])) ?></td><td class="num"><?= e($ratio($career['goals'], $career['appearances'])) ?></td><td class="num"><?= e(Format::number($career['penalty_goals'])) ?></td><td class="num"><?= e(Format::number($cards($career))) ?></td></tr></tfoot>
        </table>
      </div>
    </div>

    <div class="tab-panel" id="tab-turnuvalar">
      <h3 class="visually-hidden">Turnuvalara göre istatistikler</h3>
      <div class="table-wrap">
        <table class="table table-sortable">
          <thead><tr><th scope="col">Turnuva</th><th scope="col" class="num">Maç</th><th scope="col" class="num">Gol</th><th scope="col" class="num">Asist</th><th scope="col" class="num">Dakika</th><th scope="col" class="num">Gol/Maç</th><th scope="col" class="num">Penaltı</th></tr></thead>
          <tbody>
            <?php foreach ($competitionStats as $row): ?>
            <tr>
              <th scope="row"><span class="cell-club"><?php if ($row['logo_url']): ?><img class="logo-xs" src="<?= e($row['logo_url']) ?>" alt="" width="18" height="18" loading="lazy" decoding="async"><?php endif; ?><?php if (isset($matchCompetitions[(string) $row['competition_id']])): ?><a href="<?= e(url('/istatistikler/maclar?turnuva=' . rawurlencode((string) $row['competition_id']))) ?>"><?= e($row['name']) ?></a><?php else: ?><?= e($row['name']) ?><?php endif; ?></span></th>
              <td class="num"><?= e(Format::number($row['appearances'])) ?></td>
              <td class="num"><strong><?= e(Format::number($row['goals'])) ?></strong></td>
              <td class="num"><?= e(Format::number($row['assists'])) ?></td>
              <td class="num"><?= e(Format::number($row['minutes'])) ?></td>
              <td class="num"><?= e($ratio($row['goals'], $row['appearances'])) ?></td>
              <td class="num"><?= e(Format::number($row['penalty_goals'])) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="tab-panel" id="tab-sezonlar">
      <h3 class="visually-hidden">Sezonlara göre istatistikler</h3>
      <nav class="filter-bar" aria-label="Sezon tablosunu takıma göre filtrele">
        <a class="tag<?= $clubFilter === null ? ' active' : '' ?>" href="<?= e(url('/istatistikler') . '#tab-sezonlar') ?>"<?= $clubFilter === null ? ' aria-current="true"' : '' ?>>Tüm takımlar</a>
        <?php foreach ($seniorFilters as $row): ?>
        <?php $on = $clubFilter !== null && $clubFilter['club_id'] === $row['club_id']; ?>
        <a class="tag<?= $on ? ' active' : '' ?>" href="<?= e(url('/istatistikler?kulup=' . rawurlencode((string) $row['slug'])) . '#tab-sezonlar') ?>" rel="nofollow"<?= $on ? ' aria-current="true"' : '' ?>><?= e($row['name']) ?></a>
        <?php endforeach; ?>
      </nav>
      <div class="table-wrap">
        <table class="table table-sortable">
          <?php if ($clubFilter !== null): ?><caption><?= e($clubFilter['name']) ?> sezonları</caption><?php endif; ?>
          <thead><tr><th scope="col">Sezon</th><th scope="col">Takım</th><th scope="col" class="num">Maç</th><th scope="col" class="num">Gol</th><th scope="col" class="num">Asist</th><th scope="col" class="num">Dakika</th><th scope="col" class="num">Gol/Maç</th><th scope="col" class="num">Kart</th></tr></thead>
          <tbody>
            <?php foreach ($seasonStats as $row): ?>
            <tr>
              <th scope="row"><?php if (isset($matchSeasons[(int) $row['season_id']])): ?><a href="<?= e(url('/istatistikler/maclar?sezon=' . $row['season_id'])) ?>"><?= e($row['season_label']) ?></a><?php else: ?><?= e($row['season_label']) ?><?php endif; ?></th>
              <td><span class="cell-club"><?php if ($row['logo_url']): ?><img class="logo-xs" src="<?= e($row['logo_url']) ?>" alt="" width="18" height="18" loading="lazy" decoding="async"><?php endif; ?><?= e($row['name']) ?></span></td>
              <td class="num"><?= e(Format::number($row['appearances'])) ?></td>
              <td class="num"><strong><?= e(Format::number($row['goals'])) ?></strong></td>
              <td class="num"><?= e(Format::number($row['assists'])) ?></td>
              <td class="num"><?= e(Format::number($row['minutes'])) ?></td>
              <td class="num"><?= e($ratio($row['goals'], $row['appearances'])) ?></td>
              <td class="num"><?= e(Format::number($cards($row))) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</section>

<section class="section" id="grafikler" aria-labelledby="grafikler-baslik">
  <div class="container stack">
    <div class="section-header">
      <h2 class="section-title" id="grafikler-baslik">Yıllara Göre Goller ve Piyasa Değeri</h2>
      <p class="lead">Takvim yılı bazında resmî goller (kulüp + milli takım) ve Transfermarkt piyasa değeri.</p>
    </div>
    <?= partial('partials/stats-chart', [
        'id' => 'yillara-gore-goller',
        'title' => 'Takvim yılına göre goller',
        'svg' => $yearSvg,
        'caption' => 'Kaynak: Transfermarkt maç kayıtları. Etiketli sütunlar en golcü yıl ve içinde bulunulan yıldır.',
        'headers' => ['Yıl', 'Maç', 'Gol', 'Asist', 'Gol/Maç'],
        'rows' => $yearRows,
        'numeric' => [1, 2, 3, 4],
    ]) ?>
    <?= partial('partials/stats-chart', [
        'id' => 'piyasa-degeri',
        'title' => 'Piyasa değeri (euro)',
        'svg' => $mvSvg,
        'caption' => 'Kaynak: Transfermarkt. İşaretli noktalar zirve ve güncel değerdir.',
        'headers' => ['Tarih', 'Kulüp', 'Yaş', 'Piyasa değeri'],
        'rows' => $mvRows,
        'numeric' => [2, 3],
    ]) ?>
  </div>
</section>

<section class="section section-alt" id="milli-takim" aria-labelledby="milli-baslik">
  <div class="container stack">
    <div class="section-header">
      <h2 class="section-title" id="milli-baslik">Portekiz Milli Takımı İstatistikleri</h2>
      <a class="link-more" href="<?= e(url('/takimlar/portekiz')) ?>">Milli takım kariyeri</a>
    </div>
    <div class="stat-tiles">
      <?= partial('partials/stat-tile', ['value' => $national['games'], 'label' => 'A milli maç']) ?>
      <?= partial('partials/stat-tile', ['value' => $national['goals'], 'label' => 'A milli gol']) ?>
      <?= partial('partials/stat-tile', ['value' => $national['debut_date'] ? Format::date($national['debut_date'], 'Y') : '–', 'label' => 'İlk maç', 'sub' => $national['debut_date'] ? Format::date($national['debut_date']) : null]) ?>
      <?= partial('partials/stat-tile', ['value' => $national['last_game_date'] ? Format::date($national['last_game_date'], 'Y') : '–', 'label' => 'Son maç', 'sub' => $national['last_game_date'] ? Format::date($national['last_game_date']) : null]) ?>
    </div>
    <div class="grid grid-2-lg">
      <div>
        <h3>Tüm Portekiz takımları</h3>
        <div class="table-wrap">
          <table class="table table-compact">
            <thead><tr><th scope="col">Takım</th><th scope="col" class="num">Maç</th><th scope="col" class="num">Gol</th><th scope="col">İlk maç</th></tr></thead>
            <tbody>
              <?php foreach ($nationalCareer as $row): ?>
              <tr>
                <th scope="row"><span class="cell-club"><?php if ($row['logo_url']): ?><img class="logo-xs" src="<?= e($row['logo_url']) ?>" alt="" width="18" height="18" loading="lazy" decoding="async"><?php endif; ?><?= e($row['name']) ?></span></th>
                <td class="num"><?= e(Format::number($row['games'])) ?></td>
                <td class="num"><?= e(Format::number($row['goals'])) ?></td>
                <td><?= e($row['debut_date'] ? Format::date($row['debut_date'], 'j M Y') : '–') ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <div>
        <h3>A milli takımda turnuvalara göre</h3>
        <div class="table-wrap">
          <table class="table table-compact table-sortable">
            <thead><tr><th scope="col">Turnuva</th><th scope="col" class="num">Maç</th><th scope="col" class="num">Gol</th><th scope="col" class="num">Asist</th></tr></thead>
            <tbody>
              <?php foreach ($nationalCompetitions as $row): ?>
              <tr>
                <th scope="row"><?= e($row['name']) ?></th>
                <td class="num"><?= e(Format::number($row['appearances'])) ?></td>
                <td class="num"><strong><?= e(Format::number($row['goals'])) ?></strong></td>
                <td class="num"><?= e(Format::number($row['assists'])) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</section>

<section class="section" id="rakipler" aria-labelledby="rakipler-baslik">
  <div class="container">
    <div class="section-header">
      <h2 class="section-title" id="rakipler-baslik">En Çok Gol Attığı 15 Rakip</h2>
      <p class="lead">Kulüp ve milli takım maçları dahil.</p>
    </div>
    <div class="table-wrap">
      <table class="table table-sortable">
        <thead><tr><th scope="col" class="num">#</th><th scope="col">Rakip</th><th scope="col" class="num">Maç</th><th scope="col" class="num">Gol</th><th scope="col" class="num">Asist</th><th scope="col" class="num">Gol/Maç</th><th scope="col" class="num">Galibiyet</th></tr></thead>
        <tbody>
          <?php foreach ($opponents as $i => $o): ?>
          <tr>
            <td class="num"><?= $i + 1 ?></td>
            <th scope="row"><span class="cell-club"><?php if ($o['logo_url']): ?><img class="logo-xs" src="<?= e($o['logo_url']) ?>" alt="" width="18" height="18" loading="lazy" decoding="async"><?php endif; ?><?= e($o['name']) ?></span></th>
            <td class="num"><?= e((string) $o['games']) ?></td>
            <td class="num"><strong><?= e((string) $o['goals']) ?></strong></td>
            <td class="num"><?= e((string) $o['assists']) ?></td>
            <td class="num"><?= e($ratio($o['goals'], $o['games'])) ?></td>
            <td class="num"><?= e((string) $o['wins']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</section>

<section class="section section-alt" id="sakatliklar" aria-labelledby="sakatlik-baslik">
  <div class="container">
    <div class="section-header">
      <h2 class="section-title" id="sakatlik-baslik">Sakatlık Geçmişi</h2>
      <p class="lead"><?= e(count($injuries) > 0 ? sprintf('%d sakatlık ve hastalık kaydı; toplam %s gün ve %s maç kaçırdı.', count($injuries), Format::number($injuryDays), Format::number($injuryGames)) : 'Sakatlık kaydı bulunmuyor.') ?></p>
    </div>
    <?php if ($injuries !== []): ?>
    <div class="table-wrap">
      <table class="table table-sortable">
        <thead><tr><th scope="col">Sezon</th><th scope="col">Sakatlık</th><th scope="col">Başlangıç</th><th scope="col">Bitiş</th><th scope="col" class="num">Gün</th><th scope="col" class="num">Kaçırdığı maç</th></tr></thead>
        <tbody>
          <?php foreach ($injuries as $injury): ?>
          <tr>
            <td><?= e($injury['season_label']) ?></td>
            <th scope="row" class="wrap"><?= e($injury['name']) ?></th>
            <td data-sort-value="<?= e((string) $injury['start_date']) ?>"><?= e($injury['start_date'] ? Format::date($injury['start_date'], 'j M Y') : '–') ?></td>
            <td data-sort-value="<?= e((string) $injury['end_date']) ?>"><?= e($injury['end_date'] ? Format::date($injury['end_date'], 'j M Y') : '–') ?></td>
            <td class="num"><?= e($injury['days'] !== null ? (string) $injury['days'] : '–') ?></td>
            <td class="num"><?= e($injury['missed_games'] !== null ? (string) $injury['missed_games'] : '–') ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</section>

<section class="section" id="son-maclar" aria-labelledby="son-maclar-baslik">
  <div class="container">
    <div class="section-header">
      <h2 class="section-title" id="son-maclar-baslik">Son 10 Maç</h2>
      <a class="link-more" href="<?= e(url('/istatistikler/maclar')) ?>">Tüm maçlar</a>
    </div>
    <?php if ($latest === []): ?>
    <div class="empty-state"><p>Maç kayıtları ilk senkronizasyondan sonra burada yer alacak.</p></div>
    <?php else: ?>
    <?= partial('partials/stats-games-table', ['games' => $latest]) ?>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>
<?= partial('partials/stats-chart-script', ['cspNonce' => $cspNonce ?? '']) ?>
