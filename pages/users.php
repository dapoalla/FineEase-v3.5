<?php
// pages/users.php
require_once '../config/config.php';
require_once '../includes/functions.php';

require_login();
require_admin(); // Only admins can manage users

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] == 'create_user') {
        $username = trim(sanitize_input($_POST['username']));
        $password = $_POST['password'];
        $role     = in_array($_POST['role'], ['admin', 'temp', 'viewer']) ? $_POST['role'] : 'viewer';

        if (strlen($password) < 6) {
            $message = "Password must be at least 6 characters.";
            $messageType = "danger";
        } else {
            try {
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $stmt = $pdo->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, ?)");
                $stmt->execute([$username, $hash, $role]);
                $message = "User '$username' created successfully.";
                $messageType = "success";
            } catch (PDOException $e) {
                $message = "Error: " . $e->getMessage();
                $messageType = "danger";
            }
        }

    } elseif ($_POST['action'] == 'change_password') {
        $uid      = intval($_POST['user_id']);
        $newpass  = $_POST['new_password'];
        $confirm  = $_POST['confirm_password'];

        if (strlen($newpass) < 6) {
            $message = "Password must be at least 6 characters.";
            $messageType = "danger";
        } elseif ($newpass !== $confirm) {
            $message = "Passwords do not match.";
            $messageType = "danger";
        } else {
            $hash = password_hash($newpass, PASSWORD_BCRYPT);
            $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$hash, $uid]);
            $message = "Password updated successfully.";
            $messageType = "success";
        }

    } elseif ($_POST['action'] == 'change_role') {
        $uid  = intval($_POST['user_id']);
        $role = in_array($_POST['role'], ['admin', 'temp', 'viewer']) ? $_POST['role'] : 'viewer';
        // Prevent removing all admins
        $admin_count = $pdo->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn();
        $current_role = $pdo->prepare("SELECT role FROM users WHERE id=?");
        $current_role->execute([$uid]);
        $current_role = $current_role->fetchColumn();
        if ($current_role === 'admin' && $role !== 'admin' && $admin_count <= 1) {
            $message = "Cannot remove the last admin account.";
            $messageType = "danger";
        } else {
            $pdo->prepare("UPDATE users SET role = ? WHERE id = ?")->execute([$role, $uid]);
            $message = "Role updated.";
            $messageType = "success";
        }

    } elseif ($_POST['action'] == 'delete_user') {
        $uid = intval($_POST['user_id']);
        // Cant delete self
        if ($uid === intval($_SESSION['user_id'])) {
            $message = "You cannot delete your own account.";
            $messageType = "danger";
        } else {
            $admin_count = $pdo->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn();
            $del_role    = $pdo->prepare("SELECT role FROM users WHERE id=?");
            $del_role->execute([$uid]);
            $del_role = $del_role->fetchColumn();
            if ($del_role === 'admin' && $admin_count <= 1) {
                $message = "Cannot delete the last admin.";
                $messageType = "danger";
            } else {
                $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$uid]);
                $message = "User deleted.";
                $messageType = "success";
            }
        }
    }
}

$users = $pdo->query("SELECT id, username, role, created_at FROM users ORDER BY role ASC, username ASC")->fetchAll(PDO::FETCH_ASSOC);

include '../includes/header.php';
?>

<!-- Add User Modal -->
<div id="addUserModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.7); z-index:9999; overflow-y:auto; padding: 2rem;">
    <div class="card" style="max-width: 450px; margin: 3rem auto; position: relative;">
        <span onclick="document.getElementById('addUserModal').style.display='none'" style="position:absolute; right:1rem; top:1rem; cursor:pointer; font-size:1.5rem; color:var(--text-muted);">&times;</span>
        <h4><i class="fas fa-user-plus text-primary"></i> Add New User</h4>
        <hr style="border-color: var(--border-color); margin: 0.75rem 0 1rem 0;">
        <form method="POST">
            <input type="hidden" name="action" value="create_user">
            <div class="form-group">
                <label class="form-label">Username *</label>
                <input type="text" name="username" class="form-control" required autofocus>
            </div>
            <div class="form-group">
                <label class="form-label">Password * (min 6 chars)</label>
                <input type="password" name="password" class="form-control" required>
            </div>
            <div class="form-group">
                <label class="form-label">Role</label>
                <select name="role" class="form-control">
                    <option value="viewer">Viewer (Read Only)</option>
                    <option value="temp">Temp (Limited Access)</option>
                    <option value="admin">Admin (Full Access)</option>
                </select>
            </div>
            <button class="btn btn-primary" style="width:100%;"><i class="fas fa-save"></i> Create User</button>
        </form>
    </div>
</div>

