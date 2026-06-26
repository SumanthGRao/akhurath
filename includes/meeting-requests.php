<?php

declare(strict_types=1);

/** @var array<string, bool>|null */
$GLOBALS['akh_meeting_request_columns'] = null;

/** @return array<string, bool> */
function akh_meeting_request_columns(): array
{
    if (is_array($GLOBALS['akh_meeting_request_columns'] ?? null)) {
        return $GLOBALS['akh_meeting_request_columns'];
    }

    $cols = [];
    if (!akh_meeting_requests_table_exists()) {
        $GLOBALS['akh_meeting_request_columns'] = $cols;

        return $cols;
    }

    try {
        $st = akh_db()->query('SHOW COLUMNS FROM meeting_requests');
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $name = strtolower((string) ($row['Field'] ?? ''));
            if ($name !== '') {
                $cols[$name] = true;
            }
        }
    } catch (Throwable) {
        $cols = [];
    }

    $GLOBALS['akh_meeting_request_columns'] = $cols;

    return $cols;
}

function akh_meeting_request_has_column(string $column): bool
{
    return (akh_meeting_request_columns()[strtolower($column)] ?? false) === true;
}

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

/** Statuses that mean “handled” — everything else is an active alert. */
function akh_meeting_request_dismissed_statuses(): array
{
    return ['read', 'acknowledged', 'dismissed', 'cancelled', 'canceled', 'declined', 'completed', 'done', 'closed'];
}

/** @return array{sql: string, params: list<mixed>} */
function akh_meeting_request_active_filter(): array
{
    $dismissed = akh_meeting_request_dismissed_statuses();
    $placeholders = implode(',', array_fill(0, count($dismissed), '?'));

    return [
        'sql' => "(status IS NULL OR TRIM(status) = '' OR LOWER(TRIM(status)) NOT IN ({$placeholders}))",
        'params' => $dismissed,
    ];
}

/** @return array{sql: string, params: list<mixed>} */
function akh_meeting_request_reminder_blocked_filter(): array
{
    return akh_meeting_request_active_filter();
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function akh_meeting_request_normalize_row(array $row): array
{
    require_once __DIR__ . '/tasks.php';

    $code = trim((string) ($row['task_code'] ?? ''));
    if ($code === '' && akh_meeting_request_has_column('task_id')) {
        $code = trim((string) ($row['task_id'] ?? ''));
    }
    $row['task_code'] = akh_task_normalize_id($code);

    return $row;
}

function akh_meeting_request_parse_datetime(string $raw): ?DateTimeImmutable
{
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }

    $tz = akh_meeting_request_site_timezone();
    $formats = ['Y-m-d H:i:s', 'Y-m-d H:i', 'd-m-Y H:i:s', 'd-m-Y H:i', DateTimeInterface::ATOM, 'Y-m-d\TH:i:sP'];
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

/** @param array<string, mixed> $row */
function akh_meeting_request_effective_start(array $row): ?DateTimeImmutable
{
    $start = akh_meeting_request_parse_datetime((string) ($row['start_time'] ?? ''));
    if ($start !== null) {
        return $start;
    }

    $text = trim((string) ($row['requested_time_text'] ?? ''));
    if ($text !== '') {
        return akh_meeting_request_parse_datetime($text);
    }

    return akh_meeting_request_parse_datetime((string) ($row['slot_selected'] ?? ''));
}

/** @param array<string, mixed> $row */
function akh_meeting_request_preview_from_row(array $row): string
{
    $slot = trim((string) ($row['requested_time_text'] ?? ''));
    if ($slot === '') {
        $slot = trim((string) ($row['slot_selected'] ?? ''));
    }
    $start = akh_meeting_request_effective_start($row);
    if ($slot === '' && $start !== null) {
        $slot = $start->format('M j, g:i A');
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
    if (mb_strlen($preview) > 140) {
        $preview = mb_substr($preview, 0, 139) . '…';
    }

    return $preview;
}

/**
 * @return list<array<string, mixed>>
 */
function akh_meeting_request_active_rows(): array
{
    if (!akh_meeting_requests_table_exists()) {
        return [];
    }

    $active = akh_meeting_request_active_filter();
    $sql = 'SELECT * FROM meeting_requests WHERE ' . $active['sql'] . ' ORDER BY id DESC LIMIT 200';

    try {
        $st = akh_db()->prepare($sql);
        $st->execute($active['params']);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = akh_meeting_request_normalize_row($row);
            }
        }

        return $out;
    } catch (Throwable $e) {
        error_log('akh_meeting_request_active_rows: ' . $e->getMessage());

        return [];
    }
}

