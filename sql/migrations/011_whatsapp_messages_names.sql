-- Display names on message rows (client + editor) for unified conversation UI / n8n.

ALTER TABLE whatsapp_messages
  ADD COLUMN customer_name VARCHAR(255) NULL DEFAULT NULL AFTER sender;

ALTER TABLE whatsapp_messages
  ADD COLUMN editor_name VARCHAR(255) NULL DEFAULT NULL AFTER customer_name;
