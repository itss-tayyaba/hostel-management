<?php
declare(strict_types=1);
/**
 * @file student/fee_voucher.php
 * @brief Fee challan — Printable fee voucher for student
 * @access student
 * @database payments
 */

require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/../includes/models/student_model.php';
require_once __DIR__ . '/../includes/models/payment_model.php';
require_once __DIR__ . '/../includes/pages/voucher_view.php';
require_role('student');

$pdo = db();
$student = student_get_by_user_id($pdo, (int)$_SESSION['user_id']);
if (!$student) {
    http_response_code(404); echo 'Student profile not found.'; exit;
}

$paymentId = (int)($_GET['id'] ?? 0);
$payment = null;
if ($paymentId > 0) {
    $payment = payment_find_by_id($pdo, $paymentId);
    if ($payment && (int)$payment['student_id'] !== (int)$student['id']) {
        http_response_code(403); echo 'Not authorised.'; exit;
    }
}

// If no specific payment requested, render a "fee challan" using the active fee structure.
if (!$payment) {
    $allocation = student_get_latest_approved_allocation($pdo, (int)$student['id']);
    $hostelId = $allocation ? (int)$allocation['hostel_id'] : 0;
    $semester = payment_active_semester($pdo);
    $fee = payment_fee_for($pdo, $hostelId > 0 ? $hostelId : null, $semester);
    $amount = $fee ? (float)$fee['amount'] : 9000.00;

    $payment = [
        'id'             => 0,
        'student_id'     => (int)$student['id'],
        'amount'         => $amount,
        'semester'       => $semester,
        'payment_date'   => date('Y-m-d'),
        'method'         => 'cash',
        'status'         => 'pending',
        'reference'      => $fee && !empty($fee['notes']) ? (string)$fee['notes'] : 'Auto-generated challan',
        'student_name'   => (string)$student['name'],
        'reg_no'         => (string)$student['reg_no'],
        'department'     => (string)$student['department'],
        'student_email'  => (string)$student['email'],
    ];
}

voucher_render($payment, [
    'doc_title' => $paymentId > 0 ? 'Hostel Fee Receipt' : 'Hostel Fee Challan',
    'org_name'  => 'University of Agriculture Faisalabad',
    'org_sub'   => 'Hostel Management Office',
]);
