<?php
/**
 * Privacy-friendly YouTube facade: thumbnail + play button; app.js swaps in a
 * youtube-nocookie.com iframe (autoplay) on click. No YouTube request before the click
 * except the thumbnail image.
 *
 * @var string $youtubeId
 * @var string $title video title (used for alt text and iframe title)
 * @var string|null $thumbnail custom thumbnail URL (default i.ytimg.com hqdefault)
 */

$youtubeId = preg_match('/^[A-Za-z0-9_-]{6,20}$/', (string) ($youtubeId ?? '')) === 1 ? (string) $youtubeId : '';
if ($youtubeId === '') {
    return;
}
$thumbnail = !empty($thumbnail) ? $thumbnail : 'https://i.ytimg.com/vi/' . $youtubeId . '/hqdefault.jpg';
?>
<div class="video-embed" data-youtube-id="<?= e($youtubeId) ?>" data-title="<?= e($title ?? '') ?>">
  <img src="<?= e($thumbnail) ?>" alt="<?= e($title ?? 'Video') ?>" width="480" height="360" loading="lazy" decoding="async">
  <button type="button" class="video-play" aria-label="Videoyu oynat: <?= e($title ?? '') ?>">
    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M8 5.5v13l11-6.5z"/></svg>
  </button>
  <noscript><a class="video-fallback" href="https://www.youtube.com/watch?v=<?= e($youtubeId) ?>" rel="noopener" target="_blank">Videoyu YouTube’da izle</a></noscript>
</div>
