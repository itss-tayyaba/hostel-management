-- Seed uaf_hostel_rooms with baseline UAF inventory.
-- Safe for repeat runs: if table already has rows, this script skips inserts.

START TRANSACTION;

SET @existing_rooms := (SELECT COUNT(*) FROM uaf_hostel_rooms);

SET @seed_sql := IF(
  @existing_rooms = 0,
  '
  INSERT IGNORE INTO uaf_hostel_rooms
    (hostel_name, faculty_affinity, room_type, gender_restriction, capacity, current_occupancy)
  VALUES
    (''UAF Boys Hostel 1'', ''Computer Science'', ''General'', ''Male'', 3, 0),
    (''UAF Boys Hostel 1'', ''Agronomy'', ''General'', ''Male'', 3, 0),
    (''UAF Boys Hostel 1'', ''Animal Sciences'', ''General'', ''Male'', 3, 0),
    (''UAF Boys Hostel 2'', ''Veterinary Sciences'', ''General'', ''Male'', 3, 0),
    (''UAF Boys Hostel 2'', ''Engineering Technology'', ''General'', ''Male'', 3, 0),
    (''UAF Boys Hostel 2'', ''Food Science'', ''General'', ''Male'', 3, 0),
    (''UAF Boys Hostel 3'', ''Basic Sciences'', ''General'', ''Male'', 3, 0),
    (''UAF Boys Hostel 3'', ''Computer Science'', ''General'', ''Male'', 3, 0),
    (''UAF Boys Hostel 3'', NULL, ''PhD'', ''Male'', 3, 0),
    (''UAF Boys Hostel 3'', NULL, ''International'', ''Male'', 3, 0),

    (''UAF Girls Hostel 1'', ''Computer Science'', ''General'', ''Female'', 3, 0),
    (''UAF Girls Hostel 1'', ''Home Economics'', ''General'', ''Female'', 3, 0),
    (''UAF Girls Hostel 1'', ''Agronomy'', ''General'', ''Female'', 3, 0),
    (''UAF Girls Hostel 2'', ''Animal Sciences'', ''General'', ''Female'', 3, 0),
    (''UAF Girls Hostel 2'', ''Veterinary Sciences'', ''General'', ''Female'', 3, 0),
    (''UAF Girls Hostel 2'', ''Engineering Technology'', ''General'', ''Female'', 3, 0),
    (''UAF Girls Hostel 3'', ''Food Science'', ''General'', ''Female'', 3, 0),
    (''UAF Girls Hostel 3'', ''Basic Sciences'', ''General'', ''Female'', 3, 0),
    (''UAF Girls Hostel 3'', NULL, ''PhD'', ''Female'', 3, 0),
    (''UAF Girls Hostel 3'', NULL, ''International'', ''Female'', 3, 0),

    (''UAF Boys Hostel 4'', ''Home Economics'', ''General'', ''Male'', 3, 0),
    (''UAF Girls Hostel 4'', ''Computer Science'', ''General'', ''Female'', 3, 0),
    (''UAF International Block M'', NULL, ''International'', ''Male'', 3, 0),
    (''UAF International Block F'', NULL, ''International'', ''Female'', 3, 0)
  ',
  'SELECT ''Seed skipped: uaf_hostel_rooms already has data.'' AS info'
);

PREPARE seed_stmt FROM @seed_sql;
EXECUTE seed_stmt;
DEALLOCATE PREPARE seed_stmt;

COMMIT;
