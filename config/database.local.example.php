<?php

declare(strict_types=1);

/**
 * Copy to: config/database.local.php (never commit database.local.php).
 *
 * --- Hostinger (hPanel → Databases → Management) ---
 * 1. Create a new MySQL database and user. Hostinger prefixes names, e.g.:
 *      Database: u113439427_akhurath_studio   (you choose the part after the prefix)
 *      User:     u113439427_akhurath_user      (often same prefix; use exact values from hPanel)
 *      Password: (the one you set in the form)
 * 2. Open phpMyAdmin from hPanel, select that database, Import → choose sql/schema.sql from this project.
 * 3. Set the three defines below to match hPanel (host is usually localhost on shared hosting).
 *
 * --- Local XAMPP ---
 * Create DB (e.g. akhurath_studio), import sql/schema.sql, then often:
 *   AKH_DB_USER = root, AKH_DB_PASS = '' (unless you set a root password)
 */

// Hostinger-style example (replace with your exact names from Databases → Management):
// define('AKH_DB_DSN', 'mysql:host=localhost;dbname=u113439427_akhurath_studio;charset=utf8mb4');
// define('AKH_DB_USER', 'u113439427_akhurath_user');
// define('AKH_DB_PASS', 'your_password_here');

// Local XAMPP-style example:
define('AKH_DB_DSN', 'mysql:host=127.0.0.1;dbname=akhurath_studio;charset=utf8mb4');
define('AKH_DB_USER', 'root');
define('AKH_DB_PASS', '');
