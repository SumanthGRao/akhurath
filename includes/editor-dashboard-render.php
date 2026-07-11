<?php

declare(strict_types=1);

require_once __DIR__ . '/dashboard-alerts.php';
require_once __DIR__ . '/task-thread-panel.php';

/**
 * @param array<string, mixed> $t
 * @param array<string, array<string, mixed>> $dashboardAlerts
 * @param array<string, bool> $editorReminderCodes
 * @param list<string> $seenNew
 * @return array<string, mixed>
 */
function akh_editor_task_view_model(
    array $t,
    string $editor,
    array $dashboardAlerts,
    array $editorReminderCodes,
    array $seenNew,
    string $section
): array {
    $tid = (string) ($t['id'] ?? '');
    $tidNorm = akh_task_normalize_id($tid);
    $st = (string) ($t['status'] ?? ($section === 'pool' ? 'new' : 'assigned'));
    $stSlug = preg_replace('/[^a-z_]/', '', $st);
    $taskAlert = $dashboardAlerts[$tidNorm] ?? null;
    if ($taskAlert === null && $tidNorm !== '') {
        foreach ($dashboardAlerts as $key => $alert) {
            if (akh_task_ids_match((string) $key, $tidNorm)) {
                $taskAlert = $alert;
                break;
            }
        }
    }
    $notify = ($t['editor_feedback_notify'] ?? false) === true || $taskAlert !== null;
    $unseenNew = $section === 'pool' && $tid !== '' && !in_array($tid, $seenNew, true);
    $hasReminder = isset($editorReminderCodes[$tidNorm]);
    $classes = ['edesk-list__item', 'ticket--st-' . $stSlug];
    if ($notify) {
        $classes[] = 'edesk-list__item--notify';
    }
    if ($unseenNew) {
        $classes[] = 'edesk-list__item--new';
    }
    if ($hasReminder) {
        $classes[] = 'edesk-list__item--meeting';
    }

    return [
        'task' => $t,
        'tid' => $tid,
        'tid_norm' => $tidNorm,
        'section' => $section,
        'status' => $st,
        'status_slug' => $stSlug,
        'headline' => (string) ($t['title'] ?? ''),
        'delivery_mode' => (string) ($t['delivery_mode'] ?? ''),
        'task_alert' => $taskAlert,
        'notify' => $notify,
        'unseen_new' => $unseenNew,
        'has_reminder' => $hasReminder,
        'list_classes' => implode(' ', $classes),
        'style_attr' => akh_task_ticket_style_attr($t),
        'ack_new' => $unseenNew,
        'ack_editor' => $notify && $section === 'mine',
    ];
}

/**
 * @param array<string, mixed> $vm
 */
function akh_editor_render_list_item(array $vm, bool $selected = false): void
{
    $t = $vm['task'];
    $tid = (string) $vm['tid'];
    if ($tid === '') {
        return;
    }
    $headline = (string) $vm['headline'];
    $st = (string) $vm['status'];
    $stSlug = (string) $vm['status_slug'];
    $section = (string) $vm['section'];
    ?>
    <button
      type="button"
      class="<?php echo h((string) $vm['list_classes']); ?><?php echo $selected ? ' edesk-list__item--active' : ''; ?>"
      <?php echo (string) $vm['style_attr']; ?>
      id="ticket-<?php echo h($tid); ?>"
      data-task-id="<?php echo h($tid); ?>"
      data-section="<?php echo h($section); ?>"
      data-updated-at="<?php echo h((string) ($t['updated_at'] ?? '')); ?>"
      <?php if ($vm['ack_new']): ?>data-ack-new="1"<?php endif; ?>
      <?php if ($vm['ack_editor']): ?>data-ack-editor="1"<?php endif; ?>
      aria-current="<?php echo $selected ? 'true' : 'false'; ?>"
    >
      <span class="edesk-list__row">
        <span class="edesk-list__id"><?php echo h($tid); ?></span>
        <span class="edesk-list__title">
          <?php if ($vm['notify']): ?>
            <span class="edesk-list__dot" aria-hidden="true"></span>
          <?php endif; ?>
          <?php echo h($headline !== '' ? $headline : '—'); ?>
        </span>
      </span>
      <span class="edesk-list__meta">
        <?php if ($vm['unseen_new']): ?>
          <span class="ticket__pill ticket__pill--new">New</span>
        <?php endif; ?>
        <?php if ($section === 'mine'): ?>
          <span class="task-badge task-badge--<?php echo h($stSlug); ?>"><?php echo h(akh_task_status_label($st)); ?></span>
        <?php endif; ?>
        <?php if ($vm['has_reminder']): ?>
          <span class="edesk-list__pill edesk-list__pill--soon">Soon</span>
        <?php endif; ?>
        <span class="edesk-list__when" data-ts="<?php echo h((string) ($t['updated_at'] ?? '')); ?>"><?php echo h((string) ($t['updated_at'] ?? '')); ?></span>
        <span class="edesk-list__client"><?php echo h((string) ($t['client_username'] ?? '')); ?></span>
      </span>
    </button>
    <?php
}

