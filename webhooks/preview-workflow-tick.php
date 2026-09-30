<?php

declare(strict_types=1);

/**
 * Optional n8n hook: GET ?token=... after inserting whatsapp_preview_messages / client messages.
 * Set AKH_PREVIEW_WORKFLOW_WEBHOOK_TOKEN in includes/config.php (non-empty).
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once AKH_ROOT . '/includes/whatsapp-preview-workflow.php';

header('Content-Type: application/json; charset=utf-8');

$expected = defined('AKH_PREVIEW_WORKFLOW_WEBHOOK_TOKEN')
    ? trim((string) AKH_PREVIEW_WORKFLOW_WEBHOOK_TOKEN)
    : '';
$token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
if ($expected === '' || $token === '' || !hash_equals($expected, $token)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

akh_wa_preview_workflow_tick_force();

$taskRef = trim((string) ($_POST['task_code'] ?? $_POST['task_id'] ?? $_GET['task_code'] ?? ''));
$applied = null;
if ($taskRef !== '' && function_exists('akh_wa_workflow_resolve_canonical_task_code')) {
    $code = akh_wa_workflow_resolve_canonical_task_code($taskRef);
    if ($code !== '' && ($_POST['action'] ?? '') === 'preview_sent') {
        $applied = akh_task_automation_apply_status(
            $code,
            'preview_sent',
            'Preview link pushed — status set to Preview sent.'
        );
    }
}

echo json_encode(['ok' => true, 'task' => $taskRef !== '' ? $taskRef : null, 'applied' => $applied], JSON_THROW_ON_ERROR);
