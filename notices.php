<?php
declare(strict_types=1);
/**
 * @file notices.php
 * @brief Public notices — Read-only list of published announcements
 * @access public
 * @database notices
 */

require_once __DIR__ . '/includes/app.php';
require_once __DIR__ . '/includes/models/notice_model.php';

$pdo = db();
$notices = notice_table_ready($pdo) ? notice_list_public($pdo, 50) : [];

$catColours = [
    'urgent'   => ['#fce4ec', '#c62828'],
    'fee'      => ['#fff8e1', '#f9a825'],
    'allotment'=> ['#e8f5e9', '#1b5e20'],
    'rules'    => ['#e3f2fd', '#0d47a1'],
    'event'    => ['#ede7f6', '#4527a0'],
    'general'  => ['#f5f5f5', '#424242'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Notices — UAF Hostel Portal</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" />
    <link rel="stylesheet" href="assets/css/landing.css" />
    <style>
        body { background:#f7f8fb; }
        .notice-hero { background: linear-gradient(135deg,#1b5e20,#4caf50); color:#fff; padding:60px 0 40px; }
        .notice-hero h1 { font-weight:800; }
        .notice-list-page { max-width: 900px; margin: -30px auto 60px; }
        .notice-card { background:#fff; border-radius:14px; padding:18px 22px; margin-bottom:14px; box-shadow:0 4px 14px rgba(0,0,0,0.05); border-left:5px solid #1b5e20; }
        .notice-card.pinned { border-left-color:#f9a825; background:#fffdf6; }
        .notice-meta { display:flex; justify-content:space-between; flex-wrap:wrap; gap:6px; font-size:12px; color:#777; margin-bottom:6px; }
        .notice-cat { display:inline-block; padding:2px 10px; border-radius:999px; font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:.5px; }
        .empty { text-align:center; color:#888; padding:40px 0; font-weight:600; }
        .topbar { display:flex; justify-content:space-between; align-items:center; max-width:900px; margin: 14px auto 0; padding: 0 10px; }
    </style>
</head>
<body>

<div class="notice-hero">
    <div class="container">
        <h1 class="display-6">All notices</h1>
        <p class="mb-0">Stay updated with hostel announcements, fee reminders, rules and allotment news.</p>
    </div>
</div>

<div class="topbar">
    <a href="index.php" class="btn btn-sm btn-outline-light" style="color:#1b5e20;border-color:#1b5e20;font-weight:700;">← Back to home</a>
    <a href="login.php" class="btn btn-sm" style="background:#1b5e20;color:#fff;font-weight:700;">Student login</a>
</div>

<div class="container notice-list-page">
    <?php if (!$notices): ?>
        <div class="notice-card empty">No notices have been published yet.</div>
    <?php else: ?>
        <?php foreach ($notices as $n): ?>
            <?php
            $cat = (string)$n['category'];
            [$bg, $fg] = $catColours[$cat] ?? $catColours['general'];
            ?>
            <div class="notice-card<?php echo (int)$n['pinned'] === 1 ? ' pinned' : ''; ?>">
                <div class="notice-meta">
                    <div>
                        <span class="notice-cat" style="background: <?php echo e($bg); ?>; color: <?php echo e($fg); ?>;">
                            <?php echo e($cat); ?>
                        </span>
                        <?php if ((int)$n['pinned'] === 1): ?>
                            <span class="notice-cat" style="background:#fff3cd;color:#7a4f01;">📌 Pinned</span>
                        <?php endif; ?>
                    </div>
                    <span><?php echo e(date('d M Y · H:i', strtotime((string)$n['published_at']) ?: time())); ?></span>
                </div>
                <h3 style="margin:0 0 6px;font-size:18px;color:#1b1b1b;font-weight:800;"><?php echo e((string)$n['title']); ?></h3>
                <div style="color:#444;font-size:14px;line-height:1.6;white-space:pre-line;"><?php echo nl2br(e((string)$n['body'])); ?></div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
