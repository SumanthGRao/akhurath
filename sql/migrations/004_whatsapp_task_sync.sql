-- Editor status history for WhatsApp / client notifications.
-- whatsapp_tasks is created by sql/migrations/004_whatsapp_tasks.sql — do not redefine it here.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS task_updates (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  task_id VARCHAR(32) NOT NULL,
  status VARCHAR(64) NOT NULL,
  comment TEXT NOT NULL,
  updated_by VARCHAR(64) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_task_updates_task (task_id),
  KEY ix_task_updates_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
