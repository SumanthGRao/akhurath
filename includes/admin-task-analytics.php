<?php

declare(strict_types=1);

require_once __DIR__ . '/tasks.php';
require_once __DIR__ . '/site-datetime.php';

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
 *   trend: array{labels: list<string>, incoming: list<int>, delivered: list<int>}
 * }
 */
function akh_admin_task_analytics_report(int $year, int $month): array
{
    [$start, $end] = akh_admin_analytics_month_range($year, $month);
    $tz = akh_site_timezone();

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

    foreach (akh_admin_analytics_tasks() as $t) {
        $created = akh_admin_analytics_task_created_at($t);
        $updated = akh_admin_analytics_task_updated_at($t);
        $status = strtolower(trim((string) ($t['status'] ?? 'new')));
        $clientKey = strtolower(trim((string) ($t['client_username'] ?? '')));
        if ($clientKey === '') {
            $clientKey = '_unknown';
        }
        $editorKey = strtolower(trim((string) ($t['assigned_editor'] ?? '')));

        $incomingThisMonth = akh_admin_analytics_in_month($created, $start, $end);
        $deliveredThisMonth = akh_admin_analytics_is_delivered_status($status)
            && akh_admin_analytics_in_month($updated, $start, $end);
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
        foreach (akh_admin_analytics_tasks() as $t) {
            $created = akh_admin_analytics_task_created_at($t);
            $updated = akh_admin_analytics_task_updated_at($t);
            $status = strtolower(trim((string) ($t['status'] ?? 'new')));
            if (akh_admin_analytics_in_month($created, $mStart, $mEnd)) {
                ++$inc;
            }
            if (akh_admin_analytics_is_delivered_status($status) && akh_admin_analytics_in_month($updated, $mStart, $mEnd)) {
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
    ];
}
