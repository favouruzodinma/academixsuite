<?php
/**
 * School Student List Page
 * Displays all students from the school database with proper relationships
 * 
 * @package AcademixSuite
 * @version 2.0
 */

// Enable error reporting
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../../logs/school_students.log');

error_log("=== STUDENT LIST PAGE START ===");
error_log("Request URI: " . ($_SERVER['REQUEST_URI'] ?? 'N/A'));
error_log("Script: " . __FILE__);

// Define constants if not defined
defined('APP_NAME') or define('APP_NAME', 'AcademixSuite');
defined('IS_LOCAL') or define('IS_LOCAL', true);

// Start session safely
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

// Get school slug from GLOBALS (set by router.php)
$schoolSlug = $GLOBALS['SCHOOL_SLUG'] ?? '';
$userType = $GLOBALS['USER_TYPE'] ?? 'admin';
$currentPage = $GLOBALS['CURRENT_PAGE'] ?? 'students.php';
$schoolData = $GLOBALS['SCHOOL_DATA'] ?? [];
$baseUrl = $GLOBALS['BASE_URL'] ?? '';

if (empty($schoolSlug)) {
    error_log("ERROR: Empty school slug from router");
    header('HTTP/1.1 400 Bad Request');
    echo json_encode(['error' => 'School identifier missing']);
    exit;
}

// Get school info from session or GLOBALS
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
    error_log("User not authenticated, redirecting to login");
    header('Location: ../../login.php?school_slug=' . urlencode($schoolSlug));
    exit;
}

// Get user info from session
$schoolAuth = $_SESSION['school_auth'];
$userId = (int)($schoolAuth['user_id'] ?? 0);
$userType = $schoolAuth['user_type'] ?? '';

// Verify access (admin or teacher can view)
if (!in_array($userType, ['admin', 'teacher'])) {
    error_log("ERROR: User does not have permission to view students");
    header('HTTP/1.1 403 Forbidden');
    echo "Access denied. Insufficient privileges.";
    exit;
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
} catch (Exception $e) {
    error_log("Error loading autoload.php: " . $e->getMessage());
    http_response_code(500);
    die("Configuration loading failed.");
}

// Connect to school database
$schoolDb = null;
try {
    if (!empty($school['database_name'])) {
        $schoolDb = Database::getSchoolConnection($school['database_name']);
        error_log("School database connection successful");
    }
} catch (Exception $e) {
    error_log("ERROR connecting to school database: " . $e->getMessage());
    $schoolDb = null;
}

// Initialize notification variables
$notificationCount = 0;
$notifications = [];

// Include GuardianManager for notifications if available
$guardianManagerPath = __DIR__ . '/../../../includes/GuardianManager.php';
if (file_exists($guardianManagerPath) && $schoolDb) {
    require_once $guardianManagerPath;
    try {
        $guardianManager = new GuardianManager($schoolDb, $school['id'], $userId, $userType, $school);
        
        if (method_exists($guardianManager, 'getNotificationCount')) {
            $notificationCount = $guardianManager->getNotificationCount();
        }
        
        if (method_exists($guardianManager, 'getNotifications')) {
            $notifications = $guardianManager->getNotifications(5);
        }
    } catch (Exception $e) {
        error_log("ERROR initializing GuardianManager: " . $e->getMessage());
    }
}

