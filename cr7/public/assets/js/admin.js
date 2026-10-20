/* CR7.com.tr admin — small enhancements (no dependencies). */
(function () {
  'use strict';

  var doc = document;

  function each(selector, fn) {
    Array.prototype.forEach.call(doc.querySelectorAll(selector), fn);
  }

  /* Turkish-aware slug, mirrors App\Support\Str::slug(). */
  var MAP = { 'ç': 'c', 'ğ': 'g', 'ı': 'i', 'i̇': 'i', 'ö': 'o', 'ş': 's', 'ü': 'u', 'â': 'a', 'î': 'i', 'û': 'u', 'ã': 'a', 'á': 'a', 'à': 'a', 'é': 'e', 'è': 'e', 'ê': 'e', 'í': 'i', 'ó': 'o', 'ô': 'o', 'õ': 'o', 'ú': 'u', 'ñ': 'n', 'ß': 'ss' };
  function slugify(text, max) {
    var s = String(text).replace(/['’‘`´]/g, '').replace(/İ/g, 'i').replace(/I/g, 'i').toLowerCase();
    s = s.replace(/i̇|[çğıöşüâîûãáàéèêíóôõúñß]/g, function (ch) { return MAP[ch] || ch; });
    s = s.normalize ? s.normalize('NFD').replace(/[̀-ͯ]/g, '') : s;
    s = s.replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
    max = max || 90;
    if (s.length > max) {
      var cut = s.slice(0, max + 1);
      var pos = cut.lastIndexOf('-');
      s = (pos > 0 ? cut.slice(0, pos) : s.slice(0, max)).replace(/-+$/g, '');
    }
    return s;
  }

  /* Confirm destructive actions: <form data-confirm="Silinsin mi?"> */
  function initConfirm() {
    doc.addEventListener('submit', function (e) {
      var form = e.target;
      var message = form && form.getAttribute && form.getAttribute('data-confirm');
      if (message && !window.confirm(message)) e.preventDefault();
    });
  }

  /*
   * Slug preview: <input data-slug-source="#slug"> fills <input id="slug"> until the slug is edited by hand;
   * an element with [data-slug-preview] (optional data-prefix) shows the resulting URL.
   */
  function initSlug() {
    each('[data-slug-source]', function (source) {
      var target = doc.querySelector(source.getAttribute('data-slug-source'));
      var preview = doc.querySelector('[data-slug-preview]');
      if (!target) return;
      var manual = target.value !== '' && target.value !== slugify(source.value);
      function render() {
        if (preview) preview.textContent = (preview.getAttribute('data-prefix') || '') + (target.value || slugify(source.value));
      }
      source.addEventListener('input', function () {
        if (!manual) target.value = slugify(source.value);
        render();
      });
      target.addEventListener('input', function () {
        manual = target.value !== '';
        render();
      });
      target.addEventListener('change', function () {
        target.value = slugify(target.value || source.value, 190);
        render();
      });
      render();
    });
  }

  /* Mobile sidebar */
  function initSidebar() {
    var toggle = doc.querySelector('.admin-menu-toggle');
    var sidebar = doc.getElementById('admin-sidebar');
    if (!toggle || !sidebar) return;
    function setOpen(open) {
      sidebar.classList.toggle('is-open', open);
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
    toggle.addEventListener('click', function (e) {
      e.stopPropagation();
      setOpen(!sidebar.classList.contains('is-open'));
    });
    doc.addEventListener('click', function (e) {
      if (sidebar.classList.contains('is-open') && !sidebar.contains(e.target)) setOpen(false);
    });
    doc.addEventListener('keydown', function (e) { if (e.key === 'Escape') setOpen(false); });
  }

  /* Show/hide secret inputs: <button data-toggle-password="#paytr_key"> */
  function initPasswordToggles() {
    each('[data-toggle-password]', function (btn) {
      var input = doc.querySelector(btn.getAttribute('data-toggle-password'));
      if (!input) return;
      btn.addEventListener('click', function () {
        var show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        btn.textContent = show ? 'Gizle' : 'Göster';
      });
    });
  }


  /* Character counters: <input data-counter="160" [data-counter-soft]> (soft = recommendation only). */
  function initCounters() {
    each('[data-counter]', function (input) {
      var max = parseInt(input.getAttribute('data-counter'), 10) || 0;
      if (!max) return;
      var soft = input.hasAttribute('data-counter-soft');
      var counter = doc.createElement('span');
      counter.className = 'char-counter';
      counter.setAttribute('aria-live', 'polite');
      var anchor = input.parentNode && input.parentNode.classList.contains('password-field') ? input.parentNode : input;
      anchor.parentNode.insertBefore(counter, anchor.nextSibling);
      function render() {
        var length = Array.from ? Array.from(input.value).length : input.value.length;
        counter.textContent = length + ' / ' + max + (soft ? ' önerilen' : '');
        counter.classList.toggle('is-over', length > max);
        counter.classList.toggle('is-near', length <= max && length > max * 0.9);
      }
      input.addEventListener('input', render);
      render();
    });
  }

  /* Copy buttons: <button data-copy="#field"> */
  function initCopy() {
    each('[data-copy]', function (btn) {
      btn.addEventListener('click', function () {
        var target = doc.querySelector(btn.getAttribute('data-copy'));
        if (!target) return;
        var text = 'value' in target ? target.value : target.textContent;
        var label = btn.textContent;
        function done(ok) {
          btn.textContent = ok ? 'Kopyalandı ✓' : 'Kopyalanamadı';
          setTimeout(function () { btn.textContent = label; }, 1600);
        }
        function fallback() {
          if (target.select) target.select();
          var ok = false;
          try { ok = doc.execCommand('copy'); } catch (err) { ok = false; }
          done(ok);
        }
        if (navigator.clipboard && window.isSecureContext) {
          navigator.clipboard.writeText(text).then(function () { done(true); }, fallback);
          return;
        }
        fallback();
      });
    });
  }

  /* Filter selects/checkboxes that submit their form on change: <select data-autosubmit> */
  function initAutosubmit() {
    each('[data-autosubmit]', function (field) {
      field.addEventListener('change', function () {
        var form = field.form;
        if (!form) return;
        if (form.requestSubmit) form.requestSubmit(); else form.submit();
      });
    });
  }

  /* Confirmation on single buttons (e.g. buttons submitting another form): <button data-confirm-click="…"> */
  function initConfirmClick() {
    doc.addEventListener('click', function (e) {
      var btn = e.target && e.target.closest ? e.target.closest('[data-confirm-click]') : null;
      if (btn && !window.confirm(btn.getAttribute('data-confirm-click'))) e.preventDefault();
    });
  }

  /* Long-running actions: <form data-busy="Çalışıyor…"> disables its buttons once submitted. */
  function initBusy() {
    doc.addEventListener('submit', function (e) {
      var form = e.target;
      if (e.defaultPrevented || !form || !form.hasAttribute || !form.hasAttribute('data-busy')) return;
      if (form.getAttribute('aria-busy') === 'true') { e.preventDefault(); return; }
      form.setAttribute('aria-busy', 'true');
      var text = form.getAttribute('data-busy');
      Array.prototype.forEach.call(form.querySelectorAll('button[type="submit"], button:not([type])'), function (btn) {
        btn.textContent = text;
        setTimeout(function () { btn.disabled = true; }, 0);
      });
    });
  }

  function init() {
    initConfirm();
    initSlug();
    initSidebar();
    initPasswordToggles();
    initCounters();
    initCopy();
    initAutosubmit();
    initConfirmClick();
    initBusy();
  }

  if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', init);
  else init();
})();
