<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/app.php';
require_role('admin');

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => false, 'error' => 'room_allocation_removed']);