/**
 * @param array<string, mixed> $vm
 */
function akh_editor_render_detail_panel(array $vm, string $pageCsrf): void
{
    $t = $vm['task'];
    $tid = (string) $vm['tid'];
    if ($tid === '') {
        return;
    }
    $section = (string) $vm['section'];
    $st = (string) $vm['status'];
    $stSlug = (string) $vm['status_slug'];
    $dm = (string) $vm['delivery_mode'];
    $headline = (string) $vm['headline'];
    $taskAlert = $vm['task_alert'];
    $opts = ['assigned', 'in_progress', 'review', 'preview_sent', 'delivered', 'reverted', 'closed'];
    $pipelineOpts = $section === 'pool' ? ['new', 'assigned'] : $opts;
    ?>
    <article
      class="edesk-panel ticket ticket--st-<?php echo h($stSlug); ?>"
      <?php echo (string) $vm['style_attr']; ?>
      data-task-id="<?php echo h($tid); ?>"
      data-section="<?php echo h($section); ?>"
      hidden
    >
      <header class="edesk-panel__head">
        <div class="edesk-panel__head-main">
          <p class="edesk-panel__kicker"><?php echo h($tid); ?></p>
          <h2 class="edesk-panel__title"><?php echo h($headline !== '' ? $headline : '—'); ?></h2>
          <div class="edesk-panel__chips">
            <?php if ($section === 'mine'): ?>
              <span class="task-badge task-badge--<?php echo h($stSlug); ?>"><?php echo h(akh_task_status_label($st)); ?></span>
            <?php else: ?>
              <span class="edesk-panel__chip">Pool</span>
            <?php endif; ?>
            <span class="edesk-panel__chip"><?php echo h((string) ($t['client_username'] ?? '—')); ?></span>
            <span class="edesk-panel__chip"><?php echo h(akh_task_delivery_mode_label($dm)); ?></span>
            <?php if (akh_task_is_bundle_child($t)): ?>
              <span class="edesk-panel__chip">Part of <?php echo h((string) ($t['parent_task_id'] ?? '')); ?></span>
            <?php endif; ?>
          </div>
        </div>
        <dl class="edesk-panel__facts">
          <div><dt>Created</dt><dd><?php echo h((string) ($t['created_at'] ?? '—')); ?></dd></div>
          <div><dt>Updated</dt><dd><?php echo h((string) ($t['updated_at'] ?? '—')); ?></dd></div>
        </dl>
      </header>

      <?php if ($section === 'mine'): ?>
        <nav class="edesk-pipeline" aria-label="Workflow progress">
          <?php foreach ($pipelineOpts as $o): ?>
            <?php if ($o === 'new') { continue; } ?>
            <button
              type="button"
              class="edesk-pipeline__step<?php echo $o === $st ? ' edesk-pipeline__step--current' : ''; ?><?php echo array_search($o, $pipelineOpts, true) !== false && array_search($st, $pipelineOpts, true) !== false && array_search($o, $pipelineOpts, true) < array_search($st, $pipelineOpts, true) ? ' edesk-pipeline__step--done' : ''; ?>"
              data-status="<?php echo h($o); ?>"
              data-task-id="<?php echo h($tid); ?>"
            ><?php echo h(akh_task_status_label($o)); ?></button>
          <?php endforeach; ?>
        </nav>
      <?php endif; ?>

      <div class="edesk-panel__grid">
        <div class="edesk-panel__work">
          <?php if (trim((string) ($t['description'] ?? '')) !== ''): ?>
            <section class="edesk-card">
              <h3 class="edesk-card__title">Brief &amp; notes</h3>
              <div class="edesk-prose"><?php echo nl2br(h((string) ($t['description'] ?? ''))); ?></div>
            </section>
          <?php endif; ?>

          <?php if (trim((string) ($t['reference_link'] ?? '')) !== '' || ($dm === 'google_drive' && trim((string) ($t['drive_link'] ?? '')) !== '')): ?>
            <section class="edesk-card edesk-card--links">
              <h3 class="edesk-card__title">Links</h3>
              <div class="edesk-link-row">
                <?php if (trim((string) ($t['reference_link'] ?? '')) !== ''): ?>
                  <a class="edesk-link" href="<?php echo h((string) $t['reference_link']); ?>" target="_blank" rel="noopener noreferrer">Reference / style</a>
                <?php endif; ?>
                <?php if ($dm === 'google_drive' && trim((string) ($t['drive_link'] ?? '')) !== ''): ?>
                  <a class="edesk-link" href="<?php echo h((string) $t['drive_link']); ?>" target="_blank" rel="noopener noreferrer">Client Drive</a>
                <?php endif; ?>
              </div>
            </section>
          <?php endif; ?>

          <?php if ($section === 'pool'): ?>
            <section class="edesk-card edesk-card--action">
              <form class="edesk-inline-form edesk-ajax-claim" method="post" action="" data-edesk-ajax="claim">
                <input type="hidden" name="csrf_token" value="<?php echo h($pageCsrf); ?>" />
                <input type="hidden" name="action" value="claim" />
                <input type="hidden" name="task_id" value="<?php echo h($tid); ?>" />
                <p class="edesk-muted">Claim this job to start editing and message the client.</p>
                <button type="submit" class="btn btn--primary">Assign to me</button>
              </form>
            </section>
          <?php else: ?>
            <section class="edesk-card edesk-card--status">
              <h3 class="edesk-card__title">Workflow &amp; deliverable</h3>
              <form class="portal-form portal-form--compact edesk-status-form edesk-ajax-status" method="post" action="" data-current-status="<?php echo h($st); ?>" data-edesk-ajax="status">
                <input type="hidden" name="csrf_token" value="<?php echo h($pageCsrf); ?>" />
                <input type="hidden" name="action" value="status" />
                <input type="hidden" name="task_id" value="<?php echo h($tid); ?>" />
                <div class="edesk-form-grid">
                  <label class="field">
                    <span>Status</span>
                    <select name="status" class="js-task-status-select" aria-label="Task status">
                      <?php foreach ($opts as $o): ?>
                        <option value="<?php echo h($o); ?>"<?php echo $o === $st ? ' selected' : ''; ?>><?php echo h(akh_task_status_label($o)); ?></option>
                      <?php endforeach; ?>
                    </select>
                  </label>
                  <label class="field field--status-comment" hidden>
                    <span>Status note <strong>(required when status changes)</strong></span>
                    <textarea name="status_comment" rows="2" maxlength="2000" placeholder="e.g. Sent preview for review…"></textarea>
                  </label>
                  <label class="field edesk-field--wide">
                    <span>Deliverable link or path</span>
                    <textarea name="deliverable_output" rows="3" maxlength="4000" placeholder="Drive, Vimeo, WeTransfer, or server path…"><?php echo h((string) ($t['deliverable_output'] ?? '')); ?></textarea>
                  </label>
                </div>
                <p class="edesk-muted edesk-muted--sm">Status changes sync to WhatsApp history. Deliverable is shown to the client when marked <strong>Delivered</strong>.</p>
                <button type="submit" class="btn btn--primary btn--sm">Save changes</button>
              </form>
            </section>
          <?php endif; ?>
        </div>

        <div class="edesk-panel__activity">
          <section class="edesk-card edesk-card--updates" aria-label="Client updates">
            <h3 class="edesk-card__title">Updates</h3>
            <?php if ($taskAlert !== null): ?>
              <article class="edesk-update edesk-update--alert">
                <p class="edesk-update__label"><?php echo h(akh_dashboard_alert_kind_label($taskAlert)); ?></p>
                <div class="edesk-prose"><?php echo nl2br(h((string) ($taskAlert['preview'] ?? ''))); ?></div>
                <?php if (trim((string) ($taskAlert['meet_link'] ?? '')) !== ''): ?>
                  <a class="edesk-link" href="<?php echo h((string) $taskAlert['meet_link']); ?>" target="_blank" rel="noopener noreferrer">Join Google Meet</a>
                <?php endif; ?>
                <?php if (trim((string) ($taskAlert['start_time'] ?? '')) !== ''): ?>
                  <p class="edesk-muted edesk-muted--sm"><strong>Starts:</strong> <?php echo h((string) $taskAlert['start_time']); ?></p>
                <?php endif; ?>
              </article>
            <?php endif; ?>
            <?php if (trim((string) ($t['client_feedback'] ?? '')) !== '' || trim((string) ($t['client_meeting_date'] ?? '')) !== ''): ?>
              <article class="edesk-update edesk-update--feedback">
                <p class="edesk-update__label">After delivery</p>
                <?php if (trim((string) ($t['client_feedback'] ?? '')) !== ''): ?>
                  <div class="edesk-prose"><?php echo nl2br(h((string) $t['client_feedback'])); ?></div>
                <?php endif; ?>
                <?php if (trim((string) ($t['client_meeting_date'] ?? '')) !== ''): ?>
                  <p class="edesk-muted edesk-muted--sm">
                    <strong>Meeting:</strong> <?php echo h((string) $t['client_meeting_date']); ?>
                    <?php if (trim((string) ($t['client_meeting_link'] ?? '')) !== ''): ?>
                      — <a class="text-link" href="<?php echo h((string) $t['client_meeting_link']); ?>" target="_blank" rel="noopener noreferrer">Google Meet</a>
                    <?php endif; ?>
                  </p>
                <?php endif; ?>
              </article>
            <?php endif; ?>
            <?php if (trim((string) ($t['deliverable_output'] ?? '')) !== '' && $section === 'mine'): ?>
              <article class="edesk-update">
                <p class="edesk-update__label">Current deliverable</p>
                <div class="edesk-prose"><?php echo nl2br(h((string) $t['deliverable_output'])); ?></div>
              </article>
            <?php endif; ?>
            <?php if (
                $taskAlert === null
                && trim((string) ($t['client_feedback'] ?? '')) === ''
                && trim((string) ($t['client_meeting_date'] ?? '')) === ''
                && trim((string) ($t['deliverable_output'] ?? '')) === ''
            ): ?>
              <p class="edesk-muted">No client updates yet for this task.</p>
            <?php endif; ?>
          </section>

          <?php if ($section === 'mine'): ?>
            <div class="edesk-card edesk-card--thread">
              <?php akh_render_task_thread_panel($t, 'editor', $pageCsrf); ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </article>
    <?php
}
