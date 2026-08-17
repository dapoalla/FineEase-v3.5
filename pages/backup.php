<?php
// pages/backup.php
require_once '../config/config.php';
require_once '../includes/functions.php';
require_once '../includes/backup_manager.php';

require_admin(); // Only admins can manage DB 

$backupManager = new BackupManager($pdo);
$message = '';
$messageType = '';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];

        if ($action === 'create') {
            $result = $backupManager->createBackup();
            if ($result['success']) {
                $message = "Backup created successfully: " . $result['filename'];
                $messageType = 'success';
            } else {
                $message = "Backup creation failed: " . $result['message'];
                $messageType = 'danger';
            }
        } elseif ($action === 'restore') {
            $filename = sanitize_input($_POST['filename']);
            $filepath = '../backups/' . basename($filename);
            $result = $backupManager->restoreBackup($filepath);
            
            if ($result['success']) {
                $message = $result['message'];
                $messageType = 'success';
            } else {
                $message = $result['message'];
                $messageType = 'danger';
            }
        } elseif ($action === 'upload') {
            if (isset($_FILES['backup_file']) && $_FILES['backup_file']['error'] == UPLOAD_ERR_OK) {
                $tmpPath = $_FILES['backup_file']['tmp_name'];
                $originalName = basename($_FILES['backup_file']['name']);
                
                // Allow ONLY .sql 
                $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
                if ($ext === 'sql') {
                    $destination = '../backups/' . time() . '_' . $originalName;
                    if (move_uploaded_file($tmpPath, $destination)) {
                        $message = "Backup file uploaded successfully. You can now restore it.";
                        $messageType = 'success';
                    } else {
                        $message = "Failed to save uploaded file.";
                        $messageType = 'danger';
                    }
                } else {
                    $message = "Invalid file type. Only .sql files are allowed.";
                    $messageType = 'danger';
                }
            } else {
                $message = "No valid file uploaded.";
                $messageType = 'danger';
            }
        } elseif ($action === 'delete') {
            $filename = sanitize_input($_POST['filename']);
            if ($backupManager->deleteBackup($filename)) {
                $message = "Backup deleted successfully.";
                $messageType = 'success';
            } else {
                $message = "Backup deletion failed.";
                $messageType = 'danger';
            }
        }
    }
}

$backups = $backupManager->getBackups();

// Include UI Components
include '../includes/header.php';
?>

<div class="flex-between mb-1">
    <h2><i class="fas fa-database text-primary"></i> Database Management</h2>
</div>

<?php if ($message): ?>
    <div class="card" style="border-left: 4px solid var(--accent-<?= $messageType ?>); background-color: rgba(0,0,0,0.2);">
        <p class="text-<?= $messageType ?>" style="margin: 0; font-weight: 500;">
            <?= htmlspecialchars($message) ?>
        </p>
    </div>
<?php endif; ?>

<div class="card">
    <div class="flex-between" style="border-bottom: 1px solid var(--border-color); padding-bottom: 1rem; margin-bottom: 1rem;">
        <h4>System Operations</h4>
        <form method="POST" style="display: inline;">
            <input type="hidden" name="action" value="create">
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-download"></i> Generate New Backup
            </button>
        </form>
    </div>

    <form method="POST" enctype="multipart/form-data" style="margin-top: 1.5rem;">
        <input type="hidden" name="action" value="upload">
        <label class="form-label text-muted">Upload External External SQL Backup File</label>
        <div style="display: flex; gap: 1rem; align-items: center;">
            <input type="file" name="backup_file" accept=".sql" class="form-control" required style="padding: 0.5rem;">
            <button type="submit" class="btn btn-outline" style="white-space: nowrap;">
                <i class="fas fa-upload"></i> Upload
            </button>
        </div>
    </form>
</div>

<div class="card">
    <h4>Available Backups</h4>
    <p class="text-muted" style="margin-bottom: 1rem;">Restoring a backup will overwrite the current database entirely. Custom v3.0 tax fields will be automatically preserved via migration script.</p>

    <?php if (empty($backups)): ?>
        <p class="text-muted text-center" style="padding: 2rem;">No backups found in system.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Date Created</th>
                        <th>Filename</th>
                        <th>Size</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($backups as $b): ?>
                        <tr>
                            <td><?= date('M d, Y H:i:s', $b['date']) ?></td>
                            <td><span style="font-family: monospace; font-size: 0.8rem;"><?= htmlspecialchars($b['filename']) ?></span></td>
                            <td><?= number_format($b['size'] / 1024 / 1024, 2) ?> MB</td>
                            <td class="text-right">
                                <div style="display: flex; justify-content: flex-end; gap: 0.5rem;">
                                    <form method="POST" onsubmit="return confirm('Are you sure you want to RESTORE this backup? ALL current data will be lost!');">
                                        <input type="hidden" name="action" value="restore">
                                        <input type="hidden" name="filename" value="<?= htmlspecialchars($b['filename']) ?>">
                                        <button type="submit" class="btn btn-success" style="padding: 0.25rem 0.5rem; font-size: 0.8rem;">
                                            <i class="fas fa-history"></i> Restore
                                        </button>
                                    </form>

                                    <form method="POST" onsubmit="return confirm('Delete this backup permanently?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="filename" value="<?= htmlspecialchars($b['filename']) ?>">
                                        <button type="submit" class="btn btn-danger" style="padding: 0.25rem 0.5rem; font-size: 0.8rem;">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>

                                    <!-- Direct File Download Link -->
                                    <a href="<?= BASE_URL ?>/backups/<?= urlencode($b['filename']) ?>" download class="btn btn-outline" style="padding: 0.25rem 0.5rem; font-size: 0.8rem;">
                                        <i class="fas fa-file-download"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php include '../includes/footer.php'; ?>
