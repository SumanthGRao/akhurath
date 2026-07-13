<?php

declare(strict_types=1);

function akh_wa_sessions_table_exists(): bool
{
    if (!function_exists('akh_db')) {
        return false;
    }

    try {
        $st = akh_db()->query("SHOW TABLES LIKE 'whatsapp_sessions'");

        return $st !== false && $st->fetch(PDO::FETCH_NUM) !== false;
    } catch (Throwable) {
        return false;
    }
}

function akh_wa_chat_close_message_text(): string
{
    return "🔒 *Chat Closed*\n"
        . 'Thank you for chatting with Akhurath Studio. Your session has been closed. '
        . "You can reply 'Hi' at any time to see the main menu again.";
}

/**
 * Reset WhatsApp bot session state for a client phone number.
 */
function akh_wa_session_reset_for_phone(string $phone): bool
{
    $phone = trim($phone);
    if ($phone === '' || !akh_wa_sessions_table_exists()) {
        return false;
    }

    try {
        $st = akh_db()->prepare(
            'UPDATE whatsapp_sessions SET
                current_step = ?,
                selected_task = NULL,
                project_name = NULL,
                task_type = NULL,
                project_details = NULL,
                reference_link = NULL,
                delivery_type = NULL,
                drive_link = NULL,
                comments = NULL
             WHERE phone = ?'
        );
        $st->execute(['menu', $phone]);

        return true;
    } catch (Throwable $e) {
        error_log('akh_wa_session_reset_for_phone: ' . $e->getMessage());

        return false;
    }
}

/**
 * End chat: reset whatsapp_sessions, then send goodbye via whatsapp_messages + n8n.
 *
 * @return array{ok: true, message_id: int}|array{ok: false, error: string}
 */
function akh_editor_end_whatsapp_chat(string $editorUsername, string $taskId): array
{
    require_once __DIR__ . '/tasks.php';
    require_once __DIR__ . '/whatsapp-messages.php';

    $editorUsername = strtolower(trim($editorUsername));
    $taskId = trim($taskId);
    if ($taskId === '' || $editorUsername === '') {
        return ['ok' => false, 'error' => 'Invalid request.'];
    }

    $t = akh_task_by_id($taskId);
    if ($t === null) {
        foreach (akh_tasks_load() as $row) {
            if (akh_task_ids_match((string) ($row['id'] ?? ''), $taskId)) {
                $t = $row;
                break;
            }
        }
    }
    if ($t === null) {
        return ['ok' => false, 'error' => 'Task not found.'];
    }
    if (strtolower(trim((string) ($t['assigned_editor'] ?? ''))) !== $editorUsername) {
        return ['ok' => false, 'error' => 'This task is not assigned to you.'];
    }

    $taskCode = akh_task_normalize_id((string) ($t['id'] ?? $taskId));
    if ($taskCode === '') {
        return ['ok' => false, 'error' => 'Task code is missing.'];
    }

    $phone = akh_wa_message_phone_for_task($taskCode);
    if ($phone === '') {
        return ['ok' => false, 'error' => 'No WhatsApp phone number is linked to this task.'];
    }

    if (!akh_wa_session_reset_for_phone($phone)) {
        return ['ok' => false, 'error' => 'Could not reset the WhatsApp session.'];
    }

    $send = akh_wa_message_send_editor_outbound(
        $taskCode,
        $phone,
        akh_wa_chat_close_message_text(),
        $editorUsername
    );
    if (($send['ok'] ?? false) !== true) {
        return [
            'ok' => false,
            'error' => (string) ($send['error'] ?? 'Session was reset but the goodbye message could not be sent.'),
        ];
    }

    return [
        'ok' => true,
        'message_id' => (int) ($send['message_id'] ?? 0),
    ];
}
