<?php
/**
 * Public-profile editing actions.
 *
 * Required by tenant/{school-slug}/admin/general.php; returns an array which
 * the parent file json_encodes. Inputs come from $_POST. Schema lookups are
 * defensive (SHOW COLUMNS) so the file is forward-compatible.
 *
 * Required parent-scope variables:
 *   - $action      (the action string)
 *   - $schoolSlug  (current tenant slug)
 *   - $school      (current tenant school row — at minimum 'id' and 'logo_path')
 */

$school = is_array($school ?? null) ? $school : ($GLOBALS['SCHOOL_DATA'] ?? []);
$schoolSlug = (string) ($schoolSlug ?? ($GLOBALS['SCHOOL_SLUG'] ?? ''));
if (empty($school) && $schoolSlug !== '' && isset($_SESSION['school_info'][$schoolSlug])) {
    $school = $_SESSION['school_info'][$schoolSlug];
}

if (empty($school) || empty($school['id'])) {
    return ['success' => false, 'message' => 'School context missing'];
}

$platformDb = \Database::getPlatformConnection();
$schoolId   = (int) $school['id'];

$columnsOf = static function (string $table) use ($platformDb): array {
    try {
        $stmt = $platformDb->query("SHOW COLUMNS FROM `{$table}`");
        return $stmt ? array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'Field') : [];
    } catch (Throwable $e) {
        error_log("public_profile_actions: SHOW COLUMNS {$table}: " . $e->getMessage());
        return [];
    }
};
$tableExists = static function (string $table) use ($platformDb): bool {
    try {
        $s = $platformDb->prepare('SHOW TABLES LIKE ?'); $s->execute([$table]);
        return (bool) $s->fetchColumn();
    } catch (Throwable $e) { return false; }
};

