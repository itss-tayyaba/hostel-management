<?php
declare(strict_types=1);
/**
 * @file register.php
 * @brief Student registration — Creates user + student row, verification token
 * @access public
 * @database users, students
 */

require_once __DIR__ . '/includes/app.php';
require_once __DIR__ . '/includes/models/audit_model.php';

if (is_logged_in()) {
    redirect('index.php?auth=already');
}

$errors = [];
$values = [
    'role' => 'student',
    'name' => '',
    'email' => '',
    'phone' => '',
    'reg_no' => '',
    'department' => '',
    'gender' => 'male',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? null;
    if (!csrf_validate(is_string($token) ? $token : null)) {
        $errors[] = 'Invalid CSRF token. Please try again.';
    } else {
        $name = trim((string)($_POST['name'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        // Public registration is student-only; wardens are created by administrators.
        $role = 'student';
        $phone = trim((string)($_POST['phone'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');

        $reg_no = trim((string)($_POST['reg_no'] ?? ''));
        $department = trim((string)($_POST['department'] ?? ''));
        $gender = normalize_user_gender((string)($_POST['gender'] ?? ''));

        $values = [
            'role' => 'student',
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'reg_no' => $reg_no,
            'department' => $department,
            'gender' => $gender ?? 'male',
        ];

        if ($name === '' || mb_strlen($name) < 2) {
            $errors[] = 'Enter your full name.';
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter a valid email address.';
        }
        if ($password === '' || mb_strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        }
        if ($password !== $confirm) {
            $errors[] = 'Passwords do not match.';
        }
        if ($reg_no === '') {
            $errors[] = 'Enter your registration number.';
        }
        if ($department === '') {
            $errors[] = 'Enter your department.';
        }
        if ($gender === null) {
            $errors[] = 'Select gender (male or female).';
        }

        if (!$errors) {
            $pdo = db();

            try {
                $pdo->beginTransaction();

                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                $emailLower = strtolower($email);
                $verifyToken = bin2hex(random_bytes(24));

                $stmtUser = $pdo->prepare('
                    INSERT INTO users (name, email, password, role, gender, phone, email_verify_token)
                    VALUES (:name, :email, :password, :role, :gender, :phone, :token)
                ');
                try {
                    $stmtUser->execute([
                        ':name' => $name,
                        ':email' => $emailLower,
                        ':password' => $passwordHash,
                        ':role' => $role,
                        ':gender' => $gender,
                        ':phone' => $phone !== '' ? $phone : null,
                        ':token' => $verifyToken,
                    ]);
                } catch (PDOException $ex2) {
                    // If new columns haven't been migrated yet, fall back to legacy insert.
                    $stmtUser = $pdo->prepare('
                        INSERT INTO users (name, email, password, role, gender, phone)
                        VALUES (:name, :email, :password, :role, :gender, :phone)
                    ');
                    $stmtUser->execute([
                        ':name' => $name,
                        ':email' => $emailLower,
                        ':password' => $passwordHash,
                        ':role' => $role,
                        ':gender' => $gender,
                        ':phone' => $phone !== '' ? $phone : null,
                    ]);
                    $verifyToken = '';
                }

                $userId = (int)$pdo->lastInsertId();

                if ($role === 'student') {
                    $stmtStudent = $pdo->prepare('
                        INSERT INTO students (user_id, reg_no, department, gender)
                        VALUES (:user_id, :reg_no, :department, :gender)
                    ');
                    $stmtStudent->execute([
                        ':user_id' => $userId,
                        ':reg_no' => $reg_no,
                        ':department' => $department,
                        ':gender' => $gender,
                    ]);
                }

                $pdo->commit();
                audit_log($pdo, 'user_registered', $userId, $role, 'user', $userId, 'email=' . $emailLower);

                if ($verifyToken !== '') {
                    $_SESSION['verify_link_once'] = base_path() . '/verify_email.php?token=' . $verifyToken;
                }
                if ($role === 'warden') {
                    redirect('login.php?registered=1&role=warden_pending');
                }
                redirect('login.php?registered=1&role=student');
            } catch (PDOException $ex) {
                if ($pdo->inTransaction()) $pdo->rollBack();

                // Show duplicate warning only for UNIQUE constraint violations.
                if (($ex->getCode() === '23000')) {
                    $errors[] = 'Registration failed. Email or registration number may already exist.';
                } else {
                    $errors[] = 'Registration failed due to a server error. Please try again.';
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
    <title>Register — Hostel Management System</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" />
    <link rel="stylesheet" href="assets/css/style.css" />
</head>
<body class="bg-light">

<div class="hero" style="padding:55px 0 35px;">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-12 col-md-7">
                <div class="card shadow-sm border-0" style="border-radius:18px;">
                    <div class="card-body p-4">
                        <h2 class="fw-bold mb-2" style="color:var(--dark);">Create Account</h2>
                        <p class="text-muted mb-4" style="font-size:13px;">
                            Register as a student or warden. Warden accounts require admin hostel assignment before dashboard access.
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

                        <form method="post" novalidate>
                            <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>" />

                            <div class="row">
                                <div class="col-12 mb-2">
                                    <p class="text-muted small mb-0">Student registration — warden accounts are created by the administrator.</p>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-bold">Full Name</label>
                                    <input
                                        type="text"
                                        class="form-control"
                                        name="name"
                                        required
                                        value="<?php echo e($values['name']); ?>"
                                    />
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-bold">Email</label>
                                    <input
                                        type="email"
                                        class="form-control"
                                        name="email"
                                        required
                                        value="<?php echo e($values['email']); ?>"
                                    />
                                </div>
                            </div>

                            <div class="row" id="studentFields">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-bold">Registration No</label>
                                    <input
                                        type="text"
                                        class="form-control"
                                        name="reg_no"
                                        id="regNoInput"
                                        required
                                        value="<?php echo e($values['reg_no']); ?>"
                                    />
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-bold">Department</label>
                                    <input
                                        type="text"
                                        class="form-control"
                                        name="department"
                                        id="departmentInput"
                                        required
                                        value="<?php echo e($values['department']); ?>"
                                    />
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-bold">Phone <span class="text-muted fw-normal">(optional)</span></label>
                                    <input
                                        type="text"
                                        class="form-control"
                                        name="phone"
                                        value="<?php echo e($values['phone']); ?>"
                                    />
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-bold">Gender</label>
                                    <select class="form-select" name="gender" required>
                                        <option value="male" <?php echo $values['gender'] === 'male' ? 'selected' : ''; ?>>Male</option>
                                        <option value="female" <?php echo $values['gender'] === 'female' ? 'selected' : ''; ?>>Female</option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-bold">Password</label>
                                    <input type="password" class="form-control" name="password" required autocomplete="new-password"/>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-bold">Confirm Password</label>
                                    <input type="password" class="form-control" name="confirm_password" required autocomplete="new-password"/>
                                </div>
                            </div>

                            

                            <button type="submit" class="btn btn-primary w-100"
                                    style="background:var(--dark); border:none;">
                                Register
                            </button>

                            <div class="text-center mt-3" style="font-size:13px;">
                                Already have an account?
                                <a href="login.php" class="fw-bold" style="color:var(--light); text-decoration:none;">
                                    Login
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

