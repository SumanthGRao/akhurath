-- WhatsApp chat media attachments (images, video, documents).

ALTER TABLE whatsapp_messages
  ADD COLUMN media_url VARCHAR(512) NULL DEFAULT NULL AFTER message,
  ADD COLUMN filename VARCHAR(255) NULL DEFAULT NULL AFTER media_url;
