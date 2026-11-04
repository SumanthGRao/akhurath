-- Run once on an existing PHP-hosted billing database.
-- Existing rows remain selected so today's billing queue is unchanged.
-- Discover the single-column unique index by its column, not its installation-specific name.
SET @billing_task_code_unique_index = (
  SELECT s.INDEX_NAME
  FROM information_schema.STATISTICS AS s
  WHERE s.TABLE_SCHEMA = DATABASE()
    AND s.TABLE_NAME = 'final_delivery_billing'
    AND s.COLUMN_NAME = 'task_code'
    AND s.NON_UNIQUE = 0
    AND s.INDEX_NAME <> 'PRIMARY'
    AND (
      SELECT COUNT(*)
      FROM information_schema.STATISTICS AS all_parts
      WHERE all_parts.TABLE_SCHEMA = s.TABLE_SCHEMA
        AND all_parts.TABLE_NAME = s.TABLE_NAME
        AND all_parts.INDEX_NAME = s.INDEX_NAME
    ) = 1
  LIMIT 1
);

SET @billing_drop_index_sql = IF(
  @billing_task_code_unique_index IS NULL,
  'SELECT 1',
  CONCAT('ALTER TABLE `final_delivery_billing` DROP INDEX `', REPLACE(@billing_task_code_unique_index, '`', '``'), '`')
);
PREPARE billing_drop_index_stmt FROM @billing_drop_index_sql;
EXECUTE billing_drop_index_stmt;
DEALLOCATE PREPARE billing_drop_index_stmt;

ALTER TABLE final_delivery_billing
  ADD COLUMN version_review_status ENUM('selected','pending','discarded') NOT NULL DEFAULT 'selected' AFTER task_code,
  ADD UNIQUE KEY uq_billing_task_file (task_code, drive_file_id),
  ADD INDEX idx_billing_version_review (task_code, version_review_status);

-- Move existing duplicate, untouched task rows into accountant review as well.
UPDATE final_delivery_billing AS billing
JOIN (
  SELECT task_code
  FROM final_delivery_billing
  GROUP BY task_code
  HAVING COUNT(*) > 1
    AND SUM(
      invoice_status = 'pending'
      AND payment_status = 'unpaid'
      AND source_invoice_id IS NULL
      AND delivery_status = 'awaiting_payment'
    ) = COUNT(*)
) AS duplicate_tasks ON duplicate_tasks.task_code = billing.task_code
SET billing.version_review_status = 'pending';
