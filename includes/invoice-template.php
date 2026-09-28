<?php

declare(strict_types=1);

require_once __DIR__ . '/invoices.php';
require_once __DIR__ . '/invoice-services.php';

/**
 * @param array<string, mixed> $row Raw or view-model line fields
 */
function akh_invoice_line_public_description(array $row): string
{
    $desc = trim((string) ($row['description'] ?? ''));
    $code = trim((string) ($row['task_code'] ?? ''));
    $sourceRef = trim((string) ($row['source_ref'] ?? ''));
    $sourceKind = trim((string) ($row['source_kind'] ?? ''));
    if ($desc === '' && $sourceKind === 'service' && $sourceRef !== '') {
        $desc = akh_invoice_service_label($sourceRef);
    }
    if ($desc === '' && $code !== '') {
        return $code;
    }
    if ($code !== '' && $desc !== '' && !str_contains($desc, $code)) {
        return $code . ' — ' . $desc;
    }

    return $desc !== '' ? $desc : 'Service';
}

/**
 * @param array<string, mixed> $invoice
 * @return array<string, mixed>
 */
function akh_invoice_template_view_model(array $invoice): array
{
    $profile = akh_invoice_studio_profile();
    $currency = (string) ($invoice['currency'] ?? 'INR');
    $lines = [];
    $n = 0;
    foreach (is_array($invoice['lines'] ?? null) ? $invoice['lines'] : [] as $line) {
        if (!is_array($line)) {
            continue;
        }
        ++$n;
        $code = trim((string) ($line['task_code'] ?? ''));
        $sourceKind = trim((string) ($line['source_kind'] ?? ''));
        $sourceRef = trim((string) ($line['source_ref'] ?? ''));
        $rowVm = [
            'task_code' => $code,
            'description' => trim((string) ($line['description'] ?? '')),
            'source_kind' => $sourceKind,
            'source_ref' => $sourceRef,
        ];
        $desc = akh_invoice_line_public_description($rowVm);
        $lines[] = [
            'no' => $n,
            'task_code' => $code,
            'description' => $desc,
            'source_kind' => $sourceKind,
            'source_ref' => $sourceRef,
            'quantity' => (int) ($line['quantity'] ?? 1),
            'unit_paise' => (int) ($line['unit_amount_paise'] ?? 0),
            'line_paise' => (int) ($line['line_total_paise'] ?? 0),
            'unit_display' => akh_invoice_money_format_paise((int) ($line['unit_amount_paise'] ?? 0), $currency),
            'line_display' => akh_invoice_money_format_paise((int) ($line['line_total_paise'] ?? 0), $currency),
        ];
    }

    $billName = trim((string) ($invoice['client_display_name'] ?? ''));
    if ($billName === '') {
        $billName = (string) ($invoice['client_username'] ?? '');
    }

    $taxBps = (int) ($invoice['tax_rate_bps'] ?? 0);
    $taxLabel = $taxBps > 0 ? 'GST (' . number_format($taxBps / 100, 2) . '%)' : 'Tax';

    return [
        'profile' => $profile,
        'invoice' => $invoice,
        'lines' => $lines,
        'bill_name' => $billName,
        'bill_email' => trim((string) ($invoice['client_email'] ?? '')),
        'currency' => $currency,
        'subtotal_display' => akh_invoice_money_format_paise((int) ($invoice['subtotal_paise'] ?? 0), $currency),
        'tax_display' => akh_invoice_money_format_paise((int) ($invoice['tax_paise'] ?? 0), $currency),
        'total_display' => akh_invoice_money_format_paise((int) ($invoice['total_paise'] ?? 0), $currency),
        'tax_label' => $taxLabel,
        'tax_bps' => $taxBps,
        'amount_words' => akh_invoice_amount_in_words((int) ($invoice['total_paise'] ?? 0)),
        'bank_details' => defined('AKH_INVOICE_BANK_DETAILS') ? trim((string) AKH_INVOICE_BANK_DETAILS) : '',
        'terms' => defined('AKH_INVOICE_TERMS') ? trim((string) AKH_INVOICE_TERMS) : '',
        'title' => $taxBps > 0 || trim((string) ($profile['gstin'] ?? '')) !== '' ? 'TAX INVOICE' : 'INVOICE',
    ];
}

function akh_invoice_amount_in_words(int $paise): string
{
    $rupees = intdiv(max(0, $paise), 100);
    $pa = $paise % 100;
    if (class_exists(NumberFormatter::class)) {
        $fmt = new NumberFormatter('en_IN', NumberFormatter::SPELLOUT);
        $words = $fmt->format($rupees);
        if (is_string($words) && $words !== '') {
            $out = ucfirst($words) . ' rupees';
            if ($pa > 0) {
                $pw = $fmt->format($pa);
                if (is_string($pw) && $pw !== '') {
                    $out .= ' and ' . $pw . ' paise';
                }
            }

            return $out . ' only';
        }
    }

    return 'Rupees ' . number_format($rupees) . ($pa > 0 ? ' and ' . $pa . ' paise' : '') . ' only';
}

