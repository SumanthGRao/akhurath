<?php

declare(strict_types=1);

$akhConfig = __DIR__ . '/config.php';
$akhConfigExample = __DIR__ . '/config.example.php';
if (is_file($akhConfig)) {
    require_once $akhConfig;
} elseif (is_file($akhConfigExample)) {
    // Fallback keeps production/site online if config.php is missing after deploy.
    require_once $akhConfigExample;
    $msg = 'includes/config.php is missing; using includes/config.example.php defaults. Copy includes/config.example.php to includes/config.php for environment-specific values.';
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $msg . "\n");
    } else {
        error_log($msg);
    }
} else {
    $msg = 'Missing includes/config.php and includes/config.example.php.';
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $msg . "\n");
    } else {
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        echo $msg;
    }
    exit(1);
}

/**
 * Load MySQL mode when either:
 * - config/database.local.php exists, OR
 * - DB constants are already defined in includes/config.php.
 *
 * This prevents accidental fallback to file-based tasks on production when creds
 * live in includes/config.php and database.local.php is intentionally absent.
 */
$dbLocal = AKH_ROOT . '/config/database.local.php';
if (is_file($dbLocal)) {
    require_once $dbLocal;
}
if (defined('AKH_DB_DSN') && defined('AKH_DB_USER') && defined('AKH_DB_PASS')) {
    require_once __DIR__ . '/db.php';
    require_once __DIR__ . '/app-kv.php';
}

@date_default_timezone_set(AKH_SITE_TIMEZONE);

$life = max(60, (int) AKH_SESSION_LIFETIME_SECONDS);
@ini_set('session.gc_maxlifetime', (string) $life);

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['SERVER_PORT']) && (string) $_SERVER['SERVER_PORT'] === '443')
    || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
$cookiePath = akh_session_cookie_path();

session_set_cookie_params([
    'lifetime' => $life,
    'path' => $cookiePath,
    'secure' => $https,
    'httponly' => true,
    'samesite' => 'Lax',
]);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$name = session_name();
if ($name !== '' && session_status() === PHP_SESSION_ACTIVE) {
    setcookie($name, (string) session_id(), [
        'expires' => time() + $life,
        'path' => $cookiePath,
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}
