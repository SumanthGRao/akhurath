<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once AKH_ROOT . '/includes/whatsapp-board.php';

header('Content-Type: application/json; charset=utf-8');

if (!akh_wa_board_enabled()) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'board_disabled']);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

$action = trim((string) ($_GET['action'] ?? 'list'));

try {
    if ($action === 'poll') {
        echo json_encode([
            'ok' => true,
            'sig' => akh_wa_board_poll_signature(),
            'notify_count' => count(akh_dashboard_unread_alerts_grouped()),
        ], JSON_THROW_ON_ERROR);
        exit;
    }

    if ($action === 'detail') {
        $taskCode = trim((string) ($_GET['task_code'] ?? ''));
        $detail = akh_wa_board_task_detail($taskCode);
        if ($detail === null) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'not_found'], JSON_THROW_ON_ERROR);
            exit;
        }
        echo json_encode(['ok' => true, 'detail' => $detail], JSON_THROW_ON_ERROR);
        exit;
    }

    if ($action === 'list') {
        echo json_encode(array_merge(['ok' => true], akh_wa_board_payload()), JSON_THROW_ON_ERROR);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'unknown_action']);
} catch (Throwable $e) {
    error_log('whatsapp/board-api: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server_error']);
}
