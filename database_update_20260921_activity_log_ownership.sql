-- Additive audit-log ownership update.
-- Existing name-only records remain available to administrators, but are not
-- assigned to a beneficiary automatically because names are not unique IDs.

ALTER TABLE activity_logs
  ADD COLUMN IF NOT EXISTS user_id INT NULL AFTER admin_id;

SET @user_id_index_exists = (
  SELECT COUNT(*)
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'activity_logs'
    AND index_name = 'idx_activity_user_id'
);

SET @add_user_id_index = IF(
  @user_id_index_exists = 0,
  'ALTER TABLE activity_logs ADD KEY idx_activity_user_id (user_id)',
  'SELECT 1'
);

PREPARE activity_index_statement FROM @add_user_id_index;
EXECUTE activity_index_statement;
DEALLOCATE PREPARE activity_index_statement;
