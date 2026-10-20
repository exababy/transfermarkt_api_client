<?php
/**
 * Page-specific styles of the site module (home, hayati, kupalar, arama, static pages),
 * injected into <head> through the layout's $extraHead. app.css (design system) stays the
 * single shared stylesheet; these rules only compose its tokens/classes.
 *
 * @var string $page home|life|honours|search|static
 */

$page = $page ?? 'static';
?>
<style>
.site-mark{padding:0 .15em;border-radius:3px;background:rgba(212,175,55,.28);color:#fff}
.page-meta{margin-top:14px}
.sources-list{margin:0;padding-left:1.3em;color:var(--text-muted);font-size:.9rem}
.sources-list li{margin:.3em 0;break-inside:avoid}
.sources-list a{color:var(--text-muted)}
.sources-list a:hover{color:var(--gold-soft)}
@media (min-width:960px){.sources-list{columns:2;column-gap:40px}}
<?php if ($page === 'home'): ?>
.home-hero .hero-text h1{margin-bottom:.25em}
.home-kicker{margin:0 0 .4em;color:var(--gold-soft);font-size:clamp(1.05rem,1rem + .5vw,1.35rem);font-weight:750}
.home-hero-meta{display:flex;flex-wrap:wrap;gap:8px;margin:-6px 0 26px;padding:0;list-style:none}
.home-hero-meta li{padding:4px 12px;border:1px solid var(--border);border-radius:999px;background:rgba(20,20,24,.7);color:var(--text);font-size:.86rem;font-weight:650}
.home-portrait{max-width:420px;margin:0}
.home-portrait.is-tm{max-width:300px;border-color:rgba(212,175,55,.45);box-shadow:0 0 0 6px rgba(212,175,55,.08),var(--shadow)}
.home-portrait.is-empty{display:grid;place-items:center;max-width:300px}
.home-portrait.is-empty span{color:rgba(212,175,55,.45);font-size:5rem;font-weight:900;font-style:italic;letter-spacing:-.05em}
.home-credit{position:absolute;right:10px;bottom:8px;left:10px;z-index:2;color:#d6d6de;font-size:.72rem;text-align:right}
@media (max-width:959.98px){.home-hero .hero-media{order:-1}.home-portrait.is-tm,.home-portrait.is-empty{max-width:220px;justify-self:start}}
.stat-tiles.home-tiles{grid-template-columns:repeat(2,minmax(0,1fr))}
@media (min-width:640px){.stat-tiles.home-tiles{grid-template-columns:repeat(4,minmax(0,1fr))}}
.home-source{margin:14px 0 0}
.home-about{display:grid;gap:clamp(24px,4vw,48px);align-items:start}
.home-about .prose{margin-bottom:22px}
.home-aside-title{margin:0 0 12px;color:var(--gold);font-size:.78rem;letter-spacing:.14em;text-transform:uppercase}
@media (min-width:960px){.home-about{grid-template-columns:minmax(0,1.25fr) minmax(0,.9fr)}}
.home-split{display:grid;gap:clamp(36px,5vw,56px)}
@media (min-width:1100px){.home-split{grid-template-columns:minmax(0,1.2fr) minmax(0,.8fr)}}
.match-list{display:grid;gap:10px}
.match{display:grid;grid-template-columns:minmax(0,1fr) auto;grid-template-areas:"when result" "teams teams";gap:8px 16px;align-items:center;padding:14px 16px;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--bg-card)}
.match-when{grid-area:when;display:flex;flex-direction:column;color:var(--text-muted);font-size:.82rem;line-height:1.35}
.match-when time{color:var(--text);font-weight:750}
.match-teams{grid-area:teams;display:flex;flex-wrap:wrap;align-items:center;gap:8px 12px;font-weight:650}
.match-teams .logo-sm{padding:2px;border-radius:6px;background:#ececf1}
.match-score{padding:2px 10px;border:1px solid var(--border);border-radius:8px;background:var(--bg-elev);font-variant-numeric:tabular-nums;white-space:nowrap}
.match-result{grid-area:result;display:flex;align-items:center;justify-content:flex-end;gap:10px}
.match-contrib{color:var(--gold-soft);font-size:.82rem;font-weight:700;white-space:nowrap}
@media (min-width:640px){.match{grid-template-columns:130px minmax(0,1fr) auto;grid-template-areas:"when teams result"}}
.club-strip{display:grid;gap:10px}
@media (min-width:640px) and (max-width:1099.98px){.club-strip{grid-template-columns:repeat(2,minmax(0,1fr))}}
.club-chip{display:flex;align-items:center;gap:14px;height:100%;padding:10px 14px;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--bg-card);color:var(--text);text-decoration:none;transition:border-color .2s}
.club-chip:hover{border-color:var(--gold);color:var(--text)}
.club-chip .logo-md{padding:5px;border-radius:10px;background:#ececf1}
.club-chip-initial{display:grid;place-items:center;flex:none;width:44px;height:44px;border-radius:10px;background:rgba(212,175,55,.14);color:var(--gold);font-weight:900}
.club-chip-text{display:flex;flex-direction:column;min-width:0;line-height:1.35}
.club-chip-text span{color:var(--text-muted);font-size:.84rem}
.honour-grid{display:grid;gap:14px}
@media (min-width:640px){.honour-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media (min-width:960px){.honour-grid{grid-template-columns:repeat(3,minmax(0,1fr))}}
.honour-card{display:flex;align-items:center;gap:16px;padding:18px;border:1px solid var(--border);border-radius:var(--radius);background:var(--bg-card)}
.honour-count{flex:none;min-width:70px;color:var(--gold);font-size:2.5rem;font-weight:900;line-height:1;letter-spacing:-.03em;font-variant-numeric:tabular-nums}
.honour-count span{font-size:.55em;margin-left:2px;opacity:.75}
.honour-text{display:flex;flex-direction:column;gap:3px;line-height:1.35}
.honour-text span{color:var(--text-muted);font-size:.86rem}
.shop-teaser{display:grid;gap:28px;align-items:center;padding:clamp(22px,4vw,44px);border:1px solid rgba(212,175,55,.35);border-radius:calc(var(--radius) * 1.4);background:radial-gradient(700px 300px at 100% 0,rgba(212,175,55,.16),transparent 65%),var(--bg-elev)}
.shop-teaser .lead{margin-bottom:16px}
.shop-teaser-items{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
.shop-teaser-items a{display:flex;flex-direction:column;gap:6px;height:100%;padding:10px;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--bg-card);color:var(--text);font-size:.86rem;font-weight:650;line-height:1.35;text-decoration:none}
.shop-teaser-items a:hover{border-color:var(--gold)}
.shop-teaser-items img{width:100%;height:auto;aspect-ratio:1;object-fit:contain}
@media (min-width:960px){.shop-teaser{grid-template-columns:minmax(0,1fr) minmax(0,1fr)}}
.shop-teaser-crests{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;max-width:420px;justify-self:center;width:100%}
.shop-teaser-crests li{display:grid;place-items:center;aspect-ratio:1;border:1px solid var(--border);border-radius:var(--radius);background:radial-gradient(circle at 50% 35%,#2a2a33,#141418 75%)}
.shop-teaser-crests img{width:56%;height:auto;filter:drop-shadow(0 0 1px rgba(255,255,255,.65)) drop-shadow(0 6px 14px rgba(0,0,0,.5))}
.home-faq-more{margin:18px 0 0}
@media (max-width:639.98px){.home-hero-news{display:none}}
<?php elseif ($page === 'life'): ?>
.life-intro{display:grid;gap:clamp(24px,4vw,40px)}
.life-intro .prose{font-size:1.1rem}
.life-facts .facts{grid-template-columns:minmax(100px,36%) minmax(0,1fr);font-size:.92rem}
.life-facts .facts dd{font-weight:600}
@media (min-width:960px){
.life-facts .facts{grid-template-columns:170px minmax(0,1fr) 170px minmax(0,1fr)}
.life-facts .facts dt:nth-of-type(even){border-left:1px solid var(--border)}
.life-facts.is-even .facts dt:nth-last-of-type(2),.life-facts.is-even .facts dd:nth-last-of-type(2){border-bottom:0}
}
.life-aside-title{margin:0 0 12px;color:var(--gold);font-size:.78rem;letter-spacing:.14em;text-transform:uppercase}
.life-toc > summary{display:flex;align-items:center;justify-content:space-between;gap:8px}
.life-toc > summary::after{content:"▾";color:var(--gold);font-size:1rem;transition:transform .2s}
.life-toc[open] > summary::after{transform:rotate(180deg)}
.life-toc ol{counter-reset:toc}
.life-section{scroll-margin-top:calc(var(--header-h) + 16px)}
.life-section + .life-section{margin-top:2.6rem;padding-top:2.4rem;border-top:1px solid var(--border)}
.life-section > h2{margin-top:0}
.life-extra{margin-top:3rem;padding-top:2.4rem;border-top:1px solid var(--border);scroll-margin-top:calc(var(--header-h) + 16px)}
.life-extra .faq{max-width:72ch}
.life-related{display:flex;flex-wrap:wrap;gap:8px;margin-top:18px}
<?php elseif ($page === 'honours'): ?>
.stat-tiles.honours-tiles{grid-template-columns:repeat(2,minmax(0,1fr))}
@media (min-width:640px){.stat-tiles.honours-tiles{grid-template-columns:repeat(4,minmax(0,1fr))}}
.honours-nav{margin-top:20px}
.honours-clubs{display:grid;gap:var(--gap)}
@media (min-width:960px){.honours-clubs{display:block;columns:2;column-gap:var(--gap)}.honours-club{break-inside:avoid;margin-bottom:var(--gap)}}
.honours-club{padding:0}
.honours-club:hover{transform:none}
.honours-club-head{display:flex;align-items:center;gap:14px;padding:16px 18px;border-bottom:1px solid var(--border);background:var(--bg-elev)}
.honours-club-head .logo-md{padding:5px;border-radius:10px;background:#ececf1}
.honours-club-head h3{margin:0;font-size:1.15rem}
.honours-club-head p{margin:0;color:var(--text-muted);font-size:.85rem}
.honours-club-head .honours-total{margin-left:auto;text-align:right}
.honours-club-head .honours-total strong{display:block;color:var(--gold);font-size:1.6rem;line-height:1;font-variant-numeric:tabular-nums}
.club-initial{display:grid;place-items:center;flex:none;width:44px;height:44px;border-radius:10px;background:rgba(212,175,55,.14);color:var(--gold);font-weight:900}
.trophy-list{margin:0;padding:6px 18px 12px;list-style:none}
.trophy{padding:12px 0;border-bottom:1px solid var(--border)}
.trophy:last-child{border-bottom:0}
.trophy-row{display:flex;align-items:baseline;justify-content:space-between;gap:12px}
.trophy-row h4{margin:0;font-size:1rem}
.trophy-count{flex:none;color:var(--gold-soft);font-weight:850;font-variant-numeric:tabular-nums}
.trophy .tags{gap:6px;margin-top:8px}
.trophy .tag{padding:2px 9px;font-size:.78rem}
.trophy-note{margin:8px 0 0;color:var(--text-muted);font-size:.86rem;line-height:1.5}
.awards-table td.wrap{min-width:200px}
.awards-table .award-note{display:block;font-weight:400;margin-top:4px;color:var(--text-muted);font-size:.82rem;line-height:1.45;white-space:normal}
.awards-table td:first-child{white-space:normal;min-width:220px;font-weight:650}
@media (max-width:639.98px){
.awards-table thead{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)}
.awards-table tbody tr{display:grid;grid-template-columns:minmax(0,1fr) auto;border-bottom:1px solid var(--border)}
.awards-table tbody tr:last-child{border-bottom:0}
.awards-table td{display:block;border-bottom:0;white-space:normal}
.awards-table td:first-child{min-width:0;padding-bottom:4px}
.awards-table td.num{grid-column:2;grid-row:1;color:var(--gold-soft);font-size:1.05rem}
.awards-table td.wrap{grid-column:1/-1;min-width:0;padding-top:0;color:var(--text-muted);font-size:.86rem}
}
.records{display:grid;gap:14px;margin:0;padding:0;list-style:none;counter-reset:rec}
@media (min-width:960px){.records{grid-template-columns:repeat(2,minmax(0,1fr))}}
.record{position:relative;padding:18px 18px 18px 58px;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--bg-card);counter-increment:rec}
.record::before{content:counter(rec);position:absolute;top:16px;left:16px;display:grid;place-items:center;width:30px;height:30px;border-radius:50%;background:rgba(212,175,55,.14);color:var(--gold);font-size:.85rem;font-weight:850}
.record h3{margin:0 0 6px;font-size:1.02rem;line-height:1.4}
.record p{margin:0;color:var(--text-muted);font-size:.92rem}
<?php elseif ($page === 'search'): ?>
.search-page .search-form{max-width:680px;margin-top:18px}
.search-summary{margin:18px 0 0;color:var(--text-muted)}
.search-group + .search-group{margin-top:clamp(32px,5vw,52px)}
.search-results{display:grid;gap:12px;margin:0;padding:0;list-style:none}
.search-hit{display:grid;gap:14px;align-items:start;padding:14px 16px;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--bg-card)}
.search-hit.has-thumb{grid-template-columns:96px minmax(0,1fr)}
.search-hit img{width:96px;height:54px;border-radius:7px;object-fit:cover;background:#111}
.search-hit h3{margin:0 0 4px;font-size:1.04rem;line-height:1.4}
.search-hit h3 a{color:var(--text);text-decoration:none}
.search-hit h3 a:hover{color:var(--gold-soft)}
.search-hit p{margin:4px 0 0;color:var(--text-muted);font-size:.9rem}
@media (min-width:640px){.search-hit.has-thumb{grid-template-columns:160px minmax(0,1fr)}.search-hit img{width:160px;height:90px}}
.search-tips{margin:0;padding-left:1.2em;color:var(--text-muted)}
<?php else: ?>
.static-page .prose{margin-inline:auto}
.static-page .notice + .prose{margin-top:2.2rem}
.static-page .toc{position:static;max-height:none;margin:0 0 2rem}
.static-page .toc ol{columns:2 220px;column-gap:16px}
.static-page .prose h2:first-child{margin-top:0}
.contact-grid{display:grid;gap:14px;margin:0 0 1.6em}
@media (min-width:640px){.contact-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
.contact-card{padding:18px;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--bg-card)}
.contact-card h2{margin:0 0 6px !important;font-size:1.05rem !important}
.contact-card p{margin:0 0 10px;color:var(--text-muted);font-size:.93rem}
.contact-card .btn{white-space:normal}
<?php endif; ?>
</style>
