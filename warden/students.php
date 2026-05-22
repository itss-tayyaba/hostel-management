<?php
declare(strict_types=1);
/**
 * @file warden/students.php
 * @brief Warden student list — Residents in assigned hostel only
 * @access warden
 * @database room_allocation
 */

require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/../includes/warden_scope.php';
require_allocated_warden();

$userName = (string)($_SESSION['name'] ?? 'Warden');
$userRole = (string)($_SESSION['role'] ?? 'warden');
$pageTitle = 'Students · My hostel';
$activePage = 'warden_students';
$hostelId = (int)($_SESSION['warden_hostel_id'] ?? 0);
$hostelName = (string)($_SESSION['warden_hostel_name'] ?? '');

$search = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$params = [':hid' => $hostelId];
$sqlWhereExtra = '';

if ($search !== '') {
    $sqlWhereExtra = ' AND (u.name LIKE :s OR u.email LIKE :s2 OR s.reg_no LIKE :s3 OR s.department LIKE :s4) ';
    $like = '%' . $search . '%';
    $params[':s'] = $like;
    $params[':s2'] = $like;
    $params[':s3'] = $like;
    $params[':s4'] = $like;
}

$rows = [];
try {
    $pdo = db();
    $sql = '
        SELECT s.id AS student_pk, u.name, u.email, s.reg_no, s.department, s.gender,
               r.room_number, h.name AS hostel_name,
               COALESCE(ra.responded_at, ra.applied_at) AS last_alloc_at
        FROM students s
        INNER JOIN users u ON u.id = s.user_id
        INNER JOIN room_allocation ra ON ra.student_id = s.id AND ra.status = "approved"
        INNER JOIN rooms r ON r.id = ra.room_id AND r.hostel_id = :hid
        INNER JOIN hostels h ON h.id = r.hostel_id
        WHERE ra.id = (
          SELECT MAX(ra2.id) FROM room_allocation ra2
          INNER JOIN rooms r2 ON r2.id = ra2.room_id
          WHERE ra2.student_id = s.id
            AND ra2.status = "approved"
            AND r2.hostel_id = :hid2
        )
        ' . $sqlWhereExtra . '
        ORDER BY r.room_number ASC, u.name ASC
    ';
    $params[':hid2'] = $hostelId;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
} catch (Throwable $e) {
    $rows = [];
}
?>

<?php require_once __DIR__ . '/../includes/layout/header.php'; ?>
<div class="dash-overlay" aria-hidden="true"></div>
<?php require_once __DIR__ . '/../includes/layout/sidebar.php'; ?>

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
            <div class="dash-topbar-meta">Search & inspect residents tied to beds you supervise</div>
        </div>
    </div>
    <span class="dash-topbar-chip"><i class="bi bi-people me-2"></i>Roster</span>
</div>

<div class="dash-content dash-content-modern">
    <div class="dash-card dash-card-glass mb-4">
        <div style="font-weight:900;color:var(--dark);margin-bottom:6px;">
            Resident directory (allocation-based)
        </div>
        <p class="mb-3" style="font-size:13px;color:var(--text-muted);font-weight:700;line-height:1.5;">
            Only listings with approved rooms located inside <?php echo e($hostelName); ?> belong to your portfolio.
            Other hostels—or students awaiting approval—appear only to campus administration.
        </p>
        <form method="get" class="row g-2 align-items-end flex-wrap">
            <div class="col-md-6">
                <label class="form-label fw-bold" style="font-size:13px;">Search students</label>
                <input type="search" name="q" value="<?php echo e($search); ?>" class="form-control"
                       placeholder="Name, email, registration # or department"/>
            </div>
            <div class="col-md-4">
                <button type="submit" class="btn btn-primary mt-4 mt-md-0" style="background:var(--dark);border:none;font-weight:800;">
                    Apply filter
                </button>
                <?php if ($search !== ''): ?>
                    <a href="<?php echo e(base_path() . '/warden/students.php'); ?>" class="btn btn-outline-secondary ms-2">Clear</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div class="dash-table dash-table-modern rounded-4 overflow-hidden">
        <div class="p-4 border-bottom dash-table-head">
            <div class="dash-table-title"><?php echo e((string)count($rows)); ?> matched residents</div>
            <div class="dash-table-subtitle">Approved allocations only • latest room per learner</div>
        </div>
        <div class="table-responsive">
            <table class="table dash-table-rows align-middle mb-0">
                <thead>
                <tr>
                    <th>Student</th>
                    <th>Email</th>
                    <th>Reg no</th>
                    <th>Department</th>
                    <th>Gender</th>
                    <th>Room</th>
                    <th>Last allotment event</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr>
                        <td colspan="7" class="text-center" style="color:var(--text-muted);font-weight:800;">
                            No matched residents allocated to this hostel<?php echo $search !== '' ? ' for this query' : ''; ?>.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td style="font-weight:900;"><?php echo e((string)$row['name']); ?></td>
                            <td><?php echo e((string)$row['email']); ?></td>
                            <td><?php echo e((string)$row['reg_no']); ?></td>
                            <td><?php echo e((string)$row['department']); ?></td>
                            <td><?php echo e(ucfirst((string)($row['gender'] ?? 'male'))); ?></td>
                            <td><span class="badge badge-approved"><?php echo e((string)$row['room_number']); ?></span></td>
                            <td style="font-size:12px;color:var(--text-muted);"><?php echo e((string)$row['last_alloc_at']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/layout/footer.php'; ?>
