USE hostel_management;
ALTER TABLE students
  ADD COLUMN phone VARCHAR(40) NULL DEFAULT NULL AFTER department,
  ADD COLUMN avatar_path VARCHAR(255) NULL DEFAULT NULL AFTER phone;
