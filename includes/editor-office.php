<?php

declare(strict_types=1);

/**
 * Editor portal: only allow access from the studio office network (public IPv4 + IPv6 prefix).
 * All computers on office Wi‑Fi share the same internet addresses — no per-device setup.
 */

function akh_editor_office_only_enabled(): bool
{
    return defined('AKH_EDITOR_OFFICE_ONLY') && AKH_EDITOR_OFFICE_ONLY;
}

function akh_editor_ip_valid(string $ip): bool
{
    return $ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) !== false;
}

function akh_editor_ip_is_public(string $ip): bool
{
    if (!akh_editor_ip_valid($ip)) {
        return false;
    }

    return filter_var(
        $ip,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    ) !== false;
}

/**
 * @return list<string>
 */
function akh_editor_request_ip_candidates(): array
{
    $seen = [];

    $add = static function (string $ip) use (&$seen): void {
        $ip = trim($ip);
        if (akh_editor_ip_valid($ip)) {
            $seen[$ip] = true;
        }
    };

    $add((string) ($_SERVER['REMOTE_ADDR'] ?? ''));

    $trustProxy = defined('AKH_EDITOR_TRUST_PROXY_IP') && AKH_EDITOR_TRUST_PROXY_IP;
    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if ($trustProxy || ($remote !== '' && !akh_editor_ip_is_public($remote))) {
        foreach ([
            'HTTP_CF_CONNECTING_IP',
            'HTTP_TRUE_CLIENT_IP',
            'HTTP_X_REAL_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_CLIENT_IP',
        ] as $key) {
            $raw = (string) ($_SERVER[$key] ?? '');
            if ($raw === '') {
                continue;
            }
            foreach (explode(',', $raw) as $part) {
                $add($part);
            }
        }
    }

    return array_keys($seen);
}

function akh_editor_ipv6_prefix64(string $ip): ?string
{
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
        return null;
    }
    $bin = inet_pton($ip);
    if ($bin === false || strlen($bin) !== 16) {
        return null;
    }
    $prefixBin = substr($bin, 0, 8) . str_repeat("\0", 8);
    $prefixIp = inet_ntop($prefixBin);
    if ($prefixIp === false) {
        return null;
    }
    $hextets = explode(':', $prefixIp);
    $short = [];
    foreach (array_slice($hextets, 0, 4) as $h) {
        if ($h !== '') {
            $short[] = $h;
        }
    }
    if (count($short) < 4) {
        return null;
    }

    return implode(':', $short) . '::/64';
}

function akh_editor_primary_public_ipv4(): ?string
{
    foreach (akh_editor_request_ip_candidates() as $ip) {
        if (akh_editor_ip_is_public($ip) && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $ip;
        }
    }

    return null;
}

/**
 * @return list<string>
 */
function akh_editor_office_network_list(): array
{
    if (!defined('AKH_EDITOR_OFFICE_NETWORKS')) {
        return [];
    }
    $raw = trim((string) AKH_EDITOR_OFFICE_NETWORKS);
    if ($raw === '') {
        return [];
    }

    return array_values(array_filter(array_map('trim', preg_split('/\s*,\s*/', $raw) ?: []), static fn (string $s): bool => $s !== ''));
}

