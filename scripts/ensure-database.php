#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Idempotent MySQL fixes + optional import of legacy file-based customers into `users`.
 *
 * Requires config/database.local.php (same as the web app).
 *
 *   php scripts/ensure-database.php
 *   php scripts/ensure-database.php --migrate-customers-from-files
 *   php scripts/ensure-database.php --migrate-editors-from-files
 *
 * Web UI (admin): /updated-sql
 */

$root = dirname(__DIR__);
require_once $root . '/includes/config.php';

$dbLocal = AKH_ROOT . '/config/database.local.php';
if (!is_file($dbLocal)) {
    fwrite(STDERR, "Missing config/database.local.php — copy config/database.local.example.php and set DSN/user/pass.\n");
    exit(1);
}

require_once $dbLocal;
require_once AKH_ROOT . '/includes/db.php';
require_once AKH_ROOT . '/includes/ensure-database-patches.php';

$args = array_slice($argv, 1);
$migrateCustomers = in_array('--migrate-customers-from-files', $args, true);
$migrateEditors = in_array('--migrate-editors-from-files', $args, true);
$patchesOnly = in_array('--patches-only', $args, true);
if ($patchesOnly) {
    $migrateCustomers = false;
    $migrateEditors = false;
}

$result = akh_ensure_database_apply_patches(akh_db(), [
    'migrate_customers' => $migrateCustomers,
    'migrate_editors' => $migrateEditors,
]);

foreach ($result['lines'] as $line) {
    echo $line . "\n";
}

if ($result['error'] !== null) {
    fwrite(STDERR, $result['error'] . "\n");
    exit(1);
}

exit(0);
