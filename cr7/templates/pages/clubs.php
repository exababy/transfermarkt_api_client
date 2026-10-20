<?php
/**
 * /takimlar — club cards (senior clubs + Portekiz), comparison table, youth national teams.
 *
 * @var list<array<string, mixed>> $cards
 * @var list<array<string, mixed>> $youth national_career rows without Portugal A
 * @var string|null $lastSync
 */

use App\Support\Format;

$ratio = static fn (array $c): string => $c['appearances'] > 0 ? Format::number($c['goals'] / $c['appearances'], 2) : '–';
?>
<header class="page-header">
  <div class="container">
    <span class="eyebrow">Oynadığı Takımlar</span>
    <h1>Cristiano Ronaldo’nun Oynadığı Takımlar</h1>
    <p class="lead">Lizbon’dan Manchester’a, Madrid’den Torino’ya ve Riyad’a: Ronaldo’nun forma giydiği beş kulüp ve Portekiz Milli Takımı, maç maç Transfermarkt verileriyle.</p>
  </div>
</header>

<section class="section" aria-labelledby="takimlar-baslik">
  <div class="container">
    <h2 class="visually-hidden" id="takimlar-baslik">Kulüpler ve milli takım</h2>
    <?php if ($cards === []): ?>
    <div class="empty-state"><p>Takım bilgileri hazırlanıyor.</p></div>
    <?php else: ?>
    <div class="grid grid-3">
      <?php foreach ($cards as $card): ?>
      <article class="card club-card"<?= !empty($card['color']) && $card['color'] !== '#FFFFFF' ? ' style="--club: ' . e($card['color']) . '"' : '' ?>>
        <?php if ($card['logo_url']): ?>
        <img class="club-logo" src="<?= e($card['logo_url']) ?>" alt="<?= e($card['name']) ?> <?= $card['is_national'] ? 'bayrağı' : 'logosu' ?>" width="76" height="76" loading="lazy" decoding="async">
        <?php endif; ?>
        <div class="card-body">
          <h3 class="card-title"><a href="<?= e(url($card['url'])) ?>"><?= e($card['name']) ?></a></h3>
          <p class="club-years mb-0"><?= e($card['period']) ?></p>
          <?php if ($card['country'] !== ''): ?><p class="meta mb-0"><span><?= e($card['country']) ?></span><?php if ($card['honours'] > 0): ?><span><?= e((string) $card['honours']) ?> kupa</span><?php endif; ?></p><?php endif; ?>
          <div class="club-card-stats">
            <span><strong><?= e(Format::number($card['appearances'])) ?></strong>Maç</span>
            <span><strong><?= e(Format::number($card['goals'])) ?></strong>Gol</span>
            <span><strong><?= e(Format::number($card['assists'])) ?></strong>Asist</span>
          </div>
          <?php if ($card['summary'] !== ''): ?><p class="card-text"><?= e($card['summary']) ?></p><?php endif; ?>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</section>

<?php if ($cards !== []): ?>
<section class="section section-alt" aria-labelledby="karsilastirma-baslik">
  <div class="container">
    <div class="section-header">
      <h2 class="section-title" id="karsilastirma-baslik">Takım Takım Karşılaştırma</h2>
      <p class="lead">Sütun başlıklarına dokunarak tabloyu sıralayabilirsiniz.</p>
    </div>
    <div class="table-wrap">
      <table class="table table-sortable">
        <thead>
          <tr>
            <th scope="col">Takım</th><th scope="col" class="num">Maç</th><th scope="col" class="num">Gol</th>
            <th scope="col" class="num">Asist</th><th scope="col" class="num">Dakika</th><th scope="col" class="num">Gol/Maç</th>
            <th scope="col" class="num">Penaltı golü</th><th scope="col" class="num">Hat-trick</th><th scope="col" class="num">Galibiyet</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($cards as $card): ?>
          <tr>
            <th scope="row"><a href="<?= e(url($card['url'])) ?>"><?= e($card['name']) ?></a></th>
            <td class="num"><?= e(Format::number($card['appearances'])) ?></td>
            <td class="num"><?= e(Format::number($card['goals'])) ?></td>
            <td class="num"><?= e(Format::number($card['assists'])) ?></td>
            <td class="num"><?= e(Format::number($card['minutes'])) ?></td>
            <td class="num"><?= e($ratio($card)) ?></td>
            <td class="num"><?= e(Format::number($card['penalty_goals'])) ?></td>
            <td class="num"><?= e(Format::number($card['hat_tricks'])) ?></td>
            <td class="num"><?= e(Format::number($card['wins'])) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="meta mt-0" style="margin-top:12px"><span>Kaynak: Transfermarkt maç kayıtları</span><?php if ($lastSync): ?><span>Son güncelleme: <?= e(Format::date($lastSync, 'j F Y H:i')) ?></span><?php endif; ?></p>
  </div>
</section>
<?php endif; ?>

<section class="section" aria-labelledby="altyapi-baslik">
  <div class="container stack">
    <div class="section-header">
      <h2 class="section-title" id="altyapi-baslik">Altyapı Kulüpleri ve Genç Milli Takımlar</h2>
      <p class="lead">Profesyonel kariyerinden önce CF Andorinha (1992–1995), CD Nacional (1995–1997) ve Sporting CP akademisinde (1997–2002) yetişti; Portekiz’in genç milli takımlarında da forma giydi.</p>
    </div>
    <?php if ($youth !== []): ?>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th scope="col">Takım</th><th scope="col" class="num">Maç</th><th scope="col" class="num">Gol</th><th scope="col">İlk maç</th><th scope="col">Son maç</th></tr></thead>
        <tbody>
          <?php foreach ($youth as $row): ?>
          <tr>
            <th scope="row"><span class="cell-club"><?php if ($row['logo_url']): ?><img class="logo-xs" src="<?= e($row['logo_url']) ?>" alt="" width="18" height="18" loading="lazy" decoding="async"><?php endif; ?><?= e($row['name']) ?></span></th>
            <td class="num"><?= e(Format::number($row['games'])) ?></td>
            <td class="num"><?= e(Format::number($row['goals'])) ?></td>
            <td><?= e($row['debut_date'] ? Format::date($row['debut_date'], 'j M Y') : '–') ?></td>
            <td><?= e($row['last_game_date'] ? Format::date($row['last_game_date'], 'j M Y') : '–') ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
    <div class="cluster">
      <a class="btn btn-outline" href="<?= e(url('/kariyer#kulup-kulup')) ?>">Kariyer yolculuğu</a>
      <a class="btn btn-ghost" href="<?= e(url('/istatistikler')) ?>">Tüm istatistikler</a>
      <?php if (setting('shop.enabled', true)): ?><a class="btn btn-ghost" href="<?= e(url('/forma-magazasi')) ?>">Kulüp formaları</a><?php endif; ?>
    </div>
  </div>
</section>
