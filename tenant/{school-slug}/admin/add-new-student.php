<?php
/**
 * School Add Student Page
 * Handles adding new students to the school database with parent/guardian relationships
 * 
 * @package AcademixSuite
 * @version 2.3 (added class capacity check)
 */

// Enable error reporting for debugging
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../../logs/school_add_student.log');

error_log("=== ADD STUDENT PAGE START ===");
error_log("Request URI: " . ($_SERVER['REQUEST_URI'] ?? 'N/A'));
error_log("Script: " . __FILE__);
error_log("Request Method: " . $_SERVER['REQUEST_METHOD']);
error_log("Session ID: " . (session_id() ?: 'No session'));

// Define constants if not defined
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

// Start session
initializeSession();

/**
 * Get school slug from router
 */
$schoolSlug = $GLOBALS['SCHOOL_SLUG'] ?? '';
$userType = $GLOBALS['USER_TYPE'] ?? 'admin';
$currentPage = $GLOBALS['CURRENT_PAGE'] ?? 'add-student.php';
$schoolData = $GLOBALS['SCHOOL_DATA'] ?? [];
$baseUrl = $GLOBALS['BASE_URL'] ?? '';

error_log("School Slug: " . $schoolSlug);
error_log("User Type: " . $userType);
error_log("Current Page: " . $currentPage);

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
// Notifications
$notifications = [];
$unreadCount = 0;

error_log("Authenticated User ID: " . $userId);
error_log("Authenticated User Type: " . $userType);

// Verify admin access
if ($userType !== 'admin') {
    error_log("ERROR: User does not have admin privileges. User type: " . $userType);
    header('HTTP/1.1 403 Forbidden');
    die("Access denied. Admin privileges required.");
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
    
    // Include required classes
    $studentManagerPath = __DIR__ . '/../../../includes/StudentManager.php';
    error_log("Loading StudentManager from: " . $studentManagerPath);
    
    if (!file_exists($studentManagerPath)) {
        throw new Exception("StudentManager file not found at: " . $studentManagerPath);
    }
    require_once $studentManagerPath;
    error_log("StudentManager loaded successfully");
    
} catch (Exception $e) {
    error_log("CRITICAL ERROR loading configuration: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
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
            error_log("Database connection PDO object: " . get_class($schoolDb));
        } else {
            throw new Exception("Database connection returned null");
        }
    } else {
        throw new Exception("No database name configured for school");
    }
} catch (Exception $e) {
    error_log("ERROR connecting to school database: " . $e->getMessage());
    error_log("Database connection error trace: " . $e->getTraceAsString());
    $schoolDb = null;
    $_SESSION['toast_error'] = "Database connection failed. Please contact support.";
}

/**
 * Initialize StudentManager
 */
$studentManager = null;
if ($schoolDb) {
    try {
        error_log("Initializing StudentManager with parameters:");
        error_log("- School ID: " . $school['id']);
        error_log("- User ID: " . $userId);
        error_log("- User Type: " . $userType);
        
        $studentManager = new StudentManager($schoolDb, $school['id'], $userId, $userType, $school);
        error_log("StudentManager initialized successfully");
    } catch (Exception $e) {
        error_log("ERROR initializing StudentManager: " . $e->getMessage());
        error_log("StudentManager init trace: " . $e->getTraceAsString());
        $_SESSION['toast_error'] = "Failed to initialize student management system.";
    }
} else {
    error_log("WARNING: School database connection not available, StudentManager not initialized");
}

/**
 * Define helper functions if not already defined
 */
