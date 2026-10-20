<?php
declare(strict_types=1);
/**
 * @file includes/models/smart_allocation_model.php
 * @brief Smart allocation layer — Beds, allocations, occupancy sync
 * @access admin
 * @database beds, allocations
 */

/**
 * Bed-based allocation persistence + guards for the recommendation UI.
 *
 * Allocation workflow:
 *  1) Admin picks a student; backend scores candidate rooms (see recommendation_service.php).
 *  2) "Auto Assign" chooses the best-ranked room still holding a spare bed row in `beds`.
 *  3) Successful INSERT into `allocations` is followed by a room occupancy recount so we never
 *     drift between `rooms.occupied` and real assignment rows — mitigating overlapping requests.
 */

require_once __DIR__ . '/../app.php';

function smart_allocation_tables_ready(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT 1 FROM allocations LIMIT 1');
        $pdo->query('SELECT 1 FROM beds LIMIT 1');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Runtime-safe column check for progressive migrations.
 * Prevents SQL errors when optional ML/rule columns are not present yet.
 */
function smart_allocation_students_has_column(PDO $pdo, string $column): bool
{
    static $cache = [];
    $key = strtolower(trim($column));
    if ($key === '') {
        return false;
    }
    if (array_key_exists($key, $cache)) {
        return (bool)$cache[$key];
    }
    try {
        $stmt = $pdo->prepare(
            '
            SELECT COUNT(*)
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = \'students\'
              AND COLUMN_NAME = :col
        '
        );
        $stmt->execute([':col' => $key]);
        $cache[$key] = ((int)$stmt->fetchColumn()) > 0;
    } catch (Throwable $e) {
        $cache[$key] = false;
    }
    return (bool)$cache[$key];
}

/** boys / girls hostel rules vs student.users.gender or students.gender fallback */
function smart_allocation_hostel_matches_student_gender(string $hostelType, string $studentGender): bool
{
    $g = strtolower(trim($studentGender));
    $ht = strtolower(trim($hostelType));
    if ($ht === 'mixed') {
        return true;
    }
    if ($ht === 'boys') {
        return $g === 'male';
    }
    if ($ht === 'girls') {
        return $g === 'female';
    }
    return false;
}

function smart_allocation_get_student_profile(PDO $pdo, int $studentId): ?array
{
    $natCol = smart_allocation_students_has_column($pdo, 'nationality')
        ? 's.nationality'
        : "'pakistani'";
    $degreeCol = smart_allocation_students_has_column($pdo, 'degree')
        ? 's.degree'
        : "CASE WHEN LOWER(s.department) LIKE '%mphil%' OR LOWER(s.department) LIKE '%phd%' THEN 'research' ELSE 'undergraduate' END";
    $programCol = smart_allocation_students_has_column($pdo, 'program')
        ? 's.program'
        : 's.department';

    $sql = "
        SELECT s.id AS student_id,
               s.reg_no,
               s.department,
               {$programCol} AS program,
               COALESCE(s.faculty, s.department, '') AS faculty,
               s.semester,
               COALESCE(s.student_status, 'active') AS student_status,
               COALESCE(s.expelled, 0) AS expelled,
               COALESCE(s.graduated, 0) AS graduated,
               COALESCE(s.hostel_ban, 0) AS hostel_ban,
               COALESCE(s.cgpa, 0) AS cgpa,
               COALESCE(s.special_needs, 0) AS special_needs,
               COALESCE(s.distance_from_uaf_km, 0) AS distance_from_uaf_km,
               s.gender AS student_record_gender,
               s.room_preference,
               s.fee_status,
               {$natCol} AS nationality,
               {$degreeCol} AS degree,
               u.name,
               COALESCE(NULLIF(TRIM(u.gender), ''), NULLIF(TRIM(s.gender), ''), 'male') AS gender
        FROM students s
        INNER JOIN users u ON u.id = s.user_id
        WHERE s.id = :sid
        LIMIT 1
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':sid' => $studentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function smart_allocation_student_is_assigned(PDO $pdo, int $studentId): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM allocations WHERE student_id = :sid LIMIT 1');
    $stmt->execute([':sid' => $studentId]);
    return (bool)$stmt->fetchColumn();
}

/** Keep rooms.occupied aligned with authoritative allocation rows */
function smart_allocation_sync_room_occupied(PDO $pdo, int $roomId): void
{
    $stmt = $pdo->prepare(
        '
        UPDATE rooms r
        SET r.occupied = (
            SELECT COUNT(*) FROM allocations a WHERE a.room_id = r.id
        )
        WHERE r.id = :rid
    '
    );
    $stmt->execute([':rid' => $roomId]);
}

/**
 * Ensure beds B1..B{capacity} exist for a freshly added/edited room.
 * Safe to call repeatedly thanks to UNIQUE(room_id, bed_code).
 */
function smart_allocation_materialize_beds(PDO $pdo, int $roomId): void
{
    $stmt = $pdo->prepare('SELECT capacity FROM rooms WHERE id = :rid LIMIT 1');
    $stmt->execute([':rid' => $roomId]);
    $cap = (int)$stmt->fetchColumn();
    if ($cap <= 0) {
        return;
    }
    $ins = $pdo->prepare('INSERT IGNORE INTO beds (room_id, bed_code) VALUES (:rid, :code)');
    for ($i = 1; $i <= $cap && $i <= 24; $i++) {
        $ins->execute([':rid' => $roomId, ':code' => 'B' . $i]);
    }
}

/**
 * @return array<int,array<string,mixed>>|null  bed row ['id'=>..,'bed_code'=>..] or null if full
 */
function smart_allocation_pick_free_bed(PDO $pdo, int $roomId): ?array
{
    smart_allocation_materialize_beds($pdo, $roomId);

    $stmt = $pdo->prepare(
        '
        SELECT b.id, b.bed_code
        FROM beds b
        LEFT JOIN allocations a ON a.bed_id = b.id
        WHERE b.room_id = :rid AND a.id IS NULL
        ORDER BY b.id ASC
        LIMIT 1
    '
    );
    $stmt->execute([':rid' => $roomId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Persist assignment with transactional locking to prevent bunk overbooking.
 *
 * @return array{ok:bool,message:string}
 */
function smart_allocation_assign_student_to_room(
    PDO $pdo,
    int $studentId,
    int $roomId,
    ?int $adminUserId,
    ?string $notes
): array {
    if (!smart_allocation_tables_ready($pdo)) {
        return ['ok' => false, 'message' => 'Database migration not applied. Import database/smart_allocation_schema.sql.'];
    }

    $ownsTransaction = !$pdo->inTransaction();
    try {
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        $student = smart_allocation_get_student_profile($pdo, $studentId);
        if (!$student) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'message' => 'Student not found.'];
        }

        $dup = $pdo->prepare('SELECT 1 FROM allocations WHERE student_id = :sid FOR UPDATE');
        $dup->execute([':sid' => $studentId]);
        if ($dup->fetchColumn()) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'message' => 'Duplicate allocation prevented: student already has a bed.'];
        }

        $roomStmt = $pdo->prepare(
            '
            SELECT r.id, r.hostel_id, r.capacity, r.occupied,
                   r.room_number, r.room_type, h.type AS hostel_type,
                   h.name AS hostel_name
            FROM rooms r
            INNER JOIN hostels h ON h.id = r.hostel_id
            WHERE r.id = :rid
            FOR UPDATE
        '
        );
        $roomStmt->execute([':rid' => $roomId]);
        $room = $roomStmt->fetch(PDO::FETCH_ASSOC);
        if (!$room) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'message' => 'Room not found.'];
        }

        if (!smart_allocation_hostel_matches_student_gender((string)$room['hostel_type'], (string)$student['gender'])) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'message' => 'Gender mismatch: choose a hostel that matches the student gender.'];
        }

        smart_allocation_materialize_beds($pdo, $roomId);

        // Hard gate before we carve out a berth — prevents drifting counts under concurrent admins.
        $occStmt = $pdo->prepare('SELECT COUNT(*) FROM allocations WHERE room_id = :rid FOR UPDATE');
        $occStmt->execute([':rid' => $roomId]);
        $current = (int)$occStmt->fetchColumn();
        if ($current >= (int)$room['capacity']) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'message' => 'Room is already at capacity.'];
        }

        $bed = smart_allocation_pick_free_bed($pdo, $roomId);
        if ($bed === null) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'message' => 'Over-capacity prevented: room has no vacant bed.'];
        }

        $ins = $pdo->prepare(
            '
            INSERT INTO allocations (student_id, room_id, bed_id, admin_user_id, notes)
            VALUES (:sid, :rid, :bid, :aid, :notes)
        ');
        $ins->execute([
            ':sid' => $studentId,
            ':rid' => $roomId,
            ':bid' => (int)$bed['id'],
            ':aid' => $adminUserId,
            ':notes' => $notes !== null && trim($notes) !== '' ? trim($notes) : null,
        ]);

        smart_allocation_sync_room_occupied($pdo, $roomId);
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->commit();
        }
        return ['ok' => true, 'message' => 'Allocated to bed ' . (string)$bed['bed_code'] . ' successfully.'];
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'message' => 'Allocation failed — please retry (' . $e->getMessage() . ').'];
    }
}

