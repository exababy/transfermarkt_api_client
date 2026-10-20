<?php
/**
 * Admin dashboard.
 *
 * @var array<string, array{value: string, label: string, meta?: string, url?: string, tone?: string, progress?: int}> $tiles
 * @var list<array<string, mixed>> $tasks scheduler tasks (DashboardController::tasks())
 * @var string|null $lastTmSync UTC
 * @var list<array{ts: string, level: string, channel: string, message: string, context: array}> $logs newest first
 * @var string $back current path (for action redirects)
 */

$statusBadge = static function (?string $status, bool $stale): string {
    [$class, $label] = match (true) {
        $stale => ['badge-warning', 'Takıldı'],
        $status === 'ok' => ['badge-success', 'Başarılı'],
        $status === 'error' => ['badge-danger', 'Hata'],
        $status === 'running' => ['badge-info', 'Çalışıyor'],
        $status === null || $status === '' => ['badge-muted', 'Hiç çalışmadı'],
        default => ['badge-muted', $status],
    };
    return '<span class="badge ' . $class . '">' . e($label) . '</span>';
};
$interval = static function (int $minutes): string {
    if ($minutes % 60 === 0 && $minutes >= 60) {
        return ($minutes / 60) . ' saatte bir';
    }
    return $minutes . ' dakikada bir';
};
$logContext = static function (array $context): string {
    if ($context === []) {
        return '';
    }
    if (isset($context['exception']) && is_array($context['exception'])) {
        $context['exception'] = ($context['exception']['class'] ?? '') . ': ' . ($context['exception']['message'] ?? '');
    }
    $json = (string) json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    return mb_strlen($json) > 260 ? mb_substr($json, 0, 259) . '…' : $json;
};
$quickLinks = [
    ['/admin/haberler/yeni', 'Yeni haber yaz', 'Elle haber ekleyin'],
    ['/admin/haber-kuyrugu?durum=pending', 'Haber kuyruğu', 'Bekleyen haberleri yayınlayın'],
    ['/admin/videolar', 'Video ekle', 'YouTube bağlantısıyla'],
    ['/admin/siparisler', 'Siparişler', 'Mağaza siparişleri'],
    ['/admin/ayarlar', 'Ayarlar', 'Limitler, kaynaklar, anahtarlar'],
    ['/', 'Siteyi görüntüle', 'Yeni sekmede açılır'],
];
?>
<section class="admin-section" aria-labelledby="overview-title">
  <h2 id="overview-title" class="visually-hidden">Genel bakış</h2>
  <div class="stat-grid">
    <?php foreach ($tiles as $key => $tile): ?>
    <a class="stat-card<?= !empty($tile['tone']) ? ' tone-' . e($tile['tone']) : '' ?>" href="<?= e(url($tile['url'] ?? '/admin')) ?>" data-tile="<?= e($key) ?>">
      <span class="stat-label"><?= e($tile['label']) ?></span>
      <span class="stat-value"><?= e($tile['value']) ?></span>
      <?php if (isset($tile['progress'])): ?>
      <span class="progress" role="img" aria-label="Günlük limitin yüzde <?= (int) $tile['progress'] ?> kadarı kullanıldı"><span style="width: <?= (int) $tile['progress'] ?>%"></span></span>
      <?php endif; ?>
      <?php if (!empty($tile['meta'])): ?><span class="stat-meta"><?= e($tile['meta']) ?></span><?php endif; ?>
    </a>
    <?php endforeach; ?>
  </div>
</section>

<section class="admin-section" aria-labelledby="links-title">
  <h2 id="links-title" class="visually-hidden">Hızlı bağlantılar</h2>
  <ul class="quick-links">
    <?php foreach ($quickLinks as [$href, $label, $hint]): ?>
    <li><a href="<?= e(url($href)) ?>"<?= $href === '/' ? ' target="_blank" rel="noopener"' : '' ?>><strong><?= e($label) ?></strong><span><?= e($hint) ?></span></a></li>
    <?php endforeach; ?>
  </ul>
</section>

