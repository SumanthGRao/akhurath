<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once AKH_ROOT . '/includes/admin-auth.php';
require_once AKH_ROOT . '/includes/auth.php';
require_once AKH_ROOT . '/includes/invoices.php';
require_once AKH_ROOT . '/includes/invoice-mail.php';
require_once AKH_ROOT . '/includes/csrf.php';

akh_require_admin();

$pageTitle = 'Admin — Invoices — ' . SITE_NAME;
$bodyClass = 'page-portal admin-page admin-page--board';
$adminNavActive = 'invoices.php';
$adminConsoleActive = 'invoices';

$flash = '';
$error = '';
$profile = akh_invoice_studio_profile();
$schemaReady = akh_invoices_schema_ready();
$mysqlOk = akh_invoices_enabled();

$invoiceId = (int) ($_GET['id'] ?? 0);
$viewParam = trim((string) ($_GET['view'] ?? ''));
$view = $invoiceId > 0 ? 'detail' : ($viewParam === 'create' ? 'create' : 'list');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!akh_csrf_verify($_POST['csrf_token'] ?? null)) {
        $error = 'Security check failed. Refresh and try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'install_schema') {
            $r = akh_invoices_apply_schema();
            if ($r['ok']) {
                $flash = 'Invoice tables are ready.';
                $schemaReady = true;
            } else {
                $error = $r['error'];
            }
        } elseif ($action === 'create_invoice') {
            $client = strtolower(trim((string) ($_POST['client_username'] ?? '')));
            $taxPct = trim((string) ($_POST['tax_percent'] ?? ''));
            $taxBps = $taxPct !== '' && is_numeric($taxPct)
                ? (int) round((float) $taxPct * 100)
                : (int) $profile['default_tax_bps'];
            $notes = trim((string) ($_POST['notes'] ?? ''));
            $displayName = trim((string) ($_POST['client_display_name'] ?? ''));
            $lines = [];
            $picked = $_POST['line_pick'] ?? [];
            if (!is_array($picked)) {
                $picked = [];
            }
            foreach ($picked as $key => $on) {
                if ((string) $on !== '1') {
                    continue;
                }
                $key = (string) $key;
                $desc = trim((string) ($_POST['line_desc'][$key] ?? ''));
                $amountInr = trim((string) ($_POST['line_amount'][$key] ?? '0'));
                $sourceKind = trim((string) ($_POST['line_source_kind'][$key] ?? 'manual'));
                $sourceRef = trim((string) ($_POST['line_source_ref'][$key] ?? ''));
                $taskCode = trim((string) ($_POST['line_task_code'][$key] ?? ''));
                $lines[] = [
                    'description' => $desc,
                    'quantity' => 1,
                    'unit_amount_paise' => akh_invoice_inr_to_paise($amountInr),
                    'source_kind' => $sourceKind,
                    'source_ref' => $sourceRef,
                    'task_code' => $taskCode,
                ];
            }
            $manualDesc = trim((string) ($_POST['manual_description'] ?? ''));
            $manualAmount = trim((string) ($_POST['manual_amount'] ?? ''));
            if ($manualDesc !== '' && $manualAmount !== '') {
                $lines[] = [
                    'description' => $manualDesc,
                    'quantity' => 1,
                    'unit_amount_paise' => akh_invoice_inr_to_paise($manualAmount),
                    'source_kind' => 'manual',
                    'source_ref' => '',
                    'task_code' => '',
                ];
            }
            $created = akh_invoice_create($client, $lines, $taxBps, $notes, $displayName, null, null);
            if (!($created['ok'] ?? false)) {
                $error = (string) ($created['error'] ?? 'Could not create invoice.');
            } else {
                header('Location: ' . base_path('admin/invoices.php?id=' . (int) $created['id']));
                exit;
            }
        } elseif ($action === 'send_invoice') {
            $id = (int) ($_POST['invoice_id'] ?? 0);
            $override = trim((string) ($_POST['send_email'] ?? ''));
            $mail = akh_invoice_email_to_client($id, $override !== '' ? $override : null);
            if ($mail['skipped'] ?? false) {
                $error = $mail['error'];
            } elseif (!($mail['ok'] ?? false)) {
                $error = $mail['error'];
            } else {
                $flash = 'Invoice emailed to the client with PDF attached.';
                header('Location: ' . base_path('admin/invoices.php?id=' . $id));
                exit;
            }
        } elseif ($action === 'void_invoice') {
            $id = (int) ($_POST['invoice_id'] ?? 0);
            $void = akh_invoice_void($id);
            if (!($void['ok'] ?? false)) {
                $error = (string) ($void['error'] ?? 'Could not void invoice.');
            } else {
                $flash = 'Invoice voided. Billable tasks were released for a new invoice.';
                header('Location: ' . base_path('admin/invoices.php'));
                exit;
            }
        } else {
            $error = 'Unknown action.';
        }
    }
}

