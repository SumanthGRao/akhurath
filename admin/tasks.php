<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once AKH_ROOT . '/includes/admin-auth.php';
require_once AKH_ROOT . '/includes/auth.php';
require_once AKH_ROOT . '/includes/editor-auth.php';
require_once AKH_ROOT . '/includes/tasks.php';
require_once AKH_ROOT . '/includes/csrf.php';

akh_require_admin();

$pageTitle = 'Admin — Tasks — ' . SITE_NAME;
$bodyClass = 'page-portal admin-page admin-page--board';
$adminNavActive = 'tasks.php';

$rawView = trim((string) ($_REQUEST['view'] ?? 'tasks'));
if (!in_array($rawView, ['tasks', 'create'], true)) {
    $rawView = 'tasks';
}
$adminConsoleActive = $rawView === 'create' ? 'create' : 'tasks';

$flash = '';
$error = '';
$adminPortalOpen = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && trim((string) ($_POST['ajax_action'] ?? '')) !== '') {
    header('Content-Type: application/json; charset=utf-8');
    if (!akh_csrf_verify($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        echo json_encode(['ok' => false]);
        exit;
    }
    $ajax = trim((string) ($_POST['ajax_action'] ?? ''));
    if ($ajax === 'poll') {
        try {
            echo json_encode(akh_task_ajax_poll_admin(), JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            echo json_encode(['ok' => false]);
        }
        exit;
    }
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'bad_ajax']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!akh_csrf_verify($_POST['csrf_token'] ?? null)) {
        $error = 'Security check failed. Refresh and try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'task_delete_all') {
            $purge = isset($_POST['confirm_delete_all']) && (string) ($_POST['confirm_delete_all'] ?? '') === '1';
            if (!$purge) {
                $error = 'Check the box to confirm deleting every task.';
            } elseif (!akh_task_admin_delete_all()) {
                $error = 'Could not clear tasks.';
            } else {
                $flash = 'All tasks were deleted.';
            }
        } elseif ($action === 'task_create') {
            $client = strtolower(trim((string) ($_POST['client_username'] ?? '')));
            $title = trim((string) ($_POST['task_title'] ?? ''));
            $description = trim((string) ($_POST['task_description'] ?? ''));
            $mode = (string) ($_POST['delivery_mode'] ?? '');
            $link = trim((string) ($_POST['drive_link'] ?? ''));
            $ref = trim((string) ($_POST['reference_link'] ?? ''));
            $cust = akh_customer_accounts();
            if ($client === '' || !isset($cust[$client])) {
                $error = 'Pick a valid client.';
            } else {
                $task = akh_task_admin_create_for_client($client, $title, $description, $mode, $link, $ref);
                if ($task === null) {
                    $error = 'Could not create task. Check title, delivery mode, optional reference URL, and that Drive links start with https://.';
                } else {
                    $flash = 'Task created.';
                    if ($mode === 'nas_storage') {
                        $adminPortalOpen = DRIVE_PORTAL_URL;
                    }
                }
            }
        } elseif ($action === 'task_assign') {
            $tid = trim((string) ($_POST['task_id'] ?? ''));
            $ed = trim((string) ($_POST['editor_username'] ?? ''));
            $err = akh_task_admin_assign($tid, $ed === '' ? null : $ed);
            if ($err !== null) {
                $error = $err;
            } else {
                $flash = 'Assignment updated.';
            }
        } elseif ($action === 'task_status') {
            $tid = trim((string) ($_POST['task_id'] ?? ''));
            $st = (string) ($_POST['status'] ?? '');
            $err = akh_task_admin_set_status($tid, $st);
            if ($err !== null) {
                $error = $err;
            } else {
                $flash = 'Status updated.';
            }
        } elseif ($action === 'task_delete') {
            $tid = trim((string) ($_POST['task_id'] ?? ''));
            if (!akh_task_admin_delete($tid)) {
                $error = 'Could not delete task.';
            } else {
                $flash = 'Task deleted.';
            }
        } else {
            $error = 'Unknown action.';
        }
    }
}

