<?php
// index.php — Smart entry point
// Redirects to setup wizard if DB isn't ready or doesn't exist

try {
    // We attempt to load config and connect
    require_once __DIR__ . '/config/config.php';
    
    if (!isset($pdo) || $pdo === null) {
        throw new Exception("Database configuration is missing or invalid.");
    }
    
    // Check if any tables exist (quick proxy for "has setup been run?")
    $check = $pdo->query("SHOW TABLES LIKE 'users'")->fetchColumn();
    
    if ($check) {
        // App is set up — go to login
        header("Location: " . BASE_URL . "/auth/login.php");
        exit;
    } else {
        // App needs setup
        header("Location: setup/install.php");
        exit;
    }
} catch (Throwable $e) {
    // If we can't connect, it might be a fresh install with a non-existent DB
    // or wrong credentials. Since BASE_URL isn't defined if config fails,
    // we use a relative path.
    header("Location: setup/install.php");
    exit;
}
?>
