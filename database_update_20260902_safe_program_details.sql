-- Safe phase 1: add program detail tables and COPY existing values.
-- This migration intentionally does not alter or remove any beneficiaries column.
-- It is idempotent and can be run again without creating duplicate detail rows.

CREATE TABLE IF NOT EXISTS beneficiary_spes_details (
  beneficiary_id INT NOT NULL,
  gsis_beneficiary_name VARCHAR(150) NULL,
  gsis_relationship VARCHAR(100) NULL,
  place_of_birth VARCHAR(150) NULL,
  citizenship VARCHAR(50) NULL,
  social_media VARCHAR(150) NULL,
  spes_type VARCHAR(50) NULL,
  parents_status VARCHAR(100) NULL,
  permanent_address TEXT NULL,
  father_name VARCHAR(100) NULL,
  father_contact VARCHAR(50) NULL,
  father_occupation VARCHAR(100) NULL,
  mother_name VARCHAR(100) NULL,
  mother_contact VARCHAR(50) NULL,
  mother_occupation VARCHAR(100) NULL,
  elem_school VARCHAR(150) NULL,
  elem_degree VARCHAR(100) NULL,
  elem_year_level VARCHAR(50) NULL,
  elem_date_attendance VARCHAR(50) NULL,
  sec_school VARCHAR(150) NULL,
  sec_degree VARCHAR(100) NULL,
  sec_year_level VARCHAR(50) NULL,
  sec_date_attendance VARCHAR(50) NULL,
  tert_school VARCHAR(150) NULL,
  tert_course VARCHAR(100) NULL,
  tert_year_level VARCHAR(50) NULL,
  tert_date_attendance VARCHAR(50) NULL,
  tv_school VARCHAR(150) NULL,
  tv_course VARCHAR(100) NULL,
  tv_year_level VARCHAR(50) NULL,
  tv_date_attendance VARCHAR(50) NULL,
  special_skills TEXT NULL,
  spes_other_info TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (beneficiary_id),
  CONSTRAINT fk_spes_details_beneficiary
    FOREIGN KEY (beneficiary_id) REFERENCES beneficiaries (beneficiary_id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS beneficiary_spes_history (
  history_id INT NOT NULL AUTO_INCREMENT,
  beneficiary_id INT NOT NULL,
  sequence_no TINYINT UNSIGNED NOT NULL,
  establishment VARCHAR(180) NULL,
  availment_year VARCHAR(20) NULL,
  spes_id_number VARCHAR(50) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (history_id),
  UNIQUE KEY uq_spes_history_sequence (beneficiary_id, sequence_no),
  CONSTRAINT fk_spes_history_beneficiary
    FOREIGN KEY (beneficiary_id) REFERENCES beneficiaries (beneficiary_id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS beneficiary_msme_details (
  beneficiary_id INT NOT NULL,
  business_name VARCHAR(150) NULL,
  ownership_type VARCHAR(50) NULL,
  business_nature VARCHAR(100) NULL,
  primary_products TEXT NULL,
  product_price TEXT NULL,
  year_started VARCHAR(10) NULL,
  business_permit_no VARCHAR(100) NULL,
  permit_validity VARCHAR(50) NULL,
  dti_no VARCHAR(100) NULL,
  tin_no VARCHAR(100) NULL,
  educational_attainment VARCHAR(100) NULL,
  work_experience TEXT NULL,
  business_email VARCHAR(150) NULL,
  business_social_media VARCHAR(150) NULL,
  assets_owned TEXT NULL,
  utility_needs TEXT NULL,
  hr_male INT NOT NULL DEFAULT 0,
  hr_female INT NOT NULL DEFAULT 0,
  hr_total INT NOT NULL DEFAULT 0,
  emp_regular INT NOT NULL DEFAULT 0,
  emp_seasonal INT NOT NULL DEFAULT 0,
  emp_contractual INT NOT NULL DEFAULT 0,
  emp_family INT NOT NULL DEFAULT 0,
  hr_skills TEXT NULL,
  source_of_capital TEXT NULL,
  business_size VARCHAR(50) NULL,
  initial_capital DECIMAL(15,2) NULL,
  current_capital DECIMAL(15,2) NULL,
  daily_earnings DECIMAL(15,2) NULL,
  mode_of_payment TEXT NULL,
  distribution_channels TEXT NULL,
  availed_before VARCHAR(10) NULL,
  assistance_availed TEXT NULL,
  past_programs TEXT NULL,
  programs_needed TEXT NULL,
  challenges_encountered TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (beneficiary_id),
  CONSTRAINT fk_msme_details_beneficiary
    FOREIGN KEY (beneficiary_id) REFERENCES beneficiaries (beneficiary_id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO beneficiary_spes_details (
  beneficiary_id, gsis_beneficiary_name, gsis_relationship, place_of_birth,
  citizenship, social_media, spes_type, parents_status, permanent_address,
  father_name, father_contact, father_occupation, mother_name, mother_contact,
  mother_occupation, elem_school, elem_degree, elem_year_level,
  elem_date_attendance, sec_school, sec_degree, sec_year_level,
  sec_date_attendance, tert_school, tert_course, tert_year_level,
  tert_date_attendance, tv_school, tv_course, tv_year_level,
  tv_date_attendance, special_skills, spes_other_info
)
SELECT
  b.beneficiary_id, b.gsis_beneficiary_name, b.gsis_relationship,
  b.place_of_birth, b.citizenship, b.social_media, b.spes_type,
  b.parents_status, b.permanent_address, b.father_name, b.father_contact,
  b.father_occupation, b.mother_name, b.mother_contact, b.mother_occupation,
  b.elem_school, b.elem_degree, b.elem_year_level, b.elem_date_attendance,
  b.sec_school, b.sec_degree, b.sec_year_level, b.sec_date_attendance,
  b.tert_school, b.tert_course, b.tert_year_level, b.tert_date_attendance,
  b.tv_school, b.tv_course, b.tv_year_level, b.tv_date_attendance,
  b.special_skills, b.spes_other_info
FROM beneficiaries b
JOIN programs p ON p.program_id = b.program_id
WHERE UPPER(p.program_name) LIKE '%SPES%'
ON DUPLICATE KEY UPDATE beneficiary_id = VALUES(beneficiary_id);

INSERT INTO beneficiary_spes_history
  (beneficiary_id, sequence_no, establishment, availment_year, spes_id_number)
SELECT beneficiary_id, sequence_no, establishment, availment_year, spes_id_number
FROM (
  SELECT b.beneficiary_id, 1 sequence_no, b.spes_history_1_establishment establishment,
         b.spes_history_1_year availment_year, b.spes_history_1_id spes_id_number
  FROM beneficiaries b JOIN programs p ON p.program_id=b.program_id
  WHERE UPPER(p.program_name) LIKE '%SPES%'
  UNION ALL
  SELECT b.beneficiary_id, 2, b.spes_history_2_establishment, b.spes_history_2_year, b.spes_history_2_id
  FROM beneficiaries b JOIN programs p ON p.program_id=b.program_id
  WHERE UPPER(p.program_name) LIKE '%SPES%'
  UNION ALL
  SELECT b.beneficiary_id, 3, b.spes_history_3_establishment, b.spes_history_3_year, b.spes_history_3_id
  FROM beneficiaries b JOIN programs p ON p.program_id=b.program_id
  WHERE UPPER(p.program_name) LIKE '%SPES%'
  UNION ALL
  SELECT b.beneficiary_id, 4, b.spes_history_4_establishment, b.spes_history_4_year, b.spes_history_4_id
  FROM beneficiaries b JOIN programs p ON p.program_id=b.program_id
  WHERE UPPER(p.program_name) LIKE '%SPES%'
) history_rows
WHERE COALESCE(TRIM(establishment), TRIM(availment_year), TRIM(spes_id_number), '') <> ''
ON DUPLICATE KEY UPDATE history_id = history_id;

INSERT INTO beneficiary_msme_details (
  beneficiary_id, business_name, ownership_type, business_nature,
  primary_products, product_price, year_started, business_permit_no,
  permit_validity, dti_no, tin_no, educational_attainment, work_experience,
  business_email, business_social_media, assets_owned, utility_needs,
  hr_male, hr_female, hr_total, emp_regular, emp_seasonal, emp_contractual,
  emp_family, hr_skills, source_of_capital, business_size, initial_capital,
  current_capital, daily_earnings, mode_of_payment, distribution_channels,
  availed_before, assistance_availed, past_programs, programs_needed,
  challenges_encountered
)
SELECT
  b.beneficiary_id, b.business_name, b.ownership_type, b.business_nature,
  b.primary_products, b.product_price, b.year_started, b.business_permit_no,
  b.permit_validity, b.dti_no, b.tin_no, b.educational_attainment,
  b.work_experience, b.business_email, b.business_social_media,
  b.assets_owned, b.utility_needs, COALESCE(b.hr_male,0),
  COALESCE(b.hr_female,0), COALESCE(b.hr_total,0), COALESCE(b.emp_regular,0),
  COALESCE(b.emp_seasonal,0), COALESCE(b.emp_contractual,0),
  COALESCE(b.emp_family,0), b.hr_skills, b.source_of_capital, b.business_size,
  b.initial_capital, b.current_capital, b.daily_earnings, b.mode_of_payment,
  b.distribution_channels, b.availed_before, b.assistance_availed,
  b.past_programs, b.programs_needed, b.challenges_encountered
FROM beneficiaries b
JOIN programs p ON p.program_id = b.program_id
WHERE UPPER(p.program_name) LIKE '%MSME%'
ON DUPLICATE KEY UPDATE beneficiary_id = VALUES(beneficiary_id);
