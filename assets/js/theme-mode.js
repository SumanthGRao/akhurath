(function () {
  'use strict';

  var STORAGE_KEY = 'akh-theme';

  function themeAllowed() {
    return !!(document.body && document.body.classList.contains('akh-theme-enabled'));
  }

  function currentTheme() {
    if (!themeAllowed()) {
      return 'light';
    }
    var attr = document.documentElement.getAttribute('data-theme');
    if (attr === 'dark' || attr === 'light') {
      return attr;
    }
    try {
      var saved = localStorage.getItem(STORAGE_KEY);
      if (saved === 'dark' || saved === 'light') {
        return saved;
      }
    } catch (e) {}
    return 'light';
  }

  function applyTheme(theme) {
    if (!themeAllowed()) {
      document.documentElement.removeAttribute('data-theme');
      return;
    }

    var next = theme === 'dark' ? 'dark' : 'light';
    document.documentElement.setAttribute('data-theme', next);
    try {
      localStorage.setItem(STORAGE_KEY, next);
    } catch (e) {}
    document.querySelectorAll('[data-akh-theme-toggle]').forEach(function (btn) {
      var label = btn.querySelector('[data-akh-theme-label]');
      var isDark = next === 'dark';
      btn.setAttribute('aria-label', isDark ? 'Switch to light mode' : 'Switch to dark mode');
      btn.setAttribute('title', isDark ? 'Switch to light mode' : 'Switch to dark mode');
      btn.classList.toggle('is-dark', isDark);
      if (label) {
        label.textContent = isDark ? 'Light' : 'Dark';
      }
    });
  }

  function toggleTheme() {
    applyTheme(currentTheme() === 'dark' ? 'light' : 'dark');
  }

  document.addEventListener('click', function (e) {
    var btn = e.target && e.target.closest ? e.target.closest('[data-akh-theme-toggle]') : null;
    if (!btn) {
      return;
    }
    e.preventDefault();
    toggleTheme();
  });

  applyTheme(currentTheme());
})();
