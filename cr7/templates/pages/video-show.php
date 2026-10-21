<?php
/**
 * /video/{slug} — server-rendered player (indexable), details, description, related videos.
 *
 * @var array<string, mixed> $video VideoRepository::findBySlug() row
 * @var list<array<string, mixed>> $related
 * @var string $summary Turkish context paragraph
 * @var string $categoryUrl
 * @var array<string, int> $counts
 * @var array<string, string> $categories
 * @var string $descriptionHtml safe HTML (VideoText::descriptionHtml)
 * @var string $descriptionMoreHtml safe HTML, '' when the description is short
 */

use App\Services\Video\VideoText;
use App\Support\Format;

$watchUrl = VideoText::watchUrl($video['youtube_id']);
$duration = VideoText::humanDuration($video['duration_seconds']);
?>
<div class="container video-page">
  <div class="layout-sidebar video-layout">
    <article class="video-article" aria-labelledby="video-title">
      <?= partial('partials/video-embed', ['youtubeId' => $video['youtube_id'], 'title' => $video['title'], 'thumbnail' => $video['thumbnail_url'], 'facade' => false]) ?>

      <header class="video-head">
        <a class="badge" href="<?= e(url($categoryUrl)) ?>"><?= e($video['category_name']) ?></a>
        <h1 id="video-title"><?= e($video['title']) ?></h1>
        <div class="meta">
          <?php if (!empty($video['channel_title'])): ?><span><?= e($video['channel_title']) ?></span><?php endif; ?>
          <time datetime="<?= e(Format::iso($video['date'])) ?>"><?= e(fmt_date($video['date'])) ?></time>
          <?php if ($duration !== ''): ?><span>Süre: <?= e($duration) ?></span><?php endif; ?>
          <?php if ($video['view_count'] !== null): ?><span><?= e(fmt_number($video['view_count'])) ?> görüntülenme</span><?php endif; ?>
        </div>
      </header>

      <p class="video-summary"><?= e($summary) ?></p>

      <div class="cluster video-actions">
        <a class="btn btn-gold" href="<?= e($watchUrl) ?>" target="_blank" rel="noopener">YouTube’da izle</a>
        <a class="btn btn-outline" href="<?= e(url($categoryUrl)) ?>">Tüm <?= e($video['category_name']) ?> videoları</a>
      </div>

      <?php if ($descriptionHtml !== ''): ?>
      <section class="video-description" aria-labelledby="video-desc-title">
        <h2 id="video-desc-title">Video açıklaması</h2>
        <div class="prose"><p><?= $descriptionHtml ?></p></div>
        <?php if ($descriptionMoreHtml !== ''): ?>
        <details>
          <summary>Devamını göster</summary>
          <div class="prose"><p><?= $descriptionMoreHtml ?></p></div>
        </details>
        <?php endif; ?>
      </section>
      <?php endif; ?>
    </article>

    <aside class="video-aside" aria-labelledby="related-title">
      <h2 id="related-title" class="related-title">Benzer Videolar</h2>
      <?php if ($related === []): ?>
      <p class="text-muted">Şimdilik başka video yok.</p>
      <?php else: ?>
      <ul class="list-unstyled related-list">
        <?php foreach ($related as $item): ?>
        <?php $clock = VideoText::clock($item['duration_seconds']); ?>
        <li class="related-item">
          <span class="related-thumb">
            <img src="<?= e(VideoText::thumbnail($item['youtube_id'], 'mqdefault')) ?>" alt="<?= e($item['title']) ?>" width="320" height="180" loading="lazy" decoding="async">
            <?php if ($clock !== ''): ?><span class="duration"><?= e($clock) ?></span><?php endif; ?>
          </span>
          <div>
            <a class="related-link" href="<?= e(url($item['url'])) ?>"><?= e($item['title']) ?></a>
            <div class="meta">
              <?php if (!empty($item['channel_title'])): ?><span><?= e($item['channel_title']) ?></span><?php endif; ?>
              <time datetime="<?= e(Format::iso($item['date'])) ?>"><?= e(fmt_date($item['date'], 'M Y')) ?></time>
            </div>
          </div>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
      <p class="video-more"><a class="link-more" href="<?= e(url('/videolar')) ?>">Tüm videolar</a></p>
    </aside>
  </div>

  <?php if (count($counts) > 1): ?>
  <nav class="video-categories" aria-label="Video kategorileri">
    <ul class="tags list-unstyled">
      <?php foreach ($categories as $key => $label): ?>
      <?php if (!isset($counts[$key])) { continue; } ?>
      <li><a class="tag<?= $key === $video['category'] ? ' active' : '' ?>" href="<?= e(url('/videolar?kategori=' . rawurlencode($key))) ?>"><?= e($label) ?></a></li>
      <?php endforeach; ?>
    </ul>
  </nav>
  <?php endif; ?>
</div>
