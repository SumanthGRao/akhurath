<?php

declare(strict_types=1);

require_once __DIR__ . '/dashboard-alerts.php';
require_once __DIR__ . '/task-thread-panel.php';
require_once __DIR__ . '/whatsapp-messages.php';

/**
 * Sidebar label for edit / WhatsApp task type (falls back to couple/title).
 *
 * @param array<string, mixed> $t
 */
function akh_editor_task_list_type_label(array $t): string
{
    require_once __DIR__ . '/tasks.php';

    $editType = trim((string) ($t['edit_type'] ?? ''));
    if ($editType !== '' && !in_array($editType, ['studio_admin', 'bundle_parent'], true)) {
        return akh_task_edit_type_label($editType);
    }

    $waType = trim((string) ($t['whatsapp_task_type'] ?? ''));
    if ($waType !== '') {
        return $waType;
    }

    require_once __DIR__ . '/whatsapp-tasks.php';
    if (akh_wa_tasks_table_exists()) {
        $code = akh_task_normalize_id((string) ($t['id'] ?? ''));
        if ($code !== '') {
            $waRow = akh_wa_task_by_code($code);
            if (is_array($waRow)) {
                $fromWa = trim((string) ($waRow['task_type'] ?? ''));
                if ($fromWa !== '') {
                    return $fromWa;
                }
            }
        }
    }

    $title = trim((string) ($t['title'] ?? ''));

    return $title !== '' ? $title : '—';
}

/**
 * Human-readable client label for editor desk (whatsapp_tasks.customer_name preferred).
 *
 * @param array<string, mixed> $t
 */
function akh_editor_task_customer_display_name(array $t): string
{
    require_once __DIR__ . '/tasks.php';
    require_once __DIR__ . '/whatsapp-tasks.php';
    require_once __DIR__ . '/whatsapp-messages.php';

    $name = trim((string) ($t['customer_name'] ?? ''));
    if ($name !== '') {
        return $name;
    }

    $code = akh_task_normalize_id((string) ($t['id'] ?? ''));
    if ($code !== '') {
        $wa = akh_wa_task_by_code($code);
        if (is_array($wa)) {
            $name = trim((string) ($wa['customer_name'] ?? ''));
            if ($name !== '') {
                return $name;
            }
        }

        $fromMsg = trim(akh_wa_message_customer_name_for_task($code));
        if ($fromMsg !== '') {
            return $fromMsg;
        }
    }

    $client = trim((string) ($t['client_username'] ?? ''));
    if ($client !== '' && strtolower($client) !== 'whatsapp') {
        return $client;
    }

    return '';
}

/**
 * @param array<string, mixed> $t
 * @param array<string, mixed> $vm
 */
