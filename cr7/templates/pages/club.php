<?php
/**
 * /takimlar/{slug} — one career club (Manchester United: both spells) or Portekiz.
 *
 * @var array<string, mixed> $page ClubRepository::page()
 * @var array<string, mixed>|null $club clubs row
 * @var string|null $logo
 * @var string $period
 * @var list<array<string, mixed>> $spells careerClubs() rows of this club
 * @var array<string, int> $totals gameTotals()
 * @var list<array<string, mixed>> $seasons seasonStats() ascending
 * @var list<array<string, mixed>> $competitions
 * @var list<array<string, mixed>> $opponents
 * @var list<array<string, mixed>> $wins
 * @var list<array<string, mixed>> $hatTricks
 * @var list<array<string, mixed>> $latest
 * @var list<array<string, mixed>> $transfers
 * @var list<array<string, mixed>> $nationalCareer
 * @var array|null $prev
 * @var array|null $next
 * @var string|null $lastSync
 */

use App\Repositories\ClubRepository;
use App\Support\Format;

$name = (string) $page['name'];
$entries = array_values($page['spells']);
$multi = count($entries) > 1;
$isNational = (bool) $page['is_national'];
$ratio = $totals['appearances'] > 0 ? $totals['goals'] / $totals['appearances'] : 0;
$winRate = $totals['appearances'] > 0 ? $totals['wins'] / $totals['appearances'] * 100 : 0;
$ordinals = ['Birinci', 'İkinci', 'Üçüncü', 'Dördüncü'];
$color = !empty($page['color1']) && $page['color1'] !== '#FFFFFF' ? (string) $page['color1'] : null;

// Season rows grouped by spell (latest spell whose years contain the season).
$groups = [];
foreach ($entries as $i => $entry) {
    $groups[$i] = ['entry' => $entry, 'rows' => []];
}
foreach ($seasons as $row) {
    $target = 0;
    foreach ($entries as $i => $entry) {
        $from = isset($entry['from']) ? (int) $entry['from'] - 1 : 0;
        $to = isset($entry['to']) ? (int) $entry['to'] : 9999;
        if ($row['season_id'] >= $from && $row['season_id'] <= $to) {
            $target = $i;
        }
    }
    $groups[$target]['rows'][] = $row;
}
if ($groups === []) {
    $groups[] = ['entry' => [], 'rows' => $seasons];
}
$sum = static function (array $rows, string $col): int {
    return array_sum(array_map(static fn (array $r): int => (int) $r[$col], $rows));
};
?>
<header class="page-header"<?= $color ? ' style="border-top: 3px solid ' . e($color) . '"' : '' ?>>
  <div class="container">
    <div class="cluster">
      <?php if ($logo): ?><img class="club-logo" src="<?= e($logo) ?>" alt="<?= e($name) ?> <?= $isNational ? 'bayrağı' : 'logosu' ?>" width="76" height="76" style="width:76px;height:76px;object-fit:contain"><?php endif; ?>
      <div>
        <span class="eyebrow"><?= e($isNational ? 'Milli Takım' : 'Oynadığı Takımlar') ?><?= $page['country'] !== '' ? ' · ' . e($page['country']) : '' ?></span>
        <h1>Cristiano Ronaldo’nun <?= e($name) ?> Kariyeri</h1>
      </div>
    </div>
    <p class="lead"><?= e((string) ($entries[0]['summary'] ?? '')) ?></p>
    <p class="meta"><span class="club-years"><?= e($period) ?></span><?php if ($club && $club['name'] !== $name): ?><span><?= e($club['name']) ?></span><?php endif; ?><span>Kaynak: Transfermarkt</span></p>
  </div>
</header>

