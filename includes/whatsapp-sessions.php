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

function akh_wa_session_phone_digits(string $phone): string
{
    return preg_replace('/\D+/', '', trim($phone));
}

function akh_wa_session_sql_phone_digits_expr(string $column = 'phone'): string
{
    return "REPLACE(REPLACE(REPLACE(TRIM({$column}), '+', ''), ' ', ''), '-', '')";
}

/**
 * @return list<string>
 */
function akh_wa_phone_digit_variants(string $phone): array
{
    $raw = trim($phone);
    $digits = akh_wa_session_phone_digits($raw);
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
 * Task codes / ids that may appear in whatsapp_sessions.selected_task.
 *
 * @return list<string>
 */
function akh_wa_session_task_match_variants(string $taskCode): array
{
    require_once __DIR__ . '/tasks.php';
    require_once __DIR__ . '/whatsapp-tasks.php';

    $taskCode = akh_task_normalize_id(trim($taskCode));
    if ($taskCode === '') {
        return [];
    }

    $variants = akh_task_id_match_variants($taskCode);
    $wa = akh_wa_task_by_code($taskCode);
    if (is_array($wa)) {
        $waId = trim((string) ($wa['id'] ?? ''));
        if ($waId !== '') {
            $variants[] = $waId;
        }
        $waCode = trim((string) ($wa['task_code'] ?? ''));
        if ($waCode !== '') {
            $variants[] = $waCode;
        }
    }

    return array_values(array_unique(array_filter($variants, static fn (string $v): bool => $v !== '')));
}

/**
 * @return ?array<string, mixed>
 */
function akh_wa_session_row_by_phone_digits(string $phoneDigits): ?array
{
    if ($phoneDigits === '' || !akh_wa_sessions_table_exists()) {
        return null;
    }

    $expr = akh_wa_session_sql_phone_digits_expr('phone');

    try {
        $sql = "SELECT phone, current_step, selected_task
                FROM whatsapp_sessions
                WHERE {$expr} = ?
                LIMIT 1";
        $st = akh_db()->prepare($sql);
        $st->execute([$phoneDigits]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    } catch (Throwable $e) {
        error_log('akh_wa_session_row_by_phone_digits: ' . $e->getMessage());

        return null;
    }
}

/**
 * @return ?array<string, mixed>
 */
function akh_wa_session_row_by_task_code(string $taskCode): ?array
{
    $taskVariants = akh_wa_session_task_match_variants($taskCode);
    if ($taskVariants === [] || !akh_wa_sessions_table_exists()) {
        return null;
    }

    $tph = implode(',', array_fill(0, count($taskVariants), '?'));

    try {
        $sql = "SELECT phone, current_step, selected_task
                FROM whatsapp_sessions
                WHERE TRIM(COALESCE(selected_task, '')) IN ({$tph})
                LIMIT 1";
        $st = akh_db()->prepare($sql);
        $st->execute($taskVariants);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    } catch (Throwable $e) {
        error_log('akh_wa_session_row_by_task_code: ' . $e->getMessage());

        return null;
    }
}

function akh_wa_session_reset_sql_set_clause(): string
{
    return "current_step = 'menu',
            selected_task = NULL,
            project_name = NULL,
            task_type = NULL,
            project_details = NULL,
            reference_link = NULL,
            delivery_type = NULL,
            drive_link = NULL,
            comments = NULL";
}

/**
 * Reset WhatsApp bot session for the client tied to this task/message phone.
 *
 * @return array{ok: bool, rows: int, phone?: string, error?: string}
 */
function akh_wa_session_reset_for_context(string $taskCode, string $phone = ''): array
{
    if (!akh_wa_sessions_table_exists()) {
        return ['ok' => false, 'rows' => 0, 'error' => 'Table whatsapp_sessions was not found.'];
    }

    require_once __DIR__ . '/tasks.php';

    $taskCode = akh_task_normalize_id(trim($taskCode));
    $phoneDigits = akh_wa_session_phone_digits($phone);
    $taskVariants = $taskCode !== '' ? akh_wa_session_task_match_variants($taskCode) : [];
    $setSql = akh_wa_session_reset_sql_set_clause();
    $phoneExpr = akh_wa_session_sql_phone_digits_expr('phone');
    $totalRows = 0;

    try {
        if ($phoneDigits !== '') {
            $sql = "UPDATE whatsapp_sessions SET {$setSql} WHERE {$phoneExpr} = ?";
            $st = akh_db()->prepare($sql);
            $st->execute([$phoneDigits]);
            $totalRows += $st->rowCount();
        }

        if ($taskVariants !== []) {
            $tph = implode(',', array_fill(0, count($taskVariants), '?'));
            $sql = "UPDATE whatsapp_sessions SET {$setSql}
                    WHERE TRIM(COALESCE(selected_task, '')) IN ({$tph})";
            $st = akh_db()->prepare($sql);
            $st->execute($taskVariants);
            $totalRows += $st->rowCount();
        }

        $sessionRow = null;
        if ($phoneDigits !== '') {
            $sessionRow = akh_wa_session_row_by_phone_digits($phoneDigits);
        }
        if ($sessionRow === null && $taskCode !== '') {
            $sessionRow = akh_wa_session_row_by_task_code($taskCode);
        }

        if ($sessionRow === null) {
            error_log(
                'akh_wa_session_reset: no session row found task=' . $taskCode
                . ' phone_digits=' . $phoneDigits
                . ' updated_rows=' . $totalRows
            );

            return ['ok' => false, 'rows' => $totalRows, 'error' => 'No WhatsApp session row matched this client.'];
        }

        $exactPhone = trim((string) ($sessionRow['phone'] ?? ''));
        if ($exactPhone !== '') {
            $sql = "UPDATE whatsapp_sessions SET {$setSql} WHERE phone = ?";
            $st = akh_db()->prepare($sql);
            $st->execute([$exactPhone]);
            $totalRows += $st->rowCount();
        }

        $after = $phoneDigits !== ''
            ? akh_wa_session_row_by_phone_digits($phoneDigits)
            : akh_wa_session_row_by_task_code($taskCode);
        if ($after === null) {
            $after = $sessionRow;
        }

        $step = strtolower(trim((string) ($after['current_step'] ?? '')));
        $selected = trim((string) ($after['selected_task'] ?? ''));

        error_log(
            'akh_wa_session_reset: task=' . $taskCode
            . ' phone_digits=' . $phoneDigits
            . ' exact_phone=' . $exactPhone
            . ' tasks=' . json_encode($taskVariants, JSON_UNESCAPED_SLASHES)
            . ' updated_rows=' . $totalRows
            . ' after_step=' . $step
            . ' after_selected=' . $selected
        );

        if ($step !== 'menu' || $selected !== '') {
            return [
                'ok' => false,
                'rows' => $totalRows,
                'phone' => $exactPhone !== '' ? $exactPhone : $phone,
                'error' => 'WhatsApp session was found but current_step did not reset to menu (now: ' . ($step !== '' ? $step : 'empty') . ').',
            ];
        }

        return [
            'ok' => true,
            'rows' => max($totalRows, 1),
            'phone' => $exactPhone !== '' ? $exactPhone : $phone,
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
