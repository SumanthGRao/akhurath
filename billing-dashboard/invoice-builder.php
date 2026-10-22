<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once AKH_ROOT . '/includes/admin-auth.php';
require_once AKH_ROOT . '/includes/csrf.php';
require_once AKH_ROOT . '/includes/invoices.php';
require_once AKH_ROOT . '/includes/invoice-clients.php';
require_once AKH_ROOT . '/includes/invoice-access.php';

function billing_invoice_audit(PDO $db, int $billingId, string $actor, string $action, array $details): bool
{
    try {
        $st = $db->prepare('INSERT INTO billing_audit_log (billing_id, actor, action, details) VALUES (?, ?, ?, ?)');
        $st->execute([$billingId, $actor, $action, json_encode($details, JSON_UNESCAPED_SLASHES)]);
        return true;
    } catch (Throwable $e) {
        error_log('Billing invoice audit write failed for billing id ' . $billingId . ': ' . $e->getMessage());
        return false;
    }
}

/** Dispatch an invoice lifecycle event to n8n for the WhatsApp notification workflow. */
function billing_invoice_dispatch_webhook(string $event, string $eventId, array $billing, array $invoice, array $extra = []): bool
{
    $url = defined('AKH_N8N_INVOICE_CREATED_WEBHOOK_URL') ? trim((string) AKH_N8N_INVOICE_CREATED_WEBHOOK_URL) : '';
    $secret = defined('AKH_N8N_INVOICE_WEBHOOK_SECRET') ? trim((string) AKH_N8N_INVOICE_WEBHOOK_SECRET) : '';
    if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL) || !str_starts_with($url, 'https://') || $secret === '') {
        error_log('Billing invoice n8n webhook skipped: configure its HTTPS URL and shared secret in includes/config.php.');
        return false;
    }
    if (!function_exists('curl_init')) {
        error_log('Billing invoice n8n webhook failed: PHP cURL extension is unavailable.');
        return false;
    }

    $lines = [];
    foreach (($invoice['lines'] ?? []) as $line) {
        if (!is_array($line)) continue;
        $lines[] = [
            'description' => (string) ($line['description'] ?? ''),
            'quantity' => (int) ($line['quantity'] ?? 1),
            'unit_amount_paise' => (int) ($line['unit_amount_paise'] ?? 0),
            'line_total_paise' => (int) ($line['line_total_paise'] ?? 0),
        ];
    }
    $invoiceId = (int) ($invoice['id'] ?? 0);
    $payload = [
        'event' => $event,
        'event_id' => $eventId,
        'billing_id' => (int) ($billing['id'] ?? 0),
        'task_code' => (string) ($billing['task_code'] ?? ''),
        'customer_name' => (string) ($billing['customer_name'] ?? ''),
        'phone' => (string) ($billing['phone'] ?? ''),
        'project_name' => (string) ($billing['project_name'] ?? ''),
        'task_type' => (string) ($billing['task_type'] ?? ''),
        'invoice_id' => $invoiceId,
        'invoice_number' => (string) ($invoice['invoice_number'] ?? ''),
        'invoice_status' => (string) ($invoice['status'] ?? 'draft'),
        'currency' => (string) ($invoice['currency'] ?? 'INR'),
        'subtotal_paise' => (int) ($invoice['subtotal_paise'] ?? 0),
        'tax_rate_bps' => (int) ($invoice['tax_rate_bps'] ?? 0),
        'tax_paise' => (int) ($invoice['tax_paise'] ?? 0),
        'total_paise' => (int) ($invoice['total_paise'] ?? 0),
        'total_inr' => number_format(((int) ($invoice['total_paise'] ?? 0)) / 100, 2, '.', ''),
        'due_at' => (string) ($invoice['due_at'] ?? ''),
        'lines' => $lines,
    ] + $extra;
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_string($json)) {
        error_log('Billing invoice n8n webhook failed: could not encode event payload.');
        return false;
    }

    try {
        $ch = curl_init($url);
        if ($ch === false) return false;
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Webhook-Secret: ' . $secret],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 8,
        ]);
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        if ($status < 200 || $status >= 300) {
            error_log('Billing invoice n8n webhook failed: HTTP ' . $status . ($curlError !== '' ? ' (' . $curlError . ')' : ''));
            return false;
        }
        return true;
    } catch (Throwable $e) {
        error_log('Billing invoice n8n webhook exception: ' . $e->getMessage());
        return false;
    }
}

