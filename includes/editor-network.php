<?php

declare(strict_types=1);

/**
 * Restrict editor login / dashboard to office LAN or VPN (configurable CIDRs).
 */

function akh_editor_office_network_required(): bool
{
    return defined('AKH_EDITOR_OFFICE_NETWORK_ONLY') && AKH_EDITOR_OFFICE_NETWORK_ONLY;
}

/**
 * Client IP for access checks. Uses REMOTE_ADDR unless proxy trust is enabled.
 */
function akh_editor_request_client_ip(): string
{
    $trustProxy = defined('AKH_EDITOR_TRUST_PROXY_IP') && AKH_EDITOR_TRUST_PROXY_IP;
    if ($trustProxy) {
        $xff = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($xff !== '') {
            $first = trim(explode(',', $xff)[0]);
            if ($first !== '' && filter_var($first, FILTER_VALIDATE_IP) !== false) {
                return $first;
            }
        }
        $real = (string) ($_SERVER['HTTP_X_REAL_IP'] ?? '');
        if ($real !== '' && filter_var($real, FILTER_VALIDATE_IP) !== false) {
            return $real;
        }
    }

    return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
}

function akh_editor_request_is_loopback(): bool
{
    $ip = akh_editor_request_client_ip();

    return $ip === '127.0.0.1' || $ip === '::1';
}

/**
 * @return list<string>
 */
function akh_editor_allowed_network_list(): array
{
    if (!defined('AKH_EDITOR_ALLOWED_NETWORKS')) {
        return [];
    }
    $raw = trim((string) AKH_EDITOR_ALLOWED_NETWORKS);
    if ($raw === '') {
        return [];
    }
    $parts = preg_split('/\s*,\s*/', $raw) ?: [];

    return array_values(array_filter(array_map('trim', $parts), static fn (string $s): bool => $s !== ''));
}

function akh_editor_ip_matches_network(string $ip, string $network): bool
{
    $network = trim($network);
    if ($network === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
        return false;
    }
    if (!str_contains($network, '/')) {
        return $ip === $network;
    }

    [$subnet, $bitsStr] = explode('/', $network, 2);
    $bits = (int) $bitsStr;
    $subnet = trim($subnet);
    if ($subnet === '' || filter_var($subnet, FILTER_VALIDATE_IP) === false) {
        return false;
    }

    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
        && filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
        if ($bits < 0 || $bits > 32) {
            return false;
        }
        $ipLong = ip2long($ip);
        $subLong = ip2long($subnet);
        if ($ipLong === false || $subLong === false) {
            return false;
        }
        if ($bits === 0) {
            return true;
        }
        $mask = (-1 << (32 - $bits)) & 0xFFFFFFFF;

        return ($ipLong & $mask) === ($subLong & $mask);
    }

    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false
        || filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
        return false;
    }
    if ($bits < 0 || $bits > 128) {
        return false;
    }
    $ipBin = inet_pton($ip);
    $subBin = inet_pton($subnet);
    if ($ipBin === false || $subBin === false) {
        return false;
    }
    $fullBytes = intdiv($bits, 8);
    $remBits = $bits % 8;
    if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($subBin, 0, $fullBytes)) {
        return false;
    }
    if ($remBits === 0) {
        return true;
    }
    $mask = (0xFF << (8 - $remBits)) & 0xFF;

    return (ord($ipBin[$fullBytes]) & $mask) === (ord($subBin[$fullBytes]) & $mask);
}

function akh_editor_request_ip_allowed(): bool
{
    if (!akh_editor_office_network_required()) {
        return true;
    }

    $allowLoopback = !defined('AKH_EDITOR_OFFICE_NETWORK_ALLOW_LOOPBACK') || AKH_EDITOR_OFFICE_NETWORK_ALLOW_LOOPBACK;
    if ($allowLoopback && akh_editor_request_is_loopback()) {
        return true;
    }
    if (defined('AKH_DEV_TEST_LOGIN') && AKH_DEV_TEST_LOGIN && akh_editor_request_is_loopback()) {
        return true;
    }

    $ip = akh_editor_request_client_ip();
    if ($ip === '') {
        return false;
    }

    foreach (akh_editor_allowed_network_list() as $network) {
        if (akh_editor_ip_matches_network($ip, $network)) {
            return true;
        }
    }

    return false;
}

function akh_editor_require_office_network(): void
{
    if (akh_editor_request_ip_allowed()) {
        return;
    }

    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');

    $home = h(base_path('index.php'));
    $site = h(SITE_NAME);

    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8" /><meta name="viewport" content="width=device-width, initial-scale=1" />';
    echo '<title>Editor access restricted — ' . $site . '</title>';
    echo '<link rel="stylesheet" href="' . h(base_path('assets/css/site.css')) . '" /></head>';
    echo '<body class="page-portal"><main id="main" class="portal-main"><div class="portal-card">';
    echo '<h1 class="portal-title">Editor portal not available here</h1>';
    echo '<p class="portal-lead">The editor login and task board can only be opened from the studio office network (or VPN). Connect to office Wi‑Fi or VPN and try again.</p>';
    echo '<p class="portal-muted">If you are already at the studio, ask admin to add this location’s public IP to <code>AKH_EDITOR_ALLOWED_NETWORKS</code> in <code>includes/config.php</code>.</p>';
    echo '<p class="portal-foot"><a class="text-link" href="' . $home . '">← Website home</a></p>';
    echo '</div></main></body></html>';
    exit;
}
