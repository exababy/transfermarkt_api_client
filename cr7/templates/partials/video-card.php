<?php
/**
 * Video card (shape of VideoRepository::latest() rows); links to the video page.
 *
 * @var array{title: string, youtube_id: string, url?: string, slug?: string, thumbnail_url?: ?string, published_at?: ?string, channel_title?: ?string, duration_seconds?: ?int} $video
 * @var string|null $headingTag h2|h3|h4 (default h3)
 */

$headingTag = in_array($headingTag ?? 'h3', ['h2', 'h3', 'h4'], true) ? ($headingTag ?? 'h3') : 'h3';
$href = url((string) ($video['url'] ?? ('/video/' . ($video['slug'] ?? ''))));
$thumb = !empty($video['thumbnail_url']) ? $video['thumbnail_url'] : 'https://i.ytimg.com/vi/' . rawurlencode((string) ($video['youtube_id'] ?? '')) . '/hqdefault.jpg';
$seconds = (int) ($video['duration_seconds'] ?? 0);
$duration = $seconds > 0
    ? ($seconds >= 3600 ? sprintf('%d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60) : sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60))
    : '';
?>
<article class="card video-card">
  <a class="card-media" href="<?= e($href) ?>" tabindex="-1" aria-hidden="true">
    <img src="<?= e($thumb) ?>" alt="<?= e($video['title']) ?>" width="480" height="270" loading="lazy" decoding="async">
    <span class="video-card-play"></span>
    <?php if ($duration !== ''): ?><span class="duration"><?= e($duration) ?></span><?php endif; ?>
  </a>
  <div class="card-body">
    <<?= $headingTag ?> class="card-title"><a href="<?= e($href) ?>"><?= e($video['title']) ?></a></<?= $headingTag ?>>
    <div class="meta">
      <?php if (!empty($video['channel_title'])): ?><span><?= e($video['channel_title']) ?></span><?php endif; ?>
      <?php if (!empty($video['published_at'])): ?><time datetime="<?= e(\App\Support\Format::iso($video['published_at'])) ?>"><?= e(fmt_date($video['published_at'])) ?></time><?php endif; ?>
    </div>
  </div>
</article>
