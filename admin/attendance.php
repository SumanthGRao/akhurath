<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once AKH_ROOT . '/includes/admin-auth.php';
require_once AKH_ROOT . '/includes/editor-attendance-report.php';

akh_require_admin();

$y = (int) ($_GET['year'] ?? date('Y'));
$m = (int) ($_GET['month'] ?? date('n'));
$report = akh_editor_attendance_month_report($y, $m);
$monthLabel = date('F Y', strtotime(sprintf('%04d-%02d-01', $report['year'], $report['month'])) ?: time());

$pageTitle = 'Editor attendance — ' . SITE_NAME;
$bodyClass = 'page-portal admin-page admin-page--board admin-page--attendance';
$adminNavActive = 'attendance.php';

$years = range((int) date('Y'), (int) date('Y') - 2);
$months = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June',
    7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
];

require_once AKH_ROOT . '/includes/header.php';
?>

  <main id="main" class="portal-main portal-main--board">
    <div class="portal-card portal-card--tasks admin-shell admin-attendance">
      <header class="admin-head">
        <div>
          <h1 class="portal-title">Editor attendance</h1>
          <p class="portal-lead admin-head__meta">Month view: Sundays off (grey). <strong class="atd-legend atd-legend--leave">Red</strong> = leave (no clock-in on a past Mon–Sat). <strong class="atd-legend atd-legend--short">Red line</strong> = worked but under the daily target (Mon–Fri <?php echo (int) AKH_ATTENDANCE_EXPECTED_HOURS; ?>h, <strong>Saturday half-day <?php echo (int) AKH_ATTENDANCE_EXPECTED_HOURS / 2; ?>h</strong>). <strong class="atd-legend atd-legend--9h">Green</strong> = full shift (Mon–Fri <?php echo (int) AKH_ATTENDANCE_FULL_SHIFT_HOURS; ?>h+, Sat <?php echo (int) AKH_ATTENDANCE_FULL_SHIFT_HOURS / 2; ?>h 30m+). Open shifts capped at 9h Mon–Fri and 4h 30m when started on Saturday.</p>
        </div>
        <p class="admin-head__actions">
          <a class="btn btn--ghost btn--sm" href="<?php echo h(base_path('admin/logout.php')); ?>">Sign out</a>
        </p>
      </header>

      <?php require AKH_ROOT . '/includes/admin-nav.php'; ?>

      <?php if (!AKH_EDITOR_ATTENDANCE_ENABLED): ?>
        <p class="banner banner--info" role="status">Editor attendance is turned off in <code>includes/config.php</code> (<code>AKH_EDITOR_ATTENDANCE_ENABLED</code>). Turn it on to record clock-in/out from the editor task board.</p>
      <?php endif; ?>

      <form class="admin-attendance__picker portal-form" method="get" action="">
        <label class="field">
          <span>Month</span>
          <select name="month">
            <?php foreach ($months as $num => $label): ?>
              <option value="<?php echo (int) $num; ?>"<?php echo $num === $report['month'] ? ' selected' : ''; ?>><?php echo h($label); ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="field">
          <span>Year</span>
          <select name="year">
            <?php foreach ($years as $yr): ?>
              <option value="<?php echo (int) $yr; ?>"<?php echo $yr === $report['year'] ? ' selected' : ''; ?>><?php echo (int) $yr; ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <button type="submit" class="btn btn--primary btn--sm">Show</button>
      </form>

      <?php if ($report['editors'] === []): ?>
        <p class="portal-muted" style="margin-top:1rem">No editor accounts yet. Add editors under <a class="text-link" href="<?php echo h(base_path('admin/editors.php')); ?>">Editors</a>.</p>
      <?php endif; ?>

      <?php foreach ($report['editors'] as $idx => $ed): ?>
        <?php
        $cells = $ed['cells'];
        $firstTs = strtotime(sprintf('%04d-%02d-01 12:00:00', $report['year'], $report['month']));
        $pad = $firstTs !== false ? (int) date('w', $firstTs) : 0;
        $grid = [];
        for ($i = 0; $i < $pad; ++$i) {
            $grid[] = null;
        }
        foreach ($cells as $c) {
            $grid[] = $c;
        }
        while (count($grid) % 7 !== 0) {
            $grid[] = null;
        }
        $barP = $ed['bars'];
        ?>
        <article class="admin-attendance-card" style="--stagger: <?php echo (int) $idx; ?>">
          <header class="admin-attendance-card__head">
            <h2 class="admin-attendance-card__title"><?php echo h($ed['username']); ?></h2>
            <p class="admin-attendance-card__sub"><?php echo h($monthLabel); ?></p>
          </header>

          <div class="admin-attendance-stats" aria-label="Monthly totals for <?php echo h($ed['username']); ?>">
            <div class="admin-attendance-stat admin-attendance-stat--pop">
              <span class="admin-attendance-stat__value"><?php echo (int) $ed['present_working_days']; ?></span>
              <span class="admin-attendance-stat__label">Present (Mon–Sat)</span>
            </div>
            <div class="admin-attendance-stat admin-attendance-stat--pop">
              <span class="admin-attendance-stat__value"><?php echo (int) $ed['clock_in_days']; ?></span>
              <span class="admin-attendance-stat__label">Days clocked in</span>
            </div>
            <div class="admin-attendance-stat admin-attendance-stat--pop">
              <span class="admin-attendance-stat__value"><?php echo (int) $ed['days_9h_plus']; ?></span>
              <span class="admin-attendance-stat__label">Full-shift days</span>
              <span class="admin-attendance-stat__hint">Mon–Fri <?php echo (int) AKH_ATTENDANCE_FULL_SHIFT_HOURS; ?>h+ · Sat 4h 30m+</span>
            </div>
            <div class="admin-attendance-stat admin-attendance-stat--pop<?php echo $ed['days_under_8h'] > 0 ? ' admin-attendance-stat--warn' : ''; ?>">
              <span class="admin-attendance-stat__value"><?php echo (int) $ed['days_under_8h']; ?></span>
              <span class="admin-attendance-stat__label">Under target</span>
              <span class="admin-attendance-stat__hint">Mon–Fri &lt;<?php echo (int) AKH_ATTENDANCE_EXPECTED_HOURS; ?>h · Sat &lt;<?php echo (int) AKH_ATTENDANCE_EXPECTED_HOURS / 2; ?>h</span>
            </div>
            <div class="admin-attendance-stat admin-attendance-stat--pop<?php echo $ed['leave_days'] > 0 ? ' admin-attendance-stat--warn' : ''; ?>">
              <span class="admin-attendance-stat__value"><?php echo (int) $ed['leave_days']; ?></span>
              <span class="admin-attendance-stat__label">Leave days</span>
            </div>
            <div class="admin-attendance-stat admin-attendance-stat--pop admin-attendance-stat--muted">
              <span class="admin-attendance-stat__value"><?php echo (int) $ed['sundays']; ?></span>
              <span class="admin-attendance-stat__label">Sundays (off)</span>
            </div>
          </div>

          <div class="admin-attendance-bars" aria-hidden="true">
            <div class="admin-attendance-bar">
              <span class="admin-attendance-bar__label">Present / Mon–Sat</span>
              <div class="admin-attendance-bar__track"><span class="admin-attendance-bar__fill admin-attendance-bar__fill--mint" style="--w: <?php echo (float) $barP['present_pct']; ?>%"></span></div>
              <span class="admin-attendance-bar__pct"><?php echo h((string) $barP['present_pct']); ?>%</span>
            </div>
            <div class="admin-attendance-bar">
              <span class="admin-attendance-bar__label">Clock-in / Mon–Sat</span>
              <div class="admin-attendance-bar__track"><span class="admin-attendance-bar__fill admin-attendance-bar__fill--sky" style="--w: <?php echo (float) $barP['clock_pct']; ?>%"></span></div>
              <span class="admin-attendance-bar__pct"><?php echo h((string) $barP['clock_pct']); ?>%</span>
            </div>
            <div class="admin-attendance-bar">
              <span class="admin-attendance-bar__label">Full-shift met / Mon–Sat</span>
              <div class="admin-attendance-bar__track"><span class="admin-attendance-bar__fill admin-attendance-bar__fill--green" style="--w: <?php echo (float) $barP['nine_pct']; ?>%"></span></div>
              <span class="admin-attendance-bar__pct"><?php echo h((string) $barP['nine_pct']); ?>%</span>
            </div>
          </div>

          <div class="admin-attendance-cal" role="grid" aria-label="Calendar for <?php echo h($ed['username']); ?>">
            <div class="admin-attendance-cal__dow" role="row">
              <?php foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $dow): ?>
                <span role="columnheader"><?php echo h($dow); ?></span>
              <?php endforeach; ?>
            </div>
            <?php
            for ($row = 0; $row * 7 < count($grid); ++$row):
                ?>
            <div class="admin-attendance-cal__row" role="row">
              <?php
                for ($col = 0; $col < 7; ++$col):
                    $slot = $row * 7 + $col;
                    $c = $grid[$slot] ?? null;
                    if ($c === null):
                        ?>
              <div class="atd atd--empty" role="gridcell"></div>
                        <?php
                    else:
                        $classes = ['atd'];
                        if ($c['sunday']) {
                            $classes[] = 'atd--sun';
                        } elseif ($c['future']) {
                            $classes[] = 'atd--future';
                        } elseif ($c['leave']) {
                            $classes[] = 'atd--leave';
                        } elseif ($c['under8']) {
                            $classes[] = 'atd--short';
                        } elseif ($c['nine_plus']) {
                            $classes[] = 'atd--9h';
                        } elseif (($c['expected_sec'] ?? 0) > 0
                            && ($c['seconds'] ?? 0) >= ($c['expected_sec'] ?? 0)
                            && ($c['seconds'] ?? 0) < ($c['full_shift_sec'] ?? PHP_INT_MAX)) {
                            $classes[] = 'atd--ok';
                        } elseif (($c['seconds'] ?? 0) > 0 || ($c['clock_in'] ?? false)) {
                            $classes[] = 'atd--in';
                        } else {
                            $classes[] = 'atd--na';
                        }
                        if (!empty($c['today'])) {
                            $classes[] = 'atd--today';
                        }
                        $hrs = akh_editor_attendance_format_hours((int) ($c['seconds'] ?? 0));
                        ?>
              <div class="<?php echo h(implode(' ', $classes)); ?>" role="gridcell" title="<?php echo h($c['ymd'] . ' — ' . $hrs); ?>">
                <span class="atd__num"><?php echo (int) $c['dom']; ?></span>
                <span class="atd__hrs"><?php echo h($hrs); ?></span>
              </div>
                        <?php
                    endif;
                endfor;
                ?>
            </div>
            <?php endfor; ?>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  </main>

<?php require_once AKH_ROOT . '/includes/footer.php'; ?>
