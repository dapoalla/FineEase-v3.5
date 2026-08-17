<?php
// auth/login.php

require_once '../config/config.php';
require_once '../includes/functions.php';

session_start();

if (isset($_SESSION['user_id'])) {
    header("Location: " . BASE_URL . "/pages/dashboard.php");
    exit;
}

$error = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = sanitize_input($_POST['username']);
    $password = $_POST['password'];

    try {
        $stmt = $pdo->prepare("SELECT id, username, password, role FROM users WHERE username = :username LIMIT 1");
        $stmt->bindParam(':username', $username);
        $stmt->execute();
        
        if ($stmt->rowCount() == 1) {
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            // Remember the old DB has `password` column holding the hash
            if (password_verify($password, $user['password'])) {
                session_regenerate_id();
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['user_role'] = $user['role'];
                
                header("Location: " . BASE_URL . "/pages/dashboard.php");
                exit;
            } else {
                $error = "Invalid username or password.";
            }
        } else {
            $error = "Invalid username or password.";
        }
    } catch (PDOException $e) {
        $error = "System Error: Please try again later.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - FinEase v3.0</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
    <style>
        body {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            background-color: var(--bg-main);
            padding: 1rem;
        }
        .login-card {
            width: 100%;
            max-width: 400px;
            background-color: var(--bg-card);
            border-radius: var(--radius-lg);
            padding: 2.5rem;
            box-shadow: var(--shadow);
            border: 1px solid var(--border-color);
            position: relative;
            overflow: hidden;
        }
        .login-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--accent-primary), var(--accent-info));
        }
        .login-logo {
            text-align: center;
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--text-main);
            margin-bottom: 2rem;
        }
        .login-logo i {
            display: block;
            font-size: 1.9rem;
            color: var(--accent-primary);
            margin-bottom: 0.6rem;
        }
        .error-msg {
            color: var(--accent-danger);
            background-color: rgba(239, 68, 68, 0.1);
            padding: 0.75rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            text-align: center;
            font-size: 0.875rem;
        }
    </style>
</head>
<body>

    <div class="login-card">
        <div class="login-logo"><i class="fas fa-chart-pie"></i>FinEase v3.0</div>
        
        <?php if (!empty($error)): ?>
            <div class="error-msg"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="form-group">
                <label class="form-label" for="username">Username</label>
                <input type="text" id="username" name="username" class="form-control" required autofocus autocomplete="username">
            </div>
            
            <div class="form-group">
                <label class="form-label" for="password">Password</label>
                <input type="password" id="password" name="password" class="form-control" required autocomplete="current-password">
            </div>
            
            <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 1rem;">Login</button>
        </form>
    </div>

</body>
</html>
