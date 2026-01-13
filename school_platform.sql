-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: localhost
-- Generation Time: Jan 12, 2026 at 12:57 AM
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
-- Database: `school_platform`
--

-- --------------------------------------------------------

--
-- Stand-in structure for view `active_schools_view`
-- (See below for the actual view)
--
CREATE TABLE `active_schools_view` (
`id` int(10) unsigned
,`name` varchar(255)
,`slug` varchar(100)
,`email` varchar(255)
,`phone` varchar(20)
,`plan_name` varchar(100)
,`price_monthly` decimal(10,2)
,`trial_ends_at` timestamp
,`created_at` timestamp
);

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

CREATE TABLE `audit_logs` (
  `id` int(10) UNSIGNED NOT NULL,
  `school_id` int(10) UNSIGNED DEFAULT NULL,
  `user_id` int(10) UNSIGNED DEFAULT NULL,
  `user_type` varchar(50) DEFAULT NULL,
  `event` varchar(100) NOT NULL,
  `auditable_type` varchar(255) DEFAULT NULL,
  `auditable_id` int(10) UNSIGNED DEFAULT NULL,
  `old_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`old_values`)),
  `new_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`new_values`)),
  `url` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `tags` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`tags`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `audit_logs`
--
--------------------------------------------------------

--
-- Table structure for table `database_backups`
--

