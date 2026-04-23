<?php

declare(strict_types=1);

/**
 * Shared PDO connection (lazy).
 */
function akh_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    if (!defined('AKH_DB_DSN') || !defined('AKH_DB_USER') || !defined('AKH_DB_PASS')) {
        throw new RuntimeException('Missing database config: copy config/database.local.example.php to config/database.local.php.');
    }

    try {
        $pdo = new PDO(AKH_DB_DSN, AKH_DB_USER, AKH_DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (PDOException $e) {
        throw new RuntimeException(
            'MySQL connection failed: ' . $e->getMessage()
            . ' — Check config/database.local.php (Hostinger: database name, user, password, host; often localhost).',
            0,
            $e
        );
    }

    return $pdo;
}
