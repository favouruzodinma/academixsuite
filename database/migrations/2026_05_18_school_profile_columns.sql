-- Migration: ensure every column the school-profile editor and public
-- landing page use exists on the platform `schools` table.
--
-- Run once on the academixsuite_platform database. Each statement uses
-- `ADD COLUMN IF NOT EXISTS` so the file is safe to re-run.

ALTER TABLE schools
  ADD COLUMN IF NOT EXISTS landing_badge_text          VARCHAR(120)  NULL AFTER vision_statement,
  ADD COLUMN IF NOT EXISTS landing_headline            VARCHAR(255)  NULL,
  ADD COLUMN IF NOT EXISTS landing_subheadline         TEXT          NULL,
  ADD COLUMN IF NOT EXISTS landing_primary_cta_text    VARCHAR(60)   NULL,
  ADD COLUMN IF NOT EXISTS landing_secondary_cta_text  VARCHAR(60)   NULL,
  ADD COLUMN IF NOT EXISTS landing_intro_title         VARCHAR(255)  NULL,
  ADD COLUMN IF NOT EXISTS landing_intro_text          TEXT          NULL,
  ADD COLUMN IF NOT EXISTS landing_highlight_title     VARCHAR(255)  NULL,
  ADD COLUMN IF NOT EXISTS landing_highlight_text      TEXT          NULL,
  ADD COLUMN IF NOT EXISTS landing_cta_title           VARCHAR(255)  NULL,
  ADD COLUMN IF NOT EXISTS landing_cta_text            TEXT          NULL,
  ADD COLUMN IF NOT EXISTS landing_hero_image          VARCHAR(500)  NULL,
  ADD COLUMN IF NOT EXISTS landing_feature_image       VARCHAR(500)  NULL,
  ADD COLUMN IF NOT EXISTS landing_programs            JSON          NULL,
  ADD COLUMN IF NOT EXISTS landing_testimonials        JSON          NULL,
  ADD COLUMN IF NOT EXISTS primary_color               VARCHAR(7)    NULL,
  ADD COLUMN IF NOT EXISTS secondary_color             VARCHAR(7)    NULL;

-- These three tables are read by school_profile.php; create them if any
-- legacy install is missing them.
CREATE TABLE IF NOT EXISTS school_contacts (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  school_id    BIGINT UNSIGNED NOT NULL,
  type         ENUM('email','phone','address','website','whatsapp','social') NOT NULL DEFAULT 'phone',
  label        VARCHAR(120) NULL,
  value        VARCHAR(500) NOT NULL,
  is_primary   TINYINT(1) NOT NULL DEFAULT 0,
  sort_order   INT NOT NULL DEFAULT 0,
  created_at   DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_school (school_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS school_facilities (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  school_id    BIGINT UNSIGNED NOT NULL,
  name         VARCHAR(160) NOT NULL,
  description  TEXT NULL,
  icon         VARCHAR(80) NULL,
  is_active    TINYINT(1) NOT NULL DEFAULT 1,
  sort_order   INT NOT NULL DEFAULT 0,
  KEY idx_school (school_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS school_gallery (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  school_id    BIGINT UNSIGNED NOT NULL,
  image_url    VARCHAR(500) NOT NULL,
  caption      VARCHAR(255) NULL,
  type         VARCHAR(40) NOT NULL DEFAULT 'campus',
  sort_order   INT NOT NULL DEFAULT 0,
  created_at   DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_school (school_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Public review submission target.
CREATE TABLE IF NOT EXISTS school_reviews (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  school_id     BIGINT UNSIGNED NOT NULL,
  parent_name   VARCHAR(150) NOT NULL,
  student_name  VARCHAR(150) NULL,
  rating        TINYINT UNSIGNED NOT NULL DEFAULT 5,
  comment       TEXT NOT NULL,
  is_approved   TINYINT(1) NOT NULL DEFAULT 0,
  helpful_count INT UNSIGNED NOT NULL DEFAULT 0,
  submitter_ip  VARCHAR(45) NULL,
  created_at    DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_school_approved (school_id, is_approved, created_at),
  KEY idx_school_ip       (school_id, submitter_ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- If the table already existed without the IP column, add it.
ALTER TABLE school_reviews
  ADD COLUMN IF NOT EXISTS submitter_ip  VARCHAR(45) NULL,
  ADD COLUMN IF NOT EXISTS helpful_count INT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS student_name  VARCHAR(150) NULL;
