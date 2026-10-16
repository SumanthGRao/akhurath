CREATE DATABASE IF NOT EXISTS akhurath_studio CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE akhurath_studio;

CREATE TABLE IF NOT EXISTS final_delivery_billing (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  task_code VARCHAR(100) NOT NULL UNIQUE,
  phone VARCHAR(40) NOT NULL,
  customer_name VARCHAR(200) NOT NULL,
  project_name VARCHAR(255) NOT NULL,
  task_type VARCHAR(100) NULL,
  folder_id VARCHAR(255) NULL,
  drive_file_id VARCHAR(255) NOT NULL,
  file_name VARCHAR(500) NOT NULL,
  mime_type VARCHAR(150) NULL,
  file_size BIGINT UNSIGNED NULL,
  bill_amount DECIMAL(12,2) NULL,
  invoice_number VARCHAR(100) NULL,
  invoice_file_url TEXT NULL,
  invoice_status ENUM('pending','sent') NOT NULL DEFAULT 'pending',
  payment_status ENUM('unpaid','cleared') NOT NULL DEFAULT 'unpaid',
  payment_reference VARCHAR(255) NULL,
  cleared_by VARCHAR(100) NULL,
  cleared_at DATETIME(3) NULL,
  delivery_status ENUM('awaiting_payment','ready','processing','delivered','failed') NOT NULL DEFAULT 'awaiting_payment',
  final_link TEXT NULL,
  delivered_at DATETIME(3) NULL,
  delivery_claim_token CHAR(36) NULL,
  delivery_claimed_at DATETIME(3) NULL,
  delivery_error TEXT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  INDEX idx_billing_queue (payment_status, invoice_status, created_at),
  INDEX idx_delivery_queue (delivery_status, payment_status)
) ENGINE=InnoDB;

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
