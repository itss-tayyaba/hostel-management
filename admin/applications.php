<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/../includes/services/UafAllocationEngine.php';
require_role('admin');

$pdo = db();
$userName = (string)($_SESSION['name'] ?? 'Admin');
$userRole = (string)($_SESSION['role'] ?? 'admin');
$pageTitle = 'Room Applications';
$activePage = 'admin_applications';

$errors = [];
$messages = [];
$engine = new UafAllocationEngine($pdo);
$roomDebug = [
    'total_rooms' => 0,
    'available_rooms' => 0,
    'by_gender' => ['Male' => 0, 'Female' => 0, 'Other' => 0],
    'by_room_type' => ['General' => 0, 'PhD' => 0, 'International' => 0, 'Other' => 0],
    'null_faculty_count' => 0,
];
try {
    $roomDebug = $engine->debugRoomCount();
} catch (Throwable $e) {
}
$statusFilter = (string)($_GET['status'] ?? 'pending');
if (!in_array($statusFilter, ['pending', 'allocated', 'waitlisted', 'ineligible'], true)) {
    $statusFilter = 'pending';
}

function admin_applications_has_column(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table_name
           AND COLUMN_NAME = :column_name
         LIMIT 1'
    );
    $stmt->execute([':table_name' => $table, ':column_name' => $column]);
    return (bool)$stmt->fetchColumn();
}

/**
 * @param array<string,mixed> $student
 */
