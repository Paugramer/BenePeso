-- Safe, repeatable data-quality update.
-- This migration changes only exact, unambiguous capitalization/wording
-- variants and records every affected value before it is changed.

CREATE TABLE IF NOT EXISTS data_cleaning_log (
  cleaning_log_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  table_name VARCHAR(64) NOT NULL,
  record_key VARCHAR(100) NOT NULL,
  field_name VARCHAR(64) NOT NULL,
  old_value TEXT NULL,
  new_value TEXT NULL,
  cleaning_rule VARCHAR(255) NOT NULL,
  changed_by VARCHAR(100) NOT NULL DEFAULT 'System data-quality update',
  changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (cleaning_log_id),
  KEY idx_cleaning_record (table_name, record_key),
  KEY idx_cleaning_changed_at (changed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

START TRANSACTION;

INSERT INTO data_cleaning_log
  (table_name, record_key, field_name, old_value, new_value, cleaning_rule)
SELECT 'beneficiaries', CAST(beneficiary_id AS CHAR), 'barangay', barangay,
       'Sula', 'Canonicalize an exact capitalization variant'
FROM beneficiaries
WHERE BINARY TRIM(barangay) = 'SULA';

UPDATE beneficiaries
SET barangay = 'Sula'
WHERE BINARY TRIM(barangay) = 'SULA';

INSERT INTO data_cleaning_log
  (table_name, record_key, field_name, old_value, new_value, cleaning_rule)
SELECT 'users', CAST(user_id AS CHAR), 'barangay', barangay,
       'Aguit-It', 'Canonicalize an exact capitalization variant'
FROM users
WHERE BINARY TRIM(barangay) = 'Aguit-it';

UPDATE users
SET barangay = 'Aguit-It'
WHERE BINARY TRIM(barangay) = 'Aguit-it';

INSERT INTO data_cleaning_log
  (table_name, record_key, field_name, old_value, new_value, cleaning_rule)
SELECT 'program_categories', CAST(category_id AS CHAR), 'requirements', requirements,
       REPLACE(REPLACE(REPLACE(requirements,
         'Photo Copy', 'Photocopy'),
         'Photo copy', 'Photocopy'),
         'school registrat', 'school registrar'),
       'Correct clear wording and capitalization errors'
FROM program_categories
WHERE INSTR(BINARY requirements, BINARY 'Photo Copy') > 0
   OR INSTR(BINARY requirements, BINARY 'Photo copy') > 0
   OR (INSTR(BINARY requirements, BINARY 'school registrat') > 0
       AND INSTR(BINARY requirements, BINARY 'school registrar') = 0);

UPDATE program_categories
SET requirements = REPLACE(REPLACE(REPLACE(requirements,
      'Photo Copy', 'Photocopy'),
      'Photo copy', 'Photocopy'),
      'school registrat', 'school registrar')
WHERE INSTR(BINARY requirements, BINARY 'Photo Copy') > 0
   OR INSTR(BINARY requirements, BINARY 'Photo copy') > 0
   OR (INSTR(BINARY requirements, BINARY 'school registrat') > 0
       AND INSTR(BINARY requirements, BINARY 'school registrar') = 0);

INSERT INTO data_cleaning_log
  (table_name, record_key, field_name, old_value, new_value, cleaning_rule)
SELECT 'program_categories', CAST(category_id AS CHAR), 'requirements', requirements,
       REPLACE(requirements, 'Any VALID Government-issued ID',
                            'Any valid government-issued ID'),
       'Normalize sentence capitalization'
FROM program_categories
WHERE INSTR(BINARY requirements, BINARY 'Any VALID Government-issued ID') > 0;

UPDATE program_categories
SET requirements = REPLACE(requirements, 'Any VALID Government-issued ID',
                                         'Any valid government-issued ID')
WHERE INSTR(BINARY requirements, BINARY 'Any VALID Government-issued ID') > 0;

-- Existing program batches keep a snapshot of the category requirements, so
-- apply the same exact corrections to those snapshots as well.
INSERT INTO data_cleaning_log
  (table_name, record_key, field_name, old_value, new_value, cleaning_rule)
SELECT 'programs', CAST(program_id AS CHAR), 'requirements', requirements,
       REPLACE(REPLACE(REPLACE(REPLACE(requirements,
         'Photo Copy', 'Photocopy'),
         'Photo copy', 'Photocopy'),
         'school registrat', 'school registrar'),
         'Any VALID Government-issued ID', 'Any valid government-issued ID'),
       'Correct clear wording and capitalization errors'
FROM programs
WHERE INSTR(BINARY requirements, BINARY 'Photo Copy') > 0
   OR INSTR(BINARY requirements, BINARY 'Photo copy') > 0
   OR (INSTR(BINARY requirements, BINARY 'school registrat') > 0
       AND INSTR(BINARY requirements, BINARY 'school registrar') = 0)
   OR INSTR(BINARY requirements, BINARY 'Any VALID Government-issued ID') > 0;

UPDATE programs
SET requirements = REPLACE(REPLACE(REPLACE(REPLACE(requirements,
      'Photo Copy', 'Photocopy'),
      'Photo copy', 'Photocopy'),
      'school registrat', 'school registrar'),
      'Any VALID Government-issued ID', 'Any valid government-issued ID')
WHERE INSTR(BINARY requirements, BINARY 'Photo Copy') > 0
   OR INSTR(BINARY requirements, BINARY 'Photo copy') > 0
   OR (INSTR(BINARY requirements, BINARY 'school registrat') > 0
       AND INSTR(BINARY requirements, BINARY 'school registrar') = 0)
   OR INSTR(BINARY requirements, BINARY 'Any VALID Government-issued ID') > 0;

COMMIT;
