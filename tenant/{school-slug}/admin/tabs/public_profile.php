<?php
/**
 * Public-Profile editor tab.
 *
 * Renders inside general.php's right column. Every form posts AJAX to
 * general.php with `action=profile_*` — handlers live in
 * tabs/public_profile_actions.php.
 *
 * Reads current values from the PLATFORM database (schools row + the related
 * tables school_contacts / school_facilities / school_gallery / school_reviews)
 * so the form is always pre-filled with what the public profile is currently
 * showing.
 */

if (!function_exists('Database')) {
    // page is required by general.php, autoload already happened — defensive
    require_once dirname(__DIR__, 4) . '/includes/autoload.php';
}

$school = is_array($school ?? null) ? $school : ($GLOBALS['SCHOOL_DATA'] ?? []);
$schoolSlug = (string) ($schoolSlug ?? ($GLOBALS['SCHOOL_SLUG'] ?? ''));
if (empty($school) && $schoolSlug !== '' && isset($_SESSION['school_info'][$schoolSlug])) {
    $school = $_SESSION['school_info'][$schoolSlug];
}

$ppDb  = \Database::getPlatformConnection();
$ppSid = (int) ($school['id'] ?? 0);
$pE = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

$ppCols = (function () use ($ppDb): array {
    try { return array_column($ppDb->query("SHOW COLUMNS FROM `schools`")->fetchAll(PDO::FETCH_ASSOC), 'Field'); }
    catch (Throwable $e) { return []; }
})();

$ppTableExists = static function (string $t) use ($ppDb): bool {
    try { $s = $ppDb->prepare('SHOW TABLES LIKE ?'); $s->execute([$t]); return (bool) $s->fetchColumn(); }
    catch (Throwable $e) { return false; }
};

$ppEnsurePublicProfileSchema = static function () use ($ppDb): void {
    $ensureSchoolColumn = static function (string $column, string $definition) use ($ppDb): void {
        try {
            $stmt = $ppDb->prepare('SHOW COLUMNS FROM `schools` LIKE ?');
            $stmt->execute([$column]);
            if (!$stmt->fetchColumn()) {
                $ppDb->exec("ALTER TABLE `schools` ADD COLUMN `{$column}` {$definition}");
            }
        } catch (Throwable $e) {
            error_log("public_profile_tab: could not ensure schools.{$column}: " . $e->getMessage());
        }
    };

    foreach ([
        'landing_showcase_image_1',
        'landing_showcase_image_2',
        'landing_showcase_image_3',
    ] as $column) {
        $ensureSchoolColumn($column, 'VARCHAR(255) NULL DEFAULT NULL');
    }

    foreach ([
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
    ] as $sql) {
        try {
            $ppDb->exec($sql);
        } catch (Throwable $e) {
            error_log('public_profile_tab: could not ensure profile table: ' . $e->getMessage());
        }
    }
};

$ppEnsurePublicProfileSchema();
$ppCols = (function () use ($ppDb): array {
    try { return array_column($ppDb->query("SHOW COLUMNS FROM `schools`")->fetchAll(PDO::FETCH_ASSOC), 'Field'); }
    catch (Throwable $e) { return []; }
})();

$ppRow = (function () use ($ppDb, $ppSid): array {
    try { $s = $ppDb->prepare('SELECT * FROM schools WHERE id = ? LIMIT 1'); $s->execute([$ppSid]); $r = $s->fetch(PDO::FETCH_ASSOC); return $r ?: []; }
    catch (Throwable $e) { return []; }
})();

$ppContacts = $ppTableExists('school_contacts')
    ? (function () use ($ppDb, $ppSid) {
        $s = $ppDb->prepare('SELECT * FROM school_contacts WHERE school_id = ? ORDER BY is_primary DESC, sort_order ASC, type ASC');
        $s->execute([$ppSid]); return $s->fetchAll(PDO::FETCH_ASSOC);
    })() : [];

$ppFacilities = $ppTableExists('school_facilities')
    ? (function () use ($ppDb, $ppSid) {
        $s = $ppDb->prepare('SELECT * FROM school_facilities WHERE school_id = ? ORDER BY sort_order ASC, id ASC');
        $s->execute([$ppSid]); return $s->fetchAll(PDO::FETCH_ASSOC);
    })() : [];

$ppGallery = $ppTableExists('school_gallery')
    ? (function () use ($ppDb, $ppSid) {
        $s = $ppDb->prepare('SELECT * FROM school_gallery WHERE school_id = ? ORDER BY sort_order ASC, id DESC');
        $s->execute([$ppSid]); return $s->fetchAll(PDO::FETCH_ASSOC);
    })() : [];

$ppReviews = $ppTableExists('school_reviews')
    ? (function () use ($ppDb, $ppSid) {
        $s = $ppDb->prepare('SELECT * FROM school_reviews WHERE school_id = ? ORDER BY created_at DESC LIMIT 30');
        $s->execute([$ppSid]); return $s->fetchAll(PDO::FETCH_ASSOC);
    })() : [];

$ppSettings = $ppTableExists('school_profile_settings')
    ? (function () use ($ppDb, $ppSid) {
        $s = $ppDb->prepare('SELECT setting_key, setting_value FROM school_profile_settings WHERE school_id = ?');
        $s->execute([$ppSid]); return array_column($s->fetchAll(PDO::FETCH_ASSOC), 'setting_value', 'setting_key');
    })() : [];

$ppFaqs = $ppTableExists('school_profile_faqs')
    ? (function () use ($ppDb, $ppSid) {
        $s = $ppDb->prepare('SELECT * FROM school_profile_faqs WHERE school_id = ? ORDER BY sort_order ASC, id ASC');
        $s->execute([$ppSid]); return $s->fetchAll(PDO::FETCH_ASSOC);
    })() : [];

$ppBlogs = $ppTableExists('school_profile_blogs')
    ? (function () use ($ppDb, $ppSid) {
        $s = $ppDb->prepare('SELECT * FROM school_profile_blogs WHERE school_id = ? ORDER BY sort_order ASC, COALESCE(published_at, created_at) DESC, id DESC LIMIT 12');
        $s->execute([$ppSid]); return $s->fetchAll(PDO::FETCH_ASSOC);
    })() : [];

$ppSlides = $ppTableExists('school_profile_slides')
    ? (function () use ($ppDb, $ppSid) {
        $s = $ppDb->prepare('SELECT * FROM school_profile_slides WHERE school_id = ? ORDER BY sort_order ASC, id ASC');
        $s->execute([$ppSid]); return $s->fetchAll(PDO::FETCH_ASSOC);
    })() : [];

