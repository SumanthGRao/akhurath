<?php

declare(strict_types=1);

/** @var array<string, bool>|null */
$GLOBALS['akh_task_notification_columns'] = null;

/** @return array<string, bool> */
function akh_task_notification_columns(): array
{
    if (is_array($GLOBALS['akh_task_notification_columns'] ?? null)) {
        return $GLOBALS['akh_task_notification_columns'];
    }

    $cols = [];
    if (!akh_task_notification_table_exists()) {
        $GLOBALS['akh_task_notification_columns'] = $cols;

        return $cols;
    }

    try {
        $st = akh_db()->query('SHOW COLUMNS FROM task_notification_events');
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $name = strtolower((string) ($row['Field'] ?? ''));
            if ($name !== '') {
                $cols[$name] = true;
            }
        }
    } catch (Throwable) {
        $cols = [];
    }

    $GLOBALS['akh_task_notification_columns'] = $cols;

    return $cols;
}

function akh_task_notification_has_column(string $column): bool
{
    return (akh_task_notification_columns()[strtolower($column)] ?? false) === true;
}

/** @return list<string> */
function akh_task_notification_client_kinds(): array
{
    return ['client_feedback', 'client_update', 'client_message', 'client_status_request'];
}

function akh_task_notification_table_exists(): bool
{
    if (!function_exists('akh_db')) {
        return false;
    }

    try {
        $st = akh_db()->query("SHOW TABLES LIKE 'task_notification_events'");

        return $st !== false && $st->fetch(PDO::FETCH_NUM) !== false;
    } catch (Throwable) {
        return false;
    }
}

function akh_task_notification_body_column(): string
{
    if (akh_task_notification_has_column('body')) {
        return 'body';
    }
    if (akh_task_notification_has_column('message')) {
        return 'message';
    }
    if (akh_task_notification_has_column('comment')) {
        return 'comment';
    }
    if (akh_task_notification_has_column('notification')) {
        return 'notification';
    }

    return 'body';
}

function akh_task_notification_body_select(): string
{
    $parts = [];
    foreach (['body', 'message', 'comment', 'notification'] as $col) {
        if (akh_task_notification_has_column($col)) {
            $parts[] = "NULLIF(TRIM({$col}), '')";
        }
    }
    if ($parts === []) {
        return "'Client update request' AS body";
    }

    return 'COALESCE(' . implode(', ', $parts) . ", 'Client update request') AS body";
}

/** SQL fragment selecting a unified task id (bot may use task_code). */
function akh_task_notification_task_ref_select(): string
{
    $hasTaskId = akh_task_notification_has_column('task_id');
    $hasTaskCode = akh_task_notification_has_column('task_code');
    if ($hasTaskId && $hasTaskCode) {
        return 'COALESCE(NULLIF(TRIM(task_id), ""), NULLIF(TRIM(task_code), "")) AS task_id';
    }
    if ($hasTaskId) {
        return 'task_id';
    }
    if ($hasTaskCode) {
        return 'task_code AS task_id';
    }

    return 'task_id';
}

function akh_task_notification_task_ref_column(): string
{
    if (akh_task_notification_has_column('task_id')) {
        return 'task_id';
    }
    if (akh_task_notification_has_column('task_code')) {
        return 'task_code';
    }

    return 'task_id';
}

/**
 * @return list<string>
 */
function akh_task_notification_read_statuses(): array
{
    return ['read', 'acknowledged', 'dismissed', 'done', 'closed', 'handled', 'resolved'];
}

/**
 * @return array{sql: string, params: list<mixed>}
 */
function akh_task_notification_pending_filter(): array
{
    if (akh_task_notification_has_column('status')) {
        $read = akh_task_notification_read_statuses();
        $placeholders = implode(',', array_fill(0, count($read), '?'));
        $parts = ["(status IS NULL OR TRIM(status) = '' OR LOWER(TRIM(status)) NOT IN ({$placeholders}))"];
        if (akh_task_notification_has_column('is_read')) {
            $parts[] = '(is_read = 0 OR is_read IS NULL)';
        }

        return ['sql' => '(' . implode(' AND ', $parts) . ')', 'params' => $read];
    }
    if (akh_task_notification_has_column('is_read')) {
        return ['sql' => '(is_read = 0 OR is_read IS NULL)', 'params' => []];
    }
    if (akh_task_notification_has_column('read_at')) {
        return ['sql' => 'read_at IS NULL', 'params' => []];
    }
    if (akh_task_notification_has_column('event_kind')) {
        $kinds = akh_task_notification_client_kinds();
        $placeholders = implode(',', array_fill(0, count($kinds), '?'));

        return ['sql' => "event_kind IN ({$placeholders})", 'params' => $kinds];
    }

    return ['sql' => '1=1', 'params' => []];
}

