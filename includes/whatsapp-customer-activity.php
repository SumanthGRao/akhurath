<?php

declare(strict_types=1);

require_once __DIR__ . '/tasks.php';
require_once __DIR__ . '/site-datetime.php';

/** @var array<string, string|null> */
$akhWaCustomerActivityColumnCache = [
    'task' => null,
    'client' => null,
    'resolved' => '0',
];

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

function akh_wa_customer_activity_column_exists(string $column): bool
{
    if (!akh_wa_customer_activity_table_exists()) {
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
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $st->execute([$schema, 'whatsapp_customer_activity', $column]);

        return (int) $st->fetchColumn() >= 1;
    } catch (\Throwable $e) {
        return false;
    }
}

/**
 * @return array{task: string|null, client: string|null}
 */
function akh_wa_customer_activity_columns(): array
{
    global $akhWaCustomerActivityColumnCache;
    if (($akhWaCustomerActivityColumnCache['resolved'] ?? '0') === '1') {
        return [
            'task' => $akhWaCustomerActivityColumnCache['task'],
            'client' => $akhWaCustomerActivityColumnCache['client'],
        ];
    }
    $taskCol = null;
    foreach (['task_code', 'studio_task_id', 'task_id'] as $candidate) {
        if (akh_wa_customer_activity_column_exists($candidate)) {
            $taskCol = $candidate;
            break;
        }
    }
    $clientCol = null;
    foreach (['client_username', 'customer_username', 'username', 'customer_id'] as $candidate) {
        if (akh_wa_customer_activity_column_exists($candidate)) {
            $clientCol = $candidate;
            break;
        }
    }
    $akhWaCustomerActivityColumnCache['task'] = $taskCol;
    $akhWaCustomerActivityColumnCache['client'] = $clientCol;
    $akhWaCustomerActivityColumnCache['resolved'] = '1';

    return ['task' => $taskCol, 'client' => $clientCol];
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
    $cols = akh_wa_customer_activity_columns();
    $taskCol = $cols['task'];
    if ($taskCol === null) {
        return;
    }
    try {
        $insertCols = [$taskCol];
        $insertVals = [$taskCode];
        $clientCol = $cols['client'];
        if ($clientCol !== null) {
            $insertCols[] = $clientCol;
            $insertVals[] = $clientUsername;
        }
        if (akh_wa_customer_activity_column_exists('source')) {
            $insertCols[] = 'source';
            $insertVals[] = $source;
        }
        if (akh_wa_customer_activity_column_exists('activity_kind')) {
            $insertCols[] = 'activity_kind';
            $insertVals[] = $activityKind;
        }
        $placeholders = implode(', ', array_fill(0, count($insertCols), '?'));
        $sql = 'INSERT INTO whatsapp_customer_activity (' . implode(', ', $insertCols) . ') VALUES (' . $placeholders . ')';
        $st = akh_db()->prepare($sql);
        $st->execute($insertVals);
    } catch (\Throwable $e) {
        error_log('akh_wa_customer_activity_record: ' . $e->getMessage());
    }
}

/**
 * @return list<string>
 */
function akh_wa_customer_activity_timestamp_columns(): array
{
    $out = [];
    foreach (['activity_at', 'last_active_at', 'updated_at', 'created_at'] as $candidate) {
        if (akh_wa_customer_activity_column_exists($candidate)) {
            $out[] = $candidate;
        }
    }

    return $out;
}

function akh_wa_customer_activity_parse_dt(string $raw): ?DateTimeImmutable
{
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }
    $tz = akh_site_timezone();
    try {
        if (preg_match('/Z$|[+-]\d{2}:?\d{2}$/', $raw) === 1) {
            return new DateTimeImmutable($raw);
        }

        return new DateTimeImmutable($raw, $tz);
    } catch (\Throwable $e) {
        return null;
    }
}

/**
 * @param array<string, mixed> $task
 * @return list<string>
 */
function akh_wa_customer_activity_client_identifiers(array $task): array
{
    $ids = [];
    $add = static function (string $v) use (&$ids): void {
        $v = trim($v);
        if ($v === '') {
            return;
        }
        $lower = strtolower($v);
        if (!in_array($lower, $ids, true)) {
            $ids[] = $lower;
        }
        $digits = preg_replace('/\D+/', '', $v) ?? '';
        if ($digits !== '' && strlen($digits) >= 8 && !in_array($digits, $ids, true)) {
            $ids[] = $digits;
        }
    };

    $add((string) ($task['client_username'] ?? ''));
    $code = akh_task_normalize_id((string) ($task['id'] ?? ''));
    if ($code === '') {
        return $ids;
    }

    require_once __DIR__ . '/whatsapp-messages.php';
    require_once __DIR__ . '/whatsapp-tasks.php';

    $add(akh_wa_message_phone_for_task($code));
    $wa = akh_wa_task_by_code($code);
    if (is_array($wa)) {
        $add((string) ($wa['phone'] ?? ''));
        $add((string) ($wa['customer_id'] ?? ''));
        $add((string) ($wa['customer_name'] ?? ''));
    }

    return $ids;
}

