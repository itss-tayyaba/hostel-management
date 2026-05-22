-- Remove CGPA from students (run once on existing databases).
USE hostel_management;
ALTER TABLE students DROP COLUMN cgpa;
