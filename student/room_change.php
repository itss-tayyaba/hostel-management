<?php
declare(strict_types=1);
/**
 * @file student/room_change.php
 * @brief Room change request — Ask to move between rooms
 * @access student
 * @database room_change_requests
 */

require_once __DIR__ . '/../includes/app.php';
require_role('student');

require_once __DIR__ . '/../includes/models/student_model.php';
require_once __DIR__ . '/../includes/models/room_change_model.php';

$userName = (string)($_SESSION['name'] ?? 'Student');
$userRole = 'student';
$pageTitle = 'Room Change Request';
$activePage = 'student_room_change';

$pdo = db();
$student = student_get_by_user_id($pdo, (int)$_SESSION['user_id']);

$errors = [];
$success = false;
$allocatedRoom = null;
$changeRequests = [];

$hostels = [];
$selectedHostelId = 0;
$selectedCapacity = 1;
$rooms = [];

if (!$student) {
    $errors[] = 'Student profile not found. Please contact admin.';
} else {
    $allocatedRoom = student_get_latest_approved_allocation($pdo, (int)$student['id']);
    $changeRequests = room_change_list($pdo, (int)$student['id'], 30);

    $studentGender = student_resolve_gender($student);
    $hostels = student_get_hostels($pdo, $studentGender);
    $allowedHostelIds = array_map(static fn(array $h): int => (int)$h['id'], $hostels);
    if (!empty($hostels)) {
        $selectedHostelId = (int)($_GET['hostel_id'] ?? 0);
        if ($selectedHostelId <= 0) {
            $preferred = (int)($allocatedRoom['hostel_id'] ?? 0);
            $selectedHostelId = in_array($preferred, $allowedHostelIds, true)
                ? $preferred
                : (int)$hostels[0]['id'];
        } elseif (!in_array($selectedHostelId, $allowedHostelIds, true)) {
            $selectedHostelId = (int)$hostels[0]['id'];
        }
    }
    $selectedCapacity = (int)($_GET['capacity'] ?? 1);
    if ($selectedCapacity < 1 || $selectedCapacity > 3) $selectedCapacity = 1;

    $rooms = student_get_available_rooms(
        $pdo,
        $selectedHostelId > 0 ? $selectedHostelId : null,
        $selectedCapacity,
        $studentGender
    );

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($errors)) {
        $token = $_POST['csrf_token'] ?? null;
        if (!csrf_validate(is_string($token) ? $token : null)) {
            $errors[] = 'Invalid CSRF token. Please try again.';
        } else {
            $reason = trim((string)($_POST['reason'] ?? ''));
            $toRoomId = (int)($_POST['to_room_id'] ?? 0);
            $toHostelId = (int)($_POST['to_hostel_id'] ?? 0);
            $toCapacity = (int)($_POST['to_capacity'] ?? 0);

            if (mb_strlen($reason) < 10) $errors[] = 'Reason must be at least 10 characters.';
            if ($toRoomId <= 0) $errors[] = 'Please select a destination room.';
            if ($toHostelId <= 0) $errors[] = 'Destination hostel is required.';
            if ($toCapacity < 1 || $toCapacity > 3) $errors[] = 'Invalid capacity selection.';

            if (empty($errors)) {
                if (!$allocatedRoom) {
                    $errors[] = 'You do not have an approved room right now.';
                } elseif (room_change_has_pending($pdo, (int)$student['id'])) {
                    $errors[] = 'You already have a pending room change request.';
                } else {
                    $studentGender = student_resolve_gender($student);
                    if (!student_room_allowed_for_gender($pdo, $toRoomId, $toHostelId, $toCapacity, $studentGender)) {
                        $errors[] = $studentGender === 'female'
                            ? 'You can only move to girls hostels.'
                            : 'You can only move to boys hostels.';
                    } else {
                        $fromRoomId = (int)($allocatedRoom['room_id'] ?? 0);
                        if ($fromRoomId <= 0) $errors[] = 'Unable to detect your current room.';
                        else {
                            if (room_change_create($pdo, (int)$student['id'], $fromRoomId, $toRoomId, $reason)) {
                                redirect('student/room_change.php?submitted=1');
                            }
                            $errors[] = 'Failed to submit room change request. Please try again.';
                        }
                    }
                }
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
        Request a room change
    </div>
</div>

<div class="dash-content">
    <?php if (isset($_GET['submitted']) && $_GET['submitted'] === '1'): ?>
        <div class="alert alert-success">
            Room change request submitted. Please wait for admin response.
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
        <div class="col-lg-6">
            <div class="dash-card">
                <div style="font-weight:900;color:var(--dark);margin-bottom:8px;">Change Request Form</div>

                <?php if (!$allocatedRoom): ?>
                    <div style="color:var(--text-muted);font-weight:800;font-size:14px;line-height:1.6;">
                        You don’t have an approved room yet. Request will be available after allocation.
                    </div>
                <?php else: ?>
                    <div style="color:var(--text-muted);font-weight:800;font-size:13px;margin-bottom:16px;line-height:1.6;">
                        Current room: <strong><?php echo e((string)$allocatedRoom['room_number']); ?></strong>
                        (<?php echo e((string)$allocatedRoom['hostel_name']); ?>)
                        <br />
                        Destination list shows <?php echo isset($studentGender) && $studentGender === 'female' ? 'girls' : 'boys'; ?> hostels only.
                    </div>

                    <form method="get" class="mb-3">
                        <div class="row g-2 align-items-end">
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Destination Hostel</label>
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
                            <div class="col-md-3">
                                <div style="color:var(--text-muted);font-weight:800;font-size:13px;">
                                    Free rooms only
                                </div>
                            </div>
                        </div>
                    </form>

                    <?php if (!empty($rooms)): ?>
                        <form method="post" novalidate>
                            <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>" />
                            <input type="hidden" name="to_hostel_id" value="<?php echo (int)$selectedHostelId; ?>" />
                            <input type="hidden" name="to_capacity" value="<?php echo (int)$selectedCapacity; ?>" />

                            <div class="mb-3">
                                <label class="form-label fw-bold">Destination Room</label>
                                <select class="form-select" name="to_room_id" required>
                                    <?php foreach ($rooms as $r): ?>
                                        <option value="<?php echo (int)$r['id']; ?>">
                                            <?php echo e((string)$r['room_number']); ?> (Cap: <?php echo (int)$r['capacity']; ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-bold">Reason</label>
                                <textarea name="reason" class="form-control" rows="4" required
                                          placeholder="Explain why you want to change your room..."></textarea>
                            </div>

                            <button type="submit" class="btn btn-primary w-100" style="background:var(--dark); border:none;">
                                Submit Request
                            </button>
                        </form>
                    <?php else: ?>
                        <div class="dash-card" style="background:rgba(76,175,80,0.06); border:1px dashed rgba(27,94,32,0.18);">
                            <div style="font-weight:900;color:var(--dark);margin-bottom:6px;">No destination rooms</div>
                            <div style="color:var(--text-muted);font-weight:800;font-size:13px;line-height:1.6;">
                                No free rooms match your selected hostel and capacity.
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="dash-card">
                <div style="font-weight:900;color:var(--dark);margin-bottom:10px;">Your Change Requests</div>

                <div class="table-responsive">
                    <table class="table table-borderless align-middle">
                        <thead>
                        <tr>
                            <th>From</th>
                            <th>To</th>
                            <th>Status</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($changeRequests)): ?>
                            <tr>
                                <td colspan="3" style="color:var(--text-muted);font-weight:800;">No change requests yet.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($changeRequests as $req): ?>
                                <tr>
                                    <td style="font-weight:900;"><?php echo e((string)($req['from_room_number'] ?? '—')); ?></td>
                                    <td style="font-weight:900;"><?php echo e((string)($req['to_room_number'] ?? '—')); ?></td>
                                    <td style="text-align:right;">
                                        <span class="<?php echo e(status_badge((string)$req['status'])); ?>">
                                            <?php echo e((string)$req['status']); ?>
                                        </span>
                                    </td>
                                </tr>
                                <tr>
                                    <td colspan="3" style="color:var(--text-muted);font-weight:800;font-size:13px;line-height:1.6;">
                                        <?php echo e((string)$req['reason']); ?>
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

