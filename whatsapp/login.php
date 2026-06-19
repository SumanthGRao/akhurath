<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once AKH_ROOT . '/includes/whatsapp-dashboard-auth.php';
require_once AKH_ROOT . '/includes/csrf.php';

$pageTitle = 'WhatsApp tasks login — ' . SITE_NAME;
$metaDescription = 'WhatsApp wedding task dashboard for ' . SITE_NAME . '.';
$bodyClass = 'page-portal page-wa-login';

$error = '';
$dbError = '';
$notConfigured = !akh_wa_dashboard_configured();
$disabled = !akh_wa_dashboard_enabled();

if (!$disabled && !$notConfigured && function_exists('akh_db')) {
    try {
        akh_db()->query('SELECT 1');
    } catch (Throwable $e) {
        $dbError = 'Could not connect to the database. Check config/database.local.php (host, database name, user, password).';
    }
}

if (akh_wa_dashboard_current() !== null) {
    header('Location: ' . base_path('whatsapp/index.php'));
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if ($disabled) {
        $error = 'This dashboard is disabled.';
    } elseif ($notConfigured) {
        $error = 'Dashboard login is not configured. Set AKH_WA_DASHBOARD_USER and AKH_WA_DASHBOARD_PASS_HASH in includes/config.php.';
    } elseif ($dbError !== '') {
        // Banner only.
    } elseif (!akh_csrf_verify($_POST['csrf_token'] ?? null)) {
        $error = 'Security check failed. Refresh and try again.';
    } else {
        $user = trim((string) ($_POST['username'] ?? ''));
        $pass = (string) ($_POST['password'] ?? '');
        if ($user === '' || $pass === '') {
            $error = 'Enter username and password.';
        } elseif (!akh_wa_dashboard_login($user, $pass)) {
            $error = 'Invalid credentials.';
        } else {
            header('Location: ' . base_path('whatsapp/index.php'));
            exit;
        }
    }
}

require_once AKH_ROOT . '/includes/header.php';
$waCss = base_path('assets/css/whatsapp-dashboard.css');
$waCssVer = is_file(AKH_ROOT . '/assets/css/whatsapp-dashboard.css') ? (string) filemtime(AKH_ROOT . '/assets/css/whatsapp-dashboard.css') : '';
?>
  <link rel="stylesheet" href="<?php echo h($waCss . ($waCssVer !== '' ? '?v=' . rawurlencode($waCssVer) : '')); ?>" />

  <main id="main" class="portal-main wa-login-main">
    <div class="wa-login-card">
      <div class="wa-login-card__brand">
        <span class="wa-login-card__icon" aria-hidden="true">💬</span>
        <h1 class="wa-login-card__title">WhatsApp tasks</h1>
        <p class="wa-login-card__lead">Wedding editing jobs from WhatsApp automation — sign in to view and update tasks.</p>
      </div>

      <?php if ($disabled): ?>
        <p class="banner banner--err" role="alert">Dashboard is disabled on this server.</p>
      <?php elseif ($notConfigured): ?>
        <p class="banner banner--err" role="alert">Login not configured. Copy includes/config.example.php settings into includes/config.php and set a password hash via <code>php scripts/hash-password.php</code>.</p>
      <?php elseif ($dbError !== ''): ?>
        <p class="banner banner--err" role="alert"><?php echo h($dbError); ?></p>
      <?php elseif ($error !== ''): ?>
        <p class="banner banner--err" role="alert"><?php echo h($error); ?></p>
      <?php endif; ?>

      <?php if (!$disabled && !$notConfigured): ?>
        <form class="portal-form wa-login-form" method="post" action="" autocomplete="username">
          <input type="hidden" name="csrf_token" value="<?php echo h(akh_csrf_token()); ?>" />
          <label class="field">
            <span>Username</span>
            <input type="text" name="username" required autocomplete="username" maxlength="120" />
          </label>
          <label class="field">
            <span>Password</span>
            <input type="password" name="password" required autocomplete="current-password" maxlength="500" />
          </label>
          <button type="submit" class="btn btn--primary btn--block wa-btn">Sign in</button>
        </form>
      <?php endif; ?>

      <p class="portal-foot"><a class="text-link" href="<?php echo h(base_path('index.php')); ?>">← Website home</a></p>
    </div>
  </main>

<?php require_once AKH_ROOT . '/includes/footer.php'; ?>
