<?php

declare(strict_types=1);

/** @var array<string, int>|null */
$GLOBALS['akh_wa_message_acks_cache'] = null;

function akh_wa_messages_table_exists(): bool
{
    if (!function_exists('akh_db')) {
        return false;
    }

    try {
        $st = akh_db()->query("SHOW TABLES LIKE 'whatsapp_messages'");

        return $st !== false && $st->fetch(PDO::FETCH_NUM) !== false;
    } catch (Throwable) {
        return false;
    }
}

function akh_wa_message_acks_kv_key(): string
{
    return 'whatsapp_message_dashboard_acks';
}

/** @return array<string, int> task_code => last read message id */
function akh_wa_message_acks_load(): array
{
    if (is_array($GLOBALS['akh_wa_message_acks_cache'])) {
        return $GLOBALS['akh_wa_message_acks_cache'];
    }

    require_once __DIR__ . '/tasks.php';
    akh_tasks_require_kv();

    $raw = akh_kv_get(akh_wa_message_acks_kv_key());
    $out = [];
    if ($raw !== null && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            foreach ($decoded as $code => $maxId) {
                $norm = akh_task_normalize_id((string) $code);
                if ($norm === '') {
                    continue;
                }
                $out[$norm] = max((int) ($out[$norm] ?? 0), (int) $maxId);
            }
        }
    }

    $GLOBALS['akh_wa_message_acks_cache'] = $out;

    return $out;
}

function akh_wa_message_acks_invalidate(): void
{
    $GLOBALS['akh_wa_message_acks_cache'] = null;
}

function akh_wa_message_ack_max_id(string $taskCode): int
{
    require_once __DIR__ . '/tasks.php';

    $taskCode = akh_task_normalize_id(trim($taskCode));
    if ($taskCode === '') {
        return 0;
    }

    $acks = akh_wa_message_acks_load();

    return (int) ($acks[$taskCode] ?? 0);
}

/**
 * @param array<string, mixed> $row
 */
function akh_wa_message_is_client_incoming(array $row): bool
{
    $direction = strtolower(trim((string) ($row['direction'] ?? 'incoming')));
    if ($direction !== 'incoming') {
        return false;
    }
    $sender = strtolower(trim((string) ($row['sender'] ?? 'client')));
    if ($sender === '') {
        return true;
    }

    return $sender === 'client';
}

/**
 * @return array{sql: string, params: list<string>}
 */
