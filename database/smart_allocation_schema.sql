-- =====================================================================
-- Smart AI-style room recommendation & bed-based allocation schema
-- Safe to re-run on MySQL / MariaDB: skips existing student columns and
-- skips room occupancy migration if already converted to INTEGER.
--
-- If your students table already has semester, room_preference, and
-- fee_status (e.g. VARCHAR profile fields), those blocks are skipped.
-- =====================================================================
--
-- Allocation workflow (summary):
--  1. rooms.occupied is an INTEGER count of filled beds (0 .. capacity).
--  2. beds holds one row per physical berth; allocations links a student to a bed.
--  3. Foreign keys + UNIQUE constraints block double booking; PHP enforces gender rules.

USE hostel_management;

DROP PROCEDURE IF EXISTS smart_allocation_schema_apply;

DELIMITER $$

CREATE PROCEDURE smart_allocation_schema_apply()
BEGIN
    DECLARE col_count INT DEFAULT 0;
    DECLARE occ_type VARCHAR(64) DEFAULT NULL;
    DECLARE has_occ_new INT DEFAULT 0;

    -- -----------------------------------------------------------------
    -- STUDENTS: only add columns that are missing (avoids #1060 duplicate)
    -- Uses VARCHAR for preference/fee when adding fresh, matching common
    -- profile migrations; PHP treats values the same as ENUMs.
    -- -----------------------------------------------------------------
    SELECT COUNT(*) INTO col_count
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'students'
      AND COLUMN_NAME = 'semester';
    IF col_count = 0 THEN
        ALTER TABLE students
            ADD COLUMN semester VARCHAR(50) NULL AFTER department;
    END IF;

    SELECT COUNT(*) INTO col_count
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'students'
      AND COLUMN_NAME = 'room_preference';
    IF col_count = 0 THEN
        ALTER TABLE students
            ADD COLUMN room_preference VARCHAR(20) NOT NULL DEFAULT 'shared' AFTER semester;
    END IF;

    SELECT COUNT(*) INTO col_count
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'students'
      AND COLUMN_NAME = 'fee_status';
    IF col_count = 0 THEN
        ALTER TABLE students
            ADD COLUMN fee_status VARCHAR(20) NOT NULL DEFAULT 'pending' AFTER room_preference;
    END IF;

    -- -----------------------------------------------------------------
    -- ROOMS: room_type (skip if already present)
    -- -----------------------------------------------------------------
    SELECT COUNT(*) INTO col_count
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'rooms'
      AND COLUMN_NAME = 'room_type';
    IF col_count = 0 THEN
        ALTER TABLE rooms
            ADD COLUMN room_type ENUM('single','shared') NOT NULL DEFAULT 'shared' AFTER capacity;
    END IF;

    -- -----------------------------------------------------------------
    -- ROOMS: migrate BOOLEAN/TINYINT occupied → INT bed count (once only)
    -- -----------------------------------------------------------------
    SELECT DATA_TYPE INTO occ_type
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'rooms'
      AND COLUMN_NAME = 'occupied'
    LIMIT 1;

    SELECT COUNT(*) INTO has_occ_new
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'rooms'
      AND COLUMN_NAME = 'occupied_new';

    IF occ_type = 'tinyint' AND has_occ_new = 0 THEN
        ALTER TABLE rooms
            ADD COLUMN occupied_new INT NOT NULL DEFAULT 0 AFTER room_type;

        UPDATE rooms
        SET occupied_new = CASE
            WHEN CAST(occupied AS UNSIGNED) >= 1 AND capacity <= 1 THEN LEAST(capacity, 1)
            WHEN CAST(occupied AS UNSIGNED) >= 1 THEN capacity
            ELSE 0
        END;

        ALTER TABLE rooms DROP COLUMN occupied;
        ALTER TABLE rooms CHANGE COLUMN occupied_new occupied INT NOT NULL DEFAULT 0;
    END IF;
END$$

DELIMITER ;

CALL smart_allocation_schema_apply();
DROP PROCEDURE IF EXISTS smart_allocation_schema_apply;

-- ---------------------------------------------------------------------
-- BEDS / ALLOCATIONS / WAITING LIST (idempotent create)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS beds (
  id INT AUTO_INCREMENT PRIMARY KEY,
  room_id INT NOT NULL,
  bed_code VARCHAR(24) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_beds_room_code (room_id, bed_code),
  KEY idx_beds_room_id (room_id),
  CONSTRAINT fk_beds_room
    FOREIGN KEY (room_id) REFERENCES rooms(id)
    ON DELETE CASCADE
    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS allocations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  student_id INT NOT NULL,
  room_id INT NOT NULL,
  bed_id INT NOT NULL,
  allocated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  admin_user_id INT NULL,
  notes VARCHAR(255) NULL,
  UNIQUE KEY uq_allocations_student (student_id),
  UNIQUE KEY uq_allocations_bed (bed_id),
  KEY idx_allocations_room_id (room_id),
  CONSTRAINT fk_allocations_student
    FOREIGN KEY (student_id) REFERENCES students(id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,
  CONSTRAINT fk_allocations_room
    FOREIGN KEY (room_id) REFERENCES rooms(id)
    ON DELETE RESTRICT
    ON UPDATE CASCADE,
  CONSTRAINT fk_allocations_bed
    FOREIGN KEY (bed_id) REFERENCES beds(id)
    ON DELETE RESTRICT
    ON UPDATE CASCADE,
  CONSTRAINT fk_allocations_admin_user
    FOREIGN KEY (admin_user_id) REFERENCES users(id)
    ON DELETE SET NULL
    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS allocation_waiting_list (
  id INT AUTO_INCREMENT PRIMARY KEY,
  student_id INT NOT NULL UNIQUE,
  hostel_id INT NULL,
  status ENUM('queued','assigned','cancelled') NOT NULL DEFAULT 'queued',
  priority INT NOT NULL DEFAULT 0,
  requested_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  processed_at TIMESTAMP NULL,
  notes VARCHAR(255) NULL,
  KEY idx_waiting_status_priority (status, priority DESC, requested_at ASC),
  CONSTRAINT fk_waiting_student
    FOREIGN KEY (student_id) REFERENCES students(id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,
  CONSTRAINT fk_waiting_hostel
    FOREIGN KEY (hostel_id) REFERENCES hostels(id)
    ON DELETE SET NULL
    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Generate beds B1..Bn for each room (supports capacity up to 12)
-- ---------------------------------------------------------------------
INSERT IGNORE INTO beds (room_id, bed_code)
SELECT r.id, CONCAT('B', n.n)
FROM rooms r
CROSS JOIN (
  SELECT 1 AS n UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
  UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8
  UNION ALL SELECT 9 UNION ALL SELECT 10 UNION ALL SELECT 11 UNION ALL SELECT 12
) AS n
WHERE n.n <= r.capacity;
