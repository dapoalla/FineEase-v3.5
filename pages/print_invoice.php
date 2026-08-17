<?php
// pages/print_invoice.php
require_once '../config/config.php';
require_once '../includes/functions.php';

require_login();

if (!isset($_GET['id'])) {
    die("Invalid Invoice ID specified.");
}

$job_id = intval($_GET['id']);
$doc_type = $_GET['type'] ?? 'invoice'; 

try {
    // Get Job Details
    $stmt = $pdo->prepare("SELECT i.*, p.code as project_code, c.address as client_address, c.phone as client_phone, c.email as client_email FROM invoices i LEFT JOIN project_types p ON i.project_type_id = p.id LEFT JOIN clients c ON i.client_id = c.id WHERE i.id = ?");
    $stmt->execute([$job_id]);
    $job = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$job) die("Job Order not found.");

    // Get Line Items
    $stmt = $pdo->prepare("SELECT * FROM job_order_line_items WHERE job_order_id = ? ORDER BY id ASC");
    $stmt->execute([$job_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get Global Settings
    $settings = get_company_settings($pdo);

    // Get Company Registrations
    $regs = $pdo->query("SELECT * FROM company_registrations WHERE company_id = 1")->fetchAll(PDO::FETCH_ASSOC);
    $reg_string_arr = [];
    foreach($regs as $r) {
        if (strtoupper($r['reg_name']) == 'TIN') {
             $reg_string_arr[] = "TIN No: " . $r['reg_number'];
        }
    }
    $tin_string = implode(" | ", $reg_string_arr);

} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
}

$is_receipt = ($doc_type === 'receipt');
$document_title = $is_receipt ? "RECEIPT" : "INVOICE";
$accent_color  = "#1a3a6b"; // Navy blue - renders reliably in all PDF engines
$accent_light  = "#d6e4f7"; // Light navy tint for alternating cells
$accent_darker = "#1a3a6b"; // Same navy for title text

// Financials
$subtotal = array_reduce($items, function($carry, $item) { return $carry + $item['total']; }, 0);
if ($subtotal == 0) $subtotal = floatval($job['amount']); // Fallback for legacy

$vat = $job['apply_vat'] ? ($subtotal * ($settings['vat_rate'] / 100)) : 0;
$total = $subtotal + $vat;

// Part Payment Logic
$percent_advanced = isset($_GET['percent_advanced']) ? floatval($_GET['percent_advanced']) : null;
$part_payment = ($percent_advanced !== null && $total > 0) ? ($total * ($percent_advanced / 100)) : null;

