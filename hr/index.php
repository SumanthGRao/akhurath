<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once AKH_ROOT . '/includes/hr-auth.php';
require_once AKH_ROOT . '/includes/editor-auth.php';
require_once AKH_ROOT . '/includes/editor-attendance-report.php';

akh_require_hr();

$y = (int) ($_GET['year'] ?? date('Y'));
$m = (int) ($_GET['month'] ?? date('n'));
$report = akh_editor_attendance_month_report($y, $m);
$monthLabel = date('F Y', strtotime(sprintf('%04d-%02d-01', $report['year'], $report['month'])) ?: time());
$exportQs = http_build_query(['year' => $report['year'], 'month' => $report['month']]);
$exportBase = base_path('hr/attendance-export.php?' . $exportQs);
$hrUser = (string) akh_hr_current();

$pageTitle = 'HR — Attendance — ' . SITE_NAME;
$bodyClass = 'page-portal admin-page admin-page--board';

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
          <h1 class="portal-title">Editor attendance</h1>
          <p class="portal-lead admin-head__meta">HR view — monitor clock-ins and monthly hours. Signed in as <strong><?php echo h($hrUser); ?></strong>. Times shown in <?php echo h(AKH_SITE_TIMEZONE === 'Asia/Kolkata' ? 'IST (Asia/Kolkata)' : AKH_SITE_TIMEZONE); ?>.</p>
        </div>
        <div class="admin-head__actions">
          <a class="btn btn--ghost btn--sm" href="<?php echo h(base_path('hr/logout.php')); ?>">Sign out</a>
        </div>
      </header>

      <form class="admin-attendance-toolbar" method="get" action="" aria-label="Report month">
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

      <?php if ($report['editors'] !== []): ?>
        <div class="admin-attendance-export" aria-label="Export report">
          <span class="admin-attendance-export__label">Export <?php echo h($monthLabel); ?></span>
          <a class="btn btn--ghost btn--sm" href="<?php echo h($exportBase . '&format=csv'); ?>">Excel (CSV)</a>
          <a class="btn btn--ghost btn--sm" href="<?php echo h($exportBase . '&format=excel'); ?>">Excel (.xls)</a>
          <a class="btn btn--ghost btn--sm" href="<?php echo h($exportBase . '&format=pdf'); ?>" target="_blank" rel="noopener">PDF</a>
        </div>
      <?php endif; ?>

      <?php if (!AKH_EDITOR_ATTENDANCE_ENABLED): ?>
        <p class="banner banner--info" role="status">Editor attendance is turned off in site config.</p>
      <?php endif; ?>

      <?php if ($report['editors'] === []): ?>
        <p class="portal-muted" style="margin-top:1rem">No editor accounts found.</p>
      <?php endif; ?>

      <div class="admin-attendance-rows">
        <?php foreach ($report['editors'] as $ed): ?>
          <?php
          $detailUrl = base_path('hr/attendance-detail.php?editor=' . rawurlencode((string) ($ed['username'] ?? '')) . '&year=' . (int) $report['year'] . '&month=' . (int) $report['month']);
          $brief = (int) ($ed['present_working_days'] ?? 0) . ' pres · '
              . (int) ($ed['days_under_8h'] ?? 0) . ' short · '
              . (int) ($ed['leave_days'] ?? 0) . ' absent · '
              . akh_editor_attendance_format_leave_units((float) ($ed['excused_leave_days'] ?? 0)) . ' leave ok';
          $punches = akh_editor_attendance_today_punch_parts((string) ($ed['username'] ?? ''));
          ?>
          <div class="admin-attendance-row">
            <div class="admin-attendance-row__who">
              <a class="admin-attendance-row__name" href="<?php echo h($detailUrl); ?>"><?php echo h((string) ($ed['username'] ?? '')); ?></a>
              <span class="admin-attendance-row__month"><?php echo h($monthLabel); ?></span>
            </div>
            <div class="admin-attendance-row__today" aria-label="Today">
              <span class="admin-attendance-row__inline-k">Today</span>
              <?php if ($punches['kind'] === 'empty'): ?>
                <span class="admin-attendance-row__inline-muted">No punches today</span>
              <?php elseif ($punches['kind'] === 'on_shift'): ?>
                <span class="admin-attendance-row__inline-bit">In</span>
                <span class="admin-attendance-row__time"><?php echo h($punches['in']); ?></span>
                <span class="admin-attendance-row__sep" aria-hidden="true">·</span>
                <span class="admin-attendance-row__inline-muted">On shift</span>
              <?php elseif ($punches['kind'] === 'full'): ?>
                <span class="admin-attendance-row__inline-bit">In</span>
                <span class="admin-attendance-row__time"><?php echo h($punches['in']); ?></span>
                <span class="admin-attendance-row__sep" aria-hidden="true">·</span>
                <span class="admin-attendance-row__inline-bit">Out</span>
                <span class="admin-attendance-row__time"><?php echo h($punches['out']); ?></span>
              <?php else: ?>
                <span class="admin-attendance-row__inline-bit">In</span>
                <span class="admin-attendance-row__time"><?php echo h($punches['in']); ?></span>
              <?php endif; ?>
            </div>
            <div class="admin-attendance-row__brief" aria-label="Month summary">
              <span class="admin-attendance-row__inline-k">Month</span>
              <span class="admin-attendance-row__inline-val"><?php echo h($brief); ?></span>
            </div>
            <div class="admin-attendance-row__go">
              <a class="btn btn--ghost btn--sm" href="<?php echo h($detailUrl); ?>">Details</a>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </main>

<?php require_once AKH_ROOT . '/includes/footer.php'; ?>