CREATE TABLE `database_backups` (
  `id` int(11) NOT NULL,
  `school_id` int(11) DEFAULT NULL,
  `database_name` varchar(100) DEFAULT NULL,
  `filename` varchar(255) DEFAULT NULL,
  `file_size` bigint(20) DEFAULT NULL,
  `backup_type` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `email_logs`
--

CREATE TABLE `email_logs` (
  `id` int(10) UNSIGNED NOT NULL,
  `school_id` int(10) UNSIGNED DEFAULT NULL,
  `to` varchar(255) NOT NULL,
  `subject` varchar(500) NOT NULL,
  `template` varchar(100) DEFAULT NULL,
  `status` enum('sent','failed','bounced') DEFAULT 'sent',
  `message_id` varchar(255) DEFAULT NULL,
  `opened_at` timestamp NULL DEFAULT NULL,
  `clicked_at` timestamp NULL DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `enrollment_documents`
--

CREATE TABLE `enrollment_documents` (
  `id` int(10) UNSIGNED NOT NULL,
  `enrollment_request_id` int(10) UNSIGNED NOT NULL,
  `document_type` varchar(50) NOT NULL,
  `document_name` varchar(255) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_size` int(11) DEFAULT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `enrollment_requests`
--

CREATE TABLE `enrollment_requests` (
  `id` int(10) UNSIGNED NOT NULL,
  `school_id` int(10) UNSIGNED NOT NULL,
  `request_number` varchar(50) NOT NULL,
  `parent_first_name` varchar(100) NOT NULL,
  `parent_last_name` varchar(100) NOT NULL,
  `parent_email` varchar(255) NOT NULL,
  `parent_phone` varchar(20) NOT NULL,
  `parent_address` text DEFAULT NULL,
  `student_first_name` varchar(100) NOT NULL,
  `student_last_name` varchar(100) NOT NULL,
  `student_gender` enum('male','female','other') NOT NULL,
  `student_date_of_birth` date NOT NULL,
  `student_grade_level` varchar(50) NOT NULL,
  `student_previous_school` varchar(255) DEFAULT NULL,
  `enrollment_type` enum('new','transfer','re_enrollment') DEFAULT 'new',
  `academic_year` varchar(20) NOT NULL,
  `academic_term` varchar(50) DEFAULT NULL,
  `special_requirements` text DEFAULT NULL,
  `documents_submitted` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`documents_submitted`)),
  `status` enum('pending','reviewing','accepted','waitlisted','rejected') DEFAULT 'pending',
  `admin_notes` text DEFAULT NULL,
  `submitted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `reviewed_by` int(10) UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `invoices`
--

CREATE TABLE `invoices` (
  `id` int(10) UNSIGNED NOT NULL,
  `school_id` int(10) UNSIGNED NOT NULL,
  `subscription_id` int(10) UNSIGNED DEFAULT NULL,
  `invoice_number` varchar(100) NOT NULL,
  `description` varchar(500) DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `tax` decimal(10,2) DEFAULT 0.00,
  `total_amount` decimal(10,2) DEFAULT NULL,
  `currency` varchar(3) DEFAULT 'NGN',
  `status` enum('draft','sent','paid','overdue','canceled') DEFAULT 'draft',
  `due_date` date DEFAULT NULL,
  `paid_at` timestamp NULL DEFAULT NULL,
  `start_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `end_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `payment_method` varchar(50) DEFAULT NULL,
  `transaction_id` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `invoices`
--

INSERT INTO `invoices` (`id`, `school_id`, `subscription_id`, `invoice_number`, `description`, `amount`, `tax`, `total_amount`, `currency`, `status`, `due_date`, `paid_at`, `start_date`, `end_date`, `payment_method`, `transaction_id`, `notes`, `created_at`) VALUES
(1, 14, NULL, 'INV-20251231-6033', NULL, 479.88, 0.00, NULL, 'NGN', 'draft', '2026-01-07', NULL, '2025-12-30 23:00:00', '2026-12-30 23:00:00', NULL, NULL, NULL, '2025-12-31 19:46:05');

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `id` int(11) NOT NULL,
  `invoice_id` int(11) DEFAULT NULL,
  `school_id` int(11) DEFAULT NULL,
  `amount` decimal(10,2) DEFAULT NULL,
  `payment_method` varchar(50) DEFAULT NULL,
  `transaction_id` varchar(100) DEFAULT NULL,
  `status` varchar(50) DEFAULT NULL,
  `paid_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `plans`
--

CREATE TABLE `plans` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `slug` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  `price_monthly` decimal(10,2) NOT NULL DEFAULT 0.00,
  `price_yearly` decimal(10,2) NOT NULL DEFAULT 0.00,
  `student_limit` int(10) UNSIGNED NOT NULL DEFAULT 50,
  `teacher_limit` int(10) UNSIGNED NOT NULL DEFAULT 10,
  `campus_limit` int(10) UNSIGNED DEFAULT 1,
  `storage_limit` int(10) UNSIGNED DEFAULT 1024,
  `features` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`features`)),
  `is_active` tinyint(1) DEFAULT 1,
  `is_default` tinyint(1) DEFAULT 0,
  `sort_order` int(10) UNSIGNED DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `plans`
--

INSERT INTO `plans` (`id`, `name`, `slug`, `description`, `price_monthly`, `price_yearly`, `student_limit`, `teacher_limit`, `campus_limit`, `storage_limit`, `features`, `is_active`, `is_default`, `sort_order`, `created_at`) VALUES
(1, 'Starter', 'starter', 'Perfect for small schools', 0.00, 0.00, 20, 3, 1, 1024, '[\r\n  \"Student Management\",\r\n  \"Attendance Tracking\",\r\n  \"Basic Reports\",\r\n  \"Email Support\"\r\n]', 1, 1, 1, '2025-12-23 20:39:13'),
(2, 'Growth', 'growth', 'For growing schools', 39.99, 399.99, 500, 20, NULL, 5120, '[\r\n  \"All Starter Features\",\r\n  \"Fee Management\",\r\n  \"Online Payments\",\r\n  \"SMS Notifications\",\r\n  \"Advanced Reports\",\r\n  \"Phone Support\"\r\n]', 1, 0, 2, '2025-12-23 20:39:13'),
(3, 'Enterprise', 'enterprise', 'For large institutions', 199.99, 1999.99, 1500, 100, NULL, 10240, '[\"All Growth Features\", \"Custom Domain\", \"API Access\", \"Priority Support\", \"Custom Development\", \"Dedicated Account Manager\"]', 1, 0, 3, '2025-12-23 20:39:13'),
(4, 'Tether', 'tether', 'tester', 44.00, 444.00, 5000, 100, 5, 1024, '[\r\n  \"Student Management\",\r\n  \"Attendance Tracking\",\r\n  \"Basic Reports\",\r\n  \"Email Support\"\r\n]', 1, 0, 1, '2025-12-31 08:24:24');

-- --------------------------------------------------------

--
-- Table structure for table `platform_audit_logs`
--

CREATE TABLE `platform_audit_logs` (
  `id` int(11) NOT NULL,
  `school_id` int(11) DEFAULT NULL,
  `event` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `user_type` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `platform_audit_logs`
--

--
-- Table structure for table `platform_broadcasts`
--

CREATE TABLE `platform_broadcasts` (
  `id` int(11) NOT NULL,
  `school_id` int(11) DEFAULT NULL,
  `subject` varchar(255) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `user_types` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`user_types`)),
  `total_recipients` int(11) DEFAULT NULL,
  `emails_sent` int(11) DEFAULT NULL,
  `sent_by` varchar(100) DEFAULT NULL,
  `sent_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `platform_users`
--

CREATE TABLE `platform_users` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('super_admin','support','sales') DEFAULT 'support',
  `phone` varchar(20) DEFAULT NULL,
  `avatar` varchar(500) DEFAULT NULL,
  `two_factor_secret` text DEFAULT NULL,
  `two_factor_recovery_codes` text DEFAULT NULL,
  `remember_token` varchar(100) DEFAULT NULL,
  `last_login_at` timestamp NULL DEFAULT NULL,
  `last_login_ip` varchar(45) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `platform_users`
--

INSERT INTO `platform_users` (`id`, `name`, `email`, `email_verified_at`, `password`, `role`, `phone`, `avatar`, `two_factor_secret`, `two_factor_recovery_codes`, `remember_token`, `last_login_at`, `last_login_ip`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'Super Admin', 'admin@schoolsaas.com', NULL, '$2y$10$d2Kj0wgJjhgplYqhdUFXRemEC17IAy/ik61X1J0iJMOoWjW8OkE96', 'super_admin', NULL, NULL, NULL, NULL, NULL, '2026-01-11 23:14:51', '127.0.0.1', 1, '2025-12-23 20:39:13', '2026-01-11 23:14:51');

-- --------------------------------------------------------

--
-- Table structure for table `schools`
--

CREATE TABLE `schools` (
  `id` int(10) UNSIGNED NOT NULL,
  `parent_school_id` int(10) UNSIGNED DEFAULT NULL,
  `uuid` char(36) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `mission_statement` text DEFAULT NULL,
  `vision_statement` text DEFAULT NULL,
  `principal_name` varchar(255) DEFAULT NULL,
  `principal_message` text DEFAULT NULL,
  `slug` varchar(100) NOT NULL,
  `school_type` enum('nursery','primary','secondary','comprehensive','international','montessori','boarding','day') DEFAULT 'secondary',
  `curriculum` varchar(100) DEFAULT 'Nigerian',
  `student_count` int(11) DEFAULT 0,
  `teacher_count` int(11) DEFAULT 0,
  `class_count` int(11) DEFAULT 0,
  `email` varchar(255) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `postal_code` varchar(20) DEFAULT NULL,
  `state` varchar(100) DEFAULT NULL,
  `country` varchar(100) DEFAULT 'Nigeria',
  `establishment_year` year(4) DEFAULT NULL,
  `avg_rating` decimal(3,2) DEFAULT 0.00,
  `total_reviews` int(11) DEFAULT 0,
  `fee_range_from` decimal(10,2) DEFAULT 0.00,
  `fee_range_to` decimal(10,2) DEFAULT 0.00,
  `facilities` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`facilities`)),
  `gallery_images` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`gallery_images`)),
  `admission_status` enum('open','closed','waiting_list') DEFAULT 'open',
  `accreditation` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`accreditation`)),
  `accreditations` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`accreditations`)),
  `affiliations` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`affiliations`)),
  `extracurricular_activities` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`extracurricular_activities`)),
  `sports_facilities` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`sports_facilities`)),
  `transportation_available` tinyint(1) DEFAULT 0,
  `boarding_available` tinyint(1) DEFAULT 0,
  `meal_provided` tinyint(1) DEFAULT 0,
  `teacher_student_ratio` varchar(20) DEFAULT NULL,
  `average_class_size` int(11) DEFAULT NULL,
  `school_hours` varchar(100) DEFAULT NULL,
  `admission_process` text DEFAULT NULL,
  `admission_deadline` date DEFAULT NULL,
  `entrance_exam_required` tinyint(1) DEFAULT 0,
  `interview_required` tinyint(1) DEFAULT 0,
  `social_links` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`social_links`)),
  `logo_path` varchar(500) DEFAULT NULL,
  `primary_color` varchar(7) DEFAULT '#3B82F6',
  `secondary_color` varchar(7) DEFAULT '#10B981',
  `database_name` varchar(100) DEFAULT NULL,
  `database_host` varchar(255) DEFAULT 'localhost',
  `database_port` int(11) DEFAULT 3306,
  `plan_id` int(10) UNSIGNED DEFAULT NULL,
  `status` enum('pending','trial','active','suspended','cancelled') DEFAULT 'pending',
  `trial_ends_at` timestamp NULL DEFAULT NULL,
  `subscription_ends_at` timestamp NULL DEFAULT NULL,
  `settings` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`settings`)),
  `timezone` varchar(50) DEFAULT 'Africa/Lagos',
  `currency` varchar(3) DEFAULT 'NGN',
  `language` varchar(10) DEFAULT 'en',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `suspended_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `campus_type` enum('main','branch') DEFAULT 'main',
  `campus_code` varchar(50) DEFAULT NULL,
  `storage_used` int(11) DEFAULT 0 COMMENT 'Storage used in MB',
  `request_count` int(11) DEFAULT 0,
  `last_request_at` timestamp NULL DEFAULT NULL,
  `last_backup_at` timestamp NULL DEFAULT NULL,
  `last_optimized_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `schools`
