<?php
/**
 * School Add Guardian Page
 * Handles adding new guardians to the school database
 * 
 * @package AcademixSuite
 * @version 2.0
 */

// Enable error reporting for debugging
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../../logs/school_add_guardian.log');

error_log("=== ADD GUARDIAN PAGE START ===");
error_log("Script: " . __FILE__);

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
$currentPage = $GLOBALS['CURRENT_PAGE'] ?? 'add-new-guardian.php';
$schoolData = $GLOBALS['SCHOOL_DATA'] ?? [];

error_log("School Slug: " . $schoolSlug);

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

/**
 * Load required files
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
    
    // Include GuardianManager
    $guardianManagerPath = __DIR__ . '/../../../includes/GuardianManager.php';
    error_log("Loading GuardianManager from: " . $guardianManagerPath);
    
    if (!file_exists($guardianManagerPath)) {
        throw new Exception("GuardianManager file not found at: " . $guardianManagerPath);
    } else {
        require_once $guardianManagerPath;
        error_log("GuardianManager loaded successfully");
    }
    
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
            error_log("School database connection successful");
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
 * Initialize GuardianManager
 */
$guardianManager = null;
if ($schoolDb) {
    try {
        $guardianManager = new GuardianManager($schoolDb, $school['id'], $userId, $userType, $school);
        error_log("GuardianManager initialized successfully");
    } catch (Exception $e) {
        error_log("ERROR initializing GuardianManager: " . $e->getMessage());
        $_SESSION['toast_error'] = "Failed to initialize guardian management system.";
    }
}

