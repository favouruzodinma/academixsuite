<?php
// fix_email_queue_table.php — CLI-only migration helper.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script is CLI-only.\n");
}
require_once __DIR__ . '/includes/autoload.php';

try {
    $db = Database::getPlatformConnection();
    
    echo "Updating email_queue table schema...\n";
    
    $queries = [
        "ALTER TABLE `email_queue` ADD COLUMN IF NOT EXISTS `tenant_id` INT UNSIGNED DEFAULT NULL AFTER `id`",
        "ALTER TABLE `email_queue` CHANGE COLUMN IF EXISTS `to` `recipient_email` VARCHAR(255) NOT NULL",
        "ALTER TABLE `email_queue` ADD COLUMN IF NOT EXISTS `recipient_name` VARCHAR(255) DEFAULT NULL AFTER `recipient_email`",
        "ALTER TABLE `email_queue` ADD COLUMN IF NOT EXISTS `recipient_type` VARCHAR(50) DEFAULT 'other' AFTER `recipient_name`",
        "ALTER TABLE `email_queue` CHANGE COLUMN IF EXISTS `body` `body_html` LONGTEXT NOT NULL",
        "ALTER TABLE `email_queue` ADD COLUMN IF NOT EXISTS `body_text` TEXT DEFAULT NULL AFTER `body_html`",
        "ALTER TABLE `email_queue` CHANGE COLUMN IF EXISTS `template` `template_name` VARCHAR(100) DEFAULT NULL",
        "ALTER TABLE `email_queue` ADD COLUMN IF NOT EXISTS `from_email` VARCHAR(255) DEFAULT NULL AFTER `template_name`",
        "ALTER TABLE `email_queue` ADD COLUMN IF NOT EXISTS `from_name` VARCHAR(255) DEFAULT NULL AFTER `from_email`",
        "ALTER TABLE `email_queue` ADD COLUMN IF NOT EXISTS `reply_to` VARCHAR(255) DEFAULT NULL AFTER `from_name`",
        "ALTER TABLE `email_queue` ADD COLUMN IF NOT EXISTS `priority` TINYINT UNSIGNED DEFAULT 5 AFTER `reply_to`",
        "ALTER TABLE `email_queue` ADD COLUMN IF NOT EXISTS `headers` TEXT DEFAULT NULL AFTER `priority`",
        "ALTER TABLE `email_queue` ADD COLUMN IF NOT EXISTS `max_attempts` TINYINT UNSIGNED DEFAULT 3 AFTER `attempts`",
        "ALTER TABLE `email_queue` ADD COLUMN IF NOT EXISTS `next_retry_at` DATETIME DEFAULT NULL AFTER `last_attempt_at`",
        "ALTER TABLE `email_queue` ADD COLUMN IF NOT EXISTS `processing_at` DATETIME DEFAULT NULL AFTER `next_retry_at`",
        "ALTER TABLE `email_queue` ADD COLUMN IF NOT EXISTS `failed_at` DATETIME DEFAULT NULL AFTER `sent_at`",
        "ALTER TABLE `email_queue` ADD COLUMN IF NOT EXISTS `message_id` VARCHAR(255) DEFAULT NULL AFTER `failed_at`",
        "ALTER TABLE `email_queue` ADD COLUMN IF NOT EXISTS `provider_message_id` VARCHAR(255) DEFAULT NULL AFTER `message_id`",
        "ALTER TABLE `email_queue` ADD COLUMN IF NOT EXISTS `smtp_response` TEXT DEFAULT NULL AFTER `provider_message_id`",
        "ALTER TABLE `email_queue` ADD COLUMN IF NOT EXISTS `batch_id` VARCHAR(100) DEFAULT NULL AFTER `smtp_response`",
        "ALTER TABLE `email_queue` ADD INDEX IF NOT EXISTS `idx_tenant` (`tenant_id`)",
        "ALTER TABLE `email_queue` ADD INDEX IF NOT EXISTS `idx_batch` (`batch_id`)"
    ];
    
    foreach ($queries as $query) {
        try {
            $db->exec($query);
            echo "Executed: " . substr($query, 0, 50) . "...\n";
        } catch (Exception $e) {
            echo "Note: " . $e->getMessage() . "\n";
        }
    }
    
    echo "Table schema update complete!\n";
    
} catch (Exception $e) {
    echo "CRITICAL ERROR: " . $e->getMessage() . "\n";
}
?>
