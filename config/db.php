<?php
declare(strict_types=1);
/**
 * @file config/db.php
 * @brief Database bootstrap — PDO singleton + env loader helpers
 * @access system
 */

/**
 * PDO database connection (singleton).
 * Credentials load from config/env.php when present.
 */

function app_env(): array
{
    static $env = null;
    if ($env !== null) {
        return $env;
    }
    $path = __DIR__ . '/env.php';
    $env = is_file($path) ? (require $path) : [];
    if (!is_array($env)) {
        $env = [];
    }
    return $env;
}

function app_env_string(string $key, string $default = ''): string
{
    $v = app_env()[$key] ?? $default;
    return is_string($v) ? $v : (string)$v;
}

function app_env_bool(string $key, bool $default = false): bool
{
    $v = app_env()[$key] ?? $default;
    if (is_bool($v)) {
        return $v;
    }
    if (is_string($v)) {
        return in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
    }
    return (bool)$v;
}

function app_env_int(string $key, int $default = 0): int
{
    $v = app_env()[$key] ?? $default;
    return is_numeric($v) ? (int)$v : $default;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $env = app_env();
    $DB_HOST = (string)($env['DB_HOST'] ?? 'localhost');
    $DB_NAME = (string)($env['DB_NAME'] ?? 'hostel_management');
    $DB_USER = (string)($env['DB_USER'] ?? 'root');
    $DB_PASS = (string)($env['DB_PASS'] ?? '');

    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $DB_HOST, $DB_NAME);

    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}
