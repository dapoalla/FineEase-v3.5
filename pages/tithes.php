<?php
// pages/tithes.php
require_once '../config/config.php';
require_once '../includes/functions.php';
require_once '../includes/financial_engine.php';

require_login();

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    
    if ($_POST['action'] == 'pay_tithe') {
        $tithe_id = intval($_POST['tithe_id']);
        
        try {
            $pdo->beginTransaction();
            
            // Get amount before marking paid
            $t_stmt = $pdo->prepare("SELECT amount, invoice_id FROM tithes WHERE id = ?");
            $t_stmt->execute([$tithe_id]);
            $tithe_row = $t_stmt->fetch(PDO::FETCH_ASSOC);
            if (!$tithe_row) {
                throw new Exception("Tithe record not found (it may have been deleted).");
            }
            $amt = $tithe_row['amount'];
            $inv_id = $tithe_row['invoice_id'];

            $stmt = $pdo->prepare("UPDATE tithes SET status = 'paid', date_paid = CURDATE() WHERE id = ?");
            $stmt->execute([$tithe_id]);
            
            // Log as outflow transaction - transaction_date is NOT NULL so must be supplied
            $stmt = $pdo->prepare("INSERT INTO transactions 
                (type, amount, description, transaction_date, category, report_category, invoice_id, created_by) 
                VALUES ('outflow', ?, 'Tithe Remittance', CURDATE(), 'internal', 'Operating Expense', ?, ?)");
            $stmt->execute([$amt, $inv_id, $_SESSION['user_id'] ?? null]);

            $pdo->commit();
            $message = "Tithe marked as paid and logged to ledger.";
            $messageType = "success";

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $message = "Error: " . $e->getMessage();
            $messageType = "danger";
        }

    } elseif ($_POST['action'] == 'scan_tithes') {
        // Manual scan: re-run the trigger for all completed+fully paid jobs
        $jobs = $pdo->query("SELECT id FROM invoices WHERE status = 'completed' AND payment_status = 'fully_paid'")->fetchAll(PDO::FETCH_ASSOC);
        $count = 0;
        foreach ($jobs as $j) {
            $result = evaluate_tithe_trigger($pdo, $j['id']);
            if ($result && isset($result['amount'])) $count++;
        }
        $message = $count > 0 ? "$count new Tithe obligation(s) generated!" : "No new tithes found (already generated or no qualifying jobs).";
        $messageType = $count > 0 ? "success" : "warning";
    }
}

