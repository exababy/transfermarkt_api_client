<?php
/**
 * Password change form.
 *
 * @var array<string, string> $errors field => message
 * @var int $minLength
 * @var array|null $user
 */

$field = static function (string $name, string $label, string $autocomplete, array $errors, string $help = ''): string {
    $error = $errors[$name] ?? null;
    $describedBy = trim(($help !== '' ? $name . '-help ' : '') . ($error !== null ? $name . '-error' : ''));
    ob_start(); ?>
    <div class="field">
      <label for="<?= e($name) ?>"><?= e($label) ?></label>
      <div class="password-field">
        <input class="input" id="<?= e($name) ?>" name="<?= e($name) ?>" type="password" autocomplete="<?= e($autocomplete) ?>" maxlength="200" required<?= $error !== null ? ' aria-invalid="true"' : '' ?><?= $describedBy !== '' ? ' aria-describedby="' . e($describedBy) . '"' : '' ?>>
        <button class="btn btn-sm btn-ghost" type="button" data-toggle-password="#<?= e($name) ?>" aria-controls="<?= e($name) ?>">Göster</button>
      </div>
      <?php if ($help !== ''): ?><p class="form-help" id="<?= e($name) ?>-help"><?= e($help) ?></p><?php endif; ?>
      <?php if ($error !== null): ?><p class="form-error" id="<?= e($name) ?>-error"><?= e($error) ?></p><?php endif; ?>
    </div>
    <?php return (string) ob_get_clean();
};
?>
<div class="narrow">
  <?php if ($errors !== []): ?>
  <div class="notice notice-error" role="alert">Şifre değiştirilemedi. Lütfen işaretli alanları kontrol edin.</div>
  <?php endif; ?>
  <section class="admin-card" aria-labelledby="password-title">
    <div class="card-header">
      <h2 id="password-title">Yönetici şifresini değiştir</h2>
      <?php if (!empty($user['last_login_at'])): ?><span class="muted text-sm">Son giriş: <?= e(fmt_date((string) $user['last_login_at'], 'j F Y H:i')) ?></span><?php endif; ?>
    </div>
    <form class="form" method="post" action="<?= e(url('/admin/sifre')) ?>">
      <?= csrf_field() ?>
      <?= $field('current_password', 'Mevcut şifre', 'current-password', $errors) ?>
      <?= $field('new_password', 'Yeni şifre', 'new-password', $errors, 'En az ' . $minLength . ' karakter. Harf, rakam ve sembol karışımı bir parola ya da birkaç kelimelik bir cümle önerilir.') ?>
      <?= $field('new_password_confirmation', 'Yeni şifre (tekrar)', 'new-password', $errors) ?>
      <div class="form-actions">
        <button class="btn btn-primary" type="submit">Şifreyi güncelle</button>
        <a class="btn" href="<?= e(url('/admin')) ?>">Vazgeç</a>
      </div>
    </form>
  </section>
  <p class="form-help">Şifreyi unutursanız sunucuda <code>php bin/install.php --reset-password --password=YENI_SIFRE</code> komutuyla sıfırlayabilirsiniz.</p>
</div>
