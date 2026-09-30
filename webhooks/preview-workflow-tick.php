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

echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);