$tasksAll = akh_tasks_all_sorted();
$clients = akh_customer_accounts();
ksort($clients, SORT_STRING);
$editors = akh_editor_accounts();
ksort($editors, SORT_STRING);
$statuses = ['new', 'assigned', 'in_progress', 'review', 'delivered', 'reverted', 'closed'];
$counts = akh_task_status_counts();

$fStatus = trim((string) ($_REQUEST['f_status'] ?? ''));
$fClient = strtolower(trim((string) ($_REQUEST['f_client'] ?? '')));
$fEditor = trim((string) ($_REQUEST['f_editor'] ?? ''));
$fQ = strtolower(trim((string) ($_REQUEST['f_q'] ?? '')));

$knownStatuses = ['new', 'assigned', 'in_progress', 'review', 'delivered', 'reverted', 'closed'];
$tasks = array_values(array_filter($tasksAll, static function (array $t) use ($fStatus, $fClient, $fEditor, $fQ, $knownStatuses): bool {
    if ($fStatus === 'other') {
        if (in_array((string) ($t['status'] ?? ''), $knownStatuses, true)) {
            return false;
        }
    } elseif ($fStatus !== '' && (string) ($t['status'] ?? '') !== $fStatus) {
        return false;
    }
    if ($fClient !== '' && strtolower((string) ($t['client_username'] ?? '')) !== $fClient) {
        return false;
    }
    if ($fEditor !== '') {
        $ed = trim((string) ($t['assigned_editor'] ?? ''));
        if ($fEditor === '__unassigned__') {
            if ($ed !== '') {
                return false;
            }
        } elseif (strtolower($ed) !== strtolower($fEditor)) {
            return false;
        }
    }
    if ($fQ !== '') {
        $tid = strtolower((string) ($t['id'] ?? ''));
        $title = strtolower((string) ($t['title'] ?? ''));
        if (strpos($tid, $fQ) === false && strpos($title, $fQ) === false) {
            return false;
        }
    }

    return true;
}));
$taskTotalAll = count($tasksAll);
$taskFilteredCount = count($tasks);

$adminBoardCsrf = akh_csrf_token();
$adminBellNotices = akh_task_admin_notice_rows();
$adminBellPool = count($adminBellNotices);
$adminBoardSig = akh_task_poll_signature_all();

$preserveHidden = array_filter([
    'view' => $rawView,
    'f_status' => $fStatus !== '' ? $fStatus : null,
    'f_client' => $fClient !== '' ? $fClient : null,
    'f_editor' => $fEditor !== '' ? $fEditor : null,
    'f_q' => $fQ !== '' ? $fQ : null,
], static fn ($v) => $v !== null && $v !== '');

