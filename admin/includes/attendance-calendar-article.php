<?php

declare(strict_types=1);

/** @var array $ed */
/** @var string $monthLabel */
/** @var array $report */

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
        <article class="admin-attendance-card admin-attendance-card--detail" style="--stagger: 0">
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
              <span class="admin-attendance-stat__label">Below target days</span>
              <span class="admin-attendance-stat__hint">Mon–Fri &lt;<?php echo (int) AKH_ATTENDANCE_EXPECTED_HOURS; ?>h · Sat &lt;<?php echo (int) AKH_ATTENDANCE_EXPECTED_HOURS / 2; ?>h</span>
            </div>
            <div class="admin-attendance-stat admin-attendance-stat--pop<?php echo $ed['leave_days'] > 0 ? ' admin-attendance-stat--warn' : ''; ?>">
              <span class="admin-attendance-stat__value"><?php echo (int) $ed['leave_days']; ?></span>
              <span class="admin-attendance-stat__label">Absent (unapproved)</span>
              <span class="admin-attendance-stat__hint">No punch and no approved leave</span>
            </div>
            <div class="admin-attendance-stat admin-attendance-stat--pop">
              <span class="admin-attendance-stat__value"><?php echo h(akh_editor_attendance_format_leave_units((float) ($ed['excused_leave_days'] ?? 0))); ?></span>
              <span class="admin-attendance-stat__label">Approved leave</span>
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
            <?php for ($row = 0; $row * 7 < count($grid); ++$row): ?>
            <div class="admin-attendance-cal__row" role="row">
              <?php for ($col = 0; $col < 7; ++$col):
                  $slot = $row * 7 + $col;
                  $c = $grid[$slot] ?? null;
                  if ($c === null): ?>
              <div class="atd atd--empty" role="gridcell"></div>
                  <?php else:
                      $classes = ['atd'];
                      if ($c['sunday']) {
                          $classes[] = 'atd--sun';
                      } elseif ($c['future']) {
                          $classes[] = 'atd--future';
                      } elseif (!empty($c['pleave'])) {
                          $classes[] = 'atd--pleave';
                          if (($c['leave_part'] ?? 'full') !== 'full') {
                              $classes[] = 'atd--pleave-half';
                          }
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
                      $pi = $c['punch_in'] ?? null;
                      $po = $c['punch_out'] ?? null;
                      $tip = $c['ymd'] . ' — ' . $hrs;
                      if ($pi !== null && $pi !== '') {
                          $tip .= ' · In ' . $pi;
                      }
                      if ($po !== null && $po !== '') {
                          $tip .= ' · Out ' . $po;
                      }
                      ?>
              <div class="<?php echo h(implode(' ', $classes)); ?>" role="gridcell" title="<?php echo h($tip); ?>">
                <span class="atd__num"><?php echo (int) $c['dom']; ?></span>
                <?php if (($pi !== null && $pi !== '') || ($po !== null && $po !== '')): ?>
                  <div class="atd__punches">
                    <?php if ($pi !== null && $pi !== ''): ?>
                      <span class="atd__punch atd__punch--in"><?php echo h($pi); ?></span>
                    <?php endif; ?>
                    <?php if ($po !== null && $po !== ''): ?>
                      <span class="atd__punch atd__punch--out"><?php echo h($po); ?></span>
                    <?php endif; ?>
                  </div>
                <?php endif; ?>
                <span class="atd__hrs"><?php
                    if (!empty($c['pleave'])) {
                        $lp = (string) ($c['leave_part'] ?? 'full');
                        if ($lp === 'first_half') {
                            echo 'Leave 1st half';
                        } elseif ($lp === 'second_half') {
                            echo 'Leave 2nd half';
                        } else {
                            echo 'Leave';
                        }
                    } else {
                        echo h($hrs);
                    }
                ?></span>
              </div>
                  <?php endif;
              endfor; ?>
            </div>
            <?php endfor; ?>
          </div>
        </article>
