<?php

declare(strict_types=1);

function akh_editor_storage_is_database(): bool
{
    return function_exists('akh_db');
}

/**
 * @return array<string, string> username => password_hash
 */
function akh_editor_accounts_path(): string
{
    return AKH_ROOT . '/data/editors.php';
}

/**
 * @return array<string, string>
 */
function akh_editor_accounts_from_file(): array
{
    $path = akh_editor_accounts_path();
    if (!is_file($path)) {
        return [];
    }
    $data = require $path;

    return is_array($data) ? $data : [];
}

/**
 * @return array<string, string>
 */
function akh_editor_accounts_from_database(): array
{
    $st = akh_db()->prepare(
        'SELECT username, password_hash FROM users WHERE role = ? ORDER BY username'
    );
    $st->execute(['editor']);
    $out = [];
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $u = strtolower(trim((string) ($row['username'] ?? '')));
        $h = (string) ($row['password_hash'] ?? '');
        if ($u !== '' && $h !== '') {
            $out[$u] = $h;
        }
    }

    return $out;
}

/**
 * Lock file locations, most preferred first. Under Apache (e.g. XAMPP "daemon"), sys_get_temp_dir()
 * may point to a user /var/folders/... path that the web user cannot write — so we try data/ and /tmp.
 *
 * @return list<string>
 */
function akh_editor_accounts_lock_candidates(): array
{
    $name = 'akhurath-editors-' . hash('sha256', AKH_ROOT) . '.lock';
    $candidates = [
        AKH_ROOT . '/data/.editors-admin.lock',
        '/tmp/' . $name,
        rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $name,
    ];

    return $candidates;
}

/**
 * @return array{ok: true, fp: resource}|array{ok: false, error: string}
 */
function akh_editor_accounts_lock_acquire(): array
{
    foreach (akh_editor_accounts_lock_candidates() as $lockPath) {
        $dir = dirname($lockPath);
        if ($lockPath !== '' && $dir !== '' && $dir !== '.' && !is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $lockFp = fopen($lockPath, 'cb');
        if ($lockFp === false) {
            continue;
        }
        if (!flock($lockFp, LOCK_EX)) {
            fclose($lockFp);

            return [
                'ok' => false,
                'error' => 'Could not lock editor accounts (another update may be in progress). Wait a moment and try again.',
            ];
        }

        return ['ok' => true, 'fp' => $lockFp];
    }

    return [
        'ok' => false,
        'error' => 'Could not create an editor accounts lock file. Ensure the web server can write to the data/ directory or to /tmp.',
    ];
}

function akh_editor_accounts_lock_release($fp): void
{
    if (is_resource($fp)) {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

function akh_editor_accounts(): array
{
    if (akh_editor_storage_is_database()) {
        return akh_editor_accounts_from_database();
    }

    return akh_editor_accounts_from_file();
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

    if (akh_editor_storage_is_database()) {
        try {
            $st = akh_db()->prepare('DELETE FROM users WHERE role = ? AND username = ?');

            return $st->execute(['editor', $key]);
        } catch (\Throwable $e) {
            return false;
        }
    }

    $lock = akh_editor_accounts_lock_acquire();
    if (!$lock['ok']) {
        return false;
    }
    $lockFp = $lock['fp'];
    try {
        $accounts = akh_editor_accounts_from_file();
        unset($accounts[$key]);
        if (!akh_editor_write_accounts_file($accounts)) {
            return false;
        }
    } finally {
        akh_editor_accounts_lock_release($lockFp);
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

    if (akh_editor_storage_is_database()) {
        $accounts = akh_editor_accounts_from_database();
        if (isset($accounts[$username])) {
            return 'That editor username already exists.';
        }
        $hash = password_hash($password, PASSWORD_DEFAULT);
        if ($hash === false) {
            return 'Could not hash password.';
        }
        try {
            $st = akh_db()->prepare(
                'INSERT INTO users (role, username, password_hash, email) VALUES (?, ?, ?, NULL)'
            );
            $st->execute(['editor', $username, $hash]);
        } catch (\PDOException $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, 'Duplicate') || str_contains($msg, '1062')) {
                return 'That editor username already exists.';
            }

            return 'Could not save editor account to the database.';
        }

        return null;
    }

    $lock = akh_editor_accounts_lock_acquire();
    if (!$lock['ok']) {
        return $lock['error'];
    }
    $lockFp = $lock['fp'];
    try {
        $accounts = akh_editor_accounts_from_file();
        if (isset($accounts[$username])) {
            return 'That editor username already exists.';
        }
        $hash = password_hash($password, PASSWORD_DEFAULT);
        if ($hash === false) {
            return 'Could not hash password.';
        }
        $accounts[$username] = $hash;
        if (!akh_editor_write_accounts_file($accounts)) {
            return 'Could not save editor accounts. Ensure the web server can write to data/editors.php (and the data/ directory).';
        }
    } finally {
        akh_editor_accounts_lock_release($lockFp);
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
