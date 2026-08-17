<?php
// pages/reports.php
require_once '../config/config.php';
require_once '../includes/functions.php';

require_login();

$settings = get_company_settings($pdo);

// --- Filters ---
$view       = $_GET['view']     ?? 'pl';           // pl | balance | cashflow | client | job | jobtype
$date_from  = $_GET['date_from'] ?? date('Y-01-01');
$date_to    = $_GET['date_to']   ?? date('Y-12-31');
$client_id  = intval($_GET['client_id'] ?? 0);
$job_type_id= intval($_GET['job_type_id'] ?? 0);

// Date SQL filter for transactions
$df = $pdo->quote($date_from);
$dt = $pdo->quote($date_to);
$date_filter = "AND transaction_date BETWEEN $df AND $dt";

// =========================================================
// VIEW: P&L (Income Statement)
// =========================================================
if ($view === 'pl') {
    $pl_sql = "
        SELECT report_category, type, SUM(amount) as total
        FROM transactions
        WHERE report_category IN ('Revenue', 'Operating Expense', 'Other Income')
        $date_filter
        GROUP BY report_category, type
    ";
    $pl_raw = $pdo->query($pl_sql)->fetchAll(PDO::FETCH_ASSOC);

    $revenue = $other_income = $operating_expenses = 0;
    foreach ($pl_raw as $row) {
        if ($row['report_category'] == 'Revenue'           && $row['type'] == 'inflow')  $revenue            += $row['total'];
        if ($row['report_category'] == 'Other Income'      && $row['type'] == 'inflow')  $other_income       += $row['total'];
        if ($row['report_category'] == 'Operating Expense' && $row['type'] == 'outflow') $operating_expenses += $row['total'];
        if ($row['report_category'] == 'Revenue'           && $row['type'] == 'outflow') $revenue            -= $row['total'];
        if ($row['report_category'] == 'Other Income'      && $row['type'] == 'outflow') $other_income       -= $row['total'];
        if ($row['report_category'] == 'Operating Expense' && $row['type'] == 'inflow')  $operating_expenses -= $row['total'];
    }
    $gross_profit    = $revenue;
    $net_profit      = ($gross_profit + $other_income) - $operating_expenses;
    $edu_tax         = $net_profit > 0 ? ($net_profit * ($settings['education_tax_rate']/100)) : 0;
    $profit_after_tax = $net_profit - $edu_tax;
}

