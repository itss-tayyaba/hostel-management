START TRANSACTION;

-- MariaDB-safe constraint update:
-- DROP CHECK is not supported; use DROP CONSTRAINT when present.
SET @has_year_check := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'student_applications'
    AND CONSTRAINT_NAME = 'chk_student_apps_year_range'
    AND CONSTRAINT_TYPE = 'CHECK'
);

SET @drop_sql := IF(
  @has_year_check > 0,
  'ALTER TABLE student_applications DROP CONSTRAINT chk_student_apps_year_range',
  'SELECT 1'
);
PREPARE stmt_drop FROM @drop_sql;
EXECUTE stmt_drop;
DEALLOCATE PREPARE stmt_drop;

ALTER TABLE student_applications
    ADD CONSTRAINT chk_student_apps_year_range CHECK (year_of_study BETWEEN 1 AND 7);

ALTER TABLE student_applications
    ADD COLUMN is_uaf_student TINYINT(1) NOT NULL DEFAULT 1 AFTER has_unpaid_dues,
    ADD COLUMN distance_from_home SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER is_uaf_student,
    ADD COLUMN has_disability TINYINT(1) NOT NULL DEFAULT 0 AFTER distance_from_home;

ALTER TABLE student_applications
    MODIFY COLUMN allocation_status ENUM('pending','allocated','waitlisted','ineligible')
    NOT NULL DEFAULT 'pending';

CREATE TABLE IF NOT EXISTS uaf_waitlist (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_application_id BIGINT UNSIGNED NOT NULL UNIQUE,
  priority_score DECIMAL(10,2) NOT NULL,
  position INT UNSIGNED NOT NULL DEFAULT 0,
  reason VARCHAR(120) NOT NULL DEFAULT 'no_room',
  waitlisted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_waitlist_student
    FOREIGN KEY (student_application_id)
    REFERENCES student_applications(id) ON DELETE CASCADE,
  INDEX idx_waitlist_priority (priority_score DESC, waitlisted_at ASC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS uaf_room_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  room_id BIGINT UNSIGNED NOT NULL,
  event_type ENUM('checkout','room_change','cancellation','manual_free') NOT NULL,
  freed_beds TINYINT UNSIGNED NOT NULL DEFAULT 1,
  triggered_by VARCHAR(80) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_room_events_room (room_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;
