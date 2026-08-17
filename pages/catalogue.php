<?php
require_once '../config/config.php';
require_once '../includes/functions.php';
require_login();

$message = '';
$messageType = '';

// Handle form submission for adding replacing catalogue items
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    if ($action === 'add_item') {
        $job_order_id = intval($_POST['job_order_id']);
        $line_item_number = sanitize_input($_POST['line_item_number']);
        $title = sanitize_input($_POST['title']);
        $description = sanitize_input($_POST['description']);
        $quantity = intval($_POST['quantity'] ?? 1);
        $manufacturer_url = sanitize_input($_POST['manufacturer_url']);
        $imagePath = null;
        if (!empty($_FILES['image']['name'])) {
            $targetDir = '../uploads/catalogue_images/';
            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0755, true);
            }
            $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
            $fileName = uniqid('cat_') . '.' . $ext;
            $targetFile = $targetDir . $fileName;
            if (move_uploaded_file($_FILES['image']['tmp_name'], $targetFile)) {
                $imagePath = 'uploads/catalogue_images/' . $fileName;
            }
        }
        $stmt = $pdo->prepare("INSERT INTO catalogue_items (job_order_id, line_item_number, title, description, image_path, quantity, manufacturer_url) VALUES (?,?,?,?,?,?,?)");
        $stmt->execute([$job_order_id, $line_item_number, $title, $description, $imagePath, $quantity, $manufacturer_url]);
        
        header("Location: catalogue.php?job_id=" . $job_order_id . "&msg=Item added successfully");
        exit;
    } elseif ($action === 'edit_item') {
        $item_id = intval($_POST['item_id']);
        $job_id = intval($_POST['job_order_id']);
        $line_item_number = sanitize_input($_POST['line_item_number']);
        $title = sanitize_input($_POST['title']);
        $description = sanitize_input($_POST['description']);
        $quantity = intval($_POST['quantity']);
        $manufacturer_url = sanitize_input($_POST['manufacturer_url']);
        
        $sql = "UPDATE catalogue_items SET line_item_number = ?, title = ?, description = ?, quantity = ?, manufacturer_url = ? WHERE id = ?";
        $params = [$line_item_number, $title, $description, $quantity, $manufacturer_url, $item_id];
        
        if (!empty($_FILES['image']['name'])) {
            $targetDir = '../uploads/catalogue_images/';
            $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
            $fileName = uniqid('cat_') . '.' . $ext;
            $targetFile = $targetDir . $fileName;
            if (move_uploaded_file($_FILES['image']['tmp_name'], $targetFile)) {
                $imagePath = 'uploads/catalogue_images/' . $fileName;
                $sql = "UPDATE catalogue_items SET line_item_number = ?, title = ?, description = ?, quantity = ?, manufacturer_url = ?, image_path = ? WHERE id = ?";
                $params = [$line_item_number, $title, $description, $quantity, $manufacturer_url, $imagePath, $item_id];
            }
        }
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        header("Location: catalogue.php?job_id=" . $job_id . "&msg=Item updated successfully");
        exit;
    } elseif ($action === 'delete_item') {
        $item_id = intval($_POST['item_id']);
        $job_id = intval($_POST['job_id']);
        $stmt = $pdo->prepare("DELETE FROM catalogue_items WHERE id = ?");
        $stmt->execute([$item_id]);
        header("Location: catalogue.php?job_id=" . $job_id . "&msg=Item deleted successfully");
        exit;
    } elseif ($action === 'delete_catalogue') {
        $job_id = intval($_POST['job_order_id']);
        $stmt = $pdo->prepare("DELETE FROM catalogue_items WHERE job_order_id = ?");
        $stmt->execute([$job_id]);
        header("Location: catalogue.php?msg=Catalogue deleted successfully");
        exit;
    }
}

if (isset($_GET['msg'])) {
    $message = sanitize_input($_GET['msg']);
    $messageType = 'success';
}

$view_mode = isset($_GET['job_id']) ? 'items' : 'list';

