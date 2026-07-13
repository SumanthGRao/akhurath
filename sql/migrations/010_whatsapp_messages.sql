-- Client ↔ studio WhatsApp message log (incoming/outgoing).
-- task_code matches studio task id (e.g. AS0001).

CREATE TABLE IF NOT EXISTS whatsapp_messages (
  id INT NOT NULL AUTO_INCREMENT,
  phone VARCHAR(20) NULL DEFAULT NULL,
  task_code VARCHAR(20) NULL DEFAULT NULL,
  direction ENUM('incoming', 'outgoing') NULL DEFAULT NULL,
  sender ENUM('client', 'editor', 'system') NULL DEFAULT 'client',
  message TEXT NULL DEFAULT NULL,
  whatsapp_message_id VARCHAR(255) NULL DEFAULT NULL,
  status ENUM('pending', 'sent', 'delivered', 'read', 'received') NULL DEFAULT 'received',
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_whatsapp_messages_task (task_code),
  KEY ix_whatsapp_messages_created (created_at),
  KEY ix_whatsapp_messages_direction (direction)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
