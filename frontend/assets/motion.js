/* ═══════════════════════════════════════════════════════════════════════════════
   Vesla Motors — shared motion layer (behaviour)
   Companion to motion.css. Ported from the MRM Investment mockup set and
   adapted to this page's markup.

   Division of labour: this file sets custom properties and classes, nothing
   else. Every visual decision lives in motion.css, so the look can be retuned
   without touching a line of JavaScript.

   Load this LAST, after app.js, so the markup it enhances — including the
   cards rendered by the filter — already exists. Content rendered later is
   picked up by the observer at the bottom.

   Escape hatch: set window.VESLA_MOTION_OFF = true before this file loads and
   nothing here runs.
   ═══════════════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  if (window.VESLA_MOTION_OFF) return;

  /* Stamped first, before anything else can throw. motion.css holds the two
     entrances that only a class from this file can complete — the photograph
     fade-up and the card frame wipe — behind this hook, so a stylesheet that
     loads without its script leaves the photographs visible rather than
     hidden behind a frame that never opens. */
  document.documentElement.classList.add('m-on');

  /* Held at false on purpose, matching app.js and the parked block in the two
     stylesheets: many Windows laptops report "reduce motion" from battery
     saver or the Visual effects setting, which was switching everything off
     unasked. Flip this to the matchMedia query, flip the same flag in app.js
     and un-park both reduced-motion blocks together — all four must agree. */
  var reduced = false;

  /* Tilt and magnetism are pointer affordances, not decoration — on a touch
     screen there is no cursor to track, so they are simply not wired up. */
  var finePointer = window.matchMedia('(hover: hover) and (pointer: fine)').matches;

  /* Every surface motion.css treats as a card. The spotlight tracks all of
     them; the 3D tilt is held back to `.card` alone, because the smaller
     panels read as flat and wobble unpleasantly when they are tipped. */
  var CARD_SEL = '.card,.why-grid article,.chan li,.glance div';
  var MAGNET_SEL = '.btn,.btn-line,.btn-gold,.btn-ghost';
  /* `.totop` is deliberately absent: the ripple has to make its host
     position:relative;overflow:hidden to contain the circle, and the
     back-to-top's progress ring overhangs its box by 4px. Clipping that costs
     more than the ripple adds. */
  var RIPPLE_SEL = '.btn,.btn-line,.btn-gold,.btn-ghost,.btn-sound';
  var TILT_MAX = 5;          /* degrees at the very corner of a card */
  var PARALLAX = 5;          /* px the photograph slides inside its frame */
  var STAGGER = 38;          /* ms between neighbours in a row */
  var STAGGER_CAP = 9;       /* after this many, the delay stops growing */

  var $ = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };
  var kidsOf = function (el) { return Array.prototype.filter.call(el.children, function (n) { return n.nodeType === 1; }); };
  var inView = function (el) {
    var b = el.getBoundingClientRect();
    return b.top < (window.innerHeight || 0) && b.bottom > 0;
  };

  /* ═══ 1 · pointer tracking — tilt and spotlight ══════════════════════════
     One delegated listener for the whole page rather than a pair per card:
     cards are re-rendered on every filter change, and per-element listeners
     would have to be re-bound each time (and leak if they were not). */
  (function pointer() {
    if (!finePointer || reduced) return;

    var current = null, pending = null, frame = 0;

    var apply = function () {
      frame = 0;
      if (!pending) return;
      var el = pending.el, box = el.getBoundingClientRect();
      var px = (pending.x - box.left) / box.width;
      var py = (pending.y - box.top) / box.height;

      /* spotlight follows in the element's own percentage space */
      el.style.setProperty('--m-mx', (px * 100).toFixed(1) + '%');
      el.style.setProperty('--m-my', (py * 100).toFixed(1) + '%');

      /* tilt only on the stock cards — see the note on CARD_SEL */
      if (el.classList.contains('card')) {
        el.style.setProperty('--m-ry', ((px - 0.5) * TILT_MAX * 2).toFixed(2) + 'deg');
        el.style.setProperty('--m-rx', ((0.5 - py) * TILT_MAX * 2).toFixed(2) + 'deg');
        /* the photograph slides against the cursor, not with it — the parallax
           is what sells the car as sitting behind the frame. Kept inside the
           headroom the permanent scale in motion.css allows, or an edge would
           show. */
        el.style.setProperty('--m-px', ((0.5 - px) * PARALLAX * 2).toFixed(1) + 'px');
        el.style.setProperty('--m-py', ((0.5 - py) * PARALLAX * 2).toFixed(1) + 'px');
      }
      pending = null;
    };

    var release = function (el) {
      if (!el) return;
      el.classList.remove('m-tracking');
      el.style.removeProperty('--m-rx');
      el.style.removeProperty('--m-ry');
      el.style.removeProperty('--m-px');
      el.style.removeProperty('--m-py');
    };

    document.addEventListener('pointermove', function (e) {
      var el = e.target.closest ? e.target.closest(CARD_SEL) : null;
      if (el !== current) { release(current); current = el; if (el) el.classList.add('m-tracking'); }
      if (!el) return;
      pending = { el: el, x: e.clientX, y: e.clientY };
      if (!frame) frame = requestAnimationFrame(apply);
    }, { passive: true });

    /* a card removed mid-hover by a re-render can never fire pointerleave,
       so the pointer's own exit from the document is the reliable reset */
    document.addEventListener('pointerleave', function () { release(current); current = null; });

    /* the hero carries the same cursor highlight, read from the same two
       properties — it is just a much larger, much fainter one */
    $('.hero').forEach(function (band) {
      var bFrame = 0, bPos = null;
      var paint = function () {
        bFrame = 0;
        if (!bPos) return;
        var box = band.getBoundingClientRect();
        band.style.setProperty('--m-mx', (((bPos.x - box.left) / box.width) * 100).toFixed(1) + '%');
        band.style.setProperty('--m-my', (((bPos.y - box.top) / box.height) * 100).toFixed(1) + '%');
        bPos = null;
      };
      band.addEventListener('pointermove', function (e) {
        bPos = { x: e.clientX, y: e.clientY };
        if (!bFrame) bFrame = requestAnimationFrame(paint);
      }, { passive: true });
    });
  })();

  /* ═══ 2 · magnetic buttons ═══════════════════════════════════════════════
     The pull is capped at a third of the travel from centre, so a wide button
     never slides far enough to sit oddly against the text beside it. */
  (function magnetic() {
    if (!finePointer || reduced) return;
    var held = null;

    var reset = function (el) {
      if (!el) return;
      el.style.removeProperty('--m-tx');
      el.style.removeProperty('--m-ty');
    };

    document.addEventListener('pointermove', function (e) {
      var el = e.target.closest ? e.target.closest(MAGNET_SEL) : null;
      if (el !== held) { reset(held); held = el; }
      if (!el) return;
      var box = el.getBoundingClientRect();
      var dx = e.clientX - (box.left + box.width / 2);
      var dy = e.clientY - (box.top + box.height / 2);
      /* A sixth of the travel, capped at 4px across and 2px down. At a third
         and 10px the wide buttons slid far enough to sit visibly out of line
         with the text beside them, and the label appeared to swim under the
         cursor. This is enough for the button to feel like it answers the
         pointer and not enough to notice as movement. */
      el.style.setProperty('--m-tx', Math.max(-4, Math.min(4, dx / 6)).toFixed(1) + 'px');
      el.style.setProperty('--m-ty', Math.max(-2, Math.min(2, dy / 6)).toFixed(1) + 'px');
    }, { passive: true });

    document.addEventListener('pointerleave', function () { reset(held); held = null; });
  })();

  /* ═══ 3 · ripple ═════════════════════════════════════════════════════════
     Sized to the furthest corner from the strike point so the circle always
     finishes by covering the control, whichever edge it was pressed near. */
  document.addEventListener('pointerdown', function (e) {
    if (reduced) return;
    var el = e.target.closest ? e.target.closest(RIPPLE_SEL) : null;
    if (!el) return;

    el.classList.add('m-rip');
    var box = el.getBoundingClientRect();
    var x = e.clientX - box.left, y = e.clientY - box.top;
    var far = Math.max(
      Math.hypot(x, y), Math.hypot(box.width - x, y),
      Math.hypot(x, box.height - y), Math.hypot(box.width - x, box.height - y)
    );

    var ink = document.createElement('span');
    ink.className = 'm-ripple';
    ink.style.left = x + 'px';
    ink.style.top = y + 'px';
    ink.style.width = ink.style.height = (far * 2) + 'px';
    el.appendChild(ink);
    ink.addEventListener('animationend', function () { ink.remove(); });
  }, { passive: true });

  /* ═══ 4 · scroll state ═══════════════════════════════════════════════════
     The header shadow, the reading rail across the top of it and the progress
     ring on the back-to-top button all read the same scroll position, so they
     share one listener and one frame rather than each scheduling their own.
     app.js keeps its own listener for the two classes it owns; this one adds
     only what motion.css reads.

     `--m-progress` and `--m-scroll` are set on the root element so anything
     can read them by inheritance — the rail and the ring are pseudo-elements
     on two different controls and neither needs its own bookkeeping. */
  (function scrollState() {
    var bar = document.querySelector('.bar');
    var root = document.documentElement;
    var ticking = false;
    var span = 1, stuck = null;

    /* `scrollHeight` is a layout-forcing read. Taken inside the scroll handler
       it made the browser recompute layout on every single frame of every
       scroll, which is the difference between this feeling smooth and feeling
       like it is dragging. The document only changes length when something
       resizes or the grid is refiltered, so it is measured then and cached. */
    var remeasure = function () {
      span = Math.max(1, root.scrollHeight - window.innerHeight);
      sync();
    };

    var sync = function () {
      ticking = false;
      var y = window.scrollY;
      var p = Math.min(1, Math.max(0, y / span));

      /* only touch the class list when the state actually changes — a
         classList write every frame invalidates style for the whole subtree */
      var isStuck = y > 12;
      if (bar && isStuck !== stuck) { bar.classList.toggle('m-stuck', isStuck); stuck = isStuck; }

      root.style.setProperty('--m-progress', p.toFixed(4));
      /* the drift reads its own copy so the two can be tuned apart later */
      root.style.setProperty('--m-scroll', p.toFixed(4));
    };

    window.addEventListener('scroll', function () {
      if (!ticking) { ticking = true; requestAnimationFrame(sync); }
    }, { passive: true });
    window.addEventListener('resize', remeasure);
    /* images settling and webfonts landing both change the document height
       after this first runs, and neither fires a resize */
    window.addEventListener('load', remeasure);
    remeasure();

    /* the grid is refiltered without any of the above firing */
    var grid = document.getElementById('grid');
    if (grid && 'MutationObserver' in window) {
      new MutationObserver(function () { requestAnimationFrame(remeasure); })
        .observe(grid, { childList: true });
    }
  })();

  /* ═══ 5 · headline, split into words ═════════════════════════════════════
     The page's own reveal classes are stripped from the heading first — two
     entry animations on one element cancel each other out, and the word
     stagger is the better of the two.

     The heading is an amber gradient clipped to the glyphs. background-clip is
     per-box, so splitting the words into new boxes would drop the fill; the
     rule is re-declared for `.hero h1 .m-word i` in motion.css. */
  (function headline() {
    var h = document.querySelector('.hero h1');
    if (!h || reduced || h.querySelector('.m-word')) return;

    var text = h.textContent.trim();
    if (!text) return;

    h.classList.remove('reveal', 'd1', 'd2', 'd3', 'in');
    h.textContent = '';

    text.split(/\s+/).forEach(function (word, i) {
      var wrap = document.createElement('span');
      wrap.className = 'm-word';
      wrap.style.setProperty('--m-d', (90 + i * 85) + 'ms');
      var inner = document.createElement('i');
      inner.textContent = word;
      wrap.appendChild(inner);
      h.appendChild(wrap);
      h.appendChild(document.createTextNode(' '));
    });
  })();

  /* ═══ 6 · reveal stagger ═════════════════════════════════════════════════
     Most of the grids hand the page's observer a whole row at once — four
     blocks that all arrive on the same frame. Where a row was never given a
     hand-written delay, one is assigned by position.

     Two guards keep this from making things worse:
       · only rows. A column of `.reveal` blocks down a long section already
         arrives one at a time as it is scrolled to, and adding half a second
         of delay to each would just make the page feel slow. The parent's
         computed display has to be a grid or a non-column flex row.
       · `.d1`/`.d2`/`.d3` are left alone. Those are two-class selectors and
         win over `--m-rd` anyway, so anything choreographed by hand stays as
         it was and only takes its place in the count. */
  var stagger = function (root) {
    var parents = [];
    $('.reveal', root).forEach(function (el) {
      var p = el.parentNode;
      if (!p || p.nodeType !== 1 || parents.indexOf(p) !== -1) return;
      parents.push(p);
    });

    parents.forEach(function (p) {
      var kids = kidsOf(p).filter(function (el) { return el.classList.contains('reveal'); });
      if (kids.length < 2) return;

      var cs = getComputedStyle(p);
      var row = cs.display === 'grid' || cs.display === 'inline-grid'
             || ((cs.display === 'flex' || cs.display === 'inline-flex') && cs.flexDirection.indexOf('column') !== 0);
      if (!row) return;

      kids.forEach(function (el, i) {
        if (el.classList.contains('d1') || el.classList.contains('d2') || el.classList.contains('d3')) return;
        if (el.style.getPropertyValue('--m-rd')) return;
        el.style.setProperty('--m-rd', (Math.min(i, STAGGER_CAP) * STAGGER) + 'ms');
      });
    });
  };
  stagger(document);

  /* ═══ 7 · cast ═══════════════════════════════════════════════════════════
     The rows nobody wired to the page's observer at all: the contact channel
     list and the hero stat figures. Their children are marked and then
     released a beat apart as the container is reached.

     Two containers are skipped outright, and the reasons matter:
       · one whose children already carry `.reveal` — the page is
         choreographing it and a second entrance would fight the first.
       · one whose children already run an animation of their own. That is how
         the stock grid opts out: app.js renders `.card` with
         `animation:cardIn` and an inline per-card delay, and re-casting it
         here would replace a staggered entrance with a slightly different
         staggered entrance — and, worse, would only ever run once, leaving
         everything filtered in afterwards with no entrance at all. */
  var cast = function (root) {
    var CAST_SEL = '#grid,.chan,.hero-stats';

    /* querySelectorAll only looks downward, so a re-render handed the grid
       element itself would otherwise never match its own selector */
    var boxes = $(CAST_SEL, root);
    if (root.nodeType === 1 && root.matches && root.matches(CAST_SEL)) boxes.unshift(root);

    boxes.forEach(function (box) {
      if (box.dataset.mCast) return;
      var kids = kidsOf(box);
      if (kids.length < 2) return;
      if (kids.some(function (el) { return el.classList.contains('reveal'); })) return;
      if (getComputedStyle(kids[0]).animationName !== 'none') return;

      box.dataset.mCast = '1';
      kids.forEach(function (el, i) {
        el.classList.add('m-cast');
        el.style.setProperty('--m-rd', (Math.min(i, STAGGER_CAP) * STAGGER) + 'ms');
      });

      var release = function () { kids.forEach(function (el) { el.classList.add('m-on'); }); };

      /* Anything already on screen is released in the same tick it was marked,
         so the page never paints a frame with a hole where the row is. */
      if (reduced || !('IntersectionObserver' in window) || inView(box)) { release(); return; }

      var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (e) {
          if (!e.isIntersecting) return;
          release();
          io.disconnect();
        });
      }, { threshold: 0.06, rootMargin: '0px 0px -6% 0px' });
      io.observe(box);
    });
  };
  cast(document);

  /* ═══ 8 · footer shield ══════════════════════════════════════════════════
     Where the browser understands scroll-driven animation motion.css raises the
     Vesla shield off the scroll position itself and this class is simply
     unused. Where it does not, the `@supports not` block picks the class up
     instead, so the footer lands the same way on both. */
  (function shield() {
    var img = document.querySelector('.foot-wm img');
    if (!img) return;
    if (reduced || !('IntersectionObserver' in window)) { img.classList.add('m-in'); return; }

    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) {
        if (!e.isIntersecting) return;
        img.classList.add('m-in');
        io.disconnect();
      });
    }, { threshold: 0.15 });
    io.observe(img);
  })();

  /* ═══ 9 · photographs and line art ═══════════════════════════════════════
     pathLength="1" normalises every stroke to the same nominal length, so the
     single dash pair in motion.css draws a small wheel and a long roofline at
     the same rate instead of one finishing while the other has barely begun.

     `has-photo` is stamped here rather than by the renderer: it is what the
     frame-wipe hangs off, and only a media box that actually holds a
     photograph should wipe — the lettered placeholder tiles have no frame to
     open. */
  var firstArtPass = true;
  var stampArt = function (root) {
    $('.card-media svg', root).forEach(function (svg) {
      Array.prototype.forEach.call(svg.children, function (node) {
        if (!node.hasAttribute('pathLength')) node.setAttribute('pathLength', '1');
      });
    });

    /* Photographs fade up as they decode rather than snapping in halfway
       through the card's entrance. `complete` is checked first because a
       cached image can finish loading before this ever runs, and its load
       event would never fire.

       The fade is the FIRST pass only. Every filter change rebuilds the grid
       from scratch, so each new <img> starts at opacity 0 again — and for a
       photograph the browser already has, `complete` is still false at the
       moment this runs while `load` may not fire for another frame or two.
       That put a blink through the stock list on every filter change, for no
       gain: the cards are already arriving on cardIn and the frame is already
       wiping open. After the first pass the photographs are simply there. */
    $('.card-media img', root).forEach(function (img) {
      var media = img.parentNode;
      if (media && media.classList) media.classList.add('has-photo');
      if (img.classList.contains('m-loaded')) return;
      if (!firstArtPass || (img.complete && img.naturalWidth)) { img.classList.add('m-loaded'); return; }
      img.addEventListener('load', function () { img.classList.add('m-loaded'); }, { once: true });
      /* a broken path must not leave an invisible card. app.js swaps the image
         for a placeholder tile on error, but the class is added here too so
         the frame is never left holding a transparent box either way. */
      img.addEventListener('error', function () { img.classList.add('m-loaded'); }, { once: true });
    });
    firstArtPass = false;
  };
  stampArt(document);

  /* ═══ 10 · re-rendered grid ══════════════════════════════════════════════
     The stock grid is re-rendered wholesale on every filter change, so one
     observer catches new cards however they arrived — no hook into app.js's
     render function, and nothing to keep in step with it. */
  (function results() {
    if (!('MutationObserver' in window)) return;

    $('#grid').forEach(function (grid) {
      var count = document.getElementById('count');
      var first = true;

      new MutationObserver(function () {
        stampArt(grid);
        stagger(grid);
        cast(grid);
        if (first) { first = false; return; }   /* the first paint is not a change */
        if (!count || reduced) return;
        count.classList.remove('m-pulse');
        void count.offsetWidth;                 /* restart the animation cleanly */
        count.classList.add('m-pulse');
      }).observe(grid, { childList: true });
    });
  })();
})();
