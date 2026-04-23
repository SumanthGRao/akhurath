<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once AKH_ROOT . '/includes/auth.php';
require_once AKH_ROOT . '/includes/csrf.php';

$pageTitle = 'Create client account — ' . SITE_NAME;
$metaDescription = 'Register to submit tasks and track status at ' . SITE_NAME . '.';
$bodyClass = 'page-portal';

$error = '';
$dbError = '';
try {
    akh_customer_accounts();
} catch (Throwable $e) {
    $dbError = 'Could not connect to the database. Start MySQL in XAMPP and set the correct database name and user in config/database.local.php. Detail: ' . trim((string) $e->getMessage());
}

if (!AKH_ALLOW_CLIENT_REGISTRATION) {
    http_response_code(403);
}

if (akh_customer_current() !== null) {
    header('Location: ' . base_path('customer/dashboard.php'));
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && AKH_ALLOW_CLIENT_REGISTRATION) {
    if ($dbError !== '') {
        // Shown via $dbError banner only.
    } else {
        $hp = trim((string) ($_POST['website'] ?? ''));
        if ($hp !== '') {
            $error = 'Spam detected.';
        } elseif (!akh_csrf_verify($_POST['csrf_token'] ?? null)) {
            $error = 'Security check failed. Refresh the page and try again.';
        } else {
            try {
                $user = trim((string) ($_POST['username'] ?? ''));
                $pass = (string) ($_POST['password'] ?? '');
                $pass2 = (string) ($_POST['password_confirm'] ?? '');
                $err = akh_customer_register($user, $pass, $pass2);
                if ($err !== null) {
                    $error = $err;
                } else {
                    header('Location: ' . base_path('customer/login.php?registered=1'));
                    exit;
                }
            } catch (Throwable $e) {
                $error = 'Registration failed (database error). Check MySQL and config/database.local.php.';
            }
        }
    }
}

require_once AKH_ROOT . '/includes/header.php';
?>

  <main id="main" class="portal-main">
    <div class="portal-card">
      <h1 class="portal-title">Create your account</h1>

      <?php if (!AKH_ALLOW_CLIENT_REGISTRATION): ?>
        <p class="banner banner--err" role="alert">New registrations are disabled. Email <a class="text-link" href="mailto:<?php echo h(CONTACT_EMAIL); ?>"><?php echo h(CONTACT_EMAIL); ?></a> to get access.</p>
        <p class="portal-foot"><a class="text-link" href="<?php echo h(base_path('customer/login.php')); ?>">← Client login</a></p>
      <?php else: ?>
        <p class="portal-lead">Choose a username and password. You’ll use these to log in, submit tasks, and track status. Usernames are lowercase letters, numbers, and underscores only.</p>

        <?php if ($dbError !== ''): ?>
          <p class="banner banner--err" role="alert"><?php echo h($dbError); ?></p>
        <?php elseif ($error !== ''): ?>
          <p class="banner banner--err" role="alert"><?php echo h($error); ?></p>
        <?php endif; ?>

        <?php if ($dbError === ''): ?>
        <form class="portal-form" method="post" action="" autocomplete="off">
          <input type="hidden" name="csrf_token" value="<?php echo h(akh_csrf_token()); ?>" />
          <label class="field honeypot" aria-hidden="true">
            <span>Leave blank</span>
            <input type="text" name="website" tabindex="-1" autocomplete="off" />
          </label>
          <label class="field">
            <span>Username</span>
            <input type="text" name="username" required autocomplete="username" maxlength="32" pattern="[a-zA-Z][a-zA-Z0-9_]{2,31}" title="Letter first, then letters, numbers, or underscores (3–32 chars)" />
          </label>
          <label class="field">
            <span>Password <span class="req">*</span></span>
            <input type="password" name="password" required autocomplete="new-password" minlength="8" maxlength="128" />
          </label>
          <label class="field">
            <span>Confirm password <span class="req">*</span></span>
            <input type="password" name="password_confirm" required autocomplete="new-password" minlength="8" maxlength="128" />
          </label>
          <button type="submit" class="btn btn--primary btn--block">Create account</button>
        </form>
        <?php endif; ?>

        <p class="portal-foot">
          <a class="text-link" href="<?php echo h(base_path('customer/login.php')); ?>">Already have an account? Sign in</a>
          ·
          <a class="text-link" href="<?php echo h(base_path('index.php')); ?>">Website home</a>
        </p>
      <?php endif; ?>
    </div>
  </main>

<?php require_once AKH_ROOT . '/includes/footer.php'; ?>
