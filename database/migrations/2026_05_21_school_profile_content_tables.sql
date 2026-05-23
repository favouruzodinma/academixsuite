-- Public school profile content controls.
-- These tables live in academixsuite_platform because tenant/school_profile.php
-- resolves the school first from the platform database, then pulls public content.

CREATE TABLE IF NOT EXISTS `school_profile_settings` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `school_id` int(10) UNSIGNED NOT NULL,
  `setting_key` varchar(120) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_school_profile_setting` (`school_id`, `setting_key`),
  KEY `idx_school_profile_settings_school` (`school_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `school_profile_faqs` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `school_id` int(10) UNSIGNED NOT NULL,
  `question` varchar(255) NOT NULL,
  `answer` text NOT NULL,
  `sort_order` int(10) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_school_profile_faqs_school` (`school_id`, `is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `school_profile_blogs` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `school_id` int(10) UNSIGNED NOT NULL,
  `title` varchar(255) NOT NULL,
  `excerpt` text DEFAULT NULL,
  `content` mediumtext DEFAULT NULL,
  `image_url` varchar(500) DEFAULT NULL,
  `category` varchar(120) NOT NULL DEFAULT 'Education',
  `author_name` varchar(180) DEFAULT NULL,
  `published_at` datetime DEFAULT NULL,
  `sort_order` int(10) NOT NULL DEFAULT 0,
  `is_published` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_school_profile_blogs_school` (`school_id`, `is_published`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `school_profile_slides` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `school_id` int(10) UNSIGNED NOT NULL,
  `title` varchar(255) DEFAULT NULL,
  `subtitle` text DEFAULT NULL,
  `image_url` varchar(500) NOT NULL,
  `button_label` varchar(120) DEFAULT NULL,
  `button_url` varchar(500) DEFAULT NULL,
  `sort_order` int(10) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_school_profile_slides_school` (`school_id`, `is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
