<?php

declare(strict_types=1);

require_once __DIR__ . '/tasks.php';
require_once __DIR__ . '/customer-email-store.php';
require_once __DIR__ . '/site-datetime.php';

/**
 * Invoicing requires MySQL (PDO). Schema: sql/migrations/014_invoices_and_completed_tasks.sql
 */
function akh_invoices_enabled(): bool
{
    return function_exists('akh_db_is_pdo') && akh_db_is_pdo();
}

function akh_invoices_migration_sql_path(): string
{
    return AKH_ROOT . '/sql/migrations/014_invoices_and_completed_tasks.sql';
}

function akh_invoices_table_exists(string $table): bool
{
    if (!akh_invoices_enabled()) {
        return false;
    }
    $pdo = akh_db();
    $name = $pdo->query('SELECT DATABASE()');
    if ($name === false) {
        return false;
    }
    $schema = $name->fetchColumn();
    if (!is_string($schema) || $schema === '') {
        return false;
    }
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?'
    );
    $st->execute([$schema, $table]);

    return (int) $st->fetchColumn() >= 1;
}

function akh_invoices_schema_ready(): bool
{
    return akh_invoices_table_exists('invoices')
        && akh_invoices_table_exists('invoice_lines')
        && akh_invoices_table_exists('completed_tasks');
}

/** @return array{ok: bool, error: string} */
function akh_invoices_apply_schema(): array
{
    if (!akh_invoices_enabled()) {
        return ['ok' => false, 'error' => 'MySQL is not configured for this site.'];
    }
    $path = akh_invoices_migration_sql_path();
    if (!is_file($path)) {
        return ['ok' => false, 'error' => 'Invoice migration file is missing.'];
    }
    $sql = file_get_contents($path);
    if (!is_string($sql) || trim($sql) === '') {
        return ['ok' => false, 'error' => 'Invoice migration file is empty.'];
    }
    try {
        akh_db()->exec($sql);
    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => 'Could not create invoice tables: ' . $e->getMessage()];
    }

    return akh_invoices_schema_ready()
        ? ['ok' => true, 'error' => '']
        : ['ok' => false, 'error' => 'Invoice tables were not created.'];
}

function akh_invoice_money_format_paise(int $paise, string $currency = 'INR'): string
{
    $rupees = $paise / 100;
    if ($currency === 'INR') {
        return 'Rs. ' . number_format($rupees, 2, '.', ',');
    }

    return $currency . ' ' . number_format($rupees, 2, '.', ',');
}

function akh_invoice_inr_to_paise(string $inr): int
{
    $s = str_replace([',', '₹', ' '], '', trim($inr));
    if ($s === '' || !is_numeric($s)) {
        return 0;
    }

    return max(0, (int) round((float) $s * 100));
}

function akh_invoice_studio_profile(): array
{
    $name = defined('AKH_INVOICE_STUDIO_LEGAL_NAME') ? (string) AKH_INVOICE_STUDIO_LEGAL_NAME : SITE_NAME;
    $address = defined('AKH_INVOICE_STUDIO_ADDRESS') ? trim((string) AKH_INVOICE_STUDIO_ADDRESS) : '';
    $gstin = defined('AKH_INVOICE_GSTIN') ? trim((string) AKH_INVOICE_GSTIN) : '';
    $email = defined('CONTACT_EMAIL') ? trim((string) CONTACT_EMAIL) : '';
    $defaultTaxBps = defined('AKH_INVOICE_DEFAULT_TAX_BPS') ? max(0, (int) AKH_INVOICE_DEFAULT_TAX_BPS) : 0;
    $dueDays = defined('AKH_INVOICE_DUE_DAYS') ? max(1, (int) AKH_INVOICE_DUE_DAYS) : 15;

    return [
        'name' => $name,
        'address' => $address,
        'gstin' => $gstin,
        'email' => $email,
        'default_tax_bps' => $defaultTaxBps,
        'due_days' => $dueDays,
    ];
}

/**
 * Keep completed_tasks in sync with delivered / closed studio board tasks (idempotent).
 */
