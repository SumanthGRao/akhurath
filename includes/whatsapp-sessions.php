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
 * @return list<string>
 */
function akh_wa_phone_digit_variants(string $phone): array
{
    $raw = trim($phone);
    $digits = preg_replace('/\D+/', '', $raw);
    if ($digits === '') {
        return $raw !== '' ? [$raw] : [];
    }

    $variants = [$raw, $digits, '+' . $digits];
    if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
        $local = substr($digits, 2);
        $variants[] = $local;
        $variants[] = '91' . $local;
        $variants[] = '+91' . $local;
    }
    if (strlen($digits) === 10) {
        $variants[] = '91' . $digits;
        $variants[] = '+91' . $digits;
    }

    return array_values(array_unique(array_filter(array_map('trim', $variants), static fn (string $v): bool => $v !== '')));
}

/**
 * Collect likely client phone strings for a task (tasks row, recent messages, active session).
 *
 * @return list<string>
 */
function akh_wa_session_phone_candidates(string $taskCode, string $primaryPhone = ''): array
{
    require_once __DIR__ . '/tasks.php';
    require_once __DIR__ . '/whatsapp-tasks.php';
    require_once __DIR__ . '/whatsapp-messages.php';

    $taskCode = akh_task_normalize_id(trim($taskCode));
    $phones = [];

    foreach (akh_wa_phone_digit_variants($primaryPhone) as $variant) {
        $phones[] = $variant;
    }

    if ($taskCode !== '') {
        $wa = akh_wa_task_by_code($taskCode);
        if (is_array($wa)) {
            foreach (akh_wa_phone_digit_variants((string) ($wa['phone'] ?? '')) as $variant) {
                $phones[] = $variant;
            }
        }

        if (akh_wa_messages_table_exists()) {
            $match = akh_wa_message_task_match_clause($taskCode);
            if ($match['sql'] !== '0') {
                try {
                    $sql = "SELECT phone FROM whatsapp_messages
                            WHERE {$match['sql']} AND TRIM(COALESCE(phone, '')) <> ''
                            ORDER BY id DESC LIMIT 5";
                    $st = akh_db()->prepare($sql);
                    $st->execute($match['params']);
                    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                        if (!is_array($row)) {
                            continue;
                        }
                        foreach (akh_wa_phone_digit_variants((string) ($row['phone'] ?? '')) as $variant) {
                            $phones[] = $variant;
                        }
                    }
                } catch (Throwable $e) {
                    error_log('akh_wa_session_phone_candidates messages: ' . $e->getMessage());
                }
            }
        }

        if (akh_wa_sessions_table_exists()) {
            $taskVariants = akh_task_id_match_variants($taskCode);
            if ($taskVariants !== []) {
                $tph = implode(',', array_fill(0, count($taskVariants), '?'));
                try {
                    $st = akh_db()->prepare(
                        "SELECT phone FROM whatsapp_sessions
                         WHERE TRIM(COALESCE(selected_task, '')) IN ({$tph})
                         LIMIT 5"
                    );
                    $st->execute($taskVariants);
                    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                        if (!is_array($row)) {
                            continue;
                        }
                        foreach (akh_wa_phone_digit_variants((string) ($row['phone'] ?? '')) as $variant) {
                            $phones[] = $variant;
                        }
                    }
                } catch (Throwable $e) {
                    error_log('akh_wa_session_phone_candidates sessions: ' . $e->getMessage());
                }
            }
        }
    }

    return array_values(array_unique(array_filter($phones, static fn (string $v): bool => $v !== '')));
}

/**
 * @param list<string> $phoneVariants
 * @param list<string> $taskVariants
 * @return array{sql: string, params: list<string>}|null
 */
function akh_wa_session_match_clause(array $phoneVariants, array $taskVariants): ?array
{
    $where = [];
    $params = [];

    if ($phoneVariants !== []) {
        $ph = implode(',', array_fill(0, count($phoneVariants), '?'));
        $where[] = "TRIM(phone) IN ({$ph})";
        foreach ($phoneVariants as $variant) {
            $params[] = $variant;
        }

        $digitSet = [];
        foreach ($phoneVariants as $variant) {
            $digits = preg_replace('/\D+/', '', $variant);
            if ($digits !== '') {
                $digitSet[$digits] = true;
            }
        }
        if ($digitSet !== []) {
            $digits = array_keys($digitSet);
            $dph = implode(',', array_fill(0, count($digits), '?'));
            $where[] = "REPLACE(REPLACE(REPLACE(TRIM(phone), '+', ''), ' ', ''), '-', '') IN ({$dph})";
            foreach ($digits as $digit) {
                $params[] = $digit;
            }
        }
    }

    if ($taskVariants !== []) {
        $tph = implode(',', array_fill(0, count($taskVariants), '?'));
        $where[] = "TRIM(COALESCE(selected_task, '')) IN ({$tph})";
        foreach ($taskVariants as $variant) {
            $params[] = $variant;
        }
    }

    if ($where === []) {
        return null;
    }

    return [
        'sql' => '(' . implode(' OR ', $where) . ')',
        'params' => $params,
    ];
}

