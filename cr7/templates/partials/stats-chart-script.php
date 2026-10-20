<?php
/**
 * Hover/touch tooltip + crosshair for App\Support\Svg charts ([data-tip-v] inside .chart).
 * Include once per page that renders charts. Values are inserted with textContent only.
 *
 * @var string|null $cspNonce
 */
?>
<script nonce="<?= e($cspNonce ?? '') ?>">
(function () {
  var d = document, tip = null, svg = null;
  function box() {
    if (tip) return tip;
    tip = d.createElement('div');
    tip.setAttribute('aria-hidden', 'true');
    tip.style.cssText = 'position:fixed;z-index:200;display:none;pointer-events:none;max-width:260px;padding:7px 11px;border:1px solid #3a3a45;border-radius:9px;background:#141418;box-shadow:0 8px 24px rgba(0,0,0,.45);color:#a3a3b2;font:13px/1.4 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif';
    var v = d.createElement('strong');
    v.style.cssText = 'display:block;color:#f2f2f5;font-size:15px';
    tip.appendChild(v);
    tip.appendChild(d.createElement('span'));
    d.body.appendChild(tip);
    return tip;
  }
  function mark(s, on, t) {
    if (!s) return;
    var c = s.querySelector('.st-cross'), h = s.querySelector('.st-hdot');
    if (c) { if (on) { c.setAttribute('x1', t.getAttribute('data-cx')); c.setAttribute('x2', t.getAttribute('data-cx')); } c.setAttribute('visibility', on ? 'visible' : 'hidden'); }
    if (h) { if (on) { h.setAttribute('cx', t.getAttribute('data-cx')); h.setAttribute('cy', t.getAttribute('data-cy')); } h.setAttribute('visibility', on ? 'visible' : 'hidden'); }
  }
  function hide() { if (tip) tip.style.display = 'none'; mark(svg, false); svg = null; }
  function move(e) {
    var t = e.target && e.target.closest ? e.target.closest('.chart [data-tip-v]') : null;
    if (!t) { if (svg || (tip && tip.style.display !== 'none')) hide(); return; }
    var b = box(), s = t.closest('svg');
    if (svg && svg !== s) mark(svg, false);
    svg = s;
    b.firstChild.textContent = t.getAttribute('data-tip-v');
    b.lastChild.textContent = t.getAttribute('data-tip-l') || '';
    b.style.display = 'block';
    mark(s, t.hasAttribute('data-cx'), t);
    var w = b.offsetWidth, h = b.offsetHeight, x = e.clientX + 14, y = e.clientY - h - 12;
    if (x + w > window.innerWidth - 8) x = e.clientX - w - 14;
    if (x < 8) x = 8;
    if (y < 8) y = e.clientY + 20;
    b.style.left = x + 'px';
    b.style.top = y + 'px';
  }
  d.addEventListener('pointermove', move, { passive: true });
  d.addEventListener('pointerdown', move, { passive: true });
  window.addEventListener('scroll', hide, { passive: true });
  d.addEventListener('pointerleave', hide);
})();
</script>
