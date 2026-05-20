<?php
/**
 * Add Timetable Page
 * Allows admin to create new timetable entries
 * 
 * @package AcademixSuite
 * @version 2.0
 */

// Enable error reporting
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../../logs/school_add_timetable.log');

error_log("=== ADD TIMETABLE PAGE START ===");
error_log("Request URI: " . ($_SERVER['REQUEST_URI'] ?? 'N/A'));
error_log("Script: " . __FILE__);

// Define constants
defined('APP_NAME') or define('APP_NAME', 'AcademixSuite');
defined('IS_LOCAL') or define('IS_LOCAL', true);

// Start session
try {
    if (session_status() === PHP_SESSION_NONE) {
        session_start([
            'cookie_lifetime' => 86400,
            'read_and_close'  => false,
        ]);
    }
} catch (Exception $e) {
    error_log("Session error: " . $e->getMessage());
}

// Get school slug from GLOBALS
$schoolSlug = $GLOBALS['SCHOOL_SLUG'] ?? '';
$userType = $GLOBALS['USER_TYPE'] ?? 'admin';
$schoolData = $GLOBALS['SCHOOL_DATA'] ?? [];
$baseUrl = $GLOBALS['BASE_URL'] ?? '';

if (empty($schoolSlug)) {
    error_log("ERROR: Empty school slug from router");
    header('HTTP/1.1 400 Bad Request');
    echo json_encode(['error' => 'School identifier missing']);
    exit;
}

// Get school info
$school = $schoolData;
if (empty($school) && isset($_SESSION['school_info'][$schoolSlug])) {
    $school = $_SESSION['school_info'][$schoolSlug];
}

if (empty($school)) {
    error_log("ERROR: School data not found for slug: " . $schoolSlug);
    header("Location: ../../login.php?school_slug=" . urlencode($schoolSlug));
    exit;
}

// Check authentication
$isAuthenticated = false;
if (isset($_SESSION['school_auth']) && is_array($_SESSION['school_auth'])) {
    if (($_SESSION['school_auth']['school_slug'] ?? '') === $schoolSlug) {
        $isAuthenticated = true;
    }
}

if (!$isAuthenticated) {
    error_log("User not authenticated");
    header('Location: ../../login.php?school_slug=' . urlencode($schoolSlug));
    exit;
}

// Get user info
$schoolAuth = $_SESSION['school_auth'];
$userId = (int)($schoolAuth['user_id'] ?? 0);
$userType = $schoolAuth['user_type'] ?? '';

// Verify admin access
if ($userType !== 'admin') {
    error_log("ERROR: User does not have admin privileges");
    header('HTTP/1.1 403 Forbidden');
    die("Access denied. Admin privileges required.");
}

// Load configuration
try {
    $autoloadPath = __DIR__ . '/../../../includes/autoload.php';
    if (!file_exists($autoloadPath)) {
        throw new Exception("Autoload file not found");
    }
    require_once $autoloadPath;
    
    if (!class_exists('Database')) {
        throw new Exception("Database class not found");
    }
    
    // Include TimetableManager
    $timetableManagerPath = __DIR__ . '/../../../includes/TimetableManager.php';
    if (!file_exists($timetableManagerPath)) {
        throw new Exception("TimetableManager file not found");
    }
    require_once $timetableManagerPath;
    
} catch (Exception $e) {
    error_log("Error loading files: " . $e->getMessage());
    http_response_code(500);
    die("Configuration loading failed.");
}

// Connect to school database
$schoolDb = null;
$timetableManager = null;
try {
    if (!empty($school['database_name'])) {
        $schoolDb = Database::getSchoolConnection($school['database_name']);
        error_log("School database connection successful");
        
        // Initialize TimetableManager
        $timetableManager = new TimetableManager($schoolDb, $school['id'], $userId, $userType, $school);
        error_log("TimetableManager initialized successfully");
    }
} catch (Exception $e) {
    error_log("ERROR connecting to school database: " . $e->getMessage());
    $schoolDb = null;
}

