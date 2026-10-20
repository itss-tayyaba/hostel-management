-- UAF Hostel deterministic room-allocation schema
-- Safe to run multiple times.

START TRANSACTION;

CREATE TABLE IF NOT EXISTS uaf_hostel_rooms (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    hostel_name VARCHAR(120) NOT NULL,
    faculty_affinity VARCHAR(120) NOT NULL,
    room_type ENUM('General', 'PhD', 'International') NOT NULL DEFAULT 'General',
    gender_restriction ENUM('Male', 'Female') NOT NULL,
    capacity TINYINT UNSIGNED NOT NULL,
    current_occupancy TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT chk_uaf_rooms_capacity_positive CHECK (capacity > 0),
    CONSTRAINT chk_uaf_rooms_occupancy_nonneg CHECK (current_occupancy >= 0),
    CONSTRAINT chk_uaf_rooms_occupancy_within_capacity CHECK (current_occupancy <= capacity),
    INDEX idx_uaf_rooms_lookup (faculty_affinity, gender_restriction, room_type),
    INDEX idx_uaf_rooms_capacity_window (gender_restriction, current_occupancy, capacity),
    INDEX idx_uaf_rooms_hostel_faculty (hostel_name, faculty_affinity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS student_applications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_name VARCHAR(160) NOT NULL,
    gender ENUM('Male', 'Female') NOT NULL,
    faculty VARCHAR(120) NOT NULL,
    degree_level ENUM('Undergraduate', 'MS/MPhil', 'PhD') NOT NULL,
    year_of_study TINYINT UNSIGNED NOT NULL,
    cgpa DECIMAL(3,2) NOT NULL,
    is_international TINYINT(1) NOT NULL DEFAULT 0,
    has_unpaid_dues TINYINT(1) NOT NULL DEFAULT 0,
    past_violations_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    allocation_status ENUM('pending', 'allocated', 'waitlisted') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT chk_student_apps_year_range CHECK (year_of_study BETWEEN 1 AND 4),
    CONSTRAINT chk_student_apps_cgpa_range CHECK (cgpa >= 0.00 AND cgpa <= 4.00),
    INDEX idx_student_apps_pending_scan (allocation_status, faculty, gender, degree_level, year_of_study),
    INDEX idx_student_apps_priority_inputs (degree_level, year_of_study, cgpa, has_unpaid_dues, past_violations_count),
    INDEX idx_student_apps_faculty_gender (faculty, gender)
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
    UNIQUE KEY uq_uaf_allotments_student_once (student_application_id),
    INDEX idx_uaf_allotments_room_time (room_id, allocated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;

