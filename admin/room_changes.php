<?php
declare(strict_types=1);
/**
 * @file admin/room_changes.php
 * @brief Room change requests — Approve/reject student moves
 * @access admin
 * @database room_change_requests
 */

require_once __DIR__ . '/../includes/app.php';
require_role('admin');

$userName = (string)($_SESSION['name'] ?? 'Admin');
$userRole = (string)($_SESSION['role'] ?? 'admin');
$pageTitle = 'Room Change Requests';
$activePage = 'admin_changes';

$errors = [];
$messages = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? null;
    if (!csrf_validate(is_string($token) ? $token : null)) {
        $errors[] = 'Invalid CSRF token. Please refresh and try again.';
    } else {
        $requestId = (int)($_POST['request_id'] ?? 0);
        $status = trim((string)($_POST['status'] ?? ''));
        $allowed = ['pending', 'approved', 'rejected'];
        if ($requestId <= 0 || !in_array($status, $allowed, true)) {
            $errors[] = 'Invalid room change update request.';
        } else {
            try {
                $pdo = db();
                $stmt = $pdo->prepare('
                    UPDATE room_change_requests
                    SET status = :status,
                        responded_at = NOW(),
                        admin_user_id = :admin_id
                    WHERE id = :id
                ');
                $stmt->execute([
                    ':status' => $status,
                    ':admin_id' => (int)$_SESSION['user_id'],
                    ':id' => $requestId,
                ]);
                $messages[] = 'Room change request updated.';
            } catch (Throwable $e) {
                $errors[] = 'Could not update room change request.';
            }
        }
    }
}

$rows = [];
try {
    $pdo = db();
    $stmt = $pdo->query('
        SELECT rcr.id, rcr.status, rcr.reason, rcr.requested_at,
               s.reg_no, u.name AS student_name,
               rf.room_number AS from_room,
               rt.room_number AS to_room
        FROM room_change_requests rcr
        JOIN students s ON rcr.student_id = s.id
        JOIN users u ON s.user_id = u.id
        LEFT JOIN rooms rf ON rcr.from_room_id = rf.id
        LEFT JOIN rooms rt ON rcr.to_room_id = rt.id
        ORDER BY rcr.requested_at DESC
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
        Process student room transfer requests
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
                    <th>From Room</th>
                    <th>To Room</th>
                    <th>Reason</th>
                    <th>Status</th>
                    <th>Update</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="7" class="text-center" style="color:var(--text-muted);font-weight:800;">No room change requests found.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td style="font-weight:900;"><?php echo e((string)$row['student_name']); ?></td>
                            <td><?php echo e((string)$row['reg_no']); ?></td>
                            <td><?php echo e((string)($row['from_room'] ?? '-')); ?></td>
                            <td><?php echo e((string)($row['to_room'] ?? '-')); ?></td>
                            <td><?php echo e((string)$row['reason']); ?></td>
                            <td><?php echo e((string)$row['status']); ?></td>
                            <td>
                                <form method="post" class="d-flex gap-2">
                                    <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                                    <input type="hidden" name="request_id" value="<?php echo e((string)$row['id']); ?>">
                                    <select name="status" class="form-select form-select-sm">
                                        <?php foreach (['pending', 'approved', 'rejected'] as $status): ?>
                                            <option value="<?php echo e($status); ?>" <?php echo ((string)$row['status'] === $status) ? 'selected' : ''; ?>>
                                                <?php echo e($status); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="btn btn-sm btn-primary">Save</button>
                                </form>
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

