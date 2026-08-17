<?php
// includes/financial_engine.php
require_once __DIR__ . '/../config/config.php';

// Engine handling Tithe automated creation
function evaluate_tithe_trigger($pdo, $job_order_id) {
    try {
        // 1. Check if Job is Completed & Fully Paid
        $stmt = $pdo->prepare("SELECT status, payment_status, total_with_vat FROM invoices WHERE id = ?");
        $stmt->execute([$job_order_id]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$job || strtolower(trim($job['status'])) !== 'completed' || strtolower(trim($job['payment_status'])) !== 'fully_paid') {
            return false; // Conditions not met
        }

        // 2. Check if a Tithe record already exists to prevent duplicates
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM tithes WHERE invoice_id = ?");
        $stmt->execute([$job_order_id]);
        if ($stmt->fetchColumn() > 0) {
            return false; // Already triggered earlier
        }

        // 3. Calculate "Net Profit" (Total Inflows - Total Outflows linked to this job)
        $stmt = $pdo->prepare("
            SELECT 
                COALESCE(SUM(CASE WHEN type='inflow' THEN amount ELSE 0 END), 0) as total_in,
                COALESCE(SUM(CASE WHEN type='outflow' THEN amount ELSE 0 END), 0) as total_out
            FROM transactions 
            WHERE invoice_id = ?
        ");
        $stmt->execute([$job_order_id]);
        $flows = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $net_profit = floatval($flows['total_in']) - floatval($flows['total_out']);

        // Only tithe if profit is > 0
        if ($net_profit > 0) {
            // Get rate
            $settingsStmt = $pdo->query("SELECT tithe_rate FROM company_settings LIMIT 1");
            $rate = floatval($settingsStmt->fetchColumn() ?: 10.00);

            $tithe_amount = $net_profit * ($rate / 100);

            // Insert pending tithe
            $insert = $pdo->prepare("INSERT INTO tithes (invoice_id, amount, status, date_generated) VALUES (?, ?, 'owed', CURDATE())");
            $insert->execute([$job_order_id, $tithe_amount]);
            
            return ['status' => true, 'amount' => $tithe_amount];
        }

        return false;

    } catch (PDOException $e) {
        error_log("Tithe Engine Error: " . $e->getMessage());
        return false;
    }
}
?>
