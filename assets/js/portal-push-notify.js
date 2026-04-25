/**
 * Live desk: OS notifications (optional), bell dropdown → jump to task,
 * poll every ~14s and reload when task data changes (tab open).
 */
(function () {
  'use strict';

  var POLL_MS = 14000;
  var FIRST_POLL_MS = 2200;
  var cfg = window._akhPortalPush;
  if (!cfg || !cfg.mode || !cfg.csrf) {
    return;
  }

  var site = typeof cfg.siteName === 'string' && cfg.siteName !== '' ? cfg.siteName : 'Studio';
  var pollUrl = typeof cfg.pollUrl === 'string' && cfg.pollUrl !== '' ? cfg.pollUrl : window.location.pathname;
  var ticketExtra =
    typeof cfg.ticketQueryPrefix === 'string' && cfg.ticketQueryPrefix !== '' ? cfg.ticketQueryPrefix : '';
  var lastBell = typeof cfg.bell === 'number' ? cfg.bell : 0;
  var lastPool = typeof cfg.pool === 'number' ? cfg.pool : 0;
  var lastSig = typeof cfg.sig === 'string' ? cfg.sig : '';
  var pollReady = false;
  var baseTitle = document.title;

  function postPoll() {
    var fd = new URLSearchParams();
    fd.set('ajax_action', 'poll');
    fd.set('csrf_token', cfg.csrf);
    return fetch(pollUrl, {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: fd,
      credentials: 'same-origin',
    }).then(function (r) {
      return r.json();
    });
  }

  function tryNotify(title, body, tag) {
    if (!('Notification' in window) || Notification.permission !== 'granted') {
      return;
    }
    try {
      new Notification(title, { body: body, tag: tag || 'akh-portal', silent: false });
    } catch (e) {
      /* ignore */
    }
  }

  function buildTicketHref(anchorId) {
    var q = ticketExtra + 'ticket=' + encodeURIComponent(anchorId);
    var join = pollUrl.indexOf('?') === -1 ? '?' : '&';
    return pollUrl + join + q + '#ticket-' + anchorId;
  }

  function esc(s) {
    return String(s || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function renderDropdown(drop, notices) {
    if (!drop) return;
    if (!notices || notices.length === 0) {
      drop.innerHTML = '<div class="desk-bell-dropdown__empty">No items right now.</div>';
      return;
    }
    drop.innerHTML = notices
      .map(function (n) {
        var aid = String(n.anchor_id || n.task_id || '');
        var tid = String(n.task_id || '');
        var title = esc(n.title || aid);
        var label = esc(n.label || 'Task');
        var detail = esc(n.detail || '').trim();
        var href = buildTicketHref(aid);
        var detailBlock =
          detail !== ''
            ? '<span class="desk-bell-dropdown__detail">' + detail + '</span>'
            : '';
        return (
          '<a class="desk-bell-dropdown__item" href="' +
          href +
          '"><span class="desk-bell-dropdown__label">' +
          label +
          '</span><span class="desk-bell-dropdown__title">' +
          title +
          '</span>' +
          detailBlock +
          '<span class="desk-bell-dropdown__id">' +
          tid.replace(/</g, '') +
          '</span></a>'
        );
      })
      .join('');
  }

  function setBellCount(btn, n) {
    if (!btn) return;
    var c = btn.querySelector('.desk-bell__count');
    if (c && typeof n === 'number') {
      c.textContent = String(n);
    }
    btn.classList.toggle('desk-bell--zero', typeof n === 'number' && n < 1);
    if (typeof n === 'number' && n > 0) {
      btn.classList.add('desk-bell--wiggle', 'desk-bell--pop');
    } else {
      btn.classList.remove('desk-bell--wiggle', 'desk-bell--pop');
    }
  }

  function setTabBellBadge(n) {
    var count = typeof n === 'number' ? n : 0;
    if (count > 0) {
      document.title = '🔔 ' + String(count) + ' · ' + baseTitle;
    } else {
      document.title = baseTitle;
    }
  }

  function mountDeskBellHub() {
    var wrap = document.querySelector('.desk-bell-wrap');
    if (!wrap) return null;
    var btn = wrap.querySelector('.desk-bell');
    var drop = wrap.querySelector('.desk-bell-dropdown');
    if (!btn || !drop) return null;

    renderDropdown(drop, cfg.notices || []);

    btn.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      var open = drop.hidden === false;
      drop.hidden = open;
      btn.setAttribute('aria-expanded', open ? 'false' : 'true');
    });
    document.addEventListener('click', function () {
      drop.hidden = true;
      btn.setAttribute('aria-expanded', 'false');
    });
    wrap.addEventListener('click', function (e) {
      e.stopPropagation();
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        drop.hidden = true;
        btn.setAttribute('aria-expanded', 'false');
      }
    });

    return function (data) {
      if (Array.isArray(data.notices)) {
        renderDropdown(drop, data.notices);
      }
      var b = typeof data.bell === 'number' ? data.bell : lastBell;
      setBellCount(btn, b);
      setTabBellBadge(b);
    };
  }

  function mountPermissionPrompt() {
    if (cfg.mode === 'admin_overview') return;
    if (!('Notification' in window) || Notification.permission !== 'default') return;
    var host = document.querySelector('.portal-card--ticketboard') || document.querySelector('.portal-card');
    if (!host) return;
    var wrap = document.createElement('div');
    wrap.className = 'portal-push-prompt';
    wrap.setAttribute('role', 'region');
    wrap.setAttribute('aria-label', 'Desktop alerts');
    wrap.innerHTML =
      '<p class="portal-push-prompt__text">Get a system-style alert when something changes here (works while this tab is open or in the background).</p>' +
      '<button type="button" class="btn btn--ghost btn--sm portal-push-prompt__btn">Enable alerts</button>';
    var btn = wrap.querySelector('button');
    btn.addEventListener('click', function () {
      Notification.requestPermission().then(function (perm) {
        if (perm === 'granted') {
          tryNotify(site, 'You will get alerts for new activity on this page.', 'akh-portal-on');
          wrap.remove();
        } else {
          wrap.querySelector('.portal-push-prompt__text').textContent =
            'Alerts stay off. You can turn them on later in the browser site settings for this URL.';
          btn.remove();
        }
      });
    });
    host.insertBefore(wrap, host.firstChild);
  }

  var domUpdate = mountDeskBellHub();

  function osNoticeFromPoll(data, fallbackBody) {
    if (data && Array.isArray(data.notices) && data.notices.length > 0) {
      var n = data.notices[0];
      var taskTitle = String(n.title || '').trim();
      var detail = String(n.detail || '').trim();
      var label = String(n.label || '').trim();
      var body = detail || label || fallbackBody;
      var title = taskTitle !== '' ? taskTitle : site;
      return { title: title, body: body };
    }
    return { title: site, body: fallbackBody };
  }

  function onPollData(data) {
    if (!data || !data.ok) return;

    var sig = typeof data.sig === 'string' ? data.sig : '';
    var sigChanged = pollReady && sig !== '' && lastSig !== '' && sig !== lastSig;

    if (cfg.mode === 'admin_overview') {
      if (sig !== '') {
        lastSig = sig;
      }
      pollReady = true;
      return;
    }

    var b = typeof data.bell === 'number' ? data.bell : lastBell;
    var p = typeof data.pool === 'number' ? data.pool : lastPool;

    // Run alerts + bell dropdown from JSON before full reload; otherwise a sig
    // change returned early and never reached this block (no OS notify, no in-tab bell update).
    if (pollReady) {
      if (cfg.mode === 'editor') {
        if (p > lastPool) {
          var poolOs = osNoticeFromPoll(data, 'A new task is in the pool.');
          tryNotify(poolOs.title, poolOs.body, 'akh-editor-pool');
        } else if (b > lastBell) {
          var edOs = osNoticeFromPoll(data, 'Update on your task board.');
          tryNotify(edOs.title, edOs.body, 'akh-editor-bell');
        }
      } else if (cfg.mode === 'client' && b > lastBell) {
        var clOs = osNoticeFromPoll(data, 'Your editor posted an update.');
        tryNotify(clOs.title, clOs.body, 'akh-client-bell');
      } else if (cfg.mode === 'admin_tasks' && b > lastBell) {
        var adOs = osNoticeFromPoll(data, 'New unassigned task(s) in the pool.');
        tryNotify(adOs.title, adOs.body, 'akh-admin-pool');
      }
    }

    if (typeof domUpdate === 'function') {
      domUpdate(data);
    }

    pollReady = true;
    if (sig !== '') {
      lastSig = sig;
    }
    lastBell = b;
    lastPool = p;

    if (sigChanged) {
      window.location.reload();
    }
  }

  function pollTick() {
    postPoll().then(onPollData).catch(function () {});
  }

  function boot() {
    mountPermissionPrompt();
    setTabBellBadge(lastBell);
    setTimeout(pollTick, FIRST_POLL_MS);
    setInterval(pollTick, POLL_MS);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
