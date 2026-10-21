<?php
/**
 * Accessible pagination. Page 1 links to $baseUrl, others to ?sayfa=N (see page_url()).
 *
 * @var int $current current page (1-based)
 * @var int $total total number of pages
 * @var string $baseUrl list URL (may contain other query params)
 * @var string|null $param query parameter name (default 'sayfa')
 */

$current = max(1, (int) ($current ?? 1));
$total = max(1, (int) ($total ?? 1));
$baseUrl = (string) ($baseUrl ?? '');
$param = (string) ($param ?? 'sayfa');
if ($total <= 1) {
    return;
}
$current = min($current, $total);
// First, last, current ±1, and the first/last four pages when near an edge.
$pages = array_values(array_filter(
    range(1, $total),
    static fn (int $p): bool => $p === 1 || $p === $total || abs($p - $current) <= 1
        || ($current <= 3 && $p <= 4) || ($current >= $total - 2 && $p >= $total - 3)
));
?>
<nav class="pagination" aria-label="Sayfalar">
  <ul>
    <?php if ($current > 1): ?>
    <li><a href="<?= e(page_url($baseUrl, $current - 1, $param)) ?>" rel="prev" aria-label="Önceki sayfa">‹ <span class="pagination-label">Önceki</span></a></li>
    <?php endif; ?>
    <?php $previous = 0; foreach ($pages as $p): ?>
    <?php if ($p - $previous > 1): ?><li><span class="gap" aria-hidden="true">…</span></li><?php endif; ?>
    <li><?php if ($p === $current): ?><span aria-current="page"><?= $p ?></span><?php else: ?><a href="<?= e(page_url($baseUrl, $p, $param)) ?>" aria-label="Sayfa <?= $p ?>"><?= $p ?></a><?php endif; ?></li>
    <?php $previous = $p; endforeach; ?>
    <?php if ($current < $total): ?>
    <li><a href="<?= e(page_url($baseUrl, $current + 1, $param)) ?>" rel="next" aria-label="Sonraki sayfa"><span class="pagination-label">Sonraki</span> ›</a></li>
    <?php endif; ?>
  </ul>
</nav>
