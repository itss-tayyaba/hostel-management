<?php
declare(strict_types=1);
/**
 * @file admin/notices.php
 * @brief Notices CMS — Create/edit/publish announcements
 * @access admin
 * @database notices
 */

require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/../includes/models/notice_model.php';
require_once __DIR__ . '/../includes/models/audit_model.php';
require_role('admin');

$userName = (string)($_SESSION['name'] ?? 'Admin');
$userRole = 'admin';
$pageTitle = 'Notices';
$activePage = 'admin_notices';

$pdo = db();
$errors = [];
$messages = [];

if (!notice_table_ready($pdo)) {
    $errors[] = 'notices table missing. Run database/v2_full_upgrade.sql first.';
}

$editingNotice = null;
$editingId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
if ($editingId > 0 && notice_table_ready($pdo)) {
    $editingNotice = notice_find($pdo, $editingId);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && notice_table_ready($pdo)) {
    $token = $_POST['csrf_token'] ?? null;
    if (!csrf_validate(is_string($token) ? $token : null)) {
        $errors[] = 'Invalid CSRF token. Please refresh and try again.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        $title = trim((string)($_POST['title'] ?? ''));
        $body = trim((string)($_POST['body'] ?? ''));
        $category = (string)($_POST['category'] ?? 'general');
        $isPublished = !empty($_POST['is_published']);
        $pinned = !empty($_POST['pinned']);

        if ($action === 'create' || $action === 'update') {
            if (mb_strlen($title) < 3) $errors[] = 'Title must be at least 3 characters.';
            if (mb_strlen($body) < 10) $errors[] = 'Body must be at least 10 characters.';
        }

        if (!$errors) {
            try {
                if ($action === 'create') {
                    $id = notice_create($pdo, $title, $body, $category, $isPublished, $pinned, (int)$_SESSION['user_id']);
                    audit_log_from_session($pdo, 'notice_created', 'notice', $id, $title);
                    $messages[] = 'Notice published (#' . $id . ').';
                    redirect('admin/notices.php?ok=created');
                } elseif ($action === 'update') {
                    $id = (int)($_POST['notice_id'] ?? 0);
                    if ($id <= 0 || !notice_update($pdo, $id, $title, $body, $category, $isPublished, $pinned)) {
                        $errors[] = 'Could not update notice.';
                    } else {
                        audit_log_from_session($pdo, 'notice_updated', 'notice', $id, $title);
                        $messages[] = 'Notice updated.';
                        redirect('admin/notices.php?ok=updated');
                    }
                } elseif ($action === 'delete') {
                    $id = (int)($_POST['notice_id'] ?? 0);
                    if ($id > 0 && notice_delete($pdo, $id)) {
                        audit_log_from_session($pdo, 'notice_deleted', 'notice', $id);
                        $messages[] = 'Notice deleted.';
                        redirect('admin/notices.php?ok=deleted');
                    } else {
                        $errors[] = 'Delete failed.';
                    }
                }
            } catch (Throwable $e) {
                $errors[] = 'Operation failed: ' . $e->getMessage();
            }
        }
    }
}

$notices = notice_table_ready($pdo) ? notice_list_all($pdo, 300) : [];
$ok = (string)($_GET['ok'] ?? '');
?>

<?php require_once __DIR__ . '/../includes/layout/header.php'; ?>
<div class="dash-overlay" aria-hidden="true"></div>
<?php require_once __DIR__ . '/../includes/layout/sidebar.php'; ?>

<div class="dash-topbar d-flex align-items-center justify-content-between gap-3">
    <div class="dash-mobile-toggle">
        <button class="btn btn-sm" type="button" onclick="toggleDashboardSidebar()"
                style="background:rgba(27,94,32,0.08); color:var(--dark); border:1px solid rgba(27,94,32,0.18); font-weight:900;">☰</button>
    </div>
    <div class="title"><?php echo e($pageTitle); ?></div>
    <a href="<?php echo e(base_path() . '/notices.php'); ?>" target="_blank" class="btn btn-sm btn-outline-dark fw-bold">View public page</a>
</div>

