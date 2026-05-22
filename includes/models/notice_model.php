<?php
declare(strict_types=1);
/**
 * @file includes/models/notice_model.php
 * @brief Notices layer — Admin CMS + public listing
 * @access admin,public
 * @database notices
 */

require_once __DIR__ . '/../app.php';

function notice_table_ready(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT 1 FROM notices LIMIT 1');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

const NOTICE_CATEGORIES = ['general','urgent','fee','allotment','rules','event'];

function notice_create(PDO $pdo, string $title, string $body, string $category, bool $isPublished, bool $pinned, ?int $authorUserId): int
{
    if (!in_array($category, NOTICE_CATEGORIES, true)) $category = 'general';
    $stmt = $pdo->prepare(
        'INSERT INTO notices (title, body, category, is_published, pinned, author_user_id)
         VALUES (:t, :b, :c, :p, :pin, :au)'
    );
    $stmt->execute([
        ':t' => $title,
        ':b' => $body,
        ':c' => $category,
        ':p' => $isPublished ? 1 : 0,
        ':pin' => $pinned ? 1 : 0,
        ':au' => $authorUserId,
    ]);
    return (int)$pdo->lastInsertId();
}

function notice_update(PDO $pdo, int $id, string $title, string $body, string $category, bool $isPublished, bool $pinned): bool
{
    if (!in_array($category, NOTICE_CATEGORIES, true)) $category = 'general';
    $stmt = $pdo->prepare(
        'UPDATE notices SET title = :t, body = :b, category = :c, is_published = :p, pinned = :pin, updated_at = NOW()
         WHERE id = :id'
    );
    return $stmt->execute([
        ':t' => $title,
        ':b' => $body,
        ':c' => $category,
        ':p' => $isPublished ? 1 : 0,
        ':pin' => $pinned ? 1 : 0,
        ':id' => $id,
    ]);
}

function notice_delete(PDO $pdo, int $id): bool
{
    $stmt = $pdo->prepare('DELETE FROM notices WHERE id = :id');
    return $stmt->execute([':id' => $id]);
}

function notice_find(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM notices WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function notice_list_all(PDO $pdo, int $limit = 200): array
{
    $limit = max(1, min(1000, $limit));
    $stmt = $pdo->query(
        'SELECT n.id, n.title, n.body, n.category, n.is_published, n.pinned,
                n.published_at, n.created_at, n.updated_at, u.name AS author_name
         FROM notices n
         LEFT JOIN users u ON u.id = n.author_user_id
         ORDER BY n.pinned DESC, n.published_at DESC, n.id DESC
         LIMIT ' . $limit
    );
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function notice_list_public(PDO $pdo, int $limit = 30): array
{
    $limit = max(1, min(200, $limit));
    $stmt = $pdo->query(
        'SELECT id, title, body, category, pinned, published_at
         FROM notices
         WHERE is_published = 1
         ORDER BY pinned DESC, published_at DESC, id DESC
         LIMIT ' . $limit
    );
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