/** @return array<string, array<string, mixed>> */
function akh_meeting_request_pending_alerts_grouped(): array
{
    $out = [];
    foreach (akh_meeting_request_active_rows() as $row) {
        $code = (string) ($row['task_code'] ?? '');
        if ($code === '') {
            continue;
        }
        $id = (int) ($row['id'] ?? 0);
        $preview = akh_meeting_request_preview_from_row($row);
        $created = (string) ($row['created_at'] ?? '');
        $start = akh_meeting_request_effective_start($row);
        $startLabel = $start !== null ? $start->format('Y-m-d H:i') : (string) ($row['start_time'] ?? '');
        if (!isset($out[$code])) {
            $out[$code] = [
                'count' => 0,
                'max_id' => $id,
                'preview' => $preview,
                'kind' => 'meeting_request',
                'priority' => 90,
                'created_at' => $created,
                'meet_link' => trim((string) ($row['meet_link'] ?? '')),
                'start_time' => $startLabel,
                'meeting_id' => $id,
            ];
        }
        $out[$code]['count']++;
        if ($id >= (int) ($out[$code]['max_id'] ?? 0)) {
            $out[$code]['max_id'] = $id;
            $out[$code]['preview'] = $preview;
            $out[$code]['created_at'] = $created;
            $out[$code]['meet_link'] = trim((string) ($row['meet_link'] ?? ''));
            $out[$code]['start_time'] = $startLabel;
            $out[$code]['meeting_id'] = $id;
        }
    }

    return $out;
}

/**
 * @return list<array{id: int, task_code: string, tier: string, minutes_until: int, title: string, body: string, meet_link: string, start_time: string}>
 */
