-- Demo hostels & rooms for allocation testing (mirrors institutional labels).
-- Run on hostel_management after core tables exist (schema.sql applied).
-- Idempotent-ish: deletes prior [TEST]% hostels plus their rooms.

USE hostel_management;

DELETE r FROM rooms r
INNER JOIN hostels h ON h.id=r.hostel_id WHERE h.name LIKE '[TEST]%';
DELETE FROM hostels WHERE name LIKE '[TEST]%';

INSERT INTO hostels (name,type) VALUES ('[TEST] UAF Agronomy Boys Annex','boys');
SET @hidBoys = LAST_INSERT_ID();
INSERT INTO hostels (name,type) VALUES ('[TEST] UAF Home Economics Girls Wing','girls');
SET @hidGirls = LAST_INSERT_ID();

INSERT INTO rooms (hostel_id,room_number,capacity,occupied) VALUES
(@hidBoys,'T2-101',2,0),
(@hidBoys,'T2-102',2,0),
(@hidBoys,'T3-201',3,0),
(@hidBoys,'T3-202',3,0),
(@hidGirls,'F2-A01',2,0),
(@hidGirls,'F2-A02',2,0),
(@hidGirls,'F3-B01',3,0);
