<?php
class CreatePaymentTables {
    public function up() {
        $sql = [];
        
        // Payment gateways table
        $sql[] = "CREATE TABLE `payment_gateways` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED DEFAULT NULL,
            `name` varchar(100) NOT NULL,
            `provider` enum('paystack','flutterwave','stripe','paypal','manual') NOT NULL,
            `mode` enum('test','live') DEFAULT 'test',
            `public_key` varchar(500) DEFAULT NULL,
            `secret_key` varchar(500) DEFAULT NULL,
            `encryption_key` varchar(500) DEFAULT NULL,
            `webhook_url` varchar(500) DEFAULT NULL,
            `webhook_secret` varchar(255) DEFAULT NULL,
            `is_active` tinyint(1) DEFAULT 1,
            `is_default` tinyint(1) DEFAULT 0,
            `transaction_fee_percentage` decimal(5,2) DEFAULT 0.00,
            `transaction_fee_fixed` decimal(10,2) DEFAULT 0.00,
            `settlement_bank` varchar(100) DEFAULT NULL,
            `account_number` varchar(50) DEFAULT NULL,
            `account_name` varchar(255) DEFAULT NULL,
            `supported_currencies` json DEFAULT NULL,
            `config` json DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_school_provider` (`school_id`, `provider`),
            KEY `idx_active_default` (`is_active`, `is_default`)
        )";
        
        // Payment transactions table
        $sql[] = "CREATE TABLE `payment_transactions` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `invoice_id` int(10) UNSIGNED DEFAULT NULL,
            `student_id` int(10) UNSIGNED DEFAULT NULL,
            `parent_id` int(10) UNSIGNED DEFAULT NULL,
            `payment_gateway_id` int(10) UNSIGNED NOT NULL,
            `transaction_reference` varchar(255) NOT NULL,
            `gateway_transaction_id` varchar(255) DEFAULT NULL,
            `amount` decimal(10,2) NOT NULL,
            `currency` varchar(3) DEFAULT 'NGN',
            `gateway_fee` decimal(10,2) DEFAULT 0.00,
            `net_amount` decimal(10,2) NOT NULL,
            `status` enum('initiated','pending','success','failed','cancelled','refunded') DEFAULT 'initiated',
            `payment_method` varchar(50) DEFAULT NULL,
            `card_last_four` varchar(4) DEFAULT NULL,
            `bank_name` varchar(100) DEFAULT NULL,
            `account_number` varchar(50) DEFAULT NULL,
            `payer_name` varchar(255) DEFAULT NULL,
            `payer_email` varchar(255) DEFAULT NULL,
            `payer_phone` varchar(20) DEFAULT NULL,
            `metadata` json DEFAULT NULL,
            `gateway_response` json DEFAULT NULL,
            `verified_at` timestamp NULL DEFAULT NULL,
            `refunded_at` timestamp NULL DEFAULT NULL,
            `refund_reason` text DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `transaction_reference` (`transaction_reference`),
            UNIQUE KEY `gateway_transaction_id` (`gateway_transaction_id`),
            KEY `idx_school_status` (`school_id`, `status`),
            KEY `idx_invoice` (`invoice_id`),
            KEY `idx_student` (`student_id`),
            KEY `idx_parent` (`parent_id`),
            KEY `idx_created` (`created_at`)
        )";
        
        // Batch payments table
        $sql[] = "CREATE TABLE `batch_payments` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `parent_id` int(10) UNSIGNED NOT NULL,
            `student_id` int(10) UNSIGNED NOT NULL,
            `batch_reference` varchar(100) NOT NULL,
            `total_amount` decimal(10,2) NOT NULL,
            `status` enum('pending','processing','completed','failed','cancelled') DEFAULT 'pending',
            `payment_method` varchar(50) DEFAULT NULL,
            `transaction_id` int(10) UNSIGNED DEFAULT NULL,
            `completed_at` timestamp NULL DEFAULT NULL,
            `metadata` json DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `batch_reference` (`batch_reference`),
            KEY `idx_school_parent` (`school_id`, `parent_id`),
            KEY `idx_student` (`student_id`),
            KEY `idx_status` (`status`)
        )";
        
        // Execute all SQL
        foreach ($sql as $query) {
            Database::getInstance()->query($query);
        }
    }
}