if (isset($_GET['part_payment'])) {
    $part_payment = floatval($_GET['part_payment']);
    $percent_advanced = ($total > 0) ? ($part_payment / $total) * 100 : 0;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= $document_title ?> - <?= htmlspecialchars($job['invoice_id']) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        @page {
            size: A4;
            margin: 0;
        }
        /* CRITICAL: Force backgrounds to print in PDF - without this all bg colors are stripped */
        * {
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
            color-adjust: exact !important;
        }
        body {
            font-family: 'Poppins', sans-serif;
            margin: 0;
            padding: 0;
            background: #fff;
            color: #000;
            font-size: 10px; /* Tighter baseline for A4 print */
        }
        .a4-container {
            width: 210mm;
            min-height: 297mm;
            padding: 20mm 20mm 20mm 20mm;
            margin: 0 auto;
            background: white;
            box-sizing: border-box;
            position: relative;
        }
        
        /* Header specific */
        .doc-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 25px;
        }
        .company-info {
            max-width: 60%;
        }
        .company-info h1 {
            font-size: 24px;
            font-weight: 700;
            margin: 0 0 5px 0;
            text-transform: uppercase;
        }
        .company-slogan {
            font-style: italic;
            color: #000;
            font-size: 11px;
            margin-bottom: 10px;
        }
        .company-contacts {
            font-size: 10px;
            color: #000;
            border-top: 1px solid #999;
            padding-top: 5px;
        }
        .company-logo {
            max-height: 50px;
            margin-bottom: 15px;
            display: block; /* Ensure it stays above text */
        }

        /* Top Right Meta Box */
        .meta-box {
            text-align: right;
            width: 250px;
        }
        .doc-title {
            font-size: 36px;
            font-weight: 700;
            color: #1a3a6b;
            margin: 0 0 10px 0;
            letter-spacing: 2px;
        }
        .meta-table {
            width: 100%;
            border-collapse: collapse;
        }
        .meta-table td {
            text-align: right;
            padding: 4px;
        }
        .meta-label {
            background-color: #1a3a6b !important;
            color: #ffffff !important;
            padding-right: 15px !important;
            font-weight: 700;
            width: 50%;
        }

        /* Bill To / Ship To */
        .addresses-container {
            display: flex;
            gap: 20px;
            margin-bottom: 15px;
        }
        .address-box {
            flex: 1;
            border: 1px solid #999;
        }
        .box-header {
            background-color: #1a3a6b !important;
            color: #ffffff !important;
            padding: 4px 10px;
            font-size: 11px;
            font-weight: 700;
        }
        .box-content {
            padding: 10px;
            min-height: 60px;
        }

        /* Meta Logistics Table */
        .logistics-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
        }
        .logistics-table th, .logistics-table td {
            border: 1px solid #999;
            padding: 5px;
            text-align: center;
        }
        .logistics-table th {
            background-color: #1a3a6b !important;
            color: #ffffff !important;
            font-weight: 700;
        }

        /* Main Data Table */
        .main-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        .main-table th, .main-table td {
            border: 1px solid #999;
            padding: 4px 6px;
            font-size: 9.5px;
        }
        .main-table th {
            background-color: #1a3a6b !important;
            color: #ffffff !important;
            text-align: center;
            font-weight: 700;
        }
        .main-table td {
             vertical-align: top;
        }
        .td-center { text-align: center; }
        .td-right { text-align: right; }
        
        /* Empty rows padding for uniform look */
        .empty-row td {
            color: transparent;
            height: 20px;
        }
        .empty-row td:last-child {
            color: #555;
        }

        /* Totals Block - now inside a flex row alongside comments */
        .after-items-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 15px;
            margin-bottom: 20px;
        }
        .totals-container {
            flex-shrink: 0;
        }
        .totals-table {
            width: 250px;
            border-collapse: collapse;
        }
        .totals-table td {
            padding: 5px 8px;
            border: 1px solid #999;
        }
        .totals-label {
            background-color: #1a3a6b !important;
            color: #ffffff !important;
            font-weight: 700;
            width: 50%;
        }
        .totals-table tr.grand-total td {
            background-color: #0d2040 !important;
            color: #ffffff !important;
            font-weight: 700;
            font-size: 12px;
        }
        .totals-table tr.grand-total td:first-child {
            border-right: none;
        }

        /* Footer / Instructions / Signatures */
        .footer-section {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            margin-top: 20px;
        }
        .instructions-box {
            border: 1px solid #999;
        }
        .instructions-header {
            background-color: #1a3a6b !important;
            color: #ffffff !important;
            padding: 4px 10px;
            font-weight: 700;
            border-bottom: 1px solid #999;
        }
        .instructions-content {
            padding: 10px;
            line-height: 1.5;
        }
        .signature-box {
            width: 30%;
            text-align: center;
        }
        .signature-img {
            max-height: 50px;
            display: block;
            margin: 0 auto;
        }
        .signature-line {
            border-top: 1px solid #000;
            margin-top: 55px; /* space if no image */
            padding-top: 5px;
            font-weight: 600;
        }

        .thank-you {
            text-align: center;
            font-size: 16px;
            font-style: italic;
            font-weight: 600;
            color: #1a3a6b;
            margin-top: 40px;
        }

        /* PAID Stamp for receipts */
        .paid-stamp {
            position: absolute;
            top: 40mm;
            right: 25mm;
            width: 120px;
            height: 120px;
            border: 6px solid #1a3a6b;
            border-radius: 50%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            transform: rotate(-20deg);
            opacity: 0.75;
            z-index: 10;
            pointer-events: none;
        }
        .paid-stamp-text {
            font-size: 30px;
            font-weight: 900;
            color: #1a3a6b;
            letter-spacing: 3px;
            line-height: 1;
        }
        .paid-stamp-sub {
            font-size: 9px;
            color: #1a3a6b;
            font-weight: 600;
            letter-spacing: 1px;
            margin-top: 3px;
        }

        @media print {
            .no-print { display: none !important; }
            .a4-container {
                padding: 15mm; 
                width: 100%; 
                margin: 0; 
                box-shadow: none;
            }
        }
    </style>
