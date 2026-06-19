#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Usage: php scripts/hash-password.php 'YourPassword'
 * Paste the single-line hash into:
 *   - data/customers.php (client username => hash), or
 *   - data/admins.php (admin username => hash), or
 *   - data/editors.php (editor username => hash), or
 *   - includes/config.php AKH_WA_DASHBOARD_PASS_HASH (WhatsApp task dashboard).
 */
$pw = $argv[1] ?? '';
if ($pw === '') {
    fwrite(STDERR, "Usage: php scripts/hash-password.php 'YourPassword'\n");
    exit(1);
}

echo password_hash($pw, PASSWORD_DEFAULT), "\n";
