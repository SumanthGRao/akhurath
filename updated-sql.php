<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once AKH_ROOT . '/includes/admin-auth.php';
require_once AKH_ROOT . '/includes/csrf.php';
require_once AKH_ROOT . '/includes/ensure-database-patches.php';

akh_require_admin();

if (!akh_db_schema_web_enabled()) {
    http_response_code(403);
    $pageTitle = 'Database updates disabled — ' . SITE_NAME;
    $bodyClass = 'page-portal admin-page';
    require_once AKH_ROOT . '/includes/header.php';
    echo '<main id="main" class="portal-main"><div class="portal-card"><p class="banner banner--err">Web database updates are disabled. Set <code>AKH_DB_SCHEMA_WEB_ENABLED</code> to <code>true</code> in includes/config.php or use SSH <code>php scripts/ensure-database.php</code>.</p></div></main>';
    require_once AKH_ROOT . '/includes/footer.php';
    exit;
}

$pageTitle = 'Database updates — ' . SITE_NAME;
$bodyClass = 'page-portal admin-page admin-page--board';
$adminNavActive = '';

$dbReady = function_exists('akh_db_is_pdo') && akh_db_is_pdo();
$checklist = [];
$dbName = '';
$dbError = '';

if ($dbReady) {
    try {
        $pdo = akh_db();
        $dbName = (string) (akh_ensure_db_current_schema($pdo) ?? '');
        $checklist = akh_ensure_database_schema_checklist($pdo);
    } catch (Throwable $e) {
        $dbError = trim($e->getMessage());
        $dbReady = false;
    }
} else {
    $dbError = 'MySQL is not configured. Add config/database.local.php and set AKH_DB_DSN in includes/config.php.';
}

$runLog = [];
$runError = '';
$ran = false;

if ($dbReady && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!akh_csrf_verify($_POST['csrf_token'] ?? null)) {
        $runError = 'Security check failed. Refresh and try again.';
    } else {
        $action = trim((string) ($_POST['action'] ?? ''));
        if ($action === 'run_patches') {
            $ran = true;
            $result = akh_ensure_database_apply_patches(akh_db(), [
                'migrate_customers' => isset($_POST['migrate_customers']),
                'migrate_editors' => isset($_POST['migrate_editors']),
            ]);
            $runLog = $result['lines'];
            $runError = $result['error'] ?? '';
            if ($result['ok']) {
                $checklist = akh_ensure_database_schema_checklist(akh_db());
            }
        }
    }
}

require_once AKH_ROOT . '/includes/header.php';
?>

  <main id="main" class="portal-main portal-main--board">
    <div class="portal-card portal-card--tasks admin-shell admin-updated-sql">
      <header class="admin-head">
        <div>
          <h1 class="portal-title">Database schema updates</h1>
          <p class="portal-lead admin-head__meta">
            Runs the same idempotent SQL patches as <code>scripts/ensure-database.php</code> — safe to run after each deploy.
            <?php if ($dbName !== ''): ?>
              Connected to <strong><?php echo h($dbName); ?></strong>.
            <?php endif; ?>
          </p>
        </div>
        <div class="admin-head__actions">
          <a class="btn btn--ghost btn--sm" href="<?php echo h(base_path('admin/index.php')); ?>">Admin home</a>
          <a class="btn btn--ghost btn--sm" href="<?php echo h(base_path('admin/logout.php')); ?>">Sign out</a>
        </div>
      </header>

      <?php if ($dbError !== ''): ?>
        <p class="banner banner--err" role="alert"><?php echo h($dbError); ?></p>
      <?php endif; ?>

      <?php if ($dbReady && $checklist !== []): ?>
        <section class="admin-updated-sql__check" aria-labelledby="sql-check-h">
          <h2 id="sql-check-h" class="admin-panel__title">Table checklist</h2>
          <ul class="admin-updated-sql__checklist">
            <?php foreach ($checklist as $row): ?>
              <li class="admin-updated-sql__check-item<?php echo ($row['ok'] ?? false) ? ' is-ok' : ' is-missing'; ?>">
                <span class="admin-updated-sql__check-name"><?php echo h((string) ($row['table'] ?? '')); ?></span>
                <span class="admin-updated-sql__check-state"><?php echo ($row['ok'] ?? false) ? 'Present' : 'Missing — run updates below'; ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        </section>
      <?php endif; ?>

      <?php if ($dbReady): ?>
        <section class="admin-updated-sql__run" aria-labelledby="sql-run-h">
          <h2 id="sql-run-h" class="admin-panel__title">Run updates</h2>
          <p class="portal-muted">Only applies changes that are not already on the database. Does not delete data.</p>

          <?php if ($runError !== ''): ?>
            <p class="banner banner--err" role="alert"><?php echo h($runError); ?></p>
          <?php elseif ($ran): ?>
            <p class="banner banner--ok" role="status">Finished. Review the log below.</p>
          <?php endif; ?>

          <form method="post" action="" class="admin-updated-sql__form">
            <input type="hidden" name="csrf_token" value="<?php echo h(akh_csrf_token()); ?>" />
            <input type="hidden" name="action" value="run_patches" />
            <fieldset class="admin-updated-sql__options">
              <legend class="visually-hidden">Optional imports</legend>
              <label class="admin-updated-sql__option">
                <input type="checkbox" name="migrate_editors" value="1" />
                Import editors from <code>data/editors.php</code> (legacy file hosts only)
              </label>
              <label class="admin-updated-sql__option">
                <input type="checkbox" name="migrate_customers" value="1" />
                Import customers from <code>data/customers.php</code> (legacy file hosts only)
              </label>
            </fieldset>
            <button type="submit" class="btn btn--primary">Run schema updates</button>
          </form>

          <?php if ($runLog !== []): ?>
            <pre class="admin-updated-sql__log" aria-label="Update log"><?php echo h(implode("\n", $runLog)); ?></pre>
          <?php endif; ?>
        </section>
      <?php endif; ?>
    </div>
  </main>
<?php require_once AKH_ROOT . '/includes/footer.php'; ?>
