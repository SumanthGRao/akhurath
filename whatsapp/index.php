<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once AKH_ROOT . '/includes/whatsapp-dashboard-auth.php';
require_once AKH_ROOT . '/includes/whatsapp-tasks.php';
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
        $initialTasks = akh_wa_tasks_list();
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
$waNotify = $dbError === '' ? akh_wa_client_notification_payload() : ['count' => 0, 'alerts' => [], 'notify_sig' => 'missing', 'watermark' => 0];
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
        <button
          type="button"
          class="wa-bell<?php echo (int) ($waNotify['count'] ?? 0) > 0 ? ' wa-bell--active' : ''; ?>"
          id="wa-notify-bell"
          title="Client updates needing attention"
        >
          <span class="wa-bell__icon" aria-hidden="true">🔔</span>
          <span class="wa-bell__count" id="wa-notify-count"><?php echo (int) ($waNotify['count'] ?? 0); ?></span>
          <span class="visually-hidden"><?php echo (int) ($waNotify['count'] ?? 0); ?> client updates</span>
        </button>
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
            <?php foreach ($tasksJson as $t): ?>
              <tr data-task-id="<?php echo (int) $t['id']; ?>">
                <td><code class="wa-code"><?php echo h((string) $t['task_code']); ?></code></td>
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

  <script>
    window.WA_DASHBOARD = <?php echo json_encode([
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
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); ?>;
  </script>
  <script src="<?php echo h(base_path('assets/js/whatsapp-dashboard.js') . ($waJsVer !== '' ? '?v=' . rawurlencode($waJsVer) : '')); ?>" defer></script>
</body>
</html>
