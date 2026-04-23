<?php

declare(strict_types=1);

function akh_admin_storage_is_database(): bool
{
    return function_exists('akh_db');
}

/**
 * @return array<string, string> lowercase username => password_hash
 */
function akh_admin_accounts(): array
{
    if (akh_admin_storage_is_database()) {
        $st = akh_db()->prepare(
            'SELECT username, password_hash FROM users WHERE role = ? ORDER BY username'
        );
        $st->execute(['admin']);
        $out = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $out[strtolower((string) $row['username'])] = (string) $row['password_hash'];
        }

        return $out;
    }

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

/** True when the HTTP client appears to be the same machine (PHP built-in server, local Apache, etc.). */
function akh_admin_request_is_loopback(): bool
{
    $addr = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

    return $addr === '127.0.0.1' || $addr === '::1';
}

/**
 * When true, admin accepts username/password `test`/`test` without real admin accounts (UI / local dev).
 * Enabled if: AKH_ADMIN_DEV_TEST_LOGIN, or AKH_DEV_TEST_LOGIN, or loopback with no admin accounts yet.
 */
function akh_admin_dev_test_login_allowed(): bool
{
    if (AKH_ADMIN_DEV_TEST_LOGIN || AKH_DEV_TEST_LOGIN) {
        return true;
    }

    return akh_admin_request_is_loopback() && akh_admin_accounts() === [];
}

function akh_admin_login(string $username, string $password): bool
{
    $key = strtolower(trim($username));
    if ($key === '') {
        return false;
    }

    if (akh_admin_dev_test_login_allowed() && $key === 'test' && $password === 'test') {
        session_regenerate_id(true);
        $_SESSION['akh_admin_user'] = 'test';

        return true;
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

/**
 * @param array<string, string> $accounts lowercase username => password_hash
 * @param ?string $contactEmail When using MySQL and there is exactly one admin row in $accounts, stored in users.email (first-time setup).
 */
function akh_admin_save_accounts(array $accounts, ?string $contactEmail = null): bool
{
    if (akh_admin_storage_is_database()) {
        $emailForRow = null;
        if ($contactEmail !== null && count($accounts) === 1) {
            $ce = strtolower(trim($contactEmail));
            if ($ce !== '' && filter_var($ce, FILTER_VALIDATE_EMAIL) && mb_strlen($ce) <= 120) {
                $emailForRow = $ce;
            }
        }
        try {
            $pdo = akh_db();
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM users WHERE role = ?')->execute(['admin']);
            if ($emailForRow !== null) {
                $ins = $pdo->prepare(
                    'INSERT INTO users (role, username, password_hash, email) VALUES (?, ?, ?, ?)'
                );
            } else {
                $ins = $pdo->prepare(
                    'INSERT INTO users (role, username, password_hash) VALUES (?, ?, ?)'
                );
            }
            foreach ($accounts as $u => $hash) {
                $u = strtolower(trim((string) $u));
                if ($u === '' || !is_string($hash)) {
                    continue;
                }
                if ($emailForRow !== null) {
                    $ins->execute(['admin', $u, $hash, $emailForRow]);
                } else {
                    $ins->execute(['admin', $u, $hash]);
                }
            }
            $pdo->commit();

            return true;
        } catch (\Throwable $e) {
            if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            return false;
        }
    }

    $target = AKH_ROOT . '/data/admins.php';
    $dir = dirname($target);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return false;
    }
    ksort($accounts);
    $body = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($accounts, true) . ";\n";
    $tmp = $target . '.tmp';
    if (file_put_contents($tmp, $body) === false) {
        return false;
    }
    if (!@rename($tmp, $target)) {
        @unlink($tmp);

        return false;
    }

    return true;
}

function akh_admin_update_password_hash(string $username, string $newHash): bool
{
    $key = strtolower(trim($username));
    if ($key === '' || $newHash === '') {
        return false;
    }

    if (akh_admin_storage_is_database()) {
        try {
            $st = akh_db()->prepare(
                'UPDATE users SET password_hash = ? WHERE role = ? AND username = ?'
            );

            return $st->execute([$newHash, 'admin', $key]) && $st->rowCount() > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    $accounts = akh_admin_accounts();
    if (!isset($accounts[$key])) {
        return false;
    }
    $accounts[$key] = $newHash;

    return akh_admin_save_accounts($accounts);
}
