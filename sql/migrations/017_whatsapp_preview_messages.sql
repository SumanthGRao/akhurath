-- Preview links pushed from automation (n8n) — processed by PHP to set preview_sent.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS whatsapp_preview_messages (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  task_code VARCHAR(32) NOT NULL,
  payload TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  processed_at DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY ix_wa_preview_task (task_code),
  KEY ix_wa_preview_pending (processed_at, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
