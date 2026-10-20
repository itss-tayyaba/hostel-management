<?php
declare(strict_types=1);

/**
 * UAF deterministic allocation utilities.
 *
 * Scope:
 * - Provision allocation schema with transactional DDL execution.
 * - Compute deterministic student priority score.
 * - Run deterministic, chunked allocation cycles safely.
 */
final class UafAllocationEngine
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        if ($db instanceof PDO) {
            $this->db = $db;
            return;
        }

        if (isset($GLOBALS['db']) && $GLOBALS['db'] instanceof PDO) {
            $this->db = $GLOBALS['db'];
            return;
        }

        if (function_exists('db')) {
            /** @var mixed $resolved */
            $resolved = db();
            if ($resolved instanceof PDO) {
                $this->db = $resolved;
                return;
            }
        }

        throw new RuntimeException('No active PDO connection available for UafAllocationEngine.');
    }

    /**
     * Provision schema (idempotent) using a DB transaction.
     *
     * @throws Throwable
     */
    public function provisionSchema(PDO $pdo): void
    {
        $sqlStatements = [
            <<<SQL
            CREATE TABLE IF NOT EXISTS uaf_hostel_rooms (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                hostel_name VARCHAR(120) NOT NULL,
                faculty_affinity VARCHAR(120) NOT NULL,
                room_type ENUM('General', 'PhD', 'International') NOT NULL DEFAULT 'General',
                gender_restriction ENUM('Male', 'Female') NOT NULL,
                capacity TINYINT UNSIGNED NOT NULL,
                current_occupancy TINYINT UNSIGNED NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT chk_uaf_rooms_capacity_positive CHECK (capacity > 0),
                CONSTRAINT chk_uaf_rooms_occupancy_nonneg CHECK (current_occupancy >= 0),
                CONSTRAINT chk_uaf_rooms_occupancy_within_capacity CHECK (current_occupancy <= capacity),
                INDEX idx_uaf_rooms_lookup (faculty_affinity, gender_restriction, room_type),
                INDEX idx_uaf_rooms_capacity_window (gender_restriction, current_occupancy, capacity),
                INDEX idx_uaf_rooms_hostel_faculty (hostel_name, faculty_affinity)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,
            <<<SQL
            CREATE TABLE IF NOT EXISTS student_applications (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                student_name VARCHAR(160) NOT NULL,
                gender ENUM('Male', 'Female') NOT NULL,
                faculty VARCHAR(120) NOT NULL,
                degree_level ENUM('Undergraduate', 'MS/MPhil', 'PhD') NOT NULL,
                year_of_study TINYINT UNSIGNED NOT NULL,
                cgpa DECIMAL(3,2) NOT NULL,
                is_international TINYINT(1) NOT NULL DEFAULT 0,
                has_unpaid_dues TINYINT(1) NOT NULL DEFAULT 0,
                past_violations_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                allocation_status ENUM('pending', 'allocated', 'waitlisted') NOT NULL DEFAULT 'pending',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT chk_student_apps_year_range CHECK (year_of_study BETWEEN 1 AND 7),
                CONSTRAINT chk_student_apps_cgpa_range CHECK (cgpa >= 0.00 AND cgpa <= 4.00),
                INDEX idx_student_apps_pending_scan (allocation_status, faculty, gender, degree_level, year_of_study),
                INDEX idx_student_apps_priority_inputs (degree_level, year_of_study, cgpa, has_unpaid_dues, past_violations_count),
                INDEX idx_student_apps_faculty_gender (faculty, gender)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,
            <<<SQL
            CREATE TABLE IF NOT EXISTS uaf_allotments (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                student_application_id BIGINT UNSIGNED NOT NULL,
                room_id BIGINT UNSIGNED NOT NULL,
                priority_score DECIMAL(10,2) NOT NULL,
                allocated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                notes VARCHAR(255) NULL,
                CONSTRAINT fk_uaf_allotments_student
                    FOREIGN KEY (student_application_id) REFERENCES student_applications(id)
                    ON DELETE CASCADE,
                CONSTRAINT fk_uaf_allotments_room
                    FOREIGN KEY (room_id) REFERENCES uaf_hostel_rooms(id)
                    ON DELETE RESTRICT,
                UNIQUE KEY uq_uaf_allotments_student_once (student_application_id),
                INDEX idx_uaf_allotments_room_time (room_id, allocated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,
        ];

        try {
            $pdo->beginTransaction();
            foreach ($sqlStatements as $statement) {
                $pdo->exec($statement);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Calculate UAF hostel priority score.
     *
     * Enhanced priority score formula:
     * - Degree base:
     *   PhD +500, MS/MPhil +300, UG year4 +200, year3 +150, year2 +100, year1 +50
     * - Academic: + (cgpa * 10), capped at CGPA 4.0
     * - Special priority: disability +200, international +150, distance_from_home > 100 => +50
     * - Penalty: each past violation => -25
     * - Floor: -1000
     *
     * @param array{
     *   degree_level?:string,
     *   year_of_study?:int|string,
     *   cgpa?:float|int|string,
     *   has_unpaid_dues?:bool|int|string,
     *   past_violations_count?:int|string
     * } $student
     */
    public function calculatePriorityScore(array $student): float
    {
        $degreeLevel = trim((string)($student['degree_level'] ?? 'Undergraduate'));
        $cgpa = $this->boundedFloat($student['cgpa'] ?? 0.0, 0.0, 4.0);
        $violations = max(0, (int)($student['past_violations_count'] ?? 0));
        $hasDisability = $this->toBool($student['has_disability'] ?? false);
        $isInternational = $this->toBool($student['is_international'] ?? false);
        $distanceFromHome = max(0, (int)($student['distance_from_home'] ?? 0));

        $score = 0.0;
        if (strcasecmp($degreeLevel, 'PhD') === 0) {
            $score += 500.0;
        } elseif (strcasecmp($degreeLevel, 'MS/MPhil') === 0) {
            $score += 300.0;
        } else {
            $yearOfStudy = $this->boundedInt($student['year_of_study'] ?? 1, 1, 4);
            $score += match ($yearOfStudy) {
                4 => 200.0,
                3 => 150.0,
                2 => 100.0,
                default => 50.0,
            };
        }

        $score += ($cgpa * 10.0);
        if ($hasDisability) {
            $score += 200.0;
        }
        if ($isInternational) {
            $score += 150.0;
        }
        if ($distanceFromHome > 100) {
            $score += 50.0;
        }
        $score -= ((float)$violations * 25.0);
        return round(max($score, -1000.0), 2);
    }

    /**
     * A student is ineligible if dues are unpaid or UAF enrollment is not verified.
     *
     * @param array<string,mixed> $student
     */
    private function isEligible(array $student): bool
    {
        if ($this->toBool($student['has_unpaid_dues'] ?? false)) {
            return false;
        }
        return $this->toBool($student['is_uaf_student'] ?? true);
    }

    /**
     * Run pending allocation in memory-safe chunks until queue is exhausted.
     *
     * @return array{processed:int,allocated:int,waitlisted:int,failed:int}
     */
    public function runAllocationCycle(int $chunkSize = 500): array
    {
        $size = max(1, min($chunkSize, 2000));
        $stats = ['processed' => 0, 'allocated' => 0, 'waitlisted' => 0, 'failed' => 0];

        while (true) {
            $students = $this->fetchPendingStudentsChunk($this->db, $size);
            if ($students === []) {
                break;
            }
            $batchStats = $this->allocateBatch($students);
            $stats['processed'] += $batchStats['processed'];
            $stats['allocated'] += $batchStats['allocated'];
            $stats['waitlisted'] += $batchStats['waitlisted'];
            $stats['failed'] += $batchStats['failed'];
        }

        return $stats;
    }

    /**
     * Allocate one chunk in deterministic priority order.
     *
     * @param list<array<string,mixed>> $students
     * @return array{processed:int,allocated:int,waitlisted:int,failed:int}
     */
    public function allocateBatch(array $students): array
    {
        $stats = ['processed' => 0, 'allocated' => 0, 'waitlisted' => 0, 'failed' => 0];
        if ($students === []) {
            return $stats;
        }

        foreach ($students as &$student) {
            $student['__priority_score'] = $this->calculatePriorityScore($student);
        }
        unset($student);

        usort(
            $students,
            static fn(array $a, array $b): int => (float)$b['__priority_score'] <=> (float)$a['__priority_score']
        );

        foreach ($students as $student) {
            $stats['processed']++;
            $studentId = (int)($student['id'] ?? 0);
            if ($studentId <= 0) {
                $stats['failed']++;
                continue;
            }

            try {
                $this->db->beginTransaction();

                // Lock the student row first so concurrent workers cannot process it twice.
                $lockedStudent = $this->lockPendingStudent($this->db, $studentId);
                if ($lockedStudent === null) {
                    // Already processed by another worker or missing row.
                    $this->db->commit();
                    continue;
                }

                if (!$this->isEligible($lockedStudent)) {
                    $this->removeWaitlistEntry($this->db, (int)$lockedStudent['id']);
                    $this->updateStudentStatus($this->db, (int)$lockedStudent['id'], 'ineligible');
                    $this->db->commit();
                    continue;
                }

                $priorityScore = $this->calculatePriorityScore($lockedStudent);
                $room = $this->resolveRoomTypeWithFallback($this->db, $lockedStudent);

                if ($room !== null) {
                    $this->insertAllotment($this->db, (int)$lockedStudent['id'], (int)$room['id'], $priorityScore);
                    $this->incrementRoomOccupancy($this->db, (int)$room['id']);
                    $this->removeWaitlistEntry($this->db, (int)$lockedStudent['id']);
                    $this->updateStudentStatus($this->db, (int)$lockedStudent['id'], 'allocated');
                    $stats['allocated']++;
                } else {
                    $this->upsertWaitlistEntry($this->db, (int)$lockedStudent['id'], $priorityScore, 'no_room');
                    $this->updateStudentStatus($this->db, (int)$lockedStudent['id'], 'waitlisted');
                    $stats['waitlisted']++;
                }

                $this->db->commit();
            } catch (Throwable $e) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                $stats['failed']++;
            }
        }

        return $stats;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function fetchPendingStudentsChunk(PDO $pdo, int $chunkSize): array
    {
        $selectColumns = [
            'id',
            'student_name',
            'gender',
            'faculty',
            'degree_level',
            'year_of_study',
            'cgpa',
            'is_international',
            'has_unpaid_dues',
            'past_violations_count',
            'allocation_status',
        ];
        $selectColumns[] = $this->tableHasColumn($pdo, 'student_applications', 'is_uaf_student')
            ? 'is_uaf_student'
            : '1 AS is_uaf_student';
        $selectColumns[] = $this->tableHasColumn($pdo, 'student_applications', 'distance_from_home')
            ? 'distance_from_home'
            : '0 AS distance_from_home';
        $selectColumns[] = $this->tableHasColumn($pdo, 'student_applications', 'has_disability')
            ? 'has_disability'
            : '0 AS has_disability';

        $stmt = $pdo->prepare(
            'SELECT ' . implode(', ', $selectColumns) . '
            FROM student_applications
            WHERE allocation_status IN (\'pending\', \'waitlisted\')
            ORDER BY id ASC
            LIMIT :limit_rows
        '
        );
        $stmt->bindValue(':limit_rows', $chunkSize, PDO::PARAM_INT);
        $stmt->execute();
        /** @var list<array<string,mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return $rows;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function lockPendingStudent(PDO $pdo, int $studentId): ?array
    {
        $selectColumns = [
            'id',
            'student_name',
            'gender',
            'faculty',
            'degree_level',
            'year_of_study',
            'cgpa',
            'is_international',
            'has_unpaid_dues',
            'past_violations_count',
            'allocation_status',
        ];
        $selectColumns[] = $this->tableHasColumn($pdo, 'student_applications', 'is_uaf_student')
            ? 'is_uaf_student'
            : '1 AS is_uaf_student';
        $selectColumns[] = $this->tableHasColumn($pdo, 'student_applications', 'distance_from_home')
            ? 'distance_from_home'
            : '0 AS distance_from_home';
        $selectColumns[] = $this->tableHasColumn($pdo, 'student_applications', 'has_disability')
            ? 'has_disability'
            : '0 AS has_disability';

        $stmt = $pdo->prepare(
            'SELECT ' . implode(', ', $selectColumns) . '
            FROM student_applications
            WHERE id = :id AND allocation_status IN (\'pending\', \'waitlisted\')
            FOR UPDATE
        '
        );
        $stmt->execute([':id' => $studentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * Waterfall room type chain resolver that returns first lockable eligible room.
     *
     * @param array<string,mixed> $student
     * @return array<string,mixed>|null
     */
    private function resolveRoomTypeWithFallback(PDO $pdo, array $student): ?array
    {
        foreach ($this->roomTypeChain($student) as $roomType) {
            $room = $this->findFirstEligibleRoomForUpdate($pdo, $student, $roomType);
            if ($room !== null) {
                return $room;
            }
        }
        return null;
    }

    /**
     * @param array<string,mixed> $student
     * @return list<string>
     */
    private function roomTypeChain(array $student): array
    {
        if ($this->toBool($student['is_international'] ?? false)) {
            return ['International', 'General'];
        }
        if (strcasecmp((string)($student['degree_level'] ?? ''), 'PhD') === 0) {
            return ['PhD', 'General'];
        }
        return ['General'];
    }

    /**
     * @param array<string,mixed> $student
     * @return array<string,mixed>|null
     */
    private function findFirstEligibleRoomForUpdate(PDO $pdo, array $student, string $roomType): ?array
    {
        // Attempt 1: strict match (faculty + gender + room type), but NULL faculty acts as open-to-all.
        $stmtStrict = $pdo->prepare(
            '
            SELECT id, capacity, current_occupancy
            FROM uaf_hostel_rooms
            WHERE (faculty_affinity = :faculty OR faculty_affinity IS NULL)
              AND gender_restriction = :gender
              AND room_type = :room_type
              AND current_occupancy < capacity
            ORDER BY id ASC
            LIMIT 1
            FOR UPDATE
        '
        );
        $stmtStrict->execute([
            ':faculty' => (string)($student['faculty'] ?? ''),
            ':gender' => (string)($student['gender'] ?? ''),
            ':room_type' => $roomType,
        ]);
        $row = $stmtStrict->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            return $row;
        }

        // Attempt 2: relaxed (ignore faculty, keep gender + room type).
        $stmtRelaxed = $pdo->prepare(
            '
            SELECT id, capacity, current_occupancy
            FROM uaf_hostel_rooms
            WHERE gender_restriction = :gender
              AND room_type = :room_type
              AND current_occupancy < capacity
            ORDER BY id ASC
            LIMIT 1
            FOR UPDATE
        '
        );
        $stmtRelaxed->execute([
            ':gender' => (string)($student['gender'] ?? ''),
            ':room_type' => $roomType,
        ]);
        $row = $stmtRelaxed->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            return $row;
        }

        // Attempt 3: fallback (ignore faculty and room type, keep gender only).
        $stmtGenderOnly = $pdo->prepare(
            '
            SELECT id, capacity, current_occupancy
            FROM uaf_hostel_rooms
            WHERE gender_restriction = :gender
              AND current_occupancy < capacity
            ORDER BY id ASC
            LIMIT 1
            FOR UPDATE
        '
        );
        $stmtGenderOnly->execute([
            ':gender' => (string)($student['gender'] ?? ''),
        ]);
        $row = $stmtGenderOnly->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * Admin diagnostics for room inventory readiness.
     *
     * @return array{
     *   total_rooms:int,
     *   available_rooms:int,
     *   by_gender:array<string,int>,
     *   by_room_type:array<string,int>,
     *   null_faculty_count:int
     * }
     */
    public function debugRoomCount(): array
    {
        $out = [
            'total_rooms' => 0,
            'available_rooms' => 0,
            'by_gender' => ['Male' => 0, 'Female' => 0, 'Other' => 0],
            'by_room_type' => ['General' => 0, 'PhD' => 0, 'International' => 0, 'Other' => 0],
            'null_faculty_count' => 0,
        ];

        $out['total_rooms'] = (int)$this->db->query('SELECT COUNT(*) FROM uaf_hostel_rooms')->fetchColumn();
        $out['available_rooms'] = (int)$this->db->query(
            'SELECT COUNT(*) FROM uaf_hostel_rooms WHERE current_occupancy < capacity'
        )->fetchColumn();

        $genderRows = $this->db->query(
            'SELECT gender_restriction, COUNT(*) AS total
             FROM uaf_hostel_rooms
             WHERE current_occupancy < capacity
             GROUP BY gender_restriction'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($genderRows as $row) {
            $g = (string)($row['gender_restriction'] ?? '');
            $n = (int)($row['total'] ?? 0);
            if ($g === 'Male' || $g === 'Female') {
                $out['by_gender'][$g] = $n;
            } else {
                $out['by_gender']['Other'] += $n;
            }
        }

        $typeRows = $this->db->query(
            'SELECT room_type, COUNT(*) AS total
             FROM uaf_hostel_rooms
             GROUP BY room_type'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($typeRows as $row) {
            $t = (string)($row['room_type'] ?? '');
            $n = (int)($row['total'] ?? 0);
            if ($t === 'General' || $t === 'PhD' || $t === 'International') {
                $out['by_room_type'][$t] = $n;
            } else {
                $out['by_room_type']['Other'] += $n;
            }
        }

        $out['null_faculty_count'] = (int)$this->db->query(
            'SELECT COUNT(*) FROM uaf_hostel_rooms WHERE faculty_affinity IS NULL'
        )->fetchColumn();

        return $out;
    }

    private function insertAllotment(PDO $pdo, int $studentApplicationId, int $roomId, float $priorityScore): void
    {
        $stmt = $pdo->prepare(
            '
            INSERT INTO uaf_allotments (student_application_id, room_id, priority_score, notes)
            VALUES (:student_application_id, :room_id, :priority_score, :notes)
            ON DUPLICATE KEY UPDATE
                room_id = VALUES(room_id),
                priority_score = VALUES(priority_score),
                allocated_at = CURRENT_TIMESTAMP,
                notes = VALUES(notes)
        '
        );
        $stmt->execute([
            ':student_application_id' => $studentApplicationId,
            ':room_id' => $roomId,
            ':priority_score' => round($priorityScore, 2),
            ':notes' => 'Auto allocation cycle',
        ]);
    }

    private function incrementRoomOccupancy(PDO $pdo, int $roomId): void
    {
        $stmt = $pdo->prepare(
            '
            UPDATE uaf_hostel_rooms
            SET current_occupancy = current_occupancy + 1
            WHERE id = :id
              AND current_occupancy < capacity
        '
        );
        $stmt->execute([':id' => $roomId]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Room occupancy update failed due to capacity race.');
        }
    }

    private function updateStudentStatus(PDO $pdo, int $studentId, string $status): void
    {
        $stmt = $pdo->prepare(
            '
            UPDATE student_applications
            SET allocation_status = :status, updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        '
        );
        $stmt->execute([':status' => $status, ':id' => $studentId]);
    }

    private function upsertWaitlistEntry(PDO $pdo, int $studentId, float $priorityScore, string $reason): void
    {
        $stmt = $pdo->prepare(
            '
            INSERT INTO uaf_waitlist (student_application_id, priority_score, reason)
            VALUES (:sid, :score, :reason)
            ON DUPLICATE KEY UPDATE
                priority_score = VALUES(priority_score),
                reason = VALUES(reason),
                updated_at = CURRENT_TIMESTAMP
        '
        );
        $stmt->execute([
            ':sid' => $studentId,
            ':score' => round($priorityScore, 2),
            ':reason' => $reason,
        ]);
        $this->recalculateWaitlistPositions($pdo);
    }

    private function removeWaitlistEntry(PDO $pdo, int $studentId): void
    {
        $stmt = $pdo->prepare('DELETE FROM uaf_waitlist WHERE student_application_id = :sid');
        $stmt->execute([':sid' => $studentId]);
        if ($stmt->rowCount() > 0) {
            $this->recalculateWaitlistPositions($pdo);
        }
    }

    /**
     * Promote highest-priority compatible waitlisted students into a freed room.
     *
     * @return list<int> promoted student application IDs
     */
    public function promoteFromWaitlist(int $roomId, int $freedBeds = 1): array
    {
        $beds = max(1, $freedBeds);
        $promoted = [];
        try {
            $this->db->beginTransaction();

            $roomStmt = $this->db->prepare(
                'SELECT id, faculty_affinity, gender_restriction, room_type
                 FROM uaf_hostel_rooms
                 WHERE id = :id
                 FOR UPDATE'
            );
            $roomStmt->execute([':id' => $roomId]);
            $room = $roomStmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($room)) {
                $this->db->rollBack();
                return [];
            }

            $candStmt = $this->db->prepare(
                'SELECT w.student_application_id
                 FROM uaf_waitlist w
                 INNER JOIN student_applications sa ON sa.id = w.student_application_id
                 WHERE sa.faculty = :faculty
                   AND sa.gender = :gender
                   AND sa.allocation_status = \'waitlisted\'
                 ORDER BY w.priority_score DESC, w.waitlisted_at ASC
                 LIMIT :lim'
            );
            $candStmt->bindValue(':faculty', (string)$room['faculty_affinity'], PDO::PARAM_STR);
            $candStmt->bindValue(':gender', (string)$room['gender_restriction'], PDO::PARAM_STR);
            $candStmt->bindValue(':lim', $beds, PDO::PARAM_INT);
            $candStmt->execute();
            $candidateIds = array_map(
                static fn(array $r): int => (int)$r['student_application_id'],
                $candStmt->fetchAll(PDO::FETCH_ASSOC) ?: []
            );

            foreach ($candidateIds as $studentId) {
                $student = $this->lockPendingStudent($this->db, $studentId);
                if ($student === null || !$this->isEligible($student)) {
                    continue;
                }
                $roomForStudent = $this->resolveRoomTypeWithFallback($this->db, $student);
                if ($roomForStudent === null || (int)$roomForStudent['id'] !== $roomId) {
                    continue;
                }
                $score = $this->calculatePriorityScore($student);
                $this->insertAllotment($this->db, $studentId, $roomId, $score);
                $this->incrementRoomOccupancy($this->db, $roomId);
                $this->updateStudentStatus($this->db, $studentId, 'allocated');
                $this->removeWaitlistEntry($this->db, $studentId);
                $promoted[] = $studentId;
            }

            $evtStmt = $this->db->prepare(
                'INSERT INTO uaf_room_events (room_id, event_type, freed_beds, triggered_by)
                 VALUES (:room_id, :event_type, :freed_beds, :triggered_by)'
            );
            $evtStmt->execute([
                ':room_id' => $roomId,
                ':event_type' => 'manual_free',
                ':freed_beds' => $beds,
                ':triggered_by' => 'waitlist_promotion',
            ]);

            $this->recalculateWaitlistPositions($this->db);
            $this->db->commit();
            return $promoted;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return [];
        }
    }

    private function recalculateWaitlistPositions(PDO $pdo): void
    {
        $pdo->exec('SET @rownum := 0');
        $pdo->exec(
            '
            UPDATE uaf_waitlist w
            INNER JOIN (
                SELECT id, (@rownum := @rownum + 1) AS rn
                FROM uaf_waitlist
                ORDER BY priority_score DESC, waitlisted_at ASC
            ) ranked ON ranked.id = w.id
            SET w.position = ranked.rn
        '
        );
    }

    /**
     * @param mixed $value
     */
    private function boundedInt(mixed $value, int $min, int $max): int
    {
        $intValue = (int)$value;
        if ($intValue < $min) {
            return $min;
        }
        if ($intValue > $max) {
            return $max;
        }
        return $intValue;
    }

    /**
     * @param mixed $value
     */
    private function boundedFloat(mixed $value, float $min, float $max): float
    {
        $floatValue = (float)$value;
        if ($floatValue < $min) {
            return $min;
        }
        if ($floatValue > $max) {
            return $max;
        }
        return $floatValue;
    }

    /**
     * @param mixed $value
     */
    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (int)$value !== 0;
        }
        if (is_string($value)) {
            $v = strtolower(trim($value));
            return in_array($v, ['1', 'true', 'yes', 'y', 'on'], true);
        }
        return false;
    }

    private function tableHasColumn(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare(
            'SELECT 1
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table_name
               AND COLUMN_NAME = :column_name
             LIMIT 1'
        );
        $stmt->execute([':table_name' => $table, ':column_name' => $column]);
        return (bool)$stmt->fetchColumn();
    }
}

