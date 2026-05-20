-- WhatsApp announcement notification log.
-- Run this on each school database. The admin pages also create/upgrade
-- the table automatically when the connected database user has CREATE rights.

CREATE TABLE IF NOT EXISTS `whatsapp_notifications` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `school_id` int(10) UNSIGNED NOT NULL,
  `announcement_id` int(10) UNSIGNED DEFAULT NULL,
  `feature` varchar(50) NOT NULL DEFAULT 'announcement',
  `reference_id` int(10) UNSIGNED DEFAULT NULL,
  `recipient_user_id` int(10) UNSIGNED DEFAULT NULL,
  `recipient_type` varchar(30) NOT NULL,
  `recipient_name` varchar(190) DEFAULT NULL,
  `phone` varchar(32) NOT NULL,
  `template_name` varchar(190) NOT NULL,
  `message_preview` text DEFAULT NULL,
  `status` enum('sent','failed','skipped') NOT NULL DEFAULT 'skipped',
  `provider_message_id` varchar(190) DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  `provider_response` mediumtext DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `sent_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_school_announcement` (`school_id`, `announcement_id`),
  KEY `idx_school_feature` (`school_id`, `feature`, `reference_id`),
  KEY `idx_status` (`status`),
  KEY `idx_phone` (`phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
