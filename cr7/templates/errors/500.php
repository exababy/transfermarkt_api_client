<?php
/**
 * 5xx page (noindex). In debug mode ErrorController passes the exception as $error.
 *
 * @var int $status
 * @var \Throwable|null $error
 */
?>
<section class="section error-page">
  <div class="container container-narrow">
    <p class="error-code" aria-hidden="true"><?= e($status ?? 500) ?></p>
    <h1>Bir şeyler ters gitti</h1>
    <p class="lead">Sunucuda beklenmeyen bir hata oluştu. Sorunu kayda aldık; lütfen birkaç dakika sonra tekrar deneyin.</p>
    <ul class="error-links">
      <li><a class="tag" href="<?= e(url('/')) ?>">Ana Sayfa</a></li>
      <li><a class="tag" href="<?= e(url('/haberler')) ?>">Haberler</a></li>
      <li><a class="tag" href="<?= e(url('/iletisim')) ?>">İletişim</a></li>
    </ul>
    <?php if (isset($error) && $error instanceof \Throwable): ?>
    <div class="debug-box">
      <p><strong><?= e(get_class($error)) ?>:</strong> <?= e($error->getMessage()) ?></p>
      <p><?= e($error->getFile()) ?>:<?= e($error->getLine()) ?></p>
      <pre><?= e($error->getTraceAsString()) ?></pre>
    </div>
    <?php endif; ?>
  </div>
</section>
