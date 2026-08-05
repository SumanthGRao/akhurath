<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once AKH_ROOT . '/includes/editor-auth.php';
require_once AKH_ROOT . '/includes/editor-attendance.php';
require_once AKH_ROOT . '/includes/editor-leave.php';
require_once AKH_ROOT . '/includes/tasks.php';
require_once AKH_ROOT . '/includes/dashboard-alerts.php';
require_once AKH_ROOT . '/includes/whatsapp-tasks.php';
require_once AKH_ROOT . '/includes/task-thread-panel.php';
require_once AKH_ROOT . '/includes/editor-dashboard-api.php';
require_once AKH_ROOT . '/includes/csrf.php';

akh_require_editor();

$pageTitle = 'Editor tasks — ' . SITE_NAME;
$metaDescription = 'Assign and update client tasks.';
$bodyClass = 'page-portal page-portal--board page-editor-desk';

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
    if ($ajax === 'desk_panel') {
        $taskId = trim((string) ($_POST['task_id'] ?? ''));
        try {
            echo json_encode([
                'ok' => true,
                'task_id' => $taskId,
                'html' => akh_editor_desk_panel_html($editor, $taskId, akh_csrf_token()),
            ], JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            echo json_encode(['ok' => false]);
        }
        exit;
    }
    if ($ajax === 'thread_poll') {
        $taskId = trim((string) ($_POST['task_id'] ?? ''));
        try {
            echo json_encode(akh_editor_desk_thread_poll($editor, $taskId), JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            echo json_encode(['ok' => false]);
        }
        exit;
    }
    if ($ajax === 'end_chat') {
        require_once AKH_ROOT . '/includes/whatsapp-sessions.php';
        $taskId = trim((string) ($_POST['task_id'] ?? ''));
        try {
            echo json_encode(akh_editor_end_whatsapp_chat($editor, $taskId), JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            error_log('end_chat: ' . $e->getMessage());
            echo json_encode(['ok' => false, 'error' => 'Could not end chat.']);
        }
        exit;
    }
    if ($ajax === 'thread_send') {
        $taskId = trim((string) ($_POST['task_id'] ?? ''));
        $body = trim((string) ($_POST['thread_body'] ?? ''));
        $err = akh_task_editor_append_thread($taskId, $editor, $body);
        if ($err !== null) {
            echo json_encode(['ok' => false, 'error' => $err]);
            exit;
        }
        try {
            $tAfter = akh_task_by_id($taskId);
            echo json_encode([
                'ok' => true,
                'task_id' => $taskId,
                'html' => akh_editor_desk_panel_html($editor, $taskId, akh_csrf_token()),
                'msg_sig' => is_array($tAfter) ? akh_task_merged_conversation_sig($tAfter) : '',
                'bell' => akh_task_editor_board_bell_count($editor),
            ], JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            echo json_encode(['ok' => true, 'task_id' => $taskId]);
        }
        exit;
    }
    if ($ajax === 'claim_task') {
        $taskId = trim((string) ($_POST['task_id'] ?? ''));
        $t = akh_task_claim($taskId, $editor);
        if ($t === null) {
            echo json_encode(['ok' => false, 'error' => 'That task is no longer available to claim.']);
            exit;
        }
        $taskId = (string) ($t['id'] ?? $taskId);
        try {
            echo json_encode([
                'ok' => true,
                'task_id' => $taskId,
                'html' => akh_editor_desk_panel_html($editor, $taskId, akh_csrf_token()),
                'desk' => akh_editor_desk_poll_bundle($editor),
                'bell' => akh_task_editor_board_bell_count($editor),
            ], JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            echo json_encode(['ok' => true, 'task_id' => $taskId]);
        }
        exit;
    }
    if ($ajax === 'status_save') {
        $taskId = trim((string) ($_POST['task_id'] ?? ''));
        $status = (string) ($_POST['status'] ?? '');
        $deliverable = trim((string) ($_POST['deliverable_output'] ?? ''));
        $statusComment = trim((string) ($_POST['status_comment'] ?? ''));
        $existing = akh_task_by_id($taskId);
        if ($deliverable === '' && is_array($existing)) {
            $deliverable = trim((string) ($existing['deliverable_output'] ?? ''));
        }
        $t = akh_task_set_status($taskId, $editor, $status, $deliverable, $statusComment);
        if ($t === null) {
            $waErr = akh_whatsapp_task_sync_last_error();
            if ($waErr !== '') {
                $msg = $waErr;
            } elseif (
                is_array($existing)
                && (string) ($existing['status'] ?? '') !== $status
                && $statusComment === ''
            ) {
                $msg = 'A status note is required when changing workflow status.';
            } else {
                $msg = 'Could not update status.';
            }
            echo json_encode(['ok' => false, 'error' => $msg]);
            exit;
        }
        $taskId = (string) ($t['id'] ?? $taskId);
        try {
            echo json_encode([
                'ok' => true,
                'task_id' => $taskId,
                'html' => akh_editor_desk_panel_html($editor, $taskId, akh_csrf_token()),
                'desk' => akh_editor_desk_poll_bundle($editor),
                'bell' => akh_task_editor_board_bell_count($editor),
            ], JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            echo json_encode(['ok' => true, 'task_id' => $taskId]);
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
            $statusComment = trim((string) ($_POST['status_comment'] ?? ''));
            $existing = akh_task_by_id($taskId);
            $t = akh_task_set_status($taskId, $editor, $status, $deliverable, $statusComment);
            if ($t === null) {
                $waErr = akh_whatsapp_task_sync_last_error();
                if ($waErr !== '') {
                    $error = $waErr;
                } elseif (
                    is_array($existing)
                    && (string) ($existing['status'] ?? '') !== $status
                    && $statusComment === ''
                ) {
                    $error = 'A status note is required when changing workflow status.';
                } else {
                    $error = 'Could not update status. Only the assigned editor can change it, or the final output text may be too long.';
                }
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

require_once AKH_ROOT . '/includes/meeting-requests.php';
akh_wa_sync_whatsapp_pool_to_studio_board();
akh_wa_sync_for_editor($editor);

$all = akh_tasks_all_sorted();
$newTasks = array_values(array_filter($all, static function (array $t): bool {
    return akh_task_editor_pool_eligible($t);
}));
usort($newTasks, static function (array $a, array $b): int {
    $cmp = strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
    if ($cmp !== 0) {
        return $cmp;
    }

    return strcmp((string) ($b['updated_at'] ?? ''), (string) ($a['updated_at'] ?? ''));
});
$mine = array_values(array_filter($all, static function (array $t) use ($editor): bool {
    return strtolower(trim((string) ($t['assigned_editor'] ?? ''))) === strtolower(trim($editor));
}));
require_once AKH_ROOT . '/includes/dashboard-alerts.php';
$dashboardAlerts = akh_dashboard_alerts_for_editor($editor);
$mineIds = [];
foreach ($mine as $t) {
    $nid = akh_task_normalize_id((string) ($t['id'] ?? ''));
    if ($nid !== '') {
        $mineIds[$nid] = true;
    }
}
foreach (array_keys($dashboardAlerts) as $alertTaskId) {
    if (isset($mineIds[$alertTaskId])) {
        continue;
    }
    $extra = akh_task_notification_editor_board_row($alertTaskId, $editor);
    if (is_array($extra)) {
        $mine[] = $extra;
        $mineIds[$alertTaskId] = true;
    }
}
usort($mine, static function (array $a, array $b) use ($dashboardAlerts): int {
    $aid = akh_task_normalize_id((string) ($a['id'] ?? ''));
    $bid = akh_task_normalize_id((string) ($b['id'] ?? ''));
    $aa = $dashboardAlerts[$aid] ?? null;
    $ab = $dashboardAlerts[$bid] ?? null;
    $pa = is_array($aa) ? (int) ($aa['priority'] ?? 0) : 0;
    $pb = is_array($ab) ? (int) ($ab['priority'] ?? 0) : 0;
    if ($pa !== $pb) {
        return $pb <=> $pa;
    }
    if ($pa > 0 && $pb > 0) {
        $ta = (string) ($aa['created_at'] ?? '');
        $tb = (string) ($ab['created_at'] ?? '');
        $cmp = strcmp($tb, $ta);
        if ($cmp !== 0) {
            return $cmp;
        }
    }

    return strcmp((string) ($b['updated_at'] ?? ''), (string) ($a['updated_at'] ?? ''));
});
$seenNew = akh_task_editor_seen_load()[strtolower($editor)] ?? [];
$editorBellCount = akh_task_editor_board_bell_count($editor);
$editorBoardSig = akh_task_poll_signature_all();
$editorBellNotices = akh_task_editor_notice_rows($editor);
$pageCsrf = akh_csrf_token();
require_once AKH_ROOT . '/includes/meeting-requests.php';
$editorMeetingRows = akh_meeting_request_scheduled_for_editor($editor);
$editorMeetingCount = count($editorMeetingRows);
$editorReminders = akh_meeting_request_upcoming_reminders_for_editor($editor);
$editorReminderCodes = [];
foreach ($editorReminders as $r) {
    $c = akh_task_normalize_id((string) ($r['task_code'] ?? ''));
    if ($c !== '') {
        $editorReminderCodes[$c] = true;
    }
}
$meetJsVer = is_file(AKH_ROOT . '/assets/js/meeting-alerts.js') ? (string) filemtime(AKH_ROOT . '/assets/js/meeting-alerts.js') : '1';

$attendanceOn = AKH_EDITOR_ATTENDANCE_ENABLED && akh_editor_attendance_is_clocked_in($editor);
$attendanceSinceTs = AKH_EDITOR_ATTENDANCE_ENABLED ? akh_editor_attendance_open_shift_started_at_for($editor) : null;
$leavePendingCount = AKH_EDITOR_ATTENDANCE_ENABLED ? akh_editor_leave_pending_for_editor($editor) : 0;

require_once AKH_ROOT . '/includes/editor-dashboard-render.php';
$edeskCssVer = is_file(AKH_ROOT . '/assets/css/editor-dashboard.css') ? (string) filemtime(AKH_ROOT . '/assets/css/editor-dashboard.css') : '1';
$edeskJsVer = is_file(AKH_ROOT . '/assets/js/editor-dashboard.js') ? (string) filemtime(AKH_ROOT . '/assets/js/editor-dashboard.js') : '1';
$defaultDeskTab = $mine !== [] ? 'mine' : 'pool';
if ($openTicketId !== '') {
    foreach ($newTasks as $t) {
        if (akh_task_ids_match((string) ($t['id'] ?? ''), $openTicketId)) {
            $defaultDeskTab = 'pool';
            break;
        }
    }
}

require_once AKH_ROOT . '/includes/header.php';
?>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Plus+Jakarta+Sans:wght@600;700;800&family=Sometype+Mono:wght@400;500&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="<?php echo h(base_path('assets/css/editor-dashboard.css')); ?>?v=<?php echo h($edeskCssVer); ?>" />

  <main id="main" class="portal-main portal-main--board">
    <div
      class="portal-card portal-card--tasks portal-card--ticketboard edesk"
      id="editor-desk"
      data-csrf="<?php echo h($pageCsrf); ?>"
      data-bell="<?php echo (int) $editorBellCount; ?>"
      data-default-tab="<?php echo h($defaultDeskTab); ?>"
      data-timezone="<?php echo h(AKH_SITE_TIMEZONE); ?>"
    >
      <header class="edesk-topbar">
        <div class="edesk-topbar__brand">
          <p class="edesk-topbar__kicker"><?php echo h(SITE_NAME); ?></p>
          <h1 class="edesk-topbar__title">Editor desk</h1>
          <p class="edesk-topbar__user"><?php echo h($editor); ?><?php if (AKH_EDITOR_ATTENDANCE_ENABLED): ?> · <?php echo $attendanceOn ? 'On shift since ' . h(akh_format_datetime_site_short((string) $attendanceSinceTs)) : 'Not clocked in'; ?><?php endif; ?> · <?php echo h(AKH_SITE_TIMEZONE === 'Asia/Kolkata' ? 'IST' : AKH_SITE_TIMEZONE); ?></p>
        </div>
        <nav class="edesk-topbar__tabs" aria-label="Task lists">
          <button type="button" class="edesk-tab" data-section="pool" aria-selected="<?php echo $defaultDeskTab === 'pool' ? 'true' : 'false'; ?>">
            Pool
            <?php if (count($newTasks) > 0): ?>
              <span class="edesk-tab__badge"><?php echo count($newTasks); ?></span>
            <?php endif; ?>
          </button>
          <button type="button" class="edesk-tab" data-section="mine" aria-selected="<?php echo $defaultDeskTab === 'mine' ? 'true' : 'false'; ?>">
            My tasks
            <?php if (count($mine) > 0): ?>
              <span class="edesk-tab__badge"><?php echo count($mine); ?></span>
            <?php endif; ?>
          </button>
          <button type="button" class="edesk-tab" data-section="meetings" aria-selected="false">
            Meetings
            <?php if ($editorMeetingCount > 0): ?>
              <span class="edesk-tab__badge"><?php echo $editorMeetingCount; ?></span>
            <?php endif; ?>
          </button>
        </nav>
        <div class="edesk-topbar__actions">
          <span class="edesk-live" id="edesk-live" title="Board refreshes automatically">
            <span class="edesk-live__dot" aria-hidden="true"></span>
            <span class="edesk-live__label">Live</span>
            <span class="edesk-live__time" id="edesk-live-time">syncing…</span>
          </span>
          <?php if (AKH_EDITOR_ATTENDANCE_ENABLED): ?>
            <span class="edesk-att" title="Attendance">
              <span class="edesk-att__dot<?php echo $attendanceOn ? ' edesk-att__dot--on' : ''; ?>" aria-hidden="true"></span>
              <?php if (!$attendanceOn): ?>
                <form method="post" action="" class="edesk-att-form">
                  <input type="hidden" name="csrf_token" value="<?php echo h($pageCsrf); ?>" />
                  <input type="hidden" name="action" value="attendance_clock_in" />
                  <button type="submit" class="btn btn--primary btn--sm">Clock in</button>
                </form>
              <?php else: ?>
                <form method="post" action="" class="edesk-att-form">
                  <input type="hidden" name="csrf_token" value="<?php echo h($pageCsrf); ?>" />
                  <input type="hidden" name="action" value="attendance_clock_out" />
                  <button type="submit" class="btn btn--ghost btn--sm">Clock out</button>
                </form>
              <?php endif; ?>
              <a class="btn btn--ghost btn--sm" href="<?php echo h(base_path('editor/leave.php')); ?>">Leave</a>
            </span>
          <?php endif; ?>
          <?php require_once AKH_ROOT . '/includes/theme-mode.php'; akh_theme_mode_toggle(); ?>
          <div class="desk-bell-wrap">
            <button
              type="button"
              class="desk-bell desk-bell--editor<?php echo $editorBellCount > 0 ? ' desk-bell--wiggle desk-bell--pop' : ' desk-bell--zero'; ?>"
              aria-expanded="false"
              aria-haspopup="true"
              title="Notifications"
            >
              <span class="desk-bell__icon" aria-hidden="true">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                  <path d="M12 22a2 2 0 002-2H10a2 2 0 002 2zm6-6V11a6 6 0 10-12 0v5l-2 2v1h16v-1l-2-2z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/>
                </svg>
              </span>
              <span class="desk-bell__count"><?php echo (int) $editorBellCount; ?></span>
              <span class="visually-hidden"><?php echo (int) $editorBellCount; ?> notifications</span>
            </button>
            <div class="desk-bell-dropdown" id="editor-desk-bell-dropdown" hidden></div>
          </div>
          <a class="btn btn--ghost btn--sm" href="<?php echo h(base_path('editor/logout.php')); ?>">Sign out</a>
        </div>
      </header>

      <?php if ($flash !== '' || $error !== ''): ?>
        <div class="edesk-banners">
          <?php if ($flash !== ''): ?>
            <p class="edesk-banner edesk-banner--ok" role="status"><?php echo h($flash); ?></p>
          <?php endif; ?>
          <?php if ($error !== ''): ?>
            <p class="edesk-banner edesk-banner--err" role="alert"><?php echo h($error); ?></p>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <div class="edesk-body">
        <aside class="edesk-sidebar" aria-label="Task list">
          <div class="edesk-sidebar__tools">
            <input type="search" class="edesk-search" id="edesk-search" placeholder="Search tasks…" aria-label="Search tasks" />
          </div>
          <div class="edesk-filters" role="group" aria-label="Filter tasks">
            <label class="edesk-status-filter-wrap">
              <span class="edesk-status-filter__label">Status</span>
              <select class="edesk-status-filter" id="edesk-status-filter" aria-label="Filter by status">
                <option value="all">All</option>
              </select>
            </label>
          </div>
          <p class="edesk-sidebar__hint" id="edesk-sidebar-hint">Select a task to view brief, updates, and messages.</p>
          <div class="edesk-list-wrap">
            <div class="edesk-list" id="edesk-list-pool" role="list"<?php echo $defaultDeskTab === 'pool' ? '' : ' hidden'; ?>>
              <?php if ($newTasks === []): ?>
                <p class="edesk-list__empty">No unassigned tasks in the pool.</p>
              <?php else: ?>
                <?php foreach ($newTasks as $t): ?>
                  <?php
                  $vm = akh_editor_task_view_model($t, $editor, $dashboardAlerts, $editorReminderCodes, $seenNew, 'pool');
                  $sel = $openTicketId !== '' && akh_task_ids_match($openTicketId, (string) $vm['tid']);
                  akh_editor_render_list_item($vm, $sel);
                  ?>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
            <div class="edesk-list" id="edesk-list-mine" role="list"<?php echo $defaultDeskTab === 'mine' ? '' : ' hidden'; ?>>
              <?php if ($mine === []): ?>
                <p class="edesk-list__empty">No assigned tasks yet — claim one from the pool.</p>
              <?php else: ?>
                <?php foreach ($mine as $t): ?>
                  <?php
                  $vm = akh_editor_task_view_model($t, $editor, $dashboardAlerts, $editorReminderCodes, $seenNew, 'mine');
                  $sel = $openTicketId !== '' && akh_task_ids_match($openTicketId, (string) $vm['tid']);
                  akh_editor_render_list_item($vm, $sel);
                  ?>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
            <div class="edesk-list edesk-list--meetings" id="edesk-list-meetings" role="list" hidden>
              <?php if ($editorMeetingRows === []): ?>
                <p class="edesk-list__empty">No upcoming meetings scheduled.</p>
              <?php else: ?>
                <?php foreach ($editorMeetingRows as $desk): ?>
                  <?php
                  $mSel = $openTicketId !== '' && akh_task_ids_match($openTicketId, (string) ($desk['task_code'] ?? ''));
                  akh_editor_render_meeting_list_item($desk, $mSel);
                  ?>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>
        </aside>

        <section class="edesk-detail" aria-label="Task detail">
          <div class="edesk-detail__toolbar">
            <button type="button" class="edesk-detail__back">← All tasks</button>
          </div>
          <div class="edesk-detail__scroll" id="edesk-detail-scroll">
            <div class="edesk-empty" id="edesk-empty"<?php echo $openTicketId !== '' ? ' hidden' : ''; ?>>
              <h2 class="edesk-empty__title">Select a task</h2>
              <p>Pick a job from the list to read the brief, client updates, and messages — everything stays on one screen.</p>
            </div>
            <?php
            $renderedPanels = [];
            foreach ($mine as $t) {
                $tid = (string) ($t['id'] ?? '');
                if ($tid === '' || isset($renderedPanels[$tid])) {
                    continue;
                }
                $renderedPanels[$tid] = true;
                $vm = akh_editor_task_view_model($t, $editor, $dashboardAlerts, $editorReminderCodes, $seenNew, 'mine');
                akh_editor_render_detail_panel($vm, $pageCsrf);
            }
            foreach ($newTasks as $t) {
                $tid = (string) ($t['id'] ?? '');
                if ($tid === '' || isset($renderedPanels[$tid])) {
                    continue;
                }
                $renderedPanels[$tid] = true;
                $vm = akh_editor_task_view_model($t, $editor, $dashboardAlerts, $editorReminderCodes, $seenNew, 'pool');
                akh_editor_render_detail_panel($vm, $pageCsrf);
            }
            ?>
          </div>
        </section>
      </div>
      <div class="edesk-toasts" id="edesk-toasts" aria-live="polite" aria-atomic="true"></div>
    </div>
  </main>
  <?php require_once AKH_ROOT . '/includes/meeting-join-modal.php'; ?>
  <?php
  $akhPushJs = AKH_ROOT . '/assets/js/portal-push-notify.js';
  $akhPushVer = is_file($akhPushJs) ? (string) filemtime($akhPushJs) : '1';
  $deskAlertJs = AKH_ROOT . '/assets/js/desk-alert.js';
  $deskAlertVer = is_file($deskAlertJs) ? (string) filemtime($deskAlertJs) : '1';
  $deskChimeRel = 'assets/audio/desk-notify.ogg';
  $deskChimePath = AKH_ROOT . '/' . $deskChimeRel;
  $deskChimeUrl = akh_absolute_url($deskChimeRel);
  if (is_file($deskChimePath)) {
      $deskChimeUrl .= '?v=' . (string) filemtime($deskChimePath);
  }
  ?>
  <audio id="akh-desk-notify-chime" preload="auto" src="<?php echo h($deskChimeUrl); ?>" hidden playsinline></audio>
  <script>
    window._akhDeskNotify = {
      swUrl: <?php echo json_encode(base_path('sw/desk-notify.js'), JSON_THROW_ON_ERROR); ?>,
      swScope: <?php echo json_encode(base_path('sw/'), JSON_THROW_ON_ERROR); ?>,
      icon: <?php echo json_encode(akh_absolute_url('assets/images/brand/akhurath-favicon-192.png'), JSON_THROW_ON_ERROR); ?>,
      chimeUrl: <?php echo json_encode($deskChimeUrl, JSON_THROW_ON_ERROR); ?>
    };
    window._akhPortalPush = {
      mode: 'editor',
      siteName: <?php echo json_encode(SITE_NAME, JSON_THROW_ON_ERROR); ?>,
      csrf: <?php echo json_encode($pageCsrf, JSON_THROW_ON_ERROR); ?>,
      bell: <?php echo (int) $editorBellCount; ?>,
      pool: <?php echo (int) count($newTasks); ?>,
      sig: <?php echo json_encode($editorBoardSig, JSON_THROW_ON_ERROR); ?>,
      notify_sig: <?php echo json_encode(akh_dashboard_alerts_poll_signature(), JSON_THROW_ON_ERROR); ?>,
      notices: <?php echo json_encode($editorBellNotices, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES); ?>,
      reminders: <?php echo json_encode($editorReminders, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES); ?>
    };
  </script>
  <script src="<?php echo h(base_path('assets/js/desk-alert.js')); ?>?v=<?php echo h($deskAlertVer); ?>"></script>
  <script src="<?php echo h(base_path('assets/js/meeting-alerts.js')); ?>?v=<?php echo h($meetJsVer); ?>"></script>
  <script defer src="<?php echo h(base_path('assets/js/editor-dashboard.js')); ?>?v=<?php echo h($edeskJsVer); ?>"></script>
  <script defer src="<?php echo h(base_path('assets/js/portal-push-notify.js')); ?>?v=<?php echo h($akhPushVer); ?>"></script>
  <script>
    if (window.AkhMeetingAlerts && window._akhPortalPush) {
      AkhMeetingAlerts.init({ notifySig: '', reminders: window._akhPortalPush.reminders || [] });
    }
  </script>

<?php require_once AKH_ROOT . '/includes/footer.php'; ?>
