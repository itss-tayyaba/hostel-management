<?php
declare(strict_types=1);
/**
 * @file includes/models/complaint_model.php
 * @brief Complaints layer — CRUD, escalation to admin
 * @access student,admin,warden
 * @database complaints
 */

require_once __DIR__ . '/../app.php';

function complaint_create(PDO $pdo, int $studentId, ?int $hostelId, ?int $roomId, string $title, string $description): bool
{
    $stmt = $pdo->prepare('
        INSERT INTO complaints (student_id, hostel_id, room_id, title, description, status)
        VALUES (:sid, :hid, :rid, :title, :desc, "open")
    ');
    return $stmt->execute([
        ':sid' => $studentId,
        ':hid' => $hostelId,
        ':rid' => $roomId,
        ':title' => $title,
        ':desc' => $description,
    ]);
}

function complaint_list_by_student(PDO $pdo, int $studentId, int $limit = 20): array
{
    $stmt = $pdo->prepare('
        SELECT c.id, c.title, c.description, c.status, c.created_at, c.resolved_at,
               c.escalated_to_admin, c.escalated_at,
               h.name AS hostel_name, r.room_number
        FROM complaints c
        LEFT JOIN hostels h ON c.hostel_id = h.id
        LEFT JOIN rooms r ON c.room_id = r.id
        WHERE c.student_id = :sid
        ORDER BY c.created_at DESC
        LIMIT :lim
    ');
    $stmt->bindValue(':sid', $studentId, PDO::PARAM_INT);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Escalate an unresolved complaint to admin (student-owned only).
 * Returns true if updated, false otherwise.
 */
function complaint_escalate_to_admin(PDO $pdo, int $studentId, int $complaintId): bool
{
    $stmt = $pdo->prepare('
        UPDATE complaints
        SET escalated_to_admin = 1,
            escalated_at = COALESCE(escalated_at, NOW())
        WHERE id = :cid
          AND student_id = :sid
          AND status IN ("open","in_progress")
    ');
    $stmt->execute([
        ':cid' => $complaintId,
        ':sid' => $studentId,
    ]);
    return $stmt->rowCount() > 0;
}

