<?php

declare(strict_types=1);

/**
 * Idempotent MySQL schema patches (shared by CLI and admin web UI).
 */

/**
 * @return non-empty-string|null
 */
function akh_ensure_db_current_schema(PDO $pdo): ?string
{
    $name = $pdo->query('SELECT DATABASE()');
    if ($name === false) {
        return null;
    }
    $db = $name->fetchColumn();
    if (!is_string($db) || $db === '') {
        return null;
    }

    return $db;
}

function akh_ensure_db_column_exists(PDO $pdo, string $schema, string $table, string $column): bool
{
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $st->execute([$schema, $table, $column]);
    $n = $st->fetchColumn();

    return (int) $n >= 1;
}

function akh_ensure_db_index_exists(PDO $pdo, string $schema, string $table, string $indexName): bool
{
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?'
    );
    $st->execute([$schema, $table, $indexName]);
    $n = $st->fetchColumn();

    return (int) $n >= 1;
}

function akh_ensure_db_table_exists(PDO $pdo, string $schema, string $table): bool
{
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?'
    );
    $st->execute([$schema, $table]);
    $n = $st->fetchColumn();

    return (int) $n >= 1;
}

function akh_db_schema_web_enabled(): bool
{
    return !defined('AKH_DB_SCHEMA_WEB_ENABLED') || AKH_DB_SCHEMA_WEB_ENABLED;
}

/**
 * @return list<array{table: string, ok: bool}>
 */
function akh_ensure_database_schema_checklist(PDO $pdo): array
{
    $schema = akh_ensure_db_current_schema($pdo);
    if ($schema === null) {
        return [];
    }

    $tables = [
        'users',
        'task_updates',
        'task_status_changes',
        'whatsapp_tasks',
        'whatsapp_messages',
        'whatsapp_customer_activity',
        'task_notification_events',
        'meeting_requests',
        'whatsapp_preview_messages',
        'invoices',
        'completed_tasks',
    ];
    $out = [];
    foreach ($tables as $table) {
        $out[] = [
            'table' => $table,
            'ok' => akh_ensure_db_table_exists($pdo, $schema, $table),
        ];
    }

    return $out;
}

/**
 * @param array{migrate_customers?: bool, migrate_editors?: bool} $options
 * @return array{ok: bool, lines: list<string>, error: ?string}
 */
