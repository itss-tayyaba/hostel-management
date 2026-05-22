<?php
declare(strict_types=1);
/**
 * @file includes/models/password_reset_model.php
 * @brief Password reset layer — Token issue and consume (hashed)
 * @access public
 * @database password_resets
 */

require_once __DIR__ . '/../app.php';

const PWD_RESET_TTL_MIN = 30;

function password_reset_table_ready(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT 1 FROM password_resets LIMIT 1');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function password_reset_hash(string $raw): string
{
    return hash('sha256', $raw);
}

/**
 * Issue a fresh reset token (any earlier unused tokens are invalidated).
 *
 * @return string raw token (caller shows this once, never stored in plain text)
 */
function password_reset_issue(PDO $pdo, int $userId, int $ttlMinutes = PWD_RESET_TTL_MIN): string
{
    $raw = bin2hex(random_bytes(32));
    $hash = password_reset_hash($raw);
    $expires = (new DateTimeImmutable('+' . $ttlMinutes . ' minutes'))->format('Y-m-d H:i:s');

    $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE user_id = :u AND used_at IS NULL')
        ->execute([':u' => $userId]);

    $ins = $pdo->prepare(
        'INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (:u, :t, :e)'
    );
    $ins->execute([':u' => $userId, ':t' => $hash, ':e' => $expires]);
    return $raw;
}

/**
 * @return array{ok:bool,user_id:int,reason:string}
 */
function password_reset_consume(PDO $pdo, string $rawToken, string $newPasswordHash): array
{
    $hash = password_reset_hash($rawToken);
    $stmt = $pdo->prepare(
        'SELECT id, user_id, expires_at, used_at
         FROM password_resets
         WHERE token_hash = :t
         LIMIT 1
         FOR UPDATE'
    );
    try {
        $pdo->beginTransaction();
        $stmt->execute([':t' => $hash]);
        $row = $stmt->fetch();

        if (!$row) {
            $pdo->rollBack();
            return ['ok' => false, 'user_id' => 0, 'reason' => 'invalid'];
        }
        if (!empty($row['used_at'])) {
            $pdo->rollBack();
            return ['ok' => false, 'user_id' => 0, 'reason' => 'used'];
        }
        if (strtotime((string)$row['expires_at']) < time()) {
            $pdo->rollBack();
            return ['ok' => false, 'user_id' => 0, 'reason' => 'expired'];
        }

        $upd = $pdo->prepare('UPDATE users SET password = :p WHERE id = :id');
        $upd->execute([':p' => $newPasswordHash, ':id' => (int)$row['user_id']]);

        $mark = $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = :id');
        $mark->execute([':id' => (int)$row['id']]);

        $pdo->commit();
        return ['ok' => true, 'user_id' => (int)$row['user_id'], 'reason' => 'ok'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'user_id' => 0, 'reason' => 'error'];
    }
}
