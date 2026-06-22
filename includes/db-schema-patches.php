<?php

declare(strict_types=1);

/**
 * Idempotent schema fixes for shared hosting (no CLI ensure-database.php).
 * Runs at most once per PHP process after the DB connection is opened.
 */
function akh_db_apply_runtime_patches(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $schema = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        if ($schema === '') {
            return;
        }

        $tbl = $pdo->query("SHOW TABLES LIKE 'task_notification_events'");
        if ($tbl === false || $tbl->fetch(PDO::FETCH_NUM) === false) {
            return;
        }

        $col = $pdo->prepare(
            'SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
        );
        $col->execute([$schema, 'task_notification_events', 'status']);
        if ($col->fetchColumn() !== false) {
            return;
        }

        $after = 'body';
        $col->execute([$schema, 'task_notification_events', 'body']);
        if ($col->fetchColumn() === false) {
            $col->execute([$schema, 'task_notification_events', 'message']);
            $after = $col->fetchColumn() !== false ? 'message' : '';
        }
        $afterSql = $after !== '' ? " AFTER {$after}" : '';
        $pdo->exec(
            "ALTER TABLE task_notification_events ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'pending'{$afterSql}"
        );
        $pdo->exec(
            'ALTER TABLE task_notification_events ADD KEY ix_task_notification_status (status)'
        );
    } catch (Throwable $e) {
        error_log('akh_db_apply_runtime_patches: ' . $e->getMessage());
    }
}
