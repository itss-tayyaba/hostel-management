<?php
declare(strict_types=1);
/**
 * @file includes/warden_scope.php
 * @brief Warden authorization — Checks complaint/room-change belongs to warden hostel
 * @access warden
 */

require_once __DIR__ . '/app.php';

/** Student lives in assigned hostel iff they hold an approved allocation to a room in that hostel */
function warden_student_allocated_in_hostel(PDO $pdo, int $studentId, int $wardenHostelId): bool
{
    if ($studentId <= 0 || $wardenHostelId <= 0) {
        return false;
    }
    $st = $pdo->prepare(
        'SELECT 1 FROM room_allocation ra
            INNER JOIN rooms rm ON rm.id = ra.room_id
            WHERE ra.student_id = :sid AND ra.status = \'approved\' AND rm.hostel_id = :hid
            LIMIT 1'
    );
    $st->execute([':sid' => $studentId, ':hid' => $wardenHostelId]);
    return (bool)$st->fetch();
}

function warden_complaint_in_scope(PDO $pdo, int $complaintId, int $wardenHostelId): bool
{
    $st = $pdo->prepare('SELECT student_id FROM complaints WHERE id = :id LIMIT 1');
    $st->execute([':id' => $complaintId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return false;
    }
    return warden_student_allocated_in_hostel($pdo, (int)$row['student_id'], $wardenHostelId);
}

function warden_room_change_in_scope(PDO $pdo, int $requestId, int $wardenHostelId): bool
{
    $st = $pdo->prepare('SELECT student_id FROM room_change_requests WHERE id = :id LIMIT 1');
    $st->execute([':id' => $requestId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return false;
    }
    return warden_student_allocated_in_hostel($pdo, (int)$row['student_id'], $wardenHostelId);
}
