<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once AKH_ROOT . '/includes/admin-auth.php';
require_once AKH_ROOT . '/includes/editor-auth.php';
require_once AKH_ROOT . '/includes/editor-attendance-report.php';

akh_require_admin();

$editorKey = strtolower(trim((string) ($_GET['editor'] ?? '')));
if ($editorKey === '' || !isset(akh_editor_accounts()[$editorKey])) {
    header('Location: ' . base_path('admin/attendance.php'));
    exit;
}

$y = (int) ($_GET['year'] ?? date('Y'));
$m = (int) ($_GET['month'] ?? date('n'));
$report = akh_editor_attendance_month_report($y, $m);
$monthLabel = date('F Y', strtotime(sprintf('%04d-%02d-01', $report['year'], $report['month'])) ?: time());

$ed = null;
foreach ($report['editors'] as $row) {
    if (($row['username'] ?? '') === $editorKey) {
        $ed = $row;
        break;
    }
}
if ($ed === null) {
    header('Location: ' . base_path('admin/attendance.php'));
    exit;
}

$pageTitle = 'Attendance — ' . $editorKey . ' — ' . SITE_NAME;
$bodyClass = 'page-portal admin-page admin-page--board';
$adminNavActive = 'attendance.php';

$years = range((int) date('Y'), (int) date('Y') - 2);
$months = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June',
    7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
];

require_once AKH_ROOT . '/includes/header.php';
?>

  <main id="main" class="portal-main portal-main--board">
    <div class="portal-card portal-card--tasks admin-shell">
      <header class="admin-head">
        <div>
          <h1 class="portal-title">Attendance — <?php echo h($editorKey); ?></h1>
          <p class="portal-lead admin-head__meta">Calendar and monthly totals. <strong class="atd-legend atd-legend--pleave">Purple</strong> = approved leave. <strong class="atd-legend atd-legend--leave">Red</strong> = absent. Saturday uses half-day targets. <span class="admin-attendance-tz">All times <?php echo h(AKH_SITE_TIMEZONE === 'Asia/Kolkata' ? 'IST (Asia/Kolkata)' : AKH_SITE_TIMEZONE); ?>.</span></p>
        </div>
        <div class="admin-head__actions">
          <?php $adminConsoleActive = ''; require __DIR__ . '/includes/admin-console-sidebar.php'; ?>
          <a class="btn btn--ghost btn--sm" href="<?php echo h(base_path('admin/attendance.php?year=' . $report['year'] . '&month=' . $report['month'])); ?>">← All editors</a>
          <a class="btn btn--ghost btn--sm" href="<?php echo h(base_path('admin/logout.php')); ?>">Sign out</a>
        </div>
      </header>

      <?php require AKH_ROOT . '/includes/admin-nav.php'; ?>

      <form class="admin-attendance-toolbar" method="get" action="" aria-label="Report month">
        <input type="hidden" name="editor" value="<?php echo h($editorKey); ?>" />
        <span class="admin-attendance-toolbar__label">Month</span>
        <label class="admin-attendance-toolbar__field"><span class="visually-hidden">Month</span>
          <select name="month" aria-label="Month">
            <?php foreach ($months as $num => $label): ?>
              <option value="<?php echo (int) $num; ?>"<?php echo $num === $report['month'] ? ' selected' : ''; ?>><?php echo h($label); ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <span class="admin-attendance-toolbar__label">Year</span>
        <label class="admin-attendance-toolbar__field"><span class="visually-hidden">Year</span>
          <select name="year" aria-label="Year">
            <?php foreach ($years as $yr): ?>
              <option value="<?php echo (int) $yr; ?>"<?php echo $yr === $report['year'] ? ' selected' : ''; ?>><?php echo (int) $yr; ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <button type="submit" class="btn btn--primary btn--sm">Show</button>
      </form>

      <div class="admin-attendance-detailcard">
        <?php require __DIR__ . '/includes/attendance-calendar-article.php'; ?>
      </div>
    </div>
  </main>

<?php require_once AKH_ROOT . '/includes/footer.php'; ?>
