ALTER TABLE beneficiaries
  ADD COLUMN IF NOT EXISTS spes_history_1_establishment VARCHAR(180) NULL AFTER special_skills,
  ADD COLUMN IF NOT EXISTS spes_history_2_establishment VARCHAR(180) NULL AFTER spes_history_1_id,
  ADD COLUMN IF NOT EXISTS spes_history_3_establishment VARCHAR(180) NULL AFTER spes_history_2_id,
  ADD COLUMN IF NOT EXISTS spes_history_4_establishment VARCHAR(180) NULL AFTER spes_history_3_id,
  ADD COLUMN IF NOT EXISTS spes_other_info TEXT NULL AFTER spes_history_4_id;
