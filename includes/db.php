<?php

declare(strict_types=1);

/**
 * Akhurath Studio - Data Bridge
 * Replaces the old PDO connection with an n8n API stream when configured.
 */

if (!function_exists('getAkhurathChatData')) {
    $dbLocal = __DIR__ . '/../config/database.local.php';
    if (is_file($dbLocal)) {
        require_once $dbLocal;
    }
}

/**
 * Returns dashboard data from the n8n API bridge (array) or a PDO handle (legacy MySQL).
 *
 * @return array<string, mixed>|list<array<string, mixed>>|PDO
 */
function akh_db()
{
    if (function_exists('getAkhurathChatData')) {
        return getAkhurathChatData();
    }

    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (!defined('AKH_DB_DSN') || !defined('AKH_DB_USER') || !defined('AKH_DB_PASS')) {
        return [];
    }

    $pdo = new PDO(
        (string) AKH_DB_DSN,
        (string) AKH_DB_USER,
        (string) AKH_DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    return $pdo;
}

/** True when akh_db() returns grouped/flat JSON from n8n (not PDO). */
function akh_db_is_bridge(): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    if (!function_exists('akh_db')) {
        $cached = false;

        return $cached;
    }

    $cached = is_array(akh_db());
    return $cached;
}

/** True when akh_db() returns a live PDO connection (legacy Hostinger MySQL). */
function akh_db_is_pdo(): bool
{
    if (!function_exists('akh_db')) {
        return false;
    }

    $handle = akh_db();

    return $handle instanceof PDO;
}

/** Normalized grouped dashboard payload from the n8n bridge. */
function akh_db_data(): array
{
    if (!akh_db_is_bridge()) {
        return [];
    }

    require_once __DIR__ . '/dashboard-data-bridge.php';

    return akh_dashboard_data_normalize(akh_db());
}

/** Helper to check if the data stream is active. */
function is_db_connected(): bool
{
    if (akh_db_is_bridge()) {
        return akh_db_data() !== [];
    }

    if (akh_db_is_pdo()) {
        return true;
    }

    return false;
}

/**
 * WhatsApp / portal message rows for one task (case-insensitive task_code match).
 *
 * @return list<array<string, mixed>>
 */
function akh_db_messages_for_task(string $taskCode, int $limit = 500): array
{
    require_once __DIR__ . '/tasks.php';
    require_once __DIR__ . '/whatsapp-messages.php';

    $rows = akh_wa_messages_list_for_task($taskCode, $limit);
    usort($rows, static function (array $a, array $b): int {
        $cmp = strcmp((string) ($a['created_at'] ?? ''), (string) ($b['created_at'] ?? ''));
        if ($cmp !== 0) {
            return $cmp;
        }

        return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
    });

    return $rows;
}
