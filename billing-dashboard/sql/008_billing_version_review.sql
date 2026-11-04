-- Run once on an existing PHP-hosted billing database.
-- Existing rows remain selected so today's billing queue is unchanged.
ALTER TABLE final_delivery_billing
  DROP INDEX task_code,
  ADD COLUMN version_review_status ENUM('selected','pending','discarded') NOT NULL DEFAULT 'selected' AFTER task_code,
  ADD UNIQUE KEY uq_billing_task_file (task_code, drive_file_id),
  ADD INDEX idx_billing_version_review (task_code, version_review_status);
