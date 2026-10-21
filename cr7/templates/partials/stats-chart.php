<?php
/**
 * Chart figure: heading, responsive SVG (App\Support\Svg output), caption and a table-view twin.
 *
 * @var string $id figure id (anchor)
 * @var string $title visible chart title (h3)
 * @var string $svg trusted SVG markup from App\Support\Svg
 * @var string|null $caption
 * @var list<string> $headers table-view column headers
 * @var list<list<string>> $rows table-view rows (plain strings, escaped here)
 * @var list<int>|null $numeric indexes of numeric columns (right-aligned)
 */

$numeric = $numeric ?? [];
?>
<figure class="chart" id="<?= e($id) ?>" aria-labelledby="<?= e($id) ?>-title">
  <h3 class="card-title" id="<?= e($id) ?>-title"><?= e($title) ?></h3>
  <?php if ($svg !== ''): ?>
  <?= $svg ?>
  <?php else: ?>
  <p class="text-muted">Grafik için henüz veri yok.</p>
  <?php endif; ?>
  <?php if (!empty($caption)): ?><figcaption class="chart-caption"><?= e($caption) ?></figcaption><?php endif; ?>
  <?php if (!empty($rows)): ?>
  <details class="chart-table">
    <summary class="btn btn-sm">Tablo olarak göster</summary>
    <div class="table-wrap">
      <table class="table table-compact">
        <thead><tr><?php foreach ($headers as $i => $h): ?><th scope="col"<?= in_array($i, $numeric, true) ? ' class="num"' : '' ?>><?= e($h) ?></th><?php endforeach; ?></tr></thead>
        <tbody>
          <?php foreach ($rows as $row): ?>
          <tr><?php foreach ($row as $i => $cell): ?><td<?= in_array($i, $numeric, true) ? ' class="num"' : '' ?>><?= e($cell) ?></td><?php endforeach; ?></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </details>
  <?php endif; ?>
</figure>
