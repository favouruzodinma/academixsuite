<?php
/**
 * School Management Hub
 * Comprehensive school management with vertical navigation and professional UI
 */

// Error logging setup
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../../logs/school_management.log');

require_once __DIR__ . '/../../../includes/autoload.php';
if (session_status() === PHP_SESSION_NONE) {
    session_name('academix_tenant');
    session_start(function_exists('academix_session_options') ? academix_session_options() : []);
}

// Get school slug from router globals
$schoolSlug = $GLOBALS['SCHOOL_SLUG'] ?? '';
$schoolData = $GLOBALS['SCHOOL_DATA'] ?? [];

if (empty($schoolSlug)) {
    header('HTTP/1.1 400 Bad Request');
    exit('School identifier missing');
}

// Load school info
$school = $schoolData;
if (empty($school) && isset($_SESSION['school_info'][$schoolSlug])) {
    $school = $_SESSION['school_info'][$schoolSlug];
}
if (empty($school)) {
    $loginUrl = function_exists('school_login_url') ? school_login_url($schoolSlug, false) : '../../login.php?school_slug=' . urlencode($schoolSlug);
    header("Location: {$loginUrl}");
    exit;
}

// Authentication check
if (empty($_SESSION['school_auth']) || $_SESSION['school_auth']['school_slug'] !== $schoolSlug) {
    $loginUrl = function_exists('school_login_url') ? school_login_url($schoolSlug, false) : '../../login.php?school_slug=' . urlencode($schoolSlug);
    header("Location: {$loginUrl}");
    exit;
}

$userId = $_SESSION['school_auth']['user_id'] ?? 0;
$userType = $_SESSION['school_auth']['user_type'] ?? '';
if ($userType !== 'admin') {
    header('HTTP/1.1 403 Forbidden');
    exit('Access denied. Admin privileges required.');
}

// Database connections
$platformDb = Database::getPlatformConnection();
$schoolDb = null;
try {
    if (!empty($school['database_name'])) {
        $schoolDb = Database::getSchoolConnection($school['database_name']);
    }
} catch (Throwable $e) {
    error_log('General settings school database unavailable: ' . $e->getMessage());
}

// Include SchoolActionManager
require_once __DIR__ . '/../../../includes/SchoolActionManager.php';
require_once __DIR__ . '/../../../includes/Services/WhatsAppService.php';
$manager = new SchoolActionManager($platformDb, $schoolDb, $school['id'], $schoolSlug, $userId);

// Page-scoped AJAX CSRF token.
//
// The global generateCsrfToken()/validateCsrfToken() helper is intentionally
// one-time-use. This page makes many AJAX calls without a full refresh, so it
// needs a stable session token for this editor surface.
if (!function_exists('academix_general_csrf_token')) {
    function academix_general_csrf_token(): string {
        if (empty($_SESSION['general_page_csrf_token'])) {
            $_SESSION['general_page_csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['general_page_csrf_token'];
    }
}
if (!function_exists('academix_general_validate_csrf_token')) {
    function academix_general_validate_csrf_token($token): bool {
        $token = is_string($token) ? $token : '';
        if ($token === '') {
            return false;
        }

        foreach (['general_page_csrf_token', 'csrf_token', 'admin_csrf_token'] as $sessionKey) {
            $sessionToken = $_SESSION[$sessionKey] ?? null;
            if (is_string($sessionToken) && $sessionToken !== '' && hash_equals($sessionToken, $token)) {
                return true;
            }
        }

        if (!empty($_SESSION['csrf_tokens']) && is_array($_SESSION['csrf_tokens'])) {
            if (isset($_SESSION['csrf_tokens'][$token])) {
                $expiry = $_SESSION['csrf_tokens'][$token];
                return !is_numeric($expiry) || (int)$expiry >= time();
            }
            foreach ($_SESSION['csrf_tokens'] as $csrfTokenData) {
                if (is_array($csrfTokenData) && isset($csrfTokenData['token'], $csrfTokenData['expiry'])) {
                    if ((int)$csrfTokenData['expiry'] >= time() && hash_equals((string)$csrfTokenData['token'], $token)) {
                        return true;
                    }
                }
            }
        }

        $host = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''));
        $originHost = strtolower((string)parse_url($_SERVER['HTTP_ORIGIN'] ?? '', PHP_URL_HOST));
        $refererHost = strtolower((string)parse_url($_SERVER['HTTP_REFERER'] ?? '', PHP_URL_HOST));
        $sameOrigin = ($originHost !== '' && $originHost === $host) || ($refererHost !== '' && $refererHost === $host);
        $isAjax = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';

        return $isAjax && $sameOrigin && (bool)preg_match('/^[a-f0-9]{32,128}$/i', $token);
    }
}
$csrfToken = academix_general_csrf_token();

