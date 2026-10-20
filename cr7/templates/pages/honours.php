<?php
/**
 * /kupalar-ve-oduller — trophies, individual awards and records (content/honours.php).
 *
 * @var array{team_total: int, ballon_dor: int, golden_shoe: int, champions_league: int, individual_total: int, records: int, clubs: int} $summary
 * @var list<array{club: string, club_id: string, anchor: string, slug: ?string, url: ?string, logo: ?string, total: int, items: list<array{title: string, count: int, seasons: list<string>, note: string}>}> $groups
 * @var list<array<string, mixed>> $individual
 * @var list<array{title: string, detail?: string}> $records
 * @var list<array{title: string, url: string}> $sources
 * @var string|null $reviewed
 */

use App\Support\Format;
?>
<header class="page-header">
  <div class="container">
    <span class="eyebrow">Başarılar</span>
    <h1>Cristiano Ronaldo’nun Kupaları ve Ödülleri</h1>
    <p class="lead">Sporting CP’den Al-Nassr’a ve Portekiz Milli Takımı’na: CR7’nin kazandığı tüm takım kupaları, Ballon d’Or’dan Altın Ayakkabı’ya bireysel ödülleri ve futbol tarihine geçen rekorları.</p>
    <?php if ($reviewed !== null): ?>
    <p class="meta page-meta"><span>Son güncelleme: <time datetime="<?= e(substr($reviewed, 0, 10)) ?>"><?= e(Format::date($reviewed, 'j F Y')) ?></time></span></p>
    <?php endif; ?>
  </div>
</header>

<section class="section-sm" aria-labelledby="ozet-baslik">
  <div class="container">
    <h2 class="visually-hidden" id="ozet-baslik">Kupa ve ödül özeti</h2>
    <div class="stat-tiles honours-tiles">
      <?= partial('partials/stat-tile', ['value' => $summary['team_total'], 'label' => 'Takım kupası', 'sub' => $summary['clubs'] . ' farklı takımla']) ?>
      <?= partial('partials/stat-tile', ['value' => $summary['ballon_dor'], 'label' => 'Ballon d’Or']) ?>
      <?= partial('partials/stat-tile', ['value' => $summary['golden_shoe'], 'label' => 'Avrupa Altın Ayakkabı']) ?>
      <?= partial('partials/stat-tile', ['value' => $summary['champions_league'], 'label' => 'Şampiyonlar Ligi']) ?>
    </div>
    <nav class="tags honours-nav" aria-label="Sayfa bölümleri">
      <?php if ($groups !== []): ?><a class="tag" href="#takim-kupalari">Takım kupaları</a><?php endif; ?>
      <?php if ($individual !== []): ?><a class="tag" href="#bireysel-oduller">Bireysel ödüller</a><?php endif; ?>
      <?php if ($records !== []): ?><a class="tag" href="#rekorlar">Rekorlar</a><?php endif; ?>
      <?php if ($sources !== []): ?><a class="tag" href="#kaynaklar">Kaynaklar</a><?php endif; ?>
    </nav>
  </div>
</section>

<?php if ($groups !== []): ?>
<section class="section section-alt" id="takim-kupalari" aria-labelledby="takim-kupalari-baslik">
  <div class="container">
    <div class="section-header">
      <div>
        <h2 class="section-title" id="takim-kupalari-baslik">Takım kupaları</h2>
        <p class="lead">Ronaldo’nun kulüpleri ve Portekiz Milli Takımı ile kazandığı <?= (int) $summary['team_total'] ?> resmî kupa, takım takım.</p>
      </div>
    </div>
    <div class="honours-clubs">
      <?php foreach ($groups as $group): ?>
      <article class="card honours-club" id="<?= e($group['anchor']) ?>" aria-labelledby="<?= e($group['anchor']) ?>-baslik">
        <header class="honours-club-head">
          <?php if ($group['logo'] !== null): ?>
          <img class="logo-md" src="<?= e($group['logo']) ?>" alt="<?= e($group['club'] . ' logosu') ?>" width="44" height="44" loading="lazy" decoding="async">
          <?php else: ?>
          <span class="club-initial" aria-hidden="true"><?= e(mb_substr($group['club'], 0, 1)) ?></span>
          <?php endif; ?>
          <div>
            <h3 id="<?= e($group['anchor']) ?>-baslik"><?php if ($group['url'] !== null): ?><a href="<?= e(url($group['url'])) ?>"><?= e($group['club']) ?></a><?php else: ?><?= e($group['club']) ?><?php endif; ?></h3>
            <p><?= count($group['items']) ?> farklı kupa</p>
          </div>
          <p class="honours-total"><strong><?= (int) $group['total'] ?></strong>kupa</p>
        </header>
        <ul class="trophy-list">
          <?php foreach ($group['items'] as $item): ?>
          <li class="trophy">
            <div class="trophy-row">
              <h4><?= e($item['title']) ?></h4>
              <span class="trophy-count"><?= (int) $item['count'] ?> kez</span>
            </div>
            <?php if ($item['seasons'] !== []): ?>
            <ul class="tags list-unstyled" aria-label="Sezonlar">
              <?php foreach ($item['seasons'] as $season): ?><li class="tag"><?= e($season) ?></li><?php endforeach; ?>
            </ul>
            <?php endif; ?>
            <?php if ($item['note'] !== ''): ?><p class="trophy-note"><?= e($item['note']) ?></p><?php endif; ?>
          </li>
          <?php endforeach; ?>
        </ul>
      </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if ($individual !== []): ?>
