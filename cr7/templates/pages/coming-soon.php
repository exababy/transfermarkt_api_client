<?php
/**
 * "Bu bölüm hazırlanıyor" placeholder used by stub controllers (public and admin).
 *
 * @var string $title
 * @var bool|null $admin true when rendered inside layouts/admin (title is the layout's h1)
 * @var bool|null $bare admin layout without sidebar (login)
 */

$admin = (bool) ($admin ?? false);
?>
<section class="<?= $admin ? 'admin-section' : 'section' ?>">
  <div<?= $admin ? '' : ' class="container"' ?>>
    <div class="empty-state coming-soon">
      <span class="empty-state-icon" aria-hidden="true">7</span>
      <?php if ($admin): ?>
      <h2><?= e($title) ?></h2>
      <?php else: ?>
      <span class="eyebrow">CR7.com.tr</span>
      <h1><?= e($title) ?></h1>
      <?php endif; ?>
      <p class="lead">Bu bölüm hazırlanıyor. Çok yakında burada olacak.</p>
      <div class="cluster center">
        <?php if ($admin && !empty($bare)): ?>
        <a class="btn btn-gold" href="<?= e(url('/')) ?>">Siteye dön</a>
        <?php elseif ($admin): ?>
        <a class="btn btn-primary" href="<?= e(url('/admin')) ?>">Panele dön</a>
        <?php else: ?>
        <a class="btn btn-gold" href="<?= e(url('/')) ?>">Ana sayfaya dön</a>
        <a class="btn btn-outline" href="<?= e(url('/haberler')) ?>">Son haberler</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>
