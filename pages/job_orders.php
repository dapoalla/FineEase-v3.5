<?php
// pages/job_orders.php
require_once '../config/config.php';
require_once '../includes/functions.php';

require_login();

$message = '';
$messageType = '';

// Handle actions (Create, Status Update)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] == 'create') {
        $client_id = intval($_POST['client_id']);
        $client_name = sanitize_input($_POST['client_name']); // Stored for legacy DB compatibility
        $project_type_id = intval($_POST['project_type_id']);
        $service_description = sanitize_input($_POST['service_description']);
        $date = sanitize_input($_POST['date']);
        $location = sanitize_input($_POST['location']);
        $apply_vat = isset($_POST['apply_vat']) ? 1 : 0;

        // Generate Custom Job ID: [CLI]-[YYYYMMDD]-[COUNT]
        try {
            $c_code = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $client_name), 0, 3));
            if (strlen($c_code) < 3) $c_code = str_pad($c_code, 3, 'X');
            
            $date_db  = date('Y-m-d', strtotime($date));
            $date_str = date('Ymd', strtotime($date));
            
            // Count existing for this client on this day
            $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM invoices WHERE client_id = ? AND date = ?");
            $count_stmt->execute([$client_id, $date_db]);
            $day_count = intval($count_stmt->fetchColumn()) + 1;
            $count_padded = str_pad($day_count, 2, '0', STR_PAD_LEFT);
            
            $custom_job_id = "{$c_code}-{$date_str}-{$count_padded}";

            // Final unique safety check
            $check_stmt = $pdo->prepare("SELECT COUNT(*) FROM invoices WHERE invoice_id = ?");
            $check_stmt->execute([$custom_job_id]);
            if ($check_stmt->fetchColumn() > 0) {
                $custom_job_id .= "-" . rand(1,9);
            }

            // Calculate Base Amount dynamically from line items if provided
            $amount = 0;
            $has_items = false;
            
            if (isset($_POST['item_desc']) && is_array($_POST['item_desc'])) {
                $has_items = true;
                foreach($_POST['item_desc'] as $k => $desc) {
                    $qty = floatval($_POST['item_qty'][$k]);
                    $price = floatval($_POST['item_price'][$k]);
                    $amount += ($qty * $price);
                }
            } else {
                $amount = floatval($_POST['amount']);
            }

            $pdo->beginTransaction();

            $sql = "INSERT INTO invoices (invoice_id, client_id, client_name, project_type_id, service_description, amount, total_with_vat, date, location, status, payment_status, has_line_items, line_items_total, apply_vat) 
                    VALUES (:jid, :cid, :cname, :pid, :desc, :amt, :tot, :dt, :loc, 'open', 'unpaid', :hli, :lit, :vat)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':jid' => $custom_job_id,
                ':cid' => $client_id,
                ':cname' => $client_name,
                ':pid' => $project_type_id,
                ':desc' => $service_description,
                ':amt' => $amount,
                ':tot' => $amount, 
                ':dt' => $date,
                ':loc' => $location,
                ':hli' => $has_items ? 1 : 0,
                ':lit' => $amount,
                ':vat' => $apply_vat
            ]);
            
            $job_id = $pdo->lastInsertId();

            if($has_items) {
                $stmt_li = $pdo->prepare("INSERT INTO job_order_line_items (job_order_id, description, quantity, unit_price, total) VALUES (?, ?, ?, ?, ?)");
                foreach($_POST['item_desc'] as $k => $desc) {
                    $d = sanitize_input($desc);
                    $q = floatval($_POST['item_qty'][$k]);
                    $p = floatval($_POST['item_price'][$k]);
                    $t = $q * $p;
                    if (!empty($d)) {
                        $stmt_li->execute([$job_id, $d, $q, $p, $t]);
                    }
                }
            }

            $pdo->commit();

            $message = "Job Order '{$custom_job_id}' created successfully!";
            $messageType = "success";

        } catch (PDOException $e) {
            $pdo->rollBack();
            $message = "Error creating Job Order: " . $e->getMessage();
            $messageType = "danger";
        }
    } elseif ($_POST['action'] == 'delete_job') {
        $del_id = intval($_POST['job_id']);
        try {
            // Cascades delete line_items, tithes, vat_records due to FK ON DELETE CASCADE
            $stmt = $pdo->prepare("DELETE FROM invoices WHERE id = ?");
            $stmt->execute([$del_id]);
            $message = "Job Order deleted successfully.";
            $messageType = "success";
        } catch (PDOException $e) {
            $message = "Cannot delete job: " . $e->getMessage();
            $messageType = "danger";
        }
    }
}