$ppDefaultSettings = [
    'trusted_heading' => 'Trusted by families and learners across {location}',
    'programs_heading' => 'Our Popular Learning Areas',
    'facilities_heading' => 'Campus Spaces Built for Learning',
    'faq_heading' => 'Frequently Asked Questions',
    'updates_heading' => 'Latest News & Events',
    'gallery_heading' => 'Life at {school}',
    'reviews_heading' => 'What Parents Say',
    'contact_heading' => 'Visit, call, or send a message',
    'slider_heading' => 'Featured moments from {school}',
    'about_badge' => 'About Us',
    'programs_badge' => 'Subjects',
    'facilities_badge' => 'Facilities',
    'faq_badge' => 'FAQ',
    'updates_badge' => 'Updates',
    'gallery_badge' => 'Gallery',
    'reviews_badge' => 'Reviews',
    'admission_badge' => 'Admission',
    'contact_badge' => 'Contact',
    'review_form_heading' => 'Share your experience',
    'review_form_intro' => 'Only parents or guardians with an email on the school account can post reviews.',
    'review_rating_options' => '{"5":"Excellent","4":"Very good","3":"Good","2":"Fair","1":"Poor"}',
    'admission_gender_options' => '{"male":"Male","female":"Female","other":"Other"}',
    'admission_enrollment_type_options' => '{"new":"New student","transfer":"Transfer","re_enrollment":"Re-enrollment"}',
    'footer_credit' => 'Made with AcademixSuite.',
    'theme_background_color' => '#fbfff7',
    'theme_hero_background_color' => '#14382f',
    'theme_footer_background_color' => '#14382f',
    'theme_card_background_color' => '#ffffff',
];
$ppSetting = static fn (string $key) => $ppSettings[$key] ?? ($ppDefaultSettings[$key] ?? '');

$ppPrograms = []; $ppTestimonials = [];
$pRaw = $ppRow['landing_programs']     ?? null;
$tRaw = $ppRow['landing_testimonials'] ?? null;
if ($pRaw) { $d = json_decode((string) $pRaw, true); if (is_array($d)) $ppPrograms     = $d; }
if ($tRaw) { $d = json_decode((string) $tRaw, true); if (is_array($d)) $ppTestimonials = $d; }

$ppPublicUrl = 'https://' . $pE($schoolSlug ?? '') . '.academixsuite.com/';
?>