$clients = akh_customer_accounts();
ksort($clients, SORT_STRING);
$billableClient = strtolower(trim((string) ($_GET['client'] ?? '')));
$billableRows = $schemaReady ? akh_invoice_billable_rows($billableClient !== '' ? $billableClient : null) : [];
$invoices = $schemaReady ? akh_invoice_list_all() : [];
$detail = ($schemaReady && $invoiceId > 0) ? akh_invoice_get($invoiceId) : null;
if ($view === 'detail' && $detail === null) {
    $error = $error !== '' ? $error : 'Invoice not found.';
    $view = 'list';
}

require_once AKH_ROOT . '/includes/header.php';
?>

  <main id="main" class="portal-main portal-main--board">
    <div class="portal-card portal-card--tasks admin-shell admin-console">
      <header class="admin-head">
        <div>
          <h1 class="portal-title">Invoices</h1>
          <p class="portal-lead admin-head__meta">Bill completed work, download PDF, and email clients. Completed tasks sync from delivered / closed jobs and from the <code>completed_tasks</code> table.</p>
        </div>
        <div class="admin-head__actions">
          <?php require __DIR__ . '/includes/admin-console-sidebar.php'; ?>
          <a class="btn btn--ghost btn--sm" href="<?php echo h(base_path('admin/logout.php')); ?>">Sign out</a>
        </div>
      </header>

      <?php require AKH_ROOT . '/includes/admin-nav.php'; ?>

      <?php if ($flash !== ''): ?>
        <p class="banner banner--ok" role="status"><?php echo h($flash); ?></p>
      <?php endif; ?>
      <?php if ($error !== ''): ?>
        <p class="banner banner--err" role="alert"><?php echo h($error); ?></p>
      <?php endif; ?>

      <?php if (!$mysqlOk): ?>
        <p class="banner banner--err" role="alert">Invoicing needs MySQL (<code>config/database.local.php</code> or <code>AKH_DB_*</code> in <code>includes/config.php</code>).</p>
      <?php elseif (!$schemaReady): ?>
        <section class="portal-section">
          <h2 class="portal-section__title">Set up invoice tables</h2>
          <p class="portal-muted">Run once on this database (or use <code>php scripts/ensure-database.php</code> after deploy).</p>
          <form method="post" action="">
            <input type="hidden" name="csrf_token" value="<?php echo h(akh_csrf_token()); ?>" />
            <input type="hidden" name="action" value="install_schema" />
            <button type="submit" class="btn btn--primary">Create invoice tables</button>
          </form>
        </section>
      <?php elseif ($view === 'create'): ?>
        <section class="portal-section" aria-labelledby="inv-create-h">
          <h2 id="inv-create-h" class="portal-section__title">New invoice</h2>
          <p class="portal-muted"><a class="text-link" href="<?php echo h(base_path('admin/invoices.php')); ?>">← All invoices</a></p>
          <form method="get" action="" class="portal-form" style="margin-bottom:1rem">
            <label class="field">
              <span>Filter billable tasks by client</span>
              <select name="client" onchange="this.form.submit()">
                <option value="">All clients</option>
                <?php foreach ($clients as $u => $_hash): ?>
                  <option value="<?php echo h($u); ?>"<?php echo $billableClient === $u ? ' selected' : ''; ?>><?php echo h($u); ?></option>
                <?php endforeach; ?>
              </select>
            </label>
          </form>
          <form method="post" action="" class="portal-form">
            <input type="hidden" name="csrf_token" value="<?php echo h(akh_csrf_token()); ?>" />
            <input type="hidden" name="action" value="create_invoice" />
            <div class="admin-form-row">
              <label class="field">
                <span>Client account</span>
                <select name="client_username" required>
                  <option value="">— Select —</option>
                  <?php foreach ($clients as $u => $_hash): ?>
                    <option value="<?php echo h($u); ?>"<?php echo $billableClient === $u ? ' selected' : ''; ?>><?php echo h($u); ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label class="field">
                <span>Bill-to name (optional)</span>
                <input type="text" name="client_display_name" maxlength="255" placeholder="Company or couple name" />
              </label>
              <label class="field">
                <span>Tax % (GST)</span>
                <input type="text" name="tax_percent" inputmode="decimal" placeholder="<?php echo h(number_format((int) $profile['default_tax_bps'] / 100, 2)); ?>" />
              </label>
            </div>
            <label class="field">
              <span>Notes on invoice (optional)</span>
              <textarea name="notes" rows="2" maxlength="2000"></textarea>
            </label>

            <h3 class="portal-section__title">Completed work (billable)</h3>
            <?php if ($billableRows === []): ?>
              <p class="portal-muted">No unbilled completed tasks. Mark studio tasks as <strong>Delivered</strong> or <strong>Closed</strong>, or add rows to <code>completed_tasks</code> for custom billing.</p>
            <?php else: ?>
              <table class="admin-table">
                <thead>
                  <tr>
                    <th scope="col">Use</th>
                    <th scope="col">Task</th>
                    <th scope="col">Client</th>
                    <th scope="col">Completed</th>
                    <th scope="col">Amount (INR)</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($billableRows as $row): ?>
                    <?php
                    $key = (string) ($row['source_ref'] ?? '');
                    $defaultInr = '';
                    if (isset($row['default_amount_paise']) && $row['default_amount_paise'] !== null) {
                        $defaultInr = number_format((int) $row['default_amount_paise'] / 100, 2, '.', '');
                    }
                    ?>
                    <tr>
                      <td>
                        <input type="checkbox" name="line_pick[<?php echo h($key); ?>]" value="1" />
                      </td>
                      <td>
                        <strong><?php echo h((string) ($row['task_code'] ?? '')); ?></strong>
                        <input type="hidden" name="line_desc[<?php echo h($key); ?>]" value="<?php echo h((string) ($row['title'] ?? '')); ?>" />
                        <input type="hidden" name="line_source_kind[<?php echo h($key); ?>]" value="<?php echo h((string) ($row['source_kind'] ?? '')); ?>" />
                        <input type="hidden" name="line_source_ref[<?php echo h($key); ?>]" value="<?php echo h($key); ?>" />
                        <input type="hidden" name="line_task_code[<?php echo h($key); ?>]" value="<?php echo h((string) ($row['task_code'] ?? '')); ?>" />
                        <div class="portal-muted"><?php echo h((string) ($row['title'] ?? '')); ?></div>
                      </td>
                      <td><?php echo h((string) ($row['client_username'] ?? '')); ?></td>
                      <td><?php echo h((string) ($row['completed_at'] ?? '')); ?></td>
                      <td>
                        <input type="text" name="line_amount[<?php echo h($key); ?>]" inputmode="decimal" placeholder="0.00" value="<?php echo h($defaultInr); ?>" style="max-width:8rem" />
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            <?php endif; ?>

            <h3 class="portal-section__title">Manual line (optional)</h3>
            <div class="admin-form-row">
              <label class="field">
                <span>Description</span>
                <input type="text" name="manual_description" maxlength="500" />
              </label>
              <label class="field">
                <span>Amount (INR)</span>
                <input type="text" name="manual_amount" inputmode="decimal" />
              </label>
            </div>

            <button type="submit" class="btn btn--primary">Create draft invoice</button>
          </form>
        </section>
      <?php elseif ($view === 'detail' && is_array($detail)): ?>
        <section class="portal-section">
          <p class="portal-muted"><a class="text-link" href="<?php echo h(base_path('admin/invoices.php')); ?>">← All invoices</a></p>
          <h2 class="portal-section__title"><?php echo h((string) $detail['invoice_number']); ?></h2>
          <p class="portal-muted">
            Client: <strong><?php echo h((string) $detail['client_username']); ?></strong>
            · Status: <strong><?php echo h((string) $detail['status']); ?></strong>
            · Total: <strong><?php echo h(akh_invoice_money_format_paise((int) $detail['total_paise'], (string) $detail['currency'])); ?></strong>
          </p>
          <p>
            <a class="btn btn--ghost btn--sm" href="<?php echo h(base_path('admin/invoice-pdf.php?id=' . (int) $detail['id'])); ?>" target="_blank" rel="noopener">Download PDF</a>
          </p>
          <table class="admin-table">
            <thead>
              <tr>
                <th>Description</th>
                <th>Qty</th>
                <th>Rate</th>
                <th>Line total</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ((array) ($detail['lines'] ?? []) as $line): ?>
                <tr>
                  <td>
                    <?php if (!empty($line['task_code'])): ?>
                      <span class="portal-muted"><?php echo h((string) $line['task_code']); ?></span><br />
                    <?php endif; ?>
                    <?php echo h((string) ($line['description'] ?? '')); ?>
                  </td>
                  <td><?php echo (int) ($line['quantity'] ?? 1); ?></td>
                  <td><?php echo h(akh_invoice_money_format_paise((int) ($line['unit_amount_paise'] ?? 0))); ?></td>
                  <td><?php echo h(akh_invoice_money_format_paise((int) ($line['line_total_paise'] ?? 0))); ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <?php if ((string) ($detail['status'] ?? '') !== 'void'): ?>
            <form method="post" action="" class="portal-form" style="margin-top:1.5rem">
              <input type="hidden" name="csrf_token" value="<?php echo h(akh_csrf_token()); ?>" />
              <input type="hidden" name="action" value="send_invoice" />
              <input type="hidden" name="invoice_id" value="<?php echo (int) $detail['id']; ?>" />
              <label class="field">
                <span>Email to client (PDF attached)</span>
                <input type="email" name="send_email" value="<?php echo h((string) ($detail['client_email'] ?? '')); ?>" placeholder="Uses client profile email if blank" />
              </label>
              <button type="submit" class="btn btn--primary">Send invoice</button>
            </form>
            <?php if ((string) ($detail['status'] ?? '') !== 'paid'): ?>
              <form method="post" action="" onsubmit="return confirm('Void this invoice and release tasks for billing again?');" style="margin-top:1rem">
                <input type="hidden" name="csrf_token" value="<?php echo h(akh_csrf_token()); ?>" />
                <input type="hidden" name="action" value="void_invoice" />
                <input type="hidden" name="invoice_id" value="<?php echo (int) $detail['id']; ?>" />
                <button type="submit" class="btn btn--ghost">Void invoice</button>
              </form>
            <?php endif; ?>
          <?php endif; ?>
        </section>
      <?php else: ?>
        <section class="portal-section">
          <p>
            <a class="btn btn--primary btn--sm" href="<?php echo h(base_path('admin/invoices.php?view=create')); ?>">New invoice</a>
          </p>
          <?php if ($invoices === []): ?>
            <p class="portal-muted">No invoices yet.</p>
          <?php else: ?>
            <table class="admin-table">
              <thead>
                <tr>
                  <th>Invoice</th>
                  <th>Client</th>
                  <th>Status</th>
                  <th>Total</th>
                  <th>Issued</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($invoices as $inv): ?>
                  <tr>
                    <td><?php echo h((string) $inv['invoice_number']); ?></td>
                    <td><?php echo h((string) $inv['client_username']); ?></td>
                    <td><?php echo h((string) $inv['status']); ?></td>
                    <td><?php echo h(akh_invoice_money_format_paise((int) $inv['total_paise'], (string) $inv['currency'])); ?></td>
                    <td><?php echo h((string) ($inv['issued_at'] ?? '')); ?></td>
                    <td><a class="text-link" href="<?php echo h(base_path('admin/invoices.php?id=' . (int) $inv['id'])); ?>">Open</a></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </section>
      <?php endif; ?>
    </div>
  </main>

<?php require_once AKH_ROOT . '/includes/footer.php'; ?>
