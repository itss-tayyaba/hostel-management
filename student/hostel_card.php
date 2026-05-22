<?php
declare(strict_types=1);
/**
 * @file student/hostel_card.php
 * @brief Hostel ID card — Printable card with QR code
 * @access student
 * @database students, room_allocation
 */

require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/../includes/models/student_model.php';
require_role('student');

$pdo = db();
$student = student_get_by_user_id($pdo, (int)$_SESSION['user_id']);
if (!$student) {
    http_response_code(404);
    echo 'Student profile not found.';
    exit;
}

$allocation = student_get_latest_approved_allocation($pdo, (int)$student['id']);
$validUntil = date('d M Y', strtotime('+12 months'));
$issued = date('d M Y');

$avatarPath = (string)($student['avatar_path'] ?? '');
$avatarUrl = $avatarPath !== '' && is_file(__DIR__ . '/../' . $avatarPath)
    ? base_path() . '/' . ltrim($avatarPath, '/')
    : '';

// Build a deterministic QR-friendly string (decoded by hostel office on scan).
$qrPayload = sprintf(
    'UAF-HOSTEL|%s|%s|%s|%s|%s',
    (string)$student['reg_no'],
    (string)$student['name'],
    (string)($student['department'] ?? ''),
    $allocation ? (string)$allocation['hostel_name'] : 'NOT_ALLOCATED',
    $allocation ? (string)$allocation['room_number'] : ''
);
$qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=140x140&margin=2&data=' . urlencode($qrPayload);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Hostel ID Card — <?php echo e((string)$student['reg_no']); ?></title>
<style>
    * { box-sizing: border-box; }
    body { margin:0; background:#eef1f6; font-family: 'Segoe UI', Roboto, Arial, sans-serif; padding: 30px 14px; color:#1a1a1a; }
    .actions { max-width: 640px; margin: 0 auto 14px; display: flex; gap: 8px; justify-content: flex-end; }
    .btn { background:#1b5e20;color:#fff;border:0;padding:8px 14px;border-radius:6px;font-weight:700;cursor:pointer;text-decoration:none;font-size:13px; }
    .btn.ghost { background:#fff;color:#1b5e20;border:1px solid #1b5e20; }

    .card-wrap { max-width: 640px; margin: 0 auto; }
    .id-card {
        width: 640px; height: 380px; background:#fff;
        border: 2px solid #1b5e20; border-radius: 14px; overflow: hidden;
        position: relative; box-shadow: 0 8px 24px rgba(0,0,0,0.1);
    }
    .id-head { background: linear-gradient(135deg,#1b5e20,#4caf50); color:#fff; padding:14px 20px; display:flex; justify-content:space-between; align-items:center; }
    .id-head h1 { font-size:16px; margin:0; letter-spacing:0.6px; }
    .id-head h2 { font-size:11px; margin:2px 0 0; font-weight:500; opacity:.9; }
    .id-body { display:grid; grid-template-columns: 140px 1fr 160px; padding: 18px 22px; gap: 20px; align-items:flex-start; height: calc(100% - 130px); }
    .photo { width:130px; height:160px; background:#f4f4f4; border:2px solid #1b5e20; border-radius:6px; display:flex; align-items:center; justify-content:center; overflow:hidden; }
    .photo img { width:100%; height:100%; object-fit:cover; }
    .photo-placeholder { font-size:42px; color:#1b5e20; font-weight:900; }
    .field-block { font-size:13px; }
    .field-block .k { color:#666; font-size:11px; letter-spacing:.5px; text-transform: uppercase; }
    .field-block .v { font-size:15px; font-weight:800; margin-bottom:8px; color:#1a1a1a; }
    .qr { display:flex; flex-direction:column; align-items:center; gap:4px; font-size:11px; color:#666; }
    .qr img { border:1px solid #ccc; padding:3px; background:#fff; }
    .id-foot { position:absolute; bottom:0; left:0; right:0; background:#f9fafb; padding:10px 22px; font-size:11px; color:#444; display:flex; justify-content:space-between; border-top:1px solid #e5e7eb; }
    .stamp { position:absolute; top:50%; left:50%; transform: translate(-50%,-50%) rotate(-22deg); font-size:60px; color:rgba(76,175,80,0.08); font-weight:900; pointer-events:none; }

    @media print {
        body { background:#fff; padding:0; }
        .actions { display:none; }
        .id-card { box-shadow:none; }
    }
</style>
</head>
<body>

<div class="actions">
    <a class="btn ghost" href="<?php echo e(base_path() . '/student/dashboard.php'); ?>">← Dashboard</a>
    <button class="btn" onclick="window.print();">Print / Save as PDF</button>
</div>

<div class="card-wrap">
    <div class="id-card">
        <div class="id-head">
            <div>
                <h1>UNIVERSITY OF AGRICULTURE, FAISALABAD</h1>
                <h2>Hostel Identification Card · <?php echo $allocation ? 'Resident' : 'Provisional'; ?></h2>
            </div>
            <div style="text-align:right;font-size:11px;">
                <div>Issued: <?php echo e($issued); ?></div>
                <div>Valid: <?php echo e($validUntil); ?></div>
            </div>
        </div>
        <div class="id-body">
            <div class="photo">
                <?php if ($avatarUrl !== ''): ?>
                    <img src="<?php echo e($avatarUrl); ?>" alt="photo">
                <?php else: ?>
                    <div class="photo-placeholder"><?php echo e(mb_substr((string)$student['name'], 0, 1)); ?></div>
                <?php endif; ?>
            </div>

            <div class="field-block">
                <div class="k">Name</div>
                <div class="v"><?php echo e((string)$student['name']); ?></div>

                <div class="k">Registration #</div>
                <div class="v"><?php echo e((string)$student['reg_no']); ?></div>

                <div class="k">Department</div>
                <div class="v"><?php echo e((string)$student['department']); ?></div>

                <div class="k">Gender</div>
                <div class="v"><?php echo e(ucfirst((string)$student['gender'])); ?></div>

                <?php if ($allocation): ?>
                    <div class="k">Hostel · Room</div>
                    <div class="v"><?php echo e((string)$allocation['hostel_name']); ?> · <?php echo e((string)$allocation['room_number']); ?>
                        <?php if (!empty($allocation['bed_code'])): ?>
                            · Bed <?php echo e((string)$allocation['bed_code']); ?>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="k">Allocation</div>
                    <div class="v" style="color:#c62828;">Pending — apply for a room</div>
                <?php endif; ?>
            </div>

            <div class="qr">
                <img src="<?php echo e($qrUrl); ?>" alt="QR">
                <span>Scan to verify</span>
            </div>
        </div>

        <div class="id-foot">
            <span>Hostel office — UAF, Faisalabad · +92-41-9201045</span>
            <span>Lost? Report at hostel@uaf.edu.pk</span>
        </div>

        <?php if (!$allocation): ?>
            <div class="stamp">PROVISIONAL</div>
        <?php endif; ?>
    </div>
</div>

</body>
</html>
