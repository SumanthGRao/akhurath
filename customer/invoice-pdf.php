<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once AKH_ROOT . '/includes/auth.php';
require_once AKH_ROOT . '/includes/invoices.php';
require_once AKH_ROOT . '/includes/invoice-pdf.php';

akh_require_customer();

$user = (string) akh_customer_current();
$id = (int) ($_GET['id'] ?? 0);
$inv = $id > 0 ? akh_invoice_get($id, $user) : null;
if ($inv === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Invoice not found.';
    exit;
}

$bytes = akh_invoice_pdf_bytes($inv);
$filename = akh_invoice_pdf_filename($inv);
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . str_replace('"', '', $filename) . '"');
header('Content-Length: ' . (string) strlen($bytes));
header('Cache-Control: private, no-store');
echo $bytes;
