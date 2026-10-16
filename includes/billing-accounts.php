<?php

declare(strict_types=1);


function akh_billing_role_schema_ensure(): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    if (!function_exists('akh_db') || !akh_db() instanceof PDO) return $ready = false;
    try {
        $pdo = akh_db();
        $column = $pdo->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch(PDO::FETCH_ASSOC);
        if (!$column) return $ready = false;
        $type = strtolower((string) ($column['Type'] ?? ''));
        if (str_contains($type, "'accountant'")) return $ready = true;
        // Additive role migration required for accountant-only dashboard accounts.
        $pdo->exec("ALTER TABLE users MODIFY role ENUM('customer','editor','admin','accountant') NOT NULL");
        $column = $pdo->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch(PDO::FETCH_ASSOC);
        $ready = is_array($column) && str_contains(strtolower((string) ($column['Type'] ?? '')), "'accountant'");
        return $ready;
    } catch (Throwable $e) {
        error_log('akh_billing_role_schema_ensure: ' . $e->getMessage());
        return $ready = false;
    }
}

/** @return array<string, string> username => password hash */
function akh_billing_accounts(): array
{
    if (!akh_billing_role_schema_ensure()) return [];
    try {
        $st = akh_db()->query("SELECT username,password_hash FROM users WHERE role='accountant' ORDER BY username");
        $accounts = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $accounts[strtolower((string) $row['username'])] = (string) $row['password_hash'];
        }
        return $accounts;
    } catch (Throwable $e) {
        error_log('akh_billing_accounts: ' . $e->getMessage());
        return [];
    }
}

/** @return string|null validation/storage error, or null on success */
function akh_billing_account_add(string $username, string $password, string $confirmation): ?string
{
    $username = strtolower(trim($username));
    if (!akh_billing_role_schema_ensure()) return 'Could not enable accountant accounts. Apply the accountant role migration or allow the site to alter the users table.';
    if (!preg_match('/^[a-z][a-z0-9_]{2,31}$/', $username)) {
        return 'Username must be 3–32 characters: start with a letter and use only letters, numbers, or underscores.';
    }
    if (mb_strlen($password) < 8 || strlen($password) > 128) return 'Password must be 8–128 characters.';
    if (!hash_equals($password, $confirmation)) return 'Passwords do not match.';
    if (!function_exists('akh_db') || !akh_db() instanceof PDO) return 'The site database is unavailable.';

    try {
        $pdo = akh_db();
        $exists = $pdo->prepare('SELECT COUNT(*) FROM users WHERE LOWER(username)=LOWER(?)');
        $exists->execute([$username]);
        if ((int) $exists->fetchColumn() > 0) return 'That username is already used by another site account.';
        $hash = password_hash($password, PASSWORD_DEFAULT);
        if (!is_string($hash) || $hash === '') return 'Could not secure the password.';
        $insert = $pdo->prepare("INSERT INTO users (role,username,password_hash,email) VALUES ('accountant',?,?,NULL)");
        $insert->execute([$username, $hash]);
        return null;
    } catch (PDOException $e) {
        error_log('akh_billing_account_add: ' . $e->getMessage());
        if ((string) $e->getCode() === '23000') return 'That username is already used by another site account.';
        if (str_contains(strtolower($e->getMessage()), 'role')) return 'Apply the accountant role migration before creating accountant users.';
        return 'Could not save the accountant account. Check that the accountant role migration is applied.';
    } catch (Throwable $e) {
        error_log('akh_billing_account_add: ' . $e->getMessage());
        return 'Could not save the accountant account.';
    }
}

function akh_billing_account_delete(string $username): bool
{
    $username = strtolower(trim($username));
    if ($username === '' || !akh_billing_role_schema_ensure()) return false;
    try {
        $st = akh_db()->prepare("DELETE FROM users WHERE role='accountant' AND LOWER(username)=LOWER(?)");
        $st->execute([$username]);
        return $st->rowCount() === 1;
    } catch (Throwable $e) {
        error_log('akh_billing_account_delete: ' . $e->getMessage());
        return false;
    }
}

/** @return list<string> Usernames MySQL stored with the invalid ENUM value '' during earlier account creation. */
function akh_billing_orphan_accounts(): array
{
    if (!function_exists('akh_db') || !akh_db() instanceof PDO) return [];
    try {
        $st = akh_db()->query("SELECT username FROM users WHERE role='' ORDER BY username");
        return array_values(array_map(static fn($row) => (string) $row['username'], $st->fetchAll(PDO::FETCH_ASSOC)));
    } catch (Throwable $e) {
        error_log('akh_billing_orphan_accounts: ' . $e->getMessage());
        return [];
    }
}

function akh_billing_account_repair(string $username): bool
{
    $username = strtolower(trim($username));
    if ($username === '' || !akh_billing_role_schema_ensure()) return false;
    try {
        $st = akh_db()->prepare("UPDATE users SET role='accountant' WHERE LOWER(username)=LOWER(?) AND role=''");
        $st->execute([$username]);
        return $st->rowCount() === 1;
    } catch (Throwable $e) {
        error_log('akh_billing_account_repair: ' . $e->getMessage());
        return false;
    }
}
