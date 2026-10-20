<?php
/**
 * Site settings (non-payment keys of spec §4).
 *
 * @var array<string, array<string, mixed>> $fields SettingsController::FIELDS
 * @var array<string, mixed> $current input name => effective value
 * @var array<string, mixed> $old posted values after a validation error ([] otherwise)
 * @var array<string, string> $errors input name => message
 * @var array<string, array<string, mixed>> $sources every defined news source
 * @var list<string> $disabledSources
 * @var bool $registryAvailable
 * @var array<string, mixed> $defaults config defaults per input
 * @var string $publishWindow
 * @var int $maxAgeHours
 * @var string $youtubeMasked
 * @var string $cronToken
 * @var bool $cronRevealed
 * @var string $cronUrl
 * @var string $cronConfigToken
 * @var string $crontabLine
 * @var string $indexNowUrl
 */

$submitted = $old !== [];
$val = static function (string $name) use ($old, $current, $submitted): string {
    $value = $submitted ? ($old[$name] ?? '') : ($current[$name] ?? '');
    if (is_bool($value)) {
        return $value ? '1' : '';
    }
    if (is_float($value)) {
        return str_replace('.', ',', rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.'));
    }
    return is_scalar($value) ? (string) $value : '';
};
$isChecked = static function (string $name) use ($old, $current, $submitted): bool {
    if ($submitted) {
        return isset($old[$name]) && $old[$name] !== '' && $old[$name] !== '0';
    }
    $value = $current[$name] ?? false;
    return !in_array($value, [false, 0, '0', 'false', '', null], true);
};
$err = static fn (string $name): string => isset($errors[$name]) ? '<p class="form-error" id="' . e($name) . '-error">' . e($errors[$name]) . '</p>' : '';
$aria = static fn (string $name, string $help = ''): string => (isset($errors[$name]) ? ' aria-invalid="true"' : '')
    . (($ids = trim(($help !== '' ? $help . ' ' : '') . (isset($errors[$name]) ? $name . '-error' : ''))) !== '' ? ' aria-describedby="' . e($ids) . '"' : '');
$enabledPosted = $submitted && is_array($old['sources_enabled'] ?? null) ? $old['sources_enabled'] : null;
$typeLabels = ['rss' => 'RSS', 'atom' => 'Atom', 'bing' => 'Bing Haberler', 'google' => 'Google Haberler'];
$portrait = $val('site_portrait_url');
$cronFromConfig = $cronToken !== '' && $cronConfigToken !== '' && hash_equals($cronConfigToken, $cronToken);
$webCronLine = $cronUrl !== '' ? '*/10 * * * * curl -fsS "' . $cronUrl . '" >/dev/null 2>&1' : '';
?>
<?php if ($errors !== []): ?>
<div class="notice notice-error" role="alert">
  <strong>Ayarlar kaydedilemedi.</strong> Lütfen işaretli alanları düzeltin:
  <ul class="error-list"><?php foreach ($errors as $name => $message): ?><li><a href="#<?= e($name) ?>"><?= e($message) ?></a></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<nav class="settings-nav" aria-label="Ayar bölümleri">
  <a href="#haber-yayini">Haber yayını</a>
  <a href="#haber-kaynaklari">Haber kaynakları</a>
  <a href="#ana-sayfa">Ana sayfa</a>
  <a href="#arama-motorlari">Analitik ve SEO</a>
  <a href="#youtube">YouTube</a>
  <a href="#cron">Zamanlanmış görevler</a>
  <a href="<?= e(url('/admin/odeme-ayarlari')) ?>">Mağaza ve ödeme ›</a>
</nav>

<form class="settings-form" method="post" action="<?= e(url('/admin/ayarlar')) ?>" id="settings-form" novalidate>
  <?= csrf_field() ?>

  <section class="admin-card settings-group" id="haber-yayini" aria-labelledby="g-news">
    <div class="settings-head">
      <h2 id="g-news">Haber yayını</h2>
      <p class="muted text-sm">Otomatik haberlerin ne sıklıkla ve hangi kalite eşiğiyle yayınlanacağı.</p>
    </div>
    <div class="form-row">
      <div class="field">
        <label for="news_daily_limit">Günlük yayın limiti</label>
        <input class="input" id="news_daily_limit" name="news_daily_limit" type="number" min="1" max="50" step="1" inputmode="numeric" value="<?= e($val('news_daily_limit')) ?>" required<?= $aria('news_daily_limit', 'h-limit') ?>>
        <p class="form-help" id="h-limit">Bir günde (İstanbul saatiyle) otomatik yayınlanacak en fazla haber: 1–50. Varsayılan: <?= e((string) $defaults['news_daily_limit']) ?>.</p>
        <?= $err('news_daily_limit') ?>
      </div>
      <div class="field">
        <label for="news_min_interval_minutes">İki yayın arası en az süre (dakika)</label>
        <input class="input" id="news_min_interval_minutes" name="news_min_interval_minutes" type="number" min="0" max="1440" step="1" inputmode="numeric" value="<?= e($val('news_min_interval_minutes')) ?>" required<?= $aria('news_min_interval_minutes', 'h-interval') ?>>
        <p class="form-help" id="h-interval">0–1440. Haberlerin güne yayılması için. Varsayılan: <?= e((string) $defaults['news_min_interval_minutes']) ?>.</p>
        <?= $err('news_min_interval_minutes') ?>
      </div>
    </div>
    <div class="form-row">
      <div class="field">
        <label for="spinner_min_change_ratio">Asgari özgünlük oranı</label>
        <input class="input" id="spinner_min_change_ratio" name="spinner_min_change_ratio" type="text" inputmode="decimal" value="<?= e($val('spinner_min_change_ratio')) ?>" required<?= $aria('spinner_min_change_ratio', 'h-ratio') ?>>
        <p class="form-help" id="h-ratio">0,20–0,90. Yazının her kaynaktan en az bu oranda farklı olması gerekir; altında kalanlar yayınlanmaz. Varsayılan: <?= e(str_replace('.', ',', (string) $defaults['spinner_min_change_ratio'])) ?>.</p>
        <?= $err('spinner_min_change_ratio') ?>
      </div>
      <div class="field">
        <span class="label">Yayın penceresi ve haber yaşı</span>
        <p class="static-value"><?= e($publishWindow) ?> · en fazla <?= (int) $maxAgeHours ?> saatlik haberler</p>
        <p class="form-help"><code>config/config.php</code> içindeki <code>news.publish_window</code> ve <code>news.max_age_hours</code> ile değiştirilir.</p>
      </div>
    </div>
    <label class="checkbox">
      <input type="checkbox" name="news_index_spun_posts" value="1"<?= $isChecked('news_index_spun_posts') ? ' checked' : '' ?>>
      <span>Otomatik (spin) haberler arama motorlarında indekslensin<span class="form-help block">Kapatırsanız yeni otomatik haberler <code>noindex</code> olarak yayınlanır ve site haritasına girmez. Mevcut haberler için haber düzenleme ekranını kullanın.</span></span>
    </label>
  </section>

  <section class="admin-card settings-group" id="haber-kaynaklari" aria-labelledby="g-sources">
    <div class="settings-head">
      <h2 id="g-sources">Haber kaynakları</h2>
      <p class="muted text-sm">İşareti kaldırılan kaynaklardan haber toplanmaz.</p>
    </div>
    <?php if (!$registryAvailable || $sources === []): ?>
    <div class="empty-state empty-state-sm"><p class="mb-0">Haber kaynakları listesi şu an kullanılamıyor (haber modülü ya da <code>config/news-sources.php</code> eksik).</p></div>
    <?php else: ?>
    <input type="hidden" name="sources_present" value="1">
    <fieldset class="source-grid"<?= isset($errors['sources_enabled']) ? ' aria-describedby="sources_enabled-error"' : '' ?>>
      <legend class="visually-hidden">Etkin haber kaynakları</legend>
      <?php foreach ($sources as $key => $source):
          $enabled = $enabledPosted !== null ? in_array((string) $key, $enabledPosted, true) : !in_array((string) $key, $disabledSources, true);
          $host = (string) parse_url((string) ($source['url'] ?? ''), PHP_URL_HOST);
          parse_str((string) parse_url((string) ($source['url'] ?? ''), PHP_URL_QUERY), $sourceQuery);
          $searchTerm = in_array($source['type'] ?? '', ['bing', 'google'], true) && is_string($sourceQuery['q'] ?? null) ? $sourceQuery['q'] : '';
      ?>
      <label class="source-option">
        <input type="checkbox" name="sources_enabled[]" value="<?= e((string) $key) ?>"<?= $enabled ? ' checked' : '' ?><?= $key === array_key_first($sources) ? ' id="sources_enabled"' : '' ?>>
        <span>
          <strong><?= e($source['name'] ?? $key) ?></strong>
          <span class="cell-sub"><?= e($typeLabels[$source['type'] ?? ''] ?? ($source['type'] ?? '')) ?><?= $searchTerm !== '' ? ' · “' . e(mb_strimwidth($searchTerm, 0, 40, '…')) . '”' : ($host !== '' ? ' · ' . e(preg_replace('/^www\./', '', $host)) : '') ?></span>
          <span class="cell-sub mono"><?= e((string) $key) ?></span>
        </span>
      </label>
      <?php endforeach; ?>
    </fieldset>
    <?= $err('sources_enabled') ?>
    <?php endif; ?>
  </section>

  <section class="admin-card settings-group" id="ana-sayfa" aria-labelledby="g-home">
    <div class="settings-head">
      <h2 id="g-home">Ana sayfa</h2>
      <p class="muted text-sm">Boş bırakılan alanlarda sitenin varsayılan metinleri kullanılır.</p>
    </div>
    <div class="field">
      <label for="site_hero_title">Ana sayfa başlığı</label>
      <input class="input" id="site_hero_title" name="site_hero_title" type="text" value="<?= e($val('site_hero_title')) ?>" maxlength="120" data-counter="120" placeholder="ör. Cristiano Ronaldo: Bir Efsanenin Hikâyesi"<?= $aria('site_hero_title') ?>>
      <?= $err('site_hero_title') ?>
    </div>
    <div class="field">
      <label for="site_hero_subtitle">Ana sayfa alt başlığı</label>
      <textarea class="textarea textarea-sm" id="site_hero_subtitle" name="site_hero_subtitle" rows="2" maxlength="300" data-counter="300"<?= $aria('site_hero_subtitle') ?>><?= e($val('site_hero_subtitle')) ?></textarea>
      <?= $err('site_hero_subtitle') ?>
    </div>
    <div class="field">
      <label for="site_portrait_url">Portre görseli adresi</label>
      <div class="input-with-preview">
        <input class="input" id="site_portrait_url" name="site_portrait_url" type="text" inputmode="url" value="<?= e($portrait) ?>" maxlength="500" placeholder="https://… ya da /assets/img/…" spellcheck="false"<?= $aria('site_portrait_url', 'h-portrait') ?>>
        <?php if ($portrait !== '' && !isset($errors['site_portrait_url'])): ?><img class="portrait-preview" src="<?= e(url($portrait)) ?>" width="56" height="56" alt="Mevcut portre görseli" loading="lazy"><?php endif; ?>
      </div>
      <p class="form-help" id="h-portrait">Kullanım hakkına sahip olduğunuz bir görsel kullanın. Boşsa Transfermarkt portresi ya da varsayılan görsel gösterilir.</p>
      <?= $err('site_portrait_url') ?>
    </div>
  </section>

  <section class="admin-card settings-group" id="arama-motorlari" aria-labelledby="g-seo">
    <div class="settings-head">
      <h2 id="g-seo">Analitik ve arama motorları</h2>
    </div>
    <div class="form-row">
      <div class="field">
        <label for="analytics_ga4_id">Google Analytics 4 ölçüm kimliği</label>
        <input class="input mono" id="analytics_ga4_id" name="analytics_ga4_id" type="text" value="<?= e($val('analytics_ga4_id')) ?>" maxlength="24" placeholder="G-XXXXXXXXXX" spellcheck="false" autocapitalize="characters"<?= $aria('analytics_ga4_id', 'h-ga4') ?>>
        <p class="form-help" id="h-ga4">Boş bırakılırsa Analytics yüklenmez. Ziyaretçi çerez onayı verdikten sonra yüklenir.</p>
        <?= $err('analytics_ga4_id') ?>
      </div>
      <div class="field">
        <label for="indexnow_key">IndexNow anahtarı (Bing, Yandex)</label>
        <div class="input-group">
          <input class="input mono" id="indexnow_key" name="indexnow_key" type="text" value="<?= e($val('indexnow_key')) ?>" maxlength="128" spellcheck="false"<?= $aria('indexnow_key', 'h-indexnow') ?>>
          <button class="btn" type="submit" form="gen-indexnow"<?= $val('indexnow_key') !== '' ? ' data-confirm-click="Mevcut IndexNow anahtarı yenisiyle değiştirilsin mi?"' : '' ?>>Anahtar üret</button>
        </div>
        <p class="form-help" id="h-indexnow">Yeni ve güncellenen haberler/videolar arama motorlarına anında bildirilir. Anahtar dosyası: <a href="<?= e($indexNowUrl) ?>" target="_blank" rel="noopener"><?= e($indexNowUrl) ?></a></p>
        <?= $err('indexnow_key') ?>
      </div>
    </div>
  </section>

  <section class="admin-card settings-group" id="youtube" aria-labelledby="g-yt">
    <div class="settings-head">
      <h2 id="g-yt">YouTube</h2>
      <p class="muted text-sm">API anahtarı olmadan videolar kanal RSS akışları ve oEmbed ile alınır; anahtar süre ve izlenme bilgilerini ekler.</p>
    </div>
    <div class="field">
      <label for="youtube_api_key">YouTube Data API v3 anahtarı</label>
      <div class="password-field">
        <input class="input mono" id="youtube_api_key" name="youtube_api_key" type="password" value="" maxlength="100" autocomplete="off" spellcheck="false" placeholder="<?= e($youtubeMasked !== '' ? 'Kayıtlı: ' . $youtubeMasked : 'AIza…') ?>"<?= $aria('youtube_api_key', 'h-yt') ?>>
        <button class="btn btn-sm btn-ghost" type="button" data-toggle-password="#youtube_api_key" aria-controls="youtube_api_key">Göster</button>
      </div>
      <p class="form-help" id="h-yt"><?= $youtubeMasked !== '' ? 'Bir anahtar kayıtlı. Değiştirmek için yenisini yazın; boş bırakırsanız mevcut anahtar korunur.' : 'Henüz anahtar kayıtlı değil.' ?></p>
      <?= $err('youtube_api_key') ?>
    </div>
    <?php if ($youtubeMasked !== ''): ?>
    <label class="checkbox">
      <input type="checkbox" name="youtube_api_key_clear" value="1"<?= $isChecked('youtube_api_key_clear') ? ' checked' : '' ?>>
      <span>Kayıtlı API anahtarını sil</span>
    </label>
    <?php endif; ?>
  </section>

  <section class="admin-card settings-group" id="cron" aria-labelledby="g-cron">
    <div class="settings-head">
      <h2 id="g-cron">Zamanlanmış görevler (cron)</h2>
      <p class="muted text-sm">Haber toplama/yayınlama, video ve istatistik senkronizasyonu için tek bir cron satırı yeterlidir.</p>
    </div>
    <div class="field">
      <label for="crontab-line">Sunucu cron satırı (önerilen)</label>
      <div class="input-group">
        <input class="input mono" id="crontab-line" type="text" value="<?= e($crontabLine) ?>" readonly>
        <button class="btn" type="button" data-copy="#crontab-line">Kopyala</button>
      </div>
      <p class="form-help">cPanel › Cron Jobs › “Her 10 dakikada bir”. PHP yolu sunucunuza göre farklı olabilir (ör. <code>/usr/local/bin/php</code>).</p>
    </div>

    <div class="field">
      <label for="cron_web_token">Web cron anahtarı</label>
      <div class="input-group">
        <input class="input mono" id="cron_web_token" name="cron_web_token" type="password" value="" maxlength="128" autocomplete="off" spellcheck="false" placeholder="<?= e($cronToken !== '' ? 'Kayıtlı: ' . \App\Controllers\Admin\AdminPage::mask($cronToken) : 'Ayarlı değil — web cron kapalı') ?>"<?= $aria('cron_web_token', 'h-cron') ?>>
        <button class="btn" type="submit" form="gen-cron"<?= $cronToken !== '' ? ' data-confirm-click="Yeni anahtar üretilirse eski web cron adresi çalışmaz. Devam edilsin mi?"' : '' ?>>Anahtar üret</button>
      </div>
      <p class="form-help" id="h-cron">Sunucuda komut satırı cron'u yoksa harici bir cron servisine (ör. cron-job.org) aşağıdaki adresi verin. En az 32 karakter; boş bırakırsanız mevcut anahtar korunur.<?= $cronFromConfig ? ' Şu anki anahtar config.php dosyasından geliyor.' : '' ?></p>
      <?= $err('cron_web_token') ?>
    </div>
    <?php if ($cronToken !== ''): ?>
    <div class="field">
      <label for="cron-url">Web cron adresi</label>
      <div class="input-group">
        <input class="input mono" id="cron-url" type="text" value="<?= e($cronUrl) ?>" readonly>
        <?php if ($cronRevealed): ?><button class="btn" type="button" data-copy="#cron-url">Kopyala</button><?php endif; ?>
      </div>
      <?php if ($cronRevealed): ?>
      <p class="notice notice-warning text-sm">Bu adresi şimdi kopyalayın: güvenlik nedeniyle sayfa yenilendiğinde anahtarın tamamı bir daha gösterilmez.</p>
      <label for="webcron-line" class="label">Harici sunucu için cron satırı</label>
      <div class="input-group">
        <input class="input mono" id="webcron-line" type="text" value="<?= e($webCronLine) ?>" readonly>
        <button class="btn" type="button" data-copy="#webcron-line">Kopyala</button>
      </div>
      <?php else: ?>
      <p class="form-help">Anahtar gizlendi. Tam adrese ihtiyacınız varsa yeni bir anahtar üretin.</p>
      <?php endif; ?>
    </div>
    <label class="checkbox">
      <input type="checkbox" name="cron_web_token_clear" value="1"<?= $isChecked('cron_web_token_clear') ? ' checked' : '' ?>>
      <span>Web cron'u kapat (anahtarı sil)<span class="form-help block">Adres 404 döner; sunucu cron'u etkilenmez.</span></span>
    </label>
    <?php endif; ?>
  </section>

  <div class="save-bar">
    <span class="muted text-sm">Değişiklikler kaydedildiğinde hemen uygulanır.</span>
    <button class="btn btn-gold" type="submit">Ayarları kaydet</button>
  </div>
</form>

<form id="gen-indexnow" method="post" action="<?= e(url('/admin/ayarlar')) ?>" hidden>
  <?= csrf_field() ?>
  <input type="hidden" name="_action" value="generate_indexnow">
</form>
<form id="gen-cron" method="post" action="<?= e(url('/admin/ayarlar')) ?>" hidden>
  <?= csrf_field() ?>
  <input type="hidden" name="_action" value="generate_cron">
</form>