/**
 * Last customer activity for this task from whatsapp_customer_activity only.
 *
 * @param array<string, mixed> $task
 */
function akh_wa_customer_activity_last_at_from_db(array $task): ?DateTimeImmutable
{
    if (!akh_wa_customer_activity_table_exists()) {
        return null;
    }
    $cols = akh_wa_customer_activity_columns();
    $taskCol = $cols['task'];
    $tsCols = akh_wa_customer_activity_timestamp_columns();
    if ($taskCol === null || $tsCols === []) {
        return null;
    }

    $taskId = trim((string) ($task['id'] ?? ''));
    $variants = akh_task_id_match_variants($taskId);
    if ($variants === []) {
        return null;
    }

    $coalesce = [];
    foreach ($tsCols as $c) {
        $coalesce[] = '`' . $c . '`';
    }
    $maxExpr = count($coalesce) === 1
        ? 'MAX(' . $coalesce[0] . ')'
        : 'MAX(COALESCE(' . implode(', ', $coalesce) . '))';

    $taskPlaceholders = implode(',', array_fill(0, count($variants), '?'));
    $sql = 'SELECT ' . $maxExpr . ' AS last_at FROM whatsapp_customer_activity WHERE TRIM(`' . $taskCol . '`) IN (' . $taskPlaceholders . ')';
    $params = $variants;

    $clientIds = akh_wa_customer_activity_client_identifiers($task);
    $clientPredicates = [];
    $clientCol = $cols['client'];
    if ($clientCol !== null) {
        $clientPredicates[] = 'TRIM(COALESCE(`' . $clientCol . '`, \'\')) = \'\'';
        foreach ($clientIds as $id) {
            $clientPredicates[] = 'LOWER(TRIM(`' . $clientCol . '`)) = ?';
            $params[] = $id;
        }
    }
    if (akh_wa_customer_activity_column_exists('phone') && ($clientCol === null || $clientCol !== 'phone')) {
        $clientPredicates[] = 'TRIM(COALESCE(`phone`, \'\')) = \'\'';
        foreach ($clientIds as $id) {
            $clientPredicates[] = 'LOWER(TRIM(`phone`)) = ?';
            $params[] = $id;
        }
    }
    if ($clientPredicates !== []) {
        $sql .= ' AND (' . implode(' OR ', $clientPredicates) . ')';
    }

    foreach (['actor', 'role', 'user_type', 'sender'] as $actorCol) {
        if (!akh_wa_customer_activity_column_exists($actorCol)) {
            continue;
        }
        $sql .= ' AND (
            `' . $actorCol . '` IS NULL
            OR TRIM(`' . $actorCol . '`) = \'\'
            OR LOWER(TRIM(`' . $actorCol . '`)) NOT IN (\'editor\', \'system\', \'staff\', \'admin\')
        )';
        break;
    }

    try {
        $st = akh_db()->prepare($sql);
        $st->execute($params);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || empty($row['last_at'])) {
            return null;
        }

        return akh_wa_customer_activity_parse_dt((string) $row['last_at']);
    } catch (\Throwable $e) {
        error_log('akh_wa_customer_activity_last_at_from_db: ' . $e->getMessage());

        return null;
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
    $disabled = [
        'enabled' => false,
        'state' => 'unknown',
        'message' => '',
        'detail' => '',
        'last_at_iso' => '',
        'last_at_label' => '',
    ];

    if (!akh_wa_customer_activity_table_exists()) {
        return $disabled;
    }

    $code = akh_task_normalize_id((string) ($task['id'] ?? ''));
    if ($code === '') {
        return $disabled;
    }

    $windowHours = max(1, min(168, $windowHours));
    $tz = akh_site_timezone();
    $now = new DateTimeImmutable('now', $tz);
    $cutoff = $now->modify('-' . $windowHours . ' hours');

    $lastAt = akh_wa_customer_activity_last_at_from_db($task);

    if ($lastAt === null) {
        return [
            'enabled' => true,
            'state' => 'idle',
            'message' => 'Customer has not been active for ' . $windowHours . ' hours',
            'detail' => 'No rows in whatsapp_customer_activity for this customer on this task.',
            'last_at_iso' => '',
            'last_at_label' => '',
        ];
    }

    $lastLabel = akh_format_datetime_site_short($lastAt->format('Y-m-d H:i:s'));
    $active = $lastAt->getTimestamp() >= $cutoff->getTimestamp();
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
