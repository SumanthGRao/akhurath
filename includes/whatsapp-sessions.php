<?php

declare(strict_types=1);

function akh_wa_chat_close_message_text(): string
{
    return "🔒 *Chat Closed*\n"
        . 'Thank you for chatting with Akhurath Studio. Your session has been closed. '
        . "You can reply 'Hi' at any time to see the main menu again.";
}

/**
 * End chat: insert goodbye into whatsapp_messages and notify n8n (chat_action=close).
 * Session reset is handled by n8n — PHP does not update whatsapp_sessions.
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

    $send = akh_wa_message_send_editor_outbound(
        $taskCode,
        $phone,
        akh_wa_chat_close_message_text(),
        $editorUsername,
        'close'
    );
    if (($send['ok'] ?? false) !== true) {
        return [
            'ok' => false,
            'error' => (string) ($send['error'] ?? 'Could not send the goodbye message.'),
        ];
    }

    return [
        'ok' => true,
        'message_id' => (int) ($send['message_id'] ?? 0),
    ];
}
