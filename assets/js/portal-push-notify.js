/**
 * Live desk: OS notifications (background tabs), bell dropdown, poll loop.
 */
(function () {
  'use strict';

  var cfg = window._akhPortalPush;
  if (!cfg || !cfg.mode || !cfg.csrf) {
    return;
  }

  var POLL_MS = cfg.mode === 'editor' ? 8000 : 14000;
  var FIRST_POLL_MS = cfg.mode === 'editor' ? 800 : 2200;
  var site = typeof cfg.siteName === 'string' && cfg.siteName !== '' ? cfg.siteName : 'Studio';
  var pollUrl = typeof cfg.pollUrl === 'string' && cfg.pollUrl !== '' ? cfg.pollUrl : window.location.pathname;
  var ticketExtra =
    typeof cfg.ticketQueryPrefix === 'string' && cfg.ticketQueryPrefix !== '' ? cfg.ticketQueryPrefix : '';
  var lastBell = typeof cfg.bell === 'number' ? cfg.bell : 0;
  var lastPool = typeof cfg.pool === 'number' ? cfg.pool : 0;
  var lastSig = typeof cfg.sig === 'string' ? cfg.sig : '';
  var lastNotifySig = typeof cfg.notify_sig === 'string' ? cfg.notify_sig : '';
  var pollReady = false;
  var baseTitle = document.title;
  var hiddenBeepAudio = null;

  function deskTabInactive() {
    return document.hidden || !document.hasFocus();
  }

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

  function buildTicketHref(anchorId) {
    var q = ticketExtra + 'ticket=' + encodeURIComponent(anchorId);
    var join = pollUrl.indexOf('?') === -1 ? '?' : '&';
    return pollUrl + join + q + '#ticket-' + anchorId;
  }

  function unlockHiddenBeep() {
    if (hiddenBeepAudio) return;
    try {
      hiddenBeepAudio = new Audio(
        'data:audio/wav;base64,UklGRlIAAABXQVZFZm10IBAAAAABAAEARKwAAIhYAQACABAAZGF0YQ4AAAA='
      );
      hiddenBeepAudio.volume = 0.01;
      hiddenBeepAudio.play().then(function () {
        hiddenBeepAudio.pause();
        hiddenBeepAudio.currentTime = 0;
        hiddenBeepAudio.volume = 1;
      }).catch(function () {});
    } catch (e) {
      hiddenBeepAudio = null;
    }
  }

  function playHiddenBeep(times) {
    var count = typeof times === 'number' ? times : 2;
    if (!hiddenBeepAudio) return;
    var i = 0;
    function ping() {
      try {
        hiddenBeepAudio.currentTime = 0;
        hiddenBeepAudio.volume = 1;
        hiddenBeepAudio.play().catch(function () {});
      } catch (e) {}
      i += 1;
      if (i < count) setTimeout(ping, 320);
    }
    ping();
  }

  function tryOsNotify(title, body, taskId, tag) {
    if (!('Notification' in window) || Notification.permission !== 'granted') {
      return false;
    }
    try {
      var n = new Notification(title, {
        body: body,
        tag: tag || 'akh-portal',
        silent: false,
        renotify: true,
      });
      n.onclick = function () {
        try {
          window.focus();
        } catch (e) {}
        if (taskId && window.AkhEditorDesk && typeof window.AkhEditorDesk.selectTask === 'function') {
          window.AkhEditorDesk.selectTask(taskId);
        } else if (taskId) {
          window.location.href = buildTicketHref(taskId);
        }
        n.close();
      };
      return true;
    } catch (e) {
      return false;
    }
  }

  /**
   * In-tab popup + sound when focused; OS notification + system sound when in another tab.
   *
   * @param {{title?: string, body?: string, label?: string, taskId?: string, icon?: string, beep?: number, tag?: string}} opts
   */
  function notifyDeskActivity(opts) {
    opts = opts || {};
    var taskId = String(opts.taskId || '').trim();
    var title = String(opts.title || taskId || site).trim();
    var body = String(opts.body || 'New activity on your board.').trim();
    var label = String(opts.label || 'Update').trim();
    var tag = String(opts.tag || 'akh-desk-' + (taskId || label || 'general'));
    var inactive = deskTabInactive();
    var osSent = tryOsNotify(title, body, taskId, tag);

    if (!inactive && window.AkhEditorDesk) {
      if (typeof window.AkhEditorDesk.playLoudAlert === 'function') {
        window.AkhEditorDesk.playLoudAlert(typeof opts.beep === 'number' ? opts.beep : 2);
      }
      if (typeof window.AkhEditorDesk.showDeskAlert === 'function') {
        window.AkhEditorDesk.showDeskAlert({
          taskId: taskId,
          title: title,
          label: label,
          body: body,
          icon: opts.icon || '🔔',
        });
      }
    } else if (inactive && !osSent) {
      playHiddenBeep(typeof opts.beep === 'number' ? opts.beep : 2);
    }
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
    var host = document.getElementById('editor-desk') || document.querySelector('.portal-card--ticketboard') || document.querySelector('.portal-card');
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
      unlockHiddenBeep();
      Notification.requestPermission().then(function (perm) {
        if (perm === 'granted') {
          tryOsNotify(site, 'You will get alerts for new activity on this page.', '', 'akh-portal-on');
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

  function processMeetingReminders(reminders) {
    if (window.AkhMeetingAlerts) {
      AkhMeetingAlerts.processReminders(reminders);
    }
  }

  function noticePayload(data, fallbackBody) {
    if (data && Array.isArray(data.notices) && data.notices.length > 0) {
      var n = data.notices[0];
      return {
        taskId: String(n.task_id || n.anchor_id || ''),
        title: String(n.title || n.task_id || site).trim() || site,
        label: String(n.label || 'Update').trim(),
        body: String(n.detail || n.label || fallbackBody).trim() || fallbackBody,
        icon: String(n.label || '').toLowerCase().indexOf('message') !== -1 ? '💬' : '🔔',
      };
    }
    return {
      taskId: '',
      title: site,
      label: 'Update',
      body: fallbackBody,
      icon: '🔔',
    };
  }

  function onPollData(data) {
    if (!data || !data.ok) return;

    var sig = typeof data.sig === 'string' ? data.sig : '';
    var sigChanged = pollReady && sig !== '' && lastSig !== '' && sig !== lastSig;
    var notifySig = typeof data.notify_sig === 'string' ? data.notify_sig : '';
    var notifyChanged = pollReady && notifySig !== '' && notifySig !== lastNotifySig;

    if (cfg.mode === 'admin_overview') {
      if (sig !== '') {
        lastSig = sig;
      }
      pollReady = true;
      return;
    }

    var b = typeof data.bell === 'number' ? data.bell : lastBell;
    var p = typeof data.pool === 'number' ? data.pool : lastPool;

    if (pollReady && cfg.mode !== 'editor') {
      if (cfg.mode === 'client' && b > lastBell) {
        var cl = noticePayload(data, 'Your editor posted an update.');
        tryOsNotify(cl.title, cl.body, cl.taskId, 'akh-client-bell');
      } else if (cfg.mode === 'admin_tasks' && b > lastBell) {
        var ad = noticePayload(data, 'New unassigned task(s) in the pool.');
        tryOsNotify(ad.title, ad.body, ad.taskId, 'akh-admin-pool');
      }
    }

    if (typeof domUpdate === 'function') {
      domUpdate(data);
    }

    if (Array.isArray(data.reminders)) {
      processMeetingReminders(data.reminders);
    }

    if (cfg.mode === 'editor' && window.AkhEditorDesk && typeof window.AkhEditorDesk.handlePoll === 'function') {
      window.AkhEditorDesk.handlePoll(data, {
        pollReady: pollReady,
        bellUp: b > lastBell,
        poolUp: p > lastPool,
        notifyChanged: notifyChanged,
      });
    } else if (sigChanged) {
      window.location.reload();
    }

    pollReady = true;
    if (sig !== '') {
      lastSig = sig;
    }
    if (notifySig !== '') {
      lastNotifySig = notifySig;
    }
    lastBell = b;
    lastPool = p;
  }

  function pollTick() {
    if (window.AkhEditorDesk && typeof window.AkhEditorDesk.setLiveSyncing === 'function') {
      window.AkhEditorDesk.setLiveSyncing(true);
    }
    postPoll()
      .then(onPollData)
      .catch(function () {})
      .finally(function () {
        if (window.AkhEditorDesk && typeof window.AkhEditorDesk.setLiveSyncing === 'function') {
          window.AkhEditorDesk.setLiveSyncing(false);
        }
      });
  }

  function boot() {
    mountPermissionPrompt();
    setTabBellBadge(lastBell);
    document.addEventListener('pointerdown', unlockHiddenBeep, { once: true });
    document.addEventListener('keydown', unlockHiddenBeep, { once: true });
    setTimeout(pollTick, FIRST_POLL_MS);
    setInterval(pollTick, POLL_MS);
  }

  window.AkhPortalPush = window.AkhPortalPush || {};
  window.AkhPortalPush.notify = notifyDeskActivity;
  window.AkhPortalPush.tryOsNotify = tryOsNotify;

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
