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
$announcements = [];
$events = [];
$stats = ['students' => 0, 'teachers' => 0, 'classes' => 0, 'subjects' => null];
$enrollmentError = '';
$enrollmentSuccess = false;
$requestNumber = '';

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

    if (school_profile_table_exists($platformDb, 'school_reviews')) {
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
            : 'Admissions are not open at this time.';
    }

    // ------------------------------------------------------------------
    // Public review submission. Reviews are inserted unapproved; school
    // admins moderate them from /admin/school-profile (Reviews tab).
    // ------------------------------------------------------------------
    $reviewError = '';
    $reviewSuccess = false;
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['review_submit'])) {
        if (function_exists('validateCsrfToken') && !validateCsrfToken($_POST['csrf_token'] ?? '')) {
            $reviewError = 'Your form session expired. Please refresh and try again.';
        } elseif (!school_profile_table_exists($platformDb, 'school_reviews')) {
            $reviewError = 'Reviews are not available right now.';
        } else {
            $parentName  = trim(strip_tags((string) ($_POST['review_parent_name']  ?? '')));
            $studentName = trim(strip_tags((string) ($_POST['review_student_name'] ?? '')));
            $rating      = (int) ($_POST['review_rating']  ?? 0);
            $comment     = trim(strip_tags((string) ($_POST['review_comment']     ?? '')));

            if ($parentName === '' || $comment === '') {
                $reviewError = 'Please enter your name and a short comment.';
            } elseif ($rating < 1 || $rating > 5) {
                $reviewError = 'Please choose a rating from 1 to 5.';
            } elseif (mb_strlen($comment) > 1000) {
                $reviewError = 'Please keep your review under 1000 characters.';
            } else {
                try {
                    // Cheap dedupe — same IP can post one review per school per hour.
                    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
                    $dup = $platformDb->prepare(
                        "SELECT 1 FROM school_reviews
                         WHERE school_id = ? AND submitter_ip = ?
                         AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
                         LIMIT 1"
                    );
                    try { $dup->execute([$school['id'], $ip]); } catch (Throwable $eDup) { /* submitter_ip column may not exist */ }

                    $reviewColumns = (function () use ($platformDb) {
                        try {
                            return array_column($platformDb->query('SHOW COLUMNS FROM `school_reviews`')->fetchAll(PDO::FETCH_ASSOC), 'Field');
                        } catch (Throwable $e) { return []; }
                    })();

                    $cols = array_values(array_filter(
                        ['school_id', 'parent_name', 'student_name', 'rating', 'comment', 'is_approved', 'submitter_ip', 'created_at'],
                        fn ($c) => $c === 'school_id' || $c === 'created_at' || in_array($c, $reviewColumns, true)
                    ));
                    $vals = [];
                    $placeholders = [];
                    foreach ($cols as $c) {
                        switch ($c) {
                            case 'school_id':    $vals[] = $school['id']; $placeholders[] = '?'; break;
                            case 'parent_name':  $vals[] = $parentName;  $placeholders[] = '?'; break;
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
                } catch (Throwable $e) {
                    error_log('Review submission failed: ' . $e->getMessage());
                    $reviewError = 'Could not submit your review. Please try again.';
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
$primaryColor = preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($school['primary_color'] ?? '')) ? $school['primary_color'] : '#7c73ff';
$secondaryColor = preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($school['secondary_color'] ?? '')) ? $school['secondary_color'] : '#b8ff61';

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
$circleImages = [
    $heroImage,
    school_profile_first_image([$gallery[1]['image_url'] ?? '', 'tenant/assets/images/thumbs/avatar-img1.png'], 'tenant/assets/images/thumbs/avatar-img1.png'),
    school_profile_first_image([$gallery[2]['image_url'] ?? '', 'tenant/assets/images/thumbs/avatar-img4.png'], 'tenant/assets/images/thumbs/avatar-img4.png'),
    school_profile_first_image([$gallery[3]['image_url'] ?? '', 'tenant/assets/images/thumbs/top-teacher-img1.png'], 'tenant/assets/images/thumbs/top-teacher-img1.png'),
    school_profile_first_image([$gallery[4]['image_url'] ?? '', 'tenant/assets/images/thumbs/library-img1.png'], 'tenant/assets/images/thumbs/library-img1.png')
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
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo school_profile_e($schoolName); ?> | School Portal</title>
    <meta name="description" content="<?php echo school_profile_e(substr($landingSubheadline, 0, 160)); ?>">
    <style>
        :root {
            --ink: #161616;
            --muted: #626262;
            --paper: #ffffff;
            --soft: #f5f5f0;
            --line: #e4e1d8;
            --violet: <?php echo school_profile_e($primaryColor); ?>;
            --lime: <?php echo school_profile_e($secondaryColor); ?>;
            --peach: #ffb29f;
            --sky: #bde7e3;
            --sun: #f8d66d;
            --shadow: 0 24px 70px rgba(25, 25, 25, 0.11);
            --radius: 22px;
        }

        * {
            box-sizing: border-box;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            margin: 0;
            color: var(--ink);
            background: var(--paper);
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            line-height: 1.5;
        }

        img {
            display: block;
            max-width: 100%;
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        .container {
            width: min(1180px, calc(100% - 32px));
            margin: 0 auto;
        }

        .site-header {
            position: sticky;
            top: 0;
            z-index: 40;
            background: rgba(255, 255, 255, 0.92);
            border-bottom: 1px solid var(--line);
            backdrop-filter: blur(18px);
        }

        .nav {
            min-height: 78px;
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr);
            align-items: center;
            gap: 18px;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 0;
        }

        .logo {
            width: 54px;
            height: 54px;
            border-radius: 16px;
            object-fit: contain;
            background: #fff;
            border: 1px solid var(--line);
            padding: 5px;
        }

        .logo-fallback {
            width: 54px;
            height: 54px;
            border-radius: 16px;
            display: grid;
            place-items: center;
            background: var(--ink);
            color: #fff;
            font-weight: 900;
            letter-spacing: 0;
        }

        .brand strong {
            display: block;
            max-width: 310px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            font-size: 20px;
            letter-spacing: 0;
        }

        .brand span {
            display: block;
            color: var(--muted);
            font-size: 12px;
            font-weight: 700;
        }

        .nav-links {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 24px;
            color: #2d2d2d;
            font-size: 13px;
            font-weight: 800;
        }

        .nav-actions {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 10px;
        }

        .btn {
            min-height: 46px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 0;
            border-radius: 999px;
            padding: 0 20px;
            font-weight: 900;
            cursor: pointer;
            font-size: 14px;
        }

        .btn-lime {
            background: var(--lime);
            color: #101010;
        }

        .btn-dark {
            background: var(--ink);
            color: #fff;
        }

        .btn-light {
            background: #fff;
            color: var(--ink);
            border: 1px solid var(--line);
        }

        .hero {
            min-height: calc(100vh - 78px);
            padding: 58px 0 52px;
            overflow: hidden;
            position: relative;
            background: #fff;
        }

        .hero-grid {
            display: grid;
            grid-template-columns: minmax(0, 0.95fr) minmax(420px, 1.05fr);
            align-items: center;
            gap: 46px;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #fff0ea;
            border-radius: 999px;
            padding: 8px 12px;
            font-size: 12px;
            font-weight: 900;
            margin-bottom: 18px;
        }

        .badge::before {
            content: "";
            width: 9px;
            height: 9px;
            border-radius: 999px;
            background: var(--lime);
            box-shadow: 0 0 0 5px rgba(184, 255, 97, .25);
        }

        .hero-title,
        .section-title,
        .cta-title {
            font-family: "Arial Black", "Trebuchet MS", ui-sans-serif, system-ui, sans-serif;
            letter-spacing: 0;
        }

        .hero-title {
            font-size: clamp(46px, 6vw, 86px);
            line-height: .95;
            margin: 0;
            max-width: 690px;
        }

        .marker {
            display: inline;
            background: linear-gradient(transparent 54%, #ffb8a8 54%);
            padding: 0 5px;
        }

        .hero-copy {
            color: #3e3e3e;
            max-width: 610px;
            font-size: 18px;
            margin: 22px 0 0;
        }

        .hero-buttons {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 28px;
        }

        .mini-proof {
            margin-top: 22px;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 12px;
            color: var(--muted);
            font-size: 13px;
            font-weight: 800;
        }

        .avatar-stack {
            display: flex;
        }

        .avatar-stack img,
        .avatar-stack .avatar-initial {
            width: 34px;
            height: 34px;
            border-radius: 999px;
            border: 2px solid #fff;
            object-fit: cover;
            margin-left: -8px;
            background: var(--violet);
            color: #fff;
            display: grid;
            place-items: center;
            font-weight: 900;
        }

        .avatar-stack img:first-child,
        .avatar-stack .avatar-initial:first-child {
            margin-left: 0;
        }

        .hero-art {
            position: relative;
            min-height: 590px;
        }

        .circle-photo {
            position: absolute;
            overflow: hidden;
            border-radius: 999px;
            background: var(--soft);
            box-shadow: var(--shadow);
            border: 9px solid #fff;
        }

        .circle-photo img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .circle-main {
            width: 265px;
            height: 265px;
            right: 220px;
            top: 22px;
        }

        .circle-top {
            width: 218px;
            height: 218px;
            right: 12px;
            top: 82px;
        }

        .circle-mid {
            width: 236px;
            height: 236px;
            right: 178px;
            top: 314px;
        }

        .circle-low {
            width: 226px;
            height: 226px;
            right: 0;
            top: 360px;
        }

        .student-cutout {
            position: absolute;
            width: 190px;
            left: 40px;
            bottom: 16px;
            border-radius: 30px;
            overflow: hidden;
            box-shadow: var(--shadow);
            transform: rotate(-3deg);
            background: #fff;
            border: 8px solid #fff;
        }

        .doodle {
            position: absolute;
            pointer-events: none;
        }

        .doodle-arc {
            right: 110px;
            top: 238px;
            width: 420px;
            height: 210px;
            border: 8px solid rgba(124, 115, 255, .24);
            border-top: 0;
            border-radius: 0 0 240px 240px;
        }

        .doodle-shapes {
            left: 0;
            top: 330px;
            width: 250px;
            height: 160px;
            border: 2px dashed #969696;
            border-radius: 50%;
            transform: rotate(12deg);
        }

        .shape {
            position: absolute;
            border-radius: 12px;
        }

        .shape.one {
            width: 38px;
            height: 38px;
            background: var(--lime);
            left: 36px;
            top: 34px;
            border-radius: 50%;
        }

        .shape.two {
            width: 54px;
            height: 54px;
            background: var(--peach);
            left: 86px;
            top: 56px;
            clip-path: polygon(50% 0, 100% 100%, 0 100%);
        }

        .shape.three {
            width: 46px;
            height: 46px;
            background: #b7b0ff;
            right: 46px;
            top: 42px;
            transform: rotate(25deg);
        }

        .section {
            padding: 86px 0;
        }

        .section-soft {
            background: var(--soft);
        }

        .section-violet {
            background:
                linear-gradient(rgba(255,255,255,.06) 2px, transparent 2px),
                linear-gradient(90deg, rgba(255,255,255,.06) 2px, transparent 2px),
                var(--violet);
            background-size: 46px 46px;
            color: #fff;
            overflow: hidden;
            position: relative;
        }

        .section-heading {
            display: flex;
            align-items: end;
            justify-content: space-between;
            gap: 28px;
            margin-bottom: 34px;
        }

        .section-kicker {
            font-weight: 900;
            color: var(--violet);
            text-transform: uppercase;
            font-size: 12px;
            letter-spacing: 0;
        }

        .section-violet .section-kicker {
            color: var(--lime);
        }

        .section-title {
            font-size: clamp(34px, 5vw, 58px);
            line-height: 1;
            margin: 8px 0 0;
            max-width: 760px;
        }

        .section-note {
            max-width: 440px;
            color: var(--muted);
            margin: 0;
        }

        .section-violet .section-note {
            color: rgba(255,255,255,.8);
        }

        .stats-strip {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            border: 1px solid var(--line);
            border-radius: 24px;
            overflow: hidden;
            background: #fff;
            box-shadow: var(--shadow);
            margin-top: -42px;
            position: relative;
            z-index: 5;
        }

        .stat {
            padding: 28px;
            border-right: 1px solid var(--line);
        }

        .stat:last-child {
            border-right: 0;
        }

        .stat strong {
            display: block;
            font-size: 36px;
            line-height: 1;
            font-weight: 900;
        }

        .stat span {
            display: block;
            margin-top: 8px;
            color: var(--muted);
            font-weight: 800;
            font-size: 13px;
        }

        .about-grid {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 430px;
            gap: 40px;
            align-items: start;
        }

        .about-card {
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 26px;
            padding: 34px;
            box-shadow: 0 14px 36px rgba(22,22,22,.06);
        }

        .about-card p {
            color: #3d3d3d;
            font-size: 17px;
            margin: 0;
        }

        .mission-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 16px;
            margin-top: 18px;
        }

        .mini-card {
            background: #fff7ef;
            border-radius: 18px;
            padding: 20px;
            border: 1px solid #ffdcca;
        }

        .mini-card h3 {
            margin: 0 0 8px;
            font-size: 18px;
        }

        .mini-card p {
            font-size: 14px;
            color: #525252;
        }

        .portrait-card {
            border-radius: 32px;
            overflow: hidden;
            background: var(--violet);
            padding: 18px;
            color: #fff;
            box-shadow: var(--shadow);
        }

        .portrait-card img {
            width: 100%;
            aspect-ratio: 1 / 1;
            object-fit: cover;
            border-radius: 50%;
            border: 10px solid #fff;
            background: #fff;
        }

        .portrait-card h3 {
            margin: 18px 0 4px;
            font-size: 26px;
            font-family: "Arial Black", "Trebuchet MS", ui-sans-serif;
            letter-spacing: 0;
        }

        .portrait-card p {
            margin: 0;
            color: rgba(255,255,255,.82);
        }

        .program-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            border-top: 1px solid #d8d8d8;
            border-left: 1px solid #d8d8d8;
            background: #fff;
        }

        .program-card {
            min-height: 310px;
            padding: 44px;
            border-right: 1px solid #d8d8d8;
            border-bottom: 1px solid #d8d8d8;
            display: grid;
            grid-template-columns: minmax(0, 1fr) 58px;
            gap: 18px;
            position: relative;
            overflow: hidden;
        }

        .program-card:nth-child(4n + 2),
        .program-card:nth-child(4n + 3) {
            background: var(--violet);
            color: #fff;
        }

        .program-card h3 {
            font-size: clamp(25px, 3vw, 38px);
            line-height: 1.05;
            margin: 0 0 14px;
        }

        .program-card p {
            margin: 0;
            color: #555;
            max-width: 410px;
            font-weight: 700;
        }

        .program-card:nth-child(4n + 2) p,
        .program-card:nth-child(4n + 3) p {
            color: rgba(255,255,255,.86);
        }

        .arrow-btn {
            width: 58px;
            height: 58px;
            border-radius: 50%;
            display: grid;
            place-items: center;
            background: #fff;
            color: var(--ink);
            font-size: 24px;
            font-weight: 900;
            box-shadow: 0 10px 25px rgba(0,0,0,.08);
        }

        .program-card:nth-child(4n + 2) .arrow-btn,
        .program-card:nth-child(4n + 3) .arrow-btn {
            background: var(--lime);
        }

        .program-image {
            position: absolute;
            right: 42px;
            bottom: -40px;
            width: 300px;
            max-width: 44%;
            aspect-ratio: 1.45 / 1;
            border-radius: 999px 999px 0 0;
            overflow: hidden;
            background: #fff;
        }

        .program-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .teacher-strip {
            padding-top: 70px;
        }

        .teacher-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 28px;
        }

        .teacher-card {
            position: relative;
            min-height: 280px;
            border-radius: 28px;
            background: rgba(255,255,255,.15);
            display: grid;
            align-items: end;
            overflow: hidden;
            padding: 0 20px 26px;
        }

        .teacher-card img {
            position: absolute;
            inset: 0 0 auto 0;
            width: 100%;
            height: 72%;
            object-fit: contain;
            object-position: top center;
        }

        .teacher-caption {
            position: relative;
            z-index: 2;
            background: #fff;
            color: var(--ink);
            border-radius: 0 0 100px 100px;
            text-align: center;
            padding: 28px 18px 36px;
            min-height: 118px;
        }

        .teacher-caption strong {
            display: block;
            font-family: "Arial Black", "Trebuchet MS", ui-sans-serif;
            letter-spacing: 0;
        }

        .teacher-caption span {
            color: var(--muted);
            font-size: 12px;
            font-weight: 800;
        }

        .testimonial-wrap {
            display: grid;
            grid-template-columns: 380px minmax(0, 1fr);
            gap: 36px;
            align-items: center;
        }

        .testimonial-feature {
            background: #fff;
            border-radius: 28px;
            padding: 28px;
            box-shadow: var(--shadow);
            min-height: 420px;
            position: relative;
            overflow: hidden;
        }

        .testimonial-feature blockquote {
            margin: 0;
            font-size: 22px;
            line-height: 1.22;
            font-weight: 900;
        }

        .testimonial-feature img {
            width: 170px;
            height: 170px;
            border-radius: 50%;
            object-fit: cover;
            margin: 34px auto 0;
            border: 8px solid #fff0ea;
        }

        .testimonial-list {
            display: grid;
            grid-template-columns: repeat(4, minmax(90px, 1fr));
            gap: 0;
            min-height: 420px;
            border-left: 1px dashed #b9b9b9;
        }

        .testimonial-name {
            border-right: 1px dashed #b9b9b9;
            display: grid;
            place-items: end center;
            padding: 24px 10px;
            color: var(--violet);
            font-weight: 900;
            writing-mode: vertical-rl;
            transform: rotate(180deg);
            text-align: center;
        }

        .updates-grid,
        .contact-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 18px;
        }

        .update-card,
        .contact-card {
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 20px;
            padding: 22px;
            min-height: 190px;
        }

        .update-card small,
        .contact-card small {
            display: block;
            color: var(--violet);
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 0;
            margin-bottom: 12px;
        }

        .update-card h3,
        .contact-card h3 {
            margin: 0 0 8px;
            font-size: 20px;
        }

        .update-card p,
        .contact-card p,
        .contact-card a {
            margin: 0;
            color: var(--muted);
            font-weight: 700;
            word-break: break-word;
        }

        .admission-shell {
            display: grid;
            grid-template-columns: minmax(0, .88fr) minmax(420px, 1.12fr);
            gap: 28px;
            align-items: start;
        }

        .cta-panel {
            border-radius: 32px;
            padding: 44px;
            min-height: 520px;
            color: #fff;
            background: var(--violet);
            position: sticky;
            top: 104px;
            overflow: hidden;
        }

        .cta-title {
            font-size: clamp(34px, 5vw, 56px);
            line-height: 1;
            margin: 0 0 18px;
        }

        .cta-panel p {
            color: rgba(255,255,255,.82);
            font-weight: 700;
        }

        .cta-floating {
            position: absolute;
            width: 160px;
            height: 160px;
            border-radius: 50%;
            overflow: hidden;
            border: 9px solid #fff;
            background: #fff;
        }

        .cta-floating.one {
            right: 34px;
            bottom: 34px;
        }

        .cta-floating.two {
            left: 40px;
            bottom: -48px;
        }

        .cta-floating img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .form-panel {
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 28px;
            padding: 30px;
            box-shadow: var(--shadow);
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
        }

        .field {
            display: grid;
            gap: 7px;
        }

        .field.full {
            grid-column: 1 / -1;
        }

        label {
            font-size: 13px;
            font-weight: 900;
        }

        input,
        select,
        textarea {
            width: 100%;
            min-height: 48px;
            border: 1px solid var(--line);
            border-radius: 13px;
            padding: 13px 14px;
            font: inherit;
            background: #fff;
        }

        textarea {
            min-height: 112px;
            resize: vertical;
        }

        input:focus,
        select:focus,
        textarea:focus {
            outline: 3px solid rgba(184, 255, 97, .45);
            border-color: var(--ink);
        }

        .alert {
            border-radius: 16px;
            padding: 14px 16px;
            margin-bottom: 18px;
            font-weight: 800;
        }

        .alert-error {
            color: #9f1d13;
            background: #fff0ec;
            border: 1px solid #ffd1c6;
        }

        .alert-success {
            color: #12643c;
            background: #eafff3;
            border: 1px solid #c3f6d8;
        }

        .footer {
            padding: 46px 0;
            border-top: 1px solid var(--line);
            background: #fff;
        }

        .footer-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.1fr) repeat(3, minmax(140px, .5fr));
            gap: 28px;
        }

        .footer h3,
        .footer h4 {
            margin: 0 0 12px;
        }

        .footer p,
        .footer a {
            color: var(--muted);
            font-size: 14px;
            font-weight: 700;
        }

        .empty {
            border: 1px dashed var(--line);
            border-radius: 20px;
            padding: 28px;
            color: var(--muted);
            text-align: center;
            background: #fff;
        }

        @media (max-width: 1050px) {
            .nav {
                grid-template-columns: minmax(0, 1fr) auto;
            }

            .nav-links {
                display: none;
            }

            .hero-grid,
            .about-grid,
            .testimonial-wrap,
            .admission-shell {
                grid-template-columns: 1fr;
            }

            .hero-art {
                min-height: 520px;
            }

            .cta-panel {
                position: relative;
                top: auto;
            }
        }

        @media (max-width: 760px) {
            .container {
                width: min(100% - 22px, 1180px);
            }

            .nav {
                min-height: auto;
                padding: 12px 0;
            }

            .brand strong {
                max-width: 180px;
                font-size: 16px;
            }

            .nav-actions .btn-light {
                display: none;
            }

            .hero {
                padding-top: 34px;
                min-height: auto;
            }

            .hero-grid {
                gap: 24px;
            }

            .hero-art {
                min-height: 430px;
            }

            .circle-main {
                width: 190px;
                height: 190px;
                left: 0;
                top: 0;
                right: auto;
            }

            .circle-top {
                width: 158px;
                height: 158px;
                right: 0;
                top: 34px;
            }

            .circle-mid {
                width: 176px;
                height: 176px;
                left: 72px;
                top: 214px;
                right: auto;
            }

            .circle-low {
                width: 154px;
                height: 154px;
                right: 0;
                top: 244px;
            }

            .student-cutout,
            .doodle-shapes {
                display: none;
            }

            .stats-strip,
            .program-grid,
            .teacher-row,
            .updates-grid,
            .contact-grid,
            .form-grid,
            .mission-grid,
            .footer-grid {
                grid-template-columns: 1fr;
            }

            .stats-strip {
                margin-top: 18px;
            }

            .stat {
                border-right: 0;
                border-bottom: 1px solid var(--line);
            }

            .stat:last-child {
                border-bottom: 0;
            }

            .section {
                padding: 58px 0;
            }

            .section-heading {
                display: block;
            }

            .program-card {
                min-height: 260px;
                padding: 26px;
            }

            .program-image {
                opacity: .28;
                right: -26px;
                max-width: 56%;
            }

            .testimonial-list {
                grid-template-columns: 1fr;
                min-height: auto;
                border-left: 0;
            }

            .testimonial-name {
                writing-mode: horizontal-tb;
                transform: none;
                border: 1px dashed #b9b9b9;
                place-items: center;
            }
        }
    </style>
