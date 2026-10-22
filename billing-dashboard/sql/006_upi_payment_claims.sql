-- Run once against the site database after 003_link_invoices.sql and 005_billing_groups.sql.
-- Safe to rerun: the enum definition is replaced with the same values and all tables use IF NOT EXISTS.
ALTER TABLE final_delivery_billing
  MODIFY payment_status ENUM('unpaid','payment_claimed','cleared') NOT NULL DEFAULT 'unpaid';

CREATE TABLE IF NOT EXISTS billing_invoice_payment_links (
  invoice_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  token_hash CHAR(64) NOT NULL UNIQUE,
  token_ciphertext VARBINARY(128) NOT NULL,
  expires_at DATETIME NOT NULL,
  status ENUM('active','claimed','cleared','revoked') NOT NULL DEFAULT 'active',
  payment_claimed_at DATETIME(3) NULL,
  client_utr VARCHAR(80) NULL,
  claim_verification_status ENUM('unverified','verified','rejected') NOT NULL DEFAULT 'unverified',
  verified_by VARCHAR(100) NULL,
  verified_at DATETIME(3) NULL,
  cleared_at DATETIME(3) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  INDEX idx_payment_link_status_expiry (status, expires_at),
  CONSTRAINT fk_payment_link_invoice
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS billing_payment_claim_rate_limits (
  ip_hash CHAR(64) NOT NULL PRIMARY KEY,
  window_started_at DATETIME NOT NULL,
  request_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB;

-- n8n inserts event_id here before sending WhatsApp. The primary key makes webhook retries idempotent.
CREATE TABLE IF NOT EXISTS billing_webhook_events (
  event_id VARCHAR(120) NOT NULL PRIMARY KEY,
  event_type VARCHAR(80) NOT NULL,
  invoice_id BIGINT UNSIGNED NOT NULL,
  status ENUM('processing','sent','failed') NOT NULL DEFAULT 'processing',
  whatsapp_message_id VARCHAR(255) NULL,
  last_error VARCHAR(1000) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  completed_at DATETIME(3) NULL,
  INDEX idx_billing_webhook_invoice (invoice_id, created_at)
) ENGINE=InnoDB;
