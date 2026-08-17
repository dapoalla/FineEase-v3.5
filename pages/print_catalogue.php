<?php
require_once '../config/config.php';
require_once '../includes/functions.php';

require_login();

$job_order_id = isset($_POST['job_order_id']) ? intval($_POST['job_order_id']) : (isset($_GET['job_order_id']) ? intval($_GET['job_order_id']) : 0);

if ($job_order_id > 0) {
    $stmt = $pdo->prepare("SELECT ci.*, i.invoice_id, i.client_name, i.date as job_date, i.location as job_location, c.address as client_address, c.phone as client_phone, c.email as client_email, i.id as job_id FROM catalogue_items ci JOIN invoices i ON ci.job_order_id = i.id LEFT JOIN clients c ON i.client_id = c.id WHERE ci.job_order_id = ? ORDER BY ci.created_at ASC");
    $stmt->execute([$job_order_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    // Fallback if no specific job is selected
    $items = $pdo->query("SELECT ci.*, i.invoice_id, i.client_name, i.date as job_date, i.location as job_location, c.address as client_address, c.phone as client_phone, c.email as client_email, i.id as job_id FROM catalogue_items ci JOIN invoices i ON ci.job_order_id = i.id LEFT JOIN clients c ON i.client_id = c.id ORDER BY i.id DESC, ci.created_at ASC")->fetchAll(PDO::FETCH_ASSOC);
}

$settings = get_company_settings($pdo);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Product Catalogue</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @page {
            size: A4;
            margin: 0;
        }
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
            font-size: 10px;
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
        .header {text-align:center; margin-bottom:20px; border-bottom: 2px solid #1a3a6b; padding-bottom: 15px;}
        .header h1 {margin:0; font-size:24px; color: #1a3a6b;}
        .job-header {margin-top:20px; margin-bottom: 10px; font-size:14px; background: #d6e4f7; padding: 10px; border-radius: 4px; color: #1a3a6b;}
        table {width:100%; border-collapse:collapse; margin-top:5px;}
        th, td {border:1px solid #ddd; padding:8px; font-size:12px; vertical-align: middle;}
        th {background:#f2f2f2; color: #1a3a6b;}
        img {max-width:80px; height:auto; border-radius: 4px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);}
        
        .print-btn-container {
            text-align: center;
            margin: 20px 0;
        }
        .btn-print {
            padding: 10px 20px;
            background: #1a3a6b;
            color: #fff;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            font-family: 'Poppins', sans-serif;
        }
        @media print {
            body { background: white; }
            .a4-container { margin: 0; border: none; box-shadow: none; width: auto; min-height: auto; padding: 15mm; }
            .print-btn-container { display: none; }
        }

        /* Header specific (copied from print_invoice.php) */
        .doc-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 25px; }
        .company-info { max-width: 60%; }
        .company-info h1 { font-size: 24px; font-weight: 700; margin: 0 0 5px 0; text-transform: uppercase; color: #1a3a6b;}
        .company-slogan { font-style: italic; color: #000; font-size: 11px; margin-bottom: 10px; }
        .company-contacts { font-size: 10px; color: #000; border-top: 1px solid #999; padding-top: 5px; }
        .company-logo { max-height: 50px; margin-bottom: 15px; display: block; }

        /* Top Right Meta Box */
        .meta-box { text-align: right; width: 250px; }
        .doc-title { font-size: 26px; font-weight: 700; color: #1a3a6b; margin: 0 0 10px 0; letter-spacing: 2px; }
        .meta-table { width: 100%; border-collapse: collapse; }
        .meta-table td { text-align: right; padding: 4px; }
        .meta-label { background-color: #1a3a6b !important; color: #ffffff !important; padding-right: 15px !important; font-weight: 700; width: 50%; }

        /* Client Details */
        .addresses-container { display: flex; gap: 20px; margin-bottom: 15px; }
        .address-box { flex: 1; border: 1px solid #999; }
        .box-header { background-color: #1a3a6b !important; color: #ffffff !important; padding: 4px 10px; font-size: 11px; font-weight: 700; }
        .box-content { padding: 10px; min-height: 50px; }
    </style>
</head>
<body>
    <div class="print-btn-container">
        <button class="btn-print" onclick="window.print()"><i class="fas fa-print"></i> Print / Save as PDF</button>
    </div>
    
    <div class="a4-container">
        <div class="doc-header">
            <div class="company-info">
                <?php if (!empty($settings['logo_path'])): ?>
                    <img src="<?= BASE_URL ?>/<?= htmlspecialchars($settings['logo_path']) ?>" class="company-logo" alt="Logo">
                <?php endif; ?>
                <h1><?= htmlspecialchars($settings['company_name'] ?? 'COMPANY NAME') ?></h1>
                <div class="company-slogan">...Securing Your Future with Smart, Sustainable Solutions</div>
                <div class="company-contacts">
                    <?= nl2br(htmlspecialchars($settings['address'] ?? '')) ?><br>
                    <?= htmlspecialchars($settings['contact_info'] ?? '') ?> | <?= htmlspecialchars($settings['company_email'] ?? '') ?>
                </div>
            </div>

            <div class="meta-box">
                <div class="doc-title">PRODUCT CATALOGUE</div>
                <table class="meta-table">
                    <tr>
                        <td class="meta-label">DATE</td>
                        <td><?= date('d/m/Y') ?></td>
                    </tr>
                    <tr>
                        <td class="meta-label">Job Order #</td>
                        <td style="font-weight: bold;"><?= htmlspecialchars($items[0]['invoice_id'] ?? 'N/A') ?></td>
                    </tr>
                </table>
            </div>
        </div>

        <?php
        $currentJob = null;
        if (empty($items)) {
            echo "<p style='text-align:center; color:#999; margin-top: 50px;'>No catalogue items found for this Job Order.</p>";
        } else {
            foreach ($items as $it) {
                if ($currentJob !== $it['job_id']) {
                    if ($currentJob !== null) echo "</tbody></table><br/>"; // close previous table
                    $currentJob = $it['job_id'];

                    // Client details block
                    echo "<div class='addresses-container'>";
                    echo "<div class='address-box'>";
                    echo "<div class='box-header'>CLIENT DETAILS</div>";
                    echo "<div class='box-content'>";
                    echo "<strong>" . htmlspecialchars($it['client_name']) . "</strong><br>";
                    $addr = !empty($it['client_address']) ? $it['client_address'] : $it['job_location'];
                    echo nl2br(htmlspecialchars($addr ?? ''));
                    if (!empty($it['client_phone'])) echo "<br><small>" . htmlspecialchars($it['client_phone']) . "</small>";
                    if (!empty($it['client_email'])) echo "<br><small>" . htmlspecialchars($it['client_email']) . "</small>";
                    echo "</div></div></div>";

                    echo "<table><thead><tr><th style='width: 100px; text-align: center;'>Image</th><th style='width: 50px; text-align: center;'>S/N</th><th>Item Details</th><th style='width: 60px; text-align: center;'>Quantity</th></tr></thead><tbody>";
                }
                echo "<tr>";
                echo "<td style='text-align: center; vertical-align: middle;'>";
                if ($it['image_path']) {
                    echo "<div style='width: 80px; height: 80px; margin: 0 auto; display: flex; align-items: center; justify-content: center;'>";
                    echo "<img src='../" . htmlspecialchars($it['image_path']) . "' style='max-width: 100%; max-height: 100%; object-fit: contain;' />";
                    echo "</div>";
                } else {
                    echo "<span style='color:#aaa; font-style:italic;'>-</span>";
                }
                echo "</td>";
                echo "<td style='text-align: center;'>" . htmlspecialchars($it['line_item_number'] ?? '-') . "</td>";
                echo "<td><strong style='font-size: 14px; display:block; margin-bottom: 4px;'>" . htmlspecialchars($it['title']) . "</strong>" . nl2br(htmlspecialchars($it['description']));
                if (!empty($it['manufacturer_url'])) {
                    echo "<br><a href='" . htmlspecialchars($it['manufacturer_url']) . "' style='color: #1a3a6b; font-size: 10px; text-decoration: none;' target='_blank'><i class='fas fa-link'></i> Manufacturer Detail Site</a>";
                }
                echo "</td>";
                echo "<td style='text-align: center; font-size: 14px; font-weight: bold;'>" . $it['quantity'] . "</td>";
                echo "</tr>";
            }
            if (!empty($items)) echo "</tbody></table>";
        }
        ?>
    </div>
</body>
</html>
