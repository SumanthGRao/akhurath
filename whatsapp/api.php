<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once AKH_ROOT . '/includes/whatsapp-dashboard-auth.php';
require_once AKH_ROOT . '/includes/whatsapp-tasks.php';
require_once AKH_ROOT . '/includes/csrf.php';

akh_require_wa_dashboard();

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

if (!akh_csrf_verify($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'csrf']);
    exit;
}

$action = trim((string) ($_POST['ajax_action'] ?? ''));

try {
    if ($action === 'poll') {
        $notify = akh_wa_client_notification_payload();
        echo json_encode([
            'ok' => true,
            'sig' => akh_wa_tasks_poll_signature(),
            'notify_sig' => $notify['notify_sig'],
            'notify_count' => $notify['count'],
        ], JSON_THROW_ON_ERROR);
        exit;
    }

    if ($action === 'notify_ack') {
        $maxId = (int) ($_POST['max_id'] ?? 0);
        if ($maxId > 0) {
            akh_wa_notification_mark_seen($maxId);
        } else {
            akh_wa_notification_mark_all_seen();
        }
        $notify = akh_wa_client_notification_payload();
        echo json_encode([
            'ok' => true,
            'notify_sig' => $notify['notify_sig'],
            'notify_count' => $notify['count'],
            'alerts' => $notify['alerts'],
        ], JSON_THROW_ON_ERROR);
        exit;
    }

    if ($action === 'list') {
        $filters = [
            'status' => (string) ($_POST['status'] ?? ''),
            'q' => (string) ($_POST['q'] ?? ''),
        ];
        $rows = akh_wa_tasks_list($filters);
        $editors = akh_wa_editors_for_select();
        $tasks = array_map(static fn (array $r): array => akh_wa_task_row_for_json($r, $editors), $rows);
        $notify = akh_wa_client_notification_payload();

        echo json_encode([
            'ok' => true,
            'sig' => akh_wa_tasks_poll_signature(),
            'notify_sig' => $notify['notify_sig'],
            'notify_count' => $notify['count'],
            'alerts' => $notify['alerts'],
            'counts' => akh_wa_task_status_counts(),
            'tasks' => $tasks,
            'editors' => $editors,
            'statuses' => akh_wa_task_statuses(),
        ], JSON_THROW_ON_ERROR);
        exit;
    }

    if ($action === 'update') {
        $id = (int) ($_POST['id'] ?? 0);
        // Only update fields the client sent — omitting phone (etc.) must not clear DB values.
        $fieldKeys = [
            'task_code', 'customer_id', 'customer_name', 'phone', 'project_name', 'task_type',
            'instructions', 'delivery_type', 'drive_link', 'reference_link', 'comments',
            'status', 'assigned_editor',
        ];
        $fields = [];
        foreach ($fieldKeys as $key) {
            if (array_key_exists($key, $_POST)) {
                $fields[$key] = (string) $_POST[$key];
            }
        }

        $result = akh_wa_task_update($id, $fields);
        if (!$result['ok']) {
            http_response_code(400);
            echo json_encode([
                'ok' => false,
                'error' => (string) ($result['error'] ?? 'Update failed.'),
            ], JSON_THROW_ON_ERROR);
            exit;
        }

        $editors = akh_wa_editors_for_select();
        $task = akh_wa_task_row_for_json((array) ($result['task'] ?? []), $editors);

        echo json_encode([
            'ok' => true,
            'sig' => akh_wa_tasks_poll_signature(),
            'counts' => akh_wa_task_status_counts(),
            'task' => $task,
        ], JSON_THROW_ON_ERROR);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'bad_action']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => trim((string) $e->getMessage()) !== '' ? $e->getMessage() : 'Server error.',
    ]);
}
