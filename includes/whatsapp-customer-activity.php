<?php

declare(strict_types=1);

require_once __DIR__ . '/tasks.php';
require_once __DIR__ . '/site-datetime.php';

function akh_wa_customer_activity_table_exists(): bool
{
    if (!function_exists('akh_db_is_pdo') || !akh_db_is_pdo()) {
        return false;
    }
    try {
        $name = akh_db()->query('SELECT DATABASE()');
        if ($name === false) {
            return false;
        }
        $schema = $name->fetchColumn();
        if (!is_string($schema) || $schema === '') {
            return false;
        }
        $st = akh_db()->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?'
        );
        $st->execute([$schema, 'whatsapp_customer_activity']);

        return (int) $st->fetchColumn() >= 1;
    } catch (\Throwable $e) {
        return false;
    }
}

/**
 * Log a customer interaction for a task (no-op if table missing or task invalid).
 */
function akh_wa_customer_activity_record(
    string $taskCode,
    string $clientUsername,
    string $source,
    string $activityKind = 'interaction'
): void {
    if (!akh_wa_customer_activity_table_exists()) {
        return;
    }
    $taskCode = akh_task_normalize_id(trim($taskCode));
    $clientUsername = strtolower(trim($clientUsername));
    $source = mb_substr(trim($source), 0, 32);
    $activityKind = mb_substr(trim($activityKind), 0, 32);
    if ($taskCode === '') {
        return;
    }
    try {
        $st = akh_db()->prepare(
            'INSERT INTO whatsapp_customer_activity (task_code, client_username, source, activity_kind)
             VALUES (?, ?, ?, ?)'
        );
        $st->execute([$taskCode, $clientUsername, $source, $activityKind]);
    } catch (\Throwable $e) {
        error_log('akh_wa_customer_activity_record: ' . $e->getMessage());
    }
}

/**
 * @param array<string, mixed> $task
 */
function akh_wa_customer_activity_record_for_task(array $task, string $source, string $activityKind = 'interaction'): void
{
    $code = akh_task_normalize_id((string) ($task['id'] ?? ''));
    $client = strtolower(trim((string) ($task['client_username'] ?? '')));
    if ($code === '') {
        return;
    }
    akh_wa_customer_activity_record($code, $client, $source, $activityKind);
}

/**
 * Best-effort last customer touch from portal thread + WhatsApp (when activity log is empty).
 *
 * @param array<string, mixed> $task
 */
function akh_wa_customer_activity_fallback_last_at(array $task): ?DateTimeImmutable
{
    $latest = null;
    foreach (akh_task_conversation_list($task) as $row) {
        if (!is_array($row)) {
            continue;
        }
        if (strtolower(trim((string) ($row['role'] ?? ''))) !== 'client') {
            continue;
        }
        $at = trim((string) ($row['at'] ?? ''));
        if ($at === '') {
            continue;
        }
        try {
            $dt = new DateTimeImmutable($at);
            if ($latest === null || $dt > $latest) {
                $latest = $dt;
            }
        } catch (\Throwable $e) {
            continue;
        }
    }

    $code = akh_task_normalize_id((string) ($task['id'] ?? ''));
    if ($code !== '') {
        require_once __DIR__ . '/whatsapp-messages.php';
        if (akh_wa_messages_table_exists()) {
            foreach (akh_wa_messages_list_for_task($code, 80) as $row) {
                if (!is_array($row) || !akh_wa_message_is_client_incoming($row)) {
                    continue;
                }
                $created = trim((string) ($row['created_at'] ?? ''));
                if ($created === '') {
                    continue;
                }
                try {
                    $dt = new DateTimeImmutable($created, akh_site_timezone());
                    if ($latest === null || $dt > $latest) {
                        $latest = $dt;
                    }
                } catch (\Throwable $e) {
                    continue;
                }
            }
        }
    }

    return $latest;
}

/**
 * Editor hint payload for a task (24h window).
 *
 * @param array<string, mixed> $task
 * @return array{
 *   enabled: bool,
 *   state: string,
 *   message: string,
 *   detail: string,
 *   last_at_iso: string,
 *   last_at_label: string
 * }
 */
