<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once AKH_ROOT . '/includes/admin-auth.php';
require_once AKH_ROOT . '/includes/admin-meta.php';
require_once AKH_ROOT . '/includes/admin-notify-mail.php';
require_once AKH_ROOT . '/includes/csrf.php';

$pageTitle = 'Create first admin — ' . SITE_NAME;
$metaDescription = 'One-time setup for the studio admin console.';
$bodyClass = 'page-portal';

$dbError = '';
$adminList = [];
try {
    $adminList = akh_admin_accounts();
} catch (Throwable $e) {
    $dbError = 'Could not connect to the database. Start MySQL (XAMPP) and verify config/database.local.php matches your phpMyAdmin database. Detail: ' . trim((string) $e->getMessage());
}

if (akh_admin_current() !== null) {
    header('Location: ' . base_path('admin/index.php'));
    exit;
}

if ($dbError === '' && $adminList !== []) {
    header('Location: ' . base_path('admin/login.php'));
    exit;
}

if (!AKH_ADMIN_SETUP_ENABLED) {
    http_response_code(403);
}

$error = '';
$success = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && AKH_ADMIN_SETUP_ENABLED) {
    if ($dbError !== '') {
        // Shown via $dbError banner only.
    } else {
    $hp = trim((string) ($_POST['website'] ?? ''));
    if ($hp !== '') {
        $error = 'Spam detected.';
    } elseif (!akh_csrf_verify($_POST['csrf_token'] ?? null)) {
        $error = 'Security check failed. Refresh and try again.';
    } else {
        $user = strtolower(trim((string) ($_POST['username'] ?? '')));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $pass = (string) ($_POST['password'] ?? '');
        $pass2 = (string) ($_POST['password_confirm'] ?? '');

        if ($user === '' || $pass === '' || $pass2 === '') {
            $error = 'Fill in all fields.';
        } elseif (!preg_match('/^[a-z][a-z0-9_]{2,31}$/', $user)) {
            $error = 'Username: letter first, then letters, numbers, or underscores (3–32 chars).';
        } elseif ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Enter a valid email address.';
        } elseif (strlen($pass) < 8) {
            $error = 'Password must be at least 8 characters.';
        } elseif ($pass !== $pass2) {
            $error = 'Passwords do not match.';
        } else {
            $hash = password_hash($pass, PASSWORD_DEFAULT);
            if ($hash === false) {
                $error = 'Could not hash password.';
            } elseif (!akh_admin_save_accounts([$user => $hash])) {
                $error = 'Could not save the admin account. Check database connection and permissions.';
            } elseif (!akh_admin_meta_save([
                'email' => $email,
                'email_verified' => !AKH_SMTP_ENABLED,
                'verify_token' => null,
                'verify_expires_at' => null,
            ])) {
                $error = 'Account was created but saving profile failed. Check database / app_kv.';
            } elseif (AKH_SMTP_ENABLED) {
                $token = akh_admin_meta_begin_verification($email);
                if ($token === null) {
                    $error = 'Could not start email verification.';
                } else {
                    $url = akh_absolute_url('admin/verify-admin-email.php?token=' . rawurlencode($token));
                    $sent = akh_admin_mail_verify($url);
                    if (!$sent['ok']) {
                        $success = 'Admin account created. Verification email could not be sent: ' . $sent['error'] . ' You can resend from Account after signing in.';
                    } else {
                        $success = 'Admin account created. Check your inbox for a verification link.';
                    }
                }
            } else {
                $success = 'Admin account created. Enable SMTP in config to require email verification on future updates.';
            }
            if ($error === '' && $success !== '') {
                header('Location: ' . base_path('admin/login.php?setup=1'));
                exit;
            }
        }
    }
    }
}

require_once AKH_ROOT . '/includes/header.php';
?>

  <main id="main" class="portal-main">
    <div class="portal-card">
      <h1 class="portal-title">Create first admin</h1>

      <?php if (!AKH_ADMIN_SETUP_ENABLED): ?>
        <p class="banner banner--err" role="alert">Web-based first-time setup is disabled in <code class="text-link">includes/config.php</code> (<code>AKH_ADMIN_SETUP_ENABLED</code>). Use <code>php scripts/seed-admin-console.php</code> instead.</p>
        <p class="portal-foot"><a class="text-link" href="<?php echo h(base_path('admin/login.php')); ?>">← Admin login</a></p>
      <?php else: ?>
        <p class="portal-lead">This page is only available while no admin accounts exist. After you create one, use <strong>Account</strong> in the console to change password or email.</p>

        <?php if ($dbError !== ''): ?>
          <p class="banner banner--err" role="alert"><?php echo h($dbError); ?></p>
        <?php elseif ($error !== ''): ?>
          <p class="banner banner--err" role="alert"><?php echo h($error); ?></p>
        <?php endif; ?>
        <?php if ($success !== ''): ?>
          <p class="banner banner--ok" role="status"><?php echo h($success); ?></p>
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
            <input type="text" name="username" required autocomplete="username" maxlength="32" pattern="[a-z][a-z0-9_]{2,31}" title="Letter first, then letters, numbers, or underscores (3–32 chars)" />
          </label>
          <label class="field">
            <span>Admin email</span>
            <input type="email" name="email" required autocomplete="email" maxlength="120" />
          </label>
          <label class="field">
            <span>Password</span>
            <input type="password" name="password" required autocomplete="new-password" minlength="8" maxlength="128" />
          </label>
          <label class="field">
            <span>Confirm password</span>
            <input type="password" name="password_confirm" required autocomplete="new-password" minlength="8" maxlength="128" />
          </label>
          <button type="submit" class="btn btn--primary btn--block">Create admin account</button>
        </form>
        <?php endif; ?>
        <p class="portal-foot"><a class="text-link" href="<?php echo h(base_path('admin/login.php')); ?>">← Admin login</a></p>
      <?php endif; ?>
    </div>
  </main>

<?php require_once AKH_ROOT . '/includes/footer.php'; ?>
