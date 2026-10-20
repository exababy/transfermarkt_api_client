<?php
/**
 * /haberler/etiket/{slug} — posts carrying one tag.
 *
 * @var string $name tag name
 * @var string $slug
 * @var list<array<string, mixed>> $posts
 * @var int $page
 * @var int $pages
 * @var int $total
 * @var string $baseUrl
 * @var string|null $clubPage /takimlar/{slug} when the tag is a club he played for
 */
?>
<header class="page-header">
  <div class="container">
    <span class="eyebrow">Etiket</span>
    <h1><?= e($name) ?> Haberleri</h1>
    <p class="lead">Cristiano Ronaldo ve <?= e($name) ?> ile ilgili <?= (int) $total ?> haber. En yeni gelişmeler en üstte.</p>
    <?php if ($clubPage !== null): ?>
    <p class="mt-0"><a class="link-more" href="<?= e(url($clubPage)) ?>">Ronaldo’nun <?= e($name) ?> kariyeri ve istatistikleri</a></p>
    <?php endif; ?>
  </div>
</header>

<section class="section" aria-labelledby="tag-list-title">
  <div class="container">
    <h2 id="tag-list-title" class="visually-hidden"><?= e($name) ?> haberleri listesi</h2>
    <div class="grid grid-3">
      <?php foreach ($posts as $post): ?>
      <?= partial('partials/news-card', ['post' => $post, 'headingTag' => 'h3']) ?>
      <?php endforeach; ?>
    </div>
    <?= partial('partials/pagination', ['current' => $page, 'total' => $pages, 'baseUrl' => $baseUrl]) ?>
    <p class="news-tags"><a class="link-more" href="<?= e(url('/haberler')) ?>">Tüm Cristiano Ronaldo haberleri</a></p>
  </div>
</section>
