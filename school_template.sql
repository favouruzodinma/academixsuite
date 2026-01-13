-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: localhost
-- Generation Time: Jan 12, 2026 at 03:49 PM
-- Server version: 10.4.28-MariaDB
-- PHP Version: 8.2.4

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `school_23`
--

-- --------------------------------------------------------

--
-- Table structure for table `academic_terms`
--

CREATE TABLE `academic_terms` (
  `id` int(10) UNSIGNED NOT NULL,
  `school_id` int(10) UNSIGNED NOT NULL,
  `academic_year_id` int(10) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `is_default` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `academic_years`
--

CREATE TABLE `academic_years` (
  `id` int(10) UNSIGNED NOT NULL,
  `school_id` int(10) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `is_default` tinyint(1) DEFAULT 0,
  `status` enum('upcoming','active','completed') DEFAULT 'upcoming',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `announcements`
--

CREATE TABLE `announcements` (
  `id` int(10) UNSIGNED NOT NULL,
  `school_id` int(10) UNSIGNED NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `target` enum('all','students','teachers','parents','class','section') DEFAULT 'all',
  `class_id` int(10) UNSIGNED DEFAULT NULL,
  `section_id` int(10) UNSIGNED DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `is_published` tinyint(1) DEFAULT 1,
  `created_by` int(10) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `api_keys`
--

CREATE TABLE `api_keys` (
  `id` int(10) UNSIGNED NOT NULL,
  `school_id` int(10) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `api_key` varchar(100) NOT NULL,
  `api_secret` varchar(100) DEFAULT NULL,
  `permissions` text DEFAULT NULL COMMENT 'JSON encoded permissions',
  `rate_limit_per_minute` int(10) DEFAULT 60,
  `rate_limit_per_hour` int(10) DEFAULT 1000,
  `rate_limit_per_day` int(10) DEFAULT 10000,
  `allowed_ips` text DEFAULT NULL COMMENT 'JSON array of allowed IPs',
  `allowed_origins` text DEFAULT NULL COMMENT 'JSON array of allowed origins',
  `expires_at` timestamp NULL DEFAULT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `api_logs`
--

CREATE TABLE `api_logs` (
  `id` int(10) UNSIGNED NOT NULL,
  `school_id` int(10) UNSIGNED DEFAULT NULL,
  `api_key_id` int(10) UNSIGNED DEFAULT NULL,
  `endpoint` varchar(500) NOT NULL,
  `method` varchar(10) NOT NULL,
  `request_body` text DEFAULT NULL,
  `response_body` text DEFAULT NULL,
  `status_code` int(3) DEFAULT NULL,
  `response_time` decimal(10,4) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `is_success` tinyint(1) DEFAULT 0,
  `error_message` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `api_usage`
--

CREATE TABLE `api_usage` (
  `id` int(10) UNSIGNED NOT NULL,
  `school_id` int(10) UNSIGNED NOT NULL,
  `api_key_id` int(10) UNSIGNED DEFAULT NULL,
  `endpoint` varchar(500) NOT NULL,
  `method` varchar(10) NOT NULL,
  `request_count` int(10) DEFAULT 1,
  `total_response_time` decimal(12,4) DEFAULT 0.0000,
  `failed_count` int(10) DEFAULT 0,
  `period` enum('minute','hour','day','month') DEFAULT 'day',
  `period_start` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `period_end` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `attendance`
--

CREATE TABLE `attendance` (
  `id` int(10) UNSIGNED NOT NULL,
  `school_id` int(10) UNSIGNED NOT NULL,
  `student_id` int(10) UNSIGNED NOT NULL,
  `class_id` int(10) UNSIGNED NOT NULL,
  `date` date NOT NULL,
  `status` enum('present','absent','late','half_day','holiday','sunday') NOT NULL,
  `remark` varchar(255) DEFAULT NULL,
  `marked_by` int(10) UNSIGNED DEFAULT NULL,
  `session` enum('morning','afternoon','full_day') DEFAULT 'full_day',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

CREATE TABLE `audit_logs` (
  `id` int(10) UNSIGNED NOT NULL,
  `school_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED DEFAULT NULL,
  `user_type` varchar(50) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `entity_type` varchar(100) DEFAULT NULL,
  `entity_id` int(10) UNSIGNED DEFAULT NULL,
  `old_values` text DEFAULT NULL COMMENT 'JSON encoded old values',
  `new_values` text DEFAULT NULL COMMENT 'JSON encoded new values',
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `url` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `backup_history`
--

CREATE TABLE `backup_history` (
  `id` int(10) UNSIGNED NOT NULL,
  `school_id` int(10) UNSIGNED NOT NULL,
  `backup_type` enum('full','incremental','differential','schema_only') DEFAULT 'full',
  `storage_type` enum('local','s3','ftp','google_drive') DEFAULT 'local',
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(500) DEFAULT NULL,
  `file_size` bigint(20) DEFAULT NULL,
  `database_size` bigint(20) DEFAULT NULL,
  `table_count` int(10) DEFAULT NULL,
  `status` enum('pending','in_progress','completed','failed','cancelled') DEFAULT 'pending',
  `error_message` text DEFAULT NULL,
  `started_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `retention_days` int(10) DEFAULT 30,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `billing_history`
--

CREATE TABLE `billing_history` (
  `id` int(10) UNSIGNED NOT NULL,
  `school_id` int(10) UNSIGNED NOT NULL,
  `subscription_id` int(10) UNSIGNED DEFAULT NULL,
  `invoice_number` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `tax_amount` decimal(10,2) DEFAULT 0.00,
  `total_amount` decimal(10,2) NOT NULL,
  `currency` varchar(3) DEFAULT 'NGN',
  `payment_method` varchar(50) DEFAULT NULL,
  `payment_status` enum('pending','paid','failed','refunded') DEFAULT 'pending',
  `payment_date` timestamp NULL DEFAULT NULL,
  `due_date` date NOT NULL,
  `paid_at` timestamp NULL DEFAULT NULL,
  `transaction_id` varchar(255) DEFAULT NULL,
  `payment_gateway` varchar(50) DEFAULT NULL,
  `gateway_response` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `classes`
--

CREATE TABLE `classes` (
  `id` int(10) UNSIGNED NOT NULL,
  `school_id` int(10) UNSIGNED NOT NULL,
  `campus_id` int(10) UNSIGNED DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  `code` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  `grade_level` varchar(50) DEFAULT NULL,
  `class_teacher_id` int(10) UNSIGNED DEFAULT NULL,
  `capacity` int(10) UNSIGNED DEFAULT 40,
  `room_number` varchar(50) DEFAULT NULL,
  `academic_year_id` int(10) UNSIGNED NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `class_subjects`
--

CREATE TABLE `class_subjects` (
  `id` int(10) UNSIGNED NOT NULL,
  `class_id` int(10) UNSIGNED NOT NULL,
  `subject_id` int(10) UNSIGNED NOT NULL,
  `teacher_id` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `email_templates`
--

CREATE TABLE `email_templates` (
  `id` int(10) UNSIGNED NOT NULL,
  `school_id` int(10) UNSIGNED NOT NULL,
  `template_key` varchar(100) NOT NULL,
  `name` varchar(255) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `body_html` text NOT NULL,
  `body_text` text DEFAULT NULL,
  `variables` text DEFAULT NULL COMMENT 'JSON array of available variables',
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `events`
--

CREATE TABLE `events` (
  `id` int(10) UNSIGNED NOT NULL,
  `school_id` int(10) UNSIGNED NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `type` enum('holiday','exam','meeting','celebration','sports','other') DEFAULT 'other',
  `start_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `venue` varchar(255) DEFAULT NULL,
  `is_public` tinyint(1) DEFAULT 1,
  `created_by` int(10) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `exams`
--

CREATE TABLE `exams` (
  `id` int(10) UNSIGNED NOT NULL,
  `school_id` int(10) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `academic_year_id` int(10) UNSIGNED NOT NULL,
  `academic_term_id` int(10) UNSIGNED NOT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `is_published` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `exam_grades`
--

CREATE TABLE `exam_grades` (
  `id` int(10) UNSIGNED NOT NULL,
  `school_id` int(10) UNSIGNED NOT NULL,
  `exam_id` int(10) UNSIGNED NOT NULL,
  `student_id` int(10) UNSIGNED NOT NULL,
  `subject_id` int(10) UNSIGNED NOT NULL,
  `class_id` int(10) UNSIGNED NOT NULL,
  `marks_obtained` decimal(5,2) DEFAULT NULL,
  `total_marks` decimal(5,2) NOT NULL,
  `grade` varchar(5) DEFAULT NULL,
  `remarks` varchar(255) DEFAULT NULL,
  `entered_by` int(10) UNSIGNED DEFAULT NULL,
  `entered_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_published` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `fee_categories`
--

CREATE TABLE `fee_categories` (
  `id` int(10) UNSIGNED NOT NULL,
  `school_id` int(10) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `fee_structures`
--

CREATE TABLE `fee_structures` (
  `id` int(10) UNSIGNED NOT NULL,
  `school_id` int(10) UNSIGNED NOT NULL,
  `academic_year_id` int(10) UNSIGNED NOT NULL,
  `academic_term_id` int(10) UNSIGNED NOT NULL,
  `class_id` int(10) UNSIGNED NOT NULL,
  `fee_category_id` int(10) UNSIGNED NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `due_date` date DEFAULT NULL,
  `late_fee` decimal(10,2) DEFAULT 0.00,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `file_storage`
--

CREATE TABLE `file_storage` (
  `id` int(10) UNSIGNED NOT NULL,
  `school_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED DEFAULT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_type` varchar(100) NOT NULL,
  `file_size` bigint(20) NOT NULL,
  `mime_type` varchar(100) DEFAULT NULL,
  `storage_type` enum('local','s3','cloudinary','wasabi') DEFAULT 'local',
  `bucket_name` varchar(255) DEFAULT NULL,
  `object_key` varchar(500) DEFAULT NULL,
  `is_public` tinyint(1) DEFAULT 0,
  `access_hash` varchar(100) DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `download_count` int(10) DEFAULT 0,
  `last_downloaded` timestamp NULL DEFAULT NULL,
  `metadata` text DEFAULT NULL COMMENT 'JSON encoded metadata',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `guardians`
--

CREATE TABLE `guardians` (
  `id` int(10) UNSIGNED NOT NULL,
  `school_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `student_id` int(10) UNSIGNED NOT NULL,
  `relationship` enum('father','mother','brother','sister','uncle','aunt','grandfather','grandmother','guardian','other') NOT NULL,
  `is_primary` tinyint(1) DEFAULT 0,
  `can_pickup` tinyint(1) DEFAULT 1,
  `emergency_contact` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `homework`
--

CREATE TABLE `homework` (
  `id` int(10) UNSIGNED NOT NULL,
  `school_id` int(10) UNSIGNED NOT NULL,
  `class_id` int(10) UNSIGNED NOT NULL,
  `section_id` int(10) UNSIGNED DEFAULT NULL,
  `subject_id` int(10) UNSIGNED NOT NULL,
  `teacher_id` int(10) UNSIGNED NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `attachment` varchar(500) DEFAULT NULL,
  `due_date` date NOT NULL,
  `submission_type` enum('online','offline') DEFAULT 'offline',
  `max_marks` decimal(5,2) DEFAULT NULL,
  `is_published` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `invoices`
--

CREATE TABLE `invoices` (
  `id` int(10) UNSIGNED NOT NULL,
  `school_id` int(10) UNSIGNED NOT NULL,
  `invoice_number` varchar(100) NOT NULL,
  `student_id` int(10) UNSIGNED NOT NULL,
  `academic_year_id` int(10) UNSIGNED NOT NULL,
  `academic_term_id` int(10) UNSIGNED NOT NULL,
  `class_id` int(10) UNSIGNED NOT NULL,
  `issue_date` date NOT NULL,
  `due_date` date NOT NULL,
  `total_amount` decimal(10,2) NOT NULL,
  `discount` decimal(10,2) DEFAULT 0.00,
  `late_fee` decimal(10,2) DEFAULT 0.00,
  `paid_amount` decimal(10,2) DEFAULT 0.00,
  `balance_amount` decimal(10,2) NOT NULL,
  `status` enum('draft','pending','partial','paid','overdue','cancelled') DEFAULT 'pending',
  `payment_method` varchar(50) DEFAULT NULL,
  `paid_at` timestamp NULL DEFAULT NULL,
  `transaction_id` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `invoices_v2`
--

CREATE TABLE `invoices_v2` (
  `id` int(10) UNSIGNED NOT NULL,
  `school_id` int(10) UNSIGNED NOT NULL,
  `invoice_number` varchar(100) NOT NULL,
  `billing_history_id` int(10) UNSIGNED DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `tax` decimal(10,2) DEFAULT 0.00,
  `discount` decimal(10,2) DEFAULT 0.00,
  `total_amount` decimal(10,2) NOT NULL,
  `currency` varchar(3) DEFAULT 'NGN',
  `status` enum('draft','sent','viewed','paid','overdue','cancelled') DEFAULT 'draft',
  `due_date` date NOT NULL,
  `paid_date` timestamp NULL DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `terms` text DEFAULT NULL,
  `pdf_path` varchar(500) DEFAULT NULL,
  `sent_at` timestamp NULL DEFAULT NULL,
  `viewed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `invoice_items`
--

CREATE TABLE `invoice_items` (
  `id` int(10) UNSIGNED NOT NULL,
  `invoice_id` int(10) UNSIGNED NOT NULL,
  `fee_category_id` int(10) UNSIGNED NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `login_attempts`
--

CREATE TABLE `login_attempts` (
  `id` int(10) UNSIGNED NOT NULL,
  `school_id` int(10) UNSIGNED DEFAULT NULL,
  `username` varchar(255) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `user_agent` text DEFAULT NULL,
  `success` tinyint(1) DEFAULT 0,
  `failed_reason` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `maintenance_logs`
--

CREATE TABLE `maintenance_logs` (
  `id` int(10) UNSIGNED NOT NULL,
  `school_id` int(10) UNSIGNED NOT NULL,
  `maintenance_type` enum('database_optimization','cache_clear','backup_cleanup','storage_cleanup','system_update') NOT NULL,
  `description` text NOT NULL,
  `status` enum('pending','running','completed','failed','cancelled') DEFAULT 'pending',
  `started_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `duration_seconds` int(10) DEFAULT NULL,
  `affected_records` int(10) DEFAULT NULL,
  `freed_space` bigint(20) DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  `performed_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(10) UNSIGNED NOT NULL,
  `school_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `type` enum('email','sms','push','in_app','system') DEFAULT 'in_app',
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `data` text DEFAULT NULL COMMENT 'JSON encoded data',
  `priority` enum('low','normal','high','urgent') DEFAULT 'normal',
  `is_read` tinyint(1) DEFAULT 0,
  `read_at` timestamp NULL DEFAULT NULL,
  `is_sent` tinyint(1) DEFAULT 0,
  `sent_at` timestamp NULL DEFAULT NULL,
  `delivery_status` enum('pending','sent','delivered','failed','bounced') DEFAULT 'pending',
  `failure_reason` text DEFAULT NULL,
  `scheduled_for` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `id` int(10) UNSIGNED NOT NULL,
  `school_id` int(10) UNSIGNED NOT NULL,
  `invoice_id` int(10) UNSIGNED NOT NULL,
  `payment_number` varchar(100) NOT NULL,
  `student_id` int(10) UNSIGNED NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_method` enum('cash','cheque','bank_transfer','card','mobile_money','online') NOT NULL,
  `payment_date` date NOT NULL,
  `collected_by` int(10) UNSIGNED DEFAULT NULL,
  `bank_name` varchar(255) DEFAULT NULL,
  `cheque_number` varchar(100) DEFAULT NULL,
  `transaction_id` varchar(255) DEFAULT NULL,
  `reference` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payment_methods`
--

CREATE TABLE `payment_methods` (
  `id` int(10) UNSIGNED NOT NULL,
  `school_id` int(10) UNSIGNED NOT NULL,
  `type` enum('card','bank_transfer','mobile_money','wallet') NOT NULL,
  `provider` varchar(50) DEFAULT NULL,
  `last_four` varchar(4) DEFAULT NULL,
  `exp_month` int(2) DEFAULT NULL,
  `exp_year` int(4) DEFAULT NULL,
  `is_default` tinyint(1) DEFAULT 0,
  `is_verified` tinyint(1) DEFAULT 0,
  `metadata` text DEFAULT NULL COMMENT 'JSON encoded metadata',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `performance_metrics`
--

CREATE TABLE `performance_metrics` (
  `id` int(10) UNSIGNED NOT NULL,
  `school_id` int(10) UNSIGNED NOT NULL,
  `metric_type` enum('api_response','page_load','query_time','memory_usage','cpu_usage') NOT NULL,
  `endpoint` varchar(500) DEFAULT NULL,
  `value` decimal(10,4) NOT NULL,
  `unit` varchar(20) DEFAULT NULL,
  `sample_count` int(10) DEFAULT 1,
  `min_value` decimal(10,4) DEFAULT NULL,
  `max_value` decimal(10,4) DEFAULT NULL,
  `avg_value` decimal(10,4) DEFAULT NULL,
  `p95_value` decimal(10,4) DEFAULT NULL,
  `metadata` text DEFAULT NULL COMMENT 'JSON encoded metadata',
  `recorded_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `rate_limits`
--

CREATE TABLE `rate_limits` (
  `id` int(10) UNSIGNED NOT NULL,
  `school_id` int(10) UNSIGNED NOT NULL,
  `endpoint` varchar(500) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `user_id` int(10) UNSIGNED DEFAULT NULL,
  `request_count` int(10) DEFAULT 1,
  `limit_reached` tinyint(1) DEFAULT 0,
  `first_request` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_request` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `window_reset` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `recovery_points`
--

CREATE TABLE `recovery_points` (
  `id` int(10) UNSIGNED NOT NULL,
  `school_id` int(10) UNSIGNED NOT NULL,
  `backup_id` int(10) UNSIGNED DEFAULT NULL,
  `point_name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `recovery_type` enum('full','partial','data_only','schema_only') DEFAULT 'full',
  `tables_included` text DEFAULT NULL COMMENT 'JSON array of tables',
  `status` enum('available','restoring','restored','failed') DEFAULT 'available',
  `file_path` varchar(500) DEFAULT NULL,
  `file_size` bigint(20) DEFAULT NULL,
  `checksum` varchar(64) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `restored_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

CREATE TABLE `roles` (
  `id` int(10) UNSIGNED NOT NULL,
  `school_id` int(10) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `slug` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `permissions` text DEFAULT NULL,
  `is_system` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `roles`
--

INSERT INTO `roles` (`id`, `school_id`, `name`, `slug`, `description`, `permissions`, `is_system`, `created_at`) VALUES
(1, 23, 'Super Administrator', 'super_admin', 'Has full access to all features', '[\"*\"]', 1, '2026-01-12 02:44:06'),
(2, 23, 'School Administrator', 'school_admin', 'Manages school operations', '[\"dashboard.view\", \"students.*\", \"teachers.*\", \"classes.*\", \"attendance.*\", \"exams.*\", \"fees.*\", \"reports.*\", \"settings.*\"]', 1, '2026-01-12 02:44:06'),
(3, 23, 'Teacher', 'teacher', 'Can manage classes and students', '[\"dashboard.view\", \"attendance.mark\", \"grades.enter\", \"homework.*\", \"students.view\"]', 1, '2026-01-12 02:44:06'),
(4, 23, 'Student', 'student', 'Can view their own information', '[\"dashboard.view\", \"timetable.view\", \"grades.view\", \"homework.view\"]', 1, '2026-01-12 02:44:06'),
(5, 23, 'Parent', 'parent', 'Can view child information', '[\"dashboard.view\", \"children.view\", \"attendance.view\", \"fees.view\"]', 1, '2026-01-12 02:44:06'),
(6, 23, 'Accountant', 'accountant', 'Manages financial operations', '[\"dashboard.view\", \"fees.*\", \"payments.*\", \"invoices.*\", \"reports.financial\"]', 1, '2026-01-12 02:44:06'),
(7, 23, 'Librarian', 'librarian', 'Manages library operations', '[\"dashboard.view\", \"library.*\"]', 1, '2026-01-12 02:44:06');

-- --------------------------------------------------------

--
-- Table structure for table `sections`
--

CREATE TABLE `sections` (
  `id` int(10) UNSIGNED NOT NULL,
  `school_id` int(10) UNSIGNED NOT NULL,
  `class_id` int(10) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `code` varchar(50) NOT NULL,
  `room_number` varchar(50) DEFAULT NULL,
  `capacity` int(10) UNSIGNED DEFAULT 40,
  `class_teacher_id` int(10) UNSIGNED DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `security_logs`
--

CREATE TABLE `security_logs` (
  `id` int(10) UNSIGNED NOT NULL,
  `school_id` int(10) UNSIGNED DEFAULT NULL,
  `event_type` enum('login_attempt','failed_login','password_change','session_start','session_end','suspicious_activity','blocked_ip') NOT NULL,
  `severity` enum('low','medium','high','critical') DEFAULT 'low',
  `user_id` int(10) UNSIGNED DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `details` text DEFAULT NULL,
  `resolved` tinyint(1) DEFAULT 0,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `resolved_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `settings`
--

CREATE TABLE `settings` (
  `id` int(10) UNSIGNED NOT NULL,
  `school_id` int(10) UNSIGNED NOT NULL,
  `key` varchar(100) NOT NULL,
  `value` text DEFAULT NULL,
  `type` varchar(50) DEFAULT 'string',
  `category` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `settings`
--

INSERT INTO `settings` (`id`, `school_id`, `key`, `value`, `type`, `category`, `created_at`, `updated_at`) VALUES
(1, 23, 'school_name', 'New School', 'string', 'general', '2026-01-12 02:44:06', '2026-01-12 02:44:06'),
(2, 23, 'school_email', '', 'string', 'general', '2026-01-12 02:44:06', '2026-01-12 02:44:06'),
(3, 23, 'school_phone', '', 'string', 'general', '2026-01-12 02:44:06', '2026-01-12 02:44:06'),
(4, 23, 'school_address', '', 'string', 'general', '2026-01-12 02:44:06', '2026-01-12 02:44:06'),
(5, 23, 'currency', 'NGN', 'string', 'financial', '2026-01-12 02:44:06', '2026-01-12 02:44:06'),
(6, 23, 'currency_symbol', '₦', 'string', 'financial', '2026-01-12 02:44:06', '2026-01-12 02:44:06'),
(7, 23, 'attendance_method', 'daily', 'string', 'academic', '2026-01-12 02:44:06', '2026-01-12 02:44:06'),
(8, 23, 'grading_system', 'percentage', 'string', 'academic', '2026-01-12 02:44:06', '2026-01-12 02:44:06'),
(9, 23, 'result_publish', 'immediate', 'string', 'academic', '2026-01-12 02:44:06', '2026-01-12 02:44:06'),
(10, 23, 'fee_due_days', '30', 'number', 'financial', '2026-01-12 02:44:06', '2026-01-12 02:44:06'),
(11, 23, 'late_fee_percentage', '5', 'number', 'financial', '2026-01-12 02:44:06', '2026-01-12 02:44:06');

-- --------------------------------------------------------

--
-- Table structure for table `sms_logs`
--

CREATE TABLE `sms_logs` (
  `id` int(10) UNSIGNED NOT NULL,
  `school_id` int(10) UNSIGNED NOT NULL,
  `recipient` varchar(20) NOT NULL,
  `message` text NOT NULL,
  `sender_id` varchar(20) DEFAULT NULL,
  `message_id` varchar(100) DEFAULT NULL,
  `status` enum('pending','sent','delivered','failed','undelivered') DEFAULT 'pending',
  `status_code` varchar(50) DEFAULT NULL,
  `status_message` text DEFAULT NULL,
  `cost` decimal(8,4) DEFAULT NULL,
  `units` int(10) DEFAULT NULL,
  `provider` varchar(50) DEFAULT NULL,
  `sent_at` timestamp NULL DEFAULT NULL,
  `delivered_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `storage_usage`
--

CREATE TABLE `storage_usage` (
  `id` int(10) UNSIGNED NOT NULL,
  `school_id` int(10) UNSIGNED NOT NULL,
  `storage_type` enum('database','files','backups','attachments') NOT NULL,
  `used_bytes` bigint(20) DEFAULT 0,
  `limit_bytes` bigint(20) DEFAULT 1073741824,
  `file_count` int(10) DEFAULT 0,
  `last_calculated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `storage_usage`
--

INSERT INTO `storage_usage` (`id`, `school_id`, `storage_type`, `used_bytes`, `limit_bytes`, `file_count`, `last_calculated`, `created_at`) VALUES
(1, 23, 'database', 0, 1073741824, 0, '2026-01-12 02:44:06', '2026-01-12 02:44:06'),
(2, 23, 'files', 0, 1073741824, 0, '2026-01-12 02:44:06', '2026-01-12 02:44:06'),
(3, 23, 'backups', 0, 536870912, 0, '2026-01-12 02:44:06', '2026-01-12 02:44:06'),
(4, 23, 'attachments', 0, 536870912, 0, '2026-01-12 02:44:06', '2026-01-12 02:44:06');

-- --------------------------------------------------------

--
-- Table structure for table `students`
--

CREATE TABLE `students` (
  `id` int(10) UNSIGNED NOT NULL,
  `school_id` int(10) UNSIGNED NOT NULL,
  `campus_id` int(10) UNSIGNED DEFAULT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `admission_number` varchar(50) NOT NULL,
  `roll_number` varchar(50) DEFAULT NULL,
  `class_id` int(10) UNSIGNED DEFAULT NULL,
  `section_id` int(10) UNSIGNED DEFAULT NULL,
  `admission_date` date NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) NOT NULL,
  `date_of_birth` date NOT NULL,
  `birth_place` varchar(255) DEFAULT NULL,
  `nationality` varchar(100) DEFAULT NULL,
  `mother_tongue` varchar(100) DEFAULT NULL,
  `current_address` text DEFAULT NULL,
  `permanent_address` text DEFAULT NULL,
  `previous_school` varchar(255) DEFAULT NULL,
  `previous_class` varchar(100) DEFAULT NULL,
  `transfer_certificate_no` varchar(100) DEFAULT NULL,
  `blood_group` varchar(5) DEFAULT NULL,
  `allergies` text DEFAULT NULL,
  `medical_conditions` text DEFAULT NULL,
  `doctor_name` varchar(255) DEFAULT NULL,
  `doctor_phone` varchar(20) DEFAULT NULL,
  `status` enum('active','inactive','graduated','transferred','withdrawn') DEFAULT 'active',
  `graduation_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `subjects`
--

CREATE TABLE `subjects` (
  `id` int(10) UNSIGNED NOT NULL,
  `school_id` int(10) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `code` varchar(50) NOT NULL,
  `type` enum('core','elective','extra_curricular') DEFAULT 'core',
  `description` text DEFAULT NULL,
  `credit_hours` decimal(4,1) DEFAULT 1.0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `subscriptions`
--

CREATE TABLE `subscriptions` (
  `id` int(10) UNSIGNED NOT NULL,
  `school_id` int(10) UNSIGNED NOT NULL,
  `plan_id` varchar(50) NOT NULL,
  `plan_name` varchar(100) NOT NULL,
  `status` enum('active','pending','cancelled','expired','past_due') DEFAULT 'pending',
  `billing_cycle` enum('monthly','quarterly','yearly') DEFAULT 'monthly',
  `amount` decimal(10,2) NOT NULL,
  `currency` varchar(3) DEFAULT 'NGN',
  `storage_limit` bigint(20) DEFAULT 1073741824,
  `user_limit` int(10) DEFAULT 100,
  `student_limit` int(10) DEFAULT 500,
  `features` text DEFAULT NULL COMMENT 'JSON encoded features',
  `current_period_start` date NOT NULL,
  `current_period_end` date NOT NULL,
  `cancel_at_period_end` tinyint(1) DEFAULT 0,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `trial_ends_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `subscriptions`
--

INSERT INTO `subscriptions` (`id`, `school_id`, `plan_id`, `plan_name`, `status`, `billing_cycle`, `amount`, `currency`, `storage_limit`, `user_limit`, `student_limit`, `features`, `current_period_start`, `current_period_end`, `cancel_at_period_end`, `cancelled_at`, `trial_ends_at`, `created_at`, `updated_at`) VALUES
(1, 23, 'free_tier', 'Free Plan', 'active', 'monthly', 0.00, 'NGN', 1073741824, 100, 500, NULL, '2026-01-12', '2026-02-12', 0, NULL, NULL, '2026-01-12 02:44:06', '2026-01-12 02:44:06');

-- --------------------------------------------------------

--
-- Table structure for table `system_alerts`
--

CREATE TABLE `system_alerts` (
  `id` int(10) UNSIGNED NOT NULL,
  `school_id` int(10) UNSIGNED NOT NULL,
  `alert_type` enum('storage_limit','user_limit','subscription_expiry','payment_failed','performance_issue','security_issue','system_error') NOT NULL,
  `severity` enum('info','warning','error','critical') DEFAULT 'info',
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `data` text DEFAULT NULL COMMENT 'JSON encoded data',
  `is_resolved` tinyint(1) DEFAULT 0,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `resolved_by` int(10) UNSIGNED DEFAULT NULL,
  `resolution_notes` text DEFAULT NULL,
  `acknowledged` tinyint(1) DEFAULT 0,
  `acknowledged_at` timestamp NULL DEFAULT NULL,
  `acknowledged_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `teachers`
--

CREATE TABLE `teachers` (
  `id` int(10) UNSIGNED NOT NULL,
  `school_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `employee_id` varchar(50) NOT NULL,
  `qualification` varchar(255) DEFAULT NULL,
  `specialization` varchar(255) DEFAULT NULL,
  `experience_years` int(10) UNSIGNED DEFAULT NULL,
  `joining_date` date DEFAULT NULL,
  `leaving_date` date DEFAULT NULL,
  `salary_grade` varchar(50) DEFAULT NULL,
  `bank_name` varchar(255) DEFAULT NULL,
  `bank_account` varchar(50) DEFAULT NULL,
  `ifsc_code` varchar(20) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `timetables`
--

CREATE TABLE `timetables` (
  `id` int(10) UNSIGNED NOT NULL,
  `school_id` int(10) UNSIGNED NOT NULL,
  `class_id` int(10) UNSIGNED NOT NULL,
  `section_id` int(10) UNSIGNED DEFAULT NULL,
  `academic_year_id` int(10) UNSIGNED NOT NULL,
  `academic_term_id` int(10) UNSIGNED NOT NULL,
  `day` enum('monday','tuesday','wednesday','thursday','friday','saturday') NOT NULL,
  `period_number` int(10) UNSIGNED NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `subject_id` int(10) UNSIGNED NOT NULL,
  `teacher_id` int(10) UNSIGNED NOT NULL,
  `room_number` varchar(50) DEFAULT NULL,
  `is_break` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(10) UNSIGNED NOT NULL,
  `school_id` int(10) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `username` varchar(100) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `user_type` enum('admin','teacher','student','parent','accountant','librarian','receptionist') NOT NULL,
  `profile_photo` varchar(500) DEFAULT NULL,
  `gender` enum('male','female','other') DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  `blood_group` varchar(5) DEFAULT NULL,
  `religion` varchar(50) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `phone_verified_at` timestamp NULL DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `last_login_at` timestamp NULL DEFAULT NULL,
  `last_login_ip` varchar(45) DEFAULT NULL,
  `remember_token` varchar(100) DEFAULT NULL,
  `reset_token` varchar(100) DEFAULT NULL,
  `reset_token_expires` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `school_id`, `name`, `email`, `phone`, `username`, `password`, `user_type`, `profile_photo`, `gender`, `date_of_birth`, `blood_group`, `religion`, `address`, `email_verified_at`, `phone_verified_at`, `is_active`, `last_login_at`, `last_login_ip`, `remember_token`, `reset_token`, `reset_token_expires`, `created_at`, `updated_at`) VALUES
(1, 23, 'james lincon', 'brookstone@gmail.com', '08119999755', NULL, '$2y$10$d2Kj0wgJjhgplYqhdUFXRemEC17IAy/ik61X1J0iJMOoWjW8OkE96', 'admin', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1, '2026-01-12 02:55:40', '127.0.0.1', NULL, NULL, NULL, '2026-01-12 02:44:06', '2026-01-12 02:55:40');

-- --------------------------------------------------------

--
-- Table structure for table `user_roles`
--

CREATE TABLE `user_roles` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `role_id` int(10) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `user_roles`
--

INSERT INTO `user_roles` (`id`, `user_id`, `role_id`, `created_at`) VALUES
(1, 1, 2, '2026-01-12 02:44:06');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `academic_terms`
--
ALTER TABLE `academic_terms`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_term_school` (`school_id`,`academic_year_id`,`name`),
  ADD KEY `idx_school` (`school_id`),
  ADD KEY `idx_year` (`academic_year_id`);

--
-- Indexes for table `academic_years`
--
ALTER TABLE `academic_years`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_year_school` (`school_id`,`name`),
  ADD KEY `idx_school` (`school_id`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `announcements`
--
ALTER TABLE `announcements`
  ADD PRIMARY KEY (`id`),
  ADD KEY `class_id` (`class_id`),
  ADD KEY `section_id` (`section_id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `idx_school` (`school_id`),
  ADD KEY `idx_published` (`is_published`),
  ADD KEY `idx_dates` (`start_date`,`end_date`);

--
-- Indexes for table `api_keys`
--
ALTER TABLE `api_keys`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `api_key` (`api_key`),
  ADD KEY `idx_school` (`school_id`),
  ADD KEY `idx_is_active` (`is_active`),
  ADD KEY `idx_expires_at` (`expires_at`),
  ADD KEY `idx_school_active` (`school_id`,`is_active`,`expires_at`);

--
-- Indexes for table `api_logs`
--
ALTER TABLE `api_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `api_key_id` (`api_key_id`),
  ADD KEY `idx_school` (`school_id`),
  ADD KEY `idx_endpoint` (`endpoint`),
  ADD KEY `idx_status_code` (`status_code`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_school_endpoint` (`school_id`,`endpoint`,`created_at`),
  ADD KEY `idx_api_logs_school_endpoint` (`school_id`,`endpoint`,`created_at`);

--
-- Indexes for table `api_usage`
--
ALTER TABLE `api_usage`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_api_usage` (`school_id`,`api_key_id`,`endpoint`,`method`,`period`,`period_start`),
  ADD KEY `api_key_id` (`api_key_id`),
  ADD KEY `idx_school` (`school_id`),
  ADD KEY `idx_endpoint` (`endpoint`),
  ADD KEY `idx_period` (`period`),
  ADD KEY `idx_period_start` (`period_start`),
  ADD KEY `idx_school_period` (`school_id`,`period`,`period_start`);

--
-- Indexes for table `attendance`
--
ALTER TABLE `attendance`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_attendance` (`student_id`,`date`,`session`),
  ADD KEY `marked_by` (`marked_by`),
  ADD KEY `idx_school` (`school_id`),
  ADD KEY `idx_student` (`student_id`),
  ADD KEY `idx_date` (`date`),
  ADD KEY `idx_class` (`class_id`),
  ADD KEY `idx_attendance_student_date` (`student_id`,`date`);

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_school` (`school_id`),
  ADD KEY `idx_action` (`action`),
  ADD KEY `idx_entity` (`entity_type`,`entity_id`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_school_action` (`school_id`,`action`,`created_at`),
  ADD KEY `idx_audit_logs_school_action` (`school_id`,`action`,`created_at`);

--
-- Indexes for table `backup_history`
--
ALTER TABLE `backup_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_school` (`school_id`),
  ADD KEY `idx_backup_type` (`backup_type`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_expires_at` (`expires_at`),
  ADD KEY `idx_school_status` (`school_id`,`status`,`created_at`),
  ADD KEY `idx_backup_history_school_status` (`school_id`,`status`,`created_at`);

--
-- Indexes for table `billing_history`
--
ALTER TABLE `billing_history`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `invoice_number` (`invoice_number`),
  ADD KEY `subscription_id` (`subscription_id`),
  ADD KEY `idx_school` (`school_id`),
  ADD KEY `idx_payment_status` (`payment_status`),
  ADD KEY `idx_payment_date` (`payment_date`),
  ADD KEY `idx_school_status` (`school_id`,`payment_status`);

--
-- Indexes for table `classes`
--
ALTER TABLE `classes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_class_school` (`school_id`,`academic_year_id`,`code`),
  ADD KEY `class_teacher_id` (`class_teacher_id`),
  ADD KEY `idx_school` (`school_id`),
  ADD KEY `idx_year` (`academic_year_id`);

--
-- Indexes for table `class_subjects`
--
ALTER TABLE `class_subjects`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_class_subject` (`class_id`,`subject_id`),
  ADD KEY `subject_id` (`subject_id`),
  ADD KEY `idx_class` (`class_id`),
  ADD KEY `idx_teacher` (`teacher_id`);

--
-- Indexes for table `email_templates`
--
ALTER TABLE `email_templates`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_template` (`school_id`,`template_key`),
  ADD KEY `idx_school` (`school_id`),
  ADD KEY `idx_template_key` (`template_key`),
  ADD KEY `idx_is_active` (`is_active`),
  ADD KEY `idx_school_active` (`school_id`,`is_active`);

--
-- Indexes for table `events`
--
ALTER TABLE `events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `idx_school` (`school_id`),
  ADD KEY `idx_dates` (`start_date`,`end_date`),
  ADD KEY `idx_type` (`type`);

--
-- Indexes for table `exams`
--
ALTER TABLE `exams`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_exam_school` (`school_id`,`academic_year_id`,`academic_term_id`,`name`),
  ADD KEY `academic_term_id` (`academic_term_id`),
  ADD KEY `idx_school` (`school_id`),
  ADD KEY `idx_year` (`academic_year_id`);

--
-- Indexes for table `exam_grades`
--
ALTER TABLE `exam_grades`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_exam_grade` (`exam_id`,`student_id`,`subject_id`),
  ADD KEY `class_id` (`class_id`),
  ADD KEY `entered_by` (`entered_by`),
  ADD KEY `idx_school` (`school_id`),
  ADD KEY `idx_exam` (`exam_id`),
  ADD KEY `idx_student` (`student_id`),
  ADD KEY `idx_subject` (`subject_id`),
  ADD KEY `idx_exam_grades_exam_student` (`exam_id`,`student_id`);

--
-- Indexes for table `fee_categories`
--
ALTER TABLE `fee_categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_category_school` (`school_id`,`name`),
  ADD KEY `idx_school` (`school_id`);

--
-- Indexes for table `fee_structures`
--
ALTER TABLE `fee_structures`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_fee_structure` (`academic_year_id`,`academic_term_id`,`class_id`,`fee_category_id`),
  ADD KEY `academic_term_id` (`academic_term_id`),
  ADD KEY `class_id` (`class_id`),
  ADD KEY `fee_category_id` (`fee_category_id`),
  ADD KEY `idx_school` (`school_id`),
  ADD KEY `idx_year` (`academic_year_id`);

--
-- Indexes for table `file_storage`
--
ALTER TABLE `file_storage`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_school` (`school_id`),
  ADD KEY `idx_file_type` (`file_type`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_school_type` (`school_id`,`file_type`),
  ADD KEY `idx_access_hash` (`access_hash`);

--
-- Indexes for table `guardians`
--
ALTER TABLE `guardians`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_guardian_student` (`student_id`,`user_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_school` (`school_id`),
  ADD KEY `idx_student` (`student_id`),
  ADD KEY `idx_primary` (`is_primary`);

--
-- Indexes for table `homework`
--
ALTER TABLE `homework`
  ADD PRIMARY KEY (`id`),
  ADD KEY `section_id` (`section_id`),
  ADD KEY `subject_id` (`subject_id`),
  ADD KEY `idx_school` (`school_id`),
  ADD KEY `idx_class` (`class_id`),
  ADD KEY `idx_due_date` (`due_date`),
  ADD KEY `idx_teacher` (`teacher_id`);

--
-- Indexes for table `invoices`
--
ALTER TABLE `invoices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `invoice_number` (`invoice_number`),
  ADD KEY `academic_year_id` (`academic_year_id`),
  ADD KEY `academic_term_id` (`academic_term_id`),
  ADD KEY `class_id` (`class_id`),
  ADD KEY `idx_school` (`school_id`),
  ADD KEY `idx_student` (`student_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_due_date` (`due_date`),
  ADD KEY `idx_invoices_student_status` (`student_id`,`status`);

--
-- Indexes for table `invoices_v2`
--
ALTER TABLE `invoices_v2`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `invoice_number` (`invoice_number`),
  ADD KEY `billing_history_id` (`billing_history_id`),
  ADD KEY `idx_school` (`school_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_due_date` (`due_date`),
  ADD KEY `idx_school_status` (`school_id`,`status`);

--
-- Indexes for table `invoice_items`
--
ALTER TABLE `invoice_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fee_category_id` (`fee_category_id`),
  ADD KEY `idx_invoice` (`invoice_id`);

--
-- Indexes for table `login_attempts`
--
ALTER TABLE `login_attempts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_school` (`school_id`),
  ADD KEY `idx_username` (`username`),
  ADD KEY `idx_ip` (`ip_address`),
  ADD KEY `idx_success` (`success`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_school_ip` (`school_id`,`ip_address`,`created_at`);

--
-- Indexes for table `maintenance_logs`
--
ALTER TABLE `maintenance_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `performed_by` (`performed_by`),
  ADD KEY `idx_school` (`school_id`),
  ADD KEY `idx_maintenance_type` (`maintenance_type`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_school_type` (`school_id`,`maintenance_type`,`created_at`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_school` (`school_id`),
  ADD KEY `idx_type` (`type`),
  ADD KEY `idx_is_read` (`is_read`),
  ADD KEY `idx_priority` (`priority`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_school_user` (`school_id`,`user_id`,`is_read`,`created_at`),
  ADD KEY `idx_notifications_school_user_read` (`school_id`,`user_id`,`is_read`,`created_at`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `payment_number` (`payment_number`),
  ADD KEY `collected_by` (`collected_by`),
  ADD KEY `idx_school` (`school_id`),
  ADD KEY `idx_invoice` (`invoice_id`),
  ADD KEY `idx_student` (`student_id`),
  ADD KEY `idx_payment_date` (`payment_date`),
  ADD KEY `idx_payments_invoice_date` (`invoice_id`,`payment_date`);

--
-- Indexes for table `payment_methods`
--
ALTER TABLE `payment_methods`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_school` (`school_id`),
  ADD KEY `idx_type` (`type`),
  ADD KEY `idx_default` (`is_default`),
  ADD KEY `idx_school_default` (`school_id`,`is_default`);

--
-- Indexes for table `performance_metrics`
--
ALTER TABLE `performance_metrics`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_school` (`school_id`),
  ADD KEY `idx_metric_type` (`metric_type`),
  ADD KEY `idx_recorded_at` (`recorded_at`),
  ADD KEY `idx_school_metric` (`school_id`,`metric_type`,`recorded_at`);

--
-- Indexes for table `rate_limits`
--
ALTER TABLE `rate_limits`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_rate_limit` (`school_id`,`endpoint`,`ip_address`,`user_id`),
  ADD KEY `idx_school` (`school_id`),
  ADD KEY `idx_endpoint` (`endpoint`),
  ADD KEY `idx_ip` (`ip_address`),
  ADD KEY `idx_window_reset` (`window_reset`),
  ADD KEY `idx_school_endpoint_ip` (`school_id`,`endpoint`,`ip_address`,`last_request`);

--
-- Indexes for table `recovery_points`
--
ALTER TABLE `recovery_points`
  ADD PRIMARY KEY (`id`),
  ADD KEY `backup_id` (`backup_id`),
  ADD KEY `idx_school` (`school_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_school_status` (`school_id`,`status`,`created_at`);

--
-- Indexes for table `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_role_school` (`school_id`,`slug`),
  ADD KEY `idx_school` (`school_id`);

--
-- Indexes for table `sections`
--
ALTER TABLE `sections`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_section_class` (`class_id`,`code`),
  ADD KEY `class_teacher_id` (`class_teacher_id`),
  ADD KEY `idx_school` (`school_id`),
  ADD KEY `idx_class` (`class_id`);

--
-- Indexes for table `security_logs`
--
ALTER TABLE `security_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_school` (`school_id`),
  ADD KEY `idx_event_type` (`event_type`),
  ADD KEY `idx_severity` (`severity`),
  ADD KEY `idx_ip` (`ip_address`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_school_event` (`school_id`,`event_type`,`created_at`);

--
-- Indexes for table `settings`
--
ALTER TABLE `settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_setting` (`school_id`,`key`),
  ADD KEY `idx_school` (`school_id`),
  ADD KEY `idx_key` (`key`);

--
-- Indexes for table `sms_logs`
--
ALTER TABLE `sms_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_school` (`school_id`),
  ADD KEY `idx_recipient` (`recipient`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_school_status` (`school_id`,`status`,`created_at`);

--
-- Indexes for table `storage_usage`
--
ALTER TABLE `storage_usage`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_school_storage` (`school_id`,`storage_type`),
  ADD KEY `idx_school` (`school_id`),
  ADD KEY `idx_type` (`storage_type`),
  ADD KEY `idx_usage` (`used_bytes`),
  ADD KEY `idx_school_type` (`school_id`,`storage_type`),
  ADD KEY `idx_storage_usage_school_type` (`school_id`,`storage_type`);

--
-- Indexes for table `students`
--
ALTER TABLE `students`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `admission_number` (`admission_number`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `section_id` (`section_id`),
  ADD KEY `idx_school` (`school_id`),
  ADD KEY `idx_class` (`class_id`),
  ADD KEY `idx_admission` (`admission_number`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_students_class_status` (`class_id`,`status`),
  ADD KEY `idx_students_admission_date` (`admission_date`);

--
-- Indexes for table `subjects`
--
ALTER TABLE `subjects`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_subject_school` (`school_id`,`code`),
  ADD KEY `idx_school` (`school_id`);

--
-- Indexes for table `subscriptions`
--
ALTER TABLE `subscriptions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_school_subscription` (`school_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_period` (`current_period_end`),
  ADD KEY `idx_school_plan` (`school_id`,`plan_id`),
  ADD KEY `idx_subscriptions_status_end` (`status`,`current_period_end`);

--
-- Indexes for table `system_alerts`
--
ALTER TABLE `system_alerts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_school` (`school_id`),
  ADD KEY `idx_alert_type` (`alert_type`),
  ADD KEY `idx_severity` (`severity`),
  ADD KEY `idx_is_resolved` (`is_resolved`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_school_resolved` (`school_id`,`is_resolved`,`created_at`);

--
-- Indexes for table `teachers`
--
ALTER TABLE `teachers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `employee_id` (`employee_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_school` (`school_id`),
  ADD KEY `idx_employee` (`employee_id`);

--
-- Indexes for table `timetables`
--
ALTER TABLE `timetables`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_timetable` (`class_id`,`section_id`,`day`,`period_number`,`academic_year_id`),
  ADD KEY `section_id` (`section_id`),
  ADD KEY `academic_year_id` (`academic_year_id`),
  ADD KEY `academic_term_id` (`academic_term_id`),
  ADD KEY `subject_id` (`subject_id`),
  ADD KEY `teacher_id` (`teacher_id`),
  ADD KEY `idx_school` (`school_id`),
  ADD KEY `idx_class` (`class_id`),
  ADD KEY `idx_day` (`day`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_email_school` (`school_id`,`email`),
  ADD UNIQUE KEY `unique_phone_school` (`school_id`,`phone`),
  ADD KEY `idx_school` (`school_id`),
  ADD KEY `idx_user_type` (`user_type`),
  ADD KEY `idx_email` (`email`),
  ADD KEY `idx_phone` (`phone`),
  ADD KEY `idx_users_school_type` (`school_id`,`user_type`),
  ADD KEY `idx_users_email_type` (`email`,`user_type`);

--
-- Indexes for table `user_roles`
--
ALTER TABLE `user_roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_user_role` (`user_id`,`role_id`),
  ADD KEY `role_id` (`role_id`),
  ADD KEY `idx_user` (`user_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `academic_terms`
--
ALTER TABLE `academic_terms`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `academic_years`
--
ALTER TABLE `academic_years`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `announcements`
--
ALTER TABLE `announcements`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `api_keys`
--
ALTER TABLE `api_keys`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `api_logs`
--
ALTER TABLE `api_logs`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `api_usage`
--
ALTER TABLE `api_usage`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `attendance`
--
ALTER TABLE `attendance`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `backup_history`
--
ALTER TABLE `backup_history`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `billing_history`
--
ALTER TABLE `billing_history`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `classes`
--
ALTER TABLE `classes`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `class_subjects`
--
ALTER TABLE `class_subjects`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `email_templates`
--
ALTER TABLE `email_templates`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `events`
--
ALTER TABLE `events`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `exams`
--
ALTER TABLE `exams`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `exam_grades`
--
ALTER TABLE `exam_grades`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `fee_categories`
--
ALTER TABLE `fee_categories`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `fee_structures`
--
ALTER TABLE `fee_structures`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `file_storage`
--
ALTER TABLE `file_storage`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `guardians`
--
ALTER TABLE `guardians`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `homework`
--
ALTER TABLE `homework`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `invoices`
--
ALTER TABLE `invoices`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `invoices_v2`
--
ALTER TABLE `invoices_v2`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `invoice_items`
--
ALTER TABLE `invoice_items`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `login_attempts`
--
ALTER TABLE `login_attempts`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `maintenance_logs`
--
ALTER TABLE `maintenance_logs`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payment_methods`
--
ALTER TABLE `payment_methods`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `performance_metrics`
--
ALTER TABLE `performance_metrics`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `rate_limits`
--
ALTER TABLE `rate_limits`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `recovery_points`
--
ALTER TABLE `recovery_points`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `roles`
--
ALTER TABLE `roles`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `sections`
--
ALTER TABLE `sections`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `security_logs`
--
ALTER TABLE `security_logs`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `settings`
--
ALTER TABLE `settings`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `sms_logs`
--
ALTER TABLE `sms_logs`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `storage_usage`
--
ALTER TABLE `storage_usage`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `students`
--
ALTER TABLE `students`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `subjects`
--
ALTER TABLE `subjects`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `subscriptions`
--
ALTER TABLE `subscriptions`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `system_alerts`
--
ALTER TABLE `system_alerts`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `teachers`
--
ALTER TABLE `teachers`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `timetables`
--
ALTER TABLE `timetables`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `user_roles`
--
ALTER TABLE `user_roles`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
