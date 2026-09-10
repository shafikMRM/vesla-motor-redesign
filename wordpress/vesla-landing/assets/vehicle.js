/* Vesla -- a car's page.

   Everything here is enhancement: the page is complete without it. The
   photographs are all in the markup, the specification is a table, the finance
   figures are the showroom's own. This adds the slider, the depth and the
   calculator.

   Written as an init() that can be called more than once, because the same
   markup arrives two ways: as a page somebody opened directly, and as content
   swapped in without a reload when they clicked a card. The second way has no
   fresh script execution to rely on, so setup has to be callable.

   No framework and no build step, matching the rest of the site. */
(function () {
  'use strict';

  function init(root) {
    var page = (root || document).querySelector('.veh-page');
    if (!page || page.dataset.ready) { return; }
    page.dataset.ready = '1';   // swapping the same car back in must not bind twice


    var $  = function (s, r) { return (r || document).querySelector(s); };
    var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
    var CFG = window.VESLA_DATA || {};
    var soft = !window.matchMedia || !window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    /* ---------------- the slider ----------------
       The photographs are already in the page, one on top of the other. Moving
       between them swaps a class rather than a src, so there is nothing to
       fetch and no blank frame between two pictures. */
    var stage  = $('.vp-stage');
    var shots  = $$('.vp-shot');
    /* The blurred backdrops, one per photograph and in the same order.
       Empty on a car with no photographs, which is most of them. */
    var fills  = $$('.vp-fill');
    var thumbs = $$('.vp-th');
    var at = 0;

    /* A slide's address sits in data-src until it is wanted. Everything is
       stacked in the viewport, so the browser treats all twenty images as
       visible and loading="lazy" saves nothing — it fetched two megabytes to
       show one photograph. This loads the one being shown and the one after
       it, so moving on is instant and nothing else is ever fetched. */
    function need(n) {
      [shots[n], fills[n], shots[n + 1], fills[n + 1],
       shots[n - 1], fills[n - 1]].forEach(function (img) {
        if (img && !img.getAttribute('src') && img.dataset.src) {
          img.src = img.dataset.src;
        }
      });
    }
    need(0);

    function show(n) {
      if (!shots.length) { return; }
      var was = at;
      at = (n + shots.length) % shots.length;   // wraps, so the arrows never dead-end
      if (at === was) { return; }

      need(at);
      shots.forEach(function (img, i) {
        img.classList.toggle('is-on', i === at);
        /* Direction matters: sliding the same way whichever arrow was pressed
           reads as a glitch rather than as movement. */
        img.classList.toggle('from-right', i === at && at > was);
        img.classList.toggle('from-left', i === at && at < was);
      });
      /* The backdrop follows the photograph it belongs to. Toggled in the
         same pass rather than on a transition callback: if the two ever
         fell out of step, the blur behind one car would be another car. */
      fills.forEach(function (img, i) { img.classList.toggle('is-on', i === at); });
      thumbs.forEach(function (b, i) {
        b.classList.toggle('is-on', i === at);
        if (i === at && b.scrollIntoView) {
          b.scrollIntoView({ block: 'nearest', inline: 'nearest', behavior: soft ? 'smooth' : 'auto' });
        }
      });
      var c = $('.vp-count');
      if (c) { c.textContent = (at + 1) + ' / ' + shots.length; }
    }

    if (stage && shots.length > 1) {
      var prev = $('.vp-prev'), next = $('.vp-next');
      if (prev) { prev.addEventListener('click', function () { show(at - 1); }); }
      if (next) { next.addEventListener('click', function () { show(at + 1); }); }
      thumbs.forEach(function (b, i) { b.addEventListener('click', function () { show(i); }); });

      document.addEventListener('keydown', function (e) {
        var t = e.target.tagName;
        if (t === 'INPUT' || t === 'SELECT' || t === 'TEXTAREA') { return; }
        if (e.key === 'ArrowLeft')  { show(at - 1); }
        if (e.key === 'ArrowRight') { show(at + 1); }
      });

      /* Swipe. A horizontal move that beats the vertical one is a swipe;
         anything else is the page being scrolled and is left alone. */
      var x0 = null, y0 = null;
      stage.addEventListener('touchstart', function (e) {
        x0 = e.touches[0].clientX; y0 = e.touches[0].clientY;
      }, { passive: true });
      stage.addEventListener('touchend', function (e) {
        if (x0 === null) { return; }
        var dx = e.changedTouches[0].clientX - x0;
        var dy = e.changedTouches[0].clientY - y0;
        if (Math.abs(dx) > 45 && Math.abs(dx) > Math.abs(dy)) { show(at + (dx < 0 ? 1 : -1)); }
        x0 = y0 = null;
      }, { passive: true });
    }

    /* ---------------- depth ----------------
       The stage tilts a few degrees towards the pointer. Kept small on purpose:
       a card that swings about is a toy, and this is a page somebody is trying
       to read a price off. Pointer devices only, and never when the visitor has
       asked for less movement. */
    if (stage && soft && window.matchMedia('(hover:hover) and (pointer:fine)').matches) {
      var frame = null;
      stage.addEventListener('mousemove', function (e) {
        if (frame) { return; }              // one write per frame, not one per event
        frame = requestAnimationFrame(function () {
          frame = null;
          var r = stage.getBoundingClientRect();
          var px = (e.clientX - r.left) / r.width  - 0.5;
          var py = (e.clientY - r.top)  / r.height - 0.5;
          stage.style.setProperty('--ry', (px *  7).toFixed(2) + 'deg');
          stage.style.setProperty('--rx', (py * -5).toFixed(2) + 'deg');
          stage.classList.add('is-tilt');
        });
      });
      stage.addEventListener('mouseleave', function () {
        stage.classList.remove('is-tilt');
        stage.style.removeProperty('--ry');
        stage.style.removeProperty('--rx');
      });
    }

    /* Blocks rise as they come into view. One observer, and each block is
       unobserved once it has arrived — a page that keeps recalculating for
       elements that have finished is a page that stutters while you read it. */
    if (soft && 'IntersectionObserver' in window) {
      var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (en) {
          if (!en.isIntersecting) { return; }
          en.target.classList.add('is-in');
          io.unobserve(en.target);
        });
      }, { rootMargin: '0px 0px -8% 0px' });
      $$('.vp-block, .vp-side, .vp-gal').forEach(function (el) { io.observe(el); });
    } else {
      $$('.vp-block, .vp-side, .vp-gal').forEach(function (el) { el.classList.add('is-in'); });
    }

    /* The shared sections — the map and the footer — use the landing page's
       own `.reveal` / `.reveal.in` pair, and the script that drives it is
       app.js, which a car's page does not load. So every one of them sat at
       opacity 0 for ever: the whole Where we are section was built, tiles and
       pin and all, and painted nothing.

       Same class and same behaviour as app.js, so the two pages reveal the
       same markup the same way rather than looking subtly different. */
    var revs = $$('.reveal', root);
    if (soft && 'IntersectionObserver' in window) {
      var rio = new IntersectionObserver(function (entries) {
        entries.forEach(function (en) { en.target.classList.toggle('in', en.isIntersecting); });
      }, { rootMargin: '18% 0px 8% 0px', threshold: 0.01 });
      revs.forEach(function (el) { rio.observe(el); });

      /* The same safety sweep app.js runs: anything still hidden but already
         within the viewport after a beat is simply released. */
      setTimeout(function () {
        revs.forEach(function (el) {
          if (!el.classList.contains('in') && el.getBoundingClientRect().top < window.innerHeight) {
            el.classList.add('in');
          }
        });
      }, 1200);
    } else {
      revs.forEach(function (el) { el.classList.add('in'); });
    }

    /* ---------------- the monthly figure ---------------- */
    var fin = $('.vp-fin');
    /* The enquiry button carries which car it came from, so the form on the
       landing page arrives already filled in. sessionStorage rather than a query
       string: the address stays clean and shareable.

       It now carries what KIND of enquiry it is as well, and -- from the finance
       block below -- the figures that were on screen. The showroom could
       previously tell that somebody asked about a car, but not that they had
       been working out a monthly payment on it first, which is the difference
       between a question and a buyer. */
    function carry(type, details) {
      try {
        sessionStorage.setItem('vesla-enq', JSON.stringify({
          car: (enq && enq.dataset.car) || '',
          type: type,
          details: details || null
        }));
      } catch (e) {
        /* Private mode refuses storage; the form simply arrives empty. */
      }
    }

    var enq = $('.vp-act .btn-solid');
    if (enq && enq.dataset.car) {
      enq.addEventListener('click', function () { carry('car', null); });
    }

    /* ---------------- the video ----------------
       The markup ships a link to where the video lives, so with no scripting
       pressing it simply opens YouTube. Where there IS scripting, the press is
       caught and the player is put in place instead -- nothing of YouTube's is
       fetched until this runs, which is the point of the facade. */
    var vgo = $('.vp-video-go');
    if (vgo && vgo.dataset.embed) {
      vgo.addEventListener('click', function (ev) {
        /* Let the modified clicks through: somebody middle-clicking or
           ctrl-clicking is asking for a tab, not an inline player. */
        if (ev.metaKey || ev.ctrlKey || ev.shiftKey || ev.button !== 0) { return; }
        ev.preventDefault();

        var frame = document.createElement('iframe');
        frame.src = vgo.dataset.embed;
        frame.title = vgo.textContent.trim();
        frame.setAttribute('allow', 'accelerometer; autoplay; encrypted-media; picture-in-picture');
        frame.setAttribute('allowfullscreen', '');
        frame.setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
        frame.loading = 'lazy';

        var box = vgo.parentNode;
        box.classList.add('is-playing');
        box.innerHTML = '';
        box.appendChild(frame);
      });
    }

    /* ---------------- sending the car to somebody ----------------
       Built here rather than in the markup so it never exists in a state where
       it cannot work: with no scripting there is no button, which is better
       than one that does nothing when pressed.

       navigator.share where the browser has it -- on a phone that opens
       WhatsApp, Messages and the rest, which is how a car actually gets sent to
       the person paying for it. On a desktop it falls back to copying the
       address, and says so. */
    var act = $('.vp-act');
    if (act && (navigator.share || (navigator.clipboard && navigator.clipboard.writeText))) {
      var L2 = CFG.labels || {};
      var share = document.createElement('button');
      share.type = 'button';
      share.className = 'btn btn-line vp-share';
      share.textContent = L2.share || 'Share';

      share.addEventListener('click', function () {
        var url = window.location.href;
        var title = (document.querySelector('h1') || {}).textContent || document.title;

        if (navigator.share) {
          /* A cancelled share rejects. That is the visitor changing their
             mind, not a failure, so it is swallowed rather than reported. */
          navigator.share({ title: title, url: url }).catch(function () {});
          return;
        }
        navigator.clipboard.writeText(url).then(function () {
          var was = share.textContent;
          share.textContent = L2.shareCopied || 'Link copied';
          share.disabled = true;
          setTimeout(function () {
            share.textContent = was;
            share.disabled = false;
          }, 2000);
        }).catch(function () {
          share.textContent = L2.shareFailed || 'Could not copy';
        });
      });

      act.appendChild(share);
    }

    if (fin && CFG.finance && CFG.finance.on) {
      /* The car's own figures where it has them, the site's where it has
         not. Written on the section by the server, so the two can never
         disagree about which rate is being shown. */
      var num = function (v, fallback) {
        var n = parseFloat(String(v == null ? '' : v).replace(',', '.'));
        return isNaN(n) ? fallback : n;
      };
      var F = {
        on:      CFG.finance.on,
        title:   CFG.finance.title,
        downPct: num(fin.dataset.down,  CFG.finance.downPct),
        rate:    num(fin.dataset.rate,  CFG.finance.rate),
        years:   num(fin.dataset.years, CFG.finance.years),
        note:    fin.dataset.note || CFG.finance.note
      };
      var L = CFG.labels || {};
      var price = parseInt(fin.dataset.price, 10) || 0;
      var cur = CFG.currency || '';

      var money = function (n) {
        try { return cur + ' ' + n.toLocaleString(CFG.locale || undefined); }
        catch (e) { return cur + ' ' + n; }
      };

      $('.vp-fin-in', fin).innerHTML =
        '<div class="vp-fin-row"><label for="vf-down">' + (L.finDown || 'Deposit') + '</label>' +
          '<output id="vf-down-v"></output>' +
          '<input type="range" id="vf-down" min="0" max="60" step="5" value="' + F.downPct + '"></div>' +
        '<div class="vp-fin-row"><label for="vf-years">' + (L.finYears || 'Years') + '</label>' +
          '<output id="vf-years-v"></output>' +
          '<input type="range" id="vf-years" min="1" max="8" step="1" value="' + F.years + '"></div>' +
        '<div class="vp-fin-out"><b id="vf-month"></b><span>' + (L.finPerMonth || '') + '</span></div>' +
        (F.note ? '<p class="vp-fin-note">' + F.note + '</p>' : '');

      var down = $('#vf-down'), years = $('#vf-years');

      function run() {
        var pct = Number(down.value), yrs = Number(years.value);
        var dep = Math.round(price * pct / 100);
        var loan = price - dep;
        var r = (Number(F.rate) || 0) / 100;

        /* Flat-rate interest, which is how car finance is quoted in the UAE: the
           whole interest is worked out on the amount borrowed for the whole
           term, then the total is divided by the months. A reducing-balance sum
           gives a smaller number than the showroom would quote, which is the
           wrong way to be wrong. */
        var months = yrs * 12;
        var total = loan + (loan * r * yrs);
        $('#vf-down-v').textContent  = money(dep) + '  ·  ' + pct + '%';
        $('#vf-years-v').textContent = yrs;
        $('#vf-month').textContent   = money(months ? Math.round(total / months) : 0);
      }
      down.addEventListener('input', run);
      years.addEventListener('input', run);
      run();

      /* The calculator worked out a monthly figure and then let the visitor
         leave with it. Somebody who has moved both sliders has told us their
         deposit and the term they want; asking them to say it again on the
         phone is how that interest goes cold.

         The link goes wherever the Enquire button goes -- read off that button
         rather than configured twice, so the two can never point at different
         pages. */
      var ask = document.createElement('a');
      ask.className = 'btn btn-line vp-fin-ask';
      ask.textContent = L.finAsk || 'Ask us about these figures';
      ask.href = (enq && enq.getAttribute('href')) || '';
      if (ask.href) {
        ask.addEventListener('click', function () {
          var d = {};
          d[L.finDown || 'Deposit']      = $('#vf-down-v').textContent.trim();
          d[L.finYears || 'Years']       = $('#vf-years-v').textContent.trim();
          d[L.finPerMonth || 'Per month'] = $('#vf-month').textContent.trim();
          d[L.finRate || 'Rate quoted']  = F.rate + '%';
          carry('finance', d);
        });
        $('.vp-fin-in', fin).appendChild(ask);
      }
    }

    /* ---------------- the header and the footer ----------------
       This page carries the site's header bar and footer, but not the landing
       page's script, which is built around a car grid that is not here. These
       are the few behaviours those two parts need, and nothing else. */
    /* motion.css is loaded here too, and it holds anything marked `m-cast` at
     opacity 0 until `m-on` appears on <html>. On the landing page motion.js
     puts it there; this page does not load motion.js, so without this line
     the header and footer arrive invisible and the page looks like a
     different site. */
  document.documentElement.classList.add('m-on');

  var burger = $('#burger');
    var nav    = $('#nav');
    if (burger && nav) {
      burger.addEventListener('click', function () {
        var open = document.documentElement.classList.toggle('nav-on');
        burger.setAttribute('aria-expanded', open ? 'true' : 'false');
      });
      /* Following a link should close the drawer behind you. */
      $$('#nav a').forEach(function (a) {
        a.addEventListener('click', function () {
          document.documentElement.classList.remove('nav-on');
          burger.setAttribute('aria-expanded', 'false');
        });
      });
    }

    var bar = $('#bar');
    var totop = $('#totop');
    if (bar || totop) {
      var ticking = false;
      window.addEventListener('scroll', function () {
        if (ticking) { return; }          // one read per frame, not one per event
        ticking = true;
        requestAnimationFrame(function () {
          ticking = false;
          var y = window.scrollY || window.pageYOffset;
          /* m-stuck is the class motion.css styles the stuck header with. Using
           a different name here gave this page a header that never picked up
           its shadow or its rule. */
        if (bar) { bar.classList.toggle('m-stuck', y > 10); }
          if (totop) { totop.classList.toggle('is-on', y > window.innerHeight * 0.8); }
        });
      }, { passive: true });
    }
    if (totop) {
      totop.addEventListener('click', function () {
        window.scrollTo({ top: 0, behavior: soft ? 'smooth' : 'auto' });
      });
    }

    var yr = $('#yr');
    if (yr) { yr.textContent = new Date().getFullYear(); }
  }

  /* A page opened directly. The router calls init() again after it swaps a
     car in, so this is only the first one. */
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { init(document); });
  } else {
    init(document);
  }

  window.VeslaVehicle = { init: init };
})();
