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

  function init() {
    initConfirm();
    initSlug();
    initSidebar();
    initPasswordToggles();
  }

  if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', init);
  else init();
})();
