<?php

declare(strict_types=1);

function akh_meeting_requests_table_exists(): bool
{
    if (!function_exists('akh_db')) {
        return false;
    }

    try {
        $st = akh_db()->query("SHOW TABLES LIKE 'meeting_requests'");

        return $st !== false && $st->fetch(PDO::FETCH_NUM) !== false;
    } catch (Throwable) {
        return false;
    }
}

function akh_meeting_request_site_timezone(): DateTimeZone
{
    $name = defined('AKH_SITE_TIMEZONE') ? (string) AKH_SITE_TIMEZONE : 'Asia/Kolkata';

    try {
        return new DateTimeZone($name);
    } catch (Throwable) {
        return new DateTimeZone('Asia/Kolkata');
    }
}

/** @return list<string> */
function akh_meeting_request_pending_statuses(): array
{
    return ['pending', 'new', 'requested', 'open'];
}

/** @return list<string> */
function akh_meeting_request_reminder_blocked_statuses(): array
{
    return ['cancelled', 'canceled', 'declined', 'completed', 'done', 'closed'];
}

/**
 * @return array{sql: string, params: list<mixed>}
 */
function akh_meeting_request_pending_filter(): array
{
    $statuses = akh_meeting_request_pending_statuses();
    $placeholders = implode(',', array_fill(0, count($statuses), '?'));

    return [
        'sql' => "(status IS NULL OR TRIM(status) = '' OR LOWER(TRIM(status)) IN ({$placeholders}))",
        'params' => $statuses,
    ];
}

function akh_meeting_request_parse_datetime(string $raw): ?DateTimeImmutable
{
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }

    $tz = akh_meeting_request_site_timezone();
    $formats = ['Y-m-d H:i:s', 'Y-m-d H:i', DateTimeInterface::ATOM, 'Y-m-d\TH:i:sP'];
    foreach ($formats as $fmt) {
        $dt = DateTimeImmutable::createFromFormat($fmt, $raw, $tz);
        if ($dt instanceof DateTimeImmutable) {
            return $dt;
        }
    }

    try {
        return new DateTimeImmutable($raw, $tz);
    } catch (Throwable) {
        return null;
    }
}

/**
 * @param array<string, mixed> $row
 */
function akh_meeting_request_preview_from_row(array $row): string
{
    $slot = trim((string) ($row['requested_time_text'] ?? ''));
    if ($slot === '') {
        $slot = trim((string) ($row['slot_selected'] ?? ''));
    }
    $customer = trim((string) ($row['customer_name'] ?? ''));
    $project = trim((string) ($row['project_name'] ?? ''));
    $parts = ['Client requested a meeting'];
    if ($customer !== '') {
        $parts[] = $customer;
    }
    if ($project !== '') {
        $parts[] = $project;
    }
    if ($slot !== '') {
        $parts[] = $slot;
    }
    $preview = implode(' — ', $parts);
    if (mb_strlen($preview) > 120) {
        $preview = mb_substr($preview, 0, 119) . '…';
    }

    return $preview;
}

/**
 * @return list<array<string, mixed>>
 */
function akh_meeting_request_pending_rows(): array
{
    if (!akh_meeting_requests_table_exists()) {
        return [];
    }

    $pending = akh_meeting_request_pending_filter();
    $sql = 'SELECT id, task_code, phone, slot_selected, meet_link, start_time, end_time, created_at,
            requested_time_text, customer_name, project_name, status, calendar_event_id, updated_at
            FROM meeting_requests WHERE ' . $pending['sql'] . ' ORDER BY id ASC';

    try {
        $st = akh_db()->prepare($sql);
        $st->execute($pending['params']);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    } catch (Throwable $e) {
        error_log('akh_meeting_request_pending_rows: ' . $e->getMessage());

        return [];
    }
}

/**
 * @return array<string, array<string, mixed>>
 */
