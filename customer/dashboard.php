<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once AKH_ROOT . '/includes/auth.php';
require_once AKH_ROOT . '/includes/tasks.php';
require_once AKH_ROOT . '/includes/task-thread-panel.php';
require_once AKH_ROOT . '/includes/csrf.php';

akh_require_customer();

$pageTitle = 'Client dashboard — ' . SITE_NAME;
$metaDescription = 'Submit a project task and track status.';
$bodyClass = 'page-portal page-portal--board';

$user = (string) akh_customer_current();

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
                akh_task_ajax_client_view_ack($user, trim((string) ($_POST['task_id'] ?? ''))),
                JSON_THROW_ON_ERROR
            );
        } catch (\Throwable $e) {
            echo json_encode(['ok' => false]);
        }
        exit;
    }
    if ($ajax === 'poll') {
        try {
            echo json_encode(akh_task_ajax_poll_client($user), JSON_THROW_ON_ERROR);
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
$portalOpenUrl = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!akh_csrf_verify($_POST['csrf_token'] ?? null)) {
        $error = 'Security check failed. Refresh the page and try again.';
    } else {
        $action = trim((string) ($_POST['task_action'] ?? 'create_task'));

        if ($action === 'thread_message') {
            $tid = trim((string) ($_POST['task_id'] ?? ''));
            $err = akh_task_client_append_thread($tid, $user, trim((string) ($_POST['thread_body'] ?? '')));
            if ($err !== null) {
                $error = $err;
            } else {
                header('Location: ' . base_path('customer/dashboard.php?ticket=' . rawurlencode($tid)));
                exit;
            }
        } elseif ($action === 'save_delivered') {
            $tid = trim((string) ($_POST['task_id'] ?? ''));
            $fb = trim((string) ($_POST['client_feedback'] ?? ''));
            $md = trim((string) ($_POST['client_meeting_date'] ?? ''));
            $ml = trim((string) ($_POST['client_meeting_link'] ?? ''));
            $err = akh_task_client_save_post_delivery($tid, $user, $fb, $md, $ml);
            if ($err !== null) {
                $error = $err;
            } else {
                $flash = 'Your feedback was sent to your editor. This task is now marked returned for revision.';
            }
        } elseif ($action === 'update_task') {
            $tid = trim((string) ($_POST['task_id'] ?? ''));
            $coupleName = trim((string) ($_POST['couple_name'] ?? ''));
            $editType = trim((string) ($_POST['edit_type'] ?? ''));
            $projectDetails = trim((string) ($_POST['project_details'] ?? ''));
            $referenceLink = trim((string) ($_POST['reference_link'] ?? ''));
            $mode = (string) ($_POST['delivery_mode'] ?? '');
            $link = trim((string) ($_POST['drive_link'] ?? ''));
            $task = akh_task_client_update($tid, $user, $coupleName, $editType, $projectDetails, $referenceLink, $mode, $link, false);
            if ($task === null) {
                $error = 'Could not update the task. Check all required fields, or this task may no longer be editable (after an editor is assigned).';
            } else {
                if ($mode === 'nas_storage') {
                    $portalOpenUrl = DRIVE_PORTAL_URL;
                    $flash = 'Task updated. Open the upload portal in a new tab when you’re ready.';
                } else {
                    $flash = 'Task updated.';
                }
            }
        } else {
            $coupleName = trim((string) ($_POST['couple_name'] ?? ''));
            $projectDetails = trim((string) ($_POST['project_details'] ?? ''));
            $referenceLink = trim((string) ($_POST['reference_link'] ?? ''));
            $mode = (string) ($_POST['delivery_mode'] ?? '');
            $link = trim((string) ($_POST['drive_link'] ?? ''));

            $rawTypes = $_POST['edit_types'] ?? $_POST['edit_type'] ?? [];
            if (!is_array($rawTypes)) {
                $rawTypes = $rawTypes !== '' ? [(string) $rawTypes] : [];
            }
            $editTypes = array_values(array_unique(array_filter(array_map('trim', $rawTypes), static fn ($s) => $s !== '')));
            if ($editTypes === []) {
                $error = 'Pick at least one type of edit.';
            } elseif (count($editTypes) >= 2) {
                $task = akh_task_create_bundle($user, $coupleName, $editTypes, $projectDetails, $referenceLink, $mode, $link, false);
                if ($task === null) {
                    $error = 'Could not create the job. Check couple name, at least two edit types, project details, a valid https reference link, and footage options (Drive link when required).';
                } else {
                    if ($mode === 'nas_storage') {
                        $portalOpenUrl = DRIVE_PORTAL_URL;
                        $flash = 'Your multi-part job was submitted. Each type is a separate editor task under one request. Open the upload portal in a new tab when you’re ready.';
                    } elseif ($mode === 'courier_hdd') {
                        $flash = 'Your multi-part job was submitted. Each type is a separate editor task under one request. Ship media as agreed.';
                    } else {
                        $flash = 'Your multi-part job was submitted. Each type is a separate editor task; editors claim them individually from the pool.';
                    }
                }
            } else {
                $task = akh_task_create($user, $coupleName, $editTypes[0], $projectDetails, $referenceLink, $mode, $link, false);
                if ($task === null) {
                    $error = 'Could not create the task. Fill every required field: couple name, type of edit, project details, reference link (https), and either a Google Drive link or a storage/courier option as described.';
                } else {
                    if ($mode === 'nas_storage') {
                        $portalOpenUrl = DRIVE_PORTAL_URL;
                        $flash = 'Your task was submitted. Open the upload portal in a new tab when you’re ready.';
                    } elseif ($mode === 'courier_hdd') {
                        $flash = 'Your task was submitted. Ship your hard drive or media to the studio as agreed — add tracking or notes in project details if you like.';
                    } else {
                        $flash = 'Your task was submitted. We’ll assign an editor and update the status here.';
                    }
                }
            }
        }
    }
}

