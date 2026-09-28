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
    foreach (['task_code', 'whatsapp_task_code', 'studio_task_id', 'studio_task_code', 'task_id', 'task', 'code'] as $candidate) {
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
    $phoneCol = akh_wa_customer_activity_primary_phone_column();
    require_once __DIR__ . '/whatsapp-contacts.php';
    $rawPhones = akh_whatsapp_phones_for_task_code($taskCode);
    $resolvedPhone = $rawPhones[0] ?? '';
    if ($taskCol === null && ($phoneCol === null || $resolvedPhone === '')) {
        return;
    }
    try {
        $insertCols = [];
        $insertVals = [];
        if ($taskCol !== null) {
            $insertCols[] = $taskCol;
            $insertVals[] = $taskCode;
        }
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
        if ($phoneCol !== null && $resolvedPhone !== '') {
            $insertCols[] = $phoneCol;
            $insertVals[] = $resolvedPhone;
        }
        if ($insertCols === []) {
            return;
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
    foreach (['activity_at', 'last_active_at', 'last_seen', 'active_at', 'occurred_at', 'event_at', 'created_at', 'updated_at'] as $candidate) {
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

function akh_wa_customer_activity_digits(string $value): string
{
    return preg_replace('/\D+/', '', trim($value)) ?? '';
}

/**
 * @return list<string>
 */
function akh_wa_customer_activity_phone_digit_variants(string $phone): array
{
    $digits = akh_wa_customer_activity_digits($phone);
    if ($digits === '') {
        return [];
    }
    $out = [$digits];
    if (strlen($digits) > 10) {
        $tail = substr($digits, -10);
        if ($tail !== false && $tail !== '') {
            $out[] = $tail;
        }
    }
    if (strlen($digits) === 10) {
        $out[] = '91' . $digits;
    }
    if (str_starts_with($digits, '91') && strlen($digits) > 10) {
        $out[] = substr($digits, 2);
    }

    return array_values(array_unique(array_filter($out, static fn (string $v): bool => $v !== '')));
}

/**
 * Digit variants for the customer WhatsApp number (whatsapp_tasks + whatsapp_contacts).
 *
 * @param array<string, mixed> $task
 * @return list<string>
 */
function akh_wa_customer_activity_phones_for_task(array $task): array
{
    $phones = [];
    $code = akh_task_normalize_id((string) ($task['id'] ?? ''));
    if ($code === '') {
        return $phones;
    }

    require_once __DIR__ . '/whatsapp-contacts.php';
    foreach (akh_whatsapp_phones_for_task_code($code) as $raw) {
        foreach (akh_wa_customer_activity_phone_digit_variants($raw) as $variant) {
            if (!in_array($variant, $phones, true)) {
                $phones[] = $variant;
            }
        }
    }

    return $phones;
}

function akh_wa_customer_activity_phone_digits_match(string $stored, string $expected): bool
{
    $a = akh_wa_customer_activity_digits($stored);
    $b = akh_wa_customer_activity_digits($expected);
    if ($a === '' || $b === '') {
        return false;
    }
    if ($a === $b) {
        return true;
    }
    if (strlen($a) >= 10 && strlen($b) >= 10) {
        return substr($a, -10) === substr($b, -10);
    }

    return false;
}

/**
 * @param array<string, mixed> $row
 * @return list<string>
 */
function akh_wa_customer_activity_row_phone_values(array $row): array
{
    $values = [];
    foreach ($row as $key => $value) {
        if (!is_string($key)) {
            continue;
        }
        $k = strtolower($key);
        if (!preg_match('/phone|mobile|whatsapp|wa_/', $k)) {
            continue;
        }
        $v = trim((string) $value);
        if ($v !== '') {
            $values[] = $v;
        }
    }
    foreach (['client_username', 'customer_username', 'username', 'customer_id'] as $key) {
        if (!isset($row[$key])) {
            continue;
        }
        $v = trim((string) $row[$key]);
        if ($v !== '' && akh_wa_customer_activity_digits($v) !== '') {
            $values[] = $v;
        }
    }

    return $values;
}

/**
 * @param array<string, mixed> $row
 * @param list<string> $expectedDigits
 */
function akh_wa_customer_activity_row_matches_phone(array $row, array $expectedDigits): bool
{
    $storedPhones = akh_wa_customer_activity_row_phone_values($row);
    if ($storedPhones === []) {
        return true;
    }
    if ($expectedDigits === []) {
        return true;
    }
    foreach ($storedPhones as $stored) {
        foreach ($expectedDigits as $expected) {
            if (akh_wa_customer_activity_phone_digits_match($stored, $expected)) {
                return true;
            }
        }
    }

    return false;
}

/**
 * @param array<string, mixed> $row
 */
function akh_wa_customer_activity_row_is_customer_event(array $row): bool
{
    foreach (['actor', 'role', 'user_type', 'sender'] as $key) {
        if (!array_key_exists($key, $row)) {
            continue;
        }
        $value = strtolower(trim((string) $row[$key]));
        if ($value === '') {
            continue;
        }
        if (in_array($value, ['editor', 'system', 'staff', 'admin'], true)) {
            return false;
        }
    }

    return true;
}

/**
 * @param array<string, mixed> $row
 */
function akh_wa_customer_activity_row_latest_at(array $row): ?DateTimeImmutable
{
    $available = akh_wa_customer_activity_timestamp_columns();
    $preferred = [];
    foreach (['activity_at', 'last_active_at', 'created_at', 'last_seen', 'active_at', 'occurred_at', 'event_at'] as $col) {
        if (in_array($col, $available, true)) {
            $preferred[] = $col;
        }
    }
    if ($preferred === []) {
        $preferred = array_values(array_filter($available, static fn (string $c): bool => $c !== 'updated_at'));
    }
    if ($preferred === []) {
        $preferred = $available;
    }

    $latest = null;
    foreach ($preferred as $col) {
        if (!isset($row[$col]) || trim((string) $row[$col]) === '') {
            continue;
        }
        $dt = akh_wa_customer_activity_parse_dt((string) $row[$col]);
        if ($dt === null) {
            continue;
        }
        if ($latest === null || $dt->getTimestamp() > $latest->getTimestamp()) {
            $latest = $dt;
        }
    }

    return $latest;
}

/**
 * Load activity rows for this customer — whatsapp_customer_activity is matched by phone only.
 *
 * @param list<string> $phoneDigits
 * @return list<array<string, mixed>>
 */
function akh_wa_customer_activity_fetch_rows_by_phone(array $phoneDigits): array
{
    if ($phoneDigits === []) {
        return [];
    }

    $phoneCols = akh_wa_customer_activity_phone_columns();
    if ($phoneCols === []) {
        $phoneCols = ['phone'];
    }
    $phoneOr = [];
    $params = [];
    foreach ($phoneCols as $phoneCol) {
        if (!akh_wa_customer_activity_column_exists($phoneCol)) {
            continue;
        }
        $pred = akh_wa_customer_activity_sql_phone_predicate($phoneCol, $phoneDigits);
        if ($pred['sql'] === '0') {
            continue;
        }
        $phoneOr[] = $pred['sql'];
        foreach ($pred['params'] as $p) {
            $params[] = $p;
        }
    }
    if ($phoneOr === []) {
        return [];
    }

    $tsCols = akh_wa_customer_activity_timestamp_columns();
    $orderCol = akh_wa_customer_activity_column_exists('id')
        ? 'id'
        : ($tsCols[0] ?? 'id');
    $sql = 'SELECT * FROM whatsapp_customer_activity WHERE (' . implode(' OR ', $phoneOr) . ') ORDER BY `' . $orderCol . '` DESC LIMIT 300';

    try {
        $st = akh_db()->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    } catch (\Throwable $e) {
        error_log('akh_wa_customer_activity_fetch_rows_by_phone: ' . $e->getMessage());

        return [];
    }
}

function akh_wa_customer_activity_primary_phone_column(): ?string
{
    foreach (['phone', 'customer_phone', 'phone_number', 'mobile_number', 'whatsapp_phone', 'wa_phone', 'mobile'] as $candidate) {
        if (akh_wa_customer_activity_column_exists($candidate)) {
            return $candidate;
        }
    }

    return null;
}

/**
 * @return list<string>
 */
function akh_wa_customer_activity_phone_columns(): array
{
    $out = [];
    foreach (['phone', 'customer_phone', 'phone_number', 'mobile_number', 'whatsapp_phone', 'wa_phone', 'mobile'] as $candidate) {
        if (akh_wa_customer_activity_column_exists($candidate)) {
            $out[] = $candidate;
        }
    }

    return $out;
}

/**
 * @param list<string> $digitVariants
 * @return array{sql: string, params: list<string>}
 */
function akh_wa_customer_activity_sql_phone_predicate(string $column, array $digitVariants): array
{
    if ($digitVariants === []) {
        return ['0', []];
    }
    $normalized = 'REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(`' . $column . '`, \'\'), \'+\', \'\'), \' \', \'\'), \'-\', \'\'), \'(\', \'\'), \')\', \'\')';
    $parts = [];
    $params = [];
    foreach ($digitVariants as $variant) {
        $parts[] = $normalized . ' = ?';
        $params[] = $variant;
        if (strlen($variant) >= 10) {
            $tail = substr($variant, -10);
            if ($tail !== false) {
                $parts[] = 'RIGHT(' . $normalized . ', 10) = ?';
                $params[] = $tail;
            }
        }
    }

    return ['(' . implode(' OR ', $parts) . ')', $params];
}

/**
 * SQL expression: digits-only phone from a column name (for parameterized WHERE).
 */
function akh_wa_customer_activity_sql_digits_expr(string $column): string
{
    return 'REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(`' . $column . '`, \'\'), \'+\', \'\'), \' \', \'\'), \'-\', \'\'), \'(\', \'\'), \')\', \'\')';
}

function akh_wa_customer_activity_editor_inbound_columns_ready(): bool
{
    return akh_wa_customer_activity_column_exists('phone')
        && akh_wa_customer_activity_column_exists('activity_type')
        && akh_wa_customer_activity_column_exists('whatsapp_timestamp');
}

/**
 * Authoritative WhatsApp number for a studio task (whatsapp_tasks.phone only).
 */
function akh_wa_customer_activity_task_phone_from_wa_tasks(string $taskCode): string
{
    require_once __DIR__ . '/whatsapp-tasks.php';
    $code = akh_task_normalize_id(trim($taskCode));
    if ($code === '') {
        return '';
    }
    $wa = akh_wa_task_by_code($code);
    if (!is_array($wa)) {
        return '';
    }

    return trim((string) ($wa['phone'] ?? ''));
}

/**
 * Latest inbound customer WhatsApp touch for a task phone (activity table only).
 *
 * @return array{last_at: ?DateTimeImmutable, match_count: int}
 */
function akh_wa_customer_activity_last_inbound_whatsapp_for_phone(string $taskPhoneRaw): array
{
    $empty = ['last_at' => null, 'match_count' => 0];
    if (!akh_wa_customer_activity_table_exists() || !akh_wa_customer_activity_editor_inbound_columns_ready()) {
        return $empty;
    }

    $digits = akh_wa_customer_activity_digits($taskPhoneRaw);
    if ($digits === '') {
        return $empty;
    }

    $phoneExpr = akh_wa_customer_activity_sql_digits_expr('phone');
    $predicates = [$phoneExpr . ' = ?'];
    $params = [$digits];
    if (strlen($digits) >= 10) {
        $tail = substr($digits, -10);
        if ($tail !== false && $tail !== '') {
            $predicates[] = 'RIGHT(' . $phoneExpr . ', 10) = ?';
            $params[] = $tail;
        }
    }

    $sql = 'SELECT MAX(`whatsapp_timestamp`) AS last_activity, COUNT(*) AS row_count
            FROM whatsapp_customer_activity
            WHERE `activity_type` = ?
              AND (' . implode(' OR ', $predicates) . ')';
    $params = array_merge(['inbound_message'], $params);

    try {
        $st = akh_db()->prepare($sql);
        $st->execute($params);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return $empty;
        }
        $matchCount = (int) ($row['row_count'] ?? 0);
        $rawLast = $row['last_activity'] ?? null;
        if ($matchCount < 1 || $rawLast === null || trim((string) $rawLast) === '') {
            return ['last_at' => null, 'match_count' => $matchCount];
        }
        $lastAt = akh_wa_customer_activity_parse_dt((string) $rawLast);
        if ($lastAt === null) {
            return ['last_at' => null, 'match_count' => $matchCount];
        }

        return ['last_at' => $lastAt, 'match_count' => $matchCount];
    } catch (\Throwable $e) {
        error_log('akh_wa_customer_activity_last_inbound_whatsapp_for_phone: ' . $e->getMessage());

        return $empty;
    }
}

/**
 * @param array<string, mixed> $debug
 */
function akh_wa_customer_activity_log_editor_status_debug(string $taskCode, array $debug): void
{
    $code = akh_task_normalize_id(trim($taskCode));
    if ($code === '' || !akh_task_ids_match($code, 'AS0237')) {
        return;
    }
    error_log('akh_wa_customer_activity editor_status AS0237: ' . json_encode($debug, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
}

/**
 * Last customer activity from whatsapp_customer_activity (legacy; not used for editor 24h banner).
 *
 * @param array<string, mixed> $task
 */
function akh_wa_customer_activity_last_at_from_db(array $task): ?DateTimeImmutable
{
    if (!akh_wa_customer_activity_table_exists()) {
        return null;
    }
    if (akh_wa_customer_activity_timestamp_columns() === []) {
        return null;
    }

    $phoneDigits = akh_wa_customer_activity_phones_for_task($task);
    if ($phoneDigits === []) {
        return null;
    }

    $rows = akh_wa_customer_activity_fetch_rows_by_phone($phoneDigits);

    $lastAt = null;
    foreach ($rows as $row) {
        if (!is_array($row) || !akh_wa_customer_activity_row_is_customer_event($row)) {
            continue;
        }
        if (!akh_wa_customer_activity_row_matches_phone($row, $phoneDigits)) {
            continue;
        }
        $rowAt = akh_wa_customer_activity_row_latest_at($row);
        if ($rowAt === null) {
            continue;
        }
        if ($lastAt === null || $rowAt->getTimestamp() > $lastAt->getTimestamp()) {
            $lastAt = $rowAt;
        }
    }

    return $lastAt;
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

    $templateHint = 'Send an approved WhatsApp Business template to contact this customer.';

    if (!akh_wa_customer_activity_table_exists() || !akh_wa_customer_activity_editor_inbound_columns_ready()) {
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

    $taskPhone = akh_wa_customer_activity_task_phone_from_wa_tasks($code);
    $normalizedPhone = akh_wa_customer_activity_digits($taskPhone);

    $inbound = akh_wa_customer_activity_last_inbound_whatsapp_for_phone($taskPhone);
    $lastAt = $inbound['last_at'];
    $matchCount = $inbound['match_count'];

    $active = $lastAt !== null && $lastAt->getTimestamp() >= $cutoff->getTimestamp();
    $state = $active ? 'active' : 'idle';

    akh_wa_customer_activity_log_editor_status_debug($code, [
        'task_code' => $code,
        'task_phone' => $taskPhone,
        'normalized_phone' => $normalizedPhone,
        'matching_activity_rows' => $matchCount,
        'latest_whatsapp_timestamp' => $lastAt !== null ? $lastAt->format('Y-m-d H:i:s') : null,
        'current_time' => $now->format('Y-m-d H:i:s'),
        'current_timezone' => $tz->getName(),
        'cutoff_24h' => $cutoff->format('Y-m-d H:i:s'),
        'active' => $active,
        'state' => $state,
    ]);

    if ($lastAt === null) {
        return [
            'enabled' => true,
            'state' => 'idle',
            'message' => 'No recent inbound WhatsApp activity from this customer',
            'detail' => $templateHint,
            'last_at_iso' => '',
            'last_at_label' => '',
        ];
    }

    $lastLabel = akh_format_datetime_site_short($lastAt->format('Y-m-d H:i:s'));
    if ($active) {
        return [
            'enabled' => true,
            'state' => 'active',
            'message' => 'Customer is active on WhatsApp — last inbound message within the last ' . $windowHours . ' hours',
            'detail' => '',
            'last_at_iso' => $lastAt->format(DateTimeInterface::ATOM),
            'last_at_label' => $lastLabel,
        ];
    }

    return [
        'enabled' => true,
        'state' => 'idle',
        'message' => 'No inbound WhatsApp activity from this customer in the last ' . $windowHours . ' hours',
        'detail' => $templateHint,
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
