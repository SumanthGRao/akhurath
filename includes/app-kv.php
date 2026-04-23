<?php

declare(strict_types=1);

/**
 * Small key/value store in MySQL (JSON blobs for tasks, attendance, etc.).
 */
function akh_kv_get(string $key): ?string
{
    $st = akh_db()->prepare('SELECT v FROM app_kv WHERE k = ?');
    $st->execute([$key]);
    $row = $st->fetch();

    return $row === false ? null : (string) $row['v'];
}

function akh_kv_set(string $key, string $value): void
{
    $st = akh_db()->prepare(
        'INSERT INTO app_kv (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)'
    );
    $st->execute([$key, $value]);
}

function akh_kv_set_with_pdo(PDO $pdo, string $key, string $value): void
{
    $st = $pdo->prepare(
        'INSERT INTO app_kv (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)'
    );
    $st->execute([$key, $value]);
}