$tasks = akh_tasks_for_client($user);
$displayTasks = array_values(array_filter($tasks, static function (array $t): bool {
    return !akh_task_is_bundle_child($t);
}));
$openTicketId = trim((string) ($_GET['ticket'] ?? ''));
$clientBellCount = akh_task_client_unread_editor_count($user);
$clientBoardSig = akh_task_poll_signature_client($user);
$clientBellNotices = akh_task_client_notice_rows($user);
$pageCsrf = akh_csrf_token();

$editId = trim((string) ($_GET['edit'] ?? ''));
$editTask = null;
if ($editId !== '') {
    foreach ($tasks as $t) {
        if (($t['id'] ?? '') !== $editId || !akh_task_client_may_edit($t)) {
            continue;
        }
        if (akh_task_is_bundle_child($t)) {
            $pid = trim((string) ($t['parent_task_id'] ?? ''));
            if ($pid !== '') {
                header('Location: ' . base_path('customer/dashboard.php?edit=' . rawurlencode($pid)));

                exit;
            }
        }
        $editTask = $t;
        break;
    }
}

$showNewTaskForm = $editTask === null
    && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && trim((string) ($_POST['task_action'] ?? '')) === 'create_task'
    && $error !== '';

require_once AKH_ROOT . '/includes/header.php';
?>

  <main id="main" class="portal-main portal-main--board">
    <div class="portal-card portal-card--tasks portal-card--ticketboard">
      <header class="desk-head desk-head--client">
        <div>
          <h1 class="portal-title">Welcome, <?php echo h($user); ?></h1>
          <p class="portal-lead">Submit footage with a <strong>Google Drive</strong> link, request <strong>NAS / Nextcloud</strong> space, or choose <strong>courier / hard drive</strong> if your partner will ship media to us. NAS opens our drive portal in a <strong>new tab</strong> after you submit. Track every task below.</p>
        </div>
        <div class="desk-bell-wrap">
          <button
            type="button"
            class="desk-bell<?php echo $clientBellCount > 0 ? ' desk-bell--wiggle desk-bell--pop' : ' desk-bell--zero'; ?>"
            aria-expanded="false"
            aria-haspopup="true"
            title="Notifications — jump to a task with editor updates"
          >
            <span class="desk-bell__icon" aria-hidden="true">
              <svg width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M12 22a2 2 0 002-2H10a2 2 0 002 2zm6-6V11a6 6 0 10-12 0v5l-2 2v1h16v-1l-2-2z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/>
              </svg>
            </span>
            <span class="desk-bell__count"><?php echo (int) $clientBellCount; ?></span>
            <span class="visually-hidden"><?php echo (int) $clientBellCount; ?> tasks with unread editor updates</span>
          </button>
          <div class="desk-bell-dropdown" id="client-desk-bell-dropdown" hidden></div>
        </div>
      </header>

      <?php if ($flash !== ''): ?>
        <p class="banner banner--ok" role="status"><?php echo h($flash); ?></p>
      <?php endif; ?>
      <?php if ($portalOpenUrl !== ''): ?>
        <p class="portal-actions" style="margin-top:0">
          <a class="btn btn--primary btn--block" href="<?php echo h($portalOpenUrl); ?>" target="_blank" rel="noopener noreferrer">Open upload portal (new tab)</a>
        </p>
      <?php endif; ?>
      <?php if ($error !== ''): ?>
        <p class="banner banner--err" role="alert"><?php echo h($error); ?></p>
      <?php endif; ?>

      <section class="portal-section" aria-labelledby="your-tasks-heading" id="client-desk-updates">
        <h2 id="your-tasks-heading" class="portal-section__title">Your tasks</h2>
        <p class="portal-muted" style="margin-top:-0.5rem">Each row shows the <strong>task ID</strong> and a short <strong>headline</strong>. Open a task to see full notes, links, editor deliverables, and feedback. <strong>Multi-part jobs</strong> list each deliverable below the summary so you can track every editor.</p>
        <?php if ($displayTasks === []): ?>
          <p class="portal-muted">No tasks yet — use the form below to create your first one.</p>
        <?php else: ?>
          <div class="ticket-board">
                <?php foreach ($displayTasks as $t): ?>
                  <?php
                  $tid = (string) ($t['id'] ?? '');
                  $st = (string) ($t['status'] ?? 'new');
                  $dm = (string) ($t['delivery_mode'] ?? '');
                  $headline = (string) ($t['title'] ?? '');
                  $stSlug = preg_replace('/[^a-z_]/', '', $st);
                  $delOut = trim((string) ($t['deliverable_output'] ?? ''));
                  $assignedEd = trim((string) ($t['assigned_editor'] ?? ''));
                  $isBundleParent = akh_task_is_bundle_parent($t);
                  $bundleKids = $isBundleParent ? akh_task_bundle_children($tid, $tasks) : [];
                  $showPostDelivery = !$isBundleParent && ($st === 'delivered' || $st === 'reverted');
                  $isOpen = $openTicketId !== '' && $openTicketId === $tid;
                  $clientNotify = ($t['client_editor_notify'] ?? false) === true;
                  if ($isBundleParent) {
                      $clientNotify = false;
                      foreach ($bundleKids as $ch) {
                          if (($ch['client_editor_notify'] ?? false) === true) {
                              $clientNotify = true;
                              break;
                          }
                      }
                  }
                  ?>
            <details
              class="ticket ticket--st-<?php echo h($stSlug); ?>"
              <?php echo akh_task_ticket_style_attr($t); ?>
              id="ticket-<?php echo h($tid); ?>"
              data-task-id="<?php echo h($tid); ?>"
              <?php if ($clientNotify): ?>data-ack-client="1"<?php endif; ?>
              <?php echo $isOpen ? ' open' : ''; ?>
            >
              <summary class="ticket__summary ticket__summary--bar">
                <span class="ticket__id" title="Task ID"><?php echo h($tid); ?></span>
                <span class="ticket__headline"><?php echo h($headline !== '' ? $headline : '—'); ?></span>
                <span class="ticket__meta">
                  <span class="task-badge task-badge--<?php echo h($stSlug); ?>"><?php echo h(akh_task_status_label($st)); ?></span>
                  <span class="ticket__meta-muted"><?php echo h(akh_task_delivery_mode_label($dm)); ?></span>
                  <?php if ($assignedEd !== ''): ?>
                    <span class="ticket__meta-muted"><?php echo h($assignedEd); ?></span>
                  <?php endif; ?>
                  <?php if (akh_task_client_may_edit($t)): ?>
                    <a class="btn btn--ghost btn--sm ticket__summary-action" href="<?php echo h(base_path('customer/dashboard.php?edit=' . rawurlencode($tid))); ?>" onclick="event.stopPropagation();">Edit draft</a>
                  <?php endif; ?>
                </span>
              </summary>
              <div class="ticket__body">
                <div class="ticket__split">
                  <div class="ticket__main">
                    <dl class="ticket__dl">
                      <div><dt>Updated</dt><dd><?php echo h((string) ($t['updated_at'] ?? '—')); ?></dd></div>
                      <div><dt>Delivery</dt><dd><?php echo h(akh_task_delivery_mode_label($dm)); ?></dd></div>
                      <div><dt>Editor</dt><dd><?php echo h($isBundleParent ? '— (per part below)' : ($assignedEd !== '' ? $assignedEd : '—')); ?></dd></div>
                    </dl>
                    <?php if ($isBundleParent && $bundleKids !== []): ?>
                      <div class="ticket__block">
                        <h3 class="ticket__block-title">Deliverables (separate editor tasks)</h3>
                        <ul class="portal-muted" style="margin:0;padding-left:1.1rem;line-height:1.55">
                          <?php foreach ($bundleKids as $ch): ?>
                            <?php
                            $cid = (string) ($ch['id'] ?? '');
                            $cst = (string) ($ch['status'] ?? 'new');
                            $cstSlug = preg_replace('/[^a-z_]/', '', $cst);
                            $ced = trim((string) ($ch['assigned_editor'] ?? ''));
                            $ctype = akh_task_edit_type_label((string) ($ch['edit_type'] ?? ''));
                            ?>
                            <li>
                              <strong><?php echo h($ctype); ?></strong>
                              — <span class="task-badge task-badge--<?php echo h($cstSlug); ?>"><?php echo h(akh_task_status_label($cst)); ?></span>
                              <?php if ($ced !== ''): ?> · <?php echo h($ced); ?><?php endif; ?>
                              · <span class="admin-table__mono"><?php echo h($cid); ?></span>
                            </li>
                          <?php endforeach; ?>
                        </ul>
                      </div>
                    <?php endif; ?>
                    <?php if (trim((string) ($t['description'] ?? '')) !== ''): ?>
                      <div class="ticket__block">
                        <h3 class="ticket__block-title">Brief &amp; notes</h3>
                        <div class="ticket__prose"><?php echo nl2br(h((string) ($t['description'] ?? ''))); ?></div>
                      </div>
                    <?php endif; ?>
                    <?php if (trim((string) ($t['reference_link'] ?? '')) !== ''): ?>
                      <p class="ticket__line"><a class="text-link" href="<?php echo h((string) $t['reference_link']); ?>" target="_blank" rel="noopener noreferrer">Reference / style link</a></p>
                    <?php endif; ?>
                    <?php if ($dm === 'google_drive' && trim((string) ($t['drive_link'] ?? '')) !== ''): ?>
                      <p class="ticket__line"><a class="text-link" href="<?php echo h((string) $t['drive_link']); ?>" target="_blank" rel="noopener noreferrer">Your footage (Google Drive)</a></p>
                    <?php endif; ?>
                    <?php if (!$isBundleParent && $delOut !== ''): ?>
                      <div class="ticket__block ticket__block--deliverable">
                        <h3 class="ticket__block-title">Final deliverable<?php echo $assignedEd !== '' ? ' — from ' . h($assignedEd) : ''; ?></h3>
                        <div class="ticket__prose"><?php echo nl2br(h($delOut)); ?></div>
                      </div>
                    <?php endif; ?>
                    <?php if (!$isBundleParent && trim((string) ($t['client_feedback'] ?? '')) !== ''): ?>
                      <div class="ticket__block">
                        <h3 class="ticket__block-title">Your feedback (on file)</h3>
                        <div class="ticket__prose"><?php echo nl2br(h((string) $t['client_feedback'])); ?></div>
                      </div>
                    <?php endif; ?>
                    <?php if (!$isBundleParent && trim((string) ($t['client_meeting_date'] ?? '')) !== ''): ?>
                      <p class="ticket__line portal-muted"><strong>Meeting:</strong> <?php echo h((string) $t['client_meeting_date']); ?>
                        <?php if (trim((string) ($t['client_meeting_link'] ?? '')) !== ''): ?>
                          — <a class="text-link" href="<?php echo h((string) $t['client_meeting_link']); ?>" target="_blank" rel="noopener noreferrer">Google Meet</a>
                        <?php endif; ?>
                      </p>
                    <?php endif; ?>
                    <?php if ($showPostDelivery): ?>
                      <form class="portal-form portal-form--compact ticket__form" method="post" action="">
                        <input type="hidden" name="csrf_token" value="<?php echo h($pageCsrf); ?>" />
                        <input type="hidden" name="task_action" value="save_delivered" />
                        <input type="hidden" name="task_id" value="<?php echo h($tid); ?>" />
                        <h3 class="ticket__block-title">After delivery — feedback &amp; meeting</h3>
                        <p class="portal-muted" style="margin-top:0">Saving notifies your editor and sets this task to <strong>returned for revision</strong> until they deliver again.</p>
                        <label class="field">
                          <span>Feedback for the team</span>
                          <textarea name="client_feedback" rows="3" maxlength="4000" placeholder="What you loved, changes for next time, thank-you…"><?php echo h((string) ($t['client_feedback'] ?? '')); ?></textarea>
                        </label>
                        <div class="portal-form__row">
                          <label class="field">
                            <span>Preferred meeting date</span>
                            <input type="date" name="client_meeting_date" value="<?php echo h((string) ($t['client_meeting_date'] ?? '')); ?>" />
                          </label>
                          <label class="field">
                            <span>Google Meet link</span>
                            <input type="url" name="client_meeting_link" maxlength="2000" placeholder="https://meet.google.com/…" value="<?php echo h((string) ($t['client_meeting_link'] ?? '')); ?>" />
                          </label>
                        </div>
                        <p class="portal-note">If you schedule a call, use a real <strong>Google Meet</strong> link and pick the date you want. Leave both date and Meet empty if you only want to send written feedback.</p>
                        <button type="submit" class="btn btn--primary btn--sm">Save feedback / meeting</button>
                      </form>
                    <?php endif; ?>
                    <?php if ($isBundleParent): ?>
                      <?php foreach ($bundleKids as $ch): ?>
                        <?php
                        $cid = (string) ($ch['id'] ?? '');
                        $cst = (string) ($ch['status'] ?? 'new');
                        $chDel = trim((string) ($ch['deliverable_output'] ?? ''));
                        $chEd = trim((string) ($ch['assigned_editor'] ?? ''));
                        $chShowPost = ($cst === 'delivered' || $cst === 'reverted');
                        ?>
                        <?php if ($chDel !== '' || trim((string) ($ch['client_feedback'] ?? '')) !== '' || $chShowPost): ?>
                          <div class="ticket__block" style="margin-top:0.75rem;padding-top:0.75rem;border-top:1px solid rgba(0,0,0,0.08)">
                            <h3 class="ticket__block-title"><?php echo h(akh_task_edit_type_label((string) ($ch['edit_type'] ?? ''))); ?> <span class="admin-table__mono"><?php echo h($cid); ?></span></h3>
                            <?php if ($chDel !== ''): ?>
                              <div class="ticket__prose"><?php echo nl2br(h($chDel)); ?></div>
                            <?php endif; ?>
                            <?php if (trim((string) ($ch['client_feedback'] ?? '')) !== ''): ?>
                              <p class="portal-muted" style="margin-top:0.5rem"><strong>Your feedback:</strong></p>
                              <div class="ticket__prose"><?php echo nl2br(h((string) $ch['client_feedback'])); ?></div>
                            <?php endif; ?>
                            <?php if ($chShowPost): ?>
                              <form class="portal-form portal-form--compact ticket__form" method="post" action="" style="margin-top:0.5rem">
                                <input type="hidden" name="csrf_token" value="<?php echo h($pageCsrf); ?>" />
                                <input type="hidden" name="task_action" value="save_delivered" />
                                <input type="hidden" name="task_id" value="<?php echo h($cid); ?>" />
                                <p class="portal-muted" style="margin-top:0">Feedback for this part only — notifies <?php echo h($chEd !== '' ? $chEd : 'your editor'); ?>.</p>
                                <label class="field">
                                  <span>Feedback</span>
                                  <textarea name="client_feedback" rows="2" maxlength="4000"><?php echo h((string) ($ch['client_feedback'] ?? '')); ?></textarea>
                                </label>
                                <div class="portal-form__row">
                                  <label class="field">
                                    <span>Meeting date</span>
                                    <input type="date" name="client_meeting_date" value="<?php echo h((string) ($ch['client_meeting_date'] ?? '')); ?>" />
                                  </label>
                                  <label class="field">
                                    <span>Google Meet</span>
                                    <input type="url" name="client_meeting_link" maxlength="2000" placeholder="https://meet.google.com/…" value="<?php echo h((string) ($ch['client_meeting_link'] ?? '')); ?>" />
                                  </label>
                                </div>
                                <button type="submit" class="btn btn--primary btn--sm">Save for this part</button>
                              </form>
                            <?php endif; ?>
                          </div>
                        <?php endif; ?>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </div>
                  <?php if ($isBundleParent): ?>
                    <div class="ticket__thread-stack" style="min-width:min(100%,14rem)">
                      <?php foreach ($bundleKids as $ch): ?>
                        <?php if (trim((string) ($ch['assigned_editor'] ?? '')) !== ''): ?>
                          <div style="margin-bottom:1rem">
                            <p class="portal-muted" style="font-size:0.82rem;margin:0 0 0.35rem"><?php echo h(akh_task_edit_type_label((string) ($ch['edit_type'] ?? ''))); ?></p>
                            <?php akh_render_task_thread_panel($ch, 'client', $pageCsrf); ?>
                          </div>
                        <?php endif; ?>
                      <?php endforeach; ?>
                    </div>
                  <?php else: ?>
                    <?php akh_render_task_thread_panel($t, 'client', $pageCsrf); ?>
                  <?php endif; ?>
                </div>
              </div>
            </details>
                <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>

      <?php if ($editTask !== null): ?>
        <section class="portal-section" aria-labelledby="edit-task-heading">
          <h2 id="edit-task-heading" class="portal-section__title">Edit task</h2>
          <p class="portal-muted">You can edit until an editor is assigned. <a class="text-link" href="<?php echo h(base_path('customer/dashboard.php')); ?>">Cancel</a></p>
          <form class="portal-form portal-form--task" method="post" action="">
            <input type="hidden" name="csrf_token" value="<?php echo h(akh_csrf_token()); ?>" />
            <input type="hidden" name="task_action" value="update_task" />
            <input type="hidden" name="task_id" value="<?php echo h((string) ($editTask['id'] ?? '')); ?>" />
            <?php
            $ev = $editTask;
            $edCouple = (string) ($ev['couple_name'] ?? '');
            $edType = (string) ($ev['edit_type'] ?? '');
            $edProj = (string) ($ev['project_details'] ?? '');
            $edRef = (string) ($ev['reference_link'] ?? '');
            $edMode = (string) ($ev['delivery_mode'] ?? 'google_drive');
            $edDrive = (string) ($ev['drive_link'] ?? '');
            $edIsBundleParent = akh_task_is_bundle_parent($ev);
            $edBundleTypes = $edIsBundleParent && is_array($ev['bundle_edit_types'] ?? null) ? $ev['bundle_edit_types'] : [];
            ?>
            <label class="field">
              <span>Couple / project name <span class="req">*</span></span>
              <input type="text" name="couple_name" required maxlength="200" value="<?php echo h($edCouple); ?>" />
            </label>
            <?php if ($edIsBundleParent): ?>
              <input type="hidden" name="edit_type" value="bundle_parent" />
              <fieldset class="field field--fieldset">
                <legend>Types of edit</legend>
                <p class="portal-muted" style="margin:0 0 0.5rem;font-size:0.88rem">Locked while the job is open for editors. Contact the studio if you need to change deliverables.</p>
                <?php foreach (akh_task_client_edit_types() as $slug => $label): ?>
                  <label class="field field--checkbox" style="margin:0.15rem 0">
                    <input type="checkbox" disabled<?php echo in_array($slug, $edBundleTypes, true) ? ' checked' : ''; ?> />
                    <span><?php echo h($label); ?></span>
                  </label>
                <?php endforeach; ?>
              </fieldset>
            <?php else: ?>
              <label class="field">
                <span>Type of edit <span class="req">*</span></span>
                <select name="edit_type" required>
                  <?php foreach (akh_task_client_edit_types() as $slug => $label): ?>
                    <option value="<?php echo h($slug); ?>"<?php echo $slug === $edType ? ' selected' : ''; ?>><?php echo h($label); ?></option>
                  <?php endforeach; ?>
                  <?php if ($edType !== '' && !array_key_exists($edType, akh_task_client_edit_types())): ?>
                    <option value="<?php echo h($edType); ?>" selected><?php echo h(akh_task_edit_type_label($edType)); ?></option>
                  <?php endif; ?>
                </select>
              </label>
            <?php endif; ?>
            <label class="field">
              <span>Project details <span class="req">*</span></span>
              <textarea name="project_details" rows="6" required maxlength="8000"><?php echo h($edProj); ?></textarea>
            </label>
            <label class="field">
              <span>Reference video / style link <span class="req">*</span></span>
              <input type="url" name="reference_link" required maxlength="2000" value="<?php echo h($edRef); ?>" />
            </label>
            <fieldset class="field field--fieldset">
              <legend>Footage <span class="req">*</span></legend>
              <label class="field field--radio">
                <input type="radio" name="delivery_mode" value="google_drive"<?php echo $edMode === 'google_drive' ? ' checked' : ''; ?> />
                <span><strong>Google Drive</strong> link below</span>
              </label>
              <label class="field field--radio">
                <input type="radio" name="delivery_mode" value="nas_storage"<?php echo $edMode === 'nas_storage' ? ' checked' : ''; ?> />
                <span><strong>NAS / Nextcloud</strong> (open portal in new tab after save)</span>
              </label>
              <label class="field field--radio">
                <input type="radio" name="delivery_mode" value="courier_hdd"<?php echo $edMode === 'courier_hdd' ? ' checked' : ''; ?> />
                <span><strong>Courier / hard drive</strong> — partner ships media; no Drive link</span>
              </label>
            </fieldset>
            <label class="field" id="edit-drive-link-field">
              <span>Google Drive link <span class="req" id="edit-drive-link-req" aria-hidden="true">*</span></span>
              <input type="url" name="drive_link" maxlength="2000" value="<?php echo h($edDrive); ?>" />
            </label>
            <p class="portal-note" id="edit-drive-link-hint"></p>
            <button type="submit" class="btn btn--primary btn--block">Save changes</button>
          </form>
        </section>
      <?php endif; ?>

      <?php if ($editTask === null): ?>
      <section class="portal-section" aria-labelledby="new-task-heading">
        <h2 id="new-task-heading" class="portal-section__title">New task</h2>
        <div id="new-task-launch" class="portal-new-task__launch"<?php echo $showNewTaskForm ? ' hidden' : ''; ?>>
          <p class="portal-muted" style="margin-top:0">When you’re ready, open the form to enter your brief and footage options.</p>
          <button type="button" class="btn btn--primary btn--sm portal-new-task__reveal-btn" id="new-task-reveal-btn" aria-expanded="<?php echo $showNewTaskForm ? 'true' : 'false'; ?>" aria-controls="new-task-form-wrap">Create new task</button>
        </div>
        <div id="new-task-form-wrap" class="portal-new-task__form"<?php echo $showNewTaskForm ? '' : ' hidden'; ?> role="region" aria-labelledby="new-task-form-title" tabindex="-1">
          <h3 id="new-task-form-title" class="portal-section__sub" style="margin:0 0 0.75rem;font-size:1.05rem;font-weight:600">Task details</h3>
          <div class="portal-callout" role="note">
            <p><strong>Types of edit:</strong> choose <strong>every</strong> deliverable you need from the list (multiple allowed). More than one type still shows as <strong>one job</strong> for you, with a separate <strong>editor task</strong> per type. A single type stays one classic task.</p>
          </div>
          <form class="portal-form portal-form--task" method="post" action="">
            <input type="hidden" name="csrf_token" value="<?php echo h(akh_csrf_token()); ?>" />
            <input type="hidden" name="task_action" value="create_task" />
            <label class="field">
              <span>Couple / project name <span class="req">*</span></span>
              <input type="text" name="couple_name" required maxlength="200" placeholder="e.g. Meera &amp; Rahul" autocomplete="name" />
            </label>
            <div class="field">
              <span>Type(s) of edit <span class="req">*</span></span>
              <div class="portal-ms" id="new-task-edit-ms" data-portal-ms>
                <button type="button" class="portal-ms__trigger" id="new-task-ms-trigger" aria-expanded="false" aria-haspopup="listbox" aria-controls="new-task-ms-panel">
                  <span class="portal-ms__summary" id="new-task-ms-summary">Select types…</span>
                  <span class="portal-ms__chevron" aria-hidden="true">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                  </span>
                </button>
                <div class="portal-ms__panel" id="new-task-ms-panel" role="listbox" aria-labelledby="new-task-ms-trigger" hidden>
                  <?php foreach (akh_task_client_edit_types() as $slug => $label): ?>
                    <label class="portal-ms__row">
                      <span class="portal-ms__hit">
                        <input type="checkbox" name="edit_types[]" value="<?php echo h($slug); ?>" class="portal-ms__check" />
                        <span class="portal-ms__box" aria-hidden="true"></span>
                      </span>
                      <span class="portal-ms__text"><?php echo h($label); ?></span>
                    </label>
                  <?php endforeach; ?>
                </div>
              </div>
              <span class="portal-note" id="new-task-edit-types-hint">Open the list, then tick every deliverable you need.</span>
            </div>
            <label class="field">
              <span>Project details <span class="req">*</span></span>
              <textarea name="project_details" rows="6" required maxlength="8000" placeholder="Deliverables, pacing, music, deadlines, and for “Other” describe exactly what you need…"></textarea>
            </label>
            <label class="field">
              <span>Reference video / style link <span class="req">*</span></span>
              <input type="url" name="reference_link" required maxlength="2000" placeholder="https://…" inputmode="url" />
            </label>
            <fieldset class="field field--fieldset">
              <legend>Footage: Drive, cloud upload, or courier <span class="req">*</span></legend>
              <label class="field field--radio">
                <input type="radio" name="delivery_mode" value="google_drive" required checked />
                <span>I’ll share a <strong>Google Drive</strong> link to my footage (paste below). <span class="req">*</span></span>
              </label>
              <label class="field field--radio">
                <input type="radio" name="delivery_mode" value="nas_storage" />
                <span><strong>NAS / Nextcloud</strong> — after submit, open our upload portal in a <strong>new tab</strong> (no Drive link here).</span>
              </label>
              <label class="field field--radio">
                <input type="radio" name="delivery_mode" value="courier_hdd" />
                <span><strong>Courier / hard drive / copy locally</strong> — partner will ship media to the studio (no Drive link required).</span>
              </label>
            </fieldset>
            <label class="field" id="drive-link-field">
              <span>Google Drive link <span class="req" id="drive-link-req" aria-hidden="true">*</span></span>
              <input type="url" name="drive_link" maxlength="2000" placeholder="https://drive.google.com/..." inputmode="url" />
            </label>
            <p class="portal-note" id="drive-link-hint">Required for Google Drive. Hidden for NAS or courier.</p>
            <button type="submit" class="btn btn--primary btn--block">Submit task</button>
          </form>
        </div>
      </section>
      <?php endif; ?>

      <script>
        (function () {
          function bind(form, ids) {
            if (!form) return;
            var linkInput = form.querySelector('input[name="drive_link"]');
            var linkField = document.getElementById(ids.field);
            var hint = document.getElementById(ids.hint);
            var reqMark = document.getElementById(ids.req);
            if (!linkInput) return;
            function sync() {
              var google = form.querySelector('input[name="delivery_mode"][value="google_drive"]').checked;
              if (google) {
                linkInput.setAttribute('required', 'required');
                if (linkField) linkField.removeAttribute('hidden');
                if (reqMark) reqMark.removeAttribute('hidden');
                if (hint) hint.textContent = 'Required when sharing footage via Google Drive (https link).';
              } else {
                linkInput.removeAttribute('required');
                linkInput.value = '';
                if (linkField) linkField.setAttribute('hidden', '');
                if (reqMark) reqMark.setAttribute('hidden', '');
                if (hint) {
                  hint.textContent = form.querySelector('input[name="delivery_mode"][value="nas_storage"]').checked
                    ? 'NAS — after submit/save use “Open upload portal (new tab)” when shown.'
                    : 'Courier — no Drive link. Ship media as agreed with the studio.';
                }
              }
            }
            form.querySelectorAll('input[name="delivery_mode"]').forEach(function (r) { r.addEventListener('change', sync); });
            sync();
          }
          var nf = document.querySelector('input[name="task_action"][value="create_task"]');
          var createForm = nf ? nf.closest('form') : null;
          var launch = document.getElementById('new-task-launch');
          var wrap = document.getElementById('new-task-form-wrap');
          var revealBtn = document.getElementById('new-task-reveal-btn');
          if (revealBtn && launch && wrap) {
            revealBtn.addEventListener('click', function () {
              launch.hidden = true;
              wrap.hidden = false;
              revealBtn.setAttribute('aria-expanded', 'true');
              var first = wrap.querySelector('input[name="couple_name"]');
              if (first) {
                first.focus();
              } else {
                wrap.focus();
              }
            });
          }
          if (createForm) {
            bind(createForm, { field: 'drive-link-field', hint: 'drive-link-hint', req: 'drive-link-req' });
            createForm.addEventListener('submit', function (e) {
              var checked = createForm.querySelectorAll('input[name="edit_types[]"]:checked');
              if (!checked || checked.length < 1) {
                e.preventDefault();
                alert('Select at least one type of edit.');
              }
            });
          }
          document.querySelectorAll('[data-portal-ms]').forEach(function (container) {
            var trigger = container.querySelector('.portal-ms__trigger');
            var panel = container.querySelector('.portal-ms__panel');
            var summary = container.querySelector('.portal-ms__summary');
            var checks = container.querySelectorAll('input[name="edit_types[]"]');
            if (!trigger || !panel || !summary) return;
            function countSel() {
              var n = 0;
              checks.forEach(function (c) {
                if (c.checked) {
                  n++;
                }
              });
              return n;
            }
            function syncSummary() {
              var n = countSel();
              summary.textContent = n === 0 ? 'Select types…' : (n + ' selected');
              container.classList.toggle('has-selection', n > 0);
            }
            function setOpen(open) {
              container.classList.toggle('is-open', open);
              panel.hidden = !open;
              trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
            }
            trigger.addEventListener('click', function () {
              setOpen(panel.hidden);
            });
            checks.forEach(function (c) {
              c.addEventListener('change', syncSummary);
            });
            document.addEventListener('click', function (e) {
              if (!container.classList.contains('is-open')) return;
              if (!container.contains(e.target)) setOpen(false);
            });
            document.addEventListener('keydown', function (e) {
              if (e.key === 'Escape' && container.classList.contains('is-open')) setOpen(false);
            });
            syncSummary();
          });
          var uf = document.querySelector('input[name="task_action"][value="update_task"]');
          if (uf) bind(uf.closest('form'), { field: 'edit-drive-link-field', hint: 'edit-drive-link-hint', req: 'edit-drive-link-req' });
        })();
      </script>
      <?php
      $akhPushJs = AKH_ROOT . '/assets/js/portal-push-notify.js';
      $akhPushVer = is_file($akhPushJs) ? (string) filemtime($akhPushJs) : '1';
      ?>
      <script>
        window._akhPortalPush = {
          mode: 'client',
          siteName: <?php echo json_encode(SITE_NAME, JSON_THROW_ON_ERROR); ?>,
          csrf: <?php echo json_encode($pageCsrf, JSON_THROW_ON_ERROR); ?>,
          bell: <?php echo (int) $clientBellCount; ?>,
          pool: 0,
          sig: <?php echo json_encode($clientBoardSig, JSON_THROW_ON_ERROR); ?>,
          notices: <?php echo json_encode($clientBellNotices, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES); ?>
        };
      </script>
      <script defer src="<?php echo h(base_path('assets/js/portal-push-notify.js')); ?>?v=<?php echo h($akhPushVer); ?>"></script>
      <script>
        (function () {
          var csrf = <?php echo json_encode($pageCsrf, JSON_THROW_ON_ERROR); ?>;
          function setDeskBell(n) {
            var b = document.querySelector('.desk-bell-wrap .desk-bell');
            if (!b) return;
            var c = b.querySelector('.desk-bell__count');
            if (c && typeof n === 'number') c.textContent = String(n);
            b.classList.toggle('desk-bell--zero', typeof n === 'number' && n < 1);
            if (typeof n === 'number' && n > 0) {
              b.classList.add('desk-bell--wiggle', 'desk-bell--pop');
            } else {
              b.classList.remove('desk-bell--wiggle', 'desk-bell--pop');
            }
          }
          function postAck(taskId) {
            var fd = new URLSearchParams();
            fd.set('ajax_action', 'view_ack');
            fd.set('task_id', taskId);
            fd.set('csrf_token', csrf);
            return fetch(window.location.pathname, {
              method: 'POST',
              headers: { 'X-Requested-With': 'XMLHttpRequest' },
              body: fd
            }).then(function (r) { return r.json(); });
          }
          function ackOpenedClient(el) {
            if (!el || el.getAttribute('data-ack-client') !== '1') return;
            el.removeAttribute('data-ack-client');
            var tid = el.getAttribute('data-task-id');
            if (!tid) return;
            postAck(tid).then(function (j) {
              if (j && j.ok && typeof j.bell === 'number') setDeskBell(j.bell);
            }).catch(function () {});
          }
          document.addEventListener('toggle', function (e) {
            var el = e.target;
            if (!el || !el.classList || !el.classList.contains('ticket') || !el.open) return;
            ackOpenedClient(el);
          }, true);
          document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('details.ticket[open]').forEach(ackOpenedClient);
          });
        })();
      </script>

      <div class="portal-actions">
        <a class="btn btn--ghost btn--block" href="<?php echo h(DRIVE_PORTAL_URL); ?>" target="_blank" rel="noopener noreferrer">Open client drive (NAS)</a>
      </div>

      <p class="portal-foot">
        <a class="text-link" href="<?php echo h(base_path('customer/logout.php')); ?>">Sign out</a>
        ·
        <a class="text-link" href="<?php echo h(base_path('index.php')); ?>">Website home</a>
      </p>
    </div>
  </main>

<?php require_once AKH_ROOT . '/includes/footer.php'; ?>