// Fetch all Tithes
$tithes = $pdo->query("
    SELECT t.*, i.invoice_id as job_ref, i.client_name 
    FROM tithes t 
    LEFT JOIN invoices i ON t.invoice_id = i.id 
    ORDER BY t.date_generated DESC, t.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

include '../includes/header.php';
?>

<div class="flex-between mb-1">
    <h2><i class="fas fa-hand-holding-heart text-primary"></i> Automata Tithe Engine</h2>
    <form method="POST" style="display:inline;">
        <input type="hidden" name="action" value="scan_tithes">
        <button class="btn btn-outline"><i class="fas fa-sync-alt"></i> Scan for New Tithes</button>
    </form>
</div>

<?php if ($message): ?>
    <div class="card" style="border-left: 4px solid var(--accent-<?= $messageType ?>); background-color: rgba(0,0,0,0.2);">
        <p class="text-<?= $messageType ?>" style="margin: 0; font-weight: 500;">
            <?= htmlspecialchars($message) ?>
        </p>
    </div>
<?php endif; ?>

<div class="card mb-2">
    <p class="text-muted"><i class="fas fa-info-circle"></i> Tithes are automatically generated when a Job Order is marked as <strong>Completed</strong> AND <strong>Fully Paid</strong>, calculating net profit from the Job's tracked transactions and applying the global Tithe percentage against it.</p>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th class="sortable" data-col="0" style="cursor:pointer;">Date Generated <span class="sort-arrow"> ↓</span></th>
                    <th class="sortable" data-col="1" style="cursor:pointer;">Job Ref <span class="sort-arrow"></span></th>
                    <th class="sortable" data-col="2" style="cursor:pointer;">Client <span class="sort-arrow"></span></th>
                    <th class="sortable text-right" data-col="3" style="cursor:pointer;">Tithe Amount <span class="sort-arrow"></span></th>
                    <th class="sortable" data-col="4" style="cursor:pointer;">Status <span class="sort-arrow"></span></th>
                    <th class="sortable" data-col="5" style="cursor:pointer;">Date Paid <span class="sort-arrow"></span></th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($tithes)): ?>
                    <tr><td colspan="7" class="text-center text-muted">No tithe obligations recorded yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($tithes as $t): ?>
                        <tr>
                            <td data-sort="<?= strtotime($t['date_generated']) ?>"><?= date('d M, Y', strtotime($t['date_generated'])) ?></td>
                            <td><a href="view_job.php?id=<?= $t['invoice_id'] ?>" style="color:var(--accent-primary); text-decoration:none; font-weight:600;"><?= htmlspecialchars($t['job_ref']) ?></a></td>
                            <td><?= htmlspecialchars($t['client_name']) ?></td>
                            <td class="currency-naira text-right" style="font-weight: 600;" data-sort="<?= $t['amount'] ?>"><?= number_format($t['amount'], 2) ?></td>
                            <td>
                                <?php if ($t['status'] == 'paid'): ?>
                                    <span style="background: var(--accent-success); padding: 0.2rem 0.5rem; border-radius: 4px; font-size: 0.75rem; color: #fff;">PAID</span>
                                <?php else: ?>
                                    <span style="background: var(--accent-warning); padding: 0.2rem 0.5rem; border-radius: 4px; font-size: 0.75rem; color: #fff;">PENDING</span>
                                <?php endif; ?>
                            </td>
                            <td data-sort="<?= $t['date_paid'] ? strtotime($t['date_paid']) : 0 ?>"><?= $t['date_paid'] ? date('d M, Y', strtotime($t['date_paid'])) : '-' ?></td>
                            <td>
                                <?php if ($t['status'] == 'owed'): ?>
                                    <form method="POST" onsubmit="return confirm('Mark this tithe as remitted?');" style="display:inline;">
                                        <input type="hidden" name="action" value="pay_tithe">
                                        <input type="hidden" name="tithe_id" value="<?= $t['id'] ?>">
                                        <button class="btn btn-success" style="padding: 0.3rem 0.6rem; font-size: 0.75rem;"><i class="fas fa-check"></i> Remit</button>
                                    </form>
                                <?php else: ?>
                                    <span class="text-muted"><i class="fas fa-check-double text-success"></i> Remitted</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include '../includes/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const table = document.querySelector('.table');
    if (!table) return;
    const tbody = table.querySelector('tbody');
    const ths = table.querySelectorAll('th.sortable');
    let sortCol = 0, sortDir = -1;

    function sortTable(colIdx, dir) {
        const rows = Array.from(tbody.querySelectorAll('tr'));
        // Filter out empty row if exists
        const dataRows = rows.filter(r => r.cells.length > 1);
        
        dataRows.sort((a, b) => {
            const ac = a.cells[colIdx], bc = b.cells[colIdx];
            if (!ac || !bc) return 0;
            const av = ac.dataset.sort !== undefined ? parseFloat(ac.dataset.sort) : ac.textContent.trim();
            const bv = bc.dataset.sort !== undefined ? parseFloat(bc.dataset.sort) : bc.textContent.trim();

            if (!isNaN(av) && !isNaN(bv)) return (av - bv) * dir;
            return String(av).localeCompare(String(bv)) * dir;
        });
        
        dataRows.forEach(r => tbody.appendChild(r));
        ths.forEach(th => {
            const arrow = th.querySelector('.sort-arrow');
            if (arrow) arrow.textContent = '';
        });
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

    // Default sort
    sortTable(sortCol, sortDir);
});
</script>
