-- Add admin escalation tracking to complaints table.
-- Run once on existing hostel_management databases.

USE hostel_management;

ALTER TABLE complaints
  ADD COLUMN escalated_to_admin TINYINT(1) NOT NULL DEFAULT 0 AFTER status,
  ADD COLUMN escalated_at TIMESTAMP NULL AFTER escalated_to_admin,
  ADD KEY idx_complaint_escalated (escalated_to_admin, escalated_at);

