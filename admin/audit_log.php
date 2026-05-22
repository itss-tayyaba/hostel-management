<?php
declare(strict_types=1);
/**
 * @file admin/audit_log.php
 * @brief Audit log viewer — Filter security and business events
 * @access admin
 * @database audit_log
 */

require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/../includes/models/audit_model.php';
require_role('admin');

$userName = (string)($_SESSION['name'] ?? 'Admin');
$userRole = (string)($_SESSION['role'] ?? 'admin');
$pageTitle = 'Audit Log';
$activePage = 'admin_audit';

$pdo = db();
$ready = audit_table_ready($pdo);

$actionFilter = isset($_GET['action']) ? trim((string)$_GET['action']) : '';
$userFilter = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
$limit = isset($_GET['limit']) ? max(20, min(1000, (int)$_GET['limit'])) : 200;

$rows = $ready ? audit_log_recent($pdo, $limit, $actionFilter !== '' ? $actionFilter : null, $userFilter > 0 ? $userFilter : null) : [];

$actionList = [];
if ($ready) {
    try {
        $actionList = $pdo->query('SELECT DISTINCT action FROM audit_log ORDER BY action ASC')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
        $actionList = [];
    }
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
        Sensitive-action history
    </div>
</div>

<div class="dash-content">
    <?php if (!$ready): ?>
        <div class="alert alert-warning">
            Audit log table not found. Run <code>database/v2_full_upgrade.sql</code> first.
        </div>
    <?php endif; ?>

    <div class="dash-card mb-3">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label fw-bold">Action</label>
                <select class="form-select" name="action">
                    <option value="">All actions</option>
                    <?php foreach ($actionList as $act): ?>
                        <option value="<?php echo e((string)$act); ?>" <?php echo $actionFilter === $act ? 'selected' : ''; ?>>
                            <?php echo e((string)$act); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-bold">User ID</label>
                <input type="number" class="form-control" name="user_id" min="0" value="<?php echo $userFilter > 0 ? (int)$userFilter : ''; ?>" />
            </div>
            <div class="col-md-3">
                <label class="form-label fw-bold">Rows</label>
                <select class="form-select" name="limit">
                    <?php foreach ([50, 100, 200, 500, 1000] as $opt): ?>
                        <option value="<?php echo $opt; ?>" <?php echo $limit === $opt ? 'selected' : ''; ?>><?php echo $opt; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <button class="btn btn-primary w-100" style="background:var(--dark);border:none;">Filter</button>
            </div>
        </form>
    </div>

    <div class="dash-card">
        <div style="font-weight:900;color:var(--dark);margin-bottom:10px;">Latest audit entries (newest first)</div>
        <div class="table-responsive">
            <table class="table table-borderless align-middle">
                <thead>
                <tr>
                    <th>When</th>
                    <th>User</th>
                    <th>Role</th>
                    <th>Action</th>
                    <th>Entity</th>
                    <th>Detail</th>
                    <th>IP</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="7" style="color:var(--text-muted);font-weight:800;">No audit entries match.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td style="font-weight:700;font-size:13px;"><?php echo e((string)$r['created_at']); ?></td>
                            <td style="font-weight:900;">
                                <?php echo e((string)($r['user_name'] ?? '—')); ?>
                                <div class="small text-muted"><?php echo e((string)($r['user_email'] ?? '')); ?></div>
                            </td>
                            <td><?php echo e((string)($r['actor_role'] ?? '')); ?></td>
                            <td><span class="badge text-bg-dark"><?php echo e((string)$r['action']); ?></span></td>
                            <td style="font-size:13px;">
                                <?php echo e((string)($r['entity_type'] ?? '')); ?><?php echo !empty($r['entity_id']) ? '#' . (int)$r['entity_id'] : ''; ?>
                            </td>
                            <td style="font-size:13px;color:var(--text-muted);font-weight:700;">
                                <?php echo e((string)($r['detail'] ?? '')); ?>
                            </td>
                            <td style="font-size:12px;color:var(--text-muted);"><?php echo e((string)($r['ip_address'] ?? '')); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/layout/footer.php'; ?>