// Handle AJAX requests for CRUD operations
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
    header('Content-Type: application/json');
    if (!academix_general_validate_csrf_token($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
        exit;
    }
    $action = $_POST['action'] ?? '';
    $response = ['success' => false, 'message' => 'Invalid action'];

    switch ($action) {
        // Academic Years
        case 'create_academic_year':
            $data = [
                'name' => $_POST['name'] ?? '',
                'start_date' => $_POST['start_date'] ?? '',
                'end_date' => $_POST['end_date'] ?? '',
                'is_default' => isset($_POST['is_default']) ? 1 : 0,
                'status' => $_POST['status'] ?? 'upcoming'
            ];
            $response = $manager->createAcademicYear($data);
            break;
        case 'get_academic_year':
            $id = (int)($_POST['id'] ?? 0);
            $data = $manager->getAcademicYearById($id);
            $response = $data ? ['success' => true, 'data' => $data] : ['success' => false, 'message' => 'Not found'];
            break;
        case 'update_academic_year':
            $id = (int)($_POST['id'] ?? 0);
            $data = [
                'name' => $_POST['name'] ?? '',
                'start_date' => $_POST['start_date'] ?? '',
                'end_date' => $_POST['end_date'] ?? '',
                'is_default' => isset($_POST['is_default']) ? 1 : 0,
                'status' => $_POST['status'] ?? 'upcoming'
            ];
            $response = $manager->updateAcademicYear($id, $data);
            break;
        case 'delete_academic_year':
            $id = (int)($_POST['id'] ?? 0);
            $response = $manager->deleteAcademicYear($id);
            break;

        // Academic Terms
        case 'create_academic_term':
            $data = [
                'name' => $_POST['name'] ?? '',
                'academic_year_id' => (int)($_POST['academic_year_id'] ?? 0),
                'start_date' => $_POST['start_date'] ?? '',
                'end_date' => $_POST['end_date'] ?? '',
                'is_default' => isset($_POST['is_default']) ? 1 : 0
            ];
            $response = $manager->createAcademicTerm($data);
            break;
        case 'get_academic_term':
            $id = (int)($_POST['id'] ?? 0);
            $data = $manager->getAcademicTermById($id);
            $response = $data ? ['success' => true, 'data' => $data] : ['success' => false, 'message' => 'Not found'];
            break;
        case 'update_academic_term':
            $id = (int)($_POST['id'] ?? 0);
            $data = [
                'name' => $_POST['name'] ?? '',
                'academic_year_id' => (int)($_POST['academic_year_id'] ?? 0),
                'start_date' => $_POST['start_date'] ?? '',
                'end_date' => $_POST['end_date'] ?? '',
                'is_default' => isset($_POST['is_default']) ? 1 : 0
            ];
            $response = $manager->updateAcademicTerm($id, $data);
            break;
        case 'delete_academic_term':
            $id = (int)($_POST['id'] ?? 0);
            $response = $manager->deleteAcademicTerm($id);
            break;

        // Classes
        case 'create_class':
            $data = [
                'name' => $_POST['name'] ?? '',
                'code' => $_POST['code'] ?? '',
                'academic_year_id' => (int)($_POST['academic_year_id'] ?? 0),
                'grade_level' => $_POST['grade_level'] ?? null,
                'capacity' => (int)($_POST['capacity'] ?? 40),
                'room_number' => $_POST['room_number'] ?? null,
                'description' => $_POST['description'] ?? null,
                'class_teacher_id' => !empty($_POST['class_teacher_id']) ? (int)$_POST['class_teacher_id'] : null
            ];
            $response = $manager->createClass($data);
            break;
        case 'get_class':
            $id = (int)($_POST['id'] ?? 0);
            $data = $manager->getClassById($id);
            $response = $data ? ['success' => true, 'data' => $data] : ['success' => false, 'message' => 'Not found'];
            break;
        case 'update_class':
            $id = (int)($_POST['id'] ?? 0);
            $data = [
                'name' => $_POST['name'] ?? '',
                'code' => $_POST['code'] ?? '',
                'academic_year_id' => (int)($_POST['academic_year_id'] ?? 0),
                'grade_level' => $_POST['grade_level'] ?? null,
                'capacity' => (int)($_POST['capacity'] ?? 40),
                'room_number' => $_POST['room_number'] ?? null,
                'description' => $_POST['description'] ?? null,
                'class_teacher_id' => !empty($_POST['class_teacher_id']) ? (int)$_POST['class_teacher_id'] : null
            ];
            $response = $manager->updateClass($id, $data);
            break;
        case 'delete_class':
            $id = (int)($_POST['id'] ?? 0);
            $response = $manager->deleteClass($id);
            break;

        // Sections
        case 'create_section':
            $data = [
                'name' => $_POST['name'] ?? '',
                'code' => $_POST['code'] ?? '',
                'class_id' => (int)($_POST['class_id'] ?? 0),
                'capacity' => (int)($_POST['capacity'] ?? 40),
                'room_number' => $_POST['room_number'] ?? null,
                'class_teacher_id' => !empty($_POST['class_teacher_id']) ? (int)$_POST['class_teacher_id'] : null
            ];
            $response = $manager->createSection($data);
            break;
        case 'get_section':
            $id = (int)($_POST['id'] ?? 0);
            $data = $manager->getSectionById($id);
            $response = $data ? ['success' => true, 'data' => $data] : ['success' => false, 'message' => 'Not found'];
            break;
        case 'update_section':
            $id = (int)($_POST['id'] ?? 0);
            $data = [
                'name' => $_POST['name'] ?? '',
                'code' => $_POST['code'] ?? '',
                'class_id' => (int)($_POST['class_id'] ?? 0),
                'capacity' => (int)($_POST['capacity'] ?? 40),
                'room_number' => $_POST['room_number'] ?? null,
                'class_teacher_id' => !empty($_POST['class_teacher_id']) ? (int)$_POST['class_teacher_id'] : null
            ];
            $response = $manager->updateSection($id, $data);
            break;
        case 'delete_section':
            $id = (int)($_POST['id'] ?? 0);
            $response = $manager->deleteSection($id);
            break;

        // Subjects
        case 'create_subject':
            $data = [
                'name' => $_POST['name'] ?? '',
                'code' => $_POST['code'] ?? '',
                'type' => $_POST['type'] ?? 'core',
                'credit_hours' => (float)($_POST['credit_hours'] ?? 1.0),
                'description' => $_POST['description'] ?? null
            ];
            $response = $manager->createSubject($data);
            break;
        case 'get_subject':
            $id = (int)($_POST['id'] ?? 0);
            $data = $manager->getSubjectById($id);
            $response = $data ? ['success' => true, 'data' => $data] : ['success' => false, 'message' => 'Not found'];
            break;
        case 'update_subject':
            $id = (int)($_POST['id'] ?? 0);
            $data = [
                'name' => $_POST['name'] ?? '',
                'code' => $_POST['code'] ?? '',
                'type' => $_POST['type'] ?? 'core',
                'credit_hours' => (float)($_POST['credit_hours'] ?? 1.0),
                'description' => $_POST['description'] ?? null
            ];
            $response = $manager->updateSubject($id, $data);
            break;
        case 'delete_subject':
            $id = (int)($_POST['id'] ?? 0);
            $response = $manager->deleteSubject($id);
            break;

        // Subject Assignments
        case 'assign_subject':
            $data = [
                'class_id' => (int)($_POST['class_id'] ?? 0),
                'subject_id' => (int)($_POST['subject_id'] ?? 0),
                'teacher_id' => !empty($_POST['teacher_id']) ? (int)$_POST['teacher_id'] : null
            ];
            $response = $manager->assignSubjectToClass($data);
            break;
        case 'delete_assignment':
            $id = (int)($_POST['id'] ?? 0);
            $response = $manager->deleteSubjectAssignment($id);
            break;

        // Payment Methods
        case 'create_payment_method':
            $data = [
                'type' => $_POST['type'] ?? '',
                'provider' => $_POST['provider'] ?? null,
                'last_four' => $_POST['last_four'] ?? null,
                'exp_month' => !empty($_POST['exp_month']) ? (int)$_POST['exp_month'] : null,
                'exp_year' => !empty($_POST['exp_year']) ? (int)$_POST['exp_year'] : null,
                'is_default' => isset($_POST['is_default']) ? 1 : 0,
                'is_verified' => isset($_POST['is_verified']) ? 1 : 0,
                'metadata' => [
                    'account_name' => $_POST['account_name'] ?? null,
                    'account_number' => $_POST['account_number'] ?? null
                ]
            ];
            $response = $manager->createPaymentMethod($data);
            break;
        case 'get_payment_method':
            $id = (int)($_POST['id'] ?? 0);
            $data = $manager->getPaymentMethodById($id);
            $response = $data ? ['success' => true, 'data' => $data] : ['success' => false, 'message' => 'Not found'];
            break;
        case 'update_payment_method':
            $id = (int)($_POST['id'] ?? 0);
            $data = [
                'type' => $_POST['type'] ?? '',
                'provider' => $_POST['provider'] ?? null,
                'last_four' => $_POST['last_four'] ?? null,
                'exp_month' => !empty($_POST['exp_month']) ? (int)$_POST['exp_month'] : null,
                'exp_year' => !empty($_POST['exp_year']) ? (int)$_POST['exp_year'] : null,
                'is_default' => isset($_POST['is_default']) ? 1 : 0,
                'is_verified' => isset($_POST['is_verified']) ? 1 : 0,
                'metadata' => [
                    'account_name' => $_POST['account_name'] ?? null,
                    'account_number' => $_POST['account_number'] ?? null
                ]
            ];
            $response = $manager->updatePaymentMethod($id, $data);
            break;
        case 'delete_payment_method':
            $id = (int)($_POST['id'] ?? 0);
            $response = $manager->deletePaymentMethod($id);
            break;

        // Fee Categories
        case 'create_fee_category':
            $data = [
                'name' => $_POST['name'] ?? '',
                'description' => $_POST['description'] ?? null
            ];
            $response = $manager->createFeeCategory($data);
            break;
        case 'get_fee_category':
            $id = (int)($_POST['id'] ?? 0);
            $data = $manager->getFeeCategoryById($id);
            $response = $data ? ['success' => true, 'data' => $data] : ['success' => false, 'message' => 'Not found'];
            break;
        case 'update_fee_category':
            $id = (int)($_POST['id'] ?? 0);
            $data = [
                'name' => $_POST['name'] ?? '',
                'description' => $_POST['description'] ?? null
            ];
            $response = $manager->updateFeeCategory($id, $data);
            break;
        case 'delete_fee_category':
            $id = (int)($_POST['id'] ?? 0);
            $response = $manager->deleteFeeCategory($id);
            break;

        // Fee Structures
        case 'create_fee_structure':
            $data = [
                'academic_year_id' => (int)($_POST['academic_year_id'] ?? 0),
                'class_id' => (int)($_POST['class_id'] ?? 0),
                'fee_category_id' => (int)($_POST['fee_category_id'] ?? 0),
                'amount' => (float)($_POST['amount'] ?? 0),
                'due_date' => $_POST['due_date'] ?? null,
                'late_fee' => (float)($_POST['late_fee'] ?? 0),
                'academic_term_id' => !empty($_POST['academic_term_id']) ? (int)$_POST['academic_term_id'] : null
            ];
            $response = $manager->createFeeStructure($data);
            break;
        case 'get_fee_structure':
            $id = (int)($_POST['id'] ?? 0);
            $data = $manager->getFeeStructureById($id);
            $response = $data ? ['success' => true, 'data' => $data] : ['success' => false, 'message' => 'Not found'];
            break;
        case 'update_fee_structure':
            $id = (int)($_POST['id'] ?? 0);
            $data = [
                'academic_year_id' => (int)($_POST['academic_year_id'] ?? 0),
                'class_id' => (int)($_POST['class_id'] ?? 0),
                'fee_category_id' => (int)($_POST['fee_category_id'] ?? 0),
                'amount' => (float)($_POST['amount'] ?? 0),
                'due_date' => $_POST['due_date'] ?? null,
                'late_fee' => (float)($_POST['late_fee'] ?? 0),
                'academic_term_id' => !empty($_POST['academic_term_id']) ? (int)$_POST['academic_term_id'] : null
            ];
            $response = $manager->updateFeeStructure($id, $data);
            break;
        case 'delete_fee_structure':
            $id = (int)($_POST['id'] ?? 0);
            $response = $manager->deleteFeeStructure($id);
            break;

        // Announcements
        case 'create_announcement':
            $data = [
                'title' => $_POST['title'] ?? '',
                'description' => $_POST['description'] ?? '',
                'target' => $_POST['target'] ?? 'all',
                'class_id' => !empty($_POST['class_id']) ? (int)$_POST['class_id'] : null,
                'section_id' => !empty($_POST['section_id']) ? (int)$_POST['section_id'] : null,
                'start_date' => $_POST['start_date'] ?? null,
                'end_date' => $_POST['end_date'] ?? null
            ];
            $response = $manager->createAnnouncement($data);
            break;

        // API Keys
        case 'create_api_key':
            $data = [
                'name' => $_POST['name'] ?? '',
                'rate_limit_per_minute' => (int)($_POST['rate_limit_per_minute'] ?? 60),
                'expires_at' => !empty($_POST['expires_at']) ? $_POST['expires_at'] : null
            ];
            $response = $manager->createApiKey($data);
            break;
        case 'delete_api_key':
            $id = (int)($_POST['id'] ?? 0);
            $response = $manager->deleteApiKey($id);
            break;

        // -----------------------------------------------------------------
        // General Settings — updates the core school record in the platform
        // database so changes are immediately visible on school_profile.php.
        // -----------------------------------------------------------------
        case 'update_general': {
            $data = [];

            // Scalar fields
            $textFields = [
                'name' => 'school_name',      // form field → DB column
                'email'               => 'school_email',
                'phone'               => 'school_phone',
                'website'             => 'website',
                'address'             => 'address',
                'city'                => 'city',
                'state'               => 'state',
                'country'             => 'country',
                'postal_code'         => 'postal_code',
                'timezone'            => 'timezone',
                'currency'            => 'currency',
                'language'            => 'language',
                'school_type'         => 'school_type',
                'curriculum'          => 'curriculum',
                'establishment_year'  => 'establishment_year',
                'principal_name'      => 'principal_name',
                'description'         => 'description',
                'mission_statement'   => 'mission_statement',
                'vision_statement'    => 'vision_statement',
                'principal_message'   => 'principal_message',
                'primary_color'       => 'primary_color',
                'secondary_color'     => 'secondary_color',
            ];

            foreach ($textFields as $dbCol => $postKey) {
                if (array_key_exists($postKey, $_POST)) {
                    $data[$dbCol] = trim((string) $_POST[$postKey]);
                }
            }

            // Social links passed as individual POST fields
            foreach (['facebook','twitter','instagram','linkedin','youtube'] as $net) {
                if (array_key_exists($net, $_POST)) {
                    $data[$net] = trim((string) $_POST[$net]);
                }
            }

            // Logo upload
            $uploadDir = dirname(__DIR__, 3) . '/assets/uploads/schools/' . $school['id'] . '/branding';
            $imageMimes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];

            foreach (['logo' => 'logo_path', 'favicon' => 'favicon_path'] as $fileKey => $dbCol) {
                $f = $_FILES[$fileKey] ?? null;
                if ($f && ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_uploaded_file($f['tmp_name'] ?? '')) {
                    if (($f['size'] ?? 0) <= 5 * 1024 * 1024) {
                        $mime = function_exists('finfo_open')
                            ? (function($p) { $fi = finfo_open(FILEINFO_MIME_TYPE); $m = (string) finfo_file($fi, $p); finfo_close($fi); return $m; })($f['tmp_name'])
                            : (string) mime_content_type($f['tmp_name']);
                        if (isset($imageMimes[$mime])) {
                            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                            $fname = $fileKey . '-' . bin2hex(random_bytes(8)) . '.' . $imageMimes[$mime];
                            $dest  = $uploadDir . '/' . $fname;
                            if (move_uploaded_file($f['tmp_name'], $dest)) {
                                $data[$dbCol] = 'assets/uploads/schools/' . $school['id'] . '/branding/' . $fname;
                            }
                        }
                    }
                }
            }

            $response = $manager->updateSchoolDetails($data);
            break;
        }

        // -----------------------------------------------------------------
        // Public-profile editing (controls tenant/school_profile.php).
        // Reads/writes against the PLATFORM database, scoped to the current
        // school. Loaded from a separate file to keep this controller small.
        // -----------------------------------------------------------------
        case 'profile_save_basics':
        case 'profile_save_contacts':
        case 'profile_save_facilities':
        case 'profile_gallery_add':
        case 'profile_gallery_delete':
        case 'profile_review_toggle':
            $response = require __DIR__ . '/tabs/public_profile_actions.php';
            break;

        // -----------------------------------------------------------------
        // AI Profile Content Generator
        // Uses GroqClient to produce school description, mission, vision,
        // and principal's message from school context + optional user hint.
        // -----------------------------------------------------------------
        case 'generate_profile_content': {
            $field = $_POST['field'] ?? '';
            $hint  = trim($_POST['hint'] ?? '');
            $tone  = $_POST['tone']  ?? 'professional';

            $validFields = [
                // General Settings tab
                'description', 'mission_statement', 'vision_statement', 'principal_message',
                // Public Profile tab — Hero section
                'landing_headline', 'landing_subheadline', 'landing_badge_text',
                'landing_primary_cta_text', 'landing_secondary_cta_text',
                // Public Profile tab — About & Story section
                'landing_intro_title', 'landing_intro_text',
                'landing_highlight_title', 'landing_highlight_text',
                // Public Profile tab — Closing CTA
                'landing_cta_title', 'landing_cta_text',
            ];
            if (!in_array($field, $validFields, true)) {
                $response = ['success' => false, 'message' => 'Invalid field specified.'];
                break;
            }

            require_once __DIR__ . '/../../../includes/GroqClient.php';
            $apiKey = $_ENV['GROQ_API_KEY'] ?? getenv('GROQ_API_KEY') ?? '';
            if (empty($apiKey) || $apiKey === 'gsk-your-key-here') {
                $response = ['success' => false, 'message' => 'Groq AI is not configured. Add GROQ_API_KEY to your .env file.'];
                break;
            }

            // Build school context for the prompt
            $details    = $manager->getSchoolDetails();
            $schoolName = $details['name']               ?? ($school['name'] ?? 'the school');
            $schoolType = $details['school_type']        ?? '';
            $curriculum = $details['curriculum']         ?? '';
            $city       = $details['city']               ?? '';
            $country    = $details['country']            ?? '';
            $estYear    = $details['establishment_year'] ?? '';
            $principal  = $details['principal_name']     ?? '';

            // Field-specific generation instructions
            $fieldLabels = [
                // General Settings
                'description'              => 'a concise and compelling school description (2–3 sentences) for the public profile page',
                'mission_statement'        => 'a clear and purposeful mission statement (1–2 sentences) that captures what the school strives to do every day',
                'vision_statement'         => 'an aspirational vision statement (1–2 sentences) describing where the school aims to be in the future',
                'principal_message'        => 'a warm, professional welcome message from the principal (3–4 sentences) addressed to prospective students and parents',
                // Hero section
                'landing_headline'         => 'a punchy, memorable hero headline (max 10 words) for the school\'s public landing page — something that immediately grabs a parent\'s attention',
                'landing_subheadline'      => 'a compelling sub-headline (1–2 sentences) that expands on the hero headline and invites parents to learn more or apply',
                'landing_badge_text'       => 'a short badge/label (3–6 words) for the hero section, such as admission status or a key highlight — e.g. "Admissions Now Open" or "Top-Ranked Secondary School"',
                'landing_primary_cta_text' => 'a short, action-oriented primary call-to-action button label (2–4 words) — e.g. "Apply Now", "Start Admission", "Enrol Today"',
                'landing_secondary_cta_text' => 'a short secondary call-to-action button label (2–4 words) — e.g. "Take a Tour", "Learn More", "Portal Login"',
                // About & Story
                'landing_intro_title'      => 'a compelling section title (4–8 words) for the "About the school" intro section on the public profile page',
                'landing_intro_text'       => 'an engaging intro paragraph (3–4 sentences) introducing the school\'s story, values, and what makes it stand out to prospective families',
                'landing_highlight_title'  => 'a punchy highlight/achievement section title (4–8 words) showcasing what the school is known for — e.g. "Why Families Choose Us" or "Our Academic Achievements"',
                'landing_highlight_text'   => 'a persuasive highlights paragraph (3–4 sentences) showcasing the school\'s key strengths, achievements, or differentiators that parents care about',
                // Closing CTA
                'landing_cta_title'        => 'a persuasive closing call-to-action heading (5–10 words) encouraging parents to take the next step — e.g. enrol, book a visit, or apply',
                'landing_cta_text'         => 'a short supporting sentence (1–2 sentences) under the closing CTA heading that reinforces urgency or value and encourages action',
            ];

            $toneMap = [
                'professional' => 'Use a professional and authoritative tone.',
                'inspiring'    => 'Use an inspiring and motivational tone that energises readers.',
                'formal'       => 'Use a formal, measured academic tone.',
                'friendly'     => 'Use a warm, friendly, and approachable tone that feels welcoming.',
                'academic'     => 'Use a scholarly and rigorous academic tone.',
            ];

            $toneInstr = $toneMap[$tone] ?? 'Use a professional tone.';

            $contextParts = array_filter([
                "School name: {$schoolName}",
                $schoolType ? "Type: {$schoolType} school"          : '',
                $curriculum ? "Curriculum: {$curriculum}"           : '',
                ($city || $country)
                    ? 'Location: ' . implode(', ', array_filter([$city, $country]))
                    : '',
                $estYear    ? "Established: {$estYear}"             : '',
                $principal  ? "Principal: {$principal}"             : '',
                $hint       ? "Admin's focus/context: {$hint}"      : '',
            ]);

            $systemPrompt = <<<SYSPROMPT
You are a professional copywriter specialising in African school marketing and communications.
Write content that is authentic, culturally appropriate, and tailored to the school's context.
Return ONLY the requested text — no intro phrases, no labels, no quotes, no markdown, no extra commentary.
SYSPROMPT;

            $userPrompt = "Write {$fieldLabels[$field]} for:\n\n"
                        . implode("\n", $contextParts)
                        . "\n\n{$toneInstr}"
                        . "\n\nReturn only the content text.";

            try {
                $groq = new GroqClient($apiKey, $_ENV['GROQ_MODEL'] ?? getenv('GROQ_MODEL') ?: 'llama-3.3-70b-versatile');
                $result = $groq->chat(
                    [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user',   'content' => $userPrompt],
                    ],
                    null,     // no tools
                    'none',   // no tool_choice pressure
                    650
                );

                if (!empty($result['error'])) {
                    $response = ['success' => false, 'message' => $result['error']['message'] ?? 'AI error'];
                } else {
                    $content  = trim($result['choices'][0]['message']['content'] ?? '');
                    // Strip any accidental wrapping quotes
                    $content  = trim($content, '"\'');
                    $response = ['success' => true, 'content' => $content];
                }
            } catch (Throwable $e) {
                error_log('generate_profile_content error: ' . $e->getMessage());
                $response = ['success' => false, 'message' => 'AI service error. Please try again.'];
            }
            break;
        }

        case 'update_whatsapp_settings': {
            if (!$schoolDb) {
                $response = ['success' => false, 'message' => 'School database is unavailable.'];
                break;
            }

            $settings = [
                'whatsapp_enabled' => isset($_POST['whatsapp_enabled']) ? '1' : '0',
                'whatsapp_announcements_enabled' => isset($_POST['whatsapp_announcements_enabled']) ? '1' : '0',
                'whatsapp_events_enabled' => isset($_POST['whatsapp_events_enabled']) ? '1' : '0',
                'whatsapp_fees_enabled' => isset($_POST['whatsapp_fees_enabled']) ? '1' : '0',
                'whatsapp_attendance_enabled' => isset($_POST['whatsapp_attendance_enabled']) ? '1' : '0',
            ];

            WhatsAppService::saveFeatureSettings($schoolDb, (int)$school['id'], $settings, (int)($school['campus_id'] ?? 1));
            $response = ['success' => true, 'message' => 'WhatsApp notification settings saved.'];
            break;
        }

        default:
            $response = ['success' => false, 'message' => 'Unknown action'];
    }
    echo json_encode($response);
    exit;
}

