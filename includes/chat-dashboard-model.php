<?php

declare(strict_types=1);

require_once __DIR__ . '/dashboard-data-bridge.php';

/**
 * Build view-model for chat / ops dashboards from grouped $dashboard_data (n8n JSON).
 *
 * @return array{
 *   ok: bool,
 *   error: string,
 *   received_keys: list<string>,
 *   tasks: list<array<string, mixed>>,
 *   task_pool: list<array<string, mixed>>,
 *   whatsapp_tasks: list<array<string, mixed>>,
 *   attendance: list<array<string, mixed>>,
 *   editors: list<array<string, mixed>>,
 *   editors_clocked_in: list<string>,
 *   logs: list<array<string, mixed>>,
 *   meetings: list<array<string, mixed>>,
 *   alerts: array<string, array<string, mixed>>,
 *   counters: array<string, int|float|string>
 * }
 */
function akh_chat_dashboard_view_model(): array
{
    $tasks = akh_dashboard_data_tasks();
    $taskPool = akh_dashboard_data_task_pool();
    $whatsappTasks = akh_dashboard_data_whatsapp_tasks();
    $attendance = akh_dashboard_data_attendance();
    $editors = akh_dashboard_data_editors();
    $logs = akh_dashboard_data_logs();
    $meetings = akh_dashboard_data_meetings();
    $alerts = akh_dashboard_data_alerts();
    $counters = akh_dashboard_data_counters();
    $receivedKeys = akh_dashboard_data_received_keys();

    $clockedIn = akh_chat_dashboard_clocked_in_editors($editors, $attendance);

    if ((int) ($counters['editors_online'] ?? 0) < 1 && $clockedIn !== []) {
        $counters['editors_online'] = count($clockedIn);
    }

    $error = '';
    if (!akh_dashboard_data_has_grouped_payload()) {
        $error = defined('AKH_CHAT_API_URL')
            ? 'Webhook returned no grouped sections. n8n must respond with keys: tasks, whatsapp_tasks, attendance, editors, logs, counters (see includes/n8n-dashboard-payload.example.json).'
            : 'Dashboard data source is not configured (AKH_CHAT_API_URL missing).';
    }

    return [
        'ok' => $error === '',
        'error' => $error,
        'received_keys' => $receivedKeys,
        'tasks' => $tasks,
        'task_pool' => $taskPool,
        'whatsapp_tasks' => $whatsappTasks,
        'attendance' => $attendance,
        'editors' => $editors,
        'editors_clocked_in' => $clockedIn,
        'logs' => $logs,
        'meetings' => $meetings,
        'alerts' => $alerts,
        'counters' => $counters,
    ];
}

/**
 * @param list<array<string, mixed>> $editors
 * @param list<array<string, mixed>> $attendance
 * @return list<string>
 */
function akh_chat_dashboard_clocked_in_editors(array $editors, array $attendance): array
{
    $clockedIn = [];

    foreach ($editors as $ed) {
        $username = strtolower(trim((string) ($ed['username'] ?? $ed['editor'] ?? '')));
        if ($username === '') {
            continue;
        }
        if (!empty($ed['clocked_in']) || !empty($ed['on_shift'])) {
            $clockedIn[$username] = true;
        }
    }

    foreach ($attendance as $event) {
        $editor = strtolower(trim((string) ($event['editor'] ?? $event['username'] ?? '')));
        $type = strtolower(trim((string) ($event['type'] ?? $event['action'] ?? '')));
        if ($editor === '') {
            continue;
        }
        if ($type === 'clock_in' || $type === 'in') {
            $clockedIn[$editor] = true;
        } elseif ($type === 'clock_out' || $type === 'out') {
            unset($clockedIn[$editor]);
        }
    }

    $out = array_keys($clockedIn);
    sort($out);

    return $out;
}

/**
 * @param array<string, mixed> $row
 */
function akh_chat_dashboard_task_label(array $row): string
{
    foreach (['id', 'task_code', 'task_id', 'code'] as $key) {
        $val = trim((string) ($row[$key] ?? ''));
        if ($val !== '') {
            return $val;
        }
    }

    return 'Task';
}

/**
 * @param array<string, mixed> $row
 */
function akh_chat_dashboard_task_title(array $row): string
{
    foreach (['title', 'project_name', 'customer_name', 'task_type'] as $key) {
        $val = trim((string) ($row[$key] ?? ''));
        if ($val !== '') {
            return $val;
        }
    }

    return '—';
}

/**
 * @param array<string, mixed> $row
 */
function akh_chat_dashboard_task_editor(array $row): string
{
    foreach (['assigned_editor_username', 'assigned_editor', 'editor', 'editor_name'] as $key) {
        $val = trim((string) ($row[$key] ?? ''));
        if ($val !== '' && !is_numeric($val)) {
            return $val;
        }
    }

    return '—';
}

/**
 * @param array<string, mixed> $row
 */
function akh_chat_dashboard_attendance_label(array $row): string
{
    $editor = trim((string) ($row['editor'] ?? $row['username'] ?? 'Editor'));
    $type = trim((string) ($row['type'] ?? $row['action'] ?? 'event'));
    $at = trim((string) ($row['created_at'] ?? $row['at'] ?? $row['timestamp'] ?? ''));
    if (is_numeric($at) && (int) $at > 1000000000) {
        $at = date('Y-m-d H:i:s', (int) $at);
    }

    $label = $editor . ' · ' . str_replace('_', ' ', $type);
    if ($at !== '') {
        $label .= ' · ' . $at;
    }

    return $label;
}

/**
 * @param array<string, mixed> $row
 */
function akh_chat_dashboard_log_label(array $row): string
{
    $code = trim((string) ($row['task_code'] ?? $row['task_id'] ?? ''));
    $msg = trim((string) ($row['message'] ?? $row['detail'] ?? $row['text'] ?? ''));
    $at = trim((string) ($row['created_at'] ?? $row['at'] ?? $row['timestamp'] ?? ''));

    $parts = [];
    if ($code !== '') {
        $parts[] = $code;
    }
    if ($at !== '') {
        $parts[] = $at;
    }
    if ($msg !== '') {
        $parts[] = $msg;
    }

    return $parts !== [] ? implode(' — ', $parts) : 'Log entry';
}

/**
 * @param array<string, mixed> $row
 */
function akh_chat_dashboard_editor_label(array $row): string
{
    $username = trim((string) ($row['username'] ?? $row['editor'] ?? 'Editor'));
    $on = !empty($row['clocked_in']) || !empty($row['on_shift']);
    $status = $on ? 'On shift' : 'Off shift';

    return $username . ' · ' . $status;
}
