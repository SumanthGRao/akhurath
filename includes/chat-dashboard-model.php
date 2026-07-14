<?php

declare(strict_types=1);

require_once __DIR__ . '/dashboard-data-bridge.php';

/**
 * Build view-model for chat / ops dashboards from $dashboard_data (n8n JSON).
 *
 * @return array{
 *   ok: bool,
 *   error: string,
 *   rows: list<array<string, mixed>>,
 *   attendance: list<array<string, mixed>>,
 *   logs: list<array<string, mixed>>,
 *   counters: array<string, int|float|string>,
 *   editors_clocked_in: list<string>
 * }
 */
function akh_chat_dashboard_view_model(): array
{
    $rows = akh_dashboard_data_rows();
    $attendance = akh_dashboard_data_attendance_rows();
    $logs = akh_dashboard_data_log_rows();
    $counters = akh_dashboard_data_counters();

    $clockedIn = [];
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

    $editorsOnline = [];
    foreach (array_keys($clockedIn) as $editor) {
        $editorsOnline[] = $editor;
    }
    sort($editorsOnline);

    if ((int) ($counters['editors_online'] ?? 0) < 1 && $editorsOnline !== []) {
        $counters['editors_online'] = count($editorsOnline);
    }

    $error = '';
    if (!akh_dashboard_data_is_available()) {
        $error = defined('AKH_CHAT_API_URL')
            ? 'Dashboard data is temporarily unavailable. The n8n bridge did not return data.'
            : 'Dashboard data source is not configured.';
    }

    return [
        'ok' => $error === '',
        'error' => $error,
        'rows' => $rows,
        'attendance' => $attendance,
        'logs' => $logs,
        'counters' => $counters,
        'editors_clocked_in' => $editorsOnline,
    ];
}

/**
 * @param array<string, mixed> $row
 */
function akh_chat_dashboard_row_label(array $row): string
{
    foreach (['task_code', 'task_id', 'code', 'id', 'phone', 'customer_name', 'title'] as $key) {
        $val = trim((string) ($row[$key] ?? ''));
        if ($val !== '') {
            return $val;
        }
    }

    return 'Row';
}

/**
 * @param array<string, mixed> $row
 */
function akh_chat_dashboard_row_preview(array $row): string
{
    foreach (['message', 'preview', 'last_message', 'status', 'notes'] as $key) {
        $val = trim((string) ($row[$key] ?? ''));
        if ($val !== '') {
            return $val;
        }
    }

    return '';
}

/**
 * @param array<string, mixed> $row
 */
function akh_chat_dashboard_attendance_label(array $row): string
{
    $editor = trim((string) ($row['editor'] ?? $row['username'] ?? 'Editor'));
    $type = trim((string) ($row['type'] ?? $row['action'] ?? 'event'));
    $at = trim((string) ($row['at'] ?? $row['created_at'] ?? $row['timestamp'] ?? ''));

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
    $msg = trim((string) ($row['message'] ?? $row['detail'] ?? $row['text'] ?? ''));
    $at = trim((string) ($row['at'] ?? $row['created_at'] ?? $row['timestamp'] ?? ''));

    if ($msg === '' && $at === '') {
        return 'Log entry';
    }
    if ($at === '') {
        return $msg;
    }
    if ($msg === '') {
        return $at;
    }

    return $at . ' — ' . $msg;
}
