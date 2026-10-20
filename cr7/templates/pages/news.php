<?php
/**
 * /haberler — paginated news list.
 *
 * @var list<array<string, mixed>> $posts NewsRepository::latest() rows
 * @var int $page
 * @var int $pages
 * @var int $total
 * @var string $baseUrl
 * @var list<array{slug: string, name: string, count: int}> $popularTags
 */
?>
<header class="page-header">
  <div class="container">
    <span class="eyebrow">Haberler</span>
    <h1>Cristiano Ronaldo Haberleri<?= $page > 1 ? ' <span class="text-muted">· Sayfa ' . (int) $page . '</span>' : '' ?></h1>
    <p class="lead">CR7’nin maçları, golleri, rekorları, transfer gelişmeleri ve açıklamaları: Türk ve dünya basınından derlenen Cristiano Ronaldo haberleri her gün güncelleniyor.</p>
  </div>
</header>

<section class="section" aria-labelledby="news-list-title">
  <div class="container">
    <h2 id="news-list-title" class="visually-hidden">Son haberler</h2>
    <?php if ($posts === []): ?>
    <div class="empty-state">
      <span class="empty-state-icon" aria-hidden="true">7</span>
      <h2>Henüz haber yok</h2>
      <p class="lead">Cristiano Ronaldo ile ilgili haberler çok yakında burada olacak. Bu arada kariyerine ve istatistiklerine göz atabilirsiniz.</p>
      <div class="cluster center">
        <a class="btn btn-gold" href="<?= e(url('/istatistikler')) ?>">İstatistikler</a>
        <a class="btn btn-outline" href="<?= e(url('/kariyer')) ?>">Kariyer</a>
      </div>
    </div>
    <?php else: ?>
    <div class="grid grid-3">
      <?php foreach ($posts as $i => $post): ?>
      <?= partial('partials/news-card', ['post' => $post, 'headingTag' => 'h3', 'priority' => $i === 0, 'lazy' => $i >= 3]) ?>
      <?php endforeach; ?>
    </div>
    <?= partial('partials/pagination', ['current' => $page, 'total' => $pages, 'baseUrl' => $baseUrl]) ?>
    <?php endif; ?>

    <?php if ($popularTags !== []): ?>
    <nav class="news-tags" aria-labelledby="popular-tags-title">
      <h2 id="popular-tags-title" class="section-title">Öne çıkan konular</h2>
      <ul class="tags list-unstyled">
        <?php foreach ($popularTags as $tag): ?>
        <li><a class="tag" href="<?= e(url('/haberler/etiket/' . $tag['slug'])) ?>"><?= e($tag['name']) ?></a></li>
        <?php endforeach; ?>
      </ul>
    </nav>
    <?php endif; ?>
  </div>
</section>
