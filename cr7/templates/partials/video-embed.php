<?php
/**
 * Privacy-friendly YouTube facade: thumbnail + play button; app.js swaps in a
 * youtube-nocookie.com iframe (autoplay) on click. No YouTube request before the click
 * except the thumbnail image.
 *
 * @var string $youtubeId
 * @var string $title video title (used for alt text and iframe title)
 * @var string|null $thumbnail custom thumbnail URL (default i.ytimg.com hqdefault)
 * @var bool|null $lazy lazy-load the thumbnail (default true; pass false for the above-the-fold player on detail pages)
 * @var bool|null $facade false renders the youtube-nocookie iframe server-side instead of the
 *                        click-to-load facade. Watch pages need this: Google only indexes a video
 *                        whose player (<iframe>) is in the page without user interaction.
 */

$youtubeId = preg_match('/^[A-Za-z0-9_-]{6,20}$/', (string) ($youtubeId ?? '')) === 1 ? (string) $youtubeId : '';
if ($youtubeId === '') {
    return;
}
$thumbnail = !empty($thumbnail) ? $thumbnail : 'https://i.ytimg.com/vi/' . $youtubeId . '/hqdefault.jpg';
$lazy = !isset($lazy) || (bool) $lazy;
if (isset($facade) && $facade === false): ?>
<div class="video-embed is-playing">
  <iframe src="<?= e(\App\Services\Video\VideoText::embedUrl($youtubeId) . '?rel=0') ?>" title="<?= e($title ?? 'YouTube video') ?>" width="1280" height="720" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" referrerpolicy="strict-origin-when-cross-origin" allowfullscreen></iframe>
</div>
<?php else: ?>
<div class="video-embed" data-youtube-id="<?= e($youtubeId) ?>" data-title="<?= e($title ?? '') ?>">
  <img src="<?= e($thumbnail) ?>" alt="<?= e($title ?? 'Video') ?>" width="480" height="360" <?= $lazy ? 'loading="lazy" decoding="async"' : 'fetchpriority="high" decoding="async"' ?>>
  <button type="button" class="video-play" aria-label="Videoyu oynat: <?= e($title ?? '') ?>">
    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M8 5.5v13l11-6.5z"/></svg>
  </button>
  <noscript><a class="video-fallback" href="https://www.youtube.com/watch?v=<?= e($youtubeId) ?>" rel="noopener" target="_blank">Videoyu YouTube’da izle</a></noscript>
</div>
<?php endif; ?>
