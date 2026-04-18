<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once AKH_ROOT . '/includes/admin-auth.php';
require_once AKH_ROOT . '/includes/admin-meta.php';
require_once AKH_ROOT . '/includes/admin-notify-mail.php';
require_once AKH_ROOT . '/includes/csrf.php';

akh_require_admin();

$pageTitle = 'Account & security — ' . SITE_NAME;
$bodyClass = 'page-portal admin-page admin-page--board';
$adminNavActive = 'account.php';

$error = '';
$flash = '';
$meta = akh_admin_meta();
$user = akh_admin_current() ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!akh_csrf_verify($_POST['csrf_token'] ?? null)) {
        $error = 'Security check failed. Refresh and try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'password') {
            $cur = (string) ($_POST['current_password'] ?? '');
            $new = (string) ($_POST['new_password'] ?? '');
            $new2 = (string) ($_POST['new_password_confirm'] ?? '');
            if ($cur === '' || $new === '' || $new2 === '') {
                $error = 'Fill in all password fields.';
            } elseif (strlen($new) < 8) {
                $error = 'New password must be at least 8 characters.';
            } elseif ($new !== $new2) {
                $error = 'New passwords do not match.';
            } else {
                $hashCur = akh_admin_accounts()[$user] ?? '';
                $hashCur = is_string($hashCur) ? $hashCur : '';
                if ($hashCur === '' || !password_verify($cur, $hashCur)) {
                    $error = 'Current password is incorrect.';
                } else {
                    $hash = password_hash($new, PASSWORD_DEFAULT);
                    if ($hash === false) {
                        $error = 'Could not hash new password.';
                    } elseif (!akh_admin_update_password_hash($user, $hash)) {
                        $error = 'Could not update password.';
                    } else {
                        $flash = 'Password updated.';
                        if (AKH_SMTP_ENABLED) {
                            $m = akh_admin_mail_password_changed($user);
                            if (!$m['ok'] && $m['error'] !== 'No email on file.') {
                                $flash .= ' Notification email could not be sent: ' . $m['error'];
                            }
                        }
                    }
                }
            }
        } elseif ($action === 'email') {
            $email = strtolower(trim((string) ($_POST['email'] ?? '')));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Enter a valid email address.';
            } elseif ($email === strtolower(trim((string) ($meta['email'] ?? ''))) && ($meta['email_verified'] ?? false)) {
                $flash = 'That address is already saved and verified.';
            } else {
                $token = akh_admin_meta_begin_verification($email);
                if ($token === null) {
                    $error = 'Could not save email or start verification.';
                } elseif (AKH_SMTP_ENABLED) {
                    $url = akh_absolute_url('admin/verify-admin-email.php?token=' . rawurlencode($token));
                    $sent = akh_admin_mail_verify($url);
                    if (!$sent['ok']) {
                        $error = 'Email saved but verification message could not be sent: ' . $sent['error'];
                    } else {
                        $flash = 'Check your inbox to verify this address.';
                    }
                } else {
                    if (!akh_admin_meta_save(['email' => $email, 'email_verified' => true, 'verify_token' => null, 'verify_expires_at' => null])) {
                        $error = 'Could not save email.';
                    } else {
                        $flash = 'Email updated. Enable SMTP in config to require verification links for future changes.';
                    }
                }
            }
        } elseif ($action === 'resend') {
            $em = trim((string) ($meta['email'] ?? ''));
            if ($em === '' || !filter_var($em, FILTER_VALIDATE_EMAIL)) {
                $error = 'Set an email address first.';
            } elseif ($meta['email_verified'] ?? false) {
                $error = 'This address is already verified.';
            } elseif (!AKH_SMTP_ENABLED) {
                $error = 'SMTP is disabled; enable it in config to send verification mail.';
            } else {
                $token = akh_admin_meta_begin_verification($em);
                if ($token === null) {
                    $error = 'Could not create a new verification link.';
                } else {
                    $url = akh_absolute_url('admin/verify-admin-email.php?token=' . rawurlencode($token));
                    $sent = akh_admin_mail_verify($url);
                    if (!$sent['ok']) {
                        $error = $sent['error'];
                    } else {
                        $flash = 'Verification email sent again.';
                    }
                }
            }
        }
    }
    $meta = akh_admin_meta();
}

