<?php
declare(strict_types=1);
/**
 * @file student/apply.php
 * @brief Hostel application — Submit room_allocation request
 * @access student
 * @database room_allocation
 */

require_once __DIR__ . '/../includes/app.php';
require_role('student');

require_once __DIR__ . '/../includes/models/student_model.php';

$userName = (string)($_SESSION['name'] ?? 'Student');
$userRole = 'student';
$pageTitle = 'Apply for Hostel';
$activePage = 'student_apply';

$errors = [];
$success = false;
$pdo = db();

$student = student_get_by_user_id($pdo, (int)$_SESSION['user_id']);

if (!$student) {
    $errors[] = 'Student profile not found. Please contact admin.';
    $hostels = [];
    $rooms = [];
    $applicationStatuses = [];
    $selectedHostelId = 0;
    $selectedCapacity = 1;
    $studentGender = '';
} else {
    $studentGender = student_resolve_gender($student);
    $hostels = student_get_hostels($pdo, $studentGender);
    $allowedHostelIds = array_map(static fn(array $h): int => (int)$h['id'], $hostels);

    $selectedHostelId = (int)($_GET['hostel_id'] ?? 0);
    $selectedCapacity = (int)($_GET['capacity'] ?? 1);
    if ($selectedHostelId <= 0 && !empty($hostels)) {
        $selectedHostelId = (int)$hostels[0]['id'];
    } elseif ($selectedHostelId > 0 && !in_array($selectedHostelId, $allowedHostelIds, true)) {
        $selectedHostelId = !empty($hostels) ? (int)$hostels[0]['id'] : 0;
    }
    if ($selectedCapacity < 1 || $selectedCapacity > 3) {
        $selectedCapacity = 1;
    }

    $rooms = student_get_available_rooms(
        $pdo,
        $selectedHostelId > 0 ? $selectedHostelId : null,
        $selectedCapacity,
        $studentGender
    );
    $applicationStatuses = student_get_application_statuses($pdo, (int)$student['id'], 10);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($errors)) {
    $token = $_POST['csrf_token'] ?? null;
    if (!csrf_validate(is_string($token) ? $token : null)) {
        $errors[] = 'Invalid CSRF token. Please try again.';
    } else {
        $roomId = (int)($_POST['room_id'] ?? 0);
        $hostelId = (int)($_POST['hostel_id'] ?? 0);
        $capacity = (int)($_POST['capacity'] ?? 0);

        if ($roomId <= 0) $errors[] = 'Please select a room.';
        if ($hostelId <= 0) $errors[] = 'Please select a hostel.';
        if ($capacity < 1 || $capacity > 3) $errors[] = 'Invalid capacity selection.';

        if (empty($errors)) {
            $studentGender = student_resolve_gender($student);
            if (student_has_pending_allocation($pdo, (int)$student['id'])) {
                $errors[] = 'You already have a pending application. Please wait for admin response.';
            } elseif (!student_room_allowed_for_gender($pdo, $roomId, $hostelId, $capacity, $studentGender)) {
                $errors[] = $studentGender === 'female'
                    ? 'You can only apply to girls hostels.'
                    : 'You can only apply to boys hostels.';
            } else {
                if (student_create_pending_allocation($pdo, (int)$student['id'], $roomId)) {
                    redirect('student/apply.php?submitted=1');
                }
                $errors[] = 'Failed to submit your application. Please try again.';
            }
        }
    }
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
        Choose preferences and submit
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

    <?php if (isset($_GET['submitted']) && $_GET['submitted'] === '1'): ?>
        <div class="alert alert-success">
            Application submitted successfully. Please wait for admin approval.
        </div>
    <?php endif; ?>

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="dash-card">
                <div style="font-weight:900;color:var(--dark);margin-bottom:6px;">Preferences & Application</div>
                <div style="color:var(--text-muted);font-weight:800;font-size:13px;margin-bottom:16px;">
                    <?php if (!empty($student) && isset($studentGender)): ?>
                        Showing <?php echo $studentGender === 'female' ? 'girls' : 'boys'; ?> hostels only
                        (based on your profile gender).
                    <?php else: ?>
                        Select a hostel, choose room capacity, then apply for an available room.
                    <?php endif; ?>
                </div>

                <form method="get" class="mb-3">
                    <div class="row g-2 align-items-end">
                        <div class="col-md-5">
                            <label class="form-label fw-bold">Hostel</label>
                            <select class="form-select" name="hostel_id" onchange="this.form.submit()">
                                <?php foreach ($hostels as $h): ?>
                                    <option value="<?php echo (int)$h['id']; ?>" <?php echo ((int)$h['id'] === $selectedHostelId) ? 'selected' : ''; ?>>
                                        <?php echo e((string)$h['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label fw-bold">Capacity</label>
                            <select class="form-select" name="capacity" onchange="this.form.submit()">
                                <?php for ($cap=1; $cap<=3; $cap++): ?>
                                    <option value="<?php echo $cap; ?>" <?php echo ($cap === $selectedCapacity) ? 'selected' : ''; ?>>
                                        <?php echo $cap; ?>-Sharing
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <div style="color:var(--text-muted);font-weight:800;font-size:13px;">
                                Showing rooms with <strong><?php echo smart_allocation_tables_ready($pdo) ? 'spare beds' : 'legacy vacant flag'; ?></strong>
                            </div>
                        </div>
                    </div>
                </form>

                <?php if (!empty($rooms)): ?>
                    <form method="post" novalidate>
                        <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>" />
                        <input type="hidden" name="hostel_id" value="<?php echo (int)$selectedHostelId; ?>" />
                        <input type="hidden" name="capacity" value="<?php echo (int)$selectedCapacity; ?>" />

                        <div class="mb-3">
                            <label class="form-label fw-bold">Available Rooms</label>
                            <select class="form-select" name="room_id" required>
                                <?php foreach ($rooms as $room): ?>
                                    <option value="<?php echo (int)$room['id']; ?>">
                                        <?php echo e((string)$room['room_number']); ?> (Cap: <?php echo (int)$room['capacity']; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <button type="submit" class="btn btn-primary w-100" style="background:var(--dark); border:none;">
                            Submit Application
                        </button>
                    </form>
                <?php else: ?>
                    <div class="dash-card" style="background:rgba(76,175,80,0.06); border:1px dashed rgba(27,94,32,0.18);">
                        <div style="font-weight:900;color:var(--dark);margin-bottom:6px;">No rooms available</div>
                        <div style="color:var(--text-muted);font-weight:800;font-size:13px;line-height:1.6;">
                            No free rooms match the selected hostel and capacity right now.
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="dash-card">
                <div style="font-weight:900;color:var(--dark);margin-bottom:10px;">Application Status</div>

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
                                <td colspan="2" style="color:var(--text-muted);font-weight:800;">No applications yet.</td>
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

                <div class="mt-3">
                    <a href="<?php echo e(base_path() . '/student/my_room.php'); ?>" class="btn btn-outline w-100"
                       style="border-color:rgba(27,94,32,0.22); color:var(--dark); background:#fff;">
                        View Allocated Room
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/layout/footer.php'; ?>

