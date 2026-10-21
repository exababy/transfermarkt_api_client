<?php
/**
 * Generic error page for 4xx statuses other than 404 (403, 405, 419, 422, 429 …).
 *
 * @var int $status
 * @var string $title
 * @var string $message optional detail (plain text)
 */
?>
<section class="section error-page">
  <div class="container container-narrow">
    <p class="error-code" aria-hidden="true"><?= e($status ?? 400) ?></p>
    <h1><?= e($title ?? 'Bir sorun oluştu') ?></h1>
    <p class="lead"><?= e(!empty($message) ? $message : 'İsteğiniz işlenemedi. Lütfen sayfayı yenileyip tekrar deneyin.') ?></p>
    <ul class="error-links">
      <li><a class="tag" href="<?= e(url('/')) ?>">Ana Sayfa</a></li>
      <li><a class="tag" href="<?= e(url('/iletisim')) ?>">İletişim</a></li>
    </ul>
  </div>
</section>