function akh_editor_ip_matches_network(string $ip, string $network): bool
{
    $network = trim($network);
    if ($network === '' || !akh_editor_ip_valid($ip)) {
        return false;
    }
    if (!str_contains($network, '/')) {
        return $ip === $network;
    }

    [$subnet, $bitsStr] = explode('/', $network, 2);
    $bits = (int) $bitsStr;
    $subnet = trim($subnet);
    if ($subnet === '' || !akh_editor_ip_valid($subnet)) {
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

function akh_editor_office_ip_allowed(): bool
{
    if (!akh_editor_office_only_enabled()) {
        return true;
    }

    $allowLoopback = !defined('AKH_EDITOR_OFFICE_ALLOW_LOOPBACK') || AKH_EDITOR_OFFICE_ALLOW_LOOPBACK;
    foreach (akh_editor_request_ip_candidates() as $ip) {
        if ($allowLoopback && ($ip === '127.0.0.1' || $ip === '::1')) {
            return true;
        }
    }

    $networks = akh_editor_office_network_list();
    if ($networks === []) {
        return false;
    }

    foreach (akh_editor_request_ip_candidates() as $ip) {
        foreach ($networks as $network) {
            if (akh_editor_ip_matches_network($ip, $network)) {
                return true;
            }
        }
    }

    return false;
}

/**
 * One-line value to paste into AKH_EDITOR_OFFICE_NETWORKS (from current office visit).
 */
function akh_editor_office_networks_suggested(): string
{
    $parts = [];
    $v4 = akh_editor_primary_public_ipv4();
    if ($v4 !== null) {
        $parts[] = $v4;
    }
    foreach (akh_editor_request_ip_candidates() as $ip) {
        $v6 = akh_editor_ipv6_prefix64($ip);
        if ($v6 !== null && !in_array($v6, $parts, true)) {
            $parts[] = $v6;
            break;
        }
    }

    return implode(',', $parts);
}

/**
 * @return list<string>
 */
function akh_editor_office_status_lines(): array
{
    $lines = [];
    $on = akh_editor_office_only_enabled();
    $lines[] = 'Office-only login: ' . ($on ? 'ON — home / mobile data cannot sign in' : 'OFF — editors may sign in from anywhere');

    if (!$on) {
        $lines[] = 'Admin: open this page on studio Wi‑Fi, copy the config line below into includes/config.php, then set AKH_EDITOR_OFFICE_ONLY = true.';

        return array_merge($lines, akh_editor_office_visit_lines());
    }

    $lines[] = 'Your connection now: ' . (akh_editor_office_ip_allowed() ? 'at the studio (allowed)' : 'not the studio network (blocked)');

    return array_merge($lines, akh_editor_office_visit_lines());
}

/**
 * @return list<string>
 */
function akh_editor_office_visit_lines(): array
{
    $lines = [];
    $v4 = akh_editor_primary_public_ipv4();
    if ($v4 !== null) {
        $lines[] = 'Office IPv4 (all PCs on this Wi‑Fi): ' . $v4;
    }
    foreach (akh_editor_request_ip_candidates() as $ip) {
        $v6 = akh_editor_ipv6_prefix64($ip);
        if ($v6 !== null) {
            $lines[] = 'Office IPv6 range (phones/laptops on Wi‑Fi): ' . $v6;
            break;
        }
    }
    $suggest = akh_editor_office_networks_suggested();
    if ($suggest !== '') {
        $lines[] = 'Paste into config → AKH_EDITOR_OFFICE_NETWORKS = \'' . $suggest . '\'';
    }
    $configured = akh_editor_office_network_list();
    if ($configured !== []) {
        $lines[] = 'Currently allowed: ' . implode(', ', $configured);
    }

    return $lines;
}

function akh_editor_require_office_network(): void
{
    if (akh_editor_office_ip_allowed()) {
        return;
    }

    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');

    $home = h(base_path('index.php'));
    $site = h(SITE_NAME);
    $suggest = akh_editor_office_networks_suggested();

    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8" /><meta name="viewport" content="width=device-width, initial-scale=1" />';
    echo '<title>Studio network required — ' . $site . '</title>';
    echo '<link rel="stylesheet" href="' . h(base_path('assets/css/site.css')) . '" /></head>';
    echo '<body class="page-portal"><main id="main" class="portal-main"><div class="portal-card">';
    echo '<h1 class="portal-title">Editor login — studio only</h1>';
    echo '<p class="portal-lead">Sign-in is only allowed from the Akhurath office internet connection, not from home or personal mobile data.</p>';
    echo '<p class="portal-note">Connect to <strong>office Wi‑Fi</strong> and open editor login again. Employees do not need to register devices — one config covers every PC on that network.</p>';
    if ($suggest !== '') {
        echo '<p class="portal-muted">If you are already on office Wi‑Fi and still see this, ask admin to set:<br /><code>AKH_EDITOR_OFFICE_NETWORKS = \'' . h($suggest) . '\'</code></p>';
    }
    echo '<p class="portal-foot"><a class="text-link" href="' . $home . '">← Website home</a></p>';
    echo '</div></main></body></html>';
    exit;
}