include '../includes/header.php';
?>
<div class="container">
    <div class="d-flex justify-content-between align-items-center mt-3 mb-3">
        <h2><i class="fas fa-box-open text-primary"></i> Product Catalogues</h2>
        <?php if ($view_mode === 'items'): ?>
            <a href="catalogue.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left"></i> Back to Catalogues</a>
        <?php else: ?>
            <button class="btn btn-success" onclick="document.getElementById('addModal').style.display='block'">
                <i class="fas fa-plus"></i> New Item
            </button>
        <?php endif; ?>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?= $messageType ?> border-left-success border-left-4"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <?php if ($view_mode === 'list'): 
        // Group catalogues by job order
        $catalogues = [];
        try {
            $catalogues = $pdo->query("SELECT i.id as job_order_id, i.invoice_id, i.client_name, COUNT(ci.id) as item_count, SUM(CASE WHEN ci.image_path IS NOT NULL THEN 1 ELSE 0 END) as photo_count FROM invoices i JOIN catalogue_items ci ON i.id = ci.job_order_id GROUP BY i.id ORDER BY i.id DESC")->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            echo "<div class='alert alert-danger'>Table missing. Please go to Settings and click 'Repair & Sync Database'.</div>";
        }
    ?>
        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="thead-light">
                        <tr>
                            <th>Job Order</th>
                            <th>Total Items</th>
                            <th>Photos Attached</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($catalogues)): ?>
                            <tr><td colspan="4" class="text-center text-muted">No catalogues found. Click "New Item" to start one.</td></tr>
                        <?php else: ?>
                            <?php foreach ($catalogues as $cat): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($cat['client_name']) ?></strong><br>
                                        <span class="text-muted" style="font-size: 0.85rem;"><?= htmlspecialchars($cat['invoice_id']) ?></span>
                                    </td>
                                    <td><span class="badge badge-primary badge-pill" style="font-size: 14px;"><?= $cat['item_count'] ?> items</span></td>
                                    <td>
                                        <?php if ($cat['photo_count'] > 0): ?>
                                            <span class="text-success"><i class="fas fa-images"></i> <?= $cat['photo_count'] ?> photos</span>
                                        <?php else: ?>
                                            <span class="text-muted text-sm"><i class="fas fa-images"></i> No photos</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div style="display: flex; gap: 0.5rem; align-items: center;">
                                            <a href="catalogue.php?job_id=<?= $cat['job_order_id'] ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-edit"></i> Manage Info</a>
                                            <form method="POST" action="print_catalogue.php" target="_blank" style="margin:0;">
                                                <input type="hidden" name="job_order_id" value="<?= $cat['job_order_id'] ?>" />
                                                <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-print"></i> Print PDF</button>
                                            </form>
                                            <form method="POST" onsubmit="return confirm('Delete entire catalogue for this job?');" style="margin:0;">
                                                <input type="hidden" name="action" value="delete_catalogue" />
                                                <input type="hidden" name="job_order_id" value="<?= $cat['job_order_id'] ?>" />
                                                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    <?php else: 
        // View specific Job Order
        $job_id = intval($_GET['job_id']);
        $job = ['client_name' => 'Unknown', 'invoice_id' => '000'];
        $items = [];
        try {
            $jobInfo = $pdo->prepare("SELECT invoice_id, client_name FROM invoices WHERE id = ?");
            $jobInfo->execute([$job_id]);
            if ($j = $jobInfo->fetch(PDO::FETCH_ASSOC)) $job = $j;

            $itemsQuery = $pdo->prepare("SELECT ci.* FROM catalogue_items ci WHERE ci.job_order_id = ? ORDER BY ci.created_at ASC");
            $itemsQuery->execute([$job_id]);
            $items = $itemsQuery->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            echo "<div class='alert alert-danger'>Table missing. Please go to Settings and click 'Repair & Sync Database'.</div>";
        }
    ?>

        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center" style="background: var(--bg-main);">
                <h5 class="mb-0">
                    Catalogue Items for Job: <?= htmlspecialchars($job['client_name']) ?> <span class="text-muted" style="font-size: 0.9rem;">(<?= htmlspecialchars($job['invoice_id']) ?>)</span>
                </h5>
                <div style="display: flex; gap: 0.5rem;">
                    <button class="btn btn-sm btn-success" onclick="document.getElementById('addModal').style.display='block'">
                        <i class="fas fa-plus"></i> Add Item
                    </button>
                    <form method="POST" action="print_catalogue.php" target="_blank" style="margin:0;">
                        <input type="hidden" name="job_order_id" value="<?= $job_id ?>" />
                        <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-print"></i> Print PDF</button>
                    </form>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="thead-light">
                        <tr>
                            <th style="width: 100px; text-align: center;">Image</th>
                            <th style="width: 50px;">S/N</th>
                            <th>Item Title & Description</th>
                            <th style="width: 80px; text-align: center;">Qty</th>
                            <th style="width: 100px; text-align: center;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                         <?php if (empty($items)): ?>
                            <tr><td colspan="5" class="text-center text-muted">No items in this catalogue yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($items as $it): ?>
                                <tr>
                                    <td style="text-align: center; vertical-align: middle;">
                                        <?php if ($it['image_path']): ?>
                                            <div style="width: 80px; height: 80px; margin: 0 auto; background: #f8f9fa; border: 1px solid #ddd; border-radius: 4px; display: flex; align-items: center; justify-content: center; overflow: hidden;">
                                                <img src="../<?= htmlspecialchars($it['image_path']) ?>" style="max-width: 100%; max-height: 100%; object-fit: contain;" />
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted">No Image</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($it['line_item_number'] ?? '-') ?></td>
                                    <td>
                                        <strong><?= htmlspecialchars($it['title']) ?></strong><br>
                                        <span class="text-muted" style="font-size: 0.85rem;"><?= nl2br(htmlspecialchars($it['description'])) ?></span>
                                        <?php if (!empty($it['manufacturer_url'])): ?>
                                            <div style="margin-top: 5px;">
                                                <a href="<?= htmlspecialchars($it['manufacturer_url']) ?>" target="_blank" class="badge badge-outline-info" style="font-size: 0.75rem;"><i class="fas fa-external-link-alt"></i> Manufacturer Site</a>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align: center; font-weight: bold; font-size: 1.1rem;"><?= $it['quantity'] ?></td>
                                    <td style="text-align: center;">
                                        <div style="display: flex; gap: 0.3rem; justify-content: center;">
                                            <button class="btn btn-sm btn-outline-primary" onclick='editItem(<?= json_encode($it) ?>)'>
                                                <i class="fas fa-pencil-alt"></i>
                                            </button>
                                            <form method="POST" onsubmit="return confirm('Delete this item?');" style="margin:0;">
                                                <input type="hidden" name="action" value="delete_item" />
                                                <input type="hidden" name="item_id" value="<?= $it['id'] ?>" />
                                                <input type="hidden" name="job_id" value="<?= $job_id ?>" />
                                                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    <?php endif; ?>