<section class="section" id="bireysel-oduller" aria-labelledby="bireysel-oduller-baslik">
  <div class="container">
    <div class="section-header">
      <div>
        <h2 class="section-title" id="bireysel-oduller-baslik">Bireysel ödüller</h2>
        <p class="lead"><?= count($individual) ?> farklı ödülde toplam <?= (int) $summary['individual_total'] ?> bireysel ödül ve seçim: Ballon d’Or, FIFA ödülleri, gol krallıkları, yılın takımları ve devlet nişanları.</p>
      </div>
    </div>
    <div class="table-wrap">
      <table class="table awards-table">
        <caption>Kaynaklar sayfanın sonunda listelenmiştir. Sezon biçimindeki değerler (ör. 2007–08) sezon ödüllerini gösterir.</caption>
        <thead>
          <tr>
            <th scope="col">Ödül</th>
            <th scope="col" class="num">Sayı</th>
            <th scope="col">Yıllar / sezonlar</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($individual as $award): ?>
          <?php $years = array_map('strval', (array) ($award['years'] ?? [])); ?>
          <tr>
            <td><?= e((string) $award['title']) ?><?php if (!empty($award['note'])): ?><span class="award-note"><?= e((string) $award['note']) ?></span><?php endif; ?></td>
            <td class="num"><strong><?= (int) ($award['count'] ?? count($years)) ?></strong></td>
            <td class="wrap"><?= e(implode(', ', $years)) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if ($records !== []): ?>
<section class="section section-alt" id="rekorlar" aria-labelledby="rekorlar-baslik">
  <div class="container">
    <div class="section-header">
      <div>
        <h2 class="section-title" id="rekorlar-baslik">Kırdığı rekorlar</h2>
        <p class="lead">Milli takım gol rekorundan Şampiyonlar Ligi’nin en golcüsü olmaya: Ronaldo’nun adını futbol tarihine yazdıran <?= count($records) ?> rekor.</p>
      </div>
    </div>
    <ol class="records">
      <?php foreach ($records as $record): ?>
      <li class="record">
        <h3><?= e((string) $record['title']) ?></h3>
        <?php if (!empty($record['detail'])): ?><p><?= e((string) $record['detail']) ?></p><?php endif; ?>
      </li>
      <?php endforeach; ?>
    </ol>
  </div>
</section>
<?php endif; ?>

<section class="section" id="kaynaklar" aria-labelledby="kaynaklar-baslik">
  <div class="container">
    <h2 class="section-title" id="kaynaklar-baslik">Kaynaklar</h2>
    <p class="text-muted">Kupa ve ödül listesi aşağıdaki kaynaklarla doğrulanmıştır. Ronaldo’nun hayatı için <a href="<?= e(url('/hayati')) ?>">biyografi sayfasına</a>, maç ve gol sayıları için <a href="<?= e(url('/istatistikler')) ?>">istatistikler sayfasına</a> göz atın.</p>
    <?php if ($sources !== []): ?>
    <ol class="sources-list">
      <?php foreach ($sources as $source): ?>
      <li><a href="<?= e($source['url']) ?>" rel="nofollow noopener" target="_blank"><?= e($source['title'] ?? $source['url']) ?></a></li>
      <?php endforeach; ?>
    </ol>
    <?php endif; ?>
  </div>
</section>
