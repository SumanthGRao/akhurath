/**
 * Shared desk alerts: unlocked audio, popup toasts, browser/OS notifications.
 */
(function (global) {
  'use strict';

  var audioCtx = null;
  var silentLoop = null;
  var unlocked = false;
  var swRegistration = null;
  var notifyIcon = '';
  var notifySwUrl = '';
  var notifySwScope = '/sw/';
  var SILENT_WAV =
    'data:audio/wav;base64,UklGRigAAABXQVZFZm10IBAAAAABAAEARKwAAIhYAQACABAAZGF0YQQAAAAAAA==';

  function readBootConfig() {
    var cfg = global._akhDeskNotify || {};
    if (typeof cfg.icon === 'string') {
      notifyIcon = cfg.icon;
    }
    if (typeof cfg.swUrl === 'string') {
      notifySwUrl = cfg.swUrl;
    }
    if (typeof cfg.swScope === 'string' && cfg.swScope !== '') {
      notifySwScope = cfg.swScope;
    }
  }

  function esc(s) {
    return String(s || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function permissionState() {
    if (!('Notification' in global)) {
      return 'unsupported';
    }
    return Notification.permission;
  }

  function initServiceWorker() {
    readBootConfig();
    if (!notifySwUrl || !('serviceWorker' in navigator)) {
      return Promise.resolve(null);
    }
    if (swRegistration) {
      return Promise.resolve(swRegistration);
    }
    return navigator.serviceWorker
      .register(notifySwUrl, { scope: notifySwScope })
      .then(function (reg) {
        swRegistration = reg;
        return reg;
      })
      .catch(function () {
        return null;
      });
  }

  function unlockAudio() {
    if (unlocked && audioCtx && audioCtx.state === 'running') {
      return;
    }
    unlocked = true;
    try {
      var Ctx = global.AudioContext || global.webkitAudioContext;
      if (Ctx && !audioCtx) {
        audioCtx = new Ctx();
      }
      if (audioCtx && audioCtx.state === 'suspended') {
        audioCtx.resume();
      }
    } catch (e) {
      /* ignore */
    }
    startKeepalive();
    initServiceWorker();
  }

  function startKeepalive() {
    if (silentLoop) {
      return;
    }
    try {
      silentLoop = new Audio(SILENT_WAV);
      silentLoop.loop = true;
      silentLoop.volume = 0.001;
      silentLoop.setAttribute('playsinline', '');
      var p = silentLoop.play();
      if (p && typeof p.catch === 'function') {
        p.catch(function () {});
      }
    } catch (e) {
      silentLoop = null;
    }
  }

  function playAlert(times) {
    unlockAudio();
    var count = typeof times === 'number' ? times : 2;
    try {
      if (!audioCtx) {
        return;
      }
      var freqs = [880, 1175, 988, 1318];
      for (var i = 0; i < count; i += 1) {
        (function (idx) {
          var o = audioCtx.createOscillator();
          var g = audioCtx.createGain();
          o.type = 'square';
          o.frequency.value = freqs[idx % freqs.length];
          var t0 = audioCtx.currentTime + idx * 0.24;
          g.gain.setValueAtTime(0.0001, t0);
          g.gain.exponentialRampToValueAtTime(0.34, t0 + 0.03);
          g.gain.exponentialRampToValueAtTime(0.0001, t0 + 0.22);
          o.connect(g);
          g.connect(audioCtx.destination);
          o.start(t0);
          o.stop(t0 + 0.24);
        })(i);
      }
    } catch (e) {
      /* ignore */
    }
  }

  function postSwNotify(title, body, tag, url, taskId) {
    if (!('serviceWorker' in navigator)) {
      return false;
    }
    var payload = {
      type: 'notify',
      title: title,
      body: body,
      tag: tag,
      url: url || global.location.href,
      taskId: taskId || '',
      icon: notifyIcon || undefined,
    };
    try {
      if (navigator.serviceWorker.controller) {
        navigator.serviceWorker.controller.postMessage(payload);
        return true;
      }
      if (swRegistration && swRegistration.active) {
        swRegistration.active.postMessage(payload);
        return true;
      }
    } catch (e) {
      /* ignore */
    }
    return false;
  }

  function tryOsNotify(title, body, tag, onClick, taskId, url) {
    if (!('Notification' in global) || Notification.permission !== 'granted') {
      return false;
    }
    var safeTitle = String(title || 'Update');
    var safeBody = String(body || '');
    var safeTag = String(tag || 'akh-desk-alert');
    var targetUrl = String(url || global.location.href);

    if (postSwNotify(safeTitle, safeBody, safeTag, targetUrl, taskId)) {
      return true;
    }

    try {
      var options = {
        body: safeBody,
        tag: safeTag,
        silent: false,
        renotify: true,
      };
      if (notifyIcon) {
        options.icon = notifyIcon;
        options.badge = notifyIcon;
      }
      var n = new Notification(safeTitle, options);
      n.onclick = function () {
        try {
          global.focus();
        } catch (e) {}
        if (typeof onClick === 'function') {
          onClick();
        } else if (targetUrl) {
          global.location.href = targetUrl;
        }
        n.close();
      };
      return true;
    } catch (e) {
      return false;
    }
  }

  function showPopup(host, opts) {
    opts = opts || {};
    if (!host) {
      return null;
    }
    var el = document.createElement('button');
    el.type = 'button';
    el.className = 'edesk-chat-alert desk-alert-popup';
    var title = String(opts.title || opts.taskId || 'Update').trim();
    var body = String(opts.body || '').trim();
    var label = String(opts.label || 'Notification').trim();
    el.innerHTML =
      '<span class="edesk-chat-alert__icon" aria-hidden="true">' +
      esc(opts.icon || '🔔') +
      '</span>' +
      '<span class="edesk-chat-alert__content">' +
      '<span class="edesk-chat-alert__label">' +
      esc(label) +
      '</span>' +
      '<strong class="edesk-chat-alert__title">' +
      esc(title) +
      '</strong>' +
      (body !== ''
        ? '<span class="edesk-chat-alert__body">' + esc(body) + '</span>'
        : '') +
      '</span>' +
      '<span class="edesk-chat-alert__close" aria-hidden="true">×</span>';
    el.addEventListener('click', function () {
      if (typeof opts.onClick === 'function') {
        opts.onClick();
      }
      el.classList.remove('edesk-chat-alert--in');
      setTimeout(function () {
        el.remove();
      }, 220);
    });
    host.appendChild(el);
    requestAnimationFrame(function () {
      el.classList.add('edesk-chat-alert--in');
    });
    setTimeout(function () {
      if (!el.parentNode) {
        return;
      }
      el.classList.remove('edesk-chat-alert--in');
      setTimeout(function () {
        el.remove();
      }, 280);
    }, 14000);
    return el;
  }

  /**
   * @param {{host?: HTMLElement, title?: string, body?: string, label?: string, icon?: string, beep?: number, tag?: string, taskId?: string, url?: string, onClick?: function}} opts
   */
  function notify(opts) {
    opts = opts || {};
    var title = String(opts.title || opts.taskId || 'Update').trim();
    var body = String(opts.body || 'New activity on your board.').trim();
    var label = String(opts.label || 'Update').trim();
    var tag = String(opts.tag || 'akh-desk-' + (opts.taskId || label));
    var hidden = !!global.document.hidden;

    playAlert(typeof opts.beep === 'number' ? opts.beep : 2);

    if (!hidden) {
      showPopup(opts.host || document.querySelector('.desk-alert-host'), opts);
    }

    if (permissionState() === 'granted') {
      tryOsNotify(title, body, tag, opts.onClick, opts.taskId, opts.url);
    }
  }

  function requestPermission(onDone) {
    unlockAudio();
    if (!('Notification' in global)) {
      if (typeof onDone === 'function') {
        onDone('unsupported');
      }
      return;
    }
    if (Notification.permission !== 'default') {
      initServiceWorker().finally(function () {
        if (typeof onDone === 'function') {
          onDone(Notification.permission);
        }
      });
      return;
    }
    Notification.requestPermission()
      .then(function (perm) {
        return initServiceWorker().then(function () {
          return perm;
        });
      })
      .then(function (perm) {
        if (typeof onDone === 'function') {
          onDone(perm);
        }
      });
  }

  readBootConfig();
  initServiceWorker();

  global.DeskAlert = {
    unlock: unlockAudio,
    startKeepalive: startKeepalive,
    play: playAlert,
    popup: showPopup,
    osNotify: tryOsNotify,
    notify: notify,
    requestPermission: requestPermission,
    permissionState: permissionState,
    initServiceWorker: initServiceWorker,
  };

  document.addEventListener('pointerdown', unlockAudio, { once: true, capture: true });
  document.addEventListener('keydown', unlockAudio, { once: true, capture: true });
})(window);
