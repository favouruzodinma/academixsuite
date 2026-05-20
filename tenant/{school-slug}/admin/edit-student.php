<?php
/**
 * School Edit Student Page
 * Handles editing existing student information using StudentManager
 * 
 * @package AcademixSuite
 * @version 2.2 (with notifications)
 */

// Enable error reporting
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../../logs/school_edit_student.log');

error_log("=== EDIT STUDENT PAGE START ===");
error_log("Request URI: " . ($_SERVER['REQUEST_URI'] ?? 'N/A'));
error_log("Script: " . __FILE__);
error_log("Request Method: " . $_SERVER['REQUEST_METHOD']);
error_log("Session ID: " . (session_id() ?: 'No session'));

// Define constants
defined('APP_NAME') or define('APP_NAME', 'AcademixSuite');
defined('IS_LOCAL') or define('IS_LOCAL', true);

/**
 * Initialize session safely
 */
function initializeSession() {
    try {
        if (session_status() === PHP_SESSION_NONE) {
            session_start([
                'cookie_lifetime' => 86400,
                'read_and_close' => false,
                'cookie_secure' => !IS_LOCAL,
                'cookie_httponly' => true,
                'cookie_samesite' => 'Lax'
            ]);
            error_log("Session started successfully");
        }
        return true;
    } catch (Exception $e) {
        error_log("Session initialization error: " . $e->getMessage());
        return false;
    }
}
initializeSession();

/**
 * Get school slug from router
 */
$schoolSlug = $GLOBALS['SCHOOL_SLUG'] ?? '';
$userType = $GLOBALS['USER_TYPE'] ?? 'admin';
$currentPage = $GLOBALS['CURRENT_PAGE'] ?? 'edit-student.php';
$schoolData = $GLOBALS['SCHOOL_DATA'] ?? [];
$baseUrl = $GLOBALS['BASE_URL'] ?? '';

error_log("School Slug: " . $schoolSlug);
error_log("User Type: " . $userType);

// Validate school slug
if (empty($schoolSlug)) {
    error_log("ERROR: Empty school slug from router");
    header('HTTP/1.1 400 Bad Request');
    die(json_encode(['error' => 'School identifier missing']));
}

/**
 * Get school information
 */
$school = $schoolData;
if (empty($school) && isset($_SESSION['school_info'][$schoolSlug])) {
    $school = $_SESSION['school_info'][$schoolSlug];
    error_log("School data retrieved from session for slug: " . $schoolSlug);
}

// Redirect if school not found
if (empty($school)) {
    error_log("ERROR: School data not found for slug: " . $schoolSlug);
    header("Location: ../../login.php?school_slug=" . urlencode($schoolSlug));
    exit;
}

error_log("School ID: " . ($school['id'] ?? 'N/A'));
error_log("School Name: " . ($school['name'] ?? 'N/A'));

/**
 * Verify authentication
 */
$isAuthenticated = isset($_SESSION['school_auth']) && 
                   is_array($_SESSION['school_auth']) && 
                   ($_SESSION['school_auth']['school_slug'] ?? '') === $schoolSlug;

if (!$isAuthenticated) {
    error_log("User not authenticated, redirecting to login");
    header('Location: ../../login.php?school_slug=' . urlencode($schoolSlug));
    exit;
}

// Get user info
$schoolAuth = $_SESSION['school_auth'];
$userId = (int)($schoolAuth['user_id'] ?? 0);
$userType = $schoolAuth['user_type'] ?? '';

error_log("Authenticated User ID: " . $userId);
error_log("Authenticated User Type: " . $userType);

// Verify admin or teacher access
if (!in_array($userType, ['admin', 'teacher'])) {
    error_log("ERROR: User does not have permission to edit students");
    header('HTTP/1.1 403 Forbidden');
    die("Access denied.");
}

/**
 * Load required files and classes
 */
try {
    $autoloadPath = __DIR__ . '/../../../includes/autoload.php';
    error_log("Loading autoload from: " . $autoloadPath);
    
    if (!file_exists($autoloadPath)) {
        throw new Exception("Autoload file not found at: " . $autoloadPath);
    }
    require_once $autoloadPath;
    error_log("Autoload loaded successfully");
    
    if (!class_exists('Database')) {
        throw new Exception("Database class not found");
    }
    error_log("Database class found");
    
    $studentManagerPath = __DIR__ . '/../../../includes/StudentManager.php';
    error_log("Loading StudentManager from: " . $studentManagerPath);
    
    if (!file_exists($studentManagerPath)) {
        throw new Exception("StudentManager file not found at: " . $studentManagerPath);
    }
    require_once $studentManagerPath;
    error_log("StudentManager loaded successfully");
    
} catch (Exception $e) {
    error_log("CRITICAL ERROR loading configuration: " . $e->getMessage());
    http_response_code(500);
    die("System configuration loading failed. Please try again later.");
}

/**
 * Connect to school database
 */