// Fetch lists for dropdowns
$clients = $pdo->query("SELECT id, name FROM clients ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$project_types = $pdo->query("SELECT id, name, code FROM project_types ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch recent Job Orders
$jobs = $pdo->query("SELECT id, invoice_id, client_name, service_description, total_with_vat, status, payment_status, date FROM invoices ORDER BY id DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);

include '../includes/header.php';
?>

<div class="flex-between mb-1">
    <h2><i class="fas fa-file-invoice text-primary"></i> Job Orders</h2>
    <button class="btn btn-primary" onclick="document.getElementById('createJobModal').style.display='block'">
        <i class="fas fa-plus"></i> New Job Order
    </button>
</div>

<?php if ($message): ?>
    <div class="card" style="border-left: 4px solid var(--accent-<?= $messageType ?>); background-color: rgba(0,0,0,0.2);">
        <p class="text-<?= $messageType ?>" style="margin: 0; font-weight: 500;">
            <?= htmlspecialchars($message) ?>
        </p>
    </div>
<?php endif; ?>

<!-- Create Modal Component logic (pure HTML/CSS) -->
<div id="createJobModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.7); z-index:9999; overflow-y:auto; padding: 2rem;">
    <div class="card" style="max-width: 800px; margin: 2rem auto; position: relative;">
        <span onclick="document.getElementById('createJobModal').style.display='none'" style="position:absolute; right:1rem; top:1rem; cursor:pointer; font-size:1.5rem;">&times;</span>
        <h4>Create New Job Order</h4>
        <hr style="border-color: var(--border-color); margin: 1rem 0;">
        <form method="POST">
            <input type="hidden" name="action" value="create">
            
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:1rem;">
                <div class="form-group">
                    <div class="flex-between">
                        <label class="form-label">Client</label>
                        <a href="clients.php" style="font-size: 0.75rem; color: var(--accent-primary);"><i class="fas fa-plus"></i> New Client</a>
                    </div>
                    <select name="client_id" id="clientSelect" class="form-control" required onchange="document.getElementById('cname_hidden').value = this.options[this.selectedIndex].text;">
                        <option value="">Select a Client...</option>
                        <?php foreach ($clients as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="hidden" name="client_name" id="cname_hidden" value="">
                </div>

                <div class="form-group">
                    <label class="form-label">Project Type</label>
                    <select name="project_type_id" class="form-control" required>
                        <?php foreach ($project_types as $pt): ?>
                            <option value="<?= $pt['id'] ?>"><?= htmlspecialchars($pt['name']) ?> (<?= $pt['code'] ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Brief Service Description</label>
                <input type="text" name="service_description" class="form-control" required>
            </div>

            <!-- Line Items UI -->
            <div style="background: rgba(0,0,0,0.1); padding: 1rem; border-radius: 8px; margin-bottom: 1rem;">
                <div class="flex-between mb-1">
                    <label class="form-label" style="margin:0;">Invoice Line Items</label>
                    <button type="button" class="btn btn-outline" style="padding: 0.2rem 0.5rem; font-size: 0.8rem;" onclick="add_line_item()"><i class="fas fa-plus"></i> Add Row</button>
                </div>
                
                <div id="lineItemsContainer">
                    <div class="line-item-row" style="display:grid; grid-template-columns: 3fr 1fr 1fr 30px; gap: 0.5rem; margin-bottom: 0.5rem;">
                        <input type="text" name="item_desc[]" class="form-control" placeholder="Item Description" required>
                        <input type="number" step="0.01" name="item_qty[]" class="form-control" placeholder="Qty" value="1" required>
                        <input type="number" step="0.01" name="item_price[]" class="form-control" placeholder="Unit Price" required>
                        <button type="button" class="btn btn-outline" style="padding: 0; color:var(--accent-danger);" onclick="this.parentElement.remove()"><i class="fas fa-trash"></i></button>
                    </div>
                </div>
            </div>

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:1rem;">
                <div class="form-group">
                    <label class="form-label">Location (Optional)</label>
                    <input type="text" name="location" class="form-control">
                </div>
                <div class="form-group">
                    <label class="form-label">Date</label>
                    <input type="date" name="date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                </div>
            </div>

            <div class="form-group">
                <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                    <input type="checkbox" name="apply_vat" value="1" checked>
                    <span>Charge VAT for this Job Order</span>
                </label>
            </div>
            
            <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 1rem;"><i class="fas fa-check"></i> Generate Job Order</button>
        </form>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th class="sortable" data-col="0" style="cursor:pointer;">Date <span class="sort-arrow">↓</span></th>
                    <th>Action</th>
                    <th class="sortable" data-col="2" style="cursor:pointer;">Job ID <span class="sort-arrow"></span></th>
                    <th class="sortable" data-col="3" style="cursor:pointer;">Client <span class="sort-arrow"></span></th>
                    <th>Description</th>
                    <th class="sortable text-right" data-col="5" style="cursor:pointer;">Total Value <span class="sort-arrow"></span></th>
                    <th>Status</th>
                    <th>Payment</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($jobs)): ?>
                    <tr><td colspan="8" class="text-center text-muted">No job orders found.</td></tr>
                <?php else: ?>
                    <?php foreach ($jobs as $job): ?>
                        <tr data-value="<?= $job['total_with_vat'] ?>">
                            <td data-sort="<?= strtotime($job['date']) ?>"><?= date('d M, Y', strtotime($job['date'])) ?></td>
                            <td>
                                <a href="view_job.php?id=<?= $job['id'] ?>" class="btn btn-outline" style="padding: 0.3rem 0.6rem; font-size: 0.75rem;"><i class="fas fa-eye"></i></a>
                                <form method="POST" onsubmit="return confirm('Permanently delete this Job Order and all its data?');" style="display:inline;">
                                    <input type="hidden" name="action" value="delete_job">
                                    <input type="hidden" name="job_id" value="<?= $job['id'] ?>">
                                    <button class="btn btn-outline" style="padding: 0.3rem 0.6rem; font-size: 0.75rem; color:var(--accent-danger);"><i class="fas fa-trash"></i></button>
                                </form>
                            </td>
                            <td style="font-weight: 600; color: var(--accent-primary);"><a href="view_job.php?id=<?= $job['id'] ?>"><?= htmlspecialchars($job['invoice_id']) ?></a></td>
                            <td><?= htmlspecialchars($job['client_name']) ?></td>
                            <td style="max-width: 200px; overflow: hidden; text-overflow: ellipsis;"><?= htmlspecialchars($job['service_description']) ?></td>
                            <td class="currency-naira text-right" data-sort="<?= $job['total_with_vat'] ?>"><?= number_format($job['total_with_vat'], 2) ?></td>
                            <td>
                                <?php 
                                    $s_color = $job['status'] == 'completed' ? 'success' : 'warning';
                                ?>
                                <span style="background: var(--accent-<?= $s_color ?>); padding: 0.2rem 0.5rem; border-radius: 4px; font-size: 0.75rem; color: #fff;">
                                    <?= strtoupper($job['status']) ?>
                                </span>
                            </td>
                            <td>
                                <?php 
                                    $p_color = $job['payment_status'] == 'fully_paid' ? 'success' : ($job['payment_status'] == 'partly_paid' ? 'warning' : 'danger');
                                ?>
                                <span style="background: var(--accent-<?= $p_color ?>); padding: 0.2rem 0.5rem; border-radius: 4px; font-size: 0.75rem; color: #fff;">
                                    <?= strtoupper(str_replace('_', ' ', $job['payment_status'])) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <tfoot id="jobsFooter" style="font-weight:700; background: rgba(59,130,246,0.07);">
                <tr>
                    <td colspan="5">Total <span id="jobCount" style="font-size:0.8rem; font-weight:400; color:var(--text-muted);"></span></td>
                    <td class="text-right currency-naira" id="jobTotal"></td>
                    <td colspan="2"></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<script>
function add_line_item() {
    let container = document.getElementById('lineItemsContainer');
    let row = document.createElement('div');
    row.className = 'line-item-row';
    row.style.cssText = 'display:grid; grid-template-columns: 3fr 1fr 1fr 30px; gap: 0.5rem; margin-bottom: 0.5rem;';
    row.innerHTML = `
        <input type="text" name="item_desc[]" class="form-control" placeholder="Item Description" required>
        <input type="number" step="0.01" name="item_qty[]" class="form-control" placeholder="Qty" value="1" required>
        <input type="number" step="0.01" name="item_price[]" class="form-control" placeholder="Unit Price" required>
        <button type="button" class="btn btn-outline" style="padding: 0; color:var(--accent-danger);" onclick="this.parentElement.remove()"><i class="fas fa-trash"></i></button>
    `;
    container.appendChild(row);
}

// Sortable Table
document.addEventListener('DOMContentLoaded', function() {
    const table = document.querySelector('.table');
    if (!table) return;
    const tbody = table.querySelector('tbody');
    const ths = table.querySelectorAll('th.sortable');
    let sortCol = 0, sortDir = -1; // default: date descending (index 0)

    function updateFooter() {
        const rows = Array.from(tbody.querySelectorAll('tr[data-value]'));
        let total = rows.reduce((s, r) => s + parseFloat(r.dataset.value || 0), 0);
        const el = document.getElementById('jobTotal');
        const cnt = document.getElementById('jobCount');
        if (el) el.textContent = total.toLocaleString('en-NG', {minimumFractionDigits:2, maximumFractionDigits:2});
        if (cnt) cnt.textContent = '(' + rows.length + ' jobs)';
    }

    function sortTable(colIdx, dir) {
        const rows = Array.from(tbody.querySelectorAll('tr[data-value]'));
        rows.sort((a, b) => {
            const ac = a.cells[colIdx];
            const bc = b.cells[colIdx];
            const av = ac.dataset.sort !== undefined ? ac.dataset.sort : ac.textContent.trim();
            const bv = bc.dataset.sort !== undefined ? bc.dataset.sort : bc.textContent.trim();
            
            // numeric?
            const an = parseFloat(av.replace(/[^0-9.-]/g, ''));
            const bn = parseFloat(bv.replace(/[^0-9.-]/g, ''));
            
            if (!isNaN(an) && !isNaN(bn)) return (an - bn) * dir;
            
            // fallback to string compare
            return av.localeCompare(bv, undefined, {numeric: true, sensitivity: 'base'}) * dir;
        });
        rows.forEach(r => tbody.appendChild(r));
        ths.forEach(th => th.querySelector('.sort-arrow').textContent = '');
        const active = table.querySelector('th.sortable[data-col="' + colIdx + '"] .sort-arrow');
        if (active) active.textContent = dir === 1 ? ' ↑' : ' ↓';
    }

    ths.forEach(th => {
        th.addEventListener('click', function() {
            const col = parseInt(this.dataset.col);
            if (sortCol === col) sortDir *= -1; else { sortCol = col; sortDir = 1; }
            sortTable(sortCol, sortDir);
        });
    });

    sortTable(sortCol, sortDir);
    updateFooter();
});
</script>

<?php include '../includes/footer.php'; ?>
