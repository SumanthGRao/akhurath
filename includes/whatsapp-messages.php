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
    if (is_array($wa)) {
        $phone = trim((string) ($wa['phone'] ?? ''));
        if ($phone !== '') {
            return $phone;
        }
    }

    if (akh_wa_messages_table_exists()) {
        $match = akh_wa_message_task_match_clause($taskCode);
        if ($match['sql'] !== '0') {
            try {
                $sql = "SELECT phone FROM whatsapp_messages
                        WHERE {$match['sql']} AND TRIM(COALESCE(phone, '')) <> ''
                        ORDER BY id DESC LIMIT 1";
                $st = akh_db()->prepare($sql);
                $st->execute($match['params']);
                $phone = $st->fetchColumn();
                if (is_string($phone) && trim($phone) !== '') {
                    return trim($phone);
                }
            } catch (Throwable $e) {
                error_log('akh_wa_message_phone_for_task: ' . $e->getMessage());
            }
        }
    }

    return '';
}

function akh_wa_message_direction_is_outbound(string $direction): bool
{
    $direction = strtolower(trim($direction));

    return $direction === 'outbound' || $direction === 'outgoing';
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

    $direction = strtolower(trim((string) ($fields['direction'] ?? 'outbound')));
    if (!in_array($direction, ['incoming', 'outgoing', 'outbound'], true)) {
        $direction = 'outbound';
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

        return $id;
    } catch (Throwable $e) {
        error_log('akh_wa_message_insert: ' . $e->getMessage());

        return null;
    }
}

/**
 * Step 2 — POST exact JSON payload to n8n after a successful editor outbound insert.
 */
function akh_wa_message_n8n_webhook_url(): string
{
    $default = 'https://n8n.akhurathstudio.com/webhook/ada421c4-2607-4b06-990b-51d0c704dd9c';
    if (defined('AKH_N8N_WA_MESSAGE_WEBHOOK_URL')) {
        $configured = trim((string) AKH_N8N_WA_MESSAGE_WEBHOOK_URL);
        if ($configured !== '') {
            return $configured;
        }
    }

    return $default;
}

/**
 * Step 2 — POST exact JSON payload to n8n after a successful editor outbound insert.
 */
function akh_wa_message_dispatch_n8n_editor_outbound(
    int $messageId,
    string $phone,
    string $taskCode,
    string $message,
    string $chatAction = ''
): void {
    $url = akh_wa_message_n8n_webhook_url();
    if ($url === '' || !str_starts_with($url, 'http')) {
        error_log('akh_n8n_webhook: skipped — invalid URL');

        return;
    }

    $body = [
        'message_id' => $messageId,
        'phone' => $phone,
        'task_code' => $taskCode,
        'message' => $message,
    ];
    $chatAction = trim($chatAction);
    if ($chatAction !== '') {
        $body['chat_action'] = $chatAction;
    }

    $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($payload)) {
        error_log('akh_n8n_webhook: skipped — could not encode JSON payload');

        return;
    }

    try {
        if (!function_exists('curl_init')) {
            error_log('akh_n8n_webhook: cURL extension is not available');

            return;
        }
        $ch = curl_init($url);
        if ($ch === false) {
            error_log('akh_n8n_webhook: curl_init failed for URL ' . $url);

            return;
        }

        error_log('akh_n8n_webhook: POST ' . $url . ' payload=' . $payload);

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 2,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);

        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            error_log('akh_n8n_webhook: cURL errno=' . $errno . ' error=' . $error);

            return;
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            error_log(
                'akh_n8n_webhook: HTTP status=' . $httpCode
                . ' response=' . mb_substr((string) $response, 0, 500)
            );

            return;
        }

        error_log('akh_n8n_webhook: OK HTTP status=' . $httpCode);
    } catch (Throwable $e) {
        error_log('akh_n8n_webhook: exception ' . $e->getMessage());
    }
}

/**
 * Editor outbound message handler (Step 1: insert, Step 2: n8n webhook).
 *
 * Phone and task_code are taken from the active editor desk context — the open
 * task's whatsapp_tasks row (phone) and normalized task id (task_code).
 *
 * @return array{ok: true, message_id: int}|array{ok: false, error: string}
 */
