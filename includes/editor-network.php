<?php

declare(strict_types=1);

/**
 * Restrict editor login / dashboard to office LAN or VPN (configurable CIDRs).
 */

function akh_editor_office_network_required(): bool
{
    return defined('AKH_EDITOR_OFFICE_NETWORK_ONLY') && AKH_EDITOR_OFFICE_NETWORK_ONLY;
}

function akh_editor_ip_string_valid(string $ip): bool
{
    return $ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) !== false;
}

function akh_editor_ip_is_public(string $ip): bool
{
    if (!akh_editor_ip_string_valid($ip)) {
        return false;
    }

    return filter_var(
        $ip,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    ) !== false;
}

/**
 * All plausible client IPs (REMOTE_ADDR plus trusted proxy headers).
 *
 * @return list<string>
 */
function akh_editor_request_ip_candidates(): array
{
    $seen = [];

    $add = static function (string $ip) use (&$seen): void {
        $ip = trim($ip);
        if (akh_editor_ip_string_valid($ip)) {
            $seen[$ip] = true;
        }
    };

    $add((string) ($_SERVER['REMOTE_ADDR'] ?? ''));

    $trustProxy = defined('AKH_EDITOR_TRUST_PROXY_IP') && AKH_EDITOR_TRUST_PROXY_IP;
    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $needForwarded = $trustProxy || ($remote !== '' && !akh_editor_ip_is_public($remote));

    if ($needForwarded) {
        $headerKeys = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_TRUE_CLIENT_IP',
            'HTTP_X_REAL_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
        ];
        foreach ($headerKeys as $key) {
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

/**
 * Primary IP shown in messages (first public IPv4, else first candidate).
 */
function akh_editor_request_client_ip(): string
{
    $candidates = akh_editor_request_ip_candidates();
    foreach ($candidates as $ip) {
        if (akh_editor_ip_is_public($ip) && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $ip;
        }
    }

    return $candidates[0] ?? '';
}

function akh_editor_request_is_loopback(): bool
{
    foreach (akh_editor_request_ip_candidates() as $ip) {
        if ($ip === '127.0.0.1' || $ip === '::1') {
            return true;
        }
    }

    return false;
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
    if ($network === '' || !akh_editor_ip_string_valid($ip)) {
        return false;
    }
    if (!str_contains($network, '/')) {
        return $ip === $network;
    }

    [$subnet, $bitsStr] = explode('/', $network, 2);
    $bits = (int) $bitsStr;
    $subnet = trim($subnet);
    if ($subnet === '' || !akh_editor_ip_string_valid($subnet)) {
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

    foreach (akh_editor_request_ip_candidates() as $ip) {
        foreach (akh_editor_allowed_network_list() as $network) {
            if (akh_editor_ip_matches_network($ip, $network)) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Office Wi‑Fi IPv6 /64 prefix from a full address (all devices on same LAN share the prefix).
 */
function akh_editor_ipv6_prefix64_cidr(string $ip): ?string
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
        if ($h === '' && $short !== []) {
            break;
        }
        if ($h !== '') {
            $short[] = $h;
        }
    }
    if (count($short) < 4) {
        return null;
    }

    return implode(':', $short) . '::/64';
}

/**
 * Status lines for editor login (verify office lock and which IP Hostinger sees).
 *
 * @return list<string>
 */
function akh_editor_network_status_lines(): array
{
    $lines = [];
    $on = akh_editor_office_network_required();
    $lines[] = 'Office-only lock: ' . ($on ? 'ON' : 'OFF (any network can open this page)');

    if (!$on) {
        return $lines;
    }

    $primary = akh_editor_request_client_ip();
    $lines[] = 'IP your server sees: ' . ($primary !== '' ? $primary : 'unknown');

    foreach (akh_editor_request_ip_candidates() as $cand) {
        $v6range = akh_editor_ipv6_prefix64_cidr($cand);
        if ($v6range !== null) {
            $lines[] = 'Add to config (office Wi‑Fi IPv6 range): ' . $v6range;
            break;
        }
    }

    $candidates = akh_editor_request_ip_candidates();
    if (count($candidates) > 1) {
        $lines[] = 'All IPs checked: ' . implode(', ', $candidates);
    }

    $allow = akh_editor_allowed_network_list();
    $lines[] = 'Allowed in config: ' . ($allow !== [] ? implode(', ', $allow) : '(empty — everyone blocked except loopback)');

    $lines[] = 'Your access: ' . (akh_editor_request_ip_allowed() ? 'allowed' : 'blocked');

    return $lines;
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
    $candidates = akh_editor_request_ip_candidates();
    $primary = akh_editor_request_client_ip();
    $candidateLine = $candidates !== []
        ? implode(', ', array_map(static fn (string $ip): string => h($ip), $candidates))
        : '(none detected)';

    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8" /><meta name="viewport" content="width=device-width, initial-scale=1" />';
    echo '<title>Editor access restricted — ' . $site . '</title>';
    echo '<link rel="stylesheet" href="' . h(base_path('assets/css/site.css')) . '" /></head>';
    echo '<body class="page-portal"><main id="main" class="portal-main"><div class="portal-card">';
    echo '<h1 class="portal-title">Editor portal not available here</h1>';
    echo '<p class="portal-lead">The editor login and task board can only be opened from the studio office network (or VPN). Connect to office Wi‑Fi or VPN and try again.</p>';
    echo '<p class="portal-note"><strong>IP your server sees:</strong> ';
    echo $primary !== '' ? '<code>' . h($primary) . '</code>' : '<span class="portal-muted">unknown</span>';
    if (count($candidates) > 1) {
        echo '<br /><span class="portal-muted">All addresses checked: ' . $candidateLine . '</span>';
    }
    echo '</p>';
    echo '<p class="portal-muted">On the live site, <code>192.168.x.x</code> in config does not apply (that is only for local XAMPP). Add the <strong>public IPv4</strong> above to <code>AKH_EDITOR_ALLOWED_NETWORKS</code> in <code>includes/config.php</code> on the server, then reload. Keep <code>AKH_EDITOR_TRUST_PROXY_IP = true</code> on Hostinger.</p>';
    echo '<p class="portal-foot"><a class="text-link" href="' . $home . '">← Website home</a></p>';
    echo '</div></main></body></html>';
    exit;
}
