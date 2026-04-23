<?php

declare(strict_types=1);

/**
 * Copy this entire file to config/database.local.php and uncomment ONE block.
 * database.local.php is gitignored — do not commit passwords.
 *
 * === Hostinger — database `u113439427_akhurath` (phpMyAdmin) ===
 * MySQL user is often the same full name as the database (verify in hPanel → Databases → Management).
 * Host is usually localhost.
 *
 * define('AKH_DB_DSN', 'mysql:host=localhost;dbname=u113439427_akhurath;charset=utf8mb4');
 * define('AKH_DB_USER', 'u113439427_akhurath');
 * define('AKH_DB_PASS', 'YOUR_PASSWORD_HERE');
 *
 * Import: phpMyAdmin → select database → Import → sql/schema.sql
 *
 * === Local XAMPP ===
 *
 * define('AKH_DB_DSN', 'mysql:host=127.0.0.1;dbname=akhurath_studio;charset=utf8mb4');
 * define('AKH_DB_USER', 'root');
 * define('AKH_DB_PASS', '');
 */
