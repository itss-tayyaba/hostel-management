<?php
declare(strict_types=1);
/**
 * @file student/payments.php
 * @brief Student payments — View dues and payment history
 * @access student
 * @database payments
 */

require_once __DIR__ . '/../includes/app.php';
require_role('student');

require_once __DIR__ . '/../includes/models/student_model.php';
require_once __DIR__ . '/../includes/models/payment_model.php';

$userName = (string)($_SESSION['name'] ?? 'Student');
$userRole = 'student';
$pageTitle = 'Payments';
$activePage = 'student_payments';

$pdo = db();
$student = student_get_by_user_id($pdo, (int)$_SESSION['user_id']);

$errors = [];
$payments = [];
$latest = null;

if (!$student) {
    $errors[] = 'Student profile not found. Please contact admin.';
} else {
    $payments = payment_list_by_student($pdo, (int)$student['id'], 100);
    $latest = payment_latest($pdo, (int)$student['id']);
}

$paidCount = 0;
$pendingCount = 0;
foreach ($payments as $p) {
    $st = strtolower((string)$p['status']);
    if ($st === 'paid') $paidCount++;
    elseif ($st === 'pending') $pendingCount++;
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
        Hostel fee payment history
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

    <div class="row g-3 mb-3">
        <div class="col-md-6 col-xl-3">
            <div class="dash-card">
                <div class="kpi"><?php echo e((string)$paidCount); ?></div>
                <div class="kpi-label">Paid Payments</div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="dash-card">
                <div class="kpi"><?php echo e((string)$pendingCount); ?></div>
                <div class="kpi-label">Pending Payments</div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="dash-card">
                <div class="kpi"><?php echo e(!empty($latest['payment_date']) ? e((string)$latest['payment_date']) : '—'); ?></div>
                <div class="kpi-label">Last Payment Date</div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="dash-card">
                <div class="kpi"><?php echo e(!empty($latest['amount']) ? number_format((float)$latest['amount'], 2) : '—'); ?></div>
                <div class="kpi-label">Last Payment Amount</div>
            </div>
        </div>
    </div>

    <div class="dash-card mb-3">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div>
                <div style="font-weight:900;color:var(--dark);">Current semester fee challan</div>
                <div class="small text-muted" style="font-weight:600;">Print a fee challan for the current semester to deposit at the bank.</div>
            </div>
            <a href="<?php echo e(base_path() . '/student/fee_voucher.php'); ?>" target="_blank"
               class="btn btn-success fw-bold">📄 Download Fee Challan</a>
        </div>
    </div>

    <div class="dash-card">
        <div style="font-weight:900;color:var(--dark);margin-bottom:10px;">Payments Table</div>
        <div class="table-responsive">
            <table class="table table-borderless align-middle">
                <thead>
                <tr>
                    <th>Semester</th>
                    <th>Amount</th>
                    <th>Date</th>
                    <th>Method</th>
                    <th>Status</th>
                    <th class="text-end">Receipt</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($payments)): ?>
                    <tr>
                        <td colspan="6" style="color:var(--text-muted);font-weight:800;">No payment records yet.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($payments as $p): ?>
                        <?php
                        $st = strtolower((string)$p['status']);
                        $badgeCls = 'badge';
                        if ($st === 'paid') $badgeCls .= ' badge-approved';
                        elseif ($st === 'pending') $badgeCls .= ' badge-pending';
                        elseif ($st === 'failed') $badgeCls .= ' badge-rejected';
                        ?>
                        <tr>
                            <td style="font-weight:900;"><?php echo e((string)$p['semester']); ?></td>
                            <td style="font-weight:900;"><?php echo e(number_format((float)$p['amount'], 2)); ?></td>
                            <td style="color:var(--text-muted);font-weight:800;font-size:13px;"><?php echo e((string)$p['payment_date']); ?></td>
                            <td style="color:var(--text-muted);font-weight:800;font-size:13px;"><?php echo e((string)$p['method']); ?></td>
                            <td><span class="<?php echo e($badgeCls); ?>"><?php echo e((string)$p['status']); ?></span></td>
                            <td class="text-end">
                                <a href="<?php echo e(base_path() . '/student/fee_voucher.php?id=' . (int)$p['id']); ?>"
                                   target="_blank" class="btn btn-sm btn-outline-dark">Voucher</a>
                            </td>
                        </tr>
                        <?php if (!empty($p['reference'])): ?>
                            <tr>
                                <td colspan="6" style="color:var(--text-muted);font-weight:800;font-size:13px;">
                                    Reference: <?php echo e((string)$p['reference']); ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/layout/footer.php'; ?>

