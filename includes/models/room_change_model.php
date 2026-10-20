<?php
declare(strict_types=1);
/**
 * @file includes/models/room_change_model.php
 * @brief Room change layer — Student requests and status updates
 * @access student,admin,warden
 * @database room_change_requests
 */

require_once __DIR__ . '/../app.php';

function room_change_create(PDO $pdo, int $studentId, ?int $fromRoomId, int $toRoomId, string $reason): bool
{
    $stmt = $pdo->prepare('
        INSERT INTO room_change_requests (student_id, from_room_id, to_room_id, reason, status)
        VALUES (:sid, :from_room_id, :to_room_id, :reason, "pending")
    ');
    return $stmt->execute([
        ':sid' => $studentId,
        ':from_room_id' => $fromRoomId,
        ':to_room_id' => $toRoomId,
        ':reason' => $reason,
    ]);
}

function room_change_list(PDO $pdo, int $studentId, int $limit = 20): array
{
    $stmt = $pdo->prepare('
        SELECT rcr.id,
               rcr.status,
               rcr.reason,
               rcr.requested_at,
               rcr.responded_at,
               to_r.room_number AS to_room_number,
               to_h.name AS to_hostel_name,
               from_r.room_number AS from_room_number,
               from_h.name AS from_hostel_name
        FROM room_change_requests rcr
        LEFT JOIN rooms to_r ON rcr.to_room_id = to_r.id
        LEFT JOIN hostels to_h ON to_r.hostel_id = to_h.id
        LEFT JOIN rooms from_r ON rcr.from_room_id = from_r.id
        LEFT JOIN hostels from_h ON from_r.hostel_id = from_h.id
        WHERE rcr.student_id = :sid
        ORDER BY rcr.requested_at DESC
        LIMIT :lim
    ');
    $stmt->bindValue(':sid', $studentId, PDO::PARAM_INT);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function room_change_has_pending(PDO $pdo, int $studentId): bool
{
    $stmt = $pdo->prepare('
        SELECT 1 FROM room_change_requests
        WHERE student_id = :sid AND status = "pending"
        LIMIT 1
    ');
    $stmt->execute([':sid' => $studentId]);
    return (bool)$stmt->fetchColumn();
}

/**
 * Approve a room change request and promote from waitlist for freed old room.
 */
function room_change_approve(PDO $pdo, int $requestId, int $adminUserId): bool
{
    try {
        $pdo->beginTransaction();

        $lock = $pdo->prepare(
            'SELECT id, student_id, from_room_id, to_room_id, status
             FROM room_change_requests
             WHERE id = :id
             FOR UPDATE'
        );
        $lock->execute([':id' => $requestId]);
        $req = $lock->fetch(PDO::FETCH_ASSOC);
        if (!$req || (string)$req['status'] !== 'pending') {
            $pdo->rollBack();
            return false;
        }

        $oldRoomId = isset($req['from_room_id']) ? (int)$req['from_room_id'] : 0;
        $toRoomId = (int)$req['to_room_id'];

        $upReq = $pdo->prepare(
            'UPDATE room_change_requests
             SET status = \'approved\', responded_at = CURRENT_TIMESTAMP, admin_user_id = :aid
             WHERE id = :id'
        );
        $upReq->execute([':aid' => $adminUserId, ':id' => $requestId]);

        if ($oldRoomId > 0) {
            $decOld = $pdo->prepare('UPDATE rooms SET occupied = GREATEST(occupied - 1, 0) WHERE id = :id');
            $decOld->execute([':id' => $oldRoomId]);
        }

        $incNew = $pdo->prepare('UPDATE rooms SET occupied = occupied + 1 WHERE id = :id');
        $incNew->execute([':id' => $toRoomId]);

        if ($oldRoomId > 0) {
            require_once __DIR__ . '/../services/UafAllocationEngine.php';
            $engine = new UafAllocationEngine($pdo);
            $engine->promoteFromWaitlist((int)$oldRoomId, 1);
        }

        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return false;
    }
}

