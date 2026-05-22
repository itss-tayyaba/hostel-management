<?php
declare(strict_types=1);
/**
 * @file includes/app.php
 * @brief Application core — Sessions, CSRF, auth guards, password verify, warden scope
 * @access system
 */

// ---------------------------------------------------------------------------
// Session bootstrap — httponly cookies; secure flag when HTTPS is detected
// ---------------------------------------------------------------------------
$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

require_once __DIR__ . '/../config/db.php';

// ---------------------------------------------------------------------------
// Flash messages — one-shot alerts consumed by includes/partials/flash_alerts.php
// ---------------------------------------------------------------------------
/** Flash message helpers (toast-friendly). */
function flash_set(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function flash_get(): ?array
{
    if (empty($_SESSION['flash']) || !is_array($_SESSION['flash'])) {
        return null;
    }
    $f = $_SESSION['flash'];
    unset($_SESSION['flash']);
    $type = (string)($f['type'] ?? 'info');
    $message = (string)($f['message'] ?? '');
    if ($message === '') {
        return null;
    }
    return ['type' => $type, 'message' => $message];
}

function user_email_verified(PDO $pdo, int $userId): bool
{
    try {
        $stmt = $pdo->prepare(
            'SELECT email_verified_at FROM users WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return false;
        }
        $at = $row['email_verified_at'] ?? null;
        return $at !== null && trim((string)$at) !== '';
    } catch (Throwable $e) {
        // Column may not exist on very old DB — treat as verified to avoid lockout.
        return true;
    }
}

// ---------------------------------------------------------------------------
// Output safety & navigation
// ---------------------------------------------------------------------------

/** Escape HTML for safe output in templates (XSS prevention). */
function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

// ---------------------------------------------------------------------------
// CSRF — token stored in session; validate on every state-changing POST
// ---------------------------------------------------------------------------

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_validate(?string $token): bool
{
    $stored = $_SESSION['csrf_token'] ?? '';
    if (!is_string($token) || !is_string($stored)) return false;
    return hash_equals($stored, $token);
}

// ---------------------------------------------------------------------------
// Authentication state — $_SESSION keys: user_id, name, email, role
// ---------------------------------------------------------------------------

function is_logged_in(): bool
{
    return !empty($_SESSION['user_id']) && !empty($_SESSION['role']);
}

/** Base URL path when app lives in subdirectory (e.g. "/hostel"). */
function base_path(): string
{
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $parts = array_values(array_filter(explode('/', trim($script, '/'))));
    $first = $parts[0] ?? '';
    return $first === '' ? '' : '/' . $first;
}

function require_login(): void
{
    if (!is_logged_in()) {
        redirect('login.php');
    }
}

/** Restrict page to a single role (admin | student | warden). */
function require_role(string $role): void
{
    require_login();
    $current = (string)($_SESSION['role'] ?? '');
    if ($current !== $role) {
        redirect('login.php?auth=forbidden');
    }
}

// ---------------------------------------------------------------------------
// Shared validation helpers
// ---------------------------------------------------------------------------

/** @return 'male'|'female'|null */
function normalize_user_gender(?string $raw): ?string
{
    $g = strtolower(trim((string)$raw));
    return $g === 'male' || $g === 'female' ? $g : null;
}

// ---------------------------------------------------------------------------
// Warden scope — one active hostel per warden via warden_assignments table
// ---------------------------------------------------------------------------

/**
 * Active admin assignment for a warden user (one hostel per warden row).
 *
 * @return array{assignment_id:int,hostel_id:int,assigned_at:string,hostel_name:string,hostel_type:string}|null
 */
function warden_fetch_active_assignment(PDO $pdo, int $userId): ?array
{
    $stmt = $pdo->prepare(
        '
        SELECT wa.id AS assignment_id,
               wa.hostel_id AS hostel_id,
               wa.assigned_at AS assigned_at,
               h.name AS hostel_name,
               h.type AS hostel_type
        FROM warden_assignments wa
        INNER JOIN hostels h ON h.id = wa.hostel_id
        WHERE wa.user_id = :uid AND wa.is_active = 1
        LIMIT 1
    '
    );
    $stmt->execute([':uid' => $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    return [
        'assignment_id' => (int)$row['assignment_id'],
        'hostel_id' => (int)$row['hostel_id'],
        'assigned_at' => (string)$row['assigned_at'],
        'hostel_name' => (string)$row['hostel_name'],
        'hostel_type' => (string)$row['hostel_type'],
    ];
}

/**
 * Warden routes: requires role warden plus an admin-created active hostel assignment.
 */
function require_allocated_warden(): void
{
    require_login();
    if ((string)($_SESSION['role'] ?? '') !== 'warden') {
        redirect('login.php?auth=forbidden');
        return;
    }
    try {
        $pdo = db();
        $wa = warden_fetch_active_assignment($pdo, (int)$_SESSION['user_id']);
        if ($wa === null) {
            redirect('login.php?auth=warden_not_assigned');
            return;
        }
        $_SESSION['warden_hostel_id'] = $wa['hostel_id'];
        $_SESSION['warden_hostel_name'] = $wa['hostel_name'];
    } catch (Throwable $e) {
        redirect('login.php?auth=warden_not_assigned');
    }
}

/**
 * True if user has active warden assignment (for login gate).
 */
function warden_has_active_assignment(PDO $pdo, int $userId): bool
{
    try {
        return warden_fetch_active_assignment($pdo, $userId) !== null;
    } catch (Throwable $e) {
        return false;
    }
}

// ---------------------------------------------------------------------------
// Password verification — bcrypt preferred; auto-upgrade legacy plain/MD5
// ---------------------------------------------------------------------------

/**
 * Verify a login password against users.password.
 * Supports bcrypt/argon hashes (password_hash) and legacy plain-text values (upgrades to bcrypt on success).
 */
function verify_user_password(PDO $pdo, int $userId, string $plain, string $stored): bool
{
    $stored = trim($stored);
    if ($stored === '') {
        return false;
    }
    $info = password_get_info($stored);
    if (($info['algo'] ?? 0) !== 0) {
        return password_verify($plain, $stored);
    }
    if (hash_equals($stored, $plain)) {
        $hash = password_hash($plain, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare('UPDATE users SET password = :h WHERE id = :id');
        $stmt->execute([':h' => $hash, ':id' => $userId]);
        return true;
    }
    // Legacy MD5 hex (32 chars), upgrade to bcrypt on success
    if (strlen($stored) === 32 && ctype_xdigit($stored) && hash_equals($stored, md5($plain))) {
        $hash = password_hash($plain, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare('UPDATE users SET password = :h WHERE id = :id');
        $stmt->execute([':h' => $hash, ':id' => $userId]);
        return true;
    }
    return false;
}

