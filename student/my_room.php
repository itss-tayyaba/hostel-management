<?php
declare(strict_types=1);
/**
 * @file student/my_room.php
 * @brief My allotment — Shows approved room_allocation status
 * @access student
 * @database room_allocation
 */

require_once __DIR__ . '/../includes/app.php';
require_role('student');

require_once __DIR__ . '/../includes/models/student_model.php';

$userName = (string)($_SESSION['name'] ?? 'Student');
$userRole = 'student';
$pageTitle = 'Allocated Room';
$activePage = 'student_my_room';

$pdo = db();
$student = student_get_by_user_id($pdo, (int)$_SESSION['user_id']);

$allocatedRoom = null;
$applicationStatuses = [];
$errors = [];
if (!$student) {
    $errors[] = 'Student profile not found. Please contact admin.';
} else {
    $allocatedRoom = student_get_latest_approved_allocation($pdo, (int)$student['id']);
    $applicationStatuses = student_get_application_statuses($pdo, (int)$student['id'], 5);
}

function status_badge(string $status): string
{
    $s = strtolower($status);
    $cls = 'badge';
    if ($s === 'pending') $cls .= ' badge-pending';
    elseif ($s === 'approved') $cls .= ' badge-approved';
    elseif ($s === 'rejected') $cls .= ' badge-rejected';
    return $cls;
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
        Latest approved allocation
    </div>
</div>

<div class="dash-content">
    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
            <ul class="mb-0">
                <?php foreach ($errors as $err): ?>
                    <li><?php echo e($err); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="row g-3">
        <div class="col-lg-6">
            <div class="dash-card">
                <div style="font-weight:900;color:var(--dark);margin-bottom:8px;">Your Room</div>
                <?php if ($allocatedRoom): ?>
                    <div style="color:var(--text-muted);font-weight:800;font-size:14px;line-height:1.7;">
                        Hostel: <span style="font-weight:900;color:var(--dark);"><?php echo e((string)$allocatedRoom['hostel_name']); ?></span><br/>
                        Room: <span style="font-weight:900;color:var(--dark);"><?php echo e((string)$allocatedRoom['room_number']); ?></span><br/>
                        <?php if (!empty($allocatedRoom['bed_code'])): ?>
                            Bed: <span style="font-weight:900;color:var(--dark);"><?php echo e((string)$allocatedRoom['bed_code']); ?></span><br/>
                        <?php endif; ?>
                        Status: <span class="<?php echo e(status_badge((string)$allocatedRoom['status'])); ?>"><?php echo e((string)$allocatedRoom['status']); ?></span>
                    </div>
                <?php else: ?>
                    <div style="color:var(--text-muted);font-weight:800;font-size:14px;line-height:1.6;">
                        No approved room allocation yet. Apply for a hostel room to get started.
                    </div>
                    <div class="mt-3">
                        <a href="<?php echo e(base_path() . '/student/apply.php'); ?>" class="btn btn-primary w-100" style="background:var(--dark); border:none;">
                            Apply Now
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="dash-card">
                <div style="font-weight:900;color:var(--dark);margin-bottom:10px;">Recent Applications</div>
                <div class="table-responsive">
                    <table class="table table-borderless align-middle">
                        <thead>
                        <tr>
                            <th>Room</th>
                            <th>Status</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($applicationStatuses)): ?>
                            <tr>
                                <td colspan="2" style="color:var(--text-muted);font-weight:800;">No application history yet.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($applicationStatuses as $row): ?>
                                <?php $status = (string)$row['status']; ?>
                                <tr>
                                    <td style="font-weight:900;"><?php echo e((string)$row['room_number']); ?></td>
                                    <td style="text-align:right;">
                                        <span class="<?php echo e(status_badge($status)); ?>"><?php echo e($status); ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="mt-3 d-grid gap-2">
                    <a href="<?php echo e(base_path() . '/student/allotment_slip.php'); ?>" target="_blank"
                       class="btn btn-success fw-bold">📄 Download Allotment Slip</a>
                    <a href="<?php echo e(base_path() . '/student/hostel_card.php'); ?>" target="_blank"
                       class="btn btn-outline-success fw-bold">🪪 Print Hostel ID Card</a>
                    <a href="<?php echo e(base_path() . '/student/complaints.php'); ?>" class="btn btn-outline w-100"
                       style="border-color:rgba(27,94,32,0.22); color:var(--dark); background:#fff;">
                        Submit Complaint
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/layout/footer.php'; ?>

