<?php

declare(strict_types=1);

/**
 * Editor portal: allow only registered devices (browser-stored ID per computer).
 * MAC addresses cannot be read by PHP over the internet; this is the practical equivalent.
 */

function akh_editor_device_lock_enabled(): bool
{
    return defined('AKH_EDITOR_DEVICE_LOCK') && AKH_EDITOR_DEVICE_LOCK;
}

function akh_editor_normalize_device_id(string $raw): ?string
{
    $id = strtolower(trim($raw));
    if ($id === '') {
        return null;
    }
    if (preg_match('/^edv_[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i', $id) === 1) {
        return $id;
    }
    if (preg_match('/^edv_[a-f0-9]{32}$/i', $id) === 1) {
        return $id;
    }

    return null;
}

/**
 * @return list<string>
 */
function akh_editor_allowed_device_list(): array
{
    if (!defined('AKH_EDITOR_ALLOWED_DEVICES')) {
        return [];
    }
    $raw = trim((string) AKH_EDITOR_ALLOWED_DEVICES);
    if ($raw === '') {
        return [];
    }
    $out = [];
    foreach (preg_split('/\s*,\s*/', $raw) ?: [] as $part) {
        $id = akh_editor_normalize_device_id((string) $part);
        if ($id !== null) {
            $out[$id] = true;
        }
    }

    return array_keys($out);
}

function akh_editor_device_is_allowed(?string $deviceId): bool
{
    if (!akh_editor_device_lock_enabled()) {
        return true;
    }
    if ($deviceId === null) {
        return false;
    }
    $allowed = akh_editor_allowed_device_list();

    return $allowed !== [] && in_array($deviceId, $allowed, true);
}

function akh_editor_device_cookie_name(): string
{
    return 'akh_editor_device';
}

function akh_editor_set_device_cookie(string $deviceId): void
{
    $life = max(60, (int) (defined('AKH_SESSION_LIFETIME_SECONDS') ? AKH_SESSION_LIFETIME_SECONDS : 86400 * 30));
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (string) $_SERVER['SERVER_PORT'] === '443')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');

    setcookie(akh_editor_device_cookie_name(), $deviceId, [
        'expires' => time() + $life,
        'path' => akh_session_cookie_path(),
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $_COOKIE[akh_editor_device_cookie_name()] = $deviceId;
}

function akh_editor_clear_device_cookie(): void
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (string) $_SERVER['SERVER_PORT'] === '443')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');

    setcookie(akh_editor_device_cookie_name(), '', [
        'expires' => time() - 3600,
        'path' => akh_session_cookie_path(),
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    unset($_COOKIE[akh_editor_device_cookie_name()]);
}

function akh_editor_device_from_request(): ?string
{
    $fromPost = akh_editor_normalize_device_id((string) ($_POST['device_id'] ?? ''));
    if ($fromPost !== null) {
        return $fromPost;
    }
    $fromCookie = akh_editor_normalize_device_id((string) ($_COOKIE[akh_editor_device_cookie_name()] ?? ''));

    return $fromCookie;
}

function akh_editor_session_device_id(): ?string
{
    $sid = $_SESSION['akh_editor_device'] ?? null;

    return is_string($sid) ? akh_editor_normalize_device_id($sid) : null;
}

function akh_editor_bind_device(string $deviceId): void
{
    $_SESSION['akh_editor_device'] = $deviceId;
    akh_editor_set_device_cookie($deviceId);
}

function akh_editor_session_device_valid(): bool
{
    if (!akh_editor_device_lock_enabled()) {
        return true;
    }
    $id = akh_editor_session_device_id();
    if ($id !== null && akh_editor_device_is_allowed($id)) {
        return true;
    }
    $req = akh_editor_device_from_request();
    if ($req !== null && akh_editor_device_is_allowed($req)) {
        akh_editor_bind_device($req);

        return true;
    }

    return false;
}

function akh_editor_require_allowed_device(): void
{
    if (!akh_editor_device_lock_enabled()) {
        return;
    }

    if (akh_editor_session_device_valid()) {
        return;
    }

    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');

    $home = h(base_path('index.php'));
    $site = h(SITE_NAME);
    $login = h(base_path('editor/login.php'));

    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8" /><meta name="viewport" content="width=device-width, initial-scale=1" />';
    echo '<title>Device not approved — ' . $site . '</title>';
    echo '<link rel="stylesheet" href="' . h(base_path('assets/css/site.css')) . '" /></head>';
    echo '<body class="page-portal"><main id="main" class="portal-main"><div class="portal-card">';
    echo '<h1 class="portal-title">This device is not approved</h1>';
    echo '<p class="portal-lead">Editor login is limited to studio computers you register. Open <a class="text-link" href="' . $login . '">editor login</a> on that computer, copy the <strong>Device ID</strong> shown there, and ask admin to add it to <code>AKH_EDITOR_ALLOWED_DEVICES</code> in <code>includes/config.php</code>.</p>';
    echo '<p class="portal-muted">Websites cannot read your network card MAC address; the Device ID is a per-browser code stored on that machine.</p>';
    echo '<p class="portal-foot"><a class="text-link" href="' . $home . '">← Website home</a></p>';
    echo '</div></main></body></html>';
    exit;
}
