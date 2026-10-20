<?php
declare(strict_types=1);
/**
 * @file includes/layout/sidebar.php
 * @brief Role navigation — Admin / student / warden menu links
 * @access layout
 */

require_once __DIR__ . '/../app.php';

// Expected vars:
// - $userName (string)
// - $userRole (string) => 'admin' | 'warden' | 'student'
// - $activePage (string) => key used for active nav highlighting
?>

<?php $base = base_path(); ?>
<?php
$asideClasses = ['dash-sidebar', 'dash-sidebar-pro'];
$__light_sidebar = !empty($adminSidebarLight);
if ($__light_sidebar) {
    $asideClasses[] = 'dash-sidebar-light';
}

$__student_sidebar_modern = (($userRole ?? '') === 'student')
    && isset($dashShellClass)
    && trim((string)$dashShellClass) === 'student-shell-ref';
if ($__student_sidebar_modern) {
    $asideClasses[] = 'dash-sidebar-student-ref';
}
?>

<aside class="<?php echo e(implode(' ', $asideClasses)); ?>" id="dashSidebar" aria-label="Sidebar navigation">
    <div class="dash-brand">
        <span class="dash-brand-mark" aria-hidden="true"></span>
        <span>
            UAF Hostel
            <div class="dash-brand-sub">Management System</div>
        </span>
    </div>

    <div class="user-box user-box-pro">
        <div class="user-avatar-ring" aria-hidden="true"><?php
            $uname = (string)($userName ?? 'User');
            $ini = mb_substr($uname !== '' ? $uname : 'U', 0, 1, 'UTF-8');
            echo e(mb_strtoupper($ini, 'UTF-8')); ?></div>
        <div class="user-box-text">
            <div class="user-name"><?php echo e($userName ?? 'User'); ?></div>
            <div class="user-role"><?php echo e($userRole ?? ''); ?></div>
        </div>
    </div>

    <nav>
        <?php if (($userRole ?? '') === 'admin'): ?>
            <a href="<?php echo e($base . '/admin/dashboard.php'); ?>" class="dash-nav-link <?php echo ($activePage ?? '') === 'admin_dashboard' ? 'active' : ''; ?>"><i class="bi bi-grid-1x2-fill"></i><span>Dashboard</span></a>
            <a href="<?php echo e($base . '/admin/students.php'); ?>" class="dash-nav-link <?php echo ($activePage ?? '') === 'admin_students' ? 'active' : ''; ?>"><i class="bi bi-people-fill"></i><span>Manage Students</span></a>
            <a href="<?php echo e($base . '/admin/applications.php'); ?>" class="dash-nav-link <?php echo ($activePage ?? '') === 'admin_applications' ? 'active' : ''; ?>"><i class="bi bi-door-open"></i><span>Room Applications</span></a>
            <a href="<?php echo e($base . '/admin/rooms.php'); ?>" class="dash-nav-link <?php echo ($activePage ?? '') === 'admin_rooms' ? 'active' : ''; ?>"><i class="bi bi-building"></i><span>Rooms</span></a>
            <a href="<?php echo e($base . '/admin/complaints.php'); ?>" class="dash-nav-link <?php echo ($activePage ?? '') === 'admin_complaints' ? 'active' : ''; ?>"><i class="bi bi-chat-left-text-fill"></i><span>Complaints</span></a>
            <a href="<?php echo e($base . '/admin/room_changes.php'); ?>" class="dash-nav-link <?php echo ($activePage ?? '') === 'admin_changes' ? 'active' : ''; ?>"><i class="bi bi-arrow-left-right"></i><span>Room Changes</span></a>
            <a href="<?php echo e($base . '/admin/payments.php'); ?>" class="dash-nav-link <?php echo ($activePage ?? '') === 'admin_payments' ? 'active' : ''; ?>"><i class="bi bi-cash-coin"></i><span>Payments</span></a>
            <a href="<?php echo e($base . '/admin/notices.php'); ?>" class="dash-nav-link <?php echo ($activePage ?? '') === 'admin_notices' ? 'active' : ''; ?>"><i class="bi bi-megaphone-fill"></i><span>Notices</span></a>
            <a href="<?php echo e($base . '/admin/wardens.php'); ?>" class="dash-nav-link <?php echo ($activePage ?? '') === 'admin_wardens' ? 'active' : ''; ?>"><i class="bi bi-person-badge-fill"></i><span>Wardens</span></a>
            <a href="<?php echo e($base . '/admin/audit_log.php'); ?>" class="dash-nav-link <?php echo ($activePage ?? '') === 'admin_audit' ? 'active' : ''; ?>"><i class="bi bi-shield-lock"></i><span>Audit Log</span></a>
            <a href="<?php echo e($base . '/admin/reports.php'); ?>" class="dash-nav-link <?php echo ($activePage ?? '') === 'admin_reports' ? 'active' : ''; ?>"><i class="bi bi-graph-up-arrow"></i><span>Reports</span></a>
        <?php elseif (($userRole ?? '') === 'warden'): ?>
            <a href="<?php echo e($base . '/warden.php'); ?>" class="dash-nav-link <?php echo ($activePage ?? '') === 'warden_dashboard' ? 'active' : ''; ?>"><i class="bi bi-speedometer2"></i><span>Warden Dashboard</span></a>
            <a href="<?php echo e($base . '/warden.php'); ?>#notices" class="dash-nav-link"><i class="bi bi-megaphone-fill"></i><span>Notices</span></a>
            <a href="<?php echo e($base . '/warden/students.php'); ?>" class="dash-nav-link <?php echo ($activePage ?? '') === 'warden_students' ? 'active' : ''; ?>"><i class="bi bi-people"></i><span>Students (My Hostel)</span></a>
        <?php else: ?>
            <a href="<?php echo e($base . '/student/dashboard.php'); ?>" class="dash-nav-link <?php echo ($activePage ?? '') === 'student_dashboard' ? 'active' : ''; ?>"><i class="bi bi-grid-1x2-fill"></i><span>Dashboard</span></a>
            <a href="<?php echo e($base . '/student/dashboard.php'); ?>#notices" class="dash-nav-link"><i class="bi bi-megaphone-fill"></i><span>Notices</span></a>
            <a href="<?php echo e($base . '/student/apply.php'); ?>" class="dash-nav-link <?php echo ($activePage ?? '') === 'student_apply' ? 'active' : ''; ?>"><i class="bi bi-house-add"></i><span>Apply for Hostel</span></a>
            <a href="<?php echo e($base . '/student/complaints.php'); ?>" class="dash-nav-link <?php echo ($activePage ?? '') === 'student_complaints' ? 'active' : ''; ?>"><i class="bi bi-chat-square-text"></i><span>Complaints</span></a>
            <a href="<?php echo e($base . '/student/room_change.php'); ?>" class="dash-nav-link <?php echo ($activePage ?? '') === 'student_room_change' ? 'active' : ''; ?>"><i class="bi bi-arrow-left-right"></i><span>Room Change</span></a>
            <a href="<?php echo e($base . '/student/payments.php'); ?>" class="dash-nav-link <?php echo ($activePage ?? '') === 'student_payments' ? 'active' : ''; ?>"><i class="bi bi-wallet2"></i><span>Payments</span></a>
            <a href="<?php echo e($base . '/student/fee_voucher.php'); ?>" class="dash-nav-link" target="_blank"><i class="bi bi-receipt"></i><span>Fee Voucher</span></a>
            <a href="<?php echo e($base . '/student/help_chatbot.php'); ?>" class="dash-nav-link <?php echo ($activePage ?? '') === 'student_help_chat' ? 'active' : ''; ?>"><i class="bi bi-robot"></i><span>Help assistant</span></a>
        <?php endif; ?>
    </nav>

    <?php if (!empty($__student_sidebar_modern)): ?>
        <div class="student-sidebar-promo">
            <div class="student-sidebar-promo-title">Get the hostel app</div>
            <p class="student-sidebar-promo-text">Room updates and notices on the go.</p>
            <a class="student-sidebar-promo-btn" href="<?php echo e($base . '/student/dashboard.php'); ?>#notices">View notices</a>
        </div>
    <?php endif; ?>

    <div class="dash-sidebar-footer">
        <a href="<?php echo e($base . '/logout.php'); ?>" class="dash-sidebar-logout <?php echo $__light_sidebar ? 'dash-sidebar-logout-light' : ''; ?>"><i class="bi bi-box-arrow-right me-2"></i>Logout</a>
    </div>
</aside>

<div class="dash-main" style="display:flex;flex-direction:column;">

