<?php
/**
 * 404 page (rendered with status 404 and robots noindex by ErrorController).
 */
?>
<section class="section error-page">
  <div class="container container-narrow">
    <p class="error-code" aria-hidden="true">404</p>
    <h1>Aradığınız sayfa bulunamadı</h1>
    <p class="lead">Sayfa taşınmış, kaldırılmış ya da adres hatalı yazılmış olabilir. Aşağıdaki bağlantılarla devam edebilir veya sitede arama yapabilirsiniz.</p>
    <form class="search-form" action="<?= e(url('/arama')) ?>" method="get" role="search">
      <label class="visually-hidden" for="q-404">Sitede ara</label>
      <input class="input" id="q-404" type="search" name="q" placeholder="Ronaldo, gol rekoru, Al-Nassr…" autocomplete="off">
      <button class="btn btn-gold" type="submit">Ara</button>
    </form>
    <ul class="error-links">
      <li><a class="tag" href="<?= e(url('/')) ?>">Ana Sayfa</a></li>
      <li><a class="tag" href="<?= e(url('/hayati')) ?>">Hayatı</a></li>
      <li><a class="tag" href="<?= e(url('/istatistikler')) ?>">İstatistikler</a></li>
      <li><a class="tag" href="<?= e(url('/haberler')) ?>">Haberler</a></li>
      <li><a class="tag" href="<?= e(url('/videolar')) ?>">Videolar</a></li>
    </ul>
  </div>
</section>
