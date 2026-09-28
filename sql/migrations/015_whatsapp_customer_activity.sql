-- Customer touchpoints per studio task (editor desk: recent activity hints).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS whatsapp_customer_activity (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  task_code VARCHAR(32) NOT NULL,
  client_username VARCHAR(64) NOT NULL DEFAULT '',
  source VARCHAR(32) NOT NULL DEFAULT '',
  activity_kind VARCHAR(32) NOT NULL DEFAULT 'interaction',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_wa_customer_activity_task_time (task_code, created_at),
  KEY ix_wa_customer_activity_client_time (client_username, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
