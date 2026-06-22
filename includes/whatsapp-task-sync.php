<?php

declare(strict_types=1);

/**
 * Sync editor workflow status to WhatsApp-facing MySQL tables.
 * Internal task ids use AS_0001; whatsapp_tasks uses AS0001 (no underscore).
 */
function akh_whatsapp_external_task_id(string $internalTaskId): string
{
    return str_replace('_', '', trim($internalTaskId));
}

/**
 * WhatsApp status label for internal editor workflow status.
 */
function akh_whatsapp_status_for_editor_status(string $internalStatus): ?string
{
    $map = [
        'assigned' => 'Assigned',
        'in_progress' => 'Editing',
        'review' => 'Review',
        'delivered' => 'Delivered',
        'reverted' => 'Reverted',
        'closed' => 'Closed',
    ];

    return $map[$internalStatus] ?? null;
}

function akh_whatsapp_task_tables_exist(): bool
{
    if (!function_exists('akh_db')) {
        return false;
    }
    try {
        $pdo = akh_db();
        $schema = $pdo->query('SELECT DATABASE()');
        if ($schema === false) {
            return false;
        }
        $db = $schema->fetchColumn();
        if (!is_string($db) || $db === '') {
            return false;
        }
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN (?, ?)'
        );
        $st->execute([$db, 'whatsapp_tasks', 'task_updates']);

        return (int) $st->fetchColumn() === 2;
    } catch (\Throwable $e) {
        return false;
    }
}

/**
 * Update whatsapp_tasks and append task_updates when editor workflow status changes.
 */
function akh_whatsapp_record_task_status_update(
    string $internalTaskId,
    string $internalStatus,
    string $editorUsername,
    string $comment
): bool {
    if (!akh_whatsapp_task_tables_exist()) {
        return true;
    }

    $comment = trim($comment);
    if ($comment === '' || mb_strlen($comment) > 2000) {
        return false;
    }

    $editorUsername = trim($editorUsername);
    if ($editorUsername === '' || mb_strlen($editorUsername) > 64) {
        return false;
    }

    $extId = akh_whatsapp_external_task_id($internalTaskId);
    $waStatus = akh_whatsapp_status_for_editor_status($internalStatus);
    if ($waStatus === null || $extId === '') {
        return false;
    }

    try {
        $pdo = akh_db();
        $pdo->beginTransaction();

        $upd = $pdo->prepare(
            'UPDATE whatsapp_tasks SET status = ?, status_updated_at = NOW() WHERE task_id = ?'
        );
        $upd->execute([$waStatus, $extId]);

        $ins = $pdo->prepare(
            'INSERT INTO task_updates (task_id, status, comment, updated_by) VALUES (?, ?, ?, ?)'
        );
        $ins->execute([$extId, $waStatus, $comment, $editorUsername]);

        $pdo->commit();

        return true;
    } catch (\Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return false;
    }
}
