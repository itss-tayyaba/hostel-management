<?php
declare(strict_types=1);
/**
 * @file student/help_chat_api.php
 * @brief Help assistant API — JSON POST endpoint for chat replies
 * @access student
 */

require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/../includes/help_chatbot.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'reply' => 'Method not allowed']);
    exit;
}

if (!is_logged_in() || ($_SESSION['role'] ?? '') !== 'student') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'reply' => 'Student session required']);
    exit;
}

$csrfInput = isset($_SERVER['HTTP_X_CSRF_TOKEN']) ? (string)$_SERVER['HTTP_X_CSRF_TOKEN'] : '';
if (!csrf_validate($csrfInput)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'reply' => 'Expired security token — refresh assistant page']);
    exit;
}

$payload = [];
$body = file_get_contents('php://input');
if (is_string($body) && $body !== '') {
    $decoded = json_decode($body, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        $payload = $decoded;
    }
}
$msg = isset($payload['message']) ? trim((string)$payload['message']) : '';
if (mb_strlen($msg) > 480) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'reply' => 'Message exceeds 480 characters']);
    exit;
}

try {
    $pdo = db();
    $ctx = help_chatbot_student_context($pdo, (int)$_SESSION['user_id']);
    $reply = help_chatbot_reply($msg, $ctx);
    echo json_encode(['ok' => true, 'reply' => $reply], JSON_HEX_TAG | JSON_HEX_AMP);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'reply' => 'Could not fulfil request right now.']);
}
