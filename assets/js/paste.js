(function () {
  'use strict';

  var area = document.getElementById('paste-content');
  var countEl = document.getElementById('paste-count');
  var statusEl = document.getElementById('paste-status');
  var copyBtn = document.getElementById('paste-copy');
  var clearBtn = document.getElementById('paste-clear');

  if (!area || !countEl || !statusEl || !copyBtn || !clearBtn) {
    return;
  }

  var maxBytes = Number(area.getAttribute('data-max-bytes') || '100000');
  var apiUrl = area.getAttribute('data-api-url') || '';
  var syncedUpdated = Number(area.getAttribute('data-updated') || '0');
  var saveTimer = null;
  var pollTimer = null;
  var isFocused = false;
  var isSaving = false;

  function setStatus(message, type) {
    statusEl.textContent = message || '';
    statusEl.classList.remove('is-success', 'is-error', 'is-syncing');
    if (type) {
      statusEl.classList.add('is-' + type);
    }
  }

  function updateCount() {
    var length = area.value.length;
    countEl.textContent = length.toLocaleString() + ' characters';
  }

  function copyText(text) {
    if (navigator.clipboard && window.isSecureContext) {
      return navigator.clipboard.writeText(text);
    }

    return new Promise(function (resolve, reject) {
      var helper = document.createElement('textarea');
      helper.value = text;
      helper.setAttribute('readonly', '');
      helper.style.position = 'fixed';
      helper.style.left = '-9999px';
      document.body.appendChild(helper);
      helper.select();
      try {
        document.execCommand('copy');
        resolve();
      } catch (err) {
        reject(err);
      } finally {
        document.body.removeChild(helper);
      }
    });
  }

  function applyRemote(content, updated) {
    if (updated <= syncedUpdated) {
      return;
    }

    area.value = content;
    syncedUpdated = updated;
    updateCount();
    setStatus('Updated from another device.', 'success');
    window.setTimeout(function () {
      if (!isSaving) {
        setStatus('Synced', 'success');
      }
    }, 1800);
  }

  function fetchClipboard() {
    if (!apiUrl) {
      return Promise.resolve(null);
    }

    return fetch(apiUrl, {
      method: 'GET',
      headers: { Accept: 'application/json' },
      cache: 'no-store',
    })
      .then(function (response) {
        return response.json().then(function (payload) {
          if (!response.ok) {
            throw payload;
          }
          return payload;
        });
      })
      .catch(function () {
        return null;
      });
  }

  function pollClipboard() {
    if (isFocused || isSaving) {
      return;
    }

    fetchClipboard().then(function (payload) {
      if (!payload || typeof payload.updated !== 'number') {
        return;
      }

      if (payload.updated > syncedUpdated && payload.content !== area.value) {
        applyRemote(String(payload.content || ''), payload.updated);
      } else if (!isSaving) {
        setStatus('Synced', 'success');
      }
    });
  }

  function pushClipboard() {
    if (!apiUrl) {
      setStatus('Sync is unavailable right now.', 'error');
      return;
    }

    if (area.value.length > maxBytes) {
      setStatus('Text is too long.', 'error');
      return;
    }

    isSaving = true;
    setStatus('Saving…', 'syncing');

    fetch(apiUrl, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
      },
      body: JSON.stringify({ content: area.value }),
    })
      .then(function (response) {
        return response.json().then(function (payload) {
          if (!response.ok) {
            throw payload;
          }
          return payload;
        });
      })
      .then(function (payload) {
        if (!payload || typeof payload.updated !== 'number') {
          throw { error: 'save_failed' };
        }

        syncedUpdated = payload.updated;
        setStatus('Synced', 'success');
      })
      .catch(function (err) {
        var code = err && err.error ? err.error : 'save_failed';
        if (code === 'too_large') {
          setStatus('Text is too long.', 'error');
        } else {
          setStatus('Could not save. Will retry shortly.', 'error');
        }
      })
      .finally(function () {
        isSaving = false;
      });
  }

  function scheduleSave() {
    window.clearTimeout(saveTimer);
    saveTimer = window.setTimeout(pushClipboard, 450);
  }

  area.addEventListener('input', function () {
    updateCount();
    scheduleSave();
  });

  area.addEventListener('focus', function () {
    isFocused = true;
  });

  area.addEventListener('blur', function () {
    isFocused = false;
    pollClipboard();
  });

  copyBtn.addEventListener('click', function () {
    if (area.value === '') {
      setStatus('Nothing to copy yet.', 'error');
      return;
    }

    copyText(area.value)
      .then(function () {
        setStatus('Copied to clipboard.', 'success');
        window.setTimeout(function () {
          if (!isSaving) {
            setStatus('Synced', 'success');
          }
        }, 1800);
      })
      .catch(function () {
        setStatus('Could not copy. Select the text and copy manually.', 'error');
      });
  });

  clearBtn.addEventListener('click', function () {
    area.value = '';
    updateCount();
    window.clearTimeout(saveTimer);
    pushClipboard();
    area.focus();
  });

  updateCount();
  setStatus('Synced', 'success');
  pollTimer = window.setInterval(pollClipboard, 2000);
  window.addEventListener('beforeunload', function () {
    window.clearInterval(pollTimer);
    window.clearTimeout(saveTimer);
  });
})();
