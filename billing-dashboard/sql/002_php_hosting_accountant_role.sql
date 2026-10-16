-- Optional role support for the PHP-hosted dashboard login.
-- Run once in the same MariaDB database configured for the Akhurath PHP site.
ALTER TABLE users
  MODIFY role ENUM('customer', 'editor', 'admin', 'accountant') NOT NULL;
