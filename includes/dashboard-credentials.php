<?php

declare(strict_types=1);

function akh_dashboard_credentials_path(): string
{
    return AKH_ROOT . '/data/dashboard-credentials.json';
}

/**
 * @return array<string, mixed>
 */
function akh_dashboard_credentials_load(): array
{
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }

    $path = akh_dashboard_credentials_path();
    if (!is_file($path)) {
        $cache = [];

        return $cache;
    }

    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        $cache = [];

        return $cache;
    }

    $decoded = json_decode($raw, true);
    $cache = is_array($decoded) ? $decoded : [];

    return $cache;
}

/**
 * @param array<string, mixed> $data
 */
function akh_dashboard_credentials_save(array $data): bool
{
    $path = akh_dashboard_credentials_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    try {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        return false;
    }

    return @file_put_contents($path, $json, LOCK_EX) !== false;
}

/**
 * @return array{user: string, pass_hash: string}
 */
function akh_dashboard_credentials_wa(): array
{
    $all = akh_dashboard_credentials_load();
    $wa = $all['wa'] ?? [];
    if (!is_array($wa)) {
        $wa = [];
    }

    $user = trim((string) ($wa['user'] ?? ''));
    $hash = trim((string) ($wa['pass_hash'] ?? ''));
    if ($user === '' && defined('AKH_WA_DASHBOARD_USER')) {
        $user = trim((string) AKH_WA_DASHBOARD_USER);
    }
    if ($hash === '' && defined('AKH_WA_DASHBOARD_PASS_HASH')) {
        $hash = trim((string) AKH_WA_DASHBOARD_PASS_HASH);
    }

    return ['user' => $user, 'pass_hash' => $hash];
}

function akh_dashboard_credentials_wa_configured(): bool
{
    $wa = akh_dashboard_credentials_wa();

    return $wa['user'] !== '' && $wa['pass_hash'] !== '';
}

/**
 * @return string|null error message or null on success
 */
function akh_dashboard_credentials_set_wa(string $username, string $password, string $passwordConfirm): ?string
{
    $username = strtolower(trim($username));
    if (!preg_match('/^[a-z][a-z0-9_]{2,31}$/', $username)) {
        return 'Username must be 3–32 characters: letter first, then letters, numbers, or underscores.';
    }
    if (mb_strlen($password) < 8) {
        return 'Password must be at least 8 characters.';
    }
    if ($password !== $passwordConfirm) {
        return 'Passwords do not match.';
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    if ($hash === false) {
        return 'Could not hash password.';
    }

    $all = akh_dashboard_credentials_load();
    $all['wa'] = [
        'user' => $username,
        'pass_hash' => $hash,
        'updated_at' => gmdate('c'),
    ];

    if (!akh_dashboard_credentials_save($all)) {
        return 'Could not save WhatsApp dashboard credentials.';
    }

    return null;
}
