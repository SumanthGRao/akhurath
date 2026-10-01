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
    $out = akh_task_status_log_first_delivered_at_map();

    require_once __DIR__ . '/whatsapp-task-sync.php';
    if (!function_exists('akh_wa_task_updates_table_exists') || !akh_wa_task_updates_table_exists()) {
        return $out;
    }
    if (!function_exists('akh_db') || !akh_db_is_pdo()) {
        return $out;
    }

    try {
        $st = akh_db()->query(
            'SELECT task_id, status, created_at FROM task_updates ORDER BY task_id ASC, created_at ASC'
        );
        if ($st === false) {
            return $out;
        }
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $norm = akh_task_status_log_label_to_status((string) ($row['status'] ?? ''));
            if (!in_array($norm, ['delivered', 'closed'], true)) {
                continue;
            }
            $code = akh_task_normalize_id((string) ($row['task_id'] ?? ''));
            $raw = trim((string) ($row['created_at'] ?? ''));
            if ($code === '' || $raw === '') {
                continue;
            }
            $dt = akh_analytics_parse_log_timestamp($raw);
            if ($dt === null) {
                $dt = akh_parse_datetime_to_site($raw);
            }
            if ($dt === null) {
                continue;
            }
            if (!isset($out[$code]) || $dt < $out[$code]) {
                $out[$code] = $dt;
            }
        }

        return $out;
    } catch (Throwable) {
        return $out;
    }
}

/**
 * @param array<string, DateTimeImmutable> $deliveryLogMap
 */
function akh_admin_analytics_delivery_map_lookup(string $taskCode, array $deliveryLogMap): ?DateTimeImmutable
{
    $code = akh_task_normalize_id($taskCode);
    if ($code !== '' && isset($deliveryLogMap[$code])) {
        return $deliveryLogMap[$code];
    }
    foreach (akh_task_id_match_variants($taskCode) as $variant) {
        $norm = akh_task_normalize_id($variant);
        if ($norm !== '' && isset($deliveryLogMap[$norm])) {
            return $deliveryLogMap[$norm];
        }
    }

    return null;
}

/**
 * Per-task delivery instant when not in the pre-built map (just-delivered tasks).
 */
function akh_admin_analytics_lazy_delivered_at(string $taskCode): ?DateTimeImmutable
{
    $code = akh_task_normalize_id($taskCode);
    if ($code === '') {
        return null;
    }

    if (akh_task_status_log_table_exists()) {
        try {
            foreach (akh_task_id_match_variants($code) as $variant) {
                $st = akh_db()->prepare(
                    "SELECT created_at FROM task_status_changes
                     WHERE task_id = ? AND to_status IN ('delivered', 'closed')
                     ORDER BY created_at ASC LIMIT 1"
                );
                $st->execute([akh_task_normalize_id($variant)]);
                $raw = $st->fetchColumn();
                if (is_string($raw) && trim($raw) !== '') {
                    $dt = akh_analytics_parse_log_timestamp($raw);
                    if ($dt !== null) {
                        return $dt;
                    }
                }
            }
        } catch (Throwable) {
            // fall through
        }
    }

    require_once __DIR__ . '/whatsapp-task-sync.php';
    if (function_exists('akh_wa_task_updates_table_exists') && akh_wa_task_updates_table_exists() && akh_db_is_pdo()) {
        try {
            foreach (akh_task_id_match_variants($code) as $variant) {
                $st = akh_db()->prepare(
                    'SELECT status, created_at FROM task_updates WHERE task_id = ? ORDER BY created_at ASC'
                );
                $st->execute([akh_task_normalize_id($variant)]);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $norm = akh_task_status_log_label_to_status((string) ($row['status'] ?? ''));
                    if (!in_array($norm, ['delivered', 'closed'], true)) {
                        continue;
                    }
                    $raw = trim((string) ($row['created_at'] ?? ''));
                    if ($raw === '') {
                        continue;
                    }
                    $dt = akh_analytics_parse_log_timestamp($raw) ?? akh_parse_datetime_to_site($raw);

                    return $dt;
                }
            }
        } catch (Throwable) {
            return null;
        }
    }

    return null;
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
    if ($code === '') {
        return null;
    }

    $dt = akh_admin_analytics_delivery_map_lookup($code, $deliveryLogMap);
    if ($dt !== null) {
        return $dt;
    }

    return akh_admin_analytics_lazy_delivered_at($code);
}

/**
 * Stable bucket for by-client charts (WhatsApp tasks use customer_name, not client_username "whatsapp").
 *
 * @param array<string, mixed> $task
 */
function akh_admin_analytics_client_bucket(array $task): string
{
    $display = trim(akh_task_customer_display_name($task));
    if ($display !== '') {
        return 'name:' . mb_strtolower($display);
    }

    $user = strtolower(trim((string) ($task['client_username'] ?? '')));
    if ($user !== '') {
        return 'user:' . $user;
    }

    return '_unknown';
}

/**
 * @param array<string, mixed> $task
 * @param array<string, string> $clientAccounts
 */
function akh_admin_analytics_client_label_for_task(array $task, array $clientAccounts): string
{
    $display = trim(akh_task_customer_display_name($task));
    if ($display !== '') {
        return $display;
    }

    return akh_admin_analytics_client_label(
        strtolower(trim((string) ($task['client_username'] ?? ''))),
        $clientAccounts
    );
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
        $clientKey = akh_admin_analytics_client_bucket($t);
        $clientLabel = akh_admin_analytics_client_label_for_task($t, $clientAccounts);
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
                'username' => str_starts_with($clientKey, 'user:') ? substr($clientKey, 5) : '',
                'label' => $clientLabel,
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
