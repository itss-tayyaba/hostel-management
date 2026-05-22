-- Ensure `users.role` supports `warden` (older DBs may only have admin/student).
-- Run once on existing hostel_management databases.

USE hostel_management;

ALTER TABLE users
MODIFY COLUMN role ENUM('admin','warden','student') NOT NULL DEFAULT 'student';

