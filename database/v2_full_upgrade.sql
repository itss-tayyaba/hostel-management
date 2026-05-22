-- =====================================================================
-- v2 Full Upgrade Migration
-- Adds: notices, audit_log, fee_structure, password_resets, login_attempts,
--       users.is_active, users.email_verified_at, users.email_verify_token
-- Safe to re-run: every block is guarded.
-- Run AFTER database/schema.sql and database/smart_allocation_schema.sql.
-- =====================================================================

USE hostel_management;

DROP PROCEDURE IF EXISTS v2_full_upgrade_apply;

DELIMITER $$

CREATE PROCEDURE v2_full_upgrade_apply()
BEGIN
    DECLARE col_count INT DEFAULT 0;

    -- users.is_active
    SELECT COUNT(*) INTO col_count FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'is_active';
    IF col_count = 0 THEN
        ALTER TABLE users ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER role;
    END IF;

    -- users.email_verified_at
    SELECT COUNT(*) INTO col_count FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'email_verified_at';
    IF col_count = 0 THEN
        ALTER TABLE users ADD COLUMN email_verified_at TIMESTAMP NULL AFTER is_active;
    END IF;

    -- users.email_verify_token
    SELECT COUNT(*) INTO col_count FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'email_verify_token';
    IF col_count = 0 THEN
        ALTER TABLE users ADD COLUMN email_verify_token VARCHAR(80) NULL AFTER email_verified_at;
    END IF;
END$$

DELIMITER ;

CALL v2_full_upgrade_apply();
DROP PROCEDURE IF EXISTS v2_full_upgrade_apply;

-- ---------------------------------------------------------------------
-- Notices (admin CRUD, public-facing list on landing/notices page)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    body TEXT NOT NULL,
    category ENUM('general','urgent','fee','allotment','rules','event') NOT NULL DEFAULT 'general',
    is_published TINYINT(1) NOT NULL DEFAULT 1,
    pinned TINYINT(1) NOT NULL DEFAULT 0,
    author_user_id INT NULL,
    published_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL,
    KEY idx_notices_pub (is_published, published_at DESC),
    KEY idx_notices_pinned (pinned DESC, published_at DESC),
    CONSTRAINT fk_notices_author
        FOREIGN KEY (author_user_id) REFERENCES users(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Audit log (login, payments, allocation, complaint resolution, etc.)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_log (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    actor_role VARCHAR(20) NULL,
    action VARCHAR(80) NOT NULL,
    entity_type VARCHAR(60) NULL,
    entity_id BIGINT NULL,
    detail VARCHAR(255) NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_audit_user (user_id, created_at DESC),
    KEY idx_audit_action (action, created_at DESC),
    KEY idx_audit_entity (entity_type, entity_id),
    CONSTRAINT fk_audit_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Fee structure per hostel + semester (used to auto-bill / show dues)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS fee_structure (
    id INT AUTO_INCREMENT PRIMARY KEY,
    hostel_id INT NULL,
    semester VARCHAR(50) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    due_date DATE NULL,
    notes VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_fee_hostel_sem (hostel_id, semester),
    CONSTRAINT fk_fee_hostel
        FOREIGN KEY (hostel_id) REFERENCES hostels(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Password reset tokens (no SMTP — token shown on-screen for dev env)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    used_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pr_token (token_hash),
    KEY idx_pr_user (user_id),
    CONSTRAINT fk_pr_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Login attempts (rate limiting + brute-force lockout)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS login_attempts (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(191) NULL,
    ip_address VARCHAR(45) NOT NULL,
    success TINYINT(1) NOT NULL DEFAULT 0,
    attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_la_ip_time (ip_address, attempted_at DESC),
    KEY idx_la_email_time (email, attempted_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Seed a default fee structure row + a welcome notice
-- ---------------------------------------------------------------------
INSERT IGNORE INTO fee_structure (hostel_id, semester, amount, notes)
VALUES (NULL, 'Fall 2026', 9000.00, 'Default per-semester hostel fee');

INSERT IGNORE INTO notices (title, body, category, is_published, pinned)
VALUES
('Welcome to the new portal',
 'The hostel management portal has been upgraded. You can now download your hostel card, view fee slips, and submit complaints online.',
 'general', 1, 1);
