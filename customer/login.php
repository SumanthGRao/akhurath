<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once AKH_ROOT . '/includes/auth.php';

$pageTitle = 'Client login — ' . SITE_NAME;
$metaDescription = 'Customer portal for ' . SITE_NAME . '.';
$bodyClass = 'page-portal';

$error = '';

$dbError = '';
try {
    akh_customer_accounts();
} catch (Throwable $e) {
    $dbError = 'Could not connect to the database. Start MySQL in XAMPP and set the correct database name and user in config/database.local.php. Detail: ' . trim((string) $e->getMessage());
}

if (akh_customer_current() !== null) {
    header('Location: ' . base_path('customer/dashboard.php'));
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
            if (akh_customer_accounts() === [] && !AKH_DEV_TEST_LOGIN) {
                $error = 'No customer accounts are configured.';
            } elseif (!akh_customer_login($user, $pass)) {
                $error = 'Invalid username or password.';
            } else {
                header('Location: ' . base_path('customer/dashboard.php'));
                exit;
            }
        } catch (Throwable $e) {
            $error = 'Sign-in failed (database error). Check MySQL and config/database.local.php.';
        }
    }
}

require_once AKH_ROOT . '/includes/header.php';
?>

  <main id="main" class="portal-main">
    <div class="portal-card">
      <h1 class="portal-title">Client login</h1>
      <p class="portal-lead">Sign in to submit tasks (Drive link or NAS upload), track status, and open your deliverables portal at <strong><?php echo h(parse_url(DRIVE_PORTAL_URL, PHP_URL_HOST) ?: DRIVE_PORTAL_URL); ?></strong> — use the drive credentials we gave you when you land there.</p>

      <?php if ($dbError !== ''): ?>
        <p class="banner banner--err" role="alert"><?php echo h($dbError); ?></p>
      <?php endif; ?>
      <?php if ($error !== '' && $dbError === ''): ?>
        <p class="banner banner--err" role="alert"><?php echo h($error); ?></p>
      <?php endif; ?>

      <form class="portal-form" method="post" action="" autocomplete="username">
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

      <p class="portal-foot">
        <?php if (AKH_ALLOW_CLIENT_REGISTRATION): ?>
          <a class="text-link" href="<?php echo h(base_path('customer/register.php')); ?>">Create an account</a>
          ·
        <?php endif; ?>
        <a class="text-link" href="<?php echo h(base_path('index.php')); ?>">← Back to website</a>
      </p>
    </div>
  </main>

<?php require_once AKH_ROOT . '/includes/footer.php'; ?>
