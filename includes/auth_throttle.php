<?php
declare(strict_types=1);
/**
 * @file includes/auth_throttle.php
 * @brief Login rate limiter — Blocks brute force via login_attempts table
 * @access system
 * @database login_attempts
 */

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/models/audit_model.php';

/**
 * Lightweight login rate limiter backed by `login_attempts`.
 *
 * Rules:
 *  - Window: 15 minutes
 *  - Max failures per IP+email within window: 5
 *  - Max failures per IP within window: 15 (covers many emails from one bot)
 *  - On limit hit, login is blocked for the rest of the window.
 */

const AUTH_THROTTLE_WINDOW_MIN = 15;
const AUTH_THROTTLE_MAX_PER_IP_EMAIL = 5;
const AUTH_THROTTLE_MAX_PER_IP = 15;

function auth_throttle_table_ready(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT 1 FROM login_attempts LIMIT 1');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function auth_throttle_record(PDO $pdo, string $email, bool $success): void
{
    if (!auth_throttle_table_ready($pdo)) return;
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO login_attempts (email, ip_address, success) VALUES (:e, :ip, :ok)'
        );
        $stmt->execute([
            ':e'  => $email !== '' ? mb_substr($email, 0, 191) : null,
            ':ip' => audit_client_ip(),
            ':ok' => $success ? 1 : 0,
        ]);
    } catch (Throwable $e) {
        // best-effort
    }
}

/**
 * @return array{blocked:bool, retry_minutes:int, reason:string}
 */
function auth_throttle_check(PDO $pdo, string $email): array
{
    if (!auth_throttle_table_ready($pdo)) {
        return ['blocked' => false, 'retry_minutes' => 0, 'reason' => ''];
    }
    $ip = audit_client_ip();
    $window = AUTH_THROTTLE_WINDOW_MIN;
    try {
        $a = $pdo->prepare(
            'SELECT COUNT(*) FROM login_attempts
             WHERE ip_address = :ip AND email = :e AND success = 0
               AND attempted_at >= DATE_SUB(NOW(), INTERVAL :w MINUTE)'
        );
        $a->bindValue(':ip', $ip);
        $a->bindValue(':e', mb_substr($email, 0, 191));
        $a->bindValue(':w', $window, PDO::PARAM_INT);
        $a->execute();
        $perPair = (int)$a->fetchColumn();

        $b = $pdo->prepare(
            'SELECT COUNT(*) FROM login_attempts
             WHERE ip_address = :ip AND success = 0
               AND attempted_at >= DATE_SUB(NOW(), INTERVAL :w MINUTE)'
        );
        $b->bindValue(':ip', $ip);
        $b->bindValue(':w', $window, PDO::PARAM_INT);
        $b->execute();
        $perIp = (int)$b->fetchColumn();

        if ($perPair >= AUTH_THROTTLE_MAX_PER_IP_EMAIL) {
            return ['blocked' => true, 'retry_minutes' => $window,
                'reason' => 'Too many failed login attempts for this email. Try again in ' . $window . ' minutes.'];
        }
        if ($perIp >= AUTH_THROTTLE_MAX_PER_IP) {
            return ['blocked' => true, 'retry_minutes' => $window,
                'reason' => 'Too many failed attempts from your network. Try again in ' . $window . ' minutes.'];
        }
    } catch (Throwable $e) {
        // fail open on infra issue — never lock everyone out
    }
    return ['blocked' => false, 'retry_minutes' => 0, 'reason' => ''];
}
