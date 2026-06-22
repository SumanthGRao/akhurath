-- WhatsApp task sync tables (editor portal → WhatsApp bot / client notifications).
-- Safe to run on DBs that already have these tables (CREATE IF NOT EXISTS).

CREATE TABLE IF NOT EXISTS whatsapp_tasks (
  task_id VARCHAR(32) NOT NULL,
  status VARCHAR(64) NOT NULL DEFAULT 'New',
  status_updated_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (task_id),
  KEY ix_whatsapp_tasks_status (status),
  KEY ix_whatsapp_tasks_status_updated (status_updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
