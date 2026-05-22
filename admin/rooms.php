<?php
declare(strict_types=1);
/**
 * @file admin/rooms.php
 * @brief Hostels & rooms — CRUD hostels/rooms, bed capacity
 * @access admin
 * @database hostels, rooms, beds
 */

require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/../includes/models/smart_allocation_model.php';
require_role('admin');

$userName = (string)($_SESSION['name'] ?? 'Admin');
$userRole = (string)($_SESSION['role'] ?? 'admin');
$pageTitle = 'Rooms';
$activePage = 'admin_rooms';

$errors = [];
$messages = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? null;
    if (!csrf_validate(is_string($token) ? $token : null)) {
        $errors[] = 'Invalid CSRF token. Please refresh and try again.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        try {
            $pdo = db();
            $smartInventory = smart_allocation_tables_ready($pdo);
            if ($action === 'add_room') {
                $hostelId = (int)($_POST['hostel_id'] ?? 0);
                $roomNumber = trim((string)($_POST['room_number'] ?? ''));
                $capacity = (int)($_POST['capacity'] ?? 1);
                $roomType = strtolower(trim((string)($_POST['room_type'] ?? 'shared')));
                if (!in_array($roomType, ['single', 'shared'], true)) {
                    $roomType = 'shared';
                }
                if ($hostelId <= 0 || $roomNumber === '' || $capacity <= 0) {
                    $errors[] = 'Please enter valid hostel, room number, and capacity.';
                } else {
                    if ($smartInventory) {
                        $stmt = $pdo->prepare('
                            INSERT INTO rooms (hostel_id, room_number, capacity, occupied, room_type)
                            VALUES (:hostel_id, :room_number, :capacity, 0, :room_type)
                        ');
                        $stmt->execute([
                            ':hostel_id' => $hostelId,
                            ':room_number' => $roomNumber,
                            ':capacity' => $capacity,
                            ':room_type' => $roomType,
                        ]);
                        smart_allocation_materialize_beds($pdo, (int)$pdo->lastInsertId());
                    } else {
                        $stmt = $pdo->prepare('
                            INSERT INTO rooms (hostel_id, room_number, capacity, occupied)
                            VALUES (:hostel_id, :room_number, :capacity, 0)
                        ');
                        $stmt->execute([
                            ':hostel_id' => $hostelId,
                            ':room_number' => $roomNumber,
                            ':capacity' => $capacity,
                        ]);
                    }
                    $messages[] = 'Room added successfully.';
                }
            } elseif ($action === 'toggle_occupied') {
                $roomId = (int)($_POST['room_id'] ?? 0);
                $occupied = (int)($_POST['occupied'] ?? 0);
                if ($smartInventory) {
                    $errors[] = 'Occupancy toggles disabled while bed-level allocations are authoritative — edit assignments from Smart Allocation instead.';
                } elseif ($roomId <= 0 || ($occupied !== 0 && $occupied !== 1)) {
                    $errors[] = 'Invalid room update request.';
                } else {
                    $stmt = $pdo->prepare('UPDATE rooms SET occupied = :occupied WHERE id = :id');
                    $stmt->execute([
                        ':occupied' => $occupied,
                        ':id' => $roomId,
                    ]);
                    $messages[] = 'Room occupancy updated.';
                }
            }
        } catch (Throwable $e) {
            $errors[] = 'Could not save room changes.';
        }
    }
}