function akh_meeting_request_upcoming_reminders(): array
{
    if (!akh_meeting_requests_table_exists()) {
        return [];
    }

    $rows = akh_meeting_request_active_rows();
    $tz = akh_meeting_request_site_timezone();
    $now = new DateTimeImmutable('now', $tz);
    $out = [];

    foreach ($rows as $row) {
        $start = akh_meeting_request_effective_start($row);
        if ($start === null || $start <= $now) {
            continue;
        }
        $seconds = $start->getTimestamp() - $now->getTimestamp();
        $minutes = (int) ceil($seconds / 60);
        if ($minutes > 10 || $minutes < 1) {
            continue;
        }

        $tier = $minutes <= 5 ? '5' : '10';
        $code = (string) ($row['task_code'] ?? '');
        if ($code === '') {
            continue;
        }
        $customer = trim((string) ($row['customer_name'] ?? ''));
        $project = trim((string) ($row['project_name'] ?? ''));
        $title = $project !== '' ? $project : ($customer !== '' ? $customer : $code);
        $meetLink = trim((string) ($row['meet_link'] ?? ''));
        $body = $tier === '5'
            ? "Meeting {$code} starts in {$minutes} min — join now."
            : "Meeting {$code} starts in {$minutes} min.";

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

/** @return array<string, array<string, mixed>> */
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

/** @return array<string, bool> */
function akh_meeting_request_assigned_task_codes(): array
{
    require_once __DIR__ . '/tasks.php';

    $assigned = [];
    foreach (akh_tasks_load() as $t) {
        $editor = strtolower(trim((string) ($t['assigned_editor'] ?? '')));
        if ($editor === '') {
            continue;
        }
        $id = akh_task_normalize_id((string) ($t['id'] ?? ''));
        if ($id !== '') {
            $assigned[$id] = true;
        }
    }

    if (!function_exists('akh_db')) {
        return $assigned;
    }

    try {
        $st = akh_db()->query("SHOW TABLES LIKE 'whatsapp_tasks'");
        if ($st === false || $st->fetch(PDO::FETCH_NUM) === false) {
            return $assigned;
        }
        $waSt = akh_db()->query(
            'SELECT task_code FROM whatsapp_tasks WHERE assigned_editor IS NOT NULL AND TRIM(task_code) <> ""'
        );
        while ($row = $waSt->fetch(PDO::FETCH_ASSOC)) {
            if (!is_array($row)) {
                continue;
            }
            $code = akh_task_normalize_id((string) ($row['task_code'] ?? ''));
            if ($code !== '') {
                $assigned[$code] = true;
            }
        }
    } catch (Throwable) {
        // best-effort
    }

    return $assigned;
}

/** @return array<string, bool> */
function akh_meeting_request_assigned_task_codes_for_editor(string $editorUsername): array
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

    if (!function_exists('akh_db')) {
        return $assigned;
    }

    try {
        $st = akh_db()->query("SHOW TABLES LIKE 'whatsapp_tasks'");
        if ($st === false || $st->fetch(PDO::FETCH_NUM) === false) {
            return $assigned;
        }
        $waSt = akh_db()->prepare(
            "SELECT wt.task_code
             FROM whatsapp_tasks wt
             INNER JOIN users u ON u.id = wt.assigned_editor AND u.role = 'editor'
             WHERE wt.assigned_editor IS NOT NULL AND LOWER(u.username) = ?"
        );
        $waSt->execute([$editorUsername]);
        while ($row = $waSt->fetch(PDO::FETCH_ASSOC)) {
            if (!is_array($row)) {
                continue;
            }
            $code = akh_task_normalize_id((string) ($row['task_code'] ?? ''));
            if ($code !== '') {
                $assigned[$code] = true;
            }
        }
    } catch (Throwable) {
        // best-effort
    }

    return $assigned;
}

/** @return array<string, array<string, mixed>> */
function akh_meeting_request_pending_alerts_for_editor(string $editorUsername): array
{
    $owned = akh_meeting_request_assigned_task_codes_for_editor($editorUsername);
    $out = [];
    foreach (akh_meeting_request_pending_alerts_grouped() as $taskId => $alert) {
        if (isset($owned[$taskId])) {
            $out[$taskId] = $alert;
        }
    }
    foreach (akh_meeting_request_reminder_alert_highlights() as $taskId => $alert) {
        if (!isset($owned[$taskId])) {
            continue;
        }
        if (!isset($out[$taskId]) || (int) ($alert['priority'] ?? 0) >= (int) ($out[$taskId]['priority'] ?? 0)) {
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
        $active = akh_meeting_request_active_filter();
        $st = akh_db()->prepare(
            'SELECT COUNT(*) AS c, COALESCE(MAX(id), 0) AS m FROM meeting_requests WHERE ' . $active['sql']
        );
        $st->execute($active['params']);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        $reminders = akh_meeting_request_upcoming_reminders();
        $remSig = [];
        foreach ($reminders as $r) {
            $remSig[] = (string) ($r['id'] ?? '') . ':' . (string) ($r['tier'] ?? '') . ':' . (string) ($r['minutes_until'] ?? '');
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

    $active = akh_meeting_request_active_filter();
    $matchSql = 'task_code = ?';
    $params = [$taskCode];
    if (akh_meeting_request_has_column('task_id')) {
        $matchSql = '(task_code = ? OR task_id = ?)';
        $params = [$taskCode, $taskCode];
    }

    try {
        $setRead = "status = 'read'";
        if (akh_meeting_request_has_column('updated_at')) {
            $setRead .= ', updated_at = CURRENT_TIMESTAMP';
        }
        $sql = "UPDATE meeting_requests SET {$setRead}
                WHERE {$matchSql} AND ({$active['sql']})";
        $st = akh_db()->prepare($sql);
        $st->execute(array_merge($params, $active['params']));
    } catch (Throwable $e) {
        error_log('akh_meeting_request_mark_task_read: ' . $e->getMessage());
    }
}

function akh_meeting_request_mark_all_read(): void
{
    if (!akh_meeting_requests_table_exists()) {
        return;
    }

    $active = akh_meeting_request_active_filter();

    try {
        $setRead = "status = 'read'";
        if (akh_meeting_request_has_column('updated_at')) {
            $setRead .= ', updated_at = CURRENT_TIMESTAMP';
        }
        $sql = "UPDATE meeting_requests SET {$setRead} WHERE {$active['sql']}";
        $st = akh_db()->prepare($sql);
        $st->execute($active['params']);
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

function akh_meeting_requests_table_ready(): bool
{
    return akh_meeting_requests_table_exists() && akh_meeting_request_columns() !== [];
}

/**
 * Pending (unread) meeting request rows only.
 *
 * @return list<array<string, mixed>>
 */
function akh_meeting_request_pending_rows(): array
{
    if (!akh_meeting_requests_table_exists()) {
        return [];
    }

    $pending = akh_meeting_request_active_filter();
    $sql = 'SELECT * FROM meeting_requests WHERE ' . $pending['sql'] . ' ORDER BY id DESC LIMIT 100';

    try {
        $st = akh_db()->prepare($sql);
        $st->execute($pending['params']);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = akh_meeting_request_normalize_row($row);
            }
        }

        return $out;
    } catch (Throwable $e) {
        error_log('akh_meeting_request_pending_rows: ' . $e->getMessage());

        return [];
    }
}

/**
 * All non-cancelled meetings for the Meetings tab (includes read / scheduled).
 *
 * @return list<array<string, mixed>>
 */
function akh_meeting_request_list_for_dashboard(): array
{
    if (!akh_meeting_requests_table_exists()) {
        return [];
    }

    $blocked = akh_meeting_request_dismissed_statuses();
    $placeholders = implode(',', array_fill(0, count($blocked), '?'));
    $orderBy = akh_meeting_request_has_column('created_at')
        ? 'COALESCE(start_time, created_at) DESC, id DESC'
        : (akh_meeting_request_has_column('start_time') ? 'start_time DESC, id DESC' : 'id DESC');
    $sql = "SELECT * FROM meeting_requests
            WHERE (status IS NULL OR TRIM(status) = '' OR LOWER(TRIM(status)) NOT IN ({$placeholders}))
            ORDER BY {$orderBy}
            LIMIT 200";

    try {
        $st = akh_db()->prepare($sql);
        $st->execute($blocked);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return [];
        }
        $pendingCodes = [];
        foreach (akh_meeting_request_pending_alerts_grouped() as $code => $_a) {
            $pendingCodes[$code] = true;
        }
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $norm = akh_meeting_request_normalize_row($row);
            $code = (string) ($norm['task_code'] ?? '');
            $start = akh_meeting_request_effective_start($norm);
            $out[] = [
                'id' => (int) ($norm['id'] ?? 0),
                'task_code' => $code,
                'customer_name' => (string) ($norm['customer_name'] ?? ''),
                'project_name' => (string) ($norm['project_name'] ?? ''),
                'phone' => (string) ($norm['phone'] ?? ''),
                'slot_selected' => (string) ($norm['slot_selected'] ?? ''),
                'requested_time_text' => (string) ($norm['requested_time_text'] ?? ''),
                'start_time' => $start !== null ? $start->format('Y-m-d H:i') : (string) ($norm['start_time'] ?? ''),
                'end_time' => (string) ($norm['end_time'] ?? ''),
                'meet_link' => trim((string) ($norm['meet_link'] ?? '')),
                'status' => (string) ($norm['status'] ?? ''),
                'created_at' => (string) ($norm['created_at'] ?? ''),
                'preview' => akh_meeting_request_preview_from_row($norm),
                'is_unread' => isset($pendingCodes[$code]),
            ];
        }

        return $out;
    } catch (Throwable $e) {
        error_log('akh_meeting_request_list_for_dashboard: ' . $e->getMessage());

        return [];
    }
}