function akh_wa_message_send_editor_outbound(
    string $taskCode,
    string $phone,
    string $messageText,
    string $editorUsername = '',
    string $chatAction = ''
): array {
    require_once __DIR__ . '/tasks.php';

    $taskCode = akh_task_normalize_id(trim($taskCode));
    $phone = trim($phone);
    $messageText = trim($messageText);
    $editorUsername = strtolower(trim($editorUsername));

    if ($taskCode === '') {
        return ['ok' => false, 'error' => 'Task code is required.'];
    }
    if ($messageText === '' || mb_strlen($messageText) > 2000) {
        return ['ok' => false, 'error' => 'Message must be between 1 and 2000 characters.'];
    }
    if (!akh_wa_messages_table_exists()) {
        return ['ok' => false, 'error' => 'Message store is not available.'];
    }

    if ($phone === '') {
        $phone = akh_wa_message_phone_for_task($taskCode);
    }

    $customerName = akh_wa_message_customer_name_for_task($taskCode);
    $editorName = $editorUsername !== ''
        ? akh_wa_message_editor_display_name($editorUsername)
        : '';

    $messageId = akh_wa_message_insert([
        'phone' => $phone,
        'task_code' => $taskCode,
        'direction' => 'outbound',
        'sender' => 'editor',
        'message' => $messageText,
        'status' => 'pending',
        'customer_name' => $customerName,
        'editor_name' => $editorName,
    ]);

    if ($messageId === null) {
        return ['ok' => false, 'error' => 'Could not save message.'];
    }

    akh_wa_message_dispatch_n8n_editor_outbound($messageId, $phone, $taskCode, $messageText, $chatAction);

    return ['ok' => true, 'message_id' => $messageId];
}

function akh_wa_message_insert_editor_reply(string $taskCode, string $editorUsername, string $body): ?int
{
    $result = akh_wa_message_send_editor_outbound(
        $taskCode,
        akh_wa_message_phone_for_task($taskCode),
        $body,
        $editorUsername
    );

    if (($result['ok'] ?? false) !== true) {
        return null;
    }

    return (int) ($result['message_id'] ?? 0);
}

/**
 * @deprecated Use akh_wa_message_dispatch_n8n_editor_outbound() for editor sends.
 * @param array<string, mixed> $row
 */
function akh_wa_message_dispatch_n8n_webhook(array $row): void
{
    $messageId = (int) ($row['id'] ?? 0);
    if ($messageId < 1) {
        return;
    }
    akh_wa_message_dispatch_n8n_editor_outbound(
        $messageId,
        trim((string) ($row['phone'] ?? '')),
        akh_task_normalize_id(trim((string) ($row['task_code'] ?? ''))),
        trim((string) ($row['message'] ?? ''))
    );
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
    $sender = strtolower(trim((string) ($row['sender'] ?? 'client')));
    if (in_array($sender, ['editor', 'system'], true)) {
        return false;
    }
    $direction = strtolower(trim((string) ($row['direction'] ?? 'incoming')));
    if ($direction === '') {
        $direction = 'incoming';
    }
    if ($direction === 'outbound') {
        return false;
    }
    if ($direction === 'outgoing' && $sender === 'editor') {
        return false;
    }

    return $direction === 'incoming'
        || ($direction === 'outgoing' && ($sender === '' || $sender === 'client'));
}

/** SQL fragment: rows that should count as unread client WhatsApp messages. */
function akh_wa_message_sql_client_incoming_clause(): string
{
    return <<<'SQL'
(
    LOWER(TRIM(COALESCE(sender, ''))) NOT IN ('editor', 'system')
    AND LOWER(TRIM(COALESCE(NULLIF(TRIM(direction), ''), 'incoming'))) NOT IN ('outbound')
    AND NOT (
        LOWER(TRIM(COALESCE(sender, ''))) = 'editor'
        AND LOWER(TRIM(COALESCE(NULLIF(TRIM(direction), ''), 'outbound'))) IN ('outbound', 'outgoing')
    )
)
SQL;
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
        $clause = akh_wa_message_sql_client_incoming_clause();
        $sql = "SELECT COUNT(*) FROM whatsapp_messages
                WHERE {$match['sql']}
                  AND {$clause}
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
        $clause = akh_wa_message_sql_client_incoming_clause();
        $cols = akh_wa_messages_select_columns();
        $st = akh_db()->query(
            "SELECT {$cols}
             FROM whatsapp_messages
             WHERE {$clause}
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
            if (!akh_wa_message_is_client_incoming($row)) {
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
        } elseif ($sender === 'editor' || (akh_wa_message_direction_is_outbound($direction) && !akh_wa_message_is_client_incoming($row))) {
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
