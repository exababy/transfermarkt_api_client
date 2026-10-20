<?php
/**
 * News card (shape of NewsRepository::latest() rows).
 *
 * @var array{title: string, url?: string, slug?: string, summary?: string, image_url?: ?string, published_at?: ?string, source_name?: ?string} $post
 * @var string|null $headingTag h2|h3|h4 (default h3)
 */

$headingTag = in_array($headingTag ?? 'h3', ['h2', 'h3', 'h4'], true) ? ($headingTag ?? 'h3') : 'h3';
$href = url((string) ($post['url'] ?? ('/haber/' . ($post['slug'] ?? ''))));
$image = $post['image_url'] ?? null;
$published = $post['published_at'] ?? null;
?>
<article class="card news-card">
  <a class="card-media<?= empty($image) ? ' is-placeholder' : '' ?>" href="<?= e($href) ?>" tabindex="-1" aria-hidden="true">
    <?php if (!empty($image)): ?>
    <img src="<?= e($image) ?>" alt="<?= e($post['title']) ?>" width="1200" height="630" loading="lazy" decoding="async">
    <?php endif; ?>
  </a>
  <div class="card-body">
    <div class="meta">
      <?php if (!empty($published)): ?><time datetime="<?= e(\App\Support\Format::iso($published)) ?>"><?= e(fmt_date($published, 'j F Y')) ?></time><?php endif; ?>
      <?php if (!empty($post['source_name'])): ?><span><?= e($post['source_name']) ?></span><?php endif; ?>
    </div>
    <<?= $headingTag ?> class="card-title"><a href="<?= e($href) ?>"><?= e($post['title']) ?></a></<?= $headingTag ?>>
    <?php if (!empty($post['summary'])): ?>
    <p class="card-text"><?= e(\App\Support\Str::limit((string) $post['summary'], 160)) ?></p>
    <?php endif; ?>
  </div>
</article>
