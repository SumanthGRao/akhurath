<?php

declare(strict_types=1);

/** @return list<string> */
function akh_task_notification_client_kinds(): array
{
    return ['client_feedback', 'client_update', 'client_message'];
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

function akh_task_notification_insert(string $eventKind, string $taskId, string $body): void
{
    $taskId = trim($taskId);
    $body = trim($body);
    if ($taskId === '' || $body === '') {
        return;
    }

    if (function_exists('akh_task_normalize_id')) {
        $taskId = akh_task_normalize_id($taskId);
    }

    if (akh_task_notification_table_exists()) {
        try {
            akh_db()->prepare(
                'INSERT INTO task_notification_events (event_kind, task_id, body) VALUES (?, ?, ?)'
            )->execute([$eventKind, $taskId, $body]);
        } catch (Throwable) {
            // best-effort
        }

        return;
    }

    $dir = AKH_ROOT . '/data/task-notifications';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', $taskId);
    $kind = preg_replace('/[^a-zA-Z0-9_-]/', '_', $eventKind);
    $file = $dir . '/' . gmdate('Y-m-d_His') . '_' . $safe . '_' . strtoupper($kind) . '.txt';
    @file_put_contents($file, $body, LOCK_EX);
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
        $row = akh_db()->query(
            'SELECT COUNT(*) AS c, COALESCE(MAX(id), 0) AS m FROM task_notification_events
             WHERE event_kind IN (\'client_feedback\', \'client_update\', \'client_message\')'
        )->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return 'empty';
        }

        return hash('sha256', (string) ($row['c'] ?? '0') . '|' . (string) ($row['m'] ?? '0'));
    } catch (Throwable) {
        return 'error';
    }
}

/**
 * Client-originated events after a watermark (for WhatsApp desk alerts).
 *
 * @return list<array{id: int, event_kind: string, task_id: string, body: string, created_at: string}>
 */
function akh_task_notification_client_events_since(int $afterId): array
{
    if (!akh_task_notification_table_exists()) {
        return [];
    }

    $kinds = akh_task_notification_client_kinds();
    $placeholders = implode(',', array_fill(0, count($kinds), '?'));
    $params = $kinds;
    $params[] = max(0, $afterId);

    try {
        $st = akh_db()->prepare(
            "SELECT id, event_kind, task_id, body, created_at
             FROM task_notification_events
             WHERE event_kind IN ({$placeholders}) AND id > ?
             ORDER BY id ASC"
        );
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    } catch (Throwable) {
        return [];
    }
}

/**
 * @return array<string, array{count: int, max_id: int, preview: string, kind: string, created_at: string}>
 */
function akh_task_notification_client_alerts_grouped(int $afterId): array
{
    $out = [];
    foreach (akh_task_notification_client_events_since($afterId) as $row) {
        $tid = trim((string) ($row['task_id'] ?? ''));
        if ($tid === '') {
            continue;
        }
        if (function_exists('akh_task_normalize_id')) {
            $tid = akh_task_normalize_id($tid);
        }
        $id = (int) ($row['id'] ?? 0);
        $body = trim((string) ($row['body'] ?? ''));
        $preview = $body;
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
        if ($id > $out[$tid]['max_id']) {
            $out[$tid]['max_id'] = $id;
            $out[$tid]['preview'] = $preview;
            $out[$tid]['kind'] = $kind;
            $out[$tid]['created_at'] = $created;
        }
    }

    return $out;
}

function akh_task_notification_kind_label(string $kind): string
{
    $map = [
        'client_feedback' => 'Client feedback',
        'client_update' => 'Client updated task',
        'client_message' => 'Client message',
        'studio_new' => 'New task',
    ];

    return $map[$kind] ?? 'Update';
}
