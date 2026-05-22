<?php
declare(strict_types=1);
/**
 * @file student/complaints.php
 * @brief Student complaints — Create and escalate complaints
 * @access student
 * @database complaints
 */

require_once __DIR__ . '/../includes/app.php';
require_role('student');

require_once __DIR__ . '/../includes/models/student_model.php';
require_once __DIR__ . '/../includes/models/complaint_model.php';

$userName = (string)($_SESSION['name'] ?? 'Student');
$userRole = 'student';
$pageTitle = 'Submit / View Complaints';
$activePage = 'student_complaints';

$pdo = db();
$student = student_get_by_user_id($pdo, (int)$_SESSION['user_id']);

$errors = [];
$sent = false;
$allocatedRoom = null;
$complaints = [];

if (!$student) {
    $errors[] = 'Student profile not found. Please contact admin.';
} else {
    $allocatedRoom = student_get_latest_approved_allocation($pdo, (int)$student['id']);
    $complaints = complaint_list_by_student($pdo, (int)$student['id'], 50);
    $activeComplaints = array_values(array_filter($complaints, fn($c) => in_array((string)$c['status'], ['open','in_progress'], true)));
    $historyComplaints = array_values(array_filter($complaints, fn($c) => in_array((string)$c['status'], ['resolved','closed'], true)));

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? null;
        if (!csrf_validate(is_string($token) ? $token : null)) {
            $errors[] = 'Invalid CSRF token. Please try again.';
        } else {
            $action = (string)($_POST['action'] ?? 'create');
            if ($action === 'create') {
                $title = trim((string)($_POST['title'] ?? ''));
                $description = trim((string)($_POST['description'] ?? ''));

                if (mb_strlen($title) < 3) $errors[] = 'Complaint title must be at least 3 characters.';
                if (mb_strlen($description) < 10) $errors[] = 'Complaint description must be at least 10 characters.';

                if (empty($errors)) {
                    $hostelId = null;
                    $roomId = null;
                    if ($allocatedRoom) {
                        $hostelId = (int)($allocatedRoom['hostel_id'] ?? 0);
                        $roomId = (int)($allocatedRoom['room_id'] ?? 0);
                    }

                    if (complaint_create($pdo, (int)$student['id'], $hostelId ?: null, $roomId ?: null, $title, $description)) {
                        $sent = true;
                        // We are already inside /student/, so don't redirect to /student/student/
                        redirect('complaints.php?sent=1');
                    }
                    $errors[] = 'Failed to submit complaint. Please try again.';
                }
            } elseif ($action === 'escalate') {
                $complaintId = (int)($_POST['complaint_id'] ?? 0);
                if ($complaintId <= 0) {
                    $errors[] = 'Invalid complaint.';
                } else {
                    if (complaint_escalate_to_admin($pdo, (int)$student['id'], $complaintId)) {
                        redirect('complaints.php?escalated=1');
                    }
                    $errors[] = 'Could not escalate complaint to admin.';
                }
            } else {
                $errors[] = 'Invalid request.';
            }
        }
    }
}