function admin_resolve_room_type(array $student): string
{
    $isInternational = !empty($student['is_international']);
    if ($isInternational) {
        return 'International';
    }
    $degree = (string)($student['degree_level'] ?? '');
    return strcasecmp($degree, 'PhD') === 0 ? 'PhD' : 'General';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? null;
    if (!csrf_validate(is_string($token) ? $token : null)) {
        $errors[] = 'Invalid CSRF token.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        $applicationId = (int)($_POST['application_id'] ?? 0);

        if ($applicationId <= 0) {
            $errors[] = 'Invalid application selected.';
        } elseif ($action === 'recheck_eligibility') {
            try {
                $stmt = $pdo->prepare(
                    'UPDATE student_applications
                     SET allocation_status = \'pending\', updated_at = CURRENT_TIMESTAMP
                     WHERE id = :id AND allocation_status = \'ineligible\''
                );
                $stmt->execute([':id' => $applicationId]);
                if ($stmt->rowCount() > 0) {
                    $messages[] = 'Student moved back to pending for eligibility re-check.';
                } else {
                    $errors[] = 'This student is not currently marked ineligible.';
                }
            } catch (Throwable $e) {
                $errors[] = 'Could not re-check eligibility.';
            }
        } elseif ($action === 'mark_waitlisted') {
            try {
                $stmt = $pdo->prepare(
                    'UPDATE student_applications
                     SET allocation_status = \'waitlisted\', updated_at = CURRENT_TIMESTAMP
                     WHERE id = :id'
                );
                $stmt->execute([':id' => $applicationId]);
                $messages[] = 'Application moved to waitlist.';
            } catch (Throwable $e) {
                $errors[] = 'Could not update application status.';
            }
        } elseif ($action === 'approve_allocate') {
            try {
                $pdo->beginTransaction();

                $studentStmt = $pdo->prepare(
                    'SELECT *
                     FROM student_applications
                     WHERE id = :id AND allocation_status = \'pending\'
                     FOR UPDATE'
                );
                $studentStmt->execute([':id' => $applicationId]);
                $student = $studentStmt->fetch(PDO::FETCH_ASSOC);
                if (!$student) {
                    $pdo->rollBack();
                    $errors[] = 'Application is already processed.';
                } else {
                    $roomType = admin_resolve_room_type($student);
                    $roomStmt = $pdo->prepare(
                        'SELECT id
                         FROM uaf_hostel_rooms
                         WHERE faculty_affinity = :faculty
                           AND gender_restriction = :gender
                           AND room_type = :room_type
                           AND current_occupancy < capacity
                         ORDER BY id ASC
                         LIMIT 1
                         FOR UPDATE'
                    );
                    $roomStmt->execute([
                        ':faculty' => (string)$student['faculty'],
                        ':gender' => (string)$student['gender'],
                        ':room_type' => $roomType,
                    ]);
                    $room = $roomStmt->fetch(PDO::FETCH_ASSOC);

                    if ($room) {
                        $score = $engine->calculatePriorityScore($student);

                        $insertAllot = $pdo->prepare(
                            'INSERT INTO uaf_allotments (student_application_id, room_id, priority_score, notes)
                             VALUES (:sid, :room_id, :score, :notes)
                             ON DUPLICATE KEY UPDATE
                                room_id = VALUES(room_id),
                                priority_score = VALUES(priority_score),
                                allocated_at = CURRENT_TIMESTAMP,
                                notes = VALUES(notes)'
                        );
                        $insertAllot->execute([
                            ':sid' => (int)$student['id'],
                            ':room_id' => (int)$room['id'],
                            ':score' => round($score, 2),
                            ':notes' => 'Manual admin approval',
                        ]);

                        $occStmt = $pdo->prepare(
                            'UPDATE uaf_hostel_rooms
                             SET current_occupancy = current_occupancy + 1
                             WHERE id = :id AND current_occupancy < capacity'
                        );
                        $occStmt->execute([':id' => (int)$room['id']]);
                        if ($occStmt->rowCount() !== 1) {
                            throw new RuntimeException('Room occupancy race detected.');
                        }

                        $statusStmt = $pdo->prepare(
                            'UPDATE student_applications
                             SET allocation_status = \'allocated\', updated_at = CURRENT_TIMESTAMP
                             WHERE id = :id'
                        );
                        $statusStmt->execute([':id' => (int)$student['id']]);

                        $pdo->commit();
                        $messages[] = 'Application approved and room allocated.';
                    } else {
                        $waitStmt = $pdo->prepare(
                            'UPDATE student_applications
                             SET allocation_status = \'waitlisted\', updated_at = CURRENT_TIMESTAMP
                             WHERE id = :id'
                        );
                        $waitStmt->execute([':id' => (int)$student['id']]);
                        $pdo->commit();
                        $messages[] = 'No matching room found. Student moved to waitlist.';
                    }
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = 'Approval failed. Please retry.';
            }
        }
    }
}

try {
    $counts = [
        'pending' => 0,
        'allocated' => 0,
        'waitlisted' => 0,
        'ineligible' => 0,
    ];
    $countRows = $pdo->query(
        'SELECT allocation_status, COUNT(*) AS total
         FROM student_applications
         GROUP BY allocation_status'
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($countRows as $row) {
        $key = (string)($row['allocation_status'] ?? '');
        if (isset($counts[$key])) {
            $counts[$key] = (int)$row['total'];
        }
    }

    $selectColumns = [
        'id',
        'student_name',
        'gender',
        'faculty',
        'degree_level',
        'year_of_study',
        'cgpa',
        'is_international',
        'has_unpaid_dues',
        'past_violations_count',
        'created_at',
    ];
    if (admin_applications_has_column($pdo, 'student_applications', 'is_uaf_student')) {
        $selectColumns[] = 'is_uaf_student';
    } else {
        $selectColumns[] = '1 AS is_uaf_student';
    }

    $stmtApps = $pdo->prepare(
        'SELECT ' . implode(', ', $selectColumns) . '
         FROM student_applications
         WHERE allocation_status = :status
         ORDER BY id ASC
         LIMIT 300'
    );
    $stmtApps->execute([':status' => $statusFilter]);
    $pendingApplications = $stmtApps->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $counts = ['pending' => 0, 'allocated' => 0, 'waitlisted' => 0, 'ineligible' => 0];
    $pendingApplications = [];
    $errors[] = 'Could not load room applications.';
}

require_once __DIR__ . '/../includes/layout/header.php';
?>
<div class="dash-overlay" aria-hidden="true"></div>
<?php require_once __DIR__ . '/../includes/layout/sidebar.php'; ?>
<div class="dash-topbar d-flex align-items-center justify-content-between gap-3">
    <div class="title"><?php echo e($pageTitle); ?></div>
</div>
<div class="dash-content">
    <?php if ($messages): ?>
        <div class="alert alert-success fw-semibold">
            <?php foreach ($messages as $m): ?>
                <div><?php echo e($m); ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php if ($errors): ?>
        <div class="alert alert-danger fw-semibold">
            <?php foreach ($errors as $err): ?>
                <div><?php echo e($err); ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="row g-3 mb-3">
        <div class="col-md-3"><div class="dash-card"><div class="small text-muted fw-bold">Pending</div><div class="h4 mb-0"><?php echo (int)$counts['pending']; ?></div></div></div>
        <div class="col-md-3"><div class="dash-card"><div class="small text-muted fw-bold">Allocated</div><div class="h4 mb-0"><?php echo (int)$counts['allocated']; ?></div></div></div>
        <div class="col-md-3"><div class="dash-card"><div class="small text-muted fw-bold">Waitlisted</div><div class="h4 mb-0"><?php echo (int)$counts['waitlisted']; ?></div></div></div>
        <div class="col-md-3"><div class="dash-card border border-danger-subtle"><div class="small text-danger fw-bold">Ineligible</div><div class="h4 mb-0 text-danger"><?php echo (int)$counts['ineligible']; ?></div></div></div>
    </div>

    <div class="dash-card mb-3">
        <div class="d-flex gap-2 flex-wrap">
            <a class="btn btn-sm <?php echo $statusFilter === 'pending' ? 'btn-dark' : 'btn-outline-dark'; ?>" href="<?php echo e(base_path() . '/admin/applications.php?status=pending'); ?>">Pending</a>
            <a class="btn btn-sm <?php echo $statusFilter === 'allocated' ? 'btn-success' : 'btn-outline-success'; ?>" href="<?php echo e(base_path() . '/admin/applications.php?status=allocated'); ?>">Allocated</a>
            <a class="btn btn-sm <?php echo $statusFilter === 'waitlisted' ? 'btn-warning' : 'btn-outline-warning'; ?>" href="<?php echo e(base_path() . '/admin/applications.php?status=waitlisted'); ?>">Waitlisted</a>
            <a class="btn btn-sm <?php echo $statusFilter === 'ineligible' ? 'btn-danger' : 'btn-outline-danger'; ?>" href="<?php echo e(base_path() . '/admin/applications.php?status=ineligible'); ?>">Ineligible</a>
        </div>
    </div>

    <?php if ((int)$roomDebug['available_rooms'] > 0): ?>
        <div class="alert alert-info fw-semibold">
            <?php echo (int)$roomDebug['available_rooms']; ?> rooms available
            (<?php echo (int)($roomDebug['by_gender']['Male'] ?? 0); ?> Male /
            <?php echo (int)($roomDebug['by_gender']['Female'] ?? 0); ?> Female)
            — <?php echo (int)$roomDebug['null_faculty_count']; ?> have no faculty restriction.
        </div>
    <?php else: ?>
        <div class="alert alert-danger fw-semibold">
            No rooms found in uaf_hostel_rooms. Run <code>database/seed_uaf_rooms.sql</code> first.
        </div>
    <?php endif; ?>

    <?php require_once __DIR__ . '/../includes/partials/uaf_allocation_engine_card.php'; ?>

    <div class="dash-card mt-3">
        <div class="d-flex align-items-center justify-content-between mb-2">
            <div class="fw-bold" style="color:var(--dark);"><?php echo e(ucfirst($statusFilter)); ?> applications</div>
            <span class="small text-muted fw-semibold">Showing up to 300 records</span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Faculty</th>
                    <th>Gender</th>
                    <th>Tier</th>
                    <th>CGPA</th>
                    <th>Eligibility Note</th>
                    <th>Priority</th>
                    <th style="min-width:220px;">Action</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($pendingApplications === []): ?>
                    <tr><td colspan="9" class="text-muted fw-semibold">No applications in this filter.</td></tr>
                <?php else: ?>
                    <?php foreach ($pendingApplications as $app): ?>
                        <?php $score = $engine->calculatePriorityScore($app); ?>
                        <?php
                        $eligibilityNote = 'Eligible';
                        if (!empty($app['has_unpaid_dues'])) {
                            $eligibilityNote = 'Blocked: unpaid dues';
                        } elseif (isset($app['is_uaf_student']) && (int)$app['is_uaf_student'] === 0) {
                            $eligibilityNote = 'Blocked: non-UAF enrollment';
                        }
                        ?>
                        <tr>
                            <td class="fw-semibold">#<?php echo (int)$app['id']; ?></td>
                            <td><?php echo e((string)$app['student_name']); ?></td>
                            <td><?php echo e((string)$app['faculty']); ?></td>
                            <td><?php echo e((string)$app['gender']); ?></td>
                            <td><?php echo e((string)$app['degree_level']); ?></td>
                            <td><?php echo e((string)$app['cgpa']); ?></td>
                            <td><span class="small fw-semibold <?php echo $eligibilityNote === 'Eligible' ? 'text-success' : 'text-danger'; ?>"><?php echo e($eligibilityNote); ?></span></td>
                            <td><span class="badge bg-dark"><?php echo e((string)number_format($score, 2)); ?></span></td>
                            <td>
                                <div class="d-flex gap-2">
                                    <form method="post">
                                        <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                                        <input type="hidden" name="application_id" value="<?php echo (int)$app['id']; ?>">
                                        <input type="hidden" name="action" value="approve_allocate">
                                        <button class="btn btn-sm btn-success fw-bold" type="submit">Approve & Allocate</button>
                                    </form>
                                    <?php if ($statusFilter === 'ineligible'): ?>
                                        <form method="post">
                                            <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                                            <input type="hidden" name="application_id" value="<?php echo (int)$app['id']; ?>">
                                            <input type="hidden" name="action" value="recheck_eligibility">
                                            <button class="btn btn-sm btn-outline-danger fw-bold" type="submit">Re-check eligibility</button>
                                        </form>
                                    <?php else: ?>
                                        <form method="post">
                                            <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                                            <input type="hidden" name="application_id" value="<?php echo (int)$app['id']; ?>">
                                            <input type="hidden" name="action" value="mark_waitlisted">
                                            <button class="btn btn-sm btn-outline-secondary fw-bold" type="submit">Waitlist</button>
                                        </form>
                                    <?php endif; ?>
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
<?php require_once __DIR__ . '/../includes/layout/footer.php'; ?>
