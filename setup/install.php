<?php
// setup/install.php - FinEase v3.0 Setup Wizard

$config_error = false;
$error_msg = '';

try {
    require_once __DIR__ . '/../config/config.php';
    if (!isset($pdo) || $pdo === null) {
        throw new Exception("Database connection not established. Please check your credentials.");
    }
} catch (Throwable $e) {
    $config_error = true;
    $error_msg = $e->getMessage();
}

$step = $_GET['step'] ?? 1;
$message = '';
$messageType = '';

// Helper to execute SQL file
function executeSqlFile($pdo, $filename) {
    if (!file_exists($filename)) return false;
    $sql = file_get_contents($filename);
    $queries = explode(';', $sql);
    foreach ($queries as $query) {
        $query = trim($query);
        if (!empty($query)) {
            $pdo->exec($query);
        }
    }
    return true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_config') {
        $host = trim($_POST['db_host']);
        $name = trim($_POST['db_name']);
        $user = trim($_POST['db_user']);
        $pass = trim($_POST['db_pass']);

        try {
            // Test connection first
            $test_pdo = new PDO("mysql:host=$host;dbname=$name", $user, $pass);
            $test_pdo = null; // Close connection
            
            // Connection successful! Now write to config/config.php
            $config_content = "<?php
// config/config.php

// ── Dynamic Base URL ─────────────────────────────────────────
// Auto-detects URL prefix at runtime — works on localhost/fe3/,
// yourdomain.com/, or any subdirectory. No manual config needed.
\$_app_root  = str_replace('\\\\', '/', realpath(__DIR__ . '/..'));
\$_doc_root  = str_replace('\\\\', '/', rtrim(\$_SERVER['DOCUMENT_ROOT'], '/'));
\$_rel_path  = str_replace(\$_doc_root, '', \$_app_root);
\$_protocol  = (!empty(\$_SERVER['HTTPS']) && \$_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
define('BASE_URL', \$_protocol . '://' . \$_SERVER['HTTP_HOST'] . rtrim(\$_rel_path, '/'));
define('APP_ROOT', \$_app_root);

// ── Database Credentials ─────────────────────────────────────
// Update these for your cPanel / live server environment
define('DB_SERVER',   '$host');
define('DB_USERNAME', '$user');
define('DB_PASSWORD', '$pass');
define('DB_NAME',     '$name');

try {
    \$pdo = new PDO(\"mysql:host=\" . DB_SERVER . \";dbname=\" . DB_NAME, DB_USERNAME, DB_PASSWORD);
    \$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    \$pdo->exec(\"set names utf8mb4\");
} catch (PDOException \$e) {
    // Throw the exception instead of die() so index.php can catch it and redirect to setup
    throw \$e;
}
?>";
            if (file_put_contents('../config/config.php', $config_content)) {
                header("Location: install.php"); // Refresh to use new config
                exit;
            } else {
                $message = "Unable to write to config/config.php. Please check file permissions or update it manually.";
                $messageType = "danger";
            }

        } catch (PDOException $e) {
            $message = "Database connection test failed: " . $e->getMessage();
            $messageType = "danger";
        }
    }

    if ($action === 'initialize_db') {
        try {
            if (executeSqlFile($pdo, 'schema.sql')) {
                // Success - move to next step
                header("Location: install.php?step=2");
                exit;
            } else {
                $message = "Schema file not found.";
                $messageType = "danger";
            }
        } catch (PDOException $e) {
            $message = "Database Error: " . $e->getMessage();
            $messageType = "danger";
        }
    }

    if ($action === 'create_admin') {
        $username = trim($_POST['username']);
        $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
        
        $company_name = trim($_POST['company_name']);
        $email = trim($_POST['email']);

        try {
            $pdo->beginTransaction();
            
            // 1. Create User
            $stmt = $pdo->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, 'admin')");
            $stmt->execute([$username, $password]);
            
            // 2. Set Company Settings (assumes schema.sql created the table)
            // schema.sql usually resets the table; check if row exists
            $check = $pdo->query("SELECT id FROM company_settings LIMIT 1")->fetch();
            if ($check) {
                $stmt = $pdo->prepare("UPDATE company_settings SET company_name = ?, company_email = ? WHERE id = ?");
                $stmt->execute([$company_name, $email, $check['id']]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO company_settings (company_name, company_email) VALUES (?, ?)");
                $stmt->execute([$company_name, $email]);
            }

            $pdo->commit();
            $step = 3; // Success mark
        } catch (PDOException $e) {
            $pdo->rollBack();
            $message = "Error: " . $e->getMessage();
            $messageType = "danger";
        }
    }
}

// Basic styling matches the app
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FinEase v3.0 - Setup Wizard</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #1a3a6b;
            --success: #10b981;
            --danger: #ef4444;
            --bg: #0f172a;
            --card: #1e293b;
            --text: #f8fafc;
        }
        body { font-family: 'Poppins', sans-serif; background: var(--bg); color: var(--text); display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
        .setup-card { background: var(--card); padding: 2.5rem; border-radius: 16px; box-shadow: 0 10px 25px rgba(0,0,0,0.5); width: 100%; max-width: 500px; text-align: center; }
        .logo { font-size: 2rem; font-weight: 700; color: var(--success); margin-bottom: 0.5rem; }
        .step-indicator { display: flex; justify-content: center; gap: 0.5rem; margin-bottom: 2rem; }
        .dot { width: 10px; height: 10px; border-radius: 50%; background: #334155; }
        .dot.active { background: var(--success); box-shadow: 0 0 10px var(--success); }
        .btn { padding: 0.8rem 1.5rem; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; transition: 0.3s; width: 100%; font-size: 1rem; }
        .btn-primary { background: var(--primary); color: white; }
        .btn-success { background: var(--success); color: white; }
        .btn:hover { opacity: 0.9; transform: translateY(-2px); }
        .form-group { text-align: left; margin-bottom: 1.25rem; }
        .form-label { display: block; margin-bottom: 0.4rem; font-size: 0.85rem; color: #94a3b8; }
        .form-control { width: 100%; padding: 0.75rem; border-radius: 8px; border: 1px solid #334155; background: #0f172a; color: white; font-size: 0.95rem; box-sizing: border-box; }
        .alert { padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; font-size: 0.9rem; border-left: 4px solid; }
        .alert-danger { background: rgba(239, 68, 68, 0.1); border-color: var(--danger); color: #fecaca; }
    </style>
</head>
<body>

<div class="setup-card">
    <div class="logo">FinEase v3.0</div>
    <p style="color: #94a3b8; margin-bottom: 2rem;">Installer & Database Setup</p>

    <?php if ($message): ?>
        <div class="alert alert-<?= $messageType ?>"><?= $message ?></div>
    <?php endif; ?>

    <?php if ($config_error): ?>
        <div class="alert alert-danger" style="text-align: left; margin-bottom: 2rem;">
            <i class="fas fa-exclamation-triangle"></i> <strong>Database Connection Failed</strong><br>
            <small><?= htmlspecialchars($error_msg) ?></small>
        </div>

        <form method="POST" style="text-align: left;">
            <input type="hidden" name="action" value="save_config">
            
            <div class="form-group">
                <label class="form-label">Database Host</label>
                <input type="text" name="db_host" class="form-control" value="localhost" required>
            </div>

            <div class="form-group">
                <label class="form-label">Database Name</label>
                <input type="text" name="db_name" class="form-control" placeholder="e.g. cyberros_finease" required>
            </div>

            <div class="form-group">
                <label class="form-label">Database Username</label>
                <input type="text" name="db_user" class="form-control" placeholder="e.g. cyberros_user" required>
            </div>

            <div class="form-group">
                <label class="form-label">Database Password</label>
                <input type="password" name="db_pass" class="form-control" placeholder="••••••••" required>
            </div>

            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i> Test & Save Database Config
            </button>
            <p style="font-size: 0.7rem; color: #94a3b8; margin-top: 1rem; text-align: center;">
                This will attempt to update <code>config/config.php</code> automatically.
            </p>
        </form>
    <?php else: ?>
        <div class="step-indicator">
            <div class="dot <?= $step >= 1 ? 'active' : '' ?>"></div>
            <div class="dot <?= $step >= 2 ? 'active' : '' ?>"></div>
            <div class="dot <?= $step >= 3 ? 'active' : '' ?>"></div>
        </div>

        <?php if ($step == 1): ?>
        <h3>Step 1: Database Initialization</h3>
        <p style="font-size: 0.9rem; color: #94a3b8; margin-bottom: 2rem;">
            This will create all the necessary tables for the application. Make sure your database credentials in <code>config/config.php</code> are correct before proceeding.
        </p>
        <form method="POST">
            <input type="hidden" name="action" value="initialize_db">
            <button type="submit" class="btn btn-primary"><i class="fas fa-database"></i> Initialize Database Now</button>
        </form>

    <?php elseif ($step == 2): ?>
        <h3>Step 2: Admin Creation</h3>
        <p style="font-size: 0.9rem; color: #94a3b8; margin-bottom: 1.5rem;">Create your master administrator account.</p>
        <form method="POST">
            <input type="hidden" name="action" value="create_admin">
            
            <div class="form-group">
                <label class="form-label">Admin Username</label>
                <input type="text" name="username" class="form-control" required placeholder="e.g. admin">
            </div>
            
            <div class="form-group">
                <label class="form-label">Admin Password</label>
                <input type="password" name="password" class="form-control" required placeholder="••••••••">
            </div>

            <div class="form-group">
                <label class="form-label">Company Name</label>
                <input type="text" name="company_name" class="form-control" required placeholder="My Business Ltd">
            </div>

            <div class="form-group">
                <label class="form-label">Business Email</label>
                <input type="email" name="email" class="form-control" required placeholder="contact@business.com">
            </div>

            <button type="submit" class="btn btn-success"><i class="fas fa-user-plus"></i> Create Admin & Finish</button>
        </form>

    <?php elseif ($step == 3): ?>
        <i class="fas fa-check-circle" style="font-size: 4rem; color: var(--success); margin-bottom: 1.5rem;"></i>
        <h3>Setup Complete!</h3>
        <p style="font-size: 0.9rem; color: #94a3b8; margin-bottom: 2rem;">
            FinEase v3.0 has been successfully installed. You can now log in with your admin account.
        </p>
        <a href="../auth/login.php" class="btn btn-primary" style="text-decoration: none; display: inline-block;">Go to Login <i class="fas fa-arrow-right"></i></a>
        
        <p style="color: #ef4444; font-size: 0.75rem; margin-top: 2rem; font-weight: 500;">
            <i class="fas fa-exclamation-triangle"></i> DANGER: Please delete the <code>setup/</code> folder from your cPanel immediately for security!
        </p>
    <?php endif; ?>
    <?php endif; ?>
</div>

</body>
</html>
