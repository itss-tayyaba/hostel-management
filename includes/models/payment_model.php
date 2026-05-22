<?php
declare(strict_types=1);
/**
 * @file includes/models/payment_model.php
 * @brief Payments layer — Fee structure, record payment, balances
 * @access admin,student
 * @database payments, fee_structure
 */

require_once __DIR__ . '/../app.php';

function payment_list_by_student(PDO $pdo, int $studentId, int $limit = 50): array
{
    $stmt = $pdo->prepare('
        SELECT id, semester, amount, payment_date, method, status, reference, created_at
        FROM payments
        WHERE student_id = :sid
        ORDER BY payment_date DESC, created_at DESC
        LIMIT :lim
    ');
    $stmt->bindValue(':sid', $studentId, PDO::PARAM_INT);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function payment_latest(PDO $pdo, int $studentId): ?array
{
    $stmt = $pdo->prepare('
        SELECT amount, payment_date, method, status
        FROM payments
        WHERE student_id = :sid
        ORDER BY payment_date DESC, created_at DESC
        LIMIT 1
    ');
    $stmt->execute([':sid' => $studentId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function payment_find_by_id(PDO $pdo, int $paymentId): ?array
{
    $stmt = $pdo->prepare('
        SELECT p.id, p.student_id, p.semester, p.amount, p.payment_date, p.method,
               p.status, p.reference, p.created_at,
               s.reg_no, s.department, u.name AS student_name, u.email AS student_email
        FROM payments p
        INNER JOIN students s ON s.id = p.student_id
        INNER JOIN users u ON u.id = s.user_id
        WHERE p.id = :id
        LIMIT 1
    ');
    $stmt->execute([':id' => $paymentId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * @return int Inserted payment id, or 0 on failure.
 */
function payment_create(
    PDO $pdo,
    int $studentId,
    float $amount,
    string $semester,
    string $paymentDate,
    string $method = 'cash',
    string $status = 'paid',
    ?string $reference = null
): int {
    $methodAllowed = ['cash', 'bank_transfer', 'online'];
    $statusAllowed = ['pending', 'paid', 'failed'];
    if (!in_array($method, $methodAllowed, true)) $method = 'cash';
    if (!in_array($status, $statusAllowed, true)) $status = 'paid';

    $stmt = $pdo->prepare('
        INSERT INTO payments (student_id, amount, semester, payment_date, method, status, reference)
        VALUES (:sid, :amt, :sem, :dt, :method, :status, :ref)
    ');
    $ok = $stmt->execute([
        ':sid'    => $studentId,
        ':amt'    => $amount,
        ':sem'    => $semester,
        ':dt'     => $paymentDate,
        ':method' => $method,
        ':status' => $status,
        ':ref'    => $reference !== null && trim($reference) !== '' ? trim($reference) : null,
    ]);
    return $ok ? (int)$pdo->lastInsertId() : 0;
}

function payment_update_status(PDO $pdo, int $paymentId, string $newStatus): bool
{
    $statusAllowed = ['pending', 'paid', 'failed'];
    if (!in_array($newStatus, $statusAllowed, true)) return false;
    $stmt = $pdo->prepare('UPDATE payments SET status = :s WHERE id = :id');
    return $stmt->execute([':s' => $newStatus, ':id' => $paymentId]);
}

function payment_delete(PDO $pdo, int $paymentId): bool
{
    $stmt = $pdo->prepare('DELETE FROM payments WHERE id = :id');
    return $stmt->execute([':id' => $paymentId]);
}

function payment_total_paid(PDO $pdo, int $studentId, ?string $semester = null): float
{
    $sql = 'SELECT COALESCE(SUM(amount),0) FROM payments WHERE student_id = :sid AND status = "paid"';
    $params = [':sid' => $studentId];
    if ($semester !== null && $semester !== '') {
        $sql .= ' AND semester = :sem';
        $params[':sem'] = $semester;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (float)$stmt->fetchColumn();
}

/**
 * Active fee for a hostel + semester (falls back to default row if hostel not set).
 */
function payment_fee_for(PDO $pdo, ?int $hostelId, string $semester): ?array
{
    $stmt = $pdo->prepare('
        SELECT id, hostel_id, semester, amount, due_date, notes
        FROM fee_structure
        WHERE semester = :sem AND (hostel_id = :hid OR hostel_id IS NULL)
        ORDER BY (hostel_id IS NULL) ASC
        LIMIT 1
    ');
    $stmt->execute([':sem' => $semester, ':hid' => $hostelId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function payment_active_semester(PDO $pdo): string
{
    try {
        $stmt = $pdo->query('SELECT semester FROM fee_structure ORDER BY id DESC LIMIT 1');
        $row = $stmt->fetch();
        if ($row && !empty($row['semester'])) return (string)$row['semester'];
    } catch (Throwable $e) {
    }
    return 'Fall ' . date('Y');
}
