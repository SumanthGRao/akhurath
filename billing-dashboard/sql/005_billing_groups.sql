-- Run once on an existing Hostinger database after 003_link_invoices.sql.
-- Rows group automatically by folder_id until an accountant overrides the mode.
ALTER TABLE final_delivery_billing
  ADD COLUMN billing_group_mode ENUM('auto','single','manual') NOT NULL DEFAULT 'auto' AFTER source_invoice_id,
  ADD COLUMN billing_group_key CHAR(36) NULL AFTER billing_group_mode,
  ADD INDEX idx_billing_folder_group (folder_id, billing_group_mode, invoice_status, payment_status),
  ADD INDEX idx_billing_manual_group (billing_group_key, billing_group_mode);
