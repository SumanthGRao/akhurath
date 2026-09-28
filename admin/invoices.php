<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once AKH_ROOT . '/includes/admin-auth.php';
require_once AKH_ROOT . '/includes/invoices.php';
require_once AKH_ROOT . '/includes/invoice-clients.php';
require_once AKH_ROOT . '/includes/invoice-mail.php';
require_once AKH_ROOT . '/includes/invoice-services.php';
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
$view = $invoiceId > 0 ? 'detail' : ($viewParam === 'create' ? 'create' : ($viewParam === 'clients' ? 'clients' : 'list'));

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
            $svcRows = $_POST['svc'] ?? [];
            if (is_array($svcRows)) {
                foreach ($svcRows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $key = trim((string) ($row['key'] ?? ''));
                    $qty = (int) ($row['qty'] ?? 0);
                    if ($qty < 1) {
                        continue;
                    }
                    $rateInr = trim((string) ($row['rate_inr'] ?? ''));
                    $unitPaise = akh_invoice_inr_to_paise($rateInr);
                    if ($unitPaise <= 0) {
                        continue;
                    }
                    $customLabel = trim((string) ($row['custom_label'] ?? ''));
                    $desc = trim((string) ($row['description'] ?? ''));
                    if ($desc === '') {
                        $desc = $key === 'custom' || $key === ''
                            ? $customLabel
                            : akh_invoice_service_label($key);
                    }
                    if ($desc === '') {
                        $desc = $customLabel !== '' ? $customLabel : 'Service';
                    }
                    $lines[] = [
                        'description' => $desc,
                        'quantity' => $qty,
                        'unit_amount_paise' => $unitPaise,
                        'source_kind' => 'service',
                        'source_ref' => $key,
                        'task_code' => '',
                    ];
                }
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
        } elseif ($action === 'add_invoice_client') {
            $name = trim((string) ($_POST['client_display_name'] ?? ''));
            $email = trim((string) ($_POST['client_email'] ?? ''));
            $phone = trim((string) ($_POST['client_phone'] ?? ''));
            $address = trim((string) ($_POST['client_address'] ?? ''));
            $notes = trim((string) ($_POST['client_notes'] ?? ''));
            $slug = trim((string) ($_POST['client_slug'] ?? ''));
            if ($name === '') {
                $error = 'Client name is required.';
            } else {
                $newSlug = akh_invoice_client_add($name, $email, $phone, $address, $notes, $slug !== '' ? $slug : null);
                if ($newSlug === null) {
                    $error = 'Could not add client. Check the name and that the reference ID is not already used.';
                } else {
                    $flash = 'Client added to your invoice list.';
                    header('Location: ' . base_path('admin/invoices.php?view=clients'));
                    exit;
                }
            }
        } elseif ($action === 'delete_invoice_client') {
            $slug = trim((string) ($_POST['client_slug'] ?? ''));
            if (!akh_invoice_client_delete($slug)) {
                $error = 'Could not remove that client.';
            } else {
                $flash = 'Client removed from your invoice list.';
                header('Location: ' . base_path('admin/invoices.php?view=clients'));
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

$invoiceClients = akh_invoice_clients_list();
$billableRows = $schemaReady ? akh_invoice_billable_rows(null) : [];
$serviceCatalog = akh_invoice_service_catalog();
$invCssVer = is_file(AKH_ROOT . '/assets/css/invoice-document.css') ? (string) filemtime(AKH_ROOT . '/assets/css/invoice-document.css') : '1';
$invJsVer = is_file(AKH_ROOT . '/assets/js/admin-invoice-builder.js') ? (string) filemtime(AKH_ROOT . '/assets/js/admin-invoice-builder.js') : '1';
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
          <p class="portal-lead admin-head__meta">Manage your own invoice client list (not portal logins), build multi-service invoices, and email PDFs.</p>
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
      <?php elseif ($view === 'clients'): ?>
        <section class="portal-section" aria-labelledby="inv-clients-h">
          <h2 id="inv-clients-h" class="portal-section__title">Invoice clients</h2>
          <p class="portal-muted">
            <a class="text-link" href="<?php echo h(base_path('admin/invoices.php')); ?>">← All invoices</a>
            · These contacts are only for billing — not pulled from the client portal database.
          </p>
          <form method="post" action="" class="portal-form">
            <input type="hidden" name="csrf_token" value="<?php echo h(akh_csrf_token()); ?>" />
            <input type="hidden" name="action" value="add_invoice_client" />
            <h3 class="portal-section__title">Add client</h3>
            <div class="admin-form-row">
              <label class="field">
                <span>Name (on invoice)</span>
                <input type="text" name="client_display_name" required maxlength="255" />
              </label>
              <label class="field">
                <span>Email (for sending PDF)</span>
                <input type="email" name="client_email" maxlength="255" />
              </label>
              <label class="field">
                <span>Reference ID (optional)</span>
                <input type="text" name="client_slug" maxlength="64" pattern="[a-z0-9_-]{2,64}" placeholder="auto-generated if blank" />
              </label>
            </div>
            <div class="admin-form-row">
              <label class="field">
                <span>Phone</span>
                <input type="text" name="client_phone" maxlength="40" />
              </label>
              <label class="field">
                <span>Address (optional)</span>
                <input type="text" name="client_address" maxlength="500" />
              </label>
            </div>
            <label class="field">
              <span>Internal notes</span>
              <textarea name="client_notes" rows="2" maxlength="2000"></textarea>
            </label>
            <button type="submit" class="btn btn--primary">Add client</button>
          </form>
          <h3 class="portal-section__title" style="margin-top:1.5rem">All clients</h3>
          <?php if ($invoiceClients === []): ?>
            <p class="portal-muted">No clients yet. Add your first billing contact above.</p>
          <?php else: ?>
            <table class="admin-table">
              <thead>
                <tr>
                  <th>Name</th>
                  <th>Email</th>
                  <th>Reference</th>
                  <th>Phone</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($invoiceClients as $c): ?>
                  <tr>
                    <td><?php echo h((string) $c['display_name']); ?></td>
                    <td><?php echo h((string) $c['email']); ?></td>
                    <td><code><?php echo h((string) $c['slug']); ?></code></td>
                    <td><?php echo h((string) $c['phone']); ?></td>
                    <td>
                      <form method="post" action="" onsubmit="return confirm('Remove this client from your invoice list?');">
                        <input type="hidden" name="csrf_token" value="<?php echo h(akh_csrf_token()); ?>" />
                        <input type="hidden" name="action" value="delete_invoice_client" />
                        <input type="hidden" name="client_slug" value="<?php echo h((string) $c['slug']); ?>" />
                        <button type="submit" class="btn btn--ghost btn--sm">Remove</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </section>
      <?php elseif ($view === 'create'): ?>
        <section class="portal-section" aria-labelledby="inv-create-h">
          <h2 id="inv-create-h" class="portal-section__title">New invoice</h2>
          <p class="portal-muted">
            <a class="text-link" href="<?php echo h(base_path('admin/invoices.php')); ?>">← All invoices</a>
            · <a class="text-link" href="<?php echo h(base_path('admin/invoices.php?view=clients')); ?>">Manage clients</a>
          </p>
          <?php if ($invoiceClients === []): ?>
            <p class="banner banner--info" role="status">Add at least one client under <a class="text-link" href="<?php echo h(base_path('admin/invoices.php?view=clients')); ?>">Invoice clients</a> before creating an invoice.</p>
          <?php endif; ?>
          <form method="post" action="" class="portal-form">
            <input type="hidden" name="csrf_token" value="<?php echo h(akh_csrf_token()); ?>" />
            <input type="hidden" name="action" value="create_invoice" />
            <div class="admin-form-row">
              <label class="field">
                <span>Bill to</span>
                <select name="client_username" required<?php echo $invoiceClients === [] ? ' disabled' : ''; ?>>
                  <option value="">— Select client —</option>
                  <?php foreach ($invoiceClients as $c): ?>
                    <option value="<?php echo h((string) $c['slug']); ?>">
                      <?php echo h((string) $c['display_name']); ?><?php echo (string) $c['email'] !== '' ? ' — ' . h((string) $c['email']) : ''; ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label class="field">
                <span>Override bill-to name (optional)</span>
                <input type="text" name="client_display_name" maxlength="255" placeholder="Uses client name from list if blank" />
              </label>
              <label class="field">
                <span>Tax % (GST)</span>
                <input type="text" id="inv-tax-percent" name="tax_percent" inputmode="decimal" placeholder="<?php echo h(number_format((int) $profile['default_tax_bps'] / 100, 2)); ?>" value="<?php echo (int) $profile['default_tax_bps'] > 0 ? h(number_format((int) $profile['default_tax_bps'] / 100, 2)) : ''; ?>" />
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

            <h3 class="portal-section__title">Studio services</h3>
            <p class="portal-muted">Add one or more services. Line totals and invoice total update as you type.</p>
            <div id="inv-builder" class="inv-builder" data-catalog="<?php echo h(json_encode($serviceCatalog, JSON_THROW_ON_ERROR)); ?>">
              <table class="admin-table inv-builder__services">
                <thead>
                  <tr>
                    <th>Service type</th>
                    <th>Description</th>
                    <th>Qty</th>
                    <th>Rate (INR)</th>
                    <th>Line total</th>
                    <th></th>
                  </tr>
                </thead>
                <tbody id="inv-builder-rows"></tbody>
              </table>
              <p><button type="button" class="btn btn--ghost btn--sm" id="inv-builder-add">+ Add service</button></p>
              <div class="inv-builder__totals" aria-live="polite">
                <dl>
                  <dt>Subtotal</dt>
                  <dd id="inv-live-subtotal">Rs. 0.00</dd>
                  <dt>Tax</dt>
                  <dd id="inv-live-tax">Rs. 0.00</dd>
                </dl>
                <div class="inv-builder__grand">
                  <span>Total</span>
                  <span id="inv-live-total">Rs. 0.00</span>
                </div>
              </div>
            </div>
            <template id="inv-builder-row-tpl">
              <tr class="inv-builder__row">
                <td>
                  <select class="inv-builder__service" name="svc[__IDX__][key]">
                    <?php foreach ($serviceCatalog as $sk => $meta): ?>
                      <option value="<?php echo h($sk); ?>"><?php echo h((string) $meta['label']); ?></option>
                    <?php endforeach; ?>
                  </select>
                  <label class="inv-builder__custom-wrap field" hidden>
                    <span class="visually-hidden">Custom label</span>
                    <input type="text" class="inv-builder__custom-label" name="svc[__IDX__][custom_label]" maxlength="500" placeholder="Short label for custom service" />
                  </label>
                </td>
                <td>
                  <input type="text" class="inv-builder__description" name="svc[__IDX__][description]" maxlength="500" placeholder="What you are billing for" style="min-width:12rem" required />
                </td>
                <td><input type="number" class="inv-builder__qty" name="svc[__IDX__][qty]" min="1" max="99" value="1" style="max-width:4rem" /></td>
                <td><input type="text" class="inv-builder__rate" name="svc[__IDX__][rate_inr]" inputmode="decimal" style="max-width:7rem" /></td>
                <td class="inv-builder__line-total">Rs. 0.00</td>
                <td><button type="button" class="btn btn--ghost btn--sm inv-builder__remove" aria-label="Remove row">×</button></td>
              </tr>
            </template>

            <button type="submit" class="btn btn--primary" style="margin-top:1rem"<?php echo $invoiceClients === [] ? ' disabled' : ''; ?>>Create draft invoice</button>
          </form>
          <link rel="stylesheet" href="<?php echo h(base_path('assets/css/invoice-document.css')); ?>?v=<?php echo h($invCssVer); ?>" />
          <script src="<?php echo h(base_path('assets/js/admin-invoice-builder.js')); ?>?v=<?php echo h($invJsVer); ?>" defer></script>
        </section>
      <?php elseif ($view === 'detail' && is_array($detail)): ?>
        <section class="portal-section">
          <p class="portal-muted"><a class="text-link" href="<?php echo h(base_path('admin/invoices.php')); ?>">← All invoices</a></p>
          <h2 class="portal-section__title"><?php echo h((string) $detail['invoice_number']); ?></h2>
          <p class="portal-muted">
            Client: <strong><?php echo h(akh_invoice_client_display_label((string) $detail['client_username'])); ?></strong>
            <span class="portal-muted">(<?php echo h((string) $detail['client_username']); ?>)</span>
            · Status: <strong><?php echo h((string) $detail['status']); ?></strong>
            · Total: <strong><?php echo h(akh_invoice_money_format_paise((int) $detail['total_paise'], (string) $detail['currency'])); ?></strong>
          </p>
          <p>
            <a class="btn btn--ghost btn--sm" href="<?php echo h(base_path('admin/invoice-print.php?id=' . (int) $detail['id'])); ?>" target="_blank" rel="noopener">Preview template</a>
            <a class="btn btn--ghost btn--sm" href="<?php echo h(base_path('admin/invoice-pdf.php?id=' . (int) $detail['id'])); ?>" target="_blank" rel="noopener">Download PDF</a>
          </p>
          <div style="margin:1rem 0;border:1px solid #e8dfd4;border-radius:8px;padding:0.5rem;background:#fff">
            <?php
            require_once AKH_ROOT . '/includes/invoice-template.php';
            echo akh_invoice_render_html($detail);
            ?>
          </div>
          <link rel="stylesheet" href="<?php echo h(base_path('assets/css/invoice-document.css')); ?>?v=<?php echo h($invCssVer); ?>" />
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
            <a class="btn btn--ghost btn--sm" href="<?php echo h(base_path('admin/invoices.php?view=clients')); ?>">Invoice clients</a>
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
                    <td><?php echo h(akh_invoice_client_display_label((string) $inv['client_username'])); ?></td>
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
