-- Run once on an existing billing database. Paid invoices are recorded internally
-- without being sent to the client, while payment clearance still gates delivery.
ALTER TABLE final_delivery_billing
  MODIFY invoice_status ENUM('pending','sent','paid') NOT NULL DEFAULT 'pending';
