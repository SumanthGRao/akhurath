/**
 * Meeting alerts: chime, browser notifications, 5-minute join modal.
 */
(function (global) {
  'use strict';

  var STORAGE_PREFIX = 'akh_meet_alert_';
  var modalEl = null;
  var joinBtn = null;
  var joinLink = null;
  var titleEl = null;
  var bodyEl = null;
  var lastNotifySig = '';

  function ensureModal() {
    if (modalEl) {
      return;
    }
    modalEl = document.getElementById('akh-meeting-join-modal');
    if (!modalEl) {
      return;
    }
    joinBtn = document.getElementById('akh-meeting-join-btn');
    joinLink = document.getElementById('akh-meeting-join-link');
    titleEl = document.getElementById('akh-meeting-join-title');
    bodyEl = document.getElementById('akh-meeting-join-body');
    var closeBtn = document.getElementById('akh-meeting-join-close');
    var laterBtn = document.getElementById('akh-meeting-join-later');
    function closeModal() {
      if (modalEl && typeof modalEl.close === 'function') {
        modalEl.close();
      } else if (modalEl) {
        modalEl.hidden = true;
      }
    }
    if (closeBtn) closeBtn.addEventListener('click', closeModal);
    if (laterBtn) laterBtn.addEventListener('click', closeModal);
    if (joinBtn) {
      joinBtn.addEventListener('click', function () {
        var href = joinBtn.getAttribute('data-href') || '';
        if (href) {
          window.open(href, '_blank', 'noopener,noreferrer');
        }
        closeModal();
      });
    }
  }

  function playChime(times) {
    var n = typeof times === 'number' ? times : 1;
    var i = 0;
    function ping() {
      try {
        var Ctx = global.AudioContext || global.webkitAudioContext;
        if (!Ctx) return;
        var ctx = new Ctx();
        var o = ctx.createOscillator();
        var g = ctx.createGain();
        o.type = 'sine';
        o.frequency.value = i === 0 ? 880 : 988;
        g.gain.setValueAtTime(0.0001, ctx.currentTime);
        g.gain.exponentialRampToValueAtTime(0.12, ctx.currentTime + 0.02);
        g.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.35);
        o.connect(g);
        g.connect(ctx.destination);
        o.start(ctx.currentTime);
        o.stop(ctx.currentTime + 0.4);
        setTimeout(function () { ctx.close(); }, 500);
      } catch (e) {
        /* ignore */
      }
      i += 1;
      if (i < n) {
        setTimeout(ping, 420);
      }
    }
    ping();
  }

  function tryOsNotify(title, body, tag) {
    if (!('Notification' in global) || Notification.permission !== 'granted') {
      return;
    }
    try {
      new Notification(title, { body: body, tag: tag || 'akh-meeting', silent: false });
    } catch (e) {
      /* ignore */
    }
  }

  function showJoinModal(rem) {
    ensureModal();
    if (!modalEl) {
      return;
    }
    var code = String(rem.task_code || '');
    var mins = String(rem.minutes_until || '5');
    var href = String(rem.meet_link || '').trim();
    var title = String(rem.title || code || 'Meeting');
    if (titleEl) {
      titleEl.textContent = title;
    }
    if (bodyEl) {
      bodyEl.textContent =
        'Task ' + code + ' — meeting starts in about ' + mins + ' minute(s).' +
        (href ? ' Click Join to open Google Meet.' : ' Open the task for meeting details.');
    }
    if (joinBtn) {
      joinBtn.hidden = href === '';
      joinBtn.setAttribute('data-href', href);
    }
    if (joinLink) {
      if (href) {
        joinLink.href = href;
        joinLink.hidden = false;
      } else {
        joinLink.hidden = true;
      }
    }
    playChime(2);
    tryOsNotify(title, 'Meeting starts in ' + mins + ' min', 'akh-meet-join-' + String(rem.id || code));
    if (typeof modalEl.showModal === 'function') {
      modalEl.showModal();
    } else {
      modalEl.hidden = false;
    }
  }

  function processReminders(reminders) {
    if (!Array.isArray(reminders)) {
      return;
    }
    reminders.forEach(function (rem) {
      var id = String(rem.id || '');
      var tier = String(rem.tier || '');
      if (!id || !tier) {
        return;
      }
      var key = STORAGE_PREFIX + id + '_' + tier;
      if (sessionStorage.getItem(key) === '1') {
        return;
      }
      sessionStorage.setItem(key, '1');
      if (tier === '5') {
        showJoinModal(rem);
      } else {
        playChime(1);
        tryOsNotify(String(rem.title || 'Meeting soon'), String(rem.body || ''), 'akh-meet-' + id + '-' + tier);
      }
    });
  }

  function onNotifyChange(sig, count) {
    if (!sig || sig === lastNotifySig) {
      return;
    }
    if (lastNotifySig && typeof count === 'number' && count > 0) {
      playChime(1);
    }
    lastNotifySig = sig;
  }

  function init(cfg) {
    lastNotifySig = (cfg && cfg.notifySig) || '';
    ensureModal();
    if (cfg && cfg.reminders) {
      processReminders(cfg.reminders);
    }
  }

  global.AkhMeetingAlerts = {
    init: init,
    playChime: playChime,
    processReminders: processReminders,
    onNotifyChange: onNotifyChange,
    showJoinModal: showJoinModal,
  };
})(window);
