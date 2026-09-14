-- Recovery verifiers use password_hash(), which currently needs 60 characters
-- and may require more with future PHP algorithms. Widening is non-destructive.
ALTER TABLE admins
    MODIFY reset_code VARCHAR(255) NULL;

ALTER TABLE peso_staff
    MODIFY reset_code VARCHAR(255) NULL;
