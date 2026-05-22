<?php
declare(strict_types=1);
/**
 * @file admin/payments.php
 * @brief Payment management — Record fees and view history
 * @access admin
 * @database payments
 */

require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/../includes/models/payment_model.php';
require_once __DIR__ . '/../includes/models/audit_model.php';
require_role('admin');

$userName = (string)($_SESSION['name'] ?? 'Admin');
$userRole = (string)($_SESSION['role'] ?? 'admin');
$pageTitle = 'Payments';
$activePage = 'admin_payments';

$pdo = db();
$errors = [];
$messages = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? null;
    if (!csrf_validate(is_string($token) ? $token : null)) {
        $errors[] = 'Invalid CSRF token. Please refresh and try again.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        try {
            if ($action === 'record_payment') {
                $studentId = (int)($_POST['student_id'] ?? 0);
                $amount = (float)($_POST['amount'] ?? 0);
                $semester = trim((string)($_POST['semester'] ?? ''));
                $paymentDate = trim((string)($_POST['payment_date'] ?? ''));
                $method = trim((string)($_POST['method'] ?? 'cash'));
                $status = trim((string)($_POST['status'] ?? 'paid'));
                $reference = trim((string)($_POST['reference'] ?? ''));

                if ($studentId <= 0) $errors[] = 'Select a student.';
                if ($amount <= 0) $errors[] = 'Amount must be greater than zero.';
                if ($semester === '') $errors[] = 'Semester is required.';
                if ($paymentDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $paymentDate)) {
                    $errors[] = 'Payment date must be YYYY-MM-DD.';
                }

                if (!$errors) {
                    $newId = payment_create($pdo, $studentId, $amount, $semester, $paymentDate, $method, $status, $reference !== '' ? $reference : null);
                    if ($newId > 0) {
                        audit_log_from_session($pdo, 'payment_recorded', 'payment', $newId,
                            'student_id=' . $studentId . ' amount=' . $amount . ' sem=' . $semester);
                        $messages[] = 'Payment recorded successfully (#' . $newId . ').';
                    } else {
                        $errors[] = 'Could not save payment.';
                    }
                }
            } elseif ($action === 'update_status') {
                $paymentId = (int)($_POST['payment_id'] ?? 0);
                $newStatus = (string)($_POST['new_status'] ?? '');
                if ($paymentId > 0 && payment_update_status($pdo, $paymentId, $newStatus)) {
                    audit_log_from_session($pdo, 'payment_status_changed', 'payment', $paymentId, 'status=' . $newStatus);
                    $messages[] = 'Payment status updated.';
                } else {
                    $errors[] = 'Invalid payment update.';
                }
            } elseif ($action === 'delete_payment') {
                $paymentId = (int)($_POST['payment_id'] ?? 0);
                if ($paymentId > 0 && payment_delete($pdo, $paymentId)) {
                    audit_log_from_session($pdo, 'payment_deleted', 'payment', $paymentId);
                    $messages[] = 'Payment deleted.';
                } else {
                    $errors[] = 'Could not delete payment.';
                }
            } elseif ($action === 'upsert_fee') {
                $hostelId = (int)($_POST['hostel_id'] ?? 0);
                $semester = trim((string)($_POST['semester'] ?? ''));
                $amount = (float)($_POST['amount'] ?? 0);
                $dueDate = trim((string)($_POST['due_date'] ?? ''));
                if ($semester === '' || $amount <= 0) {
                    $errors[] = 'Semester and amount are required for fee structure.';
                } else {
                    $stmt = $pdo->prepare(
                        'INSERT INTO fee_structure (hostel_id, semester, amount, due_date)
                         VALUES (:hid, :sem, :amt, :dd)
                         ON DUPLICATE KEY UPDATE amount = VALUES(amount), due_date = VALUES(due_date)'
                    );
                    $stmt->execute([
                        ':hid' => $hostelId > 0 ? $hostelId : null,
                        ':sem' => $semester,
                        ':amt' => $amount,
                        ':dd'  => $dueDate !== '' ? $dueDate : null,
                    ]);
                    audit_log_from_session($pdo, 'fee_structure_upserted', 'fee_structure', null, 'sem=' . $semester . ' amt=' . $amount);
                    $messages[] = 'Fee structure saved.';
                }
            }
        } catch (Throwable $e) {
            $errors[] = 'Operation failed: ' . $e->getMessage();
        }
    }
}

