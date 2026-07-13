-- Allow direction = 'outbound' for editor → client messages (n8n workflow).

ALTER TABLE whatsapp_messages
  MODIFY COLUMN direction ENUM('incoming', 'outgoing', 'outbound') NULL DEFAULT NULL;
