-- Align task_notification_events with WhatsApp bot (status pending/read).
-- Safe if column already exists.

SET NAMES utf8mb4;

ALTER TABLE task_notification_events
  ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'pending' AFTER body;

ALTER TABLE task_notification_events
  ADD KEY ix_task_notification_status (status);
