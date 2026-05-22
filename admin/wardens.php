<?php
declare(strict_types=1);
/**
 * @file admin/wardens.php
 * @brief Warden management — Create wardens and assign hostels
 * @access admin
 * @database warden_assignments
 */

require_once __DIR__ . '/../includes/app.php';
require_role('admin');

$userName = (string)($_SESSION['name'] ?? 'Admin');
$userRole = (string)($_SESSION['role'] ?? 'admin');
$pageTitle = 'Warden Allocation';
$activePage = 'admin_wardens';

$pdo = db();
$errors = [];
$messages = [];
$hostels = [];
$eligibleUsers = [];
$wardenRows = [];
$dbReady = true;

try {
    $hostels = $pdo->query('SELECT id, name, type FROM hostels ORDER BY name ASC')->fetchAll();
    $eligibleUsers = $pdo->query(
        '
        SELECT u.id, u.name, u.email, u.role
        FROM users u
        WHERE u.role = "warden"
        ORDER BY u.name ASC
    '
    )->fetchAll();
    $stmtList = $pdo->query(
        '
        SELECT u.id, u.name, u.email,
               wa.hostel_id, wa.is_active, wa.assigned_at,
               h.name AS hostel_name,
               admin_u.name AS assigned_by_name
        FROM users u
        INNER JOIN warden_assignments wa ON wa.user_id = u.id
        INNER JOIN hostels h ON h.id = wa.hostel_id
        INNER JOIN users admin_u ON admin_u.id = wa.assigned_by_user_id
        WHERE u.role = "warden"
        ORDER BY wa.is_active DESC, h.name ASC, u.name ASC
    '
    );
    $wardenRows = $stmtList ? $stmtList->fetchAll() : [];
} catch (Throwable $e) {
    $dbReady = false;
    $errors[] = 'Database issue: ensure you ran database/warden_assignments.sql (or re-import schema.sql) to create table warden_assignments.';
}

