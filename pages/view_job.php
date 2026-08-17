<?php
// pages/view_job.php
require_once '../config/config.php';
require_once '../includes/functions.php';
require_once '../includes/financial_engine.php';

require_login();

if (!isset($_GET['id'])) {
    die("Job ID missing.");
}
$job_id = intval($_GET['id']);

$message = '';
$messageType = '';

// Handle Updates
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    try {
        if ($_POST['action'] == 'update_status') {
            $status = $_POST['status']; // open, in_progress, completed, cancelled
            $stmt = $pdo->prepare("UPDATE invoices SET status = ? WHERE id = ?");
            $stmt->execute([$status, $job_id]);
            $message = "Job status updated successfully.";
            $messageType = "success";
            
            // Check Tithe Engine just in case
            evaluate_tithe_trigger($pdo, $job_id);

        } elseif ($_POST['action'] == 'update_payment') {
            $stmt = $pdo->prepare("UPDATE invoices SET payment_status = ? WHERE id = ?");
            $stmt->execute([$_POST['payment_status'], $job_id]);
            $message = "Payment status updated successfully.";
            $messageType = "success";
            
             // Check Tithe Engine 
             evaluate_tithe_trigger($pdo, $job_id);
        } elseif ($_POST['action'] == 'update_vat') {
            $apply_vat = isset($_POST['apply_vat']) ? 1 : 0;
            $stmt = $pdo->prepare("UPDATE invoices SET apply_vat = ? WHERE id = ?");
            $stmt->execute([$apply_vat, $job_id]);
            $message = "VAT setting updated.";
            $messageType = "success";
        } elseif ($_POST['action'] == 'update_line_items') {
            $pdo->beginTransaction();
            // Delete existing
            $pdo->prepare("DELETE FROM job_order_line_items WHERE job_order_id = ?")->execute([$job_id]);
            
            $total_amount = 0;
            if (isset($_POST['item_desc']) && is_array($_POST['item_desc'])) {
                $stmt_li = $pdo->prepare("INSERT INTO job_order_line_items (job_order_id, description, quantity, unit_price, total) VALUES (?, ?, ?, ?, ?)");
                foreach($_POST['item_desc'] as $k => $desc) {
                    $d = sanitize_input($desc);
                    $q = floatval($_POST['item_qty'][$k]);
                    $p = floatval($_POST['item_price'][$k]);
                    $t = $q * $p;
                    if (!empty($d)) {
                        $stmt_li->execute([$job_id, $d, $q, $p, $t]);
                        $total_amount += $t;
                    }
                }
            }
            // Update invoice total
            $stmt = $pdo->prepare("UPDATE invoices SET amount = ?, total_with_vat = ?, line_items_total = ?, has_line_items = ? WHERE id = ?");
            // For now, total_with_vat update is simplified, it will be recalculated on print anyway but let's keep it consistent
            $stmt->execute([$total_amount, $total_amount, $total_amount, ($total_amount > 0 ? 1 : 0), $job_id]);
            
            $pdo->commit();
            $message = "Line items updated successfully.";
            $messageType = "success";
            
            // Refresh job data
            $stmt = $pdo->prepare("SELECT i.*, p.name as project_type_name, c.name as c_name FROM invoices i LEFT JOIN project_types p ON i.project_type_id = p.id LEFT JOIN clients c ON i.client_id = c.id WHERE i.id = ?");
            $stmt->execute([$job_id]);
            $job = $stmt->fetch(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {
         $message = "Error: " . $e->getMessage();
         $messageType = "danger";
    }
}

// Fetch Job
$stmt = $pdo->prepare("SELECT i.*, p.name as project_type_name, c.name as c_name FROM invoices i LEFT JOIN project_types p ON i.project_type_id = p.id LEFT JOIN clients c ON i.client_id = c.id WHERE i.id = ?");
$stmt->execute([$job_id]);
$job = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$job) {
    die("Job not found.");
}

// Fetch Line Items
$line_items = $pdo->query("SELECT * FROM job_order_line_items WHERE job_order_id = $job_id ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch Transactions
$transactions = $pdo->query("SELECT * FROM transactions WHERE invoice_id = $job_id ORDER BY transaction_date DESC, id DESC")->fetchAll(PDO::FETCH_ASSOC);

// Calculate P&L
$j_in = 0;
$j_out = 0;
foreach($transactions as $t) {
    if ($t['type'] == 'inflow') $j_in += $t['amount'];
    else $j_out += $t['amount'];
}
$j_pl = $j_in - $j_out;

include '../includes/header.php';
?>

<div class="flex-between mb-1">
    <h2><i class="fas fa-file-invoice text-primary"></i> Job Details: <?= htmlspecialchars($job['invoice_id']) ?></h2>
    <div>
        <button onclick="printPartPayment()" class="btn btn-outline"><i class="fas fa-file-invoice-dollar"></i> Print Invoice (Part)</button>
        <a href="print_invoice.php?id=<?= $job['id'] ?>&type=invoice" target="_blank" class="btn btn-outline"><i class="fas fa-print"></i> Print Invoice</a>
        <a href="print_invoice.php?id=<?= $job['id'] ?>&type=receipt" target="_blank" class="btn btn-outline"><i class="fas fa-receipt"></i> Print Receipt</a>
        <a href="job_orders.php" class="btn btn-primary">&larr; Back</a>
    </div>
</div>

<?php if ($message): ?>
    <div class="card" style="border-left: 4px solid var(--accent-<?= $messageType ?>); background-color: rgba(0,0,0,0.2);">
        <p class="text-<?= $messageType ?>" style="margin: 0; font-weight: 500;">
            <?= htmlspecialchars($message) ?>
        </p>
    </div>
<?php endif; ?>

<div style="display: grid; grid-template-columns: 2fr 1fr; gap: 1.5rem;">
    
    <!-- Info & Operations Column -->
    <div>
        <div class="card" style="margin-bottom: 1.5rem;">
            <h4>Job Information</h4>
            <hr style="border-color: var(--border-color); margin: 0.5rem 0 1rem 0;">
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                <div>
                    <span class="text-muted d-block" style="font-size: 0.8rem;">Client / Customer</span>
                    <strong><?= htmlspecialchars($job['c_name'] ?? $job['client_name']) ?></strong>
                </div>
                <div>
                    <span class="text-muted d-block" style="font-size: 0.8rem;">Date</span>
                    <strong><?= date('d M, Y', strtotime($job['date'])) ?></strong>
                </div>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                <div>
                    <span class="text-muted d-block" style="font-size: 0.8rem;">Project Category</span>
                    <strong><?= htmlspecialchars($job['project_type_name'] ?? 'General') ?></strong>
                </div>
                <div>
                    <span class="text-muted d-block" style="font-size: 0.8rem;">Total Value (inc VAT)</span>
                    <strong class="currency-naira" style="color: var(--accent-success); font-size: 1.2rem;"><?= number_format($job['total_with_vat'], 2) ?></strong>
                </div>
            </div>

            <div>
                 <span class="text-muted d-block" style="font-size: 0.8rem;">Service Description</span>
                 <p style="background: rgba(0,0,0,0.2); padding: 1rem; border-radius: 8px; margin-top: 5px;"><?= nl2br(htmlspecialchars($job['service_description'])) ?></p>
            </div>
        </div>

        <div class="card" style="margin-bottom: 1.5rem;">
            <div class="flex-between">
                <h4><i class="fas fa-list text-primary"></i> Invoice Line Items</h4>
                <button class="btn btn-outline" style="font-size:0.75rem; padding: 0.2rem 0.5rem;" onclick="document.getElementById('editItemsModal').style.display='block'"><i class="fas fa-edit"></i> Edit Items</button>
            </div>
            <hr style="border-color: var(--border-color); margin: 0.5rem 0 1rem 0;">
            <div class="table-responsive">
                <table class="table" style="font-size: 0.85rem;">
                    <thead><tr><th>Description</th><th>Qty</th><th>Unit Price</th><th>Total</th></tr></thead>
                    <tbody>
                        <?php if(empty($line_items)): ?>
                            <tr><td colspan="4" class="text-center text-muted">No line items encoded. Flat value used.</td></tr>
                        <?php else: ?>
                            <?php foreach($line_items as $li): ?>
                                <tr>
                                    <td><?= htmlspecialchars($li['description']) ?></td>
                                    <td><?= number_format($li['quantity'], 2) ?></td>
                                    <td><?= number_format($li['unit_price'], 2) ?></td>
                                    <td style="font-weight:600;"><?= number_format($li['total'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="flex-between mb-1">
                <h4><i class="fas fa-wallet text-primary"></i> Associated Transactions Log</h4>
                <a href="transactions.php?filter_job=<?= $job['id'] ?>" class="btn btn-outline" style="font-size:0.75rem; padding: 0.2rem 0.5rem;"><i class="fas fa-plus"></i> Record New</a>
            </div>
            <hr style="border-color: var(--border-color); margin: 0.5rem 0 1rem 0;">
            <div class="table-responsive">
                <table class="table" style="font-size: 0.8rem;">
                    <thead><tr><th>Date</th><th>Type</th><th>Desc</th><th>Amount</th><th>Category</th></tr></thead>
                    <tbody>
                        <?php if(empty($transactions)): ?>
                            <tr><td colspan="5" class="text-center text-muted">No transactions linked to this Job yet.</td></tr>
                        <?php else: ?>
                            <?php foreach($transactions as $txn): ?>
                                <tr>
                                    <td><?= date('d M, y', strtotime($txn['transaction_date'])) ?></td>
                                    <td>
                                        <?php if($txn['type'] == 'inflow'): ?>
                                            <span style="color:var(--accent-success);"><i class="fas fa-arrow-down"></i> IN</span>
                                        <?php else: ?>
                                            <span style="color:var(--accent-danger);"><i class="fas fa-arrow-up"></i> OUT</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($txn['description']) ?></td>
                                    <td class="currency-naira" style="font-weight:600;"><?= number_format($txn['amount'], 2) ?></td>
                                    <td><span style="background:var(--bg-main); padding: 2px 4px; border-radius:3px; font-size:0.7rem;"><?= htmlspecialchars($txn['report_category']) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- State Column -->
    <div>
        <!-- P&L Summary -->
        <div class="card" style="margin-bottom: 1.5rem;">
            <h4><i class="fas fa-chart-line text-primary"></i> Job P&L Summary</h4>
            <hr style="border-color: var(--border-color); margin: 0.5rem 0 1rem 0;">
            
            <div style="display:flex; justify-content:space-between; margin-bottom:0.5rem;">
                <span class="text-muted">Total Inflows (Paid):</span>
                <span class="currency-naira text-success" style="font-weight:600;"><?= number_format($j_in, 2) ?></span>
            </div>
            <div style="display:flex; justify-content:space-between; margin-bottom:0.5rem;">
                <span class="text-muted">Total Expenses:</span>
                <span class="currency-naira text-danger" style="font-weight:600;"><?= number_format($j_out, 2) ?></span>
            </div>
            <hr style="border-color: var(--border-color); opacity: 0.5;">
            <div style="display:flex; justify-content:space-between; margin-top:0.5rem; font-size:1.1rem;">
                <strong class="text-muted">Net Profit:</strong>
                <strong class="currency-naira <?= $j_pl >= 0 ? 'text-success' : 'text-danger' ?>"><?= number_format($j_pl, 2) ?></strong>
            </div>
        </div>

        <div class="card" style="margin-bottom: 1.5rem;">
            <h4><i class="fas fa-tasks text-warning"></i> Operating Status</h4>
            <hr style="border-color: var(--border-color); margin: 0.5rem 0 1rem 0;">
            
            <form method="POST">
                <input type="hidden" name="action" value="update_status">
                <div class="form-group">
                    <select name="status" class="form-control" required>
                        <option value="open" <?= strtolower($job['status']) == 'open' ? 'selected' : '' ?>>Open / Pending</option>
                        <option value="in_progress" <?= strtolower($job['status']) == 'in_progress' ? 'selected' : '' ?>>In Progress</option>
                        <option value="completed" <?= strtolower($job['status']) == 'completed' ? 'selected' : '' ?>>Completed</option>
                        <option value="cancelled" <?= strtolower($job['status']) == 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-warning" style="width: 100%;">Update Status</button>
            </form>
        </div>
        
        <div class="card">
            <h4><i class="fas fa-money-check-alt text-success"></i> Payment Status</h4>
            <hr style="border-color: var(--border-color); margin: 0.5rem 0 1rem 0;">
            
            <form method="POST">
                <input type="hidden" name="action" value="update_payment">
                <div class="form-group">
                    <select name="payment_status" class="form-control" required>
                        <option value="unpaid" <?= strtolower($job['payment_status']) == 'unpaid' ? 'selected' : '' ?>>Not Paid</option>
                        <option value="partly_paid" <?= strtolower($job['payment_status']) == 'partly_paid' ? 'selected' : '' ?>>Partially Paid</option>
                        <option value="fully_paid" <?= strtolower($job['payment_status']) == 'fully_paid' ? 'selected' : '' ?>>Fully Paid</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-success" style="width: 100%;">Update Payment</button>
            </form>
            
            <p class="text-muted" style="margin-top: 1rem; font-size: 0.75rem;">
                <i class="fas fa-info-circle"></i> Once a job is marked both "Completed" and "Fully Paid", the system will calculate profit and auto-generate a pending Tithe.
            </p>
        </div>

        <div class="card" style="margin-top: 1.5rem;">
            <h4><i class="fas fa-percent text-info"></i> VAT Override</h4>
            <hr style="border-color: var(--border-color); margin: 0.5rem 0 1rem 0;">
            <form method="POST">
                <input type="hidden" name="action" value="update_vat">
                <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                    <input type="checkbox" name="apply_vat" value="1" <?= $job['apply_vat'] ? 'checked' : '' ?> onchange="this.form.submit()">
                    <span>Charge VAT for this Job</span>
                </label>
                <p class="text-muted" style="font-size: 0.7rem; margin-top: 0.5rem;">Toggle this to selectively apply or remove VAT on the printed invoice.</p>
            </form>
        </div>
    </div>
</div>

<!-- Edit Items Modal -->
<div id="editItemsModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.7); z-index:9999; overflow-y:auto; padding: 2rem;">
    <div class="card" style="max-width: 800px; margin: 2rem auto; position: relative;">
        <span onclick="document.getElementById('editItemsModal').style.display='none'" style="position:absolute; right:1rem; top:1rem; cursor:pointer; font-size:1.5rem;">&times;</span>
        <h4>Edit Invoice Line Items</h4>
        <hr style="border-color: var(--border-color); margin: 1rem 0;">
        <form method="POST">
            <input type="hidden" name="action" value="update_line_items">
            
            <div id="lineItemsContainer">
                <?php foreach($line_items as $li): ?>
                    <div class="line-item-row" style="display:grid; grid-template-columns: 3fr 1fr 1fr 30px; gap: 0.5rem; margin-bottom: 0.5rem;">
                        <input type="text" name="item_desc[]" class="form-control" value="<?= htmlspecialchars($li['description']) ?>" required>
                        <input type="number" step="0.01" name="item_qty[]" class="form-control" value="<?= $li['quantity'] ?>" required>
                        <input type="number" step="0.01" name="item_price[]" class="form-control" value="<?= $li['unit_price'] ?>" required>
                        <button type="button" class="btn btn-outline" style="padding: 0; color:var(--accent-danger);" onclick="this.parentElement.remove()"><i class="fas fa-trash"></i></button>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <button type="button" class="btn btn-outline" style="margin-top: 0.5rem;" onclick="add_line_item()"><i class="fas fa-plus"></i> Add Another Item</button>

            <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 1.5rem;"><i class="fas fa-check"></i> Save Changes</button>
        </form>
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

function printPartPayment() {
    let percent = prompt("Enter the Percentage for this Part Payment (e.g. 60 for 60%):", "60");
    if (percent !== null && percent !== "") {
        window.open(`print_invoice.php?id=<?= $job['id'] ?>&type=invoice&percent_advanced=${percent}`, '_blank');
    }
}
</script>

<?php include '../includes/footer.php'; ?>
