(function () {
  'use strict';

  var nav = document.getElementById('site-nav');
  var siteMenu = document.querySelector('[data-site-menu]');
  var menuTrigger = document.getElementById('site-menu-trigger');
  var loginLi = document.querySelector('.site-menu__dd-item--login');
  var loginTrigger = document.getElementById('site-login-trigger');
  var loginPanel = document.getElementById('site-login-panel');

  function closeLoginPanel() {
    if (!loginLi || !loginTrigger || !loginPanel) return;
    loginLi.classList.remove('is-open');
    loginTrigger.setAttribute('aria-expanded', 'false');
    loginPanel.setAttribute('aria-hidden', 'true');
  }

  function closeSiteMenu() {
    closeLoginPanel();
    if (!siteMenu || !menuTrigger) return;
    siteMenu.classList.remove('is-open');
    menuTrigger.setAttribute('aria-expanded', 'false');
    menuTrigger.setAttribute('aria-label', 'Open site menu');
  }

  if (nav) {
    nav.querySelectorAll('a').forEach(function (a) {
      a.addEventListener('click', function () {
        closeSiteMenu();
      });
    });
  }

  if (siteMenu && menuTrigger) {
    menuTrigger.addEventListener('click', function (e) {
      e.stopPropagation();
      var open = !siteMenu.classList.contains('is-open');
      siteMenu.classList.toggle('is-open', open);
      menuTrigger.setAttribute('aria-expanded', open ? 'true' : 'false');
      menuTrigger.setAttribute('aria-label', open ? 'Close site menu' : 'Open site menu');
    });

    document.addEventListener('click', function (e) {
      if (!siteMenu.classList.contains('is-open')) return;
      if (siteMenu.contains(e.target)) return;
      closeSiteMenu();
    });

    document.addEventListener('keydown', function (e) {
      if (e.key !== 'Escape' || !siteMenu.classList.contains('is-open')) return;
      if (loginLi && loginLi.classList.contains('is-open')) {
        closeLoginPanel();
        if (loginTrigger) loginTrigger.focus();
        return;
      }
      closeSiteMenu();
      menuTrigger.focus();
    });
  }

  if (loginLi && loginTrigger && loginPanel) {
    loginTrigger.addEventListener('click', function (e) {
      e.stopPropagation();
      var open = !loginLi.classList.contains('is-open');
      loginLi.classList.toggle('is-open', open);
      loginTrigger.setAttribute('aria-expanded', open ? 'true' : 'false');
      loginPanel.setAttribute('aria-hidden', open ? 'false' : 'true');
    });

    document.addEventListener('click', function (e) {
      if (!loginLi.classList.contains('is-open')) return;
      if (loginLi.contains(e.target)) return;
      closeLoginPanel();
    });

    document.addEventListener('keydown', function (e) {
      if (e.key !== 'Escape' || !loginLi.classList.contains('is-open')) return;
      closeLoginPanel();
      loginTrigger.focus();
    });
  }

  var reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var narrow = window.matchMedia('(max-width: 900px)').matches;

  function dismissVideoBusy(video) {
    var root = video && (video.closest('.hero__media') || video.closest('.about-reel-frame'));
    if (!root) return;
    var busy = root.querySelector('[data-video-busy]');
    if (busy) busy.classList.add('is-done');
  }

  function armVideoBusyUntilPlaying(video, timeoutMs) {
    if (!video) return;
    var root = video.closest('.hero__media') || video.closest('.about-reel-frame');
    if (!root || !root.querySelector('[data-video-busy]')) return;
    var done = function () {
      dismissVideoBusy(video);
    };
    video.addEventListener('playing', done, { once: true });
    video.addEventListener('canplay', done, { once: true });
    video.addEventListener('error', done, { once: true });
    window.setTimeout(done, timeoutMs || 14000);
  }

  (function initHeroBackgroundVideo() {
    var video = document.querySelector('.hero__video');
    if (!video) return;
    var src = video.getAttribute('data-hero-src');
    if (!src) {
      dismissVideoBusy(video);
      return;
    }
    if (reducedMotion) {
      dismissVideoBusy(video);
      return;
    }
    var conn = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
    if (conn && (conn.saveData || /(^2g|slow-2g)/i.test(String(conn.effectiveType || '')))) {
      dismissVideoBusy(video);
      return;
    }
    armVideoBusyUntilPlaying(video, 8000);
    function attachAndPlay() {
      if (video.getAttribute('data-hero-loaded') === '1') return;
      video.setAttribute('data-hero-loaded', '1');
      video.setAttribute('webkit-playsinline', '');
      video.playsInline = true;
      video.src = src;
      video.load();
      var p = video.play();
      if (p && typeof p.catch === 'function') {
        p.catch(function () {});
      }
    }
    /* Start soon: Safari can stall on requestIdleCallback-only; daemon must be able to read files (chmod a+r). */
    window.setTimeout(attachAndPlay, 150);
    if ('requestIdleCallback' in window) {
      window.requestIdleCallback(attachAndPlay, { timeout: 2200 });
    } else {
      window.addEventListener('load', function () {
        window.setTimeout(attachAndPlay, 400);
      });
    }
  })();

  (function initAboutReelPlayback() {
    var video = document.querySelector('.about-reel__video');
    if (!video) return;
    armVideoBusyUntilPlaying(video, 9000);
    function tryPlay() {
      video.muted = true;
      if (video.readyState === 0) return;
      var p = video.play();
      if (p && typeof p.catch === 'function') {
        p.catch(function () {});
      }
    }
    video.addEventListener('canplay', tryPlay);
    video.addEventListener('loadeddata', tryPlay);
    var wrap = document.querySelector('.about-hero-split__reel');
    if (wrap && 'IntersectionObserver' in window) {
      var io = new IntersectionObserver(
        function (entries) {
          entries.forEach(function (en) {
            if (en.isIntersecting) tryPlay();
          });
        },
        { root: null, rootMargin: '140px 0px', threshold: 0.02 }
      );
      io.observe(wrap);
    }
    window.setTimeout(tryPlay, 0);
    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) tryPlay();
    });
    document.addEventListener(
      'pointerdown',
      function once() {
        tryPlay();
        document.removeEventListener('pointerdown', once, true);
      },
      true
    );
  })();

  if (document.body.classList.contains('page-home') && 'IntersectionObserver' in window) {
    var heroEl = document.querySelector('.hero--video');
    var siteHeader = document.querySelector('.site-header');
    if (heroEl && siteHeader) {
      var headerIo = new IntersectionObserver(
        function (entries) {
          var en = entries[0];
          siteHeader.classList.toggle('site-header--solid', !en.isIntersecting);
        },
        { root: null, rootMargin: '-72px 0px 0px 0px', threshold: 0 }
      );
      headerIo.observe(heroEl);
    }
  }

  (function initAboutCountUp() {
    var root = document.querySelector('.about-stats');
    if (!root) return;

    function easeOutCubic(t) {
      return 1 - Math.pow(1 - t, 3);
    }

    function formatVal(n, comma) {
      if (comma) {
        return n.toLocaleString('en-IN');
      }
      return String(n);
    }

    function setFinal(el) {
      var target = parseInt(el.getAttribute('data-count-up'), 10);
      if (isNaN(target)) return;
      var suffix = el.getAttribute('data-count-suffix') || '';
      var comma = el.getAttribute('data-count-format') === 'comma';
      el.textContent = formatVal(target, comma) + suffix;
    }

    function run(el) {
      var target = parseInt(el.getAttribute('data-count-up'), 10);
      if (isNaN(target) || target < 0) return;
      var suffix = el.getAttribute('data-count-suffix') || '';
      var comma = el.getAttribute('data-count-format') === 'comma';
      var duration = 1700;
      var start = window.performance.now();
      function frame(now) {
        var t = Math.min(1, (now - start) / duration);
        var eased = easeOutCubic(t);
        var val = Math.round(eased * target);
        el.textContent = formatVal(val, comma) + suffix;
        if (t < 1) {
          window.requestAnimationFrame(frame);
        } else {
          el.textContent = formatVal(target, comma) + suffix;
        }
      }
      window.requestAnimationFrame(frame);
    }

    if (reducedMotion) {
      root.querySelectorAll('.about-count[data-count-up]').forEach(setFinal);
      return;
    }

    if (!('IntersectionObserver' in window)) {
      root.querySelectorAll('.about-count[data-count-up]').forEach(run);
      return;
    }

    var done = false;
    var io = new IntersectionObserver(
      function (entries) {
        if (!entries[0].isIntersecting || done) return;
        done = true;
        root.querySelectorAll('.about-count[data-count-up]').forEach(function (el) {
          run(el);
        });
        io.disconnect();
      },
      { root: null, rootMargin: '0px 0px -12% 0px', threshold: 0.2 }
    );
    io.observe(root);
  })();

  if (!reducedMotion && narrow) {
    var cards = document.querySelectorAll('.work-card');
    if (cards.length) {
      if ('IntersectionObserver' in window) {
        var io = new IntersectionObserver(
          function (entries) {
            entries.forEach(function (en) {
              if (en.isIntersecting) {
                en.target.classList.add('is-inview');
              }
            });
          },
          { root: null, rootMargin: '0px 0px -8% 0px', threshold: 0.12 }
        );
        cards.forEach(function (c) {
          io.observe(c);
        });
      } else {
        cards.forEach(function (c) {
          c.classList.add('is-inview');
        });
      }
    }
  } else {
    document.querySelectorAll('.work-card').forEach(function (c) {
      c.classList.add('is-inview');
    });
  }

  var serviceWraps = document.querySelectorAll('.service-card-wrap');
  if (!reducedMotion && narrow && serviceWraps.length) {
    if ('IntersectionObserver' in window) {
      var sio = new IntersectionObserver(
        function (entries) {
          entries.forEach(function (en) {
            if (en.isIntersecting) {
              en.target.classList.add('is-inview');
            }
          });
        },
        { root: null, rootMargin: '0px 0px -6% 0px', threshold: 0.18 }
      );
      serviceWraps.forEach(function (w) {
        sio.observe(w);
      });
    } else {
      serviceWraps.forEach(function (w) {
        w.classList.add('is-inview');
      });
    }
  }

  function stopPlaying(card) {
    var embedWrap = card.querySelector('.work-card__embed');
    var iframe = embedWrap && embedWrap.querySelector('iframe');
    var closeBtn = card.querySelector('.work-card__close');
    var thumb = card.querySelector('.work-card__thumb');
    if (!embedWrap || !iframe || !closeBtn) return;
    card.classList.remove('is-playing');
    iframe.removeAttribute('src');
    embedWrap.setAttribute('hidden', '');
    embedWrap.setAttribute('aria-hidden', 'true');
    closeBtn.setAttribute('hidden', '');
    closeBtn.setAttribute('aria-hidden', 'true');
    if (thumb) {
      thumb.focus({ preventScroll: true });
    }
  }

  function startPlaying(card) {
    var embedWrap = card.querySelector('.work-card__embed');
    var iframe = embedWrap && embedWrap.querySelector('iframe');
    var closeBtn = card.querySelector('.work-card__close');
    var base = iframe && iframe.getAttribute('data-src');
    if (!embedWrap || !iframe || !closeBtn || !base) return;

    document.querySelectorAll('.work-card.is-playing').forEach(function (other) {
      if (other !== card) {
        stopPlaying(other);
      }
    });

    card.classList.add('is-playing');
    embedWrap.removeAttribute('hidden');
    embedWrap.setAttribute('aria-hidden', 'false');
    closeBtn.removeAttribute('hidden');
    closeBtn.setAttribute('aria-hidden', 'false');

    var sep = base.indexOf('?') >= 0 ? '&' : '?';
    iframe.setAttribute(
      'src',
      base + sep + 'autoplay=1&rel=0&controls=1&modestbranding=1&playsinline=1'
    );
    closeBtn.focus({ preventScroll: true });
  }

  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    var playing = document.querySelector('.work-card.is-playing');
    if (playing) {
      stopPlaying(playing);
    }
    closeSiteMenu();
  });

  document.querySelectorAll('.work-card').forEach(function (card) {
    var thumb = card.querySelector('.work-card__thumb');
    var closeBtn = card.querySelector('.work-card__close');
    if (!thumb || !closeBtn) return;

    thumb.addEventListener('click', function () {
      if (card.classList.contains('is-playing')) {
        stopPlaying(card);
      } else {
        startPlaying(card);
      }
    });

    closeBtn.addEventListener('click', function (ev) {
      ev.stopPropagation();
      stopPlaying(card);
    });
  });
})();
