<?php

declare(strict_types=1);

require_once __DIR__ . '/tasks.php';
require_once __DIR__ . '/task-status-log.php';
require_once __DIR__ . '/whatsapp-tasks.php';

function akh_wa_preview_messages_table_exists(): bool
{
    if (!function_exists('akh_db') || !akh_db_is_pdo()) {
        return false;
    }
    try {
        $st = akh_db()->query("SHOW TABLES LIKE 'whatsapp_preview_messages'");

        return $st !== false && $st->fetch(PDO::FETCH_NUM) !== false;
    } catch (Throwable) {
        return false;
    }
}

/**
 * Run preview queue + client feedback watchers (idempotent, safe on every poll).
 */
function akh_wa_preview_workflow_tick(): void
{
    static $ran = false;
    if ($ran) {
        return;
    }
    $ran = true;

    akh_wa_preview_messages_process_queue();
    akh_wa_preview_watch_incoming_messages();
    akh_wa_preview_watch_meeting_requests();
}

function akh_wa_preview_kv_key(string $suffix): string
{
    return 'wa_preview_workflow_' . $suffix;
}

function akh_wa_preview_kv_get_int(string $suffix): int
{
    if (!function_exists('akh_kv_get')) {
        require_once __DIR__ . '/app-kv.php';
    }
    if (!function_exists('akh_kv_get')) {
        return 0;
    }

    return (int) akh_kv_get(akh_wa_preview_kv_key($suffix), 0);
}

function akh_wa_preview_kv_set_int(string $suffix, int $value): void
{
    if (!function_exists('akh_kv_set')) {
        require_once __DIR__ . '/app-kv.php';
    }
    if (!function_exists('akh_kv_set')) {
        return;
    }
    akh_kv_set(akh_wa_preview_kv_key($suffix), (string) max(0, $value));
}

function akh_wa_preview_task_is_awaiting_feedback(string $taskCode): bool
{
    $taskCode = akh_task_normalize_id($taskCode);
    if ($taskCode === '') {
        return false;
    }
    $studio = akh_task_by_id($taskCode);
    if (is_array($studio) && strtolower(trim((string) ($studio['status'] ?? ''))) === 'preview_sent') {
        return true;
    }
    $wa = akh_wa_task_by_code($taskCode);

    return $wa !== null && strtolower(trim((string) ($wa['status'] ?? ''))) === 'preview_sent';
}

function akh_wa_workflow_set_preview_sent(string $taskCode): void
{
    $code = akh_task_normalize_id(trim($taskCode));
    if ($code === '') {
        return;
    }

    $wa = akh_wa_task_by_code($code);
    if ($wa !== null) {
        $waId = (int) ($wa['id'] ?? 0);
        if ($waId > 0) {
            akh_wa_task_update($waId, ['status' => 'preview_sent']);
        }

        return;
    }

    $studio = akh_task_by_id($code);
    $prev = is_array($studio) ? akh_task_status_log_normalize((string) ($studio['status'] ?? 'new')) : 'new';
    if ($prev === '') {
        $prev = 'new';
    }
    if ($prev === 'preview_sent') {
        return;
    }
    akh_task_status_log_record($code, $prev, 'preview_sent', 'whatsapp', 'Preview automation', 'Preview link pushed — status set to Preview sent.');
    akh_task_admin_set_status($code, 'preview_sent', 'whatsapp');
}