// Load all data via manager
$schoolDetails = $manager->getSchoolDetails();
$studentCount = $manager->getStudentCount();
$teacherCount = $manager->getTeacherCount();
$academicYears = $manager->getAcademicYears();
$academicTerms = $manager->getAcademicTerms();
$classes = $manager->getClasses();
$sections = $manager->getSections();
$subjects = $manager->getSubjects();
$classSubjects = $manager->getClassSubjects();
$paymentMethods = $manager->getPaymentMethods();
$feeCategories = $manager->getFeeCategories();
$feeStructures = $manager->getFeeStructures();
$announcements = $manager->getAnnouncements(10);
$recentActivities = $manager->getRecentActivities(20);
$storageUsage = $manager->getStorageUsage();
$subscriptionInfo = $manager->getSubscriptionInfo();
$apiKeys = $manager->getApiKeys();
$whatsappSettings = $schoolDb
    ? WhatsAppService::getFeatureSettings($schoolDb, (int)$school['id'], true)
    : WhatsAppService::defaultFeatureSettings(false);
$whatsappService = $schoolDb ? new WhatsAppService($schoolDb, $school) : null;
$whatsappConfigured = $whatsappService ? $whatsappService->isConfigured() : false;
$whatsappConfigurationStatus = $whatsappService
    ? $whatsappService->configurationStatus()
    : 'School database unavailable. WhatsApp settings cannot be checked.';

// Helper variables
$currencies = ['NGN', 'GHS', 'KES', 'ZAR', 'USD', 'GBP', 'EUR'];
$languages = [
    'en' => 'English',
    'fr' => 'French',
    'ar' => 'Arabic',
    'sw' => 'Swahili',
    'ha' => 'Hausa',
    'yo' => 'Yoruba',
    'ig' => 'Igbo',
    'pt' => 'Portuguese',
    'es' => 'Spanish',
];
$timezones = [
    'Africa/Lagos', 'Africa/Accra', 'Africa/Nairobi', 'Africa/Johannesburg',
    'Africa/Cairo', 'Europe/London', 'America/New_York', 'America/Chicago',
    'America/Denver', 'America/Los_Angeles', 'Asia/Dubai'
];
$countries = [
    'Nigeria', 'Ghana', 'Kenya', 'South Africa', 'Egypt',
    'Morocco', 'Tunisia', 'Rwanda', 'Uganda', 'Tanzania',
    'United States', 'United Kingdom', 'Canada', 'Australia'
];
$paymentTypes = [
    'bank_transfer' => 'Bank Transfer',
    'card' => 'Card',
    'mobile_money' => 'Mobile Money',
    'wallet' => 'Wallet'
];
$subjectTypes = ['core' => 'Core', 'elective' => 'Elective', 'extra_curricular' => 'Extra Curricular'];

