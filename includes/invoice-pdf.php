<?php

declare(strict_types=1);

require_once __DIR__ . '/simple-pdf.php';
require_once __DIR__ . '/invoice-template.php';

/**
 * @param array<string, mixed> $invoice Must include lines[]
 */
function akh_invoice_pdf_bytes(array $invoice): string
{
    $vm = akh_invoice_template_view_model($invoice);
    $profile = $vm['profile'];
    $inv = $vm['invoice'];
    $pdf = new AkhSimplePdf();

    $left = 40.0;
    $right = 555.0;
    $pdf->fillRect($left, 0, 515, 52, 0.18, 0.12, 0.08);
    $pdf->text($left + 8, 22, (string) $profile['name'], 14, true);
    $pdf->textRight($right - 8, 22, (string) $vm['title'], 16, true);
    $pdf->textRight($right - 8, 40, 'No. ' . (string) ($inv['invoice_number'] ?? ''), 10, false);

    $y = 64.0;
    if (trim((string) $profile['address']) !== '') {
        foreach (preg_split('/\r\n|\r|\n/', (string) $profile['address']) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $pdf->text($left, $y, $line, 9);
            $y += 12;
        }
    }
    if (trim((string) $profile['gstin']) !== '') {
        $pdf->text($left, $y, 'GSTIN: ' . (string) $profile['gstin'], 9);
        $y += 12;
    }
    if (trim((string) $profile['email']) !== '') {
        $pdf->text($left, $y, (string) $profile['email'], 9);
        $y += 12;
    }

    $metaY = 64.0;
    $issued = (string) ($inv['issued_at'] ?? '');
    if ($issued !== '') {
        $pdf->textRight($right, $metaY, 'Date: ' . $issued, 9);
        $metaY += 12;
    }
    $due = (string) ($inv['due_at'] ?? '');
    if ($due !== '') {
        $pdf->textRight($right, $metaY, 'Due: ' . $due, 9);
        $metaY += 12;
    }

    $y = max($y, $metaY) + 14;
    $pdf->line($left, $y, $right, $y);
    $y += 14;
    $pdf->text($left, $y, 'Bill to', 10, true);
    $y += 14;
    $pdf->text($left, $y, (string) $vm['bill_name'], 11, true);
    $y += 13;
    if ($vm['bill_email'] !== '') {
        $pdf->text($left, $y, $vm['bill_email'], 9);
        $y += 12;
    }

    $y += 10;
    $tableTop = $y;
    $colNo = $left;
    $colDesc = $left + 22;
    $colQty = 360;
    $colRate = 410;
    $colAmt = $right;
    $pdf->fillRect($left, $tableTop, 515, 18, 0.93, 0.9, 0.86);
    $pdf->text($colNo + 4, $tableTop + 13, '#', 9, true);
    $pdf->text($colDesc, $tableTop + 13, 'Service / particulars', 9, true);
    $pdf->text($colQty, $tableTop + 13, 'Qty', 9, true);
    $pdf->text($colRate, $tableTop + 13, 'Rate', 9, true);
    $pdf->textRight($colAmt, $tableTop + 13, 'Amount', 9, true);
    $y = $tableTop + 22;

    foreach ($vm['lines'] as $row) {
        $pdf->line($left, $y - 4, $right, $y - 4, 0.3);
        $pdf->text($colNo + 4, $y + 8, (string) $row['no'], 9);
        $desc = (string) $row['description'];
        if ($row['task_code'] !== '') {
            $desc = $row['task_code'] . ' - ' . $desc;
        }
        $pdf->text($colDesc, $y + 8, mb_substr($desc, 0, 58), 9);
        $pdf->text($colQty, $y + 8, (string) $row['quantity'], 9);
        $pdf->text($colRate, $y + 8, (string) $row['unit_display'], 9);
        $pdf->textRight($colAmt, $y + 8, (string) $row['line_display'], 9);
        $y += 18;
        if ($y > 680) {
            break;
        }
    }
    $pdf->line($left, $y + 2, $right, $y + 2);

    $y += 20;
    $pdf->textRight($colAmt, $y, 'Subtotal: ' . (string) $vm['subtotal_display'], 10);
    $y += 14;
    if ((int) $vm['tax_bps'] > 0) {
        $pdf->textRight($colAmt, $y, (string) $vm['tax_label'] . ': ' . (string) $vm['tax_display'], 10);
        $y += 14;
    }
    $pdf->textRight($colAmt, $y, 'TOTAL: ' . (string) $vm['total_display'], 12, true);

    $y += 22;
    $pdf->text($left, $y, 'Amount in words:', 9, true);
    $y += 12;
    $pdf->text($left, $y, mb_substr((string) $vm['amount_words'], 0, 95), 9);

    $notes = trim((string) ($inv['notes'] ?? ''));
    if ($notes !== '') {
        $y += 20;
        $pdf->text($left, $y, 'Notes', 9, true);
        $y += 12;
        foreach (preg_split('/\r\n|\r|\n/', $notes) ?: [] as $nLine) {
            $nLine = trim($nLine);
            if ($nLine === '') {
                continue;
            }
            $pdf->text($left, $y, mb_substr($nLine, 0, 90), 9);
            $y += 11;
        }
    }

    $footY = 760.0;
    if ($vm['bank_details'] !== '') {
        $pdf->text($left, $footY, 'Bank details', 9, true);
        $footY += 12;
        foreach (preg_split('/\r\n|\r|\n/', $vm['bank_details']) ?: [] as $bLine) {
            $bLine = trim($bLine);
            if ($bLine === '') {
                continue;
            }
            $pdf->text($left, $footY, mb_substr($bLine, 0, 90), 8);
            $footY += 10;
        }
    }
    if ($vm['terms'] !== '') {
        $pdf->text($left, $footY + 6, 'Terms: ' . mb_substr(str_replace("\n", ' ', $vm['terms']), 0, 100), 8);
    }

    return $pdf->bytes();
}

function akh_invoice_pdf_filename(array $invoice): string
{
    $num = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) ($invoice['invoice_number'] ?? 'invoice')) ?? 'invoice';

    return $num . '.pdf';
}
