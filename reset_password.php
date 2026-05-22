<?php
declare(strict_types=1);
/**
 * @file reset_password.php
 * @brief Password reset form — Consumes single-use token from email/link
 * @access public
 * @database password_resets, users
 */

require_once __DIR__ . '/includes/app.php';
require_once __DIR__ . '/includes/models/password_reset_model.php';
require_once __DIR__ . '/includes/models/audit_model.php';

$errors = [];
$success = false;
$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));

if ($token === '') {
    $errors[] = 'Reset token is missing.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$errors) {
    $csrf = $_POST['csrf_token'] ?? null;
    if (!csrf_validate(is_string($csrf) ? $csrf : null)) {
        $errors[] = 'Invalid CSRF token. Please try again.';
    } else {
        $newPassword = (string)($_POST['password'] ?? '');
        $confirm = (string)($_POST['confirm'] ?? '');
        if (mb_strlen($newPassword) < 8) {
            $errors[] = 'New password must be at least 8 characters.';
        } elseif ($newPassword !== $confirm) {
            $errors[] = 'Passwords do not match.';
        } else {
            $pdo = db();
            if (!password_reset_table_ready($pdo)) {
                $errors[] = 'Password reset is unavailable until database/v2_full_upgrade.sql is run.';
            } else {
                $hash = password_hash($newPassword, PASSWORD_DEFAULT);
                $res = password_reset_consume($pdo, $token, $hash);
                if (!$res['ok']) {
                    if ($res['reason'] === 'expired') $errors[] = 'This reset link has expired. Request a new one.';
                    elseif ($res['reason'] === 'used') $errors[] = 'This reset link has already been used.';
                    elseif ($res['reason'] === 'invalid') $errors[] = 'Invalid reset link.';
                    else $errors[] = 'Could not reset password right now.';
                } else {
                    audit_log($pdo, 'password_reset_completed', $res['user_id'], null, 'user', $res['user_id']);
                    $success = true;
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Reset password — Hostel</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="bg-light">
<div class="hero" style="padding:50px 0;">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-5">
                <div class="card shadow-sm border-0" style="border-radius:18px;">
                    <div class="card-body p-4">
                        <h2 class="fw-bold mb-2" style="color:var(--dark);">Reset password</h2>
                        <p class="text-muted small mb-3">Pick a new password (minimum 8 characters).</p>

                        <?php if ($errors): ?>
                            <div class="alert alert-danger small">
                                <ul class="mb-0"><?php foreach ($errors as $err): ?><li><?php echo e($err); ?></li><?php endforeach; ?></ul>
                            </div>
                        <?php endif; ?>

                        <?php if ($success): ?>
                            <div class="alert alert-success small">
                                Password updated. <a href="login.php" class="fw-bold">Login now →</a>
                            </div>
                        <?php else: ?>
                            <form method="post" novalidate>
                                <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                                <input type="hidden" name="token" value="<?php echo e($token); ?>">
                                <div class="mb-3">
                                    <label class="form-label fw-bold">New password</label>
                                    <input type="password" name="password" required class="form-control" autocomplete="new-password" minlength="8">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Confirm password</label>
                                    <input type="password" name="confirm" required class="form-control" autocomplete="new-password" minlength="8">
                                </div>
                                <button class="btn btn-primary w-100" style="background:var(--dark);border:none;">Update password</button>
                            </form>
                        <?php endif; ?>

                        <div class="text-center mt-3 small">
                            <a href="login.php" class="fw-bold" style="color:var(--dark);text-decoration:none;">← Back to login</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
