<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once AKH_ROOT . '/includes/editor-auth.php';
require_once AKH_ROOT . '/includes/editor-attendance.php';
require_once AKH_ROOT . '/includes/editor-leave.php';
require_once AKH_ROOT . '/includes/tasks.php';
require_once AKH_ROOT . '/includes/task-thread-panel.php';
require_once AKH_ROOT . '/includes/csrf.php';

akh_require_editor();

$pageTitle = 'Editor tasks — ' . SITE_NAME;
$metaDescription = 'Assign and update client tasks.';
$bodyClass = 'page-portal page-portal--board';

$editor = (string) akh_editor_current();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && trim((string) ($_POST['ajax_action'] ?? '')) !== '') {
    header('Content-Type: application/json; charset=utf-8');
    if (!akh_csrf_verify($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        echo json_encode(['ok' => false]);

        exit;
    }
    $ajax = trim((string) ($_POST['ajax_action'] ?? ''));
    if ($ajax === 'view_ack') {
        try {
            echo json_encode(
                akh_task_ajax_editor_view_ack(
                    $editor,
                    trim((string) ($_POST['task_id'] ?? '')),
                    trim((string) ($_POST['ack_kind'] ?? ''))
                ),
                JSON_THROW_ON_ERROR
            );
        } catch (\Throwable $e) {
            echo json_encode(['ok' => false]);
        }
        exit;
    }
    if ($ajax === 'poll') {
        try {
            echo json_encode(akh_task_ajax_poll_editor($editor), JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            echo json_encode(['ok' => false]);
        }
        exit;
    }
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'bad_ajax']);
    exit;
}