// Initialize variables
$settings = [];
$classes = [];
$sections = [];
$academicYears = [];
$academicTerms = [];
$subjects = [];
$teachers = [];
$days = $timetableManager ? $timetableManager->getDays() : [];
$periodNumbers = range(1, 10); // Max 10 periods per day
$adminUser = ['name' => 'Admin User', 'role_name' => 'Administrator'];
$formData = $_POST;
$toastSuccess = $_SESSION['toast_success'] ?? '';
$toastError = $_SESSION['toast_error'] ?? '';

// Clear session toasts
unset($_SESSION['toast_success'], $_SESSION['toast_error']);

// Fetch data from database
if ($schoolDb) {
    try {
        // Get school settings
        $settingsStmt = $schoolDb->prepare("SELECT `key`, `value` FROM settings WHERE school_id = ?");
        if ($settingsStmt) {
            $settingsStmt->execute([$school['id']]);
            while ($row = $settingsStmt->fetch(PDO::FETCH_ASSOC)) {
                $settings[$row['key']] = $row['value'];
            }
        }

        // Get logged in user details
        $userStmt = $schoolDb->prepare("
            SELECT u.*, r.name as role_name 
            FROM users u 
            LEFT JOIN user_roles ur ON u.id = ur.user_id
            LEFT JOIN roles r ON ur.role_id = r.id
            WHERE u.id = ? AND u.school_id = ?
            LIMIT 1
        ");
        if ($userStmt) {
            $userStmt->execute([$userId, $school['id']]);
            $adminUserData = $userStmt->fetch(PDO::FETCH_ASSOC);
            if ($adminUserData) {
                $adminUser = $adminUserData;
            }
        }

        // Get academic years
        $yearStmt = $schoolDb->prepare("
            SELECT * FROM academic_years 
            WHERE school_id = ? AND status IN ('active', 'upcoming')
            ORDER BY is_default DESC, start_date DESC
        ");
        if ($yearStmt) {
            $yearStmt->execute([$school['id']]);
            $academicYears = $yearStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // Get academic terms
        $termStmt = $schoolDb->prepare("
            SELECT at.*, ay.name as academic_year_name
            FROM academic_terms at
            JOIN academic_years ay ON at.academic_year_id = ay.id
            WHERE at.school_id = ?
            ORDER BY ay.start_date DESC, at.start_date
        ");
        if ($termStmt) {
            $termStmt->execute([$school['id']]);
            $academicTerms = $termStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // Get classes
        $classStmt = $schoolDb->prepare("
            SELECT c.*, ay.name as academic_year_name 
            FROM classes c
            LEFT JOIN academic_years ay ON c.academic_year_id = ay.id
            WHERE c.school_id = ? AND c.is_active = 1
            ORDER BY c.name
        ");
        if ($classStmt) {
            $classStmt->execute([$school['id']]);
            $classes = $classStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // Get subjects
        $subjectStmt = $schoolDb->prepare("
            SELECT * FROM subjects 
            WHERE school_id = ? AND is_active = 1
            ORDER BY name
        ");
        if ($subjectStmt) {
            $subjectStmt->execute([$school['id']]);
            $subjects = $subjectStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // Get teachers (users with teacher role)
        $teacherStmt = $schoolDb->prepare("
            SELECT u.id, u.name, u.email, t.employee_id
            FROM users u
            JOIN teachers t ON u.id = t.user_id
            WHERE u.school_id = ? AND u.user_type = 'teacher' AND u.is_active = 1
            ORDER BY u.name
        ");
        if ($teacherStmt) {
            $teacherStmt->execute([$school['id']]);
            $teachers = $teacherStmt->fetchAll(PDO::FETCH_ASSOC);
        }

    } catch (Exception $e) {
        error_log("Error fetching data: " . $e->getMessage());
        $toastError = "Error loading form data. Please refresh.";
    }
}

// Helper functions
if (!function_exists('sanitize')) {
    function sanitize($input) {
        if ($input === null) return null;
        return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('generateCsrfToken')) {
    function generateCsrfToken() {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('validateCsrfToken')) {
    function validateCsrfToken($token) {
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit']) && $timetableManager) {
    
    error_log("=== PROCESSING TIMETABLE FORM SUBMISSION ===");
    
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        $toastError = "Invalid security token. Please try again.";
        $_SESSION['form_data'] = $_POST;
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
    
    // Prepare timetable data
    $timetableData = [
        'academic_year_id' => !empty($_POST['academic_year']) ? (int)$_POST['academic_year'] : null,
        'academic_term_id' => !empty($_POST['academic_term']) ? (int)$_POST['academic_term'] : null,
        'class_id' => !empty($_POST['class']) ? (int)$_POST['class'] : null,
        'section_id' => !empty($_POST['section']) ? (int)$_POST['section'] : null,
        'day' => $_POST['day'] ?? null,
        'period_number' => !empty($_POST['period_number']) ? (int)$_POST['period_number'] : null,
        'start_time' => $_POST['start_time'] ?? null,
        'end_time' => $_POST['end_time'] ?? null,
        'subject_id' => !empty($_POST['subject']) ? (int)$_POST['subject'] : null,
        'teacher_id' => !empty($_POST['teacher']) ? (int)$_POST['teacher'] : null,
        'room_number' => sanitize($_POST['room_number'] ?? ''),
        'is_break' => isset($_POST['is_break']) ? 1 : 0
    ];

    error_log("Timetable data prepared");

    // Validate required fields
    $requiredFields = ['academic_year_id', 'academic_term_id', 'class_id', 'day', 'period_number', 'start_time', 'end_time'];
    $missingFields = [];
    
    foreach ($requiredFields as $field) {
        if (empty($timetableData[$field])) {
            $missingFields[] = str_replace('_', ' ', $field);
        }
    }

    // If not a break period, subject and teacher are required
    if (!$timetableData['is_break']) {
        if (empty($timetableData['subject_id'])) {
            $missingFields[] = 'subject';
        }
        if (empty($timetableData['teacher_id'])) {
            $missingFields[] = 'teacher';
        }
    }

    if (!empty($missingFields)) {
        $toastError = "Please fill all required fields: " . implode(', ', $missingFields);
        $_SESSION['form_data'] = $_POST;
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
    
    // Add timetable using TimetableManager
    $result = $timetableManager->addTimetable($timetableData);
    
    if ($result[0]) {
        $toastSuccess = $result[1];
        error_log("Timetable added successfully: " . $result[1]);
        
        // Clear form data
        unset($_SESSION['form_data']);
        $_POST = [];
        
        // Redirect to timetable list
        header("Location: timetable-list.php");
        exit;
    } else {
        $toastError = $result[1];
        error_log("Failed to add timetable: " . $result[1]);
        $_SESSION['form_data'] = $_POST;
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
}

// Get form data from session for repopulation
if (isset($_SESSION['form_data']) && !empty($_SESSION['form_data'])) {
    $formData = $_SESSION['form_data'];
    unset($_SESSION['form_data']);
}

// Generate CSRF token
$csrfToken = generateCsrfToken();

// Get sections for selected class via AJAX
$selectedClass = $formData['class'] ?? 0;
$sections = [];
if ($selectedClass && $schoolDb) {
    $sectionStmt = $schoolDb->prepare("
        SELECT id, name, capacity 
        FROM sections 
        WHERE school_id = ? AND class_id = ? AND is_active = 1
        ORDER BY name
    ");
    $sectionStmt->execute([$school['id'], $selectedClass]);
    $sections = $sectionStmt->fetchAll(PDO::FETCH_ASSOC);
}

error_log("=== ADD TIMETABLE PAGE END ===");
?>

<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="description" content="Add Timetable - School Management System">
    <meta name="keywords" content="Add Timetable, School Management">
    <meta name="robots" content="INDEX,FOLLOW">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Timetable - <?php echo htmlspecialchars($school['name'] ?? 'School'); ?></title>
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
            animation: slideIn 0.3s ease;
        }
        .toast.success {
            border-left-color: #28a745;
        }
        .toast.success .toast-header {
            background-color: #d4edda;
            color: #155724;
        }
        .toast.error {
            border-left-color: #dc3545;
        }
        .toast.error .toast-header {
            background-color: #f8d7da;
            color: #721c24;
        }
        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
        .time-input-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .time-separator {
            font-weight: 600;
            color: #6c757d;
        }
        .break-checkbox {
            margin-top: 32px;
        }
        .subject-teacher-fields {
            transition: opacity 0.3s ease;
        }
        .subject-teacher-fields.disabled {
            opacity: 0.5;
            pointer-events: none;
        }
    </style>
</head>
<body>

<!-- Toast Container -->
<div class="toast-container" id="toastContainer">
    <?php if (!empty($toastSuccess)): ?>
    <div class="toast success show" role="alert" aria-live="assertive" aria-atomic="true" data-autohide="true" data-delay="5000">
        <div class="toast-header">
            <i class="ri-checkbox-circle-line me-2"></i>
            <strong class="me-auto">Success</strong>
            <small>just now</small>
            <button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>
        <div class="toast-body">
            <?php echo htmlspecialchars($toastSuccess); ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($toastError)): ?>
    <div class="toast error show" role="alert" aria-live="assertive" aria-atomic="true" data-autohide="true" data-delay="5000">
        <div class="toast-header">
            <i class="ri-error-warning-line me-2"></i>
            <strong class="me-auto">Error</strong>
            <small>just now</small>
            <button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>
        <div class="toast-body">
            <?php echo htmlspecialchars($toastError); ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Theme Customization Structure -->
<div class="body-overlay"></div>
<button type="button" class="theme-customization__button w-48-px h-48-px bg-primary-600 text-white rounded-circle d-flex justify-content-center align-items-center position-fixed end-0 bottom-0 mb-40 me-40 text-2xxl bg-hover-primary-700" aria-label="Theme Customization Button">
    <i class="ri-settings-3-line animate-spin"></i>
</button>

<div class="overlay bg-black bg-opacity-50 w-100 h-100 position-fixed z-9 visibility-hidden opacity-0 duration-300"></div>

<!-- Sidebar -->
<?php include_once('includes/sidebar.php'); ?>

<main class="dashboard-main">
    <div class="navbar-header shadow-1">
        <div class="row align-items-center justify-content-between">
            <div class="col-auto">
                <div class="d-flex flex-wrap align-items-center gap-4">
                    <button type="button" class="sidebar-mobile-toggle" aria-label="Sidebar Mobile Toggler Button">
                        <iconify-icon icon="heroicons:bars-3-solid" class="icon"></iconify-icon>
                    </button>
                    <form class="navbar-search" method="GET" action="timetable-list.php">
                        <input type="text" class="bg-transparent" name="search" placeholder="Search timetables...">
                        <iconify-icon icon="ion:search-outline" class="icon"></iconify-icon>
                    </form>
                </div>
            </div>
            <div class="col-auto">
                <div class="d-flex flex-wrap align-items-center gap-3">
                    <button type="button" data-theme-toggle class="w-40-px h-40-px bg-neutral-200 rounded-circle d-flex justify-content-center align-items-center" aria-label="Dark & Light Mode Button"></button>
                    <div class="dropdown">
                        <button class="has-indicator w-40-px h-40-px bg-neutral-200 rounded-circle d-flex justify-content-center align-items-center position-relative" type="button" data-bs-toggle="dropdown" aria-label="Notification Button">
                            <iconify-icon icon="iconoir:bell" class="text-primary-light text-xl"></iconify-icon>
                        </button>
                        <div class="dropdown-menu to-top dropdown-menu-lg p-0">
                            <div class="text-center py-20">
                                <p class="text-secondary-light">No new notifications</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="dashboard-main-body">
        <div class="breadcrumb d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
            <div class="">
                <h1 class="fw-semibold mb-4 h6 text-primary-light">Add Timetable Entry</h1>
                <div class="">
                    <a href="index.php" class="text-secondary-light hover-text-primary hover-underline">Dashboard</a>
                    <a href="timetable-list.php" class="text-secondary-light hover-text-primary hover-underline"> / Timetable</a>
                    <span class="text-secondary-light">/ Add Timetable</span>
                </div>
            </div>
            <a href="timetable-list.php" class="btn btn-outline-primary d-flex align-items-center gap-6">
                <span class="d-flex text-md">
                    <i class="ri-list-view"></i>
                </span>
                View Timetable
            </a>
        </div>

        <div class="row">
            <div class="col-lg-8 mx-auto">
                <div class="shadow-1 radius-12 bg-base overflow-hidden">
                    <div class="card-header border-bottom bg-base py-16 px-24">
                        <h6 class="text-lg fw-semibold mb-0">Timetable Information</h6>
                        <p class="text-secondary-light mb-0">Fill in the details to create a new timetable entry</p>
                    </div>
                    <div class="card-body p-24">
                        <form method="POST" action="" id="timetableForm">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                            <input type="hidden" name="submit" value="1">
                            
                            <div class="row g-4">
                                <!-- Academic Year -->
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="academic_year" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                            Academic Year <span class="text-danger-600">*</span>
                                        </label>
                                        <select class="form-control form-select" id="academic_year" name="academic_year" required>
                                            <option value="">Select Academic Year</option>
                                            <?php foreach ($academicYears as $year): ?>
                                            <option value="<?php echo $year['id']; ?>" <?php echo (isset($formData['academic_year']) && $formData['academic_year'] == $year['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($year['name']); ?>
                                                <?php echo $year['is_default'] ? ' (Default)' : ''; ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>

                                <!-- Academic Term -->
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="academic_term" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                            Academic Term <span class="text-danger-600">*</span>
                                        </label>
                                        <select class="form-control form-select" id="academic_term" name="academic_term" required>
                                            <option value="">Select Academic Term</option>
                                            <?php foreach ($academicTerms as $term): ?>
                                            <option value="<?php echo $term['id']; ?>" <?php echo (isset($formData['academic_term']) && $formData['academic_term'] == $term['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($term['name']); ?>
                                                <?php echo !empty($term['academic_year_name']) ? '(' . htmlspecialchars($term['academic_year_name']) . ')' : ''; ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>

                                <!-- Class -->
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="class" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                            Class <span class="text-danger-600">*</span>
                                        </label>
                                        <select class="form-control form-select" id="class" name="class" required>
                                            <option value="">Select Class</option>
                                            <?php foreach ($classes as $class): ?>
                                            <option value="<?php echo $class['id']; ?>" <?php echo (isset($formData['class']) && $formData['class'] == $class['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($class['name']); ?>
                                                <?php echo !empty($class['code']) ? '(' . htmlspecialchars($class['code']) . ')' : ''; ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>

                                <!-- Section -->
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="section" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                            Section
                                        </label>
                                        <select class="form-control form-select" id="section" name="section">
                                            <option value="">Select Section (Optional)</option>
                                            <?php foreach ($sections as $section): ?>
                                            <option value="<?php echo $section['id']; ?>" <?php echo (isset($formData['section']) && $formData['section'] == $section['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($section['name']); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>

                                <!-- Day -->
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="day" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                            Day <span class="text-danger-600">*</span>
                                        </label>
                                        <select class="form-control form-select" id="day" name="day" required>
                                            <option value="">Select Day</option>
                                            <?php foreach ($days as $day): ?>
                                            <option value="<?php echo $day; ?>" <?php echo (isset($formData['day']) && $formData['day'] == $day) ? 'selected' : ''; ?>>
                                                <?php echo ucfirst($day); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>

                                <!-- Period Number -->
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="period_number" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                            Period Number <span class="text-danger-600">*</span>
                                        </label>
                                        <select class="form-control form-select" id="period_number" name="period_number" required>
                                            <option value="">Select Period</option>
                                            <?php foreach ($periodNumbers as $num): ?>
                                            <option value="<?php echo $num; ?>" <?php echo (isset($formData['period_number']) && $formData['period_number'] == $num) ? 'selected' : ''; ?>>
                                                Period <?php echo $num; ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>

                                <!-- Start Time -->
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="start_time" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                            Start Time <span class="text-danger-600">*</span>
                                        </label>
                                        <input type="time" class="form-control" id="start_time" name="start_time" 
                                               value="<?php echo htmlspecialchars($formData['start_time'] ?? '09:00'); ?>" required>
                                    </div>
                                </div>

                                <!-- End Time -->
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="end_time" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                            End Time <span class="text-danger-600">*</span>
                                        </label>
                                        <input type="time" class="form-control" id="end_time" name="end_time" 
                                               value="<?php echo htmlspecialchars($formData['end_time'] ?? '09:45'); ?>" required>
                                    </div>
                                </div>

                                <!-- Break Period Checkbox -->
                                <div class="col-12">
                                    <div class="form-check break-checkbox">
                                        <input class="form-check-input" type="checkbox" id="is_break" name="is_break" 
                                               <?php echo isset($formData['is_break']) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="is_break">
                                            This is a break period (no subject/teacher required)
                                        </label>
                                    </div>
                                </div>

                                <!-- Subject and Teacher Fields (hidden if break) -->
                                <div class="col-md-6 subject-teacher-fields" id="subjectField">
                                    <div class="form-group">
                                        <label for="subject" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                            Subject
                                        </label>
                                        <select class="form-control form-select" id="subject" name="subject">
                                            <option value="">Select Subject</option>
                                            <?php foreach ($subjects as $subject): ?>
                                            <option value="<?php echo $subject['id']; ?>" <?php echo (isset($formData['subject']) && $formData['subject'] == $subject['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($subject['name']); ?>
                                                <?php echo !empty($subject['code']) ? '(' . htmlspecialchars($subject['code']) . ')' : ''; ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>

                                <div class="col-md-6 subject-teacher-fields" id="teacherField">
                                    <div class="form-group">
                                        <label for="teacher" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                            Teacher
                                        </label>
                                        <select class="form-control form-select" id="teacher" name="teacher">
                                            <option value="">Select Teacher</option>
                                            <?php foreach ($teachers as $teacher): ?>
                                            <option value="<?php echo $teacher['id']; ?>" <?php echo (isset($formData['teacher']) && $formData['teacher'] == $teacher['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($teacher['name']); ?>
                                                <?php echo !empty($teacher['employee_id']) ? '(' . htmlspecialchars($teacher['employee_id']) . ')' : ''; ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>

                                <!-- Room Number -->
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="room_number" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                            Room Number
                                        </label>
                                        <input type="text" class="form-control" id="room_number" name="room_number" 
                                               value="<?php echo htmlspecialchars($formData['room_number'] ?? ''); ?>"
                                               placeholder="e.g., Room 101">
                                    </div>
                                </div>

                                <!-- Submit Buttons -->
                                <div class="col-12">
                                    <div class="d-flex align-items-center justify-content-center gap-3 mt-8">
                                        <a href="timetable-list.php" class="border border-danger-600 bg-hover-danger-200 text-danger-600 text-md px-50 py-11 radius-8 text-decoration-none">
                                            Cancel
                                        </a>
                                        <button type="submit" class="btn btn-primary-600 border border-primary-600 text-md px-28 py-12 radius-8">
                                            <i class="ri-save-line me-2"></i>Save Timetable
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Guidelines -->
                <div class="mt-24 p-20 bg-neutral-50 radius-12">
                    <h6 class="fw-semibold mb-3"><i class="ri-information-line text-primary-600 me-2"></i>Timetable Guidelines</h6>
                    <ul class="text-secondary-light mb-0" style="font-size: 14px;">
                        <li class="mb-2">✓ Ensure no time conflicts for the same teacher</li>
                        <li class="mb-2">✓ Ensure no room conflicts for the same period</li>
                        <li class="mb-2">✓ Break periods don't require subject/teacher assignment</li>
                        <li class="mb-2">✓ Each class can have multiple periods per day</li>
                        <li class="mb-2">✓ Period numbers should be sequential</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <footer class="d-footer">
        <div class="">
            <p class="mb-0 text-center"> &copy; <span class="current-year"></span> <?php echo htmlspecialchars($school['name'] ?? 'School'); ?> | Made With ❤️ by AcademixSuite.</p>
        </div>
    </footer>
</main>

<script src="https://academixsuite.com/tenant/assets/js/lib/jquery-3.7.1.min.js"></script>
<script src="https://academixsuite.com/tenant/assets/js/lib/bootstrap.bundle.min.js"></script>
<script src="https://academixsuite.com/tenant/assets/js/lib/iconify-icon.min.js"></script>
<script src="https://academixsuite.com/tenant/assets/js/lib/dataTables.min.js"></script>
<script src="https://academixsuite.com/tenant/assets/js/app.js"></script>

<script>
$(document).ready(function() {
    // Initialize Bootstrap toasts
    $('.toast').toast({
        autohide: true,
        delay: 5000
    });
    $('.toast').toast('show');

    // Handle break period checkbox
    $('#is_break').on('change', function() {
        if ($(this).is(':checked')) {
            $('#subjectField, #teacherField').addClass('disabled');
            $('#subject, #teacher').prop('required', false);
        } else {
            $('#subjectField, #teacherField').removeClass('disabled');
            $('#subject, #teacher').prop('required', true);
        }
    });

    // Trigger on page load
    $('#is_break').trigger('change');

    // Load sections when class changes
    $('#class').on('change', function() {
        const classId = $(this).val();
        if (classId) {
            $.ajax({
                url: 'ajax/get-sections.php',
                method: 'POST',
                data: {
                    class_id: classId,
                    school_id: <?php echo $school['id']; ?>
                },
                dataType: 'json',
                beforeSend: function() {
                    $('#section').html('<option value="">Loading...</option>').prop('disabled', true);
                },
                success: function(response) {
                    let options = '<option value="">Select Section (Optional)</option>';
                    if (response.success && response.sections.length > 0) {
                        $.each(response.sections, function(index, section) {
                            options += '<option value="' + section.id + '">' + section.name + '</option>';
                        });
                    }
                    $('#section').html(options).prop('disabled', false);
                },
                error: function() {
                    $('#section').html('<option value="">Error loading sections</option>');
                }
            });
        } else {
            $('#section').html('<option value="">Select Section (Optional)</option>').prop('disabled', false);
        }
    });

    // Load subjects based on class (optional - for class-specific subjects)
    $('#class').on('change', function() {
        const classId = $(this).val();
        if (classId) {
            $.ajax({
                url: 'ajax/get-class-subjects.php',
                method: 'POST',
                data: {
                    class_id: classId,
                    school_id: <?php echo $school['id']; ?>
                },
                dataType: 'json',
                beforeSend: function() {
                    $('#subject').html('<option value="">Loading...</option>').prop('disabled', true);
                },
                success: function(response) {
                    let options = '<option value="">Select Subject</option>';
                    if (response.success && response.subjects.length > 0) {
                        $.each(response.subjects, function(index, subject) {
                            options += '<option value="' + subject.id + '">' + subject.name + ' (' + subject.code + ')</option>';
                        });
                    } else {
                        // Fallback to all subjects
                        options = '<option value="">Select Subject</option>';
                        <?php foreach ($subjects as $subject): ?>
                        options += '<option value="<?php echo $subject['id']; ?>"><?php echo htmlspecialchars($subject['name']); ?></option>';
                        <?php endforeach; ?>
                    }
                    $('#subject').html(options).prop('disabled', false);
                },
                error: function() {
                    // Fallback to all subjects
                    let options = '<option value="">Select Subject</option>';
                    <?php foreach ($subjects as $subject): ?>
                    options += '<option value="<?php echo $subject['id']; ?>"><?php echo htmlspecialchars($subject['name']); ?></option>';
                    <?php endforeach; ?>
                    $('#subject').html(options).prop('disabled', false);
                }
            });
        }
    });

    // Form validation
    $('#timetableForm').on('submit', function(e) {
        const academicYear = $('#academic_year').val();
        const academicTerm = $('#academic_term').val();
        const classId = $('#class').val();
        const day = $('#day').val();
        const period = $('#period_number').val();
        const startTime = $('#start_time').val();
        const endTime = $('#end_time').val();
        const isBreak = $('#is_break').is(':checked');
        
        if (!academicYear || !academicTerm || !classId || !day || !period || !startTime || !endTime) {
            e.preventDefault();
            alert('Please fill in all required fields');
            return false;
        }
        
        // Validate time (start time should be before end time)
        if (startTime >= endTime) {
            e.preventDefault();
            alert('End time must be after start time');
            return false;
        }
        
        // If not break, validate subject and teacher
        if (!isBreak) {
            const subject = $('#subject').val();
            const teacher = $('#teacher').val();
            
            if (!subject || !teacher) {
                e.preventDefault();
                alert('Please select both subject and teacher for regular periods');
                return false;
            }
        }
        
        // Show loading state
        $(this).find('button[type="submit"]').prop('disabled', true)
               .html('<span class="spinner-border spinner-border-sm me-2"></span>Saving...');
        
        return true;
    });

    // Set current year in footer
    $('.current-year').text(new Date().getFullYear());
});
</script>

</body>
</html>