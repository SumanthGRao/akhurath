<?php

declare(strict_types=1);

require_once __DIR__ . '/tasks.php';
require_once __DIR__ . '/site-datetime.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/task-status-log.php';

/**
 * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
 */
function akh_admin_analytics_month_range(int $year, int $month): array
{
    $tz = akh_site_timezone();
    $year = max(2000, min(2100, $year));
    $month = max(1, min(12, $month));
    $start = new DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month), $tz);
    $end = $start->modify('+1 month');

    return [$start, $end];
}

function akh_admin_analytics_is_delivered_status(string $status): bool
{
    $s = strtolower(trim($status));

    return in_array($s, ['delivered', 'closed'], true);
}

/**
 * @param array<string, mixed> $task
 */
function akh_admin_analytics_include_task(array $task): bool
{
    if (akh_task_is_bundle_parent($task)) {
        return false;
    }
    $id = akh_task_normalize_id((string) ($task['id'] ?? ''));
    if ($id === '') {
        return false;
    }

    return true;
}

/**
 * @return list<array<string, mixed>>
 */
function akh_admin_analytics_tasks(): array
{
    $out = [];
    foreach (akh_tasks_load() as $t) {
        if (!is_array($t) || !akh_admin_analytics_include_task($t)) {
            continue;
        }
        $out[] = $t;
    }

    return $out;
}

/**
 * @param array<string, mixed> $task
 */
function akh_admin_analytics_task_created_at(array $task): ?DateTimeImmutable
{
    return akh_parse_datetime_to_site((string) ($task['created_at'] ?? ''));
}

/**
 * @param array<string, mixed> $task
 */
function akh_admin_analytics_task_updated_at(array $task): ?DateTimeImmutable
{
    return akh_parse_datetime_to_site((string) ($task['updated_at'] ?? ''));
}

function akh_admin_analytics_in_month(?DateTimeImmutable $dt, DateTimeImmutable $start, DateTimeImmutable $end): bool
{
    if ($dt === null) {
        return false;
    }

    return $dt >= $start && $dt < $end;
}

/**
 * First logged transition to Delivered / Closed from task_updates (editor + WhatsApp workflow log).
 *
 * @return array<string, DateTimeImmutable> task code => site-local instant
 */
function akh_admin_analytics_first_delivery_logged_at_map(): array
{
    $fromStatusLog = akh_task_status_log_first_delivered_at_map();
    if ($fromStatusLog !== []) {
        return $fromStatusLog;
    }

    require_once __DIR__ . '/whatsapp-task-sync.php';
    if (!function_exists('akh_wa_task_updates_table_exists') || !akh_wa_task_updates_table_exists()) {
        return [];
    }
    if (!function_exists('akh_db') || !akh_db_is_pdo()) {
        return [];
    }

    try {
        $st = akh_db()->query(
            "SELECT task_id, MIN(created_at) AS delivered_at
             FROM task_updates
             WHERE LOWER(TRIM(status)) IN ('delivered', 'closed')
             GROUP BY task_id"
        );
        if ($st === false) {
            return [];
        }
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $code = akh_task_normalize_id((string) ($row['task_id'] ?? ''));
            $raw = trim((string) ($row['delivered_at'] ?? ''));
            if ($code === '' || $raw === '') {
                continue;
            }
            $dt = akh_parse_datetime_to_site($raw);
            if ($dt !== null) {
                $out[$code] = $dt;
            }
        }

        return $out;
    } catch (Throwable) {
        return [];
    }
}

/**
 * When a task counts as "delivered" for monthly analytics (not the same as last board touch).
 *
 * @param array<string, DateTimeImmutable> $deliveryLogMap
 */
function akh_admin_analytics_task_delivered_at(array $task, array $deliveryLogMap): ?DateTimeImmutable
{
    $status = strtolower(trim((string) ($task['status'] ?? '')));
    if (!akh_admin_analytics_is_delivered_status($status)) {
        return null;
    }
    $code = akh_task_normalize_id((string) ($task['id'] ?? ''));
    if ($code !== '' && isset($deliveryLogMap[$code])) {
        return $deliveryLogMap[$code];
    }

    return akh_admin_analytics_task_updated_at($task);
}

/**
 * @param array<string, string> $clientAccounts username => display
 */
function akh_admin_analytics_client_label(string $username, array $clientAccounts): string
{
    $key = strtolower(trim($username));
    if ($key === '') {
        return '—';
    }
    if ($key === 'whatsapp') {
        return 'WhatsApp';
    }
    if (isset($clientAccounts[$key]) && trim($clientAccounts[$key]) !== '') {
        return $clientAccounts[$key];
    }

    return $username;
}

/**
 * Monthly task analytics for the admin console.
 *
 * @return array{
 *   year: int,
 *   month: int,
 *   month_label: string,
 *   timezone: string,
 *   summary: array{incoming: int, delivered: int, cancelled: int, open_pipeline: int, delivery_rate: float|null},
 *   by_client: list<array{username: string, label: string, incoming: int, delivered: int}>,
 *   by_editor: list<array{username: string, incoming: int, delivered: int, handled: int}>,
 *   trend: array{labels: list<string>, incoming: list<int>, delivered: list<int>},
 *   pipeline: array<string, mixed>
 * }
 */
