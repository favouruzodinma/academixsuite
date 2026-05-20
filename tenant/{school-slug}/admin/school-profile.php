<?php
/**
 * School public profile editor.
 *
 * Admins manage the content used by tenant/school_profile.php:
 * landing copy, logo, contacts, facilities, gallery, and moderated reviews.
 */

require_once __DIR__ . '/includes/admin-bootstrap.php';

$currentPage = 'school-profile.php';
$GLOBALS['CURRENT_PAGE'] = $currentPage;

if (!function_exists('school_profile_admin_columns_fresh')) {
    function school_profile_admin_columns_fresh(PDO $db, string $table): array {
        try {
            $safeTable = str_replace('`', '', $table);
            $rows = $db->query("SHOW COLUMNS FROM `{$safeTable}`")->fetchAll(PDO::FETCH_ASSOC);
            return array_column($rows, 'Field');
        } catch (Throwable $e) {
            error_log('School profile editor column lookup failed: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('school_profile_admin_ensure_schema')) {
    function school_profile_admin_ensure_schema(PDO $db): void {
        $schoolsColumns = school_profile_admin_columns_fresh($db, 'schools');
        $columnSql = [
            'landing_badge_text' => 'VARCHAR(120) NULL',
            'landing_headline' => 'VARCHAR(255) NULL',
            'landing_subheadline' => 'TEXT NULL',
            'landing_primary_cta_text' => 'VARCHAR(60) NULL',
            'landing_secondary_cta_text' => 'VARCHAR(60) NULL',
            'landing_intro_title' => 'VARCHAR(255) NULL',
            'landing_intro_text' => 'TEXT NULL',
            'landing_highlight_title' => 'VARCHAR(255) NULL',
            'landing_highlight_text' => 'TEXT NULL',
            'landing_cta_title' => 'VARCHAR(255) NULL',
            'landing_cta_text' => 'TEXT NULL',
            'landing_hero_image' => 'VARCHAR(500) NULL',
            'landing_feature_image' => 'VARCHAR(500) NULL',
            'landing_programs' => 'LONGTEXT NULL',
            'landing_testimonials' => 'LONGTEXT NULL',
            'primary_color' => "VARCHAR(7) NULL DEFAULT '#3B82F6'",
            'secondary_color' => "VARCHAR(7) NULL DEFAULT '#10B981'",
        ];

        foreach ($columnSql as $column => $definition) {
            if (!in_array($column, $schoolsColumns, true)) {
                $db->exec("ALTER TABLE `schools` ADD COLUMN `{$column}` {$definition}");
            }
        }

        if (!academix_admin_table_exists($db, 'school_contacts')) {
            $db->exec("
                CREATE TABLE `school_contacts` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    `school_id` INT UNSIGNED NOT NULL,
                    `type` ENUM('phone','email','address','website','social') NOT NULL DEFAULT 'phone',
                    `label` VARCHAR(100) NULL,
                    `value` VARCHAR(255) NOT NULL,
                    `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
                    `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    KEY `idx_school_contacts_school` (`school_id`, `sort_order`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }

        if (!academix_admin_table_exists($db, 'school_facilities')) {
            $db->exec("
                CREATE TABLE `school_facilities` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    `school_id` INT UNSIGNED NOT NULL,
                    `name` VARCHAR(100) NOT NULL,
                    `description` TEXT NULL,
                    `icon` VARCHAR(50) NULL,
                    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                    `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    KEY `idx_school_facilities_school` (`school_id`, `sort_order`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }

        if (!academix_admin_table_exists($db, 'school_gallery')) {
            $db->exec("
                CREATE TABLE `school_gallery` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    `school_id` INT UNSIGNED NOT NULL,
                    `image_url` VARCHAR(500) NOT NULL,
                    `caption` VARCHAR(255) NULL,
                    `type` ENUM('campus','classroom','laboratory','library','sports','events','other') NOT NULL DEFAULT 'campus',
                    `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    KEY `idx_school_gallery_school` (`school_id`, `sort_order`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }

        if (!academix_admin_table_exists($db, 'school_reviews')) {
            $db->exec("
                CREATE TABLE `school_reviews` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    `school_id` INT UNSIGNED NOT NULL,
                    `parent_name` VARCHAR(255) NOT NULL,
                    `parent_email` VARCHAR(255) NULL,
                    `student_name` VARCHAR(255) NULL,
                    `rating` DECIMAL(2,1) NOT NULL DEFAULT 5,
                    `title` VARCHAR(255) NULL,
                    `comment` TEXT NOT NULL,
                    `pros` TEXT NULL,
                    `cons` TEXT NULL,
                    `is_verified` TINYINT(1) NOT NULL DEFAULT 0,
                    `is_approved` TINYINT(1) NOT NULL DEFAULT 0,
                    `helpful_count` INT UNSIGNED NOT NULL DEFAULT 0,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    KEY `idx_school_reviews_school` (`school_id`, `is_approved`, `created_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } elseif (in_array('parent_email', school_profile_admin_columns_fresh($db, 'school_reviews'), true)) {
            try {
                $db->exec('ALTER TABLE `school_reviews` MODIFY `parent_email` VARCHAR(255) NULL');
            } catch (Throwable $e) {
                error_log('Could not relax school_reviews.parent_email: ' . $e->getMessage());
            }
        }
    }
}

if (!function_exists('school_profile_admin_lines_to_json')) {
    function school_profile_admin_lines_to_json(string $value, string $mode): ?string {
        $items = [];
        foreach (preg_split('/\r\n|\r|\n/', trim($value)) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = array_map('trim', explode('|', $line));
            if ($mode === 'programs') {
                $items[] = [
                    'title' => $parts[0] ?? '',
                    'description' => $parts[1] ?? '',
                ];
            } else {
                $items[] = [
                    'name' => $parts[0] ?? '',
                    'role' => $parts[1] ?? '',
                    'quote' => $parts[2] ?? '',
                ];
            }
        }

        return $items ? json_encode($items, JSON_UNESCAPED_SLASHES) : null;
    }
}

if (!function_exists('school_profile_admin_json_to_lines')) {
    function school_profile_admin_json_to_lines($value, string $mode): string {
        $decoded = json_decode((string) $value, true);
        if (!is_array($decoded)) {
            return '';
        }

        $lines = [];
        foreach ($decoded as $item) {
            if (!is_array($item)) {
                continue;
            }
            if ($mode === 'programs') {
                $lines[] = trim(($item['title'] ?? '') . ' | ' . ($item['description'] ?? ''));
            } else {
                $lines[] = trim(($item['name'] ?? '') . ' | ' . ($item['role'] ?? '') . ' | ' . ($item['quote'] ?? ''));
            }
        }
        return implode("\n", $lines);
    }
}

if (!function_exists('school_profile_admin_upload_image')) {
    function school_profile_admin_upload_image(string $field, int $schoolId, string $schoolSlug, string $prefix): ?string {
        if (empty($_FILES[$field]['name']) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        if (($_FILES[$field]['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload failed for ' . $field . '.');
        }

        $tmp = (string) $_FILES[$field]['tmp_name'];
        $size = (int) ($_FILES[$field]['size'] ?? 0);
        if ($size <= 0 || $size > 5 * 1024 * 1024) {
            throw new RuntimeException('Images must be less than 5MB.');
        }

        $mime = function_exists('mime_content_type') ? (string) mime_content_type($tmp) : '';
        $extensions = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
        ];
        if (!isset($extensions[$mime])) {
            throw new RuntimeException('Only JPG, PNG, WebP, and GIF images are allowed.');
        }

        $dir = ROOT_PATH . '/assets/uploads/schools/' . $schoolId;
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            throw new RuntimeException('Could not create upload directory.');
        }

        $fileName = $prefix . '-' . preg_replace('/[^a-z0-9-]/', '-', strtolower($schoolSlug)) . '-' . time() . '.' . $extensions[$mime];
        $target = $dir . '/' . $fileName;
        if (!move_uploaded_file($tmp, $target)) {
            throw new RuntimeException('Could not save uploaded image.');
        }

        return 'assets/uploads/schools/' . $schoolId . '/' . $fileName;
    }
}

if (!function_exists('school_profile_admin_insert_row')) {
    function school_profile_admin_insert_row(PDO $db, string $table, array $data): void {
        $columns = school_profile_admin_columns_fresh($db, $table);
        $insert = array_intersect_key($data, array_flip($columns));
        if (!$insert) {
            throw new RuntimeException('No valid columns found for ' . $table . '.');
        }

        $fields = array_keys($insert);
        $placeholders = array_fill(0, count($fields), '?');
        $sql = 'INSERT INTO `' . str_replace('`', '', $table) . '` (`' . implode('`,`', $fields) . '`) VALUES (' . implode(',', $placeholders) . ')';
        $db->prepare($sql)->execute(array_values($insert));
    }
}

if (!function_exists('school_profile_admin_delete_row')) {
    function school_profile_admin_delete_row(PDO $db, string $table, int $id, int $schoolId): void {
        $safeTable = str_replace('`', '', $table);
        $stmt = $db->prepare("DELETE FROM `{$safeTable}` WHERE id = ? AND school_id = ?");
        $stmt->execute([$id, $schoolId]);
    }
}

try {
    school_profile_admin_ensure_schema($platformDb);
} catch (Throwable $e) {
    error_log('School profile editor schema check failed: ' . $e->getMessage());
    $_SESSION['school_profile_flash'] = [
        'type' => 'error',
        'message' => 'Profile editor schema needs attention: ' . $e->getMessage(),
    ];
}

$schoolId = (int) $school['id'];
$flash = $_SESSION['school_profile_flash'] ?? null;
unset($_SESSION['school_profile_flash']);

$setFlash = static function (string $type, string $message): void {
    $_SESSION['school_profile_flash'] = ['type' => $type, 'message' => $message];
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!academix_admin_validate_csrf($_POST['csrf_token'] ?? '')) {
            throw new RuntimeException('Your security token expired. Please try again.');
        }

        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'save_profile') {
            $columns = school_profile_admin_columns_fresh($platformDb, 'schools');
            $updates = [];
            $values = [];
            $candidateFields = [
                'name', 'description', 'mission_statement', 'vision_statement', 'principal_name', 'principal_message',
                'school_type', 'curriculum', 'email', 'phone', 'address', 'city', 'state', 'country',
                'teacher_student_ratio', 'school_hours', 'admission_process',
                'landing_badge_text', 'landing_headline', 'landing_subheadline', 'landing_primary_cta_text',
                'landing_secondary_cta_text', 'landing_intro_title', 'landing_intro_text', 'landing_highlight_title',
                'landing_highlight_text', 'landing_cta_title', 'landing_cta_text'
            ];

            foreach ($candidateFields as $field) {
                if (in_array($field, $columns, true)) {
                    $updates[] = "`{$field}` = ?";
                    $values[] = trim((string) ($_POST[$field] ?? ''));
                }
            }

            foreach (['student_count', 'teacher_count', 'class_count', 'average_class_size'] as $field) {
                if (in_array($field, $columns, true)) {
                    $updates[] = "`{$field}` = ?";
                    $values[] = max(0, (int) ($_POST[$field] ?? 0));
                }
            }

            foreach (['fee_range_from', 'fee_range_to'] as $field) {
                if (in_array($field, $columns, true)) {
                    $updates[] = "`{$field}` = ?";
                    $values[] = max(0, (float) ($_POST[$field] ?? 0));
                }
            }

            if (in_array('establishment_year', $columns, true)) {
                $updates[] = '`establishment_year` = ?';
                $year = (int) ($_POST['establishment_year'] ?? 0);
                $values[] = $year > 1800 ? $year : null;
            }

            if (in_array('admission_deadline', $columns, true)) {
                $updates[] = '`admission_deadline` = ?';
                $values[] = trim((string) ($_POST['admission_deadline'] ?? '')) ?: null;
            }

            if (in_array('admission_status', $columns, true)) {
                $status = (string) ($_POST['admission_status'] ?? 'open');
                $updates[] = '`admission_status` = ?';
                $values[] = in_array($status, ['open', 'closed', 'waiting_list'], true) ? $status : 'open';
            }

            foreach (['primary_color', 'secondary_color'] as $field) {
                if (in_array($field, $columns, true)) {
                    $color = trim((string) ($_POST[$field] ?? ''));
                    $updates[] = "`{$field}` = ?";
                    $values[] = preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : null;
                }
            }

            if (in_array('landing_programs', $columns, true)) {
                $updates[] = '`landing_programs` = ?';
                $values[] = school_profile_admin_lines_to_json((string) ($_POST['landing_programs_text'] ?? ''), 'programs');
            }

            if (in_array('landing_testimonials', $columns, true)) {
                $updates[] = '`landing_testimonials` = ?';
                $values[] = school_profile_admin_lines_to_json((string) ($_POST['landing_testimonials_text'] ?? ''), 'testimonials');
            }

            foreach ([
                'logo_file' => ['logo_path', 'logo'],
                'hero_file' => ['landing_hero_image', 'hero'],
                'feature_file' => ['landing_feature_image', 'feature'],
            ] as $fileField => $target) {
                if (in_array($target[0], $columns, true)) {
                    $uploaded = school_profile_admin_upload_image($fileField, $schoolId, $schoolSlug, $target[1]);
                    if ($uploaded) {
                        $updates[] = "`{$target[0]}` = ?";
                        $values[] = $uploaded;
                    }
                }
            }

            if ($updates) {
                $values[] = $schoolId;
                $platformDb->prepare('UPDATE schools SET ' . implode(', ', $updates) . ' WHERE id = ?')->execute($values);
            }

            $setFlash('success', 'School profile content updated.');
        } elseif ($action === 'add_contact') {
            school_profile_admin_insert_row($platformDb, 'school_contacts', [
                'school_id' => $schoolId,
                'type' => in_array($_POST['type'] ?? '', ['phone', 'email', 'address', 'website', 'social'], true) ? $_POST['type'] : 'phone',
                'label' => trim((string) ($_POST['label'] ?? '')),
                'value' => trim((string) ($_POST['value'] ?? '')),
                'is_primary' => !empty($_POST['is_primary']) ? 1 : 0,
                'sort_order' => (int) ($_POST['sort_order'] ?? 0),
            ]);
            $setFlash('success', 'Contact added.');
        } elseif ($action === 'delete_contact') {
            school_profile_admin_delete_row($platformDb, 'school_contacts', (int) ($_POST['id'] ?? 0), $schoolId);
            $setFlash('success', 'Contact removed.');
        } elseif ($action === 'add_facility') {
            school_profile_admin_insert_row($platformDb, 'school_facilities', [
                'school_id' => $schoolId,
                'name' => trim((string) ($_POST['name'] ?? '')),
                'description' => trim((string) ($_POST['description'] ?? '')),
                'icon' => trim((string) ($_POST['icon'] ?? 'ri-building-4-line')),
                'is_active' => !empty($_POST['is_active']) ? 1 : 0,
                'sort_order' => (int) ($_POST['sort_order'] ?? 0),
            ]);
            $setFlash('success', 'Facility saved.');
        } elseif ($action === 'delete_facility') {
            school_profile_admin_delete_row($platformDb, 'school_facilities', (int) ($_POST['id'] ?? 0), $schoolId);
            $setFlash('success', 'Facility removed.');
        } elseif ($action === 'add_gallery') {
            $imagePath = school_profile_admin_upload_image('gallery_file', $schoolId, $schoolSlug, 'gallery');
            if (!$imagePath) {
                throw new RuntimeException('Please choose a gallery image.');
            }
            school_profile_admin_insert_row($platformDb, 'school_gallery', [
                'school_id' => $schoolId,
                'image_url' => $imagePath,
                'caption' => trim((string) ($_POST['caption'] ?? '')),
                'type' => in_array($_POST['type'] ?? '', ['campus', 'classroom', 'laboratory', 'library', 'sports', 'events', 'other'], true) ? $_POST['type'] : 'campus',
                'sort_order' => (int) ($_POST['sort_order'] ?? 0),
            ]);
            $setFlash('success', 'Gallery image added.');
        } elseif ($action === 'delete_gallery') {
            school_profile_admin_delete_row($platformDb, 'school_gallery', (int) ($_POST['id'] ?? 0), $schoolId);
            $setFlash('success', 'Gallery image removed.');
        } elseif (in_array($action, ['approve_review', 'unapprove_review', 'delete_review'], true)) {
            $reviewId = (int) ($_POST['id'] ?? 0);
            if ($action === 'delete_review') {
                school_profile_admin_delete_row($platformDb, 'school_reviews', $reviewId, $schoolId);
                $setFlash('success', 'Review deleted.');
            } else {
                $stmt = $platformDb->prepare('UPDATE school_reviews SET is_approved = ? WHERE id = ? AND school_id = ?');
                $stmt->execute([$action === 'approve_review' ? 1 : 0, $reviewId, $schoolId]);
                $setFlash('success', $action === 'approve_review' ? 'Review approved.' : 'Review hidden.');
            }
        }
    } catch (Throwable $e) {
        error_log('School profile editor action failed: ' . $e->getMessage());
        $setFlash('error', $e->getMessage());
    }

    header('Location: school-profile.php');
    exit;
}

$stmt = $platformDb->prepare('SELECT * FROM schools WHERE id = ? LIMIT 1');
$stmt->execute([$schoolId]);
$school = $stmt->fetch(PDO::FETCH_ASSOC) ?: $school;
$_SESSION['school_info'][$schoolSlug] = $school;
$GLOBALS['SCHOOL_DATA'] = $school;
$schoolLogoUrl = school_logo_url($school);

$contacts = academix_admin_table_exists($platformDb, 'school_contacts')
    ? $platformDb->prepare('SELECT * FROM school_contacts WHERE school_id = ? ORDER BY is_primary DESC, sort_order ASC, id DESC')
    : null;
if ($contacts) {
    $contacts->execute([$schoolId]);
    $contacts = $contacts->fetchAll(PDO::FETCH_ASSOC);
} else {
    $contacts = [];
}

$facilities = academix_admin_table_exists($platformDb, 'school_facilities')
    ? $platformDb->prepare('SELECT * FROM school_facilities WHERE school_id = ? ORDER BY sort_order ASC, id DESC')
    : null;
if ($facilities) {
    $facilities->execute([$schoolId]);
    $facilities = $facilities->fetchAll(PDO::FETCH_ASSOC);
} else {
    $facilities = [];
}

$gallery = academix_admin_table_exists($platformDb, 'school_gallery')
    ? $platformDb->prepare('SELECT * FROM school_gallery WHERE school_id = ? ORDER BY sort_order ASC, created_at DESC, id DESC')
    : null;
if ($gallery) {
    $gallery->execute([$schoolId]);
    $gallery = $gallery->fetchAll(PDO::FETCH_ASSOC);
} else {
    $gallery = [];
}

$reviews = academix_admin_table_exists($platformDb, 'school_reviews')
    ? $platformDb->prepare('SELECT * FROM school_reviews WHERE school_id = ? ORDER BY is_approved ASC, created_at DESC, id DESC')
    : null;
if ($reviews) {
    $reviews->execute([$schoolId]);
    $reviews = $reviews->fetchAll(PDO::FETCH_ASSOC);
} else {
    $reviews = [];
}

$profileUrl = function_exists('school_portal_url') ? school_portal_url($schoolSlug, '', true) : '/tenant/school_profile.php?slug=' . rawurlencode($schoolSlug);
$loginUrl = function_exists('school_login_url') ? school_login_url($schoolSlug, true) : $profileUrl . 'login.php';
$programLines = school_profile_admin_json_to_lines($school['landing_programs'] ?? '', 'programs');
$testimonialLines = school_profile_admin_json_to_lines($school['landing_testimonials'] ?? '', 'testimonials');
$primaryColor = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($school['primary_color'] ?? '')) ? $school['primary_color'] : '#3B82F6';
$secondaryColor = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($school['secondary_color'] ?? '')) ? $school['secondary_color'] : '#10B981';
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo academix_admin_e($school['name'] ?? 'School'); ?> | Public Profile</title>
    <link rel="icon" type="image/png" href="<?php echo academix_admin_e($schoolLogoUrl); ?>">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/remixicon.css">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/lib/bootstrap.min.css">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/style.css">
    <style>
        body { background: #f6f8fb; }
        .profile-shell { padding: 28px; }
        .profile-hero { background: linear-gradient(135deg, <?php echo academix_admin_e($primaryColor); ?> 0%, #101828 100%); color: #fff; border-radius: 24px; padding: 28px; position: relative; overflow: hidden; }
        .profile-hero:after { content: ""; position: absolute; inset: auto -80px -120px auto; width: 320px; height: 320px; background: <?php echo academix_admin_e($secondaryColor); ?>; opacity: .25; border-radius: 50%; }
        .profile-logo-card { width: 96px; height: 96px; background: #fff; border-radius: 22px; display: grid; place-items: center; padding: 12px; box-shadow: 0 18px 40px rgba(15, 23, 42, .18); }
        .profile-logo-card img { max-width: 100%; max-height: 100%; object-fit: contain; }
        .profile-card { background: #fff; border: 1px solid #e8edf3; border-radius: 18px; box-shadow: 0 18px 42px rgba(15, 23, 42, .05); }
        .profile-card-header { padding: 22px 24px; border-bottom: 1px solid #edf1f6; display: flex; align-items: center; justify-content: space-between; gap: 16px; }
        .profile-card-body { padding: 24px; }
        .profile-tabs { position: sticky; top: 16px; }
        .profile-tabs a { display: flex; align-items: center; gap: 10px; padding: 12px 14px; color: #667085; border-radius: 12px; font-weight: 600; text-decoration: none; }
        .profile-tabs a:hover { background: #f2f5f9; color: #101828; }
        .soft-label { color: #667085; font-size: 13px; margin-bottom: 8px; font-weight: 700; }
        .form-control, .form-select { border-radius: 12px; border-color: #d8dee8; min-height: 46px; }
        textarea.form-control { min-height: 110px; }
        .profile-table img { width: 72px; height: 56px; object-fit: cover; border-radius: 12px; }
        .empty-state { border: 1px dashed #d0d5dd; border-radius: 16px; padding: 24px; color: #667085; background: #fbfcfe; text-align: center; }
        .btn-brand { background: <?php echo academix_admin_e($primaryColor); ?>; color: #fff; border: 0; }
        .btn-brand:hover { color: #fff; filter: brightness(.95); }
        .color-preview { width: 38px; height: 38px; border-radius: 50%; border: 3px solid #fff; box-shadow: 0 0 0 1px #d0d5dd; }
        @media (max-width: 991px) { .profile-shell { padding: 18px; } .profile-tabs { position: static; } }
    </style>
</head>
<body>
<div class="overlay bg-black bg-opacity-50 w-100 h-100 position-fixed z-9 visibility-hidden opacity-0 duration-300"></div>
<?php include_once __DIR__ . '/includes/sidebar.php'; ?>

<main class="dashboard-main">
    <div class="navbar-header shadow-1">
        <div class="row align-items-center justify-content-between">
            <div class="col-auto">
                <div class="d-flex flex-wrap align-items-center gap-4">
                    <button type="button" class="sidebar-mobile-toggle" aria-label="Sidebar Mobile Toggler Button">
                        <iconify-icon icon="heroicons:bars-3-solid" class="icon"></iconify-icon>
                    </button>
                    <div>
                        <h6 class="mb-0">School Profile</h6>
                        <span class="text-sm text-secondary-light">Manage the public school landing page</span>
                    </div>
                </div>
            </div>
            <div class="col-auto d-flex align-items-center gap-2">
                <a href="<?php echo academix_admin_e($profileUrl); ?>" target="_blank" class="btn btn-outline-primary d-flex align-items-center gap-2">
                    <i class="ri-external-link-line"></i> View Site
                </a>
                <button type="button" data-theme-toggle class="w-40-px h-40-px bg-neutral-200 rounded-circle d-flex justify-content-center align-items-center" aria-label="Dark & Light Mode Button"></button>
            </div>
        </div>
    </div>

    <div class="dashboard-main-body profile-shell">
        <?php if ($flash): ?>
            <div class="alert alert-<?php echo $flash['type'] === 'success' ? 'success' : 'danger'; ?> alert-dismissible fade show" role="alert">
                <?php echo academix_admin_e($flash['message']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <section class="profile-hero mb-24">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-4 position-relative z-1">
                <div class="d-flex align-items-center gap-3">
                    <div class="profile-logo-card">
                        <img src="<?php echo academix_admin_e($schoolLogoUrl); ?>" alt="<?php echo academix_admin_e($school['name']); ?> logo">
                    </div>
                    <div>
                        <div class="text-sm opacity-75 mb-1"><?php echo academix_admin_e($school['slug']); ?></div>
                        <h2 class="mb-2 text-white"><?php echo academix_admin_e($school['name']); ?></h2>
                        <p class="mb-0 opacity-75"><?php echo academix_admin_e($school['landing_headline'] ?? 'Interactive learning that students love'); ?></p>
                    </div>
                </div>
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <span class="badge bg-white text-dark px-16 py-10">Profile URL</span>
                    <a class="text-white fw-semibold" href="<?php echo academix_admin_e($profileUrl); ?>" target="_blank"><?php echo academix_admin_e($profileUrl); ?></a>
                </div>
            </div>
        </section>

        <div class="row gy-4">
            <div class="col-xl-3">
                <div class="profile-card profile-tabs p-12">
                    <a href="#landing"><i class="ri-layout-5-line"></i> Landing Content</a>
                    <a href="#identity"><i class="ri-school-line"></i> School Details</a>
                    <a href="#contacts"><i class="ri-contacts-book-line"></i> Contacts</a>
                    <a href="#facilities"><i class="ri-building-4-line"></i> Facilities</a>
                    <a href="#gallery"><i class="ri-image-line"></i> Gallery</a>
                    <a href="#reviews"><i class="ri-chat-smile-2-line"></i> Reviews</a>
                    <a href="<?php echo academix_admin_e($loginUrl); ?>" target="_blank"><i class="ri-login-circle-line"></i> Login Page</a>
                </div>
            </div>

            <div class="col-xl-9">
                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?php echo academix_admin_e($csrfToken); ?>">
                    <input type="hidden" name="action" value="save_profile">

                    <div class="profile-card mb-24" id="landing">
                        <div class="profile-card-header">
                            <div>
                                <h5 class="mb-1">Landing Content</h5>
                                <p class="text-secondary-light mb-0">Text and images shown on the school public homepage.</p>
                            </div>
                            <button type="submit" class="btn btn-brand px-24">Save Changes</button>
                        </div>
                        <div class="profile-card-body">
                            <div class="row gy-3">
                                <div class="col-md-6">
                                    <label class="soft-label">Badge Text</label>
                                    <input class="form-control" name="landing_badge_text" value="<?php echo academix_admin_e($school['landing_badge_text'] ?? 'Admissions open'); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="soft-label">Headline</label>
                                    <input class="form-control" name="landing_headline" value="<?php echo academix_admin_e($school['landing_headline'] ?? 'Interactive learning that students love'); ?>">
                                </div>
                                <div class="col-12">
                                    <label class="soft-label">Subheadline</label>
                                    <textarea class="form-control" name="landing_subheadline"><?php echo academix_admin_e($school['landing_subheadline'] ?? ''); ?></textarea>
                                </div>
                                <div class="col-md-6">
                                    <label class="soft-label">Primary Button Text</label>
                                    <input class="form-control" name="landing_primary_cta_text" value="<?php echo academix_admin_e($school['landing_primary_cta_text'] ?? 'Apply Now'); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="soft-label">Secondary Button Text</label>
                                    <input class="form-control" name="landing_secondary_cta_text" value="<?php echo academix_admin_e($school['landing_secondary_cta_text'] ?? 'Portal Login'); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="soft-label">Hero Image</label>
                                    <input class="form-control" type="file" name="hero_file" accept="image/*">
                                </div>
                                <div class="col-md-6">
                                    <label class="soft-label">Feature Image</label>
                                    <input class="form-control" type="file" name="feature_file" accept="image/*">
                                </div>
                                <div class="col-md-6">
                                    <label class="soft-label">Intro Title</label>
                                    <input class="form-control" name="landing_intro_title" value="<?php echo academix_admin_e($school['landing_intro_title'] ?? 'Learning made fun, focused, and personal'); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="soft-label">Highlight Title</label>
                                    <input class="form-control" name="landing_highlight_title" value="<?php echo academix_admin_e($school['landing_highlight_title'] ?? 'Explore our amazing educational world'); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="soft-label">Intro Text</label>
                                    <textarea class="form-control" name="landing_intro_text"><?php echo academix_admin_e($school['landing_intro_text'] ?? ''); ?></textarea>
                                </div>
                                <div class="col-md-6">
                                    <label class="soft-label">Highlight Text</label>
                                    <textarea class="form-control" name="landing_highlight_text"><?php echo academix_admin_e($school['landing_highlight_text'] ?? ''); ?></textarea>
                                </div>
                                <div class="col-md-6">
                                    <label class="soft-label">Final CTA Title</label>
                                    <input class="form-control" name="landing_cta_title" value="<?php echo academix_admin_e($school['landing_cta_title'] ?? 'Start the learning adventure today'); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="soft-label">Final CTA Text</label>
                                    <textarea class="form-control" name="landing_cta_text"><?php echo academix_admin_e($school['landing_cta_text'] ?? ''); ?></textarea>
                                </div>
                                <div class="col-md-6">
                                    <label class="soft-label">Programs</label>
                                    <textarea class="form-control" name="landing_programs_text" placeholder="ABCs & Reading | Foundational literacy and vocabulary"><?php echo academix_admin_e($programLines); ?></textarea>
                                </div>
                                <div class="col-md-6">
                                    <label class="soft-label">Testimonials</label>
                                    <textarea class="form-control" name="landing_testimonials_text" placeholder="Jane Doe | Parent | My child is more confident now"><?php echo academix_admin_e($testimonialLines); ?></textarea>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="profile-card mb-24" id="identity">
                        <div class="profile-card-header">
                            <div>
                                <h5 class="mb-1">School Details</h5>
                                <p class="text-secondary-light mb-0">Brand, contact, admission, and public information.</p>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <span class="color-preview" style="background: <?php echo academix_admin_e($primaryColor); ?>"></span>
                                <span class="color-preview" style="background: <?php echo academix_admin_e($secondaryColor); ?>"></span>
                            </div>
                        </div>
                        <div class="profile-card-body">
                            <div class="row gy-3">
                                <div class="col-md-8">
                                    <label class="soft-label">School Name</label>
                                    <input class="form-control" name="name" value="<?php echo academix_admin_e($school['name'] ?? ''); ?>" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="soft-label">School Logo</label>
                                    <input class="form-control" type="file" name="logo_file" accept="image/*">
                                </div>
                                <div class="col-md-6">
                                    <label class="soft-label">Primary Color</label>
                                    <input class="form-control" type="color" name="primary_color" value="<?php echo academix_admin_e($primaryColor); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="soft-label">Secondary Color</label>
                                    <input class="form-control" type="color" name="secondary_color" value="<?php echo academix_admin_e($secondaryColor); ?>">
                                </div>
                                <div class="col-12">
                                    <label class="soft-label">Description</label>
                                    <textarea class="form-control" name="description"><?php echo academix_admin_e($school['description'] ?? ''); ?></textarea>
                                </div>
                                <div class="col-md-6">
                                    <label class="soft-label">Mission Statement</label>
                                    <textarea class="form-control" name="mission_statement"><?php echo academix_admin_e($school['mission_statement'] ?? ''); ?></textarea>
                                </div>
                                <div class="col-md-6">
                                    <label class="soft-label">Vision Statement</label>
                                    <textarea class="form-control" name="vision_statement"><?php echo academix_admin_e($school['vision_statement'] ?? ''); ?></textarea>
                                </div>
                                <div class="col-md-6">
                                    <label class="soft-label">Principal Name</label>
                                    <input class="form-control" name="principal_name" value="<?php echo academix_admin_e($school['principal_name'] ?? ''); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="soft-label">Principal Message</label>
                                    <textarea class="form-control" name="principal_message"><?php echo academix_admin_e($school['principal_message'] ?? ''); ?></textarea>
                                </div>
                                <div class="col-md-4">
                                    <label class="soft-label">Type</label>
                                    <select class="form-select" name="school_type">
                                        <?php foreach (['nursery','primary','secondary','comprehensive','international','montessori','boarding','day'] as $type): ?>
                                            <option value="<?php echo $type; ?>" <?php echo (($school['school_type'] ?? '') === $type) ? 'selected' : ''; ?>><?php echo academix_admin_e(ucwords($type)); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="soft-label">Curriculum</label>
                                    <input class="form-control" name="curriculum" value="<?php echo academix_admin_e($school['curriculum'] ?? ''); ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="soft-label">Established Year</label>
                                    <input class="form-control" type="number" name="establishment_year" value="<?php echo academix_admin_e($school['establishment_year'] ?? ''); ?>">
                                </div>
                                <div class="col-md-4"><label class="soft-label">Students</label><input class="form-control" type="number" name="student_count" value="<?php echo academix_admin_e($school['student_count'] ?? 0); ?>"></div>
                                <div class="col-md-4"><label class="soft-label">Teachers</label><input class="form-control" type="number" name="teacher_count" value="<?php echo academix_admin_e($school['teacher_count'] ?? 0); ?>"></div>
                                <div class="col-md-4"><label class="soft-label">Classes</label><input class="form-control" type="number" name="class_count" value="<?php echo academix_admin_e($school['class_count'] ?? 0); ?>"></div>
                                <div class="col-md-6"><label class="soft-label">Email</label><input class="form-control" type="email" name="email" value="<?php echo academix_admin_e($school['email'] ?? ''); ?>"></div>
                                <div class="col-md-6"><label class="soft-label">Phone</label><input class="form-control" name="phone" value="<?php echo academix_admin_e($school['phone'] ?? ''); ?>"></div>
                                <div class="col-12"><label class="soft-label">Address</label><textarea class="form-control" name="address"><?php echo academix_admin_e($school['address'] ?? ''); ?></textarea></div>
                                <div class="col-md-4"><label class="soft-label">City</label><input class="form-control" name="city" value="<?php echo academix_admin_e($school['city'] ?? ''); ?>"></div>
                                <div class="col-md-4"><label class="soft-label">State</label><input class="form-control" name="state" value="<?php echo academix_admin_e($school['state'] ?? ''); ?>"></div>
                                <div class="col-md-4"><label class="soft-label">Country</label><input class="form-control" name="country" value="<?php echo academix_admin_e($school['country'] ?? 'Nigeria'); ?>"></div>
                                <div class="col-md-4"><label class="soft-label">Admission Status</label><select class="form-select" name="admission_status"><?php foreach (['open'=>'Open','closed'=>'Closed','waiting_list'=>'Waiting List'] as $k=>$v): ?><option value="<?php echo $k; ?>" <?php echo (($school['admission_status'] ?? '') === $k) ? 'selected' : ''; ?>><?php echo $v; ?></option><?php endforeach; ?></select></div>
                                <div class="col-md-4"><label class="soft-label">Admission Deadline</label><input class="form-control" type="date" name="admission_deadline" value="<?php echo academix_admin_e($school['admission_deadline'] ?? ''); ?>"></div>
                                <div class="col-md-4"><label class="soft-label">School Hours</label><input class="form-control" name="school_hours" value="<?php echo academix_admin_e($school['school_hours'] ?? ''); ?>"></div>
                                <div class="col-md-6"><label class="soft-label">Fee Range From</label><input class="form-control" type="number" step="0.01" name="fee_range_from" value="<?php echo academix_admin_e($school['fee_range_from'] ?? 0); ?>"></div>
                                <div class="col-md-6"><label class="soft-label">Fee Range To</label><input class="form-control" type="number" step="0.01" name="fee_range_to" value="<?php echo academix_admin_e($school['fee_range_to'] ?? 0); ?>"></div>
                                <div class="col-12"><label class="soft-label">Admission Process</label><textarea class="form-control" name="admission_process"><?php echo academix_admin_e($school['admission_process'] ?? ''); ?></textarea></div>
                            </div>
                        </div>
                    </div>
                </form>

                <div class="profile-card mb-24" id="contacts">
                    <div class="profile-card-header"><div><h5 class="mb-1">Contacts</h5><p class="text-secondary-light mb-0">Published on the contact section of the school page.</p></div></div>
                    <div class="profile-card-body">
                        <form method="post" class="row gy-3 align-items-end mb-4">
                            <input type="hidden" name="csrf_token" value="<?php echo academix_admin_e($csrfToken); ?>">
                            <input type="hidden" name="action" value="add_contact">
                            <div class="col-md-2"><label class="soft-label">Type</label><select name="type" class="form-select"><option>phone</option><option>email</option><option>address</option><option>website</option><option>social</option></select></div>
                            <div class="col-md-3"><label class="soft-label">Label</label><input name="label" class="form-control" placeholder="Admissions office"></div>
                            <div class="col-md-4"><label class="soft-label">Value</label><input name="value" class="form-control" required></div>
                            <div class="col-md-1"><label class="soft-label">Order</label><input name="sort_order" type="number" class="form-control" value="0"></div>
                            <div class="col-md-1"><label class="soft-label">Primary</label><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="is_primary" value="1"></div></div>
                            <div class="col-md-1"><button class="btn btn-brand w-100" type="submit"><i class="ri-add-line"></i></button></div>
                        </form>
                        <?php if ($contacts): ?>
                            <div class="table-responsive"><table class="table profile-table align-middle"><tbody>
                                <?php foreach ($contacts as $contact): ?><tr>
                                    <td><span class="badge bg-primary-subtle text-primary-main"><?php echo academix_admin_e($contact['type'] ?? ''); ?></span></td>
                                    <td><?php echo academix_admin_e($contact['label'] ?? ''); ?></td>
                                    <td><?php echo academix_admin_e($contact['value'] ?? ''); ?></td>
                                    <td><?php echo !empty($contact['is_primary']) ? '<span class="badge bg-success-subtle text-success-main">Primary</span>' : ''; ?></td>
                                    <td class="text-end"><form method="post"><input type="hidden" name="csrf_token" value="<?php echo academix_admin_e($csrfToken); ?>"><input type="hidden" name="action" value="delete_contact"><input type="hidden" name="id" value="<?php echo (int)$contact['id']; ?>"><button class="btn btn-sm btn-outline-danger" type="submit"><i class="ri-delete-bin-line"></i></button></form></td>
                                </tr><?php endforeach; ?>
                            </tbody></table></div>
                        <?php else: ?><div class="empty-state">No extra contacts yet. Add admissions, support, website, or social links.</div><?php endif; ?>
                    </div>
                </div>

                <div class="profile-card mb-24" id="facilities">
                    <div class="profile-card-header"><div><h5 class="mb-1">Facilities</h5><p class="text-secondary-light mb-0">Showcase what makes the school environment strong.</p></div></div>
                    <div class="profile-card-body">
                        <form method="post" class="row gy-3 align-items-end mb-4">
                            <input type="hidden" name="csrf_token" value="<?php echo academix_admin_e($csrfToken); ?>">
                            <input type="hidden" name="action" value="add_facility">
                            <div class="col-md-3"><label class="soft-label">Name</label><input name="name" class="form-control" required placeholder="Science Laboratory"></div>
                            <div class="col-md-5"><label class="soft-label">Description</label><input name="description" class="form-control"></div>
                            <div class="col-md-2"><label class="soft-label">Icon</label><input name="icon" class="form-control" value="ri-building-4-line"></div>
                            <div class="col-md-1"><label class="soft-label">Order</label><input name="sort_order" type="number" class="form-control" value="0"></div>
                            <div class="col-md-1"><input type="hidden" name="is_active" value="1"><button class="btn btn-brand w-100" type="submit"><i class="ri-add-line"></i></button></div>
                        </form>
                        <div class="row gy-3">
                            <?php foreach ($facilities as $facility): ?>
                                <div class="col-md-6">
                                    <div class="border rounded-4 p-16 h-100 d-flex justify-content-between gap-3">
                                        <div><i class="<?php echo academix_admin_e($facility['icon'] ?? 'ri-building-4-line'); ?> text-primary-main text-xl"></i><h6 class="mt-2 mb-1"><?php echo academix_admin_e($facility['name'] ?? ''); ?></h6><p class="text-secondary-light text-sm mb-0"><?php echo academix_admin_e($facility['description'] ?? ''); ?></p></div>
                                        <form method="post"><input type="hidden" name="csrf_token" value="<?php echo academix_admin_e($csrfToken); ?>"><input type="hidden" name="action" value="delete_facility"><input type="hidden" name="id" value="<?php echo (int)$facility['id']; ?>"><button class="btn btn-sm btn-outline-danger" type="submit"><i class="ri-delete-bin-line"></i></button></form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <?php if (!$facilities): ?><div class="col-12"><div class="empty-state">No facilities added yet.</div></div><?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="profile-card mb-24" id="gallery">
                    <div class="profile-card-header"><div><h5 class="mb-1">Gallery</h5><p class="text-secondary-light mb-0">Images power the public landing page hero and campus sections.</p></div></div>
                    <div class="profile-card-body">
                        <form method="post" enctype="multipart/form-data" class="row gy-3 align-items-end mb-4">
                            <input type="hidden" name="csrf_token" value="<?php echo academix_admin_e($csrfToken); ?>">
                            <input type="hidden" name="action" value="add_gallery">
                            <div class="col-md-4"><label class="soft-label">Image</label><input class="form-control" type="file" name="gallery_file" accept="image/*" required></div>
                            <div class="col-md-3"><label class="soft-label">Caption</label><input class="form-control" name="caption"></div>
                            <div class="col-md-2"><label class="soft-label">Type</label><select class="form-select" name="type"><option>campus</option><option>classroom</option><option>laboratory</option><option>library</option><option>sports</option><option>events</option><option>other</option></select></div>
                            <div class="col-md-2"><label class="soft-label">Order</label><input class="form-control" type="number" name="sort_order" value="0"></div>
                            <div class="col-md-1"><button class="btn btn-brand w-100" type="submit"><i class="ri-upload-cloud-line"></i></button></div>
                        </form>
                        <div class="row gy-3">
                            <?php foreach ($gallery as $image): $imageUrl = preg_match('#^https?://#', $image['image_url'] ?? '') ? $image['image_url'] : '/' . ltrim($image['image_url'] ?? '', '/'); ?>
                                <div class="col-md-4">
                                    <div class="border rounded-4 overflow-hidden bg-white">
                                        <img src="<?php echo academix_admin_e($imageUrl); ?>" alt="" style="width:100%;height:160px;object-fit:cover;">
                                        <div class="p-12 d-flex justify-content-between gap-2">
                                            <div><div class="fw-semibold"><?php echo academix_admin_e($image['caption'] ?? 'Campus image'); ?></div><span class="text-secondary-light text-sm"><?php echo academix_admin_e($image['type'] ?? 'campus'); ?></span></div>
                                            <form method="post"><input type="hidden" name="csrf_token" value="<?php echo academix_admin_e($csrfToken); ?>"><input type="hidden" name="action" value="delete_gallery"><input type="hidden" name="id" value="<?php echo (int)$image['id']; ?>"><button class="btn btn-sm btn-outline-danger" type="submit"><i class="ri-delete-bin-line"></i></button></form>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <?php if (!$gallery): ?><div class="col-12"><div class="empty-state">No gallery images uploaded yet.</div></div><?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="profile-card" id="reviews">
                    <div class="profile-card-header"><div><h5 class="mb-1">Reviews</h5><p class="text-secondary-light mb-0">Approve reviews before they appear on the public page.</p></div></div>
                    <div class="profile-card-body">
                        <?php if ($reviews): ?>
                            <div class="table-responsive"><table class="table align-middle">
                                <thead><tr><th>Reviewer</th><th>Rating</th><th>Comment</th><th>Status</th><th class="text-end">Action</th></tr></thead>
                                <tbody>
                                <?php foreach ($reviews as $review): ?>
                                    <tr>
                                        <td><strong><?php echo academix_admin_e($review['parent_name'] ?? 'Parent'); ?></strong><div class="text-secondary-light text-sm"><?php echo academix_admin_e($review['parent_email'] ?? ($review['student_name'] ?? '')); ?></div></td>
                                        <td><?php echo str_repeat('★', max(1, min(5, (int)($review['rating'] ?? 5)))); ?></td>
                                        <td style="max-width:380px;"><?php echo academix_admin_e($review['comment'] ?? ''); ?></td>
                                        <td><?php echo !empty($review['is_approved']) ? '<span class="badge bg-success-subtle text-success-main">Approved</span>' : '<span class="badge bg-warning-subtle text-warning-main">Pending</span>'; ?></td>
                                        <td class="text-end d-flex justify-content-end gap-2">
                                            <form method="post"><input type="hidden" name="csrf_token" value="<?php echo academix_admin_e($csrfToken); ?>"><input type="hidden" name="id" value="<?php echo (int)$review['id']; ?>"><input type="hidden" name="action" value="<?php echo !empty($review['is_approved']) ? 'unapprove_review' : 'approve_review'; ?>"><button class="btn btn-sm btn-outline-primary" type="submit"><?php echo !empty($review['is_approved']) ? 'Hide' : 'Approve'; ?></button></form>
                                            <form method="post"><input type="hidden" name="csrf_token" value="<?php echo academix_admin_e($csrfToken); ?>"><input type="hidden" name="id" value="<?php echo (int)$review['id']; ?>"><input type="hidden" name="action" value="delete_review"><button class="btn btn-sm btn-outline-danger" type="submit"><i class="ri-delete-bin-line"></i></button></form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table></div>
                        <?php else: ?><div class="empty-state">No reviews have been submitted yet.</div><?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<script src="https://academixsuite.com/tenant/assets/js/lib/jquery-3.7.1.min.js"></script>
<script src="https://academixsuite.com/tenant/assets/js/lib/bootstrap.bundle.min.js"></script>
<script src="https://academixsuite.com/tenant/assets/js/lib/iconify-icon.min.js"></script>
<script src="https://academixsuite.com/tenant/assets/js/app.js"></script>
</body>
</html>