$hostels = [];
$rooms = [];
$inventorySmartMode = false;
try {
    $pdo = db();
    $inventorySmartMode = smart_allocation_tables_ready($pdo);
    $hostels = $pdo->query('SELECT id, name FROM hostels ORDER BY name ASC')->fetchAll();
    $roomCols = $inventorySmartMode
        ? 'r.id, r.room_number, r.capacity, r.occupied, r.room_type'
        : 'r.id, r.room_number, r.capacity, r.occupied';
    $rooms = $pdo->query(
        "
        SELECT $roomCols, h.name AS hostel_name
        FROM rooms r
        JOIN hostels h ON r.hostel_id = h.id
        ORDER BY h.name ASC, r.room_number ASC
    "
    )->fetchAll();
} catch (Throwable $e) {
    $hostels = [];
    $rooms = [];
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
        Add and manage room inventory
    </div>
</div>

<div class="dash-content">
    <?php if ($errors): ?>
        <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $err): ?><li><?php echo e($err); ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>
    <?php if ($messages): ?>
        <div class="alert alert-success"><ul class="mb-0"><?php foreach ($messages as $msg): ?><li><?php echo e($msg); ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>

    <div class="dash-card mb-4">
        <div style="font-weight:900;color:var(--dark);margin-bottom:12px;">Add New Room</div>
        <form method="post" class="row g-2">
            <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
            <input type="hidden" name="action" value="add_room">
            <div class="<?php echo !empty($inventorySmartMode) ? 'col-md-3' : 'col-md-4'; ?>">
                <select class="form-select" name="hostel_id" required>
                    <option value="">Select Hostel</option>
                    <?php foreach ($hostels as $h): ?>
                        <option value="<?php echo e((string)$h['id']); ?>"><?php echo e((string)$h['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="<?php echo !empty($inventorySmartMode) ? 'col-md-3' : 'col-md-4'; ?>">
                <input type="text" class="form-control" name="room_number" placeholder="Room Number" required>
            </div>
            <div class="col-md-2">
                <input type="number" min="1" class="form-control" name="capacity" placeholder="Capacity" required>
            </div>
            <?php if (!empty($inventorySmartMode)): ?>
                <div class="col-md-2">
                    <select class="form-select" name="room_type" title="Room classification for scoring">
                        <option value="single">single</option>
                        <option value="shared" selected>shared</option>
                    </select>
                </div>
            <?php endif; ?>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100">Add</button>
            </div>
        </form>
    </div>

    <div class="dash-table">
        <div class="table-responsive">
            <table class="table table-borderless align-middle">
                <thead>
                <tr>
                    <th>Hostel</th>
                    <th>Room #</th>
                    <?php if ($inventorySmartMode): ?>
                        <th>Type</th>
                    <?php endif; ?>
                    <th>Capacity</th>
                    <th>Beds used</th>
                    <th>Update</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$rooms): ?>
                    <tr><td colspan="<?php echo $inventorySmartMode ? 6 : 5; ?>" class="text-center" style="color:var(--text-muted);font-weight:800;">No rooms found.</td></tr>
                <?php else: ?>
                    <?php foreach ($rooms as $room): ?>
                        <tr>
                            <td><?php echo e((string)$room['hostel_name']); ?></td>
                            <td style="font-weight:900;"><?php echo e((string)$room['room_number']); ?></td>
                            <?php if ($inventorySmartMode): ?>
                                <td><span class="badge rounded-pill text-bg-light border"><?php echo e((string)($room['room_type'] ?? '-')); ?></span></td>
                            <?php endif; ?>
                            <td><?php echo e((string)$room['capacity']); ?></td>
                            <td>
                                <?php
                                if ($inventorySmartMode) {
                                    echo (int)$room['occupied'] . ' / ' . (int)$room['capacity'];
                                } else {
                                    echo ((int)$room['occupied'] === 1) ? 'Yes' : 'No';
                                }
                                ?>
                            </td>
                            <td>
                                <?php if ($inventorySmartMode): ?>
                                    <span class="small text-muted" style="font-weight:800;">Managed via Smart Allocation</span>
                                <?php else: ?>
                                    <form method="post" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                                        <input type="hidden" name="action" value="toggle_occupied">
                                        <input type="hidden" name="room_id" value="<?php echo e((string)$room['id']); ?>">
                                        <input type="hidden" name="occupied" value="<?php echo ((int)$room['occupied'] === 1) ? '0' : '1'; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-primary">
                                            Mark <?php echo ((int)$room['occupied'] === 1) ? 'Available' : 'Occupied'; ?>
                                        </button>
                                    </form>
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

