<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once AKH_ROOT . '/includes/whatsapp-dashboard-auth.php';
require_once AKH_ROOT . '/includes/whatsapp-tasks.php';
require_once AKH_ROOT . '/includes/dashboard-alerts.php';
require_once AKH_ROOT . '/includes/csrf.php';

akh_require_wa_dashboard();

$pageTitle = 'WhatsApp tasks — ' . SITE_NAME;
$metaDescription = 'WhatsApp wedding task dashboard.';
$bodyClass = 'page-wa-dashboard';

$dbError = '';
$initialTasks = [];
$initialCounts = array_fill_keys(akh_wa_task_statuses(), 0);
$editors = [];
$pollSig = 'empty';

try {
    if (!akh_wa_tasks_table_exists()) {
        $dbError = 'Table whatsapp_tasks was not found. Import sql/migrations/004_whatsapp_tasks.sql in phpMyAdmin.';
    } else {
        $initialTasks = akh_wa_tasks_list_for_dashboard();
        $initialCounts = akh_wa_task_status_counts();
        $editors = akh_wa_editors_for_select();
        $pollSig = akh_wa_tasks_poll_signature();
    }
} catch (Throwable $e) {
    $dbError = trim((string) $e->getMessage()) !== '' ? $e->getMessage() : 'Could not load tasks.';
}

$fStatus = trim((string) ($_GET['status'] ?? ''));
$fQ = trim((string) ($_GET['q'] ?? ''));

$csrf = akh_csrf_token();
$refreshSec = akh_wa_dashboard_refresh_seconds();
$apiUrl = base_path('whatsapp/api.php');
$waCssVer = is_file(AKH_ROOT . '/assets/css/whatsapp-dashboard.css') ? (string) filemtime(AKH_ROOT . '/assets/css/whatsapp-dashboard.css') : '';
$waJsVer = is_file(AKH_ROOT . '/assets/js/whatsapp-dashboard.js') ? (string) filemtime(AKH_ROOT . '/assets/js/whatsapp-dashboard.js') : '';

$tasksJson = [];
foreach ($initialTasks as $row) {
    $tasksJson[] = akh_wa_task_row_for_json($row, $editors);
}

$totalTasks = array_sum($initialCounts);
$userLabel = akh_wa_dashboard_current() ?? '';
$waNotify = ['count' => 0, 'alerts' => [], 'notices' => [], 'notify_sig' => 'missing', 'reminders' => [], 'meetings' => []];
$meetingReminders = [];
$waMeetings = [];
try {
    if (akh_meeting_requests_table_exists()) {
        $waNotify = akh_dashboard_notification_payload();
        $meetingReminders = $waNotify['reminders'] ?? [];
        $waMeetings = $waNotify['meetings'] ?? [];
    } elseif ($dbError === '') {
        $waNotify = akh_wa_client_notification_payload();
    }
} catch (Throwable $e) {
    error_log('whatsapp/index notify: ' . $e->getMessage());
    if ($dbError === '') {
        $dbError = 'Could not load notifications. Try refreshing — if this persists, contact support.';
    }
}
$meetJsVer = is_file(AKH_ROOT . '/assets/js/meeting-alerts.js') ? (string) filemtime(AKH_ROOT . '/assets/js/meeting-alerts.js') : '1';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <meta name="description" content="<?php echo h($metaDescription); ?>" />
  <title><?php echo h($pageTitle); ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400&family=Source+Sans+3:wght@300;400;500;600&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="<?php echo h(base_path('assets/css/whatsapp-dashboard.css') . ($waCssVer !== '' ? '?v=' . rawurlencode($waCssVer) : '')); ?>" />
