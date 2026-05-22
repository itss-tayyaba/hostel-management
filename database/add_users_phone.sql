-- Add phone column to users table for warden registration.
-- Run once on existing hostel_management databases.

USE hostel_management;

ALTER TABLE users
ADD COLUMN phone VARCHAR(40) NULL AFTER email;
