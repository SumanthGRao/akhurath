/**
 * Shared desk alerts: unlocked audio, popup toasts, browser/OS notifications.
 */
(function (global) {
  'use strict';

  var audioCtx = null;
  var silentLoop = null;
  var chimeAudio = null;
  var chimePrimed = false;
  var unlocked = false;
  var swRegistration = null;
  var notifyIcon = '';
  var notifySwUrl = '';
  var notifySwScope = '/sw/';
  var chimeUrl = '';
  var popupSeq = 0;
  var MAX_POPUPS = 6;

  function uniqueNotifyTag(base) {
    return (
      String(base || 'akh-desk-alert') +
      '-' +
      Date.now().toString(36) +
      '-' +
      Math.random().toString(36).slice(2, 8)
    );
  }
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
    if (typeof cfg.chimeUrl === 'string' && cfg.chimeUrl !== '') {
      chimeUrl = cfg.chimeUrl;
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
    unlocked = true;
    readBootConfig();
    try {
      var Ctx = global.AudioContext || global.webkitAudioContext;
      if (Ctx && !audioCtx) {
        audioCtx = new Ctx();
      }
      if (audioCtx && audioCtx.state === 'suspended' && typeof audioCtx.resume === 'function') {
        audioCtx.resume().catch(function () {});
      }
    } catch (e) {
      /* ignore */
    }
    primeChimeAudio();
    startKeepalive();
    initServiceWorker();
  }

  function getChimeAudio() {
    if (!chimeUrl) {
      return null;
    }
    if (!chimeAudio) {
      chimeAudio = new Audio(chimeUrl);
      chimeAudio.preload = 'auto';
      chimeAudio.volume = 0.62;
      chimeAudio.setAttribute('playsinline', '');
    }
    return chimeAudio;
  }

  function primeChimeAudio() {
    var audio = getChimeAudio();
    if (!audio || chimePrimed) {
      return;
    }
    chimePrimed = true;
    try {
      var prev = audio.volume;
      audio.volume = 0.001;
      var p = audio.play();
      if (p && typeof p.then === 'function') {
        p.then(function () {
          audio.pause();
          audio.currentTime = 0;
          audio.volume = prev;
        }).catch(function () {
          chimePrimed = false;
          audio.volume = prev;
        });
      }
    } catch (e) {
      chimePrimed = false;
    }
  }

  function playElementChime(times) {
    var audio = getChimeAudio();
    if (!audio) {
      return false;
    }
    var repeats = typeof times === 'number' && times > 1 ? Math.min(times, 2) : 1;
    var i = 0;
    function ping() {
      try {
        audio.currentTime = 0;
        audio.volume = i > 0 ? 0.5 : 0.62;
        var p = audio.play();
        if (p && typeof p.catch === 'function') {
          p.catch(function () {});
        }
      } catch (e) {
        /* ignore */
      }
      i += 1;
      if (i < repeats) {
        setTimeout(ping, 420);
      }
    }
    ping();
    return true;
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

  function ensureAudioReady() {
    unlockAudio();
    var audioReady = !!getChimeAudio();
    if (!audioCtx) {
      return Promise.resolve(audioReady);
    }
    if (audioCtx.state === 'suspended') {
      return audioCtx.resume().then(function () {
        return audioCtx.state === 'running' || audioReady;
      }).catch(function () {
        return audioReady;
      });
    }
    return Promise.resolve(audioCtx.state === 'running' || audioReady);
  }

  /**
   * Soft two-note chime (chat-style): subtle sine tones with a warm low-pass.
   * @param {number} times 1 = single chime, 2+ = gentle repeat for urgent alerts
   */
  function playChatChime(times) {
    unlockAudio();
    if (playElementChime(times)) {
      return;
    }
    ensureAudioReady().then(function (ready) {
      if (!ready || !audioCtx) {
        return;
      }
      var repeats = typeof times === 'number' && times > 1 ? Math.min(times, 2) : 1;
      for (var r = 0; r < repeats; r += 1) {
        (function (repeatIdx) {
          var base = audioCtx.currentTime + repeatIdx * 0.42;
          var notes = [
            { freq: 523.25, start: 0, peak: 0.11, duration: 0.2 },
            { freq: 659.25, start: 0.1, peak: 0.09, duration: 0.26 },
          ];
          var master = audioCtx.createGain();
          master.gain.value = repeatIdx > 0 ? 0.82 : 1;
          master.connect(audioCtx.destination);

          var filter = audioCtx.createBiquadFilter();
          filter.type = 'lowpass';
          filter.frequency.value = 2200;
          filter.Q.value = 0.55;
          filter.connect(master);

          notes.forEach(function (note) {
            var t0 = base + note.start;
            var osc = audioCtx.createOscillator();
            var harmonic = audioCtx.createOscillator();
            var tone = audioCtx.createGain();
            var shimmer = audioCtx.createGain();

            osc.type = 'sine';
            osc.frequency.value = note.freq;
            harmonic.type = 'triangle';
            harmonic.frequency.value = note.freq * 2;
            shimmer.gain.value = 0.07;

            tone.gain.setValueAtTime(0.0001, t0);
            tone.gain.exponentialRampToValueAtTime(note.peak, t0 + 0.02);
            tone.gain.exponentialRampToValueAtTime(0.0001, t0 + note.duration);

            osc.connect(tone);
            harmonic.connect(shimmer);
            shimmer.connect(tone);
            tone.connect(filter);

            osc.start(t0);
            harmonic.start(t0);
            osc.stop(t0 + note.duration + 0.04);
            harmonic.stop(t0 + note.duration + 0.04);
          });
        })(r);
      }
    });
  }

  function playAlert(times) {
    playChatChime(times);
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
    var existing = host.querySelectorAll('.desk-alert-popup');
    if (existing.length >= MAX_POPUPS) {
      existing[0].remove();
    }
    var el = document.createElement('button');
    el.type = 'button';
    el.className = 'edesk-chat-alert desk-alert-popup';
    popupSeq += 1;
    el.style.zIndex = String(120 + popupSeq);
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
      requestAnimationFrame(function () {
        el.classList.add('edesk-chat-alert--in');
      });
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
    var baseTag = String(opts.tag || 'akh-desk-' + (opts.taskId || label));
    var eventTag = uniqueNotifyTag(baseTag);
    var hidden = !!global.document.hidden;

    playAlert(typeof opts.beep === 'number' ? opts.beep : 1);

    if (!hidden || opts.forcePopup) {
      showPopup(opts.host || document.querySelector('.desk-alert-host'), opts);
    }

    if (permissionState() === 'granted') {
      tryOsNotify(title, body, eventTag, opts.onClick, opts.taskId, opts.url);
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

  document.addEventListener('pointerdown', unlockAudio, { capture: true });
  document.addEventListener('keydown', unlockAudio, { capture: true });
  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) {
      unlockAudio();
    }
  });
})(window);
