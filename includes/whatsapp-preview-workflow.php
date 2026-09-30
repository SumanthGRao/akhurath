<?php

declare(strict_types=1);

require_once __DIR__ . '/tasks.php';
require_once __DIR__ . '/task-status-log.php';
require_once __DIR__ . '/whatsapp-tasks.php';

/** MySQL handle for automation tables (works when tasks load from n8n bridge). */
function akh_wa_workflow_pdo(): ?PDO
{
    if (function_exists('akh_notify_db_is_available') && akh_notify_db_is_available()) {
        $pdo = akh_notify_db();
        if ($pdo instanceof PDO) {
            return $pdo;
        }
    }
    if (function_exists('akh_db_is_pdo') && akh_db_is_pdo()) {
        $main = akh_db();
        if ($main instanceof PDO) {
            return $main;
        }
    }

    return null;
}

/**
 * Throttled tick on normal page loads (n8n inserts do not hit PHP otherwise).
 */
function akh_wa_preview_workflow_maybe_tick(): void
{
    $pdo = akh_wa_workflow_pdo();
    if ($pdo === null) {
        return;
    }

    $urgent = false;
    if (akh_wa_preview_messages_table_exists()) {
        try {
            $pending = $pdo->query(
                'SELECT COUNT(*) FROM whatsapp_preview_messages WHERE processed_at IS NULL'
            );
            if ($pending !== false) {
                $urgent = (int) $pending->fetchColumn() > 0;
            }
        } catch (Throwable) {
            $urgent = false;
        }
    }

    $now = time();
    $last = akh_wa_preview_kv_get_int('last_tick_ts');
    if (!$urgent && $last > 0 && ($now - $last) < 2) {
        return;
    }
    akh_wa_preview_kv_set_int('last_tick_ts', $now);
    akh_wa_preview_workflow_tick();
}

/**
 * Run preview queue + client feedback watchers (idempotent, safe on every poll).
 */
function akh_wa_preview_workflow_tick(): void
{
    static $ran = false;
    if ($ran) {
        return;
    }
    $ran = true;

    akh_wa_preview_workflow_run();
}

/** Bypass per-request guard (webhook / manual). */
function akh_wa_preview_workflow_tick_force(): void
{
    akh_wa_preview_workflow_run();
}

function akh_wa_preview_workflow_run(): void
{
    if (akh_wa_workflow_pdo() === null) {
        return;
    }

    akh_wa_preview_messages_process_queue();
    akh_wa_preview_watch_incoming_messages();
    akh_wa_preview_watch_meeting_requests();
}

function akh_wa_preview_messages_column_exists(PDO $pdo, string $column): bool
{
    try {
        $schema = $pdo->query('SELECT DATABASE()')->fetchColumn();
        if (!is_string($schema) || $schema === '') {
            return false;
        }
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $st->execute([$schema, 'whatsapp_preview_messages', $column]);

        return (int) $st->fetchColumn() >= 1;
    } catch (Throwable) {
        return false;
    }
}

function akh_wa_preview_messages_table_exists(): bool
{
    $pdo = akh_wa_workflow_pdo();
    if ($pdo === null) {
        return false;
    }
    try {
        $st = $pdo->query("SHOW TABLES LIKE 'whatsapp_preview_messages'");

        return $st !== false && $st->fetch(PDO::FETCH_NUM) !== false;
    } catch (Throwable) {
        return false;
    }
}

function akh_wa_preview_kv_key(string $suffix): string
{
    return 'wa_preview_workflow_' . $suffix;
}

function akh_wa_preview_kv_get_int(string $suffix): int
{
    if (!function_exists('akh_kv_get')) {
        require_once __DIR__ . '/app-kv.php';
    }
    if (!function_exists('akh_kv_get')) {
        return 0;
    }

    return (int) akh_kv_get(akh_wa_preview_kv_key($suffix), 0);
}