function akh_meeting_request_pending_alerts_grouped(): array
{
    require_once __DIR__ . '/tasks.php';

    $out = [];
    foreach (akh_meeting_request_pending_rows() as $row) {
        $code = akh_task_normalize_id((string) ($row['task_code'] ?? ''));
        if ($code === '') {
            continue;
        }
        $id = (int) ($row['id'] ?? 0);
        $preview = akh_meeting_request_preview_from_row($row);
        $created = (string) ($row['created_at'] ?? '');
        if (!isset($out[$code])) {
            $out[$code] = [
                'count' => 0,
                'max_id' => $id,
                'preview' => $preview,
                'kind' => 'meeting_request',
                'priority' => 90,
                'created_at' => $created,
                'meet_link' => trim((string) ($row['meet_link'] ?? '')),
                'start_time' => (string) ($row['start_time'] ?? ''),
                'meeting_id' => $id,
            ];
        }
        $out[$code]['count']++;
        if ($id >= (int) ($out[$code]['max_id'] ?? 0)) {
            $out[$code]['max_id'] = $id;
            $out[$code]['preview'] = $preview;
            $out[$code]['created_at'] = $created;
            $out[$code]['meet_link'] = trim((string) ($row['meet_link'] ?? ''));
            $out[$code]['start_time'] = (string) ($row['start_time'] ?? '');
            $out[$code]['meeting_id'] = $id;
        }
    }

    return $out;
}

/**
 * Meetings starting within 10 minutes (reminder tiers at 10 and 5 minutes).
 *
 * @return list<array{id: int, task_code: string, tier: string, minutes_until: int, title: string, body: string, meet_link: string, start_time: string}>
 */
function akh_meeting_request_upcoming_reminders(): array
{
    if (!akh_meeting_requests_table_exists()) {
        return [];
    }

    require_once __DIR__ . '/tasks.php';

    $inactive = akh_meeting_request_reminder_blocked_statuses();
    $inactivePh = implode(',', array_fill(0, count($inactive), '?'));
    $sql = "SELECT id, task_code, meet_link, start_time, end_time, customer_name, project_name, status
            FROM meeting_requests
            WHERE start_time IS NOT NULL AND TRIM(start_time) <> ''
              AND LOWER(TRIM(status)) NOT IN ({$inactivePh})
            ORDER BY start_time ASC";

    try {
        $st = akh_db()->prepare($sql);
        $st->execute($inactive);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('akh_meeting_request_upcoming_reminders: ' . $e->getMessage());

        return [];
    }

    if (!is_array($rows)) {
        return [];
    }

    $tz = akh_meeting_request_site_timezone();
    $now = new DateTimeImmutable('now', $tz);
    $out = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $start = akh_meeting_request_parse_datetime((string) ($row['start_time'] ?? ''));
        if ($start === null) {
            continue;
        }
        if ($start <= $now) {
            continue;
        }
        $seconds = $start->getTimestamp() - $now->getTimestamp();
        $minutes = (int) ceil($seconds / 60);
        if ($minutes > 10 || $minutes < 1) {
            continue;
        }

        $tier = $minutes <= 5 ? '5' : '10';
        $code = akh_task_normalize_id((string) ($row['task_code'] ?? ''));
        if ($code === '') {
            continue;
        }
        $customer = trim((string) ($row['customer_name'] ?? ''));
        $project = trim((string) ($row['project_name'] ?? ''));
        $title = $project !== '' ? $project : ($customer !== '' ? $customer : $code);
        $body = $tier === '5'
            ? "Meeting for {$code} starts in about {$minutes} minute(s)."
            : "Meeting for {$code} starts in about {$minutes} minute(s).";
        $meetLink = trim((string) ($row['meet_link'] ?? ''));

        $out[] = [
            'id' => (int) ($row['id'] ?? 0),
            'task_code' => $code,
            'tier' => $tier,
            'minutes_until' => $minutes,
            'title' => $title,
            'body' => $body,
            'meet_link' => $meetLink,
            'start_time' => $start->format('Y-m-d H:i'),
        ];
    }

    return $out;
}

/**
 * @return array<string, array<string, mixed>>
 */
