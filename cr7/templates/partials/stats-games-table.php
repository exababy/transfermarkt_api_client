<?php
/**
 * Match log table (rows of StatsRepository::games()).
 *
 * @var list<array<string, mixed>> $games
 * @var string|null $caption
 * @var bool|null $showClub show "Takım" column (default true)
 * @var bool|null $showStatus show participation column (when non-played entries are listed)
 * @var bool|null $showSeason show season column (default false)
 */

use App\Repositories\ClubRepository;
use App\Repositories\StatsRepository;
use App\Support\Format;

$showClub = $showClub ?? true;
$showStatus = $showStatus ?? false;
$showSeason = $showSeason ?? false;
?>
<div class="table-wrap">
  <table class="table table-compact">
    <?php if (!empty($caption)): ?><caption><?= e($caption) ?></caption><?php endif; ?>
    <thead>
      <tr>
        <th scope="col">Tarih</th>
        <?php if ($showSeason): ?><th scope="col">Sezon</th><?php endif; ?>
        <th scope="col">Turnuva</th>
        <?php if ($showClub): ?><th scope="col">Takım</th><?php endif; ?>
        <th scope="col">Rakip</th>
        <th scope="col">Saha</th>
        <th scope="col" class="num">Skor</th>
        <th scope="col">Sonuç</th>
        <?php if ($showStatus): ?><th scope="col">Durum</th><?php endif; ?>
        <th scope="col" class="num">Dk.</th>
        <th scope="col" class="num">Gol</th>
        <th scope="col" class="num">Asist</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($games as $g): ?>
      <?php
        $result = StatsRepository::RESULT_LABELS[$g['result'] ?? ''] ?? null;
        $played = ($g['participation'] ?? '') === 'played';
        $clubLogo = ClubRepository::logo($g['club_logo'] ?? null, 'xs');
        $oppLogo = ClubRepository::logo($g['opponent_logo'] ?? null, 'xs');
      ?>
      <tr>
        <td><time datetime="<?= e(Format::iso($g['date_utc'])) ?>"><?= e(Format::date($g['date_utc'], 'j M Y')) ?></time></td>
        <?php if ($showSeason): ?><td><?= e($g['season_label']) ?></td><?php endif; ?>
        <td class="wrap"><?= e($g['competition_name']) ?></td>
        <?php if ($showClub): ?>
        <td><span class="cell-club"><?php if ($clubLogo): ?><img class="logo-xs" src="<?= e($clubLogo) ?>" alt="" width="18" height="18" loading="lazy" decoding="async"><?php endif; ?><?= e($g['club_name']) ?></span></td>
        <?php endif; ?>
        <td><span class="cell-club"><?php if ($oppLogo): ?><img class="logo-xs" src="<?= e($oppLogo) ?>" alt="" width="18" height="18" loading="lazy" decoding="async"><?php endif; ?><?= e($g['opponent_name']) ?></span></td>
        <td><?= e(StatsRepository::VENUE_LABELS[$g['venue'] ?? ''] ?? '–') ?></td>
        <td class="num"><?= $g['goals_for'] !== null ? e($g['goals_for'] . '–' . $g['goals_against']) : '–' ?></td>
        <td><?php if ($result): ?><span class="result-<?= e($g['result']) ?>" title="<?= e($result[1]) ?>"><span aria-hidden="true"><?= e($result[0]) ?></span><span class="visually-hidden"><?= e($result[1]) ?></span></span><?php else: ?>–<?php endif; ?></td>
        <?php if ($showStatus): ?><td><?= e(StatsRepository::PARTICIPATION_LABELS[$g['participation'] ?? ''] ?? (string) ($g['participation'] ?? '–')) ?></td><?php endif; ?>
        <td class="num"><?= $played ? e((string) $g['minutes']) : '–' ?></td>
        <td class="num"><?= $played ? ($g['goals'] > 0 ? '<strong>' . e((string) $g['goals']) . '</strong>' : '0') : '–' ?></td>
        <td class="num"><?= $played ? e((string) $g['assists']) : '–' ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