<section class="section-sm" aria-labelledby="ozet-baslik">
  <div class="container stack">
    <h2 class="visually-hidden" id="ozet-baslik"><?= e($name) ?> istatistik özeti</h2>
    <div class="stat-tiles">
      <?= partial('partials/stat-tile', ['value' => $totals['appearances'], 'label' => 'Maç', 'sub' => Format::number($totals['minutes']) . ' dakika']) ?>
      <?= partial('partials/stat-tile', ['value' => $totals['goals'], 'label' => 'Gol', 'sub' => 'Maç başına ' . Format::number($ratio, 2)]) ?>
      <?= partial('partials/stat-tile', ['value' => $totals['assists'], 'label' => 'Asist', 'sub' => Format::number($totals['starts']) . ' kez ilk 11']) ?>
      <?= partial('partials/stat-tile', ['value' => $totals['penalty_goals'], 'label' => 'Penaltı golü']) ?>
      <?= partial('partials/stat-tile', ['value' => $totals['hat_tricks'], 'label' => 'Hat-trick', 'sub' => 'Maçta 3+ gol']) ?>
      <?= partial('partials/stat-tile', ['value' => Format::number($winRate, 1) . '%', 'label' => 'Galibiyet oranı', 'sub' => $totals['wins'] . 'G ' . $totals['draws'] . 'B ' . $totals['losses'] . 'M']) ?>
    </div>
    <?php if ($multi && $spells !== []): ?>
    <div class="grid grid-2">
      <?php foreach ($spells as $i => $spell): ?>
      <article class="card">
        <div class="card-body">
          <span class="eyebrow"><?= e(($ordinals[$i] ?? ($i + 1) . '.') . ' dönem') ?></span>
          <h3 class="card-title"><?= e($spell['period']) ?></h3>
          <div class="club-card-stats" style="justify-content:flex-start">
            <span><strong><?= e(Format::number($spell['appearances'])) ?></strong>Maç</span>
            <span><strong><?= e(Format::number($spell['goals'])) ?></strong>Gol</span>
            <span><strong><?= e(Format::number($spell['assists'])) ?></strong>Asist</span>
            <span><strong><?= e(Format::number($spell['minutes'])) ?></strong>Dakika</span>
          </div>
          <?php if ($spell['summary'] !== ''): ?><p class="card-text"><?= e($spell['summary']) ?></p><?php endif; ?>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</section>

<section class="section" aria-labelledby="hikaye-baslik">
  <div class="container layout-sidebar">
    <div>
      <h2 class="section-title" id="hikaye-baslik"><?= e($name) ?> Yılları</h2>
      <?php foreach ($entries as $i => $entry): ?>
      <div class="prose">
        <?php if ($multi): ?><h3><?= e(($ordinals[$i] ?? ($i + 1) . '.') . ' dönem: ' . ($entry['period'] ?? '')) ?></h3><?php endif; ?>
        <?= (string) ($entry['html'] ?? '') ?>
      </div>
      <?php endforeach; ?>
    </div>
    <aside class="stack" aria-label="<?= e($name) ?> özet bilgiler">
      <?php foreach ($entries as $i => $entry): ?>
      <?php if (!empty($entry['honours'])): ?>
      <div class="card"><div class="card-body">
        <h3 class="card-title">Kupalar<?= $multi ? ' (' . e((string) ($entry['period'] ?? '')) . ')' : '' ?></h3>
        <ul class="prose" style="font-size:.95rem">
          <?php foreach ($entry['honours'] as $honour): ?><li><?= e($honour) ?></li><?php endforeach; ?>
        </ul>
      </div></div>
      <?php endif; ?>
      <?php endforeach; ?>
      <?php if ($transfers !== []): ?>
      <div class="card"><div class="card-body">
        <h3 class="card-title">Transferler</h3>
        <ul class="list-unstyled stack" style="--gap:10px">
          <?php foreach ($transfers as $t): ?>
          <li>
            <strong><?= e($t['transfer_date'] ? Format::date($t['transfer_date'], 'j F Y') : '–') ?></strong><br>
            <?= e($t['from_name']) ?> → <?= e($t['to_name']) ?><br>
            <span class="text-muted">Bonservis: <?= e($t['fee_value'] !== null ? Format::money($t['fee_value']) : ($t['fee_label'] ?: '–')) ?><?= $t['market_value'] !== null ? ' · Piyasa değeri: ' . e(Format::money($t['market_value'])) : '' ?></span>
          </li>
          <?php endforeach; ?>
        </ul>
      </div></div>
      <?php endif; ?>
      <?php foreach ($entries as $i => $entry): ?>
      <?php if (!empty($entry['highlights'])): ?>
      <div class="card"><div class="card-body">
        <h3 class="card-title">Öne çıkanlar<?= $multi ? ' (' . e((string) ($entry['period'] ?? '')) . ')' : '' ?></h3>
        <ul class="prose" style="font-size:.95rem">
          <?php foreach ($entry['highlights'] as $h): ?><li><?= e($h) ?></li><?php endforeach; ?>
        </ul>
      </div></div>
      <?php endif; ?>
      <?php endforeach; ?>
    </aside>
  </div>
</section>

