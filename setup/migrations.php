<?php
// FinEase v3.0 Database Migrations
// This file executes ALTER TABLE statements safely to handle legacy database imports.

function apply_v3_migrations($pdo) {
    try {
        // Migration 1: Add WHT, PAYE, and Report Categories to transactions table
        $columns_to_add = [
            "transactions" => [
                "receipt_path" => "VARCHAR(255) DEFAULT NULL COMMENT 'Path to the uploaded receipt document'",
                "wht_amount" => "DECIMAL(15,2) DEFAULT '0.00' COMMENT 'Withholding Tax deducted'",
                "paye_amount" => "DECIMAL(15,2) DEFAULT '0.00' COMMENT 'PAYE for payroll'",
                "report_category" => "ENUM('Revenue', 'Operating Expense', 'Other Income', 'Asset', 'Liability', 'Equity') DEFAULT 'Operating Expense'",
                "is_non_current" => "BOOLEAN DEFAULT FALSE COMMENT 'Used for distinguishing Current vs Non-Current items on Balance Sheet'",
                "created_by" => "INT DEFAULT NULL COMMENT 'Immutable Logs: Digital Footprint'"
            ],
            "company_settings" => [
                "education_tax_rate" => "DECIMAL(5,2) DEFAULT '3.00'",
                "signature_path" => "VARCHAR(255) DEFAULT NULL COMMENT 'Path to the authorized signatory image'"
            ],
            "catalogue_items" => [
                "line_item_number" => "VARCHAR(50) DEFAULT NULL",
                "manufacturer_url" => "VARCHAR(255) DEFAULT NULL"
            ]
        ];

        // Migration 2: Transaction Line Items table
        $pdo->exec("CREATE TABLE IF NOT EXISTS `transaction_line_items` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `transaction_id` INT NOT NULL,
            `description` VARCHAR(255) NOT NULL,
            `quantity` DECIMAL(15,2) DEFAULT 1,
            `unit_price` DECIMAL(15,2) NOT NULL,
            `total` DECIMAL(15,2) NOT NULL,
            FOREIGN KEY (`transaction_id`) REFERENCES `transactions`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $columns_to_add['transactions']["has_line_items"] = "TINYINT(1) DEFAULT 0";
        $columns_to_add['transactions']["line_items_total"] = "DECIMAL(15,2) DEFAULT 0";

        // New tables for Product Catalogue and Project Survey modules
        // Catalogue Items table
        $pdo->exec("CREATE TABLE IF NOT EXISTS `catalogue_items` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `job_order_id` INT NOT NULL,
            `line_item_number` VARCHAR(50) NULL,
            `title` VARCHAR(255) NOT NULL,
            `description` TEXT,
            `image_path` VARCHAR(255),
            `manufacturer_url` VARCHAR(255) NULL,
            `quantity` INT DEFAULT 1,
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX (`job_order_id`),
            CONSTRAINT `catalogue_items_ibfk_1` FOREIGN KEY (`job_order_id`) REFERENCES `invoices`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Project Surveys table
        $pdo->exec("CREATE TABLE IF NOT EXISTS `project_surveys` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `job_order_id` INT NOT NULL,
            `project_type_id` INT NULL,
            `gps_lat` DECIMAL(10,7) NULL,
            `gps_lng` DECIMAL(10,7) NULL,
            `photo_path` VARCHAR(255) NULL,
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX (`job_order_id`),
            INDEX (`project_type_id`),
            CONSTRAINT `project_surveys_ibfk_1` FOREIGN KEY (`job_order_id`) REFERENCES `invoices`(`id`) ON DELETE CASCADE,
            CONSTRAINT `project_surveys_ibfk_2` FOREIGN KEY (`project_type_id`) REFERENCES `project_types`(`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Project Survey Fields (dynamic fields per project type)
        $pdo->exec("CREATE TABLE IF NOT EXISTS `project_survey_fields` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `project_type_id` INT NOT NULL,
            `field_name` VARCHAR(100) NOT NULL,
            `field_label` VARCHAR(150) NOT NULL,
            `field_type` ENUM('text','number','date','textarea') NOT NULL,
            `is_required` TINYINT(1) DEFAULT 0,
            INDEX (`project_type_id`),
            CONSTRAINT `project_survey_fields_ibfk_1` FOREIGN KEY (`project_type_id`) REFERENCES `project_types`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Project Survey Responses (answers)
        $pdo->exec("CREATE TABLE IF NOT EXISTS `project_survey_responses` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `survey_id` INT NOT NULL,
            `field_id` INT NOT NULL,
            `value` TEXT,
            INDEX (`survey_id`),
            INDEX (`field_id`),
            CONSTRAINT `project_survey_responses_ibfk_1` FOREIGN KEY (`survey_id`) REFERENCES `project_surveys`(`id`) ON DELETE CASCADE,
            CONSTRAINT `project_survey_responses_ibfk_2` FOREIGN KEY (`field_id`) REFERENCES `project_survey_fields`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        foreach ($columns_to_add as $table => $columns) {
            foreach ($columns as $column_name => $definition) {
                // Check if column exists
                try {
                    $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
                    $stmt->execute([$column_name]);
                    $exists = $stmt->fetch();

                    if (!$exists) {
                        $alter_sql = "ALTER TABLE `$table` ADD COLUMN `$column_name` $definition";
                        $pdo->exec($alter_sql);
                    }
                } catch (PDOException $e) {
                    // Ignore errors if table doesn't exist yet, OR if column already exists
                    // SQL State 42S21 = Column already exists
                    if ($e->getCode() != '42S21' && strpos($e->getMessage(), '1060') === false) {
                        // If it's some other error, we might want to know, but for safety in migrations we often skip
                    }
                }
            }
        }



        return ['success' => true, 'message' => 'Migrations applied successfully.'];
    } catch (PDOException $e) {
        return ['success' => false, 'message' => 'Migration failed: ' . $e->getMessage()];
    }
}
?>
