-- FinEase v3.0 Database Schema (Based on provided backup baseline)
-- Setup script for fresh installation

SET FOREIGN_KEY_CHECKS=0;

DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','temp','viewer') DEFAULT 'viewer',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `company_settings`;
CREATE TABLE `company_settings` (
  `id` int NOT NULL AUTO_INCREMENT,
  `company_name` varchar(255) NOT NULL,
  `address` text,
  `contact_info` text,
  `company_email` varchar(255) DEFAULT NULL,
  `website` varchar(255) DEFAULT NULL,
  `logo_path` varchar(255) DEFAULT NULL,
  `signature_path` varchar(255) DEFAULT NULL,
  `country` varchar(100) DEFAULT 'Nigeria',
  `currency` varchar(10) DEFAULT '₦',
  `vat_enabled` tinyint(1) DEFAULT '0',
  `vat_rate` decimal(5,2) DEFAULT '7.50',
  `vat_threshold` decimal(15,2) DEFAULT '25000000.00',
  `tithe_rate` decimal(5,2) DEFAULT '10.00',
  `invoice_terms` text,
  `invoice_validity` varchar(100) DEFAULT NULL,
  `payment_instructions` text,
  `invoice_bank_name` varchar(100) DEFAULT NULL,
  `invoice_bank_account_number` varchar(50) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `education_tax_rate` decimal(5,2) DEFAULT '3.00',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `company_registrations`;
CREATE TABLE `company_registrations` (
  `id` int NOT NULL AUTO_INCREMENT,
  `company_id` int NOT NULL,
  `reg_name` varchar(100) NOT NULL,
  `reg_number` varchar(100) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `company_id` (`company_id`),
  CONSTRAINT `company_registrations_ibfk_1` FOREIGN KEY (`company_id`) REFERENCES `company_settings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `project_types`;
CREATE TABLE `project_types` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `code` varchar(10) NOT NULL,
  `description` text,
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `clients`;
CREATE TABLE `clients` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `address` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `bank_accounts`;
CREATE TABLE `bank_accounts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `type` enum('cash','bank','mobile_money','other') NOT NULL,
  `balance` decimal(15,2) DEFAULT '0.00',
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `invoices`;
CREATE TABLE `invoices` (
  `id` int NOT NULL AUTO_INCREMENT,
  `invoice_id` varchar(50) NOT NULL,
  `client_id` int DEFAULT NULL,
  `client_name` varchar(255) NOT NULL,
  `project_type_id` int DEFAULT NULL,
  `location` varchar(100) DEFAULT NULL,
  `id_comments` varchar(50) DEFAULT NULL,
  `service_description` text NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `expenses_amount` decimal(15,2) DEFAULT '0.00',
  `vat_amount` decimal(15,2) DEFAULT '0.00',
  `total_with_vat` decimal(15,2) DEFAULT '0.00',
  `has_line_items` tinyint(1) DEFAULT '0',
  `apply_vat` tinyint(1) DEFAULT '0',
  `line_items_total` decimal(15,2) DEFAULT '0.00',
  `date` date NOT NULL,
  `notes` text,
  `currency` varchar(3) DEFAULT 'NGN',
  `exchange_rate` decimal(15,2) DEFAULT '1.00',
  `status` enum('open','completed') DEFAULT 'open',
  `payment_status` enum('unpaid','partly_paid','fully_paid') DEFAULT 'unpaid',
  `tithe_paid` tinyint(1) DEFAULT '0',
  `tithe_amount` decimal(15,2) DEFAULT '0.00',
  `tithe_date` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `invoice_id` (`invoice_id`),
  KEY `client_id` (`client_id`),
  KEY `project_type_id` (`project_type_id`),
  CONSTRAINT `invoices_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE SET NULL,
  CONSTRAINT `invoices_ibfk_2` FOREIGN KEY (`project_type_id`) REFERENCES `project_types` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `job_order_line_items`;
CREATE TABLE `job_order_line_items` (
  `id` int NOT NULL AUTO_INCREMENT,
  `job_order_id` int NOT NULL,
  `description` varchar(255) NOT NULL,
  `unit_price` decimal(15,2) NOT NULL,
  `quantity` decimal(10,2) NOT NULL DEFAULT '1.00',
  `total` decimal(15,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `job_order_id` (`job_order_id`),
  CONSTRAINT `job_order_line_items_ibfk_1` FOREIGN KEY (`job_order_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `transactions`;
CREATE TABLE `transactions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `type` enum('inflow','outflow') NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `description` text NOT NULL,
  `seller_details` varchar(255) DEFAULT NULL,
  `receipt_number` varchar(100) DEFAULT NULL,
  `receipt_file` varchar(255) DEFAULT NULL,
  `receipt_path` varchar(255) DEFAULT NULL COMMENT 'Path to the uploaded receipt document',
  `category` enum('internal','invoice_linked') NOT NULL,
  `invoice_id` int DEFAULT NULL,
  `bank_account_id` int DEFAULT NULL,
  `is_recurring` tinyint(1) DEFAULT '0',
  `recurring_frequency` enum('monthly','quarterly','yearly') DEFAULT NULL,
  `transaction_date` date NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `wht_amount` decimal(15,2) DEFAULT '0.00' COMMENT 'Withholding Tax deducted',
  `paye_amount` decimal(15,2) DEFAULT '0.00' COMMENT 'PAYE for payroll',
  `report_category` enum('Revenue','Operating Expense','Other Income','Asset','Liability','Equity') DEFAULT 'Operating Expense',
  `is_non_current` tinyint(1) DEFAULT '0' COMMENT 'Used for distinguishing Current vs Non-Current items on Balance Sheet',
  `created_by` int DEFAULT NULL COMMENT 'Immutable Logs: Digital Footprint',
  `has_line_items` tinyint(1) DEFAULT '0',
  `line_items_total` decimal(15,2) DEFAULT '0.00',
  PRIMARY KEY (`id`),
  KEY `invoice_id` (`invoice_id`),
  KEY `bank_account_id` (`bank_account_id`),
  CONSTRAINT `transactions_ibfk_1` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE SET NULL,
  CONSTRAINT `transactions_ibfk_2` FOREIGN KEY (`bank_account_id`) REFERENCES `bank_accounts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `transaction_line_items`;
CREATE TABLE `transaction_line_items` (
  `id` int NOT NULL AUTO_INCREMENT,
  `transaction_id` int NOT NULL,
  `description` varchar(255) NOT NULL,
  `quantity` decimal(15,2) DEFAULT '1.00',
  `unit_price` decimal(15,2) NOT NULL,
  `total` decimal(15,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `transaction_id` (`transaction_id`),
  CONSTRAINT `transaction_line_items_ibfk_1` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `tithes`;
CREATE TABLE `tithes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `invoice_id` int NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `status` enum('owed','paid') DEFAULT 'owed',
  `date_generated` date NOT NULL,
  `date_paid` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `invoice_id` (`invoice_id`),
  CONSTRAINT `tithes_ibfk_1` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `vat_records`;
CREATE TABLE `vat_records` (
  `id` int NOT NULL AUTO_INCREMENT,
  `invoice_id` int DEFAULT NULL,
  `vat_amount` decimal(15,2) NOT NULL,
  `vat_rate` decimal(5,2) NOT NULL,
  `period_month` varchar(7) DEFAULT NULL,
  `status` enum('collected','paid','pending') DEFAULT 'collected',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `invoice_id` (`invoice_id`),
  CONSTRAINT `vat_records_ibfk_1` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `generated_invoices`;
CREATE TABLE `generated_invoices` (
  `id` int NOT NULL AUTO_INCREMENT,
  `invoice_number` varchar(50) NOT NULL,
  `job_order_id` int NOT NULL,
  `client_id` int DEFAULT NULL,
  `client_name` varchar(255) NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `vat_amount` decimal(15,2) DEFAULT '0.00',
  `total_amount` decimal(15,2) NOT NULL,
  `document_type` enum('invoice','receipt') DEFAULT 'invoice',
  `status` enum('sent','paid','overdue') DEFAULT 'sent',
  `due_date` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `invoice_number` (`invoice_number`),
  KEY `job_order_id` (`job_order_id`),
  KEY `client_id` (`client_id`),
  CONSTRAINT `generated_invoices_ibfk_1` FOREIGN KEY (`job_order_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE,
  CONSTRAINT `generated_invoices_ibfk_2` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/* New tables for Product Catalogue and Project Survey modules */

DROP TABLE IF EXISTS `catalogue_items`;
CREATE TABLE `catalogue_items` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `project_surveys`;
CREATE TABLE `project_surveys` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `project_survey_fields`;
CREATE TABLE `project_survey_fields` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `project_type_id` INT NOT NULL,
    `field_name` VARCHAR(100) NOT NULL,
    `field_label` VARCHAR(150) NOT NULL,
    `field_type` ENUM('text','number','date','textarea') NOT NULL,
    `is_required` TINYINT(1) DEFAULT 0,
    INDEX (`project_type_id`),
    CONSTRAINT `project_survey_fields_ibfk_1` FOREIGN KEY (`project_type_id`) REFERENCES `project_types`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `project_survey_responses`;
CREATE TABLE `project_survey_responses` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `survey_id` INT NOT NULL,
    `field_id` INT NOT NULL,
    `value` TEXT,
    INDEX (`survey_id`),
    INDEX (`field_id`),
    CONSTRAINT `project_survey_responses_ibfk_1` FOREIGN KEY (`survey_id`) REFERENCES `project_surveys`(`id`) ON DELETE CASCADE,
    CONSTRAINT `project_survey_responses_ibfk_2` FOREIGN KEY (`field_id`) REFERENCES `project_survey_fields`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS=1;