</head>
<body>
    <header class="site-header">
        <div class="container nav">
            <a class="brand" href="#top" aria-label="<?php echo school_profile_e($schoolName); ?> homepage">
                <?php if ($logoUrl): ?>
                    <img class="logo" src="<?php echo school_profile_e($logoUrl); ?>" alt="<?php echo school_profile_e($schoolName); ?> logo">
                <?php else: ?>
                    <span class="logo-fallback"><?php echo school_profile_e($schoolInitials); ?></span>
                <?php endif; ?>
                <span>
                    <strong><?php echo school_profile_e($schoolName); ?></strong>
                    <span><?php echo school_profile_e($schoolType); ?><?php echo $location ? ' - ' . school_profile_e($location) : ''; ?></span>
                </span>
            </a>

            <nav class="nav-links" aria-label="Main navigation">
                <a href="#about">About us</a>
                <a href="#programs">Subjects</a>
                <a href="#admission">Admission</a>
                <a href="#facilities">Facilities</a>
                <a href="#gallery">Gallery</a>
                <a href="#reviews">Reviews</a>
                <a href="#contact">Contact</a>
            </nav>

            <div class="nav-actions">
                <a class="btn btn-light" href="<?php echo school_profile_e($loginUrl); ?>"><?php echo school_profile_e($secondaryCta); ?></a>
                <a class="btn btn-lime" href="#contact">Contact Us</a>
            </div>
        </div>
    </header>

    <main id="top">
        <section class="hero">
            <div class="container hero-grid">
                <div>
                    <div class="badge"><?php echo school_profile_e($landingBadge); ?></div>
                    <h1 class="hero-title">
                        <span class="marker"><?php echo school_profile_e($landingHeadline); ?></span>
                    </h1>
                    <p class="hero-copy"><?php echo school_profile_e($landingSubheadline); ?></p>
                    <div class="hero-buttons">
                        <?php if ($enrollmentStatus === 'open'): ?>
                            <a class="btn btn-lime" href="#admission"><?php echo school_profile_e($primaryCta); ?></a>
                        <?php endif; ?>
                        <a class="btn btn-light" href="<?php echo school_profile_e($loginUrl); ?>"><?php echo school_profile_e($secondaryCta); ?></a>
                    </div>
                    <div class="mini-proof">
                        <div class="avatar-stack">
                            <?php foreach (array_slice($circleImages, 1, 3) as $image): ?>
                                <img src="<?php echo school_profile_e($image); ?>" alt="">
                            <?php endforeach; ?>
                            <span class="avatar-initial"><?php echo school_profile_e($schoolInitials); ?></span>
                        </div>
                        <span><?php echo school_profile_e($curriculum); ?> curriculum - <?php echo number_format((int) $stats['students']); ?> learners supported</span>
                    </div>
                </div>

                <div class="hero-art" aria-hidden="true">
                    <div class="doodle doodle-arc"></div>
                    <div class="doodle doodle-shapes">
                        <span class="shape one"></span>
                        <span class="shape two"></span>
                        <span class="shape three"></span>
                    </div>
                    <div class="circle-photo circle-main"><img src="<?php echo school_profile_e($circleImages[0]); ?>" alt=""></div>
                    <div class="circle-photo circle-top"><img src="<?php echo school_profile_e($circleImages[1]); ?>" alt=""></div>
                    <div class="circle-photo circle-mid"><img src="<?php echo school_profile_e($circleImages[2]); ?>" alt=""></div>
                    <div class="circle-photo circle-low"><img src="<?php echo school_profile_e($circleImages[3]); ?>" alt=""></div>
                    <div class="student-cutout"><img src="<?php echo school_profile_e($circleImages[4]); ?>" alt=""></div>
                </div>
            </div>
        </section>

        <div class="container stats-strip" aria-label="School statistics">
            <div class="stat"><strong><?php echo number_format((int) $stats['students']); ?></strong><span>Students</span></div>
            <div class="stat"><strong><?php echo number_format((int) $stats['teachers']); ?></strong><span>Teachers</span></div>
            <div class="stat"><strong><?php echo number_format((int) $stats['classes']); ?></strong><span>Classes</span></div>
            <div class="stat"><strong><?php echo $stats['subjects'] !== null ? number_format((int) $stats['subjects']) : school_profile_e($curriculum); ?></strong><span><?php echo $stats['subjects'] !== null ? 'Subjects' : 'Curriculum'; ?></span></div>
        </div>

        <section class="section" id="about">
            <div class="container about-grid">
                <div>
                    <div class="section-kicker">About us</div>
                    <h2 class="section-title"><span class="marker"><?php echo school_profile_e($introTitle); ?></span></h2>
                    <div class="about-card" style="margin-top:26px;">
                        <p><?php echo nl2br(school_profile_e($introText)); ?></p>
                        <?php if ($mission || $vision): ?>
                            <div class="mission-grid">
                                <?php if ($mission): ?>
                                    <div class="mini-card">
                                        <h3>Mission</h3>
                                        <p><?php echo nl2br(school_profile_e($mission)); ?></p>
                                    </div>
                                <?php endif; ?>
                                <?php if ($vision): ?>
                                    <div class="mini-card">
                                        <h3>Vision</h3>
                                        <p><?php echo nl2br(school_profile_e($vision)); ?></p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <aside class="portrait-card">
                    <img src="<?php echo school_profile_e($featureImage); ?>" alt="<?php echo school_profile_e($schoolName); ?> learning environment">
                    <h3><?php echo school_profile_e($schoolType); ?></h3>
                    <p><?php echo school_profile_e($location ?: $school['address'] ?? 'School campus'); ?></p>
                </aside>
            </div>
        </section>

        <section class="section section-soft" id="programs">
            <div class="container">
                <div class="section-heading">
                    <div>
                        <div class="section-kicker">Subjects and activities</div>
                        <h2 class="section-title"><?php echo school_profile_e($highlightTitle); ?></h2>
                    </div>
                    <p class="section-note"><?php echo school_profile_e($highlightText); ?></p>
                </div>
            </div>
            <div class="program-grid">
                <?php foreach (array_slice($programs, 0, 6) as $index => $program): ?>
                    <?php
                        $programTitle = is_array($program) ? ($program['title'] ?? $program['name'] ?? '') : $program;
                        $programDescription = is_array($program) ? ($program['description'] ?? '') : '';
                    ?>
                    <article class="program-card">
                        <div>
                            <h3><?php echo school_profile_e($programTitle); ?></h3>
                            <p><?php echo school_profile_e($programDescription ?: 'A focused part of the school learning experience.'); ?></p>
                        </div>
                        <span class="arrow-btn">-></span>
                        <?php if ($index < 2): ?>
                            <div class="program-image"><img src="<?php echo school_profile_e($index === 0 ? $featureImage : $heroImage); ?>" alt=""></div>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="section section-violet teacher-strip" id="life">
            <div class="container">
                <div class="section-heading">
                    <div>
                        <div class="section-kicker">Teachers and school life</div>
                        <h2 class="section-title">Learning support that feels human</h2>
                    </div>
                    <p class="section-note">Parents see the difference when teachers, routines, and activities work together.</p>
                </div>
                <div class="teacher-row">
                    <article class="teacher-card">
                        <img src="/tenant/assets/images/thumbs/top-teacher-img1.png" alt="">
                        <div class="teacher-caption"><strong>Class Teachers</strong><span><?php echo number_format((int) $stats['teachers']); ?> teachers</span></div>
                    </article>
                    <article class="teacher-card">
                        <img src="/tenant/assets/images/thumbs/top-teacher-img3.png" alt="">
                        <div class="teacher-caption"><strong>Academic Team</strong><span><?php echo school_profile_e($curriculum); ?> curriculum</span></div>
                    </article>
                    <article class="teacher-card">
                        <img src="/tenant/assets/images/thumbs/top-teacher-img5.png" alt="">
                        <div class="teacher-caption"><strong>Student Support</strong><span>Guidance and care</span></div>
                    </article>
                </div>
            </div>
        </section>

        <section class="section">
            <div class="container testimonial-wrap">
                <div class="testimonial-feature">
                    <blockquote>"<?php echo school_profile_e($testimonials[0]['quote'] ?? 'Parents trust the care, structure, and attention their children receive here.'); ?>"</blockquote>
                    <div style="margin-top:18px;font-weight:900;color:var(--violet);"><?php echo school_profile_e($testimonials[0]['name'] ?? 'Parent'); ?></div>
                    <div style="color:var(--muted);font-weight:800;"><?php echo school_profile_e($testimonials[0]['role'] ?? 'Parent'); ?></div>
                    <img src="<?php echo school_profile_e($circleImages[2]); ?>" alt="">
                </div>
                <div>
                    <div class="section-kicker">Testimonials</div>
                    <h2 class="section-title"><span class="marker">Learning made fun, what parents say about <?php echo school_profile_e($schoolName); ?></span></h2>
                    <div class="testimonial-list" style="margin-top:26px;">
                        <?php foreach (array_slice($testimonials, 0, 4) as $testimonial): ?>
                            <div class="testimonial-name"><?php echo school_profile_e($testimonial['name'] ?? 'Parent'); ?></div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </section>

        <?php if ($announcementsAndEvents): ?>
            <section class="section section-soft">
                <div class="container">
                    <div class="section-heading">
                        <div>
                            <div class="section-kicker">Latest updates</div>
                            <h2 class="section-title">Announcements and events</h2>
                        </div>
                    </div>
                    <div class="updates-grid">
                        <?php foreach (array_slice($announcementsAndEvents, 0, 3) as $item): ?>
                            <article class="update-card">
                                <small><?php echo school_profile_e($item['label']); ?> <?php echo $item['date'] ? '- ' . school_profile_e($item['date']) : ''; ?></small>
                                <h3><?php echo school_profile_e($item['title']); ?></h3>
                                <p><?php echo school_profile_e($item['description']); ?></p>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>
        <?php endif; ?>

        <?php /* =============== FACILITIES & SERVICES =============== */ ?>
        <?php if ($facilities): ?>
            <section class="section" id="facilities">
                <div class="container">
                    <div class="section-heading">
                        <div>
                            <div class="section-kicker">What we offer</div>
                            <h2 class="section-title">Facilities &amp; services</h2>
                        </div>
                        <p class="section-note">The spaces, equipment, and supports that make day-to-day learning here unique.</p>
                    </div>
                    <div class="program-grid" style="margin-top:24px;">
                        <?php foreach ($facilities as $facility): ?>
                            <article class="program-card">
                                <div>
                                    <?php if (!empty($facility['icon'])): ?>
                                        <i class="<?php echo school_profile_e($facility['icon']); ?>" style="font-size:24px;color:var(--violet);margin-bottom:8px;display:block"></i>
                                    <?php endif; ?>
                                    <h3><?php echo school_profile_e($facility['title']); ?></h3>
                                    <?php if (!empty($facility['description'])): ?>
                                        <p><?php echo school_profile_e($facility['description']); ?></p>
                                    <?php endif; ?>
                                    <?php if (!empty($facility['tag'])): ?>
                                        <span style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);"><?php echo school_profile_e($facility['tag']); ?></span>
                                    <?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>
        <?php endif; ?>

        <?php /* =============== PHOTO GALLERY =============== */ ?>
        <?php if ($gallery): ?>
            <section class="section section-soft" id="gallery">
                <div class="container">
                    <div class="section-heading">
                        <div>
                            <div class="section-kicker">School life in pictures</div>
                            <h2 class="section-title">Photo gallery</h2>
                        </div>
                        <p class="section-note">A glimpse into classrooms, events, sports, and everyday moments at <?php echo school_profile_e($schoolName); ?>.</p>
                    </div>
                    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:14px;margin-top:28px;">
                        <?php foreach ($gallery as $img):
                            $imgUrl = school_profile_asset_url((string) ($img['image_url'] ?? ''));
                            if ($imgUrl === '') continue; ?>
                            <figure style="margin:0;border-radius:16px;overflow:hidden;background:#fff;border:1px solid var(--line);">
                                <img src="<?php echo school_profile_e($imgUrl); ?>" alt="<?php echo school_profile_e($img['caption'] ?? ''); ?>" loading="lazy" style="width:100%;height:200px;object-fit:cover;display:block;">
                                <?php if (!empty($img['caption'])): ?>
                                    <figcaption style="padding:10px 14px;font-size:13px;color:var(--muted);"><?php echo school_profile_e($img['caption']); ?></figcaption>
                                <?php endif; ?>
                            </figure>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>
        <?php endif; ?>

        <?php /* =============== PARENT REVIEWS =============== */ ?>
        <section class="section" id="reviews">
            <div class="container">
                <div class="section-heading">
                    <div>
                        <div class="section-kicker">Parent voices</div>
                        <h2 class="section-title">What parents say</h2>
                    </div>
                    <p class="section-note">Honest experiences from families in the <?php echo school_profile_e($schoolName); ?> community.</p>
                </div>

                <?php if ($reviews): ?>
                    <div class="program-grid" style="margin-top:28px;">
                        <?php foreach ($reviews as $review):
                            $rating = max(0, min(5, (int) ($review['rating'] ?? 0))); ?>
                            <article class="program-card">
                                <div>
                                    <div style="color:#f6b333;font-size:18px;letter-spacing:2px;"><?php echo str_repeat('★', $rating) . str_repeat('☆', 5 - $rating); ?></div>
                                    <p style="font-style:italic;margin:10px 0;"><?php echo school_profile_e($review['comment'] ?? ''); ?></p>
                                    <div style="font-weight:700;color:var(--violet);"><?php echo school_profile_e($review['parent_name'] ?? 'Parent'); ?></div>
                                    <?php if (!empty($review['student_name'])): ?>
                                        <div style="font-size:12px;color:var(--muted);">parent of <?php echo school_profile_e($review['student_name']); ?></div>
                                    <?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p style="color:var(--muted);margin-top:20px;">Be the first to leave a review.</p>
                <?php endif; ?>

                <div style="margin-top:48px;background:#fff;border:1px solid var(--line);border-radius:20px;padding:28px;max-width:680px;">
                    <h3 style="margin-top:0;">Share your experience</h3>
                    <p style="color:var(--muted);font-size:14px;">Reviews are read by the school and published once approved.</p>

                    <?php if (!empty($reviewSuccess)): ?>
                        <div style="padding:12px 16px;border-radius:10px;background:#e8f7ec;color:#1f6c33;border:1px solid #b6dfc3;margin-bottom:16px;">
                            Thank you — your review has been submitted for moderation.
                        </div>
                    <?php elseif (!empty($reviewError)): ?>
                        <div style="padding:12px 16px;border-radius:10px;background:#fdecea;color:#a3271a;border:1px solid #f3c2bb;margin-bottom:16px;">
                            <?php echo school_profile_e($reviewError); ?>
                        </div>
                    <?php endif; ?>

                    <form method="post" action="#reviews" style="display:grid;gap:12px;">
                        <input type="hidden" name="csrf_token" value="<?php echo school_profile_e($csrfToken); ?>">
                        <input type="hidden" name="review_submit" value="1">
                        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;">
                            <label>Your name *
                                <input type="text" name="review_parent_name" required maxlength="120" style="display:block;width:100%;margin-top:4px;padding:10px;border:1px solid var(--line);border-radius:10px;">
                            </label>
                            <label>Student's name (optional)
                                <input type="text" name="review_student_name" maxlength="120" style="display:block;width:100%;margin-top:4px;padding:10px;border:1px solid var(--line);border-radius:10px;">
                            </label>
                        </div>
                        <label>Rating *
                            <select name="review_rating" required style="display:block;width:200px;margin-top:4px;padding:10px;border:1px solid var(--line);border-radius:10px;">
                                <option value="">Select rating</option>
                                <option value="5">★★★★★ — Excellent</option>
                                <option value="4">★★★★☆ — Very good</option>
                                <option value="3">★★★☆☆ — Good</option>
                                <option value="2">★★☆☆☆ — Fair</option>
                                <option value="1">★☆☆☆☆ — Poor</option>
                            </select>
                        </label>
                        <label>Your review *
                            <textarea name="review_comment" required maxlength="1000" rows="4" style="display:block;width:100%;margin-top:4px;padding:10px;border:1px solid var(--line);border-radius:10px;font-family:inherit;"></textarea>
                        </label>
                        <button type="submit" style="background:var(--violet);color:#fff;border:0;padding:12px 24px;border-radius:10px;font-weight:600;cursor:pointer;justify-self:start;">Submit review</button>
                    </form>
                </div>
            </div>
        </section>

        <?php /* =============== ALL CONTACTS =============== */ ?>
        <?php if ($contacts): ?>
            <section class="section section-soft" id="contact">
                <div class="container">
                    <div class="section-heading">
                        <div>
                            <div class="section-kicker">Get in touch</div>
                            <h2 class="section-title">Contact <?php echo school_profile_e($schoolName); ?></h2>
                        </div>
                        <p class="section-note">All the ways to reach the school office.</p>
                    </div>
                    <div class="updates-grid" style="margin-top:24px;">
                        <?php foreach ($contacts as $contact):
                            $value = (string) ($contact['value'] ?? '');
                            if ($value === '') continue;
                            $type = (string) ($contact['type'] ?? 'phone');
                            $label = (string) ($contact['label'] ?? ucfirst($type));
                            $href = match ($type) {
                                'email'   => 'mailto:' . $value,
                                'phone'   => 'tel:' . preg_replace('/[^0-9+]/', '', $value),
                                'whatsapp'=> 'https://wa.me/' . preg_replace('/[^0-9]/', '', $value),
                                'website','social' => (preg_match('#^https?://#i', $value) ? $value : 'https://' . $value),
                                default   => null,
                            }; ?>
                            <article class="update-card" style="<?php echo !empty($contact['is_primary']) ? 'border-color:var(--violet);' : ''; ?>">
                                <small style="text-transform:uppercase;letter-spacing:.05em;"><?php echo school_profile_e($type); ?></small>
                                <h3 style="margin:6px 0;"><?php echo school_profile_e($label); ?></h3>
                                <?php if ($href): ?>
                                    <p><a href="<?php echo school_profile_e($href); ?>" style="color:var(--violet);"><?php echo school_profile_e($value); ?></a></p>
                                <?php else: ?>
                                    <p><?php echo school_profile_e($value); ?></p>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>
        <?php endif; ?>

        <section class="section" id="admission">
            <div class="container admission-shell">
                <div class="cta-panel">
                    <h2 class="cta-title"><?php echo school_profile_e($ctaTitle); ?></h2>
                    <p><?php echo school_profile_e($ctaText); ?></p>
                    <?php if (!empty($school['admission_deadline'])): ?>
                        <p><strong>Admission deadline:</strong> <?php echo school_profile_e(school_profile_format_date($school['admission_deadline'])); ?></p>
                    <?php endif; ?>
                    <div class="cta-floating one"><img src="<?php echo school_profile_e($circleImages[1]); ?>" alt=""></div>
                    <div class="cta-floating two"><img src="<?php echo school_profile_e($circleImages[3]); ?>" alt=""></div>
                </div>

                <div>
                    <?php if ($enrollmentSuccess): ?>
                        <div class="alert alert-success">Application submitted successfully<?php echo $requestNumber ? '. Reference: ' . school_profile_e($requestNumber) : ''; ?>.</div>
                    <?php endif; ?>
                    <?php if ($enrollmentError): ?>
                        <div class="alert alert-error"><?php echo $enrollmentError; ?></div>
                    <?php endif; ?>

                    <?php if ($enrollmentStatus === 'open'): ?>
                        <form class="form-panel" method="POST" action="#admission" enctype="multipart/form-data">
                            <?php if ($csrfToken): ?>
                                <input type="hidden" name="csrf_token" value="<?php echo school_profile_e($csrfToken); ?>">
                            <?php endif; ?>
                            <input type="hidden" name="enrollment_submit" value="1">
                            <div class="form-grid">
                                <div class="field"><label for="parent_first_name">Parent First Name *</label><input id="parent_first_name" name="parent_first_name" required></div>
                                <div class="field"><label for="parent_last_name">Parent Last Name *</label><input id="parent_last_name" name="parent_last_name" required></div>
                                <div class="field"><label for="parent_email">Parent Email *</label><input id="parent_email" type="email" name="parent_email" required></div>
                                <div class="field"><label for="parent_phone">Parent Phone *</label><input id="parent_phone" name="parent_phone" required></div>
                                <div class="field full"><label for="parent_address">Parent Address</label><textarea id="parent_address" name="parent_address"></textarea></div>
                                <div class="field"><label for="student_first_name">Student First Name *</label><input id="student_first_name" name="student_first_name" required></div>
                                <div class="field"><label for="student_last_name">Student Last Name *</label><input id="student_last_name" name="student_last_name" required></div>
                                <div class="field"><label for="student_gender">Gender *</label><select id="student_gender" name="student_gender" required><option value="">Select gender</option><option value="male">Male</option><option value="female">Female</option><option value="other">Other</option></select></div>
                                <div class="field"><label for="student_dob">Date of Birth *</label><input id="student_dob" type="date" name="student_dob" required></div>
                                <div class="field"><label for="student_grade">Preferred Class *</label><input id="student_grade" name="student_grade" placeholder="Example: JSS 1" required></div>
                                <div class="field"><label for="student_previous_school">Previous School</label><input id="student_previous_school" name="student_previous_school"></div>
                                <div class="field"><label for="enrollment_type">Enrollment Type</label><select id="enrollment_type" name="enrollment_type"><option value="new">New student</option><option value="transfer">Transfer</option><option value="re_enrollment">Re-enrollment</option></select></div>
                                <div class="field"><label for="academic_year">Academic Year *</label><input id="academic_year" name="academic_year" value="<?php echo school_profile_e(date('Y') . '/' . (date('Y') + 1)); ?>" required></div>
                                <div class="field"><label for="academic_term">Academic Term</label><input id="academic_term" name="academic_term" placeholder="First term"></div>
                                <div class="field"><label for="documents">Documents</label><input id="documents" type="file" name="documents[]" multiple accept=".pdf,.jpg,.jpeg,.png"></div>
                                <div class="field full"><label for="special_requirements">Special Requirements</label><textarea id="special_requirements" name="special_requirements" placeholder="Medical notes, learning support needs, or additional information."></textarea></div>
                            </div>
                            <div style="margin-top:20px;"><button class="btn btn-dark" type="submit">Submit Application</button></div>
                        </form>
                    <?php else: ?>
                        <div class="empty">Admissions are currently <?php echo school_profile_e(str_replace('_', ' ', $enrollmentStatus)); ?>. Please contact the school office.</div>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <section class="section section-soft" id="contact">
            <div class="container">
                <div class="section-heading">
                    <div>
                        <div class="section-kicker">Contact us</div>
                        <h2 class="section-title">Visit, call, or send a message</h2>
                    </div>
                    <p class="section-note">Use these details for admissions, parent support, and school visits.</p>
                </div>
                <div class="contact-grid">
                    <?php if ($contacts): ?>
                        <?php foreach ($contacts as $contact): ?>
                            <?php
                                $type = (string) ($contact['type'] ?? 'contact');
                                $label = $contact['label'] ?: ucwords($type);
                                $value = (string) ($contact['value'] ?? '');
                                $href = '';
                                if ($type === 'email') {
                                    $href = 'mailto:' . $value;
                                } elseif ($type === 'phone') {
                                    $href = 'tel:' . preg_replace('/[^0-9+]/', '', $value);
                                } elseif ($type === 'website' && $value !== '') {
                                    $href = preg_match('#^https?://#i', $value) ? $value : 'https://' . $value;
                                }
                            ?>
                            <article class="contact-card">
                                <small><?php echo school_profile_e($label); ?></small>
                                <?php if ($href): ?>
                                    <a href="<?php echo school_profile_e($href); ?>"><?php echo school_profile_e($value); ?></a>
                                <?php else: ?>
                                    <p><?php echo nl2br(school_profile_e($value)); ?></p>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty">Contact details have not been published yet.</div>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    </main>

    <footer class="footer">
        <div class="container footer-grid">
            <div>
                <h3><?php echo school_profile_e($schoolName); ?></h3>
                <p><?php echo school_profile_e($landingSubheadline); ?></p>
            </div>
            <div>
                <h4>Home</h4>
                <p><a href="#about">About us</a></p>
                <p><a href="#programs">Subjects</a></p>
            </div>
            <div>
                <h4>Admission</h4>
                <p><a href="#admission">Apply now</a></p>
                <p><a href="<?php echo school_profile_e($loginUrl); ?>">Portal login</a></p>
            </div>
            <div>
                <h4>Contact</h4>
                <p><?php echo school_profile_e($school['phone'] ?? ''); ?></p>
                <p><?php echo school_profile_e($school['email'] ?? ''); ?></p>
            </div>
        </div>
    </footer>
</body>
</html>