/**
 * @return array{sql: string, params: list<string>}
 */
function akh_task_notification_task_match_clause(string $taskId): array
{
    require_once __DIR__ . '/tasks.php';

    $variants = akh_task_id_match_variants($taskId);
    if ($variants === []) {
        return ['sql' => '0', 'params' => []];
    }

    $clauses = [];
    $params = [];
    $placeholder = implode(',', array_fill(0, count($variants), '?'));

    foreach (['task_id', 'task_code'] as $col) {
        if (!akh_task_notification_has_column($col)) {
            continue;
        }
        $clauses[] = "TRIM({$col}) IN ({$placeholder})";
        foreach ($variants as $v) {
            $params[] = $v;
        }
    }

    if ($clauses === []) {
        return ['sql' => '0', 'params' => []];
    }

    return ['sql' => '(' . implode(' OR ', $clauses) . ')', 'params' => $params];
}

function akh_task_notification_mark_columns_sql(): string
{
    $sets = [];
    if (akh_task_notification_has_column('status')) {
        $sets[] = "status = 'read'";
    }
    if (akh_task_notification_has_column('is_read')) {
        $sets[] = 'is_read = 1';
    }
    if (akh_task_notification_has_column('read_at')) {
        $sets[] = 'read_at = CURRENT_TIMESTAMP';
    }

    return implode(', ', $sets);
}

function akh_task_notification_insert(string $eventKind, string $taskId, string $body): void
{
    require_once __DIR__ . '/tasks.php';

    $taskId = akh_task_normalize_id(trim($taskId));
    $body = trim($body);
    if ($taskId === '' || $body === '') {
        return;
    }

    if (!akh_task_notification_table_exists()) {
        $dir = AKH_ROOT . '/data/task-notifications';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', $taskId);
        $kind = preg_replace('/[^a-zA-Z0-9_-]/', '_', $eventKind);
        $file = $dir . '/' . gmdate('Y-m-d_His') . '_' . $safe . '_' . strtoupper($kind) . '.txt';
        @file_put_contents($file, $body, LOCK_EX);

        return;
    }

    $cols = ['task_id'];
    $vals = [$taskId];
    if (akh_task_notification_has_column('event_kind')) {
        $cols[] = 'event_kind';
        $vals[] = $eventKind;
    }
    $bodyCol = akh_task_notification_body_column();
    if (akh_task_notification_has_column($bodyCol)) {
        $cols[] = $bodyCol;
        $vals[] = $body;
    }
    if (akh_task_notification_has_column('status')) {
        $cols[] = 'status';
        $vals[] = 'pending';
    }

    if (count($cols) < 2) {
        return;
    }

    try {
        $placeholders = implode(',', array_fill(0, count($cols), '?'));
        $sql = 'INSERT INTO task_notification_events (' . implode(', ', $cols) . ') VALUES (' . $placeholders . ')';
        akh_db()->prepare($sql)->execute($vals);
    } catch (Throwable) {
        // best-effort
    }
}

function akh_task_notification_latest_id(): int
{
    if (!akh_task_notification_table_exists()) {
        return 0;
    }

    try {
        $n = akh_db()->query('SELECT COALESCE(MAX(id), 0) FROM task_notification_events')->fetchColumn();

        return (int) $n;
    } catch (Throwable) {
        return 0;
    }
}

function akh_task_notification_poll_signature(): string
{
    if (!akh_task_notification_table_exists()) {
        return 'missing';
    }

    try {
        $pending = akh_task_notification_pending_filter();
        $row = akh_db()->prepare(
            'SELECT COUNT(*) AS c, COALESCE(MAX(id), 0) AS m FROM task_notification_events WHERE ' . $pending['sql']
        );
        $row->execute($pending['params']);
        $data = $row->fetch(PDO::FETCH_ASSOC);
        if (!is_array($data)) {
            return 'empty';
        }

        return hash('sha256', (string) ($data['c'] ?? '0') . '|' . (string) ($data['m'] ?? '0'));
    } catch (Throwable $e) {
        error_log('akh_task_notification_poll_signature: ' . $e->getMessage());

        return 'error';
    }
}

/**
 * @return list<array<string, mixed>>
 */
