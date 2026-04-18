#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Writes data/admins.php with a bcrypt hash for the default admin.
 */

$root = dirname(__DIR__);
$target = $root . '/data/admins.php';

$username = 'admin';
$password = 'admin@123';

$hash = password_hash($password, PASSWORD_DEFAULT);
if ($hash === false) {
    fwrite(STDERR, "password_hash failed\n");
    exit(1);
}

$body = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export([$username => $hash], true) . ";\n";

if (!is_dir(dirname($target))) {
    mkdir(dirname($target), 0755, true);
}

if (file_put_contents($target, $body) === false) {
    fwrite(STDERR, "Could not write {$target}\n");
    exit(1);
}

echo "Wrote {$target} for user '{$username}'.\n";
