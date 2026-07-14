<?php

declare(strict_types=1);

/**
 * n8n JSON bridge for chat / ops dashboards (TrueNAS and similar).
 *
 * database.local.php (gitignored) should define AKH_CHAT_API_URL and optionally
 * set $dashboard_data; bootstrap calls akh_dashboard_data_bootstrap() to normalize it.
 */

/**
 * @param mixed $raw
 * @return array<int|string, mixed>
 */
function akh_dashboard_data_normalize(mixed $raw): array
{
    if (!is_array($raw)) {
        return [];
    }

    return $raw;
}

/**
 * Fetch dashboard rows from the n8n webhook (see AKH_CHAT_API_URL).
 *
 * @return array<int|string, mixed>
 */
function akh_dashboard_data_fetch(): array
{
    if (!defined('AKH_CHAT_API_URL')) {
        return [];
    }

    $url = trim((string) AKH_CHAT_API_URL);
    if ($url === '') {
        return [];
    }

    if (!function_exists('curl_init')) {
        error_log('akh_dashboard_data_fetch: cURL extension is not available.');

        return [];
    }

    $ch = curl_init();
    if ($ch === false) {
        return [];
    }

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 3,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json'],
    ]);

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $curlError !== '') {
        error_log('akh_dashboard_data_fetch: cURL error — ' . $curlError);

        return [];
    }

    if ($httpCode !== 200) {
        error_log('akh_dashboard_data_fetch: HTTP ' . $httpCode);

        return [];
    }

    try {
        $decoded = json_decode((string) $response, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        error_log('akh_dashboard_data_fetch: invalid JSON — ' . $e->getMessage());

        return [];
    }

    return akh_dashboard_data_normalize($decoded);
}

/** Load global $dashboard_data once per request (empty array on failure). */
function akh_dashboard_data_bootstrap(): void
{
    global $dashboard_data;

    if (isset($dashboard_data)) {
        $dashboard_data = akh_dashboard_data_normalize($dashboard_data);

        return;
    }

    if (defined('AKH_CHAT_API_URL')) {
        $dashboard_data = akh_dashboard_data_fetch();

        return;
    }

    $dashboard_data = [];
}

/**
 * @return array<int|string, mixed>
 */
function akh_dashboard_data_all(): array
{
    global $dashboard_data;

    if (!isset($dashboard_data) || !is_array($dashboard_data)) {
        return [];
    }

    return $dashboard_data;
}

/**
 * Top-level section (attendance, logs, counters, rows, …) or [] when missing.
 *
 * @return list<array<string, mixed>>
 */
function akh_dashboard_data_section(string $key): array
{
    $all = akh_dashboard_data_all();
    if ($all === []) {
        return [];
    }

    if (array_is_list($all)) {
        return [];
    }

    $section = $all[$key] ?? null;
    if (!is_array($section)) {
        return [];
    }

    if (!array_is_list($section)) {
        return [$section];
    }

    $out = [];
    foreach ($section as $row) {
        if (is_array($row)) {
            $out[] = $row;
        }
    }

    return $out;
}

/**
 * Main table rows: either a list payload or dashboard_data['rows'].
 *
 * @return list<array<string, mixed>>
 */
function akh_dashboard_data_rows(): array
{
    $all = akh_dashboard_data_all();
    if ($all === []) {
        return [];
    }

    if (array_is_list($all)) {
        $out = [];
        foreach ($all as $row) {
            if (is_array($row)) {
                $out[] = $row;
            }
        }

        return $out;
    }

    $rows = akh_dashboard_data_section('rows');
    if ($rows !== []) {
        return $rows;
    }

    foreach (['chats', 'messages', 'tasks', 'data'] as $altKey) {
        $alt = akh_dashboard_data_section($altKey);
        if ($alt !== []) {
            return $alt;
        }
    }

    return [];
}

/**
 * @return array<string, int|float|string>
 */
function akh_dashboard_data_counters(): array
{
    $all = akh_dashboard_data_all();
    if ($all === [] || array_is_list($all)) {
        $rows = akh_dashboard_data_rows();

        return [
            'total_rows' => count($rows),
            'active_chats' => count($rows),
            'unread' => 0,
            'editors_online' => 0,
        ];
    }

    $counters = $all['counters'] ?? $all['counts'] ?? $all['stats'] ?? [];
    if (!is_array($counters)) {
        $counters = [];
    }

    $rows = akh_dashboard_data_rows();

    return [
        'total_rows' => (int) ($counters['total_rows'] ?? $counters['total'] ?? count($rows)),
        'active_chats' => (int) ($counters['active_chats'] ?? $counters['active'] ?? count($rows)),
        'unread' => (int) ($counters['unread'] ?? $counters['unread_count'] ?? 0),
        'editors_online' => (int) ($counters['editors_online'] ?? $counters['online_editors'] ?? 0),
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function akh_dashboard_data_attendance_rows(): array
{
    $rows = akh_dashboard_data_section('attendance');
    if ($rows !== []) {
        return $rows;
    }

    $events = akh_dashboard_data_section('attendance_events');
    if ($events !== []) {
        return $events;
    }

    return akh_dashboard_data_section('events');
}

/**
 * @return list<array<string, mixed>>
 */
function akh_dashboard_data_log_rows(): array
{
    foreach (['logs', 'activity', 'activity_log', 'events_log'] as $key) {
        $rows = akh_dashboard_data_section($key);
        if ($rows !== []) {
            return $rows;
        }
    }

    return [];
}

function akh_dashboard_data_is_available(): bool
{
    return akh_dashboard_data_rows() !== []
        || akh_dashboard_data_attendance_rows() !== []
        || akh_dashboard_data_log_rows() !== []
        || akh_dashboard_data_all() !== [];
}

function akh_dashboard_data_row_count(): int
{
    return count(akh_dashboard_data_rows());
}
