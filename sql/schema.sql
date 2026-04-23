-- MySQL schema for Akhurath Studio. Run once on an empty database.
--   hPanel: phpMyAdmin → select `u113439427_akhurath` → Import → this file
--   CLI (see scripts/mysql-import-schema.sh), e.g.:
--     mysql -h localhost -u u113439427_akhurath -p u113439427_akhurath < sql/schema.sql

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  role ENUM('customer', 'editor', 'admin') NOT NULL,
  username VARCHAR(64) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_role_username (role, username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS app_kv (
  k VARCHAR(80) NOT NULL,
  v LONGTEXT NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (k)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_kv (k, v) VALUES
  ('tasks', '[]'),
  ('task_seq', '{"next":1}'),
  ('editor_seen_tasks', '{}'),
  ('editor_attendance', '{"events":[]}'),
  ('editor_leave', '{"requests":[]}'),
  ('admin_meta', '{"email":"","email_verified":false,"verify_token":null,"verify_expires_at":null}')
ON DUPLICATE KEY UPDATE k = k;

CREATE TABLE IF NOT EXISTS task_notification_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_kind ENUM('studio_new', 'client_feedback') NOT NULL,
  task_id VARCHAR(64) NOT NULL,
  body MEDIUMTEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_task_notification_task (task_id),
  KEY ix_task_notification_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contact_enquiries (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  submitted_at_utc VARCHAR(40) NOT NULL,
  name VARCHAR(200) NOT NULL,
  company VARCHAR(200) NOT NULL,
  phone VARCHAR(40) NOT NULL,
  email VARCHAR(120) NOT NULL DEFAULT '',
  topic_line VARCHAR(200) NOT NULL,
  project_details TEXT NOT NULL,
  body MEDIUMTEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
