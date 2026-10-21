<?php
/**
 * /hayati — biography (content/biography.php; HTML strings are trusted editorial content).
 *
 * @var string $title
 * @var string $lead trusted HTML
 * @var list<array{label: string, value: string}> $facts
 * @var list<array{id: string, title: string, html: string}> $sections
 * @var list<array{id: string, title: string}> $toc
 * @var list<array{year: string, text: string}> $timeline
 * @var list<array{q: string, a: string}> $faq
 * @var list<array{title: string, url: string}> $sources
 * @var string|null $reviewed
 * @var int $readingMinutes
 * @var string|null $cspNonce
 */

use App\Support\Format;
?>
<header class="page-header">
  <div class="container">
    <span class="eyebrow">Biyografi</span>
    <h1><?= e($title) ?></h1>
    <p class="lead">Madeira’daki çocukluğundan dünyanın en çok gol atan futbolcularından biri olmasına uzanan yolculuk: ailesi, kariyeri, özel hayatı, rekorları ve CR7 hakkında merak edilen her şey.</p>
    <p class="meta page-meta">
      <?php if ($reviewed !== null): ?><span>Son güncelleme: <time datetime="<?= e(substr($reviewed, 0, 10)) ?>"><?= e(Format::date($reviewed, 'j F Y')) ?></time></span><?php endif; ?>
      <span>Yaklaşık <?= (int) $readingMinutes ?> dakikalık okuma</span>
      <?php if ($sources !== []): ?><span><a href="#kaynaklar"><?= count($sources) ?> kaynak</a></span><?php endif; ?>
    </p>
  </div>
</header>

<section class="section-sm" aria-label="Giriş ve hızlı bilgiler">
  <div class="container life-intro">
    <div class="prose"><?= $lead ?></div>
    <?php if ($facts !== []): ?>
    <aside class="life-facts<?= count($facts) % 2 === 0 ? ' is-even' : '' ?>" aria-labelledby="hizli-bilgiler">
      <h2 class="life-aside-title" id="hizli-bilgiler">Hızlı bilgiler</h2>
      <dl class="facts">
        <?php foreach ($facts as $fact): ?>
        <dt><?= e($fact['label']) ?></dt>
        <dd><?= e($fact['value']) ?></dd>
        <?php endforeach; ?>
      </dl>
    </aside>
    <?php endif; ?>
  </div>
</section>

<div class="section-sm">
  <div class="container layout-toc">
    <aside aria-label="İçindekiler">
      <details class="toc life-toc" id="life-toc">
        <summary>İçindekiler</summary>
        <ol>
          <?php foreach ($toc as $item): ?>
          <li><a href="#<?= e($item['id']) ?>"><?= e($item['title']) ?></a></li>
          <?php endforeach; ?>
        </ol>
      </details>
    </aside>
    <article class="life-article" aria-label="<?= e($title) ?>">
      <?php foreach ($sections as $section): ?>
      <section class="prose life-section" id="<?= e($section['id']) ?>" aria-labelledby="<?= e($section['id']) ?>-baslik">
        <h2 id="<?= e($section['id']) ?>-baslik"><?= e($section['title']) ?></h2>
        <?= $section['html'] ?>
      </section>
      <?php endforeach; ?>

      <?php if ($timeline !== []): ?>
      <section class="life-extra" id="zaman-cizelgesi" aria-labelledby="zaman-cizelgesi-baslik">
        <h2 class="section-title" id="zaman-cizelgesi-baslik">Yıl Yıl Ronaldo’nun Hayatı</h2>
        <ol class="timeline">
          <?php foreach ($timeline as $item): ?>
          <li class="timeline-item">
            <span class="timeline-year"><?= e((string) $item['year']) ?></span>
            <p><?= e((string) $item['text']) ?></p>
          </li>
          <?php endforeach; ?>
        </ol>
      </section>
      <?php endif; ?>

      <?php if ($faq !== []): ?>
      <section class="life-extra" id="sss" aria-labelledby="sss-baslik">
        <h2 class="section-title" id="sss-baslik">Sıkça Sorulan Sorular</h2>
        <div class="faq">
          <?php foreach ($faq as $i => $item): ?>
          <details<?= $i === 0 ? ' open' : '' ?>>
            <summary><?= e($item['q']) ?></summary>
            <p><?= e($item['a']) ?></p>
          </details>
          <?php endforeach; ?>
        </div>
      </section>
      <?php endif; ?>

      <section class="life-extra" id="kaynaklar" aria-labelledby="kaynaklar-baslik">
        <h2 class="section-title" id="kaynaklar-baslik">Kaynaklar</h2>
        <p class="text-muted">Bu sayfadaki bilgiler aşağıdaki kaynaklardan derlenmiş ve <?php if ($reviewed !== null): ?><time datetime="<?= e(substr($reviewed, 0, 10)) ?>"><?= e(Format::date($reviewed, 'j F Y')) ?></time> tarihinde<?php else: ?>düzenli olarak<?php endif; ?> gözden geçirilmiştir. Güncel istatistikler için <a href="<?= e(url('/istatistikler')) ?>">İstatistikler</a> sayfasına bakabilirsiniz.</p>
        <?php if ($sources !== []): ?>
        <ol class="sources-list">
          <?php foreach ($sources as $source): ?>
          <li><a href="<?= e($source['url']) ?>" rel="nofollow noopener" target="_blank"><?= e($source['title'] ?? $source['url']) ?></a></li>
          <?php endforeach; ?>
        </ol>
        <?php endif; ?>
        <nav class="life-related" aria-label="İlgili sayfalar">
          <a class="tag" href="<?= e(url('/kariyer')) ?>">Kariyeri</a>
          <a class="tag" href="<?= e(url('/takimlar')) ?>">Oynadığı takımlar</a>
          <a class="tag" href="<?= e(url('/istatistikler')) ?>">İstatistikleri</a>
          <a class="tag" href="<?= e(url('/kupalar-ve-oduller')) ?>">Kupaları ve ödülleri</a>
          <a class="tag" href="<?= e(url('/haberler')) ?>">Son haberler</a>
        </nav>
      </section>
    </article>
  </div>
</div>
<script nonce="<?= e($cspNonce ?? \App\Core\Response::nonce()) ?>">
(function () {
  var toc = document.getElementById('life-toc');
  if (!toc || !window.matchMedia) return;
  var desktop = window.matchMedia('(min-width: 960px)');
  if (desktop.matches) toc.open = true;
  toc.addEventListener('click', function (e) {
    if (!desktop.matches && e.target.closest && e.target.closest('a[href^="#"]')) toc.open = false;
  });
})();
</script>