/**
 * @return array{ok: bool, rows: int, phone?: string, error?: string}
 */
function akh_wa_session_verify_menu_state(array $phoneVariants, array $taskVariants): array
{
    $match = akh_wa_session_match_clause($phoneVariants, $taskVariants);
    if ($match === null) {
        return ['ok' => false, 'rows' => 0, 'error' => 'No phone or task match criteria.'];
    }

    try {
        $sql = "SELECT phone, current_step, selected_task
                FROM whatsapp_sessions
                WHERE {$match['sql']}
                LIMIT 1";
        $st = akh_db()->prepare($sql);
        $st->execute($match['params']);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return ['ok' => false, 'rows' => 0, 'error' => 'No WhatsApp session row matched this client.'];
        }

        $step = strtolower(trim((string) ($row['current_step'] ?? '')));
        $selected = trim((string) ($row['selected_task'] ?? ''));
        $ok = $step === 'menu' && $selected === '';

        return [
            'ok' => $ok,
            'rows' => $ok ? 1 : 0,
            'phone' => trim((string) ($row['phone'] ?? '')),
            'error' => $ok ? null : 'Session row exists but is not back on the menu yet.',
        ];
    } catch (Throwable $e) {
        error_log('akh_wa_session_verify_menu_state: ' . $e->getMessage());

        return ['ok' => false, 'rows' => 0, 'error' => 'Could not verify WhatsApp session state.'];
    }
}

/**
 * Reset WhatsApp bot session state for a client phone / active task.
 *
 * @return array{ok: bool, rows: int, phone?: string, error?: string}
 */
function akh_wa_session_reset_for_context(string $taskCode, string $phone = ''): array
{
    require_once __DIR__ . '/tasks.php';

    if (!akh_wa_sessions_table_exists()) {
        return ['ok' => false, 'rows' => 0, 'error' => 'Table whatsapp_sessions was not found.'];
    }

    $taskCode = akh_task_normalize_id(trim($taskCode));
    $phoneVariants = akh_wa_session_phone_candidates($taskCode, $phone);
    $taskVariants = $taskCode !== '' ? akh_task_id_match_variants($taskCode) : [];

    $match = akh_wa_session_match_clause($phoneVariants, $taskVariants);
    if ($match === null) {
        return ['ok' => false, 'rows' => 0, 'error' => 'No client phone number is linked to this task.'];
    }

    try {
        $sql = "UPDATE whatsapp_sessions SET
                    current_step = 'menu',
                    selected_task = NULL,
                    project_name = NULL,
                    task_type = NULL,
                    project_details = NULL,
                    reference_link = NULL,
                    delivery_type = NULL,
                    drive_link = NULL,
                    comments = NULL
                WHERE {$match['sql']}";
        $st = akh_db()->prepare($sql);
        $st->execute($match['params']);
        $rows = $st->rowCount();

        error_log(
            'akh_wa_session_reset: task=' . $taskCode
            . ' phones=' . json_encode($phoneVariants, JSON_UNESCAPED_SLASHES)
            . ' tasks=' . json_encode($taskVariants, JSON_UNESCAPED_SLASHES)
            . ' rows=' . $rows
        );

        $verify = akh_wa_session_verify_menu_state($phoneVariants, $taskVariants);
        if (($verify['ok'] ?? false) === true) {
            return [
                'ok' => true,
                'rows' => max($rows, 1),
                'phone' => (string) ($verify['phone'] ?? $phone),
            ];
        }

        return [
            'ok' => false,
            'rows' => $rows,
            'error' => (string) ($verify['error'] ?? 'Could not reset the WhatsApp session.'),
        ];
    } catch (Throwable $e) {
        error_log('akh_wa_session_reset_for_context: ' . $e->getMessage());

        return ['ok' => false, 'rows' => 0, 'error' => 'Could not reset the WhatsApp session.'];
    }
}

/**
 * @deprecated Use akh_wa_session_reset_for_context()
 */
function akh_wa_session_reset_for_phone(string $phone): bool
{
    $result = akh_wa_session_reset_for_context('', $phone);

    return ($result['ok'] ?? false) === true;
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

    $reset = akh_wa_session_reset_for_context($taskCode, $phone);
    if (($reset['ok'] ?? false) !== true) {
        return [
            'ok' => false,
            'error' => (string) ($reset['error'] ?? 'Could not reset the WhatsApp session.'),
        ];
    }

    $resolvedPhone = trim((string) ($reset['phone'] ?? $phone));
    if ($resolvedPhone === '') {
        $resolvedPhone = $phone;
    }

    $send = akh_wa_message_send_editor_outbound(
        $taskCode,
        $resolvedPhone,
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
