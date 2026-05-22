<?php
declare(strict_types=1);
/**
 * @file logout.php
 * @brief Session teardown — Clears session and returns to login
 * @access authenticated
 */

require_once __DIR__ . '/includes/app.php';
require_once __DIR__ . '/includes/models/audit_model.php';

if (!empty($_SESSION['user_id'])) {
    try {
        audit_log_from_session(db(), 'logout', 'user', (int)$_SESSION['user_id']);
    } catch (Throwable $e) {
        // best-effort
    }
}

$_SESSION = [];

if (session_status() === PHP_SESSION_ACTIVE) {
    session_destroy();
}

redirect('index.php?logout=1');