function akh_admin_task_analytics_report(int $year, int $month): array
{
    [$start, $end] = akh_admin_analytics_month_range($year, $month);
    $tz = akh_site_timezone();

    $pipeline = akh_task_status_log_pipeline_report($start, $end);
    $editorPerformance = akh_task_status_log_editor_performance_report($start, $end);

    $deliveryLogMap = akh_admin_analytics_first_delivery_logged_at_map();
    $allTasks = akh_admin_analytics_tasks();

    $clientAccounts = [];
    foreach (akh_customer_accounts() as $username => $row) {
        if (!is_string($username)) {
            continue;
        }
        $display = is_array($row) ? trim((string) ($row['display_name'] ?? $row['name'] ?? '')) : '';
        $clientAccounts[strtolower($username)] = $display !== '' ? $display : $username;
    }

    $summary = [
        'incoming' => 0,
        'delivered' => 0,
        'cancelled' => 0,
        'open_pipeline' => 0,
        'delivery_rate' => null,
    ];

    /** @var array<string, array{username: string, label: string, incoming: int, delivered: int}> */
    $byClient = [];
    /** @var array<string, array{username: string, incoming: int, delivered: int, handled: int}> */
    $byEditor = [];

    foreach ($allTasks as $t) {
        $created = akh_admin_analytics_task_created_at($t);
        $updated = akh_admin_analytics_task_updated_at($t);
        $deliveredAt = akh_admin_analytics_task_delivered_at($t, $deliveryLogMap);
        $status = strtolower(trim((string) ($t['status'] ?? 'new')));
        $clientKey = strtolower(trim((string) ($t['client_username'] ?? '')));
        if ($clientKey === '') {
            $clientKey = '_unknown';
        }
        $editorKey = strtolower(trim((string) ($t['assigned_editor'] ?? '')));

        $incomingThisMonth = akh_admin_analytics_in_month($created, $start, $end);
        $deliveredThisMonth = $deliveredAt !== null && akh_admin_analytics_in_month($deliveredAt, $start, $end);
        $cancelledThisMonth = $status === 'cancelled' && akh_admin_analytics_in_month($updated, $start, $end);

        if ($incomingThisMonth) {
            ++$summary['incoming'];
        }
        if ($deliveredThisMonth) {
            ++$summary['delivered'];
        }
        if ($cancelledThisMonth) {
            ++$summary['cancelled'];
        }
        if ($incomingThisMonth && !akh_admin_analytics_is_delivered_status($status) && $status !== 'cancelled') {
            ++$summary['open_pipeline'];
        }

        if (!isset($byClient[$clientKey])) {
            $byClient[$clientKey] = [
                'username' => $clientKey === '_unknown' ? '' : $clientKey,
                'label' => akh_admin_analytics_client_label($clientKey === '_unknown' ? '' : $clientKey, $clientAccounts),
                'incoming' => 0,
                'delivered' => 0,
            ];
        }
        if ($incomingThisMonth) {
            ++$byClient[$clientKey]['incoming'];
        }
        if ($deliveredThisMonth) {
            ++$byClient[$clientKey]['delivered'];
        }

        if ($editorKey !== '') {
            if (!isset($byEditor[$editorKey])) {
                $byEditor[$editorKey] = [
                    'username' => $editorKey,
                    'incoming' => 0,
                    'delivered' => 0,
                    'handled' => 0,
                ];
            }
            if ($incomingThisMonth) {
                ++$byEditor[$editorKey]['incoming'];
            }
            if ($deliveredThisMonth) {
                ++$byEditor[$editorKey]['delivered'];
            }
            if ($incomingThisMonth || ($updated !== null && akh_admin_analytics_in_month($updated, $start, $end))) {
                ++$byEditor[$editorKey]['handled'];
            }
        }
    }

    if ($summary['incoming'] > 0) {
        $summary['delivery_rate'] = round($summary['delivered'] / $summary['incoming'] * 100, 1);
    }

    $clientRows = array_values($byClient);
    usort($clientRows, static function (array $a, array $b): int {
        $cmp = ($b['incoming'] + $b['delivered']) <=> ($a['incoming'] + $a['delivered']);
        if ($cmp !== 0) {
            return $cmp;
        }

        return strcmp($a['label'], $b['label']);
    });

    $editorRows = array_values($byEditor);
    usort($editorRows, static function (array $a, array $b): int {
        $cmp = $b['handled'] <=> $a['handled'];
        if ($cmp !== 0) {
            return $cmp;
        }

        return strcmp($a['username'], $b['username']);
    });

    $trendLabels = [];
    $trendIncoming = [];
    $trendDelivered = [];
    $cursor = $start->modify('-11 months');
    for ($i = 0; $i < 12; ++$i) {
        $mStart = $cursor;
        $mEnd = $cursor->modify('+1 month');
        $trendLabels[] = $mStart->format('M Y');
        $inc = 0;
        $del = 0;
        foreach ($allTasks as $t) {
            $created = akh_admin_analytics_task_created_at($t);
            $deliveredAt = akh_admin_analytics_task_delivered_at($t, $deliveryLogMap);
            if (akh_admin_analytics_in_month($created, $mStart, $mEnd)) {
                ++$inc;
            }
            if ($deliveredAt !== null && akh_admin_analytics_in_month($deliveredAt, $mStart, $mEnd)) {
                ++$del;
            }
        }
        $trendIncoming[] = $inc;
        $trendDelivered[] = $del;
        $cursor = $mEnd;
    }

    return [
        'year' => $year,
        'month' => $month,
        'month_label' => $start->format('F Y'),
        'timezone' => $tz->getName(),
        'summary' => $summary,
        'by_client' => $clientRows,
        'by_editor' => $editorRows,
        'trend' => [
            'labels' => $trendLabels,
            'incoming' => $trendIncoming,
            'delivered' => $trendDelivered,
        ],
        'pipeline' => $pipeline,
        'editor_performance' => $editorPerformance,
    ];
}