$currencySymbol = $schoolDetails['currency_symbol'] ?? '₦';
$activeTab = $_GET['tab'] ?? 'general';
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="description" content="School Management Hub - Manage all aspects of your school">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>School Management - <?php echo htmlspecialchars($school['name'] ?? 'School'); ?></title>

    <!-- Styles -->
    <link rel="icon" type="image/png" href="https://academixsuite.com/tenant/assets/images/favicon.png" sizes="16x16">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/remixicon.css">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/lib/bootstrap.min.css">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/lib/apexcharts.css">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/lib/dataTables.min.css">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/lib/flatpickr.min.css">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/lib/full-calendar.css">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/lib/calendar.css">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/style.css">

    <style>
        .avatar-preview {
            width: 140px;
            height: 140px;
            border-radius: 50%;
            background-size: cover;
            background-position: center;
            border: 2px solid #dee2e6;
        }
        .preview-image {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            background-size: cover;
            background-position: center;
        }
        .nav-pills .nav-link {
            color: #495057;
            font-weight: 500;
            border-radius: 8px;
            margin-bottom: 5px;
            padding: 12px 20px;
            transition: all 0.2s;
        }
        .nav-pills .nav-link i {
            margin-right: 10px;
            font-size: 1.2rem;
        }
        .nav-pills .nav-link.active {
            background-color: #25A194;
            color: #fff;
        }
        .nav-pills .nav-link:hover:not(.active) {
            background-color: #f8f9fa;
            color: #25A194;
        }
        .form-section-title {
            font-size: 1rem;
            font-weight: 600;
            color: #495057;
            margin-bottom: 1.5rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid #e9ecef;
        }
        .stat-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        .stat-card i {
            font-size: 2.5rem;
            opacity: 0.8;
        }
        .stat-card .stat-value {
            font-size: 1.8rem;
            font-weight: 600;
        }
        .stat-card .stat-label {
            font-size: 0.9rem;
            opacity: 0.9;
        }
        .activity-feed {
            max-height: 400px;
            overflow-y: auto;
        }
        .activity-item {
            padding: 10px;
            border-left: 3px solid #25A194;
            margin-bottom: 10px;
            background: #f8f9fa;
        }
        .activity-time {
            font-size: 0.8rem;
            color: #6c757d;
        }
        .storage-bar {
            height: 10px;
            border-radius: 5px;
            background: #e9ecef;
            margin: 10px 0;
        }
        .storage-bar-fill {
            height: 100%;
            border-radius: 5px;
            background: #25A194;
            transition: width 0.3s ease;
        }
        .badge-success {
            background-color: #28a745;
            color: white;
        }
        .badge-warning {
            background-color: #ffc107;
            color: #212529;
        }
        .badge-danger {
            background-color: #dc3545;
            color: white;
        }
        .badge-info {
            background-color: #17a2b8;
            color: white;
        }
        .table-responsive {
            overflow-x: auto;
        }
        .action-buttons {
            white-space: nowrap;
        }
        .vertical-nav-wrapper {
            background: #fff;
            border-radius: 12px;
            padding: 20px 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            position: sticky;
            top: 90px;
        }
        .content-col {
            padding-left: 30px;
        }
        @media (max-width: 768px) {
            .vertical-nav-wrapper {
                margin-bottom: 30px;
                position: static;
            }
            .content-col {
                padding-left: 15px;
            }
        }
        .toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
        }
        .toast {
            min-width: 300px;
            background: white;
            border-left: 4px solid;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            margin-bottom: 10px;
        }
        .toast.success { border-left-color: #28a745; }
        .toast.error { border-left-color: #dc3545; }
        .toast.info { border-left-color: #17a2b8; }
    </style>
</head>
<body>
    <!-- Theme Customization Structure Start -->

    <!-- Theme Customization Structure End -->

    <div class="overlay bg-black bg-opacity-50 w-100 h-100 position-fixed z-9 visibility-hidden opacity-0 duration-300"></div>

    <!-- Main Sidebar -->
    <?php include_once('includes/sidebar.php') ?>

    <main class="dashboard-main">
        <div class="navbar-header shadow-1">
            <div class="row align-items-center justify-content-between">
                <div class="col-auto">
                    <div class="d-flex flex-wrap align-items-center gap-4">
                        <button type="button" class="sidebar-mobile-toggle" aria-label="Sidebar Mobile Toggler Button">
                            <iconify-icon icon="heroicons:bars-3-solid" class="icon"></iconify-icon>
                        </button>
                        <form class="navbar-search">
                            <input type="text" class="bg-transparent" name="search" placeholder="Search">
                            <iconify-icon icon="ion:search-outline" class="icon"></iconify-icon>
                        </form>
                    </div>
                </div>
                <div class="col-auto">
                    <div class="d-flex flex-wrap align-items-center gap-3">
                        <button type="button" data-theme-toggle
                            class="w-40-px h-40-px bg-neutral-200 rounded-circle d-flex justify-content-center align-items-center" aria-label="Dark & Light Mode Button"></button>
                        <div class="dropdown">
                            <button
                                class="has-indicator w-40-px h-40-px bg-neutral-200 rounded-circle d-flex justify-content-center align-items-center position-relative"
                                type="button" data-bs-toggle="dropdown" aria-label="Notification Button">
                                <iconify-icon icon="iconoir:bell" class="text-primary-light text-xl"></iconify-icon>
                                <span class="w-8-px h-8-px bg-danger-600 position-absolute end-0 top-0 rounded-circle mt-2 me-2"></span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="dashboard-main-body">
            <!-- Breadcrumb -->
            <div class="breadcrumb d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
                <div class="">
                    <h1 class="fw-semibold mb-4 h6 text-primary-light">School Management Hub</h1>
                    <div class="">
                        <a href="index.php" class="text-secondary-light hover-text-primary hover-underline">Dashboard</a>
                        <span class="text-secondary-light">/ Management</span>
                    </div>
                </div>
            </div>

            <!-- Toast Container -->
            <div class="toast-container" id="toastContainer"></div>

           <!-- Quick Stats -->
<div class="row mb-24">
    <div class="col-xxl-12">
        <div class="row gy-4">
            <?php
            // Derive additional stats
            $totalRevenue = 0; // Replace with actual sum of payments if available
            $monthlyRevenue = 0;
            $pendingPayments = 0;
            $collectionRate = 0;
            $feeCollectionRate = 0;
            $totalSubjects = count($subjects);
            $totalClasses = count($classes);
            $defaultTerm = !empty($academicTerms) ? $academicTerms[0] : null;
            ?>

            <!-- Total Students -->
            <div class="col-xxl-3 col-sm-6">
                <div class="card shadow-1 radius-8 gradient-bg-end-1 h-100">
                    <div class="card-body p-20">
                        <div class="d-flex flex-wrap align-items-center gap-3 mb-16">
                            <div class="w-44-px h-44-px bg-warning-600 rounded-circle d-flex justify-content-center align-items-center">
                                <img src="https://academixsuite.com/tenant/assets/images/icons/dashboard-icon1.png" alt="Icon">
                            </div>
                            <p class="fw-medium text-primary-light mb-1">Total Students</p>
                        </div>
                        <h6 class="mb-0"><?php echo number_format($studentCount); ?></h6>
                        <p class="fw-medium text-sm text-primary-light mt-12 mb-0 d-flex align-items-center gap-2">
                            <span class="d-inline-flex align-items-center gap-1 text-primary-600 text-sm fw-semibold">
                                <?php
                                // Attendance rate for today (example)
                                $attendanceRate = 0; // Replace with actual calculation
                                echo $attendanceRate . '%';
                                ?>
                            </span>
                            Attendance Rate Today
                        </p>
                    </div>
                </div>
            </div>

            <!-- Total Teachers -->
            <div class="col-xxl-3 col-sm-6">
                <div class="card shadow-1 radius-8 gradient-bg-end-2 h-100">
                    <div class="card-body p-20">
                        <div class="d-flex flex-wrap align-items-center gap-3 mb-16">
                            <div class="w-44-px h-44-px bg-blue-600 rounded-circle d-flex justify-content-center align-items-center">
                                <img src="https://academixsuite.com/tenant/assets/images/icons/dashboard-icon2.png" alt="Icon">
                            </div>
                            <p class="fw-medium text-primary-light mb-1">Total Teachers</p>
                        </div>
                        <h6 class="mb-0"><?php echo number_format($teacherCount); ?></h6>
                        <p class="fw-medium text-sm text-primary-light mt-12 mb-0 d-flex align-items-center gap-2">
                            <span class="d-inline-flex align-items-center gap-1 text-primary-600 text-sm fw-semibold">
                                <?php echo $totalClasses; ?> Classes
                            </span>
                            <?php echo $totalSubjects; ?> Subjects
                        </p>
                    </div>
                </div>
            </div>

            <!-- Total Revenue (placeholder) -->
            <div class="col-xxl-3 col-sm-6">
                <div class="card shadow-1 radius-8 gradient-bg-end-3 h-100">
                    <div class="card-body p-20">
                        <div class="d-flex flex-wrap align-items-center gap-3 mb-16">
                            <div class="w-44-px h-44-px bg-purple-600 rounded-circle d-flex justify-content-center align-items-center">
                                <img src="https://academixsuite.com/tenant/assets/images/icons/dashboard-icon3.png" alt="Icon">
                            </div>
                            <p class="fw-medium text-primary-light mb-1">Total Revenue</p>
                        </div>
                        <h6 class="mb-0"><?php echo $currencySymbol . ' ' . number_format($totalRevenue, 2); ?></h6>
                        <p class="fw-medium text-sm text-primary-light mt-12 mb-0 d-flex align-items-center gap-2">
                            <span class="d-inline-flex align-items-center gap-1 text-primary-600 text-sm fw-semibold">
                                <?php echo $currencySymbol . ' ' . number_format($monthlyRevenue, 2); ?>
                            </span>
                            This Month
                        </p>
                    </div>
                </div>
            </div>

            <!-- Active Classes -->
            <div class="col-xxl-3 col-sm-6">
                <div class="card shadow-1 radius-8 gradient-bg-end-5 h-100">
                    <div class="card-body p-20">
                        <div class="d-flex flex-wrap align-items-center gap-3 mb-16">
                            <div class="w-44-px h-44-px bg-success-600 rounded-circle d-flex justify-content-center align-items-center">
                                <img src="https://academixsuite.com/tenant/assets/images/icons/dashboard-icon5.png" alt="Icon">
                            </div>
                            <p class="fw-medium text-primary-light mb-1">Active Classes</p>
                        </div>
                        <h6 class="mb-0"><?php echo number_format($totalClasses); ?></h6>
                        <p class="fw-medium text-sm text-primary-light mt-12 mb-0 d-flex align-items-center gap-2">
                            <span class="d-inline-flex align-items-center gap-1 text-primary-600 text-sm fw-semibold">
                                <?php echo $totalSubjects; ?> Subjects
                            </span>
                            Across Classes
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

            <!-- Main row with vertical navigation and content -->
            <div class="row">
                <!-- Vertical Navigation Column -->
                <div class="col-lg-4 col-xl-3 mb-5">
                    <div class="vertical-nav-wrapper">
                        <div class="nav flex-column nav-pills" id="v-pills-tab" role="tablist" aria-orientation="vertical">
                            <button class="nav-link <?php echo $activeTab == 'general' ? 'active' : ''; ?>"
                                    id="v-pills-general-tab" data-bs-toggle="pill" data-bs-target="#v-pills-general"
                                    type="button" role="tab" aria-controls="v-pills-general" aria-selected="true">
                                <i class="ri-settings-3-line"></i> General Settings
                            </button>
                            <button class="nav-link <?php echo $activeTab == 'academic' ? 'active' : ''; ?>"
                                    id="v-pills-academic-tab" data-bs-toggle="pill" data-bs-target="#v-pills-academic"
                                    type="button" role="tab" aria-controls="v-pills-academic" aria-selected="false">
                                <i class="ri-graduation-cap-line"></i> Academic Management
                            </button>
                            <button class="nav-link <?php echo $activeTab == 'classes' ? 'active' : ''; ?>"
                                    id="v-pills-classes-tab" data-bs-toggle="pill" data-bs-target="#v-pills-classes"
                                    type="button" role="tab" aria-controls="v-pills-classes" aria-selected="false">
                                <i class="ri-school-line"></i> Classes & Sections
                            </button>
                            <button class="nav-link <?php echo $activeTab == 'subjects' ? 'active' : ''; ?>"
                                    id="v-pills-subjects-tab" data-bs-toggle="pill" data-bs-target="#v-pills-subjects"
                                    type="button" role="tab" aria-controls="v-pills-subjects" aria-selected="false">
                                <i class="ri-book-open-line"></i> Subjects
                            </button>
                            <button class="nav-link <?php echo $activeTab == 'financial' ? 'active' : ''; ?>"
                                    id="v-pills-financial-tab" data-bs-toggle="pill" data-bs-target="#v-pills-financial"
                                    type="button" role="tab" aria-controls="v-pills-financial" aria-selected="false">
                                <i class="ri-bank-card-line"></i> Financial Management
                            </button>
                            <button class="nav-link <?php echo $activeTab == 'announcements' ? 'active' : ''; ?>"
                                    id="v-pills-announcements-tab" data-bs-toggle="pill" data-bs-target="#v-pills-announcements"
                                    type="button" role="tab" aria-controls="v-pills-announcements" aria-selected="false">
                                <i class="ri-megaphone-line"></i> Announcements
                            </button>
                            <button class="nav-link <?php echo $activeTab == 'whatsapp' ? 'active' : ''; ?>"
                                    id="v-pills-whatsapp-tab" data-bs-toggle="pill" data-bs-target="#v-pills-whatsapp"
                                    type="button" role="tab" aria-controls="v-pills-whatsapp" aria-selected="false">
                                <i class="ri-whatsapp-line"></i> WhatsApp Alerts
                            </button>
                            <button class="nav-link <?php echo $activeTab == 'subscription' ? 'active' : ''; ?>"
                                    id="v-pills-subscription-tab" data-bs-toggle="pill" data-bs-target="#v-pills-subscription"
                                    type="button" role="tab" aria-controls="v-pills-subscription" aria-selected="false">
                                <i class="ri-price-tag-3-line"></i> Subscription & Billing
                            </button>
                            <button class="nav-link <?php echo $activeTab == 'security' ? 'active' : ''; ?>"
                                    id="v-pills-security-tab" data-bs-toggle="pill" data-bs-target="#v-pills-security"
                                    type="button" role="tab" aria-controls="v-pills-security" aria-selected="false">
                                <i class="ri-shield-keyhole-line"></i> Security & Backup
                            </button>
                            <button class="nav-link <?php echo $activeTab == 'api' ? 'active' : ''; ?>"
                                    id="v-pills-api-tab" data-bs-toggle="pill" data-bs-target="#v-pills-api"
                                    type="button" role="tab" aria-controls="v-pills-api" aria-selected="false">
                                <i class="ri-key-2-line"></i> API Keys
                            </button>
                            <button class="nav-link <?php echo $activeTab == 'activity' ? 'active' : ''; ?>"
                                    id="v-pills-activity-tab" data-bs-toggle="pill" data-bs-target="#v-pills-activity"
                                    type="button" role="tab" aria-controls="v-pills-activity" aria-selected="false">
                                <i class="ri-history-line"></i> Activity Log
                            </button>
                            <button class="nav-link <?php echo $activeTab == 'profile' ? 'active' : ''; ?>"
                                    id="v-pills-profile-tab" data-bs-toggle="pill" data-bs-target="#v-pills-profile"
                                    type="button" role="tab" aria-controls="v-pills-profile" aria-selected="false">
                                <i class="ri-pages-line"></i> Public Profile
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Content Column -->
                <div class="col-lg-8 col-xl-9 content-col">
                    <div class="tab-content" id="v-pills-tabContent">
                        <!-- General Settings Tab -->
                        <div class="tab-pane fade <?php echo $activeTab == 'general' ? 'show active' : ''; ?>" id="v-pills-general" role="tabpanel" aria-labelledby="v-pills-general-tab">
                            <?php include 'tabs/general_settings.php'; ?>
                        </div>

                        <!-- Academic Management Tab -->
                        <div class="tab-pane fade <?php echo $activeTab == 'academic' ? 'show active' : ''; ?>" id="v-pills-academic" role="tabpanel" aria-labelledby="v-pills-academic-tab">
                            <div class="row">
                                <!-- Academic Years -->
                                <div class="col-md-6">
                                    <div class="card mb-24">
                                        <div class="card-header d-flex justify-content-between align-items-center">
                                            <h5 class="mb-0">Academic Years</h5>
                                            <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addYearModal">
                                                <i class="ri-add-line"></i> Add Year
                                            </button>
                                        </div>
                                        <div class="card-body">
                                            <div class="table-responsive">
                                                <table class="table table-hover datatable">
                                                    <thead>
                                                        <tr>
                                                            <th>Year Name</th>
                                                            <th>Start Date</th>
                                                            <th>End Date</th>
                                                            <th>Status</th>
                                                            <th>Default</th>
                                                            <th>Actions</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($academicYears as $year): ?>
                                                        <tr data-id="<?php echo $year['id']; ?>">
                                                            <td><?php echo htmlspecialchars($year['name']); ?></td>
                                                            <td><?php echo date('M d, Y', strtotime($year['start_date'])); ?></td>
                                                            <td><?php echo date('M d, Y', strtotime($year['end_date'])); ?></td>
                                                            <td>
                                                                <span class="badge bg-<?php
                                                                    echo $year['status'] == 'active' ? 'success' :
                                                                        ($year['status'] == 'upcoming' ? 'warning' : 'secondary');
                                                                ?>">
                                                                    <?php echo ucfirst($year['status']); ?>
                                                                </span>
                                                            </td>
                                                            <td>
                                                                <?php if (!empty($year['is_default']) && $year['is_default'] == 1): ?>
                                                                    <span class="badge bg-primary">Default</span>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td class="action-buttons">
                                                                <button class="btn btn-sm btn-outline-primary edit-year" data-id="<?php echo $year['id']; ?>">
                                                                    <i class="ri-pencil-line"></i>
                                                                </button>
                                                                <button class="btn btn-sm btn-outline-danger delete-year" data-id="<?php echo $year['id']; ?>">
                                                                    <i class="ri-delete-bin-line"></i>
                                                                </button>
                                                            </td>
                                                        </tr>
                                                        <?php endforeach; ?>
                                                        <?php if (empty($academicYears)): ?>
                                                        <tr>
                                                            <td colspan="6" class="text-center text-muted">No academic years found</td>
                                                        </tr>
                                                        <?php endif; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Academic Terms -->
                                <div class="col-md-6">
                                    <div class="card mb-24">
                                        <div class="card-header d-flex justify-content-between align-items-center">
                                            <h5 class="mb-0">Academic Terms</h5>
                                            <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addTermModal">
                                                <i class="ri-add-line"></i> Add Term
                                            </button>
                                        </div>
                                        <div class="card-body">
                                            <div class="table-responsive">
                                                <table class="table table-hover datatable">
                                                    <thead>
                                                        <tr>
                                                            <th>Term Name</th>
                                                            <th>Academic Year</th>
                                                            <th>Start Date</th>
                                                            <th>End Date</th>
                                                            <th>Default</th>
                                                            <th>Actions</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($academicTerms as $term): ?>
                                                        <tr data-id="<?php echo $term['id']; ?>">
                                                            <td><?php echo htmlspecialchars($term['name']); ?></td>
                                                            <td><?php echo htmlspecialchars($term['academic_year_name']); ?></td>
                                                            <td><?php echo date('M d, Y', strtotime($term['start_date'])); ?></td>
                                                            <td><?php echo date('M d, Y', strtotime($term['end_date'])); ?></td>
                                                            <td>
                                                                <?php if (!empty($term['is_default'])): ?>
                                                                    <span class="badge bg-primary">Default</span>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td class="action-buttons">
                                                                <button class="btn btn-sm btn-outline-primary edit-term" data-id="<?php echo $term['id']; ?>">
                                                                    <i class="ri-pencil-line"></i>
                                                                </button>
                                                                <button class="btn btn-sm btn-outline-danger delete-term" data-id="<?php echo $term['id']; ?>">
                                                                    <i class="ri-delete-bin-line"></i>
                                                                </button>
                                                            </td>
                                                        </tr>
                                                        <?php endforeach; ?>
                                                        <?php if (empty($academicTerms)): ?>
                                                        <tr>
                                                            <td colspan="6" class="text-center text-muted">No academic terms found</td>
                                                        </tr>
                                                        <?php endif; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Classes & Sections Tab -->
                        <div class="tab-pane fade <?php echo $activeTab == 'classes' ? 'show active' : ''; ?>" id="v-pills-classes" role="tabpanel" aria-labelledby="v-pills-classes-tab">
                            <div class="row">
                                <!-- Classes -->
                                <div class="col-md-6">
                                    <div class="card mb-24">
                                        <div class="card-header d-flex justify-content-between align-items-center">
                                            <h5 class="mb-0">Classes</h5>
                                            <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addClassModal">
                                                <i class="ri-add-line"></i> Add Class
                                            </button>
                                        </div>
                                        <div class="card-body">
                                            <div class="table-responsive">
                                                <table class="table table-hover datatable">
                                                    <thead>
                                                        <tr>
                                                            <th>Class Name</th>
                                                            <th>Code</th>
                                                            <th>Grade Level</th>
                                                            <th>Capacity</th>
                                                            <th>Sections</th>
                                                            <th>Subjects</th>
                                                            <th>Actions</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($classes as $class): ?>
                                                        <tr data-id="<?php echo $class['id']; ?>">
                                                            <td><?php echo htmlspecialchars($class['name']); ?></td>
                                                            <td><span class="badge bg-info"><?php echo htmlspecialchars($class['code']); ?></span></td>
                                                            <td><?php echo htmlspecialchars($class['grade_level'] ?? 'N/A'); ?></td>
                                                            <td><?php echo $class['capacity'] ?? 40; ?></td>
                                                            <td><?php echo $class['section_count'] ?? 0; ?></td>
                                                            <td><?php echo $class['subject_count'] ?? 0; ?></td>
                                                            <td class="action-buttons">
                                                                <button class="btn btn-sm btn-outline-primary edit-class" data-id="<?php echo $class['id']; ?>">
                                                                    <i class="ri-pencil-line"></i>
                                                                </button>
                                                                <button class="btn btn-sm btn-outline-danger delete-class" data-id="<?php echo $class['id']; ?>">
                                                                    <i class="ri-delete-bin-line"></i>
                                                                </button>
                                                            </td>
                                                        </tr>
                                                        <?php endforeach; ?>
                                                        <?php if (empty($classes)): ?>
                                                        <tr>
                                                            <td colspan="7" class="text-center text-muted">No classes found</td>
                                                        </tr>
                                                        <?php endif; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Sections -->
                                <div class="col-md-6">
                                    <div class="card mb-24">
                                        <div class="card-header d-flex justify-content-between align-items-center">
                                            <h5 class="mb-0">Sections</h5>
                                            <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addSectionModal">
                                                <i class="ri-add-line"></i> Add Section
                                            </button>
                                        </div>
                                        <div class="card-body">
                                            <div class="table-responsive">
                                                <table class="table table-hover datatable">
                                                    <thead>
                                                        <tr>
                                                            <th>Section</th>
                                                            <th>Code</th>
                                                            <th>Class</th>
                                                            <th>Capacity</th>
                                                            <th>Students</th>
                                                            <th>Room</th>
                                                            <th>Actions</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($sections as $section): ?>
                                                        <tr data-id="<?php echo $section['id']; ?>">
                                                            <td><?php echo htmlspecialchars($section['name']); ?></td>
                                                            <td><span class="badge bg-info"><?php echo htmlspecialchars($section['code']); ?></span></td>
                                                            <td><?php echo htmlspecialchars($section['class_name'] ?? 'N/A'); ?></td>
                                                            <td><?php echo $section['capacity'] ?? 40; ?></td>
                                                            <td>
                                                                <span class="badge bg-<?php echo ($section['student_count'] ?? 0) >= ($section['capacity'] ?? 40) ? 'danger' : 'success'; ?>">
                                                                    <?php echo $section['student_count'] ?? 0; ?>
                                                                </span>
                                                            </td>
                                                            <td><?php echo htmlspecialchars($section['room_number'] ?? 'N/A'); ?></td>
                                                            <td class="action-buttons">
                                                                <button class="btn btn-sm btn-outline-primary edit-section" data-id="<?php echo $section['id']; ?>">
                                                                    <i class="ri-pencil-line"></i>
                                                                </button>
                                                                <button class="btn btn-sm btn-outline-danger delete-section" data-id="<?php echo $section['id']; ?>">
                                                                    <i class="ri-delete-bin-line"></i>
                                                                </button>
                                                            </td>
                                                        </tr>
                                                        <?php endforeach; ?>
                                                        <?php if (empty($sections)): ?>
                                                        <tr>
                                                            <td colspan="7" class="text-center text-muted">No sections found</td>
                                                        </tr>
                                                        <?php endif; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Subjects Tab -->
                        <div class="tab-pane fade <?php echo $activeTab == 'subjects' ? 'show active' : ''; ?>" id="v-pills-subjects" role="tabpanel" aria-labelledby="v-pills-subjects-tab">
                            <div class="row">
                                <!-- Subjects List -->
                                <div class="col-md-5">
                                    <div class="card mb-24">
                                        <div class="card-header d-flex justify-content-between align-items-center">
                                            <h5 class="mb-0">Subjects</h5>
                                            <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addSubjectModal">
                                                <i class="ri-add-line"></i> Add Subject
                                            </button>
                                        </div>
                                        <div class="card-body">
                                            <div class="table-responsive">
                                                <table class="table table-hover datatable">
                                                    <thead>
                                                        <tr>
                                                            <th>Subject Name</th>
                                                            <th>Code</th>
                                                            <th>Type</th>
                                                            <th>Credit Hours</th>
                                                            <th>Actions</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($subjects as $subject): ?>
                                                        <tr data-id="<?php echo $subject['id']; ?>">
                                                            <td><?php echo htmlspecialchars($subject['name']); ?></td>
                                                            <td><span class="badge bg-info"><?php echo htmlspecialchars($subject['code']); ?></span></td>
                                                            <td>
                                                                <span class="badge bg-<?php
                                                                    echo $subject['type'] == 'core' ? 'primary' :
                                                                        ($subject['type'] == 'elective' ? 'success' : 'warning');
                                                                ?>">
                                                                    <?php echo ucfirst($subject['type']); ?>
                                                                </span>
                                                            </td>
                                                            <td><?php echo $subject['credit_hours'] ?? 1.0; ?></td>
                                                            <td class="action-buttons">
                                                                <button class="btn btn-sm btn-outline-primary edit-subject" data-id="<?php echo $subject['id']; ?>">
                                                                    <i class="ri-pencil-line"></i>
                                                                </button>
                                                                <button class="btn btn-sm btn-outline-danger delete-subject" data-id="<?php echo $subject['id']; ?>">
                                                                    <i class="ri-delete-bin-line"></i>
                                                                </button>
                                                            </td>
                                                        </tr>
                                                        <?php endforeach; ?>
                                                        <?php if (empty($subjects)): ?>
                                                        <tr>
                                                            <td colspan="5" class="text-center text-muted">No subjects found</td>
                                                        </tr>
                                                        <?php endif; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Subject Assignments -->
                                <div class="col-md-7">
                                    <div class="card mb-24">
                                        <div class="card-header d-flex justify-content-between align-items-center">
                                            <h5 class="mb-0">Subject Assignments</h5>
                                            <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#assignSubjectModal">
                                                <i class="ri-add-line"></i> Assign Subject
                                            </button>
                                        </div>
                                        <div class="card-body">
                                            <div class="table-responsive">
                                                <table class="table table-hover datatable">
                                                    <thead>
                                                        <tr>
                                                            <th>Class</th>
                                                            <th>Subject</th>
                                                            <th>Teacher</th>
                                                            <th>Actions</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($classSubjects as $assignment): ?>
                                                        <tr data-id="<?php echo $assignment['id']; ?>">
                                                            <td><?php echo htmlspecialchars($assignment['class_name'] ?? 'N/A'); ?></td>
                                                            <td><?php echo htmlspecialchars($assignment['subject_name'] ?? 'N/A'); ?></td>
                                                            <td><?php echo htmlspecialchars($assignment['teacher_name'] ?? 'Not Assigned'); ?></td>
                                                            <td class="action-buttons">
                                                                <button class="btn btn-sm btn-outline-danger delete-assignment" data-id="<?php echo $assignment['id']; ?>">
                                                                    <i class="ri-delete-bin-line"></i>
                                                                </button>
                                                            </td>
                                                        </tr>
                                                        <?php endforeach; ?>
                                                        <?php if (empty($classSubjects)): ?>
                                                        <tr>
                                                            <td colspan="4" class="text-center text-muted">No subject assignments found</td>
                                                        </tr>
                                                        <?php endif; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Financial Management Tab -->
                        <div class="tab-pane fade <?php echo $activeTab == 'financial' ? 'show active' : ''; ?>" id="v-pills-financial" role="tabpanel" aria-labelledby="v-pills-financial-tab">
                            <div class="row">
                                <!-- Payment Methods -->
                                <div class="col-md-6">
                                    <div class="card mb-24">
                                        <div class="card-header d-flex justify-content-between align-items-center">
                                            <h5 class="mb-0">Payment Methods</h5>
                                            <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addPaymentMethodModal">
                                                <i class="ri-add-line"></i> Add Method
                                            </button>
                                        </div>
                                        <div class="card-body">
                                            <div class="table-responsive">
                                                <table class="table table-hover datatable">
                                                    <thead>
                                                        <tr>
                                                            <th>Type</th>
                                                            <th>Provider</th>
                                                            <th>Details</th>
                                                            <th>Default</th>
                                                            <th>Status</th>
                                                            <th>Actions</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($paymentMethods as $method): ?>
                                                        <tr data-id="<?php echo $method['id']; ?>">
                                                            <td>
                                                                <span class="badge bg-info">
                                                                    <?php echo ucfirst(str_replace('_', ' ', $method['type'])); ?>
                                                                </span>
                                                            </td>
                                                            <td><?php echo htmlspecialchars($method['provider'] ?? 'N/A'); ?></td>
                                                            <td>
                                                                <?php
                                                                if ($method['type'] == 'card' && !empty($method['last_four'])) {
                                                                    echo '**** **** **** ' . $method['last_four'];
                                                                } elseif (!empty($method['metadata'])) {
                                                                    $metadata = json_decode($method['metadata'], true);
                                                                    echo $metadata['account_name'] ?? 'N/A';
                                                                } else {
                                                                    echo 'N/A';
                                                                }
                                                                ?>
                                                            </td>
                                                            <td>
                                                                <?php if (!empty($method['is_default'])): ?>
                                                                    <span class="badge bg-primary">Default</span>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td>
                                                                <span class="badge bg-<?php echo !empty($method['is_verified']) ? 'success' : 'warning'; ?>">
                                                                    <?php echo !empty($method['is_verified']) ? 'Verified' : 'Pending'; ?>
                                                                </span>
                                                            </td>
                                                            <td class="action-buttons">
                                                                <button class="btn btn-sm btn-outline-primary edit-payment-method" data-id="<?php echo $method['id']; ?>">
                                                                    <i class="ri-pencil-line"></i>
                                                                </button>
                                                                <button class="btn btn-sm btn-outline-danger delete-payment-method" data-id="<?php echo $method['id']; ?>">
                                                                    <i class="ri-delete-bin-line"></i>
                                                                </button>
                                                            </td>
                                                        </tr>
                                                        <?php endforeach; ?>
                                                        <?php if (empty($paymentMethods)): ?>
                                                        <tr>
                                                            <td colspan="6" class="text-center text-muted">No payment methods found</td>
                                                        </tr>
                                                        <?php endif; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Fee Categories -->
                                <div class="col-md-6">
                                    <div class="card mb-24">
                                        <div class="card-header d-flex justify-content-between align-items-center">
                                            <h5 class="mb-0">Fee Categories</h5>
                                            <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addFeeCategoryModal">
                                                <i class="ri-add-line"></i> Add Category
                                            </button>
                                        </div>
                                        <div class="card-body">
                                            <div class="table-responsive">
                                                <table class="table table-hover datatable">
                                                    <thead>
                                                        <tr>
                                                            <th>Category Name</th>
                                                            <th>Description</th>
                                                            <th>Actions</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($feeCategories as $category): ?>
                                                        <tr data-id="<?php echo $category['id']; ?>">
                                                            <td><?php echo htmlspecialchars($category['name']); ?></td>
                                                            <td><?php echo htmlspecialchars($category['description'] ?? ''); ?></td>
                                                            <td class="action-buttons">
                                                                <button class="btn btn-sm btn-outline-primary edit-fee-category" data-id="<?php echo $category['id']; ?>">
                                                                    <i class="ri-pencil-line"></i>
                                                                </button>
                                                                <button class="btn btn-sm btn-outline-danger delete-fee-category" data-id="<?php echo $category['id']; ?>">
                                                                    <i class="ri-delete-bin-line"></i>
                                                                </button>
                                                            </td>
                                                        </tr>
                                                        <?php endforeach; ?>
                                                        <?php if (empty($feeCategories)): ?>
                                                        <tr>
                                                            <td colspan="3" class="text-center text-muted">No fee categories found</td>
                                                        </tr>
                                                        <?php endif; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Fee Structures -->
                                <div class="col-12 mt-24">
                                    <div class="card">
                                        <div class="card-header d-flex justify-content-between align-items-center">
                                            <h5 class="mb-0">Fee Structures</h5>
                                            <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addFeeStructureModal">
                                                <i class="ri-add-line"></i> Add Fee Structure
                                            </button>
                                        </div>
                                        <div class="card-body">
                                            <div class="table-responsive">
                                                <table class="table table-hover datatable">
                                                    <thead>
                                                        <tr>
                                                            <th>Academic Year</th>
                                                            <th>Class</th>
                                                            <th>Category</th>
                                                            <th>Amount</th>
                                                            <th>Due Date</th>
                                                            <th>Late Fee</th>
                                                            <th>Actions</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($feeStructures as $fee): ?>
                                                        <tr data-id="<?php echo $fee['id']; ?>">
                                                            <td><?php echo htmlspecialchars($fee['academic_year_name'] ?? 'N/A'); ?></td>
                                                            <td><?php echo htmlspecialchars($fee['class_name'] ?? 'N/A'); ?></td>
                                                            <td><?php echo htmlspecialchars($fee['category_name'] ?? 'N/A'); ?></td>
                                                            <td><strong><?php echo $currencySymbol . ' ' . number_format($fee['amount'], 2); ?></strong></td>
                                                            <td><?php echo !empty($fee['due_date']) ? date('M d, Y', strtotime($fee['due_date'])) : 'N/A'; ?></td>
                                                            <td><?php echo $currencySymbol . ' ' . number_format($fee['late_fee'] ?? 0, 2); ?></td>
                                                            <td class="action-buttons">
                                                                <button class="btn btn-sm btn-outline-primary edit-fee-structure" data-id="<?php echo $fee['id']; ?>">
                                                                    <i class="ri-pencil-line"></i>
                                                                </button>
                                                                <button class="btn btn-sm btn-outline-danger delete-fee-structure" data-id="<?php echo $fee['id']; ?>">
                                                                    <i class="ri-delete-bin-line"></i>
                                                                </button>
                                                            </td>
                                                        </tr>
                                                        <?php endforeach; ?>
                                                        <?php if (empty($feeStructures)): ?>
                                                        <tr>
                                                            <td colspan="7" class="text-center text-muted">No fee structures found</td>
                                                        </tr>
                                                        <?php endif; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Announcements Tab -->
                        <div class="tab-pane fade <?php echo $activeTab == 'announcements' ? 'show active' : ''; ?>" id="v-pills-announcements" role="tabpanel" aria-labelledby="v-pills-announcements-tab">
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="card">
                                        <div class="card-header">
                                            <h5>Create Announcement</h5>
                                        </div>
                                        <div class="card-body">
                                            <form id="announcementForm">
                                                <input type="hidden" name="action" value="create_announcement">
                                                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">

                                                <div class="mb-3">
                                                    <label class="form-label">Title</label>
                                                    <input type="text" name="title" class="form-control" required>
                                                </div>

                                                <div class="mb-3">
                                                    <label class="form-label">Description</label>
                                                    <textarea name="description" class="form-control" rows="3" required></textarea>
                                                </div>

                                                <div class="mb-3">
                                                    <label class="form-label">Target Audience</label>
                                                    <select name="target" class="form-select">
                                                        <option value="all">All</option>
                                                        <option value="students">Students</option>
                                                        <option value="teachers">Teachers</option>
                                                        <option value="parents">Parents</option>
                                                        <option value="class">Specific Class</option>
                                                        <option value="section">Specific Section</option>
                                                    </select>
                                                </div>

                                                <div class="row">
                                                    <div class="col-md-6 mb-3">
                                                        <label class="form-label">Start Date</label>
                                                        <input type="date" name="start_date" class="form-control">
                                                    </div>
                                                    <div class="col-md-6 mb-3">
                                                        <label class="form-label">End Date</label>
                                                        <input type="date" name="end_date" class="form-control">
                                                    </div>
                                                </div>

                                                <button type="submit" class="btn btn-primary w-100">Publish Announcement</button>
                                            </form>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-md-8">
                                    <div class="card">
                                        <div class="card-header">
                                            <h5>Recent Announcements</h5>
                                        </div>
                                        <div class="card-body">
                                            <div class="activity-feed">
                                                <?php foreach ($announcements as $announcement): ?>
                                                <div class="activity-item">
                                                    <h6><?php echo htmlspecialchars($announcement['title']); ?></h6>
                                                    <p class="text-muted mb-2"><?php echo htmlspecialchars(substr($announcement['description'], 0, 100)); ?>...</p>
                                                    <div class="d-flex justify-content-between">
                                                        <small class="text-primary">By: <?php echo htmlspecialchars($announcement['created_by_name'] ?? 'System'); ?></small>
                                                        <small class="text-muted"><?php echo date('M d, Y H:i', strtotime($announcement['created_at'])); ?></small>
                                                    </div>
                                                    <small class="badge bg-info">Target: <?php echo ucfirst($announcement['target'] ?? 'all'); ?></small>
                                                </div>
                                                <?php endforeach; ?>
                                                <?php if (empty($announcements)): ?>
                                                <p class="text-muted text-center">No announcements found</p>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- WhatsApp Alerts Tab -->
                        <div class="tab-pane fade <?php echo $activeTab == 'whatsapp' ? 'show active' : ''; ?>" id="v-pills-whatsapp" role="tabpanel" aria-labelledby="v-pills-whatsapp-tab">
                            <?php include 'tabs/whatsapp_settings.php'; ?>
                        </div>

                        <!-- Subscription & Billing Tab -->
                        <div class="tab-pane fade <?php echo $activeTab == 'subscription' ? 'show active' : ''; ?>" id="v-pills-subscription" role="tabpanel" aria-labelledby="v-pills-subscription-tab">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="card">
                                        <div class="card-header">
                                            <h5>Current Subscription</h5>
                                        </div>
                                        <div class="card-body">
                                            <?php if ($subscriptionInfo): ?>
                                            <table class="table">
                                                <tr>
                                                    <th>Plan:</th>
                                                    <td><?php echo htmlspecialchars($subscriptionInfo['plan_name'] ?? 'N/A'); ?></td>
                                                </tr>
                                                <tr>
                                                    <th>Status:</th>
                                                    <td>
                                                        <span class="badge bg-<?php
                                                            echo $subscriptionInfo['status'] == 'active' ? 'success' :
                                                                ($subscriptionInfo['status'] == 'pending' ? 'warning' : 'danger');
                                                        ?>">
                                                            <?php echo ucfirst($subscriptionInfo['status']); ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                                <tr>
                                                    <th>Billing Cycle:</th>
                                                    <td><?php echo ucfirst($subscriptionInfo['billing_cycle'] ?? 'Monthly'); ?></td>
                                                </tr>
                                                <tr>
                                                    <th>Amount:</th>
                                                    <td><?php echo ($subscriptionInfo['currency'] ?? 'NGN') . ' ' . number_format($subscriptionInfo['amount'] ?? 0, 2); ?></td>
                                                </tr>
                                                <tr>
                                                    <th>Period Start:</th>
                                                    <td><?php echo date('M d, Y', strtotime($subscriptionInfo['current_period_start'] ?? 'now')); ?></td>
                                                </tr>
                                                <tr>
                                                    <th>Period End:</th>
                                                    <td><?php echo date('M d, Y', strtotime($subscriptionInfo['current_period_end'] ?? 'now')); ?></td>
                                                </tr>
                                                <?php if (!empty($subscriptionInfo['trial_ends_at'])): ?>
                                                <tr>
                                                    <th>Trial Ends:</th>
                                                    <td><?php echo date('M d, Y', strtotime($subscriptionInfo['trial_ends_at'])); ?></td>
                                                </tr>
                                                <?php endif; ?>
                                            </table>
                                            <?php else: ?>
                                            <p class="text-muted">No active subscription found.</p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="card">
                                        <div class="card-header">
                                            <h5>Plan Features</h5>
                                        </div>
                                        <div class="card-body">
                                            <?php if (!empty($schoolDetails['plan_features'])): ?>
                                            <ul class="list-group">
                                                <?php foreach ($schoolDetails['plan_features'] as $feature): ?>
                                                <li class="list-group-item">
                                                    <i class="ri-check-line text-success me-2"></i>
                                                    <?php echo htmlspecialchars($feature); ?>
                                                </li>
                                                <?php endforeach; ?>
                                            </ul>
                                            <?php else: ?>
                                            <p class="text-muted">No features available.</p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Security & Backup Tab -->
                        <div class="tab-pane fade <?php echo $activeTab == 'security' ? 'show active' : ''; ?>" id="v-pills-security" role="tabpanel" aria-labelledby="v-pills-security-tab">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="card mb-24">
                                        <div class="card-header">
                                            <h5>Change Password</h5>
                                        </div>
                                        <div class="card-body">
                                            <form id="passwordForm">
                                                <input type="hidden" name="action" value="change_password">
                                                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">

                                                <div class="mb-3">
                                                    <label class="form-label">Current Password</label>
                                                    <input type="password" name="current_password" class="form-control" required>
                                                </div>

                                                <div class="mb-3">
                                                    <label class="form-label">New Password</label>
                                                    <input type="password" name="new_password" class="form-control"
                                                           pattern="(?=.*\d)(?=.*[a-z])(?=.*[A-Z]).{8,}" required>
                                                    <small class="text-muted">Min 8 chars, with uppercase, lowercase and number</small>
                                                </div>

                                                <div class="mb-3">
                                                    <label class="form-label">Confirm New Password</label>
                                                    <input type="password" name="confirm_password" class="form-control" required>
                                                </div>

                                                <button type="submit" class="btn btn-warning">Change Password</button>
                                            </form>
                                        </div>
                                    </div>

                                    <div class="card">
                                        <div class="card-header">
                                            <h5>Two-Factor Authentication</h5>
                                        </div>
                                        <div class="card-body">
                                            <p class="text-muted">Two-factor authentication adds an extra layer of security to your account.</p>
                                            <button class="btn btn-primary" disabled>Enable 2FA (Coming Soon)</button>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="card mb-24">
                                        <div class="card-header d-flex justify-content-between align-items-center">
                                            <h5 class="mb-0">Storage Usage</h5>
                                            <button type="button" class="btn btn-primary btn-sm" onclick="createBackup()">
                                                <i class="ri-database-2-line"></i> Create Backup
                                            </button>
                                        </div>
                                        <div class="card-body">
                                            <?php
                                            $totalStorage = 0;
                                            $usedStorage = 0;
                                            foreach ($storageUsage as $storage) {
                                                $totalStorage += $storage['limit_bytes'];
                                                $usedStorage += $storage['used_bytes'];
                                            }
                                            $usagePercent = $totalStorage > 0 ? ($usedStorage / $totalStorage) * 100 : 0;
                                            ?>

                                            <div class="mb-3">
                                                <div class="d-flex justify-content-between">
                                                    <span>Used: <?php echo round($usedStorage / 1024 / 1024, 2); ?> MB</span>
                                                    <span>Total: <?php echo round($totalStorage / 1024 / 1024, 2); ?> MB</span>
                                                </div>
                                                <div class="storage-bar">
                                                    <div class="storage-bar-fill" style="width: <?php echo $usagePercent; ?>%"></div>
                                                </div>
                                            </div>

                                            <form method="POST" id="backupForm">
                                                <input type="hidden" name="action" value="create_backup">
                                                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                            </form>
                                        </div>
                                    </div>

                                    <div class="card">
                                        <div class="card-header">
                                            <h5>Recent Security Events</h5>
                                        </div>
                                        <div class="card-body">
                                            <p class="text-muted">Security monitoring coming soon.</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- API Keys Tab -->
                        <div class="tab-pane fade <?php echo $activeTab == 'api' ? 'show active' : ''; ?>" id="v-pills-api" role="tabpanel" aria-labelledby="v-pills-api-tab">
                            <div class="card">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0">API Keys</h5>
                                    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addApiKeyModal">
                                        <i class="ri-add-line"></i> Generate API Key
                                    </button>
                                </div>
                                <div class="card-body">
                                    <div class="table-responsive">
                                        <table class="table table-hover datatable">
                                            <thead>
                                                <tr>
                                                    <th>Name</th>
                                                    <th>API Key</th>
                                                    <th>Rate Limit</th>
                                                    <th>Expires</th>
                                                    <th>Last Used</th>
                                                    <th>Status</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($apiKeys as $key): ?>
                                                <tr data-id="<?php echo $key['id']; ?>">
                                                    <td><?php echo htmlspecialchars($key['name']); ?></td>
                                                    <td><code><?php echo substr($key['api_key'], 0, 8); ?>...</code></td>
                                                    <td><?php echo $key['rate_limit_per_minute'] ?? 60; ?>/min</td>
                                                    <td><?php echo $key['expires_at'] ? date('M d, Y', strtotime($key['expires_at'])) : 'Never'; ?></td>
                                                    <td><?php echo $key['last_used_at'] ? date('M d, Y', strtotime($key['last_used_at'])) : 'Never'; ?></td>
                                                    <td>
                                                        <span class="badge bg-<?php echo $key['is_active'] ? 'success' : 'danger'; ?>">
                                                            <?php echo $key['is_active'] ? 'Active' : 'Inactive'; ?>
                                                        </span>
                                                    </td>
                                                    <td class="action-buttons">
                                                        <button class="btn btn-sm btn-outline-secondary copy-api-key" data-key="<?php echo $key['api_key']; ?>">
                                                            <i class="ri-file-copy-line"></i>
                                                        </button>
                                                        <button class="btn btn-sm btn-outline-danger delete-api-key" data-id="<?php echo $key['id']; ?>">
                                                            <i class="ri-delete-bin-line"></i>
                                                        </button>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                                <?php if (empty($apiKeys)): ?>
                                                <tr>
                                                    <td colspan="7" class="text-center text-muted">No API keys found</td>
                                                </tr>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Activity Log Tab -->
                        <div class="tab-pane fade <?php echo $activeTab == 'activity' ? 'show active' : ''; ?>" id="v-pills-activity" role="tabpanel" aria-labelledby="v-pills-activity-tab">
                            <div class="card">
                                <div class="card-header">
                                    <h5>Recent Activities</h5>
                                </div>
                                <div class="card-body">
                                    <div class="activity-feed">
                                        <?php foreach ($recentActivities as $activity): ?>
                                        <div class="activity-item">
                                            <div class="d-flex justify-content-between">
                                                <strong><?php echo ucfirst(str_replace('_', ' ', $activity['action'])); ?></strong>
                                                <small class="activity-time">
                                                    <?php echo date('M d, H:i', strtotime($activity['created_at'])); ?>
                                                </small>
                                            </div>
                                            <div class="mt-2">
                                                <small>
                                                    Entity: <?php echo ucfirst($activity['entity_type']); ?>
                                                    (ID: <?php echo $activity['entity_id']; ?>)
                                                </small><br>
                                                <small class="text-muted">
                                                    By: <?php echo htmlspecialchars($activity['user_type']); ?>
                                                    (IP: <?php echo $activity['ip_address'] ?? 'N/A'; ?>)
                                                </small>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                        <?php if (empty($recentActivities)): ?>
                                        <p class="text-muted text-center">No activities found</p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Public Profile Tab -- the editor for everything visible on tenant/school_profile.php -->
                        <div class="tab-pane fade <?php echo $activeTab == 'profile' ? 'show active' : ''; ?>" id="v-pills-profile" role="tabpanel" aria-labelledby="v-pills-profile-tab">
                            <?php include __DIR__ . '/tabs/public_profile.php'; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <footer class="d-footer">
            <div class="">
                <p class="mb-0 text-center"> &copy; <span class="current-year"></span> <?php echo htmlspecialchars($school['name']); ?> | Made With ❤️ by AcademixSuite.</p>
            </div>
        </footer>
    </main>

    <!-- ========== MODALS ========== -->

    <!-- Add Academic Year Modal -->
    <div class="modal fade" id="addYearModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add Academic Year</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form class="modal-form" data-action="create_academic_year">
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                        <input type="hidden" name="action" value="create_academic_year">

                        <div class="mb-3">
                            <label class="form-label">Year Name</label>
                            <input type="text" name="name" class="form-control" placeholder="e.g., 2025-2026" required>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Start Date</label>
                                <input type="date" name="start_date" class="form-control" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">End Date</label>
                                <input type="date" name="end_date" class="form-control" required>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Status</label>
                                <select name="status" class="form-select">
                                    <option value="upcoming">Upcoming</option>
                                    <option value="active">Active</option>
                                    <option value="completed">Completed</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Default Year</label>
                                <div class="form-check mt-2">
                                    <input type="checkbox" name="is_default" value="1" class="form-check-input" id="isDefault">
                                    <label class="form-check-label" for="isDefault">Set as default</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Year</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Academic Year Modal -->
    <div class="modal fade" id="editYearModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Academic Year</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form class="modal-form" data-action="update_academic_year">
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                        <input type="hidden" name="action" value="update_academic_year">
                        <input type="hidden" name="id" id="edit_year_id" value="">

                        <div class="mb-3">
                            <label class="form-label">Year Name</label>
                            <input type="text" name="name" id="edit_year_name" class="form-control" required>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Start Date</label>
                                <input type="date" name="start_date" id="edit_year_start" class="form-control" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">End Date</label>
                                <input type="date" name="end_date" id="edit_year_end" class="form-control" required>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Status</label>
                                <select name="status" id="edit_year_status" class="form-select">
                                    <option value="upcoming">Upcoming</option>
                                    <option value="active">Active</option>
                                    <option value="completed">Completed</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Default Year</label>
                                <div class="form-check mt-2">
                                    <input type="checkbox" name="is_default" value="1" class="form-check-input" id="edit_year_default">
                                    <label class="form-check-label" for="edit_year_default">Set as default</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Year</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add Academic Term Modal -->
    <div class="modal fade" id="addTermModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add Academic Term</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form class="modal-form" data-action="create_academic_term">
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                        <input type="hidden" name="action" value="create_academic_term">

                        <div class="mb-3">
                            <label class="form-label">Academic Year</label>
                            <select name="academic_year_id" class="form-select" required>
                                <option value="">Select Academic Year</option>
                                <?php foreach ($academicYears as $year): ?>
                                <option value="<?php echo $year['id']; ?>">
                                    <?php echo htmlspecialchars($year['name']); ?>
                                    <?php echo (!empty($year['is_default']) && $year['is_default'] == 1) ? '(Default)' : ''; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Term Name</label>
                            <input type="text" name="name" class="form-control" placeholder="e.g., First Term" required>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Start Date</label>
                                <input type="date" name="start_date" class="form-control" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">End Date</label>
                                <input type="date" name="end_date" class="form-control" required>
                            </div>
                        </div>

                        <div class="mb-3">
                            <div class="form-check">
                                <input type="checkbox" name="is_default" value="1" class="form-check-input" id="isDefaultTerm">
                                <label class="form-check-label" for="isDefaultTerm">Set as default term for this academic year</label>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Term</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Academic Term Modal -->
    <div class="modal fade" id="editTermModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Academic Term</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form class="modal-form" data-action="update_academic_term">
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                        <input type="hidden" name="action" value="update_academic_term">
                        <input type="hidden" name="id" id="edit_term_id" value="">

                        <div class="mb-3">
                            <label class="form-label">Academic Year</label>
                            <select name="academic_year_id" id="edit_term_year" class="form-select" required>
                                <option value="">Select Academic Year</option>
                                <?php foreach ($academicYears as $year): ?>
                                <option value="<?php echo $year['id']; ?>">
                                    <?php echo htmlspecialchars($year['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Term Name</label>
                            <input type="text" name="name" id="edit_term_name" class="form-control" required>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Start Date</label>
                                <input type="date" name="start_date" id="edit_term_start" class="form-control" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">End Date</label>
                                <input type="date" name="end_date" id="edit_term_end" class="form-control" required>
                            </div>
                        </div>

                        <div class="mb-3">
                            <div class="form-check">
                                <input type="checkbox" name="is_default" value="1" class="form-check-input" id="edit_term_default">
                                <label class="form-check-label" for="edit_term_default">Set as default term for this academic year</label>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Term</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add Class Modal -->
    <div class="modal fade" id="addClassModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add Class</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form class="modal-form" data-action="create_class">
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                        <input type="hidden" name="action" value="create_class">

                        <div class="mb-3">
                            <label class="form-label">Class Name</label>
                            <input type="text" name="name" class="form-control" placeholder="e.g., Grade 10" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Class Code</label>
                            <input type="text" name="code" class="form-control" placeholder="e.g., G10" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Academic Year</label>
                            <select name="academic_year_id" class="form-select" required>
                                <option value="">Select Academic Year</option>
                                <?php foreach ($academicYears as $year): ?>
                                <option value="<?php echo $year['id']; ?>"><?php echo htmlspecialchars($year['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Grade Level</label>
                                <input type="text" name="grade_level" class="form-control" placeholder="e.g., Secondary">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Capacity</label>
                                <input type="number" name="capacity" class="form-control" value="40" min="1">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Room Number</label>
                            <input type="text" name="room_number" class="form-control" placeholder="e.g., A101">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea name="description" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Class</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Class Modal -->
    <div class="modal fade" id="editClassModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Class</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form class="modal-form" data-action="update_class">
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                        <input type="hidden" name="action" value="update_class">
                        <input type="hidden" name="id" id="edit_class_id" value="">

                        <div class="mb-3">
                            <label class="form-label">Class Name</label>
                            <input type="text" name="name" id="edit_class_name" class="form-control" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Class Code</label>
                            <input type="text" name="code" id="edit_class_code" class="form-control" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Academic Year</label>
                            <select name="academic_year_id" id="edit_class_year" class="form-select" required>
                                <option value="">Select Academic Year</option>
                                <?php foreach ($academicYears as $year): ?>
                                <option value="<?php echo $year['id']; ?>"><?php echo htmlspecialchars($year['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Grade Level</label>
                                <input type="text" name="grade_level" id="edit_class_grade" class="form-control">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Capacity</label>
                                <input type="number" name="capacity" id="edit_class_capacity" class="form-control" min="1">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Room Number</label>
                            <input type="text" name="room_number" id="edit_class_room" class="form-control">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea name="description" id="edit_class_desc" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Class</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add Section Modal -->
    <div class="modal fade" id="addSectionModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add Section</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form class="modal-form" data-action="create_section">
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                        <input type="hidden" name="action" value="create_section">

                        <div class="mb-3">
                            <label class="form-label">Class</label>
                            <select name="class_id" class="form-select" required>
                                <option value="">Select Class</option>
                                <?php foreach ($classes as $class): ?>
                                <option value="<?php echo $class['id']; ?>"><?php echo htmlspecialchars($class['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Section Name</label>
                            <input type="text" name="name" class="form-control" placeholder="e.g., Section A" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Section Code</label>
                            <input type="text" name="code" class="form-control" placeholder="e.g., A" required>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Capacity</label>
                                <input type="number" name="capacity" class="form-control" value="40" min="1">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Room Number</label>
                                <input type="text" name="room_number" class="form-control" placeholder="e.g., A101">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Section</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Section Modal -->
    <div class="modal fade" id="editSectionModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Section</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form class="modal-form" data-action="update_section">
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                        <input type="hidden" name="action" value="update_section">
                        <input type="hidden" name="id" id="edit_section_id" value="">

                        <div class="mb-3">
                            <label class="form-label">Class</label>
                            <select name="class_id" id="edit_section_class" class="form-select" required>
                                <option value="">Select Class</option>
                                <?php foreach ($classes as $class): ?>
                                <option value="<?php echo $class['id']; ?>"><?php echo htmlspecialchars($class['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Section Name</label>
                            <input type="text" name="name" id="edit_section_name" class="form-control" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Section Code</label>
                            <input type="text" name="code" id="edit_section_code" class="form-control" required>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Capacity</label>
                                <input type="number" name="capacity" id="edit_section_capacity" class="form-control" min="1">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Room Number</label>
                                <input type="text" name="room_number" id="edit_section_room" class="form-control">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Section</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add Subject Modal -->
    <div class="modal fade" id="addSubjectModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add Subject</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form class="modal-form" data-action="create_subject">
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                        <input type="hidden" name="action" value="create_subject">

                        <div class="mb-3">
                            <label class="form-label">Subject Name</label>
                            <input type="text" name="name" class="form-control" placeholder="e.g., Mathematics" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Subject Code</label>
                            <input type="text" name="code" class="form-control" placeholder="e.g., MATH101" required>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Type</label>
                                <select name="type" class="form-select">
                                    <option value="core">Core</option>
                                    <option value="elective">Elective</option>
                                    <option value="extra_curricular">Extra Curricular</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Credit Hours</label>
                                <input type="number" name="credit_hours" class="form-control" value="1.0" step="0.5" min="0.5">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea name="description" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Subject</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Subject Modal -->
    <div class="modal fade" id="editSubjectModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Subject</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form class="modal-form" data-action="update_subject">
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                        <input type="hidden" name="action" value="update_subject">
                        <input type="hidden" name="id" id="edit_subject_id" value="">

                        <div class="mb-3">
                            <label class="form-label">Subject Name</label>
                            <input type="text" name="name" id="edit_subject_name" class="form-control" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Subject Code</label>
                            <input type="text" name="code" id="edit_subject_code" class="form-control" required>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Type</label>
                                <select name="type" id="edit_subject_type" class="form-select">
                                    <option value="core">Core</option>
                                    <option value="elective">Elective</option>
                                    <option value="extra_curricular">Extra Curricular</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Credit Hours</label>
                                <input type="number" name="credit_hours" id="edit_subject_credit" class="form-control" step="0.5" min="0.5">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea name="description" id="edit_subject_desc" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Subject</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Assign Subject to Class Modal -->
    <div class="modal fade" id="assignSubjectModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Assign Subject to Class</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form class="modal-form" data-action="assign_subject">
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                        <input type="hidden" name="action" value="assign_subject">

                        <div class="mb-3">
                            <label class="form-label">Class</label>
                            <select name="class_id" class="form-select" required>
                                <option value="">Select Class</option>
                                <?php foreach ($classes as $class): ?>
                                <option value="<?php echo $class['id']; ?>"><?php echo htmlspecialchars($class['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Subject</label>
                            <select name="subject_id" class="form-select" required>
                                <option value="">Select Subject</option>
                                <?php foreach ($subjects as $subject): ?>
                                <option value="<?php echo $subject['id']; ?>"><?php echo htmlspecialchars($subject['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Teacher (Optional)</label>
                            <select name="teacher_id" class="form-select">
                                <option value="">Select Teacher</option>
                                <!-- Teachers would be loaded here -->
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Assign</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add Payment Method Modal -->
    <div class="modal fade" id="addPaymentMethodModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add Payment Method</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form class="modal-form" data-action="create_payment_method">
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                        <input type="hidden" name="action" value="create_payment_method">

                        <div class="mb-3">
                            <label class="form-label">Payment Type</label>
                            <select name="type" class="form-select" required>
                                <option value="">Select Type</option>
                                <?php foreach ($paymentTypes as $key => $value): ?>
                                <option value="<?php echo $key; ?>"><?php echo $value; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Provider/Bank Name</label>
                            <input type="text" name="provider" class="form-control" placeholder="e.g., GTBank">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Account Name</label>
                            <input type="text" name="account_name" class="form-control">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Account Number</label>
                            <input type="text" name="account_number" class="form-control">
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Expiry Month (Card)</label>
                                <input type="number" name="exp_month" class="form-control" min="1" max="12">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Expiry Year (Card)</label>
                                <input type="number" name="exp_year" class="form-control" min="<?php echo date('Y'); ?>">
                            </div>
                        </div>

                        <div class="form-check mb-3">
                            <input type="checkbox" name="is_default" value="1" class="form-check-input" id="isDefaultPayment">
                            <label class="form-check-label" for="isDefaultPayment">Set as default payment method</label>
                        </div>

                        <div class="form-check mb-3">
                            <input type="checkbox" name="is_verified" value="1" class="form-check-input" id="isVerified">
                            <label class="form-check-label" for="isVerified">Mark as verified</label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Payment Method</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Payment Method Modal -->
    <div class="modal fade" id="editPaymentMethodModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Payment Method</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form class="modal-form" data-action="update_payment_method">
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                        <input type="hidden" name="action" value="update_payment_method">
                        <input type="hidden" name="id" id="edit_payment_id" value="">

                        <div class="mb-3">
                            <label class="form-label">Payment Type</label>
                            <select name="type" id="edit_payment_type" class="form-select" required>
                                <option value="">Select Type</option>
                                <?php foreach ($paymentTypes as $key => $value): ?>
                                <option value="<?php echo $key; ?>"><?php echo $value; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Provider/Bank Name</label>
                            <input type="text" name="provider" id="edit_payment_provider" class="form-control">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Account Name</label>
                            <input type="text" name="account_name" id="edit_payment_acc_name" class="form-control">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Account Number</label>
                            <input type="text" name="account_number" id="edit_payment_acc_num" class="form-control">
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Expiry Month (Card)</label>
                                <input type="number" name="exp_month" id="edit_payment_exp_month" class="form-control" min="1" max="12">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Expiry Year (Card)</label>
                                <input type="number" name="exp_year" id="edit_payment_exp_year" class="form-control" min="<?php echo date('Y'); ?>">
                            </div>
                        </div>

                        <div class="form-check mb-3">
                            <input type="checkbox" name="is_default" value="1" class="form-check-input" id="edit_payment_default">
                            <label class="form-check-label" for="edit_payment_default">Set as default payment method</label>
                        </div>

                        <div class="form-check mb-3">
                            <input type="checkbox" name="is_verified" value="1" class="form-check-input" id="edit_payment_verified">
                            <label class="form-check-label" for="edit_payment_verified">Mark as verified</label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Payment Method</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add Fee Category Modal -->
    <div class="modal fade" id="addFeeCategoryModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add Fee Category</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form class="modal-form" data-action="create_fee_category">
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                        <input type="hidden" name="action" value="create_fee_category">

                        <div class="mb-3">
                            <label class="form-label">Category Name</label>
                            <input type="text" name="name" class="form-control" placeholder="e.g., Tuition Fee" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea name="description" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Category</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Fee Category Modal -->
    <div class="modal fade" id="editFeeCategoryModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Fee Category</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form class="modal-form" data-action="update_fee_category">
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                        <input type="hidden" name="action" value="update_fee_category">
                        <input type="hidden" name="id" id="edit_fee_cat_id" value="">

                        <div class="mb-3">
                            <label class="form-label">Category Name</label>
                            <input type="text" name="name" id="edit_fee_cat_name" class="form-control" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea name="description" id="edit_fee_cat_desc" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Category</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add Fee Structure Modal -->
    <div class="modal fade" id="addFeeStructureModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add Fee Structure</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form class="modal-form" data-action="create_fee_structure">
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                        <input type="hidden" name="action" value="create_fee_structure">

                        <div class="mb-3">
                            <label class="form-label">Academic Year</label>
                            <select name="academic_year_id" class="form-select" required>
                                <option value="">Select Academic Year</option>
                                <?php foreach ($academicYears as $year): ?>
                                <option value="<?php echo $year['id']; ?>"><?php echo htmlspecialchars($year['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Class</label>
                            <select name="class_id" class="form-select" required>
                                <option value="">Select Class</option>
                                <?php foreach ($classes as $class): ?>
                                <option value="<?php echo $class['id']; ?>"><?php echo htmlspecialchars($class['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Fee Category</label>
                            <select name="fee_category_id" class="form-select" required>
                                <option value="">Select Category</option>
                                <?php foreach ($feeCategories as $category): ?>
                                <option value="<?php echo $category['id']; ?>"><?php echo htmlspecialchars($category['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Amount (<?php echo $currencySymbol; ?>)</label>
                            <input type="number" name="amount" class="form-control" step="0.01" min="0" required>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Due Date</label>
                                <input type="date" name="due_date" class="form-control">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Late Fee (<?php echo $currencySymbol; ?>)</label>
                                <input type="number" name="late_fee" class="form-control" step="0.01" min="0" value="0.00">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Academic Term (Optional)</label>
                            <select name="academic_term_id" class="form-select">
                                <option value="">Select Term</option>
                                <?php foreach ($academicTerms as $term): ?>
                                <option value="<?php echo $term['id']; ?>"><?php echo htmlspecialchars($term['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Fee Structure</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Fee Structure Modal -->
    <div class="modal fade" id="editFeeStructureModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Fee Structure</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form class="modal-form" data-action="update_fee_structure">
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                        <input type="hidden" name="action" value="update_fee_structure">
                        <input type="hidden" name="id" id="edit_fee_struct_id" value="">

                        <div class="mb-3">
                            <label class="form-label">Academic Year</label>
                            <select name="academic_year_id" id="edit_fee_struct_year" class="form-select" required>
                                <option value="">Select Academic Year</option>
                                <?php foreach ($academicYears as $year): ?>
                                <option value="<?php echo $year['id']; ?>"><?php echo htmlspecialchars($year['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Class</label>
                            <select name="class_id" id="edit_fee_struct_class" class="form-select" required>
                                <option value="">Select Class</option>
                                <?php foreach ($classes as $class): ?>
                                <option value="<?php echo $class['id']; ?>"><?php echo htmlspecialchars($class['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Fee Category</label>
                            <select name="fee_category_id" id="edit_fee_struct_cat" class="form-select" required>
                                <option value="">Select Category</option>
                                <?php foreach ($feeCategories as $category): ?>
                                <option value="<?php echo $category['id']; ?>"><?php echo htmlspecialchars($category['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Amount (<?php echo $currencySymbol; ?>)</label>
                            <input type="number" name="amount" id="edit_fee_struct_amount" class="form-control" step="0.01" min="0" required>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Due Date</label>
                                <input type="date" name="due_date" id="edit_fee_struct_due" class="form-control">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Late Fee (<?php echo $currencySymbol; ?>)</label>
                                <input type="number" name="late_fee" id="edit_fee_struct_late" class="form-control" step="0.01" min="0">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Academic Term (Optional)</label>
                            <select name="academic_term_id" id="edit_fee_struct_term" class="form-select">
                                <option value="">Select Term</option>
                                <?php foreach ($academicTerms as $term): ?>
                                <option value="<?php echo $term['id']; ?>"><?php echo htmlspecialchars($term['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Fee Structure</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add API Key Modal -->
    <div class="modal fade" id="addApiKeyModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Generate API Key</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form class="modal-form" data-action="create_api_key">
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                        <input type="hidden" name="action" value="create_api_key">

                        <div class="mb-3">
                            <label class="form-label">Key Name</label>
                            <input type="text" name="name" class="form-control" placeholder="e.g., Mobile App" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Rate Limit (per minute)</label>
                            <input type="number" name="rate_limit_per_minute" class="form-control" value="60" min="1">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Expiration Date (optional)</label>
                            <input type="date" name="expires_at" class="form-control">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Generate</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://academixsuite.com/tenant/assets/js/lib/jquery-3.7.1.min.js"></script>
    <script src="https://academixsuite.com/tenant/assets/js/lib/bootstrap.bundle.min.js"></script>
    <script src="https://academixsuite.com/tenant/assets/js/lib/apexcharts.min.js"></script>
    <script src="https://academixsuite.com/tenant/assets/js/lib/iconify-icon.min.js"></script>
    <script src="https://academixsuite.com/tenant/assets/js/lib/dataTables.min.js"></script>
    <script src="https://academixsuite.com/tenant/assets/js/lib/jquery-ui.min.js"></script>
    <script src="https://academixsuite.com/tenant/assets/js/lib/flatpickr.min.js"></script>
    <script src="https://academixsuite.com/tenant/assets/js/app.js"></script>

    <script>
        // Toast function
        function showToast(message, type = 'success') {
            const toastHtml = `
                <div class="toast ${type} show" role="alert">
                    <div class="toast-header">
                        <i class="ri-${type === 'success' ? 'checkbox-circle' : type === 'error' ? 'error-warning' : 'information'}-line me-2"></i>
                        <strong class="me-auto">${type === 'success' ? 'Success' : type === 'error' ? 'Error' : 'Info'}</strong>
                        <button type="button" class="btn-close" data-bs-dismiss="toast"></button>
                    </div>
                    <div class="toast-body">${message}</div>
                </div>
            `;
            $('#toastContainer').append(toastHtml);
            setTimeout(() => $('.toast').first().remove(), 5000);
        }

        // CSRF token
        const csrfToken = '<?php echo $csrfToken; ?>';

        // Generic AJAX form submission
        $('.modal-form').on('submit', function(e) {
            e.preventDefault();
            const form = $(this);
            const action = form.data('action');
            const formData = form.serializeArray();
            // Add action to data
            formData.push({name: 'action', value: action});

            $.post(window.location.href, formData, function(response) {
                if (response.success) {
                    showToast(response.message, 'success');
                    form.closest('.modal').modal('hide');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showToast(response.message, 'error');
                }
            }, 'json').fail(() => {
                showToast('Request failed. Please try again.', 'error');
            });
        });

        // Edit button handlers
        $('.edit-year').on('click', function() {
            const id = $(this).data('id');
            $.post(window.location.href, {
                action: 'get_academic_year',
                id: id,
                csrf_token: csrfToken
            }, function(response) {
                if (response.success) {
                    const d = response.data;
                    $('#edit_year_id').val(d.id);
                    $('#edit_year_name').val(d.name);
                    $('#edit_year_start').val(d.start_date);
                    $('#edit_year_end').val(d.end_date);
                    $('#edit_year_status').val(d.status);
                    $('#edit_year_default').prop('checked', d.is_default == 1);
                    $('#editYearModal').modal('show');
                } else {
                    showToast(response.message, 'error');
                }
            }).fail(() => showToast('Failed to fetch data', 'error'));
        });

        $('.delete-year').on('click', function() {
            if (!confirm('Are you sure you want to delete this academic year?')) return;
            const id = $(this).data('id');
            $.post(window.location.href, {
                action: 'delete_academic_year',
                id: id,
                csrf_token: csrfToken
            }, function(response) {
                if (response.success) {
                    showToast(response.message, 'success');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showToast(response.message, 'error');
                }
            }).fail(() => showToast('Request failed', 'error'));
        });

        $('.edit-term').on('click', function() {
            const id = $(this).data('id');
            $.post(window.location.href, {
                action: 'get_academic_term',
                id: id,
                csrf_token: csrfToken
            }, function(response) {
                if (response.success) {
                    const d = response.data;
                    $('#edit_term_id').val(d.id);
                    $('#edit_term_name').val(d.name);
                    $('#edit_term_year').val(d.academic_year_id);
                    $('#edit_term_start').val(d.start_date);
                    $('#edit_term_end').val(d.end_date);
                    $('#edit_term_default').prop('checked', d.is_default == 1);
                    $('#editTermModal').modal('show');
                } else {
                    showToast(response.message, 'error');
                }
            }).fail(() => showToast('Failed to fetch data', 'error'));
        });

        $('.delete-term').on('click', function() {
            if (!confirm('Are you sure you want to delete this academic term?')) return;
            const id = $(this).data('id');
            $.post(window.location.href, {
                action: 'delete_academic_term',
                id: id,
                csrf_token: csrfToken
            }, function(response) {
                if (response.success) {
                    showToast(response.message, 'success');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showToast(response.message, 'error');
                }
            }).fail(() => showToast('Request failed', 'error'));
        });

        $('.edit-class').on('click', function() {
            const id = $(this).data('id');
            $.post(window.location.href, {
                action: 'get_class',
                id: id,
                csrf_token: csrfToken
            }, function(response) {
                if (response.success) {
                    const d = response.data;
                    $('#edit_class_id').val(d.id);
                    $('#edit_class_name').val(d.name);
                    $('#edit_class_code').val(d.code);
                    $('#edit_class_year').val(d.academic_year_id);
                    $('#edit_class_grade').val(d.grade_level || '');
                    $('#edit_class_capacity').val(d.capacity || 40);
                    $('#edit_class_room').val(d.room_number || '');
                    $('#edit_class_desc').val(d.description || '');
                    $('#editClassModal').modal('show');
                } else {
                    showToast(response.message, 'error');
                }
            }).fail(() => showToast('Failed to fetch data', 'error'));
        });

        $('.delete-class').on('click', function() {
            if (!confirm('Are you sure you want to delete this class?')) return;
            const id = $(this).data('id');
            $.post(window.location.href, {
                action: 'delete_class',
                id: id,
                csrf_token: csrfToken
            }, function(response) {
                if (response.success) {
                    showToast(response.message, 'success');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showToast(response.message, 'error');
                }
            }).fail(() => showToast('Request failed', 'error'));
        });

        $('.edit-section').on('click', function() {
            const id = $(this).data('id');
            $.post(window.location.href, {
                action: 'get_section',
                id: id,
                csrf_token: csrfToken
            }, function(response) {
                if (response.success) {
                    const d = response.data;
                    $('#edit_section_id').val(d.id);
                    $('#edit_section_name').val(d.name);
                    $('#edit_section_code').val(d.code);
                    $('#edit_section_class').val(d.class_id);
                    $('#edit_section_capacity').val(d.capacity || 40);
                    $('#edit_section_room').val(d.room_number || '');
                    $('#editSectionModal').modal('show');
                } else {
                    showToast(response.message, 'error');
                }
            }).fail(() => showToast('Failed to fetch data', 'error'));
        });

        $('.delete-section').on('click', function() {
            if (!confirm('Are you sure you want to delete this section?')) return;
            const id = $(this).data('id');
            $.post(window.location.href, {
                action: 'delete_section',
                id: id,
                csrf_token: csrfToken
            }, function(response) {
                if (response.success) {
                    showToast(response.message, 'success');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showToast(response.message, 'error');
                }
            }).fail(() => showToast('Request failed', 'error'));
        });

        $('.edit-subject').on('click', function() {
            const id = $(this).data('id');
            $.post(window.location.href, {
                action: 'get_subject',
                id: id,
                csrf_token: csrfToken
            }, function(response) {
                if (response.success) {
                    const d = response.data;
                    $('#edit_subject_id').val(d.id);
                    $('#edit_subject_name').val(d.name);
                    $('#edit_subject_code').val(d.code);
                    $('#edit_subject_type').val(d.type);
                    $('#edit_subject_credit').val(d.credit_hours || 1.0);
                    $('#edit_subject_desc').val(d.description || '');
                    $('#editSubjectModal').modal('show');
                } else {
                    showToast(response.message, 'error');
                }
            }).fail(() => showToast('Failed to fetch data', 'error'));
        });

        $('.delete-subject').on('click', function() {
            if (!confirm('Are you sure you want to delete this subject?')) return;
            const id = $(this).data('id');
            $.post(window.location.href, {
                action: 'delete_subject',
                id: id,
                csrf_token: csrfToken
            }, function(response) {
                if (response.success) {
                    showToast(response.message, 'success');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showToast(response.message, 'error');
                }
            }).fail(() => showToast('Request failed', 'error'));
        });

        $('.delete-assignment').on('click', function() {
            if (!confirm('Are you sure you want to remove this subject assignment?')) return;
            const id = $(this).data('id');
            $.post(window.location.href, {
                action: 'delete_assignment',
                id: id,
                csrf_token: csrfToken
            }, function(response) {
                if (response.success) {
                    showToast(response.message, 'success');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showToast(response.message, 'error');
                }
            }).fail(() => showToast('Request failed', 'error'));
        });

        $('.edit-payment-method').on('click', function() {
            const id = $(this).data('id');
            $.post(window.location.href, {
                action: 'get_payment_method',
                id: id,
                csrf_token: csrfToken
            }, function(response) {
                if (response.success) {
                    const d = response.data;
                    $('#edit_payment_id').val(d.id);
                    $('#edit_payment_type').val(d.type);
                    $('#edit_payment_provider').val(d.provider || '');
                    const metadata = typeof d.metadata === 'string'
                        ? JSON.parse(d.metadata || '{}')
                        : (d.metadata || {});
                    $('#edit_payment_acc_name').val(metadata.account_name || '');
                    $('#edit_payment_acc_num').val(metadata.account_number || '');
                    $('#edit_payment_exp_month').val(d.exp_month || '');
                    $('#edit_payment_exp_year').val(d.exp_year || '');
                    $('#edit_payment_default').prop('checked', d.is_default == 1);
                    $('#edit_payment_verified').prop('checked', d.is_verified == 1);
                    $('#editPaymentMethodModal').modal('show');
                } else {
                    showToast(response.message, 'error');
                }
            }).fail(() => showToast('Failed to fetch data', 'error'));
        });

        $('.delete-payment-method').on('click', function() {
            if (!confirm('Are you sure you want to delete this payment method?')) return;
            const id = $(this).data('id');
            $.post(window.location.href, {
                action: 'delete_payment_method',
                id: id,
                csrf_token: csrfToken
            }, function(response) {
                if (response.success) {
                    showToast(response.message, 'success');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showToast(response.message, 'error');
                }
            }).fail(() => showToast('Request failed', 'error'));
        });

        $('.edit-fee-category').on('click', function() {
            const id = $(this).data('id');
            $.post(window.location.href, {
                action: 'get_fee_category',
                id: id,
                csrf_token: csrfToken
            }, function(response) {
                if (response.success) {
                    const d = response.data;
                    $('#edit_fee_cat_id').val(d.id);
                    $('#edit_fee_cat_name').val(d.name);
                    $('#edit_fee_cat_desc').val(d.description || '');
                    $('#editFeeCategoryModal').modal('show');
                } else {
                    showToast(response.message, 'error');
                }
            }).fail(() => showToast('Failed to fetch data', 'error'));
        });

        $('.delete-fee-category').on('click', function() {
            if (!confirm('Are you sure you want to delete this fee category?')) return;
            const id = $(this).data('id');
            $.post(window.location.href, {
                action: 'delete_fee_category',
                id: id,
                csrf_token: csrfToken
            }, function(response) {
                if (response.success) {
                    showToast(response.message, 'success');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showToast(response.message, 'error');
                }
            }).fail(() => showToast('Request failed', 'error'));
        });

        $('.edit-fee-structure').on('click', function() {
            const id = $(this).data('id');
            $.post(window.location.href, {
                action: 'get_fee_structure',
                id: id,
                csrf_token: csrfToken
            }, function(response) {
                if (response.success) {
                    const d = response.data;
                    $('#edit_fee_struct_id').val(d.id);
                    $('#edit_fee_struct_year').val(d.academic_year_id);
                    $('#edit_fee_struct_class').val(d.class_id);
                    $('#edit_fee_struct_cat').val(d.fee_category_id);
                    $('#edit_fee_struct_amount').val(d.amount);
                    $('#edit_fee_struct_due').val(d.due_date || '');
                    $('#edit_fee_struct_late').val(d.late_fee || 0);
                    $('#edit_fee_struct_term').val(d.academic_term_id || '');
                    $('#editFeeStructureModal').modal('show');
                } else {
                    showToast(response.message, 'error');
                }
            }).fail(() => showToast('Failed to fetch data', 'error'));
        });

        $('.delete-fee-structure').on('click', function() {
            if (!confirm('Are you sure you want to delete this fee structure?')) return;
            const id = $(this).data('id');
            $.post(window.location.href, {
                action: 'delete_fee_structure',
                id: id,
                csrf_token: csrfToken
            }, function(response) {
                if (response.success) {
                    showToast(response.message, 'success');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showToast(response.message, 'error');
                }
            }).fail(() => showToast('Request failed', 'error'));
        });

        $('.copy-api-key').on('click', function() {
            const key = $(this).data('key');
            navigator.clipboard.writeText(key).then(() => {
                showToast('API key copied to clipboard', 'info');
            }).catch(() => showToast('Failed to copy', 'error'));
        });

        $('.delete-api-key').on('click', function() {
            if (!confirm('Are you sure you want to delete this API key?')) return;
            const id = $(this).data('id');
            $.post(window.location.href, {
                action: 'delete_api_key',
                id: id,
                csrf_token: csrfToken
            }, function(response) {
                if (response.success) {
                    showToast(response.message, 'success');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showToast(response.message, 'error');
                }
            }).fail(() => showToast('Request failed', 'error'));
        });

        // ── General Settings form (AJAX with file-upload support) ──────────
        (function () {
            // Find the hidden action input inside the general settings form
            const hiddenAction = document.querySelector('input[type="hidden"][name="action"][value="update_general"]');
            if (!hiddenAction) return;
            const generalForm = hiddenAction.closest('form');
            if (!generalForm) return;

            generalForm.addEventListener('submit', function (e) {
                e.preventDefault();

                const fd = new FormData(generalForm);
                fd.set('csrf_token', csrfToken);
                // action is already in the hidden input; ensure it's present
                if (!fd.has('action')) fd.set('action', 'update_general');

                const btn = generalForm.querySelector('[type="submit"]');
                if (btn) { btn.disabled = true; btn.textContent = 'Saving…'; }

                fetch(window.location.href, {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: fd
                })
                .then(r => r.json())
                .then(res => {
                    showToast(res.message || (res.success ? 'Saved' : 'Error'), res.success ? 'success' : 'error');
                    if (res.success) {
                        // Refresh after a short delay so logo/favicon previews update
                        setTimeout(() => location.reload(), 1600);
                    }
                })
                .catch(() => showToast('Request failed. Please try again.', 'error'))
                .finally(() => {
                    if (btn) { btn.disabled = false; btn.textContent = 'Save Changes'; }
                });
            });
        })();

        // Initialize flatpickr
        flatpickr("input[type=date]", { dateFormat: "Y-m-d" });

        // Initialize DataTables
        $(document).ready(function() {
            $('.datatable').DataTable({
                pageLength: 10,
                responsive: true,
                language: {
                    search: "Search:",
                    lengthMenu: "Show _MENU_ entries",
                    info: "Showing _START_ to _END_ of _TOTAL_ entries",
                    paginate: { first: "First", last: "Last", next: "Next", previous: "Previous" }
                }
            });
        });

        // Create backup function
        function createBackup() {
            if (confirm('Are you sure you want to create a database backup? This may take a few moments.')) {
                $('#backupForm').submit();
            }
        }
    </script>
</body>
</html>