require_once AKH_ROOT . '/includes/header.php';
?>

  <main id="main" class="portal-main portal-main--board">
    <div class="portal-card portal-card--tasks admin-shell">
      <header class="admin-head">
        <div>
          <h1 class="portal-title">Account & security</h1>
          <p class="portal-lead admin-head__meta">Signed in as <strong><?php echo h($user); ?></strong></p>
        </div>
        <div class="admin-head__actions">
          <?php $adminConsoleActive = ''; require __DIR__ . '/includes/admin-console-sidebar.php'; ?>
          <a class="btn btn--ghost btn--sm" href="<?php echo h(base_path('admin/logout.php')); ?>">Sign out</a>
        </div>
      </header>

      <?php require AKH_ROOT . '/includes/admin-nav.php'; ?>

      <?php if ($flash !== ''): ?>
        <p class="banner banner--ok" role="status"><?php echo h($flash); ?></p>
      <?php endif; ?>
      <?php if ($error !== ''): ?>
        <p class="banner banner--err" role="alert"><?php echo h($error); ?></p>
      <?php endif; ?>

      <section class="portal-section" style="margin-top:1rem" aria-labelledby="admin-email-h">
        <h2 id="admin-email-h" class="portal-section__title">Notification email</h2>
        <p class="portal-lead" style="margin-bottom:0.75rem">Used for verification and password-change notices when SMTP is enabled.</p>
        <p><strong>Current:</strong> <?php echo h($meta['email'] !== '' ? $meta['email'] : '(none)'); ?>
          <?php if (($meta['email'] ?? '') !== ''): ?>
            — <?php echo ($meta['email_verified'] ?? false) ? 'verified' : 'not verified'; ?>
          <?php endif; ?>
        </p>
        <form class="portal-form" method="post" action="" style="max-width:28rem">
          <input type="hidden" name="csrf_token" value="<?php echo h(akh_csrf_token()); ?>" />
          <input type="hidden" name="action" value="email" />
          <label class="field">
            <span>New email</span>
            <input type="email" name="email" required value="<?php echo h($meta['email']); ?>" autocomplete="email" maxlength="120" />
          </label>
          <button type="submit" class="btn btn--primary">Save &amp; verify</button>
        </form>
        <?php if (($meta['email'] ?? '') !== '' && !($meta['email_verified'] ?? false) && AKH_SMTP_ENABLED): ?>
          <form class="portal-form" method="post" action="" style="max-width:28rem;margin-top:0.75rem">
            <input type="hidden" name="csrf_token" value="<?php echo h(akh_csrf_token()); ?>" />
            <input type="hidden" name="action" value="resend" />
            <button type="submit" class="btn btn--ghost">Resend verification email</button>
          </form>
        <?php endif; ?>
      </section>

      <section class="portal-section" style="margin-top:1.5rem" aria-labelledby="admin-pass-h">
        <h2 id="admin-pass-h" class="portal-section__title">Change password</h2>
        <form class="portal-form" method="post" action="" style="max-width:28rem">
          <input type="hidden" name="csrf_token" value="<?php echo h(akh_csrf_token()); ?>" />
          <input type="hidden" name="action" value="password" />
          <label class="field">
            <span>Current password</span>
            <input type="password" name="current_password" required autocomplete="current-password" maxlength="200" />
          </label>
          <label class="field">
            <span>New password</span>
            <input type="password" name="new_password" required autocomplete="new-password" minlength="8" maxlength="128" />
          </label>
          <label class="field">
            <span>Confirm new password</span>
            <input type="password" name="new_password_confirm" required autocomplete="new-password" minlength="8" maxlength="128" />
          </label>
          <button type="submit" class="btn btn--primary">Update password</button>
        </form>
      </section>

      <p class="portal-foot" style="margin-top:1.5rem"><a class="text-link" href="<?php echo h(base_path('admin/index.php')); ?>">← Overview</a></p>
    </div>
  </main>

<?php require_once AKH_ROOT . '/includes/footer.php'; ?>
