-- Allow phone-first beneficiary accounts to omit email.
-- The existing UNIQUE email index remains and permits multiple NULL values.
ALTER TABLE users
    MODIFY email VARCHAR(120) NULL DEFAULT NULL;