akh_require_invoice_access();
$requestedRaw = $_POST['billing_ids'] ?? $_GET['ids'] ?? null;
if (is_string($requestedRaw)) $requestedIds = array_values(array_unique(array_filter(array_map('intval', explode(',', $requestedRaw)), static fn($id) => $id > 0)));
elseif (is_array($requestedRaw)) $requestedIds = array_values(array_unique(array_filter(array_map('intval', $requestedRaw), static fn($id) => $id > 0)));
else $requestedIds = [];
$billingId = max(0, (int) ($_GET['id'] ?? $_POST['billing_id'] ?? ($requestedIds[0] ?? 0)));
$billingIds = $requestedIds;
$billingRows = [];
$error = '';
$flash = '';
$billing = null;
$invoice = null;
try {
    $db = function_exists('akh_db') ? akh_db() : null;
} catch (Throwable $e) {
    error_log('Billing invoice builder database connection failed: ' . $e->getMessage());
    $db = null;
    http_response_code(503);
    $error = 'The billing database is temporarily unavailable. Please return to the billing dashboard and try again.';
}

if (!$db instanceof PDO || $billingId < 1) {
    if ($error === '') {
        http_response_code(400);
        $error = 'A valid billing record is required.';
    }
} else {
    try {
        $st = $db->prepare('SELECT * FROM final_delivery_billing WHERE id=? LIMIT 1');
        $st->execute([$billingId]);
        $billing = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($billing !== null) {
            if ($billingIds === []) {
                if (!empty($billing['source_invoice_id'])) {
                    $group = $db->prepare('SELECT * FROM final_delivery_billing WHERE source_invoice_id=? ORDER BY id ASC');
                    $group->execute([$billing['source_invoice_id']]);
                    $billingRows = $group->fetchAll(PDO::FETCH_ASSOC);
                } elseif (($billing['billing_group_mode'] ?? 'auto') === 'manual' && !empty($billing['billing_group_key'])) {
                    $group = $db->prepare("SELECT * FROM final_delivery_billing WHERE billing_group_mode='manual' AND billing_group_key=? AND invoice_status='pending' AND payment_status='unpaid' AND source_invoice_id IS NULL ORDER BY id ASC");
                    $group->execute([$billing['billing_group_key']]);
                    $billingRows = $group->fetchAll(PDO::FETCH_ASSOC);
                } elseif (($billing['billing_group_mode'] ?? 'auto') === 'auto' && !empty($billing['folder_id'])) {
                    $group = $db->prepare("SELECT * FROM final_delivery_billing WHERE billing_group_mode='auto' AND folder_id=? AND customer_name=? AND phone=? AND invoice_status='pending' AND payment_status='unpaid' AND source_invoice_id IS NULL AND delivery_status='awaiting_payment' ORDER BY id ASC");
                    $group->execute([$billing['folder_id'], $billing['customer_name'], $billing['phone']]);
                    $billingRows = $group->fetchAll(PDO::FETCH_ASSOC);
                }
            } else {
                if (count($billingIds) > 50) throw new RuntimeException('A combined invoice can include at most 50 projects.');
                $marks = implode(',', array_fill(0, count($billingIds), '?'));
                $group = $db->prepare("SELECT * FROM final_delivery_billing WHERE id IN ($marks) ORDER BY id ASC");
                $group->execute($billingIds);
                $billingRows = $group->fetchAll(PDO::FETCH_ASSOC);
                if (count($billingRows) !== count($billingIds)) throw new RuntimeException('One or more selected projects no longer exist.');
            }
            if ($billingRows === []) $billingRows = [$billing];
            $billing = $billingRows[0];
            $billingId = (int) $billing['id'];
            $billingIds = array_map(static fn($row) => (int) $row['id'], $billingRows);
            foreach ($billingRows as $row) {
                $sameCustomer = strtolower(trim((string) $row['customer_name'])) === strtolower(trim((string) $billing['customer_name'])) && strtolower(trim((string) $row['phone'])) === strtolower(trim((string) $billing['phone']));
                $sameManual = ($row['billing_group_mode'] ?? 'auto') === 'manual' && ($billing['billing_group_mode'] ?? 'auto') === 'manual' && !empty($row['billing_group_key']) && hash_equals((string) $billing['billing_group_key'], (string) $row['billing_group_key']);
                $sameFolder = ($row['billing_group_mode'] ?? 'auto') === 'auto' && ($billing['billing_group_mode'] ?? 'auto') === 'auto' && !empty($row['folder_id']) && hash_equals((string) $billing['folder_id'], (string) $row['folder_id']);
                if (!$sameCustomer || (count($billingRows) > 1 && !$sameManual && !$sameFolder)) throw new RuntimeException('Selected projects must belong to the same customer and billing group. Group them from the billing queue first.');
            }
            $linkedInvoiceIds = array_values(array_unique(array_filter(array_map(static fn($row) => (int) ($row['source_invoice_id'] ?? 0), $billingRows))));
            if (count($linkedInvoiceIds) > 1) throw new RuntimeException('Selected projects are linked to different invoices. Open one invoice at a time.');
        }
    } catch (Throwable $e) {
        error_log('Billing invoice group load failed: ' . $e->getMessage());
        $error = $e instanceof RuntimeException ? $e->getMessage() : 'Billing schema is not ready. Import billing-dashboard/sql/005_billing_groups.sql and billing-dashboard/sql/003_link_invoices.sql, then reload this page.';
    }
    if ($billing === null && $error === '') {
        http_response_code(404);
        $error = 'Billing record not found.';
    }
}

