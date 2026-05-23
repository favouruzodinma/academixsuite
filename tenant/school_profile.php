<?php
/**
 * Public school landing page.
 *
 * Served as:
 * https://{school-slug}.academixsuite.com/
 */

ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/school_profile.log');

if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/../includes/session_config.php';
    session_start(academix_session_options());
}

$autoloadPath = __DIR__ . '/../includes/autoload.php';
if (!is_file($autoloadPath)) {
    http_response_code(500);
    echo 'System configuration error. Please contact administrator.';
    exit;
}

require_once $autoloadPath;

if (!function_exists('school_profile_e')) {
    function school_profile_e($value) {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('school_profile_value')) {
    function school_profile_value(array $source, string $key, $default = '') {
        return array_key_exists($key, $source) && $source[$key] !== null && $source[$key] !== ''
            ? $source[$key]
            : $default;
    }
}

if (!function_exists('school_profile_table_exists')) {
    function school_profile_table_exists(PDO $db, string $table): bool {
        try {
            $stmt = $db->prepare('SHOW TABLES LIKE ?');
            $stmt->execute([$table]);
            return (bool) $stmt->fetchColumn();
        } catch (Throwable $e) {
            error_log('Table check failed for ' . $table . ': ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('school_profile_column_exists')) {
    function school_profile_column_exists(PDO $db, string $table, string $column): bool {
        try {
            $safeTable = str_replace('`', '', $table);
            $stmt = $db->prepare("SHOW COLUMNS FROM `{$safeTable}` LIKE ?");
            $stmt->execute([$column]);
            return (bool) $stmt->fetchColumn();
        } catch (Throwable $e) {
            error_log('Column check failed for ' . $table . '.' . $column . ': ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('school_profile_ensure_reviews_table')) {
    function school_profile_ensure_reviews_table(PDO $db): bool {
        try {
            $db->exec("
                CREATE TABLE IF NOT EXISTS `school_reviews` (
                    `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
                    `school_id` int(10) UNSIGNED NOT NULL,
                    `parent_name` varchar(255) NOT NULL,
                    `parent_email` varchar(255) NOT NULL,
                    `student_name` varchar(255) DEFAULT NULL,
                    `rating` tinyint(3) UNSIGNED NOT NULL DEFAULT 5,
                    `comment` text NOT NULL,
                    `is_approved` tinyint(1) NOT NULL DEFAULT 0,
                    `helpful_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
                    `submitter_ip` varchar(45) DEFAULT NULL,
                    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_school_reviews_school` (`school_id`),
                    KEY `idx_school_reviews_approved` (`school_id`, `is_approved`, `created_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            return true;
        } catch (Throwable $e) {
            error_log('Could not ensure school_reviews table: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('school_profile_ensure_public_content_tables')) {
    function school_profile_ensure_public_content_tables(PDO $db): bool {
        try {
            $db->exec("
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            $db->exec("
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            $db->exec("
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            $db->exec("
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            return true;
        } catch (Throwable $e) {
            error_log('Could not ensure school profile content tables: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('school_profile_default_settings')) {
    function school_profile_default_settings(): array {
        return [
            'nav_home' => 'Home',
            'nav_about' => 'About Us',
            'nav_subjects' => 'Subjects',
            'nav_admission' => 'Admission',
            'nav_facilities' => 'Facilities',
            'nav_gallery' => 'Gallery',
            'nav_reviews' => 'Reviews',
            'nav_contact' => 'Contact',
            'browser_title_suffix' => 'School Profile',
            'hero_secondary_link_label' => 'How it works',
            'trusted_heading' => 'Trusted by families and learners across {location}',
            'stat_students_label' => 'Students supported',
            'stat_teachers_label' => 'Teaching staff',
            'stat_classes_label' => 'Active classes',
            'stat_subjects_label' => 'Subjects',
            'stat_curriculum_label' => 'Curriculum',
            'stat_years_label' => 'Years',
            'stat_teachers_badge_label' => 'Teachers',
            'about_badge' => 'About Us',
            'about_cta_label' => 'View more details',
            'experience_badge_label' => "Years of\nexperience",
            'programs_badge' => 'Subjects',
            'programs_heading' => 'Our Popular Learning Areas',
            'program_default_title' => 'Learning Area',
            'program_default_description' => 'Structured learning for every student.',
            'testimonial_default_name' => 'Parent Community',
            'facilities_badge' => 'Facilities',
            'facilities_heading' => 'Campus Spaces Built for Learning',
            'facility_default_title' => 'Campus Facility',
            'facility_default_description' => 'Designed for safe, engaging student development.',
            'faq_badge' => 'FAQ',
            'faq_heading' => 'Frequently Asked Questions',
            'faq_image_button_label' => 'Admission Guide',
            'updates_badge' => 'Updates',
            'updates_heading' => 'Latest News & Events',
            'updates_default_label' => 'School',
            'updates_default_title' => 'Admissions and school updates',
            'gallery_badge' => 'Gallery',
            'gallery_heading' => 'Life at {school}',
            'gallery_intro' => 'A glimpse of the school environment, learning moments, and student activities.',
            'slider_badge' => 'Gallery',
            'slider_heading' => 'Featured moments from {school}',
            'gallery_default_caption_1' => 'Learning',
            'gallery_default_caption_2' => 'Campus',
            'gallery_default_caption_3' => 'Community',
            'reviews_badge' => 'Reviews',
            'reviews_heading' => 'What Parents Say',
            'reviews_intro' => 'Reviews are published after approval by the school.',
            'reviews_empty' => 'Be the first verified parent to leave a review.',
            'review_form_heading' => 'Share your experience',
            'review_form_intro' => 'Only parents or guardians with an email on the school account can post reviews.',
            'review_success_message' => 'Thank you. Your review has been submitted for moderation.',
            'review_csrf_error' => 'Your form session expired. Please refresh and try again.',
            'review_unavailable_error' => 'Reviews are not available right now.',
            'review_name_comment_error' => 'Please enter your name and a short comment.',
            'review_invalid_email_error' => 'Please enter a valid email address.',
            'review_invalid_rating_error' => 'Please choose a rating from 1 to 5.',
            'review_comment_too_long_error' => 'Please keep your review under 1000 characters.',
            'review_parent_email_required_error' => 'Only parents or guardians with an email on the school account can post reviews.',
            'review_duplicate_error' => 'Please wait before submitting another review.',
            'review_submit_error' => 'Could not submit your review. Please try again.',
            'review_name_label' => 'Your name *',
            'review_email_label' => 'Parent email *',
            'review_student_label' => 'Student name',
            'review_rating_label' => 'Rating *',
            'review_rating_placeholder' => 'Select rating',
            'review_comment_label' => 'Your review *',
            'review_submit_label' => 'Submit review',
            'review_rating_options' => '{"5":"Excellent","4":"Very good","3":"Good","2":"Fair","1":"Poor"}',
            'admission_badge' => 'Admission',
            'admission_deadline_label' => 'Deadline',
            'admission_success_prefix' => 'Application submitted successfully',
            'admission_closed_prefix' => 'Admissions are currently {admission_status}. Please contact the school office.',
            'admission_closed_error' => 'Admissions are not open at this time.',
            'admission_parent_first_name_label' => 'Parent First Name *',
            'admission_parent_last_name_label' => 'Parent Last Name *',
            'admission_parent_email_label' => 'Parent Email *',
            'admission_parent_phone_label' => 'Parent Phone *',
            'admission_parent_address_label' => 'Parent Address',
            'admission_student_first_name_label' => 'Student First Name *',
            'admission_student_last_name_label' => 'Student Last Name *',
            'admission_gender_label' => 'Gender *',
            'admission_gender_placeholder' => 'Select gender',
            'admission_gender_options' => '{"male":"Male","female":"Female","other":"Other"}',
            'admission_dob_label' => 'Date of Birth *',
            'admission_class_label' => 'Preferred Class *',
            'admission_class_placeholder' => 'Example: JSS 1',
            'admission_previous_school_label' => 'Previous School',
            'admission_enrollment_type_label' => 'Enrollment Type',
            'admission_enrollment_type_options' => '{"new":"New student","transfer":"Transfer","re_enrollment":"Re-enrollment"}',
            'admission_year_label' => 'Academic Year *',
            'admission_term_label' => 'Academic Term',
            'admission_term_placeholder' => 'First term',
            'admission_documents_label' => 'Documents',
            'admission_special_requirements_label' => 'Special Requirements',
            'admission_special_requirements_placeholder' => 'Medical notes, learning support needs, or additional information.',
            'admission_submit_label' => 'Submit Application',
            'contact_badge' => 'Contact',
            'contact_heading' => 'Visit, call, or send a message',
            'contact_intro' => 'Use these details for admissions, parent support, and school visits.',
            'contact_phone_label' => 'Our Phone',
            'contact_email_label' => 'Our Email',
            'contact_address_label' => 'Our Address',
            'contact_missing_value' => 'Not published',
            'footer_credit' => 'Made with AcademixSuite.',
            'footer_nav_home' => 'Home',
            'footer_nav_about' => 'About Us',
            'footer_nav_subjects' => 'Subjects',
            'footer_nav_admission' => 'Admission',
            'footer_nav_gallery' => 'Gallery',
            'footer_nav_contact' => 'Contact',
            'theme_background_color' => '#fbfff7',
            'theme_hero_background_color' => '#14382f',
            'theme_footer_background_color' => '#14382f',
            'theme_card_background_color' => '#ffffff'
        ];
    }
}

if (!function_exists('school_profile_seed_settings')) {
    function school_profile_seed_settings(PDO $db, int $schoolId, array $settings): void {
        try {
            $stmt = $db->prepare('INSERT IGNORE INTO school_profile_settings (school_id, setting_key, setting_value) VALUES (?, ?, ?)');
            foreach ($settings as $key => $value) {
                $stmt->execute([$schoolId, $key, $value]);
            }
        } catch (Throwable $e) {
            error_log('Could not seed school profile settings: ' . $e->getMessage());
        }
    }
}

if (!function_exists('school_profile_seed_faqs')) {
    function school_profile_seed_faqs(PDO $db, int $schoolId): void {
        try {
            $countStmt = $db->prepare('SELECT COUNT(*) FROM school_profile_faqs WHERE school_id = ?');
            $countStmt->execute([$schoolId]);
            if ((int) $countStmt->fetchColumn() > 0) {
                return;
            }

            $faqs = [
                ['What curriculum does the school use?', '{school} uses the {curriculum} curriculum for a structured academic experience.'],
                ['Are admissions currently open?', 'Admissions are currently {admission_status}{admission_deadline_sentence}.'],
                ['How can parents contact the school?', 'Parents can use the contact section, call {phone}, or email {email}.'],
                ['Can parents leave reviews?', 'Yes. Reviews are accepted only from parent or guardian emails registered in the school account.']
            ];
            $stmt = $db->prepare('INSERT INTO school_profile_faqs (school_id, question, answer, sort_order, is_active) VALUES (?, ?, ?, ?, 1)');
            foreach ($faqs as $index => $faq) {
                $stmt->execute([$schoolId, $faq[0], $faq[1], ($index + 1) * 10]);
            }
        } catch (Throwable $e) {
            error_log('Could not seed school profile FAQs: ' . $e->getMessage());
        }
    }
}

if (!function_exists('school_profile_load_settings')) {
    function school_profile_load_settings(PDO $db, int $schoolId): array {
        try {
            $stmt = $db->prepare('SELECT setting_key, setting_value FROM school_profile_settings WHERE school_id = ?');
            $stmt->execute([$schoolId]);
            return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'setting_value', 'setting_key');
        } catch (Throwable $e) {
            error_log('Could not load school profile settings: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('school_profile_load_rows')) {
    function school_profile_load_rows(PDO $db, string $table, int $schoolId, string $where, string $order, int $limit = 12): array {
        if (!school_profile_table_exists($db, $table)) {
            return [];
        }

        try {
            $safeTable = str_replace('`', '', $table);
            $sql = "SELECT * FROM `{$safeTable}` WHERE school_id = ?";
            if ($where !== '') {
                $sql .= ' AND ' . $where;
            }
            $sql .= ' ORDER BY ' . $order . ' LIMIT ' . max(1, $limit);
            $stmt = $db->prepare($sql);
            $stmt->execute([$schoolId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('Could not load rows from ' . $table . ': ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('school_profile_setting')) {
    function school_profile_setting(array $settings, string $key, string $default = ''): string {
        return array_key_exists($key, $settings) && trim((string) $settings[$key]) !== ''
            ? (string) $settings[$key]
            : $default;
    }
}

if (!function_exists('school_profile_template')) {
    function school_profile_template(string $value, array $tokens): string {
        return strtr($value, $tokens);
    }
}

if (!function_exists('school_profile_setting_options')) {
    function school_profile_setting_options(array $settings, string $key, array $default): array {
        $raw = school_profile_setting($settings, $key, '');
        if ($raw === '') {
            return $default;
        }

        $decoded = json_decode($raw, true);
        return json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : $default;
    }
}

if (!function_exists('school_profile_color')) {
    function school_profile_color($value, string $fallback): string {
        $value = trim((string) $value);
        return preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? $value : $fallback;
    }
}

if (!function_exists('school_profile_parent_email_exists')) {
    function school_profile_parent_email_exists(?PDO $schoolDb, int $schoolId, string $email): bool {
        if (!$schoolDb) {
            return false;
        }

        $email = strtolower(trim($email));
        if ($email === '') {
            return false;
        }

        $queries = [];

        if (
            school_profile_table_exists($schoolDb, 'guardians') &&
            school_profile_table_exists($schoolDb, 'users') &&
            school_profile_column_exists($schoolDb, 'users', 'email')
        ) {
            $where = ['g.school_id = ?', 'u.school_id = ?', 'LOWER(TRIM(u.email)) = ?'];
            $params = [$schoolId, $schoolId, $email];
            if (school_profile_column_exists($schoolDb, 'users', 'user_type')) {
                $where[] = "u.user_type = 'parent'";
            }
            if (school_profile_column_exists($schoolDb, 'users', 'is_active')) {
                $where[] = 'COALESCE(u.is_active, 1) = 1';
            }
            $queries[] = [
                'sql' => 'SELECT 1 FROM guardians g INNER JOIN users u ON u.id = g.user_id WHERE ' . implode(' AND ', $where) . ' LIMIT 1',
                'params' => $params
            ];
        }

        if (school_profile_table_exists($schoolDb, 'users') && school_profile_column_exists($schoolDb, 'users', 'email')) {
            $where = ['school_id = ?', 'LOWER(TRIM(email)) = ?'];
            $params = [$schoolId, $email];
            if (school_profile_column_exists($schoolDb, 'users', 'user_type')) {
                $where[] = "user_type = 'parent'";
            }
            if (school_profile_column_exists($schoolDb, 'users', 'is_active')) {
                $where[] = 'COALESCE(is_active, 1) = 1';
            }
            $queries[] = [
                'sql' => 'SELECT 1 FROM users WHERE ' . implode(' AND ', $where) . ' LIMIT 1',
                'params' => $params
            ];
        }

        if (
            school_profile_table_exists($schoolDb, 'parent_portal_access') &&
            school_profile_table_exists($schoolDb, 'users') &&
            school_profile_column_exists($schoolDb, 'users', 'email')
        ) {
            $where = ['ppa.school_id = ?', 'LOWER(TRIM(u.email)) = ?'];
            $params = [$schoolId, $email];
            if (school_profile_column_exists($schoolDb, 'parent_portal_access', 'is_active')) {
                $where[] = 'COALESCE(ppa.is_active, 1) = 1';
            }
            $queries[] = [
                'sql' => 'SELECT 1 FROM parent_portal_access ppa INNER JOIN users u ON u.id = ppa.parent_id WHERE ' . implode(' AND ', $where) . ' LIMIT 1',
                'params' => $params
            ];
        }

        foreach (['guardian_email', 'parent_email'] as $column) {
            if (school_profile_table_exists($schoolDb, 'students') && school_profile_column_exists($schoolDb, 'students', $column)) {
                $queries[] = [
                    'sql' => "SELECT 1 FROM students WHERE school_id = ? AND LOWER(TRIM(`{$column}`)) = ? LIMIT 1",
                    'params' => [$schoolId, $email]
                ];
            }
        }

        foreach ($queries as $query) {
            try {
                $stmt = $schoolDb->prepare($query['sql']);
                $stmt->execute($query['params']);
                if ($stmt->fetchColumn()) {
                    return true;
                }
            } catch (Throwable $e) {
                error_log('Parent email verification query failed: ' . $e->getMessage());
            }
        }

        return false;
    }
}

if (!function_exists('school_profile_json')) {
    function school_profile_json($value): array {
        if (is_array($value)) {
            return $value;
        }

        $value = trim((string) $value);
        if ($value === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }
}

if (!function_exists('school_profile_initials')) {
    function school_profile_initials(string $name): string {
        $parts = preg_split('/\s+/', trim($name));
        $initials = '';
        foreach ($parts as $part) {
            if ($part !== '') {
                $initials .= strtoupper(substr($part, 0, 1));
            }
        }

        return substr($initials ?: 'AS', 0, 2);
    }
}

if (!function_exists('school_profile_asset_url')) {
    function school_profile_asset_url($path): string {
        $path = trim((string) $path);
        if ($path === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }

        return '/' . ltrim($path, '/');
    }
}

if (!function_exists('school_profile_asset_exists')) {
    function school_profile_asset_exists($path): bool {
        $path = trim((string) $path);
        if ($path === '') {
            return false;
        }

        if (preg_match('#^https?://#i', $path)) {
            return true;
        }

        $root = defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__);
        return is_file($root . '/' . ltrim($path, '/'));
    }
}

if (!function_exists('school_profile_first_image')) {
    function school_profile_first_image(array $paths, string $fallback = ''): string {
        foreach ($paths as $path) {
            if (school_profile_asset_exists($path)) {
                return school_profile_asset_url($path);
            }
        }

        return school_profile_asset_url($fallback);
    }
}

if (!function_exists('school_profile_format_date')) {
    function school_profile_format_date($date): string {
        if (!$date) {
            return '';
        }

        $timestamp = strtotime((string) $date);
        return $timestamp ? date('M j, Y', $timestamp) : '';
    }
}

if (!function_exists('school_profile_count_rows')) {
    function school_profile_count_rows(PDO $db, string $table, string $extraWhere = ''): ?int {
        if (!school_profile_table_exists($db, $table)) {
            return null;
        }

        try {
            $safeTable = str_replace('`', '', $table);
            $sql = "SELECT COUNT(*) FROM `{$safeTable}`";
            if ($extraWhere !== '') {
                $sql .= ' WHERE ' . $extraWhere;
            }
            return (int) $db->query($sql)->fetchColumn();
        } catch (Throwable $e) {
            error_log('Count query failed for ' . $table . ': ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('school_profile_normalize_facilities')) {
    function school_profile_normalize_facilities(array $school, array $dbFacilities): array {
        $facilities = [];

        foreach ($dbFacilities as $facility) {
            $name = trim((string) ($facility['name'] ?? ''));
            if ($name !== '') {
                $facilities[] = [
                    'title' => $name,
                    'description' => trim((string) ($facility['description'] ?? '')),
                    'tag' => 'Facility'
                ];
            }
        }

        foreach (school_profile_json($school['facilities'] ?? '') as $item) {
            $name = is_array($item) ? ($item['name'] ?? $item['title'] ?? '') : $item;
            $description = is_array($item) ? ($item['description'] ?? '') : '';
            if (trim((string) $name) !== '') {
                $facilities[] = [
                    'title' => trim((string) $name),
                    'description' => trim((string) $description),
                    'tag' => 'Facility'
                ];
            }
        }

        $serviceMap = [
            'transportation_available' => ['Safe Transport', 'Optional school transportation for eligible routes.'],
            'boarding_available' => ['Boarding Life', 'Residential support for students who need boarding.'],
            'meal_provided' => ['Meal Program', 'Meal support during the school day.']
        ];

        foreach ($serviceMap as $key => $service) {
            if (!empty($school[$key])) {
                $facilities[] = ['title' => $service[0], 'description' => $service[1], 'tag' => 'Service'];
            }
        }

        $seen = [];
        return array_values(array_filter($facilities, static function ($item) use (&$seen) {
            $key = strtolower($item['title']);
            if (isset($seen[$key])) {
                return false;
            }
            $seen[$key] = true;
            return true;
        }));
    }
}

if (!function_exists('school_profile_process_enrollment')) {
    function school_profile_process_enrollment(PDO $db, array $school): string {
        if (function_exists('validateCsrfToken') && !validateCsrfToken($_POST['csrf_token'] ?? '')) {
            return 'Your form session expired. Please refresh the page and try again.';
        }

        if (!school_profile_table_exists($db, 'enrollment_requests')) {
            return 'Admissions are not fully configured for this school yet.';
        }

        $required = [
            'parent_first_name' => 'Parent first name',
            'parent_last_name' => 'Parent last name',
            'parent_email' => 'Parent email',
            'parent_phone' => 'Parent phone',
            'student_first_name' => 'Student first name',
            'student_last_name' => 'Student last name',
            'student_gender' => 'Student gender',
            'student_dob' => 'Student date of birth',
            'student_grade' => 'Preferred class',
            'academic_year' => 'Academic year'
        ];

        $errors = [];
        foreach ($required as $field => $label) {
            if (trim((string) ($_POST[$field] ?? '')) === '') {
                $errors[] = $label . ' is required.';
            }
        }

        if (!empty($_POST['parent_email']) && !filter_var($_POST['parent_email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid parent email address.';
        }

        if (!empty($_POST['student_dob']) && !DateTime::createFromFormat('Y-m-d', $_POST['student_dob'])) {
            $errors[] = 'Please enter a valid student date of birth.';
        }

        if ($errors) {
            return implode('<br>', array_map('school_profile_e', $errors));
        }

        $clean = static function ($key, $default = '') {
            return trim(strip_tags((string) ($_POST[$key] ?? $default)));
        };

        $requestNumber = 'ENR-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));

        try {
            $db->beginTransaction();

            $stmt = $db->prepare("
                INSERT INTO enrollment_requests (
                    school_id, request_number, parent_first_name, parent_last_name,
                    parent_email, parent_phone, parent_address, student_first_name,
                    student_last_name, student_gender, student_date_of_birth,
                    student_grade_level, student_previous_school, enrollment_type,
                    academic_year, academic_term, special_requirements, status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
            ");

            $stmt->execute([
                $school['id'],
                $requestNumber,
                $clean('parent_first_name'),
                $clean('parent_last_name'),
                $clean('parent_email'),
                $clean('parent_phone'),
                $clean('parent_address'),
                $clean('student_first_name'),
                $clean('student_last_name'),
                $clean('student_gender'),
                $_POST['student_dob'],
                $clean('student_grade'),
                $clean('student_previous_school'),
                $clean('enrollment_type', 'new'),
                $clean('academic_year'),
                $clean('academic_term'),
                $clean('special_requirements')
            ]);

            $enrollmentId = (int) $db->lastInsertId();
            $uploadedDocuments = [];

            if (!empty($_FILES['documents']['name']) && is_array($_FILES['documents']['name'])) {
                $uploadRoot = dirname(__DIR__) . '/uploads/enrollment/' . (int) $school['id'];
                if (!is_dir($uploadRoot)) {
                    mkdir($uploadRoot, 0755, true);
                }

                $allowedMimeTypes = ['application/pdf', 'image/jpeg', 'image/png', 'image/jpg'];
                foreach ($_FILES['documents']['name'] as $index => $originalName) {
                    if (($_FILES['documents']['error'][$index] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                        continue;
                    }

                    $tmpName = $_FILES['documents']['tmp_name'][$index] ?? '';
                    $fileSize = (int) ($_FILES['documents']['size'][$index] ?? 0);
                    $mimeType = function_exists('mime_content_type') ? mime_content_type($tmpName) : '';

                    if ($fileSize <= 0 || $fileSize > 5 * 1024 * 1024 || !in_array($mimeType, $allowedMimeTypes, true)) {
                        continue;
                    }

                    $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '', basename((string) $originalName));
                    $fileName = time() . '-' . bin2hex(random_bytes(3)) . '-' . $safeName;
                    $target = $uploadRoot . '/' . $fileName;

                    if (move_uploaded_file($tmpName, $target)) {
                        $uploadedDocuments[] = [
                            'name' => $safeName,
                            'path' => 'uploads/enrollment/' . (int) $school['id'] . '/' . $fileName,
                            'size' => $fileSize
                        ];
                    }
                }
            }

            if ($uploadedDocuments) {
                $updateStmt = $db->prepare('UPDATE enrollment_requests SET documents_submitted = ? WHERE id = ?');
                $updateStmt->execute([json_encode($uploadedDocuments), $enrollmentId]);

                if (school_profile_table_exists($db, 'enrollment_documents')) {
                    $docStmt = $db->prepare("
                        INSERT INTO enrollment_documents
                            (enrollment_request_id, document_type, document_name, file_path, file_size)
                        VALUES (?, 'application', ?, ?, ?)
                    ");

                    foreach ($uploadedDocuments as $document) {
                        $docStmt->execute([$enrollmentId, $document['name'], $document['path'], $document['size']]);
                    }
                }
            }

            $db->commit();

            $_SESSION['enrollment_success'] = true;
            $_SESSION['request_number'] = $requestNumber;

            header('Location: ' . strtok($_SERVER['REQUEST_URI'] ?? '/', '?') . '#admission');
            exit;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('Enrollment submission failed: ' . $e->getMessage());
            return 'We could not submit the application right now. Please try again.';
        }
    }
}

$schoolSlug = trim((string) ($_GET['slug'] ?? (function_exists('school_subdomain_slug') ? school_subdomain_slug() : '')), '/');
if (function_exists('redirect_legacy_school_url_to_subdomain')) {
    redirect_legacy_school_url_to_subdomain($schoolSlug, '', $_GET);
}

if ($schoolSlug === '' || !preg_match('/^[a-z0-9-]+$/i', $schoolSlug)) {
    http_response_code(404);
    echo 'School portal not found.';
    exit;
}

$school = null;
$contacts = [];
$facilities = [];
$gallery = [];
$reviews = [];
$profileSettings = [];
$profileFaqs = [];
$profileBlogs = [];
$profileSlides = [];
$announcements = [];
$events = [];
$stats = ['students' => 0, 'teachers' => 0, 'classes' => 0, 'subjects' => null];
$enrollmentError = '';
$enrollmentSuccess = false;
$requestNumber = '';
$schoolDb = null;

try {
    $platformDb = Database::getPlatformConnection();
    $hasPlans = school_profile_table_exists($platformDb, 'plans');
    $schoolSql = $hasPlans
        ? "SELECT s.*, p.name AS plan_name FROM schools s LEFT JOIN plans p ON s.plan_id = p.id WHERE s.slug = ? AND s.status IN ('active', 'trial') LIMIT 1"
        : "SELECT s.* FROM schools s WHERE s.slug = ? AND s.status IN ('active', 'trial') LIMIT 1";

    $stmt = $platformDb->prepare($schoolSql);
    $stmt->execute([$schoolSlug]);
    $school = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$school) {
        http_response_code(404);
        echo 'School not found or inactive.';
        exit;
    }

    $reviewsTableReady = school_profile_ensure_reviews_table($platformDb);
    $contentTablesReady = school_profile_ensure_public_content_tables($platformDb);
    if ($contentTablesReady) {
        school_profile_seed_settings($platformDb, (int) $school['id'], school_profile_default_settings());
        school_profile_seed_faqs($platformDb, (int) $school['id']);
        $profileSettings = school_profile_load_settings($platformDb, (int) $school['id']);
        $profileFaqs = school_profile_load_rows($platformDb, 'school_profile_faqs', (int) $school['id'], 'is_active = 1', 'sort_order ASC, id ASC', 20);
        $profileBlogs = school_profile_load_rows($platformDb, 'school_profile_blogs', (int) $school['id'], 'is_published = 1', 'sort_order ASC, COALESCE(published_at, created_at) DESC', 6);
        $profileSlides = school_profile_load_rows($platformDb, 'school_profile_slides', (int) $school['id'], 'is_active = 1', 'sort_order ASC, id ASC', 8);
    }

    if (school_profile_table_exists($platformDb, 'school_contacts')) {
        $stmt = $platformDb->prepare('SELECT * FROM school_contacts WHERE school_id = ? ORDER BY is_primary DESC, sort_order ASC, type ASC');
        $stmt->execute([$school['id']]);
        $contacts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if (!$contacts) {
        if (!empty($school['email'])) {
            $contacts[] = ['type' => 'email', 'label' => 'Admissions office', 'value' => $school['email'], 'is_primary' => 1];
        }
        if (!empty($school['phone'])) {
            $contacts[] = ['type' => 'phone', 'label' => 'Phone', 'value' => $school['phone'], 'is_primary' => 1];
        }
        if (!empty($school['address'])) {
            $contacts[] = ['type' => 'address', 'label' => 'Campus address', 'value' => $school['address'], 'is_primary' => 1];
        }
        if (!empty($school['website'])) {
            $contacts[] = ['type' => 'website', 'label' => 'Website', 'value' => $school['website'], 'is_primary' => 0];
        }
    }

    $dbFacilities = [];
    if (school_profile_table_exists($platformDb, 'school_facilities')) {
        $stmt = $platformDb->prepare('SELECT * FROM school_facilities WHERE school_id = ? AND is_active = 1 ORDER BY sort_order ASC, name ASC');
        $stmt->execute([$school['id']]);
        $dbFacilities = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    $facilities = school_profile_normalize_facilities($school, $dbFacilities);

    if (school_profile_table_exists($platformDb, 'school_gallery')) {
        $stmt = $platformDb->prepare('SELECT * FROM school_gallery WHERE school_id = ? ORDER BY sort_order ASC, created_at DESC LIMIT 12');
        $stmt->execute([$school['id']]);
        $gallery = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    foreach (school_profile_json($school['gallery_images'] ?? '') as $image) {
        $imageUrl = is_array($image) ? ($image['image_url'] ?? $image['url'] ?? $image['path'] ?? '') : (string) $image;
        $caption = is_array($image) ? ($image['caption'] ?? '') : '';
        if (trim($imageUrl) !== '') {
            $gallery[] = ['image_url' => $imageUrl, 'caption' => $caption, 'type' => 'campus'];
        }
    }

    if ($reviewsTableReady) {
        $stmt = $platformDb->prepare("
            SELECT *
            FROM school_reviews
            WHERE school_id = ? AND is_approved = 1
            ORDER BY helpful_count DESC, created_at DESC
            LIMIT 6
        ");
        $stmt->execute([$school['id']]);
        $reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $stats['students'] = (int) school_profile_value($school, 'student_count', 0);
    $stats['teachers'] = (int) school_profile_value($school, 'teacher_count', 0);
    $stats['classes'] = (int) school_profile_value($school, 'class_count', 0);

    if (!empty($school['database_name'])) {
        try {
            $schoolDb = Database::getSchoolConnection($school['database_name']);
            foreach ([
                'students' => ['students', ''],
                'teachers' => ['teachers', ''],
                'classes' => ['classes', 'is_active = 1'],
                'subjects' => ['subjects', 'is_active = 1']
            ] as $key => $countConfig) {
                $count = school_profile_count_rows($schoolDb, $countConfig[0], $countConfig[1]);
                if ($count !== null) {
                    $stats[$key] = $count;
                }
            }

            if (school_profile_table_exists($schoolDb, 'announcements')) {
                $stmt = $schoolDb->prepare("
                    SELECT title, description, start_date, end_date, created_at
                    FROM announcements
                    WHERE school_id = ? AND is_published = 1
                    ORDER BY created_at DESC
                    LIMIT 3
                ");
                $stmt->execute([$school['id']]);
                $announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            if (school_profile_table_exists($schoolDb, 'events')) {
                $stmt = $schoolDb->prepare("
                    SELECT title, description, type, start_date, start_time, venue
                    FROM events
                    WHERE school_id = ? AND is_public = 1
                    ORDER BY start_date DESC, start_time DESC
                    LIMIT 3
                ");
                $stmt->execute([$school['id']]);
                $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        } catch (Throwable $e) {
            error_log('School database profile data unavailable: ' . $e->getMessage());
        }
    }

    $enrollmentStatus = school_profile_value($school, 'admission_status', 'open');
    $deadline = school_profile_value($school, 'admission_deadline', null);
    if ($deadline && strtotime($deadline) && strtotime($deadline) < strtotime('today')) {
        $enrollmentStatus = 'closed';
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['enrollment_submit'])) {
        $enrollmentError = $enrollmentStatus === 'open'
            ? school_profile_process_enrollment($platformDb, $school)
            : school_profile_setting($profileSettings, 'admission_closed_error', 'Admissions are not open at this time.');
    }

    // ------------------------------------------------------------------
    // Public review submission. Reviews are inserted unapproved; school
    // admins moderate them from /admin/school-profile (Reviews tab).
    // ------------------------------------------------------------------
    $reviewError = '';
    $reviewSuccess = false;
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['review_submit'])) {
        if (function_exists('validateCsrfToken') && !validateCsrfToken($_POST['csrf_token'] ?? '')) {
            $reviewError = school_profile_setting($profileSettings, 'review_csrf_error', 'Your form session expired. Please refresh and try again.');
        } elseif (empty($reviewsTableReady)) {
            $reviewError = school_profile_setting($profileSettings, 'review_unavailable_error', 'Reviews are not available right now.');
        } else {
            $parentName  = trim(strip_tags((string) ($_POST['review_parent_name']  ?? '')));
            $parentEmail = trim(strip_tags((string) ($_POST['review_parent_email'] ?? '')));
            $studentName = trim(strip_tags((string) ($_POST['review_student_name'] ?? '')));
            $rating      = (int) ($_POST['review_rating']  ?? 0);
            $comment     = trim(strip_tags((string) ($_POST['review_comment']     ?? '')));

            if ($parentName === '' || $comment === '') {
                $reviewError = school_profile_setting($profileSettings, 'review_name_comment_error', 'Please enter your name and a short comment.');
            } elseif ($parentEmail === '' || !filter_var($parentEmail, FILTER_VALIDATE_EMAIL)) {
                $reviewError = school_profile_setting($profileSettings, 'review_invalid_email_error', 'Please enter a valid email address.');
            } elseif ($rating < 1 || $rating > 5) {
                $reviewError = school_profile_setting($profileSettings, 'review_invalid_rating_error', 'Please choose a rating from 1 to 5.');
            } elseif (mb_strlen($comment) > 1000) {
                $reviewError = school_profile_setting($profileSettings, 'review_comment_too_long_error', 'Please keep your review under 1000 characters.');
            } elseif (!school_profile_parent_email_exists($schoolDb, (int) $school['id'], $parentEmail)) {
                $reviewError = school_profile_setting($profileSettings, 'review_parent_email_required_error', 'Only parents or guardians with an email on the school account can post reviews.');
            } else {
                try {
                    // Cheap dedupe: same IP can post one review per school per hour.
                    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
                    try {
                        $dup = $platformDb->prepare(
                            "SELECT 1 FROM school_reviews
                             WHERE school_id = ? AND submitter_ip = ?
                             AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
                             LIMIT 1"
                        );
                        $dup->execute([$school['id'], $ip]);
                        if ($dup->fetchColumn()) {
                            throw new RuntimeException('duplicate_review_window');
                        }
                    } catch (Throwable $eDup) {
                        if ($eDup instanceof RuntimeException && $eDup->getMessage() === 'duplicate_review_window') {
                            $reviewError = school_profile_setting($profileSettings, 'review_duplicate_error', 'Please wait before submitting another review.');
                        }
                        // submitter_ip exists only on newer installs.
                    }

                    if ($reviewError === '') {
                        $reviewColumns = (function () use ($platformDb) {
                            try {
                                return array_column($platformDb->query('SHOW COLUMNS FROM `school_reviews`')->fetchAll(PDO::FETCH_ASSOC), 'Field');
                            } catch (Throwable $e) { return []; }
                        })();

                        $cols = array_values(array_filter(
                            ['school_id', 'parent_name', 'parent_email', 'student_name', 'rating', 'comment', 'is_approved', 'submitter_ip', 'created_at'],
                            fn ($c) => $c === 'school_id' || $c === 'created_at' || in_array($c, $reviewColumns, true)
                        ));
                        $vals = [];
                        $placeholders = [];
                        foreach ($cols as $c) {
                            switch ($c) {
                                case 'school_id':    $vals[] = $school['id']; $placeholders[] = '?'; break;
                                case 'parent_name':  $vals[] = $parentName;  $placeholders[] = '?'; break;
                                case 'parent_email': $vals[] = $parentEmail; $placeholders[] = '?'; break;
                                case 'student_name': $vals[] = $studentName; $placeholders[] = '?'; break;
                                case 'rating':       $vals[] = $rating;      $placeholders[] = '?'; break;
                                case 'comment':      $vals[] = $comment;     $placeholders[] = '?'; break;
                                case 'is_approved':  $vals[] = 0;            $placeholders[] = '?'; break;
                                case 'submitter_ip': $vals[] = $ip;          $placeholders[] = '?'; break;
                                case 'created_at':   $placeholders[] = 'NOW()'; break;
                            }
                        }
                        $platformDb->prepare(
                            'INSERT INTO school_reviews (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', $placeholders) . ')'
                        )->execute($vals);
                        $reviewSuccess = true;
                    }
                } catch (Throwable $e) {
                    error_log('Review submission failed: ' . $e->getMessage());
                    $reviewError = school_profile_setting($profileSettings, 'review_submit_error', 'Could not submit your review. Please try again.');
                }
            }
        }
    }
} catch (Throwable $e) {
    error_log('School profile failed: ' . $e->getMessage());
    http_response_code(500);
    echo 'Error loading school information. Please try again later.';
    exit;
}

if (!empty($_SESSION['enrollment_success'])) {
    $enrollmentSuccess = true;
    $requestNumber = (string) ($_SESSION['request_number'] ?? '');
    unset($_SESSION['enrollment_success'], $_SESSION['request_number']);
}

$schoolName = (string) $school['name'];
$schoolInitials = school_profile_initials($schoolName);
$logoPath = (string) school_profile_value($school, 'logo_path', '');
$logoUrl = school_profile_asset_exists($logoPath) ? school_profile_asset_url($logoPath) : '';
$primaryColor = school_profile_color(school_profile_setting($profileSettings, 'theme_primary_color', (string) ($school['primary_color'] ?? '')), '#0b3d33');
$secondaryColor = school_profile_color(school_profile_setting($profileSettings, 'theme_secondary_color', (string) ($school['secondary_color'] ?? '')), '#a6ff3d');
$backgroundColor = school_profile_color(school_profile_setting($profileSettings, 'theme_background_color', '#fbfff7'), '#fbfff7');
$heroBackgroundColor = school_profile_color(school_profile_setting($profileSettings, 'theme_hero_background_color', '#14382f'), '#14382f');
$footerBackgroundColor = school_profile_color(school_profile_setting($profileSettings, 'theme_footer_background_color', $heroBackgroundColor), $heroBackgroundColor);
$cardBackgroundColor = school_profile_color(school_profile_setting($profileSettings, 'theme_card_background_color', '#ffffff'), '#ffffff');

$locationParts = array_filter([$school['city'] ?? '', $school['state'] ?? '', $school['country'] ?? '']);
$location = implode(', ', $locationParts);
$schoolType = ucwords(str_replace('_', ' ', (string) school_profile_value($school, 'school_type', 'school')));
$curriculum = school_profile_value($school, 'curriculum', 'Nigerian');
$loginUrl = function_exists('school_login_url') ? school_login_url($schoolSlug, false) : '/login.php';
$csrfToken = function_exists('generateCsrfToken') ? generateCsrfToken() : '';

$description = trim((string) school_profile_value($school, 'description', ''));
$mission = trim((string) school_profile_value($school, 'mission_statement', ''));
$vision = trim((string) school_profile_value($school, 'vision_statement', ''));
$principalMessage = trim((string) school_profile_value($school, 'principal_message', ''));

$landingBadge = school_profile_value($school, 'landing_badge_text', $enrollmentStatus === 'open' ? 'Admissions open' : 'School portal');
$landingHeadline = school_profile_value($school, 'landing_headline', 'Interactive learning that students love');
$landingSubheadline = school_profile_value(
    $school,
    'landing_subheadline',
    $mission ?: ($description ?: 'A friendly, structured school environment where students learn with confidence, creativity, and care.')
);
$primaryCta = school_profile_value($school, 'landing_primary_cta_text', 'Apply Now');
$secondaryCta = school_profile_value($school, 'landing_secondary_cta_text', 'Portal Login');
$introTitle = school_profile_value($school, 'landing_intro_title', 'Learning made fun, focused, and personal');
$introText = school_profile_value(
    $school,
    'landing_intro_text',
    $description ?: 'We combine strong academics, caring teachers, practical activities, and clear communication with parents.'
);
$highlightTitle = school_profile_value($school, 'landing_highlight_title', 'Explore our amazing educational world');
$highlightText = school_profile_value(
    $school,
    'landing_highlight_text',
    $vision ?: 'From reading and numbers to science, sports, creativity, and character, every child has room to grow.'
);
$ctaTitle = school_profile_value($school, 'landing_cta_title', 'Start your child\'s learning adventure today');
$ctaText = school_profile_value($school, 'landing_cta_text', 'Whether your child is just starting school or moving into a new stage, we are ready to help them feel known, supported, and challenged.');

$heroImage = school_profile_first_image([
    $school['landing_hero_image'] ?? '',
    $gallery[0]['image_url'] ?? '',
    'tenant/assets/images/thumbs/login-img.png'
], 'tenant/assets/images/thumbs/login-img.png');
$featureImage = school_profile_first_image([
    $school['landing_feature_image'] ?? '',
    $gallery[1]['image_url'] ?? '',
    'tenant/assets/images/thumbs/student-details-img.png'
], 'tenant/assets/images/thumbs/student-details-img.png');
$showcaseImages = [
    school_profile_first_image([
        $school['landing_showcase_image_1'] ?? '',
        $gallery[2]['image_url'] ?? '',
        'tenant/assets/images/thumbs/top-teacher-img1.png',
    ], 'tenant/assets/images/thumbs/top-teacher-img1.png'),
    school_profile_first_image([
        $school['landing_showcase_image_2'] ?? '',
        $gallery[3]['image_url'] ?? '',
        'tenant/assets/images/thumbs/top-teacher-img3.png',
    ], 'tenant/assets/images/thumbs/top-teacher-img3.png'),
    school_profile_first_image([
        $school['landing_showcase_image_3'] ?? '',
        $gallery[4]['image_url'] ?? '',
        'tenant/assets/images/thumbs/top-teacher-img5.png',
    ], 'tenant/assets/images/thumbs/top-teacher-img5.png'),
];
$circleImages = [
    $heroImage,
    $showcaseImages[0],
    $showcaseImages[1],
    $showcaseImages[2],
    school_profile_first_image([
        $school['landing_feature_image'] ?? '',
        $gallery[5]['image_url'] ?? '',
        'tenant/assets/images/thumbs/library-img1.png',
    ], 'tenant/assets/images/thumbs/library-img1.png')
];

$programs = school_profile_json($school['landing_programs'] ?? '');
if (!$programs) {
    foreach (array_slice($facilities, 0, 4) as $facility) {
        $programs[] = [
            'title' => $facility['title'],
            'description' => $facility['description'] ?: 'A structured part of the student learning experience.'
        ];
    }
}
if (!$programs) {
    $programs = [
        ['title' => 'ABCs & Reading', 'description' => 'Foundational literacy, vocabulary, handwriting, and confident expression.'],
        ['title' => 'Math & Numbers', 'description' => 'Practical numeracy, problem solving, and everyday number confidence.'],
        ['title' => 'Science Explorations', 'description' => 'Curiosity-led lessons, observation, experiments, and discovery.'],
        ['title' => 'Games & Sports', 'description' => 'Healthy movement, teamwork, confidence, and social growth.']
    ];
}

$testimonials = school_profile_json($school['landing_testimonials'] ?? '');
if (!$testimonials && $reviews) {
    foreach (array_slice($reviews, 0, 4) as $review) {
        $testimonials[] = [
            'name' => $review['parent_name'] ?? 'Parent',
            'role' => !empty($review['student_name']) ? 'Parent of ' . $review['student_name'] : 'Parent',
            'quote' => $review['comment'] ?? ''
        ];
    }
}
if (!$testimonials) {
    $testimonials = [
        ['name' => 'Parent Feedback', 'role' => $schoolName, 'quote' => 'Our children are supported with care, structure, and learning that feels alive.']
    ];
}

$announcementsAndEvents = [];
foreach ($announcements as $announcement) {
    $announcementsAndEvents[] = [
        'label' => 'Notice',
        'date' => school_profile_format_date($announcement['created_at'] ?? ''),
        'title' => $announcement['title'] ?? '',
        'description' => $announcement['description'] ?? ''
    ];
}
foreach ($events as $event) {
    $announcementsAndEvents[] = [
        'label' => ucwords($event['type'] ?? 'Event'),
        'date' => school_profile_format_date($event['start_date'] ?? ''),
        'title' => $event['title'] ?? '',
        'description' => trim(($event['venue'] ?? '') . ' ' . ($event['description'] ?? ''))
    ];
}
?>
<?php
$profilePhone = trim((string) ($school['phone'] ?? ''));
$profileEmail = trim((string) ($school['email'] ?? ''));
$profileWebsite = trim((string) ($school['website'] ?? ''));
$profileAddress = trim((string) ($school['address'] ?? ''));
foreach ($contacts as $contact) {
    $type = strtolower((string) ($contact['type'] ?? ''));
    $value = trim((string) ($contact['value'] ?? ''));
    if ($value === '') {
        continue;
    }
    if ($type === 'phone' && $profilePhone === '') {
        $profilePhone = $value;
    } elseif ($type === 'email' && $profileEmail === '') {
        $profileEmail = $value;
    } elseif ($type === 'website' && $profileWebsite === '') {
        $profileWebsite = $value;
    } elseif ($type === 'address' && $profileAddress === '') {
        $profileAddress = $value;
    }
}
$establishedYearRaw = school_profile_value($school, 'establishment_year', school_profile_value($school, 'established_year', ''));
$establishedYear = is_numeric($establishedYearRaw) ? (int) $establishedYearRaw : null;
$experienceYears = $establishedYear ? max(1, (int) date('Y') - $establishedYear) : 10;
$firstTestimonial = $testimonials[0] ?? ['name' => 'Parent Community', 'role' => $schoolName, 'quote' => $principalMessage ?: $landingSubheadline];
$latestUpdates = array_slice($announcementsAndEvents, 0, 3);
if (!$latestUpdates) {
    $latestUpdates = [
        ['label' => 'School', 'date' => date('M j, Y'), 'title' => 'Admissions and school updates', 'description' => $landingSubheadline],
    ];
}
$contactHrefPhone = $profilePhone !== '' ? 'tel:' . preg_replace('/[^0-9+]/', '', $profilePhone) : '#contact';
$contactHrefEmail = $profileEmail !== '' ? 'mailto:' . $profileEmail : '#contact';
$contactHrefWebsite = $profileWebsite !== '' ? (preg_match('#^https?://#i', $profileWebsite) ? $profileWebsite : 'https://' . $profileWebsite) : '#top';
$profileTokens = [
    '{school}' => $schoolName,
    '{location}' => $location ?: 'our community',
    '{curriculum}' => (string) $curriculum,
    '{admission_status}' => str_replace('_', ' ', (string) $enrollmentStatus),
    '{admission_deadline}' => !empty($school['admission_deadline']) ? school_profile_format_date($school['admission_deadline']) : '',
    '{admission_deadline_sentence}' => !empty($school['admission_deadline']) ? ' until ' . school_profile_format_date($school['admission_deadline']) : '',
    '{phone}' => $profilePhone !== '' ? $profilePhone : 'the school office',
    '{email}' => $profileEmail !== '' ? $profileEmail : 'the admissions office',
    '{address}' => $profileAddress !== '' ? $profileAddress : ($location ?: 'the school office'),
];
$profileText = static function (string $key, string $default = '') use ($profileSettings, $profileTokens): string {
    return school_profile_template(school_profile_setting($profileSettings, $key, $default), $profileTokens);
};
$profileOptions = static function (string $key, array $default) use ($profileSettings): array {
    return school_profile_setting_options($profileSettings, $key, $default);
};

if (!$profileFaqs) {
    $profileFaqs = [
        ['question' => 'What curriculum does the school use?', 'answer' => '{school} uses the {curriculum} curriculum for a structured academic experience.'],
        ['question' => 'Are admissions currently open?', 'answer' => 'Admissions are currently {admission_status}{admission_deadline_sentence}.'],
        ['question' => 'How can parents contact the school?', 'answer' => 'Parents can use the contact section, call {phone}, or email {email}.'],
        ['question' => 'Can parents leave reviews?', 'answer' => 'Yes. Reviews are accepted only from parent or guardian emails registered in the school account.']
    ];
}

$publicPosts = [];
foreach ($profileBlogs as $blog) {
    $publicPosts[] = [
        'label' => $blog['category'] ?? $profileText('updates_default_label', 'School'),
        'date' => school_profile_format_date($blog['published_at'] ?? $blog['created_at'] ?? ''),
        'title' => $blog['title'] ?? '',
        'description' => $blog['excerpt'] ?: strip_tags((string) ($blog['content'] ?? '')),
        'image' => school_profile_first_image([$blog['image_url'] ?? ''], '')
    ];
}
if (!$publicPosts) {
    foreach ($latestUpdates as $update) {
        $publicPosts[] = $update + ['image' => ''];
    }
}

$publicSlides = [];
foreach ($profileSlides as $slide) {
    $slideImage = school_profile_first_image([$slide['image_url'] ?? ''], '');
    if ($slideImage === '') {
        continue;
    }
    $publicSlides[] = [
        'title' => trim((string) ($slide['title'] ?? '')),
        'subtitle' => trim((string) ($slide['subtitle'] ?? '')),
        'image' => $slideImage,
        'button_label' => trim((string) ($slide['button_label'] ?? '')),
        'button_url' => trim((string) ($slide['button_url'] ?? ''))
    ];
}
if (!$publicSlides) {
    foreach (array_filter(array_merge([$heroImage], $showcaseImages)) as $image) {
        $publicSlides[] = ['title' => '', 'subtitle' => '', 'image' => $image, 'button_label' => '', 'button_url' => ''];
    }
}

$reviewRatingOptions = $profileOptions('review_rating_options', ['5' => 'Excellent', '4' => 'Very good', '3' => 'Good', '2' => 'Fair', '1' => 'Poor']);
$genderOptions = $profileOptions('admission_gender_options', ['male' => 'Male', 'female' => 'Female', 'other' => 'Other']);
$enrollmentTypeOptions = $profileOptions('admission_enrollment_type_options', ['new' => 'New student', 'transfer' => 'Transfer', 're_enrollment' => 'Re-enrollment']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo school_profile_e($schoolName); ?> | <?php echo school_profile_e($profileText('browser_title_suffix', 'School Profile')); ?></title>
    <meta name="description" content="<?php echo school_profile_e(substr($landingSubheadline, 0, 160)); ?>">
    <?php if ($logoUrl): ?>
        <link rel="icon" href="<?php echo school_profile_e($logoUrl); ?>">
    <?php endif; ?>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html { scroll-behavior: smooth; }
        body {
            font-family: 'Inter', ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            color: #1f2933;
            overflow-x: hidden;
            background: var(--body-bg);
            line-height: 1.6;
        }
        a { color: inherit; text-decoration: none; }
        img { display: block; max-width: 100%; }
        :root {
            --green: <?php echo school_profile_e($primaryColor); ?>;
            --green-dark: <?php echo school_profile_e($heroBackgroundColor); ?>;
            --green-soft: #eaf7d8;
            --lime: <?php echo school_profile_e($secondaryColor); ?>;
            --gold: #d4a843;
            --paper: <?php echo school_profile_e($cardBackgroundColor); ?>;
            --body-bg: <?php echo school_profile_e($backgroundColor); ?>;
            --footer-bg: <?php echo school_profile_e($footerBackgroundColor); ?>;
            --muted: #647067;
            --line: #e5eadf;
            --shadow: 0 28px 80px rgba(20, 56, 47, .14);
        }
        .shell { max-width: 1200px; margin: 0 auto; padding: 0 20px; }
        .header {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            padding: 20px 0;
        }
        .header-inner {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 24px;
        }
        .logo {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #fff;
            font-weight: 800;
            font-size: 20px;
            min-width: 0;
        }
        .logo-img,
        .logo-icon {
            width: 38px;
            height: 38px;
            background: var(--lime);
            object-fit: cover;
            flex: 0 0 auto;
        }
        .logo-icon {
            display: grid;
            place-items: center;
            color: var(--green-dark);
            font-size: 13px;
            font-weight: 900;
        }
        .nav { display: flex; align-items: center; gap: 26px; }
        .nav a {
            color: rgba(255,255,255,0.85);
            font-size: 14px;
            font-weight: 600;
            transition: color .25s ease;
        }
        .nav a:hover { color: #fff; }
        .header-right { display: flex; align-items: center; gap: 14px; }
        .search-btn {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            border: 1px solid rgba(255,255,255,0.3);
            background: transparent;
            color: #fff;
            display: grid;
            place-items: center;
        }
        .login-btn,
        .btn-primary,
        .btn-green,
        .review-submit {
            border: 0;
            cursor: pointer;
            font-weight: 800;
            transition: transform .2s ease, box-shadow .2s ease;
        }
        .login-btn {
            background: var(--lime);
            color: var(--green-dark);
            padding: 11px 20px;
            border-radius: 999px;
            display: flex;
            align-items: center;
            gap: 7px;
            font-size: 13px;
        }
        .login-btn:hover,
        .btn-primary:hover,
        .btn-green:hover,
        .review-submit:hover { transform: translateY(-2px); box-shadow: 0 18px 38px rgba(20,56,47,.18); }
        .hero {
            background:
                radial-gradient(circle at 78% 22%, rgba(163,217,0,.18), transparent 24%),
                radial-gradient(circle at 14% 86%, rgba(212,168,67,.14), transparent 28%),
                var(--green-dark);
            min-height: 640px;
            position: relative;
            overflow: hidden;
            border-radius: 0 0 32px 32px;
            margin: 0 10px;
        }
        .hero::before {
            content: '';
            position: absolute;
            inset: 0;
            background-image: linear-gradient(rgba(255,255,255,.035) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,.035) 1px, transparent 1px);
            background-size: 42px 42px;
            pointer-events: none;
        }
        .hero-inner {
            max-width: 1200px;
            margin: 0 auto;
            padding: 128px 20px 92px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 52px;
            position: relative;
            z-index: 1;
        }
        .hero-left { flex: 1; max-width: 560px; }
        .hero-badge,
        .section-badge {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            background: rgba(163,217,0,.14);
            border: 1px solid rgba(163,217,0,.32);
            padding: 7px 15px;
            border-radius: 999px;
            color: var(--lime);
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .04em;
            margin-bottom: 18px;
        }
        .hero-badge::before,
        .section-badge::before { content: ''; width: 8px; height: 8px; background: var(--lime); border-radius: 50%; }
        .hero h1 {
            color: #fff;
            font-size: clamp(42px, 6vw, 72px);
            font-weight: 800;
            line-height: 1.08;
            margin-bottom: 18px;
            letter-spacing: -0.02em;
        }
        .hero p { color: rgba(255,255,255,.72); font-size: 16px; line-height: 1.75; margin-bottom: 32px; max-width: 560px; }
        .hero-buttons { display: flex; align-items: center; gap: 18px; flex-wrap: wrap; }
        .btn-primary {
            background: var(--gold);
            color: var(--green-dark);
            padding: 15px 30px;
            border-radius: 999px;
            font-size: 14px;
        }
        .btn-video { display: flex; align-items: center; gap: 10px; color: #fff; font-size: 14px; font-weight: 700; }
        .play-icon { width: 46px; height: 46px; background: var(--lime); border-radius: 50%; display: grid; place-items: center; color: var(--green-dark); }
        .hero-right { flex: 1; display: flex; justify-content: center; position: relative; }
        .hero-image-container { position: relative; width: min(420px, 100%); height: 460px; }
        .hero-image-bg {
            position: absolute;
            width: 340px;
            height: 408px;
            background: #285747;
            border-radius: 48% 52% 46% 54% / 62% 56% 44% 38%;
            top: 18px;
            left: 42px;
            box-shadow: inset 0 0 0 1px rgba(255,255,255,.08);
        }
        .hero-image {
            position: absolute;
            width: 296px;
            height: 376px;
            background: #d2b48c;
            border-radius: 48% 52% 46% 54% / 62% 56% 44% 38%;
            overflow: hidden;
            top: 34px;
            left: 64px;
            border: 10px solid rgba(255,255,255,.92);
            box-shadow: var(--shadow);
        }
        .hero-image img { width: 100%; height: 100%; object-fit: cover; }
        .tutors-badge {
            position: absolute;
            bottom: 86px;
            left: 0;
            background: rgba(20,56,47,.92);
            border: 1px solid rgba(255,255,255,.12);
            border-radius: 16px;
            padding: 14px 16px;
            color: #fff;
            display: grid;
            gap: 9px;
            box-shadow: 0 20px 50px rgba(0,0,0,.18);
        }
        .tutors-badge span { font-size: 13px; font-weight: 800; }
        .tutors-avatars { display: flex; align-items: center; }
        .tutors-avatars img,
        .tutors-more { width: 30px; height: 30px; border-radius: 50%; border: 2px solid var(--green-dark); margin-left: -8px; object-fit: cover; background: #fff; }
        .tutors-avatars img:first-child { margin-left: 0; }
        .tutors-more { background: var(--gold); color: var(--green-dark); display: grid; place-items: center; font-size: 12px; font-weight: 900; }
        .hero-slider { position: absolute; bottom: 30px; left: 50%; transform: translateX(-50%); display: flex; align-items: center; gap: 12px; z-index: 2; }
        .slider-num { color: rgba(255,255,255,.5); font-size: 13px; font-weight: 700; cursor: pointer; transition: color .25s ease; }
        .slider-num.active { color: var(--lime); }
        .slider-num:hover { color: rgba(255,255,255,.8); }
        .slider-line { width: 64px; height: 2px; background: rgba(255,255,255,.28); position: relative; }
        .slider-line::after { content: ''; position: absolute; inset: 0 auto 0 0; width: 45%; background: var(--lime); }
        .profile-slider { max-width: 1200px; margin: -34px auto 0; padding: 0 20px; position: relative; z-index: 3; }
        .profile-slider-card { background: var(--paper); border: 1px solid var(--line); border-radius: 24px; box-shadow: var(--shadow); padding: 18px; }
        .profile-slider-head { display: flex; align-items: center; justify-content: space-between; gap: 18px; margin-bottom: 14px; }
        .profile-slider-head h2 { color: var(--green-dark); font-size: 22px; line-height: 1.2; }
        .profile-slider-track { display: grid; grid-auto-flow: column; grid-auto-columns: minmax(220px, 1fr); gap: 14px; overflow-x: auto; scroll-snap-type: x mandatory; padding-bottom: 4px; scroll-behavior: smooth; }
        .profile-slider-track::-webkit-scrollbar,
        .cat-cards::-webkit-scrollbar,
        .blog-grid::-webkit-scrollbar { height: 4px; }
        .profile-slider-track::-webkit-scrollbar-track,
        .cat-cards::-webkit-scrollbar-track,
        .blog-grid::-webkit-scrollbar-track { background: transparent; }
        .profile-slider-track::-webkit-scrollbar-thumb,
        .cat-cards::-webkit-scrollbar-thumb,
        .blog-grid::-webkit-scrollbar-thumb { background: rgba(20,56,47,.2); border-radius: 4px; }
        .profile-slide { position: relative; min-height: 160px; border-radius: 18px; overflow: hidden; scroll-snap-align: start; background: #dfe8dc; }
        .profile-slide img { width: 100%; height: 100%; min-height: 160px; object-fit: cover; }
        .profile-slide figcaption { position: absolute; left: 12px; right: 12px; bottom: 12px; background: rgba(20,56,47,.82); color: #fff; border-radius: 14px; padding: 10px 12px; }
        .profile-slide strong { display: block; font-size: 13px; }
        .profile-slide span { display: block; color: rgba(255,255,255,.76); font-size: 11px; margin-top: 2px; }
        .trusted { padding: 54px 20px; text-align: center; }
        .trusted h3 { font-size: 16px; font-weight: 800; color: var(--green-dark); margin-bottom: 28px; }
        .trusted-logos { display: flex; align-items: center; justify-content: center; gap: 22px; flex-wrap: wrap; }
        .trusted-logos span { min-width: 150px; padding: 18px 20px; border-radius: 18px; background: var(--paper); box-shadow: 0 16px 38px rgba(20,56,47,.07); font-size: 14px; font-weight: 800; color: #6d756f; }
        .trusted-logos strong { display: block; color: var(--green-dark); font-size: 24px; line-height: 1; margin-bottom: 6px; }
        .about,
        .team,
        .faq,
        .blog,
        .reviews,
        .admission,
        .gallery-section,
        .contact-section { padding: 72px 20px; max-width: 1200px; margin: 0 auto; }
        .about-inner { display: flex; align-items: center; gap: 64px; }
        .about-left { flex: 1; position: relative; min-height: 430px; }
        .about-img-main { width: min(360px, 82%); height: 410px; border-radius: 24px; overflow: hidden; box-shadow: var(--shadow); }
        .about-img-main img,
        .about-img-small img,
        .testimonial-img img,
        .team-img img,
        .faq-img img,
        .blog-img img,
        .gallery-card img { width: 100%; height: 100%; object-fit: cover; }
        .about-img-small { position: absolute; bottom: 6px; right: 22px; width: 152px; height: 172px; border-radius: 18px; overflow: hidden; border: 5px solid #fff; box-shadow: 0 18px 44px rgba(20,56,47,.18); }
        .experience-badge { position: absolute; top: 48%; right: 58px; transform: translateY(-50%); width: 108px; height: 108px; background: var(--gold); border-radius: 50%; display: flex; flex-direction: column; align-items: center; justify-content: center; color: var(--green-dark); border: 3px dashed var(--green-dark); }
        .experience-badge .num { font-size: 30px; font-weight: 900; line-height: 1; }
        .experience-badge .text { font-size: 10px; font-weight: 800; text-align: center; line-height: 1.25; margin-top: 3px; }
        .about-right { flex: 1; }
        .about-right h2,
        .categories-header h2,
        .team h2,
        .faq-right h2,
        .blog-header h2,
        .reviews h2,
        .admission h2,
        .gallery-section h2,
        .contact-section h2 { font-size: clamp(28px, 4vw, 42px); font-weight: 850; color: var(--green-dark); line-height: 1.18; margin-bottom: 16px; letter-spacing: -0.02em; }
        .about-right p,
        .section-copy { color: #647067; font-size: 15px; line-height: 1.8; margin-bottom: 24px; }
        .stats { display: flex; gap: 36px; margin-bottom: 26px; flex-wrap: wrap; }
        .stat-item h4 { font-size: 30px; font-weight: 850; color: var(--green-dark); }
        .stat-item p { font-size: 12px; color: #7d877f; margin: 0; }
        .btn-green { background: var(--lime); color: var(--green-dark); padding: 13px 25px; border-radius: 999px; font-size: 13px; }
        .categories { background: var(--green-dark); padding: 64px 20px; border-radius: 32px; margin: 20px 10px; overflow: hidden; }
        .categories-inner { max-width: 1200px; margin: 0 auto; }
        .categories-header { display: flex; align-items: flex-end; justify-content: space-between; margin-bottom: 36px; gap: 18px; }
        .categories-header h2 { color: #fff; margin: 0; }
        .cat-nav,
        .blog-nav { display: flex; gap: 10px; }
        .cat-nav button,
        .blog-nav button { width: 38px; height: 38px; border-radius: 50%; border: 1px solid rgba(255,255,255,.22); background: transparent; color: #fff; display: grid; place-items: center; }
        .cat-nav button.active,
        .blog-nav button.active { background: var(--lime); color: var(--green-dark); border-color: var(--lime); }
        .cat-cards { display: grid; grid-auto-flow: column; grid-auto-columns: minmax(260px,1fr); gap: 20px; overflow-x: auto; scroll-snap-type: x mandatory; padding-bottom: 4px; }
        .cat-card { min-height: 210px; border-radius: 20px; overflow: hidden; position: relative; background: #234e42; scroll-snap-align: start; }
        .cat-card img { width: 100%; height: 100%; object-fit: cover; position: absolute; inset: 0; opacity: .82; }
        .cat-card-overlay { position: absolute; inset: auto 0 0; padding: 22px; background: linear-gradient(transparent, rgba(0,0,0,.72)); }
        .cat-card-overlay h4 { color: #fff; font-size: 17px; font-weight: 800; margin-bottom: 5px; }
        .cat-card-overlay p { color: rgba(255,255,255,.82); font-size: 12px; }
        .testimonial { background: var(--green-dark); padding: 72px 20px; margin-top: 46px; }
        .testimonial-inner { max-width: 1200px; margin: 0 auto; display: flex; gap: 50px; align-items: center; }
        .testimonial-left { flex: 1; }
        .quote-icon { color: var(--lime); font-size: 70px; line-height: .7; font-family: Georgia, serif; margin-bottom: 10px; }
        .stars { display: flex; gap: 4px; margin-bottom: 16px; color: var(--gold); font-size: 18px; }
        .testimonial-text { color: rgba(255,255,255,.82); font-size: 16px; line-height: 1.85; margin-bottom: 20px; }
        .testimonial-author { color: #fff; font-weight: 800; font-size: 15px; }
        .testimonial-role { color: rgba(255,255,255,.55); font-size: 12px; }
        .testimonial-avatars { display: flex; gap: 8px; margin-top: 14px; }
        .testimonial-avatars img { width: 34px; height: 34px; border-radius: 50%; object-fit: cover; border: 2px solid rgba(255,255,255,.18); }
        .testimonial-right { flex: 1; display: flex; justify-content: flex-end; }
        .testimonial-img { width: min(340px, 100%); height: 370px; border-radius: 24px; overflow: hidden; box-shadow: var(--shadow); }
        .team { text-align: center; }
        .team-grid { display: grid; grid-template-columns: repeat(3, minmax(0,1fr)); gap: 26px; justify-content: center; }
        .team-card { background: var(--paper); border: 1px solid var(--line); border-radius: 22px; padding: 16px 16px 22px; box-shadow: 0 18px 42px rgba(20,56,47,.08); }
        .team-img { width: 100%; aspect-ratio: 4 / 4.7; border-radius: 18px; overflow: hidden; margin-bottom: 14px; background: #e8eee4; }
        .team-card h4 { font-size: 16px; font-weight: 800; color: #1f2933; }
        .team-card p { font-size: 13px; color: #7b867f; }
        .faq { display: flex; gap: 44px; align-items: center; }
        .faq-left { flex: 0 0 400px; }
        .faq-img { width: 100%; height: 340px; border-radius: 24px; overflow: hidden; position: relative; box-shadow: var(--shadow); }
        .faq-video-btn { position: absolute; bottom: 20px; left: 50%; transform: translateX(-50%); background: var(--green-dark); color: var(--lime); padding: 11px 20px; border-radius: 999px; font-size: 13px; font-weight: 800; border: 0; }
        .faq-right { flex: 1; }
        .faq-item { border-bottom: 1px solid #e8ece3; padding: 17px 0; }
        .faq-question { display: flex; align-items: center; justify-content: space-between; gap: 20px; cursor: pointer; }
        .faq-question h4 { font-size: 15px; font-weight: 800; color: #26312d; }
        .faq-toggle { width: 26px; height: 26px; border-radius: 50%; border: 1px solid #dde5da; display: grid; place-items: center; color: #6f7b74; flex: 0 0 auto; }
        .faq-answer { font-size: 13px; color: #7b867f; line-height: 1.7; margin-top: 10px; padding-right: 34px; display: none; }
        .faq-item.open .faq-answer { display: block; animation: faqFadeIn .3s ease; }
        .faq-item.open .faq-toggle { background: var(--lime); color: var(--green-dark); border-color: var(--lime); }
        @keyframes faqFadeIn { from { opacity: 0; transform: translateY(-6px); } to { opacity: 1; transform: translateY(0); } }
        .blog-header,
        .section-head { display: flex; align-items: flex-end; justify-content: space-between; margin-bottom: 30px; gap: 18px; }
        .blog-nav button { border-color: #dde5da; background: var(--paper); color: var(--green-dark); }
        .blog-grid { display: grid; grid-auto-flow: column; grid-auto-columns: minmax(300px,1fr); gap: 22px; overflow-x: auto; scroll-snap-type: x mandatory; padding-bottom: 4px; }
        .gallery-grid,
        .review-grid,
        .contact-grid { display: grid; grid-template-columns: repeat(3, minmax(0,1fr)); gap: 22px; }
        .blog-card,
        .gallery-card,
        .review-card,
        .contact-card,
        .admission-card,
        .review-form-card { background: var(--paper); border: 1px solid var(--line); border-radius: 22px; padding: 18px; box-shadow: 0 16px 42px rgba(20,56,47,.07); }
        .blog-card { scroll-snap-align: start; }
        .blog-img,
        .gallery-card { height: 190px; border-radius: 16px; overflow: hidden; margin-bottom: 14px; }
        .gallery-card { padding: 0; margin: 0; height: 250px; position: relative; }
        .gallery-card span { position: absolute; left: 16px; right: 16px; bottom: 14px; padding: 9px 12px; border-radius: 999px; background: rgba(20,56,47,.86); color: #fff; font-size: 12px; font-weight: 800; }
        .blog-tag { display: inline-block; background: var(--gold); color: var(--green-dark); padding: 5px 10px; border-radius: 6px; font-size: 10px; font-weight: 800; margin-bottom: 9px; }
        .blog-card h4 { font-size: 16px; font-weight: 800; color: #26312d; line-height: 1.45; margin-bottom: 10px; }
        .blog-card p,
        .review-card p,
        .contact-card p { font-size: 13px; color: #748078; line-height: 1.7; }
        .blog-meta { display: flex; align-items: center; gap: 12px; font-size: 11px; color: #89938d; flex-wrap: wrap; }
        .reviews { text-align: left; }
        .review-card strong { display: block; color: var(--green-dark); margin-bottom: 6px; }
        .review-form-card { margin-top: 24px; }
        .review-form-grid,
        .form-grid { display: grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap: 16px; }
        .field.full { grid-column: 1 / -1; }
        label { display: grid; gap: 7px; font-size: 13px; font-weight: 800; color: #26312d; }
        input,
        select,
        textarea { width: 100%; min-height: 48px; border: 1px solid #dfe8dc; border-radius: 14px; padding: 12px 14px; font: inherit; background: var(--paper); color: #1f2933; }
        textarea { min-height: 120px; resize: vertical; }
        input:focus,
        select:focus,
        textarea:focus { outline: 3px solid rgba(163,217,0,.28); border-color: var(--green); }
        .review-submit { background: var(--green-dark); color: #fff; padding: 14px 25px; border-radius: 999px; justify-self: start; margin-top: 6px; }
        .alert { border-radius: 16px; padding: 14px 16px; margin-bottom: 18px; font-weight: 800; }
        .alert-success { background: #eaf8e4; color: #216333; border: 1px solid #c4e9b8; }
        .alert-error { background: #fff0ed; color: #9c2f20; border: 1px solid #f2c6be; }
        .admission-card { padding: 26px; }
        .admission-layout { display: grid; grid-template-columns: .82fr 1.18fr; gap: 26px; align-items: start; }
        .admission-panel { background: var(--green-dark); color: #fff; border-radius: 24px; padding: 28px; position: sticky; top: 24px; }
        .admission-panel h2 { color: #fff; }
        .admission-panel p { color: rgba(255,255,255,.76); }
        .empty { background: var(--paper); border: 1px dashed var(--line); border-radius: 18px; padding: 26px; color: #758078; text-align: center; }
        .contact-card strong { display: block; color: var(--green-dark); margin-bottom: 6px; }
        .contact-card a { color: var(--green); font-weight: 800; word-break: break-word; }
        .footer { background: var(--footer-bg); padding: 62px 20px 30px; margin-top: 42px; color: #fff; }
        .footer-inner { max-width: 1200px; margin: 0 auto; }
        .footer-top { display: flex; justify-content: space-between; gap: 42px; margin-bottom: 40px; }
        .footer-brand { max-width: 340px; }
        .footer-brand .logo { margin-bottom: 16px; }
        .footer-brand p { color: rgba(255,255,255,.66); font-size: 13px; line-height: 1.75; }
        .footer-contact { display: flex; gap: 32px; flex-wrap: wrap; }
        .contact-item { display: flex; align-items: flex-start; gap: 10px; color: #fff; min-width: 180px; }
        .contact-item svg { width: 20px; height: 20px; stroke: var(--lime); fill: none; flex-shrink: 0; margin-top: 3px; }
        .contact-item div { font-size: 12px; }
        .contact-item strong { display: block; font-size: 13px; margin-bottom: 3px; }
        .contact-item span { color: rgba(255,255,255,.64); }
        .footer-bottom { border-top: 1px solid rgba(255,255,255,.1); padding-top: 20px; display: flex; justify-content: space-between; align-items: center; gap: 18px; }
        .footer-links { display: flex; gap: 22px; flex-wrap: wrap; }
        .footer-links a { color: rgba(255,255,255,.64); font-size: 13px; }
        .footer-copy { color: rgba(255,255,255,.45); font-size: 12px; }
        @media (max-width: 1024px) {
            .nav { display: none; }
            .hero-inner,
            .about-inner,
            .testimonial-inner,
            .faq,
            .admission-layout { flex-direction: column; grid-template-columns: 1fr; }
            .faq-left { flex: 1; width: 100%; }
            .cat-cards { grid-auto-flow: column; grid-auto-columns: minmax(260px,1fr); overflow-x: auto; }
            .blog-grid { grid-auto-flow: column; grid-auto-columns: minmax(300px,1fr); overflow-x: auto; }
            .gallery-grid,
            .review-grid,
            .contact-grid,
            .team-grid { grid-template-columns: repeat(2, minmax(0,1fr)); }
            .admission-panel { position: static; }
        }
        @media (max-width: 768px) {
            .header-inner { align-items: flex-start; }
            .header-right .search-btn { display: none; }
            .hero-inner { text-align: center; padding-top: 104px; }
            .hero-buttons,
            .stats { justify-content: center; }
            .hero-image-container { width: 300px; height: 330px; }
            .hero-image-bg { width: 245px; height: 305px; left: 30px; }
            .hero-image { width: 225px; height: 285px; left: 42px; }
            .tutors-badge { bottom: 38px; left: -8px; }
            .categories-header,
            .blog-header,
            .section-head,
            .footer-top,
            .footer-bottom { flex-direction: column; align-items: flex-start; }
            .cat-cards { grid-auto-flow: row; grid-template-columns: 1fr; overflow-x: visible; }
            .blog-grid { grid-auto-flow: row; grid-template-columns: 1fr; overflow-x: visible; }
            .gallery-grid,
            .review-grid,
            .contact-grid,
            .team-grid,
            .review-form-grid,
            .form-grid { grid-template-columns: 1fr; }
            .field.full { grid-column: auto; }
            .about-img-main { width: 100%; }
            .about-left { min-height: 390px; }
            .experience-badge { right: 18px; }
            .footer-bottom { text-align: left; }
        }
        @media (max-width: 480px) {
            .hero h1 { font-size: 34px; }
            .logo span { max-width: 150px; }
            .login-btn { padding: 10px 13px; }
            .about,
            .team,
            .faq,
            .blog,
            .reviews,
            .admission,
            .gallery-section,
            .contact-section { padding: 54px 16px; }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-inner">
            <a href="#top" class="logo" aria-label="<?php echo school_profile_e($schoolName); ?> homepage">
                <?php if ($logoUrl): ?>
                    <img class="logo-img" src="<?php echo school_profile_e($logoUrl); ?>" alt="<?php echo school_profile_e($schoolName); ?> logo">
                <?php else: ?>
                    <span class="logo-icon"><?php echo school_profile_e($schoolInitials); ?></span>
                <?php endif; ?>
            </a>
            <nav class="nav" aria-label="Public profile navigation">
                <a href="#top"><?php echo school_profile_e($profileText('nav_home', 'Home')); ?></a>
                <a href="#about"><?php echo school_profile_e($profileText('nav_about', 'About Us')); ?></a>
                <a href="#programs"><?php echo school_profile_e($profileText('nav_subjects', 'Subjects')); ?></a>
                <a href="#admission"><?php echo school_profile_e($profileText('nav_admission', 'Admission')); ?></a>
                <a href="#facilities"><?php echo school_profile_e($profileText('nav_facilities', 'Facilities')); ?></a>
                <a href="#gallery"><?php echo school_profile_e($profileText('nav_gallery', 'Gallery')); ?></a>
                <a href="#reviews"><?php echo school_profile_e($profileText('nav_reviews', 'Reviews')); ?></a>
                <a href="#contact"><?php echo school_profile_e($profileText('nav_contact', 'Contact')); ?></a>
            </nav>
            <div class="header-right">
                <a class="search-btn" href="#contact" aria-label="Contact <?php echo school_profile_e($schoolName); ?>">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
                </a>
                <a class="login-btn" href="<?php echo school_profile_e($loginUrl); ?>">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    <?php echo school_profile_e($secondaryCta); ?>
                </a>
            </div>
        </div>
    </header>

    <main id="top">
        <section class="hero">
            <div class="hero-inner">
                <div class="hero-left">
                    <div class="hero-badge"><?php echo school_profile_e($landingBadge); ?></div>
                    <h1><?php echo school_profile_e($landingHeadline); ?></h1>
                    <p><?php echo school_profile_e($landingSubheadline); ?></p>
                    <div class="hero-buttons">
                        <?php if ($enrollmentStatus === 'open'): ?>
                            <a class="btn-primary" href="#admission"><?php echo school_profile_e($primaryCta); ?></a>
                        <?php endif; ?>
                        <a href="#about" class="btn-video"><span class="play-icon"><svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg></span><?php echo school_profile_e($profileText('hero_secondary_link_label', 'How it works')); ?></a>
                    </div>
                </div>
                <div class="hero-right">
                    <div class="hero-image-container">
                        <div class="hero-image-bg"></div>
                        <div class="hero-image"><img src="<?php echo school_profile_e($heroImage); ?>" alt="<?php echo school_profile_e($schoolName); ?> students"></div>
                        <div class="tutors-badge">
                            <span><?php echo number_format((int) $stats['teachers']); ?>+ <?php echo school_profile_e($profileText('stat_teachers_badge_label', 'Teachers')); ?></span>
                            <div class="tutors-avatars">
                                <?php foreach (array_slice($circleImages, 1, 4) as $image): ?>
                                    <img src="<?php echo school_profile_e($image); ?>" alt="">
                                <?php endforeach; ?>
                                <div class="tutors-more">+</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="hero-slider"><span class="slider-num">01</span><span class="slider-num active">02</span><div class="slider-line"></div><span class="slider-num">03</span><span class="slider-num">04</span></div>
        </section>

        <section class="profile-slider" aria-label="<?php echo school_profile_e($profileText('slider_heading', 'Featured moments from {school}')); ?>">
            <div class="profile-slider-card">
                <div class="profile-slider-head">
                    <div><div class="section-badge"><?php echo school_profile_e($profileText('slider_badge', 'Gallery')); ?></div><h2><?php echo school_profile_e($profileText('slider_heading', 'Featured moments from {school}')); ?></h2></div>
                    <div class="blog-nav"><button type="button">&larr;</button><button type="button" class="active">&rarr;</button></div>
                </div>
                <div class="profile-slider-track">
                    <?php foreach (array_slice($publicSlides, 0, 6) as $index => $slide): ?>
                        <figure class="profile-slide">
                            <img src="<?php echo school_profile_e($slide['image']); ?>" alt="<?php echo school_profile_e($slide['title'] ?: $schoolName . ' gallery image'); ?>">
                            <figcaption>
                                <strong><?php echo school_profile_e($slide['title'] ?: $profileText('gallery_default_caption_' . (($index % 3) + 1), 'School life')); ?></strong>
                                <?php if (!empty($slide['subtitle'])): ?><span><?php echo school_profile_e($slide['subtitle']); ?></span><?php endif; ?>
                            </figcaption>
                        </figure>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <section class="trusted" aria-label="School highlights">
            <h3><?php echo school_profile_e($profileText('trusted_heading', 'Trusted by families and learners across {location}')); ?></h3>
            <div class="trusted-logos">
                <span><strong><?php echo number_format((int) $stats['students']); ?></strong><?php echo school_profile_e($profileText('stat_students_label', 'Students supported')); ?></span>
                <span><strong><?php echo number_format((int) $stats['teachers']); ?></strong><?php echo school_profile_e($profileText('stat_teachers_label', 'Teaching staff')); ?></span>
                <span><strong><?php echo number_format((int) $stats['classes']); ?></strong><?php echo school_profile_e($profileText('stat_classes_label', 'Active classes')); ?></span>
                <span><strong><?php echo $stats['subjects'] !== null ? number_format((int) $stats['subjects']) : school_profile_e($curriculum); ?></strong><?php echo $stats['subjects'] !== null ? school_profile_e($profileText('stat_subjects_label', 'Subjects')) : school_profile_e($profileText('stat_curriculum_label', 'Curriculum')); ?></span>
                <span><strong><?php echo number_format($experienceYears); ?>+</strong><?php echo school_profile_e($profileText('stat_years_label', 'Years')); ?></span>
            </div>
        </section>

        <section class="about" id="about">
            <div class="about-inner">
                <div class="about-left">
                    <div class="about-img-main"><img src="<?php echo school_profile_e($featureImage); ?>" alt="<?php echo school_profile_e($schoolName); ?> campus"></div>
                    <div class="about-img-small"><img src="<?php echo school_profile_e($showcaseImages[0]); ?>" alt="<?php echo school_profile_e($schoolName); ?> learner"></div>
                    <div class="experience-badge"><div class="num"><?php echo number_format($experienceYears); ?></div><div class="text"><?php echo nl2br(school_profile_e($profileText('experience_badge_label', "Years of\nexperience"))); ?></div></div>
                </div>
                <div class="about-right">
                    <div class="section-badge"><?php echo school_profile_e($profileText('about_badge', 'About Us')); ?></div>
                    <h2><?php echo school_profile_e($introTitle); ?></h2>
                    <p><?php echo nl2br(school_profile_e($introText)); ?></p>
                    <div class="stats">
                        <div class="stat-item"><h4><?php echo number_format((int) $stats['students']); ?>+</h4><p><?php echo school_profile_e($profileText('stat_students_label', 'Students supported')); ?></p></div>
                        <div class="stat-item"><h4><?php echo number_format((int) $stats['classes']); ?>+</h4><p><?php echo school_profile_e($profileText('stat_classes_label', 'Active classes')); ?></p></div>
                        <div class="stat-item"><h4><?php echo number_format((int) $stats['teachers']); ?>+</h4><p><?php echo school_profile_e($profileText('stat_teachers_label', 'Teaching staff')); ?></p></div>
                    </div>
                    <a class="btn-green" href="#programs"><?php echo school_profile_e($profileText('about_cta_label', 'View more details')); ?></a>
                </div>
            </div>
        </section>

        <section class="categories" id="programs">
            <div class="categories-inner">
                <div class="categories-header">
                    <div><div class="section-badge"><?php echo school_profile_e($profileText('programs_badge', 'Subjects')); ?></div><h2><?php echo school_profile_e($profileText('programs_heading', 'Our Popular Learning Areas')); ?></h2></div>
                    <div class="cat-nav"><button type="button">&larr;</button><button type="button" class="active">&rarr;</button></div>
                </div>
                <div class="cat-cards">
                    <?php foreach (array_slice($programs, 0, 4) as $index => $program): ?>
                        <article class="cat-card">
                            <img src="<?php echo school_profile_e($showcaseImages[$index % count($showcaseImages)]); ?>" alt="<?php echo school_profile_e($program['title'] ?? $profileText('program_default_title', 'Learning Area')); ?>">
                            <div class="cat-card-overlay"<?php echo $index === 1 ? ' style="background:rgba(212,168,67,.92);"' : ''; ?>>
                                <h4><?php echo school_profile_e($program['title'] ?? $profileText('program_default_title', 'Learning Area')); ?></h4>
                                <p><?php echo school_profile_e($program['description'] ?? $profileText('program_default_description', 'Structured learning for every student.')); ?></p>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <section class="testimonial">
            <div class="testimonial-inner">
                <div class="testimonial-left">
                    <div class="quote-icon">&quot;</div>
                    <div class="stars"><span>&#9733;</span><span>&#9733;</span><span>&#9733;</span><span>&#9733;</span><span>&#9733;</span></div>
                    <p class="testimonial-text">&quot;<?php echo school_profile_e($firstTestimonial['quote'] ?? $landingSubheadline); ?>&quot;</p>
                    <div class="testimonial-author"><?php echo school_profile_e($firstTestimonial['name'] ?? $profileText('testimonial_default_name', 'Parent Community')); ?></div>
                    <div class="testimonial-role"><?php echo school_profile_e($firstTestimonial['role'] ?? $schoolName); ?></div>
                    <div class="testimonial-avatars">
                        <?php foreach (array_slice($circleImages, 1, 3) as $image): ?><img src="<?php echo school_profile_e($image); ?>" alt=""><?php endforeach; ?>
                    </div>
                </div>
                <div class="testimonial-right"><div class="testimonial-img"><img src="<?php echo school_profile_e($showcaseImages[1]); ?>" alt="<?php echo school_profile_e($schoolName); ?> testimonial"></div></div>
            </div>
        </section>

        <section class="team" id="facilities">
            <div class="section-badge"><?php echo school_profile_e($profileText('facilities_badge', 'Facilities')); ?></div>
            <h2><?php echo school_profile_e($profileText('facilities_heading', 'Campus Spaces Built for Learning')); ?></h2>
            <div class="team-grid">
                <?php foreach (array_slice($facilities ?: $programs, 0, 3) as $index => $facility): ?>
                    <article class="team-card">
                        <div class="team-img"><img src="<?php echo school_profile_e($showcaseImages[$index % count($showcaseImages)]); ?>" alt="<?php echo school_profile_e($facility['title'] ?? $profileText('facility_default_title', 'Campus Facility')); ?>"></div>
                        <h4><?php echo school_profile_e($facility['title'] ?? $profileText('facility_default_title', 'Campus Facility')); ?></h4>
                        <p><?php echo school_profile_e($facility['description'] ?? $profileText('facility_default_description', 'Designed for safe, engaging student development.')); ?></p>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="faq">
            <div class="faq-left">
                <div class="faq-img"><img src="<?php echo school_profile_e($showcaseImages[2]); ?>" alt="<?php echo school_profile_e($schoolName); ?> learning guide"><a class="faq-video-btn" href="#admission"><?php echo school_profile_e($profileText('faq_image_button_label', 'Admission Guide')); ?></a></div>
            </div>
            <div class="faq-right">
                <div class="section-badge"><?php echo school_profile_e($profileText('faq_badge', 'FAQ')); ?></div>
                <h2><?php echo school_profile_e($profileText('faq_heading', 'Frequently Asked Questions')); ?></h2>
                <?php foreach ($profileFaqs as $faq): ?>
                    <div class="faq-item">
                        <div class="faq-question">
                            <h4><?php echo school_profile_e(school_profile_template((string) ($faq['question'] ?? ''), $profileTokens)); ?></h4>
                            <span class="faq-toggle">+</span>
                        </div>
                        <div class="faq-answer"><?php echo school_profile_e(school_profile_template((string) ($faq['answer'] ?? ''), $profileTokens)); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="blog">
            <div class="blog-header"><div><div class="section-badge"><?php echo school_profile_e($profileText('updates_badge', 'Updates')); ?></div><h2><?php echo school_profile_e($profileText('updates_heading', 'Latest News & Events')); ?></h2></div><div class="blog-nav"><button type="button">&larr;</button><button type="button" class="active">&rarr;</button></div></div>
            <div class="blog-grid">
                <?php foreach (array_slice($publicPosts, 0, 3) as $index => $update): ?>
                    <article class="blog-card">
                        <div class="blog-img"><img src="<?php echo school_profile_e(($update['image'] ?? '') ?: $circleImages[$index % count($circleImages)]); ?>" alt="<?php echo school_profile_e($update['title'] ?? $profileText('updates_default_title', 'School update')); ?>"></div>
                        <span class="blog-tag"><?php echo school_profile_e($update['label'] ?? $profileText('updates_default_label', 'Update')); ?></span>
                        <h4><?php echo school_profile_e($update['title'] ?? $profileText('updates_default_title', 'School update')); ?></h4>
                        <p><?php echo school_profile_e($update['description'] ?? ''); ?></p>
                        <div class="blog-meta"><span><?php echo school_profile_e($schoolName); ?></span><span><?php echo school_profile_e($update['date'] ?? ''); ?></span></div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="gallery-section" id="gallery">
            <div class="section-head"><div><div class="section-badge"><?php echo school_profile_e($profileText('gallery_badge', 'Gallery')); ?></div><h2><?php echo school_profile_e($profileText('gallery_heading', 'Life at {school}')); ?></h2></div><p class="section-copy"><?php echo school_profile_e($profileText('gallery_intro', 'A glimpse of the school environment, learning moments, and student activities.')); ?></p></div>
            <div class="gallery-grid">
                <?php if ($gallery): ?>
                    <?php foreach (array_slice($gallery, 0, 6) as $img): ?>
                        <?php $imageUrl = school_profile_asset_url((string) ($img['image_url'] ?? '')); if (!$imageUrl) continue; ?>
                        <figure class="gallery-card"><img src="<?php echo school_profile_e($imageUrl); ?>" alt="<?php echo school_profile_e($img['caption'] ?? $schoolName); ?>"><span><?php echo school_profile_e($img['caption'] ?? $profileText('gallery_default_caption_2', 'Campus')); ?></span></figure>
                    <?php endforeach; ?>
                <?php else: ?>
                    <?php foreach ($showcaseImages as $index => $imageUrl): ?>
                        <figure class="gallery-card"><img src="<?php echo school_profile_e($imageUrl); ?>" alt="<?php echo school_profile_e($schoolName); ?> gallery image"><span><?php echo school_profile_e($profileText('gallery_default_caption_' . (($index % 3) + 1), 'School life')); ?></span></figure>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>

        <section class="reviews" id="reviews">
            <div class="section-head"><div><div class="section-badge"><?php echo school_profile_e($profileText('reviews_badge', 'Reviews')); ?></div><h2><?php echo school_profile_e($profileText('reviews_heading', 'What Parents Say')); ?></h2></div><p class="section-copy"><?php echo school_profile_e($profileText('reviews_intro', 'Reviews are published after approval by the school.')); ?></p></div>
            <?php if ($reviews): ?>
                <div class="review-grid">
                    <?php foreach (array_slice($reviews, 0, 3) as $review): ?>
                        <article class="review-card"><strong><?php echo school_profile_e($review['parent_name'] ?? 'Parent'); ?></strong><p><?php echo school_profile_e($review['comment'] ?? ''); ?></p><p><?php echo str_repeat('&#9733;', max(1, min(5, (int) ($review['rating'] ?? 5)))); ?></p></article>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty"><?php echo school_profile_e($profileText('reviews_empty', 'Be the first verified parent to leave a review.')); ?></div>
            <?php endif; ?>
            <div class="review-form-card">
                <h3><?php echo school_profile_e($profileText('review_form_heading', 'Share your experience')); ?></h3>
                <p class="section-copy"><?php echo school_profile_e($profileText('review_form_intro', 'Only parents or guardians with an email on the school account can post reviews.')); ?></p>
                <?php if (!empty($reviewSuccess)): ?><div class="alert alert-success"><?php echo school_profile_e($profileText('review_success_message', 'Thank you. Your review has been submitted for moderation.')); ?></div><?php endif; ?>
                <?php if (!empty($reviewError)): ?><div class="alert alert-error"><?php echo school_profile_e($reviewError); ?></div><?php endif; ?>
                <form method="post" action="#reviews" class="review-form-grid">
                    <input type="hidden" name="csrf_token" value="<?php echo school_profile_e($csrfToken); ?>">
                    <input type="hidden" name="review_submit" value="1">
                    <label><?php echo school_profile_e($profileText('review_name_label', 'Your name *')); ?><input type="text" name="review_parent_name" required maxlength="120"></label>
                    <label><?php echo school_profile_e($profileText('review_email_label', 'Parent email *')); ?><input type="email" name="review_parent_email" required maxlength="180"></label>
                    <label><?php echo school_profile_e($profileText('review_student_label', 'Student name')); ?><input type="text" name="review_student_name" maxlength="120"></label>
                    <label><?php echo school_profile_e($profileText('review_rating_label', 'Rating *')); ?><select name="review_rating" required><option value=""><?php echo school_profile_e($profileText('review_rating_placeholder', 'Select rating')); ?></option><?php foreach ($reviewRatingOptions as $value => $label): ?><option value="<?php echo school_profile_e($value); ?>"><?php echo school_profile_e($label); ?></option><?php endforeach; ?></select></label>
                    <label class="field full"><?php echo school_profile_e($profileText('review_comment_label', 'Your review *')); ?><textarea name="review_comment" required maxlength="1000" rows="4"></textarea></label>
                    <button type="submit" class="review-submit"><?php echo school_profile_e($profileText('review_submit_label', 'Submit review')); ?></button>
                </form>
            </div>
        </section>

        <section class="admission" id="admission">
            <div class="admission-layout">
                <aside class="admission-panel"><div class="section-badge"><?php echo school_profile_e($profileText('admission_badge', 'Admission')); ?></div><h2><?php echo school_profile_e($ctaTitle); ?></h2><p><?php echo school_profile_e($ctaText); ?></p><?php if (!empty($school['admission_deadline'])): ?><p><strong><?php echo school_profile_e($profileText('admission_deadline_label', 'Deadline')); ?>:</strong> <?php echo school_profile_e(school_profile_format_date($school['admission_deadline'])); ?></p><?php endif; ?></aside>
                <div class="admission-card">
                    <?php if ($enrollmentSuccess): ?><div class="alert alert-success"><?php echo school_profile_e($profileText('admission_success_prefix', 'Application submitted successfully')); ?><?php echo $requestNumber ? '. Reference: ' . school_profile_e($requestNumber) : ''; ?>.</div><?php endif; ?>
                    <?php if ($enrollmentError): ?><div class="alert alert-error"><?php echo $enrollmentError; ?></div><?php endif; ?>
                    <?php if ($enrollmentStatus === 'open'): ?>
                        <form method="POST" action="#admission" enctype="multipart/form-data" class="form-grid">
                            <?php if ($csrfToken): ?><input type="hidden" name="csrf_token" value="<?php echo school_profile_e($csrfToken); ?>"><?php endif; ?>
                            <input type="hidden" name="enrollment_submit" value="1">
                            <label><?php echo school_profile_e($profileText('admission_parent_first_name_label', 'Parent First Name *')); ?><input name="parent_first_name" required></label>
                            <label><?php echo school_profile_e($profileText('admission_parent_last_name_label', 'Parent Last Name *')); ?><input name="parent_last_name" required></label>
                            <label><?php echo school_profile_e($profileText('admission_parent_email_label', 'Parent Email *')); ?><input type="email" name="parent_email" required></label>
                            <label><?php echo school_profile_e($profileText('admission_parent_phone_label', 'Parent Phone *')); ?><input name="parent_phone" required></label>
                            <label class="field full"><?php echo school_profile_e($profileText('admission_parent_address_label', 'Parent Address')); ?><textarea name="parent_address"></textarea></label>
                            <label><?php echo school_profile_e($profileText('admission_student_first_name_label', 'Student First Name *')); ?><input name="student_first_name" required></label>
                            <label><?php echo school_profile_e($profileText('admission_student_last_name_label', 'Student Last Name *')); ?><input name="student_last_name" required></label>
                            <label><?php echo school_profile_e($profileText('admission_gender_label', 'Gender *')); ?><select name="student_gender" required><option value=""><?php echo school_profile_e($profileText('admission_gender_placeholder', 'Select gender')); ?></option><?php foreach ($genderOptions as $value => $label): ?><option value="<?php echo school_profile_e($value); ?>"><?php echo school_profile_e($label); ?></option><?php endforeach; ?></select></label>
                            <label><?php echo school_profile_e($profileText('admission_dob_label', 'Date of Birth *')); ?><input type="date" name="student_dob" required></label>
                            <label><?php echo school_profile_e($profileText('admission_class_label', 'Preferred Class *')); ?><input name="student_grade" placeholder="<?php echo school_profile_e($profileText('admission_class_placeholder', 'Example: JSS 1')); ?>" required></label>
                            <label><?php echo school_profile_e($profileText('admission_previous_school_label', 'Previous School')); ?><input name="student_previous_school"></label>
                            <label><?php echo school_profile_e($profileText('admission_enrollment_type_label', 'Enrollment Type')); ?><select name="enrollment_type"><?php foreach ($enrollmentTypeOptions as $value => $label): ?><option value="<?php echo school_profile_e($value); ?>"><?php echo school_profile_e($label); ?></option><?php endforeach; ?></select></label>
                            <label><?php echo school_profile_e($profileText('admission_year_label', 'Academic Year *')); ?><input name="academic_year" value="<?php echo school_profile_e(date('Y') . '/' . (date('Y') + 1)); ?>" required></label>
                            <label><?php echo school_profile_e($profileText('admission_term_label', 'Academic Term')); ?><input name="academic_term" placeholder="<?php echo school_profile_e($profileText('admission_term_placeholder', 'First term')); ?>"></label>
                            <label><?php echo school_profile_e($profileText('admission_documents_label', 'Documents')); ?><input type="file" name="documents[]" multiple accept=".pdf,.jpg,.jpeg,.png"></label>
                            <label class="field full"><?php echo school_profile_e($profileText('admission_special_requirements_label', 'Special Requirements')); ?><textarea name="special_requirements" placeholder="<?php echo school_profile_e($profileText('admission_special_requirements_placeholder', 'Medical notes, learning support needs, or additional information.')); ?>"></textarea></label>
                            <button class="review-submit" type="submit"><?php echo school_profile_e($profileText('admission_submit_label', 'Submit Application')); ?></button>
                        </form>
                    <?php else: ?>
                        <div class="empty"><?php echo school_profile_e($profileText('admission_closed_prefix', 'Admissions are currently {admission_status}. Please contact the school office.')); ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <section class="contact-section" id="contact">
            <div class="section-head"><div><div class="section-badge"><?php echo school_profile_e($profileText('contact_badge', 'Contact')); ?></div><h2><?php echo school_profile_e($profileText('contact_heading', 'Visit, call, or send a message')); ?></h2></div><p class="section-copy"><?php echo school_profile_e($profileText('contact_intro', 'Use these details for admissions, parent support, and school visits.')); ?></p></div>
            <div class="contact-grid">
                <article class="contact-card"><strong><?php echo school_profile_e($profileText('contact_phone_label', 'Our Phone')); ?></strong><p><a href="<?php echo school_profile_e($contactHrefPhone); ?>"><?php echo school_profile_e($profilePhone ?: $profileText('contact_missing_value', 'Not published')); ?></a></p></article>
                <article class="contact-card"><strong><?php echo school_profile_e($profileText('contact_email_label', 'Our Email')); ?></strong><p><a href="<?php echo school_profile_e($contactHrefEmail); ?>"><?php echo school_profile_e($profileEmail ?: $profileText('contact_missing_value', 'Not published')); ?></a></p></article>
                <article class="contact-card"><strong><?php echo school_profile_e($profileText('contact_address_label', 'Our Address')); ?></strong><p><?php echo nl2br(school_profile_e($profileAddress ?: $location ?: $profileText('contact_missing_value', 'Not published'))); ?></p></article>
            </div>
        </section>
    </main>

    <footer class="footer">
        <div class="footer-inner">
            <div class="footer-top">
                <div class="footer-brand">
                    <strong style="font-size:22px;font-weight:850;display:block;margin-bottom:16px;"><?php echo school_profile_e($schoolName); ?></strong>
                    <p><?php echo school_profile_e($landingSubheadline); ?></p>
                </div>
                <div class="footer-contact">
                    <div class="contact-item"><svg viewBox="0 0 24 24" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg><div><strong><?php echo school_profile_e($profileText('contact_phone_label', 'Our Phone')); ?></strong><span><?php echo school_profile_e($profilePhone ?: $profileText('contact_missing_value', 'Not published')); ?></span></div></div>
                    <div class="contact-item"><svg viewBox="0 0 24 24" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg><div><strong><?php echo school_profile_e($profileText('contact_email_label', 'Our Email')); ?></strong><span><?php echo school_profile_e($profileEmail ?: $profileText('contact_missing_value', 'Not published')); ?></span></div></div>
                    <div class="contact-item"><svg viewBox="0 0 24 24" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg><div><strong><?php echo school_profile_e($profileText('contact_address_label', 'Our Address')); ?></strong><span><?php echo school_profile_e($profileAddress ?: $location ?: $profileText('contact_missing_value', 'Not published')); ?></span></div></div>
                </div>
            </div>
            <div class="footer-bottom">
                <div class="footer-links"><a href="#top"><?php echo school_profile_e($profileText('footer_nav_home', 'Home')); ?></a><a href="#about"><?php echo school_profile_e($profileText('footer_nav_about', 'About Us')); ?></a><a href="#programs"><?php echo school_profile_e($profileText('footer_nav_subjects', 'Subjects')); ?></a><a href="#admission"><?php echo school_profile_e($profileText('footer_nav_admission', 'Admission')); ?></a><a href="#gallery"><?php echo school_profile_e($profileText('footer_nav_gallery', 'Gallery')); ?></a><a href="#contact"><?php echo school_profile_e($profileText('footer_nav_contact', 'Contact')); ?></a></div>
                <div class="footer-copy">&copy; <?php echo date('Y'); ?> <?php echo school_profile_e($schoolName); ?>. <?php echo school_profile_e($profileText('footer_credit', 'Made with AcademixSuite.')); ?></div>
            </div>
        </div>
    </footer>
<script>
(function() {
    'use strict';

    // --- FAQ Accordion ---
    document.querySelectorAll('.faq-question').forEach(function(q) {
        q.addEventListener('click', function() {
            var item = this.parentNode;
            var isOpen = item.classList.contains('open');
            item.classList.toggle('open', !isOpen);
            var toggle = this.querySelector('.faq-toggle');
            if (toggle) toggle.textContent = isOpen ? '+' : '\u2212';
        });
    });

    // --- Slider helpers ---
    function scrollSlider(container, dir) {
        var gap = parseFloat(getComputedStyle(container).gap) || 0;
        var card = container.firstElementChild;
        if (!card) return;
        var w = card.offsetWidth + gap;
        container.scrollBy({ left: dir * w, behavior: 'smooth' });
    }

    // --- Profile slider nav ---
    var profileTrack = document.querySelector('.profile-slider-track');
    var profileNav = document.querySelector('.profile-slider-head .blog-nav');
    if (profileTrack && profileNav) {
        var btns = profileNav.querySelectorAll('button');
        if (btns.length >= 2) {
            btns[0].addEventListener('click', function() { scrollSlider(profileTrack, -1); });
            btns[1].addEventListener('click', function() { scrollSlider(profileTrack, 1); });
        }
    }

    // --- Categories slider nav ---
    var catCards = document.querySelector('.cat-cards');
    var catNav = document.querySelector('.cat-nav');
    if (catCards && catNav) {
        var btns = catNav.querySelectorAll('button');
        if (btns.length >= 2) {
            btns[0].addEventListener('click', function() { scrollSlider(catCards, -1); });
            btns[1].addEventListener('click', function() { scrollSlider(catCards, 1); });
        }
    }

    // --- Blog slider nav ---
    var blogGrid = document.querySelector('.blog-grid');
    var blogNav = document.querySelector('.blog > .blog-header .blog-nav');
    if (blogGrid && blogNav) {
        var btns = blogNav.querySelectorAll('button');
        if (btns.length >= 2) {
            btns[0].addEventListener('click', function() { scrollSlider(blogGrid, -1); });
            btns[1].addEventListener('click', function() { scrollSlider(blogGrid, 1); });
        }
    }

    // --- Hero image cycling via slider numbers ---
    var heroSliderEl = document.querySelector('.hero-slider');
    var heroImage = document.querySelector('.hero-image img');
    var heroNums = heroSliderEl ? heroSliderEl.querySelectorAll('.slider-num') : [];
    var heroImages = <?php
        $heroImagesJson = array_values(array_filter(array_map(function($s) {
            return $s['image'] ?? '';
        }, $publicSlides)));
        echo json_encode($heroImagesJson ?: [$heroImage]);
    ?>;
    if (heroNums.length && heroImage && heroImages.length) {
        heroNums.forEach(function(num, idx) {
            num.addEventListener('click', function() {
                var i = idx % heroImages.length;
                heroImage.src = heroImages[i];
                heroNums.forEach(function(n) { n.classList.remove('active'); });
                this.classList.add('active');
            });
        });
        // Auto-rotate every 6 seconds
        var currentIdx = 0;
        setInterval(function() {
            currentIdx = (currentIdx + 1) % heroImages.length;
            if (heroNums[currentIdx]) {
                heroNums[currentIdx].click();
            }
        }, 6000);
    }

})();
</script>
</body>
</html>
