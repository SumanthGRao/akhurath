<?php

declare(strict_types=1);

/**
 * @return array<string, string> username => password_hash
 */
function akh_editor_accounts_path(): string
{
    return AKH_ROOT . '/data/editors.php';
}

function akh_editor_accounts(): array
{
    $path = akh_editor_accounts_path();
    if (!is_file($path)) {
        return [];
    }

    $data = require $path;

    return is_array($data) ? $data : [];
}

/**
 * @param array<string, string> $accounts
 */
function akh_editor_write_accounts_file(array $accounts): bool
{
    $path = akh_editor_accounts_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $body = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($accounts, true) . ";\n";

    return @file_put_contents($path, $body, LOCK_EX) !== false;
}

function akh_editor_delete(string $username): bool
{
    $key = strtolower(trim($username));
    if ($key === '') {
        return false;
    }

    $lockPath = AKH_ROOT . '/data/.editors-admin.lock';
    $lockFp = fopen($lockPath, 'c');
    if ($lockFp === false || !flock($lockFp, LOCK_EX)) {
        if ($lockFp !== false) {
            fclose($lockFp);
        }

        return false;
    }
    try {
        $accounts = akh_editor_accounts();
        unset($accounts[$key]);
        if (!akh_editor_write_accounts_file($accounts)) {
            return false;
        }
    } finally {
        flock($lockFp, LOCK_UN);
        fclose($lockFp);
    }

    return true;
}

/**
 * @return string|null error message or null on success
 */
function akh_editor_add(string $username, string $password, string $passwordConfirm): ?string
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

    $lockPath = AKH_ROOT . '/data/.editors-admin.lock';
    $lockFp = fopen($lockPath, 'c');
    if ($lockFp === false || !flock($lockFp, LOCK_EX)) {
        if ($lockFp !== false) {
            fclose($lockFp);
        }

        return 'Could not lock editor accounts file.';
    }
    try {
        $accounts = akh_editor_accounts();
        if (isset($accounts[$username])) {
            return 'That editor username already exists.';
        }
        $accounts[$username] = password_hash($password, PASSWORD_DEFAULT);
        if (!akh_editor_write_accounts_file($accounts)) {
            return 'Could not save editor accounts.';
        }
    } finally {
        flock($lockFp, LOCK_UN);
        fclose($lockFp);
    }

    return null;
}

function akh_editor_current(): ?string
{
    $u = $_SESSION['akh_editor'] ?? null;

    return is_string($u) && $u !== '' ? $u : null;
}

function akh_editor_login(string $username, string $password): bool
{
    if (AKH_DEV_TEST_LOGIN && $username === 'test' && $password === 'test') {
        session_regenerate_id(true);
        $_SESSION['akh_editor'] = 'test';

        return true;
    }

    $accounts = akh_editor_accounts();
    $key = strtolower(trim($username));
    if (!isset($accounts[$key])) {
        return false;
    }

    $hash = $accounts[$key];
    if (!is_string($hash) || !password_verify($password, $hash)) {
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['akh_editor'] = $key;

    return true;
}

function akh_editor_logout(): void
{
    $u = akh_editor_current();
    if ($u !== null && AKH_EDITOR_ATTENDANCE_ENABLED) {
        require_once AKH_ROOT . '/includes/editor-attendance.php';
        akh_editor_attendance_auto_clock_out_on_logout($u);
    }
    unset($_SESSION['akh_editor']);
}

function akh_require_editor(): void
{
    if (akh_editor_current() === null) {
        header('Location: ' . base_path('editor/login.php'));
        exit;
    }
}
