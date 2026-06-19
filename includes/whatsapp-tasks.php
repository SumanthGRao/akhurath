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
        $where[] = '(LOWER(task_code) LIKE ? OR LOWER(project_name) LIKE ? OR LOWER(customer_name) LIKE ? OR phone LIKE ?)';
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like, '%' . trim((string) ($filters['q'] ?? '')) . '%');
    }

    $sql = 'SELECT * FROM whatsapp_tasks WHERE ' . implode(' AND ', $where) . ' ORDER BY updated_at DESC, id DESC';
    $st = akh_db()->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    return is_array($rows) ? $rows : [];
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
            $code = trim((string) $fields[$col]);
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

    return $task !== null ? ['ok' => true, 'task' => $task] : ['ok' => false, 'error' => 'Update failed.'];
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
        'phone' => (string) ($row['phone'] ?? ''),
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
        'created_at' => (string) ($row['created_at'] ?? ''),
        'updated_at' => (string) ($row['updated_at'] ?? ''),
    ];
}
