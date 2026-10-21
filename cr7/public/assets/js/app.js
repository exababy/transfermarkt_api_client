/* CR7.com.tr — progressive enhancements (no dependencies). */
(function () {
  'use strict';

  var doc = document;
  var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  function each(selector, fn, root) {
    Array.prototype.forEach.call((root || doc).querySelectorAll(selector), fn);
  }

  /* ---------- Mobile navigation ---------- */
  function initNav() {
    var toggle = doc.querySelector('.nav-toggle');
    var nav = doc.getElementById('site-nav');
    if (!toggle || !nav) return;
    var header = nav.closest('.site-header') || nav.parentNode;
    function setOpen(open) {
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      toggle.setAttribute('aria-label', open ? 'Menüyü kapat' : 'Menüyü aç');
      nav.classList.toggle('is-open', open);
      doc.documentElement.classList.toggle('nav-open', open); // backdrop + page scroll lock (app.css)
    }
    toggle.addEventListener('click', function () {
      setOpen(toggle.getAttribute('aria-expanded') !== 'true');
    });
    doc.addEventListener('keydown', function (e) {
      if (!nav.classList.contains('is-open')) return;
      if (e.key === 'Escape') {
        setOpen(false);
        toggle.focus();
        return;
      }
      // The nav precedes the toggle in the DOM (desktop order), so keep Tab cycling between the
      // toggle and the open panel instead of moving to page content hidden under the overlay.
      if (e.key !== 'Tab') return;
      var items = nav.querySelectorAll('a[href]');
      if (!items.length) return;
      var first = items[0];
      var last = items[items.length - 1];
      var active = doc.activeElement;
      var target = null;
      if (!e.shiftKey && active === toggle) target = first;
      else if (!e.shiftKey && active === last) target = toggle;
      else if (e.shiftKey && active === first) target = toggle;
      if (target) {
        e.preventDefault();
        target.focus();
      }
    });
    doc.addEventListener('focusin', function (e) {
      if (nav.classList.contains('is-open') && !header.contains(e.target)) setOpen(false);
    });
    doc.addEventListener('click', function (e) {
      if (nav.classList.contains('is-open') && !nav.contains(e.target) && !toggle.contains(e.target)) setOpen(false);
    });
    each('a', function (a) { a.addEventListener('click', function () { setOpen(false); }); }, nav);
    window.addEventListener('resize', function () { if (window.innerWidth >= 960) setOpen(false); });
  }

  /* ---------- YouTube facade => youtube-nocookie iframe ---------- */
  function initVideos() {
    each('.video-embed[data-youtube-id]', function (box) {
      var id = box.getAttribute('data-youtube-id');
      if (!/^[A-Za-z0-9_-]{6,20}$/.test(id)) return;
      function play() {
        var iframe = doc.createElement('iframe');
        iframe.src = 'https://www.youtube-nocookie.com/embed/' + id + '?autoplay=1&rel=0&modestbranding=1&playsinline=1';
        iframe.title = box.getAttribute('data-title') || 'YouTube video';
        iframe.allow = 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share';
        iframe.allowFullscreen = true;
        iframe.referrerPolicy = 'strict-origin-when-cross-origin';
        box.innerHTML = '';
        box.appendChild(iframe);
        box.classList.add('is-playing');
        iframe.focus();
      }
      box.addEventListener('click', play, { once: true });
    });
  }

  /* ---------- Sortable tables (.table-sortable) ---------- */
  function parseValue(cell) {
    var raw = cell.getAttribute('data-sort-value');
    if (raw === null) raw = cell.textContent;
    raw = raw.trim();
    if (raw === '' || raw === '-' || raw === '–') return { empty: true };
    var text = raw.replace(/ /g, ' ');
    var multiplier = 1;
    if (/\bmlr\./i.test(text)) multiplier = 1e9;
    else if (/\bmil\./i.test(text)) multiplier = 1e6;
    else if (/\bbin\b/i.test(text)) multiplier = 1e3;
    var cleaned = text.replace(/(mlr\.|mil\.|bin|€|TL|₺|%|'|’|dk\.?)/gi, '').replace(/\s+/g, '');
    if (/^[-+]?\d{1,3}(\.\d{3})+(,\d+)?$/.test(cleaned) || /^[-+]?\d+(,\d+)?$/.test(cleaned)) {
      return { num: parseFloat(cleaned.replace(/\./g, '').replace(',', '.')) * multiplier };
    }
    if (/^[-+]?\d+\.\d+$/.test(cleaned)) return { num: parseFloat(cleaned) * multiplier };
    return { str: raw.toLocaleLowerCase('tr') };
  }

  /* ---------- Horizontally scrolling tables: keyboard-focusable, labelled regions ---------- */
  function tableLabel(wrap) {
    // Nearest heading before the wrapper (same level or an ancestor's earlier sibling); captions are
    // often footnotes ("Kaynak: …"), so they are only the fallback.
    for (var node = wrap; node && node !== doc.body; node = node.parentElement) {
      for (var prev = node.previousElementSibling; prev; prev = prev.previousElementSibling) {
        var heading = /^H[2-4]$/.test(prev.tagName) ? prev : prev.querySelector && prev.querySelector('h2, h3, h4');
        if (heading && heading.textContent.trim()) return heading.textContent.trim();
      }
    }
    var caption = wrap.querySelector('caption');
    return caption && caption.textContent.trim() ? caption.textContent.trim().slice(0, 80) : 'Tablo';
  }

  function initScrollRegions() {
    var wraps = Array.prototype.slice.call(doc.querySelectorAll('.table-wrap'));
    if (!wraps.length) return;
    function update() {
      wraps.forEach(function (wrap) {
        var scrolls = wrap.scrollWidth > wrap.clientWidth + 1;
        if (scrolls && !wrap.hasAttribute('tabindex')) {
          wrap.setAttribute('tabindex', '0');
          wrap.setAttribute('role', 'region');
          wrap.setAttribute('aria-label', tableLabel(wrap) + ' (yatay kaydırılabilir)');
          wrap.setAttribute('data-scroll-region', '');
        } else if (!scrolls && wrap.hasAttribute('data-scroll-region')) {
          ['tabindex', 'role', 'aria-label', 'data-scroll-region'].forEach(function (name) { wrap.removeAttribute(name); });
        }
      });
    }
    update();
    var timer = null;
    window.addEventListener('resize', function () { clearTimeout(timer); timer = setTimeout(update, 150); });
    // Tab panels start hidden (0 width): re-check when one is shown.
    doc.addEventListener('click', function (e) { if (e.target.closest && e.target.closest('[data-tab-target]')) setTimeout(update, 0); });
    doc.addEventListener('toggle', update, true); // tables inside <details> ("Tablo olarak göster")
  }

  function initTables() {
    each('table.table-sortable', function (table) {
      var head = table.tHead;
      var body = table.tBodies[0];
      if (!head || !body) return;
      var headers = head.rows[head.rows.length - 1].cells;
      Array.prototype.forEach.call(headers, function (th, index) {
        if (th.hasAttribute('data-no-sort')) return;
        var button = doc.createElement('button');
        button.type = 'button';
        button.className = 'sort-btn';
        while (th.firstChild) button.appendChild(th.firstChild);
        th.appendChild(button);
        th.classList.add('has-sort'); // the button fills the whole header cell (tap target)
        button.addEventListener('click', function () {
          var dir = th.getAttribute('aria-sort') === 'descending' ? 'ascending' : 'descending';
          Array.prototype.forEach.call(headers, function (other) { other.removeAttribute('aria-sort'); });
          th.setAttribute('aria-sort', dir);
          var rows = Array.prototype.slice.call(body.rows);
          var keyed = rows.map(function (row, i) { return { row: row, i: i, v: parseValue(row.cells[index] || row.cells[0]) }; });
          var factor = dir === 'ascending' ? 1 : -1;
          keyed.sort(function (a, b) {
            if (a.v.empty || b.v.empty) return a.v.empty && b.v.empty ? a.i - b.i : (a.v.empty ? 1 : -1);
            var cmp;
            if (a.v.num !== undefined && b.v.num !== undefined) cmp = a.v.num - b.v.num;
            else cmp = String(a.v.str !== undefined ? a.v.str : a.v.num).localeCompare(String(b.v.str !== undefined ? b.v.str : b.v.num), 'tr', { numeric: true });
            return cmp === 0 ? a.i - b.i : cmp * factor;
          });
          keyed.forEach(function (k) { body.appendChild(k.row); });
        });
      });
    });
  }

  /* ---------- Tabs ([data-tab-target]) ---------- */
  function initTabs() {
    each('.tabs', function (list) {
      var tabs = Array.prototype.slice.call(list.querySelectorAll('[data-tab-target]'));
      if (!tabs.length) return;
      if (!list.hasAttribute('role')) list.setAttribute('role', 'tablist');
      function activate(tab, focus) {
        tabs.forEach(function (t) {
          var panel = doc.querySelector(t.getAttribute('data-tab-target'));
          var on = t === tab;
          t.classList.toggle('active', on);
          t.setAttribute('aria-selected', on ? 'true' : 'false');
          t.setAttribute('tabindex', on ? '0' : '-1');
          if (panel) panel.hidden = !on;
        });
        if (focus) tab.focus();
      }
      tabs.forEach(function (tab, i) {
        tab.setAttribute('role', 'tab');
        var panel = doc.querySelector(tab.getAttribute('data-tab-target'));
        if (panel) {
          panel.setAttribute('role', 'tabpanel');
          if (panel.id) tab.setAttribute('aria-controls', panel.id);
        }
        tab.addEventListener('click', function (e) {
          e.preventDefault();
          activate(tab, false);
        });
        tab.addEventListener('keydown', function (e) {
          var next = null;
          if (e.key === 'ArrowRight') next = tabs[(i + 1) % tabs.length];
          if (e.key === 'ArrowLeft') next = tabs[(i - 1 + tabs.length) % tabs.length];
          if (e.key === 'Home') next = tabs[0];
          if (e.key === 'End') next = tabs[tabs.length - 1];
          if (next) { e.preventDefault(); activate(next, true); }
        });
      });
      var fromHash = location.hash && tabs.filter(function (t) { return t.getAttribute('data-tab-target') === location.hash; })[0];
      activate(fromHash || tabs.filter(function (t) { return t.classList.contains('active'); })[0] || tabs[0], false);
    });
  }

  /* ---------- Table of contents highlighting ---------- */
  function initToc() {
    var links = Array.prototype.slice.call(doc.querySelectorAll('.toc a[href^="#"]'));
    if (!links.length || !('IntersectionObserver' in window)) return;
    var map = {};
    var targets = [];
    links.forEach(function (a) {
      var id = decodeURIComponent(a.getAttribute('href').slice(1));
      var el = id && doc.getElementById(id);
      if (el) { map[id] = a; targets.push(el); }
    });
    var visible = {};
    function update() {
      var current = null;
      targets.forEach(function (el) { if (visible[el.id] && !current) current = el.id; });
      if (!current) return;
      links.forEach(function (a) {
        var on = map[current] === a;
        a.classList.toggle('active', on);
        if (on) a.setAttribute('aria-current', 'true'); else a.removeAttribute('aria-current');
      });
      keepInView(map[current]);
    }
    // The sticky desktop TOC scrolls on its own (max-height): keep the active link visible inside it
    // without scrolling the page (scrollIntoView would move the window too).
    function keepInView(a) {
      var box = a && a.closest('.toc');
      if (!box || box.scrollHeight <= box.clientHeight + 1) return;
      var boxRect = box.getBoundingClientRect();
      var rect = a.getBoundingClientRect();
      var pad = 12;
      if (rect.top < boxRect.top + pad) box.scrollTop -= boxRect.top + pad - rect.top;
      else if (rect.bottom > boxRect.bottom - pad) box.scrollTop += rect.bottom - boxRect.bottom + pad;
    }
    // Below 960px a long page's TOC is a collapsed <details> near the top: once it has scrolled out
    // of view, a floating "İçindekiler" button brings it back (hidden on desktop, see app.css).
    var tocBox = links[0].closest('details.toc');
    if (tocBox && tocBox.id) {
      var fab = doc.createElement('a');
      fab.className = 'toc-fab';
      fab.href = '#' + tocBox.id;
      fab.textContent = 'İçindekiler';
      doc.body.appendChild(fab);
      // A scroll check, not an IntersectionObserver: a jump straight past the TOC (anchor, Home/End)
      // never changes its intersection state, so an observer would not fire.
      var pending = false;
      var place = function () {
        pending = false;
        fab.classList.toggle('is-visible', tocBox.getBoundingClientRect().bottom < 0);
      };
      window.addEventListener('scroll', function () {
        if (!pending) { pending = true; window.requestAnimationFrame(place); }
      }, { passive: true });
      place();
      fab.addEventListener('click', function (e) {
        e.preventDefault();
        tocBox.open = true;
        tocBox.scrollIntoView({ behavior: reduceMotion ? 'auto' : 'smooth', block: 'start' });
        var summary = tocBox.querySelector('summary');
        if (summary) summary.focus({ preventScroll: true });
      });
    }
    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) { visible[entry.target.id] = entry.isIntersecting; });
      update();
    }, { rootMargin: '-80px 0px -55% 0px', threshold: 0 });
    targets.forEach(function (el) { observer.observe(el); });
    links.forEach(function (a) {
      a.addEventListener('click', function (e) {
        var el = doc.getElementById(decodeURIComponent(a.getAttribute('href').slice(1)));
        if (!el) return;
        e.preventDefault();
        el.scrollIntoView({ behavior: reduceMotion ? 'auto' : 'smooth', block: 'start' });
        if (history.replaceState) history.replaceState(null, '', '#' + el.id);
      });
    });
  }

  /* ---------- KVKK cookie consent + GA4 (loaded only after consent) ---------- */
  var CONSENT_KEY = 'cr7-consent';
  var CONSENT_DAYS = 180;

  function readConsent() {
    try {
      var data = JSON.parse(localStorage.getItem(CONSENT_KEY) || 'null');
      if (data && data.t && Date.now() - data.t < CONSENT_DAYS * 864e5) return data.v;
    } catch (e) { /* storage unavailable */ }
    return null;
  }

  function saveConsent(value) {
    try { localStorage.setItem(CONSENT_KEY, JSON.stringify({ v: value, t: Date.now() })); } catch (e) { /* ignore */ }
  }

  function loadAnalytics(id) {
    if (window.__cr7GaLoaded) return;
    window.__cr7GaLoaded = true;
    window.dataLayer = window.dataLayer || [];
    window.gtag = function () { window.dataLayer.push(arguments); };
    window.gtag('js', new Date());
    window.gtag('config', id, { anonymize_ip: true });
    var s = doc.createElement('script');
    s.async = true;
    s.src = 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(id);
    doc.head.appendChild(s);
  }

  function initConsent() {
    var banner = doc.getElementById('cookie-consent');
    if (!banner) return;
    var id = banner.getAttribute('data-ga4');
    var state = readConsent();
    if (state === 'granted') loadAnalytics(id);
    if (state === null) banner.hidden = false;
    each('[data-consent]', function (btn) {
      btn.addEventListener('click', function () {
        var granted = btn.getAttribute('data-consent') === 'accept';
        saveConsent(granted ? 'granted' : 'denied');
        banner.hidden = true;
        if (granted) loadAnalytics(id);
      });
    }, banner);
    each('[data-consent-open]', function (btn) {
      btn.addEventListener('click', function () { banner.hidden = false; });
    });
  }

  function init() {
    initNav();
    initVideos();
    initTables();
    initTabs();
    initScrollRegions();
    initToc();
    initConsent();
  }

  if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', init);
  else init();
})();
