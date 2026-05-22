<?php
declare(strict_types=1);
/**
 * @file includes/models/audit_model.php
 * @brief Audit log layer — Append-only security/event history
 * @access admin
 * @database audit_log
 */

require_once __DIR__ . '/../app.php';

/**
 * Append a single row to audit_log. Never throws — auditing must not break
 * the calling action. Safe to call even if the table has not been migrated
 * yet (it will silently no-op in that case).
 */
function audit_log(
    PDO $pdo,
    string $action,
    ?int $userId = null,
    ?string $actorRole = null,
    ?string $entityType = null,
    ?int $entityId = null,
    ?string $detail = null
): void {
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO audit_log
                (user_id, actor_role, action, entity_type, entity_id, detail, ip_address, user_agent)
             VALUES
                (:uid, :role, :action, :etype, :eid, :detail, :ip, :ua)'
        );
        $stmt->execute([
            ':uid'    => $userId,
            ':role'   => $actorRole,
            ':action' => $action,
            ':etype'  => $entityType,
            ':eid'    => $entityId,
            ':detail' => $detail !== null ? mb_substr($detail, 0, 255) : null,
            ':ip'     => audit_client_ip(),
            ':ua'     => audit_client_ua(),
        ]);
    } catch (Throwable $e) {
        // Auditing must be best-effort. Swallow errors silently.
    }
}

function audit_log_from_session(PDO $pdo, string $action, ?string $entityType = null, ?int $entityId = null, ?string $detail = null): void
{
    $uid = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    $role = isset($_SESSION['role']) ? (string)$_SESSION['role'] : null;
    audit_log($pdo, $action, $uid, $role, $entityType, $entityId, $detail);
}

function audit_client_ip(): string
{
    $candidates = [
        $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
        $_SERVER['HTTP_X_REAL_IP'] ?? '',
        $_SERVER['REMOTE_ADDR'] ?? '',
    ];
    foreach ($candidates as $val) {
        $val = trim((string)$val);
        if ($val === '') continue;
        $first = trim(explode(',', $val)[0] ?? '');
        if ($first !== '' && filter_var($first, FILTER_VALIDATE_IP) !== false) {
            return $first;
        }
    }
    return '0.0.0.0';
}

function audit_client_ua(): ?string
{
    $ua = trim((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    if ($ua === '') return null;
    return mb_substr($ua, 0, 255);
}

/**
 * @return list<array<string,mixed>>
 */
function audit_log_recent(PDO $pdo, int $limit = 200, ?string $actionFilter = null, ?int $userFilter = null): array
{
    $limit = max(1, min(1000, $limit));
    $sql = '
        SELECT a.id, a.user_id, a.actor_role, a.action, a.entity_type, a.entity_id,
               a.detail, a.ip_address, a.user_agent, a.created_at,
               u.name AS user_name, u.email AS user_email
        FROM audit_log a
        LEFT JOIN users u ON u.id = a.user_id
        WHERE 1 = 1
    ';
    $params = [];
    if ($actionFilter !== null && $actionFilter !== '') {
        $sql .= ' AND a.action = :action';
        $params[':action'] = $actionFilter;
    }
    if ($userFilter !== null && $userFilter > 0) {
        $sql .= ' AND a.user_id = :uid';
        $params[':uid'] = $userFilter;
    }
    $sql .= ' ORDER BY a.id DESC LIMIT ' . $limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function audit_table_ready(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT 1 FROM audit_log LIMIT 1');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}
