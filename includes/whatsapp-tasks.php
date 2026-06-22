<?php

declare(strict_types=1);

/** @return list<string> */
function akh_wa_task_statuses(): array
{
    return ['new', 'assigned', 'editing', 'review', 'delivered', 'closed'];
}

function akh_wa_task_status_label(string $status): string
{
    $key = strtolower(trim($status));
    $map = [
        'new' => 'New',
        'assigned' => 'Assigned',
        'editing' => 'Editing',
        'review' => 'Review',
        'delivered' => 'Delivered',
        'closed' => 'Closed',
    ];

    return $map[$key] ?? ucfirst($key);
}

function akh_wa_task_normalize_status(string $status): ?string
{
    $key = strtolower(trim($status));
    if ($key === '') {
        return null;
    }

    return in_array($key, akh_wa_task_statuses(), true) ? $key : null;
}

function akh_wa_tasks_table_exists(): bool
{
    if (!function_exists('akh_db')) {
        return false;
    }

    try {
        $st = akh_db()->query("SHOW TABLES LIKE 'whatsapp_tasks'");

        return $st !== false && $st->fetch(PDO::FETCH_NUM) !== false;
    } catch (Throwable) {
        return false;
    }
}

/** @return array<int, string> editor id => username */
function akh_wa_editors_for_select(): array
{
    if (!function_exists('akh_db')) {
        return [];
    }

    try {
        $st = akh_db()->prepare('SELECT id, username FROM users WHERE role = ? ORDER BY username');
        $st->execute(['editor']);
        $out = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) {
                $out[$id] = (string) ($row['username'] ?? '');
            }
        }

        return $out;
    } catch (Throwable) {
        return [];
    }
}

/**
 * @param array{status?: string, q?: string} $filters
 * @return list<array<string, mixed>>
 */
function akh_wa_tasks_list(array $filters = []): array
{
    if (!akh_wa_tasks_table_exists()) {
        throw new RuntimeException('Table whatsapp_tasks was not found. Import sql/migrations/004_whatsapp_tasks.sql.');
    }

    $where = ['1=1'];
    $params = [];

    $status = isset($filters['status']) ? akh_wa_task_normalize_status((string) $filters['status']) : null;
    if ($status !== null) {
        $where[] = 'LOWER(status) = ?';
        $params[] = $status;
    }

    $q = strtolower(trim((string) ($filters['q'] ?? '')));
    if ($q !== '') {
        $where[] = '(LOWER(task_code) LIKE ? OR LOWER(project_name) LIKE ? OR LOWER(customer_name) LIKE ?)';
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like);
    }

    $sql = 'SELECT * FROM whatsapp_tasks WHERE ' . implode(' AND ', $where) . ' ORDER BY updated_at DESC, id DESC';
    $st = akh_db()->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    return is_array($rows) ? $rows : [];
}

/**
 * @param list<array<string, mixed>> $rows
 * @return list<array<string, mixed>>
 */
function akh_wa_tasks_sort_with_alerts(array $rows): array
{
    require_once __DIR__ . '/task-notification-events.php';
    require_once __DIR__ . '/tasks.php';

    $alerts = akh_task_notification_pending_alerts_grouped();
    usort($rows, static function (array $a, array $b) use ($alerts): int {
        $ca = akh_task_normalize_id((string) ($a['task_code'] ?? ''));
        $cb = akh_task_normalize_id((string) ($b['task_code'] ?? ''));
        $aa = isset($alerts[$ca]) ? 1 : 0;
        $ab = isset($alerts[$cb]) ? 1 : 0;
        if ($aa !== $ab) {
            return $ab <=> $aa;
        }
        if ($aa && $ab) {
            $ta = (string) ($alerts[$ca]['created_at'] ?? '');
            $tb = (string) ($alerts[$cb]['created_at'] ?? '');
            $cmp = strcmp($tb, $ta);
            if ($cmp !== 0) {
                return $cmp;
            }
        }

        return strcmp((string) ($b['updated_at'] ?? ''), (string) ($a['updated_at'] ?? ''));
    });

    return $rows;
}

/**
 * @param array{status?: string, q?: string} $filters
 * @return list<array<string, mixed>>
 */
function akh_wa_tasks_list_for_dashboard(array $filters = []): array
{
    return akh_wa_tasks_sort_with_alerts(akh_wa_tasks_list($filters));
}