$schoolDb = null;
try {
    if (!empty($school['database_name'])) {
        error_log("Attempting to connect to database: " . $school['database_name']);
        $schoolDb = Database::getSchoolConnection($school['database_name']);
        
        if ($schoolDb) {
            error_log("School database connection successful for: " . $school['database_name']);
        } else {
            throw new Exception("Database connection returned null");
        }
    } else {
        throw new Exception("No database name configured for school");
    }
} catch (Exception $e) {
    error_log("ERROR connecting to school database: " . $e->getMessage());
    $schoolDb = null;
    $_SESSION['toast_error'] = "Database connection failed. Please contact support.";
}

/**
 * Initialize StudentManager
 */
$studentManager = null;
if ($schoolDb) {
    try {
        $studentManager = new StudentManager($schoolDb, $school['id'], $userId, $userType, $school);
        error_log("StudentManager initialized successfully");
    } catch (Exception $e) {
        error_log("ERROR initializing StudentManager: " . $e->getMessage());
        $_SESSION['toast_error'] = "Failed to initialize student management system.";
    }
} else {
    error_log("WARNING: School database connection not available, StudentManager not initialized");
}

/**
 * Define helper functions
 */
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
if (!function_exists('timeAgo')) {
    function timeAgo($timestamp) {
        if (!$timestamp) return 'Unknown';
        $time = strtotime($timestamp);
        $now = time();
        $diff = $now - $time;

        if ($diff < 60) return 'Just now';
        if ($diff < 3600) return floor($diff / 60) . ' minutes ago';
        if ($diff < 86400) return floor($diff / 3600) . ' hours ago';
        if ($diff < 2592000) return floor($diff / 86400) . ' days ago';
        return date('M j, Y', $time);
    }
}

/**
 * Get student ID from URL
 */
$studentId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($studentId === 0) {
    error_log("ERROR: No student ID provided");
    header('Location: student-list.php?error=no_student_id');
    exit;
}

/**
 * Initialize variables
 */
$settings = [];
$classes = [];
$academicYears = [];
$bloodGroups = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
$adminUser = ['name' => 'Admin User', 'role_name' => 'Administrator'];
$sections = [];
$student = null;
$formData = [
    'academic_year_id' => '',
    'class_id' => '',
    'section_id' => '',
    'roll_number' => '',
    'admission_number' => '',
    'first_name' => '',
    'middle_name' => '',
    'last_name' => '',
    'gender' => '',
    'date_of_birth' => '',
    'student_phone' => '',
    'student_email' => '',
    'blood_group' => '',
    'allergies' => '',
    'medical_conditions' => '',
    'doctor_name' => '',
    'doctor_phone' => '',
    'current_address' => '',
    'permanent_address' => '',
    'previous_school' => '',
    'previous_class' => '',
    'transfer_certificate_no' => ''
];
$defaultFormData = $formData;
$notifications = [];
$unreadCount = 0;

/**
 * Fetch notifications for current user
 */