</div>

<!-- Add Item Modal -->
<div id="addModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.7); z-index:9999; overflow-y:auto; padding:2rem;">
    <div class="card" style="max-width:600px; margin:2rem auto; position:relative;">
        <span onclick="document.getElementById('addModal').style.display='none'" style="position:absolute; right:1.5rem; top:1.5rem; cursor:pointer; font-size:1.5rem;">&times;</span>
        <h4 class="p-3 border-bottom"><i class="fas fa-plus-circle text-success"></i> Add Catalogue Item</h4>
        <form method="POST" enctype="multipart/form-data" class="p-3">
            <input type="hidden" name="action" value="add_item" />
            
            <?php if ($view_mode === 'items'): ?>
                <input type="hidden" name="job_order_id" value="<?= $job_id ?>" />
            <?php else: ?>
                <div class="form-group">
                    <label>Job Order</label>
                    <select name="job_order_id" class="form-control" required>
                        <option value="">-- Select Job Order --</option>
                        <?php 
                        $allJobs = $pdo->query("SELECT id, invoice_id, client_name FROM invoices ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
                        foreach ($allJobs as $j): ?>
                            <option value="<?= $j['id'] ?>"><?= htmlspecialchars($j['client_name']) ?> (<?= htmlspecialchars($j['invoice_id']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <div class="row">
                <div class="col-md-3">
                    <div class="form-group">
                        <label>S/N</label>
                        <input type="text" name="line_item_number" class="form-control" placeholder="1, 2a.." />
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label>Title</label>
                        <input type="text" name="title" class="form-control" required placeholder="Product Name" />
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        <label>Qty</label>
                        <input type="number" name="quantity" class="form-control" min="1" value="1" />
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label>Description</label>
                <textarea name="description" class="form-control" rows="3" placeholder="Additional details"></textarea>
            </div>

            <div class="form-group">
                <label>Manufacturer Details URL</label>
                <input type="url" name="manufacturer_url" class="form-control" placeholder="https://example.com/product-details" />
            </div>
            
            <div class="form-group">
                <label>Image (Optional)</label>
                <input type="file" name="image" accept="image/*" class="form-control-file" />
            </div>
            <hr>
            <button type="submit" class="btn btn-primary btn-block" style="font-size: 1.1rem; padding: 0.5rem;"><i class="fas fa-save"></i> Save Item to Catalogue</button>
        </form>
    </div>
</div>

<!-- Edit Item Modal -->
<div id="editModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.7); z-index:9999; overflow-y:auto; padding:2rem;">
    <div class="card" style="max-width:600px; margin:2rem auto; position:relative;">
        <span onclick="document.getElementById('editModal').style.display='none'" style="position:absolute; right:1.5rem; top:1.5rem; cursor:pointer; font-size:1.5rem;">&times;</span>
        <h4 class="p-3 border-bottom"><i class="fas fa-edit text-primary"></i> Edit Catalogue Item</h4>
        <form method="POST" enctype="multipart/form-data" class="p-3">
            <input type="hidden" name="action" value="edit_item" />
            <input type="hidden" name="item_id" id="edit_item_id" />
            <input type="hidden" name="job_order_id" value="<?= $view_mode === 'items' ? $job_id : '' ?>" id="edit_job_order_id" />
            
            <div class="row">
                <div class="col-md-3">
                    <div class="form-group">
                        <label>S/N</label>
                        <input type="text" name="line_item_number" id="edit_line_item_number" class="form-control" />
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label>Title</label>
                        <input type="text" name="title" id="edit_title" class="form-control" required />
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        <label>Qty</label>
                        <input type="number" name="quantity" id="edit_quantity" class="form-control" min="1" />
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label>Description</label>
                <textarea name="description" id="edit_description" class="form-control" rows="3"></textarea>
            </div>

            <div class="form-group">
                <label>Manufacturer Details URL</label>
                <input type="url" name="manufacturer_url" id="edit_manufacturer_url" class="form-control" placeholder="https://example.com/product-details" />
            </div>
            
            <div class="form-group">
                <label>Image (Optional - Upload to replace current)</label>
                <input type="file" name="image" accept="image/*" class="form-control-file" />
            </div>
            <hr>
            <button type="submit" class="btn btn-primary btn-block" style="font-size: 1.1rem; padding: 0.5rem;"><i class="fas fa-save"></i> Update Item</button>
        </form>
    </div>
</div>

<script>
function editItem(item) {
    document.getElementById('edit_item_id').value = item.id;
    document.getElementById('edit_job_order_id').value = item.job_order_id;
    document.getElementById('edit_line_item_number').value = item.line_item_number || '';
    document.getElementById('edit_title').value = item.title;
    document.getElementById('edit_quantity').value = item.quantity;
    document.getElementById('edit_description').value = item.description || '';
    document.getElementById('edit_manufacturer_url').value = item.manufacturer_url || '';
    document.getElementById('editModal').style.display = 'block';
}
</script>
<?php include '../includes/footer.php'; ?>