function complaint_badge(string $status): string
{
    $s = strtolower($status);
    $cls = 'badge';
    if ($s === 'open' || $s === 'in_progress') $cls .= ' badge-pending';
    elseif ($s === 'resolved') $cls .= ' badge-approved';
    elseif ($s === 'closed') $cls .= ' badge-rejected';
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
        Report maintenance / issues
    </div>
</div>

<div class="dash-content">
    <?php if (isset($_GET['sent']) && $_GET['sent'] === '1'): ?>
        <div class="alert alert-success">
            Complaint submitted successfully. You can track the status below.
        </div>
    <?php endif; ?>
    <?php if (isset($_GET['escalated']) && $_GET['escalated'] === '1'): ?>
        <div class="alert alert-success">
            Complaint forwarded to admin. You can still track updates below.
        </div>
    <?php endif; ?>

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
        <div class="col-lg-5">
            <div class="dash-card">
                <div style="font-weight:900;color:var(--dark);margin-bottom:8px;">Submit Complaint</div>
                <div style="color:var(--text-muted);font-weight:800;font-size:13px;margin-bottom:16px;line-height:1.6;">
                    <?php if ($allocatedRoom): ?>
                        Automatically attached to your room: <strong><?php echo e((string)$allocatedRoom['room_number']); ?></strong>
                    <?php else: ?>
                        Not allocated to a room yet. Your complaint will be saved without room details.
                    <?php endif; ?>
                </div>

                <form method="post" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>" />
                    <input type="hidden" name="action" value="create" />

                    <div class="mb-3">
                        <label class="form-label fw-bold">Title</label>
                        <input type="text" name="title" class="form-control" required
                               placeholder="e.g., Water leakage in washroom" />
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Description</label>
                        <textarea name="description" class="form-control" rows="5" required
                                  placeholder="Describe the issue clearly..."></textarea>
                    </div>

                    <button type="submit" class="btn btn-primary w-100" style="background:var(--dark); border:none;">
                        Submit Complaint
                    </button>
                </form>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="dash-card">
                <div style="font-weight:900;color:var(--dark);margin-bottom:10px;">Active Complaints</div>
                <div class="table-responsive">
                    <table class="table table-borderless align-middle">
                        <thead>
                        <tr>
                            <th>Title</th>
                            <th>Status</th>
                            <th>Date</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($activeComplaints)): ?>
                            <tr>
                                <td colspan="3" style="color:var(--text-muted);font-weight:800;">No complaints yet.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($activeComplaints as $c): ?>
                                <tr>
                                    <td style="font-weight:900;"><?php echo e((string)$c['title']); ?></td>
                                    <td style="text-align:right;">
                                        <span class="<?php echo e(complaint_badge((string)$c['status'])); ?>">
                                            <?php echo e((string)$c['status']); ?>
                                        </span>
                                    </td>
                                    <td style="color:var(--text-muted);font-weight:800;font-size:13px;"><?php echo e((string)$c['created_at']); ?></td>
                                </tr>
                                <tr>
                                    <td colspan="3" style="color:var(--text-muted);font-weight:800;font-size:13px;line-height:1.6;">
                                        <?php echo e((string)$c['description']); ?>
                                        <div class="mt-2 d-flex justify-content-end">
                                            <form method="post" onsubmit="return confirm('Forward this complaint to admin?');">
                                                <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>" />
                                                <input type="hidden" name="action" value="escalate" />
                                                <input type="hidden" name="complaint_id" value="<?php echo e((string)(int)$c['id']); ?>" />
                                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                                    Submit to Admin
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mt-1">
        <div class="col-12">
            <div class="dash-card">
                <div style="font-weight:900;color:var(--dark);margin-bottom:10px;">Complaint History (Resolved)</div>
                <div class="table-responsive">
                    <table class="table table-borderless align-middle">
                        <thead>
                        <tr>
                            <th>Title</th>
                            <th>Status</th>
                            <th>Date</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($historyComplaints)): ?>
                            <tr>
                                <td colspan="3" style="color:var(--text-muted);font-weight:800;">No resolved complaints yet.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($historyComplaints as $c): ?>
                                <tr>
                                    <td style="font-weight:900;"><?php echo e((string)$c['title']); ?></td>
                                    <td style="text-align:right;">
                                        <span class="<?php echo e(complaint_badge((string)$c['status'])); ?>">
                                            <?php echo e((string)$c['status']); ?>
                                        </span>
                                    </td>
                                    <td style="color:var(--text-muted);font-weight:800;font-size:13px;"><?php echo e((string)$c['created_at']); ?></td>
                                </tr>
                                <tr>
                                    <td colspan="3" style="color:var(--text-muted);font-weight:800;font-size:13px;line-height:1.6;">
                                        <?php echo e((string)$c['description']); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/layout/footer.php'; ?>

