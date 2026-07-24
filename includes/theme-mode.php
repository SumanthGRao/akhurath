<?php

declare(strict_types=1);

/**
 * Light / dark theme toggle for portal dashboards only (not the public marketing site).
 * Preference is stored in localStorage (akh-theme: light | dark).
 */

function akh_theme_mode_asset_version(string $relativePath): string
{
    $path = AKH_ROOT . '/' . ltrim($relativePath, '/');

    return is_file($path) ? (string) filemtime($path) : '1';
}

/** Dashboard surfaces that may use the theme toggle. */
function akh_theme_mode_enabled(string $bodyClass): bool
{
    if (preg_match('/\bpage-(home|contact|legal)\b/', $bodyClass) === 1) {
        return false;
    }

    return preg_match(
        '/\b(page-editor-desk|page-wa-dashboard|admin-page--board|page-portal--board)\b/',
        $bodyClass
    ) === 1;
}

function akh_theme_mode_body_class(string $bodyClass): string
{
    if (!akh_theme_mode_enabled($bodyClass)) {
        return $bodyClass;
    }

    return trim($bodyClass . ' akh-theme-enabled');
}

function akh_theme_mode_head(?string $bodyClass = null): void
{
    if ($bodyClass === null) {
        global $bodyClass;
        $bodyClass = is_string($bodyClass ?? null) ? $bodyClass : '';
    }
    if (!akh_theme_mode_enabled($bodyClass)) {
        return;
    }
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

function akh_theme_mode_toggle(string $extraClass = '', ?string $bodyClass = null): void
{
    if ($bodyClass === null) {
        global $bodyClass;
        $bodyClass = is_string($bodyClass ?? null) ? $bodyClass : '';
    }
    if (!akh_theme_mode_enabled($bodyClass)) {
        return;
    }

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

function akh_theme_mode_footer_script(?string $bodyClass = null): void
{
    if ($bodyClass === null) {
        global $bodyClass;
        $bodyClass = is_string($bodyClass ?? null) ? $bodyClass : '';
    }
    if (!akh_theme_mode_enabled($bodyClass)) {
        return;
    }

    $ver = akh_theme_mode_asset_version('assets/js/theme-mode.js');
    $src = base_path('assets/js/theme-mode.js') . '?v=' . rawurlencode($ver);
    ?>
  <script src="<?php echo h($src); ?>" defer></script>
    <?php
}
