<?php
declare(strict_types=1);
/**
 * @file admin/students.php
 * @brief Manage students — List, edit, activate/deactivate, delete
 * @access admin
 * @database students, users
 */

require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/../includes/models/audit_model.php';
require_role('admin');

$userName = (string)($_SESSION['name'] ?? 'Admin');
$userRole = (string)($_SESSION['role'] ?? 'admin');
$pageTitle = 'Manage Students';
$activePage = 'admin_students';

$pdo = db();
$errors = [];
$messages = [];

// Detect if v2 columns exist (is_active)
$hasIsActive = false;
try {
    $col = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'is_active'"
    )->fetchColumn();
    $hasIsActive = ((int)$col) > 0;
} catch (Throwable $e) {
    $hasIsActive = false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? null;
    if (!csrf_validate(is_string($token) ? $token : null)) {
        $errors[] = 'Invalid CSRF token. Refresh and try again.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        $studentId = (int)($_POST['student_id'] ?? 0);
        try {
            $pdo->beginTransaction();
            $info = null;
            if ($studentId > 0) {
                $stmt = $pdo->prepare(
                    'SELECT s.id AS sid, s.user_id, u.email, u.role FROM students s
                     INNER JOIN users u ON u.id = s.user_id WHERE s.id = :id LIMIT 1 FOR UPDATE'
                );
                $stmt->execute([':id' => $studentId]);
                $info = $stmt->fetch();
            }

            if (!$info) {
                throw new RuntimeException('Student not found.');
            }

            if ($action === 'update_student') {
                $name = trim((string)($_POST['name'] ?? ''));
                $department = trim((string)($_POST['department'] ?? ''));
                $phone = trim((string)($_POST['phone'] ?? ''));
                $gender = normalize_user_gender((string)($_POST['gender'] ?? ''));
                $regNo = trim((string)($_POST['reg_no'] ?? ''));

                if ($name === '' || $department === '' || $regNo === '' || $gender === null) {
                    throw new RuntimeException('Name, department, registration #, gender are required.');
                }

                $uUp = $pdo->prepare('UPDATE users SET name = :n, gender = :g WHERE id = :uid');
                $uUp->execute([':n' => $name, ':g' => $gender, ':uid' => (int)$info['user_id']]);

                $sUp = $pdo->prepare(
                    'UPDATE students SET reg_no = :r, department = :d, phone = :p, gender = :g WHERE id = :sid'
                );
                $sUp->execute([
                    ':r' => $regNo,
                    ':d' => $department,
                    ':p' => $phone !== '' ? $phone : null,
                    ':g' => $gender,
                    ':sid' => $studentId,
                ]);
                audit_log_from_session($pdo, 'student_updated', 'student', $studentId, 'reg=' . $regNo);
                $messages[] = 'Student profile updated.';
            } elseif ($action === 'toggle_active' && $hasIsActive) {
                $newState = (int)($_POST['new_state'] ?? 0) === 1 ? 1 : 0;
                $u = $pdo->prepare('UPDATE users SET is_active = :s WHERE id = :uid');
                $u->execute([':s' => $newState, ':uid' => (int)$info['user_id']]);
                audit_log_from_session($pdo, $newState === 1 ? 'student_activated' : 'student_deactivated', 'student', $studentId);
                $messages[] = $newState === 1 ? 'Student account activated.' : 'Student account deactivated.';
            } elseif ($action === 'delete_student') {
                // CASCADE on students removes child rows.
                $uDel = $pdo->prepare('DELETE FROM users WHERE id = :uid');
                $uDel->execute([':uid' => (int)$info['user_id']]);
                audit_log_from_session($pdo, 'student_deleted', 'student', $studentId);
                $messages[] = 'Student deleted.';
            } else {
                throw new RuntimeException('Unknown action.');
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors[] = $e->getMessage() ?: 'Operation failed.';
        }
    }
}

$rows = [];
try {
    $extraCol = $hasIsActive ? ', COALESCE(u.is_active,1) AS is_active' : '';
    $stmt = $pdo->query(
        'SELECT s.id, s.reg_no, s.department, s.gender, s.phone, s.created_at,
                u.id AS user_id, u.name, u.email, u.role' . $extraCol . '
         FROM students s
         JOIN users u ON s.user_id = u.id
         ORDER BY s.created_at DESC'
    );
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
                style="background:rgba(27,94,32,0.08); color:var(--dark); border:1px solid rgba(27,94,32,0.18); font-weight:900;">☰</button>
    </div>
    <div class="title"><?php echo e($pageTitle); ?></div>
    <div style="font-size:13px; color:var(--text-muted); font-weight:800;">Edit, deactivate, or delete student records</div>
</div>

<div class="dash-content">
    <?php if ($errors): ?>
        <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $err): ?><li><?php echo e($err); ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>
    <?php if ($messages): ?>
        <div class="alert alert-success"><ul class="mb-0"><?php foreach ($messages as $msg): ?><li><?php echo e($msg); ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>

    <?php if (!$hasIsActive): ?>
        <div class="alert alert-warning">
            Activate/deactivate is hidden until you run <code>database/v2_full_upgrade.sql</code>.
        </div>
    <?php endif; ?>

    <div class="dash-table">
        <div class="p-3" style="border-bottom:1px solid rgba(0,0,0,0.06);font-weight:900;color:var(--dark);">
            Student Directory
        </div>
        <div class="table-responsive">
            <table class="table table-borderless align-middle">
                <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Reg #</th>
                    <th>Department</th>
                    <th>Gender</th>
                    <?php if ($hasIsActive): ?><th>Status</th><?php endif; ?>
                    <th class="text-end">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="<?php echo $hasIsActive ? 7 : 6; ?>" class="text-center" style="color:var(--text-muted);font-weight:800;">No students found.</td></tr>
                <?php else: foreach ($rows as $row): ?>
                    <tr>
                        <td style="font-weight:900;"><?php echo e((string)$row['name']); ?></td>
                        <td><?php echo e((string)$row['email']); ?></td>
                        <td><?php echo e((string)$row['reg_no']); ?></td>
                        <td><?php echo e((string)$row['department']); ?></td>
                        <td><?php echo e(ucfirst((string)($row['gender'] ?? 'male'))); ?></td>
                        <?php if ($hasIsActive): ?>
                            <td>
                                <?php if ((int)$row['is_active'] === 1): ?>
                                    <span class="badge text-bg-success">Active</span>
                                <?php else: ?>
                                    <span class="badge text-bg-secondary">Disabled</span>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                        <td class="text-end">
                            <button class="btn btn-sm btn-outline-primary" type="button"
                                    data-bs-toggle="modal" data-bs-target="#editModal<?php echo (int)$row['id']; ?>">Edit</button>
                            <?php if ($hasIsActive): ?>
                                <form method="post" class="d-inline" onsubmit="return confirm('Toggle account status?');">
                                    <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                                    <input type="hidden" name="action" value="toggle_active">
                                    <input type="hidden" name="student_id" value="<?php echo (int)$row['id']; ?>">
                                    <input type="hidden" name="new_state" value="<?php echo (int)$row['is_active'] === 1 ? 0 : 1; ?>">
                                    <button class="btn btn-sm <?php echo (int)$row['is_active'] === 1 ? 'btn-outline-warning' : 'btn-outline-success'; ?>">
                                        <?php echo (int)$row['is_active'] === 1 ? 'Deactivate' : 'Activate'; ?>
                                    </button>
                                </form>
                            <?php endif; ?>
                            <form method="post" class="d-inline" onsubmit="return confirm('Permanently delete this student? All their data will cascade.');">
                                <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                                <input type="hidden" name="action" value="delete_student">
                                <input type="hidden" name="student_id" value="<?php echo (int)$row['id']; ?>">
                                <button class="btn btn-sm btn-outline-danger">Delete</button>
                            </form>
                        </td>
                    </tr>

                    <!-- Edit modal per row -->
                    <div class="modal fade" id="editModal<?php echo (int)$row['id']; ?>" tabindex="-1">
                        <div class="modal-dialog">
                            <div class="modal-content">
                                <form method="post">
                                    <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                                    <input type="hidden" name="action" value="update_student">
                                    <input type="hidden" name="student_id" value="<?php echo (int)$row['id']; ?>">
                                    <div class="modal-header">
                                        <h5 class="modal-title">Edit student — <?php echo e((string)$row['name']); ?></h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">
                                        <div class="mb-2">
                                            <label class="form-label fw-bold">Full name</label>
                                            <input type="text" class="form-control" name="name" required value="<?php echo e((string)$row['name']); ?>">
                                        </div>
                                        <div class="row g-2">
                                            <div class="col-md-6 mb-2">
                                                <label class="form-label fw-bold">Reg #</label>
                                                <input type="text" class="form-control" name="reg_no" required value="<?php echo e((string)$row['reg_no']); ?>">
                                            </div>
                                            <div class="col-md-6 mb-2">
                                                <label class="form-label fw-bold">Department</label>
                                                <input type="text" class="form-control" name="department" required value="<?php echo e((string)$row['department']); ?>">
                                            </div>
                                            <div class="col-md-6 mb-2">
                                                <label class="form-label fw-bold">Gender</label>
                                                <select class="form-select" name="gender">
                                                    <option value="male" <?php echo (string)$row['gender'] === 'male' ? 'selected' : ''; ?>>Male</option>
                                                    <option value="female" <?php echo (string)$row['gender'] === 'female' ? 'selected' : ''; ?>>Female</option>
                                                </select>
                                            </div>
                                            <div class="col-md-6 mb-2">
                                                <label class="form-label fw-bold">Phone</label>
                                                <input type="text" class="form-control" name="phone" value="<?php echo e((string)($row['phone'] ?? '')); ?>">
                                            </div>
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button class="btn btn-success fw-bold">Save changes</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/layout/footer.php'; ?>