function akh_wa_preview_kv_set_int(string $suffix, int $value): void
{
    if (!function_exists('akh_kv_set')) {
        require_once __DIR__ . '/app-kv.php';
    }
    if (!function_exists('akh_kv_set')) {
        return;
    }
    akh_kv_set(akh_wa_preview_kv_key($suffix), (string) max(0, $value));
}

/**
 * Map n8n preview row values (AS0208, 208, whatsapp_tasks.id, etc.) to canonical AS####.
 */
function akh_wa_workflow_resolve_canonical_task_code(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }

    $pdo = akh_wa_workflow_pdo();
    if ($pdo instanceof PDO && ctype_digit($raw)) {
        $waPk = (int) $raw;
        if ($waPk > 0) {
            try {
                $st = $pdo->prepare('SELECT task_code FROM whatsapp_tasks WHERE id = ? LIMIT 1');
                $st->execute([$waPk]);
                $tc = $st->fetchColumn();
                if (is_string($tc) && trim($tc) !== '') {
                    $raw = trim($tc);
                }
            } catch (Throwable) {
                // ignore
            }
        }
    }

    foreach (akh_tasks_load_persisted() as $t) {
        if (akh_task_ids_match((string) ($t['id'] ?? ''), $raw)) {
            return akh_task_normalize_id((string) ($t['id'] ?? ''));
        }
    }

    $norm = akh_task_normalize_id($raw);
    if ($norm !== '') {
        foreach (akh_tasks_load_persisted() as $t) {
            if (akh_task_ids_match((string) ($t['id'] ?? ''), $norm)) {
                return akh_task_normalize_id((string) ($t['id'] ?? ''));
            }
        }
    }

    $wa = akh_wa_task_by_code_pdo($norm !== '' ? $norm : $raw);
    if ($wa !== null) {
        $fromWa = akh_task_normalize_id((string) ($wa['task_code'] ?? ''));
        if ($fromWa !== '') {
            return $fromWa;
        }
    }

    return $norm !== '' ? $norm : akh_task_normalize_id($raw);
}

/**
 * @param array<string, mixed> $row
 */
function akh_wa_workflow_task_code_from_row(array $row): string
{
    foreach (['task_code', 'task_id', 'studio_task_code', 'studio_task_id', 'task'] as $key) {
        if (!array_key_exists($key, $row)) {
            continue;
        }
        $raw = trim((string) $row[$key]);
        if ($raw === '') {
            continue;
        }
        $code = akh_wa_workflow_resolve_canonical_task_code($raw);
        if ($code !== '') {
            return $code;
        }
    }

    $payload = $row['payload'] ?? '';
    if (!is_string($payload) || trim($payload) === '') {
        return '';
    }
    $decoded = json_decode($payload, true);
    if (!is_array($decoded)) {
        return '';
    }
    foreach (['task_code', 'task_id', 'taskId', 'task', 'studio_task_code', 'studio_task_id'] as $key) {
        if (!array_key_exists($key, $decoded)) {
            continue;
        }
        $raw = trim((string) $decoded[$key]);
        if ($raw === '') {
            continue;
        }
        $code = akh_wa_workflow_resolve_canonical_task_code($raw);
        if ($code !== '') {
            return $code;
        }
    }

    return '';
}

function akh_wa_preview_task_is_awaiting_feedback(string $taskCode): bool
{
    $taskCode = akh_task_normalize_id($taskCode);
    if ($taskCode === '') {
        return false;
    }
    $studio = akh_task_by_id($taskCode);
    if (is_array($studio) && strtolower(trim((string) ($studio['status'] ?? ''))) === 'preview_sent') {
        return true;
    }
    $wa = akh_wa_task_by_code_pdo($taskCode);
    if ($wa === null) {
        $wa = akh_wa_task_by_code($taskCode);
    }

    return $wa !== null && strtolower(trim((string) ($wa['status'] ?? ''))) === 'preview_sent';
}

