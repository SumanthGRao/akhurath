<?php

declare(strict_types=1);

require_once __DIR__ . '/whatsapp-tasks.php';

/** Last sync failure reason (for editor dashboard messages). */
function akh_whatsapp_task_sync_last_error(): string
{
    return (string) ($GLOBALS['akh_whatsapp_task_sync_error'] ?? '');
}

function akh_whatsapp_task_sync_set_error(string $message): void
{
    $GLOBALS['akh_whatsapp_task_sync_error'] = $message;
}

function akh_task_progress_stale_hours(): int
{
    if (defined('AKH_TASK_PROGRESS_STALE_HOURS')) {
        return max(1, (int) AKH_TASK_PROGRESS_STALE_HOURS);
    }

    return 48;
}

function akh_task_progress_stale_ack_kv_key(): string
{
    return 'task_progress_stale_alert_acks';
}

function akh_task_progress_stale_site_day(?int $timestamp = null): string
{
    require_once __DIR__ . '/site-datetime.php';

    $ts = $timestamp ?? time();

    return (new DateTimeImmutable('@' . $ts))->setTimezone(akh_site_timezone())->format('Y-m-d');
}

/** @return array<string, array{day: string, at: int}> */
function akh_task_progress_stale_ack_codes(): array
{
    if (isset($GLOBALS['akh_task_progress_stale_ack_codes']) && is_array($GLOBALS['akh_task_progress_stale_ack_codes'])) {
        return $GLOBALS['akh_task_progress_stale_ack_codes'];
    }

    require_once __DIR__ . '/app-kv.php';
    $raw = akh_kv_get(akh_task_progress_stale_ack_kv_key());
    if (!is_string($raw) || trim($raw) === '') {
        $GLOBALS['akh_task_progress_stale_ack_codes'] = [];

        return $GLOBALS['akh_task_progress_stale_ack_codes'];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        $GLOBALS['akh_task_progress_stale_ack_codes'] = [];

        return $GLOBALS['akh_task_progress_stale_ack_codes'];
    }

    require_once __DIR__ . '/tasks.php';
    $out = [];
    foreach ($decoded as $code => $meta) {
        $norm = akh_task_normalize_id((string) $code);
        if ($norm === '') {
            continue;
        }
        if (is_array($meta)) {
            $out[$norm] = [
                'day' => trim((string) ($meta['day'] ?? '')),
                'at' => (int) ($meta['at'] ?? 0),
            ];
        } else {
            $out[$norm] = [
                'day' => akh_task_progress_stale_site_day((int) $meta),
                'at' => (int) $meta,
            ];
        }
    }
    $GLOBALS['akh_task_progress_stale_ack_codes'] = $out;

    return $out;
}

function akh_task_progress_stale_ack_codes_invalidate(): void
{
    unset($GLOBALS['akh_task_progress_stale_ack_codes']);
}

function akh_task_progress_stale_alerts_invalidate(): void
{
    $GLOBALS['akh_task_progress_stale_cache_gen'] = (int) ($GLOBALS['akh_task_progress_stale_cache_gen'] ?? 0) + 1;
}

function akh_task_progress_stale_is_dismissed(string $taskCode): bool
{
    require_once __DIR__ . '/tasks.php';

    $today = akh_task_progress_stale_site_day();
    $acks = akh_task_progress_stale_ack_codes();
    foreach (akh_task_id_match_variants($taskCode) as $variant) {
        $norm = akh_task_normalize_id($variant);
        if ($norm === '' || !isset($acks[$norm])) {
            continue;
        }
        if (($acks[$norm]['day'] ?? '') === $today) {
            return true;
        }
    }

    return false;
}

