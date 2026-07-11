<?php

declare(strict_types=1);

require_once __DIR__ . '/editor-dashboard-render.php';

/**
 * Build the same board slices as editor/dashboard.php for API + live sync.
 *
 * @return array{
 *   newTasks: list<array<string, mixed>>,
 *   mine: list<array<string, mixed>>,
 *   dashboardAlerts: array<string, array<string, mixed>>,
 *   seenNew: list<string>,
 *   editorReminderCodes: array<string, bool>,
 *   editorMeetingRows: list<array<string, mixed>>
 * }
 */
function akh_editor_desk_board_context(string $editorUsername): array
{
    require_once __DIR__ . '/dashboard-alerts.php';
    require_once __DIR__ . '/meeting-requests.php';
    require_once __DIR__ . '/whatsapp-tasks.php';

    akh_wa_sync_whatsapp_pool_to_studio_board();

    $editorUsername = strtolower(trim($editorUsername));
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
    $mine = array_values(array_filter($all, static function (array $t) use ($editorUsername): bool {
        return strtolower(trim((string) ($t['assigned_editor'] ?? ''))) === $editorUsername;
    }));

    $dashboardAlerts = akh_dashboard_alerts_for_editor($editorUsername);
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
        $extra = akh_task_notification_editor_board_row($alertTaskId, $editorUsername);
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

    $seenNew = akh_task_editor_seen_load()[$editorUsername] ?? [];
    $editorMeetingRows = [];
    $ownedCodes = akh_meeting_request_assigned_task_codes_for_editor($editorUsername);
    foreach (akh_meeting_request_pending_rows() as $mr) {
        $c = (string) ($mr['task_code'] ?? '');
        if ($c !== '' && akh_meeting_request_editor_owns_code($ownedCodes, $c)) {
            $editorMeetingRows[] = $mr;
        }
    }
    $editorReminderCodes = [];
    foreach (akh_meeting_request_upcoming_reminders() as $r) {
        if (!akh_meeting_request_editor_owns_code($ownedCodes, (string) ($r['task_code'] ?? ''))) {
            continue;
        }
        $c = akh_task_normalize_id((string) ($r['task_code'] ?? ''));
        if ($c !== '') {
            $editorReminderCodes[$c] = true;
        }
    }

    return [
        'newTasks' => $newTasks,
        'mine' => $mine,
        'dashboardAlerts' => $dashboardAlerts,
        'seenNew' => is_array($seenNew) ? $seenNew : [],
        'editorReminderCodes' => $editorReminderCodes,
        'editorMeetingRows' => $editorMeetingRows,
    ];
}

/**
 * @param array<string, mixed> $vm
 * @return array<string, mixed>
 */
function akh_editor_desk_list_row_json(array $vm): array
{
    $t = $vm['task'];
    $alert = $vm['task_alert'];
    $priority = is_array($alert) ? (int) ($alert['priority'] ?? 0) : 0;

    $section = (string) $vm['section'];
    $listAt = $section === 'pool'
        ? (string) (($t['created_at'] ?? '') !== '' ? $t['created_at'] : ($t['updated_at'] ?? ''))
        : (string) (($t['updated_at'] ?? '') !== '' ? $t['updated_at'] : ($t['created_at'] ?? ''));

    return [
        'id' => (string) $vm['tid'],
        'section' => $section,
        'title' => (string) $vm['headline'],
        'type_label' => (string) ($vm['type_label'] ?? ''),
        'show_type' => (bool) ($vm['show_type'] ?? false),
        'list_unread' => (bool) ($vm['list_unread'] ?? false),
        'search' => strtolower(implode(' ', array_filter([
            (string) $vm['tid'],
            (string) $vm['headline'],
            (string) ($vm['type_label'] ?? ''),
            (string) ($t['client_username'] ?? ''),
            akh_task_status_label((string) $vm['status']),
        ], static fn (string $p): bool => trim($p) !== ''))),
        'client' => (string) ($t['client_username'] ?? ''),
        'status' => (string) $vm['status'],
        'status_label' => akh_task_status_label((string) $vm['status']),
        'status_slug' => (string) $vm['status_slug'],
        'updated_at' => (string) ($t['updated_at'] ?? ''),
        'created_at' => (string) ($t['created_at'] ?? ''),
        'list_at' => $listAt,
        'notify' => (bool) $vm['notify'],
        'unseen_new' => (bool) $vm['unseen_new'],
        'has_reminder' => (bool) $vm['has_reminder'],
        'meeting_unread' => (bool) ($vm['meeting_unread'] ?? false),
        'priority' => $priority,
        'ack_new' => (bool) $vm['ack_new'],
        'ack_editor' => (bool) $vm['ack_editor'],
        'ack_meeting' => (bool) ($vm['ack_meeting'] ?? false),
        'msg_count' => count(akh_task_conversation_list($t)),
        'from_whatsapp' => (string) ($t['edit_type'] ?? '') === 'studio_admin'
            || strtolower(trim((string) ($t['client_username'] ?? ''))) === 'whatsapp',
    ];
}