<!-- Change Password Modal -->
<div id="changePwModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.7); z-index:9999; overflow-y:auto; padding: 2rem;">
    <div class="card" style="max-width: 420px; margin: 3rem auto; position: relative;">
        <span onclick="document.getElementById('changePwModal').style.display='none'" style="position:absolute; right:1rem; top:1rem; cursor:pointer; font-size:1.5rem; color:var(--text-muted);">&times;</span>
        <h4><i class="fas fa-key text-warning"></i> Change Password</h4>
        <p class="text-muted" style="font-size:0.85rem;">For: <strong id="pwModalUsername"></strong></p>
        <hr style="border-color: var(--border-color); margin: 0.75rem 0 1rem 0;">
        <form method="POST">
            <input type="hidden" name="action" value="change_password">
            <input type="hidden" name="user_id" id="pwModalUserId">
            <div class="form-group">
                <label class="form-label">New Password *</label>
                <input type="password" name="new_password" class="form-control" required>
            </div>
            <div class="form-group">
                <label class="form-label">Confirm Password *</label>
                <input type="password" name="confirm_password" class="form-control" required>
            </div>
            <button class="btn btn-warning" style="width:100%;"><i class="fas fa-lock"></i> Update Password</button>
        </form>
    </div>
</div>

<div class="flex-between mb-1">
    <h2><i class="fas fa-user-shield text-primary"></i> User Management</h2>
    <button class="btn btn-primary" onclick="document.getElementById('addUserModal').style.display='block'">
        <i class="fas fa-user-plus"></i> Add User
    </button>
</div>

<?php if ($message): ?>
    <div class="card" style="border-left: 4px solid var(--accent-<?= $messageType ?>); background-color: rgba(0,0,0,0.2); margin-bottom:1rem;">
        <p class="text-<?= $messageType ?>" style="margin: 0; font-weight: 500;"><?= htmlspecialchars($message) ?></p>
    </div>
<?php endif; ?>

<div class="card">
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Username</th>
                    <th>Role</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                <tr>
                    <td>
                        <strong><?= htmlspecialchars($u['username']) ?></strong>
                        <?php if ($u['id'] == $_SESSION['user_id']): ?>
                            <span style="font-size:0.7rem; background:var(--accent-primary); color:#fff; padding:1px 6px; border-radius:3px; margin-left:5px;">You</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="action" value="change_role">
                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                            <select name="role" class="form-control" onchange="this.form.submit()" style="padding: 0.2rem 0.4rem; font-size:0.8rem; display:inline-block; width:auto;">
                                <option value="viewer"  <?= $u['role']=='viewer'  ? 'selected' : '' ?>>Viewer</option>
                                <option value="temp"    <?= $u['role']=='temp'    ? 'selected' : '' ?>>Temp</option>
                                <option value="admin"   <?= $u['role']=='admin'   ? 'selected' : '' ?>>Admin</option>
                            </select>
                        </form>
                    </td>
                    <td class="text-muted" style="font-size:0.8rem;"><?= date('d M Y', strtotime($u['created_at'])) ?></td>
                    <td style="white-space:nowrap;">
                        <!-- Change Password -->
                        <button class="btn btn-outline" style="padding:0.2rem 0.5rem; font-size:0.75rem;"
                            onclick="openPwModal(<?= $u['id'] ?>, '<?= htmlspecialchars($u['username'], ENT_QUOTES) ?>')">
                            <i class="fas fa-key"></i> Password
                        </button>
                        <!-- Delete -->
                        <?php if ($u['id'] != $_SESSION['user_id']): ?>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Delete user <?= htmlspecialchars($u['username'], ENT_QUOTES) ?>?');">
                            <input type="hidden" name="action" value="delete_user">
                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                            <button class="btn btn-outline" style="padding:0.2rem 0.5rem; font-size:0.75rem; color:var(--accent-danger);">
                                <i class="fas fa-trash"></i>
                            </button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card" style="margin-top:1rem; background: rgba(59,130,246,0.05); border-left: 4px solid var(--accent-primary);">
    <p style="margin:0; font-size:0.85rem;"><i class="fas fa-info-circle text-primary"></i>
        <strong>Role permissions:</strong>
        <strong>Admin</strong> — Full access including settings, backups, and user management. &nbsp;|&nbsp;
        <strong>Viewer</strong> — Can view all records but cannot add or modify. &nbsp;|&nbsp;
        <strong>Temp</strong> — Limited access, suitable for temporary staff.
    </p>
</div>

<script>
function openPwModal(id, username) {
    document.getElementById('pwModalUserId').value = id;
    document.getElementById('pwModalUsername').textContent = username;
    document.getElementById('changePwModal').style.display = 'block';
}
</script>

<?php include '../includes/footer.php'; ?>
