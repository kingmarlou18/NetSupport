-- Adds optional phone numbers for future technician SMS notifications.
ALTER TABLE users ADD COLUMN phone_number VARCHAR(30) NULL AFTER email;
