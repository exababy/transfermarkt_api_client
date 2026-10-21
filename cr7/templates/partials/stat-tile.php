<?php
/**
 * Single statistic tile; wrap several in <div class="stat-tiles">.
 *
 * @var int|float|string $value numbers are formatted Turkish-style (1.234)
 * @var string $label e.g. 'Gol'
 * @var string|null $sub optional small line under the label
 * @var int|null $decimals decimals for float values
 */

$display = is_int($value ?? null) || is_float($value ?? null)
    ? fmt_number($value, (int) ($decimals ?? 0))
    : (string) ($value ?? '-');
?>
<div class="stat-tile">
  <span class="stat-value"><?= e($display) ?></span>
  <span class="stat-label"><?= e($label ?? '') ?></span>
  <?php if (!empty($sub)): ?><span class="stat-sub"><?= e($sub) ?></span><?php endif; ?>
</div>