function akh_wa_workflow_set_preview_sent(string $taskCode): bool
{
    $code = akh_wa_workflow_resolve_canonical_task_code($taskCode);
    if ($code === '') {
        error_log('akh_wa_workflow_set_preview_sent: could not resolve task from ' . $taskCode);

        return false;
    }

    $studio = akh_task_by_id($code);
    if ($studio === null) {
        error_log('akh_wa_workflow_set_preview_sent: studio task not found for ' . $code);

        return false;
    }

    $canonical = akh_task_normalize_id((string) ($studio['id'] ?? $code));
    $studioPrev = akh_task_status_log_normalize((string) ($studio['status'] ?? 'new'));
    if ($studioPrev === '') {
        $studioPrev = 'new';
    }

    $changed = false;
    if ($studioPrev !== 'preview_sent') {
        akh_task_status_log_record(
            $canonical,
            $studioPrev,
            'preview_sent',
            'whatsapp',
            'Preview automation',
            'Preview link pushed — status set to Preview sent.'
        );
        $err = akh_task_admin_set_status($canonical, 'preview_sent', 'whatsapp');
        if ($err !== null) {
            error_log('akh_wa_workflow_set_preview_sent studio: ' . $err);

            return false;
        }
        $changed = true;
    }

    $wa = akh_wa_task_by_code_pdo($canonical);
    if ($wa !== null) {
        $waId = (int) ($wa['id'] ?? 0);
        $prevWa = strtolower(trim((string) ($wa['status'] ?? '')));
        if ($waId > 0 && $prevWa !== 'preview_sent') {
            $res = akh_wa_task_update($waId, ['status' => 'preview_sent']);
            if (($res['ok'] ?? false) !== true) {
                error_log('akh_wa_workflow_set_preview_sent wa: ' . (string) ($res['error'] ?? 'update failed'));

                return false;
            }
            $changed = true;
        }
    }

    if ($changed || $studioPrev === 'preview_sent') {
        require_once __DIR__ . '/whatsapp-task-sync.php';
        akh_whatsapp_dispatch_n8n_status_update(
            $canonical,
            'preview_sent',
            'Preview link pushed — status set to Preview sent.',
            'preview_automation'
        );
    }

    return true;
}

function akh_wa_workflow_revert_from_preview(string $taskCode, string $comment): void
{
    if (!akh_wa_preview_task_is_awaiting_feedback($taskCode)) {
        return;
    }

    $code = akh_task_normalize_id(trim($taskCode));
    if ($code === '') {
        return;
    }

    $studio = akh_task_by_id($code);
    $prev = is_array($studio) ? akh_task_status_log_normalize((string) ($studio['status'] ?? '')) : '';
    if ($prev === 'reverted') {
        return;
    }
    if ($prev === '') {
        $prev = 'preview_sent';
    }

    akh_task_status_log_record($code, $prev, 'reverted', 'whatsapp', 'Client feedback', $comment);
    akh_task_admin_set_status($code, 'reverted', 'whatsapp');

    $pdo = akh_wa_workflow_pdo();
    if ($pdo === null) {
        return;
    }

    $wa = akh_wa_task_by_code_pdo($code);
    if ($wa === null) {
        return;
    }
    $waId = (int) ($wa['id'] ?? 0);
    if ($waId <= 0) {
        return;
    }
    try {
        $pdo->prepare('UPDATE whatsapp_tasks SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
            ->execute(['review', $waId]);
    } catch (Throwable $e) {
        error_log('akh_wa_workflow_revert_from_preview: ' . $e->getMessage());
    }
}

function akh_wa_preview_messages_process_queue(): void
{
    $pdo = akh_wa_workflow_pdo();
    if ($pdo === null || !akh_wa_preview_messages_table_exists()) {
        return;
    }

    try {
        $select = 'SELECT id, task_code, payload, created_at';
        if (akh_wa_preview_messages_column_exists($pdo, 'task_id')) {
            $select = 'SELECT id, task_code, task_id, payload, created_at';
        }
        $st = $pdo->query(
            $select . ' FROM whatsapp_preview_messages
             WHERE processed_at IS NULL
             ORDER BY id ASC
             LIMIT 25'
        );
        if ($st === false) {
            return;
        }
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $mark = $pdo->prepare(
            'UPDATE whatsapp_preview_messages SET processed_at = CURRENT_TIMESTAMP WHERE id = ? AND processed_at IS NULL'
        );
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            $code = akh_wa_workflow_task_code_from_row($row);
            if ($id <= 0 || $code === '') {
                error_log(
                    'akh_wa_preview_messages_process_queue: missing/unresolved task on preview row '
                    . $id
                    . ' keys='
                    . json_encode(array_intersect_key($row, array_flip(['task_code', 'task_id', 'payload'])), JSON_UNESCAPED_SLASHES)
                );
                continue;
            }
            if (!akh_wa_workflow_set_preview_sent($code)) {
                continue;
            }
            $mark->execute([$id]);
            akh_wa_preview_feedback_since_preview_row($code, (string) ($row['created_at'] ?? ''));
        }
    } catch (Throwable $e) {
        error_log('akh_wa_preview_messages_process_queue: ' . $e->getMessage());
    }
}