$clientSlug = count($billingIds) > 1 ? 'delivery_group_' . substr(hash('sha256', implode(',', $billingIds)), 0, 24) : 'delivery_' . $billingId;
$studioProfile = akh_invoice_studio_profile();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $billing !== null && $error === '') {
    try {
    if (!akh_csrf_verify($_POST['csrf_token'] ?? null)) {
        $error = 'Security check failed. Refresh and try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'create_invoice') {
            $eligibleRows = array_filter($billingRows, static fn($row) => (string) $row['invoice_status'] === 'pending' && (string) $row['payment_status'] === 'unpaid' && empty($row['source_invoice_id']) && (string) $row['delivery_status'] === 'awaiting_payment');
            if (count($eligibleRows) !== count($billingRows)) {
                $error = 'One or more selected projects already have an invoice or are no longer awaiting one. Refresh the billing dashboard.';
            } else {
                $emailRaw = $_POST['client_email'] ?? '';
                $email = is_scalar($emailRaw) ? strtolower(trim((string) $emailRaw)) : '';
                $taxInput = $_POST['tax_percent'] ?? '';
                $taxRaw = is_scalar($taxInput) ? trim((string) $taxInput) : '';
                $amountInput = $_POST['delivery_amount'] ?? '';
                $descriptionInput = $_POST['delivery_description'] ?? '';
                $lineAmounts = [];
                $lineDescriptions = [];
                foreach ($billingRows as $row) {
                    $rowId = (int) $row['id'];
                    $rowAmount = is_array($amountInput) ? ($amountInput[$rowId] ?? '') : (count($billingRows) === 1 && is_scalar($amountInput) ? $amountInput : '');
                    $rowDescription = is_array($descriptionInput) ? ($descriptionInput[$rowId] ?? '') : (count($billingRows) === 1 && is_scalar($descriptionInput) ? $descriptionInput : '');
                    $lineAmounts[$rowId] = is_scalar($rowAmount) ? akh_invoice_inr_to_paise((string) $rowAmount) : 0;
                    $lineDescriptions[$rowId] = is_scalar($rowDescription) ? trim((string) $rowDescription) : '';
                }
                $taxBps = $taxRaw === '' ? (int) $studioProfile['default_tax_bps'] : (is_numeric($taxRaw) ? (int) round((float) $taxRaw * 100) : -1);
                if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $error = 'Enter a valid client email address, or leave it blank and download the PDF to send manually.';
                } elseif ($taxBps < 0 || $taxBps > 5000) {
                    $error = 'Tax must be between 0% and 50%.';
                } elseif (array_filter($lineAmounts, static fn($amount) => $amount < 1) || array_filter($lineDescriptions, static fn($description) => $description === '')) {
                    $error = 'Enter a description and valid amount for each project on this invoice.';
                } elseif (!akh_invoices_schema_ready()) {
                    $error = 'The studio invoice tables are not installed. An administrator must finish invoice setup first.';
                } else {
                    $client = akh_invoice_client_get($clientSlug);
                    if ($client === null) {
                        $added = akh_invoice_client_add((string) $billing['customer_name'], $email, (string) $billing['phone'], '', 'Billing delivery ' . (string) $billing['task_code'], $clientSlug);
                        $client = $added !== null ? akh_invoice_client_get($clientSlug) : null;
                    } elseif ($email !== '') {
                        akh_invoice_client_update($clientSlug, (string) $billing['customer_name'], $email, (string) $billing['phone'], (string) ($client['address'] ?? ''), (string) ($client['notes'] ?? ''));
                        $client = akh_invoice_client_get($clientSlug);
                    }
                    if ($client === null) {
                        $error = 'Could not prepare the customer for invoicing. Check server write access to data/invoice-clients.json.';
                    } else {
                        $lines = [];
                        foreach ($billingRows as $row) {
                            $rowId = (int) $row['id'];
                            $lines[] = ['description' => akh_invoice_text_substr($lineDescriptions[$rowId], 0, 500), 'quantity' => 1, 'unit_amount_paise' => $lineAmounts[$rowId], 'source_kind' => 'billing_delivery', 'source_ref' => (string) $rowId, 'task_code' => (string) $row['task_code']];
                        }
                        $descriptions = $_POST['extra_description'] ?? [];
                        $quantities = $_POST['extra_quantity'] ?? [];
                        $rates = $_POST['extra_rate'] ?? [];
                        if (is_array($descriptions) && is_array($quantities) && is_array($rates)) {
                            foreach ($descriptions as $i => $description) {
                                if (!is_scalar($description) || !is_scalar($quantities[$i] ?? 1) || !is_scalar($rates[$i] ?? '')) continue;
                                $description = trim((string) $description);
                                $qty = max(1, min(99, (int) ($quantities[$i] ?? 1)));
                                $unit = akh_invoice_inr_to_paise((string) ($rates[$i] ?? ''));
                                if ($description !== '' && $unit > 0) {
                                    $lines[] = ['description' => akh_invoice_text_substr($description, 0, 500), 'quantity' => $qty, 'unit_amount_paise' => $unit, 'source_kind' => 'billing_extra', 'source_ref' => (string) $billingId, 'task_code' => ''];
                                }
                            }
                        }
                        $notes = trim((string) ($_POST['notes'] ?? ''));
                        $created = akh_invoice_create($clientSlug, $lines, $taxBps, $notes, (string) $billing['customer_name'], null, null);
                        if (!($created['ok'] ?? false)) {
                            $error = (string) ($created['error'] ?? 'Could not create invoice.');
                        } else {
                            $invoiceId = (int) $created['id'];
                            $createdInvoice = akh_invoice_get($invoiceId);
                            $totalInr = ((int) ($createdInvoice['total_paise'] ?? 0)) / 100;
                            $invoiceNumber = (string) ($createdInvoice['invoice_number'] ?? '');
                            $pdfUrl = base_path('billing-dashboard/invoice-pdf.php?id=' . $invoiceId);
                            $db->beginTransaction();
                            $linkedCount = 0;
                            try {
                                $link = $db->prepare("UPDATE final_delivery_billing SET source_invoice_id=?, bill_amount=?, invoice_number=?, invoice_file_url=? WHERE id=? AND invoice_status='pending' AND payment_status='unpaid' AND source_invoice_id IS NULL");
                                foreach ($billingRows as $row) {
                                    $rowId = (int) $row['id'];
                                    $link->execute([$invoiceId, $lineAmounts[$rowId] / 100, $invoiceNumber, $pdfUrl, $rowId]);
                                    $linkedCount += $link->rowCount();
                                }
                                if ($linkedCount !== count($billingRows)) throw new RuntimeException('One or more projects changed before the invoice could be linked.');
                                $db->commit();
                            } catch (Throwable $e) {
                                if ($db->inTransaction()) $db->rollBack();
                            }
                            if ($linkedCount !== count($billingRows)) {
                                akh_invoice_void($invoiceId);
                                $error = 'Invoice was created but one or more projects changed before it could be linked. Refresh the billing dashboard and contact an administrator if the draft remains.';
                            } else {
                                $actor = (string) ($_SESSION['akh_billing_user'] ?? akh_admin_current() ?? 'admin');
                                $auditOk = true;
                                foreach ($billingRows as $row) {
                                    $rowOk = billing_invoice_audit($db, (int) $row['id'], $actor, 'invoice_draft_created', ['invoice_id' => $invoiceId, 'invoice_number' => $invoiceNumber, 'group_count' => count($billingRows)]);
                                    $auditOk = $rowOk && $auditOk;
                                }
                                if (!$auditOk) {
                                    $billing['source_invoice_id'] = $invoiceId;
                                    $billing['bill_amount'] = $lineAmounts[$billingId] / 100;
                                    $billing['invoice_number'] = $invoiceNumber;
                                    $billing['invoice_file_url'] = $pdfUrl;
                                    $error = 'The invoice was created and linked, but the audit table is missing, incompatible, or not writable. Run billing-dashboard/sql/004_billing_audit_log.sql and confirm the site database user can INSERT into billing_audit_log. Refresh the billing dashboard; do not create another invoice for this delivery.';
                                } else {
                                    header('Location: ' . base_path('billing-dashboard/invoice-builder.php?ids=' . implode(',', $billingIds)));
                                    exit;
                                }
                            }
                        }
                    }
                }
            }
        } elseif ($action === 'mark_manual_sent' && !empty($billing['source_invoice_id'])) {
            $invoiceId = (int) $billing['source_invoice_id'];
            $reference = trim((string) ($_POST['send_reference'] ?? ''));
            if ($reference === '' || strlen($reference) > 255) {
                $error = 'Enter where or how you sent the invoice (up to 255 characters).';
            } else {
                $markInvoice = akh_invoice_mark_sent($invoiceId);
                if (!($markInvoice['ok'] ?? false)) {
                    $error = (string) ($markInvoice['error'] ?? 'Could not mark the studio invoice as sent.');
                } else {
                    $marks = implode(',', array_fill(0, count($billingIds), '?'));
                    $mark = $db->prepare("UPDATE final_delivery_billing SET invoice_status='sent' WHERE id IN ($marks) AND source_invoice_id=? AND invoice_status='pending' AND payment_status='unpaid'");
                    $mark->execute(array_merge($billingIds, [$invoiceId]));
                    if ($mark->rowCount() !== count($billingIds)) {
                        $error = 'Invoice marked sent, but one or more billing records changed. Refresh and verify the projects before continuing.';
                    } else {
                        $actor = (string) ($_SESSION['akh_billing_user'] ?? akh_admin_current() ?? 'admin');
                        $auditOk = true;
                        foreach ($billingRows as $row) $auditOk = billing_invoice_audit($db, (int) $row['id'], $actor, 'invoice_sent_manual', ['invoice_id' => $invoiceId, 'reference' => $reference, 'group_count' => count($billingRows)]) && $auditOk;
                        $billing['invoice_status'] = 'sent';
                        if (!$auditOk) {
                            $error = 'The invoice is marked sent, but the audit table is missing, incompatible, or not writable. Run billing-dashboard/sql/004_billing_audit_log.sql and confirm the site database user can INSERT into billing_audit_log. Refresh the billing dashboard and verify the record.';
                        } elseif (!billing_invoice_dispatch_webhook(
                            'billing.invoice.sent_manual',
                            'invoice-sent-manual-' . $invoiceId,
                            $billing,
                            akh_invoice_get($invoiceId) ?? [],
                            ['send_reference' => $reference, 'deliveries' => array_map(static fn($row) => ['billing_id' => (int) $row['id'], 'task_code' => (string) $row['task_code'], 'project_name' => (string) $row['project_name'], 'file_name' => (string) $row['file_name']], $billingRows)]
                        )) {
                            $error = 'The invoice is marked sent manually, but n8n did not confirm the WhatsApp event. Check the live webhook settings and n8n execution; the event was attempted once.';
                        } else {
                            header('Location: ' . base_path('billing-dashboard/invoice-builder.php?ids=' . implode(',', $billingIds) . '&sent=1'));
                            exit;
                        }
                    }
                }
            }
        } elseif ($action === 'send_invoice' && !empty($billing['source_invoice_id'])) {
            require_once AKH_ROOT . '/includes/invoice-mail.php';
            $invoiceId = (int) $billing['source_invoice_id'];
            $override = trim((string) ($_POST['send_email'] ?? ''));
            $mail = akh_invoice_email_to_client($invoiceId, $override !== '' ? $override : null);
            if (($mail['skipped'] ?? false) || !($mail['ok'] ?? false)) {
                $error = (string) ($mail['error'] ?? 'Could not send the invoice.');
            } else {
                $marks = implode(',', array_fill(0, count($billingIds), '?'));
                $mark = $db->prepare("UPDATE final_delivery_billing SET invoice_status='sent' WHERE id IN ($marks) AND source_invoice_id=? AND invoice_status='pending' AND payment_status='unpaid'");
                $mark->execute(array_merge($billingIds, [$invoiceId]));
                if ($mark->rowCount() !== count($billingIds)) {
                    $error = 'The invoice was emailed, but one or more delivery records changed before their status could be updated. Refresh and verify the records.';
                } else {
                    $actor = (string) ($_SESSION['akh_billing_user'] ?? akh_admin_current() ?? 'admin');
                    $auditOk = true;
                    foreach ($billingRows as $row) $auditOk = billing_invoice_audit($db, (int) $row['id'], $actor, 'invoice_sent', ['invoice_id' => $invoiceId, 'group_count' => count($billingRows)]) && $auditOk;
                    $billing['invoice_status'] = 'sent';
                    if (!$auditOk) {
                        $error = 'The invoice email was sent, but the audit table is missing, incompatible, or not writable. Run billing-dashboard/sql/004_billing_audit_log.sql and confirm the site database user can INSERT into billing_audit_log. Refresh the billing dashboard and verify the record.';
                    } else {
                        header('Location: ' . base_path('billing-dashboard/invoice-builder.php?ids=' . implode(',', $billingIds) . '&sent=1'));
                        exit;
                    }
                }
            }
        }
    }
    } catch (Throwable $e) {
        error_log('Billing invoice builder submission failed for billing id ' . $billingId . ': ' . $e->getMessage());
        $error = 'The invoice could not be saved because of a server-side setup error. Please check the hosting PHP error log, then refresh this record before retrying.';
    }
}