function akh_task_progress_stale_mark_read(string $taskCode): void
{
    require_once __DIR__ . '/app-kv.php';
    require_once __DIR__ . '/tasks.php';

    $variants = akh_task_id_match_variants($taskCode);
    if ($variants === []) {
        return;
    }

    $acks = akh_task_progress_stale_ack_codes();
    $today = akh_task_progress_stale_site_day();
    $now = time();
    $changed = false;

    foreach ($variants as $variant) {
        $norm = akh_task_normalize_id($variant);
        if ($norm === '') {
            continue;
        }
        if (($acks[$norm]['day'] ?? '') === $today) {
            continue;
        }
        $acks[$norm] = ['day' => $today, 'at' => $now];
        $changed = true;
    }

    if (!$changed) {
        return;
    }

    try {
        akh_kv_set(akh_task_progress_stale_ack_kv_key(), json_encode($acks, JSON_UNESCAPED_SLASHES) ?: '{}');
    } catch (Throwable $e) {
        error_log('akh_task_progress_stale_mark_read: ' . $e->getMessage());

        return;
    }

    akh_task_progress_stale_ack_codes_invalidate();
    akh_task_progress_stale_alerts_invalidate();
}

function akh_task_progress_stale_mark_all_read(): void
{
    if (!akh_wa_tasks_table_exists()) {
        return;
    }

    require_once __DIR__ . '/app-kv.php';
    require_once __DIR__ . '/tasks.php';

    $acks = akh_task_progress_stale_ack_codes();
    $today = akh_task_progress_stale_site_day();
    $now = time();
    $changed = false;

    try {
        foreach (akh_wa_tasks_list(['scope' => 'active']) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $meta = akh_task_progress_update_meta($row);
            if (!$meta['stale']) {
                continue;
            }
            $code = akh_task_normalize_id((string) ($row['task_code'] ?? ''));
            if ($code === '' || ($acks[$code]['day'] ?? '') === $today) {
                continue;
            }
            $acks[$code] = ['day' => $today, 'at' => $now];
            $changed = true;
        }
    } catch (Throwable $e) {
        error_log('akh_task_progress_stale_mark_all_read: ' . $e->getMessage());

        return;
    }

    if (!$changed) {
        return;
    }

    try {
        akh_kv_set(akh_task_progress_stale_ack_kv_key(), json_encode($acks, JSON_UNESCAPED_SLASHES) ?: '{}');
    } catch (Throwable $e) {
        error_log('akh_task_progress_stale_mark_all_read: ' . $e->getMessage());

        return;
    }

    akh_task_progress_stale_ack_codes_invalidate();
    akh_task_progress_stale_alerts_invalidate();
}

function akh_task_progress_stale_alert_label(): string
{
    $days = max(1, (int) ceil(akh_task_progress_stale_hours() / 24));

    return 'No progress update in ' . $days . '+ days';
}

function akh_task_progress_stale_poll_signature(): string
{
    return hash('sha256', json_encode(akh_task_progress_stale_ack_codes(), JSON_UNESCAPED_SLASHES) ?: '[]');
}

/** @return list<string> */
function akh_task_statuses_requiring_progress_updates(): array
{
    return ['assigned', 'in_progress', 'review', 'preview_sent', 'reverted'];
}

/** @return list<string> */
function akh_wa_statuses_requiring_progress_updates(): array
{
    return ['assigned', 'editing', 'review', 'preview_sent'];
}

/**
 * @return list<array{id: int, task_id: string, status: string, comment: string, updated_by: string, created_at: string}>
 */
