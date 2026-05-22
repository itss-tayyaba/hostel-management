-- Optional dummy enrichment for recommendation demo (safe to tweak / re-run values)
USE hostel_management;

-- Example semester + preferences for existing students (MySQL UPDATE order undefined — fine here)
UPDATE students s
JOIN users u ON u.id = s.user_id
SET
  s.semester = CASE
    WHEN s.id MOD 5 = 0 THEN 'Fall 2026 — 6th'
    WHEN s.id MOD 5 = 1 THEN 'Fall 2026 — 4th'
    ELSE 'Fall 2026 — 2nd'
  END,
  s.room_preference = CASE WHEN s.id MOD 3 = 0 THEN 'single' ELSE 'shared' END,
  s.fee_status = CASE WHEN s.id MOD 7 = 0 THEN 'pending' ELSE 'paid' END
WHERE s.semester IS NULL OR s.semester = '';

-- Mix room_types on seeded test rooms labeled [TEST] if present
UPDATE rooms r
JOIN hostels h ON h.id = r.hostel_id
SET r.room_type = CASE WHEN r.room_number LIKE '%3-%' THEN 'shared' ELSE 'single' END
WHERE h.name LIKE '[TEST]%';