<section class="section section-alt" id="sezonlar" aria-labelledby="sezonlar-baslik">
  <div class="container stack">
    <div class="section-header">
      <h2 class="section-title" id="sezonlar-baslik">Sezon Sezon <?= e($name) ?> İstatistikleri</h2>
      <p class="lead">Tüm resmî turnuvalar dahil; sütun başlıklarına dokunarak sıralayın.</p>
    </div>
    <?php if ($seasons === []): ?>
    <div class="empty-state"><p>Sezon istatistikleri ilk senkronizasyondan sonra burada yer alacak.</p></div>
    <?php endif; ?>
    <?php foreach ($groups as $i => $group): ?>
    <?php if ($group['rows'] === []) { continue; } ?>
    <?php if ($multi): ?><h3 class="mb-0"><?= e(($ordinals[$i] ?? ($i + 1) . '.') . ' dönem: ' . ($group['entry']['period'] ?? '')) ?></h3><?php endif; ?>
    <div class="table-wrap">
      <table class="table table-sortable">
        <thead>
          <tr>
            <th scope="col">Sezon</th><th scope="col" class="num">Maç</th><th scope="col" class="num">Gol</th><th scope="col" class="num">Asist</th>
            <th scope="col" class="num">Dakika</th><th scope="col" class="num">Gol/Maç</th><th scope="col" class="num">Penaltı</th>
            <th scope="col" class="num">Sarı</th><th scope="col" class="num">Kırmızı</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($group['rows'] as $row): ?>
          <tr>
            <th scope="row"><a href="<?= e(url('/istatistikler/maclar?sezon=' . $row['season_id'])) ?>"><?= e($row['season_label']) ?></a></th>
            <td class="num"><?= e(Format::number($row['appearances'])) ?></td>
            <td class="num"><strong><?= e(Format::number($row['goals'])) ?></strong></td>
            <td class="num"><?= e(Format::number($row['assists'])) ?></td>
            <td class="num"><?= e(Format::number($row['minutes'])) ?></td>
            <td class="num"><?= e($row['appearances'] > 0 ? Format::number($row['goals'] / $row['appearances'], 2) : '–') ?></td>
            <td class="num"><?= e(Format::number($row['penalty_goals'])) ?></td>
            <td class="num"><?= e(Format::number($row['yellow'])) ?></td>
            <td class="num"><?= e(Format::number($row['red'] + $row['yellow_red'])) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <th scope="row">Toplam</th>
            <td class="num"><?= e(Format::number($sum($group['rows'], 'appearances'))) ?></td>
            <td class="num"><?= e(Format::number($sum($group['rows'], 'goals'))) ?></td>
            <td class="num"><?= e(Format::number($sum($group['rows'], 'assists'))) ?></td>
            <td class="num"><?= e(Format::number($sum($group['rows'], 'minutes'))) ?></td>
            <td class="num"><?php $a = $sum($group['rows'], 'appearances'); ?><?= e($a > 0 ? Format::number($sum($group['rows'], 'goals') / $a, 2) : '–') ?></td>
            <td class="num"><?= e(Format::number($sum($group['rows'], 'penalty_goals'))) ?></td>
            <td class="num"><?= e(Format::number($sum($group['rows'], 'yellow'))) ?></td>
            <td class="num"><?= e(Format::number($sum($group['rows'], 'red') + $sum($group['rows'], 'yellow_red'))) ?></td>
          </tr>
        </tfoot>
      </table>
    </div>
    <?php endforeach; ?>
  </div>
</section>

<?php if ($competitions !== []): ?>
<section class="section" id="turnuvalar" aria-labelledby="turnuvalar-baslik">
  <div class="container">
    <div class="section-header"><h2 class="section-title" id="turnuvalar-baslik">Turnuvalara Göre</h2></div>
    <div class="table-wrap">
      <table class="table table-sortable">
        <thead><tr><th scope="col">Turnuva</th><th scope="col" class="num">Sezon</th><th scope="col" class="num">Maç</th><th scope="col" class="num">Gol</th><th scope="col" class="num">Asist</th><th scope="col" class="num">Dakika</th><th scope="col" class="num">Penaltı golü</th></tr></thead>
        <tbody>
          <?php foreach ($competitions as $c): ?>
          <tr>
            <th scope="row"><span class="cell-club"><?php if ($c['logo_url']): ?><img class="logo-xs" src="<?= e($c['logo_url']) ?>" alt="" width="18" height="18" loading="lazy" decoding="async"><?php endif; ?><?= e($c['name']) ?></span></th>
            <td class="num"><?= e((string) $c['seasons']) ?></td>
            <td class="num"><?= e(Format::number($c['appearances'])) ?></td>
            <td class="num"><strong><?= e(Format::number($c['goals'])) ?></strong></td>
            <td class="num"><?= e(Format::number($c['assists'])) ?></td>
            <td class="num"><?= e(Format::number($c['minutes'])) ?></td>
            <td class="num"><?= e(Format::number($c['penalty_goals'])) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if ($opponents !== [] || $wins !== []): ?>
