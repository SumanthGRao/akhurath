<?php

declare(strict_types=1);

require_once __DIR__ . '/whatsapp-tasks.php';

/** Last sync failure reason (for editor dashboard messages). */
function akh_whatsapp_task_sync_last_error(): string
{
    return (string) ($GLOBALS['akh_whatsapp_task_sync_error'] ?? '');
}

function akh_whatsapp_task_sync_set_error(string $message): void
{
    $GLOBALS['akh_whatsapp_task_sync_error'] = $message;
}

function akh_wa_task_updates_table_exists(): bool
{
    if (!function_exists('akh_db')) {
        return false;
    }

    try {
        $st = akh_db()->query("SHOW TABLES LIKE 'task_updates'");

        return $st !== false && $st->fetch(PDO::FETCH_NUM) !== false;
    } catch (Throwable) {
        return false;
    }
}

/**
 * @return ?array<string, mixed>
 */
function akh_wa_find_row_for_studio_task(array $studioTask): ?array
{
    require_once __DIR__ . '/tasks.php';

    $taskCode = akh_task_normalize_id((string) ($studioTask['id'] ?? ''));
    if ($taskCode === '') {
        return null;
    }

    return akh_wa_task_by_code($taskCode);
}

function akh_wa_editor_display_name(string $editorUsername): string
{
    $editorUsername = trim($editorUsername);
    if ($editorUsername === '' || !function_exists('akh_db')) {
        return $editorUsername;
    }

    try {
        $st = akh_db()->prepare(
            'SELECT username FROM users WHERE role = ? AND LOWER(username) = LOWER(?) LIMIT 1'
        );
        $st->execute(['editor', $editorUsername]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            $name = trim((string) ($row['username'] ?? ''));
            if ($name !== '') {
                return $name;
            }
        }
    } catch (Throwable) {
        // fall through
    }

    return $editorUsername;
}

/**
 * Update whatsapp_tasks and append task_updates when editor workflow status changes.
 *
 * @param array<string, mixed> $studioTask
 */
function akh_whatsapp_record_task_status_update(
    array $studioTask,
    string $internalStatus,
    string $editorUsername,
    string $comment
): bool {
    require_once __DIR__ . '/tasks.php';

    akh_whatsapp_task_sync_set_error('');

    $waTable = akh_wa_tasks_table_exists();
    $updatesTable = akh_wa_task_updates_table_exists();
    if (!$waTable && !$updatesTable) {
        return true;
    }

    $comment = trim($comment);
    if ($comment === '' || mb_strlen($comment) > 2000) {
        akh_whatsapp_task_sync_set_error('A status note is required when changing workflow status.');

        return false;
    }

    $editorUsername = trim($editorUsername);
    if ($editorUsername === '' || mb_strlen($editorUsername) > 64) {
        akh_whatsapp_task_sync_set_error('Invalid editor account.');

        return false;
    }

    $waStatus = akh_wa_map_status_from_studio($internalStatus);
    if ($waStatus === null) {
        akh_whatsapp_task_sync_set_error('That workflow status cannot be synced to WhatsApp.');

        return false;
    }

    $taskCode = akh_task_normalize_id((string) ($studioTask['id'] ?? ''));
    if ($taskCode === '') {
        akh_whatsapp_task_sync_set_error('Could not resolve the task code for this job.');

        return false;
    }

    $waRow = $waTable ? akh_wa_find_row_for_studio_task($studioTask) : null;
    $statusLabel = akh_wa_task_status_label($waStatus);
    $updatedBy = akh_wa_editor_display_name($editorUsername);

    try {
        $pdo = akh_db();
        $pdo->beginTransaction();

        if ($waTable && $waRow !== null) {
            $waId = (int) ($waRow['id'] ?? 0);
            if ($waId > 0) {
                $pdo->prepare('UPDATE whatsapp_tasks SET status = ?, updated_at = NOW() WHERE id = ?')
                    ->execute([$waStatus, $waId]);
            }
        }

        if ($updatesTable) {
            $pdo->prepare(
                'INSERT INTO task_updates (task_id, status, comment, updated_by) VALUES (?, ?, ?, ?)'
            )->execute([$taskCode, $statusLabel, $comment, $updatedBy]);
        }

        $pdo->commit();

        return true;
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        akh_whatsapp_task_sync_set_error('WhatsApp sync failed: ' . $e->getMessage());

        return false;
    }
}
