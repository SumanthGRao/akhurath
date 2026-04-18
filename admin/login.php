<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once AKH_ROOT . '/includes/admin-auth.php';

$pageTitle = 'Admin login — ' . SITE_NAME;
$metaDescription = 'Studio admin console';
$bodyClass = 'page-portal';

$error = '';

if (akh_admin_current() !== null) {
    header('Location: ' . base_path('admin/index.php'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = trim((string) ($_POST['username'] ?? ''));
    $pass = (string) ($_POST['password'] ?? '');
    if ($user === '' || $pass === '') {
        $error = 'Enter username and password.';
    } elseif (akh_admin_accounts() === []) {
        $error = 'No admin accounts are configured.';
    } elseif (!akh_admin_login($user, $pass)) {
        $error = 'Invalid credentials.';
    } else {
        header('Location: ' . base_path('admin/index.php'));
        exit;
    }
}

require_once AKH_ROOT . '/includes/header.php';
?>

  <main id="main" class="portal-main">
    <div class="portal-card">
      <h1 class="portal-title">Admin console</h1>
      <p class="portal-lead">Sign in to manage clients, editors, and tasks.</p>
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
      <p class="portal-foot"><a class="text-link" href="<?php echo h(base_path('index.php')); ?>">← Website home</a></p>
    </div>
  </main>

<?php require_once AKH_ROOT . '/includes/footer.php'; ?>
