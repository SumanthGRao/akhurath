<?php

declare(strict_types=1);

/**
 * whatsapp_contacts (production) — resolve customer phone for a studio task via whatsapp_tasks.
 */

function akh_whatsapp_contacts_table_exists(): bool
{
    if (!function_exists('akh_db_is_pdo') || !akh_db_is_pdo()) {
        return false;
    }
    try {
        $name = akh_db()->query('SELECT DATABASE()');
        if ($name === false) {
            return false;
        }
        $schema = $name->fetchColumn();
        if (!is_string($schema) || $schema === '') {
            return false;
        }
        $st = akh_db()->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?'
        );
        $st->execute([$schema, 'whatsapp_contacts']);

        return (int) $st->fetchColumn() >= 1;
    } catch (\Throwable $e) {
        return false;
    }
}

function akh_whatsapp_contacts_column_exists(string $column): bool
{
    if (!akh_whatsapp_contacts_table_exists()) {
        return false;
    }
    try {
        $name = akh_db()->query('SELECT DATABASE()');
        if ($name === false) {
            return false;
        }
        $schema = $name->fetchColumn();
        if (!is_string($schema) || $schema === '') {
            return false;
        }
        $st = akh_db()->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $st->execute([$schema, 'whatsapp_contacts', $column]);

        return (int) $st->fetchColumn() >= 1;
    } catch (\Throwable $e) {
        return false;
    }
}

function akh_whatsapp_contacts_phone_column(): ?string
{
    foreach (['phone', 'mobile', 'whatsapp_phone', 'phone_number', 'mobile_number', 'wa_phone'] as $candidate) {
        if (akh_whatsapp_contacts_column_exists($candidate)) {
            return $candidate;
        }
    }

    return null;
}

function akh_whatsapp_contacts_id_column(): ?string
{
    foreach (['id', 'contact_id', 'customer_id'] as $candidate) {
        if (akh_whatsapp_contacts_column_exists($candidate)) {
            return $candidate;
        }
    }

    return null;
}

/**
 * @return array<string, mixed>|null
 */
function akh_whatsapp_contact_by_id(int $contactId): ?array
{
    if ($contactId < 1 || !akh_whatsapp_contacts_table_exists()) {
        return null;
    }
    $idCol = akh_whatsapp_contacts_id_column();
    if ($idCol === null) {
        return null;
    }
    try {
        $st = akh_db()->prepare('SELECT * FROM whatsapp_contacts WHERE `' . $idCol . '` = ? LIMIT 1');
        $st->execute([$contactId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    } catch (\Throwable $e) {
        error_log('akh_whatsapp_contact_by_id: ' . $e->getMessage());

        return null;
    }
}

/**
 * Raw phone strings for a studio task (whatsapp_tasks + whatsapp_contacts join).
 *
 * @return list<string>
 */
function akh_whatsapp_phones_for_task_code(string $taskCode): array
{
    require_once __DIR__ . '/tasks.php';
    require_once __DIR__ . '/whatsapp-tasks.php';

    $taskCode = akh_task_normalize_id(trim($taskCode));
    if ($taskCode === '') {
        return [];
    }

    $phones = [];
    $add = static function (string $raw) use (&$phones): void {
        $raw = trim($raw);
        if ($raw !== '' && !in_array($raw, $phones, true)) {
            $phones[] = $raw;
        }
    };

    $variants = akh_task_id_match_variants($taskCode);
    if ($variants !== [] && akh_wa_tasks_table_exists() && akh_whatsapp_contacts_table_exists()) {
        $phoneCol = akh_whatsapp_contacts_phone_column();
        $contactIdCol = akh_whatsapp_contacts_id_column();
        if ($phoneCol !== null && $contactIdCol !== null && akh_whatsapp_contacts_column_exists('customer_id')) {
            $placeholders = implode(',', array_fill(0, count($variants), '?'));
            try {
                $sql = 'SELECT t.phone AS wa_task_phone, c.`' . $phoneCol . '` AS contact_phone
                        FROM whatsapp_tasks t
                        LEFT JOIN whatsapp_contacts c ON c.`customer_id` = t.customer_id
                        WHERE TRIM(t.task_code) IN (' . $placeholders . ')
                        LIMIT 1';
                $st = akh_db()->prepare($sql);
                $st->execute($variants);
                $row = $st->fetch(PDO::FETCH_ASSOC);
                if (is_array($row)) {
                    $add((string) ($row['wa_task_phone'] ?? ''));
                    $add((string) ($row['contact_phone'] ?? ''));
                }
            } catch (\Throwable $e) {
                error_log('akh_whatsapp_phones_for_task_code join customer_id: ' . $e->getMessage());
            }
        }
        if ($phoneCol !== null && $contactIdCol !== null) {
            $placeholders = implode(',', array_fill(0, count($variants), '?'));
            try {
                $sql = 'SELECT t.phone AS wa_task_phone, c.`' . $phoneCol . '` AS contact_phone
                        FROM whatsapp_tasks t
                        LEFT JOIN whatsapp_contacts c ON c.`' . $contactIdCol . '` = t.customer_id
                        WHERE TRIM(t.task_code) IN (' . $placeholders . ')
                        LIMIT 1';
                $st = akh_db()->prepare($sql);
                $st->execute($variants);
                $row = $st->fetch(PDO::FETCH_ASSOC);
                if (is_array($row)) {
                    $add((string) ($row['wa_task_phone'] ?? ''));
                    $add((string) ($row['contact_phone'] ?? ''));
                }
            } catch (\Throwable $e) {
                error_log('akh_whatsapp_phones_for_task_code join id: ' . $e->getMessage());
            }
        }
    }

    $wa = akh_wa_task_by_code($taskCode);
    if (is_array($wa)) {
        $add((string) ($wa['phone'] ?? ''));
        $customerId = (int) ($wa['customer_id'] ?? 0);
        if ($customerId > 0) {
            $contact = akh_whatsapp_contact_by_id($customerId);
            if (is_array($contact)) {
                $phoneCol = akh_whatsapp_contacts_phone_column();
                if ($phoneCol !== null) {
                    $add((string) ($contact[$phoneCol] ?? ''));
                }
                foreach ($contact as $key => $value) {
                    if (!is_string($key) || !preg_match('/phone|mobile|whatsapp/i', $key)) {
                        continue;
                    }
                    $add((string) $value);
                }
            }
        }
    }

    require_once __DIR__ . '/whatsapp-messages.php';
    $add(akh_wa_message_phone_for_task($taskCode));

    return $phones;
}

function akh_wa_tasks_table_exists(): bool
{
    if (!function_exists('akh_db_is_pdo') || !akh_db_is_pdo()) {
        return false;
    }
    try {
        $st = akh_db()->query("SHOW TABLES LIKE 'whatsapp_tasks'");

        return $st !== false && $st->fetch(PDO::FETCH_NUM) !== false;
    } catch (\Throwable $e) {
        return false;
    }
}