</head>
<body class="<?php echo h($bodyClass); ?>">
  <a class="skip-link" href="#main">Skip to content</a>

  <header class="wa-topbar">
    <div class="wa-topbar__inner">
      <div class="wa-topbar__brand">
        <span class="wa-topbar__glyph" aria-hidden="true">WA</span>
        <div>
          <p class="wa-topbar__kicker">Akhurath Studio</p>
          <h1 class="wa-topbar__title">WhatsApp task board</h1>
        </div>
      </div>
      <div class="wa-topbar__actions">
        <div class="wa-bell-wrap">
          <button
            type="button"
            class="wa-bell<?php echo (int) ($waNotify['count'] ?? 0) > 0 ? ' wa-bell--active' : ''; ?>"
            id="wa-notify-bell"
            aria-expanded="false"
            aria-haspopup="true"
            aria-controls="wa-notify-dropdown"
            title="Unread client updates and meeting requests"
          >
            <span class="wa-bell__icon" aria-hidden="true">🔔</span>
            <span class="wa-bell__count" id="wa-notify-count"><?php echo (int) ($waNotify['count'] ?? 0); ?></span>
            <span class="visually-hidden"><?php echo (int) ($waNotify['count'] ?? 0); ?> unread updates</span>
          </button>
          <div class="wa-bell-dropdown" id="wa-notify-dropdown" hidden>
            <div class="wa-bell-dropdown__head">
              <strong>Updates</strong>
              <span class="wa-bell-dropdown__sub" id="wa-notify-dropdown-sub">Nothing new</span>
            </div>
            <ul class="wa-bell-dropdown__list" id="wa-notify-list"></ul>
            <div class="wa-bell-dropdown__foot">
              <button type="button" class="wa-btn wa-btn--ghost wa-btn--sm" id="wa-notify-mark-all" hidden>Mark all read</button>
            </div>
          </div>
        </div>
        <div class="wa-refresh" id="wa-refresh-indicator" aria-live="polite">
          <span class="wa-refresh__dot" aria-hidden="true"></span>
          <span class="wa-refresh__text">Next refresh in <strong id="wa-refresh-countdown"><?php echo (int) $refreshSec; ?></strong>s</span>
        </div>
        <button type="button" class="wa-btn wa-btn--ghost" id="wa-refresh-now">Refresh now</button>
        <span class="wa-topbar__user"><?php echo h($userLabel); ?></span>
        <a class="wa-btn wa-btn--ghost" href="<?php echo h(base_path('whatsapp/logout.php')); ?>">Sign out</a>
      </div>
    </div>
  </header>

  <main id="main" class="wa-main">
    <?php if ($dbError !== ''): ?>
      <p class="wa-banner wa-banner--err" role="alert"><?php echo h($dbError); ?></p>
    <?php else: ?>

    <?php if ($meetingReminders !== []): ?>
      <section class="wa-meeting-banner wa-meeting-banner--soon" id="wa-meeting-banner" aria-live="polite">
        <h2 class="wa-meeting-banner__title">⏰ Meeting starting soon</h2>
        <p class="wa-meeting-banner__hint">Join popup appears about 5 minutes before start. Use the Meet link below or open the <strong>Meetings</strong> tab.</p>
        <ul class="wa-meeting-banner__list">
          <?php foreach ($meetingReminders as $rem): ?>
            <?php
            $mCode = (string) ($rem['task_code'] ?? '');
            $mMins = (int) ($rem['minutes_until'] ?? 0);
            $mLink = trim((string) ($rem['meet_link'] ?? ''));
            $mBody = (string) ($rem['body'] ?? '');
            ?>
            <li class="wa-meeting-banner__item">
              <strong class="wa-meeting-banner__code"><?php echo h($mCode); ?></strong>
              <span class="wa-meeting-banner__text"><?php echo h($mBody !== '' ? $mBody : "Starts in {$mMins} min"); ?></span>
              <?php if ($mLink !== ''): ?>
                <a class="wa-btn wa-btn--sm wa-btn--ghost" href="<?php echo h($mLink); ?>" target="_blank" rel="noopener noreferrer">Join Meet</a>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      </section>
    <?php else: ?>
      <section class="wa-meeting-banner wa-meeting-banner--soon" id="wa-meeting-banner" hidden aria-live="polite"></section>
    <?php endif; ?>

    <nav class="wa-tabs" aria-label="Dashboard views">
      <button type="button" class="wa-tabs__btn is-active" data-wa-tab="tasks" id="wa-tab-tasks">Tasks</button>
      <button type="button" class="wa-tabs__btn" data-wa-tab="meetings" id="wa-tab-meetings">
        Meetings
        <?php if (count($waMeetings) > 0): ?>
          <span class="wa-tabs__badge" id="wa-meetings-badge"><?php echo count($waMeetings); ?></span>
        <?php else: ?>
          <span class="wa-tabs__badge wa-tabs__badge--hidden" id="wa-meetings-badge">0</span>
        <?php endif; ?>
      </button>
    </nav>

    <div class="wa-panel" id="wa-panel-tasks">

    <section class="wa-stats" aria-label="Task counts by status">
      <?php foreach (akh_wa_task_statuses() as $st): ?>
        <button
          type="button"
          class="wa-stat wa-stat--<?php echo h($st); ?><?php echo $fStatus === $st ? ' is-active' : ''; ?>"
          data-wa-filter-status="<?php echo h($st); ?>"
        >
          <span class="wa-stat__count" data-wa-count="<?php echo h($st); ?>"><?php echo (int) ($initialCounts[$st] ?? 0); ?></span>
          <span class="wa-stat__label"><?php echo h(akh_wa_task_status_label($st)); ?></span>
        </button>
      <?php endforeach; ?>
      <div class="wa-stat wa-stat--total">
        <span class="wa-stat__count" id="wa-total-count"><?php echo (int) $totalTasks; ?></span>
        <span class="wa-stat__label">Total</span>
      </div>
    </section>

    <section class="wa-toolbar">
      <label class="wa-search">
        <span class="visually-hidden">Search tasks</span>
        <input type="search" id="wa-search" placeholder="Search code, project, customer…" value="<?php echo h($fQ); ?>" autocomplete="off" />
      </label>
      <button type="button" class="wa-btn wa-btn--ghost" id="wa-clear-filters">Clear filters</button>
    </section>

    <div class="wa-table-wrap" id="wa-table-wrap">
      <table class="wa-table" id="wa-tasks-table">
        <thead>
          <tr>
            <th scope="col">Task ID</th>
            <th scope="col">Customer</th>
            <th scope="col">Project</th>
            <th scope="col">Type</th>
            <th scope="col">Status</th>
            <th scope="col">Editor</th>
            <th scope="col">Updated</th>
            <th scope="col"><span class="visually-hidden">Actions</span></th>
          </tr>
        </thead>
        <tbody id="wa-tasks-body">
          <?php if ($tasksJson === []): ?>
            <tr class="wa-table__empty"><td colspan="8">No tasks yet — they will appear here when WhatsApp automation creates them.</td></tr>
          <?php else: ?>
            <?php
            $waAlerts = $dbError === '' ? ($waNotify['alerts'] ?? []) : [];
            foreach ($tasksJson as $t):
              $alert = akh_dashboard_alert_for_code($waAlerts, (string) ($t['task_code'] ?? ''));
              $rowClass = $alert ? ' wa-table__row--unread' : '';
              $pillClass = 'wa-alert-pill';
              $pillLabel = 'Update';
              if ($alert) {
                $kind = (string) ($alert['kind'] ?? '');
                if ($kind === 'meeting_request') {
                  $pillClass .= ' wa-alert-pill--meeting';
                  $pillLabel = 'Meeting';
                } elseif ($kind === 'meeting_reminder') {
                  $pillClass .= ' wa-alert-pill--reminder';
                  $pillLabel = 'Soon';
                }
              }
              $alertBadge = $alert
                  ? '<span class="' . h($pillClass) . '" title="' . h((string) ($alert['preview'] ?? 'Alert')) . '">' . h($pillLabel) . '</span> '
                  : '';
              ?>
              <tr class="wa-table__row<?php echo $rowClass; ?>" data-task-id="<?php echo (int) $t['id']; ?>">
                <td><?php echo $alertBadge; ?><code class="wa-code"><?php echo h((string) $t['task_code']); ?></code></td>
                <td>
                  <span class="wa-cell-main"><?php echo h((string) ($t['customer_name'] !== '' ? $t['customer_name'] : '—')); ?></span>
                </td>
                <td><?php echo h((string) $t['project_name']); ?></td>
                <td><?php echo h((string) $t['task_type']); ?></td>
                <td><span class="wa-badge wa-badge--<?php echo h((string) $t['status']); ?>"><?php echo h((string) $t['status_label']); ?></span></td>
                <td><?php echo h((string) ($t['assigned_editor_name'] !== '' ? $t['assigned_editor_name'] : '—')); ?></td>
                <td class="wa-cell-muted"><?php echo h((string) $t['updated_at']); ?></td>
                <td><button type="button" class="wa-btn wa-btn--sm wa-btn--edit" data-wa-edit="<?php echo (int) $t['id']; ?>">Edit</button></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    </div>

    <div class="wa-panel wa-panel--hidden" id="wa-panel-meetings" hidden>
      <div class="wa-meetings-wrap">
        <p class="wa-meetings-intro">Scheduled and requested meetings. Unread requests are highlighted. Join popup appears about 5 minutes before start time.</p>
        <div class="wa-table-wrap">
          <table class="wa-table wa-meetings-table" id="wa-meetings-table">
            <thead>
              <tr>
                <th scope="col">Task</th>
                <th scope="col">Customer</th>
                <th scope="col">Project</th>
                <th scope="col">When</th>
                <th scope="col">Status</th>
                <th scope="col">Meet link</th>
                <th scope="col">Actions</th>
              </tr>
            </thead>
            <tbody id="wa-meetings-body">
              <tr class="wa-table__empty"><td colspan="7">Loading meetings…</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <?php endif; ?>
  </main>

  <dialog class="wa-modal" id="wa-edit-modal" aria-labelledby="wa-edit-title">
    <form method="dialog" class="wa-modal__inner" id="wa-edit-form">
      <header class="wa-modal__head">
        <div>
          <p class="wa-modal__kicker">Task details</p>
          <h2 class="wa-modal__title" id="wa-edit-title">Edit task</h2>
        </div>
        <button type="button" class="wa-modal__close" id="wa-edit-close" aria-label="Close">×</button>
      </header>

      <div class="wa-modal__body">
        <input type="hidden" name="id" id="wa-field-id" value="" />

        <div class="wa-form-grid">
          <label class="wa-field">
            <span>Task ID</span>
            <input type="text" name="task_code" id="wa-field-task_code" required maxlength="50" placeholder="AS0001" />
          </label>
          <label class="wa-field">
            <span>Status</span>
            <select name="status" id="wa-field-status">
              <?php foreach (akh_wa_task_statuses() as $st): ?>
                <option value="<?php echo h($st); ?>"><?php echo h(akh_wa_task_status_label($st)); ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="wa-field">
            <span>Customer name</span>
            <input type="text" name="customer_name" id="wa-field-customer_name" maxlength="255" />
          </label>
          <label class="wa-field">
            <span>Customer ID</span>
            <input type="number" name="customer_id" id="wa-field-customer_id" min="0" step="1" />
          </label>
          <label class="wa-field">
            <span>Project name</span>
            <input type="text" name="project_name" id="wa-field-project_name" maxlength="255" />
          </label>
          <label class="wa-field">
            <span>Task type</span>
            <input type="text" name="task_type" id="wa-field-task_type" maxlength="100" />
          </label>
          <label class="wa-field">
            <span>Delivery type</span>
            <input type="text" name="delivery_type" id="wa-field-delivery_type" maxlength="50" />
          </label>
          <label class="wa-field">
            <span>Assigned editor</span>
            <select name="assigned_editor" id="wa-field-assigned_editor">
              <option value="">— Unassigned —</option>
              <?php foreach ($editors as $eid => $ename): ?>
                <option value="<?php echo (int) $eid; ?>"><?php echo h($ename); ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="wa-field wa-field--full">
            <span>Instructions</span>
            <textarea name="instructions" id="wa-field-instructions" rows="4"></textarea>
          </label>
          <label class="wa-field wa-field--full">
            <span>Drive link <span class="wa-field__optional">(optional)</span></span>
            <input type="text" name="drive_link" id="wa-field-drive_link" inputmode="url" placeholder="https://…" />
          </label>
          <label class="wa-field wa-field--full">
            <span>Reference link <span class="wa-field__optional">(optional)</span></span>
            <input type="text" name="reference_link" id="wa-field-reference_link" inputmode="url" placeholder="https://…" />
          </label>
          <label class="wa-field wa-field--full">
            <span>Comments</span>
            <textarea name="comments" id="wa-field-comments" rows="3"></textarea>
          </label>
        </div>

        <p class="wa-modal__meta" id="wa-edit-meta"></p>
        <p class="wa-banner wa-banner--err wa-banner--hidden" id="wa-edit-error" role="alert"></p>
      </div>

      <footer class="wa-modal__foot">
        <button type="button" class="wa-btn wa-btn--ghost" id="wa-edit-cancel">Cancel</button>
        <button type="submit" class="wa-btn wa-btn--primary" id="wa-edit-save">Save changes</button>
      </footer>
    </form>
  </dialog>

  <?php require_once AKH_ROOT . '/includes/meeting-join-modal.php'; ?>

  <script src="<?php echo h(base_path('assets/js/meeting-alerts.js')); ?>?v=<?php echo h($meetJsVer); ?>"></script>
  <script>
    <?php
    $waDashboardJson = [
        'apiUrl' => $apiUrl,
        'csrf' => $csrf,
        'refreshSeconds' => $refreshSec,
        'initialSig' => $pollSig,
        'tasks' => $tasksJson,
        'counts' => $initialCounts,
        'editors' => $editors,
        'statuses' => akh_wa_task_statuses(),
        'filterStatus' => $fStatus,
        'filterQ' => $fQ,
        'notifyCount' => (int) ($waNotify['count'] ?? 0),
        'notifySig' => (string) ($waNotify['notify_sig'] ?? ''),
        'alerts' => $waNotify['alerts'] ?? [],
        'notices' => $waNotify['notices'] ?? [],
        'reminders' => $waNotify['reminders'] ?? [],
        'meetings' => $waMeetings,
    ];
    try {
        $waDashboardJs = json_encode($waDashboardJson, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        $waDashboardJson['tasks'] = [];
        $waDashboardJson['alerts'] = [];
        $waDashboardJson['notices'] = [];
        $waDashboardJson['meetings'] = [];
        $waDashboardJs = json_encode($waDashboardJson, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }
    ?>
    window.WA_DASHBOARD = <?php echo $waDashboardJs; ?>;
  </script>
  <script src="<?php echo h(base_path('assets/js/whatsapp-dashboard.js') . ($waJsVer !== '' ? '?v=' . rawurlencode($waJsVer) : '')); ?>" defer></script>
</body>
</html>
