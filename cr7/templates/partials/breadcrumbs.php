<?php
/**
 * Visual breadcrumbs (JSON-LD is printed by the layout from the same Seo data).
 * The main layout renders this automatically from $seo->breadcrumbs.
 *
 * @var list<array{name: string, url: string}>|null $items trail incl. home (default: $seo->breadcrumbTrail())
 */

$items = $items ?? (isset($seo) && $seo instanceof \App\Core\Seo ? $seo->breadcrumbTrail() : []);
if (count($items) < 2) {
    return;
}
$last = array_key_last($items);
?>
<nav class="breadcrumbs" aria-label="Sayfa yolu">
  <ol>
    <?php foreach ($items as $i => $item): ?>
    <li><?php if ($i === $last): ?><span aria-current="page"><?= e($item['name']) ?></span><?php else: ?><a href="<?= e($item['url']) ?>"><?= e($item['name']) ?></a><?php endif; ?></li>
    <?php endforeach; ?>
  </ol>
</nav>
