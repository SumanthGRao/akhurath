(function () {
  'use strict';

  var area = document.getElementById('paste-content');
  var countEl = document.getElementById('paste-count');
  var statusEl = document.getElementById('paste-status');
  var copyBtn = document.getElementById('paste-copy');
  var clearBtn = document.getElementById('paste-clear');
  var shareBtn = document.getElementById('paste-share');

  if (!area || !countEl || !statusEl || !copyBtn || !clearBtn || !shareBtn) {
    return;
  }

  var storageKey = 'akhurath-paste-draft';
  var maxBytes = Number(area.getAttribute('data-max-bytes') || '100000');
  var saveUrl = area.getAttribute('data-save-url') || '';
  var initialLoaded = area.getAttribute('data-initial-loaded') === '1';

  function setStatus(message, type) {
    statusEl.textContent = message || '';
    statusEl.classList.remove('is-success', 'is-error');
    if (type) {
      statusEl.classList.add('is-' + type);
    }
  }

  function updateCount() {
    var length = area.value.length;
    countEl.textContent = length.toLocaleString() + ' characters';
    shareBtn.disabled = length === 0 || length > maxBytes;
  }

  function saveDraft() {
    try {
      if (area.value.trim() === '') {
        localStorage.removeItem(storageKey);
      } else {
        localStorage.setItem(storageKey, area.value);
      }
    } catch (err) {
      /* ignore storage errors */
    }
  }

  function restoreDraft() {
    if (initialLoaded || area.value.trim() !== '') {
      return;
    }
    try {
      var draft = localStorage.getItem(storageKey);
      if (draft) {
        area.value = draft;
      }
    } catch (err) {
      /* ignore storage errors */
    }
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

  area.addEventListener('input', function () {
    updateCount();
    saveDraft();
    setStatus('');
  });

  copyBtn.addEventListener('click', function () {
    if (area.value === '') {
      setStatus('Nothing to copy yet.', 'error');
      return;
    }

    copyText(area.value)
      .then(function () {
        setStatus('Copied to clipboard.', 'success');
      })
      .catch(function () {
        setStatus('Could not copy. Select the text and copy manually.', 'error');
      });
  });

  clearBtn.addEventListener('click', function () {
    area.value = '';
    updateCount();
    saveDraft();
    setStatus('Cleared.', 'success');
    area.focus();
  });

  shareBtn.addEventListener('click', function () {
    if (!saveUrl) {
      setStatus('Sharing is unavailable right now.', 'error');
      return;
    }

    if (area.value.trim() === '') {
      setStatus('Add some text before sharing.', 'error');
      return;
    }

    if (area.value.length > maxBytes) {
      setStatus('Text is too long to share.', 'error');
      return;
    }

    shareBtn.disabled = true;
    setStatus('Creating share link…');

    fetch(saveUrl, {
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
        if (!payload || !payload.url) {
          throw { error: 'save_failed' };
        }

        return copyText(payload.url).then(function () {
          setStatus('Share link copied. It works once and expires in 24 hours.', 'success');
        });
      })
      .catch(function (err) {
        var code = err && err.error ? err.error : 'save_failed';
        if (code === 'empty') {
          setStatus('Add some text before sharing.', 'error');
        } else if (code === 'too_large') {
          setStatus('Text is too long to share.', 'error');
        } else {
          setStatus('Could not create a share link. Please try again.', 'error');
        }
      })
      .finally(function () {
        updateCount();
      });
  });

  restoreDraft();
  updateCount();

  if (initialLoaded) {
    setStatus('Shared text loaded. This link has now been used.', 'success');
    try {
      localStorage.removeItem(storageKey);
    } catch (err) {
      /* ignore */
    }
  }
})();
