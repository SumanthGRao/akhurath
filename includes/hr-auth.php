<?php

declare(strict_types=1);

function akh_hr_enabled(): bool
{
    return defined('AKH_HR_DASHBOARD_ENABLED') && AKH_HR_DASHBOARD_ENABLED;
}

function akh_hr_accounts_path(): string
{
    return AKH_ROOT . '/data/hr-users.php';
}

/**
 * @return array<string, string>
 */
function akh_hr_accounts(): array
{
    $path = akh_hr_accounts_path();
    if (!is_file($path)) {
        return [];
    }
    $data = require $path;

    return is_array($data) ? $data : [];
}

/**
 * @param array<string, string> $accounts
 */
function akh_hr_write_accounts(array $accounts): bool
{
    $path = akh_hr_accounts_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $body = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($accounts, true) . ";\n";

    return @file_put_contents($path, $body, LOCK_EX) !== false;
}

/**
 * @return string|null error message or null on success
 */
function akh_hr_add(string $username, string $password, string $passwordConfirm): ?string
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

    $accounts = akh_hr_accounts();
    if (isset($accounts[$username])) {
        return 'That HR username already exists.';
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    if ($hash === false) {
        return 'Could not hash password.';
    }

    $accounts[$username] = $hash;
    if (!akh_hr_write_accounts($accounts)) {
        return 'Could not save HR account.';
    }

    return null;
}

function akh_hr_delete(string $username): bool
{
    $key = strtolower(trim($username));
    if ($key === '') {
        return false;
    }

    $accounts = akh_hr_accounts();
    if (!isset($accounts[$key])) {
        return false;
    }
    unset($accounts[$key]);

    return akh_hr_write_accounts($accounts);
}

function akh_hr_current(): ?string
{
    $u = $_SESSION['akh_hr_user'] ?? null;

    return is_string($u) && $u !== '' ? $u : null;
}

function akh_hr_login(string $username, string $password): bool
{
    if (!akh_hr_enabled()) {
        return false;
    }

    $accounts = akh_hr_accounts();
    $key = strtolower(trim($username));
    if (!isset($accounts[$key])) {
        return false;
    }

    $hash = $accounts[$key];
    if (!is_string($hash) || !password_verify($password, $hash)) {
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['akh_hr_user'] = $key;

    return true;
}

function akh_hr_logout(): void
{
    unset($_SESSION['akh_hr_user']);
}

function akh_require_hr(): void
{
    if (!akh_hr_enabled()) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'HR dashboard is disabled.';
        exit;
    }

    if (akh_hr_current() === null) {
        header('Location: ' . base_path('hr/login.php'));
        exit;
    }
}