<div class="dash-content">
    <?php if ($errors): ?>
        <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $err): ?><li><?php echo e($err); ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>
    <?php if ($messages): ?>
        <div class="alert alert-success"><ul class="mb-0"><?php foreach ($messages as $msg): ?><li><?php echo e($msg); ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>
    <?php if ($ok !== ''): ?>
        <div class="alert alert-success">Notice <?php echo e($ok); ?> successfully.</div>
    <?php endif; ?>

    <div class="row g-3">
        <div class="col-lg-5">
            <div class="dash-card">
                <div style="font-weight:900;color:var(--dark);margin-bottom:10px;">
                    <?php echo $editingNotice ? 'Edit notice #' . (int)$editingNotice['id'] : 'Publish a notice'; ?>
                </div>
                <form method="post" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                    <input type="hidden" name="action" value="<?php echo $editingNotice ? 'update' : 'create'; ?>">
                    <?php if ($editingNotice): ?>
                        <input type="hidden" name="notice_id" value="<?php echo (int)$editingNotice['id']; ?>">
                    <?php endif; ?>

                    <div class="mb-2">
                        <label class="form-label fw-bold">Title</label>
                        <input type="text" class="form-control" name="title" required maxlength="200"
                               value="<?php echo e((string)($editingNotice['title'] ?? '')); ?>">
                    </div>
                    <div class="mb-2">
                        <label class="form-label fw-bold">Body</label>
                        <textarea class="form-control" name="body" rows="6" required><?php echo e((string)($editingNotice['body'] ?? '')); ?></textarea>
                    </div>
                    <div class="row g-2">
                        <div class="col-md-6 mb-2">
                            <label class="form-label fw-bold">Category</label>
                            <select class="form-select" name="category">
                                <?php foreach (NOTICE_CATEGORIES as $cat): ?>
                                    <option value="<?php echo $cat; ?>" <?php echo (string)($editingNotice['category'] ?? 'general') === $cat ? 'selected' : ''; ?>>
                                        <?php echo ucfirst($cat); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 mb-2 d-flex align-items-end">
                            <div class="form-check">
                                <input type="checkbox" id="pubChk" class="form-check-input" name="is_published"
                                       <?php echo (!$editingNotice || (int)$editingNotice['is_published'] === 1) ? 'checked' : ''; ?>>
                                <label for="pubChk" class="form-check-label fw-bold">Published</label>
                            </div>
                        </div>
                        <div class="col-md-3 mb-2 d-flex align-items-end">
                            <div class="form-check">
                                <input type="checkbox" id="pinChk" class="form-check-input" name="pinned"
                                       <?php echo $editingNotice && (int)$editingNotice['pinned'] === 1 ? 'checked' : ''; ?>>
                                <label for="pinChk" class="form-check-label fw-bold">Pinned</label>
                            </div>
                        </div>
                    </div>

                    <button class="btn btn-success w-100 fw-bold mt-2"><?php echo $editingNotice ? 'Save changes' : 'Publish notice'; ?></button>
                    <?php if ($editingNotice): ?>
                        <a class="btn btn-outline-secondary w-100 mt-2" href="<?php echo e(base_path() . '/admin/notices.php'); ?>">Cancel edit</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="dash-card">
                <div style="font-weight:900;color:var(--dark);margin-bottom:10px;">All notices</div>
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead class="table-light">
                            <tr><th>Title</th><th>Category</th><th>Status</th><th>Pinned</th><th>Published</th><th class="text-end">Actions</th></tr>
                        </thead>
                        <tbody>
                        <?php if (!$notices): ?>
                            <tr><td colspan="6" class="text-muted">No notices yet.</td></tr>
                        <?php else: foreach ($notices as $n): ?>
                            <tr>
                                <td class="fw-bold"><?php echo e((string)$n['title']); ?></td>
                                <td><span class="badge text-bg-dark"><?php echo e((string)$n['category']); ?></span></td>
                                <td>
                                    <?php if ((int)$n['is_published'] === 1): ?>
                                        <span class="badge text-bg-success">Published</span>
                                    <?php else: ?>
                                        <span class="badge text-bg-secondary">Draft</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo (int)$n['pinned'] === 1 ? '<span class="badge text-bg-warning text-dark">Pinned</span>' : '—'; ?></td>
                                <td style="font-size:13px;"><?php echo e((string)$n['published_at']); ?></td>
                                <td class="text-end">
                                    <a class="btn btn-sm btn-outline-primary" href="<?php echo e(base_path() . '/admin/notices.php?edit=' . (int)$n['id']); ?>">Edit</a>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Delete this notice?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="notice_id" value="<?php echo (int)$n['id']; ?>">
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
    </div>
</div>

<?php require_once __DIR__ . '/../includes/layout/footer.php'; ?>
