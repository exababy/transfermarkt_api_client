<?php
/**
 * Public site layout.
 *
 * @var string $content rendered page
 * @var \App\Core\Seo|null $seo page SEO data
 * @var string|null $activeNav header nav key (auto-detected from the path when omitted)
 * @var bool|null $breadcrumbsInLayout false => the page renders partials/breadcrumbs itself
 * @var string|null $extraHead trusted HTML appended to <head> (e.g. preload links)
 * @var string|null $bodyClass
 * @var bool|null $errorPage set by ErrorController: no canonical/hreflang on error responses
 * @var bool|null $noAnalytics true => no GA4/consent banner (private order/payment pages: the URL key is the credential)
 */

use App\Core\Response;
use App\Core\Seo;

$seo = (isset($seo) && $seo instanceof Seo) ? $seo : new Seo(Seo::siteName(), rawTitle: true);
$cspNonce = $cspNonce ?? Response::nonce();
$currentPath = $currentPath ?? '/';
$canonical = $seo->canonicalUrl();
$image = $seo->imageUrl();
$ogTitle = $seo->title !== '' ? $seo->title : Seo::siteName();
$ga4 = (string) setting('analytics.ga4_id', '');
$ga4 = preg_match('/^G-[A-Z0-9]{4,20}$/', $ga4) === 1 && empty($noAnalytics) ? $ga4 : '';
$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP;
// Webmaster tool ownership tags (admin › Ayarlar), printed on the home page only.
$verification = [];
if (empty($errorPage) && rtrim($canonical, '/') === rtrim(url('/'), '/')) {
    foreach (['google-site-verification' => 'seo.google_verification', 'msvalidate.01' => 'seo.bing_verification', 'yandex-verification' => 'seo.yandex_verification'] as $metaName => $settingKey) {
        $code = trim((string) setting($settingKey, ''));
        if ($code !== '' && preg_match('/^[A-Za-z0-9_=+.\/-]{6,120}$/', $code) === 1) {
            $verification[$metaName] = $code;
        }
    }
}
?><!doctype html>
<html lang="tr" dir="ltr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($seo->fullTitle()) ?></title>
<?php if ($seo->description !== ''): ?>
<meta name="description" content="<?= e($seo->description) ?>">
<?php endif; ?>
<meta name="robots" content="<?= e($seo->robots) ?>">
<?php foreach ($verification as $metaName => $code): ?>
<meta name="<?= e($metaName) ?>" content="<?= e($code) ?>">
<?php endforeach; ?>
<?php if (empty($errorPage)): ?>
<link rel="canonical" href="<?= e($canonical) ?>">
<link rel="alternate" hreflang="tr" href="<?= e($canonical) ?>">
<link rel="alternate" hreflang="x-default" href="<?= e($canonical) ?>">
<?php endif; ?>
<?php if ($seo->prev !== null): ?>
<link rel="prev" href="<?= e(url($seo->prev)) ?>">
<?php endif; ?>
<?php if ($seo->next !== null): ?>
<link rel="next" href="<?= e(url($seo->next)) ?>">
<?php endif; ?>
<meta property="og:locale" content="tr_TR">
<meta property="og:site_name" content="<?= e(Seo::siteName()) ?>">
<meta property="og:type" content="<?= e($seo->type) ?>">
<meta property="og:title" content="<?= e($ogTitle) ?>">
<?php if ($seo->description !== ''): ?>
<meta property="og:description" content="<?= e($seo->description) ?>">
<?php endif; ?>
<meta property="og:url" content="<?= e($canonical) ?>">
<meta property="og:image" content="<?= e($image) ?>">
<meta property="og:image:alt" content="<?= e($ogTitle) ?>">
<?php if ($seo->usesDefaultImage()): ?>
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<?php endif; ?>
<?php if ($seo->type === 'article' && $seo->publishedTime !== null): ?>
<meta property="article:published_time" content="<?= e($seo->publishedTime) ?>">
<?php endif; ?>
<?php if ($seo->type === 'article' && $seo->modifiedTime !== null): ?>
<meta property="article:modified_time" content="<?= e($seo->modifiedTime) ?>">
<?php endif; ?>
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= e($ogTitle) ?>">
<?php if ($seo->description !== ''): ?>
<meta name="twitter:description" content="<?= e($seo->description) ?>">
<?php endif; ?>
<meta name="twitter:image" content="<?= e($image) ?>">
<?php foreach ($seo->extraMeta as $meta): ?>
<?php if (isset($meta['property'])): ?>
<meta property="<?= e($meta['property']) ?>" content="<?= e($meta['content'] ?? '') ?>">
<?php elseif (isset($meta['name'])): ?>
<meta name="<?= e($meta['name']) ?>" content="<?= e($meta['content'] ?? '') ?>">
<?php endif; ?>
<?php endforeach; ?>
<meta name="theme-color" content="#0a0a0c">
<meta name="color-scheme" content="dark">
<link rel="icon" href="<?= e(\App\Core\App::urlPrefix()) ?>/favicon.ico" sizes="32x32">
<link rel="icon" href="<?= e(asset('img/favicon.svg')) ?>" type="image/svg+xml">
<link rel="apple-touch-icon" href="<?= e(asset('img/apple-touch-icon.png')) ?>">
<link rel="manifest" href="<?= e(\App\Core\App::urlPrefix()) ?>/site.webmanifest">
<link rel="alternate" type="application/rss+xml" title="<?= e(Seo::siteName()) ?> Haberler" href="<?= e(url('/rss.xml')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
<script nonce="<?= e($cspNonce) ?>">document.documentElement.classList.add('js');</script>
<script src="<?= e(asset('js/app.js')) ?>" defer></script>
<?= $extraHead ?? '' ?>
<?php foreach ($seo->structuredData() as $schema): ?>
<script type="application/ld+json" nonce="<?= e($cspNonce) ?>"><?= json_encode($schema, $jsonFlags) ?></script>
<?php endforeach; ?>
</head>
<body<?= !empty($bodyClass) ? ' class="' . e($bodyClass) . '"' : '' ?>>
<a class="skip-link" href="#main">İçeriğe geç</a>
<?= partial('partials/header', ['activeNav' => $activeNav ?? null, 'currentPath' => $currentPath]) ?>
<main id="main" class="site-main" tabindex="-1">
<?php if (($breadcrumbsInLayout ?? true) && count($seo->breadcrumbTrail()) > 1): ?>
<div class="container"><?= partial('partials/breadcrumbs', ['items' => $seo->breadcrumbTrail()]) ?></div>
<?php endif; ?>
<?= $content ?>
</main>
<?= partial('partials/footer', ['ga4' => $ga4]) ?>
<?php if ($ga4 !== ''): ?>
<div class="cookie-consent" id="cookie-consent" role="region" aria-label="Çerez tercihleri" data-ga4="<?= e($ga4) ?>" hidden>
  <p>Ziyaret istatistiklerini ölçmek için, onay vermeniz hâlinde Google Analytics çerezleri kullanıyoruz. Ayrıntılar için <a href="<?= e(url('/gizlilik-politikasi')) ?>">Gizlilik Politikası ve KVKK Aydınlatma Metni</a>’ni inceleyebilirsiniz.</p>
  <div class="cookie-actions">
    <button type="button" class="btn btn-outline btn-sm" data-consent="deny">Reddet</button>
    <button type="button" class="btn btn-gold btn-sm" data-consent="accept">Kabul Et</button>
  </div>
</div>
<?php endif; ?>
</body>
</html>