function akh_meeting_request_reminder_alert_highlights(): array
{
    $out = [];
    foreach (akh_meeting_request_upcoming_reminders() as $rem) {
        $code = (string) ($rem['task_code'] ?? '');
        if ($code === '') {
            continue;
        }
        $tier = (string) ($rem['tier'] ?? '10');
        $priority = $tier === '5' ? 100 : 95;
        $existing = $out[$code] ?? null;
        if (is_array($existing) && (int) ($existing['priority'] ?? 0) > $priority) {
            continue;
        }
        $out[$code] = [
            'count' => 1,
            'max_id' => (int) ($rem['id'] ?? 0),
            'preview' => (string) ($rem['body'] ?? 'Meeting starting soon'),
            'kind' => 'meeting_reminder',
            'priority' => $priority,
            'created_at' => (string) ($rem['start_time'] ?? ''),
            'meet_link' => (string) ($rem['meet_link'] ?? ''),
            'start_time' => (string) ($rem['start_time'] ?? ''),
            'meeting_id' => (int) ($rem['id'] ?? 0),
            'reminder_tier' => $tier,
            'minutes_until' => (int) ($rem['minutes_until'] ?? 0),
        ];
    }

    return $out;
}

/**
 * @return array<string, array<string, mixed>>
 */
function akh_meeting_request_pending_alerts_for_editor(string $editorUsername): array
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
    foreach (akh_meeting_request_pending_alerts_grouped() as $taskId => $alert) {
        if (isset($assigned[$taskId])) {
            $out[$taskId] = $alert;
        }
    }
    foreach (akh_meeting_request_reminder_alert_highlights() as $taskId => $alert) {
        if (!isset($assigned[$taskId])) {
            continue;
        }
        $existing = $out[$taskId] ?? null;
        if (!is_array($existing) || (int) ($alert['priority'] ?? 0) >= (int) ($existing['priority'] ?? 0)) {
            $out[$taskId] = $alert;
        }
    }

    return $out;
}

function akh_meeting_request_poll_signature(): string
{
    if (!akh_meeting_requests_table_exists()) {
        return 'missing';
    }

    try {
        $pending = akh_meeting_request_pending_filter();
        $st = akh_db()->prepare(
            'SELECT COUNT(*) AS c, COALESCE(MAX(id), 0) AS m FROM meeting_requests WHERE ' . $pending['sql']
        );
        $st->execute($pending['params']);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        $reminders = akh_meeting_request_upcoming_reminders();
        $remSig = [];
        foreach ($reminders as $r) {
            $remSig[] = (string) ($r['id'] ?? '') . ':' . (string) ($r['tier'] ?? '');
        }
        sort($remSig);

        return hash('sha256', (string) ($row['c'] ?? '0') . '|' . (string) ($row['m'] ?? '0') . '|' . implode(',', $remSig));
    } catch (Throwable $e) {
        error_log('akh_meeting_request_poll_signature: ' . $e->getMessage());

        return 'error';
    }
}

function akh_meeting_request_mark_task_read(string $taskCode): void
{
    if (!akh_meeting_requests_table_exists()) {
        return;
    }

    require_once __DIR__ . '/tasks.php';
    $taskCode = akh_task_normalize_id(trim($taskCode));
    if ($taskCode === '') {
        return;
    }

    $pending = akh_meeting_request_pending_filter();

    try {
        $sql = "UPDATE meeting_requests SET status = 'read', updated_at = CURRENT_TIMESTAMP
                WHERE task_code = ? AND ({$pending['sql']})";
        $st = akh_db()->prepare($sql);
        $st->execute(array_merge([$taskCode], $pending['params']));
    } catch (Throwable $e) {
        error_log('akh_meeting_request_mark_task_read: ' . $e->getMessage());
    }
}

function akh_meeting_request_mark_all_read(): void
{
    if (!akh_meeting_requests_table_exists()) {
        return;
    }

    $pending = akh_meeting_request_pending_filter();

    try {
        $sql = "UPDATE meeting_requests SET status = 'read', updated_at = CURRENT_TIMESTAMP WHERE {$pending['sql']}";
        $st = akh_db()->prepare($sql);
        $st->execute($pending['params']);
    } catch (Throwable $e) {
        error_log('akh_meeting_request_mark_all_read: ' . $e->getMessage());
    }
}

function akh_meeting_request_kind_label(string $kind): string
{
    $map = [
        'meeting_request' => 'Meeting request',
        'meeting_reminder' => 'Meeting soon',
    ];

    return $map[$kind] ?? 'Meeting';
}
