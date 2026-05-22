<?php
declare(strict_types=1);
/**
 * @file forgot_password.php
 * @brief Password recovery — Issues reset token (dev link on screen)
 * @access public
 * @database password_resets
 */

require_once __DIR__ . '/includes/app.php';
require_once __DIR__ . '/includes/models/password_reset_model.php';
require_once __DIR__ . '/includes/models/audit_model.php';

$errors = [];
$displayLink = '';
$displayMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? null;
    if (!csrf_validate(is_string($token) ? $token : null)) {
        $errors[] = 'Invalid CSRF token. Please try again.';
    } else {
        $email = trim((string)($_POST['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter a valid email address.';
        } else {
            $pdo = db();
            if (!password_reset_table_ready($pdo)) {
                $errors[] = 'Password reset is unavailable until database/v2_full_upgrade.sql is run.';
            } else {
                $stmt = $pdo->prepare('SELECT id, name FROM users WHERE LOWER(TRIM(email)) = :e LIMIT 1');
                $stmt->execute([':e' => strtolower($email)]);
                $user = $stmt->fetch();

                // Generic message so we don't leak account existence.
                $displayMessage = 'If that email exists in our system, a reset link has been generated below.';

                if ($user) {
                    $raw = password_reset_issue($pdo, (int)$user['id']);
                    audit_log($pdo, 'password_reset_issued', (int)$user['id'], null, 'user', (int)$user['id']);
                    $base = base_path();
                    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
                    $displayLink = $scheme . '://' . $host . $base . '/reset_password.php?token=' . $raw;
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
    <title>Forgot password — Hostel</title>
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
                        <h2 class="fw-bold mb-2" style="color:var(--dark);">Forgot password</h2>
                        <p class="text-muted small mb-3">Enter your account email. A reset link (valid 30 min) will be shown on-screen for this dev environment.</p>

                        <?php if ($errors): ?>
                            <div class="alert alert-danger small">
                                <ul class="mb-0"><?php foreach ($errors as $err): ?><li><?php echo e($err); ?></li><?php endforeach; ?></ul>
                            </div>
                        <?php endif; ?>

                        <?php if ($displayMessage !== ''): ?>
                            <div class="alert alert-success small"><?php echo e($displayMessage); ?></div>
                        <?php endif; ?>

                        <?php if ($displayLink !== ''): ?>
                            <div class="alert alert-info small">
                                <div class="fw-bold mb-1">Your reset link:</div>
                                <a href="<?php echo e($displayLink); ?>" style="word-break:break-all;"><?php echo e($displayLink); ?></a>
                            </div>
                        <?php endif; ?>

                        <form method="post" novalidate>
                            <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Email</label>
                                <input type="email" name="email" required class="form-control">
                            </div>
                            <button class="btn btn-primary w-100" style="background:var(--dark);border:none;">Send reset link</button>
                        </form>

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
