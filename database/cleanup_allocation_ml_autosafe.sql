-- Auto-safe cleanup script for removing room allocation + ML artifacts
-- Usage:
--   1) Backup your database first.
--   2) In phpMyAdmin, select your target DB (e.g., hostel_management).
--   3) Run this script.

SET @target_db = DATABASE();
-- Optional: uncomment and set if DATABASE() is empty in your client.
-- SET @target_db = 'hostel_management';

-- Safety check: require a selected database.
SET @db_ok = IF(@target_db IS NULL OR @target_db = '', 0, 1);
SET @sql = IF(@db_ok = 1, 'SELECT "Using database cleanup mode" AS info', 'SELECT "ERROR: No database selected. Select DB first." AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Stop execution logic if no DB selected (soft guard for clients that continue).
SET @skip = IF(@db_ok = 1, 0, 1);

SET FOREIGN_KEY_CHECKS = 0;

-- -------------------------------------------------------------------------
-- Drop allocation / ML / rule-engine tables only if they exist
-- -------------------------------------------------------------------------
SET @drop_tables = '
DROP TABLE IF EXISTS
  hostel_applications,
  applications,
  faculty_hostels,
  fee_records,
  disciplinary_records,
  student_notifications,
  allocations,
  allocation_waiting_list,
  beds,
  room_allocation
';
SET @sql = IF(@skip = 1, 'SELECT "Skipped table drops (no DB selected)" AS info', @drop_tables);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -------------------------------------------------------------------------
-- Drop students columns safely (only if they exist)
-- -------------------------------------------------------------------------
DROP TEMPORARY TABLE IF EXISTS tmp_drop_columns;
CREATE TEMPORARY TABLE tmp_drop_columns (
  col_name VARCHAR(64) NOT NULL
);

INSERT INTO tmp_drop_columns (col_name) VALUES
  ('nationality'),
  ('faculty'),
  ('student_status'),
  ('expelled'),
  ('graduated'),
  ('hostel_ban'),
  ('distance_from_uaf_km'),
  ('special_needs'),
  ('home_province'),
  ('fee_paid_on_time'),
  ('disciplinary_cases'),
  ('has_sibling_in_hostel'),
  ('previous_resident'),
  ('mess_enrolled');

SELECT GROUP_CONCAT(CONCAT('DROP COLUMN `', c.COLUMN_NAME, '`') ORDER BY c.ORDINAL_POSITION SEPARATOR ', ')
INTO @drop_cols_sql
FROM INFORMATION_SCHEMA.COLUMNS c
JOIN tmp_drop_columns t ON t.col_name = c.COLUMN_NAME
WHERE c.TABLE_SCHEMA = @target_db
  AND c.TABLE_NAME = 'students';

SET @drop_cols_stmt = IF(
  @skip = 1,
  'SELECT "Skipped students column cleanup (no DB selected)" AS info',
  IF(
    @drop_cols_sql IS NULL OR @drop_cols_sql = '',
    'SELECT "No matching students columns found to drop." AS info',
    CONCAT('ALTER TABLE `students` ', @drop_cols_sql)
  )
);
PREPARE stmt FROM @drop_cols_stmt; EXECUTE stmt; DEALLOCATE PREPARE stmt;

DROP TEMPORARY TABLE IF EXISTS tmp_drop_columns;

SET FOREIGN_KEY_CHECKS = 1;

SELECT 'Auto-safe cleanup completed.' AS status, @target_db AS database_name;