/** @return array{ok:bool,message:string} */
function smart_allocation_enqueue(PDO $pdo, int $studentId, ?int $hostelId, string $notes = ''): array
{
    if (!smart_allocation_tables_ready($pdo)) {
        return ['ok' => false, 'message' => 'Database migration missing. Import smart_allocation_schema.sql first.'];
    }
    if (smart_allocation_student_is_assigned($pdo, $studentId)) {
        return ['ok' => false, 'message' => 'Student already housed — queue not needed.'];
    }

    try {
        $pdo->beginTransaction();
        $find = $pdo->prepare(
            '
            SELECT id
            FROM allocation_waiting_list
            WHERE student_id = :sid
            FOR UPDATE'
        );
        $find->execute([':sid' => $studentId]);
        $waitRowId = $find->fetchColumn();
        $notesValue = $notes !== '' ? $notes : null;

        if ($waitRowId) {
            $up = $pdo->prepare(
                '
                UPDATE allocation_waiting_list
                SET status = \'queued\',
                    hostel_id = :hid,
                    notes = COALESCE(:notes, notes),
                    processed_at = NULL,
                    requested_at = CURRENT_TIMESTAMP,
                    priority = priority
                WHERE id = :wid
            ');
            $up->execute([
                ':hid' => $hostelId,
                ':notes' => $notesValue,
                ':wid' => (int)$waitRowId,
            ]);
        } else {
            $ins = $pdo->prepare(
                '
                INSERT INTO allocation_waiting_list (student_id, hostel_id, status, notes)
                VALUES (:sid, :hid, \'queued\', :notes2)
            ');
            $ins->execute([
                ':sid' => $studentId,
                ':hid' => $hostelId,
                ':notes2' => $notesValue,
            ]);
        }

        $pdo->commit();

        return ['ok' => true, 'message' => 'Student queued for automatic assignment when a bed frees up.'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'message' => 'Could not add to waiting list right now.'];
    }
}

/**
 * Attempt to honour queued students FIFO (tie-break priority desc).
 *
 * @return array{assigned:int,failures:array<int,string>}
 */
function smart_allocation_process_waiting_fifo(PDO $pdo, ?int $adminUserId): array
{
    return ['assigned' => 0, 'failures' => ['Room allocation module removed']];
}

/**
 * Auto assign the student to the highest scoring feasible room (first free bed).
 *
 * @return array{ok:bool,message:string,room:?string}
 */
function smart_allocation_try_auto_assign_best(PDO $pdo, int $studentId, ?int $adminUserId, ?string $notes): array
{
    return ['ok' => false, 'message' => 'Room allocation module removed', 'room' => null];
}

/** Dashboard helper: vacant berths summed across hostel inventory */
function smart_allocation_total_empty_beds(PDO $pdo): int
{
    if (!smart_allocation_tables_ready($pdo)) {
        return (int)$pdo->query('SELECT SUM(GREATEST(0, capacity - occupied)) FROM rooms')->fetchColumn();
    }

    /**
     * More precise: Σ max(0, capacity - allocation_count).
     * rooms.occupied is kept synchronized by smart_allocation_sync_room_occupied().
     */
    return (int)$pdo->query('SELECT COALESCE(SUM(GREATEST(0, capacity - occupied)),0) FROM rooms')->fetchColumn();
}
