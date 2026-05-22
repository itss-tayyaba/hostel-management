-- Admin-assigned hostel wardens. Run once on existing hostel_management databases.
-- (Also included in database/schema.sql for fresh installs.)

USE hostel_management;

CREATE TABLE IF NOT EXISTS warden_assignments (
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
