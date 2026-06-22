-- Deprecated: studio_task_id duplicated whatsapp_tasks.task_code. No longer used by the app.
-- Safe to run on DBs that still have the column (optional cleanup).

SET NAMES utf8mb4;

ALTER TABLE whatsapp_tasks DROP INDEX ix_whatsapp_tasks_studio;
ALTER TABLE whatsapp_tasks DROP COLUMN studio_task_id;
