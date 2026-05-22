<?php
declare(strict_types=1);
/**
 * @file includes/models/student_model.php
 * @brief Student data layer — Profile, hostels, rooms, applications
 * @access all
 * @database students, room_allocation
 */

require_once __DIR__ . '/../app.php';
require_once __DIR__ . '/smart_allocation_model.php';

// --- Profile ----------------------------------------------------------------

/** Load student row joined with users for the logged-in user_id. */
function student_get_by_user_id(PDO $pdo, int $userId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT s.id, s.user_id, s.reg_no, s.department, s.gender, s.phone, s.avatar_path,
                u.name, u.email, u.gender AS user_gender
         FROM students s INNER JOIN users u ON u.id = s.user_id
         WHERE s.user_id = :uid LIMIT 1'
    );
    $stmt->execute([':uid' => $userId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** @return 'male'|'female' */
function student_resolve_gender(array $student): string
{
    $g = normalize_user_gender((string)($student['gender'] ?? ''));
    if ($g === null) {
        $g = normalize_user_gender((string)($student['user_gender'] ?? ''));
    }
    return $g ?? 'male';
}

// --- Gender / hostel eligibility (boys | girls | mixed blocks) --------------

/** @return list<string> Hostel type values allowed for this student gender */
function student_hostel_types_for_gender(string $studentGender): array
{
    return (normalize_user_gender($studentGender) ?? 'male') === 'female'
        ? ['girls', 'mixed']
        : ['boys', 'mixed'];
}

function student_get_hostels(PDO $pdo, ?string $studentGender = null): array
{
    if ($studentGender === null || $studentGender === '') {
        $stmt = $pdo->query('SELECT id, name, type FROM hostels ORDER BY name ASC');
        return $stmt->fetchAll();
    }

    $types = student_hostel_types_for_gender($studentGender);
    $placeholders = implode(',', array_fill(0, count($types), '?'));
    $stmt = $pdo->prepare(
        "SELECT id, name, type FROM hostels WHERE type IN ($placeholders) ORDER BY name ASC"
    );
    $stmt->execute($types);
    return $stmt->fetchAll();
}

function student_get_available_rooms(PDO $pdo, ?int $hostelId, ?int $capacity, ?string $studentGender = null): array
{
    $vacancySql = smart_allocation_tables_ready($pdo)
        ? '(r.capacity > r.occupied)'
        : '(r.occupied = 0)';

    $sql = "
        SELECT r.id, r.room_number, r.capacity, h.name AS hostel_name, h.type AS hostel_type
        FROM rooms r
        JOIN hostels h ON r.hostel_id = h.id
        WHERE $vacancySql
    ";

    $params = [];
    if ($studentGender !== null && $studentGender !== '') {
        $types = student_hostel_types_for_gender($studentGender);
        $typeKeys = [];
        foreach ($types as $i => $type) {
            $key = ':ht' . $i;
            $typeKeys[] = $key;
            $params[$key] = $type;
        }
        $sql .= ' AND h.type IN (' . implode(',', $typeKeys) . ')';
    }
    if ($hostelId !== null && $hostelId > 0) {
        $sql .= ' AND r.hostel_id = :hid';
        $params[':hid'] = $hostelId;
    }
    if ($capacity !== null && $capacity > 0) {
        $sql .= ' AND r.capacity = :cap';
        $params[':cap'] = $capacity;
    }
    $sql .= ' ORDER BY h.name ASC, r.room_number ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// --- Room availability (legacy occupied flag OR smart bed counts) ------------

function student_room_allowed_for_gender(PDO $pdo, int $roomId, int $hostelId, int $capacity, string $studentGender): bool
{
    $vacClause = smart_allocation_tables_ready($pdo) ? '(r.capacity > r.occupied)' : '(r.occupied = 0)';
    $stmt = $pdo->prepare(
        "
        SELECT h.type AS hostel_type
        FROM rooms r
        INNER JOIN hostels h ON h.id = r.hostel_id
        WHERE r.id = :rid AND r.hostel_id = :hid AND r.capacity = :cap AND $vacClause
        LIMIT 1
    "
    );
    $stmt->execute([':rid' => $roomId, ':hid' => $hostelId, ':cap' => $capacity]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return false;
    }
    return smart_allocation_hostel_matches_student_gender((string)$row['hostel_type'], $studentGender);
}

// --- room_allocation application workflow (student apply → admin approve) ----

function student_has_pending_allocation(PDO $pdo, int $studentId): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM room_allocation WHERE student_id = :sid AND status = \'pending\' LIMIT 1');
    $stmt->execute([':sid' => $studentId]);
    return (bool)$stmt->fetchColumn();
}

function student_create_pending_allocation(PDO $pdo, int $studentId, int $roomId): bool
{
    $vacancy = smart_allocation_tables_ready($pdo)
        ? '(capacity > occupied)'
        : '(occupied = 0)';

    $stmtRoom = $pdo->prepare("SELECT id FROM rooms WHERE id = :rid AND $vacancy LIMIT 1");
    $stmtRoom->execute([':rid' => $roomId]);
    if (!$stmtRoom->fetch()) {
        return false;
    }

    $stmt = $pdo->prepare('
        INSERT INTO room_allocation (student_id, room_id, status, notes)
        VALUES (:sid, :rid, \'pending\', NULL)
    ');
    return $stmt->execute([':sid' => $studentId, ':rid' => $roomId]);
}

function student_get_latest_approved_allocation(PDO $pdo, int $studentId): ?array
{
    if (smart_allocation_tables_ready($pdo)) {
        // Authoritative placements after smart_allocation_schema.sql replaces boolean occupancy semantics.
        $stmtPrimary = $pdo->prepare(
            '
            SELECT a.id AS allocation_id,
                   r.id AS room_id,
                   h.name AS hostel_name,
                   h.id AS hostel_id,
                   h.type AS hostel_type,
                   r.room_number,
                   \'approved\' AS status,
                   a.allocated_at AS applied_at,
                   a.allocated_at AS responded_at,
                   bd.bed_code AS bed_code
            FROM allocations a
            INNER JOIN beds bd ON bd.id = a.bed_id
            INNER JOIN rooms r ON r.id = a.room_id
            INNER JOIN hostels h ON r.hostel_id = h.id
            WHERE a.student_id = :sid
            ORDER BY a.allocated_at DESC
            LIMIT 1
        ');
        $stmtPrimary->execute([':sid' => $studentId]);
        $prime = $stmtPrimary->fetch();
        if ($prime) {
            return $prime;
        }
    }

    $stmt = $pdo->prepare('
        SELECT ra.id AS allocation_id,
               r.id AS room_id,
               h.name AS hostel_name,
               h.id AS hostel_id,
               h.type AS hostel_type,
               r.room_number,
               ra.status,
               ra.applied_at,
               ra.responded_at
        FROM room_allocation ra
        JOIN rooms r ON ra.room_id = r.id
        JOIN hostels h ON r.hostel_id = h.id
        WHERE ra.student_id = :sid AND ra.status = \'approved\'
        ORDER BY COALESCE(ra.responded_at, ra.applied_at) DESC
        LIMIT 1
    ');
    $stmt->execute([':sid' => $studentId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function student_get_application_statuses(PDO $pdo, int $studentId, int $limit = 10): array
{
    $stmt = $pdo->prepare('
        SELECT ra.id AS allocation_id,
               ra.status,
               ra.applied_at,
               ra.responded_at,
               h.name AS hostel_name,
               r.room_number
        FROM room_allocation ra
        JOIN rooms r ON ra.room_id = r.id
        JOIN hostels h ON r.hostel_id = h.id
        WHERE ra.student_id = :sid
        ORDER BY ra.applied_at DESC
        LIMIT :lim
    ');
    $stmt->bindValue(':sid', $studentId, PDO::PARAM_INT);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function student_get_rooms_for_hostel(PDO $pdo, int $hostelId): array
{
    $stmt = $pdo->prepare('SELECT id, room_number, capacity, occupied FROM rooms WHERE hostel_id = :hid ORDER BY room_number ASC');
    $stmt->execute([':hid' => $hostelId]);
    return $stmt->fetchAll();
}
