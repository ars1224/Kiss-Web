<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

/**
 * Returns a shared PDO instance for the whole app.
 *
 * Usage:
 *   $pdo = db();
 *   $stmt = $pdo->prepare('SELECT * FROM products WHERE id = :id');
 *   $stmt->execute(['id' => $id]);
 *
 * @throws PDOException if connection fails
 */
function db(): PDO
{
    static $pdo = null;

    // Reuse existing connection
    if ($pdo instanceof PDO) {
        return $pdo;
    }

 $dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
    DB_HOST,
    DB_PORT,
    DB_NAME,
    DB_CHARSET
);

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false, // safer real prepared statements
    ];

    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);

    return $pdo;
}
