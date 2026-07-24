<?php

declare(strict_types=1);

/**
 * Light / dark theme toggle for portal dashboards.
 * Preference is stored in localStorage (akh-theme: light | dark).
 */

function akh_theme_mode_asset_version(string $relativePath): string
{
    $path = AKH_ROOT . '/' . ltrim($relativePath, '/');

    return is_file($path) ? (string) filemtime($path) : '1';
}

function akh_theme_mode_head(): void
{
    ?>
  <script>
    (function () {
      try {
        var t = localStorage.getItem('akh-theme');
        if (t === 'dark' || t === 'light') {
          document.documentElement.setAttribute('data-theme', t);
        }
      } catch (e) {}
    })();
  </script>
    <?php
    $ver = akh_theme_mode_asset_version('assets/css/theme-dark.css');
    $href = base_path('assets/css/theme-dark.css') . '?v=' . rawurlencode($ver);
    ?>
  <link rel="stylesheet" href="<?php echo h($href); ?>" />
    <?php
}

function akh_theme_mode_toggle(string $extraClass = ''): void
{
    $class = trim('akh-theme-toggle ' . $extraClass);
    ?>
  <button
    type="button"
    class="<?php echo h($class); ?>"
    data-akh-theme-toggle
    aria-label="Switch to dark mode"
    title="Toggle light / dark mode"
  >
    <span class="akh-theme-toggle__icon akh-theme-toggle__icon--sun" aria-hidden="true">☀</span>
    <span class="akh-theme-toggle__icon akh-theme-toggle__icon--moon" aria-hidden="true">☾</span>
    <span class="akh-theme-toggle__label" data-akh-theme-label>Dark</span>
  </button>
    <?php
}

function akh_theme_mode_footer_script(): void
{
    $ver = akh_theme_mode_asset_version('assets/js/theme-mode.js');
    $src = base_path('assets/js/theme-mode.js') . '?v=' . rawurlencode($ver);
    ?>
  <script src="<?php echo h($src); ?>" defer></script>
    <?php
}
