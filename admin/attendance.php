<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once AKH_ROOT . '/includes/admin-auth.php';
require_once AKH_ROOT . '/includes/editor-attendance-report.php';
require_once AKH_ROOT . '/includes/editor-leave.php';
require_once AKH_ROOT . '/includes/csrf.php';

akh_require_admin();

$leaveErr = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!akh_csrf_verify($_POST['csrf_token'] ?? null)) {
        $leaveErr = 'Security check failed. Refresh and try again.';
    } else {
        $act = (string) ($_POST['leave_action'] ?? '');
        $id = trim((string) ($_POST['request_id'] ?? ''));
        $ry = (int) ($_POST['redirect_year'] ?? date('Y'));
        $rm = (int) ($_POST['redirect_month'] ?? date('n'));
        if ($id !== '' && ($act === 'approve' || $act === 'reject')) {
            $ok = akh_editor_leave_set_status($id, $act === 'approve' ? 'approved' : 'rejected');
            if ($ok) {
                header('Location: ' . base_path('admin/attendance.php?' . http_build_query(['year' => $ry, 'month' => $rm, 'ok' => '1'])));
                exit;
            }
            $leaveErr = 'Could not update that request (already processed or missing).';
        }
    }
}

$dataClearErr = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_attendance_data_action'])) {
    if (!akh_csrf_verify($_POST['csrf_token'] ?? null)) {
        $dataClearErr = 'Security check failed. Refresh and try again.';
    } else {
        $dataAct = (string) ($_POST['admin_attendance_data_action'] ?? '');
        $ry = max(2000, min(2100, (int) ($_POST['redirect_year'] ?? date('Y'))));
        $rm = max(1, min(12, (int) ($_POST['redirect_month'] ?? date('n'))));
        if ($dataAct === 'clear_attendance') {
            $okBox = isset($_POST['confirm_delete_attendance_data']) && (string) ($_POST['confirm_delete_attendance_data'] ?? '') === '1';
            if (!$okBox) {
                $dataClearErr = 'Check the box to confirm deleting all attendance events.';
            } elseif (!akh_editor_attendance_clear_all()) {
                $dataClearErr = 'Could not clear attendance data.';
            } else {
                header('Location: ' . base_path('admin/attendance.php?' . http_build_query(['year' => $ry, 'month' => $rm, 'cleared' => 'attendance'])));
                exit;
            }
        } elseif ($dataAct === 'clear_leave') {
            $okBox = isset($_POST['confirm_delete_leave_data']) && (string) ($_POST['confirm_delete_leave_data'] ?? '') === '1';
            if (!$okBox) {
                $dataClearErr = 'Check the box to confirm deleting all leave requests.';
            } elseif (!akh_editor_leave_clear_all()) {
                $dataClearErr = 'Could not clear leave requests.';
            } else {
                header('Location: ' . base_path('admin/attendance.php?' . http_build_query(['year' => $ry, 'month' => $rm, 'cleared' => 'leave'])));
                exit;
            }
        }
    }
}

$y = (int) ($_GET['year'] ?? date('Y'));
$m = (int) ($_GET['month'] ?? date('n'));
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['redirect_year'])) {
    $y = max(2000, min(2100, (int) $_POST['redirect_year']));
    $m = max(1, min(12, (int) ($_POST['redirect_month'] ?? 1)));
}
$report = akh_editor_attendance_month_report($y, $m);
$monthLabel = date('F Y', strtotime(sprintf('%04d-%02d-01', $report['year'], $report['month'])) ?: time());
$pendingLeaves = akh_editor_leave_pending_list();
$okBanner = (string) ($_GET['ok'] ?? '') === '1';
$dataClearFlash = '';
if ((string) ($_GET['cleared'] ?? '') === 'attendance') {
    $dataClearFlash = 'All editor clock in/out events were deleted.';
} elseif ((string) ($_GET['cleared'] ?? '') === 'leave') {
    $dataClearFlash = 'All leave requests were deleted.';
}
$attEventsCount = count(akh_editor_attendance_read_doc()['events']);
$leaveReqCount = count(akh_editor_leave_read()['requests']);

