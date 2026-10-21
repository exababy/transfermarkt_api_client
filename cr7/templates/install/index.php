<?php
/**
 * Web installer page (rendered in layouts/admin, bare mode).
 *
 * @var string $state no-secret | form | done
 * @var string|null $token
 * @var list<array{label: string, ok: bool, detail: string}>|null $checks
 * @var string|null $username
 * @var array<string, string>|null $errors
 * @var list<string>|null $log
 * @var bool|null $failed
 */

$errors = $errors ?? [];
?>
<div class="admin-card install-card">
  <h1>CR7.com.tr Kurulumu</h1>

  <?php if ($state === 'no-secret'): ?>
  <div class="notice notice-error">
    Web kurulumu için <code>config/config.php</code> dosyasında <code>app.secret</code> değerini en az 16 karakterlik
    rastgele bir metinle değiştirin, ardından <code>/kurulum?token=GİZLİ_DEĞER</code> adresini açın.
    SSH erişiminiz varsa <code>php bin/install.php</code> komutunu da kullanabilirsiniz.
  </div>

  <?php elseif ($state === 'done'): ?>
  <div class="notice <?= !empty($failed) ? 'notice-warning' : 'notice-success' ?>">
    <?= !empty($failed) ? 'Kurulum uyarılarla tamamlandı.' : 'Kurulum tamamlandı.' ?> Bu sayfa artık kapalıdır.
  </div>
  <ul class="install-log">
    <?php foreach ($log ?? [] as $line): ?>
    <li><?= e($line) ?></li>
    <?php endforeach; ?>
  </ul>
  <a class="btn btn-primary" href="<?= e(url('/admin/giris')) ?>">Yönetim paneline giriş yap</a>

  <?php else: ?>
  <h2>Sunucu kontrolleri</h2>
  <div class="table-wrap">
    <table class="table">
      <tbody>
        <?php foreach ($checks ?? [] as $check): ?>
        <tr>
          <th scope="row"><?= e($check['label']) ?></th>
          <td><span class="badge <?= $check['ok'] ? 'badge-success' : 'badge-danger' ?>"><?= $check['ok'] ? 'Tamam' : 'Sorun' ?></span></td>
          <td><?= e($check['detail']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <h2>Yönetici hesabı</h2>
  <?php if (isset($errors['general'])): ?><div class="notice notice-error"><?= e($errors['general']) ?></div><?php endif; ?>
  <form class="form" method="post" action="<?= e(url('/kurulum')) ?>" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="token" value="<?= e($token ?? '') ?>">
    <div class="field">
      <label for="username">Kullanıcı adı</label>
      <input class="input" id="username" name="username" value="<?= e($username ?? 'admin') ?>" required autocomplete="username"<?= isset($errors['username']) ? ' aria-invalid="true"' : '' ?>>
      <?php if (isset($errors['username'])): ?><p class="form-error"><?= e($errors['username']) ?></p><?php endif; ?>
    </div>
    <div class="field">
      <label for="password">Şifre</label>
      <input class="input" id="password" name="password" type="password" required minlength="<?= (int) \App\Install\Installer::MIN_PASSWORD_LENGTH ?>" autocomplete="new-password"<?= isset($errors['password']) ? ' aria-invalid="true"' : '' ?>>
      <p class="form-help">En az <?= (int) \App\Install\Installer::MIN_PASSWORD_LENGTH ?> karakter; uzun ve tahmin edilmesi zor bir şifre seçin.</p>
      <?php if (isset($errors['password'])): ?><p class="form-error"><?= e($errors['password']) ?></p><?php endif; ?>
    </div>
    <div class="field">
      <label for="password_confirm">Şifre (tekrar)</label>
      <input class="input" id="password_confirm" name="password_confirm" type="password" required autocomplete="new-password"<?= isset($errors['password_confirm']) ? ' aria-invalid="true"' : '' ?>>
      <?php if (isset($errors['password_confirm'])): ?><p class="form-error"><?= e($errors['password_confirm']) ?></p><?php endif; ?>
    </div>
    <button class="btn btn-primary" type="submit">Kurulumu başlat</button>
  </form>
  <?php endif; ?>
</div>