// Initialize variables
$students = [];
$classes = [];
$sections = [];
$guardians = [];
$totalStudents = 0;
$settings = [];
$adminUser = ['name' => 'Admin User', 'role_name' => 'Administrator'];

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
            error_log("Loaded " . count($settings) . " settings records");
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
            } elseif (isset($_SESSION['school_user']['name'])) {
                $adminUser = [
                    'name' => $_SESSION['school_user']['name'],
                    'role_name' => 'Administrator'
                ];
            }
        }

        // Get all classes for filter
        $classStmt = $schoolDb->prepare("
            SELECT id, name, code 
            FROM classes 
            WHERE school_id = ? AND is_active = 1 
            ORDER BY name
        ");
        if ($classStmt) {
            $classStmt->execute([$school['id']]);
            $classes = $classStmt->fetchAll(PDO::FETCH_ASSOC);
            error_log("Loaded " . count($classes) . " classes");
        }

        // Get sections
        $sectionStmt = $schoolDb->prepare("
            SELECT s.*, c.name as class_name 
            FROM sections s
            LEFT JOIN classes c ON s.class_id = c.id
            WHERE s.school_id = ? AND s.is_active = 1
            ORDER BY c.name, s.name
        ");
        if ($sectionStmt) {
            $sectionStmt->execute([$school['id']]);
            $sections = $sectionStmt->fetchAll(PDO::FETCH_ASSOC);
            error_log("Loaded " . count($sections) . " sections");
        }

        // Get all students with related data
        $studentStmt = $schoolDb->prepare("
            SELECT 
                s.*,
                u.name as user_name,
                u.email as user_email,
                u.phone as user_phone,
                u.profile_photo as avatar,
                u.gender as user_gender,
                u.date_of_birth as user_dob,
                c.name as class_name,
                c.code as class_code,
                sec.name as section_name,
                CONCAT(c.name, ' - ', sec.name) as class_display,
                (
                    SELECT GROUP_CONCAT(CONCAT(g.relationship, ':', gu.name) SEPARATOR '|')
                    FROM guardians g
                    LEFT JOIN users gu ON g.user_id = gu.id
                    WHERE g.student_id = s.id AND g.school_id = s.school_id
                ) as guardians_info
            FROM students s
            LEFT JOIN users u ON s.user_id = u.id AND u.school_id = s.school_id
            LEFT JOIN classes c ON s.class_id = c.id AND c.school_id = s.school_id
            LEFT JOIN sections sec ON s.section_id = sec.id AND sec.school_id = s.school_id
            WHERE s.school_id = ?
            ORDER BY s.created_at DESC
        ");
        
        if ($studentStmt) {
            $studentStmt->execute([$school['id']]);
            $students = $studentStmt->fetchAll(PDO::FETCH_ASSOC);
            $totalStudents = count($students);
            error_log("Fetched " . $totalStudents . " students from database");
            
            // Debug first student to verify data
            if ($totalStudents > 0) {
                error_log("Sample student data: " . json_encode([
                    'id' => $students[0]['id'],
                    'first_name' => $students[0]['first_name'],
                    'last_name' => $students[0]['last_name'],
                    'class_name' => $students[0]['class_name'] ?? 'null',
                    'section_name' => $students[0]['section_name'] ?? 'null'
                ]));
            }
        }

    } catch (Exception $e) {
        error_log("Error fetching student data: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        $_SESSION['toast_error'] = "Error loading student data. Please refresh the page.";
    }
} else {
    error_log("WARNING: School database not available, cannot fetch students");
    $_SESSION['toast_error'] = "Database connection failed. Please try again later.";
}

// Handle search and filters
$searchTerm = $_GET['search'] ?? '';
$classFilter = $_GET['class'] ?? '';
$genderFilter = $_GET['gender'] ?? '';
$statusFilter = $_GET['status'] ?? '';

// Filter students if needed
if (!empty($searchTerm) || !empty($classFilter) || !empty($genderFilter) || !empty($statusFilter)) {
    $filteredStudents = [];
    foreach ($students as $student) {
        $match = true;
        
        if (!empty($searchTerm)) {
            $searchLower = strtolower($searchTerm);
            $fullName = strtolower(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? ''));
            $studentMatch = 
                strpos($fullName, $searchLower) !== false ||
                strpos(strtolower($student['admission_number'] ?? ''), $searchLower) !== false ||
                strpos(strtolower($student['roll_number'] ?? ''), $searchLower) !== false ||
                strpos(strtolower($student['user_phone'] ?? ''), $searchLower) !== false;
            
            if (!$studentMatch) {
                $match = false;
            }
        }
        
        if (!empty($classFilter) && ($student['class_id'] ?? 0) != $classFilter) {
            $match = false;
        }
        
        if (!empty($genderFilter)) {
            $gender = strtolower($student['gender'] ?? $student['user_gender'] ?? '');
            if ($gender != strtolower($genderFilter)) {
                $match = false;
            }
        }
        
        if (!empty($statusFilter)) {
            $status = strtolower($student['status'] ?? 'active');
            if ($statusFilter == 'active' && $status != 'active') {
                $match = false;
            } elseif ($statusFilter == 'inactive' && $status == 'active') {
                $match = false;
            }
        }
        
        if ($match) {
            $filteredStudents[] = $student;
        }
    }
    $students = $filteredStudents;
    error_log("After filtering: " . count($students) . " students remain");
}

// Collect toast messages from session
$toastSuccess = $_SESSION['toast_success'] ?? '';
$toastError = $_SESSION['toast_error'] ?? '';
$toastWarning = $_SESSION['toast_warning'] ?? '';
$toastInfo = $_SESSION['toast_info'] ?? '';

// Clear session toasts
unset($_SESSION['toast_success'], $_SESSION['toast_error'], $_SESSION['toast_warning'], $_SESSION['toast_info']);

// Format currency symbol
$currencySymbol = $settings['currency_symbol'] ?? '₦';

// Generate CSRF token
if (!function_exists('generateCsrfToken')) {
    function generateCsrfToken() {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}
$csrfToken = generateCsrfToken();

error_log("=== STUDENT LIST PAGE END ===");
error_log("Total students displayed: " . count($students));
?>

<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="description" content="Student List - Manage all students">
    <meta name="keywords" content="Student List, School Management">
    <meta name="robots" content="INDEX,FOLLOW">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student List - <?php echo htmlspecialchars($school['name'] ?? 'School'); ?></title>
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
        /* Toast container */
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
        
        /* Status badges - matching subject list style */
        .badge-active {
            background: #d4edda;
            color: #155724;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }
        .badge-inactive {
            background: #f8d7da;
            color: #721c24;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }
        
        /* Avatar styles */
        .avatar-placeholder {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, #25A194, #1a7a6f);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 16px;
        }
        
        /* Student info styles */
        .student-avatar-sm {
            width: 44px;
            height: 44px;
            border-radius: 8px;
            background: #e6f7f5;
            color: #25A194;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 16px;
        }
        
        /* Table styles matching subject list */
        .table thead th {
            background-color: #f8f9fa;
            font-weight: 600;
            font-size: 14px;
            color: #495057;
            border-bottom: 2px solid #dee2e6;
        }
        
        .table td {
            vertical-align: middle;
            padding: 12px;
        }
        
        .table tbody tr:hover {
            background-color: #f8f9fa;
        }
        
        .badge-info {
            background-color: #cce5ff;
            color: #004085;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .stat-icon {
            width: 32px;
            height: 32px;
            background: #e6f7f5;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #25A194;
            font-size: 1rem;
        }
        
        /* DataTable custom styles */
        .dataTables_wrapper .dataTables_filter input {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 8px 12px;
            margin-left: 8px;
        }
        
        .dataTables_wrapper .dataTables_length select {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 8px 12px;
            margin: 0 8px;
        }
        
        .pagination-wrapper {
            display: flex;
            justify-content: flex-end;
        }
        
        /* Gender badges */
        .gender-male {
            background: #e3f2fd;
            color: #1976d2;
        }
        .gender-female {
            background: #fce4ec;
            color: #c2185b;
        }
        .gender-other {
            background: #f3e5f5;
            color: #7b1fa2;
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
<?php include_once('includes/sidebar.php') ?>

<main class="dashboard-main">
<?php require_once __DIR__ . '/includes/nav-header.php'; ?>

    <div class="dashboard-main-body">
        <div class="breadcrumb d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
            <div class="">
                <h1 class="fw-semibold mb-4 h6 text-primary-light">Student List</h1>
                <div class="">
                    <a href="index.php" class="text-secondary-light hover-text-primary hover-underline">Dashboard</a>
                    <span class="text-secondary-light"> / Student List</span>
                </div>
            </div>
            <a href="add-new-student.php" class="btn btn-primary-600 d-flex align-items-center gap-6">
                <span class="d-flex text-md">
                    <i class="ri-add-large-line"></i>
                </span>
                Add Student
            </a>
        </div>

        <div class="mt-24">
            <div class="card h-100">
                <div class="card-body p-0 dataTable-wrapper">
                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-16 px-20 py-12 border-bottom border-neutral-200">
                        <div class="d-flex flex-wrap align-items-center gap-16">
                            <div class="dropdown">
                                <button type="button" class="px-12 py-5-px border border-neutral-300 radius-8 d-flex align-items-center gap-20" data-bs-toggle="dropdown" aria-expanded="false">
                                    <span class="d-flex align-items-center gap-1 text-secondary-light text-sm">
                                        <i class="ri-file-upload-line text-md line-height-1"></i>
                                        Export
                                    </span>
                                    <span class=""><i class="ri-arrow-down-s-line"></i></span>
                                </button>
                                <ul class="dropdown-menu p-12 border bg-base shadow">
                                    <li>
                                        <a href="export-students.php?format=pdf" class="dropdown-item px-16 py-8 rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-10">
                                            <i class="ri-file-pdf-line"></i>
                                            PDF
                                        </a>
                                    </li>
                                    <li>
                                        <a href="export-students.php?format=excel" class="dropdown-item px-16 py-8 rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-10">
                                            <i class="ri-file-excel-line"></i>
                                            Excel
                                        </a>
                                    </li>
                                </ul>
                            </div>
                            
                            <div class="dropdown">
                                <button type="button" class="px-12 py-5-px border border-neutral-300 radius-8 d-flex align-items-center gap-20" data-bs-toggle="dropdown" aria-expanded="false">
                                    <span class="d-flex align-items-center gap-1 text-secondary-light text-sm">
                                        <i class="ri-filter-line"></i>
                                        Filter
                                    </span>
                                    <span class=""><i class="ri-arrow-down-s-line"></i></span>
                                </button>
                                <div class="dropdown-menu border bg-base shadow dropdown-menu-lg p-0">
                                    <div class="d-flex align-items-center justify-content-between border-bottom py-8 px-16">
                                        <span class="fw-semibold text-lg text-primary-light">Filter Students</span>
                                        <button type="button" onclick="clearFilters()">
                                            <i class="ri-close-large-line"></i>
                                        </button>
                                    </div>
                                    <form method="GET" action="" class="p-16">
                                        <div class="row g-3">
                                            <div class="col-6">
                                                <label for="class" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Class</label>
                                                <select id="class" name="class" class="form-control form-select">
                                                    <option value="">All Classes</option>
                                                    <?php foreach ($classes as $class): ?>
                                                    <option value="<?php echo $class['id']; ?>" <?php echo $classFilter == $class['id'] ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($class['name']); ?>
                                                    </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-6">
                                                <label for="gender" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Gender</label>
                                                <select id="gender" name="gender" class="form-control form-select">
                                                    <option value="">All Genders</option>
                                                    <option value="male" <?php echo $genderFilter == 'male' ? 'selected' : ''; ?>>Male</option>
                                                    <option value="female" <?php echo $genderFilter == 'female' ? 'selected' : ''; ?>>Female</option>
                                                    <option value="other" <?php echo $genderFilter == 'other' ? 'selected' : ''; ?>>Other</option>
                                                </select>
                                            </div>
                                            <div class="col-6">
                                                <label for="status" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Status</label>
                                                <select id="status" name="status" class="form-control form-select">
                                                    <option value="">All Status</option>
                                                    <option value="active" <?php echo $statusFilter == 'active' ? 'selected' : ''; ?>>Active</option>
                                                    <option value="inactive" <?php echo $statusFilter == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                                </select>
                                            </div>
                                            <div class="col-12 d-flex gap-3">
                                                <button type="reset" class="btn btn-danger-200 text-danger-600 flex-grow-1" onclick="resetForm()">Reset</button>
                                                <button type="submit" class="btn btn-primary-600 flex-grow-1">Apply Filter</button>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            </div>
                            
                            <form class="navbar-search dt-search m-0">
                                <input type="text" class="dt-input bg-transparent radius-4" aria-controls="dataTable" name="search" placeholder="Search...">
                                <iconify-icon icon="ion:search-outline" class="icon"></iconify-icon>
                            </form>
                        </div>
                        <div class="d-flex align-items-center gap-8 text-secondary-light">
                            <span class="">Rows per page:</span>
                            <div class="dt-length">
                                <select name="dataTable_length" aria-controls="dataTable" class="dt-input form-control form-select">
                                    <option value="5">5</option>
                                    <option value="10" selected>10</option>
                                    <option value="25">25</option>
                                    <option value="50">50</option>
                                    <option value="100">100</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table bordered-table mb-0 data-table" id="studentTable">
                            <thead>
                                <tr>
                                    <th scope="col" width="50">S.L</th>
                                    <th scope="col">Student Info</th>
                                    <th scope="col">Admission No</th>
                                    <th scope="col">Class - Section</th>
                                    <th scope="col">Roll No</th>
                                    <th scope="col">Date of Birth</th>
                                    <th scope="col">Gender</th>
                                    <th scope="col">Contact</th>
                                    <th scope="col">Status</th>
                                    <th scope="col" width="100">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($students)): ?>
                                    <?php $count = 1; ?>
                                    <?php foreach ($students as $student): ?>
                                    <tr>
                                        <td>
                                            <div class="form-check style-check d-flex align-items-center">
                                                <input class="form-check-input" type="checkbox" value="<?php echo $student['id']; ?>">
                                                <label class="form-check-label">
                                                    <?php echo str_pad($count++, 2, '0', STR_PAD_LEFT); ?>
                                                </label>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center gap-10">
                                                <?php 
                                                // Get initials for avatar
                                                $firstName = $student['first_name'] ?? '';
                                                $lastName = $student['last_name'] ?? '';
                                                $initials = strtoupper(substr($firstName, 0, 1) . substr($lastName, 0, 1));
                                                if (empty(trim($initials))) {
                                                    $initials = 'ST';
                                                }
                                                $fullName = trim($firstName . ' ' . $lastName);
                                                if (empty($fullName)) {
                                                    $fullName = $student['user_name'] ?? 'Unknown';
                                                }
                                                
                                                // Check for avatar
                                                $avatar = $student['avatar'] ?? '';
                                                if (!empty($avatar)):
                                                ?>
                                                <img src="<?php echo htmlspecialchars($avatar); ?>" alt="Student" class="w-44-px h-44-px rounded-circle object-fit-cover">
                                                <?php else: ?>
                                                <div class="student-avatar-sm">
                                                    <?php echo $initials; ?>
                                                </div>
                                                <?php endif; ?>
                                                <div class="">
                                                    <h6 class="text-md fw-semibold mb-0">
                                                        <?php echo htmlspecialchars($fullName); ?>
                                                    </h6>
                                                    <?php if (!empty($student['user_email'])): ?>
                                                    <span class="text-xs text-secondary-light"><?php echo htmlspecialchars($student['user_email']); ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge bg-info"><?php echo htmlspecialchars($student['admission_number'] ?? 'N/A'); ?></span>
                                        </td>
                                        <td>
                                            <?php 
                                            $classDisplay = '';
                                            if (!empty($student['class_name'])) {
                                                $classDisplay .= $student['class_name'];
                                                if (!empty($student['section_name'])) {
                                                    $classDisplay .= ' - ' . $student['section_name'];
                                                }
                                            } else {
                                                $classDisplay = '<span class="text-muted">Not Assigned</span>';
                                            }
                                            echo $classDisplay;
                                            ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($student['roll_number'] ?? 'N/A'); ?></td>
                                        <td>
                                            <?php 
                                            $dob = $student['date_of_birth'] ?? $student['user_dob'] ?? null;
                                            if (!empty($dob)) {
                                                echo date('d M Y', strtotime($dob));
                                            } else {
                                                echo 'N/A';
                                            }
                                            ?>
                                        </td>
                                        <td>
                                            <?php 
                                            $gender = $student['gender'] ?? $student['user_gender'] ?? 'Not Specified';
                                            $genderClass = 'gender-other';
                                            if (strtolower($gender) == 'male') {
                                                $genderClass = 'gender-male';
                                            } elseif (strtolower($gender) == 'female') {
                                                $genderClass = 'gender-female';
                                            }
                                            ?>
                                            <span class="badge <?php echo $genderClass; ?>">
                                                <?php echo ucfirst($gender); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php 
                                            $phone = $student['student_phone'] ?? $student['user_phone'] ?? 'N/A';
                                            if (!empty($phone) && $phone != 'N/A'):
                                            ?>
                                            <span class="d-flex align-items-center gap-1">
                                                <i class="ri-phone-line text-primary"></i>
                                                <?php echo htmlspecialchars($phone); ?>
                                            </span>
                                            <?php else: ?>
                                            <span class="text-muted">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php 
                                            $status = strtolower($student['status'] ?? 'active');
                                            ?>
                                            <span class="badge <?php echo $status == 'active' ? 'badge-active' : 'badge-inactive'; ?>">
                                                <?php echo ucfirst($status); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="btn-group">
                                                <button type="button" class="text-primary-light text-xl" data-bs-toggle="dropdown" data-bs-display="static" aria-expanded="false">
                                                    <i class="ri-more-2-fill"></i>
                                                </button>
                                                <ul class="dropdown-menu dropdown-menu-lg-end border p-12">
                                                    <li>
                                                        <a href="student-details.php?id=<?php echo $student['id']; ?>" class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                            <i class="ri-eye-line"></i>
                                                            View Details
                                                        </a>
                                                    </li>
                                                    <li>
                                                        <a href="edit-student.php?id=<?php echo $student['id']; ?>" class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                            <i class="ri-edit-2-line"></i>
                                                            Edit
                                                        </a>
                                                    </li>
                                                    <li>
                                                        <a href="fees-collect.php?student_id=<?php echo $student['id']; ?>" class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                            <i class="ri-money-dollar-box-line"></i>
                                                            Collect Fees
                                                        </a>
                                                    </li>
                                                    <li>
                                                        <hr class="dropdown-divider">
                                                    </li>
                                                    <li>
                                                        <button onclick="toggleStatus(<?php echo $student['id']; ?>, '<?php echo $status; ?>')" class="dropdown-item rounded text-warning bg-hover-neutral-200 text-hover-warning d-flex align-items-center gap-2 py-6">
                                                            <i class="ri-error-warning-line"></i>
                                                            <?php echo $status == 'active' ? 'Deactivate' : 'Activate'; ?>
                                                        </button>
                                                    </li>
                                                    <li>
                                                        <button onclick="confirmDelete(<?php echo $student['id']; ?>)" class="dropdown-item rounded text-danger bg-hover-neutral-200 text-hover-danger d-flex align-items-center gap-2 py-6">
                                                            <i class="ri-delete-bin-6-line"></i>
                                                            Delete
                                                        </button>
                                                    </li>
                                                </ul>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="10" class="text-center py-20">
                                            <div class="text-center">
                                                <i class="ri-user-search-line fs-1 text-secondary-light mb-3 d-block" style="font-size: 3rem;"></i>
                                                <p class="text-secondary-light mt-16 mb-0">No students found</p>
                                                <a href="add-new-student.php" class="btn btn-primary-600 mt-16">
                                                    <i class="ri-add-line me-2"></i>Add Your First Student
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
</main>

<!-- Delete Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm modal-dialog modal-dialog-centered">
        <div class="modal-content radius-16 bg-base">
            <div class="modal-body pt-32 px-36 pb-24 text-center">
                <span class="mb-16 fs-1 line-height-1 text-danger">
                    <i class="ri-delete-bin-line" style="font-size: 48px;"></i>
                </span>
                <h6 class="text-lg fw-semibold text-primary-light mb-0">Delete Student</h6>
                <p class="text-secondary-light text-sm mt-8">Are you sure you want to delete this student? This action cannot be undone.</p>
                <div class="d-flex align-items-center justify-content-center gap-3 mt-24">
                    <button type="button" class="flex-grow-1 border border-danger-600 bg-hover-danger-200 text-danger-600 text-md px-24 py-11 radius-8" data-bs-dismiss="modal">
                        Cancel
                    </button>
                    <button type="button" id="confirmDeleteBtn" class="flex-grow-1 btn btn-danger-600 border border-danger-600 text-md px-16 py-12 radius-8">
                        Yes, Delete
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://academixsuite.com/tenant/assets/js/lib/jquery-3.7.1.min.js"></script>
<script src="https://academixsuite.com/tenant/assets/js/lib/bootstrap.bundle.min.js"></script>
<script src="https://academixsuite.com/tenant/assets/js/lib/iconify-icon.min.js"></script>
<script src="https://academixsuite.com/tenant/assets/js/lib/dataTables.min.js"></script>
<script src="https://academixsuite.com/tenant/assets/js/app.js"></script>

<script>
$(document).ready(function() {
    console.log('Document ready - Student List initialized');
    
    // Initialize Bootstrap toasts
    $('.toast').toast({
        autohide: true,
        delay: 5000
    });
    $('.toast').toast('show');
    
    // Initialize DataTable with subject list styling
    let table = $('#studentTable').DataTable({
        pageLength: 10,
        lengthMenu: [5, 10, 25, 50, 100],
        order: [[0, 'asc']],
        language: {
            search: "",
            searchPlaceholder: "Search...",
            lengthMenu: "Show _MENU_ entries",
            info: "Showing _START_ to _END_ of _TOTAL_ entries",
            infoEmpty: "Showing 0 to 0 of 0 entries",
            infoFiltered: "(filtered from _MAX_ total entries)",
            paginate: {
                first: "First",
                last: "Last",
                next: "Next",
                previous: "Previous"
            }
        },
        dom: 'rt<"d-flex align-items-center justify-content-between flex-wrap gap-16 px-20 py-12 border-top border-neutral-200"<"text-secondary-light"i><"pagination-wrapper"p>>',
        initComplete: function() {
            // Move the search input to the navbar search
            $('.dataTables_filter').hide();
        },
        columnDefs: [
            { orderable: false, targets: [0, 9] }
        ]
    });

    // Custom search input for DataTable
    $('.dt-search .dt-input').on('keyup', function() {
        table.search(this.value).draw();
    });

    // Handle page length change
    $('.dt-length select').on('change', function() {
        const value = $(this).val();
        table.page.len(value).draw();
    });

    // Navbar search sync with DataTable
    $('.navbar-search input').on('keyup', function() {
        table.search(this.value).draw();
    });

    // Delete functionality
    let studentToDelete = null;

    window.confirmDelete = function(studentId) {
        studentToDelete = studentId;
        $('#deleteModal').modal('show');
    };

    $('#confirmDeleteBtn').on('click', function() {
        if (studentToDelete) {
            window.location.href = 'delete-student.php?id=' + studentToDelete + '&csrf_token=<?php echo $csrfToken; ?>';
        }
    });

    // Toggle status
    window.toggleStatus = function(studentId, currentStatus) {
        const newStatus = currentStatus === 'active' ? 'inactive' : 'active';
        if (confirm('Are you sure you want to change this student\'s status to ' + newStatus + '?')) {
            $.ajax({
                url: 'toggle-student-status.php',
                method: 'POST',
                data: {
                    id: studentId,
                    status: newStatus,
                    csrf_token: '<?php echo $csrfToken; ?>'
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert('Error: ' + response.message);
                    }
                },
                error: function() {
                    alert('An error occurred. Please try again.');
                }
            });
        }
    };

    // Clear filters
    window.clearFilters = function() {
        window.location.href = 'student-list.php';
    };

    // Reset form
    window.resetForm = function() {
        document.querySelector('form').reset();
    };

    // Set current year in footer
    $('.current-year').text(new Date().getFullYear());
});
</script>
</body>
</html>