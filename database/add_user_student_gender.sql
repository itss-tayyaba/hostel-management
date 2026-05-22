-- Add gender (male/female) to users and students. Run once on existing DBs.

USE hostel_management;

ALTER TABLE users
  ADD COLUMN gender ENUM('male','female') NOT NULL DEFAULT 'male' AFTER role;

ALTER TABLE students
  ADD COLUMN gender ENUM('male','female') NOT NULL DEFAULT 'male' AFTER department;
