<?php
/**
 * config/db.php
 *
 * Also starts the PHP session (guarded, so it's safe even if a script
 * happens to require this file twice). Every endpoint that checks
 * $_SESSION['user_id'] / $_SESSION['role'] relies on this running first.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * config/db.php
 *
 * Single shared PDO connection to the afk_rpg MySQL database (XAMPP).
 * Every other PHP file (auth, combat, inventory, etc.) does:
 *
 *     require __DIR__ . '/../config/db.php';
 *
 * and then uses the $pdo variable this file creates. Always use
 * prepared statements ($pdo->prepare(...) / ->execute([...])) —
 * never build SQL by concatenating strings, or you defeat the whole
 * point of having this file.
 */

// ---- connection settings -------------------------------------------------
// XAMPP defaults: host 127.0.0.1, user root, empty password.
// Change these three if your local setup differs.
$DB_HOST = '127.0.0.1';
$DB_NAME = 'afk_rpg';
$DB_USER = 'root';
$DB_PASS = '';

$dsn = "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // throw on SQL errors instead of failing silently
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,        // rows come back as ['column' => value], not numeric-indexed too
    PDO::ATTR_EMULATE_PREPARES   => false,                   // use REAL prepared statements (this is what actually stops SQL injection)
];

try {
    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, $options);
} catch (PDOException $e) {
    // In production you'd log this instead of echoing it. For now, during
    // development, seeing the real error is more useful than a blank page.
    http_response_code(500);
    die(json_encode([
        'error' => 'Database connection failed',
        'detail' => $e->getMessage(),
    ]));
}
