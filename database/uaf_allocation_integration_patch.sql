-- UAF Allocation Integration Patch (non-destructive)
-- Applies additive schema updates and performance indexes only.

START TRANSACTION;

CREATE TABLE IF NOT EXISTS uaf_hostel_rooms (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    hostel_name VARCHAR(120) NOT NULL,
    faculty_affinity VARCHAR(120) NULL,
    room_type ENUM('General', 'PhD', 'International') NOT NULL DEFAULT 'General',
    gender_restriction ENUM('Male', 'Female') NULL,
    capacity INT UNSIGNED NOT NULL DEFAULT 1,
    current_occupancy INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS student_applications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_name VARCHAR(160) NOT NULL,
    gender ENUM('Male', 'Female') NOT NULL,
    faculty VARCHAR(120) NOT NULL,
    degree_level ENUM('Undergraduate', 'MS/MPhil', 'PhD') NOT NULL DEFAULT 'Undergraduate',
    year_of_study INT UNSIGNED NOT NULL DEFAULT 1,
    cgpa DECIMAL(3,2) NOT NULL DEFAULT 0.00,
    is_international TINYINT(1) NOT NULL DEFAULT 0,
    has_unpaid_dues TINYINT(1) NOT NULL DEFAULT 0,
    past_violations_count INT UNSIGNED NOT NULL DEFAULT 0,
    preferred_hostel_id BIGINT UNSIGNED NULL,
    preferred_room_id BIGINT UNSIGNED NULL,
    allocation_status ENUM('pending', 'allocated', 'waitlisted') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS uaf_allotments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_application_id BIGINT UNSIGNED NOT NULL,
    room_id BIGINT UNSIGNED NOT NULL,
    priority_score DECIMAL(10,2) NOT NULL,
    allocated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    notes VARCHAR(255) NULL,
    CONSTRAINT fk_uaf_allotments_student
        FOREIGN KEY (student_application_id) REFERENCES student_applications(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_uaf_allotments_room
        FOREIGN KEY (room_id) REFERENCES uaf_hostel_rooms(id)
        ON DELETE RESTRICT,
    UNIQUE KEY uq_uaf_allotments_student_once (student_application_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;

-- Conditional column/index additions for existing deployments.
DELIMITER $$

DROP PROCEDURE IF EXISTS patch_uaf_allocation_schema $$
CREATE PROCEDURE patch_uaf_allocation_schema()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'uaf_hostel_rooms' AND COLUMN_NAME = 'faculty_affinity'
    ) THEN
        ALTER TABLE uaf_hostel_rooms ADD COLUMN faculty_affinity VARCHAR(120) NULL AFTER hostel_name;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'uaf_hostel_rooms' AND COLUMN_NAME = 'room_type'
    ) THEN
        ALTER TABLE uaf_hostel_rooms ADD COLUMN room_type ENUM('General', 'PhD', 'International') NOT NULL DEFAULT 'General' AFTER faculty_affinity;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'uaf_hostel_rooms' AND COLUMN_NAME = 'capacity'
    ) THEN
        ALTER TABLE uaf_hostel_rooms ADD COLUMN capacity INT UNSIGNED NOT NULL DEFAULT 1 AFTER gender_restriction;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'uaf_hostel_rooms' AND COLUMN_NAME = 'current_occupancy'
    ) THEN
        ALTER TABLE uaf_hostel_rooms ADD COLUMN current_occupancy INT UNSIGNED NOT NULL DEFAULT 0 AFTER capacity;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'student_applications' AND COLUMN_NAME = 'degree_level'
    ) THEN
        ALTER TABLE student_applications ADD COLUMN degree_level ENUM('Undergraduate', 'MS/MPhil', 'PhD') NOT NULL DEFAULT 'Undergraduate' AFTER faculty;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'student_applications' AND COLUMN_NAME = 'year_of_study'
    ) THEN
        ALTER TABLE student_applications ADD COLUMN year_of_study INT UNSIGNED NOT NULL DEFAULT 1 AFTER degree_level;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'student_applications' AND COLUMN_NAME = 'cgpa'
    ) THEN
        ALTER TABLE student_applications ADD COLUMN cgpa DECIMAL(3,2) NOT NULL DEFAULT 0.00 AFTER year_of_study;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'student_applications' AND COLUMN_NAME = 'is_international'
    ) THEN
        ALTER TABLE student_applications ADD COLUMN is_international TINYINT(1) NOT NULL DEFAULT 0 AFTER cgpa;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'student_applications' AND COLUMN_NAME = 'has_unpaid_dues'
    ) THEN
        ALTER TABLE student_applications ADD COLUMN has_unpaid_dues TINYINT(1) NOT NULL DEFAULT 0 AFTER is_international;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'student_applications' AND COLUMN_NAME = 'past_violations_count'
    ) THEN
        ALTER TABLE student_applications ADD COLUMN past_violations_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER has_unpaid_dues;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'student_applications' AND COLUMN_NAME = 'allocation_status'
    ) THEN
        ALTER TABLE student_applications ADD COLUMN allocation_status ENUM('pending', 'allocated', 'waitlisted') NOT NULL DEFAULT 'pending' AFTER past_violations_count;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'student_applications' AND COLUMN_NAME = 'preferred_hostel_id'
    ) THEN
        ALTER TABLE student_applications ADD COLUMN preferred_hostel_id BIGINT UNSIGNED NULL AFTER past_violations_count;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'student_applications' AND COLUMN_NAME = 'preferred_room_id'
    ) THEN
        ALTER TABLE student_applications ADD COLUMN preferred_room_id BIGINT UNSIGNED NULL AFTER preferred_hostel_id;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'uaf_hostel_rooms' AND INDEX_NAME = 'idx_uaf_rooms_lookup'
    ) THEN
        CREATE INDEX idx_uaf_rooms_lookup ON uaf_hostel_rooms (faculty_affinity, gender_restriction, room_type, current_occupancy, capacity);
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'student_applications' AND INDEX_NAME = 'idx_student_pending_lookup'
    ) THEN
        CREATE INDEX idx_student_pending_lookup ON student_applications (allocation_status, faculty, gender, degree_level, is_international, year_of_study);
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'student_applications' AND INDEX_NAME = 'idx_student_merit_penalty'
    ) THEN
        CREATE INDEX idx_student_merit_penalty ON student_applications (cgpa, has_unpaid_dues, past_violations_count);
    END IF;
END $$

CALL patch_uaf_allocation_schema() $$
DROP PROCEDURE patch_uaf_allocation_schema $$

DELIMITER ;

