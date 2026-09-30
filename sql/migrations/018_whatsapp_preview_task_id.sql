-- n8n may write task_id instead of task_code on preview queue rows.

SET NAMES utf8mb4;

ALTER TABLE whatsapp_preview_messages
  ADD COLUMN task_id VARCHAR(32) NULL DEFAULT NULL AFTER task_code;
