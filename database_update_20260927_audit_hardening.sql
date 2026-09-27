-- Restore beneficiary ownership only when an orphaned user_id can be matched
-- unambiguously by normalized email and the target user has no record in the
-- same program. Ambiguous or duplicate cases are intentionally left untouched.
UPDATE beneficiaries AS b
LEFT JOIN users AS stale_user
  ON stale_user.user_id = b.user_id
JOIN (
  SELECT LOWER(TRIM(email)) AS email_key, MIN(user_id) AS user_id
  FROM users
  WHERE TRIM(COALESCE(email, '')) <> ''
  GROUP BY LOWER(TRIM(email))
  HAVING COUNT(*) = 1
) AS matched_user
  ON matched_user.email_key = LOWER(TRIM(b.email))
SET b.user_id = matched_user.user_id
WHERE b.user_id IS NOT NULL
  AND stale_user.user_id IS NULL
  AND NOT EXISTS (
    SELECT 1
    FROM beneficiaries AS existing_record
    WHERE existing_record.user_id = matched_user.user_id
      AND existing_record.program_id = b.program_id
      AND existing_record.beneficiary_id <> b.beneficiary_id
  );
