<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once AKH_ROOT . '/includes/editor-auth.php';
require_once AKH_ROOT . '/includes/editor-device.php';
require_once AKH_ROOT . '/includes/csrf.php';

$pageTitle = 'Editor login — ' . SITE_NAME;
$metaDescription = 'Staff task board for ' . SITE_NAME . '.';
$bodyClass = 'page-portal';

$error = '';
$dbError = '';
try {
    akh_editor_accounts();
} catch (Throwable $e) {
    $dbError = 'Could not connect to the database. Start MySQL in XAMPP and set the correct database name and user in config/database.local.php. Detail: ' . trim((string) $e->getMessage());
}

if (akh_editor_current() !== null) {
    if (akh_editor_session_device_valid()) {
        header('Location: ' . base_path('editor/dashboard.php'));
        exit;
    }
    akh_editor_logout();
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if ($dbError !== '') {
        // Banner uses $dbError only (avoid duplicating the same message in $error).
    } elseif (!akh_csrf_verify($_POST['csrf_token'] ?? null)) {
        $error = 'Security check failed. Refresh the page and try again.';
    } else {
        $user = trim((string) ($_POST['username'] ?? ''));
        $pass = (string) ($_POST['password'] ?? '');
        $deviceId = akh_editor_device_from_request();

        if ($user === '' || $pass === '') {
            $error = 'Enter username and password.';
        } elseif (akh_editor_device_lock_enabled() && !akh_editor_device_is_allowed($deviceId)) {
            $error = 'This device is not approved yet. Copy the Device ID below into AKH_EDITOR_ALLOWED_DEVICES in includes/config.php on the server, then try again.';
        } else {
            try {
                if (akh_editor_accounts() === [] && !AKH_DEV_TEST_LOGIN) {
                    $error = 'No editor accounts are configured.';
                } elseif (!akh_editor_login($user, $pass, $deviceId)) {
                    $error = 'Invalid username or password.';
                } else {
                    header('Location: ' . base_path('editor/dashboard.php'));
                    exit;
                }
            } catch (Throwable $e) {
                $error = 'Sign-in failed (database error). Check MySQL and config/database.local.php.';
            }
        }
    }
}

$deviceLockOn = akh_editor_device_lock_enabled();
$allowedCount = count(akh_editor_allowed_device_list());

require_once AKH_ROOT . '/includes/header.php';
$deviceJs = AKH_ROOT . '/assets/js/editor-device-id.js';
$deviceJsVer = is_file($deviceJs) ? (string) filemtime($deviceJs) : '1';
?>

  <main id="main" class="portal-main">
    <div class="portal-card">
      <h1 class="portal-title">Editor login</h1>
      <p class="portal-lead">Assign incoming client tasks to yourself and update status. This is separate from the client portal.</p>

      <div class="editor-device-status" role="status" aria-label="Device registration">
        <p class="portal-note editor-device-status__line"><strong>Device ID</strong> (per computer/browser — not MAC address; websites cannot read MAC over the internet)</p>
        <p class="editor-device-status__id" id="editor-device-id-value">Loading…</p>
        <p class="portal-note editor-device-status__line">
          <button type="button" class="btn btn--ghost btn--sm" id="editor-device-id-copy">Copy ID</button>
          <?php if ($deviceLockOn): ?>
            <span class="editor-device-status__meta">Device lock: <strong>ON</strong> — <?php echo (int) $allowedCount; ?> approved device<?php echo $allowedCount === 1 ? '' : 's'; ?> in config.</span>
          <?php else: ?>
            <span class="editor-device-status__meta">Device lock: <strong>OFF</strong> — set <code>AKH_EDITOR_DEVICE_LOCK = true</code> in config when ready.</span>
          <?php endif; ?>
        </p>
        <p class="portal-muted editor-device-status__hint">On each of your 6 studio PCs, open this page once, copy the Device ID, and add all IDs to <code>AKH_EDITOR_ALLOWED_DEVICES</code> in <code>includes/config.php</code> (comma-separated).</p>
      </div>

      <?php if ($dbError !== ''): ?>
        <p class="banner banner--err" role="alert"><?php echo h($dbError); ?></p>
      <?php elseif ($error !== ''): ?>
        <p class="banner banner--err" role="alert"><?php echo h($error); ?></p>
      <?php endif; ?>

      <form class="portal-form" method="post" action="" autocomplete="username">
        <input type="hidden" name="csrf_token" value="<?php echo h(akh_csrf_token()); ?>" />
        <input type="hidden" name="device_id" id="editor-device-id-input" value="" />
        <label class="field">
          <span>Username</span>
          <input type="text" name="username" required autocomplete="username" maxlength="120" />
        </label>
        <label class="field">
          <span>Password</span>
          <input type="password" name="password" required autocomplete="current-password" maxlength="500" />
        </label>
        <button type="submit" class="btn btn--primary btn--block">Sign in</button>
      </form>

      <p class="portal-foot"><a class="text-link" href="<?php echo h(base_path('index.php')); ?>">← Website home</a></p>
    </div>
  </main>

  <script defer src="<?php echo h(base_path('assets/js/editor-device-id.js')); ?>?v=<?php echo h($deviceJsVer); ?>"></script>

<?php require_once AKH_ROOT . '/includes/footer.php'; ?>
