<?php
declare(strict_types=1);
/**
 * @file admin/smart_recommend_api.php
 * @brief Recommend JSON API — Returns ranked rooms for a student_id
 * @access admin
 */

/**
 * JSON bridge for Bootstrap modal AJAX — surfaces ranked rooms + rationales securely.
 */

require_once __DIR__ . '/../includes/app.php';
require_role('admin');

require_once __DIR__ . '/../includes/models/smart_allocation_model.php';
require_once __DIR__ . '/../includes/services/recommendation_service.php';

header('Content-Type: application/json; charset=utf-8');

$studentId = (int)($_GET['student_id'] ?? 0);
if ($studentId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'student_id_required']);
    exit;
}

if (!smart_allocation_tables_ready(db())) {
    echo json_encode(['ok' => false, 'error' => 'migration_required']);
    exit;
}

$pdo = db();
$stu = smart_allocation_get_student_profile($pdo, $studentId);
if (!$stu) {
    echo json_encode(['ok' => false, 'error' => 'student_not_found']);
    exit;
}

$recs = hostel_recommend_rooms_with_reasons($pdo, $studentId);
$top = $recs[0] ?? null;

echo json_encode(
    [
        'ok' => true,
        'student' => [
            'id' => $studentId,
            'name' => (string)$stu['name'],
            'department' => (string)$stu['department'],
            'semester' => (string)($stu['semester'] ?? ''),
            'preference' => (string)($stu['room_preference'] ?? 'shared'),
        ],
        'top' => $top,
        'ranked' => $recs,
    ],
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
);
