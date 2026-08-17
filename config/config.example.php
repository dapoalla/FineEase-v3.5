<?php
// config/config.php
// =====================================================
// COPY THIS FILE TO config.php AND FILL IN YOUR VALUES
// =====================================================

// ── Session Isolation ────────────────────────────────────────
// Give this app its own session cookie name so it never collides with
// $_SESSION data from other PHP projects sharing the same host/port.
if (session_status() === PHP_SESSION_NONE) {
    session_name('finease_v3_session');
}

// Database Credentials
define('DB_SERVER', 'localhost');          // Usually 'localhost' on cPanel
define('DB_USERNAME', 'YOUR_DB_USER');    // cPanel DB username
define('DB_PASSWORD', 'YOUR_DB_PASS');    // cPanel DB password
define('DB_NAME',     'YOUR_DB_NAME');    // DB name created in cPanel

/* Attempt to connect to MySQL database */
try {
    $pdo = new PDO("mysql:host=" . DB_SERVER . ";dbname=" . DB_NAME, DB_USERNAME, DB_PASSWORD);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("set names utf8mb4");
}
catch (PDOException $e) {
    die("ERROR: Could not connect to the database. " . $e->getMessage());
}
?>
