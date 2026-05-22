<?php
declare(strict_types=1);
/**
 * @file warden.php
 * @brief Warden dashboard — Hostel-scoped complaints and room-change queues
 * @access warden
 * @database complaints, room_change_requests
 */

require_once __DIR__ . '/includes/app.php';
require_once __DIR__ . '/includes/warden_scope.php';
require_once __DIR__ . '/includes/models/notice_model.php';
require_allocated_warden();

$userName = (string)($_SESSION['name'] ?? 'Warden');
$userRole = (string)($_SESSION['role'] ?? 'warden');
$pageTitle = 'Warden Dashboard';
$activePage = 'warden_dashboard';
$dashShellClass = 'warden-shell-ref';
$hostelId = (int)($_SESSION['warden_hostel_id'] ?? 0);
$hostelName = (string)($_SESSION['warden_hostel_name'] ?? '');
$b = base_path();

$errors = [];
$messages = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? null;
    if (!csrf_validate(is_string($token) ? $token : null)) {
        $errors[] = 'Invalid CSRF token. Please refresh and try again.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        $recordId = (int)($_POST['id'] ?? 0);
        $status = trim((string)($_POST['status'] ?? ''));

        $allowedComplaint = ['open', 'in_progress', 'resolved', 'closed'];
        $allowedChange = ['pending', 'approved', 'rejected'];

        try {
            $pdo = db();

            if ($action === 'update_complaint' && $recordId > 0 && in_array($status, $allowedComplaint, true)) {
                if (!warden_complaint_in_scope($pdo, $recordId, $hostelId)) {
                    $errors[] = 'You can only manage complaints from your hostel.';
                } else {
                    $resolvedAtSql = ($status === 'resolved' || $status === 'closed') ? 'NOW()' : 'NULL';
                    $stmt = $pdo->prepare("
                        UPDATE complaints
                        SET status = :status,
                            resolved_at = {$resolvedAtSql}
                        WHERE id = :id
                    ");
                    $stmt->execute([
                        ':status' => $status,
                        ':id' => $recordId,
                    ]);
                    $messages[] = 'Complaint status updated.';
                }
            } elseif ($action === 'update_room_change' && $recordId > 0 && in_array($status, $allowedChange, true)) {
                if (!warden_room_change_in_scope($pdo, $recordId, $hostelId)) {
                    $errors[] = 'You can only manage room changes for rooms in your hostel.';
                } else {
                    $stmt = $pdo->prepare('
                        UPDATE room_change_requests
                        SET status = :status,
                            responded_at = NOW()
                        WHERE id = :id
                    ');
                    $stmt->execute([
                        ':status' => $status,
                        ':id' => $recordId,
                    ]);
                    $messages[] = 'Room change request status updated.';
                }
            } else {
                $errors[] = 'Invalid update request.';
            }
        } catch (Throwable $e) {
            $errors[] = 'Could not update record right now. Please try again.';
        }
    }
}

$kpis = [
    'open_complaints' => 0,
    'pending_changes' => 0,
    'hostel_students' => 0,
];
$complaints = [];
$roomChanges = [];

try {
    $pdo = db();

    $stmtOpen = $pdo->prepare(
        '
        SELECT COUNT(DISTINCT c.id) FROM complaints c
        WHERE c.status IN ("open","in_progress")
          AND EXISTS (
            SELECT 1 FROM room_allocation ra
            INNER JOIN rooms rm ON rm.id = ra.room_id
            WHERE ra.student_id = c.student_id AND ra.status = "approved" AND rm.hostel_id = :hid
          )
    '
    );
    $stmtOpen->execute([':hid' => $hostelId]);
    $kpis['open_complaints'] = (int)$stmtOpen->fetchColumn();

    $stmtPending = $pdo->prepare(
        '
        SELECT COUNT(DISTINCT rcr.id) FROM room_change_requests rcr
        WHERE rcr.status = "pending"
          AND EXISTS (
            SELECT 1 FROM room_allocation ra
            INNER JOIN rooms rm ON rm.id = ra.room_id
            WHERE ra.student_id = rcr.student_id AND ra.status = "approved" AND rm.hostel_id = :hid
          )
    '
    );
    $stmtPending->execute([':hid' => $hostelId]);
    $kpis['pending_changes'] = (int)$stmtPending->fetchColumn();

    $stu = $pdo->prepare(
        '
        SELECT COUNT(DISTINCT s.id) FROM students s
        INNER JOIN room_allocation ra ON ra.student_id = s.id AND ra.status = "approved"
        INNER JOIN rooms rm ON rm.id = ra.room_id
        WHERE rm.hostel_id = :hid
    '
    );
    $stu->execute([':hid' => $hostelId]);
    $kpis['hostel_students'] = (int)$stu->fetchColumn();

    $stmtComplaints = $pdo->prepare(
        '
        SELECT c.id, c.title, c.status, c.created_at, s.reg_no, s.department
        FROM complaints c
        JOIN students s ON c.student_id = s.id
        WHERE EXISTS (
            SELECT 1 FROM room_allocation ra
            INNER JOIN rooms rm ON rm.id = ra.room_id
            WHERE ra.student_id = c.student_id AND ra.status = "approved" AND rm.hostel_id = :hid
          )
          AND c.status IN ("open","in_progress")
        ORDER BY c.created_at DESC
        LIMIT 8
    '
    );
    $stmtComplaints->execute([':hid' => $hostelId]);
    $complaints = $stmtComplaints->fetchAll();

    $stmtChanges = $pdo->prepare(
        '
        SELECT rcr.id, rcr.status, rcr.requested_at, s.reg_no, s.department,
               rf.room_number AS from_room, rt.room_number AS to_room
        FROM room_change_requests rcr
        JOIN students s ON rcr.student_id = s.id
        LEFT JOIN rooms rf ON rcr.from_room_id = rf.id
        LEFT JOIN rooms rt ON rcr.to_room_id = rt.id
        WHERE EXISTS (
            SELECT 1 FROM room_allocation ra
            INNER JOIN rooms rm ON rm.id = ra.room_id
            WHERE ra.student_id = rcr.student_id AND ra.status = "approved" AND rm.hostel_id = :hid
          )
        ORDER BY rcr.requested_at DESC
        LIMIT 8
    '
    );
    $stmtChanges->execute([':hid' => $hostelId]);
    $roomChanges = $stmtChanges->fetchAll();
} catch (Throwable $e) {
}

$pdoForNotices = db();
$publicNotices = notice_table_ready($pdoForNotices) ? notice_list_public($pdoForNotices, 5) : [];

?>

<?php require_once __DIR__ . '/includes/layout/header.php'; ?>
<div class="dash-overlay" aria-hidden="true"></div>
<?php require_once __DIR__ . '/includes/layout/sidebar.php'; ?>

<div class="dash-topbar dash-topbar-modern d-flex align-items-center justify-content-between flex-wrap gap-3">
    <div class="d-flex align-items-center gap-3">
        <div class="dash-mobile-toggle">
            <button class="btn btn-sm btn-icon-modern" type="button" onclick="toggleDashboardSidebar()" aria-label="Menu">
                <i class="bi bi-list fs-5"></i>
            </button>
        </div>
        <div>
            <div class="dash-page-kicker"><?php echo e($hostelName); ?></div>
            <div class="title mb-0"><?php echo e($pageTitle); ?></div>
            <div class="dash-topbar-meta">Resident grievances · room-move workflow</div>
        </div>
    </div>
    <span class="dash-topbar-chip"><i class="bi bi-building-check me-2"></i>Assigned block</span>
</div>

<div class="dash-content dash-content-modern">
    <?php if ($errors): ?>
        <div class="alert alert-danger border-0 rounded-4 shadow-sm mb-4">
            <ul class="mb-0"><?php foreach ($errors as $error): ?><li class="fw-semibold"><?php echo e($error); ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>

    <?php if ($messages): ?>
        <div class="alert alert-success border-0 rounded-4 shadow-sm mb-4">
            <ul class="mb-0"><?php foreach ($messages as $message): ?><li class="fw-semibold"><?php echo e($message); ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>

    <div class="dash-info-callout mb-4">
        You only manage <strong>students allocated</strong> to <strong><?php echo e($hostelName); ?></strong>. Use
        <a href="<?php echo e($b . '/warden/students.php'); ?>" class="link-success fw-bold">Students (My Hostel)</a> for the roster.
    </div>

    <div class="dash-table dash-table-modern rounded-4 overflow-hidden mb-4" id="notices">
        <div class="p-4 border-bottom dash-table-head">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                <div>
                    <div class="dash-table-title">Hostel notices</div>
                    <div class="dash-table-subtitle">Published announcements from administration</div>
                </div>
                <a href="<?php echo e($b . '/notices.php'); ?>" class="student-dash-link-all fw-bold">View all <i class="bi bi-chevron-right small"></i></a>
            </div>
        </div>
        <div class="p-4">
            <?php
            $noticeBoardLayout = 'compact';
            $noticeBoardViewAll = '';
            require __DIR__ . '/includes/partials/notice_board.php';
            ?>
        </div>
    </div>

    <div class="dash-section-label">Hostel KPIs</div>
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="dash-stat-modern dash-stat-warden">
                <div class="dash-stat-icon"><i class="bi bi-chat-dots-fill"></i></div>
                <div class="dash-stat-num"><?php echo e((string)$kpis['open_complaints']); ?></div>
                <div class="dash-stat-label">Open complaints</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="dash-stat-modern dash-stat-warden">
                <div class="dash-stat-icon"><i class="bi bi-arrow-left-right"></i></div>
                <div class="dash-stat-num"><?php echo e((string)$kpis['pending_changes']); ?></div>
                <div class="dash-stat-label">Pending room changes</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="dash-stat-modern dash-stat-warden">
                <div class="dash-stat-icon"><i class="bi bi-people-fill"></i></div>
                <div class="dash-stat-num"><?php echo e((string)$kpis['hostel_students']); ?></div>
                <div class="dash-stat-label">Residents with rooms</div>
            </div>
        </div>
    </div>

    <div class="dash-section-label">Operational queues</div>
    <div class="dash-table dash-table-modern rounded-4 overflow-hidden mb-4">
        <div class="p-4 border-bottom dash-table-head">
            <div class="dash-table-title">Latest complaints</div>
            <div class="dash-table-subtitle">Residents linked to beds inside your hostel</div>
        </div>
        <div class="table-responsive">
            <table class="table dash-table-rows align-middle mb-0">
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Department</th>
                        <th>Title</th>
                        <th>Status</th>
                        <th>Update</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$complaints): ?>
                    <tr>
                        <td colspan="5" class="text-center py-5 text-muted fw-semibold">No complaints in scope.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($complaints as $item): ?>
                        <tr>
                            <td class="fw-bold"><?php echo e((string)$item['reg_no']); ?></td>
                            <td class="small text-muted"><?php echo e((string)$item['department']); ?></td>
                            <td><?php echo e((string)$item['title']); ?></td>
                            <td><span class="badge badge-pending text-uppercase"><?php echo e((string)$item['status']); ?></span></td>
                            <td>
                                <form method="post" class="d-flex flex-wrap gap-2 align-items-center">
                                    <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                                    <input type="hidden" name="action" value="update_complaint">
                                    <input type="hidden" name="id" value="<?php echo e((string)$item['id']); ?>">
                                    <select name="status" class="form-select form-select-sm rounded-3" style="width:auto;min-width:130px;">
                                        <?php foreach (['open', 'in_progress', 'resolved', 'closed'] as $st): ?>
                                            <option value="<?php echo e($st); ?>" <?php echo ((string)$item['status'] === $st) ? 'selected' : ''; ?>>
                                                <?php echo e($st); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="btn btn-sm dash-btn-primary rounded-pill px-3">Save</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="dash-table dash-table-modern rounded-4 overflow-hidden">
        <div class="p-4 border-bottom dash-table-head">
            <div class="dash-table-title">Room change requests</div>
            <div class="dash-table-subtitle">Approvals originate from hostel occupant workflows</div>
        </div>
        <div class="table-responsive">
            <table class="table dash-table-rows align-middle mb-0">
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Department</th>
                        <th>From</th>
                        <th>To</th>
                        <th>Status</th>
                        <th>Update</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$roomChanges): ?>
                    <tr>
                        <td colspan="6" class="text-center py-5 text-muted fw-semibold">No room change requests in scope.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($roomChanges as $item): ?>
                        <tr>
                            <td class="fw-bold"><?php echo e((string)$item['reg_no']); ?></td>
                            <td class="small text-muted"><?php echo e((string)$item['department']); ?></td>
                            <td><?php echo e((string)($item['from_room'] ?? '-')); ?></td>
                            <td><?php echo e((string)($item['to_room'] ?? '-')); ?></td>
                            <td><span class="badge badge-pending text-uppercase"><?php echo e((string)$item['status']); ?></span></td>
                            <td>
                                <form method="post" class="d-flex flex-wrap gap-2 align-items-center">
                                    <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                                    <input type="hidden" name="action" value="update_room_change">
                                    <input type="hidden" name="id" value="<?php echo e((string)$item['id']); ?>">
                                    <select name="status" class="form-select form-select-sm rounded-3" style="width:auto;min-width:120px;">
                                        <?php foreach (['pending', 'approved', 'rejected'] as $st): ?>
                                            <option value="<?php echo e($st); ?>" <?php echo ((string)$item['status'] === $st) ? 'selected' : ''; ?>>
                                                <?php echo e($st); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="btn btn-sm dash-btn-primary rounded-pill px-3">Save</button>
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

<?php require_once __DIR__ . '/includes/layout/footer.php'; ?>
