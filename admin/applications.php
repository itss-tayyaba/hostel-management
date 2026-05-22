<?php
declare(strict_types=1);
/**
 * @file admin/applications.php
 * @brief Room applications — Approve/reject room_allocation requests
 * @access admin
 * @database room_allocation
 */

require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/../includes/models/smart_allocation_model.php';
require_once __DIR__ . '/../includes/models/audit_model.php';
require_role('admin');

$userName = (string)($_SESSION['name'] ?? 'Admin');
$userRole = (string)($_SESSION['role'] ?? 'admin');
$pageTitle = 'Room Applications';
$activePage = 'admin_applications';

$errors = [];
$messages = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? null;
    if (!csrf_validate(is_string($token) ? $token : null)) {
        $errors[] = 'Invalid CSRF token. Please refresh and try again.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        $allocationId = (int)($_POST['allocation_id'] ?? 0);
        if ($allocationId > 0 && in_array($action, ['approve', 'reject'], true)) {
            try {
                $pdo = db();
                $pdo->beginTransaction();

                $stmtFetch = $pdo->prepare('
                    SELECT id, student_id, room_id, status
                    FROM room_allocation
                    WHERE id = :id
                    LIMIT 1
                ');
                $stmtFetch->execute([':id' => $allocationId]);
                $row = $stmtFetch->fetch();

                if (!$row) {
                    $errors[] = 'Application not found.';
                } elseif ((string)$row['status'] !== 'pending') {
                    $errors[] = 'Only pending applications can be updated.';
                } else {
                    $newStatus = ($action === 'approve') ? 'approved' : 'rejected';
                    $stmtUpdate = $pdo->prepare('
                        UPDATE room_allocation
                        SET status = :status, responded_at = NOW(), admin_user_id = :admin_id
                        WHERE id = :id
                    ');
                    $stmtUpdate->execute([
                        ':status' => $newStatus,
                        ':admin_id' => (int)$_SESSION['user_id'],
                        ':id' => $allocationId,
                    ]);

                    if ($newStatus === 'approved') {
                        $approvePlacementOk = true;
                        if (smart_allocation_tables_ready($pdo)) {
                            $assign = smart_allocation_assign_student_to_room(
                                $pdo,
                                (int)$row['student_id'],
                                (int)$row['room_id'],
                                (int)$_SESSION['user_id'],
                                'Approved application'
                            );
                            if (!$assign['ok']) {
                                $errors[] = 'Cannot approve: ' . $assign['message'];
                                $approvePlacementOk = false;
                            }
                        } else {
                            $stmtRoom = $pdo->prepare('UPDATE rooms SET occupied = 1 WHERE id = :room_id AND occupied = 0');
                            $stmtRoom->execute([':room_id' => (int)$row['room_id']]);
                            if ($stmtRoom->rowCount() !== 1) {
                                $errors[] = 'Cannot approve: room is already marked occupied (legacy inventory).';
                                $approvePlacementOk = false;
                            }
                        }

                        if ($approvePlacementOk) {
                            $stmtRejectOthers = $pdo->prepare('
                                UPDATE room_allocation
                                SET status = \'rejected\', responded_at = NOW(), admin_user_id = :admin_id,
                                    notes = \'Auto-rejected: another room approved\'
                                WHERE student_id = :sid AND status = \'pending\' AND id <> :id
                            ');
                            $stmtRejectOthers->execute([
                                ':admin_id' => (int)$_SESSION['user_id'],
                                ':sid' => (int)$row['student_id'],
                                ':id' => $allocationId,
                            ]);
                        }
                    }

                    if (!$errors) {
                        audit_log_from_session(
                            $pdo,
                            'application_' . $newStatus,
                            'room_allocation',
                            $allocationId,
                            'student_id=' . (int)$row['student_id'] . ' room_id=' . (int)$row['room_id']
                        );
                        $messages[] = 'Application updated successfully.';
                    }
                }

                if ($errors) {
                    $pdo->rollBack();
                } else {
                    $pdo->commit();
                }
            } catch (Throwable $e) {
                if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = 'Could not update application right now.';
            }
        } else {
            $errors[] = 'Invalid application update request.';
        }
    }
}

$rows = [];
try {
    $pdo = db();
    $stmt = $pdo->query('
        SELECT ra.id, ra.status, ra.applied_at, ra.responded_at,
               s.reg_no, s.department, u.name AS student_name,
               h.name AS hostel_name, r.room_number
        FROM room_allocation ra
        JOIN students s ON ra.student_id = s.id
        JOIN users u ON s.user_id = u.id
        JOIN rooms r ON ra.room_id = r.id
        JOIN hostels h ON r.hostel_id = h.id
        ORDER BY ra.applied_at DESC
    ');
    $rows = $stmt->fetchAll();
} catch (Throwable $e) {
    $rows = [];
}
?>

<?php require_once __DIR__ . '/../includes/layout/header.php'; ?>
<div class="dash-overlay" aria-hidden="true"></div>
<?php require_once __DIR__ . '/../includes/layout/sidebar.php'; ?>

<div class="dash-topbar d-flex align-items-center justify-content-between gap-3">
    <div class="dash-mobile-toggle">
        <button class="btn btn-sm" type="button" onclick="toggleDashboardSidebar()"
                style="background:rgba(27,94,32,0.08); color:var(--dark); border:1px solid rgba(27,94,32,0.18); font-weight:900;">
            ☰
        </button>
    </div>
    <div class="title"><?php echo e($pageTitle); ?></div>
    <div style="font-size:13px; color:var(--text-muted); font-weight:800;">
        Approve or reject allocation requests
    </div>
</div>

<div class="dash-content">
    <?php if ($errors): ?>
        <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $err): ?><li><?php echo e($err); ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>
    <?php if ($messages): ?>
        <div class="alert alert-success"><ul class="mb-0"><?php foreach ($messages as $msg): ?><li><?php echo e($msg); ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>

    <div class="dash-table">
        <div class="table-responsive">
            <table class="table table-borderless align-middle">
                <thead>
                <tr>
                    <th>Student</th>
                    <th>Reg #</th>
                    <th>Department</th>
                    <th>Hostel</th>
                    <th>Room</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="7" class="text-center" style="color:var(--text-muted);font-weight:800;">No applications found.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td style="font-weight:900;"><?php echo e((string)$row['student_name']); ?></td>
                            <td><?php echo e((string)$row['reg_no']); ?></td>
                            <td><?php echo e((string)$row['department']); ?></td>
                            <td><?php echo e((string)$row['hostel_name']); ?></td>
                            <td><?php echo e((string)$row['room_number']); ?></td>
                            <td><?php echo e((string)$row['status']); ?></td>
                            <td>
                                <?php if ((string)$row['status'] === 'pending'): ?>
                                    <div class="d-flex gap-2">
                                        <form method="post">
                                            <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                                            <input type="hidden" name="allocation_id" value="<?php echo e((string)$row['id']); ?>">
                                            <input type="hidden" name="action" value="approve">
                                            <button type="submit" class="btn btn-sm btn-success">Approve</button>
                                        </form>
                                        <form method="post">
                                            <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                                            <input type="hidden" name="allocation_id" value="<?php echo e((string)$row['id']); ?>">
                                            <input type="hidden" name="action" value="reject">
                                            <button type="submit" class="btn btn-sm btn-danger">Reject</button>
                                        </form>
                                    </div>
                                <?php else: ?>
                                    <span style="color:var(--text-muted);font-weight:800;">No action</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/layout/footer.php'; ?>

