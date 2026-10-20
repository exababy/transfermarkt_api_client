<?php
/**
 * News card (shape of NewsRepository::latest() rows).
 *
 * @var array{title: string, url?: string, slug?: string, summary?: string, image_url?: ?string, published_at?: ?string, source_name?: ?string} $post
 * @var string|null $headingTag h2|h3|h4 (default h3)
 * @var bool|null $priority true for the first card of a listing (the LCP image): eager + fetchpriority=high
 * @var bool|null $lazy false for the other above-the-fold cards (first desktop row); default true
 */

$headingTag = in_array($headingTag ?? 'h3', ['h2', 'h3', 'h4'], true) ? ($headingTag ?? 'h3') : 'h3';
$href = url((string) ($post['url'] ?? ('/haber/' . ($post['slug'] ?? ''))));
$image = $post['image_url'] ?? null;
$published = $post['published_at'] ?? null;
$priority = !empty($priority);
$lazy = !$priority && (!isset($lazy) || (bool) $lazy);
// Generated 1200 px OG cards get a 640 px JPEG for the ~360 px card slot (og:image keeps the PNG).
$srcset = '';
if (!empty($image) && preg_match('#(/og/[a-z0-9-]+\.png)$#', (string) parse_url((string) $image, PHP_URL_PATH), $m) === 1
    && ($small = \App\Support\OgImage::variant($m[1])) !== null) {
    $srcset = url($small) . ' ' . \App\Support\OgImage::CARD_WIDTH . 'w, ' . $image . ' 1200w';
}
?>
<article class="card news-card">
  <a class="card-media<?= empty($image) ? ' is-placeholder' : '' ?>" href="<?= e($href) ?>" tabindex="-1" aria-hidden="true">
    <?php if (!empty($image)): ?>
    <img src="<?= e($image) ?>"<?php if ($srcset !== ''): ?> srcset="<?= e($srcset) ?>" sizes="(min-width: 1240px) 380px, (min-width: 960px) 31vw, (min-width: 640px) 48vw, calc(100vw - 32px)"<?php endif; ?> alt="<?= e($post['title']) ?>" width="1200" height="630" <?= $priority ? 'fetchpriority="high" ' : '' ?><?= $lazy ? 'loading="lazy" ' : '' ?>decoding="async">
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
