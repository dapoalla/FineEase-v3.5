<?php
// pages/transactions.php
require_once '../config/config.php';
require_once '../includes/functions.php';
require_once '../includes/financial_engine.php';

require_login();

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    if ($action == 'add_transaction') {
        $type = $_POST['type']; // 'inflow' or 'outflow'
        $amount = floatval($_POST['amount']);
        $description = sanitize_input($_POST['description']);
        $bank_account_id = intval($_POST['bank_account_id']);
        $transaction_date = sanitize_input($_POST['transaction_date']);
        $category = $_POST['category']; // 'internal' or 'invoice_linked'
        $invoice_id = !empty($_POST['invoice_id']) ? intval($_POST['invoice_id']) : NULL;
        
        $wht_amount = floatval($_POST['wht_amount'] ?? 0);
        $paye_amount = floatval($_POST['paye_amount'] ?? 0);
        $report_category = sanitize_input($_POST['report_category'] ?? 'Operating Expense');
        $is_non_current = isset($_POST['is_non_current']) ? 1 : 0;
        $created_by = $_SESSION['user_id'];

        // Line Items check - filter out empty rows
        $item_descs = array_filter($_POST['item_desc'] ?? [], function($val) { return !empty(trim($val)); });
        $has_line_items = count($item_descs) > 0 ? 1 : 0;
        if ($has_line_items) {
            $amount = 0;
            foreach($_POST['item_price'] as $k => $p) {
                $amount += (floatval($p) * floatval($_POST['item_qty'][$k]));
            }
        }

        $receipt_path = null;
        if (!empty($_FILES['receipt_file']['name'])) {
            $targetDir = '../uploads/receipts/';
            if (!is_dir($targetDir)) mkdir($targetDir, 0755, true);
            $ext = pathinfo($_FILES['receipt_file']['name'], PATHINFO_EXTENSION);
            $fileName = uniqid('txn_') . '.' . $ext;
            $targetFile = $targetDir . $fileName;
            if (move_uploaded_file($_FILES['receipt_file']['tmp_name'], $targetFile)) {
                $receipt_path = 'uploads/receipts/' . $fileName;
            }
        }

        try {
            $pdo->beginTransaction();

            $sql = "INSERT INTO transactions 
                    (type, amount, description, bank_account_id, transaction_date, category, invoice_id, wht_amount, paye_amount, report_category, is_non_current, created_by, has_line_items, line_items_total, receipt_path) 
                    VALUES (:tp, :amt, :desc, :ba, :dt, :cat, :inv, :wht, :paye, :rcat, :isnc, :cby, :hli, :lit, :rp)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':tp' => $type, ':amt' => $amount, ':desc' => $description, ':ba' => $bank_account_id,
                ':dt' => $transaction_date, ':cat' => $category, ':inv' => $invoice_id,
                ':wht' => $wht_amount, ':paye' => $paye_amount, ':rcat' => $report_category,
                ':isnc' => $is_non_current, ':cby' => $created_by, ':hli' => $has_line_items, ':lit' => $amount, ':rp' => $receipt_path
            ]);
            $txn_id = $pdo->lastInsertId();

            if ($has_line_items) {
                $li_stmt = $pdo->prepare("INSERT INTO transaction_line_items (transaction_id, description, quantity, unit_price, total) VALUES (?, ?, ?, ?, ?)");
                foreach($_POST['item_desc'] as $k => $d) {
                    if (empty($d)) continue;
                    $q = floatval($_POST['item_qty'][$k]);
                    $p = floatval($_POST['item_price'][$k]);
                    $li_stmt->execute([$txn_id, sanitize_input($d), $q, $p, ($q * $p)]);
                }
            }

            // Update Bank Balance
            $balSql = "UPDATE bank_accounts SET balance = balance " . ($type == 'inflow' ? '+' : '-') . " :amt WHERE id = :bid";
            $pdo->prepare($balSql)->execute([':amt' => $amount, ':bid' => $bank_account_id]);

            $pdo->commit();
            $message = ucfirst($type) . " recorded successfully.";
            $messageType = "success";
            if ($invoice_id) evaluate_tithe_trigger($pdo, $invoice_id);

        } catch (PDOException $e) {
            $pdo->rollBack();
            $message = "Error: " . $e->getMessage();
            $messageType = "danger";
        }
    } elseif ($action == 'edit_transaction') {
        $txn_id = intval($_POST['txn_id']);
        $type = $_POST['type'];
        $bank_account_id = intval($_POST['bank_account_id']);
        $transaction_date = sanitize_input($_POST['transaction_date']);
        $description = sanitize_input($_POST['description']);
        $category = $_POST['category'];
        $invoice_id = !empty($_POST['invoice_id']) ? intval($_POST['invoice_id']) : NULL;
        $report_category = $_POST['report_category'];
        $is_non_current = isset($_POST['is_non_current']) ? 1 : 0;
        $wht_amount = floatval($_POST['wht_amount']);
        $paye_amount = floatval($_POST['paye_amount']);

        // Line Items calculation - filter out empty rows
        $item_descs = array_filter($_POST['item_desc'] ?? [], function($val) { return !empty(trim($val)); });
        $has_line_items = count($item_descs) > 0 ? 1 : 0;
        $new_amount = $has_line_items ? 0 : floatval($_POST['amount']);
        if ($has_line_items) {
            foreach($_POST['item_price'] as $k => $p) {
                $new_amount += (floatval($p) * floatval($_POST['item_qty'][$k]));
            }
        }

        $receipt_path = null;
        if (!empty($_FILES['receipt_file']['name'])) {
            $targetDir = '../uploads/receipts/';
            if (!is_dir($targetDir)) mkdir($targetDir, 0755, true);
            $ext = pathinfo($_FILES['receipt_file']['name'], PATHINFO_EXTENSION);
            $fileName = uniqid('txn_') . '.' . $ext;
            $targetFile = $targetDir . $fileName;
            if (move_uploaded_file($_FILES['receipt_file']['tmp_name'], $targetFile)) {
                $receipt_path = 'uploads/receipts/' . $fileName;
            }
        }

        try {
            $pdo->beginTransaction();
            
            // 1. Get original for reversal
            $old = $pdo->prepare("SELECT amount, type, bank_account_id, receipt_path FROM transactions WHERE id = ?");
            $old->execute([$txn_id]);
            $old_txn = $old->fetch(PDO::FETCH_ASSOC);

            if ($receipt_path && $old_txn['receipt_path'] && file_exists('../' . $old_txn['receipt_path'])) {
                unlink('../' . $old_txn['receipt_path']);
            }
            if (!$receipt_path) {
                $receipt_path = $old_txn['receipt_path'];
            }

            // 2. Reverse old balance
            $revOp = $old_txn['type'] == 'inflow' ? '-' : '+';
            $pdo->prepare("UPDATE bank_accounts SET balance = balance $revOp :amt WHERE id = :bid")
                ->execute([':amt' => $old_txn['amount'], ':bid' => $old_txn['bank_account_id']]);

            // 3. Update Transaction Record
            $sql = "UPDATE transactions SET 
                    type = ?, amount = ?, description = ?, bank_account_id = ?, transaction_date = ?, category = ?, 
                    invoice_id = ?, wht_amount = ?, paye_amount = ?, report_category = ?, is_non_current = ?, 
                    has_line_items = ?, line_items_total = ?, receipt_path = ? WHERE id = ?";
            $pdo->prepare($sql)->execute([
                $type, $new_amount, $description, $bank_account_id, $transaction_date, $category, 
                $invoice_id, $wht_amount, $paye_amount, $report_category, $is_non_current, 
                $has_line_items, $new_amount, $receipt_path, $txn_id
            ]);

            // 4. Update Line Items (Delete and Re-insert)
            $pdo->prepare("DELETE FROM transaction_line_items WHERE transaction_id = ?")->execute([$txn_id]);
            if ($has_line_items) {
                $li_stmt = $pdo->prepare("INSERT INTO transaction_line_items (transaction_id, description, quantity, unit_price, total) VALUES (?, ?, ?, ?, ?)");
                foreach($_POST['item_desc'] as $k => $d) {
                    if (empty($d)) continue;
                    $q = floatval($_POST['item_qty'][$k]);
                    $p = floatval($_POST['item_price'][$k]);
                    $li_stmt->execute([$txn_id, sanitize_input($d), $q, $p, ($q * $p)]);
                }
            }

            // 5. Apply new balance
            $applyOp = $type == 'inflow' ? '+' : '-';
            $pdo->prepare("UPDATE bank_accounts SET balance = balance $applyOp :amt WHERE id = :bid")
                ->execute([':amt' => $new_amount, ':bid' => $bank_account_id]);

            $pdo->commit();
            $message = "Transaction updated successfully.";
            $messageType = "success";
        } catch (PDOException $e) {
            $pdo->rollBack();
            $message = "Error: " . $e->getMessage();
            $messageType = "danger";
        }
    } elseif ($action == 'delete_transaction') {
        $del_id = intval($_POST['txn_id']);
        try {
            // Fetch the transaction first so we can reverse the bank balance
            $t = $pdo->prepare("SELECT type, amount, bank_account_id, receipt_path FROM transactions WHERE id = ?");
            $t->execute([$del_id]);
            $txn_row = $t->fetch(PDO::FETCH_ASSOC);

            if ($txn_row) {
                $pdo->beginTransaction();
                // Reverse the balance effect
                $reverseOp = $txn_row['type'] == 'inflow' ? '-' : '+';
                $balStmt = $pdo->prepare("UPDATE bank_accounts SET balance = balance $reverseOp :amt WHERE id = :bid");
                $balStmt->execute([':amt' => $txn_row['amount'], ':bid' => $txn_row['bank_account_id']]);
                if ($txn_row['receipt_path'] && file_exists('../' . $txn_row['receipt_path'])) {
                    unlink('../' . $txn_row['receipt_path']);
                }
                // Delete the record
                $pdo->prepare("DELETE FROM transactions WHERE id = ?")->execute([$del_id]);
                $pdo->commit();
                $message = "Transaction deleted and bank balance reversed.";
                $messageType = "success";
            } else {
                $message = "Transaction not found.";
                $messageType = "danger";
            }
        } catch (PDOException $e) {
            $pdo->rollBack();
            $message = "Error deleting transaction: " . $e->getMessage();
            $messageType = "danger";
        }
    }
}

