<?php
// Core Functions, Session Handling, and Auth Checks

function require_login() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    // Treat a partial/corrupt session (e.g. leftover from a stale cookie)
    // the same as a logged-out one, rather than letting pages read missing keys.
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['username']) || !isset($_SESSION['user_role'])) {
        $_SESSION = [];
        session_destroy();
        header("Location: " . BASE_URL . "/auth/login.php");
        exit;
    }
}

function require_admin() {
    require_login();
    if (($_SESSION['user_role'] ?? null) !== 'admin') {
        // Redirect non-admins trying to access admin pages
        header("Location: " . BASE_URL . "/pages/dashboard.php?error=access_denied");
        exit;
    }
}

function get_company_settings($pdo) {
    try {
        $stmt = $pdo->query("SELECT * FROM company_settings LIMIT 1");
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return false;
    }
}

function format_currency($amount, $currency_symbol = '₦') {
    return $currency_symbol . number_format($amount, 2);
}

function sanitize_input($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}
?>
