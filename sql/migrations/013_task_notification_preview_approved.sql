-- Allow preview-approval and status-request notification kinds from the WhatsApp bot.

SET NAMES utf8mb4;

ALTER TABLE task_notification_events
  MODIFY COLUMN event_kind VARCHAR(64) NOT NULL DEFAULT 'client_update';