function akh_wa_message_task_match_clause(string $taskCode): array
{
    require_once __DIR__ . '/tasks.php';

    $variants = akh_task_id_match_variants($taskCode);
    if ($variants === []) {
        return ['sql' => '0', 'params' => []];
    }

    $placeholder = implode(',', array_fill(0, count($variants), '?'));

    return [
        'sql' => "TRIM(task_code) IN ({$placeholder})",
        'params' => $variants,
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function akh_wa_messages_list_for_task(string $taskCode, int $limit = 300): array
{
    if (!akh_wa_messages_table_exists()) {
        return [];
    }

    $match = akh_wa_message_task_match_clause($taskCode);
    if ($match['sql'] === '0') {
        return [];
    }

    $limit = max(1, min(500, $limit));

    try {
        $sql = "SELECT id, phone, task_code, direction, sender, message, status, created_at
                FROM whatsapp_messages
                WHERE {$match['sql']}
                ORDER BY created_at ASC, id ASC
                LIMIT {$limit}";
        $st = akh_db()->prepare($sql);
        $st->execute($match['params']);
        $out = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            if (is_array($row)) {
                $out[] = $row;
            }
        }

        return $out;
    } catch (Throwable $e) {
        error_log('akh_wa_messages_list_for_task: ' . $e->getMessage());

        return [];
    }
}

function akh_wa_message_task_has_unread(string $taskCode): bool
{
    return akh_wa_message_unread_count_for_task($taskCode) > 0;
}

function akh_wa_message_unread_count_for_task(string $taskCode): int
{
    if (!akh_wa_messages_table_exists()) {
        return 0;
    }

    $match = akh_wa_message_task_match_clause($taskCode);
    if ($match['sql'] === '0') {
        return 0;
    }

    $ack = akh_wa_message_ack_max_id($taskCode);

    try {
        $sql = "SELECT COUNT(*) FROM whatsapp_messages
                WHERE {$match['sql']}
                  AND LOWER(TRIM(direction)) = 'incoming'
                  AND LOWER(TRIM(COALESCE(sender, 'client'))) = 'client'
                  AND id > ?";
        $params = array_merge($match['params'], [$ack]);
        $st = akh_db()->prepare($sql);
        $st->execute($params);

        return (int) $st->fetchColumn();
    } catch (Throwable $e) {
        error_log('akh_wa_message_unread_count_for_task: ' . $e->getMessage());

        return 0;
    }
}

/**
 * @return list<array<string, mixed>>
 */
function akh_wa_messages_unread_rows(): array
{
    if (!akh_wa_messages_table_exists()) {
        return [];
    }

    $acks = akh_wa_message_acks_load();
    $out = [];

    try {
        $st = akh_db()->query(
            "SELECT id, phone, task_code, direction, sender, message, status, created_at
             FROM whatsapp_messages
             WHERE LOWER(TRIM(direction)) = 'incoming'
               AND LOWER(TRIM(COALESCE(sender, 'client'))) = 'client'
             ORDER BY id ASC"
        );
        if ($st === false) {
            return [];
        }
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            if (!is_array($row)) {
                continue;
            }
            require_once __DIR__ . '/tasks.php';
            $code = akh_task_normalize_id((string) ($row['task_code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $ack = (int) ($acks[$code] ?? 0);
            if ((int) ($row['id'] ?? 0) <= $ack) {
                continue;
            }
            $out[] = $row;
        }
    } catch (Throwable $e) {
        error_log('akh_wa_messages_unread_rows: ' . $e->getMessage());
    }

    return $out;
}

/**
 * @return array<string, array{count: int, max_id: int, preview: string, kind: string, priority: int, created_at: string}>
 */
function akh_wa_messages_pending_alerts_grouped(): array
{
    require_once __DIR__ . '/tasks.php';

    $out = [];
    foreach (akh_wa_messages_unread_rows() as $row) {
        $code = akh_task_normalize_id((string) ($row['task_code'] ?? ''));
        if ($code === '') {
            continue;
        }
        $id = (int) ($row['id'] ?? 0);
        $body = trim((string) ($row['message'] ?? ''));
        $preview = $body !== '' ? $body : 'New WhatsApp message';
        if (mb_strlen($preview) > 120) {
            $preview = mb_substr($preview, 0, 119) . '…';
        }
        $created = (string) ($row['created_at'] ?? '');
        if (!isset($out[$code])) {
            $out[$code] = [
                'count' => 0,
                'max_id' => $id,
                'preview' => $preview,
                'kind' => 'whatsapp_message',
                'priority' => 60,
                'created_at' => $created,
            ];
        }
        $out[$code]['count']++;
        if ($id >= (int) ($out[$code]['max_id'] ?? 0)) {
            $out[$code]['max_id'] = $id;
            $out[$code]['preview'] = $preview;
            $out[$code]['created_at'] = $created;
        }
    }

    return $out;
}

/**
 * @return array<string, array<string, mixed>>
 */
function akh_wa_messages_alerts_for_editor(string $editorUsername): array
{
    require_once __DIR__ . '/meeting-requests.php';

    $editorUsername = strtolower(trim($editorUsername));
    if ($editorUsername === '') {
        return [];
    }

    $owned = akh_meeting_request_assigned_task_codes_for_editor($editorUsername);
    $out = [];
    foreach (akh_wa_messages_pending_alerts_grouped() as $taskId => $alert) {
        if (!akh_meeting_request_editor_owns_code($owned, $taskId)) {
            continue;
        }
        $out[$taskId] = $alert;
    }

    return $out;
}

function akh_wa_messages_poll_signature(): string
{
    if (!akh_wa_messages_table_exists()) {
        return 'missing';
    }

    try {
        $row = akh_db()->query(
            "SELECT COUNT(*) AS c, COALESCE(MAX(id), 0) AS m
             FROM whatsapp_messages
             WHERE LOWER(TRIM(direction)) = 'incoming'
               AND LOWER(TRIM(COALESCE(sender, 'client'))) = 'client'"
        )->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return 'empty';
        }
        $acks = akh_wa_message_acks_load();

        return hash(
            'sha256',
            (string) ($row['c'] ?? '0')
            . '|' . (string) ($row['m'] ?? '0')
            . '|' . json_encode($acks, JSON_UNESCAPED_SLASHES)
        );
    } catch (Throwable $e) {
        error_log('akh_wa_messages_poll_signature: ' . $e->getMessage());

        return 'error';
    }
}

function akh_wa_message_mark_task_read(string $taskCode): void
{
    if (!akh_wa_messages_table_exists()) {
        return;
    }

    require_once __DIR__ . '/tasks.php';

    $taskCode = akh_task_normalize_id(trim($taskCode));
    if ($taskCode === '') {
        return;
    }

    $match = akh_wa_message_task_match_clause($taskCode);
    if ($match['sql'] === '0') {
        return;
    }

    $maxId = 0;
    try {
        $sql = "SELECT COALESCE(MAX(id), 0) FROM whatsapp_messages WHERE {$match['sql']}";
        $st = akh_db()->prepare($sql);
        $st->execute($match['params']);
        $maxId = (int) $st->fetchColumn();
    } catch (Throwable $e) {
        error_log('akh_wa_message_mark_task_read: ' . $e->getMessage());

        return;
    }

    $acks = akh_wa_message_acks_load();
    $acks[$taskCode] = max((int) ($acks[$taskCode] ?? 0), $maxId);
    akh_tasks_require_kv();
    akh_kv_set(akh_wa_message_acks_kv_key(), json_encode($acks, JSON_UNESCAPED_SLASHES) ?: '{}');
    akh_wa_message_acks_invalidate();
}

function akh_wa_message_mark_all_read(): void
{
    if (!akh_wa_messages_table_exists()) {
        return;
    }

    require_once __DIR__ . '/tasks.php';

    $acks = akh_wa_message_acks_load();
    try {
        $st = akh_db()->query(
            'SELECT task_code, MAX(id) AS max_id
             FROM whatsapp_messages
             GROUP BY task_code'
        );
        if ($st === false) {
            return;
        }
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            if (!is_array($row)) {
                continue;
            }
            $code = akh_task_normalize_id((string) ($row['task_code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $acks[$code] = max((int) ($acks[$code] ?? 0), (int) ($row['max_id'] ?? 0));
        }
    } catch (Throwable $e) {
        error_log('akh_wa_message_mark_all_read: ' . $e->getMessage());

        return;
    }

    akh_tasks_require_kv();
    akh_kv_set(akh_wa_message_acks_kv_key(), json_encode($acks, JSON_UNESCAPED_SLASHES) ?: '{}');
    akh_wa_message_acks_invalidate();
}

function akh_whatsapp_message_kind_label(): string
{
    return 'WhatsApp message';
}

/**
 * @param list<array<string, mixed>> $waRows
 * @return list<array{at: string, role: string, who: string, text: string, source: string}>
 */
function akh_wa_messages_to_conversation_rows(array $waRows): array
{
    $out = [];
    foreach ($waRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $sender = strtolower(trim((string) ($row['sender'] ?? 'client')));
        $direction = strtolower(trim((string) ($row['direction'] ?? 'incoming')));
        if ($sender === 'system') {
            $role = 'system';
            $who = 'System';
        } elseif ($sender === 'editor' || $direction === 'outgoing') {
            $role = 'editor';
            $who = 'Editor (WhatsApp)';
        } else {
            $role = 'client';
            $who = 'Client (WhatsApp)';
        }
        $text = trim((string) ($row['message'] ?? ''));
        if ($text === '') {
            continue;
        }
        $out[] = [
            'at' => (string) ($row['created_at'] ?? ''),
            'role' => $role,
            'who' => $who,
            'text' => $text,
            'source' => 'whatsapp',
        ];
    }

    return $out;
}

/**
 * Portal thread + WhatsApp messages in chronological order.
 *
 * @param array<string, mixed> $t
 * @return list<array{at: string, role: string, who: string, text: string, source: string}>
 */
function akh_task_merged_conversation_list(array $t): array
{
    require_once __DIR__ . '/tasks.php';

    $portal = [];
    foreach (akh_task_conversation_list($t) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $role = (string) ($row['role'] ?? 'client');
        $portal[] = [
            'at' => (string) ($row['at'] ?? ''),
            'role' => $role,
            'who' => (string) ($row['who'] ?? ($role === 'editor' ? 'Editor' : 'Client')),
            'text' => (string) ($row['text'] ?? ''),
            'source' => 'portal',
        ];
    }

    $tid = akh_task_normalize_id((string) ($t['id'] ?? ''));
    $wa = $tid !== '' && akh_wa_messages_table_exists()
        ? akh_wa_messages_to_conversation_rows(akh_wa_messages_list_for_task($tid))
        : [];

    $merged = array_merge($portal, $wa);
    usort($merged, static function (array $a, array $b): int {
        $cmp = strcmp((string) ($a['at'] ?? ''), (string) ($b['at'] ?? ''));
        if ($cmp !== 0) {
            return $cmp;
        }

        return strcmp((string) ($a['source'] ?? ''), (string) ($b['source'] ?? ''));
    });

    return $merged;
}