function akh_ensure_database_apply_patches(PDO $pdo, array $options = []): array
{
    $migrateCustomers = (bool) ($options['migrate_customers'] ?? false);
    $migrateEditors = (bool) ($options['migrate_editors'] ?? false);

    /** @var list<string> */
    $lines = [];
    $log = static function (string $line) use (&$lines): void {
        $lines[] = $line;
    };

    $schema = akh_ensure_db_current_schema($pdo);
    if ($schema === null) {
        return [
            'ok' => false,
            'lines' => $lines,
            'error' => 'Could not read current database from the connection. Check AKH_DB_DSN includes dbname=...',
        ];
    }

    $log("Using database: {$schema}");

    if (!akh_ensure_db_table_exists($pdo, $schema, 'users')) {
        return [
            'ok' => false,
            'lines' => $lines,
            'error' => 'Table `users` is missing. Import sql/schema.sql in phpMyAdmin first.',
        ];
    }

    try {
        if (!akh_ensure_db_column_exists($pdo, $schema, 'users', 'email')) {
            $log('Adding column users.email ...');
            $pdo->exec(
                'ALTER TABLE users ADD COLUMN email VARCHAR(120) NULL DEFAULT NULL AFTER password_hash'
            );
        }

        if (!akh_ensure_db_index_exists($pdo, $schema, 'users', 'ix_users_customer_email')) {
            $log('Adding index ix_users_customer_email ...');
            $pdo->exec('ALTER TABLE users ADD KEY ix_users_customer_email (role, email)');
        }

        if (akh_ensure_db_table_exists($pdo, $schema, 'contact_enquiries')
            && !akh_ensure_db_column_exists($pdo, $schema, 'contact_enquiries', 'email')) {
            $log('Adding column contact_enquiries.email ...');
            $pdo->exec(
                "ALTER TABLE contact_enquiries ADD COLUMN email VARCHAR(120) NOT NULL DEFAULT '' AFTER phone"
            );
        }

        $migrationPath = AKH_ROOT . '/sql/migrations/004_whatsapp_task_sync.sql';
        if (is_file($migrationPath)) {
            $sql = file_get_contents($migrationPath);
            if (is_string($sql) && trim($sql) !== '') {
                if (!akh_ensure_db_table_exists($pdo, $schema, 'task_updates')) {
                    $log('Applying sql/migrations/004_whatsapp_task_sync.sql ...');
                    $pdo->exec($sql);
                }
            }
        }

        $migrationNotify = AKH_ROOT . '/sql/migrations/007_task_notification_event_kinds.sql';
        if (is_file($migrationNotify) && akh_ensure_db_table_exists($pdo, $schema, 'task_notification_events')) {
            $col = $pdo->query(
                "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = " . $pdo->quote($schema) . " AND TABLE_NAME = 'task_notification_events' AND COLUMN_NAME = 'event_kind'"
            );
            $type = $col !== false ? $col->fetchColumn() : false;
            if (is_string($type) && !str_contains($type, 'client_update')) {
                $log('Applying sql/migrations/007_task_notification_event_kinds.sql ...');
                $sqlNotify = file_get_contents($migrationNotify);
                if (is_string($sqlNotify) && trim($sqlNotify) !== '') {
                    $pdo->exec($sqlNotify);
                }
            }
        }

        if (akh_ensure_db_table_exists($pdo, $schema, 'task_notification_events')
            && !akh_ensure_db_column_exists($pdo, $schema, 'task_notification_events', 'status')) {
            $log('Adding column task_notification_events.status ...');
            $pdo->exec(
                "ALTER TABLE task_notification_events ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'pending' AFTER body"
            );
            $pdo->exec(
                'ALTER TABLE task_notification_events ADD KEY ix_task_notification_status (status)'
            );
        }

        $migrationNotifyPreview = AKH_ROOT . '/sql/migrations/013_task_notification_preview_approved.sql';
        if (is_file($migrationNotifyPreview) && akh_ensure_db_table_exists($pdo, $schema, 'task_notification_events')) {
            $col = $pdo->query(
                "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = " . $pdo->quote($schema) . " AND TABLE_NAME = 'task_notification_events' AND COLUMN_NAME = 'event_kind'"
            );
            $type = $col !== false ? strtolower((string) $col->fetchColumn()) : '';
            if ($type !== '' && !str_contains($type, 'varchar')) {
                $log('Applying sql/migrations/013_task_notification_preview_approved.sql ...');
                $sqlPreview = file_get_contents($migrationNotifyPreview);
                if (is_string($sqlPreview) && trim($sqlPreview) !== '') {
                    $pdo->exec($sqlPreview);
                }
            }
        }

        $migrationWaMessages = AKH_ROOT . '/sql/migrations/010_whatsapp_messages.sql';
        if (is_file($migrationWaMessages) && !akh_ensure_db_table_exists($pdo, $schema, 'whatsapp_messages')) {
            $log('Applying sql/migrations/010_whatsapp_messages.sql ...');
            $sqlWaMessages = file_get_contents($migrationWaMessages);
            if (is_string($sqlWaMessages) && trim($sqlWaMessages) !== '') {
                $pdo->exec($sqlWaMessages);
            }
        }

        if (akh_ensure_db_table_exists($pdo, $schema, 'whatsapp_messages')) {
            if (!akh_ensure_db_column_exists($pdo, $schema, 'whatsapp_messages', 'customer_name')) {
                $log('Adding column whatsapp_messages.customer_name ...');
                $pdo->exec(
                    'ALTER TABLE whatsapp_messages ADD COLUMN customer_name VARCHAR(255) NULL DEFAULT NULL AFTER sender'
                );
            }
            if (!akh_ensure_db_column_exists($pdo, $schema, 'whatsapp_messages', 'editor_name')) {
                $log('Adding column whatsapp_messages.editor_name ...');
                $pdo->exec(
                    'ALTER TABLE whatsapp_messages ADD COLUMN editor_name VARCHAR(255) NULL DEFAULT NULL AFTER customer_name'
                );
            }
            if (!akh_ensure_db_column_exists($pdo, $schema, 'whatsapp_messages', 'media_url')) {
                $log('Adding column whatsapp_messages.media_url ...');
                $pdo->exec(
                    'ALTER TABLE whatsapp_messages ADD COLUMN media_url VARCHAR(512) NULL DEFAULT NULL AFTER message'
                );
            }
            if (!akh_ensure_db_column_exists($pdo, $schema, 'whatsapp_messages', 'filename')) {
                $log('Adding column whatsapp_messages.filename ...');
                $pdo->exec(
                    'ALTER TABLE whatsapp_messages ADD COLUMN filename VARCHAR(255) NULL DEFAULT NULL AFTER media_url'
                );
            }
            $dirCol = $pdo->query(
                "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = " . $pdo->quote($schema) . " AND TABLE_NAME = 'whatsapp_messages' AND COLUMN_NAME = 'direction'"
            );
            $dirType = $dirCol !== false ? $dirCol->fetchColumn() : false;
            if (is_string($dirType) && !str_contains($dirType, 'outbound')) {
                $log('Adding whatsapp_messages.direction value outbound ...');
                $pdo->exec(
                    "ALTER TABLE whatsapp_messages MODIFY COLUMN direction ENUM('incoming', 'outgoing', 'outbound') NULL DEFAULT NULL"
                );
            }
        }

        $migrationInvoices = AKH_ROOT . '/sql/migrations/014_invoices_and_completed_tasks.sql';
        if (is_file($migrationInvoices) && !akh_ensure_db_table_exists($pdo, $schema, 'invoices')) {
            $log('Applying sql/migrations/014_invoices_and_completed_tasks.sql ...');
            $sqlInv = file_get_contents($migrationInvoices);
            if (is_string($sqlInv) && trim($sqlInv) !== '') {
                $pdo->exec($sqlInv);
            }
        }

        $migrationWaCustomerActivity = AKH_ROOT . '/sql/migrations/015_whatsapp_customer_activity.sql';
        if (is_file($migrationWaCustomerActivity) && !akh_ensure_db_table_exists($pdo, $schema, 'whatsapp_customer_activity')) {
            $log('Applying sql/migrations/015_whatsapp_customer_activity.sql ...');
            $sqlAct = file_get_contents($migrationWaCustomerActivity);
            if (is_string($sqlAct) && trim($sqlAct) !== '') {
                $pdo->exec($sqlAct);
            }
        }

        $migrationStatusLog = AKH_ROOT . '/sql/migrations/016_task_status_changes.sql';
        if (is_file($migrationStatusLog) && !akh_ensure_db_table_exists($pdo, $schema, 'task_status_changes')) {
            $log('Applying sql/migrations/016_task_status_changes.sql ...');
            $sqlLog = file_get_contents($migrationStatusLog);
            if (is_string($sqlLog) && trim($sqlLog) !== '') {
                $pdo->exec($sqlLog);
            }
        }

        $migrationWaPreview = AKH_ROOT . '/sql/migrations/017_whatsapp_preview_messages.sql';
        if (is_file($migrationWaPreview) && !akh_ensure_db_table_exists($pdo, $schema, 'whatsapp_preview_messages')) {
            $log('Applying sql/migrations/017_whatsapp_preview_messages.sql ...');
            $sqlPreview = file_get_contents($migrationWaPreview);
            if (is_string($sqlPreview) && trim($sqlPreview) !== '') {
                $pdo->exec($sqlPreview);
            }
        }

        require_once AKH_ROOT . '/includes/task-status-log.php';
        $backfilled = akh_task_status_log_backfill_from_task_updates();
        if ($backfilled > 0) {
            $log("Backfilled {$backfilled} rows into task_status_changes from task_updates.");
        }
        $repaired = akh_task_status_log_repair_reverted_mislogged_as_closed();
        if ($repaired > 0) {
            $log("Repaired {$repaired} task_status_changes rows (closed → reverted for returns).");
        }

        require_once AKH_ROOT . '/includes/db-schema-patches.php';
        akh_db_apply_runtime_patches($pdo);
        $log('Applied runtime schema patches (notifications, meetings).');

        $log('Schema patches are up to date.');

        if ($migrateEditors) {
            akh_ensure_database_migrate_editors_from_files($pdo, $log);
        }

        if ($migrateCustomers) {
            akh_ensure_database_migrate_customers_from_files($pdo, $log);
        }

        if (!$migrateCustomers && !$migrateEditors) {
            $log('Skipped file → DB user imports (optional; CLI flags or checkboxes on web UI).');
        }
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'lines' => $lines,
            'error' => $e->getMessage(),
        ];
    }

    return ['ok' => true, 'lines' => $lines, 'error' => null];
}

