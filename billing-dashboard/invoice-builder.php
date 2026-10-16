<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once AKH_ROOT . '/includes/admin-auth.php';
require_once AKH_ROOT . '/includes/csrf.php';
require_once AKH_ROOT . '/includes/invoices.php';
require_once AKH_ROOT . '/includes/invoice-clients.php';
require_once AKH_ROOT . '/includes/invoice-access.php';

akh_require_invoice_access();
$billingId = max(0, (int) ($_GET['id'] ?? $_POST['billing_id'] ?? 0));
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
    } catch (Throwable $e) {
        $error = 'Billing schema is not ready. Import billing-dashboard/sql/003_link_invoices.sql, then reload this page.';
    }
    if ($billing === null && $error === '') {
        http_response_code(404);
        $error = 'Billing record not found.';
    }
}

$clientSlug = 'delivery_' . $billingId;
$studioProfile = akh_invoice_studio_profile();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $billing !== null) {
    if (!akh_csrf_verify($_POST['csrf_token'] ?? null)) {
        $error = 'Security check failed. Refresh and try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'create_invoice') {
            if ((string) $billing['invoice_status'] !== 'pending' || (string) $billing['payment_status'] !== 'unpaid' || !empty($billing['source_invoice_id'])) {
                $error = 'This delivery already has an invoice or is no longer awaiting one. Refresh the billing dashboard.';
            } else {
                $emailRaw = $_POST['client_email'] ?? '';
                $email = is_scalar($emailRaw) ? strtolower(trim((string) $emailRaw)) : '';
                $taxInput = $_POST['tax_percent'] ?? '';
                $taxRaw = is_scalar($taxInput) ? trim((string) $taxInput) : '';
                $amountInput = $_POST['delivery_amount'] ?? '';
                $descriptionInput = $_POST['delivery_description'] ?? '';
                $baseAmount = is_scalar($amountInput) ? akh_invoice_inr_to_paise((string) $amountInput) : 0;
                $baseDescription = is_scalar($descriptionInput) ? trim((string) $descriptionInput) : '';
                $taxBps = $taxRaw === '' ? (int) $studioProfile['default_tax_bps'] : (is_numeric($taxRaw) ? (int) round((float) $taxRaw * 100) : -1);
                if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $error = 'Enter a valid client email address, or leave it blank and download the PDF to send manually.';
                } elseif ($taxBps < 0 || $taxBps > 5000) {
                    $error = 'Tax must be between 0% and 50%.';
                } elseif ($baseAmount < 1 || $baseDescription === '') {
                    $error = 'Enter a valid amount and description for the final delivery.';
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
                        $lines = [[
                            'description' => mb_substr($baseDescription, 0, 500),
                            'quantity' => 1,
                            'unit_amount_paise' => $baseAmount,
                            'source_kind' => 'billing_delivery',
                            'source_ref' => (string) $billingId,
                            'task_code' => (string) $billing['task_code'],
                        ]];
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
                                    $lines[] = ['description' => mb_substr($description, 0, 500), 'quantity' => $qty, 'unit_amount_paise' => $unit, 'source_kind' => 'billing_extra', 'source_ref' => (string) $billingId, 'task_code' => ''];
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
                            $link = $db->prepare("UPDATE final_delivery_billing SET source_invoice_id=?, bill_amount=?, invoice_number=?, invoice_file_url=? WHERE id=? AND invoice_status='pending' AND payment_status='unpaid' AND source_invoice_id IS NULL");
                            $link->execute([$invoiceId, $totalInr, $invoiceNumber, $pdfUrl, $billingId]);
                            if ($link->rowCount() !== 1) {
                                akh_invoice_void($invoiceId);
                                $error = 'Invoice was created but the delivery changed before it could be linked. Refresh the billing dashboard and contact an administrator if the draft remains.';
                            } else {
                                $actor = (string) ($_SESSION['akh_billing_user'] ?? akh_admin_current() ?? 'admin');
                                $db->prepare("INSERT INTO billing_audit_log (billing_id, actor, action, details) VALUES (?, ?, 'invoice_draft_created', ?)")->execute([$billingId, $actor, json_encode(['invoice_id' => $invoiceId, 'invoice_number' => $invoiceNumber], JSON_UNESCAPED_SLASHES)]);
                                header('Location: ' . base_path('billing-dashboard/invoice-builder.php?id=' . $billingId));
                                exit;
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
                    $mark = $db->prepare("UPDATE final_delivery_billing SET invoice_status='sent' WHERE id=? AND source_invoice_id=? AND invoice_status='pending' AND payment_status='unpaid'");
                    $mark->execute([$billingId, $invoiceId]);
                    if ($mark->rowCount() !== 1) {
                        $error = 'Invoice marked sent, but the billing record changed. Refresh and verify the delivery before continuing.';
                    } else {
                        $actor = (string) ($_SESSION['akh_billing_user'] ?? akh_admin_current() ?? 'admin');
                        $db->prepare("INSERT INTO billing_audit_log (billing_id, actor, action, details) VALUES (?, ?, 'invoice_sent_manual', ?)")->execute([$billingId, $actor, json_encode(['invoice_id' => $invoiceId, 'reference' => $reference], JSON_UNESCAPED_SLASHES)]);
                        header('Location: ' . base_path('billing-dashboard/invoice-builder.php?id=' . $billingId . '&sent=1'));
                        exit;
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
                $mark = $db->prepare("UPDATE final_delivery_billing SET invoice_status='sent' WHERE id=? AND source_invoice_id=? AND invoice_status='pending' AND payment_status='unpaid'");
                $mark->execute([$billingId, $invoiceId]);
                if ($mark->rowCount() !== 1) {
                    $error = 'The invoice was emailed, but this delivery record changed before its status could be updated. Refresh and verify the record.';
                } else {
                    $actor = (string) ($_SESSION['akh_billing_user'] ?? akh_admin_current() ?? 'admin');
                    $db->prepare("INSERT INTO billing_audit_log (billing_id, actor, action, details) VALUES (?, ?, 'invoice_sent', ?)")->execute([$billingId, $actor, json_encode(['invoice_id' => $invoiceId], JSON_UNESCAPED_SLASHES)]);
                    header('Location: ' . base_path('billing-dashboard/invoice-builder.php?id=' . $billingId . '&sent=1'));
                    exit;
                }
            }
        }
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
  <?php if ($billing !== null): ?><p class="muted"><strong><?php echo h((string) $billing['customer_name']); ?></strong> · <?php echo h((string) $billing['project_name']); ?> · <?php echo h((string) $billing['task_code']); ?></p><?php endif; ?>
  <?php if ($invoice !== null): ?>
    <section class="invoice-summary"><p class="eyebrow">Invoice summary</p><p><strong><?php echo h((string) $invoice['invoice_number']); ?></strong> · Status: <?php echo h((string) $invoice['status']); ?> · Total: <?php echo h(akh_invoice_money_format_paise((int) $invoice['total_paise'], (string) $invoice['currency'])); ?></p>
      <div class="actions"><a class="btn alt" href="<?php echo h(base_path('billing-dashboard/invoice-pdf.php?id=' . (int) $invoice['id'])); ?>" target="_blank" rel="noopener">Preview / download PDF</a></div>
      <?php if ((string) $billing['invoice_status'] === 'pending' && (string) $invoice['status'] !== 'void'): ?>
        <form method="post"><input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>"><input type="hidden" name="billing_id" value="<?php echo $billingId; ?>"><input type="hidden" name="action" value="send_invoice"><div class="grid"><label class="field"><span>Send PDF invoice by email to</span><input type="email" name="send_email" value="<?php echo h((string) ($invoice['client_email'] ?? '')); ?>" placeholder="client@example.com"></label></div><button class="btn">Email invoice and mark as sent</button></form>
        <form method="post" style="margin-top:18px"><input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>"><input type="hidden" name="billing_id" value="<?php echo $billingId; ?>"><input type="hidden" name="action" value="mark_manual_sent"><label class="field"><span>Already sent this PDF outside the dashboard? Enter the recipient or reference</span><input type="text" name="send_reference" maxlength="255" placeholder="Client email or WhatsApp reference" required></label><button class="btn alt">Confirm sent manually</button><p class="muted">The billing queue becomes invoice-sent after email succeeds or you confirm manual sending here.</p></form>
      <?php endif; ?>
    </section>
  <?php elseif ($billing !== null && (string) $billing['invoice_status'] === 'pending' && empty($billing['source_invoice_id'])): ?>
    <?php
      $invoiceSchemaReady = false;
      try { $invoiceSchemaReady = akh_invoices_schema_ready(); }
      catch (Throwable $e) { error_log('Billing invoice schema check failed: ' . $e->getMessage()); }
    ?>
    <?php if (!$invoiceSchemaReady): ?><p class="error">The studio invoice tables could not be verified. An administrator must finish the existing invoice setup and confirm database access before an accountant can create invoices.</p><?php else: ?>
      <form method="post" id="create-invoice-form"><input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>"><input type="hidden" name="billing_id" value="<?php echo $billingId; ?>"><input type="hidden" name="action" value="create_invoice">
        <div class="grid"><label class="field"><span>Client email (optional; required to email the PDF)</span><input type="email" name="client_email" maxlength="255" placeholder="client@example.com"></label><label class="field"><span>GST / tax rate (%)</span><input type="number" name="tax_percent" min="0" max="50" step="0.01" value="<?php echo h(number_format((int) $studioProfile['default_tax_bps'] / 100, 2, '.', '')); ?>"></label></div>
        <label class="field"><span>Final delivery line</span><input type="text" name="delivery_description" maxlength="500" required value="<?php echo h(trim((string) $billing['project_name'] . ' — ' . (string) ($billing['task_type'] ?? 'Final video') . ' (' . (string) $billing['file_name'] . ')')); ?>"></label>
        <label class="field"><span>Amount (INR)</span><input type="number" name="delivery_amount" min="0.01" step="0.01" required value="<?php echo h(number_format((float) ($billing['bill_amount'] ?? 0), 2, '.', '')); ?>"></label>
        <div id="extra-lines"></div><p><button type="button" class="btn alt" id="add-line">+ Add another line item</button></p>
        <label class="field"><span>Invoice notes / payment terms</span><textarea name="notes" rows="3" maxlength="2000" placeholder="Optional invoice notes"></textarea></label>
        <button class="btn">Create draft invoice</button>
      </form>
      <template id="extra-line-template"><div class="line"><label class="field"><span>Additional line description</span><input name="extra_description[]" maxlength="500"></label><label class="field"><span>Qty</span><input type="number" name="extra_quantity[]" min="1" max="99" value="1"></label><label class="field"><span>Rate (INR)</span><input type="number" name="extra_rate[]" min="0.01" step="0.01"></label></div></template>
      <script>document.getElementById('add-line').addEventListener('click',()=>document.getElementById('extra-lines').append(document.getElementById('extra-line-template').content.cloneNode(true)));</script>
    <?php endif; ?>
  <?php endif; ?>
</main></body></html>
