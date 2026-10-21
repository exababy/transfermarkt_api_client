<?php
/**
 * /videolar — featured videos, category tabs, paginated grid.
 *
 * @var list<array<string, mixed>> $videos VideoRepository::latest() rows
 * @var list<array<string, mixed>> $featured
 * @var string|null $category active category key (null = all)
 * @var array<string, string> $categories key => label
 * @var array<string, int> $counts published videos per non-empty category
 * @var int $allCount
 * @var string $h1
 * @var string $lead
 * @var int $page
 * @var int $pages
 * @var int $total
 * @var string $baseUrl
 */

use App\Services\Video\VideoText;
use App\Support\Format;
use App\Support\Str;

$mosaic = count($featured) === 5;
$listTitle = $category === null ? 'Tüm videolar' : $categories[$category] . ' videoları';
?>
<header class="page-header">
  <div class="container">
    <span class="eyebrow">Videolar</span>
    <h1><?= e($h1) ?><?= $page > 1 ? ' <span class="text-muted">· Sayfa ' . (int) $page . '</span>' : '' ?></h1>
    <p class="lead"><?= e($lead) ?></p>
  </div>
</header>

<?php if ($featured !== []): ?>
<section class="section" aria-labelledby="featured-title">
  <div class="container">
    <div class="section-header">
      <h2 id="featured-title" class="section-title">Öne Çıkan Videolar</h2>
    </div>
    <div class="grid <?= $mosaic ? 'video-mosaic' : (count($featured) >= 4 ? 'grid-4' : 'grid-3') ?>">
      <?php foreach ($featured as $i => $video): ?>
      <?php if ($mosaic && $i === 0): ?>
      <?php $clock = VideoText::clock($video['duration_seconds']); $href = url($video['url']); ?>
      <article class="card video-card video-lead">
        <a class="card-media" href="<?= e($href) ?>" tabindex="-1" aria-hidden="true">
          <img src="<?= e($video['thumbnail_url']) ?>" alt="<?= e($video['title']) ?>" width="480" height="360" decoding="async" fetchpriority="high">
          <span class="video-card-play"></span>
          <?php if ($clock !== ''): ?><span class="duration"><?= e($clock) ?></span><?php endif; ?>
        </a>
        <div class="card-body">
          <a class="badge video-lead-badge" href="<?= e(url('/videolar?kategori=' . rawurlencode($video['category']))) ?>"><?= e($video['category_name']) ?></a>
          <h3 class="card-title"><a href="<?= e($href) ?>"><?= e($video['title']) ?></a></h3>
          <?php if ($video['description'] !== ''): ?><p class="card-text"><?= e(Str::limit(str_replace("\n", ' ', $video['description']), 210)) ?></p><?php endif; ?>
          <div class="meta">
            <?php if (!empty($video['channel_title'])): ?><span><?= e($video['channel_title']) ?></span><?php endif; ?>
            <time datetime="<?= e(Format::iso($video['date'])) ?>"><?= e(fmt_date($video['date'])) ?></time>
          </div>
        </div>
      </article>
      <?php else: ?>
      <?= partial('partials/video-card', ['video' => $video, 'headingTag' => 'h3']) ?>
      <?php endif; ?>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<section class="section<?= $featured !== [] ? ' section-alt' : '' ?>" aria-labelledby="video-list-title" id="videolar">
  <div class="container">
    <?php if ($counts !== []): ?>
    <nav class="tabs video-tabs" aria-label="Video kategorileri">
      <a class="tab<?= $category === null ? ' active' : '' ?>" href="<?= e(url('/videolar')) ?>"<?= $category === null ? ' aria-current="page"' : '' ?>>Tümü<span class="tab-count"><?= (int) $allCount ?></span></a>
      <?php foreach ($categories as $key => $label): ?>
      <?php if (!isset($counts[$key]) && $key !== $category) { continue; } ?>
      <a class="tab<?= $key === $category ? ' active' : '' ?>" href="<?= e(url('/videolar?kategori=' . rawurlencode($key))) ?>"<?= $key === $category ? ' aria-current="page"' : '' ?>><?= e($label) ?><span class="tab-count"><?= (int) ($counts[$key] ?? 0) ?></span></a>
      <?php endforeach; ?>
    </nav>
    <?php if ($category !== null): ?>
    <script nonce="<?= e(\App\Core\Response::nonce()) ?>">(function(){var t=document.querySelector('.video-tabs .tab.active');if(t&&t.parentNode.scrollWidth>t.parentNode.clientWidth){t.parentNode.scrollLeft=Math.max(0,t.offsetLeft-t.parentNode.offsetLeft-24);}})();</script>
    <?php endif; ?>
    <?php endif; ?>

    <div class="list-head">
      <h2 id="video-list-title" class="section-title"><?= e($listTitle) ?></h2>
      <?php if ($total > 0): ?><p class="meta"><span><?= e(fmt_number($total)) ?> video</span><?php if ($pages > 1): ?><span>Sayfa <?= (int) $page ?> / <?= (int) $pages ?></span><?php endif; ?></p><?php endif; ?>
    </div>

    <?php if ($videos === []): ?>
    <div class="empty-state">
      <span class="empty-state-icon" aria-hidden="true">▶</span>
      <h3>Bu bölümde henüz video yok</h3>
      <p class="lead">Cristiano Ronaldo videoları resmî kanallardan düzenli olarak ekleniyor. Bu arada diğer kategorilere ya da kariyer istatistiklerine göz atabilirsiniz.</p>
      <div class="cluster center">
        <?php if ($category !== null): ?><a class="btn btn-gold" href="<?= e(url('/videolar')) ?>">Tüm videolar</a><?php endif; ?>
        <a class="btn btn-outline" href="<?= e(url('/istatistikler')) ?>">İstatistikler</a>
      </div>
    </div>
    <?php else: ?>
    <div class="grid grid-4 video-grid">
      <?php foreach ($videos as $video): ?>
      <?= partial('partials/video-card', ['video' => $video, 'headingTag' => 'h3']) ?>
      <?php endforeach; ?>
    </div>
    <?= partial('partials/pagination', ['current' => $page, 'total' => $pages, 'baseUrl' => $baseUrl]) ?>
    <?php endif; ?>
  </div>
</section>

<?php if ($page === 1 && $category === null): ?>
<section class="section" aria-labelledby="video-about-title">
  <div class="container">
    <h2 id="video-about-title" class="section-title">Cristiano Ronaldo videoları hakkında</h2>
    <div class="video-intro">
      <p>Bu sayfada Cristiano Ronaldo’nun Sporting CP’den Manchester United’a, Real Madrid’den Juventus ve Al Nassr’a uzanan kariyerinden seçilmiş videoları bulabilirsiniz. Videolar yalnızca UEFA, FIFA, kulüpler ve Ronaldo’nun kendi kanalı UR · Cristiano gibi resmî YouTube kanallarından alınır; izlediğiniz anda YouTube’un gizlilik dostu oynatıcısıyla açılır.</p>
      <p>Golleri sayılarla incelemek için <a href="<?= e(url('/istatistikler')) ?>">Ronaldo istatistikleri</a>, kulüp kulüp hikâyesi için <a href="<?= e(url('/kariyer')) ?>">kariyer</a> sayfasına, güncel gelişmeler için <a href="<?= e(url('/haberler')) ?>">Cristiano Ronaldo haberleri</a> bölümüne göz atın.</p>
    </div>
  </div>
</section>
<?php endif; ?>
