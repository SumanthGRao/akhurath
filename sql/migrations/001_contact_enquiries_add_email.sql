-- Run once if you already imported an older schema.sql without `email` on contact_enquiries.
-- phpMyAdmin → SQL tab, or: mysql -u USER -p DBNAME < sql/migrations/001_contact_enquiries_add_email.sql

ALTER TABLE contact_enquiries
  ADD COLUMN email VARCHAR(120) NOT NULL DEFAULT '' AFTER phone;
