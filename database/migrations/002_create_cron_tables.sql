-- ============================================================
-- CRON JOB SYSTEM DATABASE SCHEMA
-- ============================================================
-- This migration creates all tables needed for the cron job system
-- including email queues, scheduled tasks, and logging
-- ============================================================

-- ============================================================
-- 1. EMAIL QUEUE TABLE (Enhanced)
-- ============================================================
CREATE TABLE IF NOT EXISTS `email_queue` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `to_email` VARCHAR(255) NOT NULL,
    `to_name` VARCHAR(255) DEFAULT NULL,
    `from_email` VARCHAR(255) DEFAULT NULL,
    `from_name` VARCHAR(255) DEFAULT NULL,
    `reply_to` VARCHAR(255) DEFAULT NULL,
    `subject` VARCHAR(500) NOT NULL,
    `html_content` LONGTEXT NOT NULL,
    `text_content` TEXT DEFAULT NULL,
    `attachments` TEXT DEFAULT NULL COMMENT 'JSON array of attachment paths',
    `priority` TINYINT UNSIGNED DEFAULT 5 COMMENT '1=highest, 10=lowest',
    `type` VARCHAR(50) DEFAULT 'general' COMMENT 'general, bulk, scheduled, notification, etc.',
    `status` ENUM('pending', 'processing', 'sent', 'failed', 'cancelled') DEFAULT 'pending',
    `scheduled_for` DATETIME DEFAULT NULL COMMENT 'NULL = send immediately',
    `sent_at` DATETIME DEFAULT NULL,
    `attempts` TINYINT UNSIGNED DEFAULT 0,
    `max_attempts` TINYINT UNSIGNED DEFAULT 3,
    `error_message` TEXT DEFAULT NULL,
    `metadata` TEXT DEFAULT NULL COMMENT 'JSON for additional data',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_status` (`status`),
    INDEX `idx_scheduled` (`scheduled_for`),
    INDEX `idx_priority` (`priority`),
    INDEX `idx_type` (`type`),
    INDEX `idx_created` (`created_at`),
    INDEX `idx_status_scheduled` (`status`, `scheduled_for`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 2. BULK EMAIL CAMPAIGNS TABLE
-- ============================================================
CREATE TABLE IF NOT EXISTS `bulk_email_campaigns` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(255) NOT NULL,
    `subject` VARCHAR(500) NOT NULL,
    `html_content` LONGTEXT NOT NULL,
    `text_content` TEXT DEFAULT NULL,
    `from_email` VARCHAR(255) DEFAULT NULL,
    `from_name` VARCHAR(255) DEFAULT NULL,
    `reply_to` VARCHAR(255) DEFAULT NULL,
    `recipient_filter` TEXT DEFAULT NULL COMMENT 'JSON criteria for selecting recipients',
    `total_recipients` INT UNSIGNED DEFAULT 0,
    `sent_count` INT UNSIGNED DEFAULT 0,
    `failed_count` INT UNSIGNED DEFAULT 0,
    `status` ENUM('draft', 'scheduled', 'processing', 'completed', 'cancelled') DEFAULT 'draft',
    `scheduled_for` DATETIME DEFAULT NULL,
    `started_at` DATETIME DEFAULT NULL,
    `completed_at` DATETIME DEFAULT NULL,
    `created_by` INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_status` (`status`),
    INDEX `idx_scheduled` (`scheduled_for`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 3. SCHEDULED ANNOUNCEMENTS TABLE
-- ============================================================
CREATE TABLE IF NOT EXISTS `scheduled_announcements` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `school_id` INT UNSIGNED DEFAULT NULL COMMENT 'NULL = platform-wide',
    `title` VARCHAR(255) NOT NULL,
    `content` TEXT NOT NULL,
    `type` VARCHAR(50) DEFAULT 'general' COMMENT 'general, urgent, maintenance, etc.',
    `target_audience` VARCHAR(50) DEFAULT 'all' COMMENT 'all, students, teachers, parents, admins',
    `status` ENUM('scheduled', 'published', 'cancelled') DEFAULT 'scheduled',
    `scheduled_for` DATETIME NOT NULL,
    `published_at` DATETIME DEFAULT NULL,
    `expires_at` DATETIME DEFAULT NULL,
    `send_email` TINYINT(1) DEFAULT 0 COMMENT 'Also send as email notification',
    `send_sms` TINYINT(1) DEFAULT 0 COMMENT 'Also send as SMS',
    `created_by` INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_status` (`status`),
    INDEX `idx_scheduled` (`scheduled_for`),
    INDEX `idx_school` (`school_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 4. STUDENT SUSPENSION QUEUE TABLE
-- ============================================================
CREATE TABLE IF NOT EXISTS `student_suspension_queue` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `school_id` INT UNSIGNED NOT NULL,
    `student_id` INT UNSIGNED NOT NULL,
    `reason` VARCHAR(255) NOT NULL COMMENT 'payment_expired, violation, admin_action, etc.',
    `suspension_type` ENUM('temporary', 'permanent') DEFAULT 'temporary',
    `scheduled_for` DATETIME NOT NULL,
    `expires_at` DATETIME DEFAULT NULL COMMENT 'For temporary suspensions',
    `status` ENUM('pending', 'processed', 'cancelled') DEFAULT 'pending',
    `processed_at` DATETIME DEFAULT NULL,
    `email_sent` TINYINT(1) DEFAULT 0,
    `metadata` TEXT DEFAULT NULL COMMENT 'JSON for additional data',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_status` (`status`),
    INDEX `idx_scheduled` (`scheduled_for`),
    INDEX `idx_school_student` (`school_id`, `student_id`),
    UNIQUE KEY `unique_pending_suspension` (`school_id`, `student_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 5. CRON JOB LOGS TABLE
-- ============================================================
CREATE TABLE IF NOT EXISTS `cron_logs` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `task_name` VARCHAR(100) NOT NULL,
    `level` ENUM('INFO', 'WARNING', 'ERROR', 'SUCCESS') DEFAULT 'INFO',
    `message` TEXT NOT NULL,
    `context` TEXT DEFAULT NULL COMMENT 'JSON context data',
    `execution_time` DECIMAL(10,4) DEFAULT NULL COMMENT 'Execution time in seconds',
    `memory_used` INT UNSIGNED DEFAULT NULL COMMENT 'Memory used in bytes',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_task` (`task_name`),
    INDEX `idx_level` (`level`),
    INDEX `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 6. CRON JOB EXECUTION HISTORY TABLE
-- ============================================================
CREATE TABLE IF NOT EXISTS `cron_execution_history` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `task_name` VARCHAR(100) NOT NULL,
    `status` ENUM('started', 'completed', 'failed') DEFAULT 'started',
    `started_at` DATETIME NOT NULL,
    `completed_at` DATETIME DEFAULT NULL,
    `execution_time` DECIMAL(10,4) DEFAULT NULL COMMENT 'Execution time in seconds',
    `memory_peak` INT UNSIGNED DEFAULT NULL COMMENT 'Peak memory in bytes',
    `items_processed` INT UNSIGNED DEFAULT 0,
    `items_succeeded` INT UNSIGNED DEFAULT 0,
    `items_failed` INT UNSIGNED DEFAULT 0,
    `error_message` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_task` (`task_name`),
    INDEX `idx_status` (`status`),
    INDEX `idx_started` (`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 7. EMAIL SUPPRESSION LIST (Bounces, Unsubscribes)
-- ============================================================
CREATE TABLE IF NOT EXISTS `email_suppression_list` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `email` VARCHAR(255) NOT NULL,
    `reason` ENUM('bounce', 'complaint', 'unsubscribe', 'manual') NOT NULL,
    `bounce_type` VARCHAR(50) DEFAULT NULL COMMENT 'hard, soft, transient',
    `notes` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_email` (`email`),
    INDEX `idx_reason` (`reason`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 8. CRON JOB SCHEDULE TABLE (Optional - for dynamic scheduling)
-- ============================================================
CREATE TABLE IF NOT EXISTS `cron_schedules` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `task_name` VARCHAR(100) NOT NULL,
    `description` VARCHAR(255) DEFAULT NULL,
    `schedule` VARCHAR(100) NOT NULL COMMENT 'Cron expression: * * * * *',
    `command` VARCHAR(255) NOT NULL COMMENT 'PHP file to execute',
    `is_active` TINYINT(1) DEFAULT 1,
    `last_run_at` DATETIME DEFAULT NULL,
    `next_run_at` DATETIME DEFAULT NULL,
    `run_count` INT UNSIGNED DEFAULT 0,
    `failure_count` INT UNSIGNED DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_task` (`task_name`),
    INDEX `idx_active` (`is_active`),
    INDEX `idx_next_run` (`next_run_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- INITIAL DATA
-- ============================================================

-- Insert default cron schedules
INSERT INTO `cron_schedules` (`task_name`, `description`, `schedule`, `command`, `is_active`) VALUES
('process_email_queue', 'Process pending emails in queue', '*/5 * * * *', 'tasks/process_email_queue.php', 1),
('process_scheduled_emails', 'Send scheduled emails', '*/10 * * * *', 'tasks/process_scheduled_emails.php', 1),
('process_school_trials', 'Suspend expired school trials and send trial-ended emails', '0 * * * *', 'tasks/process_school_trials.php', 1),
('process_student_suspensions', 'Process student account suspensions', '0 * * * *', 'tasks/process_student_suspensions.php', 1),
('publish_scheduled_announcements', 'Publish scheduled announcements', '*/15 * * * *', 'tasks/publish_scheduled_announcements.php', 1),
('cleanup_old_logs', 'Clean up old cron logs', '0 2 * * *', 'tasks/cleanup_old_logs.php', 1),
('retry_failed_emails', 'Retry failed emails', '0 */6 * * *', 'tasks/retry_failed_emails.php', 1)
ON DUPLICATE KEY UPDATE 
    description = VALUES(description),
    schedule = VALUES(schedule),
    command = VALUES(command);
