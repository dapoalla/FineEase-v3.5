<?php
// Core Header logic
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// Assume user is logged in for the sake of the layout demo
$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FinEase v3.0 - Management</title>
    <!-- Google Fonts: Poppins -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- FontAwesome for Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Main Custom CSS -->
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body>

    <!-- Mobile Header -->
    <header class="mobile-header">
        <div class="logo">
            <span style="font-weight: 700; color: var(--accent-primary); font-size: 1.25rem;">FinEase v3.0</span>
        </div>
        <div class="user-action text-muted">
            <i class="fas fa-bell"></i>
        </div>
    </header>

    <!-- Desktop Sidebar -->
    <nav class="sidebar hidden-mobile">
        <div class="sidebar-logo">
            FinEase
        </div>
        <ul class="sidebar-menu">
            <li><a href="<?= BASE_URL ?>/pages/dashboard.php" class="sidebar-link <?= $current_page == 'dashboard.php' ? 'active' : '' ?>"><i class="fas fa-chart-pie"></i> Dashboard</a></li>
            <li><a href="<?= BASE_URL ?>/pages/clients.php" class="sidebar-link <?= $current_page == 'clients.php' ? 'active' : '' ?>"><i class="fas fa-users"></i> Clients</a></li>
            <li><a href="<?= BASE_URL ?>/pages/job_orders.php" class="sidebar-link <?= $current_page == 'job_orders.php' ? 'active' : '' ?>"><i class="fas fa-file-invoice"></i> Job Orders</a></li>
            <li><a href="<?= BASE_URL ?>/pages/catalogue.php" class="sidebar-link <?= $current_page == 'catalogue.php' ? 'active' : '' ?>"><i class="fas fa-box-open"></i> Product Catalogue</a></li>
            <li><a href="<?= BASE_URL ?>/pages/project_survey.php" class="sidebar-link <?= $current_page == 'project_survey.php' ? 'active' : '' ?>"><i class="fas fa-clipboard-list"></i> Project Survey</a></li>
            <li><a href="<?= BASE_URL ?>/pages/transactions.php" class="sidebar-link <?= $current_page == 'transactions.php' ? 'active' : '' ?>"><i class="fas fa-wallet"></i> Transactions</a></li>
            <li><a href="<?= BASE_URL ?>/pages/tithes.php" class="sidebar-link <?= $current_page == 'tithes.php' ? 'active' : '' ?>"><i class="fas fa-hand-holding-heart"></i> Tithes</a></li>
            <li><a href="<?= BASE_URL ?>/pages/reports.php" class="sidebar-link <?= $current_page == 'reports.php' ? 'active' : '' ?>"><i class="fas fa-chart-line"></i> Reports</a></li>
            <li><a href="<?= BASE_URL ?>/pages/backup.php" class="sidebar-link <?= $current_page == 'backup.php' ? 'active' : '' ?>"><i class="fas fa-database"></i> Backup & Restore</a></li>
            <li><a href="<?= BASE_URL ?>/pages/settings.php" class="sidebar-link <?= $current_page == 'settings.php' ? 'active' : '' ?>"><i class="fas fa-cog"></i> Settings</a></li>
            <?php if (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin'): ?>
            <li><a href="<?= BASE_URL ?>/pages/users.php" class="sidebar-link <?= $current_page == 'users.php' ? 'active' : '' ?>"><i class="fas fa-user-shield"></i> Users</a></li>
            <?php endif; ?>
        </ul>
        <div style="margin-top: auto;">
            <a href="<?= BASE_URL ?>/auth/logout.php" class="sidebar-link"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
    </nav>

    <!-- Main Content Wrapper -->
    <main class="main-content">
        <div class="container">
