<?php
declare(strict_types=1);
/**
 * @file setup/create_admin.php
 * @brief Local admin setup — Create/remove verified admin on localhost only
 * @access localhost
 * @database users
 */

/**
 * One-time localhost tool: create or replace an admin account (email pre-verified).
 * Open: http://localhost/hostel/setup/create_admin.php
 * DELETE this file after use on any shared server.
 */

require_once __DIR__ . '/../includes/app.php';

$host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
$isLocal = $host === 'localhost'
    || $host === '127.0.0.1'
    || str_starts_with($host, 'localhost:')
    || str_starts_with($host, '127.0.0.1:');

if (!$isLocal) {
    http_response_code(403);
    echo 'This setup page only runs on localhost. Remove it from production.';
    exit;
}

$messages = [];
$errors = [];
$done = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? null;
    if (!csrf_validate(is_string($token) ? $token : null)) {
        $errors[] = 'Invalid CSRF token.';
    } else {
        $action = (string)($_POST['action'] ?? 'create');
        $name = trim((string)($_POST['name'] ?? ''));
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $password = (string)($_POST['password'] ?? '');
        $removeEmail = strtolower(trim((string)($_POST['remove_email'] ?? '')));

        if ($action === 'remove' && $removeEmail !== '') {
            try {
                $pdo = db();
                $stmt = $pdo->prepare('SELECT id, role FROM users WHERE LOWER(TRIM(email)) = :e LIMIT 1');
                $stmt->execute([':e' => $removeEmail]);
                $row = $stmt->fetch();
                if (!$row) {
                    $errors[] = 'No user found with that email.';
                } elseif ((string)$row['role'] !== 'admin') {
                    $errors[] = 'That user is not an admin. Only admin accounts can be removed here.';
                } else {
                    $del = $pdo->prepare('DELETE FROM users WHERE id = :id AND role = \'admin\'');
                    $del->execute([':id' => (int)$row['id']]);
                    $messages[] = 'Removed admin: ' . $removeEmail;
                }
            } catch (Throwable $e) {
                $errors[] = 'Could not remove user. Check database connection.';
            }
        } elseif ($name === '' || mb_strlen($name) < 2) {
            $errors[] = 'Enter admin full name (min 2 characters).';
        } elseif ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter a valid email.';
        } elseif (mb_strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        } else {
            try {
                $pdo = db();
                $hash = password_hash($password, PASSWORD_DEFAULT);

                $exists = $pdo->prepare('SELECT id FROM users WHERE LOWER(TRIM(email)) = :e LIMIT 1');
                $exists->execute([':e' => $email]);
                $existing = $exists->fetch();

                if ($existing) {
                    $upd = $pdo->prepare(
                        'UPDATE users SET name = :n, password = :p, role = \'admin\',
                         is_active = 1, email_verified_at = NOW(), email_verify_token = NULL
                         WHERE id = :id'
                    );
                    $upd->execute([
                        ':n' => $name,
                        ':p' => $hash,
                        ':id' => (int)$existing['id'],
                    ]);
                    $messages[] = 'Updated existing user to verified admin: ' . $email;
                } else {
                    $ins = $pdo->prepare(
                        'INSERT INTO users (name, email, password, role, is_active, email_verified_at, email_verify_token)
                         VALUES (:n, :e, :p, \'admin\', 1, NOW(), NULL)'
                    );
                    $ins->execute([':n' => $name, ':e' => $email, ':p' => $hash]);
                    $messages[] = 'Created new verified admin: ' . $email;
                }
                $done = true;
            } catch (Throwable $e) {
                $errors[] = 'Database error. Import database/v2_full_upgrade.sql if columns are missing.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create admin — localhost setup</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body class="bg-light">
<div class="container py-5" style="max-width:520px;">
    <div class="card shadow-sm border-0" style="border-radius:18px;">
        <div class="card-body p-4">
            <h1 class="h4 fw-bold mb-2" style="color:var(--dark);">Admin setup (localhost only)</h1>
            <p class="text-muted small mb-4">
                No real email inbox needed. Admin accounts skip email verification at login.
                Use any email you will remember (e.g. <code>admin@hostel.local</code>).
            </p>

            <?php foreach ($errors as $err): ?>
                <div class="alert alert-danger py-2"><?php echo e($err); ?></div>
            <?php endforeach; ?>
            <?php foreach ($messages as $msg): ?>
                <div class="alert alert-success py-2"><?php echo e($msg); ?></div>
            <?php endforeach; ?>

            <?php if ($done): ?>
                <a href="../login.php" class="btn btn-success w-100 fw-bold">Go to login</a>
            <?php else: ?>
                <form method="post" class="mb-4">
                    <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                    <input type="hidden" name="action" value="create">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Admin name</label>
                        <input type="text" name="name" class="form-control" required placeholder="Hostel Admin">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Email (login)</label>
                        <input type="email" name="email" class="form-control" required placeholder="admin@hostel.local">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Password</label>
                        <input type="password" name="password" class="form-control" required minlength="8" autocomplete="new-password">
                    </div>
                    <button type="submit" class="btn w-100 fw-bold text-white" style="background:var(--dark);">Create / update admin</button>
                </form>

                <hr>
                <p class="small text-muted fw-semibold mb-2">Remove old admin by email</p>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                    <input type="hidden" name="action" value="remove">
                    <div class="input-group">
                        <input type="email" name="remove_email" class="form-control" placeholder="admin@gmail.com" required>
                        <button type="submit" class="btn btn-outline-danger">Remove</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <p class="text-center text-muted small mt-3">Delete <code>setup/create_admin.php</code> after finishing.</p>
</div>
</body>
</html>
