<?php

declare(strict_types=1);

/** @var array<string, int>|null */
$GLOBALS['akh_wa_message_acks_cache'] = null;

/** @var array<string, bool>|null */
$GLOBALS['akh_wa_message_columns_cache'] = null;

function akh_wa_messages_column_exists(string $column): bool
{
    if (!akh_wa_messages_table_exists()) {
        return false;
    }
    if (!is_array($GLOBALS['akh_wa_message_columns_cache'])) {
        $GLOBALS['akh_wa_message_columns_cache'] = [];
        try {
            $schema = akh_db()->query('SELECT DATABASE()')->fetchColumn();
            if (!is_string($schema) || $schema === '') {
                return false;
            }
            $st = akh_db()->prepare(
                'SELECT COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?'
            );
            $st->execute([$schema, 'whatsapp_messages']);
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                if (!is_array($row)) {
                    continue;
                }
                $name = (string) ($row['COLUMN_NAME'] ?? '');
                if ($name !== '') {
                    $GLOBALS['akh_wa_message_columns_cache'][$name] = true;
                }
            }
        } catch (Throwable) {
            $GLOBALS['akh_wa_message_columns_cache'] = [];
        }
    }

    return ($GLOBALS['akh_wa_message_columns_cache'][$column] ?? false) === true;
}

function akh_wa_messages_select_columns(): string
{
    $cols = ['id', 'phone', 'task_code', 'direction', 'sender', 'message', 'status', 'created_at'];
    if (akh_wa_messages_column_exists('customer_name')) {
        $cols[] = 'customer_name';
    }
    if (akh_wa_messages_column_exists('editor_name')) {
        $cols[] = 'editor_name';
    }

    return implode(', ', $cols);
}

function akh_wa_message_editor_display_name(string $editorUsername): string
{
    $editorUsername = strtolower(trim($editorUsername));
    if ($editorUsername === '') {
        return 'Editor';
    }

    return ucfirst($editorUsername);
}

function akh_wa_message_customer_name_for_task(string $taskCode): string
{
    require_once __DIR__ . '/tasks.php';
    require_once __DIR__ . '/whatsapp-tasks.php';

    $taskCode = akh_task_normalize_id(trim($taskCode));
    if ($taskCode === '') {
        return '';
    }

    $wa = akh_wa_task_by_code($taskCode);
    if (is_array($wa)) {
        $name = trim((string) ($wa['customer_name'] ?? ''));
        if ($name !== '') {
            return $name;
        }
    }

    $t = akh_task_by_id($taskCode);
    if (!is_array($t)) {
        return '';
    }

    $title = trim((string) ($t['title'] ?? ''));
    if ($title !== '') {
        return $title;
    }

    $client = trim((string) ($t['client_username'] ?? ''));
    if ($client !== '' && strtolower($client) !== 'whatsapp') {
        return $client;
    }

    return '';
}

function akh_wa_message_phone_for_task(string $taskCode): string
{
    require_once __DIR__ . '/whatsapp-tasks.php';

    $taskCode = akh_task_normalize_id(trim($taskCode));
    if ($taskCode === '') {
        return '';
    }

    $wa = akh_wa_task_by_code($taskCode);
    if (!is_array($wa)) {
        return '';
    }

    return trim((string) ($wa['phone'] ?? ''));
}

/**
 * @param array<string, mixed> $fields
 */
