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

    return 'body';
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
        if (akh_task_notification_has_column('status')) {
            $row = akh_db()->query(
                "SELECT COUNT(*) AS c, COALESCE(MAX(id), 0) AS m FROM task_notification_events WHERE status = 'pending'"
            )->fetch(PDO::FETCH_ASSOC);
        } elseif (akh_task_notification_has_column('event_kind')) {
            $kinds = akh_task_notification_client_kinds();
            $placeholders = implode(',', array_fill(0, count($kinds), '?'));
            $st = akh_db()->prepare(
                "SELECT COUNT(*) AS c, COALESCE(MAX(id), 0) AS m FROM task_notification_events WHERE event_kind IN ({$placeholders})"
            );
            $st->execute($kinds);
            $row = $st->fetch(PDO::FETCH_ASSOC);
        } else {
            $row = akh_db()->query(
                'SELECT COUNT(*) AS c, COALESCE(MAX(id), 0) AS m FROM task_notification_events'
            )->fetch(PDO::FETCH_ASSOC);
        }
        if (!is_array($row)) {
            return 'empty';
        }

        return hash('sha256', (string) ($row['c'] ?? '0') . '|' . (string) ($row['m'] ?? '0'));
    } catch (Throwable) {
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

    $bodyCol = akh_task_notification_body_column();
    $select = "id, task_id, {$bodyCol} AS body, created_at";
    if (akh_task_notification_has_column('event_kind')) {
        $select .= ', event_kind';
    }
    if (akh_task_notification_has_column('status')) {
        $select .= ', status';
    }

    $where = [];
    $params = [];
    if (akh_task_notification_has_column('status')) {
        $where[] = "status = 'pending'";
    } elseif (akh_task_notification_has_column('event_kind')) {
        $kinds = akh_task_notification_client_kinds();
        $where[] = 'event_kind IN (' . implode(',', array_fill(0, count($kinds), '?')) . ')';
        $params = $kinds;
    }

    if ($taskId !== null && trim($taskId) !== '') {
        require_once __DIR__ . '/tasks.php';
        $where[] = 'task_id = ?';
        $params[] = akh_task_normalize_id($taskId);
    }

    $sql = "SELECT {$select} FROM task_notification_events";
    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY id ASC';

    try {
        $st = akh_db()->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    } catch (Throwable) {
        return [];
    }
}

function akh_task_notification_mark_task_read(string $taskId): void
{
    if (!akh_task_notification_table_exists() || !akh_task_notification_has_column('status')) {
        return;
    }

    require_once __DIR__ . '/tasks.php';
    $taskId = akh_task_normalize_id(trim($taskId));
    if ($taskId === '') {
        return;
    }

    try {
        akh_db()->prepare(
            "UPDATE task_notification_events SET status = 'read' WHERE task_id = ? AND status = 'pending'"
        )->execute([$taskId]);
    } catch (Throwable) {
        // best-effort
    }
}

function akh_task_notification_mark_all_read(): void
{
    if (!akh_task_notification_table_exists() || !akh_task_notification_has_column('status')) {
        return;
    }

    try {
        akh_db()->exec("UPDATE task_notification_events SET status = 'read' WHERE status = 'pending'");
    } catch (Throwable) {
        // best-effort
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