<section class="admin-card" aria-labelledby="cron-title">
  <div class="card-header">
    <div>
      <h2 id="cron-title">Zamanlanmış görevler</h2>
      <p class="muted text-sm mb-0">Son Transfermarkt senkronizasyonu:
        <?php if ($lastTmSync !== null): ?>
        <strong><?= e(fmt_date($lastTmSync, 'j F Y H:i')) ?></strong> (<?= e(fmt_relative($lastTmSync)) ?>)
        <?php else: ?>
        <strong>henüz yapılmadı</strong>
        <?php endif; ?>
      </p>
    </div>
    <form method="post" action="<?= e(url('/admin/islem/cache-clear')) ?>" data-busy="Temizleniyor…">
      <?= csrf_field() ?>
      <input type="hidden" name="_back" value="<?= e($back) ?>">
      <button class="btn btn-sm" type="submit">Önbelleği temizle</button>
    </form>
  </div>
  <div class="table-wrap">
    <table class="table table-stack">
      <thead>
        <tr><th scope="col">Görev</th><th scope="col">Son çalışma</th><th scope="col">Durum</th><th scope="col">Mesaj</th><th scope="col">Sıklık</th><th scope="col"><span class="visually-hidden">İşlem</span></th></tr>
      </thead>
      <tbody>
        <?php foreach ($tasks as $task): ?>
        <tr>
          <th scope="row" data-label="Görev"><?= e($task['label']) ?><span class="cell-sub mono"><?= e($task['name']) ?></span></th>
          <td data-label="Son çalışma" class="nowrap">
            <?php if ($task['started'] !== null): ?>
            <span title="<?= e(fmt_date($task['started'], 'j F Y H:i:s')) ?>"><?= e(fmt_relative($task['started'])) ?></span>
            <?php if ($task['next'] !== null): ?><span class="cell-sub">Sonraki: <?= e(fmt_date($task['next'], 'd.m H:i')) ?></span><?php endif; ?>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td data-label="Durum"><?= $statusBadge($task['status'], $task['stale']) ?></td>
          <td data-label="Mesaj" class="cell-message"<?= mb_strlen($task['message']) > 160 ? ' title="' . e(mb_substr($task['message'], 0, 1000)) . '"' : '' ?>><?= $task['message'] !== '' ? e(mb_strimwidth($task['message'], 0, 160, '…')) : '<span class="muted">—</span>' ?></td>
          <td data-label="Sıklık" class="nowrap"><?= e($interval((int) $task['interval'])) ?></td>
          <td class="cell-actions">
            <form method="post" action="<?= e(url('/admin/islem/' . $task['name'])) ?>" data-busy="Çalışıyor…">
              <?= csrf_field() ?>
              <input type="hidden" name="_back" value="<?= e($back) ?>">
              <button class="btn btn-sm btn-primary" type="submit">Şimdi çalıştır</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="form-help">Görevler normalde sunucu cron'u ile <code>bin/cron.php</code> üzerinden çalışır. Kurulum satırı <a href="<?= e(url('/admin/ayarlar#cron')) ?>">Ayarlar › Zamanlanmış görevler</a> bölümündedir.</p>
</section>


<section class="admin-card" aria-labelledby="log-title">
  <div class="card-header">
    <h2 id="log-title">Son günlük kayıtları</h2>
    <span class="muted text-sm">Son <?= count($logs) ?> satır · en yenisi üstte · <code>storage/logs</code></span>
  </div>
  <?php if ($logs === []): ?>
  <div class="empty-state empty-state-sm"><p class="mb-0">Henüz günlük kaydı yok.</p></div>
  <?php else: ?>
  <pre class="log-view" tabindex="0" aria-label="Günlük kayıtları"><?php foreach ($logs as $log): ?><span class="log-line log-<?= e(preg_replace('/[^a-z]/', '', strtolower((string) $log['level']))) ?>"><span class="log-ts"><?= e(fmt_date(str_replace(['T', 'Z'], [' ', ''], (string) $log['ts']), 'd.m H:i:s')) ?></span> <span class="log-level"><?= e(strtoupper((string) $log['level'])) ?></span> <span class="log-channel"><?= e($log['channel']) ?></span> <?= e($log['message']) ?><?php $ctx = $logContext(is_array($log['context']) ? $log['context'] : []); if ($ctx !== ''): ?> <span class="log-context"><?= e($ctx) ?></span><?php endif; ?></span><?php endforeach; ?></pre>
  <?php endif; ?>
</section>
