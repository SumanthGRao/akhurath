<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once AKH_ROOT . '/includes/hr-auth.php';
require_once AKH_ROOT . '/includes/csrf.php';

$pageTitle = 'HR login — ' . SITE_NAME;
$bodyClass = 'page-portal';

$error = '';

if (!akh_hr_enabled()) {
    $error = 'HR dashboard is disabled in config.';
} elseif (akh_hr_current() !== null) {
    header('Location: ' . base_path('hr/index.php'));
    exit;
} elseif (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!akh_csrf_verify($_POST['csrf_token'] ?? null)) {
        $error = 'Security check failed. Refresh and try again.';
    } else {
        $user = trim((string) ($_POST['username'] ?? ''));
        $pass = (string) ($_POST['password'] ?? '');
        if ($user === '' || $pass === '') {
            $error = 'Enter username and password.';
        } elseif (akh_hr_accounts() === []) {
            $error = 'No HR accounts yet. Ask an admin to create one.';
        } elseif (!akh_hr_login($user, $pass)) {
            $error = 'Invalid username or password.';
        } else {
            header('Location: ' . base_path('hr/index.php'));
            exit;
        }
    }
}

require_once AKH_ROOT . '/includes/header.php';
?>

  <main id="main" class="portal-main">
    <div class="portal-card">
      <h1 class="portal-title">HR login</h1>
      <p class="portal-lead">Monitor editor attendance — clock-ins, hours, and monthly summaries.</p>

      <?php if ($error !== ''): ?>
        <p class="banner banner--err" role="alert"><?php echo h($error); ?></p>
      <?php endif; ?>

      <form class="portal-form" method="post" action="" autocomplete="username">
        <input type="hidden" name="csrf_token" value="<?php echo h(akh_csrf_token()); ?>" />
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

<?php require_once AKH_ROOT . '/includes/footer.php'; ?>