function akh_invoice_sync_completed_tasks_from_board(): int
{
    if (!akh_invoices_schema_ready()) {
        return 0;
    }
    $pdo = akh_db();
    $upsert = $pdo->prepare(
        'INSERT INTO completed_tasks (task_code, client_username, title, edit_type, completed_at, amount_paise, currency)
         VALUES (?, ?, ?, ?, ?, NULL, \'INR\')
         ON DUPLICATE KEY UPDATE
           client_username = VALUES(client_username),
           title = IF(VALUES(title) <> \'\', VALUES(title), title),
           edit_type = VALUES(edit_type),
           completed_at = GREATEST(completed_at, VALUES(completed_at))'
    );
    $count = 0;
    foreach (akh_tasks_load() as $t) {
        $st = strtolower(trim((string) ($t['status'] ?? '')));
        if ($st !== 'delivered' && $st !== 'closed') {
            continue;
        }
        $client = strtolower(trim((string) ($t['client_username'] ?? '')));
        if ($client === '' || $client === 'whatsapp') {
            continue;
        }
        $code = akh_task_normalize_id((string) ($t['id'] ?? ''));
        if ($code === '') {
            continue;
        }
        $title = trim((string) ($t['title'] ?? ''));
        if ($title === '') {
            $title = akh_task_edit_type_label((string) ($t['edit_type'] ?? ''));
        }
        $completedAt = trim((string) ($t['updated_at'] ?? ''));
        if ($completedAt === '') {
            $completedAt = trim((string) ($t['created_at'] ?? ''));
        }
        if ($completedAt === '') {
            $completedAt = gmdate('Y-m-d H:i:s');
        }
        $upsert->execute([
            $code,
            $client,
            mb_substr($title, 0, 500),
            mb_substr((string) ($t['edit_type'] ?? ''), 0, 64),
            $completedAt,
        ]);
        ++$count;
    }

    return $count;
}

/**
 * Task codes already linked to a non-void invoice.
 *
 * @return array<string, true>
 */
function akh_invoice_billed_task_codes(): array
{
    if (!akh_invoices_schema_ready()) {
        return [];
    }
    $st = akh_db()->query(
        "SELECT DISTINCT il.task_code
         FROM invoice_lines il
         INNER JOIN invoices i ON i.id = il.invoice_id
         WHERE i.status <> 'void' AND il.task_code IS NOT NULL AND il.task_code <> ''"
    );
    if ($st === false) {
        return [];
    }
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $c = akh_task_normalize_id((string) ($row['task_code'] ?? ''));
        if ($c !== '') {
            $out[$c] = true;
        }
    }

    return $out;
}

/**
 * Billable rows for the invoice builder (completed_tasks + future manual rows).
 *
 * @return list<array<string, mixed>>
 */
function akh_invoice_billable_rows(?string $clientUsername = null): array
{
    if (!akh_invoices_schema_ready()) {
        return [];
    }
    akh_invoice_sync_completed_tasks_from_board();
    $billed = akh_invoice_billed_task_codes();
    $clientUsername = $clientUsername !== null ? strtolower(trim($clientUsername)) : '';

    $sql = 'SELECT id, task_code, client_username, title, edit_type, completed_at, amount_paise, currency, invoice_line_id
            FROM completed_tasks
            WHERE invoice_line_id IS NULL';
    $params = [];
    if ($clientUsername !== '') {
        $sql .= ' AND client_username = ?';
        $params[] = $clientUsername;
    }
    $sql .= ' ORDER BY completed_at DESC, task_code ASC';
    $st = akh_db()->prepare($sql);
    $st->execute($params);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $code = akh_task_normalize_id((string) ($row['task_code'] ?? ''));
        if ($code === '' || isset($billed[$code])) {
            continue;
        }
        $title = trim((string) ($row['title'] ?? ''));
        if ($title === '') {
            $title = $code;
        }
        $out[] = [
            'source_kind' => 'completed_task',
            'source_ref' => (string) ($row['id'] ?? ''),
            'task_code' => $code,
            'client_username' => strtolower(trim((string) ($row['client_username'] ?? ''))),
            'title' => $title,
            'edit_type' => (string) ($row['edit_type'] ?? ''),
            'completed_at' => (string) ($row['completed_at'] ?? ''),
            'default_amount_paise' => isset($row['amount_paise']) ? (int) $row['amount_paise'] : null,
            'currency' => (string) ($row['currency'] ?? 'INR'),
        ];
    }

    return $out;
}