// =========================================================
// VIEW: Balance Sheet
// =========================================================
if ($view === 'balance') {
    $bs_raw = $pdo->query("
        SELECT report_category, is_non_current, type, SUM(amount) as total
        FROM transactions
        WHERE report_category IN ('Asset','Liability','Equity') $date_filter
        GROUP BY report_category, is_non_current, type
    ")->fetchAll(PDO::FETCH_ASSOC);

    $current_assets = $non_current_assets = $current_liabilities = $non_current_liabilities = $equity = 0;
    $current_assets += floatval($pdo->query("SELECT SUM(balance) FROM bank_accounts")->fetchColumn());

    foreach ($bs_raw as $row) {
        if ($row['report_category'] == 'Asset') {
            $v = ($row['type'] == 'outflow') ? $row['total'] : -$row['total'];
            $row['is_non_current'] ? $non_current_assets += $v : $current_assets += $v;
        }
        if ($row['report_category'] == 'Liability') {
            $v = ($row['type'] == 'inflow') ? $row['total'] : -$row['total'];
            $row['is_non_current'] ? $non_current_liabilities += $v : $current_liabilities += $v;
        }
        if ($row['report_category'] == 'Equity') {
            $equity += ($row['type'] == 'inflow') ? $row['total'] : -$row['total'];
        }
    }
    // Use fresh P&L for retained earnings calc
    $pl_tmp = $pdo->query("SELECT type, SUM(amount) as t FROM transactions WHERE report_category IN ('Revenue','Operating Expense','Other Income') $date_filter GROUP BY type")->fetchAll(PDO::FETCH_ASSOC);
    $_in = $_out = 0;
    foreach ($pl_tmp as $r) { if ($r['type']=='inflow') $_in += $r['t']; else $_out += $r['t']; }
    $retained = ($_in - $_out) * (1 - ($settings['education_tax_rate']/100));
    $equity += $retained;
    $total_assets = $current_assets + $non_current_assets;
    $total_le = $current_liabilities + $non_current_liabilities + $equity;
}

// =========================================================
// VIEW: Revenue by Client
// =========================================================
if ($view === 'client') {
    $client_filter = $client_id ? "AND i.client_id = $client_id" : "";
    $client_report = $pdo->query("
        SELECT 
            COALESCE(c.name, i.client_name) as client_name,
            i.client_id,
            COUNT(DISTINCT i.id) as total_jobs,
            SUM(i.total_with_vat) as contract_value,
            COALESCE(SUM(CASE WHEN t.type='inflow' THEN t.amount ELSE 0 END),0) as total_inflow,
            COALESCE(SUM(CASE WHEN t.type='outflow' THEN t.amount ELSE 0 END),0) as total_outflow
        FROM invoices i
        LEFT JOIN clients c ON i.client_id = c.id
        LEFT JOIN transactions t ON t.invoice_id = i.id AND t.transaction_date BETWEEN $df AND $dt
        WHERE i.date BETWEEN $df AND $dt
        $client_filter
        GROUP BY i.client_id, client_name
        ORDER BY total_inflow DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
}

// =========================================================
// VIEW: P&L by Job Order
// =========================================================
if ($view === 'job') {
    $job_report = $pdo->query("
        SELECT
            i.id, i.invoice_id, i.client_name, i.status, i.payment_status,
            i.total_with_vat as contract_value,
            COALESCE(SUM(CASE WHEN t.type='inflow'  THEN t.amount ELSE 0 END),0) as inflow,
            COALESCE(SUM(CASE WHEN t.type='outflow' THEN t.amount ELSE 0 END),0) as outflow
        FROM invoices i
        LEFT JOIN transactions t ON t.invoice_id = i.id AND t.transaction_date BETWEEN $df AND $dt
        WHERE i.date BETWEEN $df AND $dt
        GROUP BY i.id
        ORDER BY i.date DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
}

// =========================================================
// VIEW: P&L by Job Type
// =========================================================
if ($view === 'jobtype') {
    $jt_filter = $job_type_id ? "AND i.project_type_id = $job_type_id" : "";
    $jobtype_report = $pdo->query("
        SELECT
            COALESCE(pt.name, 'Unknown') as type_name,
            pt.code,
            COUNT(DISTINCT i.id) as total_jobs,
            SUM(i.total_with_vat) as contract_value,
            COALESCE(SUM(CASE WHEN t.type='inflow'  THEN t.amount ELSE 0 END),0) as inflow,
            COALESCE(SUM(CASE WHEN t.type='outflow' THEN t.amount ELSE 0 END),0) as outflow
        FROM invoices i
        LEFT JOIN project_types pt ON i.project_type_id = pt.id
        LEFT JOIN transactions t ON t.invoice_id = i.id AND t.transaction_date BETWEEN $df AND $dt
        WHERE i.date BETWEEN $df AND $dt $jt_filter
        GROUP BY i.project_type_id, type_name
        ORDER BY inflow DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch dropdown data
$all_clients    = $pdo->query("SELECT id, name FROM clients ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$all_jobtypes   = $pdo->query("SELECT id, name, code FROM project_types ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

include '../includes/header.php';

function fmt($n) {
    return '₦' . number_format(floatval($n), 2);
}
function pnl_color($v) {
    return floatval($v) >= 0 ? 'var(--accent-success)' : 'var(--accent-danger)';
}
?>

<style>
.report-section { margin-bottom: 2rem; }
.report-table { width: 100%; border-collapse: collapse; margin-top: 1rem; background: var(--bg-card); }
.report-table th, .report-table td { padding: 0.65rem 1rem; border-bottom: 1px solid var(--border-color); }
.report-table th { background: rgba(255,255,255,0.05); text-align: left; font-size: 0.8rem; text-transform: uppercase; }
.r-total { font-weight: 700; background: rgba(59,130,246,0.1); }
.r-positive { color: var(--accent-success); font-weight:600; }
.r-negative { color: var(--accent-danger); font-weight:600; }
.tab-bar { display: flex; flex-wrap:wrap; gap: 0.4rem; margin-bottom: 1.5rem; }
.tab-btn { padding: 0.4rem 1rem; border-radius: 6px; text-decoration: none; font-size: 0.83rem; border: 1px solid var(--border-color); color: var(--text-muted); background: var(--bg-card); }
.tab-btn.active { background: var(--accent-primary); color: #fff; border-color: var(--accent-primary); font-weight: 600; }
.filter-bar { background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 8px; padding: 1rem; margin-bottom: 1.5rem; display:flex; flex-wrap:wrap; gap: 1rem; align-items: flex-end; }
.filter-bar .form-group { margin: 0; }
.filter-bar label { font-size: 0.75rem; }
@media print {
    body { background: white; color: black; }
    .card, .report-table { background: white; border: 1px solid #ddd; box-shadow: none; }
    .no-print { display: none; }
    .sidebar { display: none; }
    .main-content { margin: 0; padding: 0; }
    th, td { border-bottom: 1px solid #ccc !important; }
}
</style>

<div class="flex-between mb-1">
    <h2><i class="fas fa-chart-line text-primary"></i> Financial Reports</h2>
    <button class="btn btn-outline no-print" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
</div>

<!-- Tab Navigation -->
<?php
$tabs = [
    'pl'      => ['icon' => 'fa-file-invoice-dollar', 'label' => 'P&L Statement'],
    'balance' => ['icon' => 'fa-balance-scale',        'label' => 'Balance Sheet'],
    'cashflow'=> ['icon' => 'fa-water',                'label' => 'Cash Flow'],
    'client'  => ['icon' => 'fa-users',                'label' => 'Revenue by Client'],
    'job'     => ['icon' => 'fa-file-alt',             'label' => 'P&L by Job Order'],
    'jobtype' => ['icon' => 'fa-tags',                 'label' => 'P&L by Job Type'],
];
$base = '?date_from=' . urlencode($date_from) . '&date_to=' . urlencode($date_to);
?>
<div class="tab-bar no-print">
    <?php foreach ($tabs as $key => $tab): ?>
        <a href="<?= $base ?>&view=<?= $key ?>" class="tab-btn <?= $view === $key ? 'active' : '' ?>">
            <i class="fas <?= $tab['icon'] ?>"></i> <?= $tab['label'] ?>
        </a>
    <?php endforeach; ?>
</div>

<!-- Filter Bar -->
<form method="GET" class="filter-bar no-print">
    <input type="hidden" name="view" value="<?= htmlspecialchars($view) ?>">
    <div class="form-group">
        <label class="form-label">Date From</label>
        <input type="date" name="date_from" class="form-control" value="<?= $date_from ?>" style="padding: 0.35rem 0.6rem;">
    </div>
    <div class="form-group">
        <label class="form-label">Date To</label>
        <input type="date" name="date_to" class="form-control" value="<?= $date_to ?>" style="padding: 0.35rem 0.6rem;">
    </div>
    <?php if ($view === 'client'): ?>
    <div class="form-group">
        <label class="form-label">Filter by Client</label>
        <select name="client_id" class="form-control" style="padding: 0.35rem 0.6rem;">
            <option value="0">— All Clients —</option>
            <?php foreach ($all_clients as $cl): ?>
                <option value="<?= $cl['id'] ?>" <?= $client_id == $cl['id'] ? 'selected' : '' ?>><?= htmlspecialchars($cl['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>
    <?php if ($view === 'jobtype'): ?>
    <div class="form-group">
        <label class="form-label">Filter by Job Type</label>
        <select name="job_type_id" class="form-control" style="padding: 0.35rem 0.6rem;">
            <option value="0">— All Types —</option>
            <?php foreach ($all_jobtypes as $jt): ?>
                <option value="<?= $jt['id'] ?>" <?= $job_type_id == $jt['id'] ? 'selected' : '' ?>><?= htmlspecialchars($jt['name']) ?> (<?= $jt['code'] ?>)</option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>
    <!-- Period shortcuts -->
    <div class="form-group">
        <label class="form-label">Quick Period</label>
        <div style="display:flex; gap:0.3rem; flex-wrap:wrap;">
            <?php
            $shortcuts = [
                'This Month' => [date('Y-m-01'), date('Y-m-t')],
                'This Year'  => [date('Y-01-01'), date('Y-12-31')],
                'Last Year'  => [date('Y-01-01', strtotime('-1 year')), date('Y-12-31', strtotime('-1 year'))],
                'All Time'   => ['2000-01-01', date('Y-12-31')],
            ];
            foreach ($shortcuts as $lbl => [$f, $t]): ?>
                <a href="?view=<?= $view ?>&date_from=<?= $f ?>&date_to=<?= $t ?>"
                   style="padding: 0.3rem 0.6rem; font-size:0.75rem; border-radius:5px; border: 1px solid var(--border-color); text-decoration:none; color: var(--text-muted); background: var(--bg-main);"><?= $lbl ?></a>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="form-group">
        <button type="submit" class="btn btn-primary" style="padding: 0.45rem 1rem;"><i class="fas fa-filter"></i> Apply</button>
    </div>
</form>
<p class="text-muted" style="font-size:0.8rem; margin-bottom:1rem;">
    Showing data for: <strong><?= date('d M Y', strtotime($date_from)) ?></strong> to <strong><?= date('d M Y', strtotime($date_to)) ?></strong>
</p>

<?php /* ==================== P&L VIEW ==================== */ if ($view === 'pl'): ?>
<div class="card report-section">
    <h4>Statement of Profit or Loss</h4>
    <table class="report-table">
        <tr><td>Revenue (Gross)</td><td class="text-right"><?= fmt($revenue) ?></td></tr>
        <tr class="r-total"><td>Gross Profit</td><td class="text-right"><?= fmt($gross_profit) ?></td></tr>
        <tr><td>Other Income</td><td class="text-right"><?= fmt($other_income) ?></td></tr>
        <tr>
            <td>Operating Expenses</td>
            <td class="text-right r-negative">-(<?= fmt($operating_expenses) ?>)</td>
        </tr>
        <tr class="r-total"><td>Net Profit Before Tax</td><td class="text-right" style="color:<?= pnl_color($net_profit) ?>"><?= fmt($net_profit) ?></td></tr>
        <tr>
            <td>Education Tax (<?= $settings['education_tax_rate'] ?>%)</td>
            <td class="text-right r-negative">-(<?= fmt($edu_tax) ?>)</td>
        </tr>
        <tr class="r-total" style="font-size:1.05rem; border-top: 2px solid var(--accent-primary);">
            <td>Profit / (Loss) After Tax</td>
            <td class="text-right" style="color:<?= pnl_color($profit_after_tax) ?>"><?= fmt($profit_after_tax) ?></td>
        </tr>
    </table>
</div>

<?php elseif ($view === 'balance'): ?>
<div class="card report-section">
    <h4>Statement of Financial Position (Balance Sheet)</h4>
    <table class="report-table">
        <tr><th colspan="2">ASSETS</th></tr>
        <tr><td style="padding-left:2rem;">Non-Current Assets</td><td class="text-right"><?= fmt($non_current_assets) ?></td></tr>
        <tr><td style="padding-left:2rem;">Current Assets (incl. Bank Balances)</td><td class="text-right"><?= fmt($current_assets) ?></td></tr>
        <tr class="r-total"><td>TOTAL ASSETS</td><td class="text-right"><?= fmt($total_assets) ?></td></tr>
        <tr><th colspan="2">EQUITY AND LIABILITIES</th></tr>
        <tr><td style="padding-left:2rem;">Equity & Retained Earnings</td><td class="text-right"><?= fmt($equity) ?></td></tr>
        <tr><td style="padding-left:2rem;">Non-Current Liabilities</td><td class="text-right"><?= fmt($non_current_liabilities) ?></td></tr>
        <tr><td style="padding-left:2rem;">Current Liabilities</td><td class="text-right"><?= fmt($current_liabilities) ?></td></tr>
        <tr class="r-total"><td>TOTAL EQUITY AND LIABILITIES</td><td class="text-right"><?= fmt($total_le) ?></td></tr>
    </table>
    <?php if (round($total_assets, 2) != round($total_le, 2)): ?>
        <p class="text-danger mt-1" style="font-size:0.8rem;"><i class="fas fa-exclamation-triangle"></i> Out of balance by <?= fmt(abs($total_assets - $total_le)) ?>. Ensure all transactions are properly categorized.</p>
    <?php endif; ?>
</div>

<?php elseif ($view === 'cashflow'): ?>
<?php
// The 'cashflow' view is requested on its own (never alongside 'balance' in the
// same request), so the figures it needs are always recalculated fresh here.
$nc_raw = $pdo->query("SELECT SUM(amount) FROM transactions WHERE report_category='Asset' AND is_non_current=1 AND type='outflow' $date_filter")->fetchColumn();
$non_current_assets = floatval($nc_raw);
$nc_liab = $pdo->query("SELECT SUM(amount) FROM transactions WHERE report_category='Liability' AND is_non_current=1 AND type='inflow' $date_filter")->fetchColumn();
$non_current_liabilities = floatval($nc_liab);
$eq_raw = $pdo->query("SELECT SUM(CASE WHEN type='inflow' THEN amount ELSE -amount END) FROM transactions WHERE report_category='Equity' $date_filter")->fetchColumn();
$equity_raw = floatval($eq_raw);
$pl_t = $pdo->query("SELECT type, SUM(amount) t FROM transactions WHERE report_category IN ('Revenue','Operating Expense','Other Income') $date_filter GROUP BY type")->fetchAll(PDO::FETCH_ASSOC);
$_in=$_out=0; foreach($pl_t as $r){ if($r['type']=='inflow')$_in+=$r['t']; else $_out+=$r['t']; }
$op_cash        = $_in - $_out;
$investing_cash = -$non_current_assets;
$financing_cash = $non_current_liabilities + $equity_raw;
$net_cash = $op_cash + $investing_cash + $financing_cash;
?>
<div class="card report-section">
    <h4>Statement of Cash Flows (Indirect Method)</h4>
    <table class="report-table">
        <tr><td>Operating Activities (Net Profit)</td><td class="text-right"><?= fmt($op_cash) ?></td></tr>
        <tr><td>Investing Activities</td><td class="text-right"><?= fmt($investing_cash) ?></td></tr>
        <tr><td>Financing Activities</td><td class="text-right"><?= fmt($financing_cash) ?></td></tr>
        <tr class="r-total" style="font-size:1.05rem;">
            <td>Net Increase / (Decrease) in Cash</td>
            <td class="text-right" style="color:<?= pnl_color($net_cash) ?>"><?= fmt($net_cash) ?></td>
        </tr>
    </table>
</div>

<?php /* ====== REVENUE BY CLIENT ====== */ elseif ($view === 'client'): ?>
<div class="card report-section">
    <h4><i class="fas fa-users text-primary"></i> Revenue by Client</h4>
    <?php if (empty($client_report)): ?>
        <p class="text-muted text-center" style="padding:2rem;">No client data found for this period.</p>
    <?php else: ?>
    <?php
        $grand_contracts = array_sum(array_column($client_report, 'contract_value'));
        $grand_in  = array_sum(array_column($client_report, 'total_inflow'));
        $grand_out = array_sum(array_column($client_report, 'total_outflow'));
    ?>
    <table class="report-table">
        <thead>
            <tr>
                <th>Client</th>
                <th class="text-right">Jobs</th>
                <th class="text-right">Contract Value</th>
                <th class="text-right">Total Inflows</th>
                <th class="text-right">Total Outflows</th>
                <th class="text-right">Net P&L</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($client_report as $cr):
            $net = $cr['total_inflow'] - $cr['total_outflow'];
        ?>
            <tr>
                <td><strong><?= htmlspecialchars($cr['client_name']) ?></strong></td>
                <td class="text-right"><?= $cr['total_jobs'] ?></td>
                <td class="text-right"><?= fmt($cr['contract_value']) ?></td>
                <td class="text-right r-positive"><?= fmt($cr['total_inflow']) ?></td>
                <td class="text-right r-negative"><?= fmt($cr['total_outflow']) ?></td>
                <td class="text-right" style="color:<?= pnl_color($net) ?>; font-weight:700;"><?= fmt($net) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr class="r-total">
                <td>TOTALS</td>
                <td class="text-right"></td>
                <td class="text-right"><?= fmt($grand_contracts) ?></td>
                <td class="text-right r-positive"><?= fmt($grand_in) ?></td>
                <td class="text-right r-negative"><?= fmt($grand_out) ?></td>
                <td class="text-right" style="color:<?= pnl_color($grand_in - $grand_out) ?>;"><?= fmt($grand_in - $grand_out) ?></td>
            </tr>
        </tfoot>
    </table>
    <?php endif; ?>
</div>

<?php /* ====== P&L BY JOB ORDER ====== */ elseif ($view === 'job'): ?>
<div class="card report-section">
    <h4><i class="fas fa-file-alt text-primary"></i> P&L by Job Order</h4>
    <?php if (empty($job_report)): ?>
        <p class="text-muted text-center" style="padding:2rem;">No job orders found for this period.</p>
    <?php else: ?>
    <?php
        $g_in  = array_sum(array_column($job_report, 'inflow'));
        $g_out = array_sum(array_column($job_report, 'outflow'));
    ?>
    <table class="report-table">
        <thead>
            <tr>
                <th>Job ID</th>
                <th>Client</th>
                <th>Status</th>
                <th class="text-right">Contract Value</th>
                <th class="text-right">Inflows</th>
                <th class="text-right">Outflows</th>
                <th class="text-right">Net P&L</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($job_report as $jr):
            $net = $jr['inflow'] - $jr['outflow'];
            $s_col = $jr['status'] == 'completed' ? 'var(--accent-success)' : 'var(--accent-warning)';
            $p_col = $jr['payment_status'] == 'fully_paid' ? 'var(--accent-success)' : 'var(--accent-danger)';
        ?>
            <tr>
                <td><a href="view_job.php?id=<?= $jr['id'] ?>" style="color:var(--accent-primary); font-weight:600;"><?= htmlspecialchars($jr['invoice_id']) ?></a></td>
                <td><?= htmlspecialchars($jr['client_name']) ?></td>
                <td>
                    <span style="font-size:0.72rem; padding:2px 6px; border-radius:4px; background:<?= $s_col ?>; color:#fff;"><?= strtoupper($jr['status']) ?></span>
                    <span style="font-size:0.72rem; padding:2px 6px; border-radius:4px; background:<?= $p_col ?>; color:#fff; margin-left:3px;"><?= strtoupper(str_replace('_',' ',$jr['payment_status'])) ?></span>
                </td>
                <td class="text-right"><?= fmt($jr['contract_value']) ?></td>
                <td class="text-right r-positive"><?= fmt($jr['inflow']) ?></td>
                <td class="text-right r-negative"><?= fmt($jr['outflow']) ?></td>
                <td class="text-right" style="color:<?= pnl_color($net) ?>; font-weight:700;"><?= fmt($net) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr class="r-total">
                <td colspan="4">TOTALS</td>
                <td class="text-right r-positive"><?= fmt($g_in) ?></td>
                <td class="text-right r-negative"><?= fmt($g_out) ?></td>
                <td class="text-right" style="color:<?= pnl_color($g_in - $g_out) ?>;"><?= fmt($g_in - $g_out) ?></td>
            </tr>
        </tfoot>
    </table>
    <?php endif; ?>
</div>

<?php /* ====== P&L BY JOB TYPE ====== */ elseif ($view === 'jobtype'): ?>
<div class="card report-section">
    <h4><i class="fas fa-tags text-primary"></i> P&L by Job Type</h4>
    <?php if (empty($jobtype_report)): ?>
        <p class="text-muted text-center" style="padding:2rem;">No job types found for this period.</p>
    <?php else: ?>
    <?php
        $g_in  = array_sum(array_column($jobtype_report, 'inflow'));
        $g_out = array_sum(array_column($jobtype_report, 'outflow'));
    ?>
    <table class="report-table">
        <thead>
            <tr>
                <th>Job Type</th>
                <th>Code</th>
                <th class="text-right">Total Jobs</th>
                <th class="text-right">Contract Value</th>
                <th class="text-right">Total Inflows</th>
                <th class="text-right">Total Outflows</th>
                <th class="text-right">Net P&L</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($jobtype_report as $jtr):
            $net = $jtr['inflow'] - $jtr['outflow'];
        ?>
            <tr>
                <td><strong><?= htmlspecialchars($jtr['type_name']) ?></strong></td>
                <td><span style="background:var(--bg-main); padding:2px 6px; border-radius:3px; font-size:0.8rem;"><?= htmlspecialchars($jtr['code'] ?? '-') ?></span></td>
                <td class="text-right"><?= $jtr['total_jobs'] ?></td>
                <td class="text-right"><?= fmt($jtr['contract_value']) ?></td>
                <td class="text-right r-positive"><?= fmt($jtr['inflow']) ?></td>
                <td class="text-right r-negative"><?= fmt($jtr['outflow']) ?></td>
                <td class="text-right" style="color:<?= pnl_color($net) ?>; font-weight:700;"><?= fmt($net) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr class="r-total">
                <td colspan="4">TOTALS</td>
                <td class="text-right r-positive"><?= fmt($g_in) ?></td>
                <td class="text-right r-negative"><?= fmt($g_out) ?></td>
                <td class="text-right" style="color:<?= pnl_color($g_in - $g_out) ?>;"><?= fmt($g_in - $g_out) ?></td>
            </tr>
        </tfoot>
    </table>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php include '../includes/footer.php'; ?>
