<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/../includes/models/student_model.php';
require_role('student');

$pdo = db();
$userName = (string)($_SESSION['name'] ?? 'Student');
$userRole = 'student';
$pageTitle = 'Hostel Application';
$activePage = 'student_apply';

$errors = [];
$okMessage = '';

$studentProfile = student_get_by_user_id($pdo, (int)($_SESSION['user_id'] ?? 0));
$defaultFaculty = (string)($studentProfile['department'] ?? '');
$defaultGender = normalize_user_gender((string)($studentProfile['gender'] ?? $studentProfile['user_gender'] ?? 'male'));
$genderValue = $defaultGender === 'female' ? 'Female' : 'Male';

$form = [
    'student_name' => (string)($studentProfile['name'] ?? $userName),
    'gender' => $genderValue,
    'faculty' => $defaultFaculty !== '' ? $defaultFaculty : 'Agriculture',
    'degree_level' => 'Undergraduate',
    'year_of_study' => '1',
    'cgpa' => '3.00',
    'is_international' => '0',
    'has_unpaid_dues' => '0',
    'past_violations_count' => '0',
    'preferred_hostel_id' => '0',
    'preferred_room_id' => '0',
];

/**
 * @return list<array{id:int,name:string,type:string}>
 */
function student_apply_get_eligible_hostels(PDO $pdo, string $gender, bool $isInternational): array
{
    if ($isInternational) {
        $stmt = $pdo->query(
            "SELECT id, name, type
             FROM hostels
             WHERE LOWER(name) LIKE '%international%'
             ORDER BY name ASC"
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $normalizedGender = strtolower(trim($gender));
    if ($normalizedGender === 'female') {
        $stmt = $pdo->query(
            "SELECT id, name, type
             FROM hostels
             WHERE type IN ('girls','mixed')
             ORDER BY name ASC"
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $stmt = $pdo->query(
        "SELECT id, name, type
         FROM hostels
         WHERE type IN ('boys','mixed')
         ORDER BY name ASC"
    );
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @return list<array{id:int,room_number:string,capacity:int,occupied:int}>
 */
function student_apply_get_available_rooms_for_hostel(PDO $pdo, int $hostelId): array
{
    $vacancySql = smart_allocation_tables_ready($pdo)
        ? '(r.capacity > r.occupied)'
        : '(r.occupied = 0)';

    $stmt = $pdo->prepare(
        "SELECT r.id, r.room_number, r.capacity, r.occupied
         FROM rooms r
         WHERE r.hostel_id = :hid
           AND $vacancySql
         ORDER BY r.room_number ASC"
    );
    $stmt->execute([':hid' => $hostelId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function student_apply_table_has_column(PDO $pdo, string $table, string $column): bool
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

$isInternationalNow = $form['is_international'] === '1';
$eligibleHostels = student_apply_get_eligible_hostels($pdo, $form['gender'], $isInternationalNow);
if ($eligibleHostels !== []) {
    $form['preferred_hostel_id'] = (string)(int)$eligibleHostels[0]['id'];
    $availableRooms = student_apply_get_available_rooms_for_hostel($pdo, (int)$form['preferred_hostel_id']);
    if ($availableRooms !== []) {
        $form['preferred_room_id'] = (string)(int)$availableRooms[0]['id'];
    }
} else {
    $availableRooms = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $refreshOnly = !empty($_POST['_refresh_pref_lists']);
    $token = $_POST['csrf_token'] ?? null;
    if (!csrf_validate(is_string($token) ? $token : null)) {
        $errors[] = 'Invalid CSRF token. Please refresh and try again.';
    } else {
        $form['student_name'] = trim((string)($_POST['student_name'] ?? $form['student_name']));
        $form['gender'] = in_array((string)($_POST['gender'] ?? ''), ['Male', 'Female'], true)
            ? (string)$_POST['gender']
            : $form['gender'];
        $form['faculty'] = trim((string)($_POST['faculty'] ?? $form['faculty']));
        $form['degree_level'] = in_array((string)($_POST['degree_level'] ?? ''), ['Undergraduate', 'MS/MPhil', 'PhD'], true)
            ? (string)$_POST['degree_level']
            : 'Undergraduate';
        $form['year_of_study'] = (string)max(1, min(4, (int)($_POST['year_of_study'] ?? 1)));
        $form['cgpa'] = (string)max(0.0, min(4.0, (float)($_POST['cgpa'] ?? 0)));
        $form['is_international'] = !empty($_POST['is_international']) ? '1' : '0';
        $form['has_unpaid_dues'] = !empty($_POST['has_unpaid_dues']) ? '1' : '0';
        $form['past_violations_count'] = (string)max(0, (int)($_POST['past_violations_count'] ?? 0));
        $form['preferred_hostel_id'] = (string)max(0, (int)($_POST['preferred_hostel_id'] ?? 0));
        $form['preferred_room_id'] = (string)max(0, (int)($_POST['preferred_room_id'] ?? 0));

        $isInternationalNow = $form['is_international'] === '1';
        $eligibleHostels = student_apply_get_eligible_hostels($pdo, $form['gender'], $isInternationalNow);
        $eligibleHostelIds = array_map(static fn(array $h): int => (int)$h['id'], $eligibleHostels);

        if (!in_array((int)$form['preferred_hostel_id'], $eligibleHostelIds, true)) {
            $form['preferred_hostel_id'] = $eligibleHostels !== [] ? (string)(int)$eligibleHostels[0]['id'] : '0';
        }
        $availableRooms = ((int)$form['preferred_hostel_id'] > 0)
            ? student_apply_get_available_rooms_for_hostel($pdo, (int)$form['preferred_hostel_id'])
            : [];
        $availableRoomIds = array_map(static fn(array $r): int => (int)$r['id'], $availableRooms);
        if (!in_array((int)$form['preferred_room_id'], $availableRoomIds, true)) {
            $form['preferred_room_id'] = $availableRooms !== [] ? (string)(int)$availableRooms[0]['id'] : '0';
        }

        if ($form['student_name'] === '') {
            $errors[] = 'Student name is required.';
        }
        if ($form['faculty'] === '') {
            $errors[] = 'Faculty is required.';
        }
        if ((int)$form['preferred_hostel_id'] <= 0) {
            $errors[] = 'Please select a valid hostel preference.';
        }
        if ((int)$form['preferred_room_id'] <= 0) {
            $errors[] = 'Please select an available room from the selected hostel.';
        }

        if ($errors === []) {
            if ($refreshOnly) {
                // Only refresh dependent preference dropdowns; do not persist.
            } else {
            try {
                $pdo->beginTransaction();

                $pendingStmt = $pdo->prepare(
                    'SELECT id FROM student_applications
                     WHERE student_name = :student_name
                       AND faculty = :faculty
                       AND allocation_status = \'pending\'
                     LIMIT 1'
                );
                $pendingStmt->execute([
                    ':student_name' => $form['student_name'],
                    ':faculty' => $form['faculty'],
                ]);
                $alreadyPending = (bool)$pendingStmt->fetchColumn();

                if ($alreadyPending) {
                    $pdo->rollBack();
                    $errors[] = 'You already have a pending hostel application.';
                } else {
                    $hasPreferredHostelCol = student_apply_table_has_column($pdo, 'student_applications', 'preferred_hostel_id');
                    $hasPreferredRoomCol = student_apply_table_has_column($pdo, 'student_applications', 'preferred_room_id');

                    $columns = [
                        'student_name',
                        'gender',
                        'faculty',
                        'degree_level',
                        'year_of_study',
                        'cgpa',
                        'is_international',
                        'has_unpaid_dues',
                        'past_violations_count',
                        'allocation_status',
                    ];
                    $params = [
                        ':student_name' => $form['student_name'],
                        ':gender' => $form['gender'],
                        ':faculty' => $form['faculty'],
                        ':degree_level' => $form['degree_level'],
                        ':year_of_study' => (int)$form['year_of_study'],
                        ':cgpa' => (float)$form['cgpa'],
                        ':is_international' => (int)$form['is_international'],
                        ':has_unpaid_dues' => (int)$form['has_unpaid_dues'],
                        ':past_violations_count' => (int)$form['past_violations_count'],
                        ':allocation_status' => 'pending',
                    ];

                    if ($hasPreferredHostelCol) {
                        $columns[] = 'preferred_hostel_id';
                        $params[':preferred_hostel_id'] = (int)$form['preferred_hostel_id'];
                    }
                    if ($hasPreferredRoomCol) {
                        $columns[] = 'preferred_room_id';
                        $params[':preferred_room_id'] = (int)$form['preferred_room_id'];
                    }

                    $placeholders = array_map(static fn(string $c): string => ':' . $c, $columns);
                    $sql = 'INSERT INTO student_applications (' . implode(', ', $columns) . ')
                            VALUES (' . implode(', ', $placeholders) . ')';
                    $insert = $pdo->prepare($sql);
                    $insert->execute($params);
                    $pdo->commit();
                    $okMessage = 'Application submitted successfully. Status: pending.';
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = 'Could not submit application right now. Please try again.';
            }
            }
        }
    }
}

try {
    $latestStmt = $pdo->prepare(
        'SELECT id, allocation_status, degree_level, year_of_study, cgpa, created_at
         FROM student_applications
         WHERE student_name = :student_name
         ORDER BY id DESC
         LIMIT 5'
    );
    $latestStmt->execute([':student_name' => $form['student_name']]);
    $recentApplications = $latestStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $recentApplications = [];
}

// Build dropdown lists for first render and after POST.
$isInternationalNow = $form['is_international'] === '1';
$eligibleHostels = student_apply_get_eligible_hostels($pdo, $form['gender'], $isInternationalNow);
$eligibleHostelIds = array_map(static fn(array $h): int => (int)$h['id'], $eligibleHostels);
if (!in_array((int)$form['preferred_hostel_id'], $eligibleHostelIds, true)) {
    $form['preferred_hostel_id'] = $eligibleHostels !== [] ? (string)(int)$eligibleHostels[0]['id'] : '0';
}
$availableRooms = ((int)$form['preferred_hostel_id'] > 0)
    ? student_apply_get_available_rooms_for_hostel($pdo, (int)$form['preferred_hostel_id'])
    : [];
$availableRoomIds = array_map(static fn(array $r): int => (int)$r['id'], $availableRooms);
if (!in_array((int)$form['preferred_room_id'], $availableRoomIds, true)) {
    $form['preferred_room_id'] = $availableRooms !== [] ? (string)(int)$availableRooms[0]['id'] : '0';
}

require_once __DIR__ . '/../includes/layout/header.php';
?>
<div class="dash-overlay" aria-hidden="true"></div>
<?php require_once __DIR__ . '/../includes/layout/sidebar.php'; ?>
<div class="dash-topbar d-flex align-items-center justify-content-between gap-3">
    <div class="title"><?php echo e($pageTitle); ?></div>
</div>
<div class="dash-content">
    <?php if ($okMessage !== ''): ?>
        <div class="alert alert-success fw-semibold"><?php echo e($okMessage); ?></div>
    <?php endif; ?>

    <?php if ($errors): ?>
        <div class="alert alert-danger">
            <ul class="mb-0">
                <?php foreach ($errors as $error): ?>
                    <li><?php echo e($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="dash-card">
                <div class="fw-bold mb-3" style="color:var(--dark);">Submit hostel application</div>
                <form method="post" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">

                    <div class="row g-2">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Student Name</label>
                            <input class="form-control" name="student_name" required value="<?php echo e($form['student_name']); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Gender</label>
                            <select class="form-select" name="gender" required>
                                <option value="Male" <?php echo $form['gender'] === 'Male' ? 'selected' : ''; ?>>Male</option>
                                <option value="Female" <?php echo $form['gender'] === 'Female' ? 'selected' : ''; ?>>Female</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Faculty</label>
                            <input class="form-control" name="faculty" required value="<?php echo e($form['faculty']); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Degree Level</label>
                            <select class="form-select" name="degree_level" required>
                                <?php foreach (['Undergraduate', 'MS/MPhil', 'PhD'] as $level): ?>
                                    <option value="<?php echo e($level); ?>" <?php echo $form['degree_level'] === $level ? 'selected' : ''; ?>><?php echo e($level); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Year of Study</label>
                            <input type="number" min="1" max="4" class="form-control" name="year_of_study" value="<?php echo e($form['year_of_study']); ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">CGPA</label>
                            <input type="number" step="0.01" min="0" max="4" class="form-control" name="cgpa" value="<?php echo e($form['cgpa']); ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Past Violations</label>
                            <input type="number" min="0" class="form-control" name="past_violations_count" value="<?php echo e($form['past_violations_count']); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Preferred Hostel</label>
                            <select class="form-select" name="preferred_hostel_id" id="preferred_hostel_id" required>
                                <?php if ($eligibleHostels === []): ?>
                                    <option value="0">No eligible hostel found</option>
                                <?php else: ?>
                                    <?php foreach ($eligibleHostels as $hostel): ?>
                                        <option value="<?php echo (int)$hostel['id']; ?>" <?php echo ((int)$form['preferred_hostel_id'] === (int)$hostel['id']) ? 'selected' : ''; ?>>
                                            <?php echo e((string)$hostel['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                            <div class="small text-muted mt-1">Hostels are filtered by gender and international policy.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Available Room in Selected Hostel</label>
                            <select class="form-select" name="preferred_room_id" id="preferred_room_id" required>
                                <?php if ($availableRooms === []): ?>
                                    <option value="0">No available room</option>
                                <?php else: ?>
                                    <?php foreach ($availableRooms as $room): ?>
                                        <option value="<?php echo (int)$room['id']; ?>" <?php echo ((int)$form['preferred_room_id'] === (int)$room['id']) ? 'selected' : ''; ?>>
                                            Room <?php echo e((string)$room['room_number']); ?> (Cap: <?php echo (int)$room['capacity']; ?>, Occupied: <?php echo (int)$room['occupied']; ?>)
                                        </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                            <div class="small text-muted mt-1">Only available rooms for selected hostel are shown.</div>
                        </div>
                    </div>

                    <div class="mt-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="intl" name="is_international" value="1" <?php echo $form['is_international'] === '1' ? 'checked' : ''; ?>>
                            <label class="form-check-label fw-semibold" for="intl">International student</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="dues" name="has_unpaid_dues" value="1" <?php echo $form['has_unpaid_dues'] === '1' ? 'checked' : ''; ?>>
                            <label class="form-check-label fw-semibold" for="dues">Has unpaid dues</label>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary w-100 mt-3 fw-bold" style="background:var(--dark);border:none;">
                        Submit Application
                    </button>
                </form>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="dash-card">
                <div class="fw-bold mb-2" style="color:var(--dark);">Recent application status</div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                        <tr><th>ID</th><th>Status</th><th>CGPA</th></tr>
                        </thead>
                        <tbody>
                        <?php if ($recentApplications === []): ?>
                            <tr><td colspan="3" class="text-muted fw-semibold">No applications found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($recentApplications as $row): ?>
                                <tr>
                                    <td class="fw-semibold">#<?php echo (int)$row['id']; ?></td>
                                    <td><span class="badge bg-secondary"><?php echo e((string)$row['allocation_status']); ?></span></td>
                                    <td><?php echo e((string)$row['cgpa']); ?></td>
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
<script>
(() => {
    const intl = document.getElementById('intl');
    const gender = document.querySelector('select[name="gender"]');
    const hostelSelect = document.getElementById('preferred_hostel_id');
    if (!intl || !gender || !hostelSelect) return;

    const triggerReload = () => {
        // Simple deterministic UX: re-submit selection context to refresh eligible hostels/rooms.
        const form = hostelSelect.closest('form');
        if (!form) return;
        const marker = document.createElement('input');
        marker.type = 'hidden';
        marker.name = '_refresh_pref_lists';
        marker.value = '1';
        form.appendChild(marker);
        form.requestSubmit();
    };

    intl.addEventListener('change', triggerReload);
    gender.addEventListener('change', triggerReload);
    hostelSelect.addEventListener('change', triggerReload);
})();
</script>
<?php require_once __DIR__ . '/../includes/layout/footer.php'; ?>
