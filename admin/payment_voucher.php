<?php
declare(strict_types=1);
/**
 * @file admin/payment_voucher.php
 * @brief Admin payment voucher — Printable receipt for recorded payment
 * @access admin
 * @database payments
 */

require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/../includes/models/payment_model.php';
require_role('admin');

$paymentId = (int)($_GET['id'] ?? 0);
$pdo = db();
$payment = $paymentId > 0 ? payment_find_by_id($pdo, $paymentId) : null;
if (!$payment) {
    http_response_code(404);
    echo 'Payment not found.';
    exit;
}

require_once __DIR__ . '/../includes/pages/voucher_view.php';
voucher_render($payment, [
    'doc_title' => 'Hostel Fee Voucher',
    'org_name'  => 'University of Agriculture Faisalabad',
    'org_sub'   => 'Hostel Management Office',
]);