if ($schoolDb) {
    try {
        // Fetch recent notifications for current user
        $notifStmt = $schoolDb->prepare("
            SELECT * FROM notifications
            WHERE school_id = ? AND user_id = ?
              AND (expires_at IS NULL OR expires_at > NOW())
            ORDER BY created_at DESC
            LIMIT 10
        ");
        if ($notifStmt) {
            $notifStmt->execute([$school['id'], $userId]);
            $notifications = $notifStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // Count unread notifications
        $unreadStmt = $schoolDb->prepare("
            SELECT COUNT(*) as unread FROM notifications
            WHERE school_id = ? AND user_id = ? AND is_read = 0
              AND (expires_at IS NULL OR expires_at > NOW())
        ");
        if ($unreadStmt) {
            $unreadStmt->execute([$school['id'], $userId]);
            $unreadCount = $unreadStmt->fetch(PDO::FETCH_ASSOC)['unread'] ?? 0;
        }
        error_log("Loaded " . count($notifications) . " notifications, unread: " . $unreadCount);
    } catch (Exception $e) {
        error_log("Error fetching notifications: " . $e->getMessage());
    }
}

/**
 * Fetch data from database
 */
if ($schoolDb && $studentManager) {
    try {
        error_log("Starting data fetch from database");
        
        // Get school settings
        $settingsStmt = $schoolDb->prepare("SELECT `key`, `value` FROM settings WHERE school_id = ?");
        $settingsStmt->execute([$school['id']]);
        while ($row = $settingsStmt->fetch(PDO::FETCH_ASSOC)) {
            $settings[$row['key']] = $row['value'];
        }

        // Get academic years
        $yearStmt = $schoolDb->prepare("
            SELECT * FROM academic_years 
            WHERE school_id = ? AND status IN ('active', 'upcoming')
            ORDER BY is_default DESC, start_date DESC
        ");
        $yearStmt->execute([$school['id']]);
        $academicYears = $yearStmt->fetchAll(PDO::FETCH_ASSOC);

        // Get classes
        $classStmt = $schoolDb->prepare("
            SELECT c.*, ay.name as academic_year_name 
            FROM classes c
            LEFT JOIN academic_years ay ON c.academic_year_id = ay.id
            WHERE c.school_id = ? AND c.is_active = 1
            ORDER BY c.name
        ");
        $classStmt->execute([$school['id']]);
        $classes = $classStmt->fetchAll(PDO::FETCH_ASSOC);

        // Get student main details (combining students and users)
        $studentStmt = $schoolDb->prepare("
            SELECT 
                s.*,
                c.academic_year_id as class_academic_year_id,  -- get academic year from class
                u.id as user_id,
                u.name as user_name,
                u.email as user_email,
                u.phone as user_phone,
                u.gender as user_gender,
                u.date_of_birth as user_dob,
                u.address as user_address,
                u.is_active as user_active
            FROM students s
            LEFT JOIN classes c ON s.class_id = c.id AND c.school_id = s.school_id
            LEFT JOIN users u ON s.user_id = u.id AND u.school_id = s.school_id
            WHERE s.id = ? AND s.school_id = ?
        ");
        $studentStmt->execute([$studentId, $school['id']]);
        $student = $studentStmt->fetch(PDO::FETCH_ASSOC);

        if (!$student) {
            error_log("ERROR: Student not found with ID: " . $studentId);
            header('Location: student-list.php?error=student_not_found');
            exit;
        }

        // Get sections for the student's class
        if (!empty($student['class_id'])) {
            $sections = $studentManager->getSectionsByClass($student['class_id']);
        }

        // Get guardians (optional display)
        $guardianStmt = $schoolDb->prepare("
            SELECT u.*, g.relationship, g.is_primary
            FROM guardians g
            JOIN users u ON g.user_id = u.id AND u.school_id = g.school_id
            WHERE g.student_id = ? AND g.school_id = ?
        ");
        $guardianStmt->execute([$studentId, $school['id']]);
        $guardians = $guardianStmt->fetchAll(PDO::FETCH_ASSOC);

        // Populate form data from student record
        $formData = array_merge($defaultFormData, [
            'academic_year_id' => $student['class_academic_year_id'] ?? '',  // from class join
            'class_id'         => $student['class_id'] ?? '',
            'section_id'       => $student['section_id'] ?? '',
            'roll_number'      => $student['roll_number'] ?? '',
            'admission_number' => $student['admission_number'] ?? '',
            'first_name'       => $student['first_name'] ?? '',
            'middle_name'      => $student['middle_name'] ?? '',
            'last_name'        => $student['last_name'] ?? '',
            'gender'           => $student['gender'] ?? $student['user_gender'] ?? '',
            'date_of_birth'    => $student['date_of_birth'] ?? $student['user_dob'] ?? '',
            'student_phone'    => $student['student_phone'] ?? $student['user_phone'] ?? '',
            'student_email'    => $student['student_email'] ?? $student['user_email'] ?? '',
            'blood_group'      => $student['blood_group'] ?? '',
            'allergies'        => $student['allergies'] ?? '',
            'medical_conditions' => $student['medical_conditions'] ?? '',
            'doctor_name'      => $student['doctor_name'] ?? '',
            'doctor_phone'     => $student['doctor_phone'] ?? '',
            'current_address'  => $student['current_address'] ?? $student['user_address'] ?? '',
            'permanent_address' => $student['permanent_address'] ?? '',
            'previous_school'  => $student['previous_school'] ?? '',
            'previous_class'   => $student['previous_class'] ?? '',
            'transfer_certificate_no' => $student['transfer_certificate_no'] ?? ''
        ]);

        error_log("Student data loaded successfully for ID: " . $studentId);

    } catch (Exception $e) {
        error_log("ERROR fetching data: " . $e->getMessage());
        $_SESSION['toast_error'] = "Error loading student data. Please refresh.";
    }
}

/**
 * Handle form submission
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_student'])) {
    error_log("=== POST REQUEST DETECTED (EDIT) ===");

    // Validate CSRF
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        error_log("CSRF validation FAILED");
        $_SESSION['toast_error'] = "Invalid security token. Please try again.";
        header("Location: edit-student.php?id=" . $studentId);
        exit;
    }
    error_log("CSRF validation PASSED");

    // Prepare update data (same structure as add)
    $updateData = [
        'academic_year_id' => !empty($_POST['academic_year']) ? (int)$_POST['academic_year'] : null,
        'class_id'         => !empty($_POST['class']) ? (int)$_POST['class'] : null,
        'section_id'       => !empty($_POST['section']) ? (int)$_POST['section'] : null,
        'roll_number'      => sanitize($_POST['roll_number'] ?? ''),
        'admission_number' => sanitize($_POST['admission_number'] ?? ''),
        'first_name'       => sanitize($_POST['first_name'] ?? ''),
        'middle_name'      => sanitize($_POST['middle_name'] ?? ''),
        'last_name'        => sanitize($_POST['last_name'] ?? ''),
        'gender'           => $_POST['gender'] ?? null,
        'date_of_birth'    => $_POST['date_of_birth'] ?? null,
        'student_phone'    => !empty($_POST['student_phone']) ? sanitize($_POST['student_phone']) : null,
        'student_email'    => sanitize($_POST['student_email'] ?? ''),
        'blood_group'      => $_POST['blood_group'] ?? null,
        'allergies'        => sanitize($_POST['allergies'] ?? ''),
        'medical_conditions' => sanitize($_POST['medical_conditions'] ?? ''),
        'doctor_name'      => sanitize($_POST['doctor_name'] ?? ''),
        'doctor_phone'     => !empty($_POST['doctor_phone']) ? sanitize($_POST['doctor_phone']) : null,
        'current_address'  => sanitize($_POST['current_address'] ?? ''),
        'permanent_address' => sanitize($_POST['permanent_address'] ?? ''),
        'previous_school'  => sanitize($_POST['previous_school'] ?? ''),
        'previous_class'   => sanitize($_POST['previous_class'] ?? ''),
        'transfer_certificate_no' => sanitize($_POST['transfer_certificate_no'] ?? ''),
        'password'         => $_POST['password'] ?? ''  // optional
    ];

    // Validate required fields
    $requiredFields = ['first_name', 'last_name', 'class_id', 'academic_year_id', 'date_of_birth'];
    $missingFields = [];
    foreach ($requiredFields as $field) {
        if (empty($updateData[$field])) {
            $missingFields[] = str_replace('_', ' ', $field);
            error_log("Missing required field: " . $field);
        }
    }

    if (!empty($missingFields)) {
        $errorMsg = "Please fill all required fields: " . implode(', ', $missingFields);
        error_log("Validation FAILED: " . $errorMsg);
        $_SESSION['toast_error'] = $errorMsg;
        $_SESSION['form_data'] = $_POST;
        header("Location: edit-student.php?id=" . $studentId);
        exit;
    }

    error_log("Validation PASSED - calling StudentManager->updateStudent()");

    // Perform update via StudentManager
    try {
        $result = $studentManager->updateStudent($studentId, $updateData);
        if (is_array($result) && count($result) >= 2) {
            $success = $result[0];
            $message = $result[1];

            if ($success) {
                error_log("=== STUDENT UPDATED SUCCESSFULLY ===");
                $_SESSION['toast_success'] = $message;
                unset($_SESSION['form_data']);

                // Redirect to student list (optional class filter)
                $redirectUrl = "student-list.php";
                if (!empty($updateData['class_id'])) {
                    $redirectUrl .= "?class_id=" . $updateData['class_id'];
                }
                header("Location: " . $redirectUrl);
                exit;
            } else {
                error_log("=== STUDENT UPDATE FAILED ===");
                error_log("Message: " . $message);
                $_SESSION['toast_error'] = $message;
                $_SESSION['form_data'] = $_POST;
                header("Location: edit-student.php?id=" . $studentId);
                exit;
            }
        } else {
            throw new Exception("Unexpected response from StudentManager");
        }
    } catch (Exception $e) {
        error_log("Exception in update: " . $e->getMessage());
        $_SESSION['toast_error'] = "An error occurred while updating the student.";
        $_SESSION['form_data'] = $_POST;
        header("Location: edit-student.php?id=" . $studentId);
        exit;
    }
}

// Restore form data from session if there was an error
if (isset($_SESSION['form_data']) && !empty($_SESSION['form_data'])) {
    error_log("Restoring form data from session after error");
    $postedFormData = is_array($_SESSION['form_data']) ? $_SESSION['form_data'] : [];
    $postedFormData['academic_year_id'] = $postedFormData['academic_year_id'] ?? $postedFormData['academic_year'] ?? '';
    $postedFormData['class_id'] = $postedFormData['class_id'] ?? $postedFormData['class'] ?? '';
    $postedFormData['section_id'] = $postedFormData['section_id'] ?? $postedFormData['section'] ?? '';
    $formData = array_merge($defaultFormData, is_array($formData) ? $formData : [], $postedFormData);
    unset($_SESSION['form_data']);
}

$formData = array_merge($defaultFormData, is_array($formData) ? $formData : []);

// Collect toast messages
$toastSuccess = $_SESSION['toast_success'] ?? '';
$toastError = $_SESSION['toast_error'] ?? '';
unset($_SESSION['toast_success'], $_SESSION['toast_error']);

// Generate CSRF token
$csrfToken = generateCsrfToken();

/**
 * Handle AJAX requests (get sections)
 */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['ajax']) && $_GET['ajax'] === 'get_sections' && isset($_GET['class_id'])) {
    header('Content-Type: application/json');
    try {
        $classId = (int)$_GET['class_id'];
        $sections = $studentManager->getSectionsByClass($classId);
        echo json_encode(['success' => true, 'sections' => $sections]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Format currency symbol
$currencySymbol = $settings['currency_symbol'] ?? '₦';

error_log("=== EDIT STUDENT PAGE END ===");
?>

<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="description" content="Edit Student - School Management System">
    <meta name="keywords" content="Edit Student, School Management">
    <meta name="robots" content="INDEX,FOLLOW">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Student - <?php echo htmlspecialchars($school['name'] ?? 'School'); ?></title>
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
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
    </style>
</head>
<body>

<!-- Toast Container -->
<div class="toast-container" id="toastContainer">
    <?php if (!empty($toastSuccess)): ?>
    <div class="toast success show">
        <div class="toast-header">
            <i class="ri-checkbox-circle-line me-2"></i>
            <strong class="me-auto">Success</strong>
            <button type="button" class="btn-close" data-bs-dismiss="toast"></button>
        </div>
        <div class="toast-body"><?php echo htmlspecialchars($toastSuccess); ?></div>
    </div>
    <?php endif; ?>
    <?php if (!empty($toastError)): ?>
    <div class="toast error show">
        <div class="toast-header">
            <i class="ri-error-warning-line me-2"></i>
            <strong class="me-auto">Error</strong>
            <button type="button" class="btn-close" data-bs-dismiss="toast"></button>
        </div>
        <div class="toast-body"><?php echo htmlspecialchars($toastError); ?></div>
    </div>
    <?php endif; ?>
</div>

<!-- Theme Customization (simplified) -->


<div class="overlay bg-black bg-opacity-50 w-100 h-100 position-fixed z-9 visibility-hidden opacity-0 duration-300"></div>

<?php include_once('includes/sidebar.php'); ?>

<main class="dashboard-main">
    <div class="navbar-header shadow-1">
        <div class="row align-items-center justify-content-between">
            <div class="col-auto">
                <div class="d-flex flex-wrap align-items-center gap-4">
                    <button type="button" class="sidebar-mobile-toggle" aria-label="Sidebar Mobile Toggler Button">
                        <iconify-icon icon="heroicons:bars-3-solid" class="icon"></iconify-icon>
                    </button>
                    <form class="navbar-search">
                        <input type="text" class="bg-transparent" name="search" placeholder="Search...">
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
                            <?php if ($unreadCount > 0): ?>
                            <span class="w-8-px h-8-px bg-danger-600 position-absolute end-0 top-0 rounded-circle mt-2 me-2"></span>
                            <?php endif; ?>
                        </button>
                        <div class="dropdown-menu to-top dropdown-menu-lg p-0">
                            <div class="m-16 py-12 px-16 radius-8 bg-primary-50 mb-16 d-flex align-items-center justify-content-between gap-2">
                                <div>
                                    <h6 class="text-lg text-primary-light fw-semibold mb-0">Notifications</h6>
                                </div>
                                <span class="text-primary-600 fw-semibold text-lg w-40-px h-40-px rounded-circle bg-base d-flex justify-content-center align-items-center"><?php echo count($notifications); ?></span>
                            </div>
                            <div class="max-h-400-px overflow-y-auto scroll-sm pe-4">
                                <?php if (!empty($notifications)): ?>
                                    <?php foreach ($notifications as $notif): ?>
                                    <a href="notification.php?id=<?php echo $notif['id']; ?>" class="px-24 py-12 d-flex align-items-start gap-3 mb-2 justify-content-between">
                                        <div class="text-black hover-bg-transparent hover-text-primary d-flex align-items-center gap-3">
                                            <span class="w-44-px h-44-px bg-success-subtle text-success-main rounded-circle d-flex justify-content-center align-items-center flex-shrink-0">
                                                <iconify-icon icon="bitcoin-icons:verify-outline" class="icon text-xxl"></iconify-icon>
                                            </span>
                                            <div>
                                                <h6 class="text-md fw-semibold mb-4"><?php echo htmlspecialchars($notif['title']); ?></h6>
                                                <p class="mb-0 text-sm text-secondary-light text-w-200-px"><?php echo htmlspecialchars(substr($notif['message'] ?? '', 0, 50)) . '...'; ?></p>
                                            </div>
                                        </div>
                                        <span class="text-sm text-secondary-light flex-shrink-0">
                                            <?php echo timeAgo($notif['created_at']); ?>
                                        </span>
                                    </a>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="text-center py-20">
                                        <p class="text-secondary-light">No new notifications</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="text-center py-12 px-16">
                                <a href="notifications.php" class="text-primary-600 fw-semibold text-md hover-underline">See All Notifications</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="dashboard-main-body">
        <div class="breadcrumb d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
            <div>
                <h1 class="fw-semibold mb-4 h6 text-primary-light">Edit Student</h1>
                <div>
                    <a href="index.php" class="text-secondary-light hover-text-primary hover-underline">Dashboard</a>
                    <a href="student-list.php" class="text-secondary-light hover-text-primary hover-underline"> / Student</a>
                    <span class="text-secondary-light">/ Edit Student</span>
                </div>
            </div>
            <a href="add-new-student.php" class="btn btn-primary-600 d-flex align-items-center gap-6">
                <i class="ri-add-large-line"></i> Add New Student
            </a>
        </div>

        <?php if ($student): ?>
        <form method="POST" action="" class="mt-24">
            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
            <input type="hidden" name="update_student" value="1">
            
            <div class="row gy-3">
                <!-- Academic Information -->
                <div class="col-lg-12">
                    <div class="shadow-1 radius-12 bg-base overflow-hidden">
                        <div class="card-header border-bottom bg-base py-16 px-24">
                            <h6 class="text-lg fw-semibold mb-0">Academic Information</h6>
                        </div>
                        <div class="card-body p-20">
                            <div class="row gy-3">
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <label class="text-sm fw-semibold text-primary-light mb-8">Academic Year *</label>
                                    <select name="academic_year" class="form-control form-select" required>
                                        <option value="">Select Academic Year</option>
                                        <?php foreach ($academicYears as $year): ?>
                                        <option value="<?php echo $year['id']; ?>" <?php echo ($formData['academic_year_id'] ?? '') == $year['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($year['name']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <label class="text-sm fw-semibold text-primary-light mb-8">Class *</label>
                                    <select id="classSelection" name="class" class="form-control form-select" required>
                                        <option value="">Select Class</option>
                                        <?php foreach ($classes as $class): ?>
                                        <option value="<?php echo $class['id']; ?>" <?php echo ($formData['class_id'] ?? '') == $class['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($class['name']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <label class="text-sm fw-semibold text-primary-light mb-8">Section</label>
                                    <select id="section" name="section" class="form-control form-select">
                                        <option value="">Select Section</option>
                                        <?php foreach ($sections as $section): ?>
                                        <option value="<?php echo $section['id']; ?>" <?php echo ($formData['section_id'] ?? '') == $section['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($section['name']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <label class="text-sm fw-semibold text-primary-light mb-8">Roll Number</label>
                                    <input type="text" name="roll_number" class="form-control" value="<?php echo htmlspecialchars($formData['roll_number']); ?>">
                                </div>
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <label class="text-sm fw-semibold text-primary-light mb-8">Admission No *</label>
                                    <input type="text" name="admission_number" class="form-control" value="<?php echo htmlspecialchars($formData['admission_number']); ?>" required>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Personal Information -->
                <div class="col-lg-12">
                    <div class="shadow-1 radius-12 bg-base overflow-hidden">
                        <div class="card-header border-bottom bg-base py-16 px-24">
                            <h6 class="text-lg fw-semibold mb-0">Personal Information</h6>
                        </div>
                        <div class="card-body p-20">
                            <div class="row gy-3">
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <label class="text-sm fw-semibold text-primary-light mb-8">First Name *</label>
                                    <input type="text" name="first_name" class="form-control" value="<?php echo htmlspecialchars($formData['first_name']); ?>" required>
                                </div>
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <label class="text-sm fw-semibold text-primary-light mb-8">Middle Name</label>
                                    <input type="text" name="middle_name" class="form-control" value="<?php echo htmlspecialchars($formData['middle_name']); ?>">
                                </div>
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <label class="text-sm fw-semibold text-primary-light mb-8">Last Name *</label>
                                    <input type="text" name="last_name" class="form-control" value="<?php echo htmlspecialchars($formData['last_name']); ?>" required>
                                </div>
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <label class="text-sm fw-semibold text-primary-light mb-8">Gender</label>
                                    <select name="gender" class="form-control form-select">
                                        <option value="">Select Gender</option>
                                        <option value="male" <?php echo ($formData['gender'] ?? '') == 'male' ? 'selected' : ''; ?>>Male</option>
                                        <option value="female" <?php echo ($formData['gender'] ?? '') == 'female' ? 'selected' : ''; ?>>Female</option>
                                        <option value="other" <?php echo ($formData['gender'] ?? '') == 'other' ? 'selected' : ''; ?>>Other</option>
                                    </select>
                                </div>
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <label class="text-sm fw-semibold text-primary-light mb-8">Date of Birth *</label>
                                    <input type="date" name="date_of_birth" class="form-control" value="<?php echo htmlspecialchars($formData['date_of_birth']); ?>" required>
                                </div>
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <label class="text-sm fw-semibold text-primary-light mb-8">Phone</label>
                                    <input type="tel" name="student_phone" class="form-control" value="<?php echo htmlspecialchars($formData['student_phone']); ?>">
                                </div>
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <label class="text-sm fw-semibold text-primary-light mb-8">Email</label>
                                    <input type="email" name="student_email" class="form-control" value="<?php echo htmlspecialchars($formData['student_email']); ?>" readonly>
                                    <small class="text-secondary-light">Email cannot be changed here</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Guardian Information (display only) -->
                <div class="col-lg-12">
                    <div class="shadow-1 radius-12 bg-base overflow-hidden">
                        <div class="card-header border-bottom bg-base py-16 px-24">
                            <h6 class="text-lg fw-semibold mb-0">Guardian Information</h6>
                        </div>
                        <div class="card-body p-20">
                            <?php if (!empty($guardians)): ?>
                                <?php foreach ($guardians as $guardian): ?>
                                <div class="border-bottom mb-20 pb-20">
                                    <h6 class="text-md fw-semibold mb-16">
                                        <?php echo ucfirst($guardian['relationship'] ?? 'Guardian'); ?>
                                        <?php echo $guardian['is_primary'] ? '(Primary)' : ''; ?>
                                    </h6>
                                    <div class="row">
                                        <div class="col-xxl-3 col-xl-4 col-sm-6">
                                            <label class="text-sm fw-semibold text-primary-light mb-8">Name</label>
                                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($guardian['name']); ?>" readonly>
                                        </div>
                                        <div class="col-xxl-3 col-xl-4 col-sm-6">
                                            <label class="text-sm fw-semibold text-primary-light mb-8">Email</label>
                                            <input type="email" class="form-control" value="<?php echo htmlspecialchars($guardian['email']); ?>" readonly>
                                        </div>
                                        <div class="col-xxl-3 col-xl-4 col-sm-6">
                                            <label class="text-sm fw-semibold text-primary-light mb-8">Phone</label>
                                            <input type="tel" class="form-control" value="<?php echo htmlspecialchars($guardian['phone']); ?>" readonly>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p class="text-secondary-light">No guardians linked. You can add guardians in the guardian management section.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Medical Details -->
                <div class="col-lg-12">
                    <div class="shadow-1 radius-12 bg-base overflow-hidden">
                        <div class="card-header border-bottom bg-base py-16 px-24">
                            <h6 class="text-lg fw-semibold mb-0">Medical Details</h6>
                        </div>
                        <div class="card-body p-20">
                            <div class="row gy-3">
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <label class="text-sm fw-semibold text-primary-light mb-8">Blood Group</label>
                                    <select name="blood_group" class="form-control form-select">
                                        <option value="">Select Blood Group</option>
                                        <?php foreach ($bloodGroups as $bg): ?>
                                        <option value="<?php echo $bg; ?>" <?php echo ($formData['blood_group'] ?? '') == $bg ? 'selected' : ''; ?>><?php echo $bg; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <label class="text-sm fw-semibold text-primary-light mb-8">Allergies</label>
                                    <input type="text" name="allergies" class="form-control" value="<?php echo htmlspecialchars($formData['allergies']); ?>">
                                </div>
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <label class="text-sm fw-semibold text-primary-light mb-8">Medical Conditions</label>
                                    <input type="text" name="medical_conditions" class="form-control" value="<?php echo htmlspecialchars($formData['medical_conditions']); ?>">
                                </div>
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <label class="text-sm fw-semibold text-primary-light mb-8">Doctor's Name</label>
                                    <input type="text" name="doctor_name" class="form-control" value="<?php echo htmlspecialchars($formData['doctor_name']); ?>">
                                </div>
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <label class="text-sm fw-semibold text-primary-light mb-8">Doctor's Phone</label>
                                    <input type="tel" name="doctor_phone" class="form-control" value="<?php echo htmlspecialchars($formData['doctor_phone']); ?>">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Address Information -->
                <div class="col-lg-12">
                    <div class="shadow-1 radius-12 bg-base overflow-hidden">
                        <div class="card-header border-bottom bg-base py-16 px-24">
                            <h6 class="text-lg fw-semibold mb-0">Address Information</h6>
                        </div>
                        <div class="card-body p-20">
                            <div class="row gy-3">
                                <div class="col-sm-6">
                                    <label class="text-sm fw-semibold text-primary-light mb-8">Current Address</label>
                                    <textarea name="current_address" class="form-control" rows="2"><?php echo htmlspecialchars($formData['current_address']); ?></textarea>
                                </div>
                                <div class="col-sm-6">
                                    <label class="text-sm fw-semibold text-primary-light mb-8">Permanent Address</label>
                                    <textarea name="permanent_address" class="form-control" rows="2"><?php echo htmlspecialchars($formData['permanent_address']); ?></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Previous School Information -->
                <div class="col-lg-12">
                    <div class="shadow-1 radius-12 bg-base overflow-hidden">
                        <div class="card-header border-bottom bg-base py-16 px-24">
                            <h6 class="text-lg fw-semibold mb-0">Previous School Information</h6>
                        </div>
                        <div class="card-body p-20">
                            <div class="row gy-3">
                                <div class="col-sm-6">
                                    <label class="text-sm fw-semibold text-primary-light mb-8">Previous School Name</label>
                                    <input type="text" name="previous_school" class="form-control" value="<?php echo htmlspecialchars($formData['previous_school']); ?>">
                                </div>
                                <div class="col-sm-6">
                                    <label class="text-sm fw-semibold text-primary-light mb-8">Previous Class</label>
                                    <input type="text" name="previous_class" class="form-control" value="<?php echo htmlspecialchars($formData['previous_class']); ?>">
                                </div>
                                <div class="col-sm-6">
                                    <label class="text-sm fw-semibold text-primary-light mb-8">Transfer Certificate No.</label>
                                    <input type="text" name="transfer_certificate_no" class="form-control" value="<?php echo htmlspecialchars($formData['transfer_certificate_no']); ?>">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Login Details (Password) -->
                <div class="col-lg-12">
                    <div class="shadow-1 radius-12 bg-base overflow-hidden">
                        <div class="card-header border-bottom bg-base py-16 px-24">
                            <h6 class="text-lg fw-semibold mb-0">Login Details</h6>
                        </div>
                        <div class="card-body p-20">
                            <div class="row gy-3">
                                <div class="col-sm-6">
                                    <label class="text-sm fw-semibold text-primary-light mb-8">Email (readonly)</label>
                                    <input type="email" class="form-control" value="<?php echo htmlspecialchars($formData['student_email']); ?>" readonly>
                                </div>
                                <div class="col-sm-6">
                                    <label for="password" class="text-sm fw-semibold text-primary-light mb-8">New Password (Optional)</label>
                                    <div class="position-relative">
                                        <input type="password" id="password" name="password" class="form-control" placeholder="Enter new password">
                                        <span class="toggle-password ri-eye-line cursor-pointer position-absolute end-0 top-50 translate-middle-y me-16 text-secondary-light" data-toggle="#password"></span>
                                    </div>
                                    <small class="text-secondary-light">Leave blank to keep current password</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Form Actions -->
                <div class="col-12">
                    <div class="d-flex align-items-center justify-content-center gap-3 mt-8">
                        <a href="student-list.php" class="border border-danger-600 bg-hover-danger-200 text-danger-600 text-md px-50 py-11 radius-8 text-decoration-none">
                            Cancel
                        </a>
                        <button type="submit" class="btn btn-primary-600 border border-primary-600 text-md px-28 py-12 radius-8">
                            Save Changes
                        </button>
                    </div>
                </div>
            </div>
        </form>
        <?php endif; ?>
    </div>

    <footer class="d-footer">
        <p class="mb-0 text-center">&copy; <span class="current-year"></span> <?php echo htmlspecialchars($school['name'] ?? 'School'); ?> | Made With ❤️ by AcademixSuite.</p>
    </footer>
</main>

<script src="https://academixsuite.com/tenant/assets/js/lib/jquery-3.7.1.min.js"></script>
<script src="https://academixsuite.com/tenant/assets/js/lib/bootstrap.bundle.min.js"></script>
<script src="https://academixsuite.com/tenant/assets/js/lib/iconify-icon.min.js"></script>
<script src="https://academixsuite.com/tenant/assets/js/app.js"></script>

<script>
$(document).ready(function() {
    // Initialize toasts
    $('.toast').toast({ autohide: true, delay: 5000 }).toast('show');

    // Load sections when class changes
    $('#classSelection').on('change', function() {
        const classId = $(this).val();
        const currentSection = '<?php echo $formData['section_id'] ?? ''; ?>';
        if (classId) {
            $.ajax({
                url: window.location.pathname,
                method: 'GET',
                data: { ajax: 'get_sections', class_id: classId },
                dataType: 'json',
                beforeSend: function() {
                    $('#section').html('<option value="">Loading...</option>').prop('disabled', true);
                },
                success: function(response) {
                    if (response.success) {
                        let options = '<option value="">Select Section</option>';
                        $.each(response.sections, function(i, s) {
                            let selected = (s.id == currentSection) ? 'selected' : '';
                            options += '<option value="' + s.id + '" ' + selected + '>' + s.name + '</option>';
                        });
                        $('#section').html(options).prop('disabled', false);
                    } else {
                        $('#section').html('<option value="">No sections available</option>');
                    }
                },
                error: function() {
                    $('#section').html('<option value="">Error loading sections</option>');
                }
            });
        } else {
            $('#section').html('<option value="">Select Section</option>').prop('disabled', false);
        }
    });

    // Password toggle
    $('.toggle-password').on('click', function() {
        $(this).toggleClass("ri-eye-off-line");
        var input = $($(this).attr("data-toggle"));
        input.attr("type", input.attr("type") === "password" ? "text" : "password");
    });

    // Form validation
    $('form').on('submit', function(e) {
        const firstName = $('#firstName').val().trim();
        const lastName = $('#lastName').val().trim();
        const dob = $('#dateOfBirth').val();
        const year = $('#academicYear').val();
        const classId = $('#classSelection').val();
        const admNo = $('#admissionNumber').val().trim();
        if (!firstName || !lastName || !dob || !year || !classId || !admNo) {
            e.preventDefault();
            alert('Please fill in all required fields');
            return false;
        }
        // Show loading
        $(this).find('button[type="submit"]').prop('disabled', true)
               .html('<span class="spinner-border spinner-border-sm me-2"></span>Saving...');
        return true;
    });

    // Current year in footer
    $('.current-year').text(new Date().getFullYear());
});
</script>
</body>
</html>
