-- Client meeting requests from WhatsApp bot (table may already exist on production).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS meeting_requests (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  task_code VARCHAR(32) NOT NULL,
  phone VARCHAR(40) NULL DEFAULT NULL,
  slot_selected VARCHAR(120) NULL DEFAULT NULL,
  meet_link VARCHAR(500) NULL DEFAULT NULL,
  start_time DATETIME NULL DEFAULT NULL,
  end_time DATETIME NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  requested_time_text VARCHAR(255) NULL DEFAULT NULL,
  customer_name VARCHAR(255) NULL DEFAULT NULL,
  project_name VARCHAR(255) NULL DEFAULT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'pending',
  calendar_event_id VARCHAR(120) NULL DEFAULT NULL,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_meeting_requests_task (task_code),
  KEY ix_meeting_requests_status (status),
  KEY ix_meeting_requests_start (start_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