function akh_task_status_updates_recent(string $taskCode, int $limit = 2): array
{
    require_once __DIR__ . '/tasks.php';

    $taskCode = akh_task_normalize_id(trim($taskCode));
    if ($taskCode === '' || !akh_wa_task_updates_table_exists()) {
        return [];
    }

    $limit = max(1, min(20, $limit));

    try {
        $st = akh_db()->prepare(
            'SELECT id, task_id, status, comment, updated_by, created_at
             FROM task_updates
             WHERE task_id = ?
             ORDER BY created_at DESC, id DESC
             LIMIT ' . $limit
        );
        $st->execute([$taskCode]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(static function (array $row): array {
            return [
                'id' => (int) ($row['id'] ?? 0),
                'task_id' => (string) ($row['task_id'] ?? ''),
                'status' => (string) ($row['status'] ?? ''),
                'comment' => (string) ($row['comment'] ?? ''),
                'updated_by' => (string) ($row['updated_by'] ?? ''),
                'created_at' => (string) ($row['created_at'] ?? ''),
            ];
        }, $rows);
    } catch (Throwable) {
        return [];
    }
}

function akh_task_last_progress_at(string $taskCode): string
{
    $updates = akh_task_status_updates_recent($taskCode, 1);
    if ($updates !== []) {
        return (string) ($updates[0]['created_at'] ?? '');
    }

    return '';
}

/**
 * @param array<string, mixed> $task
 * @return array{stale: bool, last_at: string, hours_since: ?int, label: string}
 */
function akh_task_progress_update_meta(array $task): array
{
    require_once __DIR__ . '/tasks.php';
    require_once __DIR__ . '/site-datetime.php';

    $empty = ['stale' => false, 'last_at' => '', 'hours_since' => null, 'label' => ''];
    $taskCode = akh_task_normalize_id((string) ($task['task_code'] ?? $task['id'] ?? ''));
    if ($taskCode === '') {
        return $empty;
    }

    $status = strtolower(trim((string) ($task['status'] ?? '')));
    $needsProgress = in_array($status, akh_task_statuses_requiring_progress_updates(), true)
        || in_array($status, akh_wa_statuses_requiring_progress_updates(), true);
    $hasEditor = trim((string) ($task['assigned_editor'] ?? '')) !== ''
        || trim((string) ($task['assigned_editor_name'] ?? '')) !== ''
        || akh_wa_task_has_assigned_editor($task);

    if (!$needsProgress || !$hasEditor) {
        return $empty;
    }

    $lastAt = akh_task_last_progress_at($taskCode);
    if ($lastAt === '') {
        $lastAt = (string) ($task['updated_at'] ?? $task['created_at'] ?? '');
    }

    $dt = akh_parse_datetime_to_site($lastAt);
    if ($dt === null) {
        return ['stale' => false, 'last_at' => $lastAt, 'hours_since' => null, 'label' => ''];
    }

    $hoursSince = (int) floor((time() - $dt->getTimestamp()) / 3600);
    $stale = $hoursSince >= akh_task_progress_stale_hours();
    $recentUpdates = akh_task_status_updates_recent($taskCode, 1);
    $hasLoggedUpdate = $recentUpdates !== [];

    $label = '';
    if ($stale) {
        $label = akh_task_progress_stale_alert_label();
    } elseif (!$hasLoggedUpdate) {
        $label = 'No status updates logged yet';
    }

    return [
        'stale' => $stale,
        'last_at' => $lastAt,
        'hours_since' => $hoursSince,
        'label' => $label,
    ];
}

/**
 * @return array<string, array<string, mixed>>
 */
function akh_task_progress_stale_alerts_grouped(): array
{
    static $cached = null;
    static $cacheGen = -1;
    $gen = (int) ($GLOBALS['akh_task_progress_stale_cache_gen'] ?? 0);
    if (is_array($cached) && $cacheGen === $gen) {
        return $cached;
    }

    if (!akh_wa_tasks_table_exists()) {
        $cached = [];
        $cacheGen = $gen;

        return $cached;
    }

    $out = [];
    $alertDay = akh_task_progress_stale_site_day();
    try {
        foreach (akh_wa_tasks_list(['scope' => 'active']) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $meta = akh_task_progress_update_meta($row);
            if (!$meta['stale']) {
                continue;
            }
            $code = akh_task_normalize_id((string) ($row['task_code'] ?? ''));
            if ($code === '' || akh_task_progress_stale_is_dismissed($code)) {
                continue;
            }
            $project = trim((string) ($row['project_name'] ?? ''));
            $customer = trim((string) ($row['customer_name'] ?? ''));
            $preview = (string) $meta['label'];
            if ($project !== '') {
                $preview .= ' — ' . $project;
            } elseif ($customer !== '') {
                $preview .= ' — ' . $customer;
            }
            $out[$code] = [
                'kind' => 'progress_stale',
                'preview' => $preview,
                'priority' => 45,
                'created_at' => (string) ($meta['last_at'] ?? ''),
                'alert_day' => $alertDay,
                'project_name' => $project,
                'customer_name' => $customer,
                'count' => 1,
            ];
        }
    } catch (Throwable $e) {
        error_log('akh_task_progress_stale_alerts_grouped: ' . $e->getMessage());
        $out = [];
    }

    $cached = $out;
    $cacheGen = $gen;

    return $cached;
}

/**
 * @return list<array<string, mixed>>
 */
function akh_task_status_updates_for_display(string $taskCode, int $limit = 2): array
{
    require_once __DIR__ . '/site-datetime.php';

    $rows = [];
    foreach (akh_task_status_updates_recent($taskCode, $limit) as $row) {
        $rows[] = [
            'status' => (string) ($row['status'] ?? ''),
            'comment' => (string) ($row['comment'] ?? ''),
            'updated_by' => (string) ($row['updated_by'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'created_at_label' => akh_format_datetime_site_short((string) ($row['created_at'] ?? '')),
            'relative_at' => akh_format_relative_time_site((string) ($row['created_at'] ?? '')),
        ];
    }

    return $rows;
}

function akh_wa_task_updates_table_exists(): bool
{
    if (!function_exists('akh_db')) {
        return false;
    }

    try {
        $st = akh_db()->query("SHOW TABLES LIKE 'task_updates'");

        return $st !== false && $st->fetch(PDO::FETCH_NUM) !== false;
    } catch (Throwable) {
        return false;
    }
}

/**
 * @return ?array<string, mixed>
 */
function akh_wa_find_row_for_studio_task(array $studioTask): ?array
{
    require_once __DIR__ . '/tasks.php';

    $taskCode = akh_task_normalize_id((string) ($studioTask['id'] ?? ''));
    if ($taskCode === '') {
        return null;
    }

    return akh_wa_task_by_code($taskCode);
}

function akh_wa_editor_display_name(string $editorUsername): string
{
    $editorUsername = trim($editorUsername);
    if ($editorUsername === '' || !function_exists('akh_db')) {
        return $editorUsername;
    }

    try {
        $st = akh_db()->prepare(
            'SELECT username FROM users WHERE role = ? AND LOWER(username) = LOWER(?) LIMIT 1'
        );
        $st->execute(['editor', $editorUsername]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            $name = trim((string) ($row['username'] ?? ''));
            if ($name !== '') {
                return $name;
            }
        }
    } catch (Throwable) {
        // fall through
    }

    return $editorUsername;
}

/**
 * Update whatsapp_tasks and append task_updates when editor workflow status changes or a progress note is saved.
 *
 * @param array<string, mixed> $studioTask
 */
function akh_whatsapp_record_task_status_update(
    array $studioTask,
    string $internalStatus,
    string $editorUsername,
    string $comment
): bool {
    require_once __DIR__ . '/tasks.php';

    akh_whatsapp_task_sync_set_error('');

    $waTable = akh_wa_tasks_table_exists();
    $updatesTable = akh_wa_task_updates_table_exists();
    if (!$waTable && !$updatesTable) {
        return true;
    }

    $comment = trim($comment);
    if ($comment === '' || mb_strlen($comment) > 2000) {
        akh_whatsapp_task_sync_set_error('A status note is required to record workflow updates.');

        return false;
    }

    $editorUsername = trim($editorUsername);
    if ($editorUsername === '' || mb_strlen($editorUsername) > 64) {
        akh_whatsapp_task_sync_set_error('Invalid editor account.');

        return false;
    }

    $waStatus = akh_wa_map_status_from_studio($internalStatus);
    if ($waStatus === null) {
        akh_whatsapp_task_sync_set_error('That workflow status cannot be synced to WhatsApp.');

        return false;
    }

    $taskCode = akh_task_normalize_id((string) ($studioTask['id'] ?? ''));
    if ($taskCode === '') {
        akh_whatsapp_task_sync_set_error('Could not resolve the task code for this job.');

        return false;
    }

    $waRow = $waTable ? akh_wa_find_row_for_studio_task($studioTask) : null;
    $statusLabel = akh_wa_task_status_label($waStatus);
    $updatedBy = akh_wa_editor_display_name($editorUsername);

    try {
        $pdo = akh_db();
        $pdo->beginTransaction();

        if ($waTable && $waRow !== null) {
            $waId = (int) ($waRow['id'] ?? 0);
            if ($waId > 0) {
                $pdo->prepare('UPDATE whatsapp_tasks SET status = ?, updated_at = NOW() WHERE id = ?')
                    ->execute([$waStatus, $waId]);
            }
        }

        if ($updatesTable) {
            $pdo->prepare(
                'INSERT INTO task_updates (task_id, status, comment, updated_by) VALUES (?, ?, ?, ?)'
            )->execute([$taskCode, $statusLabel, $comment, $updatedBy]);
        }

        $pdo->commit();

        return true;
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        akh_whatsapp_task_sync_set_error('WhatsApp sync failed: ' . $e->getMessage());

        return false;
    }
}

function akh_whatsapp_status_n8n_webhook_url(): string
{
    $default = 'https://n8n.akhurathstudio.com/webhook/8054f3a3-50da-4270-88f3-8e6aa1be1a5a';
    if (defined('AKH_N8N_TASK_STATUS_WEBHOOK_URL')) {
        $configured = trim((string) AKH_N8N_TASK_STATUS_WEBHOOK_URL);
        if ($configured !== '') {
            return $configured;
        }
    }

    return $default;
}

/**
 * Notify n8n after a successful editor status save (fire-and-forget).
 */
function akh_whatsapp_dispatch_n8n_status_update(
    string $taskCode,
    string $status,
    string $comment,
    string $editorUsername
): void {
    $url = akh_whatsapp_status_n8n_webhook_url();
    if ($url === '' || !str_starts_with($url, 'http')) {
        error_log('akh_n8n_status_webhook: skipped — invalid URL');

        return;
    }

    $taskCode = akh_task_normalize_id(trim($taskCode));
    if ($taskCode === '') {
        error_log('akh_n8n_status_webhook: skipped — empty task_code');

        return;
    }

    $body = [
        'task_code' => $taskCode,
        'status' => trim($status),
        'comment' => trim($comment),
        'updated_by' => akh_wa_editor_display_name(trim($editorUsername)),
    ];

    $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($payload)) {
        error_log('akh_n8n_status_webhook: skipped — could not encode JSON payload');

        return;
    }

    try {
        if (!function_exists('curl_init')) {
            error_log('akh_n8n_status_webhook: cURL extension is not available');

            return;
        }
        $ch = curl_init($url);
        if ($ch === false) {
            error_log('akh_n8n_status_webhook: curl_init failed for URL ' . $url);

            return;
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);

        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            error_log('akh_n8n_status_webhook: cURL errno=' . $errno . ' error=' . $error);

            return;
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            error_log(
                'akh_n8n_status_webhook: HTTP status=' . $httpCode
                . ' response=' . mb_substr((string) $response, 0, 500)
            );

            return;
        }

        error_log('akh_n8n_status_webhook: OK HTTP status=' . $httpCode);
    } catch (Throwable $e) {
        error_log('akh_n8n_status_webhook: exception ' . $e->getMessage());
    }
}
