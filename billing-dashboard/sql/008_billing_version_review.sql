-- Safe to run on an existing PHP-hosted billing database, including after a partial attempt.
-- Run against the billing database selected in phpMyAdmin.

-- Remove any single-column unique key on task_code, whatever its local index name.
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
SET @billing_sql = IF(
  @billing_task_code_unique_index IS NULL,
  'SELECT 1',
  CONCAT('ALTER TABLE `final_delivery_billing` DROP INDEX `', REPLACE(@billing_task_code_unique_index, '`', '``'), '`')
);
PREPARE billing_stmt FROM @billing_sql;
EXECUTE billing_stmt;
DEALLOCATE PREPARE billing_stmt;

-- Add the review state only if a prior attempt has not already added it.
SET @billing_has_review_column = (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'final_delivery_billing'
    AND COLUMN_NAME = 'version_review_status'
);
SET @billing_sql = IF(
  @billing_has_review_column = 0,
  "ALTER TABLE `final_delivery_billing` ADD COLUMN `version_review_status` ENUM('selected','pending','discarded') NOT NULL DEFAULT 'selected' AFTER `task_code`",
  'SELECT 1'
);
PREPARE billing_stmt FROM @billing_sql;
EXECUTE billing_stmt;
DEALLOCATE PREPARE billing_stmt;

-- Add a unique key per task/file pair only if that exact key is not already present.
SET @billing_has_task_file_key = (
  SELECT COUNT(*)
  FROM (
    SELECT INDEX_NAME, NON_UNIQUE, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS indexed_columns
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'final_delivery_billing'
    GROUP BY INDEX_NAME, NON_UNIQUE
  ) AS billing_indexes
  WHERE NON_UNIQUE = 0 AND indexed_columns = 'task_code,drive_file_id'
);
SET @billing_sql = IF(
  @billing_has_task_file_key = 0,
  'ALTER TABLE `final_delivery_billing` ADD UNIQUE KEY `uq_billing_task_file` (`task_code`,`drive_file_id`)',
  'SELECT 1'
);
PREPARE billing_stmt FROM @billing_sql;
EXECUTE billing_stmt;
DEALLOCATE PREPARE billing_stmt;

-- Add the review lookup index only if an equivalent index is absent.
SET @billing_has_review_index = (
  SELECT COUNT(*)
  FROM (
    SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS indexed_columns
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'final_delivery_billing'
    GROUP BY INDEX_NAME
  ) AS billing_indexes
  WHERE indexed_columns = 'task_code,version_review_status'
);
SET @billing_sql = IF(
  @billing_has_review_index = 0,
  'ALTER TABLE `final_delivery_billing` ADD INDEX `idx_billing_version_review` (`task_code`,`version_review_status`)',
  'SELECT 1'
);
PREPARE billing_stmt FROM @billing_sql;
EXECUTE billing_stmt;
DEALLOCATE PREPARE billing_stmt;

-- Send duplicate, untouched versions to review. A task can also have older
-- invoiced/delivered rows, which must stay out of the review workflow.
UPDATE final_delivery_billing AS billing
JOIN (
  SELECT task_code
  FROM final_delivery_billing
  WHERE version_review_status IN ('selected', 'pending')
    AND invoice_status = 'pending'
    AND payment_status = 'unpaid'
    AND source_invoice_id IS NULL
    AND delivery_status = 'awaiting_payment'
  GROUP BY task_code
  HAVING COUNT(*) > 1
) AS duplicate_tasks ON duplicate_tasks.task_code = billing.task_code
SET billing.version_review_status = 'pending'
WHERE billing.version_review_status = 'selected'
  AND billing.invoice_status = 'pending'
  AND billing.payment_status = 'unpaid'
  AND billing.source_invoice_id IS NULL
  AND billing.delivery_status = 'awaiting_payment';