function akh_task_notification_pending_rows(?string $taskId = null): array
{
    if (!akh_task_notification_table_exists()) {
        return [];
    }

    $bodySelect = akh_task_notification_body_select();
    $taskRef = akh_task_notification_task_ref_select();
    $select = "id, {$taskRef}, {$bodySelect}, created_at";
    if (akh_task_notification_has_column('event_kind')) {
        $select .= ', event_kind';
    }
    if (akh_task_notification_has_column('status')) {
        $select .= ', status';
    }

    $pending = akh_task_notification_pending_filter();
    $where = [$pending['sql']];
    $params = $pending['params'];

    if ($taskId !== null && trim($taskId) !== '') {
        require_once __DIR__ . '/tasks.php';
        $match = akh_task_notification_task_match_clause($taskId);
        if ($match['sql'] !== '0') {
            $where[] = $match['sql'];
            foreach ($match['params'] as $p) {
                $params[] = $p;
            }
        }
    }

    $sql = "SELECT {$select} FROM task_notification_events WHERE " . implode(' AND ', $where) . ' ORDER BY id ASC';

    try {
        $st = akh_db()->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    } catch (Throwable $e) {
        error_log('akh_task_notification_pending_rows: ' . $e->getMessage() . ' SQL: ' . $sql);

        return [];
    }
}

function akh_task_notification_mark_task_read(string $taskId): void
{
    if (!akh_task_notification_table_exists()) {
        return;
    }

    require_once __DIR__ . '/tasks.php';
    $taskId = trim($taskId);
    if ($taskId === '') {
        return;
    }

    $pending = akh_task_notification_pending_filter();
    $match = akh_task_notification_task_match_clause($taskId);
    if ($match['sql'] === '0') {
        return;
    }

    $setSql = akh_task_notification_mark_columns_sql();
    if ($setSql === '') {
        error_log('akh_task_notification_mark_task_read: no read columns on task_notification_events');

        return;
    }

    try {
        $sql = "UPDATE task_notification_events SET {$setSql} WHERE {$match['sql']} AND ({$pending['sql']})";
        $st = akh_db()->prepare($sql);
        $st->execute(array_merge($match['params'], $pending['params']));
    } catch (Throwable $e) {
        error_log('akh_task_notification_mark_task_read: ' . $e->getMessage());
    }
}

function akh_task_notification_mark_all_read(): void
{
    if (!akh_task_notification_table_exists()) {
        return;
    }

    $pending = akh_task_notification_pending_filter();
    $setSql = akh_task_notification_mark_columns_sql();
    if ($setSql === '') {
        error_log('akh_task_notification_mark_all_read: no read columns on task_notification_events');

        return;
    }

    try {
        $sql = "UPDATE task_notification_events SET {$setSql} WHERE {$pending['sql']}";
        $st = akh_db()->prepare($sql);
        $st->execute($pending['params']);
    } catch (Throwable $e) {
        error_log('akh_task_notification_mark_all_read: ' . $e->getMessage());
    }
}

/**
 * @return array<string, array{count: int, max_id: int, preview: string, kind: string, created_at: string}>
 */
function akh_task_notification_pending_alerts_grouped(): array
{
    $out = [];
    foreach (akh_task_notification_pending_rows() as $row) {
        $tid = trim((string) ($row['task_id'] ?? ''));
        if ($tid === '') {
            continue;
        }
        if (function_exists('akh_task_normalize_id')) {
            require_once __DIR__ . '/tasks.php';
            $tid = akh_task_normalize_id($tid);
        }
        $id = (int) ($row['id'] ?? 0);
        $body = trim((string) ($row['body'] ?? ''));
        $preview = $body !== '' ? $body : 'Client update';
        if (mb_strlen($preview) > 120) {
            $preview = mb_substr($preview, 0, 119) . '…';
        }
        $kind = (string) ($row['event_kind'] ?? 'client_update');
        $created = (string) ($row['created_at'] ?? '');
        if (!isset($out[$tid])) {
            $out[$tid] = [
                'count' => 0,
                'max_id' => $id,
                'preview' => $preview,
                'kind' => $kind,
                'created_at' => $created,
            ];
        }
        $out[$tid]['count']++;
        if ($id >= $out[$tid]['max_id']) {
            $out[$tid]['max_id'] = $id;
            $out[$tid]['preview'] = $preview;
            $out[$tid]['kind'] = $kind;
            $out[$tid]['created_at'] = $created;
        }
    }

    return $out;
}

/**
 * Pending client notifications on tasks assigned to this editor.
 *
 * @return array<string, array{count: int, max_id: int, preview: string, kind: string, created_at: string}>
 */