$ensurePublicProfileSchema = static function () use ($platformDb): void {
    $ensureSchoolColumn = static function (string $column, string $definition) use ($platformDb): void {
        try {
            $stmt = $platformDb->prepare('SHOW COLUMNS FROM `schools` LIKE ?');
            $stmt->execute([$column]);
            if (!$stmt->fetchColumn()) {
                $platformDb->exec("ALTER TABLE `schools` ADD COLUMN `{$column}` {$definition}");
            }
        } catch (Throwable $e) {
            error_log("public_profile_actions: could not ensure schools.{$column}: " . $e->getMessage());
        }
    };

    foreach ([
        'landing_showcase_image_1',
        'landing_showcase_image_2',
        'landing_showcase_image_3',
    ] as $column) {
        $ensureSchoolColumn($column, 'VARCHAR(255) NULL DEFAULT NULL');
    }

    $tables = [
        "CREATE TABLE IF NOT EXISTS `school_contacts` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` INT UNSIGNED NOT NULL,
            `type` VARCHAR(50) NOT NULL DEFAULT 'phone',
            `label` VARCHAR(120) DEFAULT NULL,
            `value` VARCHAR(255) NOT NULL,
            `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
            `sort_order` INT NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_school_contacts_school` (`school_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS `school_facilities` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` INT UNSIGNED NOT NULL,
            `name` VARCHAR(160) NOT NULL,
            `description` TEXT NULL,
            `icon` VARCHAR(120) DEFAULT NULL,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `sort_order` INT NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_school_facilities_school` (`school_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS `school_gallery` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` INT UNSIGNED NOT NULL,
            `image_url` VARCHAR(255) NOT NULL,
            `caption` VARCHAR(255) DEFAULT NULL,
            `type` VARCHAR(50) DEFAULT 'campus',
            `sort_order` INT NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_school_gallery_school` (`school_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS `school_reviews` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` INT UNSIGNED NOT NULL,
            `parent_name` VARCHAR(160) NOT NULL,
            `parent_email` VARCHAR(190) DEFAULT NULL,
            `student_name` VARCHAR(160) DEFAULT NULL,
            `rating` TINYINT UNSIGNED NOT NULL DEFAULT 5,
            `comment` TEXT NOT NULL,
            `is_approved` TINYINT(1) NOT NULL DEFAULT 0,
            `helpful_count` INT UNSIGNED NOT NULL DEFAULT 0,
            `submitter_ip` VARCHAR(64) DEFAULT NULL,
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_school_reviews_school` (`school_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS `school_profile_settings` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` INT UNSIGNED NOT NULL,
            `setting_key` VARCHAR(120) NOT NULL,
            `setting_value` TEXT NULL,
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_school_profile_setting` (`school_id`, `setting_key`),
            KEY `idx_school_profile_settings_school` (`school_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS `school_profile_faqs` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` INT UNSIGNED NOT NULL,
            `question` VARCHAR(255) NOT NULL,
            `answer` TEXT NOT NULL,
            `sort_order` INT NOT NULL DEFAULT 0,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_school_profile_faqs_school` (`school_id`, `is_active`, `sort_order`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS `school_profile_blogs` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` INT UNSIGNED NOT NULL,
            `title` VARCHAR(255) NOT NULL,
            `excerpt` TEXT NULL,
            `content` MEDIUMTEXT NULL,
            `image_url` VARCHAR(500) NULL,
            `category` VARCHAR(120) NOT NULL DEFAULT 'Education',
            `author_name` VARCHAR(180) NULL,
            `published_at` DATETIME NULL,
            `sort_order` INT NOT NULL DEFAULT 0,
            `is_published` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_school_profile_blogs_school` (`school_id`, `is_published`, `sort_order`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS `school_profile_slides` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` INT UNSIGNED NOT NULL,
            `title` VARCHAR(255) NULL,
            `subtitle` TEXT NULL,
            `image_url` VARCHAR(500) NOT NULL,
            `button_label` VARCHAR(120) NULL,
            `button_url` VARCHAR(500) NULL,
            `sort_order` INT NOT NULL DEFAULT 0,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_school_profile_slides_school` (`school_id`, `is_active`, `sort_order`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];

    foreach ($tables as $sql) {
        try {
            $platformDb->exec($sql);
        } catch (Throwable $e) {
            error_log('public_profile_actions: could not ensure profile table: ' . $e->getMessage());
        }
    }
};

$ensurePublicProfileSchema();

$profileSettingsWhitelist = array_fill_keys([
    'nav_home', 'nav_about', 'nav_subjects', 'nav_admission', 'nav_facilities', 'nav_gallery', 'nav_reviews', 'nav_contact',
    'hero_secondary_link_label', 'trusted_heading',
    'stat_students_label', 'stat_teachers_label', 'stat_classes_label', 'stat_subjects_label', 'stat_curriculum_label', 'stat_years_label', 'stat_teachers_badge_label',
    'about_badge', 'about_cta_label', 'experience_badge_label',
    'programs_badge', 'programs_heading', 'program_default_title', 'program_default_description',
    'testimonial_default_name', 'facilities_badge', 'facilities_heading', 'facility_default_title', 'facility_default_description',
    'faq_badge', 'faq_heading', 'faq_image_button_label',
    'updates_badge', 'updates_heading', 'updates_default_label', 'updates_default_title',
    'gallery_badge', 'gallery_heading', 'gallery_intro', 'slider_badge', 'slider_heading',
    'reviews_badge', 'reviews_heading', 'reviews_intro', 'reviews_empty', 'review_form_heading', 'review_form_intro', 'review_success_message',
    'review_name_label', 'review_email_label', 'review_student_label', 'review_rating_label', 'review_rating_placeholder', 'review_comment_label', 'review_submit_label', 'review_rating_options',
    'review_csrf_error', 'review_unavailable_error', 'review_name_comment_error', 'review_invalid_email_error', 'review_invalid_rating_error',
    'review_comment_too_long_error', 'review_parent_email_required_error', 'review_duplicate_error', 'review_submit_error',
    'admission_badge', 'admission_deadline_label', 'admission_success_prefix', 'admission_closed_prefix', 'admission_closed_error',
    'admission_parent_first_name_label', 'admission_parent_last_name_label', 'admission_parent_email_label', 'admission_parent_phone_label', 'admission_parent_address_label',
    'admission_student_first_name_label', 'admission_student_last_name_label', 'admission_gender_label', 'admission_gender_placeholder', 'admission_gender_options',
    'admission_dob_label', 'admission_class_label', 'admission_class_placeholder', 'admission_previous_school_label',
    'admission_enrollment_type_label', 'admission_enrollment_type_options', 'admission_year_label', 'admission_term_label', 'admission_term_placeholder',
    'admission_documents_label', 'admission_special_requirements_label', 'admission_special_requirements_placeholder', 'admission_submit_label',
    'contact_badge', 'contact_heading', 'contact_intro', 'contact_phone_label', 'contact_email_label', 'contact_address_label', 'contact_missing_value',
    'footer_credit', 'footer_nav_home', 'footer_nav_about', 'footer_nav_subjects', 'footer_nav_admission', 'footer_nav_gallery', 'footer_nav_contact',
    'theme_background_color', 'theme_hero_background_color', 'theme_footer_background_color', 'theme_card_background_color',
], true);

$saveProfileSettings = static function (array $settings) use ($platformDb, $schoolId, $profileSettingsWhitelist): void {
    if (!$settings || !is_array($settings)) {
        return;
    }

    $stmt = $platformDb->prepare(
        'INSERT INTO school_profile_settings (school_id, setting_key, setting_value)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = CURRENT_TIMESTAMP'
    );

    foreach ($settings as $key => $value) {
        $key = (string) $key;
        if (!isset($profileSettingsWhitelist[$key])) {
            continue;
        }

        $value = is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : trim((string) $value);
        if (strpos($key, 'theme_') === 0 && $value !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', $value)) {
            continue;
        }
        $stmt->execute([$schoolId, $key, $value]);
    }
};

// MIME → forced extension. Never trust the user-supplied filename.
$imageMime = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
    'image/gif'  => 'gif',
];

$saveImage = static function (array $f, string $purpose) use ($schoolId, $imageMime): ?string {
    if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'] ?? '')) return null;
    if (($f['size'] ?? 0) > 5 * 1024 * 1024) return null;
    $mime = '';
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        if ($fi) { $mime = (string) finfo_file($fi, $f['tmp_name']); finfo_close($fi); }
    }
    if ($mime === '' && function_exists('mime_content_type')) {
        $mime = (string) mime_content_type($f['tmp_name']);
    }
    if (!isset($imageMime[$mime])) return null;
    $ext = $imageMime[$mime];
    $root = dirname(__DIR__, 4); // four levels up from tabs/ to docroot
    $rel  = 'assets/uploads/schools/' . $schoolId . '/profile';
    $abs  = $root . '/' . $rel;
    if (!is_dir($abs) && !mkdir($abs, 0755, true) && !is_dir($abs)) return null;
    $name = $purpose . '-' . bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $abs . '/' . $name)) return null;
    return $rel . '/' . $name;
};

try {
    switch ($action) {

        // ------------------------------------------------ profile_save_basics
        case 'profile_save_basics': {
            $cols = $columnsOf('schools');
            // Whitelist of editable text columns. Each value is trimmed; only
            // columns that actually exist in this install are persisted.
            $textCols = [
                'description', 'mission_statement', 'vision_statement', 'principal_message',
                'landing_badge_text', 'landing_headline', 'landing_subheadline',
                'landing_primary_cta_text', 'landing_secondary_cta_text',
                'landing_intro_title', 'landing_intro_text',
                'landing_highlight_title', 'landing_highlight_text',
                'landing_cta_title', 'landing_cta_text',
                'curriculum', 'school_type', 'address', 'city', 'state', 'country',
                'phone', 'email', 'website',
            ];
            $sets = []; $vals = [];
            foreach ($textCols as $c) {
                if (in_array($c, $cols, true) && array_key_exists($c, $_POST)) {
                    $value = trim((string) $_POST[$c]);
                    if ($c === 'website' && in_array($value, ['http://', 'https://'], true)) {
                        $value = '';
                    }
                    $sets[] = "`$c` = ?";
                    $vals[] = $value;
                }
            }
            // Colors — must match #RRGGBB exactly.
            foreach (['primary_color', 'secondary_color'] as $cc) {
                if (in_array($cc, $cols, true) && isset($_POST[$cc])
                    && preg_match('/^#[0-9a-fA-F]{6}$/', (string) $_POST[$cc])) {
                    $sets[] = "`$cc` = ?"; $vals[] = (string) $_POST[$cc];
                }
            }
            // Admission status / deadline.
            if (in_array('admission_status', $cols, true)
                && in_array((string) ($_POST['admission_status'] ?? ''), ['open', 'closed', 'waiting_list'], true)) {
                $sets[] = "`admission_status` = ?"; $vals[] = (string) $_POST['admission_status'];
            }
            if (in_array('admission_deadline', $cols, true)) {
                $d = trim((string) ($_POST['admission_deadline'] ?? ''));
                $sets[] = "`admission_deadline` = ?";
                $vals[] = ($d !== '' && strtotime($d)) ? $d : null;
            }
            // Programs / testimonials — JSON columns.
            foreach (['landing_programs', 'landing_testimonials'] as $jc) {
                if (!in_array($jc, $cols, true)) continue;
                $raw = $_POST[$jc] ?? [];
                $clean = [];
                if (is_array($raw)) foreach ($raw as $row) {
                    if (!is_array($row)) continue;
                    $row = array_map(static fn ($v) => trim((string) $v), $row);
                    if ($jc === 'landing_programs' && ($row['title'] ?? '') !== '') {
                        $clean[] = ['title' => $row['title'], 'description' => $row['description'] ?? ''];
                    } elseif ($jc === 'landing_testimonials' && ($row['quote'] ?? '') !== '') {
                        $clean[] = ['name' => $row['name'] ?? '', 'role' => $row['role'] ?? '', 'quote' => $row['quote']];
                    }
                }
                $sets[] = "`$jc` = ?";
                $vals[] = json_encode($clean, JSON_UNESCAPED_UNICODE);
            }
            // Image uploads.
            foreach (['landing_hero_image', 'landing_feature_image', 'landing_showcase_image_1', 'landing_showcase_image_2', 'landing_showcase_image_3', 'logo_path'] as $ic) {
                if (!in_array($ic, $cols, true)) continue;
                if (!isset($_FILES[$ic]) || $_FILES[$ic]['error'] === UPLOAD_ERR_NO_FILE) continue;
                $saved = $saveImage($_FILES[$ic], str_replace('_', '-', $ic));
                if ($saved !== null) { $sets[] = "`$ic` = ?"; $vals[] = $saved; }
            }
            if (!$sets) return ['success' => false, 'message' => 'No fields submitted'];
            $vals[] = $schoolId;
            $platformDb->prepare('UPDATE schools SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);
            return ['success' => true, 'message' => 'Public profile saved.'];
        }

        // ------------------------------------------------ profile_save_contacts
        case 'profile_save_contacts': {
            if (!$tableExists('school_contacts')) {
                return ['success' => false, 'message' => 'Could not prepare the school contacts table automatically. Please check database permissions.'];
            }
            $cols = $columnsOf('school_contacts');
            $insertCols = array_values(array_filter(
                ['school_id', 'type', 'label', 'value', 'is_primary', 'sort_order'],
                static fn ($c) => $c === 'school_id' || in_array($c, $cols, true)
            ));
            $placeholders = implode(', ', array_fill(0, count($insertCols), '?'));
            $sql = 'INSERT INTO school_contacts (`' . implode('`,`', $insertCols) . "`) VALUES ($placeholders)";

            $platformDb->beginTransaction();
            $platformDb->prepare('DELETE FROM school_contacts WHERE school_id = ?')->execute([$schoolId]);
            $rows = is_array($_POST['contacts'] ?? null) ? $_POST['contacts'] : [];
            $stmt = $platformDb->prepare($sql);
            $sort = 0;
            foreach ($rows as $r) {
                if (!is_array($r)) continue;
                $value = trim((string) ($r['value'] ?? ''));
                if ($value === '') continue;
                $type = in_array($r['type'] ?? '', ['email', 'phone', 'address', 'website', 'whatsapp', 'social'], true)
                    ? $r['type'] : 'phone';
                $params = [];
                foreach ($insertCols as $c) {
                    switch ($c) {
                        case 'school_id':  $params[] = $schoolId; break;
                        case 'type':       $params[] = $type; break;
                        case 'label':      $params[] = trim((string) ($r['label'] ?? '')); break;
                        case 'value':      $params[] = $value; break;
                        case 'is_primary': $params[] = !empty($r['is_primary']) ? 1 : 0; break;
                        case 'sort_order': $params[] = $sort; break;
                        default:           $params[] = null;
                    }
                }
                $stmt->execute($params);
                $sort++;
            }
            $platformDb->commit();
            return ['success' => true, 'message' => 'Contacts saved.'];
        }

        // ----------------------------------------------- profile_save_facilities
        case 'profile_save_facilities': {
            if (!$tableExists('school_facilities')) {
                return ['success' => false, 'message' => 'Could not prepare the school facilities table automatically. Please check database permissions.'];
            }
            $cols = $columnsOf('school_facilities');
            $insertCols = array_values(array_filter(
                ['school_id', 'name', 'description', 'icon', 'is_active', 'sort_order'],
                static fn ($c) => $c === 'school_id' || in_array($c, $cols, true)
            ));
            $placeholders = implode(', ', array_fill(0, count($insertCols), '?'));
            $sql = 'INSERT INTO school_facilities (`' . implode('`,`', $insertCols) . "`) VALUES ($placeholders)";

            $platformDb->beginTransaction();
            $platformDb->prepare('DELETE FROM school_facilities WHERE school_id = ?')->execute([$schoolId]);
            $rows = is_array($_POST['facilities'] ?? null) ? $_POST['facilities'] : [];
            $stmt = $platformDb->prepare($sql);
            $sort = 0;
            foreach ($rows as $r) {
                if (!is_array($r)) continue;
                $name = trim((string) ($r['name'] ?? ''));
                if ($name === '') continue;
                $params = [];
                foreach ($insertCols as $c) {
                    switch ($c) {
                        case 'school_id':   $params[] = $schoolId; break;
                        case 'name':        $params[] = $name; break;
                        case 'description': $params[] = trim((string) ($r['description'] ?? '')); break;
                        case 'icon':        $params[] = trim((string) ($r['icon'] ?? '')); break;
                        case 'is_active':   $params[] = empty($r['is_inactive']) ? 1 : 0; break;
                        case 'sort_order':  $params[] = $sort; break;
                        default:            $params[] = null;
                    }
                }
                $stmt->execute($params);
                $sort++;
            }
            $platformDb->commit();
            return ['success' => true, 'message' => 'Facilities saved.'];
        }

        // ------------------------------------------------ profile_save_copy
        case 'profile_save_copy': {
            if (!$tableExists('school_profile_settings')) {
                return ['success' => false, 'message' => 'Could not prepare the profile settings table automatically. Please check database permissions.'];
            }
            $saveProfileSettings(is_array($_POST['settings'] ?? null) ? $_POST['settings'] : []);
            return ['success' => true, 'message' => 'Public profile copy and theme saved.'];
        }

        // ------------------------------------------------ profile_save_faqs
        case 'profile_save_faqs': {
            if (!$tableExists('school_profile_faqs')) {
                return ['success' => false, 'message' => 'Could not prepare the profile FAQ table automatically. Please check database permissions.'];
            }
            $platformDb->beginTransaction();
            $platformDb->prepare('DELETE FROM school_profile_faqs WHERE school_id = ?')->execute([$schoolId]);
            $stmt = $platformDb->prepare(
                'INSERT INTO school_profile_faqs (school_id, question, answer, sort_order, is_active) VALUES (?, ?, ?, ?, ?)'
            );
            $rows = is_array($_POST['faqs'] ?? null) ? $_POST['faqs'] : [];
            $sort = 0;
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $question = trim((string) ($row['question'] ?? ''));
                $answer = trim((string) ($row['answer'] ?? ''));
                if ($question === '' || $answer === '') {
                    continue;
                }
                $stmt->execute([$schoolId, $question, $answer, $sort, empty($row['is_inactive']) ? 1 : 0]);
                $sort += 10;
            }
            $platformDb->commit();
            return ['success' => true, 'message' => 'FAQs saved.'];
        }

        // ------------------------------------------------ profile_save_blogs
        case 'profile_save_blogs': {
            if (!$tableExists('school_profile_blogs')) {
                return ['success' => false, 'message' => 'Could not prepare the profile news table automatically. Please check database permissions.'];
            }
            $platformDb->beginTransaction();
            $platformDb->prepare('DELETE FROM school_profile_blogs WHERE school_id = ?')->execute([$schoolId]);
            $stmt = $platformDb->prepare(
                'INSERT INTO school_profile_blogs
                    (school_id, title, excerpt, content, image_url, category, author_name, published_at, sort_order, is_published)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $rows = is_array($_POST['blogs'] ?? null) ? $_POST['blogs'] : [];
            $sort = 0;
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $title = trim((string) ($row['title'] ?? ''));
                if ($title === '') {
                    continue;
                }
                $publishedAt = trim((string) ($row['published_at'] ?? ''));
                $stmt->execute([
                    $schoolId,
                    $title,
                    trim((string) ($row['excerpt'] ?? '')),
                    trim((string) ($row['content'] ?? '')),
                    trim((string) ($row['image_url'] ?? '')),
                    trim((string) ($row['category'] ?? 'Education')) ?: 'Education',
                    trim((string) ($row['author_name'] ?? '')),
                    ($publishedAt !== '' && strtotime($publishedAt)) ? date('Y-m-d H:i:s', strtotime($publishedAt)) : null,
                    $sort,
                    empty($row['is_unpublished']) ? 1 : 0,
                ]);
                $sort += 10;
            }
            $platformDb->commit();
            return ['success' => true, 'message' => 'News and blog updates saved.'];
        }

        // ----------------------------------------------- profile_gallery_add
        case 'profile_gallery_add': {
            $ensurePublicProfileSchema();
            if (!$tableExists('school_gallery')) {
                return ['success' => false, 'message' => 'Could not prepare the school gallery table automatically. Please check database permissions.'];
            }
            $cols = $columnsOf('school_gallery');
            $insertCols = array_values(array_filter(
                ['school_id', 'image_url', 'caption', 'type', 'sort_order'],
                static fn ($c) => $c === 'school_id' || in_array($c, $cols, true)
            ));
            $placeholders = implode(', ', array_fill(0, count($insertCols), '?'));
            $sql = 'INSERT INTO school_gallery (`' . implode('`,`', $insertCols) . "`) VALUES ($placeholders)";
            $stmt = $platformDb->prepare($sql);

            $files = $_FILES['gallery_images'] ?? null;
            if (!$files || !is_array($files['tmp_name'] ?? null)) {
                return ['success' => false, 'message' => 'No image files received.'];
            }
            $captions = $_POST['gallery_captions'] ?? [];
            $added = 0;
            $count = count($files['tmp_name']);
            for ($i = 0; $i < $count; $i++) {
                if ($files['error'][$i] !== UPLOAD_ERR_OK) continue;
                $f = [
                    'tmp_name' => $files['tmp_name'][$i],
                    'error'    => $files['error'][$i],
                    'size'     => $files['size'][$i] ?? 0,
                    'name'     => $files['name'][$i] ?? '',
                ];
                $path = $saveImage($f, 'gallery');
                if (!$path) continue;
                $params = [];
                foreach ($insertCols as $c) {
                    switch ($c) {
                        case 'school_id':  $params[] = $schoolId; break;
                        case 'image_url':  $params[] = $path; break;
                        case 'caption':    $params[] = trim((string) ($captions[$i] ?? '')); break;
                        case 'type':       $params[] = 'campus'; break;
                        case 'sort_order': $params[] = 1000 + $i; break;
                        default:           $params[] = null;
                    }
                }
                $stmt->execute($params);
                $added++;
            }
            return ['success' => true, 'message' => "Added {$added} image(s) to the gallery."];
        }

        // ----------------------------------------------- profile_gallery_delete
        case 'profile_gallery_delete': {
            $imgId = (int) ($_POST['image_id'] ?? 0);
            if ($imgId <= 0) return ['success' => false, 'message' => 'Invalid image id.'];
            $sel = $platformDb->prepare('SELECT image_url FROM school_gallery WHERE id = ? AND school_id = ?');
            $sel->execute([$imgId, $schoolId]);
            $img = $sel->fetch(PDO::FETCH_ASSOC);
            if (!$img) return ['success' => false, 'message' => 'Image not found.'];
            $platformDb->prepare('DELETE FROM school_gallery WHERE id = ? AND school_id = ?')
                       ->execute([$imgId, $schoolId]);
            $abs = dirname(__DIR__, 4) . '/' . ltrim((string) $img['image_url'], '/');
            if (is_file($abs)) @unlink($abs);
            return ['success' => true, 'message' => 'Image removed.'];
        }

        // ----------------------------------------------- profile_slide_add
        case 'profile_slide_add': {
            if (!$tableExists('school_profile_slides')) {
                return ['success' => false, 'message' => 'Could not prepare the profile slider table automatically. Please check database permissions.'];
            }
            $files = $_FILES['slide_images'] ?? null;
            if (!$files || !is_array($files['tmp_name'] ?? null)) {
                return ['success' => false, 'message' => 'No slide images received.'];
            }
            $stmt = $platformDb->prepare(
                'INSERT INTO school_profile_slides (school_id, title, subtitle, image_url, button_label, button_url, sort_order, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 1)'
            );
            $titles = $_POST['slide_titles'] ?? [];
            $subtitles = $_POST['slide_subtitles'] ?? [];
            $buttonLabels = $_POST['slide_button_labels'] ?? [];
            $buttonUrls = $_POST['slide_button_urls'] ?? [];
            $added = 0;
            $count = count($files['tmp_name']);
            for ($i = 0; $i < $count; $i++) {
                if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    continue;
                }
                $path = $saveImage([
                    'tmp_name' => $files['tmp_name'][$i],
                    'error' => $files['error'][$i],
                    'size' => $files['size'][$i] ?? 0,
                    'name' => $files['name'][$i] ?? '',
                ], 'slide');
                if (!$path) {
                    continue;
                }
                $stmt->execute([
                    $schoolId,
                    trim((string) ($titles[$i] ?? '')),
                    trim((string) ($subtitles[$i] ?? '')),
                    $path,
                    trim((string) ($buttonLabels[$i] ?? '')),
                    trim((string) ($buttonUrls[$i] ?? '')),
                    1000 + $i,
                ]);
                $added++;
            }
            return ['success' => true, 'message' => "Added {$added} slider image(s)."];
        }

        // ----------------------------------------------- profile_slide_delete
        case 'profile_slide_delete': {
            $slideId = (int) ($_POST['slide_id'] ?? 0);
            if ($slideId <= 0) {
                return ['success' => false, 'message' => 'Invalid slide id.'];
            }
            $sel = $platformDb->prepare('SELECT image_url FROM school_profile_slides WHERE id = ? AND school_id = ?');
            $sel->execute([$slideId, $schoolId]);
            $slide = $sel->fetch(PDO::FETCH_ASSOC);
            if (!$slide) {
                return ['success' => false, 'message' => 'Slide not found.'];
            }
            $platformDb->prepare('DELETE FROM school_profile_slides WHERE id = ? AND school_id = ?')->execute([$slideId, $schoolId]);
            $abs = dirname(__DIR__, 4) . '/' . ltrim((string) $slide['image_url'], '/');
            if (is_file($abs)) @unlink($abs);
            return ['success' => true, 'message' => 'Slider image removed.'];
        }

        // ----------------------------------------------- profile_review_toggle
        case 'profile_review_toggle': {
            $reviewId = (int) ($_POST['review_id'] ?? 0);
            $approve  = !empty($_POST['approve']) ? 1 : 0;
            $platformDb->prepare(
                'UPDATE school_reviews SET is_approved = ? WHERE id = ? AND school_id = ?'
            )->execute([$approve, $reviewId, $schoolId]);
            return ['success' => true, 'message' => $approve ? 'Review approved.' : 'Review hidden.'];
        }
    }
} catch (Throwable $e) {
    if ($platformDb->inTransaction()) $platformDb->rollBack();
    error_log('public_profile_actions error (' . $action . '): ' . $e->getMessage());
    return ['success' => false, 'message' => $e->getMessage()];
}

return ['success' => false, 'message' => 'Unhandled profile action.'];
