<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once AKH_ROOT . '/includes/admin-auth.php';
require_once AKH_ROOT . '/includes/invoice-access.php';
require_once AKH_ROOT . '/includes/invoices.php';
require_once AKH_ROOT . '/includes/invoice-pdf.php';

akh_require_invoice_access();
$id = max(0, (int) ($_GET['id'] ?? 0));
$db = function_exists('akh_db') ? akh_db() : null;
$billing = null;
if ($db instanceof PDO && $id > 0) {
    $st = $db->prepare('SELECT id FROM final_delivery_billing WHERE source_invoice_id=? LIMIT 1');
    $st->execute([$id]);
    $billing = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
$invoice = $billing !== null ? akh_invoice_get($id) : null;
if ($invoice === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Linked invoice not found.';
    exit;
}
$bytes = akh_invoice_pdf_bytes($invoice);
$filename = akh_invoice_pdf_filename($invoice);
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . str_replace('"', '', $filename) . '"');
header('Content-Length: ' . (string) strlen($bytes));
header('Cache-Control: private, no-store');
echo $bytes;
