<?php

declare(strict_types=1);

/** @var array $ed */
/** @var string $editorKey */
/** @var array $report */

$workingDays = (int) ($ed['working_days'] ?? 0);
$excusedLeave = (float) ($ed['excused_leave_days'] ?? 0);
$unapprovedLeave = (int) ($ed['leave_days'] ?? 0);
$storageKey = 'akhSalary:' . $editorKey . ':' . $report['year'] . '-' . sprintf('%02d', $report['month']);
?>
          <aside
            class="admin-salary-calc"
            id="admin-salary-calc"
            aria-labelledby="admin-salary-calc-title"
            data-working-days="<?php echo (int) $workingDays; ?>"
            data-excused-leave="<?php echo h((string) $excusedLeave); ?>"
            data-unapproved-leave="<?php echo (int) $unapprovedLeave; ?>"
            data-storage-key="<?php echo h($storageKey); ?>"
          >
            <header class="admin-salary-calc__head">
              <h3 class="admin-salary-calc__title" id="admin-salary-calc-title">Salary calculator</h3>
              <p class="admin-salary-calc__sub">Based on attendance for this month. LOP = unapproved absences + approved leave above allowance.</p>
            </header>

            <div class="admin-salary-calc__inputs">
              <label class="field admin-salary-calc__field">
                <span>Monthly salary (₹)</span>
                <input
                  type="number"
                  id="admin-salary-monthly"
                  class="admin-salary-calc__input"
                  min="0"
                  step="100"
                  inputmode="decimal"
                  placeholder="e.g. 25000"
                  autocomplete="off"
                />
              </label>
              <label class="field admin-salary-calc__field">
                <span>Paid leaves allowed / month</span>
                <input
                  type="number"
                  id="admin-salary-allowed-leaves"
                  class="admin-salary-calc__input"
                  min="0"
                  step="0.5"
                  inputmode="decimal"
                  placeholder="e.g. 1"
                  autocomplete="off"
                />
              </label>
            </div>

            <dl class="admin-salary-calc__attendance" aria-label="Attendance used for calculation">
              <div class="admin-salary-calc__att-row">
                <dt>Working days (Mon–Sat)</dt>
                <dd><?php echo (int) $workingDays; ?></dd>
              </div>
              <div class="admin-salary-calc__att-row">
                <dt>Approved leave taken</dt>
                <dd><?php echo h(akh_editor_attendance_format_leave_units($excusedLeave)); ?> day<?php echo abs($excusedLeave - 1.0) > 0.001 ? 's' : ''; ?></dd>
              </div>
              <div class="admin-salary-calc__att-row">
                <dt>Unapproved absences</dt>
                <dd><?php echo (int) $unapprovedLeave; ?> day<?php echo $unapprovedLeave === 1 ? '' : 's'; ?></dd>
              </div>
            </dl>

            <div class="admin-salary-calc__results" id="admin-salary-results" hidden>
              <dl class="admin-salary-calc__breakdown">
                <div class="admin-salary-calc__row">
                  <dt>Per-day rate</dt>
                  <dd data-salary-out="per_day">—</dd>
                </div>
                <div class="admin-salary-calc__row">
                  <dt>Paid leave (within allowance)</dt>
                  <dd data-salary-out="paid_leave">—</dd>
                </div>
                <div class="admin-salary-calc__row admin-salary-calc__row--warn">
                  <dt>LOP days</dt>
                  <dd data-salary-out="lop_days">—</dd>
                </div>
                <div class="admin-salary-calc__row admin-salary-calc__row--warn">
                  <dt>LOP deduction</dt>
                  <dd data-salary-out="lop_deduction">—</dd>
                </div>
              </dl>
              <p class="admin-salary-calc__net">
                <span class="admin-salary-calc__net-label">Net salary</span>
                <strong class="admin-salary-calc__net-value" data-salary-out="net_salary">—</strong>
              </p>
              <p class="admin-salary-calc__note" data-salary-out="lop_detail"></p>
            </div>

            <p class="admin-salary-calc__empty" id="admin-salary-empty">Enter monthly salary to calculate.</p>
          </aside>
