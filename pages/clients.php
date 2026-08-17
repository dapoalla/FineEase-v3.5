<?php
// pages/clients.php
require_once '../config/config.php';
require_once '../includes/functions.php';

require_login();

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] == 'create') {
        $name = sanitize_input($_POST['name']);
        $email = sanitize_input($_POST['email']);
        $phone = sanitize_input($_POST['phone']);
        $address = sanitize_input($_POST['address']);
        
        try {
            $stmt = $pdo->prepare("INSERT INTO clients (name, email, phone, address) VALUES (?, ?, ?, ?)");
            $stmt->execute([$name, $email, $phone, $address]);
            $message = "Client added successfully.";
            $messageType = "success";
        } catch (PDOException $e) {
            $message = "Error: " . $e->getMessage();
            $messageType = "danger";
        }
    } elseif ($_POST['action'] == 'update') {
        $id = intval($_POST['id']);
        $name = sanitize_input($_POST['name']);
        $email = sanitize_input($_POST['email']);
        $phone = sanitize_input($_POST['phone']);
        $address = sanitize_input($_POST['address']);
        
        try {
            $stmt = $pdo->prepare("UPDATE clients SET name=?, email=?, phone=?, address=? WHERE id=?");
            $stmt->execute([$name, $email, $phone, $address, $id]);
            $message = "Client updated successfully.";
            $messageType = "success";
        } catch (PDOException $e) {
            $message = "Error: " . $e->getMessage();
            $messageType = "danger";
        }
    } elseif ($_POST['action'] == 'delete') {
        $id = intval($_POST['client_id']);
        try {
            $stmt = $pdo->prepare("DELETE FROM clients WHERE id=?");
            $stmt->execute([$id]);
            $message = "Client deleted successfully.";
            $messageType = "success";
        } catch (PDOException $e) {
            $message = "Cannot delete client. They might be linked to existing Job Orders. Error: " . $e->getMessage();
            $messageType = "danger";
        }
    }
}

$clients = $pdo->query("SELECT * FROM clients ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

include '../includes/header.php';
?>

<div class="flex-between mb-1">
    <h2><i class="fas fa-users text-primary"></i> Client Management</h2>
    <button class="btn btn-primary" onclick="document.getElementById('addClientModal').style.display='block'">
        <i class="fas fa-user-plus"></i> Add New Client
    </button>
</div>

<!-- Add Client Modal -->
<div id="addClientModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.7); z-index:9999; overflow-y:auto; padding: 2rem;">
    <div class="card" style="max-width: 500px; margin: 3rem auto; position: relative;">
        <span onclick="document.getElementById('addClientModal').style.display='none'" style="position:absolute; right:1rem; top:1rem; cursor:pointer; font-size:1.5rem; color:var(--text-muted);">&times;</span>
        <h4><i class="fas fa-user-plus text-primary"></i> Add New Client</h4>
        <hr style="border-color: var(--border-color); margin: 0.75rem 0 1rem 0;">
        <form method="POST">
            <input type="hidden" name="action" value="create">
            <div class="form-group">
                <label class="form-label">Client / Company Name *</label>
                <input type="text" name="name" class="form-control" required autofocus>
            </div>
            <div class="form-group">
                <label class="form-label">Email Address(es)</label>
                <input type="text" name="email" class="form-control">
            </div>
            <div class="form-group">
                <label class="form-label">Phone Number(s)</label>
                <input type="text" name="phone" class="form-control">
            </div>
            <div class="form-group">
                <label class="form-label">Physical Address</label>
                <textarea name="address" class="form-control" rows="3"></textarea>
            </div>
            <button class="btn btn-primary" style="width: 100%;"><i class="fas fa-save"></i> Save Client</button>
        </form>
    </div>
</div>

<?php if ($message): ?>
    <div class="card" style="border-left: 4px solid var(--accent-<?= $messageType ?>); background-color: rgba(0,0,0,0.2);">
        <p class="text-<?= $messageType ?>" style="margin: 0; font-weight: 500;">
            <?= htmlspecialchars($message) ?>
        </p>
    </div>
<?php endif; ?>

<div class="card">
    <div class="flex-between mb-1">
        <h4><i class="fas fa-list text-primary"></i> Existing Clients</h4>
    </div>
    <div class="table-responsive">
        <table class="table" style="font-size: 0.85rem;">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Contact</th>
                    <th>Address</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($clients)): ?>
                    <tr><td colspan="4" class="text-center text-muted">No clients yet. Click "Add New Client" to get started.</td></tr>
                <?php else: ?>
                    <?php foreach($clients as $c): ?>
                        <tr>
                            <td style="font-weight: 600;"><?= htmlspecialchars($c['name']) ?></td>
                            <td>
                                <?php if($c['email']): ?><div><i class="fas fa-envelope text-muted"></i> <?= htmlspecialchars($c['email']) ?></div><?php endif; ?>
                                <?php if($c['phone']): ?><div><i class="fas fa-phone text-muted"></i> <?= htmlspecialchars($c['phone']) ?></div><?php endif; ?>
                            </td>
                            <td><?= nl2br(htmlspecialchars($c['address'])) ?></td>
                            <td>
                                <button class="btn btn-outline" style="padding: 0.2rem 0.5rem; color: var(--accent-primary); font-size: 0.75rem;" 
                                        onclick='editClient(<?= json_encode($c) ?>)'>
                                    <i class="fas fa-edit"></i>
                                </button>
                                <form method="POST" onsubmit="return confirm('Delete this client permanently?');" style="display:inline;">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="client_id" value="<?= $c['id'] ?>">
                                    <button class="btn btn-outline" style="padding: 0.2rem 0.5rem; color: var(--accent-danger); font-size: 0.75rem;"><i class="fas fa-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Edit Client Modal -->
<div id="editClientModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.7); z-index:9999; overflow-y:auto; padding: 2rem;">
    <div class="card" style="max-width: 500px; margin: 3rem auto; position: relative;">
        <span onclick="document.getElementById('editClientModal').style.display='none'" style="position:absolute; right:1rem; top:1rem; cursor:pointer; font-size:1.5rem; color:var(--text-muted);">&times;</span>
        <h4><i class="fas fa-user-edit text-primary"></i> Edit Client</h4>
        <hr style="border-color: var(--border-color); margin: 0.75rem 0 1rem 0;">
        <form method="POST">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" id="edit_id">
            <div class="form-group">
                <label class="form-label">Client / Company Name *</label>
                <input type="text" name="name" id="edit_name" class="form-control" required>
            </div>
            <div class="form-group">
                <label class="form-label">Email Address(es)</label>
                <input type="text" name="email" id="edit_email" class="form-control">
            </div>
            <div class="form-group">
                <label class="form-label">Phone Number(s)</label>
                <input type="text" name="phone" id="edit_phone" class="form-control">
            </div>
            <div class="form-group">
                <label class="form-label">Physical Address</label>
                <textarea name="address" id="edit_address" class="form-control" rows="3"></textarea>
            </div>
            <button class="btn btn-primary" style="width: 100%;"><i class="fas fa-save"></i> Update Client</button>
        </form>
    </div>
</div>

<script>
function editClient(client) {
    document.getElementById('edit_id').value = client.id;
    document.getElementById('edit_name').value = client.name;
    document.getElementById('edit_email').value = client.email;
    document.getElementById('edit_phone').value = client.phone;
    document.getElementById('edit_address').value = client.address;
    document.getElementById('editClientModal').style.display = 'block';
}
</script>

<?php include '../includes/footer.php'; ?>
