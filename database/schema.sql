-- Hostel Management System (core PHP + MySQL)
-- Run this SQL in phpMyAdmin or via your MySQL client.

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;

CREATE DATABASE IF NOT EXISTS hostel_management
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE hostel_management;

-- Users: Admin + Students
DROP TABLE IF EXISTS payments;
DROP TABLE IF EXISTS warden_assignments;
DROP TABLE IF EXISTS complaints;
DROP TABLE IF EXISTS room_change_requests;
DROP TABLE IF EXISTS allocation_history;
DROP TABLE IF EXISTS room_allocation;
DROP TABLE IF EXISTS rooms;
DROP TABLE IF EXISTS hostels;
DROP TABLE IF EXISTS students;
DROP TABLE IF EXISTS users;

CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  email VARCHAR(191) NOT NULL UNIQUE,
  phone VARCHAR(40) NULL,
  password VARCHAR(255) NOT NULL,
  role ENUM('admin','warden','student') NOT NULL DEFAULT 'student',
  gender ENUM('male','female') NOT NULL DEFAULT 'male',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE students (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL UNIQUE,
  reg_no VARCHAR(50) NOT NULL UNIQUE,
  department VARCHAR(150) NOT NULL,
  gender ENUM('male','female') NOT NULL DEFAULT 'male',
  phone VARCHAR(40) NULL,
  avatar_path VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_students_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE
    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE hostels (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  type ENUM('boys','girls','mixed') NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Warden accounts usable only after admin assigns them to a hostel
CREATE TABLE warden_assignments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  hostel_id INT NOT NULL,
  assigned_by_user_id INT NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_wa_user (user_id),
  KEY idx_wa_hostel_active (hostel_id, is_active),
  CONSTRAINT fk_wa_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,
  CONSTRAINT fk_wa_hostel
    FOREIGN KEY (hostel_id) REFERENCES hostels(id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,
  CONSTRAINT fk_wa_assigned_by
    FOREIGN KEY (assigned_by_user_id) REFERENCES users(id)
    ON DELETE RESTRICT
    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE rooms (
  id INT AUTO_INCREMENT PRIMARY KEY,
  hostel_id INT NOT NULL,
  room_number VARCHAR(50) NOT NULL,
  capacity INT NOT NULL DEFAULT 1,
  occupied TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_rooms_hostel
    FOREIGN KEY (hostel_id) REFERENCES hostels(id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,
  UNIQUE KEY uq_hostel_room (hostel_id, room_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Applications / allocations (pending -> approved/rejected)
CREATE TABLE room_allocation (
  id INT AUTO_INCREMENT PRIMARY KEY,
  student_id INT NOT NULL,
  room_id INT NOT NULL,
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  responded_at TIMESTAMP NULL,
  admin_user_id INT NULL,
  notes VARCHAR(255) NULL,
  CONSTRAINT fk_allocation_student
    FOREIGN KEY (student_id) REFERENCES students(id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,
  CONSTRAINT fk_allocation_room
    FOREIGN KEY (room_id) REFERENCES rooms(id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,
  CONSTRAINT fk_allocation_admin
    FOREIGN KEY (admin_user_id) REFERENCES users(id)
    ON DELETE SET NULL
    ON UPDATE CASCADE,
  KEY idx_allocation_student_status (student_id, status),
  KEY idx_allocation_room_status (room_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Allocation history (for tracking approved changes)
CREATE TABLE allocation_history (
  id INT AUTO_INCREMENT PRIMARY KEY,
  student_id INT NOT NULL,
  allocation_id INT NULL,
  from_room_id INT NULL,
  to_room_id INT NOT NULL,
  action ENUM('allocated','changed','reallocated') NOT NULL DEFAULT 'allocated',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_history_student
    FOREIGN KEY (student_id) REFERENCES students(id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,
  CONSTRAINT fk_history_allocation
    FOREIGN KEY (allocation_id) REFERENCES room_allocation(id)
    ON DELETE SET NULL
    ON UPDATE CASCADE,
  CONSTRAINT fk_history_from_room
    FOREIGN KEY (from_room_id) REFERENCES rooms(id)
    ON DELETE SET NULL
    ON UPDATE CASCADE,
  CONSTRAINT fk_history_to_room
    FOREIGN KEY (to_room_id) REFERENCES rooms(id)
    ON DELETE RESTRICT
    ON UPDATE CASCADE,
  KEY idx_history_student (student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE room_change_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  student_id INT NOT NULL,
  from_room_id INT NULL,
  to_room_id INT NULL,
  reason TEXT NULL,
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  requested_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  responded_at TIMESTAMP NULL,
  admin_user_id INT NULL,
  CONSTRAINT fk_change_student
    FOREIGN KEY (student_id) REFERENCES students(id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,
  CONSTRAINT fk_change_from_room
    FOREIGN KEY (from_room_id) REFERENCES rooms(id)
    ON DELETE SET NULL
    ON UPDATE CASCADE,
  CONSTRAINT fk_change_to_room
    FOREIGN KEY (to_room_id) REFERENCES rooms(id)
    ON DELETE SET NULL
    ON UPDATE CASCADE,
  CONSTRAINT fk_change_admin
    FOREIGN KEY (admin_user_id) REFERENCES users(id)
    ON DELETE SET NULL
    ON UPDATE CASCADE,
  KEY idx_change_student_status (student_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Complaints raised by students
CREATE TABLE complaints (
  id INT AUTO_INCREMENT PRIMARY KEY,
  student_id INT NOT NULL,
  hostel_id INT NULL,
  room_id INT NULL,
  title VARCHAR(200) NOT NULL,
  description TEXT NOT NULL,
  status ENUM('open','in_progress','resolved','closed') NOT NULL DEFAULT 'open',
  escalated_to_admin TINYINT(1) NOT NULL DEFAULT 0,
  escalated_at TIMESTAMP NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at TIMESTAMP NULL,
  CONSTRAINT fk_complaint_student
    FOREIGN KEY (student_id) REFERENCES students(id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,
  CONSTRAINT fk_complaint_hostel
    FOREIGN KEY (hostel_id) REFERENCES hostels(id)
    ON DELETE SET NULL
    ON UPDATE CASCADE,
  CONSTRAINT fk_complaint_room
    FOREIGN KEY (room_id) REFERENCES rooms(id)
    ON DELETE SET NULL
    ON UPDATE CASCADE,
  KEY idx_complaint_escalated (escalated_to_admin, escalated_at),
  KEY idx_complaint_student (student_id),
  KEY idx_complaint_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Payments for hostel fee (basic tracking)
CREATE TABLE payments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  student_id INT NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  semester VARCHAR(50) NOT NULL,
  payment_date DATE NOT NULL,
  method ENUM('cash','bank_transfer','online') NOT NULL DEFAULT 'cash',
  status ENUM('pending','paid','failed') NOT NULL DEFAULT 'paid',
  reference VARCHAR(120) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_payment_student
    FOREIGN KEY (student_id) REFERENCES students(id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,
  KEY idx_payments_student (student_id),
  KEY idx_payments_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;

