<?php

declare(strict_types=1);

/**
 * Copy to data/admins.php and replace with real bcrypt hashes.
 * Default admin is created by: php scripts/seed-admin-console.php (user admin / password from that script).
 *
 * @return array<string, string> lowercase username => password_hash
 */
return [
    // 'admin' => '$2y$10$...',
];
