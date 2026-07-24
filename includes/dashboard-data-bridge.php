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

    // Flat list legacy: messages or tasks.
    if (array_is_list($raw)) {
        $messages = [];
        $tasks = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (isset($row['message']) || isset($row['direction']) || isset($row['sender'])) {
                $messages[] = $row;
            } else {
                $tasks[] = $row;
            }
        }
        if ($messages !== []) {
            return ['whatsapp_messages' => $messages];
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

    if (function_exists('akh_db_is_bridge') && akh_db_is_bridge()) {
        $dashboard_data = akh_db_data();

        return;
    }

    if (defined('AKH_CHAT_API_URL')) {
        $dashboard_data = akh_dashboard_data_fetch();

        return;
    }

    if (function_exists('getAkhurathChatData')) {
        $dashboard_data = akh_dashboard_data_normalize(getAkhurathChatData());

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
    foreach (['tasks', 'whatsapp_tasks', 'attendance', 'editors', 'app_kv'] as $key) {
        if ($key === 'app_kv') {
            $kv = akh_dashboard_data_all()['app_kv'] ?? null;
            if (is_array($kv) && $kv !== []) {
                return true;
            }
            continue;
        }
        if (akh_dashboard_data_list_section($key) !== []) {
            return true;
        }
    }

    $counters = akh_dashboard_data_all()['counters'] ?? null;

    return is_array($counters) && $counters !== [];
}

/** True when database.local.php defines the n8n webhook URL. */
function akh_dashboard_data_bridge_enabled(): bool
{
    return defined('AKH_CHAT_API_URL') && trim((string) AKH_CHAT_API_URL) !== '';
}

/** Read tasks, attendance, and related desk data from n8n instead of MySQL/files. */
function akh_dashboard_data_bridge_reads(): bool
{
    if (function_exists('akh_db_is_bridge') && akh_db_is_bridge()) {
        return true;
    }

    return akh_dashboard_data_bridge_enabled();
}

/**
 * @return mixed
 */
function akh_dashboard_data_app_kv_raw(string $key)
{
    $all = akh_dashboard_data_all();
    $kv = $all['app_kv'] ?? null;
    if (!is_array($kv) || !array_key_exists($key, $kv)) {
        return null;
    }

    return $kv[$key];
}

/**
 * @return list<array<string, mixed>>
 */
function akh_dashboard_data_tasks_from_app_kv(): array
{
    $raw = akh_dashboard_data_app_kv_raw('tasks');
    if ($raw === null) {
        return [];
    }
    if (is_string($raw)) {
        try {
            $raw = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }
    }
    if (!is_array($raw)) {
        return [];
    }
    $out = [];
    foreach ($raw as $row) {
        if (is_array($row)) {
            $out[] = $row;
        }
    }

    return $out;
}

/**
 * @return array<string, string> editor user id => username
 */
function akh_dashboard_data_editor_id_map(): array
{
    $map = [];
    foreach (akh_dashboard_data_editors() as $ed) {
        if (!is_array($ed)) {
            continue;
        }
        $id = (int) ($ed['id'] ?? 0);
        $username = strtolower(trim((string) ($ed['username'] ?? $ed['editor'] ?? '')));
        if ($id > 0 && $username !== '') {
            $map[$id] = $username;
        }
    }

    return $map;
}

/**
 * @param array<string, mixed> $wa
 * @param array<int, string> $editorIdMap
 * @return array<string, mixed>
 */
function akh_dashboard_wa_row_to_studio_task(array $wa, array $editorIdMap): array
{
    require_once __DIR__ . '/tasks.php';
    require_once __DIR__ . '/whatsapp-tasks.php';

    $taskId = akh_task_normalize_id((string) ($wa['task_code'] ?? $wa['id'] ?? ''));
    $editorUsername = strtolower(trim((string) ($wa['assigned_editor_username'] ?? '')));
    if ($editorUsername === '' && isset($wa['assigned_editor'])) {
        $eid = (int) $wa['assigned_editor'];
        $editorUsername = strtolower(trim((string) ($editorIdMap[$eid] ?? '')));
    }

    $title = trim((string) ($wa['project_name'] ?? ''));
    if ($title === '') {
        $title = trim((string) ($wa['customer_name'] ?? ''));
    }
    if ($title === '') {
        $title = $taskId;
    }

    $client = trim((string) ($wa['customer_name'] ?? ''));
    if ($client === '') {
        $client = 'whatsapp';
    }

    return [
        'id' => $taskId,
        'title' => $title,
        'status' => akh_wa_map_status_to_studio((string) ($wa['status'] ?? 'new')),
        'assigned_editor' => $editorUsername !== '' ? $editorUsername : null,
        'client_username' => $client,
        'customer_name' => trim((string) ($wa['customer_name'] ?? '')),
        'project_name' => trim((string) ($wa['project_name'] ?? '')),
        'edit_type' => 'studio_admin',
        'description' => trim((string) ($wa['instructions'] ?? '')),
        'reference_link' => trim((string) ($wa['reference_link'] ?? '')),
        'delivery_mode' => trim((string) ($wa['delivery_type'] ?? '')),
        'drive_link' => trim((string) ($wa['drive_link'] ?? '')),
        'created_at' => (string) ($wa['created_at'] ?? ''),
        'updated_at' => (string) ($wa['updated_at'] ?? ''),
        'editor_feedback_notify' => false,
        'client_feedback' => '',
        'client_meeting_date' => '',
        'client_meeting_link' => '',
        'deliverable_output' => '',
        'conversation' => [],
        'phone' => trim((string) ($wa['phone'] ?? '')),
    ];
}

/**
 * @param array<string, mixed> $task
 * @param array<string, mixed> $wa
 * @return array<string, mixed>
 */
function akh_dashboard_merge_wa_fields_into_task(array $task, array $wa): array
{
    $customerName = trim((string) ($wa['customer_name'] ?? ''));
    if ($customerName !== '') {
        $task['customer_name'] = $customerName;
    }

    $projectName = trim((string) ($wa['project_name'] ?? ''));
    if ($projectName !== '' && trim((string) ($task['project_name'] ?? '')) === '') {
        $task['project_name'] = $projectName;
    }

    $phone = trim((string) ($wa['phone'] ?? ''));
    if ($phone !== '' && trim((string) ($task['phone'] ?? '')) === '') {
        $task['phone'] = $phone;
    }

    $client = strtolower(trim((string) ($task['client_username'] ?? '')));
    if ($customerName !== '' && ($client === '' || $client === 'whatsapp')) {
        $task['client_username'] = $customerName;
    }

    return $task;
}

/**
 * Studio board tasks merged from payload tasks + whatsapp_tasks.
 *
 * @return list<array<string, mixed>>
 */
function akh_dashboard_data_tasks_merged_for_board(): array
{
    require_once __DIR__ . '/tasks.php';

    $editorIdMap = akh_dashboard_data_editor_id_map();
    $byId = [];

    foreach (akh_dashboard_data_tasks_from_app_kv() as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = akh_task_normalize_id((string) ($row['id'] ?? ''));
        if ($id !== '') {
            $byId[$id] = akh_task_normalize_row_from_storage($row);
        }
    }

    foreach (akh_dashboard_data_tasks() as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = akh_task_normalize_id((string) ($row['id'] ?? $row['task_code'] ?? ''));
        if ($id !== '') {
            $byId[$id] = akh_task_normalize_row_from_storage($row);
        }
    }

    foreach (akh_dashboard_data_whatsapp_tasks() as $wa) {
        if (!is_array($wa)) {
            continue;
        }
        $studio = akh_dashboard_wa_row_to_studio_task($wa, $editorIdMap);
        $id = akh_task_normalize_id((string) ($studio['id'] ?? ''));
        if ($id === '') {
            continue;
        }
        if (!isset($byId[$id])) {
            $byId[$id] = $studio;
            continue;
        }
        $byId[$id] = akh_dashboard_merge_wa_fields_into_task($byId[$id], $wa);
    }

    return array_values($byId);
}

/**
 * @return array{events: list<array{editor: string, type: string, at: int}>}
 */
function akh_dashboard_data_attendance_doc(): array
{
    $raw = akh_dashboard_data_app_kv_raw('editor_attendance');
    if (is_array($raw) && isset($raw['events']) && is_array($raw['events'])) {
        $events = $raw['events'];
    } else {
        $events = akh_dashboard_data_attendance();
    }

    $out = [];
    foreach ($events as $event) {
        if (!is_array($event)) {
            continue;
        }
        $editor = strtolower(trim((string) ($event['editor'] ?? $event['username'] ?? '')));
        $type = strtolower(trim((string) ($event['type'] ?? $event['action'] ?? '')));
        if ($type === 'in') {
            $type = 'clock_in';
        } elseif ($type === 'out') {
            $type = 'clock_out';
        }
        $at = (int) ($event['at'] ?? 0);
        if ($at < 1 && isset($event['created_at'])) {
            $ts = strtotime((string) $event['created_at']);
            if ($ts !== false) {
                $at = $ts;
            }
        }
        if ($editor === '' || ($type !== 'clock_in' && $type !== 'clock_out') || $at < 1) {
            continue;
        }
        $out[] = ['editor' => $editor, 'type' => $type, 'at' => $at];
    }

    return ['events' => $out];
}

/**
 * @return array<string, list<string>>
 */
function akh_dashboard_data_editor_seen_map(): array
{
    $raw = akh_dashboard_data_app_kv_raw('editor_seen_tasks');
    if ($raw === null) {
        $raw = akh_dashboard_data_all()['editor_seen_tasks'] ?? null;
    }
    if (is_string($raw)) {
        try {
            $raw = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }
    }
    if (!is_array($raw)) {
        return [];
    }
    $out = [];
    foreach ($raw as $k => $v) {
        if (!is_string($k) || !is_array($v)) {
            continue;
        }
        $out[strtolower($k)] = array_values(array_filter($v, 'is_string'));
    }

    return $out;
}

/**
 * @return list<array<string, mixed>>
 */
function akh_dashboard_data_whatsapp_messages(): array
{
    foreach (['whatsapp_messages', 'messages', 'logs'] as $key) {
        $rows = akh_dashboard_data_list_section($key);
        if ($rows !== []) {
            return $rows;
        }
    }

    $all = akh_dashboard_data_all();
    if (array_is_list($all)) {
        $out = [];
        foreach ($all as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (isset($row['message']) || isset($row['direction']) || isset($row['sender'])) {
                $out[] = $row;
            }
        }

        return $out;
    }

    return [];
}

/**
 * @param array<string, mixed> $filters
 * @return list<array<string, mixed>>
 */
function akh_dashboard_data_whatsapp_tasks_filtered(array $filters = []): array
{
    $rows = akh_dashboard_data_whatsapp_tasks();
    if ($rows === []) {
        return [];
    }

    $status = isset($filters['status']) ? strtolower(trim((string) $filters['status'])) : '';
    $q = strtolower(trim((string) ($filters['q'] ?? '')));

    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        if ($status !== '' && strtolower(trim((string) ($row['status'] ?? ''))) !== $status) {
            continue;
        }
        if ($q !== '') {
            $hay = strtolower(implode(' ', [
                (string) ($row['task_code'] ?? ''),
                (string) ($row['project_name'] ?? ''),
                (string) ($row['customer_name'] ?? ''),
            ]));
            if (!str_contains($hay, $q)) {
                continue;
            }
        }
        $out[] = $row;
    }

    usort($out, static function (array $a, array $b): int {
        return strcmp((string) ($b['updated_at'] ?? ''), (string) ($a['updated_at'] ?? ''));
    });

    return $out;
}