<style>
.pp-card { background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:22px;margin-bottom:18px }
.pp-card h5 { font-size:15px;font-weight:600;margin:0 0 4px;color:#0f172a }
.pp-card .hint { font-size:13px;color:#6b7280;margin-bottom:18px }
.pp-grid { display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px }
.pp-grid label { display:block;font-size:12px;font-weight:600;margin-bottom:5px;color:#374151 }
.pp-grid .full { grid-column:1/-1 }
.pp-row { background:#f9fafb;border:1px dashed #e5e7eb;border-radius:10px;padding:14px;margin-bottom:10px }
.pp-thumb { width:100%;height:120px;object-fit:cover;display:block;border-radius:10px;border:1px solid #e5e7eb }
.pp-gallery-grid { display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px;margin-top:14px }
.pp-gallery-cell { position:relative;border:1px solid #e5e7eb;border-radius:10px;overflow:hidden;background:#fff }
.pp-gallery-cell img { width:100%;height:130px;object-fit:cover;display:block }
.pp-gallery-cell .cap { font-size:11px;color:#6b7280;padding:6px 8px }
.pp-gallery-cell .del { position:absolute;top:6px;right:6px;background:#fff;border:1px solid #fecaca;color:#b91c1c;border-radius:8px;width:26px;height:26px;display:flex;align-items:center;justify-content:center;cursor:pointer;font-weight:700 }
.pp-color-pair { display:flex;gap:8px;align-items:center }
.pp-color-pair input[type=color] { width:44px;height:38px;padding:2px;border-radius:8px;border:1px solid #e5e7eb }
.pp-image-field { background:#f8fafc;border:1px solid #e5e7eb;border-radius:12px;padding:12px }
.pp-image-field small { display:block;margin-top:6px;color:#64748b;font-size:11px;line-height:1.4 }
.pp-tabs { display:flex;gap:6px;flex-wrap:wrap;border-bottom:1px solid #e5e7eb;margin-bottom:18px }
.pp-tabs button { background:none;border:0;padding:10px 14px;font-size:13px;font-weight:500;color:#6b7280;border-bottom:2px solid transparent;cursor:pointer }
.pp-tabs button.is-active { color:#0f172a;border-bottom-color:#2563eb }
.pp-panel { display:none } .pp-panel.is-active { display:block }
.pp-actions { display:flex;justify-content:flex-end;gap:8px;padding-top:14px;border-top:1px solid #f3f4f6;margin-top:18px }
.pp-actions button.primary { background:#1f2937;color:#fff;border:0;padding:10px 22px;border-radius:10px;font-weight:600;cursor:pointer }
.pp-actions button.danger { background:#fff;color:#b91c1c;border:1px solid #fecaca;padding:8px 14px;border-radius:8px;font-size:12px;cursor:pointer }
.pp-actions button.secondary { background:#fff;color:#0f172a;border:1px solid #e5e7eb;padding:9px 16px;border-radius:10px;font-weight:500;cursor:pointer }
.pp-status-badge { display:inline-block;padding:3px 10px;border-radius:99px;font-size:11px;font-weight:600 }
.pp-status-badge.ok { background:#dcfce7;color:#166534 } .pp-status-badge.pending { background:#fef3c7;color:#92400e }
</style>

<div class="pp-card">
    <h5 style="display:flex;align-items:center;gap:10px;justify-content:space-between;flex-wrap:wrap">
        <span><i class="ri-pages-line"></i> Public Profile Editor</span>
        <a href="<?php echo $ppPublicUrl; ?>" target="_blank" class="btn btn-sm btn-primary" style="font-size:12px;font-weight:600">View live page →</a>
    </h5>
    <p class="hint">Everything on your public page (<?php echo $pE(rtrim($ppPublicUrl, '/')); ?>) is controlled here. Changes save instantly.</p>

    <nav class="pp-tabs">
        <button type="button" class="is-active" data-pp-tab="basics">Hero &amp; About</button>
        <button type="button" data-pp-tab="programs">Programs &amp; Testimonials</button>
        <button type="button" data-pp-tab="contacts">Contacts</button>
        <button type="button" data-pp-tab="facilities">Facilities</button>
        <button type="button" data-pp-tab="gallery">Gallery</button>
        <button type="button" data-pp-tab="reviews">Reviews</button>
        <button type="button" data-pp-tab="copy">Copy &amp; Theme</button>
        <button type="button" data-pp-tab="faqs">FAQs</button>
        <button type="button" data-pp-tab="blogs">News</button>
        <button type="button" data-pp-tab="slides">Slider</button>
    </nav>

    <!-- ================= BASICS ================= -->
    <section class="pp-panel is-active" id="pp-panel-basics">
        <form id="pp-form-basics" enctype="multipart/form-data" novalidate>
            <input type="hidden" name="action" value="profile_save_basics">

            <div class="pp-grid">
                <div><label>School type</label>
                    <select name="school_type" class="form-control">
                        <?php foreach (['nursery','primary','secondary','comprehensive','international','montessori','boarding','day'] as $o): ?>
                            <option value="<?php echo $o; ?>" <?php echo ($ppRow['school_type'] ?? '') === $o ? 'selected' : ''; ?>><?php echo ucfirst($o); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div><label>Curriculum</label><input type="text" class="form-control" name="curriculum" value="<?php echo $pE($ppRow['curriculum'] ?? ''); ?>"></div>
                <div><label>Admission status</label>
                    <select name="admission_status" class="form-control">
                        <?php foreach (['open','closed','waiting_list'] as $o): ?>
                            <option value="<?php echo $o; ?>" <?php echo ($ppRow['admission_status'] ?? 'open') === $o ? 'selected' : ''; ?>><?php echo str_replace('_', ' ', ucfirst($o)); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div><label>Admission deadline</label><input type="date" class="form-control" name="admission_deadline" value="<?php echo $pE($ppRow['admission_deadline'] ?? ''); ?>"></div>
            </div>

            <h6 style="margin-top:22px;font-size:13px;font-weight:700;color:#374151">Hero section</h6>
            <div class="pp-grid">
                <div>
                    <label style="display:flex;align-items:center;justify-content:space-between">
                        Badge text
                        <button type="button" class="btn-ai-gen" data-field="pp_landing_badge_text" data-label="Hero Badge Text">
                            <i class="ri-sparkling-line"></i> Generate
                        </button>
                    </label>
                    <input type="text" id="pp_landing_badge_text" class="form-control" name="landing_badge_text" value="<?php echo $pE($ppRow['landing_badge_text'] ?? ''); ?>" placeholder="Admissions open">
                </div>
                <div>
                    <label style="display:flex;align-items:center;justify-content:space-between">
                        Primary CTA label
                        <button type="button" class="btn-ai-gen" data-field="pp_landing_primary_cta_text" data-label="Primary CTA Button">
                            <i class="ri-sparkling-line"></i> Generate
                        </button>
                    </label>
                    <input type="text" id="pp_landing_primary_cta_text" class="form-control" name="landing_primary_cta_text" value="<?php echo $pE($ppRow['landing_primary_cta_text'] ?? ''); ?>" placeholder="Apply Now">
                </div>
                <div>
                    <label style="display:flex;align-items:center;justify-content:space-between">
                        Secondary CTA label
                        <button type="button" class="btn-ai-gen" data-field="pp_landing_secondary_cta_text" data-label="Secondary CTA Button">
                            <i class="ri-sparkling-line"></i> Generate
                        </button>
                    </label>
                    <input type="text" id="pp_landing_secondary_cta_text" class="form-control" name="landing_secondary_cta_text" value="<?php echo $pE($ppRow['landing_secondary_cta_text'] ?? ''); ?>" placeholder="Portal Login">
                </div>
                <div class="full">
                    <label style="display:flex;align-items:center;justify-content:space-between">
                        Headline
                        <button type="button" class="btn-ai-gen" data-field="pp_landing_headline" data-label="Hero Headline">
                            <i class="ri-sparkling-line"></i> Generate
                        </button>
                    </label>
                    <input type="text" id="pp_landing_headline" class="form-control" name="landing_headline" value="<?php echo $pE($ppRow['landing_headline'] ?? ''); ?>">
                </div>
                <div class="full">
                    <label style="display:flex;align-items:center;justify-content:space-between">
                        Sub-headline
                        <button type="button" class="btn-ai-gen" data-field="pp_landing_subheadline" data-label="Hero Sub-headline">
                            <i class="ri-sparkling-line"></i> Generate
                        </button>
                    </label>
                    <textarea id="pp_landing_subheadline" name="landing_subheadline" class="form-control" rows="2"><?php echo $pE($ppRow['landing_subheadline'] ?? ''); ?></textarea>
                </div>
            </div>

            <h6 style="margin-top:22px;font-size:13px;font-weight:700;color:#374151">Hero, feature &amp; showcase images (max 5 MB, jpg/png/webp)</h6>
            <div class="pp-grid">
                <div class="pp-image-field">
                    <label>Hero image</label>
                    <input type="file" class="form-control" name="landing_hero_image" accept="image/png,image/jpeg,image/webp">
                    <small>Main image used in the public landing hero.</small>
                    <?php if (!empty($ppRow['landing_hero_image'])): ?>
                        <img class="pp-thumb" style="margin-top:8px" src="/<?php echo $pE(ltrim((string) $ppRow['landing_hero_image'], '/')); ?>">
                    <?php endif; ?>
                </div>
                <div class="pp-image-field">
                    <label>Feature image</label>
                    <input type="file" class="form-control" name="landing_feature_image" accept="image/png,image/jpeg,image/webp">
                    <small>Used in the about/program highlight areas.</small>
                    <?php if (!empty($ppRow['landing_feature_image'])): ?>
                        <img class="pp-thumb" style="margin-top:8px" src="/<?php echo $pE(ltrim((string) $ppRow['landing_feature_image'], '/')); ?>">
                    <?php endif; ?>
                </div>
                <div class="pp-image-field">
                    <label>School logo</label>
                    <input type="file" class="form-control" name="logo_path" accept="image/png,image/jpeg,image/webp">
                    <small>Shown in the public navbar, login, and school admin sidebar.</small>
                    <?php if (!empty($ppRow['logo_path'])): ?>
                        <img class="pp-thumb" style="margin-top:8px;max-width:120px;height:auto" src="/<?php echo $pE(ltrim((string) $ppRow['logo_path'], '/')); ?>">
                    <?php endif; ?>
                </div>
                <?php
                    $showcaseLabels = [
                        'landing_showcase_image_1' => 'Showcase image 1',
                        'landing_showcase_image_2' => 'Showcase image 2',
                        'landing_showcase_image_3' => 'Showcase image 3',
                    ];
                ?>
                <?php foreach ($showcaseLabels as $showcaseColumn => $showcaseLabel): ?>
                    <div class="pp-image-field">
                        <label><?php echo $pE($showcaseLabel); ?></label>
                        <input type="file" class="form-control" name="<?php echo $pE($showcaseColumn); ?>" accept="image/png,image/jpeg,image/webp">
                        <small>Controls one of the circular showcase images and the school-life cards.</small>
                        <?php if (!empty($ppRow[$showcaseColumn])): ?>
                            <img class="pp-thumb" style="margin-top:8px" src="/<?php echo $pE(ltrim((string) $ppRow[$showcaseColumn], '/')); ?>">
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <h6 style="margin-top:22px;font-size:13px;font-weight:700;color:#374151">About &amp; story</h6>
            <div class="pp-grid">
                <div class="full">
                    <label style="display:flex;align-items:center;justify-content:space-between">
                        Short description
                        <button type="button" class="btn-ai-gen" data-field="pp_description" data-label="Short Description">
                            <i class="ri-sparkling-line"></i> Generate
                        </button>
                    </label>
                    <textarea id="pp_description" name="description" class="form-control" rows="3"><?php echo $pE($ppRow['description'] ?? ''); ?></textarea>
                </div>
                <div>
                    <label style="display:flex;align-items:center;justify-content:space-between">
                        Intro title
                        <button type="button" class="btn-ai-gen" data-field="pp_landing_intro_title" data-label="Intro Section Title">
                            <i class="ri-sparkling-line"></i> Generate
                        </button>
                    </label>
                    <input type="text" id="pp_landing_intro_title" class="form-control" name="landing_intro_title" value="<?php echo $pE($ppRow['landing_intro_title'] ?? ''); ?>">
                </div>
                <div>
                    <label style="display:flex;align-items:center;justify-content:space-between">
                        Highlight title
                        <button type="button" class="btn-ai-gen" data-field="pp_landing_highlight_title" data-label="Highlight Section Title">
                            <i class="ri-sparkling-line"></i> Generate
                        </button>
                    </label>
                    <input type="text" id="pp_landing_highlight_title" class="form-control" name="landing_highlight_title" value="<?php echo $pE($ppRow['landing_highlight_title'] ?? ''); ?>">
                </div>
                <div class="full">
                    <label style="display:flex;align-items:center;justify-content:space-between">
                        Intro body
                        <button type="button" class="btn-ai-gen" data-field="pp_landing_intro_text" data-label="Intro Body Text">
                            <i class="ri-sparkling-line"></i> Generate
                        </button>
                    </label>
                    <textarea id="pp_landing_intro_text" name="landing_intro_text" class="form-control" rows="3"><?php echo $pE($ppRow['landing_intro_text'] ?? ''); ?></textarea>
                </div>
                <div class="full">
                    <label style="display:flex;align-items:center;justify-content:space-between">
                        Highlight body
                        <button type="button" class="btn-ai-gen" data-field="pp_landing_highlight_text" data-label="Highlight Body Text">
                            <i class="ri-sparkling-line"></i> Generate
                        </button>
                    </label>
                    <textarea id="pp_landing_highlight_text" name="landing_highlight_text" class="form-control" rows="3"><?php echo $pE($ppRow['landing_highlight_text'] ?? ''); ?></textarea>
                </div>
                <div>
                    <label style="display:flex;align-items:center;justify-content:space-between">
                        Mission statement
                        <button type="button" class="btn-ai-gen" data-field="pp_mission_statement" data-label="Mission Statement">
                            <i class="ri-sparkling-line"></i> Generate
                        </button>
                    </label>
                    <textarea id="pp_mission_statement" name="mission_statement" class="form-control" rows="3"><?php echo $pE($ppRow['mission_statement'] ?? ''); ?></textarea>
                </div>
                <div>
                    <label style="display:flex;align-items:center;justify-content:space-between">
                        Vision statement
                        <button type="button" class="btn-ai-gen" data-field="pp_vision_statement" data-label="Vision Statement">
                            <i class="ri-sparkling-line"></i> Generate
                        </button>
                    </label>
                    <textarea id="pp_vision_statement" name="vision_statement" class="form-control" rows="3"><?php echo $pE($ppRow['vision_statement'] ?? ''); ?></textarea>
                </div>
                <div class="full">
                    <label style="display:flex;align-items:center;justify-content:space-between">
                        Principal's message
                        <button type="button" class="btn-ai-gen" data-field="pp_principal_message" data-label="Principal's Message">
                            <i class="ri-sparkling-line"></i> Generate
                        </button>
                    </label>
                    <textarea id="pp_principal_message" name="principal_message" class="form-control" rows="3"><?php echo $pE($ppRow['principal_message'] ?? ''); ?></textarea>
                </div>
            </div>

            <h6 style="margin-top:22px;font-size:13px;font-weight:700;color:#374151">Closing CTA &amp; brand colours</h6>
            <div class="pp-grid">
                <div>
                    <label style="display:flex;align-items:center;justify-content:space-between">
                        CTA title
                        <button type="button" class="btn-ai-gen" data-field="pp_landing_cta_title" data-label="Closing CTA Title">
                            <i class="ri-sparkling-line"></i> Generate
                        </button>
                    </label>
                    <input type="text" id="pp_landing_cta_title" class="form-control" name="landing_cta_title" value="<?php echo $pE($ppRow['landing_cta_title'] ?? ''); ?>">
                </div>
                <div>
                    <label style="display:flex;align-items:center;justify-content:space-between">
                        CTA body
                        <button type="button" class="btn-ai-gen" data-field="pp_landing_cta_text" data-label="Closing CTA Body">
                            <i class="ri-sparkling-line"></i> Generate
                        </button>
                    </label>
                    <input type="text" id="pp_landing_cta_text" class="form-control" name="landing_cta_text" value="<?php echo $pE($ppRow['landing_cta_text'] ?? ''); ?>">
                </div>
                <div>
                    <label>Primary colour</label>
                    <div class="pp-color-pair">
                        <input type="color" value="<?php echo $pE($ppRow['primary_color'] ?? '#7c73ff'); ?>" oninput="this.nextElementSibling.value=this.value">
                        <input type="text" class="form-control" name="primary_color" value="<?php echo $pE($ppRow['primary_color'] ?? '#7c73ff'); ?>" pattern="^#[0-9A-Fa-f]{6}$">
                    </div>
                </div>
                <div>
                    <label>Secondary colour</label>
                    <div class="pp-color-pair">
                        <input type="color" value="<?php echo $pE($ppRow['secondary_color'] ?? '#b8ff61'); ?>" oninput="this.nextElementSibling.value=this.value">
                        <input type="text" class="form-control" name="secondary_color" value="<?php echo $pE($ppRow['secondary_color'] ?? '#b8ff61'); ?>" pattern="^#[0-9A-Fa-f]{6}$">
                    </div>
                </div>
            </div>

            <h6 style="margin-top:22px;font-size:13px;font-weight:700;color:#374151">Location &amp; top-level contact</h6>
            <div class="pp-grid">
                <div><label>Phone</label><input type="tel" class="form-control" name="phone" value="<?php echo $pE($ppRow['phone'] ?? ''); ?>"></div>
                <div><label>Email</label><input type="email" class="form-control" name="email" value="<?php echo $pE($ppRow['email'] ?? ''); ?>"></div>
                <div><label>Website</label><input type="url" class="form-control" name="website" value="<?php echo $pE($ppRow['website'] ?? ''); ?>" placeholder="https://"></div>
                <div><label>Address</label><input type="text" class="form-control" name="address" value="<?php echo $pE($ppRow['address'] ?? ''); ?>"></div>
                <div><label>City</label><input type="text" class="form-control" name="city" value="<?php echo $pE($ppRow['city'] ?? ''); ?>"></div>
                <div><label>State</label><input type="text" class="form-control" name="state" value="<?php echo $pE($ppRow['state'] ?? ''); ?>"></div>
                <div><label>Country</label><input type="text" class="form-control" name="country" value="<?php echo $pE($ppRow['country'] ?? ''); ?>"></div>
            </div>

            <div class="pp-actions"><button type="submit" class="primary">Save profile</button></div>
        </form>
    </section>

    <!-- ================= PROGRAMS & TESTIMONIALS ================= -->
    <section class="pp-panel" id="pp-panel-programs">
        <form id="pp-form-programs" enctype="multipart/form-data" novalidate>
            <input type="hidden" name="action" value="profile_save_basics">

            <h6 style="font-size:13px;font-weight:700;color:#374151;margin-bottom:10px">Programs</h6>
            <div id="pp-programs-list">
                <?php $ppPrograms = $ppPrograms ?: [['title'=>'', 'description'=>'']];
                foreach ($ppPrograms as $i => $p): ?>
                    <div class="pp-row" data-row>
                        <div class="pp-grid">
                            <div><label>Title</label><input type="text" class="form-control" name="landing_programs[<?php echo $i; ?>][title]" value="<?php echo $pE($p['title'] ?? ''); ?>"></div>
                            <div><label>Description</label><input type="text" class="form-control" name="landing_programs[<?php echo $i; ?>][description]" value="<?php echo $pE($p['description'] ?? ''); ?>"></div>
                        </div>
                        <div style="margin-top:8px"><button type="button" class="danger" onclick="this.closest('[data-row]').remove()">Remove</button></div>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="secondary" onclick="ppAddRow('pp-programs-list','landing_programs',['title','description'])">+ Add program</button>

            <h6 style="font-size:13px;font-weight:700;color:#374151;margin:24px 0 10px">Testimonials</h6>
            <div id="pp-testimonials-list">
                <?php $ppTestimonials = $ppTestimonials ?: [['name'=>'', 'role'=>'', 'quote'=>'']];
                foreach ($ppTestimonials as $i => $t): ?>
                    <div class="pp-row" data-row>
                        <div class="pp-grid">
                            <div><label>Author name</label><input type="text" class="form-control" name="landing_testimonials[<?php echo $i; ?>][name]" value="<?php echo $pE($t['name'] ?? ''); ?>"></div>
                            <div><label>Author role</label><input type="text" class="form-control" name="landing_testimonials[<?php echo $i; ?>][role]" value="<?php echo $pE($t['role'] ?? ''); ?>"></div>
                            <div class="full"><label>Quote</label><textarea name="landing_testimonials[<?php echo $i; ?>][quote]" class="form-control" rows="2"><?php echo $pE($t['quote'] ?? ''); ?></textarea></div>
                        </div>
                        <div style="margin-top:8px"><button type="button" class="danger" onclick="this.closest('[data-row]').remove()">Remove</button></div>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="secondary" onclick="ppAddRow('pp-testimonials-list','landing_testimonials',['name','role','quote'])">+ Add testimonial</button>

            <div class="pp-actions"><button type="submit" class="primary">Save programs &amp; testimonials</button></div>
        </form>
    </section>

    <!-- ================= CONTACTS ================= -->
    <section class="pp-panel" id="pp-panel-contacts">
        <form id="pp-form-contacts" novalidate>
            <input type="hidden" name="action" value="profile_save_contacts">
            <div id="pp-contacts-list">
                <?php $ppContacts = $ppContacts ?: [['type'=>'phone','label'=>'','value'=>'','is_primary'=>0]];
                foreach ($ppContacts as $i => $c): ?>
                    <div class="pp-row" data-row>
                        <div class="pp-grid">
                            <div><label>Type</label>
                                <select class="form-control" name="contacts[<?php echo $i; ?>][type]">
                                    <?php foreach (['email','phone','address','website','whatsapp','social'] as $o): ?>
                                        <option value="<?php echo $o; ?>" <?php echo ($c['type'] ?? '') === $o ? 'selected' : ''; ?>><?php echo ucfirst($o); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div><label>Label</label><input type="text" class="form-control" name="contacts[<?php echo $i; ?>][label]" value="<?php echo $pE($c['label'] ?? ''); ?>" placeholder="Admissions office"></div>
                            <div><label>Value</label><input type="text" class="form-control" name="contacts[<?php echo $i; ?>][value]" value="<?php echo $pE($c['value'] ?? ''); ?>" required></div>
                            <div style="display:flex;align-items:flex-end;gap:6px">
                                <label style="margin-bottom:8px;font-weight:400;font-size:12px"><input type="checkbox" name="contacts[<?php echo $i; ?>][is_primary]" value="1" <?php echo !empty($c['is_primary']) ? 'checked' : ''; ?>> Primary</label>
                            </div>
                        </div>
                        <div style="margin-top:8px"><button type="button" class="danger" onclick="this.closest('[data-row]').remove()">Remove</button></div>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="secondary" onclick="ppAddRow('pp-contacts-list','contacts',['type','label','value','is_primary'])">+ Add contact</button>
            <div class="pp-actions"><button type="submit" class="primary">Save contacts</button></div>
        </form>
    </section>

    <!-- ================= FACILITIES ================= -->
    <section class="pp-panel" id="pp-panel-facilities">
        <form id="pp-form-facilities" novalidate>
            <input type="hidden" name="action" value="profile_save_facilities">
            <div id="pp-facilities-list">
                <?php $ppFacilities = $ppFacilities ?: [['name'=>'','description'=>'','icon'=>'']];
                foreach ($ppFacilities as $i => $f): ?>
                    <div class="pp-row" data-row>
                        <div class="pp-grid">
                            <div><label>Name</label><input type="text" class="form-control" name="facilities[<?php echo $i; ?>][name]" value="<?php echo $pE($f['name'] ?? ''); ?>"></div>
                            <div><label>Icon (FontAwesome, optional)</label><input type="text" class="form-control" name="facilities[<?php echo $i; ?>][icon]" value="<?php echo $pE($f['icon'] ?? ''); ?>" placeholder="fas fa-school"></div>
                            <div class="full"><label>Description</label><textarea class="form-control" rows="2" name="facilities[<?php echo $i; ?>][description]"><?php echo $pE($f['description'] ?? ''); ?></textarea></div>
                        </div>
                        <div style="margin-top:8px;display:flex;gap:12px;align-items:center">
                            <label style="font-weight:400;font-size:12px;margin:0"><input type="checkbox" name="facilities[<?php echo $i; ?>][is_inactive]" value="1" <?php echo isset($f['is_active']) && (int) $f['is_active'] === 0 ? 'checked' : ''; ?>> Hide from public page</label>
                            <button type="button" class="danger" onclick="this.closest('[data-row]').remove()">Remove</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="secondary" onclick="ppAddRow('pp-facilities-list','facilities',['name','icon','description'])">+ Add facility</button>
            <div class="pp-actions"><button type="submit" class="primary">Save facilities</button></div>
        </form>
    </section>

    <!-- ================= GALLERY ================= -->
    <section class="pp-panel" id="pp-panel-gallery">
        <form id="pp-form-gallery-add" enctype="multipart/form-data" novalidate>
            <input type="hidden" name="action" value="profile_gallery_add">
            <div class="pp-grid">
                <div class="full"><label>Add images (jpg/png/webp, ≤ 5 MB each)</label>
                    <input type="file" class="form-control" name="gallery_images[]" accept="image/png,image/jpeg,image/webp" multiple>
                </div>
            </div>
            <div class="pp-actions"><button type="submit" class="primary">Upload selected</button></div>
        </form>

        <div class="pp-gallery-grid">
            <?php foreach ($ppGallery as $img): ?>
                <div class="pp-gallery-cell" data-image-id="<?php echo (int) $img['id']; ?>">
                    <img src="/<?php echo $pE(ltrim((string) $img['image_url'], '/')); ?>" alt="">
                    <?php if (!empty($img['caption'])): ?><div class="cap"><?php echo $pE($img['caption']); ?></div><?php endif; ?>
                    <button type="button" class="del" onclick="ppDeleteGallery(<?php echo (int) $img['id']; ?>, this)" title="Delete">×</button>
                </div>
            <?php endforeach; ?>
            <?php if (!$ppGallery): ?><p style="color:#6b7280;font-size:13px">No images yet.</p><?php endif; ?>
        </div>
    </section>

    <!-- ================= REVIEWS ================= -->
    <section class="pp-panel" id="pp-panel-reviews">
        <?php if (!$ppReviews): ?>
            <p style="color:#6b7280;font-size:14px">No reviews yet. Parents can leave reviews from the public profile page.</p>
        <?php else: ?>
            <table class="table">
                <thead><tr><th>Parent</th><th>Rating</th><th>Comment</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($ppReviews as $r): ?>
                    <tr>
                        <td><strong><?php echo $pE($r['parent_name'] ?? '—'); ?></strong>
                            <?php if (!empty($r['student_name'])): ?><div style="color:#6b7280;font-size:12px">parent of <?php echo $pE($r['student_name']); ?></div><?php endif; ?>
                        </td>
                        <td><?php echo str_repeat('★', (int) ($r['rating'] ?? 0)); ?></td>
                        <td><?php echo $pE($r['comment'] ?? ''); ?></td>
                        <td><span class="pp-status-badge <?php echo !empty($r['is_approved']) ? 'ok' : 'pending'; ?>"><?php echo !empty($r['is_approved']) ? 'Approved' : 'Pending'; ?></span></td>
                        <td><button class="secondary" onclick="ppToggleReview(<?php echo (int) $r['id']; ?>, <?php echo !empty($r['is_approved']) ? '0' : '1'; ?>, this)"><?php echo !empty($r['is_approved']) ? 'Hide' : 'Approve'; ?></button></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <!-- ================= COPY & THEME ================= -->
    <section class="pp-panel" id="pp-panel-copy">
        <form id="pp-form-copy" novalidate>
            <input type="hidden" name="action" value="profile_save_copy">

            <h6 style="font-size:13px;font-weight:700;color:#374151;margin-bottom:10px">Section headings</h6>
            <div class="pp-grid">
                <?php foreach ([
                    'trusted_heading' => 'Trusted heading',
                    'programs_heading' => 'Learning areas heading',
                    'facilities_heading' => 'Facilities heading',
                    'faq_heading' => 'FAQ heading',
                    'updates_heading' => 'News & events heading',
                    'gallery_heading' => 'Gallery heading',
                    'reviews_heading' => 'Reviews heading',
                    'contact_heading' => 'Contact heading',
                    'slider_heading' => 'Slider heading',
                ] as $key => $label): ?>
                    <div><label><?php echo $pE($label); ?></label><input type="text" class="form-control" name="settings[<?php echo $pE($key); ?>]" value="<?php echo $pE($ppSetting($key)); ?>"></div>
                <?php endforeach; ?>
            </div>

            <h6 style="font-size:13px;font-weight:700;color:#374151;margin:24px 0 10px">Section badges</h6>
            <div class="pp-grid">
                <?php foreach ([
                    'about_badge' => 'About badge',
                    'programs_badge' => 'Subjects badge',
                    'facilities_badge' => 'Facilities badge',
                    'faq_badge' => 'FAQ badge',
                    'updates_badge' => 'Updates badge',
                    'gallery_badge' => 'Gallery badge',
                    'reviews_badge' => 'Reviews badge',
                    'admission_badge' => 'Admission badge',
                    'contact_badge' => 'Contact badge',
                ] as $key => $label): ?>
                    <div><label><?php echo $pE($label); ?></label><input type="text" class="form-control" name="settings[<?php echo $pE($key); ?>]" value="<?php echo $pE($ppSetting($key)); ?>"></div>
                <?php endforeach; ?>
            </div>

            <h6 style="font-size:13px;font-weight:700;color:#374151;margin:24px 0 10px">Forms, footer, and public messages</h6>
            <div class="pp-grid">
                <div><label>Review form title</label><input type="text" class="form-control" name="settings[review_form_heading]" value="<?php echo $pE($ppSetting('review_form_heading')); ?>"></div>
                <div><label>Footer credit</label><input type="text" class="form-control" name="settings[footer_credit]" value="<?php echo $pE($ppSetting('footer_credit')); ?>"></div>
                <div class="full"><label>Review form intro</label><textarea class="form-control" rows="2" name="settings[review_form_intro]"><?php echo $pE($ppSetting('review_form_intro')); ?></textarea></div>
                <div class="full"><label>Review rating options (JSON)</label><textarea class="form-control" rows="2" name="settings[review_rating_options]"><?php echo $pE($ppSetting('review_rating_options')); ?></textarea></div>
                <div class="full"><label>Admission gender options (JSON)</label><textarea class="form-control" rows="2" name="settings[admission_gender_options]"><?php echo $pE($ppSetting('admission_gender_options')); ?></textarea></div>
                <div class="full"><label>Admission enrollment options (JSON)</label><textarea class="form-control" rows="2" name="settings[admission_enrollment_type_options]"><?php echo $pE($ppSetting('admission_enrollment_type_options')); ?></textarea></div>
            </div>

            <h6 style="font-size:13px;font-weight:700;color:#374151;margin:24px 0 10px">Page colours</h6>
            <div class="pp-grid">
                <?php foreach ([
                    'theme_background_color' => 'Page background',
                    'theme_hero_background_color' => 'Hero background',
                    'theme_footer_background_color' => 'Footer background',
                    'theme_card_background_color' => 'Card background',
                ] as $key => $label): ?>
                    <div>
                        <label><?php echo $pE($label); ?></label>
                        <div class="pp-color-pair">
                            <input type="color" value="<?php echo $pE($ppSetting($key)); ?>" oninput="this.nextElementSibling.value=this.value">
                            <input type="text" class="form-control" name="settings[<?php echo $pE($key); ?>]" value="<?php echo $pE($ppSetting($key)); ?>" pattern="^#[0-9A-Fa-f]{6}$">
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="pp-actions"><button type="submit" class="primary">Save copy &amp; theme</button></div>
        </form>
    </section>

    <!-- ================= FAQS ================= -->
    <section class="pp-panel" id="pp-panel-faqs">
        <form id="pp-form-faqs" novalidate>
            <input type="hidden" name="action" value="profile_save_faqs">
            <div id="pp-faqs-list">
                <?php $ppFaqs = $ppFaqs ?: [['question' => '', 'answer' => '', 'is_active' => 1]];
                foreach ($ppFaqs as $i => $faq): ?>
                    <div class="pp-row" data-row>
                        <div class="pp-grid">
                            <div><label>Question</label><input type="text" class="form-control" name="faqs[<?php echo $i; ?>][question]" value="<?php echo $pE($faq['question'] ?? ''); ?>"></div>
                            <div class="full"><label>Answer</label><textarea class="form-control" rows="2" name="faqs[<?php echo $i; ?>][answer]"><?php echo $pE($faq['answer'] ?? ''); ?></textarea></div>
                        </div>
                        <div style="margin-top:8px;display:flex;gap:12px;align-items:center">
                            <label style="font-weight:400;font-size:12px;margin:0"><input type="checkbox" name="faqs[<?php echo $i; ?>][is_inactive]" value="1" <?php echo isset($faq['is_active']) && (int) $faq['is_active'] === 0 ? 'checked' : ''; ?>> Hide from public page</label>
                            <button type="button" class="danger" onclick="this.closest('[data-row]').remove()">Remove</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="secondary" onclick="ppAddRow('pp-faqs-list','faqs',['question','answer','is_inactive'])">+ Add FAQ</button>
            <div class="pp-actions"><button type="submit" class="primary">Save FAQs</button></div>
        </form>
    </section>

    <!-- ================= NEWS / BLOGS ================= -->
    <section class="pp-panel" id="pp-panel-blogs">
        <form id="pp-form-blogs" novalidate>
            <input type="hidden" name="action" value="profile_save_blogs">
            <div id="pp-blogs-list">
                <?php $ppBlogs = $ppBlogs ?: [['title' => '', 'excerpt' => '', 'content' => '', 'image_url' => '', 'category' => 'Education', 'author_name' => '', 'published_at' => '', 'is_published' => 1]];
                foreach ($ppBlogs as $i => $blog): ?>
                    <div class="pp-row" data-row>
                        <div class="pp-grid">
                            <div><label>Title</label><input type="text" class="form-control" name="blogs[<?php echo $i; ?>][title]" value="<?php echo $pE($blog['title'] ?? ''); ?>"></div>
                            <div><label>Category</label><input type="text" class="form-control" name="blogs[<?php echo $i; ?>][category]" value="<?php echo $pE($blog['category'] ?? 'Education'); ?>"></div>
                            <div><label>Author</label><input type="text" class="form-control" name="blogs[<?php echo $i; ?>][author_name]" value="<?php echo $pE($blog['author_name'] ?? ''); ?>"></div>
                            <div><label>Published date</label><input type="datetime-local" class="form-control" name="blogs[<?php echo $i; ?>][published_at]" value="<?php echo !empty($blog['published_at']) ? $pE(date('Y-m-d\TH:i', strtotime((string) $blog['published_at']))) : ''; ?>"></div>
                            <div class="full"><label>Image URL or upload path</label><input type="text" class="form-control" name="blogs[<?php echo $i; ?>][image_url]" value="<?php echo $pE($blog['image_url'] ?? ''); ?>"></div>
                            <div class="full"><label>Excerpt</label><textarea class="form-control" rows="2" name="blogs[<?php echo $i; ?>][excerpt]"><?php echo $pE($blog['excerpt'] ?? ''); ?></textarea></div>
                            <div class="full"><label>Content</label><textarea class="form-control" rows="3" name="blogs[<?php echo $i; ?>][content]"><?php echo $pE($blog['content'] ?? ''); ?></textarea></div>
                        </div>
                        <div style="margin-top:8px;display:flex;gap:12px;align-items:center">
                            <label style="font-weight:400;font-size:12px;margin:0"><input type="checkbox" name="blogs[<?php echo $i; ?>][is_unpublished]" value="1" <?php echo isset($blog['is_published']) && (int) $blog['is_published'] === 0 ? 'checked' : ''; ?>> Hide from public page</label>
                            <button type="button" class="danger" onclick="this.closest('[data-row]').remove()">Remove</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="secondary" onclick="ppAddRow('pp-blogs-list','blogs',['title','category','author_name','published_at','image_url','excerpt','content','is_unpublished'])">+ Add news item</button>
            <div class="pp-actions"><button type="submit" class="primary">Save news</button></div>
        </form>
    </section>

    <!-- ================= SLIDER ================= -->
    <section class="pp-panel" id="pp-panel-slides">
        <form id="pp-form-slide-add" enctype="multipart/form-data" novalidate>
            <input type="hidden" name="action" value="profile_slide_add">
            <div class="pp-grid">
                <div class="full"><label>Slider images (jpg/png/webp, ≤ 5 MB each)</label><input type="file" class="form-control" name="slide_images[]" accept="image/png,image/jpeg,image/webp" multiple></div>
                <div><label>Title for first image</label><input type="text" class="form-control" name="slide_titles[]" placeholder="Campus life"></div>
                <div><label>Subtitle for first image</label><input type="text" class="form-control" name="slide_subtitles[]" placeholder="Learning moments and school events"></div>
                <div><label>Button label</label><input type="text" class="form-control" name="slide_button_labels[]" placeholder="Learn more"></div>
                <div><label>Button URL</label><input type="text" class="form-control" name="slide_button_urls[]" placeholder="#gallery"></div>
            </div>
            <div class="pp-actions"><button type="submit" class="primary">Upload slider image</button></div>
        </form>

        <div class="pp-gallery-grid">
            <?php foreach ($ppSlides as $slide): ?>
                <div class="pp-gallery-cell" data-slide-id="<?php echo (int) $slide['id']; ?>">
                    <img src="/<?php echo $pE(ltrim((string) $slide['image_url'], '/')); ?>" alt="">
                    <div class="cap"><strong><?php echo $pE($slide['title'] ?? 'Slide'); ?></strong><br><?php echo $pE($slide['subtitle'] ?? ''); ?></div>
                    <button type="button" class="del" onclick="ppDeleteSlide(<?php echo (int) $slide['id']; ?>, this)" title="Delete">×</button>
                </div>
            <?php endforeach; ?>
            <?php if (!$ppSlides): ?><p style="color:#6b7280;font-size:13px">No slider images yet. The public page will use the hero/showcase images until you add slides here.</p><?php endif; ?>
        </div>
    </section>
</div>

<script>
(function () {
    var PP_CSRF = window.academixGeneralCsrfToken || <?php echo json_encode($csrfToken ?? ''); ?>;

    function currentCsrfToken() {
        return window.academixGeneralCsrfToken || PP_CSRF;
    }

    function syncPpCsrfToken(response) {
        if (!response || !response.csrf_token) return;
        PP_CSRF = response.csrf_token;
        window.academixGeneralCsrfToken = PP_CSRF;
        document.querySelectorAll('input[name="csrf_token"]').forEach(function (input) {
            input.value = PP_CSRF;
        });
    }

    // -- Tab switching ------------------------------------------------------
    document.querySelectorAll('.pp-tabs button[data-pp-tab]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('.pp-tabs button').forEach(b => b.classList.remove('is-active'));
            document.querySelectorAll('.pp-panel').forEach(p => p.classList.remove('is-active'));
            btn.classList.add('is-active');
            var p = document.getElementById('pp-panel-' + btn.dataset.ppTab);
            if (p) p.classList.add('is-active');
        });
    });

    // -- Generic repeater ---------------------------------------------------
    window.ppAddRow = function (containerId, prefix, fields) {
        var list = document.getElementById(containerId);
        var idx = list.querySelectorAll('[data-row]').length;
        var block = document.createElement('div');
        block.className = 'pp-row';
        block.setAttribute('data-row', '');
        var h = '<div class="pp-grid">';
        fields.forEach(function (f) {
            var isChk = (f === 'is_primary' || f === 'is_inactive' || f === 'is_unpublished');
            if (isChk) {
                h += '<div style="display:flex;align-items:flex-end;gap:6px"><label style="margin-bottom:8px;font-weight:400;font-size:12px"><input type="checkbox" name="' + prefix + '[' + idx + '][' + f + ']" value="1"> ' + f.replace('_', ' ') + '</label></div>';
            } else if (f === 'description' || f === 'quote' || f === 'answer' || f === 'excerpt' || f === 'content' || f === 'subtitle') {
                h += '<div class="full"><label>' + f + '</label><textarea class="form-control" rows="2" name="' + prefix + '[' + idx + '][' + f + ']"></textarea></div>';
            } else if (f === 'type') {
                h += '<div><label>type</label><select class="form-control" name="' + prefix + '[' + idx + '][type]">' +
                     '<option value="phone">phone</option><option value="email">email</option>' +
                     '<option value="address">address</option><option value="website">website</option>' +
                     '<option value="whatsapp">whatsapp</option><option value="social">social</option></select></div>';
            } else {
                h += '<div><label>' + f + '</label><input type="text" class="form-control" name="' + prefix + '[' + idx + '][' + f + ']"></div>';
            }
        });
        h += '</div><div style="margin-top:8px"><button type="button" class="danger" onclick="this.closest(\'[data-row]\').remove()">Remove</button></div>';
        block.innerHTML = h;
        list.appendChild(block);
    };

    // -- Submit helper ------------------------------------------------------
    function postForm(form) {
        var fd = new FormData(form);
        fd.set('csrf_token', currentCsrfToken());
        return fetch(window.location.pathname + window.location.search, {
            method: 'POST',
            body: fd,
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
        }).then(r => r.json()).then(function (r) {
            syncPpCsrfToken(r);
            return r;
        });
    }
    function flash(r) {
        if (window.Toast) {
            (r.success ? Toast.success : Toast.error)(r.message || (r.success ? 'Saved.' : 'Could not save.'));
        } else if (typeof window.showToast === 'function') {
            window.showToast(r.message || (r.success ? 'Saved.' : 'Could not save.'), r.success ? 'success' : 'error');
        } else {
            var box = document.createElement('div');
            box.style.cssText = 'position:fixed;right:24px;bottom:24px;z-index:10050;max-width:360px;padding:12px 16px;border-radius:10px;font:600 13px/1.4 system-ui,-apple-system,Segoe UI,sans-serif;box-shadow:0 12px 30px rgba(15,23,42,.18);background:' + (r.success ? '#dcfce7' : '#fee2e2') + ';color:' + (r.success ? '#166534' : '#991b1b');
            box.textContent = r.message || (r.success ? 'Saved.' : 'Could not save.');
            document.body.appendChild(box);
            setTimeout(function () { box.remove(); }, 4200);
        }
    }

    ['pp-form-basics','pp-form-programs','pp-form-contacts','pp-form-facilities','pp-form-gallery-add','pp-form-copy','pp-form-faqs','pp-form-blogs','pp-form-slide-add'].forEach(function (id) {
        var el = document.getElementById(id);
        if (!el) return;
        el.addEventListener('submit', function (e) {
            e.preventDefault();
            var btn = el.querySelector('[type="submit"]');
            var oldText = btn ? btn.textContent : '';
            if (btn) {
                btn.disabled = true;
                btn.textContent = 'Saving...';
            }
            postForm(el).then(function (r) {
                flash(r);
                if (r.success && (id === 'pp-form-gallery-add' || id === 'pp-form-slide-add')) {
                    // simplest UX: reload the tab so the new images appear
                    setTimeout(function () { location.reload(); }, 600);
                }
            }).catch(function () {
                flash({success:false, message:'Network error. Please try again.'});
            }).finally(function () {
                if (btn) {
                    btn.disabled = false;
                    btn.textContent = oldText;
                }
            });
        });
    });

    // -- Gallery delete -----------------------------------------------------
    window.ppDeleteGallery = function (id, btn) {
        if (window.Modal) {
            Modal.confirm({ message: 'Remove this image from your gallery?', type: 'warning' }).then(function (ok) {
                if (ok) doDeleteGallery(id, btn);
            });
        } else if (confirm('Remove this image?')) {
            doDeleteGallery(id, btn);
        }
    };
    function doDeleteGallery(id, btn) {
        var fd = new FormData();
        fd.append('action', 'profile_gallery_delete');
        fd.append('image_id', id);
        fd.append('csrf_token', currentCsrfToken());
        fetch(window.location.pathname, {
            method:'POST',
            body: fd,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(r => r.json()).then(function (r) {
                syncPpCsrfToken(r);
                flash(r);
                if (r.success) {
                    var cell = btn.closest('.pp-gallery-cell');
                    if (cell) cell.remove();
                }
            });
    }

    // -- Slider delete ------------------------------------------------------
    window.ppDeleteSlide = function (id, btn) {
        if (window.Modal) {
            Modal.confirm({ message: 'Remove this slider image?', type: 'warning' }).then(function (ok) {
                if (ok) doDeleteSlide(id, btn);
            });
        } else if (confirm('Remove this slider image?')) {
            doDeleteSlide(id, btn);
        }
    };
    function doDeleteSlide(id, btn) {
        var fd = new FormData();
        fd.append('action', 'profile_slide_delete');
        fd.append('slide_id', id);
        fd.append('csrf_token', currentCsrfToken());
        fetch(window.location.pathname, {
            method:'POST',
            body: fd,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(r => r.json()).then(function (r) {
                syncPpCsrfToken(r);
                flash(r);
                if (r.success) {
                    var cell = btn.closest('.pp-gallery-cell');
                    if (cell) cell.remove();
                }
            });
    }

    // -- Review toggle ------------------------------------------------------
    window.ppToggleReview = function (id, approve, btn) {
        var fd = new FormData();
        fd.append('action', 'profile_review_toggle');
        fd.append('review_id', id);
        fd.append('approve', approve);
        fd.append('csrf_token', currentCsrfToken());
        fetch(window.location.pathname, {
            method:'POST',
            body: fd,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(r => r.json()).then(function (r) {
                syncPpCsrfToken(r);
                flash(r);
                if (r.success) location.reload();
            });
    };
})();
</script>