function akh_invoice_next_number(): string
{
    $year = (new DateTimeImmutable('now', akh_site_timezone()))->format('Y');
    $prefix = 'INV-' . $year . '-';
    if (!akh_invoices_schema_ready()) {
        return $prefix . '0001';
    }
    $st = akh_db()->prepare(
        'SELECT invoice_number FROM invoices WHERE invoice_number LIKE ? ORDER BY id DESC LIMIT 1'
    );
    $st->execute([$prefix . '%']);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    $seq = 1;
    if (is_array($row)) {
        $last = (string) ($row['invoice_number'] ?? '');
        if (preg_match('/-(\d+)$/', $last, $m)) {
            $seq = max(1, (int) $m[1] + 1);
        }
    }

    return $prefix . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
}

/**
 * @param list<array<string, mixed>> $lines
 * @return array{ok: bool, id?: int, error?: string}
 */
function akh_invoice_create(
    string $clientUsername,
    array $lines,
    int $taxRateBps,
    ?string $notes,
    ?string $clientDisplayName,
    ?string $issuedAt,
    ?string $dueAt
): array {
    if (!akh_invoices_schema_ready()) {
        return ['ok' => false, 'error' => 'Invoice tables are not installed.'];
    }
    $clientUsername = strtolower(trim($clientUsername));
    if ($clientUsername === '') {
        return ['ok' => false, 'error' => 'Pick a client.'];
    }
    $cust = akh_customer_accounts();
    if (!isset($cust[$clientUsername])) {
        return ['ok' => false, 'error' => 'Unknown client account.'];
    }
    if ($lines === []) {
        return ['ok' => false, 'error' => 'Add at least one line item.'];
    }

    $subtotal = 0;
    $normalizedLines = [];
    $sort = 0;
    foreach ($lines as $line) {
        if (!is_array($line)) {
            continue;
        }
        $desc = trim((string) ($line['description'] ?? ''));
        if ($desc === '') {
            return ['ok' => false, 'error' => 'Each line needs a description.'];
        }
        $qty = max(1, (int) ($line['quantity'] ?? 1));
        $unit = max(0, (int) ($line['unit_amount_paise'] ?? 0));
        $lineTotal = $qty * $unit;
        $subtotal += $lineTotal;
        $normalizedLines[] = [
            'sort_order' => $sort++,
            'source_kind' => trim((string) ($line['source_kind'] ?? 'manual')),
            'source_ref' => trim((string) ($line['source_ref'] ?? '')),
            'task_code' => akh_task_normalize_id((string) ($line['task_code'] ?? '')),
            'description' => mb_substr($desc, 0, 500),
            'quantity' => $qty,
            'unit_amount_paise' => $unit,
            'line_total_paise' => $lineTotal,
        ];
    }
    if ($normalizedLines === []) {
        return ['ok' => false, 'error' => 'Add at least one line item.'];
    }

    $taxRateBps = max(0, min(5000, $taxRateBps));
    $taxPaise = (int) round($subtotal * $taxRateBps / 10000);
    $total = $subtotal + $taxPaise;

    $email = akh_customer_email_get($clientUsername);
    $profile = akh_invoice_studio_profile();
    if ($issuedAt === null || trim($issuedAt) === '') {
        $issuedAt = (new DateTimeImmutable('now', akh_site_timezone()))->format('Y-m-d');
    }
    if ($dueAt === null || trim($dueAt) === '') {
        $dueAt = (new DateTimeImmutable($issuedAt . ' 00:00:00', akh_site_timezone()))
            ->modify('+' . (int) $profile['due_days'] . ' days')
            ->format('Y-m-d');
    }

    $pdo = akh_db();
    try {
        $pdo->beginTransaction();
        $num = akh_invoice_next_number();
        $ins = $pdo->prepare(
            'INSERT INTO invoices (
                invoice_number, client_username, client_email, client_display_name, status, currency,
                subtotal_paise, tax_rate_bps, tax_paise, total_paise, notes, issued_at, due_at
             ) VALUES (?, ?, ?, ?, \'draft\', \'INR\', ?, ?, ?, ?, ?, ?, ?)'
        );
        $ins->execute([
            $num,
            $clientUsername,
            $email,
            $clientDisplayName !== null && trim($clientDisplayName) !== '' ? mb_substr(trim($clientDisplayName), 0, 255) : null,
            $subtotal,
            $taxRateBps,
            $taxPaise,
            $total,
            $notes !== null && trim($notes) !== '' ? trim($notes) : null,
            $issuedAt,
            $dueAt,
        ]);
        $invoiceId = (int) $pdo->lastInsertId();
        $lineIns = $pdo->prepare(
            'INSERT INTO invoice_lines (
                invoice_id, sort_order, source_kind, source_ref, task_code, description,
                quantity, unit_amount_paise, line_total_paise
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $linkCompleted = $pdo->prepare(
            'UPDATE completed_tasks SET invoice_line_id = ? WHERE id = ? AND invoice_line_id IS NULL'
        );
        foreach ($normalizedLines as $nl) {
            $lineIns->execute([
                $invoiceId,
                $nl['sort_order'],
                $nl['source_kind'],
                $nl['source_ref'],
                $nl['task_code'] !== '' ? $nl['task_code'] : null,
                $nl['description'],
                $nl['quantity'],
                $nl['unit_amount_paise'],
                $nl['line_total_paise'],
            ]);
            $lineId = (int) $pdo->lastInsertId();
            if ($nl['source_kind'] === 'completed_task' && $nl['source_ref'] !== '' && ctype_digit($nl['source_ref'])) {
                $linkCompleted->execute([$lineId, (int) $nl['source_ref']]);
            }
        }
        $pdo->commit();

        return ['ok' => true, 'id' => $invoiceId];
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'error' => 'Could not save invoice.'];
    }
}