function akh_task_notification_pending_alerts_for_editor(string $editorUsername): array
{
    require_once __DIR__ . '/tasks.php';

    $editorUsername = strtolower(trim($editorUsername));
    if ($editorUsername === '') {
        return [];
    }

    $assigned = [];
    foreach (akh_tasks_load() as $t) {
        if (strtolower(trim((string) ($t['assigned_editor'] ?? ''))) !== $editorUsername) {
            continue;
        }
        $id = akh_task_normalize_id((string) ($t['id'] ?? ''));
        if ($id !== '') {
            $assigned[$id] = true;
        }
    }

    if (function_exists('akh_db')) {
        try {
            $st = akh_db()->query("SHOW TABLES LIKE 'whatsapp_tasks'");
            if ($st !== false && $st->fetch(PDO::FETCH_NUM) !== false) {
                $waSt = akh_db()->prepare(
                    "SELECT wt.task_code, u.username
                     FROM whatsapp_tasks wt
                     INNER JOIN users u ON u.id = wt.assigned_editor AND u.role = 'editor'
                     WHERE wt.assigned_editor IS NOT NULL"
                );
                $waSt->execute();
                while ($row = $waSt->fetch(PDO::FETCH_ASSOC)) {
                    if (!is_array($row)) {
                        continue;
                    }
                    if (strtolower(trim((string) ($row['username'] ?? ''))) !== $editorUsername) {
                        continue;
                    }
                    $code = akh_task_normalize_id((string) ($row['task_code'] ?? ''));
                    if ($code !== '') {
                        $assigned[$code] = true;
                    }
                }
            }
        } catch (Throwable) {
            // best-effort
        }
    }

    $out = [];
    foreach (akh_task_notification_pending_alerts_grouped() as $taskId => $alert) {
        if (isset($assigned[$taskId])) {
            $out[$taskId] = $alert;
        }
    }

    return $out;
}

function akh_task_notification_pending_count_for_editor(string $editorUsername): int
{
    return count(akh_task_notification_pending_alerts_for_editor($editorUsername));
}

function akh_task_notification_kind_label(string $kind): string
{
    $map = [
        'client_feedback' => 'Client feedback',
        'client_update' => 'Client updated task',
        'client_message' => 'Client message',
        'client_status_request' => 'Client update request',
        'studio_new' => 'New task',
    ];

    return $map[$kind] ?? 'Client update';
}

/**
 * @param array<string, array<string, mixed>> $alerts
 * @return ?array<string, mixed>
 */
function akh_task_notification_alert_for_code(array $alerts, string $taskCode): ?array
{
    require_once __DIR__ . '/tasks.php';

    $code = akh_task_normalize_id($taskCode);
    if ($code === '') {
        return null;
    }
    if (isset($alerts[$code])) {
        return $alerts[$code];
    }
    foreach ($alerts as $key => $alert) {
        if (akh_task_ids_match((string) $key, $code)) {
            return $alert;
        }
    }

    return null;
}

/**
 * Studio-board row for a pending client alert (studio task or WhatsApp-only assignment).
 *
 * @return ?array<string, mixed>
 */
function akh_task_notification_editor_board_row(string $taskId, string $editorUsername): ?array
{
    require_once __DIR__ . '/tasks.php';

    $taskId = akh_task_normalize_id($taskId);
    $editorUsername = strtolower(trim($editorUsername));
    if ($taskId === '' || $editorUsername === '') {
        return null;
    }

    $studio = akh_task_by_id($taskId);
    if (is_array($studio)) {
        if (strtolower(trim((string) ($studio['assigned_editor'] ?? ''))) === $editorUsername) {
            return $studio;
        }

        return null;
    }

    if (!function_exists('akh_wa_task_by_code')) {
        require_once __DIR__ . '/whatsapp-tasks.php';
    }
    $wa = akh_wa_task_by_code($taskId);
    if (!is_array($wa)) {
        return null;
    }

    $editorId = isset($wa['assigned_editor']) ? (int) $wa['assigned_editor'] : 0;
    $waEditor = $editorId > 0 ? akh_wa_editor_username_by_id($editorId) : null;
    if (strtolower(trim((string) $waEditor)) !== $editorUsername) {
        return null;
    }

    $title = trim((string) ($wa['project_name'] ?? ''));
    if ($title === '') {
        $title = trim((string) ($wa['customer_name'] ?? ''));
    }
    if ($title === '') {
        $title = $taskId;
    }

    return [
        'id' => $taskId,
        'title' => $title,
        'status' => akh_wa_map_status_to_studio((string) ($wa['status'] ?? 'assigned')),
        'assigned_editor' => $editorUsername,
        'client_username' => trim((string) ($wa['customer_name'] ?? '')),
        'description' => trim((string) ($wa['instructions'] ?? '')),
        'reference_link' => trim((string) ($wa['reference_link'] ?? '')),
        'delivery_mode' => '',
        'drive_link' => trim((string) ($wa['drive_link'] ?? '')),
        'updated_at' => (string) ($wa['updated_at'] ?? ''),
        'editor_feedback_notify' => false,
        'client_feedback' => '',
        'client_meeting_date' => '',
        'client_meeting_link' => '',
        'deliverable_output' => '',
    ];
}