/**
 * HTML invoice document (print / browser preview).
 */
function akh_invoice_render_html(array $invoice): string
{
    $vm = akh_invoice_template_view_model($invoice);
    $inv = $vm['invoice'];
    $profile = $vm['profile'];
    ob_start();
    ?>
<div class="inv-doc">
  <header class="inv-doc__banner">
    <div class="inv-doc__brand">
      <strong class="inv-doc__studio"><?php echo h((string) $profile['name']); ?></strong>
      <?php if (trim((string) $profile['address']) !== ''): ?>
        <div class="inv-doc__addr"><?php echo nl2br(h((string) $profile['address'])); ?></div>
      <?php endif; ?>
      <?php if (trim((string) $profile['gstin']) !== ''): ?>
        <div class="inv-doc__meta">GSTIN: <?php echo h((string) $profile['gstin']); ?></div>
      <?php endif; ?>
      <?php if (trim((string) $profile['email']) !== ''): ?>
        <div class="inv-doc__meta"><?php echo h((string) $profile['email']); ?></div>
      <?php endif; ?>
    </div>
    <div class="inv-doc__head-right">
      <h1 class="inv-doc__title"><?php echo h((string) $vm['title']); ?></h1>
      <table class="inv-doc__meta-table">
        <tr><th scope="row">Invoice no.</th><td><?php echo h((string) ($inv['invoice_number'] ?? '')); ?></td></tr>
        <tr><th scope="row">Date</th><td><?php echo h((string) ($inv['issued_at'] ?? '')); ?></td></tr>
        <tr><th scope="row">Due</th><td><?php echo h((string) ($inv['due_at'] ?? '')); ?></td></tr>
      </table>
    </div>
  </header>

  <section class="inv-doc__parties">
    <div class="inv-doc__party">
      <h2>Bill to</h2>
      <p class="inv-doc__party-name"><?php echo h((string) $vm['bill_name']); ?></p>
      <?php if ($vm['bill_email'] !== ''): ?>
        <p class="inv-doc__party-meta"><?php echo h($vm['bill_email']); ?></p>
      <?php endif; ?>
      <?php if (trim((string) ($inv['client_username'] ?? '')) !== ''): ?>
        <p class="inv-doc__party-meta">Ref: <?php echo h((string) $inv['client_username']); ?></p>
      <?php endif; ?>
    </div>
  </section>

  <table class="inv-doc__services">
    <thead>
      <tr>
        <th scope="col">#</th>
        <th scope="col">Description</th>
        <th scope="col">Qty</th>
        <th scope="col">Rate</th>
        <th scope="col">Amount</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($vm['lines'] as $row): ?>
        <tr>
          <td><?php echo (int) $row['no']; ?></td>
          <td class="inv-doc__desc-cell"><?php echo h((string) $row['description']); ?></td>
          <td><?php echo (int) $row['quantity']; ?></td>
          <td><?php echo h((string) $row['unit_display']); ?></td>
          <td><?php echo h((string) $row['line_display']); ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <div class="inv-doc__totals-wrap">
    <table class="inv-doc__totals">
      <tr><th scope="row">Subtotal</th><td><?php echo h((string) $vm['subtotal_display']); ?></td></tr>
      <?php if ((int) $vm['tax_bps'] > 0): ?>
        <tr><th scope="row"><?php echo h((string) $vm['tax_label']); ?></th><td><?php echo h((string) $vm['tax_display']); ?></td></tr>
      <?php endif; ?>
      <tr class="inv-doc__grand"><th scope="row">Total</th><td><?php echo h((string) $vm['total_display']); ?></td></tr>
    </table>
  </div>

  <p class="inv-doc__words"><strong>Amount in words:</strong> <?php echo h((string) $vm['amount_words']); ?></p>

  <?php if (trim((string) ($inv['notes'] ?? '')) !== ''): ?>
    <section class="inv-doc__notes">
      <h2>Notes</h2>
      <p><?php echo nl2br(h((string) $inv['notes'])); ?></p>
    </section>
  <?php endif; ?>

  <footer class="inv-doc__footer">
    <?php if ($vm['bank_details'] !== ''): ?>
      <div class="inv-doc__bank">
        <h2>Bank details</h2>
        <p><?php echo nl2br(h($vm['bank_details'])); ?></p>
      </div>
    <?php endif; ?>
    <?php if ($vm['terms'] !== ''): ?>
      <div class="inv-doc__terms">
        <h2>Terms</h2>
        <p><?php echo nl2br(h($vm['terms'])); ?></p>
      </div>
    <?php endif; ?>
    <p class="inv-doc__thanks">Thank you for your business.</p>
  </footer>
</div>
    <?php
    $inner = ob_get_clean();

    return is_string($inner) ? $inner : '';
}
