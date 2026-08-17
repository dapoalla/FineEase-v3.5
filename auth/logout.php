<?php
// auth/logout.php
require_once '../config/config.php';

session_start();

// Unset all session variables
$_SESSION = array();

// Destroy the session
session_destroy();

// Redirect to login page
header("Location: " . BASE_URL . "/auth/login.php");
exit;
?>
