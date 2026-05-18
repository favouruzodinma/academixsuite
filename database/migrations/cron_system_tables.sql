-- ============================================================================
-- Cron System Database Schema
-- Migration file for cron-based background job system
-- ============================================================================

-- Create email_queue table
CREATE TABLE IF NOT EXISTS `email_queue` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `school_id` INT(10) UNSIGNED DEFAULT NULL COMMENT 'NULL for platform-wide emails',
  `to` VARCHAR(255) NOT NULL,
  `subject` VARCHAR(500) NOT NULL,
  `body` LONGTEXT NOT NULL,
  `template` VARCHAR(100) DEFAULT NULL COMMENT 'Template name if using template',
  `template_data` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`template_data`)),
  `status` ENUM('pending', 'sent', 'failed') DEFAULT 'pending',
  `scheduled_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'For scheduled emails',
  `sent_at` TIMESTAMP NULL DEFAULT NULL,
  `attempts` INT(3) DEFAULT 0,
  `last_attempt_at` TIMESTAMP NULL DEFAULT NULL,
  `error_message` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_scheduled_at` (`scheduled_at`),
  KEY `idx_school_id` (`school_id`),
  KEY `idx_status_scheduled` (`status`, `scheduled_at`),
  KEY `idx_attempts` (`attempts`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Queue for bulk and scheduled emails';

-- ============================================================================

-- Create scheduled_announcements table
CREATE TABLE IF NOT EXISTS `scheduled_announcements` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `school_id` INT(10) UNSIGNED NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `content` LONGTEXT NOT NULL,
  `publish_at` TIMESTAMP NOT NULL COMMENT 'Scheduled publish datetime',
  `status` ENUM('scheduled', 'published', 'cancelled', 'failed') DEFAULT 'scheduled',
  `published_at` TIMESTAMP NULL DEFAULT NULL,
  `created_by` INT(10) UNSIGNED DEFAULT NULL COMMENT 'Admin user ID who created',
  `notify_users` TINYINT(1) DEFAULT 0 COMMENT 'Send email notification to users',
  `error_message` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_school_id` (`school_id`),
  KEY `idx_status` (`status`),
  KEY `idx_publish_at` (`publish_at`),
  KEY `idx_status_publish` (`status`, `publish_at`),
  CONSTRAINT `fk_scheduled_announcements_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Scheduled announcements to be published automatically';

-- ============================================================================

-- Create cron_logs table
CREATE TABLE IF NOT EXISTS `cron_logs` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `task_name` VARCHAR(100) NOT NULL,
  `status` ENUM('started', 'completed', 'failed') DEFAULT 'started',
  `level` VARCHAR(20) DEFAULT 'INFO' COMMENT 'Log level: INFO, WARNING, ERROR, SUCCESS',
  `message` TEXT DEFAULT NULL,
  `context` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`context`)),
  `execution_time` DECIMAL(10, 2) DEFAULT NULL COMMENT 'Execution time in seconds',
  `memory_usage` DECIMAL(10, 2) DEFAULT NULL COMMENT 'Memory usage in MB',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_task_name` (`task_name`),
  KEY `idx_status` (`status`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_task_status_date` (`task_name`, `status`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Logs for cron job executions';

-- ============================================================================

-- Create cron_locks table (optional - file-based locking is primary)
CREATE TABLE IF NOT EXISTS `cron_locks` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `task_name` VARCHAR(100) NOT NULL,
  `locked_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` TIMESTAMP NOT NULL,
  `process_id` INT(11) DEFAULT NULL,
  `hostname` VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_task_name` (`task_name`),
  KEY `idx_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Database-based locks for cron tasks (backup to file locks)';

-- ============================================================================

-- Add index to existing email_logs table for better performance
ALTER TABLE `email_logs` 
ADD INDEX IF NOT EXISTS `idx_status_created` (`status`, `created_at`);

-- ============================================================================

-- Create view for cron job monitoring
CREATE OR REPLACE VIEW `cron_job_status` AS
SELECT 
    task_name,
    COUNT(*) as total_runs,
    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as successful_runs,
    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_runs,
    AVG(execution_time) as avg_execution_time,
    MAX(execution_time) as max_execution_time,
    MAX(created_at) as last_run_at
FROM cron_logs
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
GROUP BY task_name;

-- ============================================================================

-- Insert sample data for testing (optional - comment out for production)
-- INSERT INTO email_queue (to, subject, body, status) VALUES
-- ('test@example.com', 'Test Email', '<p>This is a test email from the queue.</p>', 'pending');

-- ============================================================================

-- Clean up old cron logs (keep last 30 days)
-- Run this periodically or add to a cleanup cron job
-- DELETE FROM cron_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY);

-- ============================================================================

-- Verify tables were created
SELECT 
    TABLE_NAME,
    TABLE_ROWS,
    CREATE_TIME,
    TABLE_COMMENT
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
AND TABLE_NAME IN ('email_queue', 'scheduled_announcements', 'cron_logs', 'cron_locks')
ORDER BY TABLE_NAME;