/**
 * Handle AJAX requests
 */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    
    try {
        if ($_GET['ajax'] === 'search_students' && isset($_GET['term']) && $guardianManager) {
            $term = sanitize($_GET['term']);
            $students = $guardianManager->searchStudents($term);
            echo json_encode(['success' => true, 'students' => $students]);
            exit;
        }
    } catch (Exception $e) {
        error_log("AJAX error: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'An error occurred']);
        exit;
    }
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

/**
 * Initialize variables
 */
$settings = [];
$guardianTypes = [
    'father' => 'Father',
    'mother' => 'Mother',
    'brother' => 'Brother',
    'sister' => 'Sister',
    'uncle' => 'Uncle',
    'aunt' => 'Aunt',
    'grandfather' => 'Grandfather',
    'grandmother' => 'Grandmother',
    'guardian' => 'Legal Guardian',
    'other' => 'Other'
];
$genders = [
    'male' => 'Male',
    'female' => 'Female',
    'other' => 'Other'
];
$notifications = [];
$notificationCount = 0;

// Get notifications from database
if ($guardianManager) {
    $notifications = $guardianManager->getNotifications();
    $notificationCount = $guardianManager->getNotificationCount();
}

// Store POST data for form repopulation
$formData = $_POST;

/**
 * Handle form submission
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardian_name'])) {
    error_log("=== PROCESSING FORM SUBMISSION ===");
    
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        error_log("CSRF validation FAILED");
        $_SESSION['toast_error'] = "Invalid security token. Please try again.";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }

    // Prepare guardian data
    $guardianData = [
        'guardian_name' => sanitize($_POST['guardian_name'] ?? ''),
        'guardian_type' => $_POST['guardian_type'] ?? '',
        'phone' => sanitize($_POST['phone'] ?? ''),
        'email' => sanitize($_POST['email'] ?? ''),
        'occupation' => sanitize($_POST['occupation'] ?? ''),
        'address' => sanitize($_POST['address'] ?? ''),
        'gender' => $_POST['gender'] ?? null,
        'instagram' => sanitize($_POST['instagram'] ?? ''),
        'guardian_photo' => null,
        // Student linking data - Fix the format to match what GuardianManager expects
        'student_ids' => $_POST['student_ids'] ?? [],
        'relationships' => $_POST['relationships'] ?? []
    ];

    // Handle file upload
    if (isset($_FILES['guardian_photo']) && $_FILES['guardian_photo']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = __DIR__ . '/../../../uploads/guardians/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        $mimeToExt = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
        $detectedMime = function_exists('finfo_open')
            ? (function($p) { $fi = finfo_open(FILEINFO_MIME_TYPE); $m = finfo_file($fi, $p); finfo_close($fi); return $m; })($_FILES['guardian_photo']['tmp_name'])
            : (mime_content_type($_FILES['guardian_photo']['tmp_name']) ?: '');
        if (!isset($mimeToExt[$detectedMime])) {
            $error = 'Only JPG, PNG, GIF, and WebP images are allowed.';
        } else {
            $fileName = 'guardian_' . time() . '_' . uniqid() . '.' . $mimeToExt[$detectedMime];
            $uploadPath = $uploadDir . $fileName;
            if (move_uploaded_file($_FILES['guardian_photo']['tmp_name'], $uploadPath)) {
                $guardianData['guardian_photo'] = 'uploads/guardians/' . $fileName;
            }
        }
    }

    // Add guardian
    if ($guardianManager) {
        $result = $guardianManager->addGuardian($guardianData);
        
        if ($result[0]) {
            $_SESSION['toast_success'] = $result[1];
            header("Location: guardian-list.php");
            exit;
        } else {
            $_SESSION['toast_error'] = $result[1];
            $_SESSION['form_data'] = $_POST;
            header("Location: " . $_SERVER['PHP_SELF']);
            exit;
        }
    } else {
        $_SESSION['toast_error'] = "Guardian manager not initialized. Please try again.";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
}

// Get form data from session for repopulation
if (isset($_SESSION['form_data'])) {
    $formData = $_SESSION['form_data'];
    unset($_SESSION['form_data']);
}

// Collect toast messages
$toastSuccess = $_SESSION['toast_success'] ?? '';
$toastError = $_SESSION['toast_error'] ?? '';
unset($_SESSION['toast_success'], $_SESSION['toast_error']);

// Generate CSRF token
$csrfToken = generateCsrfToken();

error_log("=== ADD GUARDIAN PAGE END ===");
?>

<!DOCTYPE html>
<html lang="en" data-theme="light">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="description"
        content="Modern Education Admin Dashboard for schools, colleges, universities, and eLearning platforms. Includes student and course management, attendance, exams, payments, analytics, and a fully responsive clean UI—ideal for LMS, coaching centers, and academic admin systems.">
    <meta name="keywords"
        content="Education Admin Dashboard, School Admin Panel, College Dashboard, University Dashboard, LMS Dashboard, eLearning Admin Template, Student Management System, Course Management, Education Template, Study Dashboard, Online Learning Dashboard, Academic Admin Panel, Bootstrap Dashboard, React Education Dashboard, Next.js Education Template">
    <meta name="robots" content="INDEX,FOLLOW">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- Title -->
    <title> <?php echo htmlspecialchars($school['name']); ?> | <?php echo defined('APP_NAME') ? APP_NAME : 'School Management'; ?></title>
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

        /* Professional styling for student linking section */
        .search-results-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            max-height: 300px;
            overflow-y: auto;
            background: white;
            border: 1px solid var(--gray-200);
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.08);
            z-index: 1000;
            display: none;
            margin-top: 4px;
        }

        .search-result-item {
            padding: 12px 16px;
            border-bottom: 1px solid var(--gray-100);
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .search-result-item:last-child {
            border-bottom: none;
        }

        .search-result-item:hover {
            background-color: var(--gray-50);
        }

        .search-result-item .student-name {
            font-weight: 600;
            color: var(--gray-800);
        }

        .search-result-item .student-details {
            font-size: 12px;
            color: var(--gray-500);
            margin-top: 4px;
        }

        .selected-students-list {
            min-height: 60px;
        }

        .student-card {
            background: white;
            border: 1px solid var(--gray-200);
            border-radius: 16px;
            padding: 20px;
            transition: all 0.3s ease;
            position: relative;
            box-shadow: 0 2px 8px rgba(0,0,0,0.02);
            margin-bottom: 20px;
        }

        .student-card:hover {
            border-color: var(--primary-300);
            box-shadow: 0 8px 20px rgba(0,0,0,0.06);
        }

        .student-card.primary-card {
            border-left: 4px solid var(--primary-600);
            background: linear-gradient(to right, rgba(37, 161, 148, 0.02), white);
        }

        .student-avatar {
            width: 56px;
            height: 56px;
            background: linear-gradient(135deg, var(--primary-50), var(--primary-100));
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary-600);
            font-weight: 600;
            font-size: 20px;
        }

        .primary-badge {
            background: var(--primary-600);
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .relationship-select {
            border-radius: 12px;
            border: 1.5px solid var(--gray-200);
            padding: 8px 12px;
            font-size: 14px;
            background-color: white;
            width: 100%;
        }

        .relationship-select:focus {
            border-color: var(--primary-600);
            box-shadow: 0 0 0 3px rgba(37, 161, 148, 0.1);
        }

        .remove-student-btn {
            position: absolute;
            top: 12px;
            right: 12px;
            width: 32px;
            height: 32px;
            border-radius: 8px;
            background: var(--gray-50);
            border: 1px solid var(--gray-200);
            color: var(--gray-500);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .remove-student-btn:hover {
            background: var(--danger-50);
            border-color: var(--danger);
            color: var(--danger);
        }

        .student-tag {
            display: inline-flex;
            align-items: center;
            background: white;
            border: 1px solid var(--gray-200);
            border-radius: 30px;
            padding: 6px 16px;
            margin: 4px;
            font-size: 13px;
            transition: all 0.2s ease;
        }

        .student-tag.primary-tag {
            background: var(--primary-50);
            border-color: var(--primary-200);
            color: var(--primary-700);
        }

        .student-tag .remove-tag {
            margin-left: 8px;
            color: var(--gray-400);
            cursor: pointer;
            font-size: 16px;
        }

        .permission-checkbox {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            background: var(--gray-50);
            border-radius: 10px;
            cursor: pointer;
            width: 100%;
        }

        .permission-checkbox:hover {
            background: var(--gray-100);
        }

        .permission-checkbox input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .student-card {
            animation: slideIn 0.3s ease;
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

<!-- Theme Customization Structure Start -->




<!-- Theme Customization Structure End -->

<div class="overlay bg-black bg-opacity-50 w-100 h-100 position-fixed z-9 visibility-hidden opacity-0 duration-300"></div>

<?php include_once('includes/sidebar.php') ?>

<main class="dashboard-main">
<?php require_once __DIR__ . '/includes/nav-header.php'; ?>

    <div class="dashboard-main-body">
        <div class="breadcrumb d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
            <div class="">
                <h1 class="fw-semibold mb-4 h6 text-primary-light">Add New Guardian</h1>
                <div class="">
                    <a href="index.php" class="text-secondary-light hover-text-primary hover-underline">Dashboard </a>
                    <a href="guardian-list.php" class="text-secondary-light hover-text-primary hover-underline"> / Guardian</a>
                    <span class="text-secondary-light">/ Add New Guardian</span>
                </div>
            </div>
            <a href="guardian-list.php" class="btn btn-primary-600 d-flex align-items-center gap-6">
                <span class="d-flex text-md">
                    <i class="ri-list-view"></i>
                </span>
                View All Guardians
            </a>
        </div>

        <form action="#" method="POST" enctype="multipart/form-data" class="mt-24">
            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
            
            <div class="row gy-3">
                <!-- Personal Info Section -->
                <div class="col-xl-12">
                    <div class="shadow-1 radius-12 bg-base h-100 overflow-hidden">
                        <div class="card-header border-bottom bg-base py-16 px-24 d-flex align-items-center justify-content-between">
                            <h6 class="text-lg fw-semibold mb-0">Personal Info</h6>
                        </div>
                        <div class="card-body p-20">
                            <div class="row gy-3">
                                <div class="col-xl-4 col-sm-6">
                                    <div class="">
                                        <label for="guardianType" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Guardian Type <span class="text-danger-600">*</span></label>
                                        <select id="guardianType" name="guardian_type" class="form-control form-select" required>
                                            <option value="" selected disabled>Select Guardian</option>
                                            <?php foreach ($guardianTypes as $value => $label): ?>
                                            <option value="<?php echo $value; ?>" <?php echo (isset($formData['guardian_type']) && $formData['guardian_type'] == $value) ? 'selected' : ''; ?>>
                                                <?php echo $label; ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-xl-4 col-sm-6">
                                    <div class="">
                                        <label for="guardianName" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Guardian Name <span class="text-danger-600">*</span></label>
                                        <input type="text" id="guardianName" name="guardian_name" class="form-control"
                                            placeholder="Enter guardian name" required
                                            value="<?php echo htmlspecialchars($formData['guardian_name'] ?? ''); ?>">
                                    </div>
                                </div>
                                <div class="col-xl-4 col-sm-6">
                                    <div class="">
                                        <label for="instagram" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Instagram</label>
                                        <input type="text" id="instagram" name="instagram" class="form-control"
                                            placeholder="@username"
                                            value="<?php echo htmlspecialchars($formData['instagram'] ?? ''); ?>">
                                    </div>
                                </div>
                                <div class="col-xl-4 col-sm-6">
                                    <div class="">
                                        <label for="phoneNumber" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Phone Number <span class="text-danger-600">*</span></label>
                                        <input type="tel" id="phoneNumber" name="phone" class="form-control"
                                            placeholder="Enter phone number" required
                                            value="<?php echo htmlspecialchars($formData['phone'] ?? ''); ?>">
                                    </div>
                                </div>
                                <div class="col-xl-4 col-sm-6">
                                    <div class="">
                                        <label for="occupation" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Occupation</label>
                                        <input type="text" id="occupation" name="occupation" class="form-control"
                                            placeholder="Enter occupation"
                                            value="<?php echo htmlspecialchars($formData['occupation'] ?? ''); ?>">
                                    </div>
                                </div>
                                <div class="col-xl-4 col-sm-6">
                                    <div class="">
                                        <label for="guardianAddress" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Guardian Address <span class="text-danger-600">*</span></label>
                                        <input type="text" id="guardianAddress" name="address" class="form-control"
                                            placeholder="Enter guardian address" required
                                            value="<?php echo htmlspecialchars($formData['address'] ?? ''); ?>">
                                    </div>
                                </div>
                                <div class="col-xl-4 col-sm-6">
                                    <div class="">
                                        <label for="gender" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Gender</label>
                                        <select id="gender" name="gender" class="form-control form-select">
                                            <option value="">Select Gender</option>
                                            <?php foreach ($genders as $value => $label): ?>
                                            <option value="<?php echo $value; ?>" <?php echo (isset($formData['gender']) && $formData['gender'] == $value) ? 'selected' : ''; ?>>
                                                <?php echo $label; ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-xl-8">
                                    <div class="">
                                        <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Guardian Photo</label>
                                        <div class="drop-zone height-44-px p-4 d-flex justify-content-center align-items-center text-center fw-medium text-md cursor-pointer border border-neutral-400 radius-8 border-dashed bg-hover-neutral-200">
                                            <span class="drop-zone__prompt">Drag & drop a file here or click</span>
                                            <input type="file" name="guardian_photo" class="drop-zone__input" accept="image/*">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Login Details Section -->
                <div class="col-xl-12">
                    <div class="shadow-1 radius-12 bg-base h-100 overflow-hidden">
                        <div class="card-header border-bottom bg-base py-16 px-24 d-flex align-items-center justify-content-between">
                            <h6 class="text-lg fw-semibold mb-0">Login Details</h6>
                        </div>
                        <div class="card-body p-20">
                            <div class="row gy-3">
                                <div class="col-sm-12">
                                    <div class="">
                                        <label for="myEmail" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Email <span class="text-danger-600">*</span></label>
                                        <input type="email" class="form-control" id="myEmail" name="email" placeholder="Enter Email" required
                                            value="<?php echo htmlspecialchars($formData['email'] ?? ''); ?>">
                                        <small class="text-muted">A password will be auto-generated and sent to this email</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Student Linking Section -->
                <div class="col-xl-12">
                    <div class="shadow-1 radius-12 bg-base h-100 overflow-hidden">
                        <div class="card-header border-bottom bg-base py-16 px-24 d-flex align-items-center justify-content-between">
                            <h6 class="text-lg fw-semibold mb-0">
                                <i class="ri-group-line me-2 text-primary-600"></i>
                                Link to Students
                            </h6>
                            <span class="badge bg-primary-50 text-primary-600 fw-semibold px-3 py-2">Optional</span>
                        </div>
                        <div class="card-body p-20">
                            <!-- Student Search Section -->
                            <div class="row mb-4">
                                <div class="col-12">
                                    <div class="alert alert-info bg-primary-50 border-0 d-flex align-items-start gap-3 mb-4">
                                        <i class="ri-information-line fs-5 text-primary-600 mt-1"></i>
                                        <div>
                                            <h6 class="fw-semibold mb-1">Link Existing Students</h6>
                                            <p class="text-secondary-light mb-0">Search for students to link with this guardian. You can link multiple students and specify their relationship.</p>
                                        </div>
                                    </div>
                                    
                                    <!-- Search Input with Icon -->
                                    <div class="position-relative mb-3">
                                        <div class="input-group">
                                            <span class="input-group-text bg-transparent border-end-0">
                                                <i class="ri-search-line text-secondary-light"></i>
                                            </span>
                                            <input type="text" class="form-control border-start-0 ps-0" id="studentSearch" 
                                                   placeholder="Search by student name, admission number, or class...">
                                            <button class="btn btn-outline-secondary" type="button" id="clearStudentSearch">
                                                <i class="ri-close-line"></i>
                                            </button>
                                        </div>
                                        <div id="studentSearchResults" class="search-results-dropdown"></div>
                                    </div>
                                    
                                    <!-- Selected Students Summary -->
                                    <div class="selected-students-summary bg-neutral-50 rounded-3 p-3">
                                        <div class="d-flex align-items-center justify-content-between mb-2">
                                            <h6 class="fw-semibold mb-0">
                                                <i class="ri-group-line me-2 text-success"></i>
                                                Selected Students (<span id="selectedCount">0</span>)
                                            </h6>
                                            <button type="button" class="btn btn-sm btn-outline-danger" id="clearAllStudents" style="display: none;">
                                                <i class="ri-delete-bin-line me-1"></i>Clear All
                                            </button>
                                        </div>
                                        <div id="selectedStudentsList" class="selected-students-list">
                                            <p class="text-secondary-light mb-0 text-center py-3" id="noStudentsMsg">
                                                <i class="ri-user-search-line me-2"></i>No students selected yet. Search and select students above.
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Student Cards Grid (for selected students) -->
                            <div id="studentCardsContainer" class="row g-3 mt-2"></div>

                            <!-- Hidden Inputs for Form Submission -->
                            <div id="studentHiddenInputs"></div>
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
                            <i class="ri-save-line me-2"></i>
                            Save Guardian
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
</main>

<!-- jQuery library js -->
<script src="https://academixsuite.com/tenant/assets/js/lib/jquery-3.7.1.min.js"></script>
<!-- Bootstrap js -->
<script src="https://academixsuite.com/tenant/assets/js/lib/bootstrap.bundle.min.js"></script>
<!-- Apex Chart js -->
<script src="https://academixsuite.com/tenant/assets/js/lib/apexcharts.min.js"></script>
<!-- Iconify Font js -->
<script src="https://academixsuite.com/tenant/assets/js/lib/iconify-icon.min.js"></script>
<!-- Data Table js -->
<script src="https://academixsuite.com/tenant/assets/js/lib/dataTables.min.js"></script>
<!-- jQuery UI js -->
<script src="https://academixsuite.com/tenant/assets/js/lib/jquery-ui.min.js"></script>
<!-- main js -->
<script src="https://academixsuite.com/tenant/assets/js/app.js"></script>

<script>
    $(document).ready(function() {
        // Initialize Bootstrap toasts
        $('.toast').toast({
            autohide: true,
            delay: 5000
        });
        $('.toast').toast('show');

        // Current year
        $('.current-year').text(new Date().getFullYear());

        // ================== Drag & Drop Upload photo Js start ========================
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
        // ========================== Drag & Drop Upload photo Js end ========================

        // ================== Student Search and Linking ==================
        let selectedStudents = [];
        let studentSearchTimeout;
        
        const $studentSearch = $('#studentSearch');
        const $studentResults = $('#studentSearchResults');
        const $selectedStudentsList = $('#selectedStudentsList');
        const $selectedCount = $('#selectedCount');
        const $noStudentsMsg = $('#noStudentsMsg');
        const $clearAllBtn = $('#clearAllStudents');
        const $studentHiddenInputs = $('#studentHiddenInputs');
        const $studentCardsContainer = $('#studentCardsContainer');

        // Search students
        $studentSearch.on('input', function() {
            clearTimeout(studentSearchTimeout);
            const term = $(this).val().trim();
            
            if (term.length < 2) {
                $studentResults.hide();
                return;
            }

            studentSearchTimeout = setTimeout(function() {
                $.ajax({
                    url: window.location.pathname,
                    method: 'GET',
                    data: {
                        ajax: 'search_students',
                        term: term
                    },
                    dataType: 'json',
                    beforeSend: function() {
                        $studentResults.html('<div class="search-result-item text-center p-3"><div class="spinner-border spinner-border-sm text-primary" role="status"></div> Searching...</div>').show();
                    },
                    success: function(response) {
                        if (response.success && response.students.length > 0) {
                            let html = '';
                            $.each(response.students, function(index, student) {
                                const isSelected = selectedStudents.some(s => s.id === student.id);
                                if (!isSelected) {
                                    html += `
                                        <div class="search-result-item student-result" 
                                             data-id="${student.id}"
                                             data-first-name="${escapeHtml(student.first_name)}"
                                             data-last-name="${escapeHtml(student.last_name)}"
                                             data-admission="${escapeHtml(student.admission_number || '')}"
                                             data-class="${escapeHtml(student.class_name || 'Not Assigned')}"
                                             data-section="${escapeHtml(student.section_name || '')}">
                                            <div class="d-flex align-items-center gap-3">
                                                <div>
                                                    <div class="student-name">${escapeHtml(student.first_name + ' ' + student.last_name)}</div>
                                                    <div class="student-details">
                                                        <i class="ri-hashtag"></i> ${escapeHtml(student.admission_number || 'No Adm No')} 
                                                        <i class="ri-group-line ms-2"></i> ${escapeHtml(student.class_name || 'No Class')}
                                                        ${student.section_name ? '<i class="ri-grid-line ms-2"></i> ' + escapeHtml(student.section_name) : ''}
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    `;
                                }
                            });
                            
                            if (html) {
                                $studentResults.html(html).show();
                            } else {
                                $studentResults.html('<div class="search-result-item text-muted">All matching students are already selected</div>').show();
                            }
                        } else {
                            $studentResults.html('<div class="search-result-item text-muted">No students found</div>').show();
                        }
                    }
                });
            }, 300);
        });

        // Select student
        $(document).on('click', '.student-result', function() {
            const student = {
                id: $(this).data('id'),
                first_name: $(this).data('first-name'),
                last_name: $(this).data('last-name'),
                full_name: $(this).data('first-name') + ' ' + $(this).data('last-name'),
                admission: $(this).data('admission'),
                class: $(this).data('class'),
                section: $(this).data('section'),
                relationship: 'guardian'
            };
            
            selectedStudents.push(student);
            renderSelectedStudents();
            
            $studentSearch.val('');
            $studentResults.hide();
        });

        // Render selected students
        function renderSelectedStudents() {
            if (selectedStudents.length > 0) {
                $noStudentsMsg.hide();
                $clearAllBtn.show();
                $selectedCount.text(selectedStudents.length);
                
                let cards = '';
                let tags = '';
                let hiddenInputs = '';
                
                $.each(selectedStudents, function(index, student) {
                    const isPrimary = index === 0;
                    
                    // Student card
                    cards += `
                        <div class="col-md-6 col-lg-4 student-card-container" data-student-id="${student.id}">
                            <div class="student-card ${isPrimary ? 'primary-card' : ''}">
                                <div class="remove-student-btn" onclick="removeStudent(${student.id})" title="Remove student">
                                    <i class="ri-close-line"></i>
                                </div>
                                
                                <div class="d-flex gap-3 mb-3">
                                    <div class="student-avatar">
                                        ${student.first_name.charAt(0)}${student.last_name.charAt(0)}
                                    </div>
                                    <div class="flex-grow-1">
                                        <h6 class="fw-semibold mb-1">${escapeHtml(student.full_name)}</h6>
                                        <div class="d-flex flex-wrap gap-2 mb-2">
                                            <span class="badge bg-light text-dark">
                                                <i class="ri-hashtag"></i> ${escapeHtml(student.admission || 'N/A')}
                                            </span>
                                            <span class="badge bg-light text-dark">
                                                <i class="ri-group-line"></i> ${escapeHtml(student.class)}
                                            </span>
                                            ${student.section ? `
                                                <span class="badge bg-light text-dark">
                                                    <i class="ri-grid-line"></i> ${escapeHtml(student.section)}
                                                </span>
                                            ` : ''}
                                        </div>
                                        ${isPrimary ? `
                                            <span class="primary-badge">
                                                <i class="ri-star-fill"></i> Primary Student
                                            </span>
                                        ` : ''}
                                    </div>
                                </div>
                                
                                <div class="border-top pt-3 mt-2">
                                    <div class="mb-3">
                                        <label class="form-label fw-medium mb-2">Relationship</label>
                                        <select class="form-select relationship-select" 
                                                onchange="updateStudentRelationship(${index}, this.value)">
                                            <option value="father" ${student.relationship === 'father' ? 'selected' : ''}>Father</option>
                                            <option value="mother" ${student.relationship === 'mother' ? 'selected' : ''}>Mother</option>
                                            <option value="guardian" ${student.relationship === 'guardian' ? 'selected' : ''}>Legal Guardian</option>
                                            <option value="brother" ${student.relationship === 'brother' ? 'selected' : ''}>Brother</option>
                                            <option value="sister" ${student.relationship === 'sister' ? 'selected' : ''}>Sister</option>
                                            <option value="uncle" ${student.relationship === 'uncle' ? 'selected' : ''}>Uncle</option>
                                            <option value="aunt" ${student.relationship === 'aunt' ? 'selected' : ''}>Aunt</option>
                                            <option value="grandfather" ${student.relationship === 'grandfather' ? 'selected' : ''}>Grandfather</option>
                                            <option value="grandmother" ${student.relationship === 'grandmother' ? 'selected' : ''}>Grandmother</option>
                                            <option value="other" ${student.relationship === 'other' ? 'selected' : ''}>Other</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                    `;
                    
                    // Tags for summary
                    tags += `
                        <span class="student-tag ${isPrimary ? 'primary-tag' : ''}" data-student-id="${student.id}">
                            <i class="ri-user-star-line me-1"></i>
                            ${escapeHtml(student.full_name)}
                            <i class="ri-close-line remove-tag" onclick="removeStudent(${student.id})"></i>
                        </span>
                    `;
                    
                    // Hidden inputs for form submission - FIXED: Use student.id as key
                    hiddenInputs += `
                        <input type="hidden" name="student_ids[]" value="${student.id}">
                        <input type="hidden" name="relationships[${student.id}]" value="${student.relationship}" id="rel_${student.id}">
                    `;
                });
                
                // Update containers
                $selectedStudentsList.html(`
                    <div class="d-flex flex-wrap gap-2">
                        ${tags}
                    </div>
                `);
                
                $studentCardsContainer.html(cards);
                $studentHiddenInputs.html(hiddenInputs);
                
            } else {
                $noStudentsMsg.show();
                $clearAllBtn.hide();
                $selectedCount.text('0');
                $selectedStudentsList.html(`
                    <p class="text-secondary-light mb-0 text-center py-3">
                        <i class="ri-user-search-line me-2"></i>No students selected yet. Search and select students above.
                    </p>
                `);
                $studentCardsContainer.empty();
                $studentHiddenInputs.empty();
            }
        }

        // Update student relationship
        window.updateStudentRelationship = function(index, relationship) {
            if (selectedStudents[index]) {
                selectedStudents[index].relationship = relationship;
                $(`#rel_${selectedStudents[index].id}`).val(relationship);
            }
        };

        // Remove student
        window.removeStudent = function(studentId) {
            selectedStudents = selectedStudents.filter(s => s.id !== studentId);
            renderSelectedStudents();
        };

        // Clear all students
        $('#clearAllStudents').on('click', function() {
            if (selectedStudents.length > 0) {
                if (confirm('Are you sure you want to remove all linked students?')) {
                    selectedStudents = [];
                    renderSelectedStudents();
                }
            }
        });

        // Clear search
        $('#clearStudentSearch').on('click', function() {
            $studentSearch.val('');
            $studentResults.hide();
        });

        // Click outside to close search results
        $(document).on('click', function(e) {
            if (!$(e.target).closest('#studentSearch, #studentSearchResults').length) {
                $studentResults.hide();
            }
        });

        // Escape HTML helper
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // ================== Form Validation ==================
        $('form').on('submit', function(e) {
            let isValid = true;
            const requiredFields = $('[required]');
            
            requiredFields.each(function() {
                if (!$(this).val()) {
                    isValid = false;
                    $(this).addClass('is-invalid');
                } else {
                    $(this).removeClass('is-invalid');
                }
            });

            // Email validation
            const email = $('#myEmail').val();
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (email && !emailRegex.test(email)) {
                isValid = false;
                $('#myEmail').addClass('is-invalid');
            }

            // Phone validation
            const phone = $('#phoneNumber').val().replace(/\D/g, '');
            if (phone && phone.length < 10) {
                isValid = false;
                $('#phoneNumber').addClass('is-invalid');
            }

            if (!isValid) {
                e.preventDefault();
                alert('Please fill all required fields correctly!');
            }
        });

        // Phone formatting
        $('#phoneNumber').on('input', function() {
            let phone = $(this).val().replace(/\D/g, '');
            if (phone.length > 10) {
                phone = phone.substr(0, 10);
            }
            if (phone.length > 6) {
                phone = phone.substr(0, 3) + '-' + phone.substr(3, 3) + '-' + phone.substr(6);
            } else if (phone.length > 3) {
                phone = phone.substr(0, 3) + '-' + phone.substr(3);
            }
            $(this).val(phone);
        });
    });
</script>

</body>
</html>