<section class="section section-alt" aria-labelledby="rakipler-baslik">
  <div class="container grid grid-2">
    <div>
      <h2 class="section-title" id="rakipler-baslik">En Çok Gol Attığı Rakipler</h2>
      <div class="table-wrap">
        <table class="table table-compact">
          <thead><tr><th scope="col" class="num">#</th><th scope="col">Rakip</th><th scope="col" class="num">Maç</th><th scope="col" class="num">Gol</th><th scope="col" class="num">Asist</th></tr></thead>
          <tbody>
            <?php foreach ($opponents as $i => $o): ?>
            <tr>
              <td class="num"><?= $i + 1 ?></td>
              <th scope="row"><span class="cell-club"><?php if ($o['logo_url']): ?><img class="logo-xs" src="<?= e($o['logo_url']) ?>" alt="" width="18" height="18" loading="lazy" decoding="async"><?php endif; ?><?= e($o['name']) ?></span></th>
              <td class="num"><?= e((string) $o['games']) ?></td>
              <td class="num"><strong><?= e((string) $o['goals']) ?></strong></td>
              <td class="num"><?= e((string) $o['assists']) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <div>
      <h2 class="section-title">En Farklı Galibiyetler</h2>
      <div class="table-wrap">
        <table class="table table-compact">
          <thead><tr><th scope="col">Tarih</th><th scope="col">Rakip</th><th scope="col" class="num">Skor</th><th scope="col" class="num">Gol</th></tr></thead>
          <tbody>
            <?php foreach ($wins as $g): ?>
            <tr>
              <td><time datetime="<?= e(Format::iso($g['date_utc'])) ?>"><?= e(Format::date($g['date_utc'], 'j M Y')) ?></time></td>
              <td class="wrap"><?= e($g['opponent_name']) ?> <span class="text-muted">· <?= e($g['competition_name']) ?></span></td>
              <td class="num"><?= e($g['goals_for'] . '–' . $g['goals_against']) ?></td>
              <td class="num"><?= e((string) $g['goals']) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</section>
<?php endif; ?>

<section class="section" id="hat-trickler" aria-labelledby="hat-trick-baslik">
  <div class="container">
    <div class="section-header">
      <h2 class="section-title" id="hat-trick-baslik"><?= e($name) ?> Formasıyla Hat-trickler (<?= count($hatTricks) ?>)</h2>
      <p class="lead">Ronaldo’nun bir maçta üç veya daha fazla gol attığı karşılaşmalar.</p>
    </div>
    <?php if ($hatTricks === []): ?>
    <p class="text-muted">Bu takımda hat-trick kaydı bulunmuyor.</p>
    <?php else: ?>
    <?= partial('partials/stats-games-table', ['games' => $hatTricks, 'showClub' => false, 'showSeason' => true]) ?>
    <?php endif; ?>
  </div>
</section>

<?php if ($latest !== []): ?>
<section class="section section-alt" aria-labelledby="son-maclar-baslik">
  <div class="container">
    <div class="section-header">
      <h2 class="section-title" id="son-maclar-baslik">Son Maçları</h2>
      <a class="link-more" href="<?= e(url('/istatistikler/maclar')) ?>">Tüm maçlar</a>
    </div>
    <?= partial('partials/stats-games-table', ['games' => $latest, 'showClub' => false]) ?>
  </div>
</section>
<?php endif; ?>

<?php if ($nationalCareer !== []): ?>
<section class="section" aria-labelledby="milli-baslik">
  <div class="container">
    <div class="section-header"><h2 class="section-title" id="milli-baslik">Tüm Portekiz Takımları</h2></div>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th scope="col">Takım</th><th scope="col" class="num">Maç</th><th scope="col" class="num">Gol</th><th scope="col">İlk maç</th><th scope="col">Son maç</th></tr></thead>
        <tbody>
          <?php foreach ($nationalCareer as $row): ?>
          <tr>
            <th scope="row"><?= e($row['name']) ?></th>
            <td class="num"><?= e(Format::number($row['games'])) ?></td>
            <td class="num"><?= e(Format::number($row['goals'])) ?></td>
            <td><?= e($row['debut_date'] ? Format::date($row['debut_date'], 'j M Y') : '–') ?></td>
            <td><?= e($row['last_game_date'] ? Format::date($row['last_game_date'], 'j M Y') : '–') ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</section>
<?php endif; ?>

<nav class="section-sm" aria-label="Diğer takımlar">
  <div class="container cluster" style="justify-content:space-between">
    <?php if ($prev): ?><a class="btn btn-ghost" href="<?= e(url($prev['url'])) ?>">← <?= e($prev['name']) ?></a><?php else: ?><span></span><?php endif; ?>
    <a class="btn btn-outline" href="<?= e(url('/takimlar')) ?>">Tüm takımlar</a>
    <?php if ($next): ?><a class="btn btn-ghost" href="<?= e(url($next['url'])) ?>"><?= e($next['name']) ?> →</a><?php else: ?><span></span><?php endif; ?>
  </div>
</nav>
<p class="container meta"><span>İstatistikler: Transfermarkt</span><?php if ($lastSync): ?><span>Son güncelleme: <?= e(Format::date($lastSync, 'j F Y H:i')) ?></span><?php endif; ?></p>
