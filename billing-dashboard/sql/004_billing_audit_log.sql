-- Run once if invoice or payment actions report that the audit entry could not be saved.
-- This creates the billing audit table when it is missing.
CREATE TABLE IF NOT EXISTS billing_audit_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  billing_id BIGINT UNSIGNED NOT NULL,
  actor VARCHAR(100) NOT NULL,
  action VARCHAR(50) NOT NULL,
  details JSON NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  INDEX idx_audit_billing (billing_id, created_at),
  CONSTRAINT fk_audit_billing FOREIGN KEY (billing_id) REFERENCES final_delivery_billing(id)
) ENGINE=InnoDB;
