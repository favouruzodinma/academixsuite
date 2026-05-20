<?php
/**
 * School Add Teacher Page
 * Handles adding new teachers using TeacherManager class
 * Subjects are REQUIRED, Classes are OPTIONAL
 * 
 * @package AcademixSuite
 * @version 2.0
 */

// Enable error reporting
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../../logs/school_add_teacher.log');

error_log("=== ADD TEACHER PAGE START ===");
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
    
    // Include TeacherManager
    $teacherManagerPath = __DIR__ . '/../../../includes/TeacherManager.php';
    if (!file_exists($teacherManagerPath)) {
        throw new Exception("TeacherManager file not found");
    }
    require_once $teacherManagerPath;
    
} catch (Exception $e) {
    error_log("Error loading files: " . $e->getMessage());
    http_response_code(500);
    die("Configuration loading failed.");
}

// Connect to school database
$schoolDb = null;
$teacherManager = null;
try {
    if (!empty($school['database_name'])) {
        $schoolDb = Database::getSchoolConnection($school['database_name']);
        error_log("School database connection successful");
        
        // Initialize TeacherManager
        $teacherManager = new TeacherManager($schoolDb, $school['id'], $userId, $userType, $school);
        error_log("TeacherManager initialized successfully");
    }
} catch (Exception $e) {
    error_log("ERROR connecting to school database: " . $e->getMessage());
    $schoolDb = null;
}

