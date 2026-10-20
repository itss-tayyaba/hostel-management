<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/app.php';
require_role('admin');

$userName = (string)($_SESSION['name'] ?? 'Admin');
$userRole = (string)($_SESSION['role'] ?? 'admin');
$pageTitle = 'Smart Allocation';
$activePage = 'admin_smart_allocation';

require_once __DIR__ . '/../includes/layout/header.php';
?>
<div class="dash-overlay" aria-hidden="true"></div>
<?php require_once __DIR__ . '/../includes/layout/sidebar.php'; ?>
<div class="dash-topbar d-flex align-items-center justify-content-between gap-3">
    <div class="title"><?php echo e($pageTitle); ?></div>
</div>
<div class="dash-content">
    <div class="alert alert-warning fw-semibold mb-0">
        Smart allocation and ML recommendation are disabled.
    </div>
</div>
<?php require_once __DIR__ . '/../includes/layout/footer.php'; ?>