require_once AKH_ROOT . '/includes/header.php';
?>

  <main id="main" class="portal-main portal-main--board">
    <div class="portal-card portal-card--tasks admin-shell admin-console">
      <header class="admin-head">
        <div>
          <h1 class="portal-title"><?php echo $rawView === 'create' ? 'Create task' : 'Tasks'; ?></h1>
          <p class="portal-lead admin-head__meta"><?php echo $rawView === 'create' ? 'Add a task for a client. Open the Console menu (top right) for the task list or account tools.' : 'Browse, filter, and update every job. Open the Console menu for create, clients, and editors.'; ?></p>
        </div>
        <div class="admin-head__actions" style="display:flex;flex-wrap:wrap;align-items:center;gap:0.75rem">
          <?php if ($rawView === 'tasks'): ?>
            <div class="desk-bell-wrap desk-bell-wrap--admin">
              <button
                type="button"
                class="desk-bell<?php echo $adminBellPool > 0 ? ' desk-bell--wiggle desk-bell--pop' : ' desk-bell--zero'; ?>"
                aria-expanded="false"
                aria-haspopup="true"
                title="Unassigned pool tasks — click for list"
              >
                <span class="desk-bell__icon" aria-hidden="true">
                  <svg width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M12 22a2 2 0 002-2H10a2 2 0 002 2zm6-6V11a6 6 0 10-12 0v5l-2 2v1h16v-1l-2-2z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/>
                  </svg>
                </span>
                <span class="desk-bell__count"><?php echo (int) $adminBellPool; ?></span>
                <span class="visually-hidden"><?php echo (int) $adminBellPool; ?> unassigned pool task(s)</span>
              </button>
              <div class="desk-bell-dropdown" id="admin-desk-bell-dropdown" hidden></div>
            </div>
          <?php endif; ?>
          <?php require __DIR__ . '/includes/admin-console-sidebar.php'; ?>
          <a class="btn btn--ghost btn--sm" href="<?php echo h(base_path('admin/logout.php')); ?>">Sign out</a>
        </div>
      </header>

      <?php require AKH_ROOT . '/includes/admin-nav.php'; ?>

      <?php if ($rawView === 'tasks'): ?>
        <p class="portal-muted" style="margin:0 0 0.75rem;font-size:0.88rem">Live: this page refreshes automatically when tasks change (~14s). Use the bell for a quick list of jobs still in the editor pool.</p>
      <?php endif; ?>

      <?php if ($flash !== ''): ?>
        <p class="banner banner--ok" role="status"><?php echo h($flash); ?></p>
      <?php endif; ?>
      <?php if ($adminPortalOpen !== ''): ?>
        <p class="portal-actions portal-actions--inline" style="margin-top:0">
          <a class="btn btn--primary" href="<?php echo h($adminPortalOpen); ?>" target="_blank" rel="noopener noreferrer">Open upload portal (new tab)</a>
        </p>
      <?php endif; ?>
      <?php if ($error !== ''): ?>
        <p class="banner banner--err" role="alert"><?php echo h($error); ?></p>
      <?php endif; ?>

      <?php if ($rawView === 'tasks'): ?>
      <ul class="admin-status-grid admin-status-grid--links" style="margin-top:0" aria-label="Task counts by status — click to filter">
        <?php
        $chipBase = static function (array $qs): string {
            $qs['view'] = 'tasks';
            $qs = array_filter($qs, static fn ($v) => $v !== null && $v !== '');

            return $qs === [] ? base_path('admin/tasks.php') : base_path('admin/tasks.php?' . http_build_query($qs));
        };
        ?>
        <?php foreach ($statuses as $st): ?>
          <?php
          $chipHref = $chipBase([
              'f_status' => $st,
              'f_client' => $fClient !== '' ? $fClient : null,
              'f_editor' => $fEditor !== '' ? $fEditor : null,
              'f_q' => $fQ !== '' ? $fQ : null,
          ]);
          $isChip = $fStatus === $st;
          ?>
          <li>
            <a class="admin-status-chip<?php echo $isChip ? ' admin-status-chip--active' : ''; ?>" href="<?php echo h($chipHref); ?>">
              <span class="admin-status-grid__n"><?php echo (int) ($counts[$st] ?? 0); ?></span>
              <span class="admin-status-grid__l"><?php echo h(akh_task_status_label($st)); ?></span>
            </a>
          </li>
        <?php endforeach; ?>
        <li>
          <a class="admin-status-chip<?php echo $fStatus === '' ? ' admin-status-chip--active' : ''; ?>" href="<?php echo h($chipBase([
              'f_client' => $fClient !== '' ? $fClient : null,
              'f_editor' => $fEditor !== '' ? $fEditor : null,
              'f_q' => $fQ !== '' ? $fQ : null,
          ])); ?>">
            <span class="admin-status-grid__n"><?php echo (int) $taskTotalAll; ?></span>
            <span class="admin-status-grid__l">All statuses</span>
          </a>
        </li>
      </ul>

      <form class="admin-filters" method="get" action="">
        <input type="hidden" name="view" value="tasks" />
        <input type="hidden" name="f_status" value="<?php echo h($fStatus); ?>" />
        <label class="field admin-filters__field admin-filters__field--grow">
          <span>Search ID or title</span>
          <input type="search" name="f_q" value="<?php echo h($fQ); ?>" placeholder="e.g. AS_ or couple name" maxlength="200" />
        </label>
        <label class="field admin-filters__field">
          <span>Client</span>
          <select name="f_client">
            <option value="">Any client</option>
            <?php foreach (array_keys($clients) as $c): ?>
              <option value="<?php echo h((string) $c); ?>"<?php echo $fClient === strtolower((string) $c) ? ' selected' : ''; ?>><?php echo h((string) $c); ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="field admin-filters__field">
          <span>Editor</span>
          <select name="f_editor">
            <option value="">Any editor</option>
            <option value="__unassigned__"<?php echo $fEditor === '__unassigned__' ? ' selected' : ''; ?>>Unassigned</option>
            <?php foreach (array_keys($editors) as $en): ?>
              <option value="<?php echo h((string) $en); ?>"<?php echo strtolower($fEditor) === strtolower((string) $en) ? ' selected' : ''; ?>><?php echo h((string) $en); ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <div class="admin-filters__actions">
          <button type="submit" class="btn btn--ghost btn--sm">Apply filters</button>
          <a class="btn btn--ghost btn--sm" href="<?php echo h(base_path('admin/tasks.php?view=tasks')); ?>">Clear</a>
        </div>
      </form>

      <section class="portal-section" aria-labelledby="all-tasks-h">
        <h2 id="all-tasks-h" class="portal-section__title">
          Tasks
          <?php if ($fStatus !== '' || $fClient !== '' || $fEditor !== '' || $fQ !== ''): ?>
            <span class="portal-muted" style="font-weight:400;font-size:0.92rem"> — showing <?php echo (int) $taskFilteredCount; ?> of <?php echo (int) $taskTotalAll; ?></span>
          <?php else: ?>
            <span class="portal-muted" style="font-weight:400;font-size:0.92rem"> (<?php echo (int) $taskTotalAll; ?>)</span>
          <?php endif; ?>
        </h2>
        <?php if ($taskTotalAll > 0): ?>
          <form class="admin-danger-zone" method="post" action="" onsubmit="return confirm('Delete ALL <?php echo (int) $taskTotalAll; ?> tasks? This cannot be undone.');">
            <input type="hidden" name="csrf_token" value="<?php echo h(akh_csrf_token()); ?>" />
            <?php foreach ($preserveHidden as $hk => $hv): ?>
              <input type="hidden" name="<?php echo h((string) $hk); ?>" value="<?php echo h((string) $hv); ?>" />
            <?php endforeach; ?>
            <input type="hidden" name="action" value="task_delete_all" />
            <label class="admin-danger-zone__check">
              <input type="checkbox" name="confirm_delete_all" value="1" />
              <span>I understand this permanently deletes <strong>every</strong> task.</span>
            </label>
            <button type="submit" class="btn btn--ghost btn--sm admin-danger-zone__btn">Delete all tasks</button>
          </form>
        <?php endif; ?>
        <?php if ($tasks === []): ?>
          <p class="portal-muted"><?php echo $taskTotalAll === 0 ? 'No tasks yet.' : 'No tasks match these filters.'; ?></p>
        <?php else: ?>
          <div class="admin-table-wrap">
            <table class="admin-table">
              <thead>
                <tr>
                  <th scope="col">ID / title</th>
                  <th scope="col">Client</th>
                  <th scope="col">Delivery</th>
                  <th scope="col">Status</th>
                  <th scope="col">Editor</th>
                  <th scope="col">Updated</th>
                  <th scope="col">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($tasks as $t): ?>
                  <?php
                  $tid = (string) ($t['id'] ?? '');
                  $st = (string) ($t['status'] ?? 'new');
                  $ed = (string) ($t['assigned_editor'] ?? '');
                  ?>
                  <tr id="ticket-<?php echo h($tid); ?>">
                    <td>
                      <span class="admin-table__mono"><?php echo h($tid); ?></span>
                      <div><strong><?php echo h((string) ($t['title'] ?? '')); ?></strong><?php if (akh_task_is_bundle_parent($t)): ?> <span class="portal-muted" style="font-weight:400">(bundle overview)</span><?php elseif (akh_task_is_bundle_child($t)): ?> <span class="portal-muted" style="font-weight:400">(part of <?php echo h((string) ($t['parent_task_id'] ?? '')); ?>)</span><?php endif; ?></div>
                      <?php if (trim((string) ($t['description'] ?? '')) !== ''): ?>
                        <p class="task-table__desc"><?php echo nl2br(h((string) ($t['description'] ?? ''))); ?></p>
                      <?php endif; ?>
                      <?php if (($t['delivery_mode'] ?? '') === 'google_drive' && ($t['drive_link'] ?? '') !== ''): ?>
                        <p><a class="text-link" href="<?php echo h((string) $t['drive_link']); ?>" target="_blank" rel="noopener">Drive</a></p>
                      <?php endif; ?>
                    </td>
                    <td class="admin-table__mono"><?php echo h((string) ($t['client_username'] ?? '')); ?></td>
                    <td><?php echo h(akh_task_delivery_mode_label((string) ($t['delivery_mode'] ?? 'google_drive'))); ?></td>
                    <td>
                      <form class="admin-inline-form" method="post" action="">
                        <input type="hidden" name="csrf_token" value="<?php echo h(akh_csrf_token()); ?>" />
                        <?php foreach ($preserveHidden as $hk => $hv): ?>
                          <input type="hidden" name="<?php echo h((string) $hk); ?>" value="<?php echo h((string) $hv); ?>" />
                        <?php endforeach; ?>
                        <input type="hidden" name="action" value="task_status" />
                        <input type="hidden" name="task_id" value="<?php echo h($tid); ?>" />
                        <select name="status" aria-label="Status for <?php echo h($tid); ?>">
                          <?php foreach ($statuses as $opt): ?>
                            <option value="<?php echo h($opt); ?>"<?php echo $opt === $st ? ' selected' : ''; ?>><?php echo h(akh_task_status_label($opt)); ?></option>
                          <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn--ghost btn--sm">Set</button>
                      </form>
                    </td>
                    <td>
                      <form class="admin-inline-form" method="post" action="">
                        <input type="hidden" name="csrf_token" value="<?php echo h(akh_csrf_token()); ?>" />
                        <?php foreach ($preserveHidden as $hk => $hv): ?>
                          <input type="hidden" name="<?php echo h((string) $hk); ?>" value="<?php echo h((string) $hv); ?>" />
                        <?php endforeach; ?>
                        <input type="hidden" name="action" value="task_assign" />
                        <input type="hidden" name="task_id" value="<?php echo h($tid); ?>" />
                        <select name="editor_username" aria-label="Editor for <?php echo h($tid); ?>">
                          <option value="">— Unassign —</option>
                          <?php foreach (array_keys($editors) as $en): ?>
                            <option value="<?php echo h((string) $en); ?>"<?php echo strtolower($ed) === strtolower((string) $en) ? ' selected' : ''; ?>><?php echo h((string) $en); ?></option>
                          <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn--ghost btn--sm">Assign</button>
                      </form>
                    </td>
                    <td class="task-table__date"><?php echo h((string) ($t['updated_at'] ?? '')); ?></td>
                    <td>
                      <form method="post" action="" onsubmit="return confirm('Delete this task permanently?');">
                        <input type="hidden" name="csrf_token" value="<?php echo h(akh_csrf_token()); ?>" />
                        <?php foreach ($preserveHidden as $hk => $hv): ?>
                          <input type="hidden" name="<?php echo h((string) $hk); ?>" value="<?php echo h((string) $hv); ?>" />
                        <?php endforeach; ?>
                        <input type="hidden" name="action" value="task_delete" />
                        <input type="hidden" name="task_id" value="<?php echo h($tid); ?>" />
                        <button type="submit" class="btn btn--ghost btn--sm">Delete</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </section>
      <?php elseif ($rawView === 'create'): ?>
      <section class="portal-section" aria-labelledby="new-task-h">
        <h2 id="new-task-h" class="portal-section__title">New task</h2>
        <?php if ($clients === []): ?>
          <p class="banner banner--topic">Add at least one client under <strong>Clients</strong> in the sidebar before creating tasks.</p>
        <?php else: ?>
          <form class="portal-form" method="post" action="">
            <input type="hidden" name="csrf_token" value="<?php echo h(akh_csrf_token()); ?>" />
            <input type="hidden" name="view" value="create" />
            <input type="hidden" name="action" value="task_create" />
            <label class="field">
              <span>Client</span>
              <select name="client_username" required>
                <?php foreach (array_keys($clients) as $c): ?>
                  <option value="<?php echo h((string) $c); ?>"><?php echo h((string) $c); ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label class="field">
              <span>Title</span>
              <input type="text" name="task_title" required maxlength="200" />
            </label>
            <label class="field">
              <span>Description</span>
              <textarea name="task_description" rows="4" maxlength="8000"></textarea>
            </label>
            <fieldset class="field">
              <legend>Delivery</legend>
              <label class="portal-radio"><input type="radio" name="delivery_mode" value="google_drive" checked /> Google Drive link</label>
              <label class="portal-radio"><input type="radio" name="delivery_mode" value="nas_storage" /> NAS / Nextcloud (portal link after submit, new tab)</label>
              <label class="portal-radio"><input type="radio" name="delivery_mode" value="courier_hdd" /> Courier / hard drive (no Drive link)</label>
            </fieldset>
            <label class="field">
              <span>Reference link (optional)</span>
              <input type="url" name="reference_link" placeholder="https://…" maxlength="2000" />
            </label>
            <label class="field">
              <span>Drive link (if Google Drive)</span>
              <input type="url" name="drive_link" placeholder="https://…" maxlength="2000" />
            </label>
            <button type="submit" class="btn btn--primary btn--inline">Create task</button>
          </form>
        <?php endif; ?>
      </section>
      <?php endif; ?>

    </div>
  </main>
  <?php if ($rawView === 'tasks'): ?>
    <?php
    $akhPushJs = AKH_ROOT . '/assets/js/portal-push-notify.js';
    $akhPushVer = is_file($akhPushJs) ? (string) filemtime($akhPushJs) : '1';
    ?>
    <script>
      window._akhPortalPush = {
        mode: 'admin_tasks',
        siteName: <?php echo json_encode(SITE_NAME, JSON_THROW_ON_ERROR); ?>,
        csrf: <?php echo json_encode($adminBoardCsrf, JSON_THROW_ON_ERROR); ?>,
        pollUrl: <?php echo json_encode(base_path('admin/tasks.php'), JSON_THROW_ON_ERROR); ?>,
        ticketQueryPrefix: 'view=tasks&',
        bell: <?php echo (int) $adminBellPool; ?>,
        pool: 0,
        sig: <?php echo json_encode($adminBoardSig, JSON_THROW_ON_ERROR); ?>,
        notices: <?php echo json_encode($adminBellNotices, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES); ?>
      };
    </script>
    <script defer src="<?php echo h(base_path('assets/js/portal-push-notify.js')); ?>?v=<?php echo h($akhPushVer); ?>"></script>
  <?php endif; ?>

<?php require_once AKH_ROOT . '/includes/footer.php'; ?>
