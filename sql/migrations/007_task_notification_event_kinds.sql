-- Extend client notification kinds for task_notification_events.

SET NAMES utf8mb4;

ALTER TABLE task_notification_events
  MODIFY COLUMN event_kind ENUM('studio_new', 'client_feedback', 'client_update', 'client_message') NOT NULL;
