<?php

declare(strict_types=1);

require_once __DIR__ . '/whatsapp-tasks.php';
require_once __DIR__ . '/dashboard-alerts.php';
require_once __DIR__ . '/meeting-requests.php';

/**
 * Public read-only WhatsApp board (no login).
 */
function akh_wa_board_enabled(): bool
{
    return !defined('AKH_WA_PUBLIC_BOARD_ENABLED') || AKH_WA_PUBLIC_BOARD_ENABLED;
}

function akh_wa_board_poll_signature(): string
{
    return hash(
        'sha256',
        akh_wa_tasks_poll_signature()
        . '|'
        . akh_dashboard_alerts_poll_signature()
        . '|'
        . akh_meeting_request_poll_signature()
    );
}

/**
 * @return list<array<string, mixed>>
 */
function akh_wa_board_task_rows(): array
{
    if (!akh_wa_tasks_table_exists()) {
        return [];
    }

    $editors = akh_wa_editors_for_select();
    $alerts = akh_dashboard_unread_alerts_grouped();
    $out = [];

    foreach (akh_wa_tasks_list_for_dashboard() as $row) {
        $json = akh_wa_task_row_for_json($row, $editors);
        if (strtolower((string) ($json['status'] ?? '')) === 'closed') {
            continue;
        }
        $code = (string) ($json['task_code'] ?? '');
        $alert = $code !== '' ? akh_dashboard_alert_for_code($alerts, $code) : null;
        $out[] = [
            'id' => (int) ($json['id'] ?? 0),
            'task_code' => $code,
            'customer_name' => (string) ($json['customer_name'] ?? ''),
            'project_name' => (string) ($json['project_name'] ?? ''),
            'task_type' => (string) ($json['task_type'] ?? ''),
            'status' => (string) ($json['status'] ?? ''),
            'status_label' => (string) ($json['status_label'] ?? ''),
            'assigned_editor_name' => (string) ($json['assigned_editor_name'] ?? ''),
            'instructions' => (string) ($json['instructions'] ?? ''),
            'delivery_type' => (string) ($json['delivery_type'] ?? ''),
            'updated_at' => (string) ($json['updated_at'] ?? ''),
            'unread_messages' => (int) ($json['unread_messages'] ?? 0),
            'has_update' => $alert !== null,
            'update_kind' => $alert !== null ? (string) ($alert['kind'] ?? '') : '',
            'update_label' => $alert !== null ? akh_dashboard_alert_kind_label($alert) : '',
            'update_preview' => $alert !== null ? (string) ($alert['preview'] ?? '') : '',
        ];
    }

    return $out;
}

/**
 * @return list<array<string, mixed>>
 */
function akh_wa_board_meeting_rows(): array
{
    if (!akh_meeting_requests_table_exists()) {
        return [];
    }

    $out = [];
    foreach (akh_meeting_request_upcoming_list_for_dashboard() as $row) {
        $when = trim((string) ($row['when_label'] ?? ''));
        if ($when === '') {
            $when = trim((string) ($row['requested_time_text'] ?? ''));
        }
        if ($when === '') {
            $when = trim((string) ($row['start_time'] ?? ''));
        }

        $out[] = [
            'id' => (int) ($row['id'] ?? 0),
            'task_code' => (string) ($row['task_code'] ?? ''),
            'customer_name' => (string) ($row['customer_name'] ?? ''),
            'project_name' => (string) ($row['project_name'] ?? ''),
            'when_label' => $when,
            'meet_link' => trim((string) ($row['meet_link'] ?? '')),
            'status' => (string) ($row['status'] ?? ''),
            'preview' => (string) ($row['preview'] ?? ''),
            'is_unread' => !empty($row['is_unread']),
        ];
    }

    return $out;
}

/**
 * @return array<string, mixed>|null
 */
function akh_wa_board_task_detail(string $taskCode): ?array
{
    require_once __DIR__ . '/tasks.php';

    $taskCode = akh_task_normalize_id(trim($taskCode));
    if ($taskCode === '' || !akh_wa_tasks_table_exists()) {
        return null;
    }

    $row = akh_wa_task_by_code($taskCode);
    if ($row === null) {
        return null;
    }

    $editors = akh_wa_editors_for_select();
    $json = akh_wa_task_row_for_json($row, $editors);
    $alerts = akh_dashboard_unread_alerts_grouped();
    $alert = akh_dashboard_alert_for_code($alerts, $taskCode);

    $updates = [];
    if ($alert !== null) {
        $updates[] = [
            'kind' => (string) ($alert['kind'] ?? 'client_update'),
            'label' => akh_dashboard_alert_kind_label($alert),
            'preview' => (string) ($alert['preview'] ?? ''),
            'when_label' => (string) ($alert['when_label'] ?? ''),
            'meet_link' => (string) ($alert['meet_link'] ?? ''),
        ];
    }

    $hasMeetingAlert = $alert !== null && str_starts_with((string) ($alert['kind'] ?? ''), 'meeting_');
    foreach (akh_wa_board_meeting_rows() as $meeting) {
        if (!akh_task_ids_match($taskCode, (string) ($meeting['task_code'] ?? ''))) {
            continue;
        }
        if ($hasMeetingAlert && !empty($meeting['is_unread'])) {
            continue;
        }
        $updates[] = [
            'kind' => 'meeting_scheduled',
            'label' => 'Scheduled meeting',
            'preview' => (string) ($meeting['preview'] ?? ''),
            'when_label' => (string) ($meeting['when_label'] ?? ''),
            'meet_link' => (string) ($meeting['meet_link'] ?? ''),
        ];
    }

    return [
        'task_code' => $taskCode,
        'customer_name' => (string) ($json['customer_name'] ?? ''),
        'project_name' => (string) ($json['project_name'] ?? ''),
        'task_type' => (string) ($json['task_type'] ?? ''),
        'status' => (string) ($json['status'] ?? ''),
        'status_label' => (string) ($json['status_label'] ?? ''),
        'assigned_editor_name' => (string) ($json['assigned_editor_name'] ?? ''),
        'instructions' => (string) ($json['instructions'] ?? ''),
        'delivery_type' => (string) ($json['delivery_type'] ?? ''),
        'drive_link' => (string) ($json['drive_link'] ?? ''),
        'reference_link' => (string) ($json['reference_link'] ?? ''),
        'updated_at' => (string) ($json['updated_at'] ?? ''),
        'unread_messages' => (int) ($json['unread_messages'] ?? 0),
        'updates' => $updates,
    ];
}

/**
 * @return array{
 *   sig: string,
 *   tasks: list<array<string, mixed>>,
 *   meetings: list<array<string, mixed>>,
 *   notify_count: int
 * }
 */
function akh_wa_board_payload(): array
{
    $alerts = akh_dashboard_unread_alerts_grouped();

    return [
        'sig' => akh_wa_board_poll_signature(),
        'tasks' => akh_wa_board_task_rows(),
        'meetings' => akh_wa_board_meeting_rows(),
        'notify_count' => count($alerts),
    ];
}
