<?php

declare(strict_types=1);

/**
 * Copy this entire file to config/database.local.php and uncomment ONE block.
 * database.local.php is gitignored — do not commit passwords.
 * On Hostinger you must create this file on the server (Git does not deploy it); otherwise
 * admin setup and logins will not write to MySQL and the site will not use the database.
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
 * Or CLI: ./scripts/mysql-import-schema.sh (set DB_HOST, DB_USER, DB_NAME, MYSQL_PWD as needed).
 * After any import or old DB: php scripts/ensure-database.php
 * With legacy data/customers.php: php scripts/ensure-database.php --migrate-customers-from-files
 * Full MySQL wipe (users + tasks + enquiries + notifications): php scripts/reset-database.php --confirm
 *
 * === Local XAMPP ===
 *
 * define('AKH_DB_DSN', 'mysql:host=127.0.0.1;dbname=akhurath_studio;charset=utf8mb4');
 * define('AKH_DB_USER', 'root');
 * define('AKH_DB_PASS', '');
 */
