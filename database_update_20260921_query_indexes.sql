-- Additive indexes for account-owned beneficiary lookups.
-- These do not alter, delete, or constrain existing beneficiary records.

SET @beneficiary_user_lookup_index_exists = (
  SELECT COUNT(*)
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'beneficiaries'
    AND index_name = 'idx_beneficiary_user_program_barangay'
);

SET @add_beneficiary_user_lookup_index = IF(
  @beneficiary_user_lookup_index_exists = 0,
  'ALTER TABLE beneficiaries ADD KEY idx_beneficiary_user_program_barangay (user_id, program_id, barangay)',
  'SELECT 1'
);

PREPARE beneficiary_user_lookup_statement FROM @add_beneficiary_user_lookup_index;
EXECUTE beneficiary_user_lookup_statement;
DEALLOCATE PREPARE beneficiary_user_lookup_statement;

SET @beneficiary_email_lookup_index_exists = (
  SELECT COUNT(*)
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'beneficiaries'
    AND index_name = 'idx_beneficiary_email_program_barangay'
);

SET @add_beneficiary_email_lookup_index = IF(
  @beneficiary_email_lookup_index_exists = 0,
  'ALTER TABLE beneficiaries ADD KEY idx_beneficiary_email_program_barangay (email, program_id, barangay)',
  'SELECT 1'
);

PREPARE beneficiary_email_lookup_statement FROM @add_beneficiary_email_lookup_index;
EXECUTE beneficiary_email_lookup_statement;
DEALLOCATE PREPARE beneficiary_email_lookup_statement;