$pageTitle = 'Editor attendance — ' . SITE_NAME;
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
          <h1 class="portal-title">Editor attendance</h1>
          <p class="portal-lead admin-head__meta">One line per editor — open a name for the full calendar. <strong class="atd-legend atd-legend--leave">Red</strong> absent · <strong class="atd-legend atd-legend--pleave">Purple</strong> approved leave · Saturday half-day. <span class="admin-attendance-tz">All times <?php echo h(AKH_SITE_TIMEZONE === 'Asia/Kolkata' ? 'IST (Asia/Kolkata)' : AKH_SITE_TIMEZONE); ?>.</span></p>
        </div>
        <div class="admin-head__actions">
          <?php $adminConsoleActive = ''; require __DIR__ . '/includes/admin-console-sidebar.php'; ?>
          <a class="btn btn--ghost btn--sm" href="<?php echo h(base_path('admin/logout.php')); ?>">Sign out</a>
        </div>
      </header>

      <?php require AKH_ROOT . '/includes/admin-nav.php'; ?>

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

      <?php if ($okBanner): ?>
        <p class="banner banner--ok" role="status">Leave request updated.</p>
      <?php endif; ?>
      <?php if ($dataClearFlash !== ''): ?>
        <p class="banner banner--ok" role="status"><?php echo h($dataClearFlash); ?></p>
      <?php endif; ?>
      <?php if ($leaveErr !== ''): ?>
        <p class="banner banner--err" role="alert"><?php echo h($leaveErr); ?></p>
      <?php endif; ?>
      <?php if ($dataClearErr !== ''): ?>
        <p class="banner banner--err" role="alert"><?php echo h($dataClearErr); ?></p>
      <?php endif; ?>

      <?php if (!AKH_EDITOR_ATTENDANCE_ENABLED): ?>
        <p class="banner banner--info" role="status">Editor attendance is turned off in <code>includes/config.php</code>.</p>
      <?php endif; ?>

      <?php if ($report['editors'] === []): ?>
        <p class="portal-muted" style="margin-top:1rem">No editor accounts yet. Add editors under <a class="text-link" href="<?php echo h(base_path('admin/editors.php')); ?>">Editors</a>.</p>
      <?php endif; ?>

      <?php if ($pendingLeaves !== [] && AKH_EDITOR_ATTENDANCE_ENABLED): ?>
        <section id="leave-queue" class="admin-attendance-leavequeue">
          <h2 class="admin-attendance-leavequeue__title">Pending leave</h2>
          <ul class="admin-attendance-leavequeue__list">
            <?php foreach ($pendingLeaves as $req): ?>
              <li class="admin-attendance-leavequeue__item">
                <div>
                  <strong><?php echo h((string) ($req['editor'] ?? '')); ?></strong>
                  <span class="admin-attendance-leavequeue__date"><?php echo h((string) ($req['date'] ?? '')); ?></span>
                  <?php if (trim((string) ($req['note'] ?? '')) !== ''): ?>
                    <span class="admin-attendance-leavequeue__note"><?php echo h((string) ($req['note'] ?? '')); ?></span>
                  <?php endif; ?>
                </div>
                <div class="admin-attendance-leavequeue__actions">
                  <form method="post" action="" class="admin-attendance-leavequeue__form">
                    <input type="hidden" name="csrf_token" value="<?php echo h(akh_csrf_token()); ?>" />
                    <input type="hidden" name="request_id" value="<?php echo h((string) ($req['id'] ?? '')); ?>" />
                    <input type="hidden" name="redirect_year" value="<?php echo (int) $report['year']; ?>" />
                    <input type="hidden" name="redirect_month" value="<?php echo (int) $report['month']; ?>" />
                    <button type="submit" name="leave_action" value="approve" class="btn btn--primary btn--sm">Approve</button>
                    <button type="submit" name="leave_action" value="reject" class="btn btn--ghost btn--sm">Reject</button>
                  </form>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        </section>
      <?php endif; ?>

      <div class="admin-attendance-rows">
        <?php foreach ($report['editors'] as $ed): ?>
          <?php
          $detailUrl = base_path('admin/attendance-detail.php?editor=' . rawurlencode((string) ($ed['username'] ?? '')) . '&year=' . (int) $report['year'] . '&month=' . (int) $report['month']);
          $brief = (int) ($ed['present_working_days'] ?? 0) . ' pres · '
              . (int) ($ed['days_under_8h'] ?? 0) . ' short · '
              . (int) ($ed['leave_days'] ?? 0) . ' absent · '
              . (int) ($ed['excused_leave_days'] ?? 0) . ' leave ok';
          if (($ed['leave_pending_in_month'] ?? 0) > 0) {
              $brief .= ' · ' . (int) $ed['leave_pending_in_month'] . ' leave pending';
          }
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

      <section class="portal-section admin-attendance-datapurge" aria-labelledby="admin-attendance-datapurge-h">
        <h2 id="admin-attendance-datapurge-h" class="portal-section__title">Attendance &amp; leave data</h2>
        <p class="portal-muted" style="margin:0 0 0.75rem">Permanently remove stored clock events or leave requests for every editor. Use after testing or before go-live.</p>
        <?php if ($attEventsCount === 0 && $leaveReqCount === 0): ?>
          <p class="portal-muted" style="margin:0">No clock events or leave requests on file.</p>
        <?php else: ?>
          <p class="portal-muted" style="margin:0 0 0.75rem">Currently <strong><?php echo (int) $attEventsCount; ?></strong> clock event<?php echo $attEventsCount === 1 ? '' : 's'; ?> and <strong><?php echo (int) $leaveReqCount; ?></strong> leave request<?php echo $leaveReqCount === 1 ? '' : 's'; ?>.</p>
          <div class="admin-attendance-datapurge__forms">
            <?php if ($attEventsCount > 0): ?>
              <form class="admin-danger-zone" method="post" action="" onsubmit="return confirm('Delete all <?php echo (int) $attEventsCount; ?> clock in/out events for every editor? This cannot be undone.');">
                <input type="hidden" name="csrf_token" value="<?php echo h(akh_csrf_token()); ?>" />
                <input type="hidden" name="admin_attendance_data_action" value="clear_attendance" />
                <input type="hidden" name="redirect_year" value="<?php echo (int) $report['year']; ?>" />
                <input type="hidden" name="redirect_month" value="<?php echo (int) $report['month']; ?>" />
                <label class="admin-danger-zone__check">
                  <input type="checkbox" name="confirm_delete_attendance_data" value="1" />
                  <span>I understand this deletes <strong>every</strong> editor clock in/out record.</span>
                </label>
                <button type="submit" class="btn btn--ghost btn--sm admin-danger-zone__btn">Delete all attendance</button>
              </form>
            <?php endif; ?>
            <?php if ($leaveReqCount > 0): ?>
              <form class="admin-danger-zone" method="post" action="" onsubmit="return confirm('Delete all <?php echo (int) $leaveReqCount; ?> leave requests (any status)? This cannot be undone.');">
                <input type="hidden" name="csrf_token" value="<?php echo h(akh_csrf_token()); ?>" />
                <input type="hidden" name="admin_attendance_data_action" value="clear_leave" />
                <input type="hidden" name="redirect_year" value="<?php echo (int) $report['year']; ?>" />
                <input type="hidden" name="redirect_month" value="<?php echo (int) $report['month']; ?>" />
                <label class="admin-danger-zone__check">
                  <input type="checkbox" name="confirm_delete_leave_data" value="1" />
                  <span>I understand this deletes <strong>every</strong> leave request (pending, approved, and rejected).</span>
                </label>
                <button type="submit" class="btn btn--ghost btn--sm admin-danger-zone__btn">Delete all leave requests</button>
              </form>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </section>
    </div>
  </main>

<?php require_once AKH_ROOT . '/includes/footer.php'; ?>
