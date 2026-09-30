-- Canonical studio task status transition log (editor desk, WhatsApp dashboard, admin).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS task_status_changes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  task_id VARCHAR(32) NOT NULL,
  from_status VARCHAR(32) NOT NULL DEFAULT '',
  to_status VARCHAR(32) NOT NULL,
  source ENUM('editor', 'whatsapp', 'admin') NOT NULL DEFAULT 'editor',
  changed_by VARCHAR(64) NOT NULL DEFAULT '',
  comment TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_task_status_changes_task (task_id),
  KEY ix_task_status_changes_created (created_at),
  KEY ix_task_status_changes_to (to_status, created_at),
  KEY ix_task_status_changes_transition (from_status, to_status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
