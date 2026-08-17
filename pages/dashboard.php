<?php
// pages/dashboard.php
require_once '../config/config.php';
require_once '../includes/functions.php';

require_login();

// --- Period Filter ---
$period = $_GET['period'] ?? 'month';
$period_map = [
    'week'  => ['label' => 'This Week',  'sql' => "YEARWEEK(transaction_date, 1) = YEARWEEK(CURDATE(), 1)"],
    'month' => ['label' => 'This Month', 'sql' => "MONTH(transaction_date) = MONTH(CURDATE()) AND YEAR(transaction_date) = YEAR(CURDATE())"],
    'year'  => ['label' => 'This Year',  'sql' => "YEAR(transaction_date) = YEAR(CURDATE())"],
    'all'   => ['label' => 'All Time',   'sql' => "1=1"],
];
if (!array_key_exists($period, $period_map)) $period = 'month';
$period_sql = $period_map[$period]['sql'];
$period_label = $period_map[$period]['label'];

// --- Core Metrics ---
$total_jobs = $pdo->query("SELECT COUNT(*) FROM invoices")->fetchColumn();
$open_jobs  = $pdo->query("SELECT COUNT(*) FROM invoices WHERE status = 'open'")->fetchColumn();
$completed_jobs = $pdo->query("SELECT COUNT(*) FROM invoices WHERE status = 'completed'")->fetchColumn();

// Account Balances
$bank_balances = $pdo->query("SELECT name, type, balance FROM bank_accounts WHERE is_active = 1 ORDER BY balance DESC")->fetchAll(PDO::FETCH_ASSOC);
$total_cash = array_sum(array_column($bank_balances, 'balance'));

// Period-filtered Transaction sums
$period_in  = $pdo->query("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE type='inflow'  AND $period_sql")->fetchColumn();
$period_out = $pdo->query("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE type='outflow' AND $period_sql")->fetchColumn();
$net_profit = $period_in - $period_out;

// Total Revenue (All time inflows from invoice-linked transactions aka actual earnings)
$total_revenue_all = $pdo->query("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE type='inflow'")->fetchColumn();

// Pending Tithes
$pending_tithes = $pdo->query("SELECT COALESCE(SUM(amount),0) FROM tithes WHERE status='owed'")->fetchColumn();