/**
 * @return array{pool: list<array<string, mixed>>, mine: list<array<string, mixed>>, meetings: list<array<string, string>>}
 */
function akh_editor_desk_lists_json(string $editorUsername): array
{
    $ctx = akh_editor_desk_board_context($editorUsername);
    $pool = [];
    foreach ($ctx['newTasks'] as $t) {
        $vm = akh_editor_task_view_model(
            $t,
            $editorUsername,
            $ctx['dashboardAlerts'],
            $ctx['editorReminderCodes'],
            $ctx['seenNew'],
            'pool'
        );
        $pool[] = akh_editor_desk_list_row_json($vm);
    }
    $mine = [];
    foreach ($ctx['mine'] as $t) {
        $vm = akh_editor_task_view_model(
            $t,
            $editorUsername,
            $ctx['dashboardAlerts'],
            $ctx['editorReminderCodes'],
            $ctx['seenNew'],
            'mine'
        );
        $mine[] = akh_editor_desk_list_row_json($vm);
    }
    $meetings = [];
    foreach ($ctx['editorMeetingRows'] as $mr) {
        $code = (string) ($mr['task_code'] ?? '');
        if ($code === '') {
            continue;
        }
        $meetings[] = [
            'task_code' => $code,
            'preview' => akh_meeting_request_preview_from_row($mr),
            'meet_link' => trim((string) ($mr['meet_link'] ?? '')),
        ];
    }

    return ['pool' => $pool, 'mine' => $mine, 'meetings' => $meetings];
}

/**
 * @param array<string, mixed> $t
 */
function akh_editor_desk_task_section(array $t, string $editorUsername): string
{
    $editorUsername = strtolower(trim($editorUsername));
    $assigned = strtolower(trim((string) ($t['assigned_editor'] ?? '')));
    if ($assigned !== '' && $assigned === $editorUsername) {
        return 'mine';
    }
    if (akh_task_editor_pool_eligible($t)) {
        return 'pool';
    }

    return 'mine';
}

/**
 * @param array{
 *   newTasks: list<array<string, mixed>>,
 *   mine: list<array<string, mixed>>,
 *   dashboardAlerts: array<string, array<string, mixed>>,
 *   seenNew: list<string>,
 *   editorReminderCodes: array<string, bool>
 * } $ctx
 * @return array{task: array<string, mixed>, section: string}|null
 */
function akh_editor_desk_find_task_in_ctx(array $ctx, string $editorUsername, string $taskId): ?array
{
    $taskId = trim($taskId);
    if ($taskId === '') {
        return null;
    }

    foreach ($ctx['mine'] as $t) {
        if (akh_task_ids_match((string) ($t['id'] ?? ''), $taskId)) {
            return [
                'task' => $t,
                'section' => akh_editor_desk_task_section($t, $editorUsername),
            ];
        }
    }
    foreach ($ctx['newTasks'] as $t) {
        if (akh_task_ids_match((string) ($t['id'] ?? ''), $taskId)) {
            return [
                'task' => $t,
                'section' => akh_editor_desk_task_section($t, $editorUsername),
            ];
        }
    }

    return null;
}

/**
 * @return array{task: array<string, mixed>, section: string}|null
 */
function akh_editor_desk_find_task(string $editorUsername, string $taskId): ?array
{
    $ctx = akh_editor_desk_board_context($editorUsername);

    return akh_editor_desk_find_task_in_ctx($ctx, $editorUsername, $taskId);
}

function akh_editor_desk_panel_html(string $editorUsername, string $taskId, string $csrf): string
{
    $taskId = trim($taskId);
    if ($taskId === '') {
        return '';
    }

    $ctx = akh_editor_desk_board_context($editorUsername);
    $found = akh_editor_desk_find_task_in_ctx($ctx, $editorUsername, $taskId);
    if ($found === null) {
        return '';
    }

    $vm = akh_editor_task_view_model(
        $found['task'],
        $editorUsername,
        $ctx['dashboardAlerts'],
        $ctx['editorReminderCodes'],
        $ctx['seenNew'],
        $found['section']
    );
    ob_start();
    akh_editor_render_detail_panel($vm, $csrf);
    $html = ob_get_clean();

    return is_string($html) ? $html : '';
}

/**
 * @return array<string, mixed>
 */
function akh_editor_desk_poll_bundle(string $editorUsername): array
{
    $lists = akh_editor_desk_lists_json($editorUsername);

    return [
        'pool' => $lists['pool'],
        'mine' => $lists['mine'],
        'meetings' => $lists['meetings'],
        'pool_count' => count($lists['pool']),
        'mine_count' => count($lists['mine']),
    ];
}