/**
 * Client may have messaged before preview_sent was applied; recheck after preview is sent.
 */
function akh_wa_preview_feedback_since_preview_row(string $taskCode, string $sinceRaw): void
{
    $pdo = akh_wa_workflow_pdo();
    if ($pdo === null) {
        return;
    }
    $code = akh_task_normalize_id($taskCode);
    if ($code === '') {
        return;
    }

    require_once __DIR__ . '/whatsapp-messages.php';
    if (!akh_wa_messages_table_exists_pdo($pdo)) {
        return;
    }

    $since = trim($sinceRaw);
    try {
        if ($since !== '') {
            $st = $pdo->prepare(
                'SELECT id, task_code, direction, sender, created_at
                 FROM whatsapp_messages
                 WHERE created_at >= ?
                 ORDER BY id ASC
                 LIMIT 200'
            );
            $st->execute([$since]);
        } else {
            $st = $pdo->query(
                'SELECT id, task_code, direction, sender, created_at
                 FROM whatsapp_messages
                 ORDER BY id DESC
                 LIMIT 50'
            );
        }
        if ($st === false) {
            return;
        }
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rowCode = akh_wa_workflow_task_code_from_row($row);
            if ($rowCode === '' || !akh_task_ids_match($rowCode, $code)) {
                continue;
            }
            if (!akh_wa_message_is_client_incoming($row)) {
                continue;
            }
            akh_wa_workflow_revert_from_preview($code, 'Client WhatsApp message after preview — returned for revision.');
        }
    } catch (Throwable $e) {
        error_log('akh_wa_preview_feedback_since_preview_row: ' . $e->getMessage());
    }

    require_once __DIR__ . '/meeting-requests.php';
    if (!akh_meeting_requests_table_exists_pdo($pdo)) {
        return;
    }
    try {
        if ($since !== '') {
            $st = $pdo->prepare(
                'SELECT id, task_code, created_at FROM meeting_requests WHERE created_at >= ? ORDER BY id ASC LIMIT 100'
            );
            $st->execute([$since]);
        } else {
            $st = $pdo->query('SELECT id, task_code, created_at FROM meeting_requests ORDER BY id DESC LIMIT 50');
        }
        if ($st === false) {
            return;
        }
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rowCode = akh_wa_workflow_task_code_from_row($row);
            if ($rowCode === '' || !akh_task_ids_match($rowCode, $code)) {
                continue;
            }
            akh_wa_workflow_revert_from_preview($code, 'Meeting request after preview — returned for revision.');
        }
    } catch (Throwable $e) {
        error_log('akh_wa_preview_feedback_since_preview_row meetings: ' . $e->getMessage());
    }
}

function akh_wa_messages_table_exists_pdo(PDO $pdo): bool
{
    try {
        $st = $pdo->query("SHOW TABLES LIKE 'whatsapp_messages'");

        return $st !== false && $st->fetch(PDO::FETCH_NUM) !== false;
    } catch (Throwable) {
        return false;
    }
}

