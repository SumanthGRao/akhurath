<?php

declare(strict_types=1);

/**
 * n8n JSON bridge for chat / ops dashboards (TrueNAS and similar).
 *
 * Expected grouped payload (see includes/n8n-dashboard-payload.example.json):
 *   tasks, whatsapp_tasks, attendance, editors, logs, meetings, alerts, counters
 *
 * database.local.php defines AKH_CHAT_API_URL and may pre-set $dashboard_data.
 */

/** Top-level keys the chat dashboard reads from n8n. */
const AKH_DASHBOARD_DATA_KEYS = [
    'tasks',
    'whatsapp_tasks',
    'task_pool',
    'attendance',
    'editors',
    'logs',
    'meetings',
    'alerts',
    'counters',
];

/**
 * @param mixed $raw
 * @return array<string, mixed>
 */
function akh_dashboard_data_normalize(mixed $raw): array
{
    if (!is_array($raw)) {
        return [];
    }

    // n8n "Respond to Webhook" often wraps as [{ ... }] or { data: { ... } }.
    if (array_is_list($raw) && count($raw) === 1 && is_array($raw[0]) && akh_dashboard_data_looks_grouped($raw[0])) {
        return $raw[0];
    }

    if (isset($raw['data']) && is_array($raw['data']) && akh_dashboard_data_looks_grouped($raw['data'])) {
        return $raw['data'];
    }

    if (isset($raw['body']) && is_array($raw['body']) && akh_dashboard_data_looks_grouped($raw['body'])) {
        return $raw['body'];
    }

    if (isset($raw['json']) && is_array($raw['json']) && akh_dashboard_data_looks_grouped($raw['json'])) {
        return $raw['json'];
    }

    // Flat list legacy: treat as tasks.
    if (array_is_list($raw)) {
        $tasks = [];
        foreach ($raw as $row) {
            if (is_array($row)) {
                $tasks[] = $row;
            }
        }

        return $tasks === [] ? [] : ['tasks' => $tasks];
    }

    return $raw;
}

/**
 * @param array<string, mixed> $payload
 */
function akh_dashboard_data_looks_grouped(array $payload): bool
{
    foreach (AKH_DASHBOARD_DATA_KEYS as $key) {
        if (array_key_exists($key, $payload)) {
            return true;
        }
    }

    return false;
}

/**
 * @return array<string, mixed>
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
        CURLOPT_TIMEOUT => 5,
        CURLOPT_CONNECTTIMEOUT => 3,
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

/** Load global $dashboard_data once per request (empty grouped array on failure). */
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
 * @return array<string, mixed>
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
 * @return list<array<string, mixed>>
 */
