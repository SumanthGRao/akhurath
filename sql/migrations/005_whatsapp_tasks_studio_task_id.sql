-- Links WhatsApp automation rows to editor-board tasks (app_kv `tasks` JSON).
-- Run once if whatsapp_tasks already exists without studio_task_id.

SET NAMES utf8mb4;

ALTER TABLE whatsapp_tasks
  ADD COLUMN studio_task_id VARCHAR(64) NULL DEFAULT NULL AFTER task_code;

ALTER TABLE whatsapp_tasks
  ADD KEY ix_whatsapp_tasks_studio (studio_task_id);
