-- Run once on existing Hostinger databases to connect delivery rows to invoices.
ALTER TABLE final_delivery_billing
  ADD COLUMN source_invoice_id BIGINT UNSIGNED NULL AFTER invoice_file_url,
  ADD INDEX idx_billing_source_invoice (source_invoice_id);
