<?php
declare(strict_types=1);
/**
 * @file verify_email.php
 * @brief Email verification — Activates account via token in URL
 * @access public
 * @database users
 */

require_once __DIR__ . '/includes/app.php';
require_once __DIR__ . '/includes/models/audit_model.php';

$token = trim((string)($_GET['token'] ?? ''));
$ok = false;
$msg = 'Invalid or expired verification link.';

if ($token !== '') {
    try {
        $pdo = db();
        $stmt = $pdo->prepare(
            'SELECT id, email, email_verified_at FROM users WHERE email_verify_token = :t LIMIT 1'
        );
        $stmt->execute([':t' => $token]);
        $user = $stmt->fetch();
        if ($user) {
            if (!empty($user['email_verified_at'])) {
                $msg = 'Email is already verified. You can log in.';
                $ok = true;
            } else {
                $upd = $pdo->prepare(
                    'UPDATE users SET email_verified_at = NOW(), email_verify_token = NULL WHERE id = :id'
                );
                $upd->execute([':id' => (int)$user['id']]);
                audit_log($pdo, 'email_verified', (int)$user['id'], null, 'user', (int)$user['id']);
                $ok = true;
                $msg = 'Email verified successfully. You can log in now.';
            }
        }
    } catch (Throwable $e) {
        $msg = 'Verification is unavailable. Run database/v2_full_upgrade.sql first.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Email verification — Hostel</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="bg-light">
<div class="hero" style="padding:60px 0;">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-5">
                <div class="card shadow-sm border-0" style="border-radius:18px;">
                    <div class="card-body p-4 text-center">
                        <h2 class="fw-bold mb-3" style="color:var(--dark);">
                            <?php echo $ok ? '✅ Verified' : '❌ Verification failed'; ?>
                        </h2>
                        <p class="mb-3"><?php echo e($msg); ?></p>
                        <a href="login.php<?php echo $ok ? '?verified=1' : ''; ?>" class="btn btn-primary w-100" style="background:var(--dark);border:none;">Go to login</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