function akh_wa_customer_activity_editor_status(array $task, int $windowHours = 24): array
{
    $empty = [
        'enabled' => false,
        'state' => 'unknown',
        'message' => '',
        'detail' => '',
        'last_at_iso' => '',
        'last_at_label' => '',
    ];
    if (!akh_wa_customer_activity_table_exists()) {
        return $empty;
    }

    $code = akh_task_normalize_id((string) ($task['id'] ?? ''));
    $client = strtolower(trim((string) ($task['client_username'] ?? '')));
    if ($code === '') {
        return $empty;
    }

    $windowHours = max(1, min(168, $windowHours));
    $tz = akh_site_timezone();
    $now = new DateTimeImmutable('now', $tz);
    $cutoff = $now->modify('-' . $windowHours . ' hours');

    $lastAt = null;
    try {
        $sql = 'SELECT MAX(created_at) AS last_at FROM whatsapp_customer_activity WHERE task_code = ?';
        $params = [$code];
        if ($client !== '') {
            $sql .= ' AND client_username = ?';
            $params[] = $client;
        }
        $st = akh_db()->prepare($sql);
        $st->execute($params);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (is_array($row) && !empty($row['last_at'])) {
            $lastAt = new DateTimeImmutable((string) $row['last_at'], $tz);
        }
    } catch (\Throwable $e) {
        error_log('akh_wa_customer_activity_editor_status: ' . $e->getMessage());
    }

    if ($lastAt === null) {
        $lastAt = akh_wa_customer_activity_fallback_last_at($task);
    }

    if ($lastAt === null) {
        return [
            'enabled' => true,
            'state' => 'idle',
            'message' => 'Customer has not been active for ' . $windowHours . ' hours',
            'detail' => 'No customer messages logged for this task yet.',
            'last_at_iso' => '',
            'last_at_label' => '',
        ];
    }

    $lastLabel = akh_format_datetime_site_short($lastAt->format('Y-m-d H:i:s'));
    $active = $lastAt >= $cutoff;
    if ($active) {
        return [
            'enabled' => true,
            'state' => 'active',
            'message' => 'Customer is active — engaged within the last ' . $windowHours . ' hours',
            'detail' => 'Last customer activity: ' . $lastLabel,
            'last_at_iso' => $lastAt->format(DateTimeInterface::ATOM),
            'last_at_label' => $lastLabel,
        ];
    }

    return [
        'enabled' => true,
        'state' => 'idle',
        'message' => 'Customer has not been active for ' . $windowHours . ' hours',
        'detail' => 'Last customer activity: ' . $lastLabel,
        'last_at_iso' => $lastAt->format(DateTimeInterface::ATOM),
        'last_at_label' => $lastLabel,
    ];
}

/**
 * @param array<string, mixed> $status From akh_wa_customer_activity_editor_status()
 */
function akh_render_editor_customer_activity_hint(array $status, string $taskId): void
{
    if (empty($status['enabled'])) {
        return;
    }
    $state = (string) ($status['state'] ?? 'unknown');
    $message = trim((string) ($status['message'] ?? ''));
    $detail = trim((string) ($status['detail'] ?? ''));
    if ($message === '') {
        return;
    }
    $tid = akh_task_normalize_id(trim($taskId));
    ?>
    <div
      class="edesk-customer-activity edesk-customer-activity--<?php echo h($state); ?>"
      role="status"
      aria-live="polite"
      data-edesk-customer-activity="1"
      data-task-id="<?php echo h($tid); ?>"
      data-activity-state="<?php echo h($state); ?>"
    >
      <span class="edesk-customer-activity__icon" aria-hidden="true"><?php echo $state === 'active' ? '●' : '○'; ?></span>
      <div class="edesk-customer-activity__copy">
        <p class="edesk-customer-activity__title"><?php echo h($message); ?></p>
        <?php if ($detail !== ''): ?>
          <p class="edesk-customer-activity__detail"><?php echo h($detail); ?></p>
        <?php endif; ?>
      </div>
    </div>
    <?php
}

/**
 * @param array<string, mixed> $task
 * @return array<string, mixed>
 */
function akh_wa_customer_activity_editor_status_for_task(array $task): array
{
    return akh_wa_customer_activity_editor_status($task, 24);
}