function akh_wa_workflow_revert_from_preview(string $taskCode, string $comment): void
{
    if (!akh_wa_preview_task_is_awaiting_feedback($taskCode)) {
        return;
    }

    $code = akh_task_normalize_id(trim($taskCode));
    if ($code === '') {
        return;
    }

    $studio = akh_task_by_id($code);
    $prev = is_array($studio) ? akh_task_status_log_normalize((string) ($studio['status'] ?? '')) : '';
    if ($prev === 'reverted') {
        return;
    }
    if ($prev === '') {
        $prev = 'preview_sent';
    }

    akh_task_status_log_record($code, $prev, 'reverted', 'whatsapp', 'Client feedback', $comment);
    akh_task_admin_set_status($code, 'reverted', 'whatsapp');

    if (!akh_wa_tasks_table_exists()) {
        return;
    }
    $wa = akh_wa_task_by_code($code);
    if ($wa === null) {
        return;
    }
    $waId = (int) ($wa['id'] ?? 0);
    if ($waId <= 0) {
        return;
    }
    try {
        akh_db()->prepare('UPDATE whatsapp_tasks SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
            ->execute(['review', $waId]);
    } catch (Throwable $e) {
        error_log('akh_wa_workflow_revert_from_preview: ' . $e->getMessage());
    }
}

function akh_wa_preview_messages_process_queue(): void
{
    if (!akh_wa_preview_messages_table_exists()) {
        return;
    }

    try {
        $st = akh_db()->query(
            'SELECT id, task_code FROM whatsapp_preview_messages
             WHERE processed_at IS NULL
             ORDER BY id ASC
             LIMIT 25'
        );
        if ($st === false) {
            return;
        }
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $mark = akh_db()->prepare(
            'UPDATE whatsapp_preview_messages SET processed_at = CURRENT_TIMESTAMP WHERE id = ? AND processed_at IS NULL'
        );
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            $code = akh_task_normalize_id((string) ($row['task_code'] ?? ''));
            if ($id <= 0 || $code === '') {
                if ($id > 0) {
                    $mark->execute([$id]);
                }
                continue;
            }
            akh_wa_workflow_set_preview_sent($code);
            $mark->execute([$id]);
        }
    } catch (Throwable $e) {
        error_log('akh_wa_preview_messages_process_queue: ' . $e->getMessage());
    }
}

function akh_wa_preview_watch_incoming_messages(): void
{
    if (!function_exists('akh_wa_messages_table_exists') || !akh_wa_messages_table_exists()) {
        return;
    }

    $lastId = akh_wa_preview_kv_get_int('message_id');
    try {
        if ($lastId === 0) {
            $seed = akh_db()->query('SELECT COALESCE(MAX(id), 0) FROM whatsapp_messages');
            $maxSeed = $seed !== false ? (int) $seed->fetchColumn() : 0;
            akh_wa_preview_kv_set_int('message_id', $maxSeed);

            return;
        }

        $st = akh_db()->prepare(
            'SELECT id, task_code, direction, sender
             FROM whatsapp_messages
             WHERE id > ?
             ORDER BY id ASC
             LIMIT 100'
        );
        $st->execute([$lastId]);
        $maxId = $lastId;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > $maxId) {
                $maxId = $id;
            }
            require_once __DIR__ . '/whatsapp-messages.php';
            if (!akh_wa_message_is_client_incoming($row)) {
                continue;
            }
            $code = akh_task_normalize_id((string) ($row['task_code'] ?? ''));
            if ($code === '') {
                continue;
            }
            akh_wa_workflow_revert_from_preview($code, 'Client WhatsApp message after preview — returned for revision.');
        }
        if ($maxId > $lastId) {
            akh_wa_preview_kv_set_int('message_id', $maxId);
        }
    } catch (Throwable $e) {
        error_log('akh_wa_preview_watch_incoming_messages: ' . $e->getMessage());
    }
}

function akh_wa_preview_watch_meeting_requests(): void
{
    require_once __DIR__ . '/meeting-requests.php';
    if (!akh_meeting_requests_table_exists()) {
        return;
    }

    $lastId = akh_wa_preview_kv_get_int('meeting_id');
    try {
        if ($lastId === 0) {
            $seed = akh_db()->query('SELECT COALESCE(MAX(id), 0) FROM meeting_requests');
            $maxSeed = $seed !== false ? (int) $seed->fetchColumn() : 0;
            akh_wa_preview_kv_set_int('meeting_id', $maxSeed);

            return;
        }

        $st = akh_db()->prepare(
            'SELECT id, task_code FROM meeting_requests WHERE id > ? ORDER BY id ASC LIMIT 100'
        );
        $st->execute([$lastId]);
        $maxId = $lastId;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > $maxId) {
                $maxId = $id;
            }
            $code = akh_task_normalize_id((string) ($row['task_code'] ?? ''));
            if ($code === '') {
                continue;
            }
            akh_wa_workflow_revert_from_preview($code, 'Meeting request after preview — returned for revision.');
        }
        if ($maxId > $lastId) {
            akh_wa_preview_kv_set_int('meeting_id', $maxId);
        }
    } catch (Throwable $e) {
        error_log('akh_wa_preview_watch_meeting_requests: ' . $e->getMessage());
    }
}