function akh_editor_task_customer_badge_tone(array $t, array $vm): string
{
    if (!empty($vm['from_whatsapp'])) {
        return 'wa';
    }

    $code = akh_task_normalize_id((string) ($t['id'] ?? ''));
    if ($code !== '' && function_exists('akh_wa_task_by_code')) {
        require_once __DIR__ . '/whatsapp-tasks.php';
        $wa = akh_wa_task_by_code($code);
        if (is_array($wa) && trim((string) ($wa['customer_name'] ?? '')) !== '') {
            return 'wa';
        }
    }

    $client = strtolower(trim((string) ($t['client_username'] ?? '')));
    if ($client !== '' && $client !== 'whatsapp') {
        return 'portal';
    }

    return 'unknown';
}

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
    $meetingAlertUnread = is_array($taskAlert)
        && str_starts_with((string) ($taskAlert['kind'] ?? ''), 'meeting_');
    $meetingUnread = $hasReminder || $meetingAlertUnread;
    $fromWhatsapp = (string) ($t['edit_type'] ?? '') === 'studio_admin'
        || strtolower(trim((string) ($t['client_username'] ?? ''))) === 'whatsapp';
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
    if ($meetingUnread) {
        $classes[] = 'edesk-list__item--meeting-unread';
    }

    $headline = (string) ($t['title'] ?? '');
    $typeLabel = akh_editor_task_list_type_label($t);
    $showType = $typeLabel !== '' && mb_strtolower($typeLabel) !== mb_strtolower($headline);
    $listUnread = $notify || $unseenNew || $meetingUnread;
    $customerLabel = akh_editor_task_customer_display_name($t);
    $customerTone = akh_editor_task_customer_badge_tone($t, [
        'from_whatsapp' => $fromWhatsapp,
    ]);

    return [
        'task' => $t,
        'tid' => $tid,
        'tid_norm' => $tidNorm,
        'section' => $section,
        'status' => $st,
        'status_slug' => $stSlug,
        'headline' => $headline,
        'type_label' => $typeLabel,
        'show_type' => $showType,
        'customer_label' => $customerLabel,
        'customer_tone' => $customerTone,
        'list_unread' => $listUnread,
        'delivery_mode' => (string) ($t['delivery_mode'] ?? ''),
        'task_alert' => $taskAlert,
        'notify' => $notify,
        'unseen_new' => $unseenNew,
        'has_reminder' => $hasReminder,
        'meeting_unread' => $meetingUnread,
        'from_whatsapp' => $fromWhatsapp,
        'list_classes' => implode(' ', $classes),
        'style_attr' => akh_task_ticket_style_attr($t),
        'ack_new' => $unseenNew,
        'ack_editor' => $notify && $section === 'mine',
        'ack_meeting' => $meetingUnread,
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
    $listAt = $section === 'pool'
        ? (string) (($t['created_at'] ?? '') !== '' ? $t['created_at'] : ($t['updated_at'] ?? ''))
        : (string) (($t['updated_at'] ?? '') !== '' ? $t['updated_at'] : ($t['created_at'] ?? ''));
    $typeLabel = (string) ($vm['type_label'] ?? '');
    $customerLabel = (string) ($vm['customer_label'] ?? akh_editor_task_customer_display_name($t));
    $statusLabel = akh_task_status_label($st);
    $searchBlob = strtolower(implode(' ', array_filter([
        $tid,
        $headline,
        $typeLabel,
        $customerLabel,
        (string) ($t['client_username'] ?? ''),
        $statusLabel,
    ], static fn (string $p): bool => trim($p) !== '')));
    ?>
    <button
      type="button"
      class="<?php echo h((string) $vm['list_classes']); ?><?php echo $selected ? ' edesk-list__item--active' : ''; ?>"
      <?php echo (string) $vm['style_attr']; ?>
      id="ticket-<?php echo h($tid); ?>"
      data-task-id="<?php echo h($tid); ?>"
      data-section="<?php echo h($section); ?>"
      data-status="<?php echo h($stSlug); ?>"
      data-whatsapp="<?php echo !empty($vm['from_whatsapp']) ? '1' : '0'; ?>"
      data-search="<?php echo h($searchBlob); ?>"
      data-updated-at="<?php echo h((string) ($t['updated_at'] ?? '')); ?>"
      data-msg-count="<?php echo (int) akh_wa_message_unread_count_for_task((string) ($vm['tid_norm'] ?? $tid)); ?>"
      data-list-at="<?php echo h($listAt); ?>"
      <?php if ($vm['ack_new']): ?>data-ack-new="1"<?php endif; ?>
      <?php if ($vm['ack_editor']): ?>data-ack-editor="1"<?php endif; ?>
      <?php if ($vm['ack_meeting']): ?>data-ack-meeting="1"<?php endif; ?>
      data-meeting="<?php echo !empty($vm['meeting_unread']) ? '1' : '0'; ?>"
      data-notify="<?php echo $vm['notify'] ? '1' : '0'; ?>"
      data-unread="<?php echo !empty($vm['list_unread']) ? '1' : '0'; ?>"
      aria-current="<?php echo $selected ? 'true' : 'false'; ?>"
    >
      <span class="edesk-list__row">
        <span class="edesk-list__id"><?php echo h($tid); ?></span>
        <span class="edesk-list__body">
          <span class="edesk-list__title">
            <?php if ($vm['notify']): ?>
              <span class="edesk-list__dot" aria-hidden="true"></span>
            <?php endif; ?>
            <?php if (!empty($vm['show_type'])): ?>
              <span class="edesk-list__type"><?php echo h((string) $vm['type_label']); ?></span>
            <?php endif; ?>
            <span class="edesk-list__name"><?php echo h($headline !== '' ? $headline : (string) ($vm['type_label'] ?? '—')); ?></span>
          </span>
          <?php if ($customerLabel !== ''): ?>
            <span class="edesk-list__customer edesk-list__customer--<?php echo h((string) ($vm['customer_tone'] ?? 'unknown')); ?>">
              <span class="edesk-list__customer-kicker">Client</span>
              <span class="edesk-list__customer-name"><?php echo h($customerLabel); ?></span>
            </span>
          <?php endif; ?>
        </span>
      </span>
      <span class="edesk-list__meta">
        <?php if ($vm['unseen_new']): ?>
          <span class="ticket__pill ticket__pill--new">New</span>
        <?php endif; ?>
        <?php if (!empty($vm['from_whatsapp']) && $section === 'pool'): ?>
          <span class="edesk-list__pill edesk-list__pill--wa">WhatsApp</span>
        <?php endif; ?>
        <?php if ($section === 'mine'): ?>
          <span class="task-badge task-badge--<?php echo h($stSlug); ?>"><?php echo h(akh_task_status_label($st)); ?></span>
        <?php endif; ?>
        <?php if ($vm['has_reminder']): ?>
          <span class="edesk-list__pill edesk-list__pill--soon">Soon</span>
        <?php endif; ?>
        <?php
        $waUnread = akh_wa_message_unread_count_for_task((string) ($vm['tid_norm'] ?? $tid));
        if ($waUnread > 0):
        ?>
          <span class="edesk-list__msgs"><?php echo (int) $waUnread; ?> msg</span>
        <?php endif; ?>
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
    $customerLabel = (string) ($vm['customer_label'] ?? akh_editor_task_customer_display_name($t));
    $customerTone = (string) ($vm['customer_tone'] ?? akh_editor_task_customer_badge_tone($t, $vm));
    $notificationUpdates = akh_task_notification_panel_updates($tid, is_array($taskAlert) ? $taskAlert : null);
    $meetingAlert = is_array($taskAlert) && str_starts_with((string) ($taskAlert['kind'] ?? ''), 'meeting_')
        ? $taskAlert
        : null;
    $scheduledMeeting = $meetingAlert === null ? akh_meeting_request_desk_for_task_code($tid) : null;
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
            <span class="edesk-panel__chip edesk-panel__chip--customer edesk-panel__chip--customer-<?php echo h($customerTone); ?>">
              <?php echo h($customerLabel !== '' ? $customerLabel : '—'); ?>
            </span>
            <span class="edesk-panel__chip"><?php echo h(akh_task_delivery_mode_label($dm)); ?></span>
            <?php if (akh_task_is_bundle_child($t)): ?>
              <span class="edesk-panel__chip">Part of <?php echo h((string) ($t['parent_task_id'] ?? '')); ?></span>
            <?php endif; ?>
          </div>
        </div>
        <dl class="edesk-panel__facts">
          <div><dt>Created</dt><dd><?php echo h(akh_format_datetime_site((string) ($t['created_at'] ?? '')) ?: '—'); ?></dd></div>
          <div><dt>Updated</dt><dd><?php echo h(akh_format_datetime_site((string) ($t['updated_at'] ?? '')) ?: '—'); ?></dd></div>
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
                <button type="submit" class="btn btn--primary edesk-claim-btn">Assign to me</button>
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
                </div>
                <p class="edesk-muted edesk-muted--sm">Status changes sync to WhatsApp history.</p>
                <button type="submit" class="btn btn--primary btn--sm">Save changes</button>
              </form>
            </section>
          <?php endif; ?>
        </div>

        <div class="edesk-panel__activity">
          <section class="edesk-card edesk-card--updates" aria-label="Client updates">
            <h3 class="edesk-card__title">Updates</h3>
            <?php if ($meetingAlert !== null): ?>
              <article class="edesk-update edesk-update--alert<?php echo !empty($vm['meeting_unread']) ? ' edesk-update--meeting' : ''; ?>">
                <div class="edesk-update__head">
                  <p class="edesk-update__label"><?php echo h(akh_dashboard_alert_kind_label($meetingAlert)); ?></p>
                  <?php if (!empty($vm['meeting_unread'])): ?>
                    <button type="button" class="btn btn--ghost btn--sm edesk-meeting-read" data-task-id="<?php echo h($tid); ?>">Mark read</button>
                  <?php endif; ?>
                </div>
                <div class="edesk-prose"><?php echo nl2br(h((string) ($meetingAlert['preview'] ?? ''))); ?></div>
                <?php if (trim((string) ($meetingAlert['when_label'] ?? '')) !== '' || trim((string) ($meetingAlert['start_time'] ?? '')) !== ''): ?>
                  <p class="edesk-muted edesk-muted--sm">
                    <strong>When:</strong>
                    <?php echo h(trim((string) (($meetingAlert['when_label'] ?? '') !== '' ? $meetingAlert['when_label'] : $meetingAlert['start_time']))); ?>
                  </p>
                <?php endif; ?>
                <?php if (trim((string) ($meetingAlert['customer_name'] ?? '')) !== '' || trim((string) ($meetingAlert['project_name'] ?? '')) !== ''): ?>
                  <p class="edesk-muted edesk-muted--sm">
                    <?php
                    $who = trim((string) ($meetingAlert['customer_name'] ?? ''));
                    $proj = trim((string) ($meetingAlert['project_name'] ?? ''));
                    echo h($who . ($proj !== '' ? ' — ' . $proj : ''));
                    ?>
                  </p>
                <?php endif; ?>
                <?php if (trim((string) ($meetingAlert['requested_time_text'] ?? '')) !== ''): ?>
                  <p class="edesk-muted edesk-muted--sm"><strong>Requested slot:</strong> <?php echo h((string) $meetingAlert['requested_time_text']); ?></p>
                <?php endif; ?>
                <?php if (trim((string) ($meetingAlert['meet_link'] ?? '')) !== ''): ?>
                  <a class="edesk-link" href="<?php echo h((string) $meetingAlert['meet_link']); ?>" target="_blank" rel="noopener noreferrer">Join Google Meet</a>
                <?php endif; ?>
                <?php if (trim((string) ($meetingAlert['end_time'] ?? '')) !== ''): ?>
                  <p class="edesk-muted edesk-muted--sm"><strong>Ends:</strong> <?php echo h((string) $meetingAlert['end_time']); ?></p>
                <?php endif; ?>
              </article>
            <?php elseif (is_array($scheduledMeeting) && (
                trim((string) ($scheduledMeeting['meet_link'] ?? '')) !== ''
                || trim((string) ($scheduledMeeting['when_label'] ?? '')) !== ''
            )): ?>
              <article class="edesk-update edesk-update--scheduled-meeting">
                <p class="edesk-update__label">Scheduled meeting</p>
                <?php if (trim((string) ($scheduledMeeting['when_label'] ?? '')) !== ''): ?>
                  <p class="edesk-muted edesk-muted--sm"><strong>When:</strong> <?php echo h((string) $scheduledMeeting['when_label']); ?></p>
                <?php endif; ?>
                <?php if (trim((string) ($scheduledMeeting['customer_name'] ?? '')) !== '' || trim((string) ($scheduledMeeting['project_name'] ?? '')) !== ''): ?>
                  <p class="edesk-muted edesk-muted--sm">
                    <?php
                    $who = trim((string) ($scheduledMeeting['customer_name'] ?? ''));
                    $proj = trim((string) ($scheduledMeeting['project_name'] ?? ''));
                    echo h($who . ($proj !== '' ? ' — ' . $proj : ''));
                    ?>
                  </p>
                <?php endif; ?>
                <?php if (trim((string) ($scheduledMeeting['meet_link'] ?? '')) !== ''): ?>
                  <a class="btn btn--primary btn--sm" href="<?php echo h((string) $scheduledMeeting['meet_link']); ?>" target="_blank" rel="noopener noreferrer">Join Google Meet</a>
                <?php endif; ?>
              </article>
            <?php endif; ?>
            <?php foreach ($notificationUpdates as $update): ?>
              <article class="edesk-update edesk-update--alert edesk-update--notification">
                <p class="edesk-update__label"><?php echo h((string) ($update['label'] ?? 'Client update')); ?></p>
                <div class="edesk-prose"><?php echo nl2br(h((string) ($update['body'] ?? ''))); ?></div>
                <?php if (trim((string) ($update['created_at'] ?? '')) !== ''): ?>
                  <p class="edesk-muted edesk-muted--sm"><?php echo h(akh_format_datetime_site_short((string) $update['created_at'])); ?></p>
                <?php endif; ?>
              </article>
            <?php endforeach; ?>
            <?php if (is_array($taskAlert) && ($taskAlert['kind'] ?? '') === 'whatsapp_message' && $notificationUpdates === []): ?>
              <article class="edesk-update edesk-update--alert">
                <p class="edesk-update__label"><?php echo h(akh_dashboard_alert_kind_label($taskAlert)); ?></p>
                <div class="edesk-prose"><?php echo nl2br(h((string) ($taskAlert['preview'] ?? ''))); ?></div>
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
            <?php if (
                $meetingAlert === null
                && $scheduledMeeting === null
                && $notificationUpdates === []
                && (!is_array($taskAlert) || ($taskAlert['kind'] ?? '') !== 'whatsapp_message')
                && trim((string) ($t['client_feedback'] ?? '')) === ''
                && trim((string) ($t['client_meeting_date'] ?? '')) === ''
            ): ?>
              <p class="edesk-muted">No client updates yet for this task.</p>
            <?php endif; ?>
          </section>

          <?php if ($section === 'mine'): ?>
            <?php
            $waPhone = akh_wa_message_phone_for_task($tid);
            ?>
            <div class="edesk-card edesk-card--thread">
              <?php if ($waPhone !== ''): ?>
                <div class="ticket__thread-toolbar">
                  <p class="ticket__thread-toolbar-lead">WhatsApp conversation</p>
                  <button
                    type="button"
                    class="btn btn--ghost btn--sm edesk-end-chat"
                    data-task-id="<?php echo h($tid); ?>"
                  >End Chat</button>
                </div>
              <?php endif; ?>
              <?php akh_render_task_thread_panel($t, 'editor', $pageCsrf); ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </article>
    <?php
}
