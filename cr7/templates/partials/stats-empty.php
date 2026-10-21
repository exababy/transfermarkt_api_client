<?php
/**
 * Shown instead of stat tiles and tables before the first Transfermarkt sync: a fresh install has no
 * games/stats rows, and "0 Maç / 0 Gol" tiles would read as real numbers.
 *
 * @var string|null $text optional sentence under the title
 */
?>
<div class="empty-state stats-empty">
  <strong class="notice-title">Veriler henüz senkronize edilmedi</strong>
  <p class="mb-0"><?= e($text ?? 'Maç, gol ve asist istatistikleri Transfermarkt ile ilk senkronizasyondan sonra burada görünecek.') ?></p>
</div>
