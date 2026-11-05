<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

/** Single PDO connection. Prepared statements everywhere = no SQL injection. */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $dsn = DB_DSN_OVERRIDE ?: 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        error_log('DB connection failed: ' . $e->getMessage());
        http_response_code(500);
        exit('Service temporarily unavailable.');
    }
    // Test-only support: lets the app run on SQLite (set BOH_DB_DSN=sqlite:...). Not used with MySQL.
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $pdo->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'), 0);
        $pdo->sqliteCreateFunction('CONCAT', fn(...$a) => implode('', $a));
        $pdo->exec('PRAGMA foreign_keys = ON');
    }
    return $pdo;
}
