<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once AKH_ROOT . '/includes/auth.php';
require_once AKH_ROOT . '/includes/invoices.php';
require_once AKH_ROOT . '/includes/invoice-template.php';

akh_require_customer();

$user = (string) akh_customer_current();
$id = (int) ($_GET['id'] ?? 0);
$inv = $id > 0 ? akh_invoice_get($id, $user) : null;
if ($inv === null) {
    http_response_code(404);
    echo 'Invoice not found.';
    exit;
}

$pageTitle = 'Invoice ' . (string) ($inv['invoice_number'] ?? '') . ' — ' . SITE_NAME;
$bodyClass = 'page-invoice-print';
$cssVer = is_file(AKH_ROOT . '/assets/css/invoice-document.css') ? (string) filemtime(AKH_ROOT . '/assets/css/invoice-document.css') : '1';
require_once AKH_ROOT . '/includes/header.php';
?>

  <main id="main" class="portal-main">
    <div class="inv-print-toolbar" style="max-width:820px;margin:0 auto 1rem;display:flex;gap:0.5rem;flex-wrap:wrap">
      <a class="btn btn--ghost btn--sm" href="<?php echo h(base_path('customer/invoices.php')); ?>">← Invoices</a>
      <a class="btn btn--primary btn--sm" href="<?php echo h(base_path('customer/invoice-pdf.php?id=' . $id)); ?>" target="_blank" rel="noopener">Download PDF</a>
      <button type="button" class="btn btn--ghost btn--sm" onclick="window.print()">Print</button>
    </div>
    <?php echo akh_invoice_render_html($inv); ?>
  </main>
  <link rel="stylesheet" href="<?php echo h(base_path('assets/css/invoice-document.css')); ?>?v=<?php echo h($cssVer); ?>" />

<?php require_once AKH_ROOT . '/includes/footer.php'; ?>