/**
 * @return array<string, mixed>|null
 */
function akh_invoice_get(int $id, ?string $forClientUsername = null): ?array
{
    if (!akh_invoices_schema_ready() || $id < 1) {
        return null;
    }
    $sql = 'SELECT * FROM invoices WHERE id = ?';
    $params = [$id];
    if ($forClientUsername !== null) {
        $sql .= ' AND client_username = ? AND status IN (\'sent\', \'paid\')';
        $params[] = strtolower(trim($forClientUsername));
    }
    $st = akh_db()->prepare($sql);
    $st->execute($params);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return null;
    }
    $linesSt = akh_db()->prepare(
        'SELECT * FROM invoice_lines WHERE invoice_id = ? ORDER BY sort_order ASC, id ASC'
    );
    $linesSt->execute([$id]);
    $row['lines'] = $linesSt->fetchAll(PDO::FETCH_ASSOC);

    return $row;
}

/**
 * @return list<array<string, mixed>>
 */
function akh_invoice_list_all(): array
{
    if (!akh_invoices_schema_ready()) {
        return [];
    }
    $st = akh_db()->query(
        'SELECT id, invoice_number, client_username, status, total_paise, currency, issued_at, due_at, sent_at, created_at
         FROM invoices ORDER BY id DESC LIMIT 500'
    );
    if ($st === false) {
        return [];
    }

    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * @return list<array<string, mixed>>
 */
function akh_invoice_list_for_client(string $clientUsername): array
{
    if (!akh_invoices_schema_ready()) {
        return [];
    }
    $u = strtolower(trim($clientUsername));
    $st = akh_db()->prepare(
        "SELECT id, invoice_number, status, total_paise, currency, issued_at, due_at, sent_at
         FROM invoices
         WHERE client_username = ? AND status IN ('sent', 'paid')
         ORDER BY issued_at DESC, id DESC"
    );
    $st->execute([$u]);

    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** @return array{ok: bool, error?: string} */
function akh_invoice_void(int $id): array
{
    $inv = akh_invoice_get($id);
    if ($inv === null) {
        return ['ok' => false, 'error' => 'Invoice not found.'];
    }
    if ((string) ($inv['status'] ?? '') === 'paid') {
        return ['ok' => false, 'error' => 'Paid invoices cannot be voided.'];
    }
    $pdo = akh_db();
    try {
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE invoices SET status = 'void' WHERE id = ?")->execute([$id]);
        $pdo->prepare(
            'UPDATE completed_tasks ct
             INNER JOIN invoice_lines il ON il.id = ct.invoice_line_id
             SET ct.invoice_line_id = NULL
             WHERE il.invoice_id = ?'
        )->execute([$id]);
        $pdo->commit();

        return ['ok' => true];
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'error' => 'Could not void invoice.'];
    }
}

/** @return array{ok: bool, error?: string} */
function akh_invoice_mark_sent(int $id): array
{
    if (!akh_invoices_schema_ready()) {
        return ['ok' => false, 'error' => 'Invoice tables are not installed.'];
    }
    $st = akh_db()->prepare(
        "UPDATE invoices SET status = 'sent', sent_at = NOW() WHERE id = ? AND status = 'draft'"
    );
    $st->execute([$id]);

    return $st->rowCount() > 0
        ? ['ok' => true]
        : ['ok' => false, 'error' => 'Invoice was already sent or not found.'];
}
