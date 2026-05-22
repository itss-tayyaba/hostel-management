<?php
declare(strict_types=1);
/**
 * @file student/allotment_slip.php
 * @brief Allotment slip — Printable official allotment document
 * @access student
 * @database room_allocation
 */

require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/../includes/models/student_model.php';
require_role('student');

$pdo = db();
$student = student_get_by_user_id($pdo, (int)$_SESSION['user_id']);
if (!$student) {
    http_response_code(404); echo 'Student profile not found.'; exit;
}
$allocation = student_get_latest_approved_allocation($pdo, (int)$student['id']);
$issued = date('d M Y H:i');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Room Allotment Slip — <?php echo e((string)$student['reg_no']); ?></title>
<style>
    body { font-family: 'Segoe UI', Arial, sans-serif; background:#eef1f6; padding: 24px; color:#1a1a1a; }
    .actions { max-width: 760px; margin: 0 auto 14px; display: flex; gap: 8px; justify-content: flex-end; }
    .btn { background:#1b5e20;color:#fff;border:0;padding:8px 14px;border-radius:6px;font-weight:700;cursor:pointer;text-decoration:none;font-size:13px; }
    .btn.ghost { background:#fff;color:#1b5e20;border:1px solid #1b5e20; }
    .slip { max-width: 760px; margin: 0 auto; background:#fff; border:2px solid #1b5e20; border-radius:10px; padding:28px 36px; position:relative; }
    .head { display:flex; justify-content:space-between; align-items:flex-start; border-bottom:2px solid #1b5e20; padding-bottom:10px; margin-bottom:16px; }
    .head h1 { font-size:18px; margin:0; color:#1b5e20; letter-spacing:.5px; }
    .head h2 { font-size:12px; margin:4px 0 0; color:#4c956c; font-weight:600; }
    .meta { font-size:12px; color:#555; text-align:right; }
    h3 { color:#1b5e20; margin: 18px 0 8px; font-size:14px; letter-spacing:.5px; text-transform: uppercase; }
    table { width:100%; border-collapse: collapse; font-size:13px; }
    table td { padding:6px 8px; border-bottom: 1px solid #f0f0f0; }
    table td.k { color:#555; width:35%; font-weight:600; }
    table td.v { font-weight:700; }
    .stamp { position:absolute; bottom:60px; right:24px; font-size:50px; color:rgba(76,175,80,0.18); transform: rotate(-18deg); font-weight:900; letter-spacing:4px; pointer-events:none; }
    .terms { margin-top:18px; font-size:11px; color:#555; line-height:1.6; }
    .signoff { margin-top:24px; display:flex; justify-content:space-between; font-size:11px; color:#444; }
    .pending { background:#fff8e1; color:#8a6d3b; padding:10px 14px; border-radius:6px; font-weight:600; }
    @media print { body { background:#fff; padding:0; } .actions { display:none; } .slip { border:2px solid #1b5e20; box-shadow:none; } }
</style>
</head>
<body>
<div class="actions">
    <a class="btn ghost" href="<?php echo e(base_path() . '/student/dashboard.php'); ?>">← Dashboard</a>
    <button class="btn" onclick="window.print();">Print / Save as PDF</button>
</div>

<div class="slip">
    <div class="head">
        <div>
            <h1>University of Agriculture Faisalabad</h1>
            <h2>Hostel Management Office · Room Allotment Slip</h2>
        </div>
        <div class="meta">
            <strong>Slip #</strong> ALT-<?php echo str_pad((string)$student['id'], 5, '0', STR_PAD_LEFT); ?><br>
            <strong>Issued:</strong> <?php echo e($issued); ?>
        </div>
    </div>

    <h3>Student details</h3>
    <table>
        <tr><td class="k">Name</td><td class="v"><?php echo e((string)$student['name']); ?></td></tr>
        <tr><td class="k">Registration #</td><td class="v"><?php echo e((string)$student['reg_no']); ?></td></tr>
        <tr><td class="k">Department</td><td class="v"><?php echo e((string)$student['department']); ?></td></tr>
        <tr><td class="k">Gender</td><td class="v"><?php echo e(ucfirst((string)$student['gender'])); ?></td></tr>
        <tr><td class="k">Email</td><td class="v"><?php echo e((string)$student['email']); ?></td></tr>
    </table>

    <h3>Allotment</h3>
    <?php if ($allocation): ?>
        <table>
            <tr><td class="k">Hostel</td><td class="v"><?php echo e((string)$allocation['hostel_name']); ?></td></tr>
            <tr><td class="k">Room number</td><td class="v"><?php echo e((string)$allocation['room_number']); ?></td></tr>
            <?php if (!empty($allocation['bed_code'])): ?>
                <tr><td class="k">Bed</td><td class="v"><?php echo e((string)$allocation['bed_code']); ?></td></tr>
            <?php endif; ?>
            <tr><td class="k">Status</td><td class="v"><?php echo e(strtoupper((string)$allocation['status'])); ?></td></tr>
            <tr><td class="k">Allotted at</td><td class="v"><?php echo e((string)($allocation['responded_at'] ?? $allocation['applied_at'])); ?></td></tr>
        </table>
        <div class="stamp">ALLOTTED</div>
    <?php else: ?>
        <div class="pending">No approved allotment yet. Submit an application from your dashboard.</div>
    <?php endif; ?>

    <div class="terms">
        <strong>Terms:</strong> The allottee shall abide by all hostel rules and the room is non-transferable.
        Damage to furniture / property will be recovered. The hostel office may re-allocate rooms due to
        operational requirements with reasonable notice.
    </div>

    <div class="signoff">
        <span>Allottee signature: ______________</span>
        <span>Warden signature: ______________</span>
        <span>Admin / Office seal</span>
    </div>
</div>
</body>
</html>
