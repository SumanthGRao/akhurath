<?php

declare(strict_types=1);

/**
 * @return array<string, string> lowercase username => password_hash
 */
function akh_admin_accounts(): array
{
    $path = AKH_ROOT . '/data/admins.php';
    if (!is_file($path)) {
        return [];
    }

    $data = require $path;

    return is_array($data) ? $data : [];
}

function akh_admin_current(): ?string
{
    $u = $_SESSION['akh_admin_user'] ?? null;

    return is_string($u) && $u !== '' ? $u : null;
}

function akh_admin_login(string $username, string $password): bool
{
    $key = strtolower(trim($username));
    if ($key === '') {
        return false;
    }

    $accounts = akh_admin_accounts();
    if ($accounts !== []) {
        if (!isset($accounts[$key])) {
            return false;
        }
        $hash = $accounts[$key];
        if (!is_string($hash) || !password_verify($password, $hash)) {
            return false;
        }
        session_regenerate_id(true);
        $_SESSION['akh_admin_user'] = $key;

        return true;
    }

    return false;
}

function akh_admin_logout(): void
{
    unset($_SESSION['akh_admin_user']);
}

function akh_require_admin(): void
{
    if (akh_admin_current() === null) {
        header('Location: ' . base_path('admin/login.php'));
        exit;
    }
}