function akh_meeting_requests_table_exists_pdo(PDO $pdo): bool
{
    try {
        $st = $pdo->query("SHOW TABLES LIKE 'meeting_requests'");

        return $st !== false && $st->fetch(PDO::FETCH_NUM) !== false;
    } catch (Throwable) {
        return false;
    }
}

function akh_wa_preview_watch_incoming_messages(): void
{
    $pdo = akh_wa_workflow_pdo();
    if ($pdo === null || !akh_wa_messages_table_exists_pdo($pdo)) {
        return;
    }

    require_once __DIR__ . '/whatsapp-messages.php';

    $lastId = akh_wa_preview_kv_get_int('message_id');
    try {
        if ($lastId === 0) {
            $seed = $pdo->query('SELECT COALESCE(MAX(id), 0) FROM whatsapp_messages');
            $maxSeed = $seed !== false ? (int) $seed->fetchColumn() : 0;
            akh_wa_preview_kv_set_int('message_id', $maxSeed);

            return;
        }

        $st = $pdo->prepare(
            'SELECT id, task_code, direction, sender
             FROM whatsapp_messages
             WHERE id > ?
             ORDER BY id ASC
             LIMIT 100'
        );
        $st->execute([$lastId]);
        $maxId = $lastId;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > $maxId) {
                $maxId = $id;
            }
            if (!akh_wa_message_is_client_incoming($row)) {
                continue;
            }
            $code = akh_wa_workflow_task_code_from_row($row);
            if ($code === '') {
                continue;
            }
            akh_wa_workflow_revert_from_preview($code, 'Client WhatsApp message after preview — returned for revision.');
        }
        if ($maxId > $lastId) {
            akh_wa_preview_kv_set_int('message_id', $maxId);
        }
    } catch (Throwable $e) {
        error_log('akh_wa_preview_watch_incoming_messages: ' . $e->getMessage());
    }
}

function akh_wa_preview_watch_meeting_requests(): void
{
    $pdo = akh_wa_workflow_pdo();
    if ($pdo === null) {
        return;
    }

    require_once __DIR__ . '/meeting-requests.php';
    if (!akh_meeting_requests_table_exists_pdo($pdo)) {
        return;
    }

    $lastId = akh_wa_preview_kv_get_int('meeting_id');
    try {
        if ($lastId === 0) {
            $seed = $pdo->query('SELECT COALESCE(MAX(id), 0) FROM meeting_requests');
            $maxSeed = $seed !== false ? (int) $seed->fetchColumn() : 0;
            akh_wa_preview_kv_set_int('meeting_id', $maxSeed);

            return;
        }

        $st = $pdo->prepare(
            'SELECT id, task_code FROM meeting_requests WHERE id > ? ORDER BY id ASC LIMIT 100'
        );
        $st->execute([$lastId]);
        $maxId = $lastId;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > $maxId) {
                $maxId = $id;
            }
            $code = akh_wa_workflow_task_code_from_row($row);
            if ($code === '') {
                continue;
            }
            akh_wa_workflow_revert_from_preview($code, 'Meeting request after preview — returned for revision.');
        }
        if ($maxId > $lastId) {
            akh_wa_preview_kv_set_int('meeting_id', $maxId);
        }
    } catch (Throwable $e) {
        error_log('akh_wa_preview_watch_meeting_requests: ' . $e->getMessage());
    }
}

/**
 * Called after a client message row is stored (immediate revert when already on preview_sent).
 */
function akh_wa_preview_on_client_message_inserted(string $taskCode, int $messageId): void
{
    if ($messageId > 0) {
        $cursor = akh_wa_preview_kv_get_int('message_id');
        if ($messageId > $cursor) {
            akh_wa_preview_kv_set_int('message_id', $messageId);
        }
    }
    akh_wa_workflow_revert_from_preview($taskCode, 'Client WhatsApp message after preview — returned for revision.');
}