// Fetch lists
$banks = $pdo->query("SELECT id, name, balance FROM bank_accounts WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
$invoices = $pdo->query("SELECT id, invoice_id, client_name FROM invoices ORDER BY id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);

// Fetch recent transactions
$transactions = $pdo->query("
    SELECT t.*, b.name as bank_name, i.invoice_id as job_ref, u.username as recorder
    FROM transactions t
    LEFT JOIN bank_accounts b ON t.bank_account_id = b.id
    LEFT JOIN invoices i ON t.invoice_id = i.id
    LEFT JOIN users u ON t.created_by = u.id
    ORDER BY t.transaction_date DESC, t.id DESC LIMIT 50
")->fetchAll(PDO::FETCH_ASSOC);

// Fetch line items for these transactions
$all_tx_ids = array_column($transactions, 'id');
$tx_line_items = [];
if (!empty($all_tx_ids)) {
    $placeholders = implode(',', array_fill(0, count($all_tx_ids), '?'));
    $li_stmt = $pdo->prepare("SELECT * FROM transaction_line_items WHERE transaction_id IN ($placeholders) ORDER BY id ASC");
    $li_stmt->execute($all_tx_ids);
    while ($row = $li_stmt->fetch(PDO::FETCH_ASSOC)) {
        $tx_line_items[$row['transaction_id']][] = $row;
    }
}

include '../includes/header.php';
?>

<div class="flex-between mb-1">
    <h2><i class="fas fa-wallet text-primary"></i> Transactions Ledger</h2>
    <button class="btn btn-success" onclick="document.getElementById('txnModal').style.display='block'">
        <i class="fas fa-plus"></i> Record Transaction
    </button>
</div>

<?php if ($message): ?>
    <div class="card" style="border-left: 4px solid var(--accent-<?= $messageType ?>); background-color: rgba(0,0,0,0.2);">
        <p class="text-<?= $messageType ?>" style="margin: 0; font-weight: 500;">
            <?= htmlspecialchars($message ?? '') ?>
        </p>
    </div>
<?php endif; ?>

<!-- Transaction Modal -->
<div id="txnModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.7); z-index:9999; overflow-y:auto; padding: 2rem;">
    <div class="card" style="max-width: 600px; margin: 2rem auto; position: relative;">
        <span onclick="document.getElementById('txnModal').style.display='none'" style="position:absolute; right:1rem; top:1rem; cursor:pointer; font-size:1.5rem;">&times;</span>
        <h4>Record Digital Ledger Entry</h4>
        <hr style="border-color: var(--border-color); margin: 1rem 0;">
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="add_transaction">
            
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:1rem;">
                <div class="form-group">
                    <label class="form-label">Type</label>
                    <select name="type" class="form-control" required>
                        <option value="inflow">Inflow (Income/Deposit)</option>
                        <option value="outflow">Outflow (Expense/Payment)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Date</label>
                    <input type="date" name="transaction_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                </div>
            </div>

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:1rem;">
                <div class="form-group">
                    <label class="form-label">Amount Base (₦)</label>
                    <input type="number" step="0.01" name="amount" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Source/Destination Account</label>
                    <select name="bank_account_id" class="form-control" required>
                        <?php foreach ($banks as $b): ?>
                            <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['name'] ?? '') ?> (<?= number_format($b['balance'], 2) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Description / Narration</label>
                <input type="text" name="description" id="add_description" class="form-control" required>
            </div>

            <!-- Transaction Line Items (Optional) -->
            <div style="margin: 1rem 0; padding: 1rem; background: rgba(59,130,246,0.05); border: 1px dashed var(--accent-primary); border-radius: 8px;">
                <div class="flex-between mb-1">
                    <h6 style="margin:0; color:var(--accent-primary);">Breakdown / Line Items (Optional)</h6>
                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="addTxRow('addItemsList')"><i class="fas fa-plus"></i> Add Item</button>
                </div>
                <p style="font-size: 0.75rem; color: var(--text-muted); margin-bottom: 1rem;">If items are added, the "Amount Base" above will be ignored and the sum of items will be used.</p>
                <div id="addItemsList">
                    <!-- Dynamic rows go here -->
                </div>
            </div>

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:1rem; background: rgba(0,0,0,0.2); padding: 1rem; border-radius: 8px; margin-bottom: 1rem;">
                <div class="form-group">
                    <label class="form-label">Link to Job Order?</label>
                    <select name="category" class="form-control" onchange="document.getElementById('jobSelector').style.display = this.value == 'invoice_linked' ? 'block' : 'none';" required>
                        <option value="internal">No - Internal / Operational</option>
                        <option value="invoice_linked">Yes - Job Order Linked</option>
                    </select>
                </div>
                <div class="form-group" id="jobSelector" style="display:none;">
                    <label class="form-label">Select Job Order</label>
                    <select name="invoice_id" class="form-control">
                        <option value="">-- Choose Job --</option>
                        <?php foreach ($invoices as $i): ?>
                            <option value="<?= $i['id'] ?>"><?= htmlspecialchars($i['invoice_id'] ?? '') ?> (<?= htmlspecialchars($i['client_name'] ?? '') ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <h5 class="text-primary mt-1"><i class="fas fa-file-invoice-dollar"></i> IFRS & Statutory Taxes</h5>
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:1rem;">
                <div class="form-group">
                    <label class="form-label">IFRS Category</label>
                    <select name="report_category" class="form-control">
                        <option value="Revenue">Revenue</option>
                        <option value="Operating Expense" selected>Operating Expense</option>
                        <option value="Other Income">Other Income</option>
                        <option value="Asset">Asset Purchase</option>
                        <option value="Liability">Liability / Loan</option>
                        <option value="Equity">Equity</option>
                    </select>
                </div>
                <div class="form-group" style="padding-top: 2rem;">
                    <label style="cursor: pointer; display: flex; align-items: center; gap: 0.5rem;">
                        <input type="checkbox" name="is_non_current" value="1"> Is Non-Current? (Long Term)
                    </label>
                </div>
            </div>
            
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:1rem;">
                <div class="form-group">
                    <label class="form-label">Withholding Tax Deducted (₦)</label>
                    <input type="number" step="0.01" name="wht_amount" class="form-control" value="0.00">
                </div>
                <div class="form-group">
                    <label class="form-label">PAYE (For Payroll Outflows)</label>
                    <input type="number" step="0.01" name="paye_amount" class="form-control" value="0.00">
                </div>
            </div>

            <div class="form-group mt-2">
                <label class="form-label">Upload Receipt (PDF/Image)</label>
                <input type="file" name="receipt_file" accept=".pdf,image/*" class="form-control-file">
            </div>

            <button type="submit" class="btn btn-success" style="width: 100%; margin-top: 1rem;"><i class="fas fa-save"></i> Save Transaction</button>
        </form>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th class="sortable" data-col="0" style="cursor:pointer;">Date <span class="sort-arrow"> ↓</span></th>
                    <th class="sortable" data-col="1" style="cursor:pointer;">Type <span class="sort-arrow"></span></th>
                    <th>Description</th>
                    <th class="sortable" data-col="3" style="cursor:pointer;">Account <span class="sort-arrow"></span></th>
                    <th>Job Ref</th>
                    <th class="sortable text-right" data-col="5" style="cursor:pointer;">Amount <span class="sort-arrow"></span></th>
                    <th>Recorder</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($transactions)): ?>
                    <tr><td colspan="8" class="text-center text-muted">No transactions recorded.</td></tr>
                <?php else: ?>
                    <?php foreach ($transactions as $txn): ?>
                        <tr data-value="<?= $txn['amount'] ?>" data-type="<?= $txn['type'] ?>">
                            <td data-sort="<?= strtotime($txn['transaction_date']) ?>"><?= date('d M, y', strtotime($txn['transaction_date'])) ?></td>
                            <td>
                                <?php if ($txn['type'] == 'inflow'): ?>
                                    <span class="text-success"><i class="fas fa-arrow-down"></i> IN</span>
                                <?php else: ?>
                                    <span class="text-danger"><i class="fas fa-arrow-up"></i> OUT</span>
                                <?php endif; ?>
                            </td>
                            <td style="max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                <?= htmlspecialchars($txn['description'] ?? '') ?>
                                <?php if(isset($txn['wht_amount']) && $txn['wht_amount'] > 0): ?>
                                    <span style="font-size: 0.7rem; background: rgba(255,193,7,0.2); padding: 2px 4px; border-radius: 3px; color: var(--accent-warning);">WHT: <?= number_format($txn['wht_amount']) ?></span>
                                <?php endif; ?>
                                <?php if(!empty($txn['receipt_path'])): ?>
                                    <a href="../<?= htmlspecialchars($txn['receipt_path'] ?? '') ?>" target="_blank" title="View Receipt" style="margin-left: 5px; color: var(--accent-primary);"><i class="fas fa-paperclip"></i></a>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($txn['bank_name'] ?? '') ?></td>
                            <td>
                                <?php if ($txn['job_ref']): ?>
                                    <span style="font-size: 0.8rem; border: 1px solid var(--border-color); padding: 2px 5px; border-radius: 4px;"><?= htmlspecialchars($txn['job_ref'] ?? '') ?></span>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="currency-naira text-right" style="font-weight: 600; color: <?= $txn['type'] == 'inflow' ? 'var(--accent-success)' : 'var(--accent-danger)' ?>;" data-sort="<?= $txn['amount'] ?>">
                                <?= number_format($txn['amount'], 2) ?>
                            </td>
                            <td class="text-muted" style="font-size: 0.8rem;"><?= htmlspecialchars($txn['recorder'] ?? 'System') ?></td>
                            <td class="text-right">
                                <?php if ($txn['has_line_items']): ?>
                                    <span class="badge" style="font-size: 0.65rem; padding: 2px 4px; background: rgba(59,130,246,0.1); color: var(--accent-primary); border: 1px solid var(--accent-primary); border-radius: 3px; margin-right: 5px;">ITEMS</span>
                                <?php endif; ?>
                                <button class="btn btn-outline-primary" style="padding: 0.2rem 0.4rem; font-size: 0.75rem; border: 1px solid var(--accent-primary); background: transparent; color: var(--accent-primary); cursor: pointer;" 
                                        onclick="editTransaction(<?= htmlspecialchars(json_encode($txn), ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars(json_encode($tx_line_items[$txn['id']] ?? []), ENT_QUOTES, 'UTF-8') ?>)">
                                    <i class="fas fa-edit"></i> Edit
                                </button>
                                <form method="POST" onsubmit="return confirm('Delete this transaction? The bank balance will be reversed.');" style="display:inline;">
                                    <input type="hidden" name="action" value="delete_transaction">
                                    <input type="hidden" name="txn_id" value="<?= $txn['id'] ?>">
                                    <button class="btn btn-outline-danger" style="padding: 0.2rem 0.4rem; font-size: 0.75rem; border: 1px solid var(--accent-danger); background: transparent; color: var(--accent-danger); cursor: pointer;"><i class="fas fa-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                <tfoot style="font-weight:700; background: rgba(59,130,246,0.07);">
                    <tr>
                        <td colspan="4">Summary <span id="txnCount" style="font-size:0.8rem; font-weight:400; color:var(--text-muted);"></span></td>
                        <td></td>
                        <td class="text-right" id="txnSummary" style="font-size:0.8rem; line-height:1.6;"></td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
        </table>
    </div>
</div>

<?php include '../includes/footer.php'; ?>

<!-- Edit Transaction Modal -->
<div id="editTxnModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.7); z-index:9999; overflow-y:auto; padding: 2rem;">
    <div class="card" style="max-width: 600px; margin: 2rem auto; position: relative;">
        <span onclick="document.getElementById('editTxnModal').style.display='none'" style="position:absolute; right:1rem; top:1rem; cursor:pointer; font-size:1.5rem;">&times;</span>
        <h4>Edit Transaction / Ledger Entry</h4>
        <hr style="border-color: var(--border-color); margin: 1rem 0;">
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="edit_transaction">
            <input type="hidden" name="txn_id" id="edit_txn_id">
            
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:1rem;">
                <div class="form-group">
                    <label class="form-label">Type</label>
                    <select name="type" id="edit_type" class="form-control" required>
                        <option value="inflow">Inflow (Income/Deposit)</option>
                        <option value="outflow">Outflow (Expense/Payment)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Date</label>
                    <input type="date" name="transaction_date" id="edit_date" class="form-control" required>
                </div>
            </div>

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:1rem;">
                <div class="form-group">
                    <label class="form-label">Amount Base (₦)</label>
                    <input type="number" step="0.01" name="amount" id="edit_amount" class="form-control">
                </div>
                <div class="form-group">
                    <label class="form-label">Account</label>
                    <select name="bank_account_id" id="edit_bank" class="form-control" required>
                        <?php foreach ($banks as $b): ?>
                            <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['name'] ?? '') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Description / Narration</label>
                <input type="text" name="description" id="edit_description" class="form-control" required>
            </div>

            <!-- Edit Line Items -->
            <div style="margin: 1rem 0; padding: 1rem; background: rgba(59,130,246,0.05); border: 1px dashed var(--accent-primary); border-radius: 8px;">
                <div class="flex-between mb-1">
                    <h6 style="margin:0; color:var(--accent-primary);">Breakdown / Items</h6>
                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="addTxRow('editItemsList')"><i class="fas fa-plus"></i> Add Item</button>
                </div>
                <div id="editItemsList"></div>
            </div>

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:1rem; background: rgba(0,0,0,0.2); padding: 1rem; border-radius: 8px; margin-bottom: 1rem;">
                <div class="form-group">
                    <label class="form-label">Link to Job?</label>
                    <select name="category" id="edit_category" class="form-control" onchange="document.getElementById('editJobSelector').style.display = this.value == 'invoice_linked' ? 'block' : 'none';">
                        <option value="internal">No</option>
                        <option value="invoice_linked">Yes</option>
                    </select>
                </div>
                <div class="form-group" id="editJobSelector" style="display:none;">
                    <label class="form-label">Select Job</label>
                    <select name="invoice_id" id="edit_invoice_id" class="form-control">
                        <option value="">-- Choose --</option>
                        <?php foreach ($invoices as $i): ?>
                            <option value="<?= $i['id'] ?>"><?= htmlspecialchars($i['invoice_id'] ?? '') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:1rem;">
                <div class="form-group">
                    <label class="form-label">IFRS Category</label>
                    <select name="report_category" id="edit_report_cat" class="form-control">
                        <option value="Revenue">Revenue</option>
                        <option value="Operating Expense">Operating Expense</option>
                        <option value="Other Income">Other Income</option>
                        <option value="Asset">Asset Purchase</option>
                        <option value="Liability">Liability</option>
                        <option value="Equity">Equity</option>
                    </select>
                </div>
                <div class="form-group" style="padding-top: 2rem;">
                    <label><input type="checkbox" name="is_non_current" id="edit_is_nc" value="1"> Non-Current?</label>
                </div>
            </div>
            
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:1rem;">
                <div class="form-group">
                    <label class="form-label">WHT (₦)</label>
                    <input type="number" step="0.01" name="wht_amount" id="edit_wht" class="form-control">
                </div>
                <div class="form-group">
                    <label class="form-label">PAYE (₦)</label>
                    <input type="number" step="0.01" name="paye_amount" id="edit_paye" class="form-control">
                </div>
            </div>

            <div class="form-group mt-2">
                <label class="form-label">Update Receipt (PDF/Image)</label>
                <input type="file" name="receipt_file" accept=".pdf,image/*" class="form-control-file">
                <small class="form-text text-muted" id="edit_receipt_link"></small>
            </div>

            <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 1rem;"><i class="fas fa-save"></i> Update Transaction</button>
        </form>
    </div>
</div>

<script>
function addTxRow(containerId, data = null) {
    const container = document.getElementById(containerId);
    const row = document.createElement('div');
    row.style.display = 'grid';
    row.style.gridTemplateColumns = '2fr 1fr 1fr 30px';
    row.style.gap = '0.5rem';
    row.style.marginBottom = '0.5rem';
    
    row.innerHTML = `
        <input type="text" name="item_desc[]" class="form-control" placeholder="Item Name" value="${data ? data.description : ''}" required>
        <input type="number" step="0.01" name="item_qty[]" class="form-control" placeholder="Qty" value="${data ? data.quantity : '1'}" required>
        <input type="number" step="0.01" name="item_price[]" class="form-control" placeholder="Price" value="${data ? data.unit_price : ''}" required>
        <button type="button" class="btn btn-sm text-danger" onclick="this.parentElement.remove()">&times;</button>
    `;
    container.appendChild(row);
}

function editTransaction(txn, items) {
    document.getElementById('edit_txn_id').value = txn.id;
    document.getElementById('edit_type').value = txn.type;
    document.getElementById('edit_date').value = txn.transaction_date;
    document.getElementById('edit_amount').value = txn.amount;
    document.getElementById('edit_bank').value = txn.bank_account_id;
    document.getElementById('edit_description').value = txn.description;
    
    document.getElementById('edit_category').value = txn.category;
    document.getElementById('editJobSelector').style.display = txn.category === 'invoice_linked' ? 'block' : 'none';
    document.getElementById('edit_invoice_id').value = txn.invoice_id || '';
    
    document.getElementById('edit_report_cat').value = txn.report_category;
    document.getElementById('edit_is_nc').checked = txn.is_non_current == 1;
    document.getElementById('edit_wht').value = txn.wht_amount;
    document.getElementById('edit_paye').value = txn.paye_amount;
    
    if (txn.receipt_path) {
        document.getElementById('edit_receipt_link').innerHTML = '<a href="../' + txn.receipt_path + '" target="_blank"><i class="fas fa-paperclip"></i> Current Receipt</a> (Upload new to replace)';
    } else {
        document.getElementById('edit_receipt_link').innerHTML = 'No receipt attached.';
    }
    
    // Clear and refill items
    const list = document.getElementById('editItemsList');
    list.innerHTML = '';
    if (items && items.length > 0) {
        items.forEach(item => addTxRow('editItemsList', item));
    }
    
    document.getElementById('editTxnModal').style.display = 'block';
}

document.addEventListener('DOMContentLoaded', function() {
    const table = document.querySelector('.table');
    if (!table) return;
    const tbody = table.querySelector('tbody');
    const ths = table.querySelectorAll('th.sortable');
    let sortCol = 0, sortDir = -1;

    function fmt(n) { return n.toLocaleString('en-NG', {minimumFractionDigits:2, maximumFractionDigits:2}); }

    function updateFooter() {
        const rows = Array.from(tbody.querySelectorAll('tr[data-value]'));
        let inflow = 0, outflow = 0;
        rows.forEach(r => {
            const v = parseFloat(r.dataset.value || 0);
            if (r.dataset.type === 'inflow') inflow += v;
            else outflow += v;
        });
        const net = inflow - outflow;
        const cnt = document.getElementById('txnCount');
        const sumEl = document.getElementById('txnSummary');
        if (cnt) cnt.textContent = '(' + rows.length + ' entries)';
        if (sumEl) sumEl.innerHTML =
            '<span style="color:var(--accent-success)">₦' + fmt(inflow) + ' IN</span> &nbsp;|&nbsp; ' +
            '<span style="color:var(--accent-danger)">₦' + fmt(outflow) + ' OUT</span> &nbsp;|&nbsp; ' +
            '<strong style="color:' + (net >= 0 ? 'var(--accent-success)' : 'var(--accent-danger)') + '">₦' + fmt(Math.abs(net)) + ' NET</strong>';
    }

    function sortTable(colIdx, dir) {
        const rows = Array.from(tbody.querySelectorAll('tr[data-value]'));
        rows.sort((a, b) => {
            const ac = a.cells[colIdx], bc = b.cells[colIdx];
            const av = ac.dataset.sort !== undefined ? parseFloat(ac.dataset.sort) : ac.textContent.trim();
            const bv = bc.dataset.sort !== undefined ? parseFloat(bc.dataset.sort) : bc.textContent.trim();

            if (!isNaN(av) && !isNaN(bv)) return (av - bv) * dir;
            return String(av).localeCompare(String(bv)) * dir;
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