$students = [];
$payments = [];
$fees = [];
$hostels = [];
try {
    $students = $pdo->query(
        'SELECT s.id AS student_id, s.reg_no, s.department, u.name, u.email
         FROM students s INNER JOIN users u ON u.id = s.user_id
         ORDER BY u.name ASC'
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $payments = $pdo->query(
        'SELECT p.id, p.amount, p.semester, p.payment_date, p.method, p.status, p.reference, p.created_at,
                s.reg_no, u.name AS student_name
         FROM payments p
         INNER JOIN students s ON s.id = p.student_id
         INNER JOIN users u ON u.id = s.user_id
         ORDER BY p.created_at DESC
         LIMIT 200'
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    try {
        $fees = $pdo->query(
            'SELECT f.id, f.semester, f.amount, f.due_date, f.hostel_id, h.name AS hostel_name
             FROM fee_structure f
             LEFT JOIN hostels h ON h.id = f.hostel_id
             ORDER BY f.id DESC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $fees = [];
    }

    $hostels = $pdo->query('SELECT id, name FROM hostels ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    // table might not exist
}

$totalPaid = 0.0;
$totalPending = 0.0;
foreach ($payments as $p) {
    $amt = (float)$p['amount'];
    if ((string)$p['status'] === 'paid') $totalPaid += $amt;
    elseif ((string)$p['status'] === 'pending') $totalPending += $amt;
}

$today = date('Y-m-d');
$activeSemester = payment_active_semester($pdo);
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
        Record fees, track dues, manage structure
    </div>
</div>

<div class="dash-content">
    <?php if ($errors): ?>
        <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $err): ?><li><?php echo e($err); ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>
    <?php if ($messages): ?>
        <div class="alert alert-success"><ul class="mb-0"><?php foreach ($messages as $msg): ?><li><?php echo e($msg); ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>

    <div class="row g-3 mb-3">
        <div class="col-md-4">
            <div class="dash-card">
                <div class="kpi"><?php echo number_format($totalPaid, 2); ?></div>
                <div class="kpi-label">Total Paid (last 200 rows)</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="dash-card">
                <div class="kpi"><?php echo number_format($totalPending, 2); ?></div>
                <div class="kpi-label">Pending</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="dash-card">
                <div class="kpi"><?php echo (int)count($payments); ?></div>
                <div class="kpi-label">Records shown</div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-6">
            <div class="dash-card">
                <div style="font-weight:900;color:var(--dark);margin-bottom:10px;">Record a payment</div>
                <form method="post" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                    <input type="hidden" name="action" value="record_payment">

                    <div class="mb-2">
                        <label class="form-label fw-bold">Student</label>
                        <select class="form-select" name="student_id" required>
                            <option value="">-- choose --</option>
                            <?php foreach ($students as $s): ?>
                                <option value="<?php echo (int)$s['student_id']; ?>">
                                    <?php echo e((string)$s['name']); ?> (<?php echo e((string)$s['reg_no']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="row g-2">
                        <div class="col-md-6 mb-2">
                            <label class="form-label fw-bold">Semester</label>
                            <input type="text" class="form-control" name="semester" required value="<?php echo e($activeSemester); ?>">
                        </div>
                        <div class="col-md-6 mb-2">
                            <label class="form-label fw-bold">Amount (PKR)</label>
                            <input type="number" step="0.01" min="0" class="form-control" name="amount" required>
                        </div>
                        <div class="col-md-6 mb-2">
                            <label class="form-label fw-bold">Payment date</label>
                            <input type="date" class="form-control" name="payment_date" required value="<?php echo e($today); ?>">
                        </div>
                        <div class="col-md-6 mb-2">
                            <label class="form-label fw-bold">Method</label>
                            <select class="form-select" name="method">
                                <option value="cash">Cash</option>
                                <option value="bank_transfer">Bank transfer</option>
                                <option value="online">Online</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-2">
                            <label class="form-label fw-bold">Status</label>
                            <select class="form-select" name="status">
                                <option value="paid" selected>Paid</option>
                                <option value="pending">Pending</option>
                                <option value="failed">Failed</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-2">
                            <label class="form-label fw-bold">Reference (optional)</label>
                            <input type="text" class="form-control" name="reference" placeholder="Bank TXN / Receipt #">
                        </div>
                    </div>

                    <button type="submit" class="btn btn-success w-100 fw-bold mt-2">Save payment</button>
                </form>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="dash-card">
                <div style="font-weight:900;color:var(--dark);margin-bottom:10px;">Fee structure</div>
                <form method="post" class="mb-3">
                    <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                    <input type="hidden" name="action" value="upsert_fee">
                    <div class="row g-2">
                        <div class="col-md-4">
                            <label class="form-label fw-bold small">Hostel</label>
                            <select name="hostel_id" class="form-select">
                                <option value="0">Default (all)</option>
                                <?php foreach ($hostels as $h): ?>
                                    <option value="<?php echo (int)$h['id']; ?>"><?php echo e((string)$h['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold small">Semester</label>
                            <input type="text" class="form-control" name="semester" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-bold small">Amount</label>
                            <input type="number" step="0.01" min="0" class="form-control" name="amount" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-bold small">Due date</label>
                            <input type="date" class="form-control" name="due_date">
                        </div>
                    </div>
                    <button class="btn btn-outline-dark mt-2 fw-bold">Save / update fee</button>
                </form>

                <div class="table-responsive" style="max-height:280px;overflow:auto;">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light"><tr><th>Hostel</th><th>Semester</th><th>Amount</th><th>Due</th></tr></thead>
                        <tbody>
                        <?php if (!$fees): ?>
                            <tr><td colspan="4" class="text-muted">No fee rows yet.</td></tr>
                        <?php else: foreach ($fees as $f): ?>
                            <tr>
                                <td><?php echo e((string)($f['hostel_name'] ?? 'Default (all)')); ?></td>
                                <td><?php echo e((string)$f['semester']); ?></td>
                                <td><?php echo number_format((float)$f['amount'], 2); ?></td>
                                <td><?php echo e((string)($f['due_date'] ?? '—')); ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="dash-card mt-3">
        <div style="font-weight:900;color:var(--dark);margin-bottom:10px;">Recent payments</div>
        <div class="table-responsive">
            <table class="table align-middle">
                <thead class="table-light">
                <tr>
                    <th>#</th><th>Student</th><th>Reg #</th><th>Semester</th><th>Amount</th>
                    <th>Method</th><th>Status</th><th>Date</th><th class="text-end">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$payments): ?>
                    <tr><td colspan="9" class="text-muted">No payments recorded yet.</td></tr>
                <?php else: foreach ($payments as $p): ?>
                    <tr>
                        <td><?php echo (int)$p['id']; ?></td>
                        <td class="fw-bold"><?php echo e((string)$p['student_name']); ?></td>
                        <td><?php echo e((string)$p['reg_no']); ?></td>
                        <td><?php echo e((string)$p['semester']); ?></td>
                        <td class="fw-bold"><?php echo number_format((float)$p['amount'], 2); ?></td>
                        <td><?php echo e((string)$p['method']); ?></td>
                        <td>
                            <?php
                            $st = strtolower((string)$p['status']);
                            $cls = $st === 'paid' ? 'success' : ($st === 'pending' ? 'warning' : 'danger');
                            ?>
                            <span class="badge text-bg-<?php echo $cls; ?>"><?php echo e($st); ?></span>
                        </td>
                        <td style="font-size:13px;"><?php echo e((string)$p['payment_date']); ?></td>
                        <td class="text-end">
                            <a href="<?php echo e(base_path() . '/admin/payment_voucher.php?id=' . (int)$p['id']); ?>"
                               target="_blank" class="btn btn-sm btn-outline-dark">Voucher</a>
                            <form method="post" class="d-inline" onsubmit="return confirm('Delete this payment record?');">
                                <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                                <input type="hidden" name="action" value="delete_payment">
                                <input type="hidden" name="payment_id" value="<?php echo (int)$p['id']; ?>">
                                <button class="btn btn-sm btn-outline-danger">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/layout/footer.php'; ?>