--
--
-- Table structure for table `school_admins`
--

CREATE TABLE `school_admins` (
  `id` int(10) UNSIGNED NOT NULL,
  `school_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `email` varchar(255) NOT NULL,
  `role` enum('owner','admin','accountant','principal') DEFAULT 'owner',
  `permissions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`permissions`)),
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `school_admins`
--

--
-- Table structure for table `school_contacts`
--

CREATE TABLE `school_contacts` (
  `id` int(10) UNSIGNED NOT NULL,
  `school_id` int(10) UNSIGNED NOT NULL,
  `type` enum('phone','email','address','website','social') NOT NULL,
  `label` varchar(100) DEFAULT NULL,
  `value` varchar(255) NOT NULL,
  `is_primary` tinyint(1) DEFAULT 0,
  `sort_order` int(10) UNSIGNED DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `school_facilities`
--

CREATE TABLE `school_facilities` (
  `id` int(10) UNSIGNED NOT NULL,
  `school_id` int(10) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `icon` varchar(50) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `sort_order` int(10) UNSIGNED DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `school_gallery`
--

CREATE TABLE `school_gallery` (
  `id` int(10) UNSIGNED NOT NULL,
  `school_id` int(10) UNSIGNED NOT NULL,
  `image_url` varchar(500) NOT NULL,
  `caption` varchar(255) DEFAULT NULL,
  `type` enum('campus','classroom','laboratory','library','sports','events','other') DEFAULT 'campus',
  `sort_order` int(10) UNSIGNED DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `school_reviews`
--

CREATE TABLE `school_reviews` (
  `id` int(10) UNSIGNED NOT NULL,
  `school_id` int(10) UNSIGNED NOT NULL,
  `parent_name` varchar(255) NOT NULL,
  `parent_email` varchar(255) NOT NULL,
  `student_name` varchar(255) DEFAULT NULL,
  `rating` decimal(2,1) NOT NULL CHECK (`rating` >= 1 and `rating` <= 5),
  `title` varchar(255) DEFAULT NULL,
  `comment` text NOT NULL,
  `pros` text DEFAULT NULL,
  `cons` text DEFAULT NULL,
  `is_verified` tinyint(1) DEFAULT 0,
  `is_approved` tinyint(1) DEFAULT 0,
  `helpful_count` int(10) UNSIGNED DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sms_logs`
--

CREATE TABLE `sms_logs` (
  `id` int(10) UNSIGNED NOT NULL,
  `school_id` int(10) UNSIGNED DEFAULT NULL,
  `to` varchar(20) NOT NULL,
  `message` text NOT NULL,
  `status` enum('sent','failed','delivered') DEFAULT 'sent',
  `cost` decimal(8,4) DEFAULT 0.0000,
  `message_id` varchar(255) DEFAULT NULL,
  `provider` varchar(50) DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `subscriptions`
--

CREATE TABLE `subscriptions` (
  `id` int(10) UNSIGNED NOT NULL,
  `school_id` int(10) UNSIGNED NOT NULL,
  `plan_id` int(10) UNSIGNED NOT NULL,
  `stripe_subscription_id` varchar(255) DEFAULT NULL,
  `paystack_subscription_code` varchar(255) DEFAULT NULL,
  `status` enum('active','pending','canceled','past_due') DEFAULT 'pending',
  `billing_cycle` enum('monthly','yearly') DEFAULT 'monthly',
  `amount` decimal(10,2) NOT NULL,
  `currency` varchar(3) DEFAULT 'NGN',
  `current_period_start` timestamp NULL DEFAULT NULL,
  `current_period_end` timestamp NULL DEFAULT NULL,
  `canceled_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `subscriptions`
--

--
-- Table structure for table `support_tickets`
--

CREATE TABLE `support_tickets` (
  `id` int(10) UNSIGNED NOT NULL,
  `school_id` int(10) UNSIGNED NOT NULL,
  `ticket_number` varchar(50) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `priority` enum('low','medium','high','urgent') DEFAULT 'medium',
  `status` enum('open','in_progress','resolved','closed') DEFAULT 'open',
  `category` varchar(100) DEFAULT NULL,
  `assigned_to` int(10) UNSIGNED DEFAULT NULL,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure for view `active_schools_view`
--
DROP TABLE IF EXISTS `active_schools_view`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `active_schools_view`  AS SELECT `s`.`id` AS `id`, `s`.`name` AS `name`, `s`.`slug` AS `slug`, `s`.`email` AS `email`, `s`.`phone` AS `phone`, `p`.`name` AS `plan_name`, `p`.`price_monthly` AS `price_monthly`, `s`.`trial_ends_at` AS `trial_ends_at`, `s`.`created_at` AS `created_at` FROM (`schools` `s` left join `plans` `p` on(`s`.`plan_id` = `p`.`id`)) WHERE `s`.`status` in ('active','trial') ORDER BY `s`.`created_at` DESC ;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_school` (`school_id`),
  ADD KEY `idx_event` (`event`),
  ADD KEY `idx_created` (`created_at`),
  ADD KEY `idx_user` (`user_id`,`user_type`),
  ADD KEY `idx_audit_school_event` (`school_id`,`event`,`created_at`);

--
-- Indexes for table `database_backups`
--
ALTER TABLE `database_backups`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_school_id` (`school_id`);

--
-- Indexes for table `email_logs`
--
ALTER TABLE `email_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_school` (`school_id`),
  ADD KEY `idx_to` (`to`),
  ADD KEY `idx_created` (`created_at`);

--
-- Indexes for table `enrollment_documents`
--
ALTER TABLE `enrollment_documents`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_enrollment` (`enrollment_request_id`);

--
-- Indexes for table `enrollment_requests`
--
ALTER TABLE `enrollment_requests`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `request_number` (`request_number`),
  ADD KEY `idx_school_id` (`school_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_email` (`parent_email`),
  ADD KEY `idx_created` (`submitted_at`);

--
-- Indexes for table `invoices`
--
ALTER TABLE `invoices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `invoice_number` (`invoice_number`),
  ADD KEY `subscription_id` (`subscription_id`),
  ADD KEY `idx_school` (`school_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_due_date` (`due_date`),
  ADD KEY `idx_invoice_number` (`invoice_number`),
  ADD KEY `idx_invoices_school_date` (`school_id`,`due_date`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_school_id` (`school_id`),
  ADD KEY `idx_invoice_id` (`invoice_id`);

--
-- Indexes for table `plans`
--
ALTER TABLE `plans`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `slug` (`slug`),
  ADD KEY `idx_active` (`is_active`),
  ADD KEY `idx_default` (`is_default`);

--
-- Indexes for table `platform_audit_logs`
--
ALTER TABLE `platform_audit_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_school_id` (`school_id`),
  ADD KEY `idx_event` (`event`);

--
-- Indexes for table `platform_broadcasts`
--
ALTER TABLE `platform_broadcasts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_school_id` (`school_id`);

--
-- Indexes for table `platform_users`
--
ALTER TABLE `platform_users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `idx_email` (`email`),
  ADD KEY `idx_role` (`role`);

--
-- Indexes for table `schools`
--
ALTER TABLE `schools`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uuid` (`uuid`),
  ADD UNIQUE KEY `slug` (`slug`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_slug` (`slug`),
  ADD KEY `idx_plan` (`plan_id`),
  ADD KEY `idx_trial` (`trial_ends_at`),
  ADD KEY `idx_schools_created` (`created_at`),
  ADD KEY `idx_schools_email` (`email`),
  ADD KEY `parent_school_id` (`parent_school_id`),
  ADD KEY `idx_school_search` (`name`,`state`,`city`,`curriculum`,`school_type`);

--
-- Indexes for table `school_admins`
--
ALTER TABLE `school_admins`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_school_user` (`school_id`,`user_id`),
  ADD KEY `idx_school` (`school_id`);

--
-- Indexes for table `school_contacts`
--
ALTER TABLE `school_contacts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_school_id` (`school_id`),
  ADD KEY `idx_type` (`type`);

--
-- Indexes for table `school_facilities`
--
ALTER TABLE `school_facilities`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_school_id` (`school_id`);

--
-- Indexes for table `school_gallery`
--
ALTER TABLE `school_gallery`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_school_id` (`school_id`);

--
-- Indexes for table `school_reviews`
--
ALTER TABLE `school_reviews`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_school_id` (`school_id`),
  ADD KEY `idx_rating` (`rating`),
  ADD KEY `idx_approved` (`is_approved`);

--
-- Indexes for table `sms_logs`
--
ALTER TABLE `sms_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_school` (`school_id`),
  ADD KEY `idx_to` (`to`),
  ADD KEY `idx_created` (`created_at`);

--
-- Indexes for table `subscriptions`
--
ALTER TABLE `subscriptions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `plan_id` (`plan_id`),
  ADD KEY `idx_school` (`school_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_period_end` (`current_period_end`);

--
-- Indexes for table `support_tickets`
--
ALTER TABLE `support_tickets`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ticket_number` (`ticket_number`),
  ADD KEY `assigned_to` (`assigned_to`),
  ADD KEY `idx_school` (`school_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_priority` (`priority`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `database_backups`
--
ALTER TABLE `database_backups`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `email_logs`
--
ALTER TABLE `email_logs`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `enrollment_documents`
--
ALTER TABLE `enrollment_documents`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `enrollment_requests`
--
ALTER TABLE `enrollment_requests`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `invoices`
--
ALTER TABLE `invoices`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `plans`
--
ALTER TABLE `plans`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `platform_audit_logs`
--
ALTER TABLE `platform_audit_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `platform_broadcasts`
--
ALTER TABLE `platform_broadcasts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `platform_users`
--
ALTER TABLE `platform_users`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `schools`
--
ALTER TABLE `schools`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `school_admins`
--
ALTER TABLE `school_admins`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `school_contacts`
--
ALTER TABLE `school_contacts`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `school_facilities`
--
ALTER TABLE `school_facilities`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `school_gallery`
--
ALTER TABLE `school_gallery`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `school_reviews`
--
ALTER TABLE `school_reviews`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sms_logs`
--
ALTER TABLE `sms_logs`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `subscriptions`
--
ALTER TABLE `subscriptions`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `support_tickets`
--
ALTER TABLE `support_tickets`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `enrollment_documents`
--
ALTER TABLE `enrollment_documents`
  ADD CONSTRAINT `enrollment_documents_ibfk_1` FOREIGN KEY (`enrollment_request_id`) REFERENCES `enrollment_requests` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `enrollment_requests`
--
ALTER TABLE `enrollment_requests`
  ADD CONSTRAINT `enrollment_requests_ibfk_1` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `invoices`
--
ALTER TABLE `invoices`
  ADD CONSTRAINT `invoices_ibfk_1` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `invoices_ibfk_2` FOREIGN KEY (`subscription_id`) REFERENCES `subscriptions` (`id`);

--
-- Constraints for table `schools`
--
ALTER TABLE `schools`
  ADD CONSTRAINT `schools_ibfk_1` FOREIGN KEY (`parent_school_id`) REFERENCES `schools` (`id`);

--
-- Constraints for table `school_admins`
--
ALTER TABLE `school_admins`
  ADD CONSTRAINT `school_admins_ibfk_1` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `school_contacts`
--
ALTER TABLE `school_contacts`
  ADD CONSTRAINT `school_contacts_ibfk_1` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `school_facilities`
--
ALTER TABLE `school_facilities`
  ADD CONSTRAINT `school_facilities_ibfk_1` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `school_gallery`
--
ALTER TABLE `school_gallery`
  ADD CONSTRAINT `school_gallery_ibfk_1` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `school_reviews`
--
ALTER TABLE `school_reviews`
  ADD CONSTRAINT `school_reviews_ibfk_1` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `subscriptions`
--
ALTER TABLE `subscriptions`
  ADD CONSTRAINT `subscriptions_ibfk_1` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `subscriptions_ibfk_2` FOREIGN KEY (`plan_id`) REFERENCES `plans` (`id`);

--
-- Constraints for table `support_tickets`
--
ALTER TABLE `support_tickets`
  ADD CONSTRAINT `support_tickets_ibfk_1` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `support_tickets_ibfk_2` FOREIGN KEY (`assigned_to`) REFERENCES `platform_users` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
