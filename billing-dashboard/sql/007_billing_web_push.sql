-- Apply to the same MariaDB database used by billing-dashboard/api.php.
CREATE TABLE IF NOT EXISTS billing_push_subscriptions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(100) NOT NULL,
  endpoint_hash CHAR(64) NOT NULL UNIQUE,
  endpoint TEXT NOT NULL,
  public_key VARCHAR(255) NOT NULL,
  auth_token VARCHAR(255) NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  INDEX idx_billing_push_user (username)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS billing_push_events (
  event_id VARCHAR(160) NOT NULL PRIMARY KEY,
  billing_id BIGINT UNSIGNED NOT NULL,
  sent_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  INDEX idx_billing_push_events_billing (billing_id)
) ENGINE=InnoDB;