$flash = '';
$error = '';
$openTicketId = trim((string) ($_GET['ticket'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!akh_csrf_verify($_POST['csrf_token'] ?? null)) {
        $error = 'Security check failed. Refresh and try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        $taskId = trim((string) ($_POST['task_id'] ?? ''));
        if ($action === 'thread_message' && $taskId !== '') {
            $err = akh_task_editor_append_thread($taskId, $editor, trim((string) ($_POST['thread_body'] ?? '')));
            if ($err !== null) {
                $error = $err;
            } else {
                header('Location: ' . base_path('editor/dashboard.php?ticket=' . rawurlencode($taskId)));
                exit;
            }
        } elseif ($action === 'claim' && $taskId !== '') {
            $t = akh_task_claim($taskId, $editor);
            if ($t === null) {
                $error = 'That task is no longer available to claim.';
            } else {
                $flash = 'Task assigned to you.';
            }
        } elseif ($action === 'status' && $taskId !== '') {
            $status = (string) ($_POST['status'] ?? '');
            $deliverable = trim((string) ($_POST['deliverable_output'] ?? ''));
            $t = akh_task_set_status($taskId, $editor, $status, $deliverable);
            if ($t === null) {
                $error = 'Could not update status (only the assigned editor can change it), or final output text is too long.';
            } else {
                $flash = 'Status updated.';
            }
        } elseif ($action === 'attendance_clock_in' && AKH_EDITOR_ATTENDANCE_ENABLED) {
            if (akh_editor_attendance_append($editor, 'clock_in')) {
                $flash = 'Clocked in.';
            } else {
                $error = 'Could not record clock-in.';
            }
        } elseif ($action === 'attendance_clock_out' && AKH_EDITOR_ATTENDANCE_ENABLED) {
            if (akh_editor_attendance_append($editor, 'clock_out')) {
                $flash = 'Clocked out.';
            } else {
                $error = 'Could not record clock-out.';
            }
        }
    }
}

$all = akh_tasks_all_sorted();
$newTasks = array_values(array_filter($all, static function (array $t): bool {
    return akh_task_editor_pool_eligible($t);
}));
$mine = array_values(array_filter($all, static function (array $t) use ($editor): bool {
    return ($t['assigned_editor'] ?? null) === $editor;
}));
$seenNew = akh_task_editor_seen_load()[strtolower($editor)] ?? [];
$editorBellCount = akh_task_editor_board_bell_count($editor);
$editorBoardSig = akh_task_poll_signature_all();
$editorBellNotices = akh_task_editor_notice_rows($editor);
$pageCsrf = akh_csrf_token();

$attendanceOn = AKH_EDITOR_ATTENDANCE_ENABLED && akh_editor_attendance_is_clocked_in($editor);
$attendanceSinceTs = AKH_EDITOR_ATTENDANCE_ENABLED ? akh_editor_attendance_open_shift_started_at_for($editor) : null;
$attendanceSinceLabel = $attendanceSinceTs !== null ? date('M j, g:i A', $attendanceSinceTs) : '';
$leavePendingCount = AKH_EDITOR_ATTENDANCE_ENABLED ? akh_editor_leave_pending_for_editor($editor) : 0;

require_once AKH_ROOT . '/includes/header.php';
?>

  <main id="main" class="portal-main portal-main--board">
    <div class="portal-card portal-card--tasks portal-card--ticketboard" id="editor-desk-updates">
      <header class="desk-head desk-head--editor">
        <div>
          <h1 class="portal-title" style="margin-bottom:0.35rem">Task board</h1>
          <p class="portal-lead" style="margin-bottom:0">Signed in as <strong><?php echo h($editor); ?></strong><?php if (AKH_EDITOR_ATTENDANCE_ENABLED): ?> — attendance: <?php echo $attendanceOn ? 'on shift since ' . h($attendanceSinceLabel) : 'not clocked in'; ?><?php endif; ?>. New jobs notify every editor until you open them; assigned tasks ring when the client replies or posts feedback.</p>
          <p class="portal-muted" style="margin:0.35rem 0 0;font-size:0.9rem"><a class="text-link" href="<?php echo h(base_path('editor/logout.php')); ?>">Sign out</a> ends your session<?php if (AKH_EDITOR_ATTENDANCE_ENABLED): ?> and clocks you out if you are still on shift<?php endif; ?>.</p>
        </div>
        <div class="desk-bell-wrap">
          <button
            type="button"
            class="desk-bell desk-bell--editor<?php echo $editorBellCount > 0 ? ' desk-bell--wiggle desk-bell--pop' : ' desk-bell--zero'; ?>"
            aria-expanded="false"
            aria-haspopup="true"
            title="Notifications — new pool tasks or client updates on your jobs"
          >
            <span class="desk-bell__icon" aria-hidden="true">
              <svg width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M12 22a2 2 0 002-2H10a2 2 0 002 2zm6-6V11a6 6 0 10-12 0v5l-2 2v1h16v-1l-2-2z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/>
              </svg>
            </span>
            <span class="desk-bell__count"><?php echo (int) $editorBellCount; ?></span>
            <span class="visually-hidden"><?php echo (int) $editorBellCount; ?> notifications (new pool or your tasks)</span>
          </button>
          <div class="desk-bell-dropdown" id="editor-desk-bell-dropdown" hidden></div>
        </div>
      </header>

      <?php if ($flash !== ''): ?>
        <p class="banner banner--ok" role="status"><?php echo h($flash); ?></p>
      <?php endif; ?>
      <?php if ($error !== ''): ?>
        <p class="banner banner--err" role="alert"><?php echo h($error); ?></p>
      <?php endif; ?>

      <?php if (AKH_EDITOR_ATTENDANCE_ENABLED): ?>
        <section class="portal-section" aria-labelledby="att-heading" style="margin-top:0.5rem">
          <h2 id="att-heading" class="portal-section__title">Attendance</h2>
          <p class="portal-muted" style="margin-top:-0.35rem">Clock in when you start working and clock out when you finish (signing out also records clock-out if you are still on shift).</p>
          <div style="display:flex;flex-wrap:wrap;gap:0.5rem;align-items:center;margin-top:0.5rem">
            <?php if (!$attendanceOn): ?>
              <form method="post" action="">
                <input type="hidden" name="csrf_token" value="<?php echo h($pageCsrf); ?>" />
                <input type="hidden" name="action" value="attendance_clock_in" />
                <button type="submit" class="btn btn--primary btn--sm">Clock in</button>
              </form>
            <?php else: ?>
              <form method="post" action="">
                <input type="hidden" name="csrf_token" value="<?php echo h($pageCsrf); ?>" />
                <input type="hidden" name="action" value="attendance_clock_out" />
                <button type="submit" class="btn btn--ghost btn--sm">Clock out</button>
              </form>
            <?php endif; ?>
            <?php if (AKH_EDITOR_ATTENDANCE_ENABLED): ?>
              <a class="btn btn--ghost btn--sm" href="<?php echo h(base_path('editor/leave.php')); ?>">Apply leave</a>
            <?php endif; ?>
            <?php if ($leavePendingCount > 0): ?>
              <span class="portal-muted" style="font-size:0.85rem"><?php echo (int) $leavePendingCount; ?> leave request(s) pending approval</span>
            <?php endif; ?>
          </div>
        </section>
      <?php endif; ?>

      <section class="portal-section" aria-labelledby="new-heading">
        <h2 id="new-heading" class="portal-section__title">New — assign to yourself</h2>
        <?php if ($newTasks === []): ?>
          <p class="portal-muted">No unassigned tasks right now.</p>
        <?php else: ?>
          <p class="portal-muted" style="margin-top:-0.5rem">Open a row once to clear it from the <strong>new</strong> bell count for you.</p>
          <div class="ticket-board">
            <?php foreach ($newTasks as $t): ?>
              <?php
              $tid = (string) ($t['id'] ?? '');
              $dm = (string) ($t['delivery_mode'] ?? '');
              $headline = (string) ($t['title'] ?? '');
              $isOpen = $openTicketId !== '' && $openTicketId === $tid;
              $st = (string) ($t['status'] ?? 'new');
              $stSlug = preg_replace('/[^a-z_]/', '', $st);
              $unseenNew = $tid !== '' && !in_array($tid, $seenNew, true);
              ?>
              <details
                class="ticket ticket--st-<?php echo h($stSlug); ?><?php echo $unseenNew ? ' ticket--new-unseen' : ''; ?>"
                <?php echo akh_task_ticket_style_attr($t); ?>
                id="ticket-<?php echo h($tid); ?>"
                data-task-id="<?php echo h($tid); ?>"
                <?php if ($unseenNew): ?>data-ack-new="1"<?php endif; ?>
                <?php echo $isOpen ? ' open' : ''; ?>
              >
                <summary class="ticket__summary ticket__summary--bar">
                  <span class="ticket__id" title="Task ID"><?php echo h($tid); ?></span>
                  <span class="ticket__headline"><?php echo h($headline !== '' ? $headline : '—'); ?></span>
                  <span class="ticket__meta">
                    <?php if ($unseenNew): ?>
                      <span class="ticket__pill ticket__pill--new">New for you</span>
                    <?php endif; ?>
                    <span class="ticket__meta-muted"><?php echo h((string) ($t['client_username'] ?? '')); ?></span>
                    <?php if (akh_task_is_bundle_child($t)): ?>
                      <span class="ticket__pill ticket__pill--new" title="Child task of a multi-type client job">Part of <?php echo h((string) ($t['parent_task_id'] ?? '')); ?></span>
                    <?php endif; ?>
                    <span class="ticket__meta-muted"><?php echo h(akh_task_delivery_mode_label($dm)); ?></span>
                    <span class="ticket__meta-muted"><?php echo h((string) ($t['created_at'] ?? '')); ?></span>
                  </span>
                </summary>
                <div class="ticket__body">
                  <div class="ticket__split ticket__split--solo">
                    <div class="ticket__main">
                      <dl class="ticket__dl">
                        <div><dt>Client</dt><dd><?php echo h((string) ($t['client_username'] ?? '—')); ?></dd></div>
                        <div><dt>Delivery</dt><dd><?php echo h(akh_task_delivery_mode_label($dm)); ?></dd></div>
                        <div><dt>Created</dt><dd><?php echo h((string) ($t['created_at'] ?? '—')); ?></dd></div>
                      </dl>
                      <?php if (trim((string) ($t['description'] ?? '')) !== ''): ?>
                        <div class="ticket__block">
                          <h3 class="ticket__block-title">Brief &amp; notes</h3>
                          <div class="ticket__prose"><?php echo nl2br(h((string) ($t['description'] ?? ''))); ?></div>
                        </div>
                      <?php endif; ?>
                      <?php if (trim((string) ($t['reference_link'] ?? '')) !== ''): ?>
                        <p class="ticket__line"><a class="text-link" href="<?php echo h((string) $t['reference_link']); ?>" target="_blank" rel="noopener noreferrer">Reference / style</a></p>
                      <?php endif; ?>
                      <?php if ($dm === 'google_drive' && trim((string) ($t['drive_link'] ?? '')) !== ''): ?>
                        <p class="ticket__line"><a class="text-link" href="<?php echo h((string) $t['drive_link']); ?>" target="_blank" rel="noopener noreferrer">Client Drive link</a></p>
                      <?php endif; ?>
                      <form class="ticket__inline-form" method="post" action="">
                        <input type="hidden" name="csrf_token" value="<?php echo h($pageCsrf); ?>" />
                        <input type="hidden" name="action" value="claim" />
                        <input type="hidden" name="task_id" value="<?php echo h($tid); ?>" />
                        <button type="submit" class="btn btn--primary btn--sm">Assign to me</button>
                      </form>
                    </div>
                  </div>
                </div>
              </details>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>

      <section class="portal-section" aria-labelledby="mine-heading">
        <h2 id="mine-heading" class="portal-section__title">My tasks</h2>
        <?php if ($mine === []): ?>
          <p class="portal-muted">You have no assigned tasks yet.</p>
        <?php else: ?>
          <p class="portal-muted" style="margin-top:-0.5rem">Opening a task clears unread markers for you. Use the message column for quick back-and-forth with the client.</p>
          <div class="ticket-board">
            <?php foreach ($mine as $t): ?>
              <?php
              $tid = (string) ($t['id'] ?? '');
              $st = (string) ($t['status'] ?? 'assigned');
              $dm = (string) ($t['delivery_mode'] ?? '');
              $headline = (string) ($t['title'] ?? '');
              $stSlug = preg_replace('/[^a-z_]/', '', $st);
              $notify = ($t['editor_feedback_notify'] ?? false) === true;
              $isOpen = $openTicketId !== '' && $openTicketId === $tid;
              $opts = ['assigned', 'in_progress', 'review', 'delivered', 'reverted', 'closed'];
              ?>
              <details
                class="ticket ticket--st-<?php echo h($stSlug); ?><?php echo $notify ? ' ticket--notify' : ''; ?>"
                <?php echo akh_task_ticket_style_attr($t); ?>
                id="ticket-<?php echo h($tid); ?>"
                data-task-id="<?php echo h($tid); ?>"
                <?php if ($notify): ?>data-ack-editor="1"<?php endif; ?>
                <?php echo $isOpen ? ' open' : ''; ?>
              >
                <summary class="ticket__summary ticket__summary--bar">
                  <span class="ticket__id" title="Task ID"><?php echo h($tid); ?></span>
                  <span class="ticket__headline">
                    <?php if ($notify): ?>
                      <span class="ticket__notify-dot" aria-label="Unread">●</span>
                    <?php endif; ?>
                    <?php echo h($headline !== '' ? $headline : '—'); ?>
                  </span>
                  <span class="ticket__meta">
                    <span class="task-badge task-badge--<?php echo h($stSlug); ?>"><?php echo h(akh_task_status_label($st)); ?></span>
                    <span class="ticket__meta-muted"><?php echo h((string) ($t['client_username'] ?? '')); ?></span>
                    <?php if (akh_task_is_bundle_child($t)): ?>
                      <span class="ticket__pill ticket__pill--new" title="Child task of a multi-type client job">Part of <?php echo h((string) ($t['parent_task_id'] ?? '')); ?></span>
                    <?php endif; ?>
                    <span class="ticket__meta-muted"><?php echo h((string) ($t['updated_at'] ?? '')); ?></span>
                  </span>
                </summary>
                <div class="ticket__body">
                  <div class="ticket__split">
                    <div class="ticket__main">
                      <dl class="ticket__dl">
                        <div><dt>Client</dt><dd><?php echo h((string) ($t['client_username'] ?? '—')); ?></dd></div>
                        <div><dt>Status</dt><dd><span class="task-badge task-badge--<?php echo h($stSlug); ?>"><?php echo h(akh_task_status_label($st)); ?></span></dd></div>
                        <div><dt>Updated</dt><dd><?php echo h((string) ($t['updated_at'] ?? '—')); ?></dd></div>
                      </dl>
                      <?php if (trim((string) ($t['description'] ?? '')) !== ''): ?>
                        <div class="ticket__block">
                          <h3 class="ticket__block-title">Brief &amp; notes</h3>
                          <div class="ticket__prose"><?php echo nl2br(h((string) ($t['description'] ?? ''))); ?></div>
                        </div>
                      <?php endif; ?>
                      <?php if (trim((string) ($t['reference_link'] ?? '')) !== ''): ?>
                        <p class="ticket__line"><a class="text-link" href="<?php echo h((string) $t['reference_link']); ?>" target="_blank" rel="noopener noreferrer">Reference / style</a></p>
                      <?php endif; ?>
                      <?php if ($dm === 'google_drive' && trim((string) ($t['drive_link'] ?? '')) !== ''): ?>
                        <p class="ticket__line"><a class="text-link" href="<?php echo h((string) $t['drive_link']); ?>" target="_blank" rel="noopener noreferrer">Client Drive link</a></p>
                      <?php endif; ?>
                      <?php if (trim((string) ($t['client_feedback'] ?? '')) !== '' || trim((string) ($t['client_meeting_date'] ?? '')) !== ''): ?>
                        <div class="ticket__block ticket__block--clientfb">
                          <h3 class="ticket__block-title">Client after delivery</h3>
                          <?php if (trim((string) ($t['client_feedback'] ?? '')) !== ''): ?>
                            <div class="ticket__prose"><?php echo nl2br(h((string) $t['client_feedback'])); ?></div>
                          <?php endif; ?>
                          <?php if (trim((string) ($t['client_meeting_date'] ?? '')) !== ''): ?>
                            <p class="ticket__line portal-muted" style="margin-bottom:0"><strong>Meeting:</strong> <?php echo h((string) $t['client_meeting_date']); ?>
                              <?php if (trim((string) ($t['client_meeting_link'] ?? '')) !== ''): ?>
                                — <a class="text-link" href="<?php echo h((string) $t['client_meeting_link']); ?>" target="_blank" rel="noopener noreferrer">Google Meet</a>
                              <?php endif; ?>
                            </p>
                          <?php endif; ?>
                        </div>
                      <?php endif; ?>
                      <form class="portal-form portal-form--compact ticket__form" method="post" action="">
                        <input type="hidden" name="csrf_token" value="<?php echo h($pageCsrf); ?>" />
                        <input type="hidden" name="action" value="status" />
                        <input type="hidden" name="task_id" value="<?php echo h($tid); ?>" />
                        <h3 class="ticket__block-title">Status &amp; deliverable</h3>
                        <label class="field" style="margin-top:0">
                          <span>Workflow status</span>
                          <select name="status" aria-label="Task status">
                            <?php foreach ($opts as $o): ?>
                              <option value="<?php echo h($o); ?>"<?php echo $o === $st ? ' selected' : ''; ?>><?php echo h(akh_task_status_label($o)); ?></option>
                            <?php endforeach; ?>
                          </select>
                        </label>
                        <label class="field">
                          <span>Final output link or path (saved with status; clients see it when you mark <strong>Delivered</strong>)</span>
                          <textarea name="deliverable_output" rows="3" maxlength="4000" placeholder="Drive link, Vimeo, WeTransfer, or server path…"><?php echo h((string) ($t['deliverable_output'] ?? '')); ?></textarea>
                        </label>
                        <p class="portal-note">Changing status or the deliverable field notifies the client (bell on their dashboard).</p>
                        <button type="submit" class="btn btn--primary btn--sm">Save status</button>
                      </form>
                    </div>
                    <?php akh_render_task_thread_panel($t, 'editor', $pageCsrf); ?>
                  </div>
                </div>
              </details>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>

      <p class="portal-foot">
        <a class="text-link" href="<?php echo h(base_path('editor/logout.php')); ?>">Sign out</a>
        ·
        <a class="text-link" href="<?php echo h(base_path('index.php')); ?>">Website home</a>
      </p>
    </div>
  </main>
  <?php
  $akhPushJs = AKH_ROOT . '/assets/js/portal-push-notify.js';
  $akhPushVer = is_file($akhPushJs) ? (string) filemtime($akhPushJs) : '1';
  ?>
  <script>
    window._akhPortalPush = {
      mode: 'editor',
      siteName: <?php echo json_encode(SITE_NAME, JSON_THROW_ON_ERROR); ?>,
      csrf: <?php echo json_encode($pageCsrf, JSON_THROW_ON_ERROR); ?>,
      bell: <?php echo (int) $editorBellCount; ?>,
      pool: <?php echo (int) count($newTasks); ?>,
      sig: <?php echo json_encode($editorBoardSig, JSON_THROW_ON_ERROR); ?>,
      notices: <?php echo json_encode($editorBellNotices, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES); ?>
    };
  </script>
  <script defer src="<?php echo h(base_path('assets/js/portal-push-notify.js')); ?>?v=<?php echo h($akhPushVer); ?>"></script>
  <script>
    (function () {
      var csrf = <?php echo json_encode($pageCsrf, JSON_THROW_ON_ERROR); ?>;
      var BellKey = 'akh_editor_bell_last';
      function playNotifyPing() {
        try {
          var Ctx = window.AudioContext || window.webkitAudioContext;
          if (!Ctx) return;
          var ctx = new Ctx();
          var o = ctx.createOscillator();
          var g = ctx.createGain();
          o.type = 'sine';
          o.frequency.value = 784;
          g.gain.setValueAtTime(0.0001, ctx.currentTime);
          g.gain.exponentialRampToValueAtTime(0.07, ctx.currentTime + 0.02);
          g.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.18);
          o.connect(g);
          g.connect(ctx.destination);
          o.start(ctx.currentTime);
          o.stop(ctx.currentTime + 0.2);
          setTimeout(function () { ctx.close(); }, 300);
        } catch (e) {}
      }
      (function bellSoundOnIncrease() {
        var n = <?php echo (int) $editorBellCount; ?>;
        var last = parseInt(sessionStorage.getItem(BellKey) || '-1', 10);
        if (last >= 0 && n > last) {
          playNotifyPing();
        }
        sessionStorage.setItem(BellKey, String(n));
      })();
      function setDeskBell(n) {
        var b = document.querySelector('.desk-bell-wrap .desk-bell');
        if (!b) return;
        var c = b.querySelector('.desk-bell__count');
        if (c && typeof n === 'number') {
          c.textContent = String(n);
          sessionStorage.setItem(BellKey, String(n));
        }
        b.classList.toggle('desk-bell--zero', typeof n === 'number' && n < 1);
        if (typeof n === 'number' && n > 0) {
          b.classList.add('desk-bell--wiggle', 'desk-bell--pop');
        } else {
          b.classList.remove('desk-bell--wiggle', 'desk-bell--pop');
        }
      }
      function postAck(kind, taskId) {
        var fd = new URLSearchParams();
        fd.set('ajax_action', 'view_ack');
        fd.set('ack_kind', kind);
        fd.set('task_id', taskId);
        fd.set('csrf_token', csrf);
        return fetch(window.location.pathname, {
          method: 'POST',
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
          body: fd
        }).then(function (r) { return r.json(); });
      }
      function ackOpenedEditor(el) {
        if (!el || !el.open) return;
        var tid = el.getAttribute('data-task-id');
        if (!tid) return;
        if (el.getAttribute('data-ack-new') === '1') {
          el.removeAttribute('data-ack-new');
          el.classList.remove('ticket--new-unseen');
          var pill = el.querySelector('.ticket__pill--new');
          if (pill) pill.remove();
          postAck('new', tid).then(function (j) {
            if (j && j.ok && typeof j.bell === 'number') setDeskBell(j.bell);
          }).catch(function () {});
        }
        if (el.getAttribute('data-ack-editor') === '1') {
          el.removeAttribute('data-ack-editor');
          el.classList.remove('ticket--notify');
          var dot = el.querySelector('.ticket__notify-dot');
          if (dot) dot.remove();
          postAck('editor_task', tid).then(function (j) {
            if (j && j.ok && typeof j.bell === 'number') setDeskBell(j.bell);
          }).catch(function () {});
        }
      }
      document.addEventListener('toggle', function (e) {
        var el = e.target;
        if (!el || !el.classList || !el.classList.contains('ticket') || !el.open) return;
        ackOpenedEditor(el);
      }, true);
      document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('details.ticket[open]').forEach(ackOpenedEditor);
      });
    })();
  </script>

<?php require_once AKH_ROOT . '/includes/footer.php'; ?>
