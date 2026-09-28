<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once AKH_ROOT . '/includes/auth.php';
require_once AKH_ROOT . '/includes/invoices.php';

akh_require_customer();

$user = (string) akh_customer_current();
$pageTitle = 'Invoices — ' . SITE_NAME;
$bodyClass = 'page-portal page-portal--board';

$rows = [];
if (akh_invoices_schema_ready()) {
    $rows = akh_invoice_list_for_client($user);
}

require_once AKH_ROOT . '/includes/header.php';
?>

  <main id="main" class="portal-main portal-main--board">
    <div class="portal-card portal-card--tasks">
      <header class="admin-head">
        <div>
          <h1 class="portal-title">Invoices</h1>
          <p class="portal-lead admin-head__meta">Download PDF copies of invoices sent by the studio.</p>
        </div>
      </header>

      <?php if (!akh_invoices_schema_ready()): ?>
        <p class="portal-muted">Invoicing is not available on this site yet.</p>
      <?php elseif ($rows === []): ?>
        <p class="portal-muted">No invoices have been sent to your account yet.</p>
      <?php else: ?>
        <table class="admin-table">
          <thead>
            <tr>
              <th>Invoice</th>
              <th>Status</th>
              <th>Total</th>
              <th>Issued</th>
              <th>Due</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $inv): ?>
              <tr>
                <td><?php echo h((string) $inv['invoice_number']); ?></td>
                <td><?php echo h((string) $inv['status']); ?></td>
                <td><?php echo h(akh_invoice_money_format_paise((int) $inv['total_paise'], (string) $inv['currency'])); ?></td>
                <td><?php echo h((string) ($inv['issued_at'] ?? '')); ?></td>
                <td><?php echo h((string) ($inv['due_at'] ?? '')); ?></td>
                <td>
                  <a class="text-link" href="<?php echo h(base_path('customer/invoice-print.php?id=' . (int) $inv['id'])); ?>" target="_blank" rel="noopener">View</a>
                  ·
                  <a class="text-link" href="<?php echo h(base_path('customer/invoice-pdf.php?id=' . (int) $inv['id'])); ?>" target="_blank" rel="noopener">PDF</a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>

      <p class="portal-foot" style="margin-top:1.5rem">
        <a class="text-link" href="<?php echo h(base_path('customer/dashboard.php')); ?>">← Tasks</a>
        ·
        <a class="text-link" href="<?php echo h(base_path('customer/logout.php')); ?>">Sign out</a>
      </p>
    </div>
  </main>

<?php require_once AKH_ROOT . '/includes/footer.php'; ?>
