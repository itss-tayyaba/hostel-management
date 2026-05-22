<?php
declare(strict_types=1);
/**
 * @file login.php
 * @brief Authentication — Email/password login, throttle, role redirect, email verify gate
 * @access public
 * @database users, login_attempts
 */

require_once __DIR__ . '/includes/app.php';
require_once __DIR__ . '/includes/models/audit_model.php';
require_once __DIR__ . '/includes/auth_throttle.php';

// --- Redirect if session already active -------------------------------------
if (is_logged_in()) {
    $role = (string)($_SESSION['role'] ?? '');
    if ($role === 'warden') {
        try {
            if (!warden_has_active_assignment(db(), (int)$_SESSION['user_id'])) {
                unset($_SESSION['user_id'], $_SESSION['name'], $_SESSION['email'], $_SESSION['role'], $_SESSION['warden_hostel_id'], $_SESSION['warden_hostel_name']);
                redirect('login.php?auth=warden_not_assigned');
            }
        } catch (Throwable $e) {
            unset($_SESSION['user_id'], $_SESSION['name'], $_SESSION['email'], $_SESSION['role']);
            redirect('login.php?auth=warden_not_assigned');
        }
        redirect('warden.php');
    }
    if ($role === 'admin') {
        redirect('admin/dashboard.php');
    }
    redirect('student/dashboard.php');
}

$errors = [];
$email = '';

