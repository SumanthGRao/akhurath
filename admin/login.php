<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once AKH_ROOT . '/includes/admin-auth.php';

$pageTitle = 'Admin login — ' . SITE_NAME;
$metaDescription = 'Studio admin console';
$bodyClass = 'page-portal';

$error = '';
$setupOk = (string) ($_GET['setup'] ?? '') === '1';

$dbError = '';
$noAdmins = true;
$showFirstSetup = false;
$devTestLoginUi = false;
try {
    $noAdmins = akh_admin_accounts() === [];
    $showFirstSetup = AKH_ADMIN_SETUP_ENABLED && $noAdmins;
    $devTestLoginUi = akh_admin_dev_test_login_allowed();
} catch (Throwable $e) {
    $dbError = 'Could not connect to the database. In XAMPP, start MySQL, then check config/database.local.php (host, database name, user, password). Detail: ' . trim((string) $e->getMessage());
}

if (akh_admin_current() !== null) {
    header('Location: ' . base_path('admin/index.php'));
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $user = trim((string) ($_POST['username'] ?? ''));
    $pass = (string) ($_POST['password'] ?? '');
    if ($dbError !== '') {
        // Shown via $dbError banner only.
    } elseif ($user === '' || $pass === '') {
        $error = 'Enter username and password.';
    } else {
        try {
            if (!akh_admin_login($user, $pass)) {
                $error = (akh_admin_accounts() === [] && !akh_admin_dev_test_login_allowed())
                    ? 'No admin accounts are configured.'
                    : 'Invalid credentials.';
            } else {
                header('Location: ' . base_path('admin/index.php'));
                exit;
            }
        } catch (Throwable $e) {
            $error = 'Sign-in failed (database error). Confirm MySQL is running and config/database.local.php is correct.';
        }
    }
}

require_once AKH_ROOT . '/includes/header.php';
?>

  <main id="main" class="portal-main">
    <div class="portal-card">
      <h1 class="portal-title">Admin console</h1>
      <p class="portal-lead">Sign in to manage clients, editors, and tasks.</p>
      <?php if ($dbError !== ''): ?>
        <p class="banner banner--err" role="alert"><?php echo h($dbError); ?></p>
      <?php endif; ?>
      <?php if ($setupOk): ?>
        <p class="banner banner--ok" role="status">First admin account was created. Sign in below. You can change password and email under <strong>Account</strong> after login.</p>
      <?php endif; ?>
      <?php if ($showFirstSetup && $dbError === ''): ?>
        <p class="banner banner--info" role="status">No admin exists yet. <a class="text-link" href="<?php echo h(base_path('admin/setup.php')); ?>">Create the first admin account</a> (or run <code>php scripts/seed-admin-console.php</code> on the server).</p>
      <?php endif; ?>
      <?php if ($error !== ''): ?>
        <p class="banner banner--err" role="alert"><?php echo h($error); ?></p>
      <?php endif; ?>
      <form class="portal-form" method="post" action="">
        <label class="field">
          <span>Username</span>
          <input type="text" name="username" required autocomplete="username" maxlength="120" />
        </label>
        <label class="field">
          <span>Password</span>
          <input type="password" name="password" required autocomplete="current-password" maxlength="200" />
        </label>
        <button type="submit" class="btn btn--primary btn--block">Sign in</button>
      </form>
      <p class="portal-foot">
        <?php if ($devTestLoginUi && $dbError === ''): ?>
          <a class="text-link" href="<?php echo h(base_path('admin/test-login.php')); ?>">Test login (UI, test / test)</a>
          ·
        <?php endif; ?>
        <a class="text-link" href="<?php echo h(base_path('index.php')); ?>">← Website home</a>
      </p>
    </div>
  </main>

<?php require_once AKH_ROOT . '/includes/footer.php'; ?>
