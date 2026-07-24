<?php

declare(strict_types=1);

require_once __DIR__ . '/tasks.php';
require_once __DIR__ . '/whatsapp-tasks.php';
require_once __DIR__ . '/whatsapp-messages.php';
require_once __DIR__ . '/task-thread-panel.php';

/**
 * Build a minimal task row for merged conversation rendering on the WA dashboard.
 *
 * @return array<string, mixed>|null
 */
function akh_wa_dashboard_task_context(string $taskCode): ?array
{
    $taskCode = akh_task_normalize_id(trim($taskCode));
    if ($taskCode === '') {
        return null;
    }

    $studio = akh_task_by_id($taskCode);
    if (is_array($studio)) {
        return $studio;
    }

    $wa = akh_wa_task_by_code($taskCode);
    if (!is_array($wa)) {
        return null;
    }

    return [
        'id' => $taskCode,
        'phone' => trim((string) ($wa['phone'] ?? '')),
        'customer_name' => trim((string) ($wa['customer_name'] ?? '')),
        'project_name' => trim((string) ($wa['project_name'] ?? '')),
        'assigned_editor' => 'whatsapp',
        'conversation' => [],
    ];
}

/**
 * @return array{ok: bool, task_code?: string, msg_sig?: string, html?: string, error?: string}
 */
function akh_wa_dashboard_thread_poll(string $taskCode): array
{
    $t = akh_wa_dashboard_task_context($taskCode);
    if ($t === null) {
        return ['ok' => false, 'error' => 'not_found'];
    }

    $canonical = akh_task_normalize_id((string) ($t['id'] ?? $taskCode));

    return [
        'ok' => true,
        'task_code' => $canonical,
        'msg_sig' => akh_task_merged_conversation_sig($t),
        'html' => akh_render_task_thread_scroll_html($t, 'editor'),
    ];
}

/**
 * @return array{ok: bool, task_code?: string, msg_sig?: string, html?: string, error?: string}
 */
function akh_wa_dashboard_thread_send(string $operator, string $taskCode, string $body): array
{
    $operator = strtolower(trim($operator));
    if ($operator === '') {
        return ['ok' => false, 'error' => 'auth'];
    }

    $body = trim($body);
    if ($body === '' || mb_strlen($body) > 2000) {
        return ['ok' => false, 'error' => 'Message must be between 1 and 2000 characters.'];
    }

    $t = akh_wa_dashboard_task_context($taskCode);
    if ($t === null) {
        return ['ok' => false, 'error' => 'not_found'];
    }

    $canonical = akh_task_normalize_id((string) ($t['id'] ?? $taskCode));
    $phone = trim((string) ($t['phone'] ?? ''));
    if ($phone === '') {
        $phone = akh_wa_message_phone_for_task($canonical);
    }
    if ($phone === '') {
        return ['ok' => false, 'error' => 'No client phone on this task yet.'];
    }

    $send = akh_wa_message_send_editor_outbound($canonical, $phone, $body, $operator);
    if (($send['ok'] ?? false) !== true) {
        return ['ok' => false, 'error' => (string) ($send['error'] ?? 'Could not send message.')];
    }

    akh_wa_message_mark_task_read($canonical);

    $tAfter = akh_wa_dashboard_task_context($canonical);
    if (!is_array($tAfter)) {
        return ['ok' => true, 'task_code' => $canonical];
    }

    return [
        'ok' => true,
        'task_code' => $canonical,
        'msg_sig' => akh_task_merged_conversation_sig($tAfter),
        'html' => akh_render_task_thread_scroll_html($tAfter, 'editor'),
    ];
}

/**
 * @return array{ok: bool, task_code?: string, message_id?: int, html?: string, error?: string}
 */
function akh_wa_dashboard_end_chat(string $operator, string $taskCode): array
{
    $operator = strtolower(trim($operator));
    if ($operator === '') {
        return ['ok' => false, 'error' => 'auth'];
    }

    $t = akh_wa_dashboard_task_context($taskCode);
    if ($t === null) {
        return ['ok' => false, 'error' => 'not_found'];
    }

    $canonical = akh_task_normalize_id((string) ($t['id'] ?? $taskCode));
    require_once __DIR__ . '/whatsapp-sessions.php';
    $close = akh_wa_close_chat_session($canonical, $operator);
    if (($close['ok'] ?? false) !== true) {
        return ['ok' => false, 'error' => (string) ($close['error'] ?? 'Could not end chat.')];
    }

    $tAfter = akh_wa_dashboard_task_context($canonical);

    return [
        'ok' => true,
        'task_code' => $canonical,
        'message_id' => (int) ($close['message_id'] ?? 0),
        'html' => is_array($tAfter)
            ? akh_render_task_thread_scroll_html($tAfter, 'editor')
            : '',
    ];
}
