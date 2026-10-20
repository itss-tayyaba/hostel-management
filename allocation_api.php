<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/app.php';
require_once __DIR__ . '/includes/services/UafAllocationEngine.php';

require_role('admin');

header('Content-Type: application/json; charset=utf-8');

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'method_not_allowed']);
    exit;
}

$token = $_POST['csrf_token'] ?? null;
if (!csrf_validate(is_string($token) ? $token : null)) {
    http_response_code(419);
    echo json_encode(['success' => false, 'error' => 'invalid_csrf_token']);
    exit;
}

$action = (string)($_GET['action'] ?? $_POST['action'] ?? '');
if ($action !== 'run') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'invalid_action']);
    exit;
}

try {
    $started = microtime(true);
    $engine = new UafAllocationEngine(db());
    $result = $engine->runAllocationCycle(500);
    $durationMs = (int)round((microtime(true) - $started) * 1000);

    echo json_encode([
        'success' => true,
        'processed' => (int)$result['processed'],
        'allocated' => (int)$result['allocated'],
        'waitlisted' => (int)$result['waitlisted'],
        'failed' => (int)$result['failed'],
        'time_ms' => $durationMs,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'allocation_cycle_failed',
        'message' => $e->getMessage(),
    ]);
}