function akh_dashboard_data_list_section(string $key): array
{
    $all = akh_dashboard_data_all();
    if ($all === [] || !isset($all[$key]) || !is_array($all[$key])) {
        return [];
    }

    $section = $all[$key];

    // attendance may be { events: [...] } from app_kv.editor_attendance
    if ($key === 'attendance' && isset($section['events']) && is_array($section['events'])) {
        $section = $section['events'];
    }

    if (!array_is_list($section)) {
        return is_array($section) ? [$section] : [];
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
 * Studio board tasks — $dashboard_data['tasks'].
 *
 * @return list<array<string, mixed>>
 */
function akh_dashboard_data_tasks(): array
{
    return akh_dashboard_data_list_section('tasks');
}

/**
 * Unassigned pool — $dashboard_data['task_pool'] or derived from tasks.
 *
 * @return list<array<string, mixed>>
 */
function akh_dashboard_data_task_pool(): array
{
    $pool = akh_dashboard_data_list_section('task_pool');
    if ($pool !== []) {
        return $pool;
    }

    $out = [];
    foreach (akh_dashboard_data_tasks() as $task) {
        $status = strtolower(trim((string) ($task['status'] ?? 'new')));
        $editor = trim((string) ($task['assigned_editor'] ?? ''));
        if ($status === 'new' && $editor === '') {
            $out[] = $task;
        }
    }

    return $out;
}

/**
 * WhatsApp task board — $dashboard_data['whatsapp_tasks'].
 *
 * @return list<array<string, mixed>>
 */
function akh_dashboard_data_whatsapp_tasks(): array
{
    return akh_dashboard_data_list_section('whatsapp_tasks');
}

/**
 * Attendance punch events — $dashboard_data['attendance'].
 *
 * @return list<array<string, mixed>>
 */
function akh_dashboard_data_attendance(): array
{
    return akh_dashboard_data_list_section('attendance');
}

/**
 * Editor roster + shift state — $dashboard_data['editors'].
 *
 * @return list<array<string, mixed>>
 */
function akh_dashboard_data_editors(): array
{
    return akh_dashboard_data_list_section('editors');
}

/**
 * Activity / message log — $dashboard_data['logs'].
 *
 * @return list<array<string, mixed>>
 */
function akh_dashboard_data_logs(): array
{
    foreach (['logs', 'activity', 'whatsapp_messages', 'messages'] as $key) {
        $rows = akh_dashboard_data_list_section($key);
        if ($rows !== []) {
            return $rows;
        }
    }

    return [];
}

/**
 * Meeting requests — $dashboard_data['meetings'].
 *
 * @return list<array<string, mixed>>
 */
function akh_dashboard_data_meetings(): array
{
    return akh_dashboard_data_list_section('meetings');
}

/**
 * Alert map keyed by task code — $dashboard_data['alerts'].
 *
 * @return array<string, array<string, mixed>>
 */
function akh_dashboard_data_alerts(): array
{
    $all = akh_dashboard_data_all();
    $alerts = $all['alerts'] ?? [];
    if (!is_array($alerts) || $alerts === []) {
        return [];
    }

    if (array_is_list($alerts)) {
        $out = [];
        foreach ($alerts as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = trim((string) ($row['task_code'] ?? $row['task_id'] ?? ''));
            if ($code !== '') {
                $out[$code] = $row;
            }
        }

        return $out;
    }

    $out = [];
    foreach ($alerts as $code => $row) {
        if (is_string($code) && is_array($row)) {
            $out[$code] = $row;
        }
    }

    return $out;
}

/**
 * @return array<string, int|float|string>
 */
function akh_dashboard_data_counters(): array
{
    $all = akh_dashboard_data_all();
    $counters = is_array($all['counters'] ?? null) ? $all['counters'] : [];

    $tasks = akh_dashboard_data_tasks();
    $waTasks = akh_dashboard_data_whatsapp_tasks();
    $pool = akh_dashboard_data_task_pool();
    $editors = akh_dashboard_data_editors();
    $logs = akh_dashboard_data_logs();

    $online = 0;
    foreach ($editors as $ed) {
        if (!empty($ed['clocked_in']) || !empty($ed['on_shift'])) {
            ++$online;
        }
    }

    return [
        'total_tasks' => (int) ($counters['total_tasks'] ?? $counters['total'] ?? max(count($tasks), count($waTasks))),
        'pool_count' => (int) ($counters['pool_count'] ?? count($pool)),
        'active_chats' => (int) ($counters['active_chats'] ?? $counters['active'] ?? count($waTasks)),
        'unread_messages' => (int) ($counters['unread_messages'] ?? $counters['unread'] ?? 0),
        'editors_online' => (int) ($counters['editors_online'] ?? $counters['online_editors'] ?? $online),
        'log_count' => (int) ($counters['log_count'] ?? count($logs)),
    ];
}

/**
 * @return list<string>
 */
function akh_dashboard_data_received_keys(): array
{
    $all = akh_dashboard_data_all();
    if ($all === []) {
        return [];
    }

    return array_values(array_filter(
        array_keys($all),
        static fn (string $k): bool => $k !== '' && isset($all[$k]) && $all[$k] !== [] && $all[$k] !== null
    ));
}

function akh_dashboard_data_has_grouped_payload(): bool
{
    foreach (['tasks', 'whatsapp_tasks', 'attendance', 'editors'] as $key) {
        if (akh_dashboard_data_list_section($key) !== []) {
            return true;
        }
    }

    $counters = akh_dashboard_data_all()['counters'] ?? null;

    return is_array($counters) && $counters !== [];
}
