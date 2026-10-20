<?php
/**
 * /istatistikler/maclar — filterable, paginated match log.
 *
 * @var list<array<string, mixed>> $games
 * @var array<string, int> $totals gameTotals() for the filter (played games)
 * @var int $total rows matching the filter
 * @var int $page
 * @var int $pages
 * @var string $listUrl filtered list URL without page
 * @var array<string, int|string> $selected normalized filters
 * @var array<int, array{label: string, games: int}> $seasons
 * @var array<string, array{name: string, games: int}> $competitions
 * @var string|null $seasonLabel
 * @var string|null $competitionName
 * @var string $heading
 * @var string|null $lastSync
 */

use App\Support\Format;

$resultOptions = ['galibiyet' => 'Galibiyet', 'beraberlik' => 'Beraberlik', 'maglubiyet' => 'Mağlubiyet'];
$showAll = ($selected['durum'] ?? null) === 'tumu';
$from = $total > 0 ? ($page - 1) * 50 + 1 : 0;
$to = min($total, $page * 50);
$hasFilter = array_diff_key($selected, ['sayfa' => true]) !== [];
$noData = $seasons === [] && $competitions === []; // no games stored yet (before the first sync)
?>
<header class="page-header">
  <div class="container">
    <span class="eyebrow">Maç Arşivi</span>
    <h1><?= e($heading) ?></h1>
    <p class="lead">Transfermarkt kayıtlarına göre Cristiano Ronaldo’nun kulüp ve milli takım maçları: tarih, rakip, skor, oynadığı dakika, gol ve asistler.</p>
  </div>
</header>

<section class="section-sm" aria-labelledby="filtre-baslik">
  <div class="container stack">
    <h2 class="visually-hidden" id="filtre-baslik">Maçları filtrele</h2>
    <form class="form" method="get" action="<?= e(url('/istatistikler/maclar')) ?>">
      <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(150px,1fr))">
        <div class="field">
          <label for="f-sezon">Sezon</label>
          <select class="select" id="f-sezon" name="sezon">
            <option value="">Tüm sezonlar</option>
            <?php foreach ($seasons as $id => $s): ?>
            <option value="<?= e((string) $id) ?>"<?= ($selected['sezon'] ?? null) === $id ? ' selected' : '' ?>><?= e($s['label']) ?> (<?= e((string) $s['games']) ?> maç)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="f-turnuva">Turnuva</label>
          <select class="select" id="f-turnuva" name="turnuva">
            <option value="">Tüm turnuvalar</option>
            <?php foreach ($competitions as $id => $c): ?>
            <option value="<?= e($id) ?>"<?= ($selected['turnuva'] ?? null) === $id ? ' selected' : '' ?>><?= e($c['name']) ?> (<?= e((string) $c['games']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="f-sonuc">Sonuç</label>
          <select class="select" id="f-sonuc" name="sonuc">
            <option value="">Tüm sonuçlar</option>
            <?php foreach ($resultOptions as $value => $label): ?>
            <option value="<?= e($value) ?>"<?= ($selected['sonuc'] ?? null) === $value ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="f-durum">Kapsam</label>
          <select class="select" id="f-durum" name="durum">
            <option value="">Oynadığı maçlar</option>
            <option value="tumu"<?= $showAll ? ' selected' : '' ?>>Tüm kadro kayıtları (oynamadıkları dahil)</option>
          </select>
        </div>
      </div>
      <div class="form-actions">
        <button class="btn btn-gold" type="submit">Filtrele</button>
        <?php if ($hasFilter): ?><a class="btn btn-ghost" href="<?= e(url('/istatistikler/maclar')) ?>">Filtreleri temizle</a><?php endif; ?>
      </div>
    </form>
    <p class="form-help">Boş bırakılan alanlar dikkate alınmaz; sayfa yenilendiğinde adres sadeleştirilir.</p>
    <?php if (!$noData): ?>
    <div class="stat-tiles">
      <?= partial('partials/stat-tile', ['value' => $totals['appearances'], 'label' => 'Maç', 'sub' => Format::number($totals['minutes']) . ' dakika']) ?>
      <?= partial('partials/stat-tile', ['value' => $totals['goals'], 'label' => 'Gol', 'sub' => $totals['appearances'] > 0 ? 'Maç başına ' . Format::number($totals['goals'] / $totals['appearances'], 2) : null]) ?>
      <?= partial('partials/stat-tile', ['value' => $totals['assists'], 'label' => 'Asist']) ?>
      <?= partial('partials/stat-tile', ['value' => $totals['wins'], 'label' => 'Galibiyet', 'sub' => Format::number($totals['draws']) . ' beraberlik · ' . Format::number($totals['losses']) . ' mağlubiyet']) ?>
    </div>
    <?php endif; ?>
  </div>
</section>

<section class="section-sm" aria-labelledby="liste-baslik">
  <div class="container">
    <div class="section-header">
      <h2 class="section-title" id="liste-baslik">Maç Listesi</h2>
      <p class="meta mb-0"><?php if ($total > 0): ?><span><?= e(Format::number($from)) ?>–<?= e(Format::number($to)) ?> / <?= e(Format::number($total)) ?> kayıt</span><?php endif; ?><span>En yeni maç üstte</span><?php if ($lastSync): ?><span>Son güncelleme: <?= e(Format::date($lastSync, 'j F Y H:i')) ?></span><?php endif; ?></p>
    </div>
    <?php if ($games === [] && $noData): ?>
    <?= partial('partials/stats-empty', ['text' => 'Ronaldo’nun maç maç kayıtları Transfermarkt ile ilk senkronizasyondan sonra burada listelenecek.']) ?>
    <?php elseif ($games === []): ?>
    <div class="empty-state">
      <h3>Bu filtrelerle eşleşen maç bulunamadı</h3>
      <p><a class="btn btn-outline" href="<?= e(url('/istatistikler/maclar')) ?>">Tüm maçları göster</a></p>
    </div>
    <?php else: ?>
    <?= partial('partials/stats-games-table', ['games' => $games, 'showStatus' => $showAll, 'showSeason' => !isset($selected['sezon']), 'caption' => 'Kaynak: Transfermarkt · G: galibiyet, B: beraberlik, M: mağlubiyet · Skor Ronaldo’nun takımına göredir.']) ?>
    <?= partial('partials/pagination', ['current' => $page, 'total' => $pages, 'baseUrl' => url($listUrl)]) ?>
    <?php endif; ?>
    <nav class="cluster" style="margin-top:28px" aria-label="İlgili sayfalar">
      <a class="btn btn-outline" href="<?= e(url('/istatistikler')) ?>">İstatistik özeti</a>
      <a class="btn btn-ghost" href="<?= e(url('/kariyer')) ?>">Kariyer</a>
      <a class="btn btn-ghost" href="<?= e(url('/takimlar')) ?>">Oynadığı takımlar</a>
    </nav>
  </div>
</section>
