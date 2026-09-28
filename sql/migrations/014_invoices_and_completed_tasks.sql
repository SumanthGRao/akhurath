-- Invoicing + billable completed work registry (studio tasks sync here until a dedicated pipeline exists).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS completed_tasks (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  task_code VARCHAR(32) NOT NULL,
  client_username VARCHAR(64) NOT NULL,
  title VARCHAR(500) NOT NULL DEFAULT '',
  edit_type VARCHAR(64) NULL DEFAULT NULL,
  completed_at DATETIME NOT NULL,
  amount_paise INT UNSIGNED NULL DEFAULT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'INR',
  invoice_line_id BIGINT UNSIGNED NULL DEFAULT NULL,
  notes TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY ux_completed_tasks_code (task_code),
  KEY ix_completed_tasks_client (client_username),
  KEY ix_completed_tasks_billable (invoice_line_id, completed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invoices (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  invoice_number VARCHAR(32) NOT NULL,
  client_username VARCHAR(64) NOT NULL,
  client_email VARCHAR(255) NULL DEFAULT NULL,
  client_display_name VARCHAR(255) NULL DEFAULT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'draft',
  currency CHAR(3) NOT NULL DEFAULT 'INR',
  subtotal_paise INT UNSIGNED NOT NULL DEFAULT 0,
  tax_rate_bps INT UNSIGNED NOT NULL DEFAULT 0,
  tax_paise INT UNSIGNED NOT NULL DEFAULT 0,
  total_paise INT UNSIGNED NOT NULL DEFAULT 0,
  notes TEXT NULL,
  issued_at DATE NULL DEFAULT NULL,
  due_at DATE NULL DEFAULT NULL,
  sent_at DATETIME NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY ux_invoices_number (invoice_number),
  KEY ix_invoices_client (client_username),
  KEY ix_invoices_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invoice_lines (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  invoice_id BIGINT UNSIGNED NOT NULL,
  sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  source_kind VARCHAR(32) NOT NULL DEFAULT 'manual',
  source_ref VARCHAR(64) NOT NULL DEFAULT '',
  task_code VARCHAR(32) NULL DEFAULT NULL,
  description VARCHAR(500) NOT NULL,
  quantity INT UNSIGNED NOT NULL DEFAULT 1,
  unit_amount_paise INT UNSIGNED NOT NULL DEFAULT 0,
  line_total_paise INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY ix_invoice_lines_invoice (invoice_id),
  KEY ix_invoice_lines_source (source_kind, source_ref),
  CONSTRAINT fk_invoice_lines_invoice
    FOREIGN KEY (invoice_id) REFERENCES invoices (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