if (!function_exists('sanitize')) {
    function sanitize($input) {
        if ($input === null) return null;
        $sanitized = htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
        return $sanitized;
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

/**
 * Initialize variables
 */
$settings = [];
$classes = [];
$academicYears = [];
$studentCategories = [];
$bloodGroups = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
$adminUser = ['name' => 'Admin User', 'role_name' => 'Administrator'];
$selectedClass = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
$sections = [];

// Store POST data for form repopulation
$formData = $_POST;
if (!empty($_POST)) {
    error_log("POST data received. Fields: " . implode(', ', array_keys($_POST)));
}

/**
 * Fetch data from database
 */
if ($schoolDb) {
    try {
        error_log("Starting data fetch from database");
        
        // Get school settings
        $settingsStmt = $schoolDb->prepare("SELECT `key`, `value` FROM settings WHERE school_id = ?");
        if ($settingsStmt) {
            $settingsStmt->execute([$school['id']]);
            $settingsCount = 0;
            while ($row = $settingsStmt->fetch(PDO::FETCH_ASSOC)) {
                $settings[$row['key']] = $row['value'];
                $settingsCount++;
            }
            error_log("Loaded $settingsCount settings records");
        }

        // Get logged in user details
        $userStmt = $schoolDb->prepare("
            SELECT u.*, r.name as role_name 
            FROM users u 
            LEFT JOIN user_roles ur ON u.id = ur.user_id
            LEFT JOIN roles r ON ur.role_id = r.id
            WHERE u.id = ? AND u.school_id = ?
        ");
        if ($userStmt) {
            $userStmt->execute([$userId, $school['id']]);
            $adminUserData = $userStmt->fetch(PDO::FETCH_ASSOC);
            if ($adminUserData) {
                $adminUser = $adminUserData;
                error_log("Loaded user data for ID: " . $userId);
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
            error_log("Loaded " . count($academicYears) . " academic years");
        }

        // If no academic years found, create default ones
        if (empty($academicYears) && $studentManager) {
            error_log("No academic years found. Creating default years...");
            $currentYear = date('Y');
            $nextYear = $currentYear + 1;
            
            $insertStmt = $schoolDb->prepare("
                INSERT INTO academic_years (school_id, name, start_date, end_date, is_default, status, created_at)
                VALUES (?, ?, ?, ?, 1, 'active', NOW())
            ");
            
            $startDate = $currentYear . '-09-01';
            $endDate = $nextYear . '-07-31';
            $name = $currentYear . '/' . $nextYear;
            
            $result = $insertStmt->execute([$school['id'], $name, $startDate, $endDate]);
            
            if ($result) {
                error_log("Created default academic year: " . $name);
            }
            
            // Fetch again
            $yearStmt->execute([$school['id']]);
            $academicYears = $yearStmt->fetchAll(PDO::FETCH_ASSOC);
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
            error_log("Loaded " . count($classes) . " classes");
        }

        // Get sections for selected class
        if ($selectedClass && $studentManager) {
            $sections = $studentManager->getSectionsByClass($selectedClass);
            error_log("Loaded " . count($sections) . " sections for class " . $selectedClass);
        }

    } catch (Exception $e) {
        error_log("ERROR fetching data: " . $e->getMessage());
        $_SESSION['toast_error'] = "Error loading form data. Please refresh the page.";
    }
}

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
} catch (Exception $e) {
    error_log("Error fetching notifications: " . $e->getMessage());
}

/**
 * Handle form submission - FIXED VERSION with conditional redirect
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    error_log("=== POST REQUEST DETECTED ===");
    error_log("POST data keys: " . implode(', ', array_keys($_POST)));
    
    // Check if this is a form submission (check for required fields instead of submit button)
    // This is the key fix - we check for presence of form data rather than the submit button
    if ($studentManager && !empty($_POST) && isset($_POST['first_name']) && isset($_POST['last_name'])) {
        
        error_log("=== PROCESSING FORM SUBMISSION ===");
        error_log("Form submission detected via field presence");
        
        // Validate CSRF token
        if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
            error_log("CSRF validation FAILED");
            $_SESSION['toast_error'] = "Invalid security token. Please try again.";
            header("Location: add-new-student.php?class_id=" . $selectedClass);
            exit;
        }
        error_log("CSRF validation PASSED");
        
        // Prepare student data matching database structure
        // 🔧 FIX: Convert empty phone fields to null to avoid unique constraint violations
        $studentData = [
            'academic_year_id' => !empty($_POST['academic_year']) ? (int)$_POST['academic_year'] : null,
            'class_id'         => !empty($_POST['class']) ? (int)$_POST['class'] : null,
            'section_id'       => !empty($_POST['section']) ? (int)$_POST['section'] : null,
            'roll_number'      => sanitize($_POST['roll_number'] ?? ''),
            'admission_date'   => $_POST['admission_date'] ?? date('Y-m-d'),
            
            // Student personal info
            'first_name'    => sanitize($_POST['first_name'] ?? ''),
            'middle_name'   => sanitize($_POST['middle_name'] ?? ''),
            'last_name'     => sanitize($_POST['last_name'] ?? ''),
            'gender'        => $_POST['gender'] ?? null,
            'date_of_birth' => $_POST['date_of_birth'] ?? date('Y-m-d', strtotime('-6 years')),
            'student_email' => sanitize($_POST['student_email'] ?? ''),
            // 🔧 FIX: empty phone -> null
            'student_phone' => !empty($_POST['student_phone']) ? sanitize($_POST['student_phone']) : null,
            
            // Parent/Guardian info
            'guardian_name'      => sanitize($_POST['guardian_name'] ?? ''),
            'guardian_email'     => sanitize($_POST['guardian_email'] ?? ''),
            // 🔧 FIX: empty phone -> null
            'guardian_phone'     => !empty($_POST['guardian_phone']) ? sanitize($_POST['guardian_phone']) : null,
            'guardian_relation'  => $_POST['guardian_relation'] ?? null,
            'guardian_address'   => sanitize($_POST['guardian_address'] ?? ''),
            'existing_parent_id' => !empty($_POST['existing_parent_id']) ? (int)$_POST['existing_parent_id'] : null,
            
            // Medical details
            'blood_group'        => $_POST['blood_group'] ?? null,
            'allergies'          => sanitize($_POST['allergies'] ?? ''),
            'medical_conditions' => sanitize($_POST['medical_conditions'] ?? ''),
            'doctor_name'        => sanitize($_POST['doctor_name'] ?? ''),
            // 🔧 FIX: empty phone -> null
            'doctor_phone'       => !empty($_POST['doctor_phone']) ? sanitize($_POST['doctor_phone']) : null,
            
            // Address
            'current_address'    => sanitize($_POST['current_address'] ?? ''),
            'permanent_address'  => sanitize($_POST['permanent_address'] ?? ''),
            
            // Previous school
            'previous_school'          => sanitize($_POST['previous_school'] ?? ''),
            'previous_class'           => sanitize($_POST['previous_class'] ?? ''),
            'transfer_certificate_no'  => sanitize($_POST['transfer_certificate_no'] ?? '')
        ];

        error_log("Student data prepared for insertion:");
        error_log("- Name: " . $studentData['first_name'] . " " . $studentData['last_name']);

        // Validate required fields
        $requiredFields = ['first_name', 'last_name', 'class_id', 'academic_year_id', 'date_of_birth'];
        $missingFields = [];
        
        foreach ($requiredFields as $field) {
            if (empty($studentData[$field])) {
                $missingFields[] = str_replace('_', ' ', $field);
                error_log("Missing required field: " . $field);
            }
        }

        if (!empty($missingFields)) {
            $errorMsg = "Please fill all required fields: " . implode(', ', $missingFields);
            error_log("Validation FAILED: " . $errorMsg);
            $_SESSION['toast_error'] = $errorMsg;
            $_SESSION['form_data'] = $_POST;
            header("Location: add-new-student.php?class_id=" . $selectedClass);
            exit;
        }
        
        error_log("Validation PASSED - all required fields present");
        
        // Add student using StudentManager
        try {
            error_log("Calling StudentManager->addStudent()");
            $startTime = microtime(true);
            
            $result = $studentManager->addStudent($studentData);
            
            $endTime = microtime(true);
            $executionTime = round(($endTime - $startTime) * 1000, 2);
            error_log("StudentManager->addStudent() completed in {$executionTime}ms");
            
            if (is_array($result) && count($result) >= 2) {
                $success = $result[0];
                $resultMessage = $result[1];
                $studentId = $result[2] ?? null;
                
                if ($success) {
                    error_log("=== STUDENT ADDED SUCCESSFULLY ===");
                    error_log("Student ID: " . ($studentId ?? 'N/A'));
                    error_log("Message: " . $resultMessage);
                    
                    $_SESSION['toast_success'] = $resultMessage;
                    unset($_SESSION['form_data']);

                    // *** FIX: Redirect to student-list.php on success ***
                    $redirectUrl = "student-list.php";
                    if (!empty($selectedClass)) {
                        $redirectUrl .= "?class_id=" . $selectedClass;
                    }
                    error_log("Redirecting to student list: " . $redirectUrl);
                    header("Location: " . $redirectUrl);
                    exit;
                    
                } else {
                    error_log("=== STUDENT ADDITION FAILED ===");
                    error_log("Error message: " . $resultMessage);
                    $_SESSION['toast_error'] = $resultMessage;
                    $_SESSION['form_data'] = $_POST;

                    // *** FIX: Stay on add-new-student.php on failure ***
                    $redirectUrl = "add-new-student.php";
                    if (!empty($selectedClass)) {
                        $redirectUrl .= "?class_id=" . $selectedClass;
                    }
                    error_log("Redirecting back to add form: " . $redirectUrl);
                    header("Location: " . $redirectUrl);
                    exit;
                }
            } else {
                error_log("=== UNEXPECTED RESPONSE FROM STUDENTMANAGER ===");
                $_SESSION['toast_error'] = "Unexpected response from StudentManager";
                $_SESSION['form_data'] = $_POST;

                $redirectUrl = "add-new-student.php";
                if (!empty($selectedClass)) {
                    $redirectUrl .= "?class_id=" . $selectedClass;
                }
                header("Location: " . $redirectUrl);
                exit;
            }
        } catch (Exception $e) {
            error_log("=== EXCEPTION IN STUDENT ADDITION ===");
            error_log("Exception message: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            
            $_SESSION['toast_error'] = "An error occurred while adding the student. Please try again.";
            $_SESSION['form_data'] = $_POST;

            $redirectUrl = "add-new-student.php";
            if (!empty($selectedClass)) {
                $redirectUrl .= "?class_id=" . $selectedClass;
            }
            header("Location: " . $redirectUrl);
            exit;
        }
        
    } else {
        error_log("WARNING: POST request detected but not a valid form submission");
        error_log("StudentManager available: " . ($studentManager ? 'YES' : 'NO'));
        error_log("first_name present: " . (isset($_POST['first_name']) ? 'YES' : 'NO'));
        error_log("last_name present: " . (isset($_POST['last_name']) ? 'YES' : 'NO'));

        // In case of unexpected POST, redirect back to form
        $redirectUrl = "add-new-student.php";
        if (!empty($selectedClass)) {
            $redirectUrl .= "?class_id=" . $selectedClass;
        }
        header("Location: " . $redirectUrl);
        exit;
    }
}

// Get form data from session if it exists (for repopulation after error)
if (isset($_SESSION['form_data']) && !empty($_SESSION['form_data'])) {
    error_log("Restoring form data from session for repopulation");
    $formData = $_SESSION['form_data'];
    unset($_SESSION['form_data']);
}

// Collect toast messages from session
$toastSuccess = $_SESSION['toast_success'] ?? '';
$toastError = $_SESSION['toast_error'] ?? '';
$toastWarning = $_SESSION['toast_warning'] ?? '';
$toastInfo = $_SESSION['toast_info'] ?? '';

// Clear session toasts
unset($_SESSION['toast_success'], $_SESSION['toast_error'], $_SESSION['toast_warning'], $_SESSION['toast_info']);

// Generate CSRF token
$csrfToken = generateCsrfToken();

/**
 * Handle AJAX requests – ENHANCED to return class capacity and enrollment
 */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['ajax'])) {
    error_log("Processing AJAX request: " . $_GET['ajax']);
    header('Content-Type: application/json');
    
    try {
        if ($_GET['ajax'] === 'get_sections' && isset($_GET['class_id']) && $studentManager) {
            $classId = (int)$_GET['class_id'];
            
            // Get class capacity
            $classStmt = $schoolDb->prepare("SELECT capacity FROM classes WHERE id = ? AND school_id = ?");
            $classStmt->execute([$classId, $school['id']]);
            $class = $classStmt->fetch(PDO::FETCH_ASSOC);
            $capacity = $class ? (int)$class['capacity'] : 0;
            
            // Get current enrollment count for this class (active students)
            $countStmt = $schoolDb->prepare("SELECT COUNT(*) as count FROM students WHERE class_id = ? AND school_id = ? AND status = 'active'");
            $countStmt->execute([$classId, $school['id']]);
            $enrolled = $countStmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
            
            // Get sections
            $sections = $studentManager->getSectionsByClass($classId);
            
            echo json_encode([
                'success' => true,
                'sections' => $sections,
                'class_capacity' => $capacity,
                'class_enrolled' => $enrolled,
                'class_full' => ($capacity > 0 && $enrolled >= $capacity)
            ]);
            exit;
        }
        
        if ($_GET['ajax'] === 'search_parents' && isset($_GET['term']) && $studentManager) {
            $term = sanitize($_GET['term']);
            $parents = $studentManager->searchParents($term);
            echo json_encode(['success' => true, 'parents' => $parents]);
            exit;
        }
    } catch (Exception $e) {
        error_log("AJAX error: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'An error occurred']);
        exit;
    }
}

// Format currency symbol
$currencySymbol = $settings['currency_symbol'] ?? '₦';

error_log("=== ADD STUDENT PAGE END ===");
error_log("Page loaded with " . count($classes) . " classes, " . count($academicYears) . " academic years");
?>

<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="description" content="Add New Student - School Management System">
    <meta name="keywords" content="Add Student, School Management">
    <meta name="robots" content="INDEX,FOLLOW">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add New Student - <?php echo htmlspecialchars($school['name'] ?? 'School'); ?></title>
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
        .guardian-fields.disabled input,
        .guardian-fields.disabled select,
        .guardian-fields.disabled textarea {
            background-color: #f5f5f5;
            opacity: 0.7;
            cursor: not-allowed;
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
        .capacity-warning {
            border-left: 4px solid #ffc107;
            background-color: #fff3cd;
            color: #856404;
            padding: 10px 15px;
            border-radius: 4px;
            margin-top: 10px;
            font-size: 14px;
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
                <h1 class="fw-semibold mb-4 h6 text-primary-light">Add New Student</h1>
                <div>
                    <a href="index.php" class="text-secondary-light hover-text-primary hover-underline">Dashboard</a>
                    <a href="student-list.php" class="text-secondary-light hover-text-primary hover-underline"> / Student</a>
                    <span class="text-secondary-light"> / Add New Student</span>
                </div>
            </div>
        </div>

        <form method="POST" action="" class="mt-24" id="addStudentForm">
            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
            
            <div class="row gy-3">
                <!-- Academic Information -->
                <div class="col-lg-12">
                    <div class="shadow-1 radius-12 bg-base h-100 overflow-hidden">
                        <div class="card-header border-bottom bg-base py-16 px-24 d-flex align-items-center justify-content-between">
                            <h6 class="text-lg fw-semibold mb-0">Academic Information</h6>
                        </div>
                        <div class="card-body p-20">
                            <div class="row gy-3">
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <div class="form-group">
                                        <label for="academicYear" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                            Academic Year <span class="text-danger-600">*</span>
                                        </label>
                                        <select id="academicYear" name="academic_year" class="form-control form-select" required>
                                            <option value="">Select Academic Year</option>
                                            <?php foreach ($academicYears as $year): ?>
                                            <option value="<?php echo $year['id']; ?>" <?php echo (!empty($year['is_default']) && $year['is_default'] == 1) ? 'selected' : ''; ?> <?php echo (isset($formData['academic_year']) && $formData['academic_year'] == $year['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($year['name']); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <div class="form-group">
                                        <label for="classSelection" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                            Class <span class="text-danger-600">*</span>
                                        </label>
                                        <select id="classSelection" name="class" class="form-control form-select" required>
                                            <option value="">Select Class</option>
                                            <?php foreach ($classes as $class): ?>
                                            <option value="<?php echo $class['id']; ?>" <?php echo ($selectedClass == $class['id']) ? 'selected' : ''; ?> <?php echo (isset($formData['class']) && $formData['class'] == $class['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($class['name']); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <div class="form-group">
                                        <label for="section" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                            Section
                                        </label>
                                        <select id="section" name="section" class="form-control form-select">
                                            <option value="">Select Section</option>
                                            <?php foreach ($sections as $section): ?>
                                            <option value="<?php echo $section['id']; ?>" <?php echo (isset($formData['section']) && $formData['section'] == $section['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($section['name']); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <div class="form-group">
                                        <label for="rollNumber" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                            Roll Number
                                        </label>
                                        <input type="text" class="form-control" id="rollNumber" name="roll_number" 
                                               placeholder="Enter roll number" value="<?php echo htmlspecialchars($formData['roll_number'] ?? ''); ?>">
                                    </div>
                                </div>
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <div class="form-group">
                                        <label for="admissionDate" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                            Admission Date
                                        </label>
                                        <input type="date" class="form-control" id="admissionDate" name="admission_date" 
                                               value="<?php echo htmlspecialchars($formData['admission_date'] ?? date('Y-m-d')); ?>">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Personal Information -->
                <div class="col-lg-12">
                    <div class="shadow-1 radius-12 bg-base h-100 overflow-hidden">
                        <div class="card-header border-bottom bg-base py-16 px-24 d-flex align-items-center justify-content-between">
                            <h6 class="text-lg fw-semibold mb-0">Personal Information</h6>
                        </div>
                        <div class="card-body p-20">
                            <div class="row gy-3">
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <div class="form-group">
                                        <label for="firstName" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                            First Name <span class="text-danger-600">*</span>
                                        </label>
                                        <input type="text" class="form-control" id="firstName" name="first_name" 
                                               placeholder="Enter first name" required
                                               value="<?php echo htmlspecialchars($formData['first_name'] ?? ''); ?>">
                                    </div>
                                </div>
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <div class="form-group">
                                        <label for="middleName" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                            Middle Name
                                        </label>
                                        <input type="text" class="form-control" id="middleName" name="middle_name" 
                                               placeholder="Enter middle name"
                                               value="<?php echo htmlspecialchars($formData['middle_name'] ?? ''); ?>">
                                    </div>
                                </div>
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <div class="form-group">
                                        <label for="lastName" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                            Last Name <span class="text-danger-600">*</span>
                                        </label>
                                        <input type="text" class="form-control" id="lastName" name="last_name" 
                                               placeholder="Enter last name" required
                                               value="<?php echo htmlspecialchars($formData['last_name'] ?? ''); ?>">
                                    </div>
                                </div>
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <div class="form-group">
                                        <label for="gender" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                            Gender
                                        </label>
                                        <select id="gender" name="gender" class="form-control form-select">
                                            <option value="">Select Gender</option>
                                            <option value="male" <?php echo (isset($formData['gender']) && $formData['gender'] == 'male') ? 'selected' : ''; ?>>Male</option>
                                            <option value="female" <?php echo (isset($formData['gender']) && $formData['gender'] == 'female') ? 'selected' : ''; ?>>Female</option>
                                            <option value="other" <?php echo (isset($formData['gender']) && $formData['gender'] == 'other') ? 'selected' : ''; ?>>Other</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <div class="form-group">
                                        <label for="dateOfBirth" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                            Date Of Birth <span class="text-danger-600">*</span>
                                        </label>
                                        <input type="date" class="form-control" id="dateOfBirth" name="date_of_birth" required
                                               value="<?php echo htmlspecialchars($formData['date_of_birth'] ?? date('Y-m-d', strtotime('-6 years'))); ?>">
                                    </div>
                                </div>
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <div class="form-group">
                                        <label for="studentPhone" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                            Phone Number
                                        </label>
                                        <input type="tel" class="form-control" id="studentPhone" name="student_phone" 
                                               placeholder="Enter phone number"
                                               value="<?php echo htmlspecialchars($formData['student_phone'] ?? ''); ?>">
                                    </div>
                                </div>
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <div class="form-group">
                                        <label for="studentEmail" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                            Email
                                        </label>
                                        <input type="email" class="form-control" id="studentEmail" name="student_email" 
                                               placeholder="Enter email"
                                               value="<?php echo htmlspecialchars($formData['student_email'] ?? ''); ?>">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Parent/Guardian Information -->
                <div class="col-lg-12">
                    <div class="shadow-1 radius-12 bg-base h-100 overflow-hidden">
                        <div class="card-header border-bottom bg-base py-16 px-24 d-flex align-items-center justify-content-between">
                            <h6 class="text-lg fw-semibold mb-0">Parent/Guardian Information</h6>
                        </div>
                        <div class="card-body p-20">
                            <!-- Existing Parent Search -->
                            <div class="row mb-4">
                                <div class="col-12">
                                    <div class="alert alert-info">
                                        <h6 class="mb-2"><i class="ri-user-search-line me-2"></i>Link Existing Parent</h6>
                                        <p class="mb-2">Search for an existing parent by name, email, or phone:</p>
                                        <div class="input-group position-relative">
                                            <input type="text" class="form-control" id="parentSearch" 
                                                   placeholder="Type at least 3 characters to search...">
                                            <input type="hidden" name="existing_parent_id" id="selectedParentId" 
                                                   value="<?php echo htmlspecialchars($formData['existing_parent_id'] ?? ''); ?>">
                                            <button class="btn btn-outline-secondary" type="button" id="clearParentSearch">
                                                <i class="ri-close-line"></i> Clear
                                            </button>
                                        </div>
                                        <div id="parentSearchResults" class="list-group mt-2" style="display: none; max-height: 200px; overflow-y: auto;"></div>
                                    </div>
                                </div>
                            </div>

                            <!-- Guardian Fields -->
                            <div class="row gy-3 guardian-fields" id="guardianFields">
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <div class="form-group">
                                        <label for="guardianName" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                            Guardian Name
                                        </label>
                                        <input type="text" class="form-control" id="guardianName" name="guardian_name" 
                                               placeholder="Enter guardian's full name"
                                               value="<?php echo htmlspecialchars($formData['guardian_name'] ?? ''); ?>">
                                    </div>
                                </div>
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <div class="form-group">
                                        <label for="guardianRelation" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                            Relationship
                                        </label>
                                        <select id="guardianRelation" name="guardian_relation" class="form-control form-select">
                                            <option value="">Select Relationship</option>
                                            <option value="father">Father</option>
                                            <option value="mother">Mother</option>
                                            <option value="guardian">Other Guardian</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <div class="form-group">
                                        <label for="guardianPhone" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                            Phone Number
                                        </label>
                                        <input type="tel" class="form-control" id="guardianPhone" name="guardian_phone" 
                                               placeholder="Enter phone number"
                                               value="<?php echo htmlspecialchars($formData['guardian_phone'] ?? ''); ?>">
                                    </div>
                                </div>
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <div class="form-group">
                                        <label for="guardianEmail" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                            Email
                                        </label>
                                        <input type="email" class="form-control" id="guardianEmail" name="guardian_email" 
                                               placeholder="Enter email"
                                               value="<?php echo htmlspecialchars($formData['guardian_email'] ?? ''); ?>">
                                    </div>
                                </div>
                                <div class="col-12">
                                    <div class="form-group">
                                        <label for="guardianAddress" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                            Address
                                        </label>
                                        <textarea class="form-control" id="guardianAddress" name="guardian_address" 
                                                  rows="2" placeholder="Enter guardian address"><?php echo htmlspecialchars($formData['guardian_address'] ?? ''); ?></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Medical Information -->
                <div class="col-lg-12">
                    <div class="shadow-1 radius-12 bg-base h-100 overflow-hidden">
                        <div class="card-header border-bottom bg-base py-16 px-24 d-flex align-items-center justify-content-between">
                            <h6 class="text-lg fw-semibold mb-0">Medical Information</h6>
                        </div>
                        <div class="card-body p-20">
                            <div class="row gy-3">
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <div class="form-group">
                                        <label for="bloodGroup" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                            Blood Group
                                        </label>
                                        <select id="bloodGroup" name="blood_group" class="form-control form-select">
                                            <option value="">Select Blood Group</option>
                                            <?php foreach ($bloodGroups as $bg): ?>
                                            <option value="<?php echo $bg; ?>"><?php echo $bg; ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <div class="form-group">
                                        <label for="allergies" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                            Allergies
                                        </label>
                                        <input type="text" class="form-control" id="allergies" name="allergies" 
                                               placeholder="Any allergies"
                                               value="<?php echo htmlspecialchars($formData['allergies'] ?? ''); ?>">
                                    </div>
                                </div>
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <div class="form-group">
                                        <label for="medicalConditions" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                            Medical Conditions
                                        </label>
                                        <input type="text" class="form-control" id="medicalConditions" name="medical_conditions" 
                                               placeholder="Any medical conditions"
                                               value="<?php echo htmlspecialchars($formData['medical_conditions'] ?? ''); ?>">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Address Information -->
                <div class="col-lg-12">
                    <div class="shadow-1 radius-12 bg-base h-100 overflow-hidden">
                        <div class="card-header border-bottom bg-base py-16 px-24 d-flex align-items-center justify-content-between">
                            <h6 class="text-lg fw-semibold mb-0">Address Information</h6>
                        </div>
                        <div class="card-body p-20">
                            <div class="row gy-3">
                                <div class="col-sm-6">
                                    <div class="form-group">
                                        <label for="currentAddress" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                            Current Address
                                        </label>
                                        <textarea class="form-control" id="currentAddress" name="current_address" 
                                                  rows="2" placeholder="Enter current address"><?php echo htmlspecialchars($formData['current_address'] ?? ''); ?></textarea>
                                    </div>
                                </div>
                                <div class="col-sm-6">
                                    <div class="form-group">
                                        <label for="permanentAddress" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                            Permanent Address
                                        </label>
                                        <textarea class="form-control" id="permanentAddress" name="permanent_address" 
                                                  rows="2" placeholder="Enter permanent address"><?php echo htmlspecialchars($formData['permanent_address'] ?? ''); ?></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Previous School Information -->
                <div class="col-lg-12">
                    <div class="shadow-1 radius-12 bg-base h-100 overflow-hidden">
                        <div class="card-header border-bottom bg-base py-16 px-24 d-flex align-items-center justify-content-between">
                            <h6 class="text-lg fw-semibold mb-0">Previous School Information</h6>
                        </div>
                        <div class="card-body p-20">
                            <div class="row gy-3">
                                <div class="col-sm-6">
                                    <div class="form-group">
                                        <label for="previousSchool" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                            Previous School Name
                                        </label>
                                        <input type="text" class="form-control" id="previousSchool" name="previous_school" 
                                               placeholder="Enter previous school name"
                                               value="<?php echo htmlspecialchars($formData['previous_school'] ?? ''); ?>">
                                    </div>
                                </div>
                                <div class="col-sm-6">
                                    <div class="form-group">
                                        <label for="previousClass" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                            Previous Class
                                        </label>
                                        <input type="text" class="form-control" id="previousClass" name="previous_class" 
                                               placeholder="Enter previous class"
                                               value="<?php echo htmlspecialchars($formData['previous_class'] ?? ''); ?>">
                                    </div>
                                </div>
                                <div class="col-sm-6">
                                    <div class="form-group">
                                        <label for="transferCertificate" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                            Transfer Certificate No.
                                        </label>
                                        <input type="text" class="form-control" id="transferCertificate" name="transfer_certificate_no" 
                                               placeholder="Enter transfer certificate number"
                                               value="<?php echo htmlspecialchars($formData['transfer_certificate_no'] ?? ''); ?>">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Form Actions -->
                <div class="col-12">
                    <div class="d-flex align-items-center justify-content-center gap-3 mt-8">
                        <button type="reset" class="border border-danger-600 bg-hover-danger-200 text-danger-600 text-md px-50 py-11 radius-8">
                            <i class="ri-close-line me-2"></i>Cancel
                        </button>
                        <button type="submit" name="submit" class="btn btn-primary-600 border border-primary-600 text-md px-28 py-12 radius-8">
                            <i class="ri-user-add-line me-2"></i>Add Student
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <footer class="d-footer">
        <div>
            <p class="mb-0 text-center"> &copy; <span class="current-year"></span> <?php echo htmlspecialchars($school['name'] ?? 'School'); ?> | Made With ❤️ by AcademixSuite.</p>
        </div>
    </footer>
</main>

<script src="https://academixsuite.com/tenant/assets/js/lib/jquery-3.7.1.min.js"></script>
<script src="https://academixsuite.com/tenant/assets/js/lib/bootstrap.bundle.min.js"></script>
<script src="https://academixsuite.com/tenant/assets/js/lib/iconify-icon.min.js"></script>
<script src="https://academixsuite.com/tenant/assets/js/app.js"></script>

<script>
$(document).ready(function() {
    // Console log for debugging
    console.log('Document ready - Add Student Form initialized');
    
    // Cache DOM elements
    const $classSelect = $('#classSelection');
    const $sectionSelect = $('#section');
    const $parentSearch = $('#parentSearch');
    const $selectedParentId = $('#selectedParentId');
    const $parentResults = $('#parentSearchResults');
    const $guardianFields = $('#guardianFields');
    const $clearParentBtn = $('#clearParentSearch');
    
    // Initialize Bootstrap toasts
    $('.toast').toast({
        autohide: true,
        delay: 5000
    });
    
    // Show any toasts that are present
    $('.toast').toast('show');
    
    // Load sections when class changes – ENHANCED with capacity check
    $classSelect.on('change', function() {
        const classId = $(this).val();
        if (classId) {
            $.ajax({
                url: window.location.pathname,
                method: 'GET',
                data: {
                    ajax: 'get_sections',
                    class_id: classId
                },
                dataType: 'json',
                beforeSend: function() {
                    $sectionSelect.html('<option value="">Loading...</option>').prop('disabled', true);
                    // Remove any previous capacity warning
                    $('#classCapacityWarning').remove();
                },
                success: function(response) {
                    if (response.success) {
                        // Populate sections dropdown
                        let options = '<option value="">Select Section</option>';
                        $.each(response.sections, function(index, section) {
                            options += '<option value="' + section.id + '">' + section.name + '</option>';
                        });
                        $sectionSelect.html(options).prop('disabled', false);
                        
                        // Check class capacity and show warning if full
                        if (response.class_full) {
                            const warningHtml = '<div id="classCapacityWarning" class="capacity-warning">' +
                                '<i class="ri-alert-line me-2"></i>' +
                                '<strong>Class Full!</strong> This class has reached its maximum capacity ' +
                                '(' + response.class_enrolled + '/' + response.class_capacity + '). ' +
                                'Please increase class capacity or select another class.' +
                                '</div>';
                            $classSelect.closest('.form-group').after(warningHtml);
                        }
                    } else {
                        alert('Error loading sections: ' + (response.message || 'Unknown error'));
                    }
                },
                error: function() {
                    alert('An error occurred while loading sections.');
                }
            });
        } else {
            $sectionSelect.html('<option value="">Select Section</option>').prop('disabled', false);
            $('#classCapacityWarning').remove();
        }
    });

    // Parent search functionality
    let searchTimeout;
    $parentSearch.on('input', function() {
        clearTimeout(searchTimeout);
        const term = $(this).val().trim();
        
        if (term.length < 3) {
            $parentResults.hide();
            return;
        }

        searchTimeout = setTimeout(function() {
            $.ajax({
                url: window.location.pathname,
                method: 'GET',
                data: {
                    ajax: 'search_parents',
                    term: term
                },
                dataType: 'json',
                beforeSend: function() {
                    $parentResults.html('<div class="list-group-item text-center">Searching...</div>').show();
                },
                success: function(response) {
                    if (response.success && response.parents.length > 0) {
                        let html = '';
                        $.each(response.parents, function(index, parent) {
                            html += '<a href="#" class="list-group-item list-group-item-action parent-result" ' +
                                   'data-id="' + parent.id + '" ' +
                                   'data-name="' + escapeHtml(parent.name) + '">' +
                                   '<strong>' + escapeHtml(parent.name) + '</strong><br>' +
                                   '<small>Email: ' + escapeHtml(parent.email || 'N/A') + '</small>' +
                                   '</a>';
                        });
                        $parentResults.html(html).show();
                    } else {
                        $parentResults.html('<div class="list-group-item text-muted">No parents found</div>').show();
                    }
                }
            });
        }, 500);
    });

    // HTML escape function
    function escapeHtml(text) {
        if (!text) return text;
        return text.replace(/[&<>"']/g, function(m) {
            return {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            }[m];
        });
    }

    // Select parent from search results
    $(document).on('click', '.parent-result', function(e) {
        e.preventDefault();
        const parentId = $(this).data('id');
        const parentName = $(this).data('name');
        
        $parentSearch.val(parentName);
        $selectedParentId.val(parentId);
        $parentResults.hide();
        
        // Disable guardian fields
        $guardianFields.find('input, select, textarea').prop('disabled', true);
        $guardianFields.addClass('disabled');
    });

    // Clear parent selection
    $clearParentBtn.on('click', function() {
        $parentSearch.val('');
        $selectedParentId.val('');
        $parentResults.hide();
        
        // Enable guardian fields
        $guardianFields.find('input, select, textarea').prop('disabled', false);
        $guardianFields.removeClass('disabled');
    });

    // Form validation – prevent submission if class is full
    $('#addStudentForm').on('submit', function(e) {
        console.log('Form submit triggered');
        
        const firstName = $('#firstName').val().trim();
        const lastName = $('#lastName').val().trim();
        const dateOfBirth = $('#dateOfBirth').val();
        const academicYear = $('#academicYear').val();
        const classId = $('#classSelection').val();
        
        if (!firstName || !lastName || !dateOfBirth || !academicYear || !classId) {
            e.preventDefault();
            alert('Please fill in all required fields');
            return false;
        }
        
        // Validate guardian
        const existingParentId = $('#selectedParentId').val();
        const guardianName = $('#guardianName').val().trim();
        
        if (!existingParentId && !guardianName) {
            e.preventDefault();
            alert('Please either select an existing parent or enter guardian information');
            return false;
        }
        
        // Check if class is full
        if ($('#classCapacityWarning').length > 0) {
            e.preventDefault();
            alert('Cannot add student: the selected class is full. Please increase class capacity or choose another class.');
            return false;
        }
        
        // Show loading state
        $(this).find('button[type="submit"]').prop('disabled', true)
               .html('<span class="spinner-border spinner-border-sm me-2"></span>Adding Student...');
        
        return true;
    });

    // Set current year in footer
    $('.current-year').text(new Date().getFullYear());
});
</script>
</body>
</html>