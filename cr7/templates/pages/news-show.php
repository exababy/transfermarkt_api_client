<?php
/**
 * /haber/{slug} — one news post.
 *
 * @var array<string, mixed> $post NewsRepository::findBySlug() row (body is sanitized HTML)
 * @var list<array{name: string, slug: string, type: string}> $tags
 * @var list<array{name: string, url: string}> $clubLinks club pages for club tags
 * @var list<array{name: string, url: string}> $sources
 * @var list<array<string, mixed>> $related
 * @var list<array<string, mixed>> $latest
 * @var int $readingMinutes
 */

use App\Support\Format;

$published = (string) $post['published_at'];
$updated = (string) ($post['updated_at'] ?? $published);
$wasUpdated = strtotime($updated . ' UTC') - strtotime($published . ' UTC') > 3600;
?>
<article class="section news-article" aria-labelledby="post-title">
  <div class="container layout-sidebar">
    <div class="news-main">
      <header>
        <span class="eyebrow">Haber</span>
        <h1 id="post-title"><?= e($post['title']) ?></h1>
        <div class="meta">
          <time datetime="<?= e(Format::iso($published)) ?>"><?= e(fmt_date($published, 'j F Y, H:i')) ?></time>
          <?php if ($wasUpdated): ?><span>Güncelleme: <time datetime="<?= e(Format::iso($updated)) ?>"><?= e(fmt_date($updated, 'j F Y, H:i')) ?></time></span><?php endif; ?>
          <span><?= (int) $readingMinutes ?> dk okuma</span>
          <span><?= e($post['source_count'] > 1 ? $post['source_count'] . ' kaynaktan derlendi' : 'Derleme haber') ?></span>
        </div>
      </header>

      <?php if (!empty($post['image_url'])): ?>
      <figure class="news-figure">
        <img src="<?= e($post['image_url']) ?>" alt="<?= e($post['title']) ?>" width="1200" height="630" fetchpriority="high" decoding="async">
      </figure>
      <?php endif; ?>

      <div class="prose">
        <?= $post['body'] /* sanitized with Html::sanitize before storage */ ?>
      </div>

      <?php if ($tags !== []): ?>
      <nav class="news-tags" aria-label="Etiketler">
        <ul class="tags list-unstyled">
          <?php foreach ($tags as $tag): ?>
          <li><a class="tag" href="<?= e(url('/haberler/etiket/' . $tag['slug'])) ?>" rel="tag"><?= e($tag['name']) ?></a></li>
          <?php endforeach; ?>
        </ul>
      </nav>
      <?php endif; ?>

      <?php if ($sources !== []): ?>
      <aside class="notice source-box" aria-labelledby="source-box-title">
        <strong class="notice-title" id="source-box-title">Kaynak ve derleme notu</strong>
        <p class="mb-0">Bu haber, aşağıdaki <?= count($sources) > 1 ? count($sources) . ' kaynaktaki' : 'kaynaktaki' ?> bilgiler derlenerek CR7.com.tr tarafından otomatik olarak yeniden yazılmıştır. Ayrıntılar ve güncel bilgi için orijinal habere göz atabilirsiniz.</p>
        <ul>
          <?php foreach ($sources as $source): ?>
          <li><a href="<?= e($source['url']) ?>" rel="nofollow noopener" target="_blank"><?= e($source['name']) ?></a></li>
          <?php endforeach; ?>
        </ul>
      </aside>
      <?php endif; ?>
    </div>

    <aside class="news-aside stack" aria-label="İlgili bağlantılar">
      <section class="side-box" aria-labelledby="side-links-title">
        <h2 id="side-links-title">Cristiano Ronaldo</h2>
        <ul class="list-unstyled side-list">
          <li><a href="<?= e(url('/istatistikler')) ?>">Güncel istatistikleri: goller, asistler, maçlar</a></li>
          <?php foreach ($clubLinks as $club): ?>
          <li><a href="<?= e(url($club['url'])) ?>"><?= e($club['name']) ?> kariyeri ve rakamları</a></li>
          <?php endforeach; ?>
          <li><a href="<?= e(url('/kariyer')) ?>">Kariyeri: Sporting’den Al-Nassr’a</a></li>
          <li><a href="<?= e(url('/kupalar-ve-oduller')) ?>">Kupaları ve ödülleri</a></li>
        </ul>
      </section>
      <?php if ($latest !== []): ?>
      <section class="side-box" aria-labelledby="side-latest-title">
        <h2 id="side-latest-title">Son haberler</h2>
        <ul class="list-unstyled side-list">
          <?php foreach (array_slice($latest, 0, 4) as $item): ?>
          <li>
            <a href="<?= e(url($item['url'])) ?>"><?= e($item['title']) ?></a>
            <div class="meta"><time datetime="<?= e(Format::iso($item['published_at'])) ?>"><?= e(fmt_relative($item['published_at'])) ?></time></div>
          </li>
          <?php endforeach; ?>
        </ul>
      </section>
      <?php endif; ?>
    </aside>
  </div>
</article>

<?php if ($related !== []): ?>
<section class="section section-alt" aria-labelledby="related-title">
  <div class="container">
    <div class="section-header">
      <h2 id="related-title" class="section-title">Benzer haberler</h2>
      <a class="link-more" href="<?= e(url('/haberler')) ?>">Tüm haberler</a>
    </div>
    <div class="grid grid-3">
      <?php foreach ($related as $item): ?>
      <?= partial('partials/news-card', ['post' => $item, 'headingTag' => 'h3']) ?>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>