if ($billing !== null) {
    if ((int) ($billing['source_invoice_id'] ?? 0) > 0) {
        try {
            $invoice = akh_invoice_get((int) $billing['source_invoice_id']);
        } catch (Throwable $e) {
            error_log('Billing invoice builder could not load linked invoice: ' . $e->getMessage());
            $error = $error !== '' ? $error : 'The invoice could not be loaded. Check that the studio invoice tables are installed.';
        }
        if ($invoice === null) $error = $error !== '' ? $error : 'The linked invoice could not be loaded.';
    }
}
if ((string) ($_GET['sent'] ?? '') === '1') $flash = 'Invoice emailed to the client with PDF attached.';
$csrf = akh_csrf_token();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Create invoice · Akhurath Studio</title>
  <link rel="stylesheet" href="../assets/css/invoice-document.css">
  <style>
    body{background:#f5f5f2;color:#27312c;font:16px/1.5 system-ui,sans-serif;margin:0;padding:30px 16px}.wrap{max-width:900px;margin:0 auto;background:white;border:1px solid #e1e5e0;border-radius:16px;padding:28px}.top{display:flex;justify-content:space-between;gap:16px;align-items:center}.eyebrow{text-transform:uppercase;letter-spacing:.14em;color:#6c8276;font-weight:700;font-size:12px}h1,h2{margin:.35em 0}.muted{color:#69746e}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px}.field{display:block;margin:14px 0}.field span{display:block;font-weight:650;margin-bottom:6px}.field input,.field textarea{box-sizing:border-box;width:100%;padding:12px;border:1px solid #d4dbd5;border-radius:8px;font:inherit}.line{display:grid;grid-template-columns:1fr 80px 140px;gap:10px;align-items:end}.btn{display:inline-block;border:0;border-radius:8px;background:#315a49;color:white;padding:12px 18px;font:inherit;font-weight:700;text-decoration:none;cursor:pointer}.btn.alt{background:#eef2ef;color:#315a49}.notice,.error,.ok{padding:12px 14px;border-radius:8px;margin:14px 0}.notice{background:#f0f3ef}.error{background:#faece8;color:#983e30}.ok{background:#eaf4eb;color:#356346}.invoice-summary{border-top:1px solid #e1e5e0;margin-top:24px;padding-top:20px}.actions{display:flex;flex-wrap:wrap;gap:10px;margin:18px 0}@media(max-width:600px){body{padding:12px}.wrap{padding:18px}.line{grid-template-columns:1fr 70px 100px}}
  </style>
</head>
<body><main class="wrap">
  <div class="top"><div><p class="eyebrow">Akhurath Studio · Billing</p><h1><?php echo $invoice ? 'Invoice ' . h((string) $invoice['invoice_number']) : 'Create invoice'; ?></h1></div><a class="btn alt" href="./">Back to billing</a></div>
  <?php if ($flash !== ''): ?><p class="ok"><?php echo h($flash); ?></p><?php endif; ?>
  <?php if ($error !== ''): ?><p class="error" role="alert"><?php echo h($error); ?></p><?php endif; ?>
  <?php if ($billing !== null): ?><p class="muted"><strong><?php echo h((string) $billing['customer_name']); ?></strong> · <?php echo count($billingRows) > 1 ? count($billingRows) . ' projects on this invoice' : h((string) $billing['project_name']) . ' · ' . h((string) $billing['task_code']); ?></p><?php endif; ?>
  <?php if ($invoice !== null): ?>
    <section class="invoice-summary"><p class="eyebrow">Invoice summary</p><p><strong><?php echo h((string) $invoice['invoice_number']); ?></strong> · Status: <?php echo h((string) $invoice['status']); ?> · Total: <?php echo h(akh_invoice_money_format_paise((int) $invoice['total_paise'], (string) $invoice['currency'])); ?></p>
      <div class="actions"><a class="btn alt" href="<?php echo h(base_path('billing-dashboard/invoice-pdf.php?id=' . (int) $invoice['id'])); ?>" target="_blank" rel="noopener">Preview / download PDF</a></div>
      <?php if ((string) $billing['invoice_status'] === 'pending' && (string) $invoice['status'] !== 'void'): ?>
        <form method="post"><input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>"><input type="hidden" name="billing_id" value="<?php echo $billingId; ?>"><?php foreach ($billingIds as $rowId): ?><input type="hidden" name="billing_ids[]" value="<?php echo (int) $rowId; ?>"><?php endforeach; ?><input type="hidden" name="action" value="send_invoice"><div class="grid"><label class="field"><span>Send PDF invoice by email to</span><input type="email" name="send_email" value="<?php echo h((string) ($invoice['client_email'] ?? '')); ?>" placeholder="client@example.com"></label></div><button class="btn">Email invoice and mark as sent</button></form>
        <form method="post" style="margin-top:18px"><input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>"><input type="hidden" name="billing_id" value="<?php echo $billingId; ?>"><?php foreach ($billingIds as $rowId): ?><input type="hidden" name="billing_ids[]" value="<?php echo (int) $rowId; ?>"><?php endforeach; ?><input type="hidden" name="action" value="mark_manual_sent"><label class="field"><span>Already sent this PDF outside the dashboard? Enter the recipient or reference</span><input type="text" name="send_reference" maxlength="255" placeholder="Client email or WhatsApp reference" required></label><button class="btn alt">Confirm sent manually</button><p class="muted">The billing queue becomes invoice-sent after email succeeds or you confirm manual sending here.</p></form>
      <?php endif; ?>
    </section>
  <?php elseif ($billing !== null && array_filter($billingRows, static fn($row) => (string) $row['invoice_status'] === 'pending' && empty($row['source_invoice_id']))): ?>
    <?php
      $invoiceSchemaReady = false;
      try { $invoiceSchemaReady = akh_invoices_schema_ready(); }
      catch (Throwable $e) { error_log('Billing invoice schema check failed: ' . $e->getMessage()); }
    ?>
    <?php if (!$invoiceSchemaReady): ?><p class="error">The studio invoice tables could not be verified. An administrator must finish the existing invoice setup and confirm database access before an accountant can create invoices.</p><?php else: ?>
      <form method="post" id="create-invoice-form"><input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>"><input type="hidden" name="billing_id" value="<?php echo $billingId; ?>"><?php foreach ($billingIds as $rowId): ?><input type="hidden" name="billing_ids[]" value="<?php echo (int) $rowId; ?>"><?php endforeach; ?><input type="hidden" name="action" value="create_invoice">
        <div class="grid"><label class="field"><span>Client email (optional; required to email the PDF)</span><input type="email" name="client_email" maxlength="255" placeholder="client@example.com"></label><label class="field"><span>GST / tax rate (%)</span><input type="number" name="tax_percent" min="0" max="50" step="0.01" value="<?php echo h(number_format((int) $studioProfile['default_tax_bps'] / 100, 2, '.', '')); ?>"></label></div>
        <?php foreach ($billingRows as $row): $rowId = (int) $row['id']; ?><div class="notice"><strong><?php echo h((string) $row['task_code']); ?> · <?php echo h((string) $row['project_name']); ?></strong><label class="field"><span>Invoice line</span><input type="text" name="delivery_description[<?php echo $rowId; ?>]" maxlength="500" required value="<?php echo h(trim((string) $row['project_name'] . ' — ' . (string) ($row['task_type'] ?? 'Final video') . ' (' . (string) $row['file_name'] . ')')); ?>"></label><label class="field"><span>Amount (INR)</span><input type="number" name="delivery_amount[<?php echo $rowId; ?>]" min="0.01" step="0.01" required value="<?php echo h(number_format((float) ($row['bill_amount'] ?? 0), 2, '.', '')); ?>"></label></div><?php endforeach; ?>
        <div id="extra-lines"></div><p><button type="button" class="btn alt" id="add-line">+ Add another line item</button></p>
        <label class="field"><span>Invoice notes / payment terms</span><textarea name="notes" rows="3" maxlength="2000" placeholder="Optional invoice notes"></textarea></label>
        <button class="btn">Create draft invoice</button>
      </form>
      <template id="extra-line-template"><div class="line"><label class="field"><span>Additional line description</span><input name="extra_description[]" maxlength="500"></label><label class="field"><span>Qty</span><input type="number" name="extra_quantity[]" min="1" max="99" value="1"></label><label class="field"><span>Rate (INR)</span><input type="number" name="extra_rate[]" min="0.01" step="0.01"></label></div></template>
      <script>document.getElementById('add-line').addEventListener('click',()=>document.getElementById('extra-lines').append(document.getElementById('extra-line-template').content.cloneNode(true)));</script>
    <?php endif; ?>
  <?php endif; ?>
</main></body></html>