function akh_wa_message_insert(array $fields): ?int
{
    if (!akh_wa_messages_table_exists()) {
        return null;
    }

    $taskCode = akh_task_normalize_id(trim((string) ($fields['task_code'] ?? '')));
    $message = trim((string) ($fields['message'] ?? ''));
    if ($taskCode === '' || $message === '') {
        return null;
    }

    $direction = strtolower(trim((string) ($fields['direction'] ?? 'outgoing')));
    if (!in_array($direction, ['incoming', 'outgoing'], true)) {
        $direction = 'outgoing';
    }
    $sender = strtolower(trim((string) ($fields['sender'] ?? 'editor')));
    if (!in_array($sender, ['client', 'editor', 'system'], true)) {
        $sender = 'editor';
    }
    $status = strtolower(trim((string) ($fields['status'] ?? 'pending')));
    if ($status === '') {
        $status = $direction === 'incoming' ? 'received' : 'pending';
    }

    $cols = ['phone', 'task_code', 'direction', 'sender', 'message', 'status'];
    $vals = [
        trim((string) ($fields['phone'] ?? '')),
        $taskCode,
        $direction,
        $sender,
        $message,
        $status,
    ];
    if (akh_wa_messages_column_exists('customer_name')) {
        $cols[] = 'customer_name';
        $vals[] = trim((string) ($fields['customer_name'] ?? ''));
    }
    if (akh_wa_messages_column_exists('editor_name')) {
        $cols[] = 'editor_name';
        $vals[] = trim((string) ($fields['editor_name'] ?? ''));
    }

    $placeholders = implode(', ', array_fill(0, count($cols), '?'));

    try {
        $sql = 'INSERT INTO whatsapp_messages (' . implode(', ', $cols) . ') VALUES (' . $placeholders . ')';
        $st = akh_db()->prepare($sql);
        $st->execute($vals);
        $id = (int) akh_db()->lastInsertId();
        if ($id < 1) {
            return null;
        }

        $row = $fields;
        $row['id'] = $id;
        $row['task_code'] = $taskCode;
        $row['direction'] = $direction;
        $row['sender'] = $sender;
        $row['message'] = $message;
        $row['status'] = $status;
        akh_wa_message_dispatch_n8n_webhook($row);

        return $id;
    } catch (Throwable $e) {
        error_log('akh_wa_message_insert: ' . $e->getMessage());

        return null;
    }
}

function akh_wa_message_insert_editor_reply(string $taskCode, string $editorUsername, string $body): ?int
{
    $customerName = akh_wa_message_customer_name_for_task($taskCode);
    $editorName = akh_wa_message_editor_display_name($editorUsername);
    $phone = akh_wa_message_phone_for_task($taskCode);

    return akh_wa_message_insert([
        'task_code' => $taskCode,
        'phone' => $phone,
        'direction' => 'outgoing',
        'sender' => 'editor',
        'message' => $body,
        'customer_name' => $customerName,
        'editor_name' => $editorName,
        'status' => 'pending',
    ]);
}

/**
 * @param array<string, mixed> $row
 */
function akh_wa_message_dispatch_n8n_webhook(array $row): void
{
    if (!defined('AKH_N8N_WA_MESSAGE_WEBHOOK_URL')) {
        return;
    }
    $url = trim((string) AKH_N8N_WA_MESSAGE_WEBHOOK_URL);
    if ($url === '' || !str_starts_with($url, 'http')) {
        return;
    }

    $payload = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($payload)) {
        return;
    }

    try {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                return;
            }
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 4,
                CURLOPT_CONNECTTIMEOUT => 2,
            ]);
            curl_exec($ch);
            curl_close($ch);

            return;
        }

        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
                'content' => $payload,
                'timeout' => 4,
                'ignore_errors' => true,
            ],
        ]);
        @file_get_contents($url, false, $ctx);
    } catch (Throwable $e) {
        error_log('akh_wa_message_dispatch_n8n_webhook: ' . $e->getMessage());
    }
}

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
        $cols = akh_wa_messages_select_columns();
        $sql = "SELECT {$cols}
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
        $cols = akh_wa_messages_select_columns();
        $st = akh_db()->query(
            "SELECT {$cols}
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
            'SELECT COUNT(*) AS c, COALESCE(MAX(id), 0) AS m FROM whatsapp_messages'
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
        $customerName = trim((string) ($row['customer_name'] ?? ''));
        $editorName = trim((string) ($row['editor_name'] ?? ''));
        if ($sender === 'system') {
            $role = 'system';
            $who = 'System';
        } elseif ($sender === 'editor' || $direction === 'outgoing') {
            $role = 'editor';
            $who = $editorName !== '' ? $editorName : 'Editor';
        } else {
            $role = 'client';
            $who = $customerName !== '' ? $customerName : 'Client';
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
