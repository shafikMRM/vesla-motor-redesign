/* Vesla — the location map.

   Leaflet, served from this plugin, and loaded only when somebody scrolls to
   it. Until then the frame holds a skeleton at the map's own height, and if
   the tiles never arrive the skeleton is what stays: a quiet frame reads as
   "the map is coming" far better than a wheel turning for ever.

   WHY IT IS LAZY
   The map is the last thing on the page. Loading 150KB of library and a dozen
   tile requests at first paint would put it in competition with the hero
   photograph for the opening connection — the same second and a half of
   loading time that self-hosting the fonts bought back. It waits until it is
   nearly on screen, which for most visitors is never.

   WHY LEAFLET AND NOT AN EMBED
   An embed comes with every shop, petrol station and car dealer around us
   pinned on it, in somebody else's colours. This draws one marker: ours. The
   only thing Google is used for is the Get directions button, which is a job
   people finish in the app already on their phone.

   No build step, matching the rest of the site. */
(function () {
  'use strict';

  if (!('IntersectionObserver' in window)) { return; }

  var loading = null;   // one promise for the library, however many maps

  function load(url, isCss) {
    return new Promise(function (resolve, reject) {
      var el;
      if (isCss) {
        el = document.createElement('link');
        el.rel = 'stylesheet';
        el.href = url;
      } else {
        el = document.createElement('script');
        el.src = url;
        el.defer = true;
      }
      el.onload = resolve;
      el.onerror = reject;
      document.head.appendChild(el);
    });
  }

  function library(base) {
    if (!loading) {
      loading = Promise.all([
        load(base + 'leaflet/leaflet.css', true),
        load(base + 'leaflet/leaflet.js', false)
      ]);
    }
    return loading;
  }

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function build(host) {
    if (!window.L || host.dataset.built) { return; }

    var lat  = parseFloat(host.dataset.lat);
    var lng  = parseFloat(host.dataset.lng);
    var zoom = parseInt(host.dataset.zoom, 10) || 15;
    if (isNaN(lat) || isNaN(lng)) { return; }

    host.dataset.built = '1';

    var canvas = document.createElement('div');
    canvas.className = 'vesla-map';
    canvas.setAttribute('role', 'application');
    canvas.setAttribute('aria-label', host.dataset.name || 'Map');
    host.appendChild(canvas);

    var map = L.map(canvas, {
      center: [lat, lng],
      zoom: zoom,
      /* The page scrolls past this. A map that eats the scroll traps the
         reader half way down the page. */
      scrollWheelZoom: false,
      attributionControl: true
    });

    L.tileLayer(host.dataset.tiles, {
      maxZoom: 19,
      /* Required by the tile licence, and shown rather than folded into a
         collapsed control — an attribution nobody can see is not one. */
      attribution: host.dataset.credit || ''
    }).addTo(map);

    /* The frame stops being a skeleton only when tiles have actually painted,
       not when the script has run. */
    map.whenReady(function () { host.classList.add('is-ready'); });

    /* A marker drawn in CSS: no sprite to fetch, brand colours rather than
       Leaflet's blue, and an aura behind it so a small mark still reads on a
       busy basemap. */
    var pin = L.divIcon({
      className: 'vesla-pin',
      html: '<span class="vesla-pin-aura"></span><span class="vesla-pin-mark"></span>',
      iconSize: [24, 24],
      iconAnchor: [12, 12],
      popupAnchor: [0, -20]
    });

    var marker = L.marker([lat, lng], {
      icon: pin,
      title: host.dataset.name || '',
      keyboard: true,
      alt: host.dataset.name || ''
    }).addTo(map);

    /* A permanent caption, because the basemap labels every other building in
       frame and the one the page is about was the only unnamed thing on it.
       Not interactive, so it never swallows a click meant for the pin. */
    if (host.dataset.name) {
      marker.bindTooltip(esc(host.dataset.name), {
        permanent: true,
        direction: 'right',
        offset: [16, 0],
        className: 'vesla-pin-label',
        interactive: false
      }).openTooltip();
    }

    /* The name and the address on click, and nothing else.

       The telephone number and the directions button live on the card beside
       the map, where they are visible without anyone having to discover that
       the pin can be pressed. Repeating them inside the popup put the same
       two actions on screen twice, a few centimetres apart. Built as an
       escaped string because Leaflet's popup takes markup, not a DOM tree,
       and both values come from settings fields. */
    var lines = [];
    if (host.dataset.name)    { lines.push('<b>' + esc(host.dataset.name) + '</b>'); }
    if (host.dataset.address) { lines.push('<span class="vesla-pop-addr">' + esc(host.dataset.address).replace(/\n/g, '<br>') + '</span>'); }

    if (lines.length) {
      marker.bindPopup(lines.join(''), { className: 'vesla-pop', closeButton: true });
    }

    /* Reachable without a mouse. Leaflet gives the marker a tabindex; this
       makes Enter and Space re-centre the map on it, so a keyboard user can
       bring it back after panning. */
    marker.on('keypress', function (e) {
      if (e.originalEvent.key === 'Enter' || e.originalEvent.key === ' ') {
        map.setView([lat, lng], zoom);
      }
    });

    /* Wheel zoom only once the map has been clicked into. */
    map.on('focus', function () { map.scrollWheelZoom.enable(); });
    map.on('blur',  function () { map.scrollWheelZoom.disable(); });
  }

  /* Watch every map on the page that is not already being watched.

     Called again after a car's page is swapped in without a reload: that
     markup carries its own map section, and an observer set up at load knows
     nothing about an element that did not exist yet. */
  function scan() {
    Array.prototype.forEach.call(document.querySelectorAll('[data-vesla-map]'), function (host) {
      if (host.dataset.watched) { return; }
      host.dataset.watched = '1';
      var base = host.dataset.assets || '';
      var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (en) {
          if (!en.isIntersecting) { return; }
          io.disconnect();
          library(base).then(function () { build(host); }).catch(function () {
            /* The skeleton is already on screen and holds the layout. Leaving it
               is a better outcome than an empty collapsed box. */
          });
        });
      }, { rootMargin: '300px 0px' });   // a little before it arrives, not after
      io.observe(host);
    });
  }

  scan();
  window.VeslaMap = { scan: scan };
})();
