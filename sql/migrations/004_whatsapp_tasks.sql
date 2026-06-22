-- WhatsApp automation task queue (wedding editing jobs).
-- Run once on database u113439427_akhurath if the table does not exist yet.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS whatsapp_tasks (
  id INT(11) NOT NULL AUTO_INCREMENT,
  task_code VARCHAR(50) NOT NULL,
  customer_id INT(11) NULL DEFAULT NULL,
  phone VARCHAR(20) NULL DEFAULT NULL,
  project_name VARCHAR(255) NULL DEFAULT NULL,
  task_type VARCHAR(100) NULL DEFAULT NULL,
  instructions TEXT NULL,
  delivery_type VARCHAR(50) NULL DEFAULT NULL,
  drive_link TEXT NULL,
  reference_link TEXT NULL,
  comments TEXT NULL,
  status VARCHAR(50) NOT NULL DEFAULT 'new',
  assigned_editor INT(11) NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  customer_name VARCHAR(255) NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_whatsapp_tasks_code (task_code),
  KEY ix_whatsapp_tasks_status (status),
  KEY ix_whatsapp_tasks_updated (updated_at),
  KEY ix_whatsapp_tasks_editor (assigned_editor)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