// Recent transactions for the mini-ledger
$recent_txns = $pdo->query("
    SELECT t.type, t.amount, t.description, t.transaction_date, b.name as bname
    FROM transactions t
    LEFT JOIN bank_accounts b ON t.bank_account_id = b.id
    ORDER BY t.transaction_date DESC, t.id DESC LIMIT 6
")->fetchAll(PDO::FETCH_ASSOC);

include '../includes/header.php';
?>

<!-- Dashboard Header + Controls -->
<div class="flex-between mb-1" style="flex-wrap: wrap; gap: 0.75rem;">
    <div>
        <h2><i class="fas fa-chart-pie text-primary"></i> Dashboard Overview</h2>
        <span class="text-muted" style="font-size:0.85rem;">Welcome back, <?= htmlspecialchars($_SESSION['username']) ?>! &nbsp;|&nbsp; <?= date('l, d M Y') ?></span>
    </div>
    <div style="display:flex; gap:0.5rem; align-items:center; flex-wrap:wrap;">
        <!-- Hide Figures Toggle -->
        <button id="toggleHide" onclick="handleToggleClick()" class="btn btn-outline" style="font-size:0.8rem; padding: 0.35rem 0.75rem;">
            <i class="fas fa-eye" id="hideIcon"></i> <span id="hideLabel">Hide Figures</span>
        </button>
        <!-- Period Selector -->
        <div style="display:flex; background:var(--bg-card); border:1px solid var(--border-color); border-radius:8px; overflow:hidden;">
            <?php foreach ($period_map as $key => $p): ?>
                <a href="?period=<?= $key ?>" style="padding: 0.35rem 0.75rem; font-size: 0.8rem; text-decoration:none;
                    color: <?= $period === $key ? '#fff' : 'var(--text-muted)' ?>;
                    background: <?= $period === $key ? 'var(--accent-primary)' : 'transparent' ?>;
                    font-weight: <?= $period === $key ? '600' : '400' ?>;">
                    <?= $p['label'] ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Revenue + Financial KPI Tiles Row 1 -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 1rem; margin-bottom: 1rem;">

    <div class="card dash-tile" style="border-top: 3px solid var(--accent-success); margin-bottom:0; text-align:center;">
        <div class="text-muted" style="font-size: 0.7rem; text-transform: uppercase; letter-spacing: 1px;">Total Revenue (All Time)</div>
        <div class="currency-naira dash-figure" style="font-size: 1.4rem; font-weight: 700; color: var(--accent-success); margin-top: 0.5rem;"><?= number_format($total_revenue_all, 2) ?></div>
        <div style="font-size: 0.72rem; color: var(--text-muted); margin-top:0.3rem;">Lifetime Inflows</div>
    </div>

    <div class="card dash-tile" style="border-top: 3px solid #6366f1; margin-bottom:0; text-align:center;">
        <div class="text-muted" style="font-size: 0.7rem; text-transform: uppercase; letter-spacing: 1px;">Total Liquidity</div>
        <div class="currency-naira dash-figure" style="font-size: 1.4rem; font-weight: 700; color: var(--text-main); margin-top: 0.5rem;"><?= number_format($total_cash, 2) ?></div>
        <div style="font-size: 0.72rem; color: var(--text-muted); margin-top:0.3rem;">All Bank / Cash Accounts</div>
    </div>

    <div class="card dash-tile" style="border-top: 3px solid var(--accent-warning); margin-bottom:0; text-align:center;">
        <div class="text-muted" style="font-size: 0.7rem; text-transform: uppercase; letter-spacing: 1px;"><?= $period_label ?> · Inflows</div>
        <div class="currency-naira dash-figure" style="font-size: 1.4rem; font-weight: 700; color: var(--accent-success); margin-top: 0.5rem;"><?= number_format($period_in, 2) ?></div>
        <div style="font-size: 0.72rem; color: var(--text-muted); margin-top:0.3rem;">Total Income</div>
    </div>

    <div class="card dash-tile" style="border-top: 3px solid var(--accent-danger); margin-bottom:0; text-align:center;">
        <div class="text-muted" style="font-size: 0.7rem; text-transform: uppercase; letter-spacing: 1px;"><?= $period_label ?> · Outflows</div>
        <div class="currency-naira dash-figure" style="font-size: 1.4rem; font-weight: 700; color: var(--accent-danger); margin-top: 0.5rem;"><?= number_format($period_out, 2) ?></div>
        <div style="font-size: 0.72rem; color: var(--text-muted); margin-top:0.3rem;">Total Expenses</div>
    </div>

    <div class="card dash-tile" style="border-top: 3px solid <?= $net_profit >= 0 ? 'var(--accent-success)' : 'var(--accent-danger)' ?>; margin-bottom:0; text-align:center;">
        <div class="text-muted" style="font-size: 0.7rem; text-transform: uppercase; letter-spacing: 1px;"><?= $period_label ?> · Net Profit</div>
        <div class="currency-naira dash-figure" style="font-size: 1.4rem; font-weight: 700; color: <?= $net_profit >= 0 ? 'var(--accent-success)' : 'var(--accent-danger)' ?>; margin-top: 0.5rem;"><?= number_format(abs($net_profit), 2) ?></div>
        <div style="font-size: 0.72rem; color: var(--text-muted); margin-top:0.3rem;"><?= $net_profit >= 0 ? 'Surplus' : 'Deficit' ?></div>
    </div>

    <div class="card dash-tile" style="border-top: 3px solid var(--accent-primary); margin-bottom:0; text-align:center;">
        <div class="text-muted" style="font-size: 0.7rem; text-transform: uppercase; letter-spacing: 1px;">Job Orders</div>
        <div style="font-size: 1.4rem; font-weight: 700; margin-top: 0.5rem;"><?= $total_jobs ?></div>
        <div style="font-size: 0.72rem; color: var(--text-muted); margin-top:0.3rem;"><?= $completed_jobs ?> Completed &nbsp;·&nbsp; <?= $open_jobs ?> Open</div>
    </div>

    <?php if ($pending_tithes > 0): ?>
    <div class="card dash-tile" style="border-top: 3px solid #f59e0b; margin-bottom:0; text-align:center;">
        <div class="text-muted" style="font-size: 0.7rem; text-transform: uppercase; letter-spacing: 1px;">Pending Tithes</div>
        <div class="currency-naira dash-figure" style="font-size: 1.4rem; font-weight: 700; color: #f59e0b; margin-top: 0.5rem;"><?= number_format($pending_tithes, 2) ?></div>
        <div style="font-size: 0.72rem; margin-top:0.3rem;"><a href="tithes.php" style="color:#f59e0b;">View Tithes →</a></div>
    </div>
    <?php endif; ?>
</div>

<!-- Bank Accounts + Recent Transactions Row -->
<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
    <!-- Bank Accounts -->
    <div class="card">
        <h4><i class="fas fa-university text-primary"></i> Account Balances</h4>
        <hr style="border-color: var(--border-color); margin: 1rem 0;">
        <?php if (empty($bank_balances)): ?>
            <p class="text-muted text-center">No accounts configured.</p>
        <?php else: ?>
            <?php foreach($bank_balances as $bank): ?>
                <div class="flex-between" style="padding: 0.6rem 0; border-bottom: 1px solid var(--border-color);">
                    <div>
                        <span><?= htmlspecialchars($bank['name']) ?></span>
                        <span style="font-size:0.7rem; color:var(--text-muted); background:var(--bg-main); padding:1px 5px; border-radius:3px; margin-left:5px;"><?= strtoupper($bank['type']) ?></span>
                    </div>
                    <span class="currency-naira dash-figure" style="font-weight: 600; color: var(--accent-success);"><?= number_format($bank['balance'], 2) ?></span>
                </div>
            <?php endforeach; ?>
            <div class="flex-between" style="padding: 0.6rem 0; font-weight:700;">
                <span>TOTAL</span>
                <span class="currency-naira dash-figure"><?= number_format($total_cash, 2) ?></span>
            </div>
        <?php endif; ?>
    </div>

    <!-- Recent Transactions -->
    <div class="card">
        <div class="flex-between">
            <h4><i class="fas fa-clock text-warning"></i> Recent Transactions</h4>
            <a href="transactions.php" style="font-size:0.8rem; color:var(--accent-primary);">View All →</a>
        </div>
        <hr style="border-color: var(--border-color); margin: 1rem 0;">
        <?php if (empty($recent_txns)): ?>
            <p class="text-muted text-center" style="padding:1rem;">No transactions yet.</p>
        <?php else: ?>
            <?php foreach($recent_txns as $rt): ?>
                <div class="flex-between" style="padding: 0.5rem 0; border-bottom: 1px solid var(--border-color);">
                    <div>
                        <div style="font-size:0.85rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:180px;">
                            <?= $rt['type'] === 'inflow'
                                ? '<i class="fas fa-arrow-down" style="color:var(--accent-success); font-size:0.7rem;"></i>'
                                : '<i class="fas fa-arrow-up" style="color:var(--accent-danger); font-size:0.7rem;"></i>'
                            ?>
                            <?= htmlspecialchars($rt['description']) ?>
                        </div>
                        <div style="font-size:0.7rem; color:var(--text-muted);"><?= date('d M y', strtotime($rt['transaction_date'])) ?> · <?= htmlspecialchars($rt['bname'] ?? '') ?></div>
                    </div>
                    <span class="currency-naira dash-figure" style="font-weight:600; font-size:0.9rem; color:<?= $rt['type'] === 'inflow' ? 'var(--accent-success)' : 'var(--accent-danger)' ?>;"><?= number_format($rt['amount'], 2) ?></span>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Quick Actions -->
<div class="card" style="margin-top:1.5rem;">
    <h4><i class="fas fa-bolt text-warning"></i> Quick Actions</h4>
    <hr style="border-color: var(--border-color); margin: 1rem 0;">
    <div style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
        <a href="job_orders.php" class="btn btn-outline"><i class="fas fa-file-invoice"></i> Job Orders</a>
        <a href="transactions.php" class="btn btn-outline"><i class="fas fa-wallet"></i> Record Transaction</a>
        <a href="catalogue.php" class="btn btn-outline"><i class="fas fa-box-open"></i> Product Catalogue</a>
        <a href="project_survey.php" class="btn btn-outline"><i class="fas fa-clipboard-list"></i> Project Survey</a>
        <a href="tithes.php" class="btn btn-outline"><i class="fas fa-hand-holding-heart"></i> Tithes</a>
        <a href="clients.php" class="btn btn-outline"><i class="fas fa-users"></i> Clients</a>
        <a href="reports.php" class="btn btn-outline"><i class="fas fa-chart-line"></i> Reports</a>
        <?php if(($_SESSION['user_role'] ?? null) === 'admin'): ?>
            <a href="backup.php" class="btn btn-outline"><i class="fas fa-database"></i> Backups</a>
            <a href="settings.php" class="btn btn-outline"><i class="fas fa-cog"></i> Settings</a>
        <?php endif; ?>
    </div>
</div>

<script>
let figuresHidden = true; // DEFAULT: HIDDEN

function toggleFigures() {
    const figures = document.querySelectorAll('.dash-figure, .currency-naira');
    figures.forEach(el => {
        if (figuresHidden) {
            if (!el.dataset.original) el.dataset.original = el.textContent;
            el.textContent = '••••••';
        } else {
            if (el.dataset.original) el.textContent = el.dataset.original;
        }
    });
    document.getElementById('hideIcon').className = figuresHidden ? 'fas fa-eye-slash' : 'fas fa-eye';
    document.getElementById('hideLabel').textContent = figuresHidden ? 'Show Figures' : 'Hide Figures';
}

// Initial state on load
document.addEventListener('DOMContentLoaded', () => {
    toggleFigures(); // Apply initial hidden state
});

function handleToggleClick() {
    figuresHidden = !figuresHidden;
    toggleFigures();
}
</script>

<?php include '../includes/footer.php'; ?>
