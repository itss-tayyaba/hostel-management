<?php
declare(strict_types=1);
/**
 * @file includes/pages/coming_soon.php
 * @brief Placeholder page — Stub for unfinished features
 * @access any
 */

// Expected vars:
// - $pageTitle (string)
// - $activePage (string)
// - $userName (string)
// - $userRole (string)
require_once __DIR__ . '/../../includes/app.php';

$userName = (string)($_SESSION['name'] ?? 'User');
$userRole = (string)($_SESSION['role'] ?? '');
?>

<?php require_once __DIR__ . '/../../includes/layout/header.php'; ?>
<div class="dash-overlay" aria-hidden="true"></div>
<?php require_once __DIR__ . '/../../includes/layout/sidebar.php'; ?>

<div class="dash-topbar d-flex align-items-center justify-content-between gap-3">
    <div class="dash-mobile-toggle">
        <button class="btn btn-sm" type="button" onclick="toggleDashboardSidebar()"
                style="background:rgba(27,94,32,0.08); color:var(--dark); border:1px solid rgba(27,94,32,0.18); font-weight:900;">
            ☰
        </button>
    </div>
    <div class="title"><?php echo e($pageTitle ?? 'Coming Soon'); ?></div>
    <div style="font-size:13px; color:var(--text-muted); font-weight:800;">
        Feature under development
    </div>
</div>

<div class="dash-content">
    <div class="dash-card">
        <div style="font-weight:900;color:var(--dark);margin-bottom:8px;">Coming soon</div>
        <div style="color:var(--text-muted); font-weight:800; font-size:13px; line-height:1.6;">
            This section will be implemented in the next steps (room allocation, complaints, approvals, and payments).
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/layout/footer.php'; ?>