</head>
<body>

    <!-- Print Button UI Wrapper (Hidden when printing) -->
    <div class="no-print" style="background:#1e293b; padding:15px; text-align:center; margin-bottom: 20px;">
        <button onclick="window.print()" style="background:#3b82f6; color:#fff; border:none; padding:10px 20px; font-family:'Poppins'; font-weight:600; border-radius:5px; cursor:pointer; font-size:14px;">Print Document / Save as PDF</button>
        <a href="job_orders.php" style="color:#FFF; display:inline-block; margin-left: 20px; font-family:'Poppins'; text-decoration:none;">&larr; Back to Job Orders</a>
    </div>

    <div class="a4-container">

        <?php if ($is_receipt): ?>
        <!-- PAID Stamp -->
        <div class="paid-stamp">
            <div class="paid-stamp-text">PAID</div>
            <div class="paid-stamp-sub">SETTLED</div>
        </div>
        <?php endif; ?>

        <div class="doc-header">
            <div class="company-info">
                <?php if (!empty($settings['logo_path'])): ?>
                    <img src="<?= BASE_URL ?>/<?= htmlspecialchars($settings['logo_path']) ?>" class="company-logo" alt="Logo">
                <?php endif; ?>
                
                <h1><?= htmlspecialchars($settings['company_name'] ?? 'COMPANY NAME') ?></h1>
                <!-- Sample Slogan extracted from layout requirement -->
                <div class="company-slogan">...Securing Your Future with Smart, Sustainable Solutions</div>
                
                <div class="company-contacts">
                    <?= nl2br(htmlspecialchars($settings['address'] ?? '')) ?><br>
                    <?= htmlspecialchars($settings['contact_info'] ?? '') ?> | <?= htmlspecialchars($settings['company_email'] ?? '') ?>
                </div>
            </div>

            <div class="meta-box">
                <div class="doc-title"><?= $document_title ?></div>
                <table class="meta-table">
                    <tr>
                        <td class="meta-label">DATE</td>
                        <td><?= date('d/m/Y', strtotime($job['date'])) ?></td>
                    </tr>
                    <tr>
                        <td class="meta-label"><?= $is_receipt ? 'Receipt' : 'INVOICE' ?> #</td>
                        <td style="font-weight: bold;"><?= htmlspecialchars($job['invoice_id']) ?></td>
                    </tr>
                    <tr>
                        <td class="meta-label">Customer ID</td>
                        <td><?= htmlspecialchars($job['client_name']) ?></td>
                    </tr>
                    <?php if ($percent_advanced !== null): ?>
                    <tr>
                        <td class="meta-label">Payment Scope</td>
                        <td style="font-weight:600; text-align:right;"><?= number_format($percent_advanced, 0) ?>% of Total</td>
                    </tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>

        <div class="addresses-container">
            <div class="address-box">
                <div class="box-header">BILL TO</div>
                <div class="box-content">
                    <strong><?= htmlspecialchars($job['client_name']) ?></strong><br>
                    <?php $addr = !empty($job['client_address']) ? $job['client_address'] : $job['location']; ?>
                    <?= nl2br(htmlspecialchars($addr ?? '')) ?>
                    <?php if (!empty($job['client_phone'])): ?><br><small><?= htmlspecialchars($job['client_phone']) ?></small><?php endif; ?>
                    <?php if (!empty($job['client_email'])): ?><br><small><?= htmlspecialchars($job['client_email']) ?></small><?php endif; ?>
                </div>
            </div>
            <div class="address-box">
                <div class="box-header">SHIP TO</div>
                <div class="box-content">
                    <strong><?= htmlspecialchars($job['client_name']) ?></strong><br>
                    <?= nl2br(htmlspecialchars($addr ?? '')) ?>
                    <?php if (!empty($job['client_phone'])): ?><br><small><?= htmlspecialchars($job['client_phone']) ?></small><?php endif; ?>
                    <?php if (!empty($job['client_email'])): ?><br><small><?= htmlspecialchars($job['client_email']) ?></small><?php endif; ?>
                </div>
            </div>
        </div>

        <table class="logistics-table">
            <thead>
                <tr>
                    <th>SALESPERSON</th>
                    <th>P.O. #</th>
                    <th>SHIP DATE</th>
                    <th>SHIP VIA</th>
                    <th>F.O.B.</th>
                    <th>TERMS</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>&nbsp;</td>
                    <td><?= htmlspecialchars($job['project_code'] ?? '') ?></td>
                    <td>&nbsp;</td>
                    <td>&nbsp;</td>
                    <td>&nbsp;</td>
                    <td>C.O.D.</td>
                </tr>
            </tbody>
        </table>

        <!-- Main Items Table -->
        <table class="main-table">
            <thead>
                <tr>
                    <th style="width: 5%;">S/N</th>
                    <th style="width: 15%;">Part No</th>
                    <th style="width: 45%;">DESCRIPTION</th>
                    <th style="width: 10%;">QTY</th>
                    <th style="width: 12%;">UNIT PRICE</th>
                    <th style="width: 13%;">TOTAL</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($job['has_line_items'] || !empty($items)): ?>
                    <?php $sn = 1; foreach ($items as $item): ?>
                        <tr>
                            <td class="td-center"><?= str_pad($sn++, 2, '0', STR_PAD_LEFT) ?></td>
                            <td class="td-center">N/A</td>
                            <td><?= nl2br(htmlspecialchars($item['description'])) ?></td>
                            <td class="td-center"><?= floatval($item['quantity']) ?></td>
                            <td class="td-right">₦<?= number_format($item['unit_price'], 2) ?></td>
                            <td class="td-right">₦<?= number_format($item['total'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <!-- Legacy invoices without line items fallback -->
                    <tr>
                        <td class="td-center">01</td>
                        <td class="td-center">N/A</td>
                        <td><?= nl2br(htmlspecialchars($job['service_description'])) ?></td>
                        <td class="td-center">1</td>
                        <td class="td-right">₦<?= number_format($job['amount'], 2) ?></td>
                        <td class="td-right">₦<?= number_format($job['amount'], 2) ?></td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
        <!-- After Items: Comments + Totals side by side -->
        <div class="after-items-row">

            <!-- Left: Other Comments / Special Instructions -->
            <div class="instructions-box" style="flex: 1;">
                <div class="instructions-header">Other Comments or Special Instructions</div>
                <div class="instructions-content">
                    <?= !empty($tin_string) ? htmlspecialchars($tin_string) . "<br>" : "" ?>
                    <br>
                    <?php if (!$is_receipt): ?>
                        <strong>Bank Instructions:</strong><br>
                        <?= nl2br(htmlspecialchars($settings['payment_instructions'] ?? 'Payment instructions not set.')) ?>
                        <br><br>
                        <em>Make all payments to <?= htmlspecialchars($settings['company_name'] ?? 'Company Name') ?></em>
                    <?php else: ?>
                        <strong>Status: SETTLED / PAID</strong><br>
                        <em>Payment Received. Thank You for your continued patronage.</em>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Right: Totals Table -->
            <div class="totals-container">
                <table class="totals-table">
                    <tr>
                        <td class="totals-label">SUBTOTAL</td>
                        <td class="td-right">₦<?= number_format($subtotal, 2) ?></td>
                    </tr>
                    <tr>
                        <td class="totals-label">VAT RATE</td>
                        <td class="td-right"><?= number_format($settings['vat_rate'], 2) ?>%</td>
                    </tr>
                    <tr>
                        <td class="totals-label">VAT AMOUNT</td>
                        <td class="td-right">₦<?= number_format($vat, 2) ?></td>
                    </tr>
                    <?php if ($part_payment !== null): ?>
                    <tr style="background: #f0f0f0;">
                        <td class="totals-label" style="background: #444 !important;">PROJECT TOTAL</td>
                        <td class="td-right" style="font-weight: bold;">₦<?= number_format($total, 2) ?></td>
                    </tr>
                    <tr>
                        <td class="totals-label">% ADVANCED</td>
                        <td class="td-right"><?= number_format($percent_advanced, 1) ?>%</td>
                    </tr>
                    <tr class="grand-total">
                        <td>PART PAYMENT</td>
                        <td class="td-right">₦<?= number_format($part_payment, 2) ?></td>
                    </tr>
                    <?php else: ?>
                    <tr class="grand-total">
                        <td>TOTAL</td>
                        <td class="td-right">₦<?= number_format($total, 2) ?></td>
                    </tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>

        <!-- Signature row -->
        <div class="footer-section">
            <div style="width:60%;"></div>
            <div class="signature-box">
                <?php if (!empty($settings['signature_path'])): ?>
                    <img src="<?= BASE_URL ?>/<?= htmlspecialchars($settings['signature_path']) ?>" class="signature-img" alt="Signature">
                    <div class="signature-line" style="margin-top: 5px;">Authorized Signatory</div>
                <?php else: ?>
                    <div class="signature-line">Authorized Signatory</div>
                <?php endif; ?>
            </div>
        </div>

        <div class="thank-you">Thank You For Your Business!</div>

    </div>
</body>
</html>
