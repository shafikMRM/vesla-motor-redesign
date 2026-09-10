/* Vesla Motors — landing page behaviour
   Plain JavaScript, no build step and no dependencies. */
(function () {
  'use strict';

  /* Held at false on purpose, matching motion.js and the parked blocks in both
     stylesheets: many Windows laptops report "reduce motion" from battery
     saver or the Visual effects setting rather than from a considered choice,
     which was switching the whole motion layer off unasked. Flip this to
       window.matchMedia('(prefers-reduced-motion:reduce)').matches
     and un-park the two CSS blocks and motion.js together — all four have to
     move at once or the page ends up half-animated. */
  var reduced = false;

  /* ----------------------------------------------------------------
     Stock, and everything else this page can be configured with, comes from
     WordPress. Vesla_Render prints `window.VESLA_DATA` immediately before this
     file, built from what the administrator entered on the Landing Page
     screen - so there is no content in this file at all.

     The fallback is an EMPTY configuration, not sample cars. A script that
     quietly falls back to demonstration data when the real data fails to
     arrive shows a showroom full of cars nobody is selling.
  ---------------------------------------------------------------- */
  var CFG    = window.VESLA_DATA || {};
  var LABELS = CFG.labels || {};

  /* ---------------- the data boundary ----------------
     Everything below this point assumes a car has the fields it should have.
     Nothing upstream guarantees that. An administrator can save a car having
     filled in only the make; PHP casts a blank price to 0 rather than refusing
     it; a row can be half-finished and still be perfectly valid to store.

     One incomplete row used to take the whole page down with it. cardFor()
     called `car.make.charAt(0)`, which on an undefined make throws — and
     because everything here lives in one IIFE, that single throw stopped the
     grid, the filters, the estimator, the enquiry form and the mobile menu
     together. A missing letter in the admin, and the site stops working.

     So the shape is fixed once, here, at the edge. After this every row has
     every field, of the right type, and the rest of the file can stop asking. */
  function toNum(v) {
    var x = Number(v);
    return isFinite(x) && x > 0 ? x : 0;
  }
  function toText(v) {
    return ( v === null || v === undefined ) ? '' : String(v).trim();
  }

  function normalise(rows) {
    if (!Array.isArray(rows)) return [];
      /* The keys and their types come from CFG.carFields, which WordPress
         builds from the schema. This was a fixed list of ten written out here,
         and every field added to a car in WordPress was silently dropped on
         the way in -- the popup showed six rows however much the admin had
         filled in. A list of names kept in two places is a list that goes out
         of date. */
      var TYPES = CFG.carFields || {
        make: 'text', model: 'text', body: 'text', trans: 'text', fuel: 'text',
        img: 'text', year: 'number', km: 'number', price: 'number', seats: 'number'
      };

      return rows.map(function (r) {
        r = (r && typeof r === 'object') ? r : {};
        var out = {};
        for (var k in TYPES) {
          if (!Object.prototype.hasOwnProperty.call(TYPES, k)) { continue; }
          /* 'rich' is markup the server has already cleaned twice and the
             popup writes with innerHTML; coercing it as text would print the
             tags themselves. Everything else is text or a number. */
          out[k] = TYPES[k] === 'number' ? toNum(r[k])
                 : TYPES[k] === 'rich'   ? (typeof r[k] === 'string' ? r[k] : '')
                 /* A list stays a list. Running the photographs through
                    toText() turned the array into a string, and the gallery
                    then had nothing to iterate — one picture, no thumbnails. */
                 : TYPES[k] === 'list'   ? (Array.isArray(r[k]) ? r[k].filter(Boolean).map(String) : [])
                 : toText(r[k]);
        }
        return out;
      }).filter(function (c) {
      /* A row with nothing to call it is one somebody started and abandoned.
         A nameless card in the showroom helps no visitor and no dealer. */
      return c.make || c.model;
    });
  }

  var STOCK = normalise(CFG.stock);

  /* ---------------- keeping a published page fresh ----------------
     The static page is written out by WordPress with its content already in
     the markup AND the same content inlined as VESLA_DATA, so everything below
     works before this fetch is even made. That ordering is deliberate:

       · a crawler, a link previewer, or anyone with scripts off sees the cars,
         because they are in the HTML rather than fetched into it;
       · if WordPress is down, slow, or mid-deploy, nothing here changes — the
         page keeps its content and, importantly, its phone number and WhatsApp
         link. The showroom stays reachable when its CMS is not.

     So this is a refresh, not a load. It asks whether anything has changed
     since the file was written and re-renders only if so — which is why the
     failure path does nothing at all rather than showing an error. */
  (function refresh() {
    var url = window.VESLA_REST || CFG.restUrl;
    if (!url || !window.fetch) return;

    fetch(url + 'landing', { credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (res) {
        if (!res || !res.ok || !res.data) return;

        /* Nothing has been saved since this page was published. */
        if (window.VESLA_PRERENDERED && res.version && res.version === CFG.version) return;

        var fresh = normalise(res.data.stock);
        /* An empty answer is treated as a fault, not as "the showroom has sold
           every car". Wiping a page full of listings because a request came
           back oddly is the worse of the two mistakes. */
        if (!fresh.length) return;

        STOCK = fresh;
        CFG = res.data;
        LABELS = CFG.labels || {};
        MSG = CFG.messages || {};
        LIM = CFG.limits || {};

        rebuildFilters();
        render();
      })
      .catch(function () {
        /* Deliberately silent. The page already has everything it needs. */
      });
  })();


  /* ---------------- wording and limits ----------------
     Both come from WordPress, and both are the SAME values the server-side
     checks use — Vesla_Enquiry::limits() and the "Enquiry form wording"
     settings. They were written down twice before, and had already drifted:
     this file told somebody their number was "too short to dial" where the
     server told the same person their number "does not look right".

     The fallbacks are the English defaults, so a missing setting degrades to
     the wording that shipped rather than to an empty message box. */
  var MSG = CFG.messages || {};
  var LIM = CFG.limits || {};

  function msg(key, fallback) {
    return MSG[key] || fallback;
  }
  function lim(key, fallback) {
    var v = parseInt(LIM[key], 10);
    return isFinite(v) && v > 0 ? v : fallback;
  }
  var PAGE   = Math.max(1, parseInt(CFG.perPage, 10) || 8);

  /* printf-style placeholders, because this wording is translatable and the
     order of the pieces is not the same in every language. */
  function fmt(str, a, b) {
    return String(str || '')
      .replace('%1$s', a).replace('%2$s', b)
      .replace('%s', a);
  }

  var $  = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

  /* ---------------- escaping ----------------
     Every value interpolated into an innerHTML string goes through this.

     The car list now comes from the database, where an administrator types it
     — so a model name of `"><script>…` would be markup rather than text if it
     went in raw. PHP escapes what it prints; this escapes what JavaScript
     builds. Both ends, because the value passes through both.

     The quote forms matter as much as the angle brackets: several of these
     values are written into quoted attributes (alt, aria-label, href), where a
     bare " ends the attribute and starts a new one. */
  function esc(v) {
    return String(v)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  /* Currency word and digit grouping are both settings, so a dealer in another
     market is not stuck with AED and English formatting. */
  var CURRENCY = CFG.currency || '';
  var nf;
  try { nf = new Intl.NumberFormat(CFG.locale || 'en'); }
  catch (e) { nf = new Intl.NumberFormat(); }
  /* A blank price is not a price of zero. Both of these return an empty string
     for a missing value so the caller can leave the line out altogether,
     rather than printing "AED 0" — which a visitor reads as a free car and a
     dealer reads as a broken website. */
  function aed(n) {
    n = toNum(n);
    return n ? (CURRENCY ? CURRENCY + ' ' : '') + nf.format(Math.round(n)) : '';
  }
  function km(n) {
    n = toNum(n);
    return n ? nf.format(n) + ' km' : '';
  }

  /* ---------------- stock grid ---------------- */
  var grid   = $('#grid');
  var empty  = $('#empty');
  var count  = $('#count');
  var fMake  = $('#f-make');
  var fBody  = $('#f-body');
  var fPrice = $('#f-price');
  var fSort  = $('#f-sort');
  var fFind  = $('#f-search');

  /* Blanks are dropped: a car saved without a body type must not put an empty
     option in the Body menu that appears to filter to nothing. */
  function uniq(key) {
    return STOCK.map(function (c) { return c[key]; })
      .filter(function (v) { return v !== '' && v !== 0; })
      .filter(function (v, i, a) { return a.indexOf(v) === i; })
      .sort();
  }

  function fillSelect(el, values) {
    /* Called for each filter menu, and there are no filter menus on a page
       without a stock grid. Nothing to fill is not a failure. */
    if (!el) { return; }
    values.forEach(function (v) {
      var o = document.createElement('option');
      o.value = v;
      o.textContent = v;
      el.appendChild(o);
    });
  }

  /* Wrapped so a refresh can rebuild them: new stock can introduce a make the
     menu has never heard of. */
  function rebuildFilters() {
    /* This script also runs on a car's own page, which has the header and the
       footer but no car grid and no filter menus. Every one of these is
       optional rather than assumed: reading .options off a select that is not
       there threw, and because the whole file is one IIFE it took the mobile
       menu and the footer year down with it. */
    [fMake, fBody].forEach(function (sel) {
      if (!sel) { return; }
      while (sel.options.length > 1) { sel.remove(1); }
    });
    if (fMake) { fillSelect(fMake, uniq('make')); }
    if (fBody) { fillSelect(fBody, uniq('body')); }
  }
  rebuildFilters();

  /* The price bands used to be four fixed options written into the markup by
     hand, which had to be kept in step with the stock by somebody remembering
     to. They are worked out from the actual prices instead - four round steps
     up to the dearest car - so the filter always covers the floor as it stands
     today, whatever the admin has added. */
  (function priceBands() {
    if (!fPrice || !STOCK.length) return;
    var prices = STOCK.map(function (c) { return Number(c.price) || 0; })
                      .filter(function (p) { return p > 0; })
                      .sort(function (a, b) { return a - b; });
    if (!prices.length) return;

    var top  = prices[prices.length - 1];
    var step = Math.ceil((top / 4) / 5000) * 5000;
    if (step <= 0) return;

    for (var i = 1; i <= 4; i++) {
      var cap = (i === 4) ? top : step * i;
      /* a band that would already hold every car tells the visitor nothing */
      if (i < 4 && cap >= top) break;
      var o = document.createElement('option');
      o.value = cap;
      o.textContent = fmt(LABELS.under || 'Under %s', aed(cap));
      fPrice.appendChild(o);
    }
  })();

  /* The WhatsApp deep link carries the car in the message, so nobody has to
     describe which one they mean. encodeURIComponent, not a template — a model
     name with a space or an ampersand would otherwise truncate the text. */
  var WA_NUMBER = (CFG.whatsapp || '').replace(/\D/g, '');
  function waHref(car) {
    if (!WA_NUMBER) return '';
    var name = car.year + ' ' + car.make + ' ' + car.model;
    return 'https://wa.me/' + WA_NUMBER + '?text=' +
      encodeURIComponent(fmt(LABELS.waText, name, aed(car.price)));
  }





  function cardFor(car, i) {
    var el = document.createElement('article');
    el.className = 'card';

    /* The entrance is staggered per card from its position in the filtered
       list, capped so a long list does not end up with a two-second tail. The
       frame-wipe in motion.css reads this same delay through
       `animation-delay:inherit`, which is what keeps the photograph and the
       card arriving together without a second number to keep in step. */
    el.style.animationDelay = (Math.min(i, 9) * 45) + 'ms';

    var name = esc(car.make) + ' ' + esc(car.model);

    /* the placeholder letter comes from whichever of the two names exists */
    var initial = (car.make || car.model || '?').charAt(0);

    var media = car.img
      ? '<img src="' + esc(car.img) + '" alt="' + name + '" loading="lazy" width="640" height="400">'
      : '<div class="ph" aria-hidden="true">' + esc(initial) + '</div>';

    /* Each specification line appears only if there is something to put in it,
       so a half-filled car shows a shorter list rather than a list of blanks. */
    var specs = [];
    if (car.km)    specs.push(km(car.km));
    if (car.body)  specs.push(esc(car.body));
    if (car.trans) specs.push(esc(car.trans));
    var last = [];
    if (car.fuel)  last.push(esc(car.fuel));
    if (car.seats) last.push(esc(car.seats) + ' ' + esc(LABELS.seats || ''));
    if (last.length) specs.push(last.join(' &middot; '));

    var price = aed(car.price);

    el.innerHTML =
      '<div class="card-media">' +
        (LABELS.badge ? '<span class="tag">' + esc(LABELS.badge) + '</span>' : '') +
        media + '</div>' +
      '<div class="card-body">' +
        '<div class="card-top">' +
          /* A car whose own page is switched off has no url, and gets no
             link: an anchor to "#" looks like a link and goes nowhere. */
          '<div><h3>' +
            (car.url
              ? '<a class="card-link" href="' + esc(car.url) + '">' + name + '</a>'
              : name) +
          '</h3>' +
          (car.year ? '<span class="yr">' + esc(car.year) + '</span>' : '') + '</div>' +
          (price
            ? '<div class="price">' + price +
                (LABELS.priceNote ? '<small>' + esc(LABELS.priceNote) + '</small>' : '') +
                (LABELS.warranty  ? '<em>'    + esc(LABELS.warranty)  + '</em>'    : '') +
              '</div>'
            : '') +
        '</div>' +
        (specs.length
          ? '<ul class="spec"><li><b>' + specs.join('</b></li><li><b>') + '</b></li></ul>'
          : '') +
        '<div class="card-act">' +
          /* No "full details" button. The whole card already leads to the
             car's own page -- the title link below is stretched across it --
             so a second control saying the same thing was a duplicate link to
             the same URL: extra tab stops, extra work for a crawler deciding
             what the card is for, and one more thing between the reader and
             the two actions that actually differ, enquiring and WhatsApp. */
          '<a class="btn btn-line js-enq" href="#contact">' + esc(LABELS.enquire || '') + '</a>' +
          /* the WhatsApp button exists only when a number has been entered in
             the settings — an empty href would look like a working button and
             go nowhere */
          (WA_NUMBER
            ? '<a class="btn btn-wa" href="' + esc(waHref(car)) + '" target="_blank" rel="noopener" ' +
                 'aria-label="' + esc(fmt(LABELS.waAria, car.year + ' ' + car.make + ' ' + car.model)) + '">' +
                '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a10 10 0 0 0-8.6 15l-1.3 4.7 4.8-1.3A10 10 0 1 0 12 2zm5.6 14.2c-.2.7-1.4 1.3-2 1.4-.5.1-1.1.1-1.8-.1-.4-.1-1-.3-1.7-.6-3-1.3-4.9-4.3-5.1-4.5-.1-.2-1.2-1.5-1.2-2.9s.7-2 1-2.3c.2-.3.5-.4.7-.4h.5c.2 0 .4 0 .6.5l.8 2c.1.2.1.3 0 .5l-.4.5-.3.3c-.1.1-.2.3 0 .5.2.3.8 1.3 1.7 2.1 1.2 1 2.1 1.4 2.4 1.5.2.1.4.1.5-.1l.8-.9c.2-.2.3-.2.5-.1l2 1c.2.1.4.2.4.3.1.2.1.7-.1 1.3z"/></svg>' +
              '</a>'
            : '') +
        '</div>' +
      '</div>';

    /* The whole card is clickable, because people click a car's picture
       expecting to see the car. Done by stretching the title's link across
       the card in CSS rather than by a click handler: that way it is a real
       link -- middle-click opens a tab, hover shows the address, a crawler
       follows it -- and the buttons on top of it still work. */

    // Prefill the enquiry form with whichever car the visitor clicked.
    $('.js-enq', el).addEventListener('click', function () {
      var f = $('#q-car');
      if (f) f.value = car.make + ' ' + car.model + ' (' + car.year + ')';
      var t = $('#q-type');
      if (t) t.value = 'car';
    });

    // If a photo 404s, drop back to the placeholder rather than a broken icon.
    var img = $('img', el);
    if (img) {
      img.addEventListener('error', function () {
        var ph = document.createElement('div');
        ph.className = 'ph';
        ph.setAttribute('aria-hidden', 'true');
        ph.textContent = car.make.charAt(0);
        img.replaceWith(ph);
      });
    }
    return el;
  }

  /* Cars with no value for the chosen field sort to the END rather than the
     top, whichever direction is asked for. A car with no price is not the
     cheapest one on the floor. */
  function by(key, dir) {
    return function (a, b) {
      var x = a[key], y = b[key];
      if (!x && !y) return 0;
      if (!x) return 1;
      if (!y) return -1;
      return dir === 'desc' ? y - x : x - y;
    };
  }
  var SORTS = {
    'price-asc':  by('price', 'asc'),
    'price-desc': by('price', 'desc'),
    'year-desc':  by('year',  'desc'),
    'km-asc':     by('km',    'asc')
  };

  /* ---------------- paging ----------------
     Eight cars is about two rows on a desktop and a comfortable scroll on a
     phone — enough to show the range of the floor without asking anyone to
     wade through two dozen listings to reach the filters' effect.

     Batches are APPENDED rather than the whole list re-rendered. Re-rendering
     would replay the entrance on cars the visitor is already looking at, and
     would throw away the card they had picked. Only a filter or sort change
     clears the grid, because only then is the list itself different. */
  var list = [];        /* the filtered, sorted list */
  var shown = 0;        /* how many of it are in the DOM */

  var more   = $('#more');
  var moreN  = $('#more-n');
  var moreWrap = $('#more-wrap');

  function addBatch() {
    var next = list.slice(shown, shown + PAGE);
    var frag = document.createDocumentFragment();
    /* the stagger index is the position WITHIN the batch, so a second page
       steps in from the top of itself rather than starting nine beats late */
    next.forEach(function (c, i) { frag.appendChild(cardFor(c, i)); });
    grid.appendChild(frag);
    shown += next.length;
    syncMeta();
    return next.length;
  }

  function syncMeta() {
    empty.hidden = list.length > 0;

    /* the figure is wrapped so motion.css can pulse the number alone as the
       filters change — pulsing the whole sentence reads as a wobble */
    count.innerHTML = list.length
      ? fmt(esc(LABELS.showing || ''), '<b>' + shown + '</b>', list.length)
      : '';

    var left = list.length - shown;
    if (moreWrap) moreWrap.hidden = left <= 0;
    if (moreN) moreN.textContent = left > 0 ? fmt(LABELS.moreLeft, Math.min(PAGE, left)) : '';
  }

  function render() {
    /* The one guard that covers the whole feature, rather than a null check on
       every menu it reads. This file runs on the Contact page and on a car's
       page, and neither has a stock grid or the four filter menus that drive
       it; without this, the first `.value` on a null threw and took the rest
       of app.js down with it -- the reveal observer included, which is what
       left the Contact page's markup complete and its every word invisible.

       Returning early is right rather than defensive: no grid means there is
       nothing on this page for render() to draw. */
    if (!grid || !fMake || !fBody || !fPrice || !fSort) { return; }

    var maxPrice = fPrice.value ? Number(fPrice.value) : Infinity;

    /* Every word has to match, in any field and in any order, so "audi 2019"
       and "2019 audi" find the same car. Matching the whole phrase against one
       field would fail on both, which is how a search box teaches people it
       does not work. */
    var terms = fFind
      ? fFind.value.toLowerCase().split(/\s+/).filter(function (t) { return t; })
      : [];

    function hay(c) {
      return [c.make, c.model, c.year, c.body, c.fuel, c.trans]
        .join(' ').toLowerCase();
    }

    list = STOCK.filter(function (c) {
      if (terms.length) {
        var h = hay(c);
        for (var i = 0; i < terms.length; i++) {
          if (h.indexOf(terms[i]) === -1) { return false; }
        }
      }
      return (!fMake.value || c.make === fMake.value) &&
             (!fBody.value || c.body === fBody.value) &&
             c.price <= maxPrice;
    }).sort(SORTS[fSort.value] || SORTS['price-asc']);

    grid.innerHTML = '';
    shown = 0;
    addBatch();
  }

  if (more) {
    more.addEventListener('click', function () {
      var before = shown;
      var added = addBatch();
      if (!added) return;

      /* Focus the first card of the batch just revealed. Without it a keyboard
         or screen-reader user is left at a button that has just moved down the
         page, with no indication that eight listings appeared above it.
         tabindex -1 so it takes focus programmatically without joining the tab
         order as a stop of its own. */
      var first = grid.children[before];
      if (first) {
        first.setAttribute('tabindex', '-1');
        first.focus({ preventScroll: true });
      }
    });
  }

  /* Guarded, because this file now runs on pages that have no stock grid.
     The Contact page is one; a car's page is another. Unguarded, the first of
     these threw on a null and took EVERYTHING BELOW IT with it -- the reveal
     observer included, so every .reveal on the page stayed at opacity 0 and
     the Contact page rendered as four empty coloured bands. The page was
     complete in the markup and invisible on screen.

     A missing filter menu is not an error here, it is a page that does not
     have filters. */
  [fMake, fBody, fPrice, fSort].forEach(function (el) {
    if (el) { el.addEventListener('change', render); }
  });

  /* 'input' rather than 'change': the grid narrows as it is typed, which is
     the whole point of a search box over another menu. The list is already in
     memory, so there is nothing to debounce. */
  if (fFind) {
    fFind.addEventListener('input', render);
    /* A search box offers a clear cross; Escape should do the same. */
    fFind.addEventListener('keydown', function (ev) {
      if (ev.key === 'Escape' && fFind.value) {
        ev.stopPropagation();
        fFind.value = '';
        render();
      }
    });
  }

  var fReset = $('#f-reset');
  if (fReset) {
    fReset.addEventListener('click', function () {
      if (fMake) { fMake.value = ''; }
      if (fBody) { fBody.value = ''; }
      if (fPrice) { fPrice.value = ''; }
      if (fFind) { fFind.value = ''; }
      if (fSort) { fSort.value = 'price-asc'; }
      render();
      markMakes();
    });
  }

  /* ---------------- the strip of makes ----------------
     A second face on the Make menu rather than a second filter. Both write to
     the same select and both call the same render(), so they can never
     disagree about what is being shown -- and the menu, the Reset button and
     the strip all stay in step because every one of them ends up here.

     Delegated to the row, not bound per tile: the strip is server-rendered and
     never rebuilt, but delegation costs one listener instead of a dozen and
     survives the row being redrawn if it ever is. */
  var makesRow = $('#makes');
  var makeTiles = makesRow ? Array.prototype.slice.call(makesRow.querySelectorAll('.make')) : [];

  function markMakes() {
    if (!makesRow || !fMake) { return; }
    var cur = fMake.value || '';
    makeTiles.forEach(function (t) {
      var on = (t.getAttribute('data-make') || '') === cur;
      t.classList.toggle('is-on', on);
      t.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
  }

  if (makesRow && fMake) {
    makesRow.addEventListener('click', function (e) {
      var tile = (e.target && e.target.closest) ? e.target.closest('.make') : null;
      if (!tile) { return; }
      var make = tile.getAttribute('data-make') || '';

      /* Tapping the chosen make again clears it. Without this the only way
         back to everything is the "All makes" tile, which may have been
         scrolled off the left by then. */
      fMake.value = (make !== '' && fMake.value === make) ? '' : make;
      render();
      markMakes();

      /* Bring the cars into view, but only when they are not already there.
         Scrolling a page somebody is already looking at is the kind of help
         nobody asked for. */
      var top = $('#stock');
      if (top) {
        var box = top.getBoundingClientRect();
        if (box.top > window.innerHeight * 0.6 || box.bottom < 0) {
          top.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
      }
    });

    /* The menu is the other way in, so it has to write back to the strip. */
    fMake.addEventListener('change', markMakes);
    markMakes();
  }

  /* The strip's own scroll bar.
     Drawn rather than borrowed: Chrome's overlay scrollbars appear only while
     a scroll is in progress and ignore ::-webkit-scrollbar styling, so the
     native one cannot be relied on to say "there is more along here". This
     one is always visible while the row overflows, and hidden when it does
     not, because a track under a row that fits is a control for nothing. */
  if (makesRow) {
    var makesBar = $('#makes-bar');
    var makesThumb = makesBar ? makesBar.firstElementChild : null;

    var paintBar = function () {
      if (!makesBar || !makesThumb) { return; }
      var scroll = makesRow.scrollWidth;
      var seen = makesRow.clientWidth;
      if (scroll <= seen + 1) { makesBar.classList.remove('is-on'); return; }
      makesBar.classList.add('is-on');

      var ratio = seen / scroll;                       // how much of it is on screen
      var travel = scroll - seen;
      var at = travel > 0 ? (makesRow.scrollLeft / travel) : 0;
      /* translate is in unscaled units, so it moves by a share of the FULL
         track and the scale then squashes the thumb from the left. */
      makesThumb.style.transform =
        'translateX(' + (at * (1 - ratio) * 100).toFixed(3) + '%) scaleX(' + ratio.toFixed(4) + ')';
    };

    makesRow.addEventListener('scroll', paintBar, { passive: true });
    window.addEventListener('resize', paintBar);
    /* Fonts land after first paint and change how wide the tiles are, so the
       first measurement is taken again once things have settled. */
    paintBar();
    setTimeout(paintBar, 400);
    setTimeout(paintBar, 1400);
  }

  /* ---------------- the Spotlight ----------------
     The markup is a plain horizontal row of linked photographs. This turns it
     into a cover flow and starts it turning, and nothing else: if this
     function returns early at any point, what stays on the page is that row,
     scrolling sideways, with every link intact. That is the whole no-script
     story and it is also the reduced-motion story.

     TWO EITHER SIDE OF THE MIDDLE ONE. Five tiles, five positions, nothing
     hidden off the edge -- which is why the section is capped at five in the
     settings rather than clipped here. */
  (function () {
    var stage = $('#spot');
    var track = $('#spot-track');
    var nav = $('#spot-nav');
    if (!stage || !track) { return; }

    var items = Array.prototype.slice.call(track.querySelectorAll('.spot-item'));
    if (items.length < 3) { return; }

    var dots = nav ? Array.prototype.slice.call(nav.querySelectorAll('.spot-dot')) : [];

    /* The gate, read from the setting rather than from the browser alone --
       the same rule the opening, the film hero and the reveals answer to. Off,
       the Spotlight turns for everyone; on, somebody who has asked for less
       movement keeps the flat scroller, which is a complete and usable version
       of this: five photographs, five links, no motion at all. */
    var respectRM = !!(CFG && CFG.reduced);
    var wantsLess = false;
    if (respectRM) {
      try { wantsLess = !!(window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches); } catch (e) {}
    }
    if (wantsLess) { return; }

    var active = parseInt(stage.getAttribute('data-start'), 10);
    if (isNaN(active)) { active = Math.floor(items.length / 2); }

    /* Height has to be reserved before the flow lifts the tiles out of flow,
       or everything below jumps up by a tile's height the moment this runs.
       Measured from the tallest while they are still in normal flow -- which
       is the only moment they can be measured. The tiles carry an
       aspect-ratio, so this is right even before a photograph has landed. */
    var tallest = 0;
    items.forEach(function (el) { tallest = Math.max(tallest, el.offsetHeight); });
    var wide = items[0].offsetWidth;
    if (tallest) { stage.style.setProperty('--spot-h', tallest + 'px'); }
    if (wide) { stage.style.setProperty('--spot-w', wide + 'px'); }

    stage.classList.add('is-flow');
    if (nav) { nav.classList.add('is-on'); }

    var num = function (name, fallback) {
      var v = parseFloat(getComputedStyle(stage).getPropertyValue(name));
      return isNaN(v) ? fallback : v;
    };

    /* Where each tile sat last time, so a tile that has just gone round the
       back can be moved there without animating across the whole stage. */
    var was = [];

    function layout() {
      var half = items.length / 2;
      var tilt = num('--spot-tilt', 34);
      var shift = num('--spot-shift', 300);
      var gap = num('--spot-gap', 104);
      var depth = num('--spot-depth', 70);

      items.forEach(function (el, i) {
        /* The shortest way round the ring, not the plain difference. This is
           what makes two-either-side true at every position instead of only in
           the middle of the row. */
        var off = i - active;
        if (off > half) { off -= items.length; }
        if (off < -half) { off += items.length; }
        var abs = Math.abs(off);
        var dir = off === 0 ? 0 : (off > 0 ? 1 : -1);
        var x, ry, z;

        if (off === 0) {
          x = 0; ry = 0; z = depth;
        } else {
          x = dir * (shift + (abs - 1) * gap);
          ry = -dir * tilt;
          z = -abs * 44;
        }

        /* A tile that has just crossed from one end of the ring to the other
           would otherwise slide the full width of the stage to get there,
           which is the single thing a ring exists to avoid. That one move is
           made with the transition off; every other move keeps it. */
        var jumped = was[i] !== undefined && Math.abs(off - was[i]) > 1;
        if (jumped) { el.style.transition = 'none'; }
        el.style.transform = 'translate3d(' + x.toFixed(1) + 'px,0,' + z.toFixed(1) + 'px) rotateY(' + ry + 'deg)';
        if (jumped) {
          /* Reading a layout property forces the move to be applied before the
             transition goes back on, which is the whole trick. */
          void el.offsetWidth;
          el.style.transition = '';
        }
        was[i] = off;
        el.style.zIndex = String(100 - abs);
        el.setAttribute('data-side', String(off));

        /* Two out each side is the whole stage. At five tiles on a ring
           nothing is ever further than two from the middle, so this never
           fires at the size the settings allow -- it is here so that a set
           somebody widens later degrades into a flow rather than a pile.
           Hidden from the tab order too, so nobody tabs into a photograph
           they cannot see. */
        var gone = abs > 2;
        /* Faded by distance rather than one flat value for every side card.
           The numbers are for a LIGHT ground: on pearl a card at .16 has all
           but dissolved into the page, and the pair beyond the first has to
           stay substantial enough to read as a card turning away rather than
           as a smudge. They were .46 and .16 while this sat on black. */
        var fade = [1, 0.70, 0.38][Math.min(abs, 2)];
        el.style.opacity = gone ? '0' : String(fade);
        el.style.pointerEvents = gone ? 'none' : '';
        el.setAttribute('aria-hidden', gone ? 'true' : 'false');

        /* ONLY THE ONE FACING FORWARD CAN BE REACHED BY KEYBOARD. Its name is
           the only one drawn -- the side tiles' captions are at opacity 0 --
           and a link nobody can see is the worst thing to put in the tab
           order: focus lands somewhere off screen with nothing to read. The
           side tiles are still reachable, by the arrow keys that move the
           flow, which is what the stage announces itself as. */
        var lit = off === 0;
        var focusables = el.querySelectorAll('a,button');
        for (var f = 0; f < focusables.length; f++) {
          if (lit) { focusables[f].removeAttribute('tabindex'); }
          else { focusables[f].setAttribute('tabindex', '-1'); }
        }
      });

      for (var d = 0; d < dots.length; d++) {
        dots[d].setAttribute('aria-current', d === active ? 'true' : 'false');
      }
    }

    /* A tile brought forward should have its photograph by the time it
       arrives, so its neighbours stop being lazy. */
    function warm() {
      for (var i = Math.max(0, active - 1); i <= Math.min(items.length - 1, active + 1); i++) {
        var img = items[i].querySelector('img[loading="lazy"]');
        if (img) { img.removeAttribute('loading'); }
      }
    }

    function go(i) {
      var n = items.length;
      active = ((i % n) + n) % n;
      layout();
      warm();
    }

    /* ── the automatic turn ──────────────────────────────────────────────
       It goes round one way and keeps going. On a ring that is a single step
       like any other -- the tile leaving the far side reappears on the near
       side with its transition off, so there is no sweep and no rewind.

       Two different kinds of stop, deliberately not the same thing:

         stopped  somebody said so -- the pause button, or any deliberate move
                  of their own. It stays stopped until they say otherwise. A
                  carousel that shrugs off the visitor and carries on after a
                  few seconds is the thing everybody hates about carousels.
         busy()   hover, focus inside it, scrolled away, or the tab in the
                  background. Temporary, and it resumes by itself.

       The pause button is not a nicety either: content that starts moving on
       its own and keeps going needs a way to stop it, and hover is not one for
       somebody who is not using a mouse. */
    var HOLD = 2000;   /* asked for: two seconds a card */
    var timer = null;
    var stopped = false;
    var why = { hover: false, focus: false, away: false, buried: false };

    function busy() { return why.hover || why.focus || why.away || why.buried; }

    function beat() { go(active + 1); }

    function run() {
      if (timer) { window.clearInterval(timer); timer = null; }
      if (stopped || busy()) { return; }
      timer = window.setInterval(beat, HOLD);
    }

    /* Every deliberate move comes through here, so there is one place that
       decides what a deliberate move means -- and with the pause button gone
       this is the only thing that stops the turn for good. */
    function drive(i) {
      stopped = true;
      run();
      go(i);
    }

    function hold(key, on) { why[key] = on; run(); }

    stage.addEventListener('pointerenter', function () { hold('hover', true); });
    stage.addEventListener('pointerleave', function () { hold('hover', false); });
    stage.addEventListener('focusin', function () { hold('focus', true); });
    stage.addEventListener('focusout', function () { hold('focus', false); });
    document.addEventListener('visibilitychange', function () {
      hold('buried', !!document.hidden);
    });

    /* Nothing turns while it is off screen. Without this the Spotlight spends
       the whole page walking back and forth for nobody, and whoever scrolls
       back to it finds it somewhere they did not leave it. */
    if ('IntersectionObserver' in window) {
      why.away = true;
      new IntersectionObserver(function (entries) {
        hold('away', !entries[0].isIntersecting);
      }, { threshold: 0.25 }).observe(stage);
    }

    /* ── driving it by hand ─────────────────────────────────────────────── */

    stage.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowRight') { drive(active + 1); e.preventDefault(); }
      if (e.key === 'ArrowLeft') { drive(active - 1); e.preventDefault(); }
      if (e.key === 'Home') { drive(0); e.preventDefault(); }
      if (e.key === 'End') { drive(items.length - 1); e.preventDefault(); }
    });

    for (var d = 0; d < dots.length; d++) {
      dots[d].addEventListener('click', function (e) {
        var i = parseInt(e.currentTarget.getAttribute('data-go'), 10);
        if (!isNaN(i)) { drive(i); }
      });
    }

    /* wheel — only when the gesture is mostly horizontal, or the page can
       never be scrolled past this thing with a trackpad. */
    var wheelLock = false;
    stage.addEventListener('wheel', function (e) {
      if (Math.abs(e.deltaX) <= Math.abs(e.deltaY)) { return; }
      if (Math.abs(e.deltaX) < 4 || wheelLock) { return; }
      e.preventDefault();
      wheelLock = true;
      drive(active + (e.deltaX > 0 ? 1 : -1));
      window.setTimeout(function () { wheelLock = false; }, 170);
    }, { passive: false });

    /* drag and swipe

       THE POINTER IS CAPTURED ONLY ONCE A DRAG HAS ACTUALLY STARTED, and that
       is the whole reason a tap on a side tile now works. Capturing on
       pointerdown -- which is what this did -- retargets the click that
       follows to the element holding the capture, so every click arrived at
       the stage with the tile nowhere in its path: `closest('.spot-item')`
       found nothing, the handler below returned, and clicking a side tile did
       exactly nothing. It also meant the middle tile's link could not be
       opened with a mouse at all. Capturing at six pixels keeps the drag
       working past the edge of the stage and leaves an ordinary click alone. */
    var startX = null, startIdx = 0, moved = 0, caught = null;
    stage.addEventListener('pointerdown', function (e) {
      startX = e.clientX; startIdx = active; moved = 0; caught = null;
    });
    stage.addEventListener('pointermove', function (e) {
      if (startX === null) { return; }
      var dx = e.clientX - startX;
      moved = Math.max(moved, Math.abs(dx));
      if (moved <= 6) { return; }
      if (caught === null) {
        caught = e.pointerId;
        try { stage.setPointerCapture(e.pointerId); } catch (err) {}
      }
      drive(startIdx - Math.round(dx / (num('--spot-gap', 118) + 30)));
    });
    ['pointerup', 'pointercancel'].forEach(function (ev) {
      stage.addEventListener(ev, function (e) {
        startX = null;
        if (caught !== null) {
          try { stage.releasePointerCapture(e.pointerId); } catch (err) {}
          caught = null;
        }
      });
    });

    /* Click a side tile to bring it forward. The middle one is left alone so
       its link opens the car, which is the whole point of it -- and a drag
       that ends on a tile is not a click. */
    track.addEventListener('click', function (e) {
      if (moved > 6) { e.preventDefault(); moved = 0; return; }
      var item = e.target.closest ? e.target.closest('.spot-item') : null;
      if (!item) { return; }
      var i = items.indexOf(item);
      if (i < 0 || i === active) { return; }
      e.preventDefault();
      drive(i);
    });

    window.addEventListener('resize', layout);
    layout();
    warm();
    run();
  })();

  render();

  /* ══ picking a car ══════════════════════════════════════════════════════════
     Clicking a card marks it and plays the cue. Delegated to the grid rather
     than bound per card, because the grid is rebuilt wholesale on every filter
     change and per-card listeners would have to be re-bound each time — and
     would leak the ones belonging to cards that no longer exist.

     The selection is deliberately not carried across a re-render. A car picked
     out of one filtered list is not still picked when the list is something
     else, and rebuilding the grid clears it for free. */
  (function () {
    var sfx = $('#sfx-pick');
    var btn = $('#sound');
    var lbl = $('#sound-lbl');

    /* Only the front page has a sound toggle. On the Contact page and on a
       car's page these are null, and paint() went straight at btn -- throwing
       before the reveal observer further down had been set up, which is what
       left a fully-rendered Contact page with every word at opacity 0. */
    if (!btn || !lbl) { return; }

    /* The mute survives a reload where storage is available. file:// pages are
       denied localStorage in some browsers and throw on the read, not just the
       write, so both ends are wrapped rather than the setter alone. */
    var KEY = 'vesla-sound';
    var on = true;
    try { on = window.localStorage.getItem(KEY) !== 'off'; } catch (e) {}

    function paint() {
      btn.setAttribute('aria-pressed', String(on));
      lbl.textContent = on ? (LABELS.soundOn || 'Sound on') : (LABELS.soundOff || 'Sound off');
    }
    paint();

    btn.addEventListener('click', function () {
      on = !on;
      paint();
      try { window.localStorage.setItem(KEY, on ? 'on' : 'off'); } catch (e) {}
      if (!on && sfx) sfx.pause();
    });

    /* ── the cue ──

       Two problems with playing the file straight:

       THE SILENCE. The recording opens with about a quarter of a second of
       nothing before the sound starts. Played from zero, the cue arrives a
       beat after the click and reads as lag rather than as feedback. The
       start is found by decoding the file and looking for the first sample
       that is actually audible — measured, not guessed at, so replacing the
       file with another one does not put the delay back.

       THE LATENCY. <audio>.play() returns a promise and the browser schedules
       it; Web Audio plays from a buffer it already holds, on the next audio
       frame. Decoded once, then every cue is immediate.

       The <audio> element stays as the fallback, and it is what plays until
       the buffer is ready. */
    var actx = null, abuf = null, aoff = 0, asrc = null, wanted = 0;

    function prepare() {
      var AC = window.AudioContext || window.webkitAudioContext;
      if (!AC || actx || !sfx || !window.fetch) return;
      try { actx = new AC(); } catch (e) { return; }
      fetch(sfx.currentSrc || sfx.src)
        .then(function (r) { return r.arrayBuffer(); })
        .then(function (b) { return actx.decodeAudioData(b); })
        .then(function (buf) {
          abuf = buf;
          var d = buf.getChannelData(0), peak = 0, i;
          for (i = 0; i < d.length; i++) { var v = d[i] < 0 ? -d[i] : d[i]; if (v > peak) peak = v; }
          var floor = peak * 0.02;
          for (i = 0; i < d.length; i++) {
            if ((d[i] < 0 ? -d[i] : d[i]) > floor) { aoff = i / buf.sampleRate; break; }
          }
          /* a hair before the first audible sample, so the attack is not clipped */
          aoff = Math.max(0, aoff - 0.01);

          /* A cue that was asked for while this was still decoding.

             Somebody whose first action on the page is to pick a car asks
             for the sound before there is a buffer to play, and the wait is
             a few hundred milliseconds at most. Playing it now is honest:
             it is the cue they asked for, only just late. Past a second it
             is not, so it is dropped rather than fired at somebody who has
             moved on. */
          if (wanted && Date.now() - wanted < 1000) { wanted = 0; cue(); }
          wanted = 0;
        })
        .catch(function () { abuf = null; });
    }

    function cue() {
      if (!on || !sfx) return;
      /* Asked for before the buffer arrived: remembered, so the decode can
         play it the moment it finishes rather than dropping it. */
      if (actx && !abuf) { wanted = Date.now(); }
      if (actx && abuf) {
        try {
          if (actx.state === 'suspended') actx.resume();
          /* One cue at a time.

             The cue runs about three quarters of a second. Every call used
             to make a fresh source and start it, so picking a second car
             while the first was still sounding left both playing over each
             other -- which is heard as the sound repeating rather than as
             two cues. The element path below already replaced the previous
             cue instead of queueing behind it; this is the same rule, which
             is what it should have been all along.

             A source that has already finished throws when stopped, and a
             cue is not worth an exception. */
          if (asrc) { try { asrc.stop(); } catch (e) {} }
          var src = actx.createBufferSource();
          src.buffer = abuf;
          src.connect(actx.destination);
          src.onended = function () { if (asrc === src) { asrc = null; } };
          src.start(0, aoff);
          asrc = src;
          return;
        } catch (e) {}
      }
      /* Fallback: the element, wound past the silence rather than to zero.
         Rewound rather than restarted, so picking a second car replaces the
         first cue instead of queueing behind it. */
      try {
        /* Only seek where the element can seek without going back to the
           network. Setting currentTime on a element that has not buffered
           that position drops it to readyState 1 and it plays nothing. */
        if (sfx.readyState >= 3) { sfx.currentTime = aoff || 0.26; }
        var p = sfx.play();
        if (p && p.catch) p.catch(function () {});
      } catch (e) {}
    }

    /* Decode as soon as the page is quiet, NOT on the first gesture.

       It used to wait for a gesture, and the first gesture was usually the
       click being cued -- so the buffer was still decoding at the moment it
       was wanted and the cue fell through to the <audio> element, which is
       exactly where it is least reliable: setting currentTime to skip the
       leading silence drops readyState from 4 to 1 and the element has to
       buffer again at the new position before anything is heard. On a fresh
       page load that is most of the time, which is why the cue seemed to
       fire once in a very long while rather than on every pick.

       Decoding needs no gesture; only starting audio does. So the context is
       built at once -- suspended, which browsers allow -- the file is
       fetched and decoded straight away, and the first gesture only lifts
       the suspension. By the time anybody has picked a car, the buffer is
       there. */
    if ('requestIdleCallback' in window) {
      requestIdleCallback(prepare, { timeout: 2000 });
    } else {
      setTimeout(prepare, 400);
    }
    ['pointerdown', 'keydown', 'touchstart'].forEach(function (ev) {
      window.addEventListener(ev, function () {
        prepare();   // in case the idle callback has not run yet
        if (actx && actx.state === 'suspended') { actx.resume(); }
      }, { once: true, passive: true });
    });

    grid.addEventListener('click', function (e) {
      /* Every click on a card lands on an anchor now: the title's link is
         stretched across the whole card, so hit-testing anywhere on it
         returns that link. This used to bail on any anchor at all, which was
         right when only the buttons were links — and silently killed the cue
         the moment the card itself became one.

         So: the card's own link counts as picking the car. Enquire and
         WhatsApp do not — those are somebody leaving for something else. */
      var hit = e.target.closest('a');
      if (hit && !hit.classList.contains('card-link')) return;

      var card = e.target.closest('.card');
      if (!card || !grid.contains(card)) return;

      var already = card.classList.contains('picked');
      Array.prototype.forEach.call(grid.children, function (c) {
        c.classList.remove('picked');
      });
      /* clicking the picked car again lets it go, and does so in silence — the
         cue marks a choice being made, not the absence of one */
      if (already) return;

      card.classList.add('picked');
      cue();
    });
  })();

  /* ---------------- valuation estimator ----------------
     Indicative only: takes the median price of that make in stock,
     depreciates by age, then applies mileage and condition factors. */
  var eMake = $('#e-make');
  var eYear = $('#e-year');
  var eKm   = $('#e-km');
  var eCond = $('#e-cond');
  var eOut  = $('#e-out');

  /* The estimator belongs to the "sell us your car" section. A page without
     that section has none of these, and every line below would throw on the
     first of them. */
  var eOK = !!( eMake && eYear && eKm && eCond && eOut );

  fillSelect(eMake, uniq('make'));

  var THIS_YEAR = new Date().getFullYear();
  /* How far back the Year menu goes is a setting: a dealer taking older cars
     in part-exchange needs more than the sixteen years this used to assume. */
  var YEAR_RANGE = Math.max(1, parseInt((CFG.estimator || {}).yearRange, 10) || 16);
  if (eOK) {
    for (var y = THIS_YEAR; y >= THIS_YEAR - YEAR_RANGE; y--) {
      var o = document.createElement('option');
      o.value = y;
      o.textContent = y;
      if (y === THIS_YEAR - 4) o.selected = true;
      eYear.appendChild(o);
    }
  }

  function medianPrice(make) {
    var prices = STOCK.filter(function (c) { return c.make === make; })
                      .map(function (c) { return c.price; })
                      .sort(function (a, b) { return a - b; });
    /* No usable prices means no basis for an estimate. Returning a made-up
       number would be worse than returning none: it is presented to a visitor
       as an indication of what their car is worth. */
    if (!prices.length) return 0;
    var mid = Math.floor(prices.length / 2);
    return prices.length % 2 ? prices[mid] : (prices[mid - 1] + prices[mid]) / 2;
  }

  function estimate() {
    var base = medianPrice(eMake.value);
    var age  = Math.max(0, THIS_YEAR - Number(eYear.value));

    /* The yearly drop is a setting, floored so an old car keeps a sane
       residual rather than trending towards nothing. */
    var est   = CFG.estimator || {};
    var rate  = Math.min(0.4, Math.max(0, (est.depreciation || 11) / 100));
    /* The floor is a setting too. The yearly drop compounds towards nothing,
       and a fifteen-year-old car is not worth nothing — where that floor sits
       is a judgement about this market, so it belongs to whoever knows it. */
    var floor = Math.min(0.9, Math.max(0.01, (est.floor || 18) / 100));
    var aged  = base * Math.max(floor, Math.pow(1 - rate, age));
    var mid  = aged * Number(eKm.value) * Number(eCond.value);

    /* With no basis there is no range. Showing a dash is honest; showing a
       number derived from nothing is not, and this figure is read by
       somebody deciding what their car is worth. */
    /* How wide the range is, either side of the calculated figure. A setting
       rather than a constant: how much a valuer will commit to without seeing
       the car is a judgement about this market, and 8% was mine, not theirs.
       Clamped so a mistyped setting cannot invert the range or make it absurd. */
    var spread = Math.min(0.4, Math.max(0, (est.spread == null ? 8 : est.spread) / 100));

    eOut.textContent = mid > 0
      ? aed(mid * (1 - spread)) + ' – ' + aed(mid * (1 + spread))
      : '—';
  }

  if (eOK) {
    [eMake, eYear, eKm, eCond].forEach(function (el) {
      el.addEventListener('change', estimate);
    });
    estimate();
  }

  /* ---------------- sending an estimate in ----------------
     The estimator answered a question and asked nothing back. Somebody who had
     just described a car they want to sell -- make, year, mileage, condition --
     left again without us knowing they had been here, which is a lead walking
     out of a feature that works.

     What they were shown goes with the enquiry. Ringing back to ask them to
     describe the car a second time is how you lose the ones who bothered. */
  var eSend = $('#s-go');
  var eOutMsg = $('#s-out');

  function estDetails() {
    /* Labels rather than keys: this is read by a person in an email and in the
       admin, never computed with, and the wording on screen is the wording
       that means something to them. */
    var pick = function (el) {
      return el && el.options && el.options[el.selectedIndex]
        ? el.options[el.selectedIndex].textContent.trim() : '';
    };
    var d = {};
    d[msg('est_d_make', 'Make')]      = pick(eMake);
    d[msg('est_d_year', 'Year')]      = pick(eYear);
    d[msg('est_d_km', 'Mileage')]     = pick(eKm);
    d[msg('est_d_cond', 'Condition')] = pick(eCond);
    d[msg('est_d_est', 'Estimate shown')] = eOut ? eOut.textContent.trim() : '';
    return d;
  }

  if (eOK && eSend && eOutMsg) {
    var estForm = $('#est');

    function estFail(message) {
      eSend.disabled = false;
      eOutMsg.className = 'form-msg bad';
      eOutMsg.textContent = message || LABELS.sendFail || 'That did not send — please call us instead.';
    }

    function estSend(nonce, retriedOnce) {
      var body = new FormData();
      body.append('action', 'vesla_enquiry');
      body.append('nonce', nonce);
      body.append('name',  ($('#s-name')  || {}).value || '');
      body.append('phone', ($('#s-phone') || {}).value || '');
      body.append('email', ($('#s-email') || {}).value || '');
      body.append('car', '');
      body.append('message', '');
      body.append('website', ($('#s-website') || {}).value || '');
      body.append('type', 'trade_in');
      body.append('details', JSON.stringify(estDetails()));

      fetch(CFG.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
        .then(function (r) { return r.json().catch(function () { return null; }); })
        .then(function (res) {
          var data = (res && res.data) || {};

          /* The same one-retry nonce recovery the enquiry form does, for the
             same reason: this page may have been cached for longer than a
             nonce lives. */
          if (res && !res.success && data.code === 'stale_nonce' && !retriedOnce) {
            fetch(CFG.ajaxUrl + '?action=vesla_refresh_nonce', { credentials: 'same-origin' })
              .then(function (r) { return r.json(); })
              .then(function (fresh) {
                if (fresh && fresh.success && fresh.data && fresh.data.nonce) {
                  estSend(fresh.data.nonce, true);
                } else { estFail(data.message); }
              })
              .catch(function () { estFail(data.message); });
            return;
          }

          eSend.disabled = false;

          if (res && res.success) {
            eOutMsg.className = 'form-msg ok';
            eOutMsg.textContent = data.message || CFG.formOk || '';
            ['#s-name', '#s-phone', '#s-email'].forEach(function (id) {
              var el = $(id);
              if (el) { el.value = ''; setFieldError(el, ''); }
            });
            return;
          }

          /* The server names its fields q-name, q-phone, q-email whichever form
             they came from. Mapped onto this form's boxes so the verdict lands
             against the box that caused it. */
          if (data.fields) {
            var map = { 'q-name': 's-name', 'q-phone': 's-phone', 'q-email': 's-email' };
            var firstEl = null;
            Object.keys(data.fields).forEach(function (id) {
              var el = $('#' + (map[id] || id));
              if (el) {
                setFieldError(el, data.fields[id]);
                if (!firstEl) { firstEl = el; }
              }
            });
            if (firstEl) { firstEl.focus(); }
          }
          estFail(data.message);
        })
        .catch(function () { estFail(''); });
    }

    /* The estimator is a <form>, so Enter in a box submits it. Catching submit
       rather than the button's click means the keyboard works too. */
    if (estForm) {
      estForm.addEventListener('submit', function (ev) {
        ev.preventDefault();
        eSend.disabled = true;
        eOutMsg.className = 'form-msg';
        eOutMsg.textContent = LABELS.sending || 'Sending…';
        /* One nonce on the page, in the enquiry form; ids are unique, so this
           finds it from here without reaching into that form's scope. */
        var el = $('#vesla_nonce');
        estSend(el ? el.value : '', false);
      });
    }
  }

  /* ---------------- enquiry form ----------------
     Checked here as the visitor types, then posted to WordPress, which checks
     everything again before it is trusted. */
  var enq = $('#enq');
  var out = $('#q-out');

  /* ---------------- arriving with something already said ----------------
     A car's page has no enquiry form on it: Enquire and the finance quote both
     send the visitor here, to #contact. vehicle.js has always written the car
     into sessionStorage on the way out -- deliberately, so the address stays
     clean and shareable -- but nothing on this side ever read it back, so the
     form it arrived at was empty every time and the buyer retyped the name of
     the car they had just been looking at.

     Read defensively and cleared once used: a stale car from an hour ago
     attaching itself to an unrelated enquiry is worse than an empty box. */
  if (enq) {
    try {
      var carried = sessionStorage.getItem('vesla-enq');
      sessionStorage.removeItem('vesla-enq');
      /* The older key, from before this carried anything but a name. */
      var legacy = sessionStorage.getItem('vesla-car');
      sessionStorage.removeItem('vesla-car');

      var got = carried ? JSON.parse(carried) : (legacy ? { car: legacy } : null);

      if (got && typeof got === 'object') {
        var cEl = $('#q-car');
        if (cEl && typeof got.car === 'string') { cEl.value = got.car.slice(0, 80); }

        var tEl = $('#q-type');
        if (tEl && typeof got.type === 'string') { tEl.value = got.type; }

        /* Rebuilt from scratch rather than passed along, so only a flat object
           of short strings can reach the post body. */
        var dEl = $('#q-details');
        if (dEl && got.details && typeof got.details === 'object' && !Array.isArray(got.details)) {
          var clean = {};
          Object.keys(got.details).slice(0, 12).forEach(function (k) {
            var v = got.details[k];
            if (typeof v === 'string' || typeof v === 'number') {
              clean[String(k).slice(0, 40)] = String(v).slice(0, 120);
            }
          });
          dEl.value = JSON.stringify(clean);
        }
      }
    } catch (e) {
      /* Private mode refuses sessionStorage outright; an empty form is the
         right outcome, not a dead page. */
    }
  }

  /* Every field is checked on its own and says what is wrong with it, in its
     own place. A single line at the bottom of the form saying "please check
     your details" leaves the reader hunting for which one.

     Each rule returns an error string or '' for valid. Optional fields return
     '' when empty — only `required` decides whether empty is an error. */
  var RULES = {
    'q-name': {
      label: 'Name',
      required: msg('err_name_required', 'Please tell us your name.'),
      test: function (v) {
        if (v.length < lim('nameMin', 2)) return msg('err_name_short', 'That looks too short — please give your full name.');
        if (v.length > lim('nameMax', 60)) return msg('err_name_long', 'Please keep the name under 60 characters.');
        /* letters in any script, plus the punctuation that genuinely occurs in
           names. Digits and symbols are what a bot fills this with. */
        if (!/^[\p{L}\p{M}][\p{L}\p{M}\s'’.-]*$/u.test(v)) {
          return msg('err_name_letters', 'Please use letters only — no digits or symbols.');
        }
        return '';
      }
    },
    'q-phone': {
      label: 'Phone',
      required: msg('err_phone_required', 'We need a number to call you back on.'),
      /* A phone box that accepts letters and only complains once you have left
         it is not a phone box. `strip` is the set of characters that may NOT
         appear, and anything matching it is removed as it is typed or pasted —
         so a letter never lands in the field in the first place.

         `type="tel"` does not do this on its own: it is a keyboard hint on a
         phone and nothing at all on a desktop, where the field accepts any
         text the same as `type="text"`. */
      strip: /[^0-9+()\-\s]/,
      stripMsg: msg('err_phone_letters', 'Numbers only — letters are not part of a phone number.'),
      test: function (v) {
        var digits = v.replace(/\D/g, '');
        if (digits.length < lim('phoneMin', 7)) return msg('err_phone_short', 'That number is too short to dial.');
        if (digits.length > lim('phoneMax', 15)) return msg('err_phone_long', 'That number is too long — check for extra digits.');
        /* the local habit is to write 05x…; both that and +9715x… are fine */
        if (v.trim().charAt(0) === '+' && digits.length < 8) return msg('err_phone_country', 'An international number needs its country code.');
        return '';
      }
    },
    'q-email': {
      label: 'Email',
      test: function (v) {
        if (v.length > lim('emailMax', 254)) return msg('err_email_long', 'That address is longer than an email address can be.');
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(v)) return msg('err_email_invalid', 'That email address does not look right.');
        return '';
      }
    },
    'q-car': {
      label: 'Car of interest',
      test: function (v) {
        if (v.length > lim('carMax', 80)) return msg('err_car_long', 'Please keep this under 80 characters.');
        return '';
      }
    },
    'q-note': {
      label: 'Message',
      test: function (v) {
        if (v.length > lim('noteMax', 1000)) return msg('err_note_long', 'Please keep the message under 1000 characters.');
        return '';
      }
    }
  };

  /* One rule applied to every field, ahead of the field's own.

     These values are posted to the server, put into an email and stored, and
     every one of these checks is run again there — this copy is not the
     control, it is what saves the visitor a round trip. Something that looks
     like markup or a script in a name field is never a genuine enquiry.

     Rejected with a message rather than silently stripped, so nobody's text is
     quietly altered underneath them. The one exception is the phone box, where
     letters are removed as they are typed because no phone number contains
     one. */
  function injectionError(v) {
    /* One message for all three, because the server answers with one — three
       different explanations here and a fourth from the server was how the
       same rejected value could be described four different ways. */
    var blocked = msg('err_blocked', 'Please remove any code or angle brackets from this field.');
    if (/[<>]/.test(v)) return blocked;
    if (/\b(?:javascript|data|vbscript)\s*:/i.test(v)) return blocked;
    if (/\son\w+\s*=/i.test(v)) return blocked;
    /* control characters, including the CR/LF that would let someone forge
       extra headers if this is ever posted somewhere less forgiving */
    if (/[\u0000-\u0008\u000B\u000C\u000E-\u001F\u007F]/.test(v)) return blocked;
    return '';
  }

  function setFieldError(el, msg) {
    var slot = $('#e-' + el.id);
    el.setAttribute('aria-invalid', msg ? 'true' : 'false');
    el.classList.toggle('is-bad', !!msg);
    if (!slot) return;
    /* textContent, never innerHTML: the message can quote what the visitor
       typed, and writing that back as markup would be the exact hole the
       checks above exist to close */
    slot.textContent = msg || '';
    slot.classList.toggle('on', !!msg);
  }

  function validateField(el) {
    var rule = RULES[el.id];
    if (!rule) return '';
    var v = el.value.trim();
    var msg = '';
    if (!v) msg = rule.required || '';
    else msg = injectionError(v) || rule.test(v);
    setFieldError(el, msg);
    return msg;
  }

  var fields = Object.keys(RULES).map(function (id) { return $('#' + id); }).filter(Boolean);

  /* Removes any character the field's `strip` set forbids, and puts the caret
     back where the typist left it.

     The caret matters more than it looks: without restoring it, `el.value = …`
     drops the cursor to the end of the field, so anyone correcting a digit in
     the middle of a number gets thrown to the end on the next keystroke. The
     new position is the old one minus however many characters were removed
     from in front of it.

     `rule.strip` is deliberately NOT a global regex — `.test` on a global one
     carries `lastIndex` between calls and would skip every other character. A
     separate global copy is used for the replace. */
  function stripField(el, rule) {
    if (!rule.strip) return false;
    var before = el.value;
    var clean = before.replace(new RegExp(rule.strip.source, 'g'), '');
    if (clean === before) return false;

    var caret = el.selectionStart;
    var removedBefore = 0;
    for (var i = 0; i < caret && i < before.length; i++) {
      if (rule.strip.test(before.charAt(i))) removedBefore++;
    }
    el.value = clean;
    var pos = Math.max(0, caret - removedBefore);
    try { el.setSelectionRange(pos, pos); } catch (e) {}
    return true;
  }

  fields.forEach(function (el) {
    var rule = RULES[el.id];

    el.addEventListener('input', function () {
      /* Rejected characters are removed silently but not invisibly — the field
         says why, or a typist who pasted a number with letters in it just sees
         characters vanish and cannot tell whether the field is broken. */
      if (stripField(el, rule)) {
        setFieldError(el, rule.stripMsg || msg('err_phone_letters', 'Some characters were removed.'));
        return;
      }

      /* Live, but not nagging. While a field is still empty there is nothing
         to be wrong about, so the `required` message is left for blur and
         submit; anything actually typed is checked on every keystroke, so the
         error appears as soon as the value is wrong and clears the moment it
         is right — rather than waiting for the field to be left. */
      if (el.value.trim()) validateField(el);
      else setFieldError(el, '');
    });

    /* Leaving a field is when `required` finally applies. */
    el.addEventListener('blur', function () { validateField(el); });
  });

  /* Guarded, and the guard is load-bearing.

     Certified, Sell, About, Stock, Privacy and Terms all load this file and
     none of them carries the enquiry form -- it lives in the contact section,
     which those pages do not render. An unguarded null here threw, and
     because the whole file is one IIFE the throw took everything below it:
     the scroll-reveal observer among them, so every .reveal on those pages
     stayed at opacity 0 and the pages rendered blank for anybody with
     scripting on. With scripting OFF they were fine, which is exactly why a
     no-JavaScript check did not catch it.

     Written as a single-statement if so the handler below keeps its
     indentation: the whole addEventListener call is one statement. */
  if (enq) enq.addEventListener('submit', function (ev) {
    ev.preventDefault();

    var firstBad = null;
    fields.forEach(function (el) {
      if (validateField(el) && !firstBad) firstBad = el;
    });

    if (firstBad) {
      out.className = 'form-msg bad';
      var count = fields.filter(function (el) { return el.classList.contains('is-bad'); }).length;
      out.textContent = count === 1
        ? msg('err_summary_one', 'One field needs attention — see the note above.')
        : fmt(msg('err_summary_many', '%s fields need attention — see the notes above.'), count);
      firstBad.focus();
      return;
    }

    var name  = $('#q-name').value.trim();
    var phone = $('#q-phone').value.trim();
    var email = $('#q-email').value.trim();
    var car   = $('#q-car').value.trim();
    var note  = $('#q-note').value.trim();

    /* Posted to WordPress rather than handed to a mailto: link.

       The old link opened the visitor's mail program, which loses the enquiry
       outright on any machine without one set up — and on a phone that is most
       of them. Posting means the lead reaches the showroom whatever the visitor
       has installed, and the server checks every value again before it is
       trusted: what the browser validates is a courtesy to the person filling
       the form in, never a control. */
    var btn = enq.querySelector('button[type=submit]');

    function payload(nonce) {
      var body = new FormData();
      body.append('action', 'vesla_enquiry');
      body.append('nonce', nonce);
      body.append('name', name);
      body.append('phone', phone);
      body.append('email', email);
      body.append('car', car);
      body.append('message', note);
      /* the hidden field no person can see; anything in it came from a bot */
      body.append('website', (enq.querySelector('#q-website') || {}).value || '');
      /* What kind of enquiry, and whatever figures the visitor was looking at.
         Both are hidden inputs the page fills in -- pressing Enquire on a car,
         or arriving from a finance quote -- so the server is told rather than
         made to guess from whether a car was named. */
      body.append('type', (enq.querySelector('#q-type') || {}).value || 'general');
      body.append('details', (enq.querySelector('#q-details') || {}).value || '');
      return body;
    }

    function currentNonce() {
      var el = enq.querySelector('#vesla_nonce');
      return el ? el.value : '';
    }

    if (btn) { btn.disabled = true; }
    out.className = 'form-msg';
    out.textContent = LABELS.sending || 'Sending…';

    /* `retried` is what keeps the stale-nonce recovery below from becoming a
       loop: exactly one second attempt, then the error is shown like any other. */
    var retried = false;

    function send(nonce) {
      fetch(CFG.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: payload(nonce) })
        .then(function (r) { return r.json().catch(function () { return null; }); })
        .then(function (res) {
          var data = (res && res.data) || {};

          /* ── the cached-page case ──
             Most cPanel hosts run a full-page cache. A cached page is served
             with the nonce that was minted when it was cached, so once that
             nonce ages out EVERY visitor gets a rejection — and the enquiry is
             lost at exactly the moment the site is busiest enough to be cached.

             The server marks that case `stale_nonce` rather than failing
             generically, so a fresh nonce can be fetched and the submission
             repeated once. The visitor sees nothing but the sending message they were
             already looking at. */
          if (res && !res.success && data.code === 'stale_nonce' && !retried) {
            retried = true;
            fetch(CFG.ajaxUrl + '?action=vesla_refresh_nonce', { credentials: 'same-origin' })
              .then(function (r) { return r.json(); })
              .then(function (fresh) {
                if (fresh && fresh.success && fresh.data && fresh.data.nonce) {
                  var el = enq.querySelector('#vesla_nonce');
                  if (el) { el.value = fresh.data.nonce; }
                  send(fresh.data.nonce);
                } else {
                  fail(data.message);
                }
              })
              .catch(function () { fail(data.message); });
            return;
          }

          if (btn) { btn.disabled = false; }

          if (res && res.success) {
            out.className = 'form-msg ok';
            out.textContent = data.message || CFG.formOk || '';
            enq.reset();
            /* clear any error still showing against a field the visitor fixed */
            fields.forEach(function (el) { setFieldError(el, ''); });
            return;
          }

          /* The server can reject a field the browser let through — a name of
             two spaces, say. Its verdict is shown against the same field, in
             the same place, so there is never a second style of error message. */
          if (data.fields) {
            Object.keys(data.fields).forEach(function (id) {
              var el = $('#' + id);
              if (el) { setFieldError(el, data.fields[id]); }
            });
            var first = $('#' + Object.keys(data.fields)[0]);
            if (first) { first.focus(); }
          }
          fail(data.message);
        })
        .catch(function () {
          /* A network failure must not look like a successful send. */
          fail('');
        });
    }

    function fail(message) {
      if (btn) { btn.disabled = false; }
      out.className = 'form-msg bad';
      out.textContent = message || LABELS.sendFail || 'That did not send — please call us instead.';
    }

    send(currentNonce());
  });

  /* ---------------- header, nav, scroll ---------------- */
  var bar    = $('#bar');
  var nav    = $('#nav');
  var burger = $('#burger');
  var totop  = $('#totop');
  var actbar = $('#actbar');

  /* A computed cascade rather than a delay class per link: the drawer opens
     with its items stepping in one after another, and adding a nav entry needs
     no new CSS. Set once — the values do not change with the drawer's state. */
  $$('#nav a').forEach(function (a, i) {
    a.style.transitionDelay = (0.03 + i * 0.035) + 's';
  });

  function setNav(open) {
    nav.classList.toggle('is-open', open);
    burger.setAttribute('aria-expanded', String(open));
  }

  burger.addEventListener('click', function () {
    setNav(!nav.classList.contains('is-open'));
  });

  $$('#nav a').forEach(function (a) {
    a.addEventListener('click', function () { setNav(false); });
  });

  /* Escape closes it and puts the focus back on the control that opened it,
     rather than leaving the reader's place inside a panel that is gone. */
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && nav.classList.contains('is-open')) {
      setNav(false);
      burger.focus();
    }
  });

  /* Crossing back to the desktop layout closes it too. The drawer is a
     max-width:820px construct; left open, its links would still be carrying
     the open state's transform into a layout that never shows a drawer. */
  var wide = window.matchMedia('(min-width:821px)');
  var syncNav = function (e) { if (e.matches) setNav(false); };
  if (wide.addEventListener) wide.addEventListener('change', syncNav);
  else if (wide.addListener) wide.addListener(syncNav);
  syncNav(wide);

  /* Same guard, same reason as the enquiry form above: this is the floating
     chrome, and a page that does not render it left totop null -- which threw,
     and took the scroll handlers, the reveal observer and the router with it. */
  if (totop) totop.addEventListener('click', function () {
    window.scrollTo({ top: 0, behavior: reduced ? 'auto' : 'smooth' });
  });

  /* One listener and one frame for all three, rather than each scheduling its
     own. motion.js keeps a second one for the properties motion.css reads;
     these are the classes this file owns. */
  var ticking = false;
  var wasStuck = null, wasTop = null, wasAct = null;

  /* Both thresholds are a percentage of ONE SCREEN HEIGHT, not a pixel count.
     700px was most of the way down a phone and barely half of a desktop, so
     the same number meant two different things — read against the window it is
     actually in, it means the same thing everywhere. Recomputed per call
     rather than cached because the viewport height changes when a phone's
     address bar hides. */
  var SCROLL = CFG.scroll || {};
  function pctOfScreen(v, fallback) {
    var pct = parseInt(v, 10);
    if (!isFinite(pct) || pct <= 0) pct = fallback;
    return (window.innerHeight || 800) * (pct / 100);
  }
  function totopAt()  { return pctOfScreen(SCROLL.totopAt, 80); }
  function actbarAt() { return pctOfScreen(SCROLL.actbarAt, 60); }

  /* Each class is written only when its state actually flips. Calling
     classList.toggle every frame with the value it already holds still
     invalidates style for that element's subtree sixty times a second, for
     nothing — with a fixed, backdrop-filtered header that alone was enough to
     make scrolling feel heavy. */
  function onScroll() {
    ticking = false;
    var y = window.scrollY;

    var stuck = y > 12;
    if (stuck !== wasStuck) { bar.classList.toggle('is-stuck', stuck); wasStuck = stuck; }

    var top = y > totopAt();
    if (top !== wasTop) { totop.classList.toggle('is-on', top); wasTop = top; }

    /* the phone action bar waits until the hero is behind us, so it never
       covers the first thing a visitor sees */
    var act = y > actbarAt();
    if (actbar && act !== wasAct) { actbar.classList.toggle('is-on', act); wasAct = act; }
  }
  window.addEventListener('scroll', function () {
    if (!ticking) { ticking = true; requestAnimationFrame(onScroll); }
  }, { passive: true });
  onScroll();

  /* ---------------- scroll reveal ---------------- */
  var reveals = $$('.reveal');

  /* Stagger comes from the d1/d2/d3 delay classes, cycling per section so
     siblings step in one after another rather than all at once. */
  reveals.forEach(function (el) {
    var sibs = $$('.reveal', el.parentNode);
    var n = sibs.indexOf(el) % 4;
    if (n) el.classList.add('d' + n);
  });

  if (!reduced && 'IntersectionObserver' in window) {
    /* Two-way. The observer used to `unobserve` on the first intersection, so
       a block animated once on the way down and was simply already-there for
       the rest of the session — scroll back up and the page was static.

       Now `.in` is toggled rather than latched, so a block plays its entrance
       whichever direction it is approached from. The reset happens only once
       the element is fully clear of the viewport, and the bottom margin is
       asymmetric (-6% in, +12% out): entering and leaving therefore cross at
       different points, and an element parked exactly on the boundary cannot
       oscillate between the two states. */
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) {
        e.target.classList.toggle('in', e.isIntersecting);
      });
    }, { rootMargin: '18% 0px 8% 0px', threshold: 0.01 });

    reveals.forEach(function (el) { io.observe(el); });

    /* Safety sweep. An element that is already above the fold but whose
       intersection ratio never reaches the threshold — a short block inside a
       tall wrapper, or a layout that settles late as the webfont lands — would
       otherwise sit invisible forever. After a beat, anything still hidden and
       within the viewport is simply released. */
    setTimeout(function () {
      $$('.reveal:not(.in)').forEach(function (el) {
        if (el.getBoundingClientRect().top < window.innerHeight) el.classList.add('in');
      });
    }, 1200);
  } else {
    reveals.forEach(function (el) { el.classList.add('in'); });
  }

  /* ---------- drawn rules ----------
     The stage rules and the hero stat rules scale from zero as they are
     reached. Where the browser understands scroll-driven animation, motion.css
     runs both off the scroll position itself and this class is simply the
     value it lands on anyway; everywhere else this is what carries them.
     A separate observer from the reveals because the threshold is different —
     a rule should draw when its block is properly in view, not at the first
     8% of it. */
  var rules = $$('.stages li,.hero-stats li');
  if (!reduced && 'IntersectionObserver' in window) {
    /* two-way as well, so the rules re-draw when the section is scrolled back
       up to rather than being permanently already-drawn after one pass */
    var ro = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) {
        e.target.classList.toggle('in', e.isIntersecting);
      });
    }, { rootMargin: '10% 0px 0px 0px', threshold: 0.3 });
    rules.forEach(function (el) { ro.observe(el); });
  } else {
    rules.forEach(function (el) { el.classList.add('in'); });
  }

  /* ---------- FAQ accordion ----------
     max-height is set in pixels from the measured content rather than to a
     hard-coded ceiling: too low clips a long answer, too high makes a short
     one drift open for most of the transition. Re-measured on resize, because
     the same answer is two lines on a desktop and six on a phone. */
  (function faq() {
    var items = $$('.faq-item');
    if (!items.length) return;

    function open(item, yes) {
      var body = $('.faq-body', item);
      var head = $('.faq-head', item);
      item.classList.toggle('open', yes);
      head.setAttribute('aria-expanded', String(yes));
      body.style.maxHeight = yes ? (body.firstElementChild.offsetHeight + 'px') : '0px';
    }

    items.forEach(function (item) {
      $('.faq-head', item).addEventListener('click', function () {
        var yes = !item.classList.contains('open');
        /* one at a time: two open answers push the third off the screen and
           the section stops reading as a list of questions */
        items.forEach(function (o) { if (o !== item) open(o, false); });
        open(item, yes);
      });
    });

    window.addEventListener('resize', function () {
      items.forEach(function (item) {
        if (!item.classList.contains('open')) return;
        var body = $('.faq-body', item);
        body.style.maxHeight = body.firstElementChild.offsetHeight + 'px';
      });
    });
  })();

  /* ---------------- stat counters ---------------- */
  function runCount(el) {
    var target = Number(el.dataset.count);
    var suffix = el.dataset.suffix || '';
    var start  = performance.now();

    (function step(now) {
      var p = Math.min(1, (now - start) / 1100);
      var eased = 1 - Math.pow(1 - p, 3);
      el.innerHTML = Math.round(target * eased) + suffix;
      if (p < 1) requestAnimationFrame(step);
    })(start);
  }

  var counters = $$('.hero-stats b[data-count]');
  if ('IntersectionObserver' in window) {
    var co = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) {
        if (!e.isIntersecting) return;
        runCount(e.target);
        co.unobserve(e.target);
      });
    }, { threshold: 0.5 });
    counters.forEach(function (el) { co.observe(el); });
  } else {
    counters.forEach(runCount);
  }

  /* ---------------- active nav link ---------------- */
  var links = $$('#nav a');

  /* Only the links that are still fragments. A menu item pointing at a page
     -- '/stock/' -- is not a selector, and handing it to querySelector throws
     a SyntaxError rather than returning null, which took every line of this
     file below here down with it on every page including the homepage. */
  var secs  = links.map(function (a) {
                     var href = a.getAttribute('href') || '';
                     return ('#' === href.charAt(0) && href.length > 1) ? $(href) : null;
                   })
                   .filter(Boolean);

  if ('IntersectionObserver' in window && secs.length) {
    var so = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) {
        if (!e.isIntersecting) return;
        links.forEach(function (a) {
          a.classList.toggle('is-active', a.getAttribute('href') === '#' + e.target.id);
        });
      });
    }, { rootMargin: '-45% 0px -50% 0px' });
    secs.forEach(function (s) { so.observe(s); });
  }

  /* The listing information search engines read used to be built here and
     injected into <head>. It is written by PHP now, in Vesla_Render::head():
     it was the last place in this file that hardcoded a domain name, and a
     crawler is handed server-rendered markup rather than having to run this
     script to find it.
  ---------------------------------------------------------------- */

  /* ---------------- footer year ----------------
     PHP already substitutes {year} into the copyright line, so this element
     normally does not exist. It threw an uncaught TypeError at the very end
     of this file on every page load - harmless in itself, because nothing
     runs after it, but an error in the console is where the next real bug
     goes to hide. Guarded rather than deleted, so a copyright line pasted in
     from the old static page still gets its year filled in. */
  var yr = $('#yr');
  if (yr) { yr.textContent = new Date().getFullYear(); }


  /* ------------------------------------------------------------------
     MOVING TO A CAR WITHOUT RELOADING THE TAB

     A card is a real link to a real address, and that is what makes this
     safe to add: turn JavaScript off, or let anything below throw, and
     the same card still opens the same page the ordinary way. Nothing
     here invents a route — it intercepts one that already works.

     What happens on a press: the curtain comes up, the car's page is
     fetched as HTML, the landing page is HIDDEN, and the car is put in
     its place. The curtain stays for at least CARD_HOLD so the move
     reads as deliberate, and longer if the car has not arrived yet.

     The landing page is hidden rather than destroyed. It holds the grid,
     the filters somebody set, the cars they paged through and where they
     had scrolled to. Rebuilding all of that on the way back would be
     both slower and wrong; revealing it again is neither.

     What is fetched is the car's own HTML, not JSON, so there is one
     template for a car — the server's — rather than one on each side
     quietly drifting apart.
     ------------------------------------------------------------------ */
  var CAR_BASE  = CFG.carBase || 'cars';
  var CARD_HOLD = CFG.carHold == null ? 2000 : Math.max(0, CFG.carHold);

  var router = (function () {
    if (!window.fetch || !window.history || !window.history.pushState || !window.DOMParser) {
      return { start: function () {} };   // the links still work on their own
    }

    var host   = null;          // where a fetched car is put
    var hidden = [];            // the landing page's own top-level nodes
    var cache  = {};            // one fetch per car per visit
    var warmed = {};            // and one prefetch per car per visit
    var homeY  = 0;
    var homeTitle = document.title;

    function curtain(on) {
      var pre = document.getElementById('preload');
      var root = document.documentElement;
      if (!pre) { return; }

      if (on) {
        /* A finished CSS animation does not replay by being shown again, so
           the figure is swapped for a pristine copy each time. */
        var art = pre.querySelector('.pl-art');
        if (art && window.__veslaArt) {
          pre.replaceChild(window.__veslaArt.cloneNode(true), art);
        }
        pre.classList.remove('done');
        root.classList.add('is-loading');
      } else {
        pre.classList.add('done');
        setTimeout(function () {
          root.classList.remove('is-loading');
          pre.classList.remove('done');
        }, 420);                 // the fade length in the stylesheet
      }
    }

    /* Everything the landing page put in <body> that is not the curtain. */
    function landingParts() {
      if (hidden.length) { return hidden; }
      var kids = document.body.children;
      for (var i = 0; i < kids.length; i++) {
        var el = kids[i];
        if (el.id === 'preload' || el.tagName === 'SCRIPT' || el.id === 'vesla-car-host') { continue; }
        hidden.push(el);
      }
      return hidden;
    }

    function showLanding(yes) {
      landingParts().forEach(function (el) { el.hidden = !yes; });
    }

    function paint(html, url) {
      var doc = new DOMParser().parseFromString(html, 'text/html');
      var page = doc.querySelector('.veh-page');
      if (!page) { location.href = url; return false; }   // not a car page: let the browser have it

      if (!host) {
        host = document.createElement('div');
        host.id = 'vesla-car-host';
        document.body.appendChild(host);
      }
      host.innerHTML = '';
      host.appendChild(document.importNode(page, true));
      host.hidden = false;
      showLanding(false);

      var t = doc.querySelector('title');
      if (t) { document.title = t.textContent; }

      /* The swapped-in markup needs the same slider, tilt and calculator a
         directly opened page gets, and the map section needs re-observing —
         neither script knows anything arrived unless it is told. */
      if (window.VeslaVehicle && window.VeslaVehicle.init) { window.VeslaVehicle.init(host); }
      if (window.VeslaMap && window.VeslaMap.scan) { window.VeslaMap.scan(); }
      window.scrollTo(0, 0);
      return true;
    }

    function go(url, push) {
      var began = Date.now();
      if (push) { homeY = window.pageYOffset; }
      curtain(true);

      var done = function (html) {
        /* Held for the rest of CARD_HOLD if the car beat it, and not at all
           if it did not: the floor is there so the move reads as deliberate,
           not to make anybody wait twice. */
        setTimeout(function () {
          if (paint(html, url) && push) {
            history.pushState({ veh: url }, '', url);
          }
          curtain(false);
        }, Math.max(0, CARD_HOLD - (Date.now() - began)));
      };

      if (cache[url]) { done(cache[url]); return; }

      fetch(url, { credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.text() : Promise.reject(r.status); })
        .then(function (html) { cache[url] = html; done(html); })
        .catch(function () {
          /* Whatever went wrong, the address is real. Hand it to the browser
             rather than leaving somebody under a curtain. */
          location.href = url;
        });
    }

    function back() {
      showLanding(true);
      if (host) { host.hidden = true; host.innerHTML = ''; }
      document.title = homeTitle;
      /* After the next frame, so the revealed page has been laid out and the
         offset it is being scrolled to actually exists. */
      requestAnimationFrame(function () { window.scrollTo(0, homeY); });
    }

    /* Fetched before it is asked for.

       WordPress takes about a second and a half to build a car's page, and
       almost all of that is WordPress starting up rather than anything this
       plugin does — so there is nothing to make faster on the server. What
       there is, is time: somebody moves a pointer onto a card several hundred
       milliseconds before they press it, and a finger touching the screen
       lands about a hundred before the click fires. Starting the fetch there
       spends a wait that was happening anyway.

       Once per address, into the same cache the click reads. Nothing is
       prefetched on a metered connection, and nothing is prefetched twice. */
    function warm(url) {
      if (!url || cache[url] || warmed[url]) { return; }
      var c = navigator.connection;
      if (c && (c.saveData || /2g/.test(c.effectiveType || ''))) { return; }
      warmed[url] = true;
      fetch(url, { credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.text() : Promise.reject(0); })
        .then(function (html) { cache[url] = html; })
        .catch(function () { warmed[url] = false; });
    }

    function start() {
      ['pointerenter', 'touchstart', 'focusin'].forEach(function (ev) {
        document.addEventListener(ev, function (e) {
          var a = e.target.closest && e.target.closest('a.card-link');
          if (a && a.origin === location.origin) { warm(a.href); }
        }, { capture: true, passive: true });
      });

      document.addEventListener('click', function (e) {
        if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) {
          return;   // a new tab, or somebody else has handled it
        }
        var a = e.target.closest && e.target.closest('a[href]');
        if (!a || a.target === '_blank' || a.hasAttribute('download')) { return; }
        if (a.origin !== location.origin) { return; }
        /* Only links into the car pages, and only from the landing page. */
        if (a.pathname.indexOf('/' + CAR_BASE + '/') !== 0) { return; }

        e.preventDefault();
        go(a.href, true);
      });

      window.addEventListener('popstate', function (e) {
        if (e.state && e.state.veh) { go(e.state.veh, false); return; }

        /* Only when there is actually a car page to come back from.

           Clicking a link to a fragment on this same page — every item in
           the menu — is a history navigation, so it fires popstate too,
           with no state on it. Treating that as a return from a car ran
           back(), which restores the landing page and scrolls to the
           position the reader was at when they left it. From a standing
           start that position is the top, so every menu link updated the
           address bar and then pulled the page back to the top: the anchor
           jump worked and was immediately undone.

           The host is only in the document while a car is being shown, so
           it is the honest test for whether there is anything to undo. */
        if (!host || host.hidden) { return; }
        back();
      });
    }

    return { start: start };
  })();
  router.start();


})();
