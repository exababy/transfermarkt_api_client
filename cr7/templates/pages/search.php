<?php
/**
 * /arama?q= — search results (noindex).
 *
 * @var string $q
 * @var list<string> $terms folded terms (for highlighting)
 * @var bool $tooShort
 * @var list<array{title: string, url: string, text: string, type: string, snippet: string}> $sections
 * @var list<array<string, mixed>> $posts NewsRepository::search()
 * @var list<array<string, mixed>> $videos VideoRepository::search()
 * @var int $total
 */

use App\Controllers\SearchController;
use App\Support\Format;

$hl = static fn (string $text): string => SearchController::highlight($text, $terms);
?>
<header class="page-header search-page">
  <div class="container">
    <span class="eyebrow">Arama</span>
    <h1><?= $q !== '' ? '“' . e($q) . '” için sonuçlar' : 'Sitede ara' ?></h1>
    <form class="search-form" action="<?= e(url('/arama')) ?>" method="get" role="search">
      <label class="visually-hidden" for="q-search">Aranacak kelime</label>
      <input class="input" id="q-search" type="search" name="q" value="<?= e($q) ?>" placeholder="Ronaldo, Real Madrid, Ballon d’Or…" minlength="<?= SearchController::MIN_LENGTH ?>" maxlength="<?= SearchController::MAX_LENGTH ?>" autocomplete="off"<?= $q === '' ? ' autofocus' : '' ?>>
      <button class="btn btn-gold" type="submit">Ara</button>
    </form>
    <?php if ($tooShort): ?>
    <p class="search-summary" role="status">Arama yapmak için en az <?= SearchController::MIN_LENGTH ?> karakter yazın.</p>
    <?php elseif ($q !== ''): ?>
    <p class="search-summary" role="status"><?= $total > 0 ? '<strong>' . (int) $total . '</strong> sonuç bulundu.' : 'Sonuç bulunamadı.' ?></p>
    <?php endif; ?>
  </div>
</header>

<section class="section-sm">
  <div class="container">
    <?php if ($q === '' || $tooShort || $total === 0): ?>
    <div class="empty-state">
      <?php if ($q !== '' && !$tooShort): ?>
      <h2>“<?= e($q) ?>” ile eşleşen içerik bulunamadı</h2>
      <?php else: ?>
      <h2>Ne aramak istersiniz?</h2>
      <?php endif; ?>
      <p class="lead">Haberler, videolar, Ronaldo’nun hayatı, kariyeri, kupaları ve rekorları arasında arama yapabilirsiniz. Örneğin: <a href="<?= e(url('/arama?q=' . rawurlencode('Ballon d’Or'))) ?>">Ballon d’Or</a>, <a href="<?= e(url('/arama?q=Madeira')) ?>">Madeira</a>, <a href="<?= e(url('/arama?q=' . rawurlencode('Şampiyonlar Ligi'))) ?>">Şampiyonlar Ligi</a>.</p>
      <div class="cluster center">
        <a class="tag" href="<?= e(url('/hayati')) ?>">Hayatı</a>
        <a class="tag" href="<?= e(url('/istatistikler')) ?>">İstatistikler</a>
        <a class="tag" href="<?= e(url('/kupalar-ve-oduller')) ?>">Kupalar ve Ödüller</a>
        <a class="tag" href="<?= e(url('/haberler')) ?>">Haberler</a>
        <a class="tag" href="<?= e(url('/videolar')) ?>">Videolar</a>
      </div>
    </div>
    <?php else: ?>

    <?php if ($sections !== []): ?>
    <section class="search-group" aria-labelledby="sonuc-bolumler">
      <h2 class="section-title" id="sonuc-bolumler">Sayfalar ve bölümler <span class="badge-muted badge"><?= count($sections) ?></span></h2>
      <ol class="search-results">
        <?php foreach ($sections as $hit): ?>
        <li class="search-hit">
          <div>
            <p class="meta mt-0"><span><?= e($hit['type']) ?></span></p>
            <h3><a href="<?= e(url($hit['url'])) ?>"><?= $hl($hit['title']) ?></a></h3>
            <?php if ($hit['snippet'] !== ''): ?><p><?= $hl($hit['snippet']) ?></p><?php endif; ?>
          </div>
        </li>
        <?php endforeach; ?>
      </ol>
    </section>
    <?php endif; ?>

    <?php if ($posts !== []): ?>
    <section class="search-group" aria-labelledby="sonuc-haberler">
      <h2 class="section-title" id="sonuc-haberler">Haberler <span class="badge-muted badge"><?= count($posts) ?></span></h2>
      <ol class="search-results">
        <?php foreach ($posts as $post): ?>
        <?php $href = url((string) ($post['url'] ?? ('/haber/' . ($post['slug'] ?? '')))); ?>
        <li class="search-hit<?= !empty($post['image_url']) ? ' has-thumb' : '' ?>">
          <?php if (!empty($post['image_url'])): ?><img src="<?= e($post['image_url']) ?>" alt="" width="160" height="90" loading="lazy" decoding="async"><?php endif; ?>
          <div>
            <p class="meta mt-0">
              <?php if (!empty($post['published_at'])): ?><time datetime="<?= e(Format::iso((string) $post['published_at'])) ?>"><?= e(Format::date((string) $post['published_at'], 'j F Y')) ?></time><?php endif; ?>
              <?php if (!empty($post['source_name'])): ?><span><?= e((string) $post['source_name']) ?></span><?php endif; ?>
            </p>
            <h3><a href="<?= e($href) ?>"><?= $hl((string) $post['title']) ?></a></h3>
            <?php if (!empty($post['summary'])): ?><p><?= $hl(SearchController::snippet((string) $post['summary'], $terms, 180)) ?></p><?php endif; ?>
          </div>
        </li>
        <?php endforeach; ?>
      </ol>
    </section>
    <?php endif; ?>

    <?php if ($videos !== []): ?>
    <section class="search-group" aria-labelledby="sonuc-videolar">
      <h2 class="section-title" id="sonuc-videolar">Videolar <span class="badge-muted badge"><?= count($videos) ?></span></h2>
      <ol class="search-results">
        <?php foreach ($videos as $video): ?>
        <?php $href = url((string) ($video['url'] ?? ('/video/' . ($video['slug'] ?? '')))); ?>
        <li class="search-hit<?= !empty($video['thumbnail_url']) ? ' has-thumb' : '' ?>">
          <?php if (!empty($video['thumbnail_url'])): ?><img src="<?= e((string) $video['thumbnail_url']) ?>" alt="" width="160" height="90" loading="lazy" decoding="async"><?php endif; ?>
          <div>
            <p class="meta mt-0">
              <?php if (!empty($video['channel_title'])): ?><span><?= e((string) $video['channel_title']) ?></span><?php endif; ?>
              <?php if (!empty($video['published_at'])): ?><time datetime="<?= e(Format::iso((string) $video['published_at'])) ?>"><?= e(Format::date((string) $video['published_at'], 'j F Y')) ?></time><?php endif; ?>
            </p>
            <h3><a href="<?= e($href) ?>"><?= $hl((string) $video['title']) ?></a></h3>
            <?php if (!empty($video['description'])): ?><p><?= $hl(SearchController::snippet((string) $video['description'], $terms, 160)) ?></p><?php endif; ?>
          </div>
        </li>
        <?php endforeach; ?>
      </ol>
    </section>
    <?php endif; ?>

    <?php endif; ?>
  </div>
</section>