// Initialize variables
$settings = [];
$classes = [];
$subjects = [];
$academicYears = [];
$bloodGroups = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
$contractTypes = ['Contractual', 'Permanent', 'Temporary', 'Hourly', 'Part-time'];
$maritalStatuses = ['Married', 'Unmarried', 'Divorced', 'Widowed'];
$genders = ['male', 'female', 'other'];
$shifts = ['Day Shift', 'Night Shift', 'Morning Shift', 'Afternoon Shift'];
$adminUser = ['name' => 'Admin User', 'role_name' => 'Administrator'];
$formData = $_POST;

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

        // Get classes for assignment (OPTIONAL)
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

        // Get subjects for assignment (REQUIRED)
        $subjectStmt = $schoolDb->prepare("
            SELECT * FROM subjects 
            WHERE school_id = ? AND is_active = 1
            ORDER BY name
        ");
        if ($subjectStmt) {
            $subjectStmt->execute([$school['id']]);
            $subjects = $subjectStmt->fetchAll(PDO::FETCH_ASSOC);
        }

    } catch (Exception $e) {
        error_log("Error fetching data: " . $e->getMessage());
        $_SESSION['toast_error'] = "Error loading form data. Please refresh.";
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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit']) && $teacherManager) {
    
    error_log("=== PROCESSING TEACHER FORM SUBMISSION ===");
    
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        $_SESSION['toast_error'] = "Invalid security token. Please try again.";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
    
    // Prepare teacher data
    $teacherData = [
        'employee_id' => !empty($_POST['employee_id']) ? sanitize($_POST['employee_id']) : null,
        'name' => sanitize($_POST['full_name'] ?? ''),
        'email' => sanitize($_POST['email'] ?? ''),
        'phone' => sanitize($_POST['phone'] ?? ''),
        'gender' => $_POST['gender'] ?? null,
        'date_of_birth' => $_POST['date_of_birth'] ?? null,
        'fathers_name' => sanitize($_POST['fathers_name'] ?? ''),
        'mothers_name' => sanitize($_POST['mothers_name'] ?? ''),
        'marital_status' => $_POST['marital_status'] ?? null,
        'contract_type' => $_POST['contract_type'] ?? null,
        'shift' => $_POST['shift'] ?? null,
        'work_location' => sanitize($_POST['work_location'] ?? ''),
        'joining_date' => $_POST['joining_date'] ?? null,
        'qualification' => sanitize($_POST['qualification'] ?? ''),
        'experience_years' => !empty($_POST['experience']) ? (int)$_POST['experience'] : null,
        'blood_group' => $_POST['blood_group'] ?? null,
        'height' => sanitize($_POST['height'] ?? ''),
        'weight' => sanitize($_POST['weight'] ?? ''),
        'bank_name' => sanitize($_POST['bank_name'] ?? ''),
        'bank_account' => sanitize($_POST['bank_account_number'] ?? ''),
        'ifsc_code' => sanitize($_POST['ifsc_code'] ?? ''),
        'national_id' => sanitize($_POST['national_id'] ?? ''),
        'current_address' => sanitize($_POST['current_address'] ?? ''),
        'permanent_address' => sanitize($_POST['permanent_address'] ?? ''),
        'previous_school' => sanitize($_POST['previous_school'] ?? ''),
        'previous_school_address' => sanitize($_POST['previous_school_address'] ?? ''),
        'facebook_link' => sanitize($_POST['facebook_link'] ?? ''),
        'linkedin_link' => sanitize($_POST['linkedin_link'] ?? ''),
        'instagram_link' => sanitize($_POST['instagram_link'] ?? ''),
        'youtube_link' => sanitize($_POST['youtube_link'] ?? ''),
        'details' => sanitize($_POST['details'] ?? ''),
        'password' => $_POST['password'] ?? null,
        'profile_photo' => null // Handle file upload separately
    ];

    // Handle file upload if present
    if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = __DIR__ . '/../../../uploads/teachers/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        $mimeToExt = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
        $detectedMime = function_exists('finfo_open')
            ? (function($p) { $fi = finfo_open(FILEINFO_MIME_TYPE); $m = finfo_file($fi, $p); finfo_close($fi); return $m; })($_FILES['profile_photo']['tmp_name'])
            : (mime_content_type($_FILES['profile_photo']['tmp_name']) ?: '');
        if (isset($mimeToExt[$detectedMime])) {
            $filename = 'teacher_' . time() . '_' . uniqid() . '.' . $mimeToExt[$detectedMime];
            $uploadPath = $uploadDir . $filename;
            if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $uploadPath)) {
                $teacherData['profile_photo'] = 'uploads/teachers/' . $filename;
            }
        }
    }

    // Get assigned classes (OPTIONAL)
    $teacherData['assigned_classes'] = $_POST['assigned_classes'] ?? [];
    
    // Get assigned subjects (REQUIRED)
    $teacherData['assigned_subjects'] = [];
    if (!empty($_POST['assigned_subjects'])) {
        foreach ($_POST['assigned_subjects'] as $subjectId) {
            foreach ($subjects as $subject) {
                if ($subject['id'] == $subjectId) {
                    $teacherData['assigned_subjects'][] = $subject;
                    break;
                }
            }
        }
    }

    error_log("Teacher data prepared: " . json_encode(array_keys($teacherData)));
    error_log("Assigned subjects count: " . count($teacherData['assigned_subjects']));
    error_log("Assigned classes count: " . count($teacherData['assigned_classes']));

    // Validate required fields
    $requiredFields = ['name', 'email', 'phone', 'date_of_birth'];
    $missingFields = [];
    
    foreach ($requiredFields as $field) {
        if (empty($teacherData[$field])) {
            $missingFields[] = str_replace('_', ' ', $field);
        }
    }

    // Validate that at least one subject is assigned (REQUIRED)
    if (empty($teacherData['assigned_subjects'])) {
        $missingFields[] = 'at least one subject';
        error_log("Validation failed: No subjects assigned");
    }

    if (!empty($missingFields)) {
        $_SESSION['toast_error'] = "Please fill all required fields and assign at least one subject: " . implode(', ', $missingFields);
        $_SESSION['form_data'] = $_POST;
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
    
    // Add teacher using TeacherManager
    $result = $teacherManager->addTeacher($teacherData);
    
    if ($result[0]) {
        $_SESSION['toast_success'] = $result[1];
        error_log("Teacher added successfully: " . $result[1]);

        // Send school-branded welcome email with login credentials
        $newTeacherUserId = $result[2] ?? null;   // addTeacher returns [true, message, teacherRecordId]
        if ($newTeacherUserId && $teacherManager) {
            // Resolve the users.id from the teachers record id returned
            try {
                $tuStmt = $schoolDb->prepare("SELECT user_id FROM teachers WHERE id = ? AND school_id = ?");
                $tuStmt->execute([$newTeacherUserId, $school['id']]);
                $tuRow = $tuStmt->fetch(PDO::FETCH_ASSOC);
                if ($tuRow && !empty($tuRow['user_id'])) {
                    $teacherManager->sendWelcomeEmail((int) $tuRow['user_id']);
                }
            } catch (Throwable $emailEx) {
                error_log("add-new-teacher: welcome email lookup failed: " . $emailEx->getMessage());
            }
        }

        // Clear form data
        unset($_SESSION['form_data']);
        $_POST = [];

        // Redirect to teacher list
        header("Location: teacher-list.php");
        exit;
    } else {
        $_SESSION['toast_error'] = $result[1];
        error_log("Failed to add teacher: " . $result[1]);
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

// Collect toast messages
$toastSuccess = $_SESSION['toast_success'] ?? '';
$toastError = $_SESSION['toast_error'] ?? '';
unset($_SESSION['toast_success'], $_SESSION['toast_error']);

// Generate CSRF token
$csrfToken = generateCsrfToken();

// Get auto-generated employee ID for preview
$autoEmployeeId = $teacherManager ? $teacherManager->generateEmployeeId() : 'TCH-' . date('Y') . '-0001';

error_log("=== ADD TEACHER PAGE END ===");
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="description" content="Add New Teacher - School Management System">
    <meta name="keywords" content="Add Teacher, School Management">
    <meta name="robots" content="INDEX,FOLLOW">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add New Teacher - <?php echo htmlspecialchars($school['name'] ?? 'School'); ?></title>
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
        .assignment-section {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
            transition: all 0.3s ease;
        }
        .assignment-section.required-section {
            border-left: 4px solid #dc3545;
        }
        .assignment-section.border-danger {
            border-left: 4px solid #dc3545;
            background: #fff5f5;
        }
        .assignment-title {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 15px;
            color: #25A194;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .required-badge {
            background-color: #dc3545;
            color: white;
            font-size: 11px;
            padding: 3px 10px;
            border-radius: 20px;
            font-weight: 500;
            letter-spacing: 0.5px;
        }
        .optional-badge {
            background-color: #6c757d;
            color: white;
            font-size: 11px;
            padding: 3px 10px;
            border-radius: 20px;
            font-weight: 500;
        }
        .checkbox-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 10px;
            max-height: 300px;
            overflow-y: auto;
            padding: 15px;
            background: white;
            border-radius: 8px;
            border: 1px solid #dee2e6;
        }
        .checkbox-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px;
            border-radius: 6px;
            transition: background 0.2s;
        }
        .checkbox-item:hover {
            background: #e9f2ff;
        }
        .checkbox-item input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }
        .subject-required-message {
            color: #dc3545;
            font-size: 13px;
            margin-top: 10px;
            padding: 8px 12px;
            background: #fff5f5;
            border-radius: 6px;
            border: 1px solid #ffcdd2;
            display: none;
        }
        .subject-required-message.show {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .section-info {
            font-size: 12px;
            color: #6c757d;
            margin-top: 8px;
            display: block;
        }
        .select-all-link {
            font-size: 12px;
            color: #25A194;
            cursor: pointer;
            margin-left: 10px;
            text-decoration: none;
        }
        .select-all-link:hover {
            text-decoration: underline;
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
                    <form class="navbar-search" method="GET" action="teacher-list.php">
                        <input type="text" class="bg-transparent" name="search" placeholder="Search teachers...">
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
                <h1 class="fw-semibold mb-4 h6 text-primary-light">Add New Teacher</h1>
                <div class="">
                    <a href="index.php" class="text-secondary-light hover-text-primary hover-underline">Dashboard</a>
                    <a href="teacher-list.php" class="text-secondary-light hover-text-primary hover-underline"> / Teacher</a>
                    <span class="text-secondary-light">/ Add New Teacher</span>
                </div>
            </div>
        </div>

        <form method="POST" action="" class="mt-24" enctype="multipart/form-data" id="teacherForm">
            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
            <input type="hidden" name="submit" value="1">
            
            <div class="row gy-3">
                <!-- Personal Information -->
                <div class="col-lg-12">
                    <div class="shadow-1 radius-12 bg-base h-100 overflow-hidden">
                        <div class="card-header border-bottom bg-base py-16 px-24 d-flex align-items-center justify-content-between">
                            <h6 class="text-lg fw-semibold mb-0">Personal Information</h6>
                            <span class="badge bg-primary">Required Fields *</span>
                        </div>
                        <div class="card-body p-20">
                            <div class="row gy-3">
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <div class="">
                                        <label for="employee_id" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                            Employee ID
                                        </label>
                                        <input type="text" class="form-control" id="employee_id" name="employee_id"
                                            value="<?php echo htmlspecialchars($formData['employee_id'] ?? ''); ?>"
                                            placeholder="Leave empty to auto-generate">
                                        <small class="text-secondary-light">Auto-generated: <?php echo $autoEmployeeId; ?></small>
                                    </div>
                                </div>
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <div class="">
                                        <label for="full_name" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                            Full Name <span class="text-danger-600">*</span>
                                        </label>
                                        <input type="text" class="form-control" id="full_name" name="full_name"
                                            value="<?php echo htmlspecialchars($formData['full_name'] ?? ''); ?>"
                                            placeholder="Enter full name" required>
                                    </div>
                                </div>
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <div class="">
                                        <label for="email" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                            Email <span class="text-danger-600">*</span>
                                        </label>
                                        <input type="email" class="form-control" id="email" name="email"
                                            value="<?php echo htmlspecialchars($formData['email'] ?? ''); ?>"
                                            placeholder="Enter email" required>
                                    </div>
                                </div>
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <div class="">
                                        <label for="phone" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                            Phone Number <span class="text-danger-600">*</span>
                                        </label>
                                        <input type="tel" class="form-control" id="phone" name="phone"
                                            value="<?php echo htmlspecialchars($formData['phone'] ?? ''); ?>"
                                            placeholder="Enter phone number" required>
                                    </div>
                                </div>
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <div class="">
                                        <label for="gender" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Gender</label>
                                        <select id="gender" name="gender" class="form-control form-select">
                                            <option value="">Select Gender</option>
                                            <?php foreach ($genders as $gender): ?>
                                            <option value="<?php echo $gender; ?>" <?php echo (isset($formData['gender']) && $formData['gender'] == $gender) ? 'selected' : ''; ?>>
                                                <?php echo ucfirst($gender); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <div class="">
                                        <label for="date_of_birth" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                            Date Of Birth <span class="text-danger-600">*</span>
                                        </label>
                                        <input type="date" class="form-control" id="date_of_birth" name="date_of_birth"
                                            value="<?php echo htmlspecialchars($formData['date_of_birth'] ?? ''); ?>" required>
                                    </div>
                                </div>
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <div class="">
                                        <label for="fathers_name" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Father's Name</label>
                                        <input type="text" class="form-control" id="fathers_name" name="fathers_name"
                                            value="<?php echo htmlspecialchars($formData['fathers_name'] ?? ''); ?>"
                                            placeholder="Enter father's name">
                                    </div>
                                </div>
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <div class="">
                                        <label for="mothers_name" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Mother's Name</label>
                                        <input type="text" class="form-control" id="mothers_name" name="mothers_name"
                                            value="<?php echo htmlspecialchars($formData['mothers_name'] ?? ''); ?>"
                                            placeholder="Enter mother's name">
                                    </div>
                                </div>
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <div class="">
                                        <label for="marital_status" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Marital Status</label>
                                        <select id="marital_status" name="marital_status" class="form-control form-select">
                                            <option value="">Select Status</option>
                                            <?php foreach ($maritalStatuses as $status): ?>
                                            <option value="<?php echo $status; ?>" <?php echo (isset($formData['marital_status']) && $formData['marital_status'] == $status) ? 'selected' : ''; ?>>
                                                <?php echo $status; ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Class and Subject Assignments -->
                <div class="col-lg-12">
                    <div class="shadow-1 radius-12 bg-base h-100 overflow-hidden">
                        <div class="card-header border-bottom bg-base py-16 px-24 d-flex align-items-center justify-content-between">
                            <h6 class="text-lg fw-semibold mb-0">Teaching Assignments</h6>
                        </div>
                        <div class="card-body p-20">
                            <div class="alert alert-info mb-20">
                                <i class="ri-information-line me-2"></i>
                                <strong>Note:</strong> Every teacher must be assigned to at least one subject. 
                                Class assignments are optional and only for homeroom/class teachers.
                            </div>
                            <div class="row gy-3">
                                <div class="col-md-6">
                                    <div class="assignment-section">
                                        <div class="assignment-title">
                                            <i class="ri-group-line me-2"></i>Class Teacher Assignment
                                            <span class="optional-badge">Optional</span>
                                        </div>
                                        <div class="checkbox-grid">
                                            <?php if (!empty($classes)): ?>
                                                <?php foreach ($classes as $class): ?>
                                                <label class="checkbox-item">
                                                    <input type="checkbox" name="assigned_classes[]" 
                                                        value="<?php echo $class['id']; ?>"
                                                        <?php echo (isset($formData['assigned_classes']) && in_array($class['id'], (array)$formData['assigned_classes'])) ? 'checked' : ''; ?>>
                                                    <span><?php echo htmlspecialchars($class['name']); ?> 
                                                        (<?php echo htmlspecialchars($class['code']); ?>)</span>
                                                </label>
                                                <?php endforeach; ?>
                                                <div class="mt-2">
                                                    <span class="select-all-link" onclick="toggleAllClasses()">
                                                        <i class="ri-checkbox-line"></i> Select All Classes
                                                    </span>
                                                </div>
                                            <?php else: ?>
                                                <p class="text-secondary-light">No classes available</p>
                                            <?php endif; ?>
                                        </div>
                                        <small class="section-info">
                                            <i class="ri-information-line me-1"></i>
                                            Select if teacher will be homeroom/class teacher
                                        </small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="assignment-section required-section" id="subjectSection">
                                        <div class="assignment-title">
                                            <i class="ri-book-open-line me-2"></i>Subject Assignment
                                            <span class="required-badge">Required</span>
                                        </div>
                                        <div class="checkbox-grid">
                                            <?php if (!empty($subjects)): ?>
                                                <?php foreach ($subjects as $subject): ?>
                                                <label class="checkbox-item">
                                                    <input type="checkbox" name="assigned_subjects[]" 
                                                        class="subject-checkbox"
                                                        value="<?php echo $subject['id']; ?>"
                                                        <?php echo (isset($formData['assigned_subjects']) && in_array($subject['id'], (array)$formData['assigned_subjects'])) ? 'checked' : ''; ?>>
                                                    <span><?php echo htmlspecialchars($subject['name']); ?> 
                                                        (<?php echo htmlspecialchars($subject['code']); ?>)</span>
                                                </label>
                                                <?php endforeach; ?>
                                                <div class="mt-2">
                                                    <span class="select-all-link" onclick="toggleAllSubjects()">
                                                        <i class="ri-checkbox-line"></i> Select All Subjects
                                                    </span>
                                                </div>
                                            <?php else: ?>
                                                <p class="text-secondary-light">No subjects available</p>
                                            <?php endif; ?>
                                        </div>
                                        <div class="subject-required-message" id="subjectRequiredMessage">
                                            <i class="ri-error-warning-line"></i>
                                            Please select at least one subject
                                        </div>
                                        <small class="section-info">
                                            <i class="ri-information-line me-1"></i>
                                            <strong class="text-danger">Required:</strong> At least one subject must be selected
                                        </small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Employment Details -->
                <div class="col-lg-12">
                    <div class="shadow-1 radius-12 bg-base h-100 overflow-hidden">
                        <div class="card-header border-bottom bg-base py-16 px-24 d-flex align-items-center justify-content-between">
                            <h6 class="text-lg fw-semibold mb-0">Employment Details</h6>
                        </div>
                        <div class="card-body p-20">
                            <div class="row gy-3">
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <div class="">
                                        <label for="contract_type" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Contract Type</label>
                                        <select id="contract_type" name="contract_type" class="form-control form-select">
                                            <option value="">Select Contract Type</option>
                                            <?php foreach ($contractTypes as $type): ?>
                                            <option value="<?php echo $type; ?>" <?php echo (isset($formData['contract_type']) && $formData['contract_type'] == $type) ? 'selected' : ''; ?>>
                                                <?php echo $type; ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <div class="">
                                        <label for="shift" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Shift</label>
                                        <select id="shift" name="shift" class="form-control form-select">
                                            <option value="">Select Shift</option>
                                            <?php foreach ($shifts as $shift): ?>
                                            <option value="<?php echo $shift; ?>" <?php echo (isset($formData['shift']) && $formData['shift'] == $shift) ? 'selected' : ''; ?>>
                                                <?php echo $shift; ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <div class="">
                                        <label for="work_location" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Work Location</label>
                                        <input type="text" class="form-control" id="work_location" name="work_location"
                                            value="<?php echo htmlspecialchars($formData['work_location'] ?? ''); ?>"
                                            placeholder="Enter work location">
                                    </div>
                                </div>
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <div class="">
                                        <label for="joining_date" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Joining Date</label>
                                        <input type="date" class="form-control" id="joining_date" name="joining_date"
                                            value="<?php echo htmlspecialchars($formData['joining_date'] ?? ''); ?>">
                                    </div>
                                </div>
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <div class="">
                                        <label for="qualification" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Qualification</label>
                                        <input type="text" class="form-control" id="qualification" name="qualification"
                                            value="<?php echo htmlspecialchars($formData['qualification'] ?? ''); ?>"
                                            placeholder="Enter qualification">
                                    </div>
                                </div>
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <div class="">
                                        <label for="experience" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Experience (Years)</label>
                                        <input type="number" class="form-control" id="experience" name="experience"
                                            value="<?php echo htmlspecialchars($formData['experience'] ?? ''); ?>"
                                            placeholder="Enter years of experience">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Medical Details -->
                <div class="col-lg-12">
                    <div class="shadow-1 radius-12 bg-base h-100 overflow-hidden">
                        <div class="card-header border-bottom bg-base py-16 px-24 d-flex align-items-center justify-content-between">
                            <h6 class="text-lg fw-semibold mb-0">Medical Details</h6>
                        </div>
                        <div class="card-body p-20">
                            <div class="row gy-3">
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <div class="">
                                        <label for="blood_group" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Blood Group</label>
                                        <select id="blood_group" name="blood_group" class="form-control form-select">
                                            <option value="">Select Blood Group</option>
                                            <?php foreach ($bloodGroups as $bg): ?>
                                            <option value="<?php echo $bg; ?>" <?php echo (isset($formData['blood_group']) && $formData['blood_group'] == $bg) ? 'selected' : ''; ?>>
                                                <?php echo $bg; ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <div class="">
                                        <label for="height" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Height (cm)</label>
                                        <input type="text" class="form-control" id="height" name="height"
                                            value="<?php echo htmlspecialchars($formData['height'] ?? ''); ?>"
                                            placeholder="Enter height">
                                    </div>
                                </div>
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <div class="">
                                        <label for="weight" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Weight (kg)</label>
                                        <input type="text" class="form-control" id="weight" name="weight"
                                            value="<?php echo htmlspecialchars($formData['weight'] ?? ''); ?>"
                                            placeholder="Enter weight">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Bank Details -->
                <div class="col-lg-12">
                    <div class="shadow-1 radius-12 bg-base h-100 overflow-hidden">
                        <div class="card-header border-bottom bg-base py-16 px-24 d-flex align-items-center justify-content-between">
                            <h6 class="text-lg fw-semibold mb-0">Bank Details</h6>
                        </div>
                        <div class="card-body p-20">
                            <div class="row gy-3">
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <div class="">
                                        <label for="bank_account_number" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Bank Account Number</label>
                                        <input type="text" class="form-control" id="bank_account_number" name="bank_account_number"
                                            value="<?php echo htmlspecialchars($formData['bank_account_number'] ?? ''); ?>"
                                            placeholder="Enter account number">
                                    </div>
                                </div>
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <div class="">
                                        <label for="bank_name" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Bank Name</label>
                                        <input type="text" class="form-control" id="bank_name" name="bank_name"
                                            value="<?php echo htmlspecialchars($formData['bank_name'] ?? ''); ?>"
                                            placeholder="Enter bank name">
                                    </div>
                                </div>
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <div class="">
                                        <label for="ifsc_code" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">IFSC Code</label>
                                        <input type="text" class="form-control" id="ifsc_code" name="ifsc_code"
                                            value="<?php echo htmlspecialchars($formData['ifsc_code'] ?? ''); ?>"
                                            placeholder="Enter IFSC code">
                                    </div>
                                </div>
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <div class="">
                                        <label for="national_id" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">National ID</label>
                                        <input type="text" class="form-control" id="national_id" name="national_id"
                                            value="<?php echo htmlspecialchars($formData['national_id'] ?? ''); ?>"
                                            placeholder="Enter national ID">
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
                                    <div class="">
                                        <label for="current_address" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Current Address</label>
                                        <textarea class="form-control" id="current_address" name="current_address" rows="2" placeholder="Enter current address"><?php echo htmlspecialchars($formData['current_address'] ?? ''); ?></textarea>
                                    </div>
                                </div>
                                <div class="col-sm-6">
                                    <div class="">
                                        <label for="permanent_address" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Permanent Address</label>
                                        <textarea class="form-control" id="permanent_address" name="permanent_address" rows="2" placeholder="Enter permanent address"><?php echo htmlspecialchars($formData['permanent_address'] ?? ''); ?></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Previous School -->
                <div class="col-lg-12">
                    <div class="shadow-1 radius-12 bg-base h-100 overflow-hidden">
                        <div class="card-header border-bottom bg-base py-16 px-24 d-flex align-items-center justify-content-between">
                            <h6 class="text-lg fw-semibold mb-0">Previous Employment</h6>
                        </div>
                        <div class="card-body p-20">
                            <div class="row gy-3">
                                <div class="col-sm-6">
                                    <div class="">
                                        <label for="previous_school" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Previous School/Institution</label>
                                        <input type="text" class="form-control" id="previous_school" name="previous_school"
                                            value="<?php echo htmlspecialchars($formData['previous_school'] ?? ''); ?>"
                                            placeholder="Enter previous school name">
                                    </div>
                                </div>
                                <div class="col-sm-6">
                                    <div class="">
                                        <label for="previous_school_address" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Address</label>
                                        <input type="text" class="form-control" id="previous_school_address" name="previous_school_address"
                                            value="<?php echo htmlspecialchars($formData['previous_school_address'] ?? ''); ?>"
                                            placeholder="Enter address">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Social Links -->
                <div class="col-lg-12">
                    <div class="shadow-1 radius-12 bg-base h-100 overflow-hidden">
                        <div class="card-header border-bottom bg-base py-16 px-24 d-flex align-items-center justify-content-between">
                            <h6 class="text-lg fw-semibold mb-0">Social Media Links</h6>
                        </div>
                        <div class="card-body p-20">
                            <div class="row gy-3">
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <div class="">
                                        <label for="facebook_link" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Facebook</label>
                                        <input type="url" class="form-control" id="facebook_link" name="facebook_link"
                                            value="<?php echo htmlspecialchars($formData['facebook_link'] ?? ''); ?>"
                                            placeholder="https://facebook.com/username">
                                    </div>
                                </div>
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <div class="">
                                        <label for="linkedin_link" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">LinkedIn</label>
                                        <input type="url" class="form-control" id="linkedin_link" name="linkedin_link"
                                            value="<?php echo htmlspecialchars($formData['linkedin_link'] ?? ''); ?>"
                                            placeholder="https://linkedin.com/in/username">
                                    </div>
                                </div>
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <div class="">
                                        <label for="instagram_link" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Instagram</label>
                                        <input type="url" class="form-control" id="instagram_link" name="instagram_link"
                                            value="<?php echo htmlspecialchars($formData['instagram_link'] ?? ''); ?>"
                                            placeholder="https://instagram.com/username">
                                    </div>
                                </div>
                                <div class="col-xxl-3 col-xl-4 col-sm-6">
                                    <div class="">
                                        <label for="youtube_link" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">YouTube</label>
                                        <input type="url" class="form-control" id="youtube_link" name="youtube_link"
                                            value="<?php echo htmlspecialchars($formData['youtube_link'] ?? ''); ?>"
                                            placeholder="https://youtube.com/@channel">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Additional Details -->
                <div class="col-lg-12">
                    <div class="shadow-1 radius-12 bg-base h-100 overflow-hidden">
                        <div class="card-header border-bottom bg-base py-16 px-24 d-flex align-items-center justify-content-between">
                            <h6 class="text-lg fw-semibold mb-0">Additional Information</h6>
                        </div>
                        <div class="card-body p-20">
                            <div class="row gy-3">
                                <div class="col-sm-12">
                                    <div class="">
                                        <label for="details" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Teacher Details / Notes</label>
                                        <textarea id="details" name="details" class="form-control" rows="3" placeholder="Enter any additional details..."><?php echo htmlspecialchars($formData['details'] ?? ''); ?></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Login Details -->
                <div class="col-lg-12">
                    <div class="shadow-1 radius-12 bg-base h-100 overflow-hidden">
                        <div class="card-header border-bottom bg-base py-16 px-24 d-flex align-items-center justify-content-between">
                            <h6 class="text-lg fw-semibold mb-0">Login Details</h6>
                        </div>
                        <div class="card-body p-20">
                            <div class="row gy-3">
                                <div class="col-sm-6">
                                    <div class="">
                                        <label for="password" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Password</label>
                                        <div class="position-relative">
                                            <input type="password" id="password" name="password" class="form-control"
                                                placeholder="Enter password (leave empty to auto-generate)">
                                            <span class="toggle-password ri-eye-line cursor-pointer position-absolute end-0 top-50 translate-middle-y me-16 text-secondary-light" data-toggle="#password"></span>
                                        </div>
                                        <small class="text-secondary-light">Auto-generated if left empty</small>
                                    </div>
                                </div>
                                <div class="col-sm-6">
                                    <div class="">
                                        <label for="profile_photo" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Profile Photo</label>
                                        <div class="drop-zone height-44-px p-4 d-flex justify-content-center align-items-center text-center fw-medium text-md cursor-pointer border border-neutral-400 radius-8 border-dashed bg-hover-neutral-200">
                                            <span class="drop-zone__prompt">Drag & drop a file here or click</span>
                                            <input type="file" name="profile_photo" class="drop-zone__input" accept="image/*">
                                        </div>
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
                            Reset
                        </button>
                        <button type="submit" class="btn btn-primary-600 border border-primary-600 text-md px-28 py-12 radius-8">
                            Save Teacher
                        </button>
                    </div>
                </div>
            </div>
        </form>
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

    // Password toggle
    $('.toggle-password').on('click', function() {
        $(this).toggleClass("ri-eye-off-line");
        var input = $($(this).attr("data-toggle"));
        if (input.attr("type") === "password") {
            input.attr("type", "text");
        } else {
            input.attr("type", "password");
        }
    });

    // Form validation with subject requirement
    $('#teacherForm').on('submit', function(e) {
        const fullName = $('#full_name').val().trim();
        const email = $('#email').val().trim();
        const phone = $('#phone').val().trim();
        const dob = $('#date_of_birth').val();
        
        // Check if at least one subject is selected
        const subjectsChecked = $('input[name="assigned_subjects[]"]:checked').length;
        
        if (!fullName || !email || !phone || !dob) {
            e.preventDefault();
            alert('Please fill in all required fields');
            return false;
        }
        
        if (subjectsChecked === 0) {
            e.preventDefault();
            $('#subjectSection').addClass('border-danger');
            $('#subjectRequiredMessage').addClass('show');
            
            // Scroll to subject section
            $('html, body').animate({
                scrollTop: $('#subjectSection').offset().top - 100
            }, 500);
            
            return false;
        }
        
        // Show loading state
        $(this).find('button[type="submit"]').prop('disabled', true)
               .html('<span class="spinner-border spinner-border-sm me-2"></span>Saving...');
        
        return true;
    });

    // Remove error styling when a subject is selected
    $('.subject-checkbox').on('change', function() {
        const subjectsChecked = $('input[name="assigned_subjects[]"]:checked').length;
        if (subjectsChecked > 0) {
            $('#subjectSection').removeClass('border-danger');
            $('#subjectRequiredMessage').removeClass('show');
        }
    });

    // Drag & drop upload
    document.querySelectorAll(".drop-zone__input").forEach((inputElement) => {
        const dropZoneElement = inputElement.closest(".drop-zone");

        dropZoneElement.addEventListener("click", (e) => {
            inputElement.click();
        });

        inputElement.addEventListener("change", (e) => {
            if (inputElement.files.length) {
                updateThumbnail(dropZoneElement, inputElement.files[0]);
            }
        });

        dropZoneElement.addEventListener("dragover", (e) => {
            e.preventDefault();
            dropZoneElement.classList.add("drop-zone--over");
        });

        ["dragleave", "dragend"].forEach((type) => {
            dropZoneElement.addEventListener(type, (e) => {
                dropZoneElement.classList.remove("drop-zone--over");
            });
        });

        dropZoneElement.addEventListener("drop", (e) => {
            e.preventDefault();

            if (e.dataTransfer.files.length) {
                inputElement.files = e.dataTransfer.files;
                updateThumbnail(dropZoneElement, e.dataTransfer.files[0]);
            }

            dropZoneElement.classList.remove("drop-zone--over");
        });
    });

    function updateThumbnail(dropZoneElement, file) {
        let thumbnailElement = dropZoneElement.querySelector(".drop-zone__thumb");

        if (dropZoneElement.querySelector(".drop-zone__prompt")) {
            dropZoneElement.querySelector(".drop-zone__prompt").remove();
        }

        if (!thumbnailElement) {
            thumbnailElement = document.createElement("div");
            thumbnailElement.classList.add("drop-zone__thumb");
            dropZoneElement.appendChild(thumbnailElement);
        }

        thumbnailElement.dataset.label = file.name;

        if (file.type.startsWith("image/")) {
            const reader = new FileReader();
            reader.readAsDataURL(file);
            reader.onload = () => {
                thumbnailElement.style.backgroundImage = `url('${reader.result}')`;
            };
        } else {
            thumbnailElement.style.backgroundImage = null;
        }
    }

    // Set current year in footer
    $('.current-year').text(new Date().getFullYear());
});

// Global functions for select all
function toggleAllClasses() {
    const checkboxes = $('input[name="assigned_classes[]"]');
    const allChecked = checkboxes.length === checkboxes.filter(':checked').length;
    checkboxes.prop('checked', !allChecked);
}

function toggleAllSubjects() {
    const checkboxes = $('input[name="assigned_subjects[]"]');
    const allChecked = checkboxes.length === checkboxes.filter(':checked').length;
    checkboxes.prop('checked', !allChecked);
    
    // Trigger change event to update validation
    checkboxes.trigger('change');
}
</script>

</body>
</html>