/** @return array<string, int> */
function akh_wa_task_status_counts(): array
{
    $counts = array_fill_keys(akh_wa_task_statuses(), 0);
    if (!akh_wa_tasks_table_exists()) {
        return $counts;
    }

    try {
        $st = akh_db()->query('SELECT LOWER(status) AS status, COUNT(*) AS c FROM whatsapp_tasks GROUP BY LOWER(status)');
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $key = akh_wa_task_normalize_status((string) ($row['status'] ?? ''));
            if ($key !== null) {
                $counts[$key] = (int) ($row['c'] ?? 0);
            }
        }
    } catch (Throwable) {
        // leave zeros
    }

    return $counts;
}

function akh_wa_tasks_poll_signature(): string
{
    if (!akh_wa_tasks_table_exists()) {
        return 'missing';
    }

    try {
        $row = akh_db()->query('SELECT COUNT(*) AS c, COALESCE(MAX(updated_at), "") AS u FROM whatsapp_tasks')->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return 'empty';
        }

        return hash('sha256', (string) ($row['c'] ?? '0') . '|' . (string) ($row['u'] ?? ''));
    } catch (Throwable) {
        return 'error';
    }
}

/** @return ?array<string, mixed> */
function akh_wa_task_by_code(string $taskCode): ?array
{
    require_once __DIR__ . '/tasks.php';

    if (!akh_wa_tasks_table_exists()) {
        return null;
    }

    $code = akh_task_normalize_id($taskCode);
    if ($code === '') {
        return null;
    }

    $st = akh_db()->prepare('SELECT * FROM whatsapp_tasks WHERE task_code = ? LIMIT 1');
    $st->execute([$code]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (is_array($row)) {
        return $row;
    }

    if ($code !== $taskCode) {
        $st->execute([trim($taskCode)]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    return null;
}

/**
 * @return ?array<string, mixed>
 */
function akh_wa_task_by_id(int $id): ?array
{
    if ($id <= 0 || !akh_wa_tasks_table_exists()) {
        return null;
    }

    $st = akh_db()->prepare('SELECT * FROM whatsapp_tasks WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

/**
 * @param array<string, mixed> $fields
 * @return array{ok: bool, error?: string, task?: array<string, mixed>}
 */
function akh_wa_task_update(int $id, array $fields): array
{
    if ($id <= 0) {
        return ['ok' => false, 'error' => 'Invalid task id.'];
    }

    if (!akh_wa_tasks_table_exists()) {
        return ['ok' => false, 'error' => 'Table whatsapp_tasks was not found.'];
    }

    $existing = akh_wa_task_by_id($id);
    if ($existing === null) {
        return ['ok' => false, 'error' => 'Task not found.'];
    }

    $allowed = [
        'task_code', 'customer_id', 'phone', 'project_name', 'task_type',
        'instructions', 'delivery_type', 'drive_link', 'reference_link',
        'comments', 'status', 'assigned_editor', 'customer_name',
    ];

    $set = [];
    $params = [];

    foreach ($allowed as $col) {
        if (!array_key_exists($col, $fields)) {
            continue;
        }

        if ($col === 'status') {
            $norm = akh_wa_task_normalize_status((string) $fields[$col]);
            if ($norm === null) {
                return ['ok' => false, 'error' => 'Invalid status.'];
            }
            $set[] = 'status = ?';
            $params[] = $norm;
            continue;
        }

        if ($col === 'customer_id' || $col === 'assigned_editor') {
            $raw = trim((string) $fields[$col]);
            if ($raw === '') {
                $set[] = $col . ' = NULL';
            } else {
                if (!ctype_digit($raw)) {
                    return ['ok' => false, 'error' => 'Invalid ' . $col . '.'];
                }
                $set[] = $col . ' = ?';
                $params[] = (int) $raw;
            }
            continue;
        }

        if ($col === 'task_code') {
            require_once __DIR__ . '/tasks.php';
            $code = akh_task_normalize_id(trim((string) $fields[$col]));
            if ($code === '') {
                return ['ok' => false, 'error' => 'Task code is required.'];
            }
            $set[] = 'task_code = ?';
            $params[] = $code;
            continue;
        }

        $val = trim((string) $fields[$col]);
        if ($val === '' && in_array($col, ['phone', 'project_name', 'task_type', 'delivery_type', 'customer_name'], true)) {
            $set[] = $col . ' = NULL';
        } else {
            $set[] = $col . ' = ?';
            $params[] = $val;
        }
    }

    if ($set === []) {
        return ['ok' => false, 'error' => 'No fields to update.'];
    }

    if (isset($fields['task_code'])) {
        $dup = akh_db()->prepare('SELECT id FROM whatsapp_tasks WHERE task_code = ? AND id <> ? LIMIT 1');
        $dup->execute([(string) $fields['task_code'], $id]);
        if ($dup->fetch(PDO::FETCH_ASSOC) !== false) {
            return ['ok' => false, 'error' => 'Task code already in use.'];
        }
    }

    $params[] = $id;
    $sql = 'UPDATE whatsapp_tasks SET ' . implode(', ', $set) . ' WHERE id = ?';
    akh_db()->prepare($sql)->execute($params);

    $task = akh_wa_task_by_id($id);
    if ($task === null) {
        return ['ok' => false, 'error' => 'Update failed.'];
    }

    $syncErr = akh_wa_sync_to_studio($task);
    if ($syncErr !== null && akh_wa_task_has_assigned_editor($task)) {
        return ['ok' => false, 'error' => 'Saved in WhatsApp tasks, but editor board sync failed: ' . $syncErr, 'task' => $task];
    }

    return ['ok' => true, 'task' => $task, 'sync_warning' => $syncErr];
}

function akh_wa_task_has_assigned_editor(array $row): bool
{
    return isset($row['assigned_editor']) && $row['assigned_editor'] !== null && (string) $row['assigned_editor'] !== '';
}

function akh_wa_map_status_to_studio(string $waStatus): string
{
    $key = strtolower(trim($waStatus));
    $map = [
        'new' => 'new',
        'assigned' => 'assigned',
        'editing' => 'in_progress',
        'review' => 'review',
        'delivered' => 'delivered',
        'closed' => 'closed',
    ];

    return $map[$key] ?? 'new';
}

function akh_wa_map_status_from_studio(string $studioStatus): ?string
{
    $key = strtolower(trim($studioStatus));
    $map = [
        'assigned' => 'assigned',
        'in_progress' => 'editing',
        'review' => 'review',
        'delivered' => 'delivered',
        'reverted' => 'review',
        'closed' => 'closed',
    ];
    $wa = $map[$key] ?? null;
    if ($wa === null) {
        return null;
    }

    return in_array($wa, akh_wa_task_statuses(), true) ? $wa : null;
}

function akh_wa_resolve_client_username(array $waRow): string
{
    $cid = isset($waRow['customer_id']) ? (int) $waRow['customer_id'] : 0;
    if ($cid > 0) {
        $st = akh_db()->prepare('SELECT username FROM users WHERE id = ? AND role = ? LIMIT 1');
        $st->execute([$cid, 'customer']);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            $u = strtolower(trim((string) ($row['username'] ?? '')));
            if ($u !== '') {
                return $u;
            }
        }
    }

    return 'whatsapp';
}

function akh_wa_build_studio_description(array $waRow): string
{
    $parts = ['WhatsApp task: ' . (string) ($waRow['task_code'] ?? '')];
    $taskType = trim((string) ($waRow['task_type'] ?? ''));
    if ($taskType !== '') {
        $parts[] = 'Type: ' . $taskType;
    }
    $deliveryType = trim((string) ($waRow['delivery_type'] ?? ''));
    if ($deliveryType !== '') {
        $parts[] = 'Delivery: ' . $deliveryType;
    }
    $instructions = trim((string) ($waRow['instructions'] ?? ''));
    if ($instructions !== '') {
        $parts[] = '';
        $parts[] = 'Instructions:';
        $parts[] = $instructions;
    }
    $comments = trim((string) ($waRow['comments'] ?? ''));
    if ($comments !== '') {
        $parts[] = '';
        $parts[] = 'Comments:';
        $parts[] = $comments;
    }
    $desc = trim(implode("\n", $parts));

    return $desc !== '' ? $desc : '(WhatsApp task — no notes.)';
}

/** @return array{0: string, 1: string} */
function akh_wa_resolve_delivery_mode(array $waRow): array
{
    $driveRaw = trim((string) ($waRow['drive_link'] ?? ''));
    $drive = akh_wa_normalize_http_url($driveRaw);
    if ($drive !== '' && preg_match('#^https?://#i', $drive)) {
        return ['google_drive', $drive];
    }

    $dtype = strtolower(trim((string) ($waRow['delivery_type'] ?? '')));
    if (str_contains($dtype, 'nas') || str_contains($dtype, 'nextcloud')) {
        return ['nas_storage', ''];
    }
    if (str_contains($dtype, 'courier') || str_contains($dtype, 'hdd')) {
        return ['courier_hdd', ''];
    }

    return ['nas_storage', ''];
}

function akh_wa_normalize_http_url(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $url)) {
        return $url;
    }
    if (preg_match('#^(www\.|drive\.google\.|[a-z0-9-]+\.)#i', $url)) {
        return 'https://' . ltrim($url, '/');
    }

    return $url;
}

/**
 * Sanitize WhatsApp row for editor-board task creation (relaxed vs client form).
 *
 * @return array{
 *   title: string,
 *   description: string,
 *   delivery_mode: string,
 *   drive_link: string,
 *   reference_link: string,
 *   client_username: string
 * }
 */
function akh_wa_prepare_studio_task_inputs(array $waRow): array
{
    require_once __DIR__ . '/tasks.php';

    $title = trim((string) ($waRow['project_name'] ?? ''));
    if ($title === '') {
        $title = trim((string) ($waRow['customer_name'] ?? ''));
    }
    if ($title === '') {
        $title = trim((string) ($waRow['task_code'] ?? 'WhatsApp task'));
    }
    if (mb_strlen($title) > 200) {
        $title = mb_substr($title, 0, 197) . '…';
    }

    $description = akh_wa_build_studio_description($waRow);
    $driveRaw = trim((string) ($waRow['drive_link'] ?? ''));
    $refRaw = trim((string) ($waRow['reference_link'] ?? ''));
    $drive = akh_wa_normalize_http_url($driveRaw);
    $ref = akh_wa_normalize_http_url($refRaw);

    if ($ref !== '' && !akh_task_is_valid_reference_link($ref)) {
        $description .= ($description !== '' ? "\n\n" : '') . 'Reference link: ' . $refRaw;
        $ref = '';
    }

    [$deliveryMode, $driveLink] = akh_wa_resolve_delivery_mode($waRow);
    if ($driveRaw !== '' && $deliveryMode !== 'google_drive') {
        $description .= ($description !== '' ? "\n\n" : '') . 'Drive link: ' . $driveRaw;
    }

    $description = trim($description);
    if ($description === '') {
        $description = '(WhatsApp task — no notes.)';
    }
    if (mb_strlen($description) > 7200) {
        $description = mb_substr($description, 0, 7197) . '…';
    }

    return [
        'title' => $title,
        'description' => $description,
        'delivery_mode' => $deliveryMode,
        'drive_link' => $driveLink,
        'reference_link' => $ref,
        'client_username' => akh_wa_resolve_client_username($waRow),
    ];
}

function akh_wa_studio_create_error_message(array $inputs): string
{
    require_once __DIR__ . '/tasks.php';

    if (trim((string) ($inputs['client_username'] ?? '')) === '') {
        return 'Missing client username.';
    }
    if (trim((string) ($inputs['title'] ?? '')) === '') {
        return 'Missing project/title.';
    }
    if (trim((string) ($inputs['description'] ?? '')) === '') {
        return 'Missing task notes.';
    }
    $mode = (string) ($inputs['delivery_mode'] ?? '');
    if (!in_array($mode, akh_task_valid_delivery_modes(), true)) {
        return 'Invalid delivery mode.';
    }
    $drive = trim((string) ($inputs['drive_link'] ?? ''));
    if ($mode === 'google_drive' && ($drive === '' || !preg_match('#^https?://#i', $drive))) {
        return 'Drive link must be a valid http(s) URL.';
    }
    $ref = trim((string) ($inputs['reference_link'] ?? ''));
    if ($ref !== '' && !akh_task_is_valid_reference_link($ref)) {
        return 'Reference link must be a valid http(s) URL.';
    }

    return 'Could not save task to the editor board (database).';
}

/**
 * Create editor-board task using whatsapp_tasks.task_code as the sole task id.
 *
 * @param array{title: string, description: string, delivery_mode: string, drive_link: string, reference_link: string, client_username: string} $inputs
 * @return ?array<string, mixed>
 */
function akh_wa_insert_studio_task_direct(array $inputs, string $taskCode): ?array
{
    require_once __DIR__ . '/tasks.php';

    $now = gmdate('c');
    $couple = trim((string) $inputs['title']);
    $projectDetails = trim((string) $inputs['description']);
    $referenceLink = trim((string) $inputs['reference_link']);
    $deliveryMode = (string) $inputs['delivery_mode'];
    $driveLink = trim((string) $inputs['drive_link']);
    [$builtDescription, $descOk] = akh_task_build_description(
        $couple,
        'studio_admin',
        $projectDetails,
        $referenceLink,
        $deliveryMode,
        $deliveryMode === 'google_drive' ? $driveLink : ''
    );
    if (!$descOk) {
        $builtDescription = "WhatsApp task: {$taskCode}\n\n{$projectDetails}";
        if (mb_strlen($builtDescription) > 8000) {
            $builtDescription = mb_substr($builtDescription, 0, 7997) . '…';
        }
    }

    $taskCode = akh_task_normalize_id($taskCode);
    if ($taskCode === '' || akh_task_by_id($taskCode) !== null) {
        return null;
    }

    $task = [
        'id' => $taskCode,
        'client_username' => strtolower(trim((string) $inputs['client_username'])),
        'title' => akh_task_build_title($couple, 'studio_admin'),
        'description' => $builtDescription,
        'couple_name' => $couple,
        'edit_type' => 'studio_admin',
        'project_details' => $projectDetails,
        'reference_link' => $referenceLink,
        'delivery_mode' => $deliveryMode,
        'drive_link' => $deliveryMode === 'google_drive' ? $driveLink : '',
        'deliverable_output' => '',
        'client_feedback' => '',
        'client_meeting_date' => '',
        'client_meeting_link' => '',
        'created_at' => $now,
        'updated_at' => $now,
        'status' => 'new',
        'assigned_editor' => null,
        'editor_feedback_notify' => false,
        'client_editor_notify' => false,
        'conversation' => [],
    ];

    $list = akh_tasks_load();
    $list[] = $task;
    if (!akh_tasks_save_locked($list)) {
        return null;
    }

    return $task;
}

function akh_wa_editor_username_by_id(int $editorId): ?string
{
    if ($editorId <= 0) {
        return null;
    }
    $st = akh_db()->prepare('SELECT username FROM users WHERE id = ? AND role = ? LIMIT 1');
    $st->execute([$editorId, 'editor']);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return null;
    }
    $u = strtolower(trim((string) ($row['username'] ?? '')));

    return $u !== '' ? $u : null;
}

function akh_wa_find_studio_task_id(int $waId, string $taskCode): string
{
    require_once __DIR__ . '/tasks.php';

    $code = akh_task_normalize_id($taskCode);
    $existing = $code !== '' ? akh_task_by_id($code) : null;
    if ($existing !== null) {
        return (string) ($existing['id'] ?? $code);
    }

    foreach (akh_tasks_load() as $t) {
        if (!is_array($t)) {
            continue;
        }
        if ($waId > 0 && (int) ($t['whatsapp_task_id'] ?? 0) === $waId) {
            return trim((string) ($t['id'] ?? ''));
        }
        $legacyCode = trim((string) ($t['whatsapp_task_code'] ?? ''));
        if ($legacyCode !== '' && akh_task_ids_match($legacyCode, $code)) {
            return trim((string) ($t['id'] ?? ''));
        }
    }

    return '';
}

/**
 * Editor-board task id for a WhatsApp row (same value as task_code).
 */
function akh_wa_studio_task_id_for_row(array $waRow): string
{
    require_once __DIR__ . '/tasks.php';

    return akh_task_normalize_id((string) ($waRow['task_code'] ?? ''));
}

/**
 * Push WhatsApp task assignment/details to the editor task board (app_kv tasks).
 *
 * @return string|null error message, or null on success / nothing to sync
 */
function akh_wa_sync_to_studio(array $waRow): ?string
{
    require_once __DIR__ . '/tasks.php';

    $waId = (int) ($waRow['id'] ?? 0);
    if ($waId <= 0) {
        return 'Invalid WhatsApp task.';
    }

    $taskCode = akh_task_normalize_id((string) ($waRow['task_code'] ?? ''));
    if ($taskCode === '') {
        return 'WhatsApp task is missing task_code.';
    }

    $studioId = akh_wa_find_studio_task_id($waId, $taskCode);
    if ($studioId === '') {
        $studioId = $taskCode;
    }

    $editorId = isset($waRow['assigned_editor']) ? (int) $waRow['assigned_editor'] : 0;
    $editorUsername = $editorId > 0 ? akh_wa_editor_username_by_id($editorId) : null;
    if ($editorId > 0 && $editorUsername === null) {
        return 'Assigned editor was not found.';
    }

    if ($editorUsername === null && akh_task_by_id($studioId) === null) {
        return null;
    }

    $inputs = akh_wa_prepare_studio_task_inputs($waRow);
    $title = $inputs['title'];
    $description = $inputs['description'];
    $deliveryMode = $inputs['delivery_mode'];
    $driveLink = $inputs['drive_link'];
    $referenceLink = $inputs['reference_link'];
    $waStatus = akh_wa_task_normalize_status((string) ($waRow['status'] ?? 'new')) ?? 'new';
    $studioStatus = akh_wa_map_status_to_studio($waStatus);

    if (akh_task_by_id($studioId) === null) {
        $created = akh_wa_insert_studio_task_direct($inputs, $taskCode);
        if ($created === null) {
            return akh_wa_studio_create_error_message($inputs);
        }
        $studioId = (string) ($created['id'] ?? $taskCode);
        if ($studioId === '') {
            return akh_wa_studio_create_error_message($inputs);
        }
    } else {
        $list = akh_tasks_load();
        $updated = false;
        foreach ($list as $i => $t) {
            if (!akh_task_ids_match((string) ($t['id'] ?? ''), $studioId)) {
                continue;
            }
            $list[$i]['title'] = mb_strlen($title) > 200 ? mb_substr($title, 0, 197) . '…' : $title;
            $list[$i]['description'] = mb_strlen($description) > 8000 ? mb_substr($description, 0, 7997) . '…' : $description;
            $list[$i]['reference_link'] = $referenceLink;
            $list[$i]['delivery_mode'] = $deliveryMode;
            $list[$i]['drive_link'] = $deliveryMode === 'google_drive' ? $driveLink : '';
            $list[$i]['updated_at'] = gmdate('c');
            $updated = true;
            break;
        }
        if ($updated && !akh_tasks_save_locked($list)) {
            return 'Could not update editor-board task.';
        }
    }

    if ($editorUsername !== null) {
        $assignErr = akh_task_admin_assign($studioId, $editorUsername);
        if ($assignErr !== null) {
            return $assignErr;
        }
        if (!in_array($studioStatus, ['new', 'assigned'], true)) {
            $statusErr = akh_task_admin_set_status($studioId, $studioStatus);
            if ($statusErr !== null) {
                return $statusErr;
            }
        }
    } else {
        $assignErr = akh_task_admin_assign($studioId, null);
        if ($assignErr !== null) {
            return $assignErr;
        }
    }

    return null;
}

/** @return array<string, mixed> */
function akh_wa_task_row_for_json(array $row, array $editors): array
{
    $editorId = isset($row['assigned_editor']) && $row['assigned_editor'] !== null && $row['assigned_editor'] !== ''
        ? (int) $row['assigned_editor']
        : null;
    $editorName = ($editorId !== null && isset($editors[$editorId])) ? $editors[$editorId] : '';

    $status = (string) ($row['status'] ?? 'new');

    return [
        'id' => (int) ($row['id'] ?? 0),
        'task_code' => (string) ($row['task_code'] ?? ''),
        'customer_id' => $row['customer_id'] !== null ? (int) $row['customer_id'] : null,
        'project_name' => (string) ($row['project_name'] ?? ''),
        'task_type' => (string) ($row['task_type'] ?? ''),
        'instructions' => (string) ($row['instructions'] ?? ''),
        'delivery_type' => (string) ($row['delivery_type'] ?? ''),
        'drive_link' => (string) ($row['drive_link'] ?? ''),
        'reference_link' => (string) ($row['reference_link'] ?? ''),
        'comments' => (string) ($row['comments'] ?? ''),
        'status' => $status,
        'status_label' => akh_wa_task_status_label($status),
        'assigned_editor' => $editorId,
        'assigned_editor_name' => $editorName,
        'customer_name' => (string) ($row['customer_name'] ?? ''),
        'phone' => (string) ($row['phone'] ?? ''),
        'created_at' => (string) ($row['created_at'] ?? ''),
        'updated_at' => (string) ($row['updated_at'] ?? ''),
    ];
}