/**
 * @param callable(string): void $log
 */
function akh_ensure_database_migrate_editors_from_files(PDO $pdo, callable $log): void
{
    $editorsPath = AKH_ROOT . '/data/editors.php';
    if (!is_file($editorsPath)) {
        $log('No data/editors.php — nothing to import for editors.');

        return;
    }
    /** @var mixed $edAccounts */
    $edAccounts = require $editorsPath;
    if (!is_array($edAccounts) || $edAccounts === []) {
        $log('data/editors.php is empty — no editor rows imported.');

        return;
    }
    $insEd = $pdo->prepare(
        'INSERT INTO users (role, username, password_hash, email) VALUES (\'editor\', ?, ?, NULL)
         ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash)'
    );
    $edN = 0;
    foreach ($edAccounts as $username => $hash) {
        if (!is_string($username) || !is_string($hash)) {
            continue;
        }
        $u = strtolower(trim($username));
        if ($u === '' || $hash === '') {
            continue;
        }
        $insEd->execute([$u, $hash]);
        ++$edN;
    }
    $log("Imported/merged {$edN} editor row(s) from data/editors.php into users (role=editor).");
}

/**
 * @param callable(string): void $log
 */
function akh_ensure_database_migrate_customers_from_files(PDO $pdo, callable $log): void
{
    $customersPath = AKH_ROOT . '/data/customers.php';
    if (!is_file($customersPath)) {
        $log('No data/customers.php — nothing to import.');

        return;
    }

    /** @var mixed $accounts */
    $accounts = require $customersPath;
    if (!is_array($accounts) || $accounts === []) {
        $log('data/customers.php is empty — nothing to import.');

        return;
    }

    $emailPath = AKH_ROOT . '/data/customer-emails.json';
    $emailMap = [];
    if (is_file($emailPath)) {
        $raw = file_get_contents($emailPath);
        if ($raw !== false && $raw !== '') {
            $j = json_decode($raw, true);
            if (is_array($j)) {
                foreach ($j as $k => $v) {
                    if (!is_string($k) || !is_string($v)) {
                        continue;
                    }
                    $ku = strtolower(trim($k));
                    if ($ku !== '') {
                        $emailMap[$ku] = strtolower(trim($v));
                    }
                }
            }
        }
    }

    $ins = $pdo->prepare(
        'INSERT INTO users (role, username, password_hash, email) VALUES (\'customer\', ?, ?, ?)
         ON DUPLICATE KEY UPDATE
           password_hash = VALUES(password_hash),
           email = IFNULL(NULLIF(VALUES(email), \'\'), users.email)'
    );

    $imported = 0;
    foreach ($accounts as $username => $hash) {
        if (!is_string($username) || !is_string($hash)) {
            continue;
        }
        $u = strtolower(trim($username));
        if ($u === '' || $hash === '') {
            continue;
        }
        $em = $emailMap[$u] ?? '';
        if ($em !== '' && !filter_var($em, FILTER_VALIDATE_EMAIL)) {
            $em = '';
        }
        if (mb_strlen($em) > 120) {
            $em = '';
        }
        $ins->execute([$u, $hash, $em !== '' ? $em : null]);
        ++$imported;
    }

    $log("Imported/merged {$imported} customer row(s) from data/customers.php into users (role=customer).");
    $log('You can archive data/customers.php after verifying logins.');
}
