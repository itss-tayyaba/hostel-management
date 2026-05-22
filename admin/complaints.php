<?php
declare(strict_types=1);
/**
 * @file admin/complaints.php
 * @brief All complaints — Admin view including escalated items
 * @access admin
 * @database complaints
 */

require_once __DIR__ . '/../includes/app.php';
require_role('admin');

$userName = (string)($_SESSION['name'] ?? 'Admin');
$userRole = (string)($_SESSION['role'] ?? 'admin');
$pageTitle = 'Complaints';
$activePage = 'admin_complaints';

$errors = [];
$messages = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? null;
    if (!csrf_validate(is_string($token) ? $token : null)) {
        $errors[] = 'Invalid CSRF token. Please refresh and try again.';
    } else {
        $complaintId = (int)($_POST['complaint_id'] ?? 0);
        $status = trim((string)($_POST['status'] ?? ''));
        $allowed = ['open', 'in_progress', 'resolved', 'closed'];
        if ($complaintId <= 0 || !in_array($status, $allowed, true)) {
            $errors[] = 'Invalid complaint update request.';
        } else {
            try {
                $pdo = db();
                $resolvedAtSql = ($status === 'resolved' || $status === 'closed') ? 'NOW()' : 'NULL';
                $stmt = $pdo->prepare("
                    UPDATE complaints
                    SET status = :status, resolved_at = {$resolvedAtSql}
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':status' => $status,
                    ':id' => $complaintId,
                ]);
                $messages[] = 'Complaint status updated.';
            } catch (Throwable $e) {
                $errors[] = 'Could not update complaint.';
            }
        }
    }
}

$rows = [];
try {
    $pdo = db();
    $stmt = $pdo->query('
        SELECT c.id, c.title, c.description, c.status, c.created_at,
               c.escalated_to_admin, c.escalated_at,
               s.reg_no, u.name AS student_name
        FROM complaints c
        JOIN students s ON c.student_id = s.id
        JOIN users u ON s.user_id = u.id
        ORDER BY c.created_at DESC
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
        Track and resolve student issues
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
                    <th>Title</th>
                    <th>Description</th>
                    <th>Status</th>
                    <th>Escalated</th>
                    <th>Update</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="7" class="text-center" style="color:var(--text-muted);font-weight:800;">No complaints found.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td style="font-weight:900;"><?php echo e((string)$row['student_name']); ?></td>
                            <td><?php echo e((string)$row['reg_no']); ?></td>
                            <td><?php echo e((string)$row['title']); ?></td>
                            <td><?php echo e((string)$row['description']); ?></td>
                            <td><?php echo e((string)$row['status']); ?></td>
                            <td>
                                <?php $esc = ((int)($row['escalated_to_admin'] ?? 0) === 1); ?>
                                <?php if ($esc): ?>
                                    <span class="badge badge-pending">yes</span>
                                    <div class="small text-muted"><?php echo e((string)($row['escalated_at'] ?? '')); ?></div>
                                <?php else: ?>
                                    <span class="text-muted" style="font-weight:800;">no</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <form method="post" class="d-flex gap-2">
                                    <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                                    <input type="hidden" name="complaint_id" value="<?php echo e((string)$row['id']); ?>">
                                    <select name="status" class="form-select form-select-sm">
                                        <?php foreach (['open', 'in_progress', 'resolved', 'closed'] as $status): ?>
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