// --- POST: validate credentials, throttle, verification, then start session -
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? null;
    if (!csrf_validate(is_string($token) ? $token : null)) {
        $errors[] = 'Invalid CSRF token. Please try again.';
    } else {
        $email = trim((string)($_POST['email'] ?? ''));
        $password = (string)($_POST['password'] ?? '');

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter a valid email address.';
        }
        if ($password === '') {
            $errors[] = 'Enter your password.';
        }

        if (!$errors) {
            $pdo = db();
            $emailLower = strtolower(trim($email));

            $throttle = auth_throttle_check($pdo, $emailLower);
            if ($throttle['blocked']) {
                $errors[] = $throttle['reason'];
                audit_log($pdo, 'login_blocked_throttle', null, null, 'user', null, 'email=' . $emailLower);
            } else {
                $stmt = $pdo->prepare('
                    SELECT id, name, email, password, role,
                           COALESCE(is_active, 1) AS is_active,
                           email_verified_at
                    FROM users
                    WHERE LOWER(TRIM(email)) = :email
                    LIMIT 1
                ');
                $stmt->execute([':email' => $emailLower]);
                $user = $stmt->fetch();

                $roleIncoming = isset($user['role']) ? (string)$user['role'] : '';
                $isActive = isset($user['is_active']) ? (int)$user['is_active'] === 1 : true;
                $validCreds = $user && verify_user_password($pdo, (int)$user['id'], $password, (string)$user['password']);

                if (!$validCreds) {
                    auth_throttle_record($pdo, $emailLower, false);
                    audit_log($pdo, 'login_failed', $user ? (int)$user['id'] : null, $roleIncoming ?: null, 'user', $user ? (int)$user['id'] : null, 'email=' . $emailLower);
                    $errors[] = 'Invalid email or password.';
                } elseif (!$isActive) {
                    auth_throttle_record($pdo, $emailLower, false);
                    audit_log($pdo, 'login_blocked_inactive', (int)$user['id'], $roleIncoming, 'user', (int)$user['id']);
                    $errors[] = 'Your account is disabled. Contact the administrator.';
                } elseif ($roleIncoming === 'warden' && !warden_has_active_assignment($pdo, (int)$user['id'])) {
                    auth_throttle_record($pdo, $emailLower, false);
                    audit_log($pdo, 'login_blocked_warden_unassigned', (int)$user['id'], 'warden', 'user', (int)$user['id']);
                    $errors[] = 'Your warden account is not assigned to a hostel yet. Ask the hostel administrator.';
                } elseif (
                    app_env_bool('REQUIRE_EMAIL_VERIFICATION', true)
                    && $roleIncoming !== 'admin'
                    && !user_email_verified($pdo, (int)$user['id'])
                ) {
                    auth_throttle_record($pdo, $emailLower, false);
                    audit_log($pdo, 'login_blocked_unverified', (int)$user['id'], $roleIncoming, 'user', (int)$user['id']);
                    $errors[] = 'Please verify your email before logging in. Check your inbox or contact the hostel office.';
                } else {
                    auth_throttle_record($pdo, $emailLower, true);
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = (int)$user['id'];
                    $_SESSION['name'] = (string)$user['name'];
                    $_SESSION['email'] = (string)$user['email'];
                    $_SESSION['role'] = $roleIncoming;

                    audit_log($pdo, 'login_success', (int)$user['id'], $roleIncoming, 'user', (int)$user['id']);

                    if ($roleIncoming === 'admin') {
                        redirect('admin/dashboard.php');
                    }
                    if ($roleIncoming === 'warden') {
                        redirect('warden.php');
                    }
                    redirect('student/dashboard.php');
                }
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Login — Hostel Management System</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" />
    <link rel="stylesheet" href="assets/css/style.css" />
</head>
<body class="bg-light">

<div class="hero" style="padding:55px 0 35px;">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-12 col-md-5">
                <div class="card shadow-sm border-0" style="border-radius:18px;">
                    <div class="card-body p-4">
                        <h2 class="fw-bold mb-2" style="color:var(--dark);">Student / Warden / Admin Login</h2>
                        <p class="text-muted mb-4" style="font-size:13px;">
                            Enter your email and password to access your hostel dashboard.
                        </p>

                        <?php if (!empty($errors)): ?>
                            <div class="alert alert-danger">
                                <ul class="mb-0">
                                    <?php foreach ($errors as $err): ?>
                                        <li><?php echo e($err); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>

                        <?php if (isset($_GET['registered']) && $_GET['registered'] === '1'): ?>
                            <div class="alert alert-success">
                                <?php if (isset($_GET['role']) && $_GET['role'] === 'warden_pending'): ?>
                                    Warden registration submitted. Admin must assign your hostel before you can access the warden dashboard.
                                <?php else: ?>
                                    Registration successful. Please log in.
                                <?php endif; ?>
                                <?php if (!empty($_SESSION['verify_link_once'])): ?>
                                    <hr class="my-2">
                                    <div class="small">
                                        <strong>Verify your email (dev env):</strong>
                                        <a href="<?php echo e((string)$_SESSION['verify_link_once']); ?>" style="word-break:break-all;"><?php echo e((string)$_SESSION['verify_link_once']); ?></a>
                                    </div>
                                    <?php unset($_SESSION['verify_link_once']); ?>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <?php if (isset($_GET['auth']) && $_GET['auth'] === 'forbidden'): ?>
                            <div class="alert alert-warning mb-3">
                                You don’t have access to that page. Please log in with the correct role.
                            </div>
                        <?php endif; ?>

                        <?php if (isset($_GET['auth']) && $_GET['auth'] === 'warden_not_assigned'): ?>
                            <div class="alert alert-warning mb-3">
                                Warden access requires an administrator to assign your account to a hostel.
                                Visit the admin portal or contact the hostel office after setup.
                            </div>
                        <?php endif; ?>

                        <?php if (isset($_GET['verified']) && $_GET['verified'] === '1'): ?>
                            <div class="alert alert-success mb-3">
                                Email verified successfully. You can log in now.
                            </div>
                        <?php endif; ?>

                        <form method="post" novalidate>
                            <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>" />

                            <div class="mb-3">
                                <label class="form-label fw-bold">Email</label>
                                <input
                                    type="email"
                                    class="form-control"
                                    name="email"
                                    required
                                    value="<?php echo e($email); ?>"
                                    autocomplete="email"
                                />
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-bold">Password</label>
                                <input
                                    type="password"
                                    class="form-control"
                                    name="password"
                                    required
                                    autocomplete="current-password"
                                />
                            </div>

                            <button type="submit" class="btn btn-primary w-100"
                                    style="background:var(--dark); border:none;">
                                Login
                            </button>

                            <div class="text-center mt-3" style="font-size:13px;">
                                <a href="forgot_password.php" class="fw-bold" style="color:var(--dark); text-decoration:none;">
                                    Forgot password?
                                </a>
                            </div>

                            <div class="text-center mt-2" style="font-size:13px;">
                                Don’t have an account?
                                <a href="register.php" class="fw-bold" style="color:var(--light); text-decoration:none;">
                                    Register
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

