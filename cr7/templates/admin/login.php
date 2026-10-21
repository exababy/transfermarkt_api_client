<?php
/**
 * Admin login form (bare admin layout, noindex).
 *
 * @var string $username previously entered username
 * @var string|null $error generic error message
 */
?>
<section class="admin-card login-card" aria-labelledby="login-title">
  <h2 id="login-title" class="login-title">Yönetici Girişi</h2>
  <p class="muted">CR7.com.tr yönetim paneline erişmek için giriş yapın.</p>
  <?php if (!empty($error)): ?>
  <div class="notice notice-error" role="alert" id="login-error"><?= e($error) ?></div>
  <?php endif; ?>
  <form class="form" method="post" action="<?= e(url('/admin/giris')) ?>" novalidate>
    <?= csrf_field() ?>
    <div class="field">
      <label for="username">Kullanıcı adı</label>
      <input class="input" id="username" name="username" type="text" value="<?= e($username) ?>" autocomplete="username" autocapitalize="none" spellcheck="false" maxlength="50" required<?= $username === '' ? ' autofocus' : '' ?><?= !empty($error) ? ' aria-describedby="login-error"' : '' ?>>
    </div>
    <div class="field">
      <label for="password">Şifre</label>
      <div class="password-field">
        <input class="input" id="password" name="password" type="password" autocomplete="current-password" maxlength="200" required<?= $username !== '' ? ' autofocus' : '' ?>>
        <button class="btn btn-sm btn-ghost" type="button" data-toggle-password="#password" aria-controls="password">Göster</button>
      </div>
    </div>
    <button class="btn btn-gold btn-block btn-lg" type="submit">Giriş yap</button>
  </form>
  <p class="login-foot"><a href="<?= e(url('/')) ?>">← Siteye dön</a></p>
</section>