$adminUserId = (int)($_SESSION['user_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $dbReady) {
    $token = $_POST['csrf_token'] ?? null;
    if (!csrf_validate(is_string($token) ? $token : null)) {
        $errors[] = 'Invalid CSRF token. Refresh and try again.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        try {
            if ($action === 'assign') {
                $userId = (int)($_POST['user_id'] ?? 0);
                $hostelId = (int)($_POST['hostel_id'] ?? 0);
                if ($userId <= 0 || $hostelId <= 0) {
                    $errors[] = 'Select both a staff member and a hostel.';
                } else {
                    $pdo->beginTransaction();
                    $uStmt = $pdo->prepare('SELECT id, role FROM users WHERE id = :id LIMIT 1 FOR UPDATE');
                    $uStmt->execute([':id' => $userId]);
                    $target = $uStmt->fetch();
                    $hStmt = $pdo->prepare('SELECT id FROM hostels WHERE id = :hid LIMIT 1 FOR UPDATE');
                    $hStmt->execute([':hid' => $hostelId]);
                    $hostelOk = (bool)$hStmt->fetch();
                    if (!$target || !$hostelOk) {
                        $pdo->rollBack();
                        $errors[] = 'Invalid user or hostel selected.';
                    } elseif ((string)$target['role'] === 'admin') {
                        $pdo->rollBack();
                        $errors[] = 'Administrator accounts cannot be assigned as wardens.';
                    } else {
                        $pdo->prepare('UPDATE users SET role = "warden" WHERE id = :id')->execute([':id' => $userId]);
                        $ins = $pdo->prepare(
                            '
                            INSERT INTO warden_assignments (user_id, hostel_id, assigned_by_user_id, is_active)
                            VALUES (:uid, :hid, :aid, 1)
                            ON DUPLICATE KEY UPDATE
                                hostel_id = VALUES(hostel_id),
                                assigned_by_user_id = VALUES(assigned_by_user_id),
                                is_active = 1
                        '
                        );
                        $ins->execute([
                            ':uid' => $userId,
                            ':hid' => $hostelId,
                            ':aid' => $adminUserId,
                        ]);
                        $pdo->commit();
                        $messages[] = 'Warden assigned to hostel successfully.';
                        $wardenRows = $pdo->query(
                            '
                            SELECT u.id, u.name, u.email,
                                   wa.hostel_id, wa.is_active, wa.assigned_at,
                                   h.name AS hostel_name,
                                   admin_u.name AS assigned_by_name
                            FROM users u
                            INNER JOIN warden_assignments wa ON wa.user_id = u.id
                            INNER JOIN hostels h ON h.id = wa.hostel_id
                            INNER JOIN users admin_u ON admin_u.id = wa.assigned_by_user_id
                            WHERE u.role = "warden"
                            ORDER BY wa.is_active DESC, h.name ASC, u.name ASC
                        '
                        )->fetchAll();
                    }
                }
            } elseif ($action === 'deactivate') {
                $userId = (int)($_POST['user_id'] ?? 0);
                if ($userId <= 0) {
                    $errors[] = 'Invalid warden.';
                } else {
                    $pdo->beginTransaction();
                    $pdo->prepare('UPDATE warden_assignments SET is_active = 0 WHERE user_id = :uid')->execute([':uid' => $userId]);
                    // Keep role as warden; login is blocked until a new assignment is made.
                    $pdo->commit();
                    $messages[] = 'Assignment deactivated. User cannot open the warden portal until reassigned.';
                    $wardenRows = $pdo->query(
                        '
                        SELECT u.id, u.name, u.email,
                               wa.hostel_id, wa.is_active, wa.assigned_at,
                               h.name AS hostel_name,
                               admin_u.name AS assigned_by_name
                        FROM users u
                        INNER JOIN warden_assignments wa ON wa.user_id = u.id
                        INNER JOIN hostels h ON h.id = wa.hostel_id
                        INNER JOIN users admin_u ON admin_u.id = wa.assigned_by_user_id
                        WHERE u.role = "warden"
                        ORDER BY wa.is_active DESC, h.name ASC, u.name ASC
                    '
                    )->fetchAll();
                }
            } else {
                $errors[] = 'Unknown action.';
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'Could not save changes. Verify the migration SQL has been applied.';
        }
    }
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
        Only admins can assign hostel wardens
    </div>
</div>

<div class="dash-content">
    <?php if ($messages): ?>
        <div class="alert alert-success">
            <ul class="mb-0"><?php foreach ($messages as $m): ?><li><?php echo e($m); ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>
    <?php if ($errors): ?>
        <div class="alert alert-danger">
            <ul class="mb-0"><?php foreach ($errors as $err): ?><li><?php echo e($err); ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-lg-5">
            <div class="dash-card">
                <div style="font-weight:900;color:var(--dark);margin-bottom:6px;">Assign / re-assign warden</div>
                <p class="text-muted" style="font-size:13px;font-weight:700;line-height:1.5;margin-bottom:16px;">
                    Pick a registered warden account and the hostel they will oversee.
                    Warden login to hostel details stays blocked until assignment is saved here.
                </p>
                <?php if (empty($hostels)): ?>
                    <div class="alert alert-warning mb-0">Create at least one hostel under <strong>Rooms</strong> before assigning.</div>
                <?php elseif (empty($eligibleUsers)): ?>
                    <div class="alert alert-info mb-0">
                        No warden accounts found yet.
                        Ask the warden to register from <a href="../register.php" class="fw-bold">Register</a> and select <strong>Warden</strong>.
                        After registration, come back here to assign a hostel.
                    </div>
                <?php else: ?>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                        <input type="hidden" name="action" value="assign">
                        <div class="mb-3">
                            <label class="form-label fw-bold">User</label>
                            <select name="user_id" class="form-select" required>
                                <option value="">— Choose user —</option>
                                <?php foreach ($eligibleUsers as $eu): ?>
                                    <option value="<?php echo e((string)(int)$eu['id']); ?>">
                                        <?php echo e((string)$eu['name']); ?> —
                                        <?php echo e((string)$eu['email']); ?>
                                        (<?php echo e((string)$eu['role']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Hostel</label>
                            <select name="hostel_id" class="form-select" required>
                                <option value="">— Choose hostel —</option>
                                <?php foreach ($hostels as $h): ?>
                                    <option value="<?php echo e((string)(int)$h['id']); ?>">
                                        <?php echo e((string)$h['name']); ?> (<?php echo e((string)$h['type']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary w-100"
                                style="background:var(--dark);border:none;font-weight:800;">
                            Save assignment
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
        <div class="col-lg-7">
            <div class="dash-card">
                <div style="font-weight:900;color:var(--dark);margin-bottom:10px;">How it works</div>
                <ul class="mb-0" style="font-size:13px;font-weight:750;line-height:1.7;color:var(--text-muted);">
                    <li>Warden accounts can register themselves, but they stay pending until admin assignment.</li>
                    <li>Each warden maps to exactly one hostel in this layout (re-assign to switch).</li>
                    <li>Deactivated assignments block warden login until you assign again.</li>
                    <li>The warden portal only shows complaints and room-change requests touching that hostel.</li>
                </ul>
            </div>
        </div>
    </div>

    <div class="dash-table">
        <div class="p-3" style="border-bottom:1px solid rgba(0,0,0,0.06);font-weight:900;color:var(--dark);">
            Warden directory
        </div>
        <div class="table-responsive">
            <table class="table table-borderless align-middle">
                <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Hostel</th>
                    <th>Status</th>
                    <th>Assigned</th>
                    <th>Allocated by</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($wardenRows)): ?>
                    <tr>
                        <td colspan="7" class="text-center" style="color:var(--text-muted);font-weight:800;">
                            No assignments yet.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($wardenRows as $rw): ?>
                        <tr>
                            <td style="font-weight:900;"><?php echo e((string)$rw['name']); ?></td>
                            <td><?php echo e((string)$rw['email']); ?></td>
                            <td><?php echo e((string)$rw['hostel_name']); ?></td>
                            <td>
                                <?php $active = ((int)$rw['is_active'] === 1); ?>
                                <span class="badge <?php echo $active ? 'badge-approved' : 'badge-rejected'; ?>">
                                    <?php echo $active ? 'active' : 'inactive'; ?>
                                </span>
                            </td>
                            <td style="font-size:13px;color:var(--text-muted);font-weight:750;">
                                <?php echo e((string)$rw['assigned_at']); ?>
                            </td>
                            <td><?php echo e((string)$rw['assigned_by_name']); ?></td>
                            <td>
                                <?php if ($active): ?>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Deactivate this assignment?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                                        <input type="hidden" name="action" value="deactivate">
                                        <input type="hidden" name="user_id" value="<?php echo e((string)(int)$rw['id']); ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">Deactivate</button>
                                    </form>
                                <?php else: ?>
                                    <span class="text-muted" style="font-size:12px;font-weight:800;">Use form to re-assign</span>
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
