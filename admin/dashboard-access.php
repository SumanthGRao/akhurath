<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once AKH_ROOT . '/includes/admin-auth.php';
require_once AKH_ROOT . '/includes/dashboard-credentials.php';
require_once AKH_ROOT . '/includes/hr-auth.php';
require_once AKH_ROOT . '/includes/csrf.php';

akh_require_admin();

$pageTitle = 'Admin — Access — ' . SITE_NAME;
$bodyClass = 'page-portal admin-page admin-page--board';
$adminNavActive = 'dashboard-access.php';
$adminConsoleActive = '';

$flash = '';
$error = '';
$wa = akh_dashboard_credentials_wa();
$hrAccounts = akh_hr_accounts();
ksort($hrAccounts, SORT_STRING);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!akh_csrf_verify($_POST['csrf_token'] ?? null)) {
        $error = 'Security check failed. Refresh and try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'save_wa') {
            $err = akh_dashboard_credentials_set_wa(
                trim((string) ($_POST['wa_username'] ?? '')),
                (string) ($_POST['wa_password'] ?? ''),
                (string) ($_POST['wa_password_confirm'] ?? '')
            );
            if ($err !== null) {
                $error = $err;
            } else {
                $flash = 'WhatsApp dashboard credentials updated.';
                $wa = akh_dashboard_credentials_wa();
            }
        } elseif ($action === 'add_hr') {
            $err = akh_hr_add(
                trim((string) ($_POST['new_username'] ?? '')),
                (string) ($_POST['new_password'] ?? ''),
                (string) ($_POST['new_password_confirm'] ?? '')
            );
            if ($err !== null) {
                $error = $err;
            } else {
                $flash = 'HR account created.';
                $hrAccounts = akh_hr_accounts();
                ksort($hrAccounts, SORT_STRING);
            }
        } elseif ($action === 'delete_hr') {
            $u = strtolower(trim((string) ($_POST['username'] ?? '')));
            if ($u === '') {
                $error = 'Missing username.';
            } elseif (!akh_hr_delete($u)) {
                $error = 'Could not delete that HR account.';
            } else {
                $flash = 'HR account removed.';
                $hrAccounts = akh_hr_accounts();
                ksort($hrAccounts, SORT_STRING);
            }
        } else {
            $error = 'Unknown action.';
        }
    }
}

require_once AKH_ROOT . '/includes/header.php';
?>

  <main id="main" class="portal-main portal-main--board">
    <div class="portal-card portal-card--tasks admin-shell admin-console">
      <header class="admin-head">
        <div>
          <h1 class="portal-title">Access</h1>
          <p class="portal-lead admin-head__meta">Manage logins for the <a class="text-link" href="<?php echo h(base_path('whatsapp/login.php')); ?>">WhatsApp task board</a> and <a class="text-link" href="<?php echo h(base_path('hr/login.php')); ?>">HR attendance dashboard</a>.</p>
        </div>
        <div class="admin-head__actions">
          <?php require __DIR__ . '/includes/admin-console-sidebar.php'; ?>
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

      <section class="portal-section" aria-labelledby="wa-access-h">
        <h2 id="wa-access-h" class="portal-section__title">WhatsApp dashboard</h2>
        <?php if ($wa['user'] !== ''): ?>
          <p class="portal-muted">Current username: <strong><?php echo h($wa['user']); ?></strong></p>
        <?php else: ?>
          <p class="portal-muted">No stored credentials yet — falls back to <code>AKH_WA_DASHBOARD_*</code> in config if set.</p>
        <?php endif; ?>
        <form method="post" action="" class="portal-form">
          <input type="hidden" name="csrf_token" value="<?php echo h(akh_csrf_token()); ?>" />
          <input type="hidden" name="action" value="save_wa" />
          <div class="admin-form-row">
            <label class="field">
              <span>Username</span>
              <input type="text" name="wa_username" required maxlength="32" pattern="[a-z][a-z0-9_]{2,31}" value="<?php echo h($wa['user']); ?>" autocomplete="off" />
            </label>
            <label class="field">
              <span>New password</span>
              <input type="password" name="wa_password" required minlength="8" maxlength="128" autocomplete="new-password" />
            </label>
            <label class="field">
              <span>Confirm password</span>
              <input type="password" name="wa_password_confirm" required minlength="8" maxlength="128" autocomplete="new-password" />
            </label>
            <button type="submit" class="btn btn--primary">Save WhatsApp login</button>
          </div>
        </form>
        <p class="portal-note">Saved to <code>data/dashboard-credentials.json</code> on the server.</p>
      </section>

      <section class="portal-section" id="hr" aria-labelledby="hr-access-h">
        <h2 id="hr-access-h" class="portal-section__title">HR dashboard</h2>
        <?php if (!akh_hr_enabled()): ?>
          <p class="banner banner--info" role="status">Set <code>AKH_HR_DASHBOARD_ENABLED</code> to <code>true</code> in <code>includes/config.php</code> to enable the HR portal.</p>
        <?php endif; ?>

        <h3 class="portal-section__sub" style="margin:0 0 0.75rem;font-size:1rem;font-weight:600">Add HR user</h3>
        <form method="post" action="#hr" class="portal-form">
          <input type="hidden" name="csrf_token" value="<?php echo h(akh_csrf_token()); ?>" />
          <input type="hidden" name="action" value="add_hr" />
          <div class="admin-form-row">
            <label class="field">
              <span>Username</span>
              <input type="text" name="new_username" required maxlength="32" pattern="[a-z][a-z0-9_]{2,31}" autocomplete="off" />
            </label>
            <label class="field">
              <span>Password</span>
              <input type="password" name="new_password" required minlength="8" maxlength="128" autocomplete="new-password" />
            </label>
            <label class="field">
              <span>Confirm</span>
              <input type="password" name="new_password_confirm" required minlength="8" maxlength="128" autocomplete="new-password" />
            </label>
            <button type="submit" class="btn btn--primary">Create HR user</button>
          </div>
        </form>

        <h3 class="portal-section__sub" style="margin:1.25rem 0 0.75rem;font-size:1rem;font-weight:600">HR users (<?php echo count($hrAccounts); ?>)</h3>
        <?php if ($hrAccounts === []): ?>
          <p class="portal-muted">No HR accounts yet.</p>
        <?php else: ?>
          <div class="admin-table-wrap">
            <table class="admin-table">
              <thead>
                <tr>
                  <th scope="col">Username</th>
                  <th scope="col">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($hrAccounts as $uname => $_hash): ?>
                  <tr>
                    <td class="admin-table__mono"><?php echo h((string) $uname); ?></td>
                    <td>
                      <form method="post" action="#hr" class="admin-inline-form" onsubmit="return confirm('Remove HR account <?php echo h((string) $uname); ?>?');">
                        <input type="hidden" name="csrf_token" value="<?php echo h(akh_csrf_token()); ?>" />
                        <input type="hidden" name="action" value="delete_hr" />
                        <input type="hidden" name="username" value="<?php echo h((string) $uname); ?>" />
                        <button type="submit" class="btn btn--ghost btn--sm">Remove</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
        <p class="portal-note">HR accounts are stored in <code>data/hr-users.php</code>. Sign-in URL: <a class="text-link" href="<?php echo h(base_path('hr/login.php')); ?>">hr/login.php</a></p>
      </section>
    </div>
  </main>

<?php require_once AKH_ROOT . '/includes/footer.php'; ?>
