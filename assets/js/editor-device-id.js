/**
 * Stable per-browser Device ID for editor portal allowlist (shown on login page).
 */
(function () {
  'use strict';

  var storageKey = 'akh_editor_device_id';
  var display = document.getElementById('editor-device-id-value');
  var input = document.getElementById('editor-device-id-input');

  function newId() {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
      return 'edv_' + crypto.randomUUID();
    }
    var s = '';
    for (var i = 0; i < 32; i++) {
      s += ((Math.random() * 16) | 0).toString(16);
    }
    return 'edv_' + s.slice(0, 8) + '-' + s.slice(8, 12) + '-' + s.slice(12, 16) + '-' + s.slice(16, 20) + '-' + s.slice(20, 32);
  }

  var id = '';
  try {
    id = localStorage.getItem(storageKey) || '';
  } catch (e) {
    id = '';
  }
  if (!id || id.indexOf('edv_') !== 0) {
    id = newId();
    try {
      localStorage.setItem(storageKey, id);
    } catch (e2) {
      /* private mode */
    }
  }

  if (display) {
    display.textContent = id;
  }
  if (input) {
    input.value = id;
  }

  var copyBtn = document.getElementById('editor-device-id-copy');
  if (copyBtn) {
    copyBtn.addEventListener('click', function () {
      var text = id;
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(function () {
          copyBtn.textContent = 'Copied';
          setTimeout(function () {
            copyBtn.textContent = 'Copy ID';
          }, 2000);
        });
      } else {
        window.prompt('Copy this Device ID:', text);
      }
    });
  }
})();
