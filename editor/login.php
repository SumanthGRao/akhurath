<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once AKH_ROOT . '/includes/editor-auth.php';
require_once AKH_ROOT . '/includes/csrf.php';

$pageTitle = 'Editor login — ' . SITE_NAME;
$metaDescription = 'Staff task board for ' . SITE_NAME . '.';
$bodyClass = 'page-portal';

$error = '';

if (akh_editor_current() !== null) {
    header('Location: ' . base_path('editor/dashboard.php'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!akh_csrf_verify($_POST['csrf_token'] ?? null)) {
        $error = 'Security check failed. Refresh the page and try again.';
    } else {
        $user = trim((string) ($_POST['username'] ?? ''));
        $pass = (string) ($_POST['password'] ?? '');

        if ($user === '' || $pass === '') {
            $error = 'Enter username and password.';
        } elseif (akh_editor_accounts() === [] && !AKH_DEV_TEST_LOGIN) {
            $error = 'No editor accounts are configured.';
        } elseif (!akh_editor_login($user, $pass)) {
            $error = 'Invalid username or password.';
        } else {
            header('Location: ' . base_path('editor/dashboard.php'));
            exit;
        }
    }
}

require_once AKH_ROOT . '/includes/header.php';
?>

  <main id="main" class="portal-main">
    <div class="portal-card">
      <h1 class="portal-title">Editor login</h1>
      <p class="portal-lead">Assign incoming client tasks to yourself and update status. This is separate from the client portal.</p>

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
