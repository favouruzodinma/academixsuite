<?php
/**
 * School Student Details Page
 * Displays comprehensive student information including personal details, attendance, fees, etc.
 * 
 * @package AcademixSuite
 * @version 3.1
 */

// Enable error reporting
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../../logs/school_student_details.log');

error_log("=== STUDENT DETAILS PAGE START ===");
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
$currentPage = $GLOBALS['CURRENT_PAGE'] ?? 'student-details.php';
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

// Verify access
if (!in_array($userType, ['admin', 'teacher', 'parent'])) {
    error_log("ERROR: User does not have access to view student details");
    header('HTTP/1.1 403 Forbidden');
    echo "Access denied.";
    exit;
}

// Load configuration
try {
    $autoloadPath = __DIR__ . '/../../../includes/autoload.php';
    if (!file_exists($autoloadPath)) {
        throw new Exception("Autoload file not found at: " . $autoloadPath);
    }
    require_once $autoloadPath;
    
    if (!class_exists('Database')) {
        throw new Exception("Database class not found");
    }
    
} catch (Exception $e) {
    error_log("Error loading files: " . $e->getMessage());
    http_response_code(500);
    die("Configuration loading failed: " . $e->getMessage());
}

// Connect to school database
$schoolDb = null;
try {
    if (!empty($school['database_name'])) {
        $schoolDb = Database::getSchoolConnection($school['database_name']);
        error_log("School database connection successful");
    } else {
        throw new Exception("School database name not found");
    }
} catch (Exception $e) {
    error_log("ERROR connecting to school database: " . $e->getMessage());
    $schoolDb = null;
}

// Get student ID from URL
$studentId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($studentId === 0) {
    error_log("ERROR: No student ID provided in URL");
    header('Location: student-list.php?error=no_student_id');
    exit;
}

// Load the StudentDetailManager
require_once __DIR__ . '/../../../includes/StudentDetailManager.php';
$manager = new StudentDetailManager($schoolDb, $school['id']);

// Helper function for CSRF token
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

// Handle AJAX requests (suspend, activate, promote, transfer)
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
    header('Content-Type: application/json');
    
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!validateCsrfToken($csrfToken)) {
        echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
        exit;
    }

    $action = $_POST['action'] ?? '';
    $response = ['success' => false, 'message' => 'Invalid action.'];

    switch ($action) {
        case 'suspend':
            $response = $manager->suspendStudent($studentId, $userId);
            break;
        case 'activate':
            $response = $manager->activateStudent($studentId, $userId);
            break;
        case 'promote':
        case 'transfer':
            $data = [
                'to_academic_year_id' => (int)($_POST['to_academic_year_id'] ?? 0),
                'to_class_id'         => (int)($_POST['to_class_id'] ?? 0),
                'to_section_id'       => !empty($_POST['to_section_id']) ? (int)$_POST['to_section_id'] : null,
                'to_campus_id'        => !empty($_POST['to_campus_id']) ? (int)$_POST['to_campus_id'] : null,
                'remarks'             => trim($_POST['remarks'] ?? '')
            ];
            $response = $manager->promoteStudent($studentId, $data, $userId);
            break;
    }

    echo json_encode($response);
    exit;
}

// Fetch data using manager
$student = $manager->getStudent($studentId);
if (!$student) {
    error_log("ERROR: Student not found with ID: " . $studentId);
    header('Location: student-list.php?error=student_not_found');
    exit;
}
$guardians = $manager->getGuardians($studentId);
$attendanceStats = $manager->getAttendanceStats($studentId);
$attendanceRecords = $manager->getAttendanceRecords($studentId);
$feeStats = $manager->getFeeStats($studentId);
$feeRecords = $manager->getFeeRecords($studentId);
$examResults = $manager->getExamResults($studentId);

// Get settings
$settings = [];
try {
    $settingsStmt = $schoolDb->prepare("SELECT `key`, `value` FROM settings WHERE school_id = ?");
    if ($settingsStmt) {
        $settingsStmt->execute([$school['id']]);
        while ($row = $settingsStmt->fetch(PDO::FETCH_ASSOC)) {
            $settings[$row['key']] = $row['value'];
        }
    }
} catch (Exception $e) {
    error_log("Error loading settings: " . $e->getMessage());
}

// Get logged in user details
$adminUser = ['name' => 'Admin User', 'role_name' => 'Administrator'];
try {
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
        } elseif (isset($_SESSION['school_user']['name'])) {
            $adminUser = [
                'name' => $_SESSION['school_user']['name'],
                'role_name' => 'Administrator'
            ];
        }
    }
} catch (Exception $e) {
    error_log("Error fetching user details: " . $e->getMessage());
}

// Generate CSRF token
$csrfToken = generateCsrfToken();

// Format currency symbol
$currencySymbol = $settings['currency_symbol'] ?? '₦';

// Helper function for time ago
function timeAgo($datetime) {
    if (empty($datetime)) return 'N/A';
    $time = strtotime($datetime);
    $now = time();
    $diff = $now - $time;
    
    if ($diff < 60) {
        return $diff . ' seconds ago';
    } elseif ($diff < 3600) {
        $mins = floor($diff / 60);
        return $mins . ' minute' . ($mins > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 2592000) {
        $days = floor($diff / 86400);
        return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
    } else {
        return date('M j, Y', $time);
    }
}

error_log("=== STUDENT DETAILS PAGE END ===");
?>

<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="description" content="Student Details - School Management System">
    <meta name="keywords" content="Student Details, Student Information, School Management">
    <meta name="robots" content="INDEX,FOLLOW">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Details - <?php echo htmlspecialchars($school['name'] ?? 'School'); ?></title>
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
        .badge-active {
            background-color: #d4edda;
            color: #155724;
            padding: 4px 12px;
            border-radius: 4px;
            font-size: 0.875rem;
            font-weight: 500;
        }
        .badge-inactive {
            background-color: #f8d7da;
            color: #721c24;
            padding: 4px 12px;
            border-radius: 4px;
            font-size: 0.875rem;
            font-weight: 500;
        }
        .custom-accordion-btn {
            background: #f8f9fa;
            border: none;
            width: 100%;
            text-align: left;
            padding: 12px 20px;
            cursor: pointer;
            transition: all 0.3s;
        }
        .custom-accordion-btn:hover {
            background: #e9ecef;
        }
        .custom-accordion-btn.active {
            background: #e9ecef;
        }
        .custom-accordion-content {
            display: none;
            padding: 20px;
        }
    </style>
</head>
<body>

<!-- Toast Container -->
<div class="toast-container" id="toastContainer"></div>

<!-- Theme Customization Structure Start -->



<div class="overlay bg-black bg-opacity-50 w-100 h-100 position-fixed z-9 visibility-hidden opacity-0 duration-300"></div>

<!-- Sidebar -->
<?php include_once('includes/sidebar.php') ?>

<main class="dashboard-main">
    <div class="navbar-header shadow-1">
        <div class="row align-items-center justify-content-between">
            <div class="col-auto">
                <div class="d-flex flex-wrap align-items-center gap-4">
                    <button type="button" class="sidebar-mobile-toggle" aria-label="Sidebar Mobile Toggler Button">
                        <iconify-icon icon="heroicons:bars-3-solid" class="icon"></iconify-icon>
                    </button>
                    <form class="navbar-search" method="GET" action="student-list.php">
                        <input type="text" class="bg-transparent" name="search" placeholder="Search students...">
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
                <h1 class="fw-semibold mb-4 h6 text-primary-light">Student Details</h1>
                <div class="">
                    <a href="index.php" class="text-secondary-light hover-text-primary hover-underline">Dashboard</a>
                    <a href="student-list.php" class="text-secondary-light hover-text-primary hover-underline"> / Students</a>
                    <span class="text-secondary-light"> / Student Details</span>
                </div>
            </div>
            <button type="button" class="my-sidebar-btn btn btn-primary-600 d-flex align-items-center gap-6">
                <span class="d-flex text-md">
                    <i class="ri-lock-2-line"></i>
                </span>
                Login Details
            </button>
        </div>

        <?php if ($student): ?>
        <div class="mt-24">
            <div class="card h-100">
                <div class="card-body p-24">
                    <div class="d-flex gap-32 flex-md-row flex-column">
                        <div class="max-w-300-px w-100 text-center">
                            <figure class="mb-24 w-120-px h-120-px mx-auto rounded-circle overflow-hidden">
                                <img src="<?php echo htmlspecialchars($student['profile_photo'] ?? 'https://academixsuite.com/tenant/assets/images/thumbs/student-details-img.png'); ?>" alt="Student Image" class="w-100 h-100 object-fit-cover">
                            </figure>
                            <h2 class="h6 text-primary-light mb-16 fw-semibold"><?php echo htmlspecialchars(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? '')); ?></h2>
                            <p class="mb-0">Admission No: <span class="text-primary-600 fw-semibold"><?php echo htmlspecialchars($student['admission_number'] ?? 'N/A'); ?></span></p>
                            <p class="mb-0">Roll No: <span class="text-primary-light fw-semibold"><?php echo htmlspecialchars($student['roll_number'] ?? 'N/A'); ?></span> </p>
                            <div class="mt-32 d-flex gap-16 w-100 flex-wrap">
                                <?php if (($student['status'] ?? 'active') == 'active'): ?>
                                    <button type="button" class="btn border fw-medium border-danger-600 bg-hover-danger-200 text-danger-600 text-md d-flex justify-content-center align-items-center gap-8 flex-grow-1 px-12 py-8 radius-8" data-bs-toggle="modal" data-bs-target="#suspendModal">
                                        <span class="d-flex text-lg"><i class="ri-delete-bin-2-line"></i></span>
                                        Suspend
                                    </button>
                                <?php else: ?>
                                    <button type="button" class="btn border fw-medium border-success-600 bg-hover-success-200 text-success-600 text-md d-flex justify-content-center align-items-center gap-8 flex-grow-1 px-12 py-8 radius-8" data-bs-toggle="modal" data-bs-target="#activateModal">
                                        <span class="d-flex text-lg"><i class="ri-check-line"></i></span>
                                        Activate
                                    </button>
                                <?php endif; ?>
                                <button type="button" class="btn border fw-medium border-primary-600 bg-hover-primary-200 text-primary-600 text-md d-flex justify-content-center align-items-center gap-8 flex-grow-1 px-12 py-8 radius-8" data-bs-toggle="modal" data-bs-target="#promoteModal">
                                    <span class="d-flex text-lg"><i class="ri-arrow-up-line"></i></span>
                                    Promote
                                </button>
                                <button type="button" class="btn border fw-medium border-warning-600 bg-hover-warning-200 text-warning-600 text-md d-flex justify-content-center align-items-center gap-8 flex-grow-1 px-12 py-8 radius-8" data-bs-toggle="modal" data-bs-target="#transferModal">
                                    <span class="d-flex text-lg"><i class="ri-swap-line"></i></span>
                                    Transfer
                                </button>
                                <a href="edit-student.php?id=<?php echo $studentId; ?>" class="btn btn-primary-600 border fw-medium border-primary-600 text-md d-flex justify-content-center align-items-center gap-8 flex-grow-1 px-12 py-8 radius-8">
                                    <span class="d-flex text-lg"><i class="ri-edit-line"></i></span>
                                    Edit
                                </a>
                            </div>
                        </div>
                        <div class="">
                            <span class="h-100 w-1-px bg-neutral-200"></span>
                        </div>
                        <div class="flex-grow-1">
                            <div class="pb-16 border-bottom d-flex align-items-center justify-content-between gap-20">
                                <h3 class="h6 text-primary-light text-lg mb-0 fw-semibold">Personal Info</h3>
                                <span class="<?php echo ($student['status'] ?? 'active') == 'active' ? 'bg-success-100 text-success-600' : 'bg-danger-100 text-danger-600'; ?> px-16 py-4 radius-4 fw-medium text-sm">
                                    <?php echo ucfirst($student['status'] ?? 'Active'); ?>
                                </span>
                            </div>
                            <div class="mt-16 d-flex flex-column gap-8">
                                <div class="d-flex gap-4">
                                    <span class="fw-semibold text-sm text-primary-light w-110-px">Class</span>
                                    <span class="fw-normal text-sm text-secondary-light">: <?php echo htmlspecialchars($student['class_name'] ?? 'N/A'); ?> <?php echo !empty($student['section_name']) ? '(' . htmlspecialchars($student['section_name']) . ')' : ''; ?></span>
                                </div>
                                <div class="d-flex gap-4">
                                    <span class="fw-semibold text-sm text-primary-light w-110-px">Roll No</span>
                                    <span class="fw-normal text-sm text-secondary-light">: <?php echo htmlspecialchars($student['roll_number'] ?? 'N/A'); ?></span>
                                </div>
                                <div class="d-flex gap-4">
                                    <span class="fw-semibold text-sm text-primary-light w-110-px">Gender</span>
                                    <span class="fw-normal text-sm text-secondary-light">: <?php echo ucfirst($student['gender'] ?? 'N/A'); ?></span>
                                </div>
                                <div class="d-flex gap-4">
                                    <span class="fw-semibold text-sm text-primary-light w-110-px">Date Of Birth</span>
                                    <span class="fw-normal text-sm text-secondary-light">: <?php echo !empty($student['date_of_birth']) ? date('d M Y', strtotime($student['date_of_birth'])) : 'N/A'; ?></span>
                                </div>
                                <div class="d-flex gap-4">
                                    <span class="fw-semibold text-sm text-primary-light w-110-px">Academic Year</span>
                                    <span class="fw-normal text-sm text-secondary-light">: <?php echo htmlspecialchars($student['academic_year_name'] ?? 'N/A'); ?></span>
                                </div>
                                <div class="d-flex gap-4">
                                    <span class="fw-semibold text-sm text-primary-light w-110-px">Phone Number</span>
                                    <span class="fw-normal text-sm text-primary-600">: <?php echo htmlspecialchars($student['student_phone'] ?? 'N/A'); ?></span>
                                </div>
                                <div class="d-flex gap-4">
                                    <span class="fw-semibold text-sm text-primary-light w-110-px">Email</span>
                                    <span class="fw-normal text-sm text-primary-600">: <?php echo htmlspecialchars($student['student_email'] ?? 'N/A'); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="my-16">
                <ul class="nav nav-pills bordered-tab mb-3" id="pills-tab" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link d-flex align-items-center gap-8 text-secondary-light fw-medium text-sm text-hover-primary-600 text-capitalize bg-transparent px-20 py-12 active" id="pills-studentDetails-tab" data-bs-toggle="pill" data-bs-target="#pills-studentDetails" type="button" role="tab" aria-controls="pills-studentDetails" aria-selected="true">
                            <span class="d-flex tab-icon line-height-1 text-md">
                                <i class="ri-group-line"></i>
                            </span>
                            Student Details
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link d-flex align-items-center gap-8 text-secondary-light fw-medium text-sm text-hover-primary-600 text-capitalize bg-transparent px-20 py-12" id="pills-attendance-tab" data-bs-toggle="pill" data-bs-target="#pills-attendance" type="button" role="tab" aria-controls="pills-attendance" aria-selected="false">
                            <span class="d-flex tab-icon line-height-1 text-md">
                                <i class="ri-calendar-check-line"></i>
                            </span>
                            Attendance
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link d-flex align-items-center gap-8 text-secondary-light fw-medium text-sm text-hover-primary-600 text-capitalize bg-transparent px-20 py-12" id="pills-fees-tab" data-bs-toggle="pill" data-bs-target="#pills-fees" type="button" role="tab" aria-controls="pills-fees" aria-selected="false">
                            <span class="d-flex tab-icon line-height-1 text-md">
                                <i class="ri-money-dollar-box-line"></i>
                            </span>
                            Fees
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link d-flex align-items-center gap-8 text-secondary-light fw-medium text-sm text-hover-primary-600 text-capitalize bg-transparent px-20 py-12" id="pills-exam-tab" data-bs-toggle="pill" data-bs-target="#pills-exam" type="button" role="tab" aria-controls="pills-exam" aria-selected="false">
                            <span class="d-flex tab-icon line-height-1 text-md">
                                <i class="ri-file-edit-line"></i>
                            </span>
                            Exam
                        </button>
                    </li>
                </ul>

                <div class="tab-content" id="pills-tabContent">
                    <!-- Student Details tab -->
                    <div class="tab-pane fade show active" id="pills-studentDetails" role="tabpanel" aria-labelledby="pills-studentDetails-tab" tabindex="0">
                        <div class="row gy-4">
                            <div class="col-12">
                                <div class="shadow-1 radius-12 bg-base h-100 overflow-hidden">
                                    <div class="card-header border-bottom bg-base py-16 px-24 d-flex align-items-center justify-content-between">
                                        <h6 class="text-lg fw-semibold mb-0">Parent Guardian Details</h6>
                                    </div>
                                    <div class="card-body p-0">
                                        <?php if (empty($guardians)): ?>
                                        <div class="p-20 text-center text-secondary-light">
                                            No guardians found for this student.
                                        </div>
                                        <?php else: ?>
                                            <?php foreach ($guardians as $guardian): ?>
                                            <div class="bg-hover-neutral-50 p-20 border-bottom">
                                                <div class="row g-4">
                                                    <div class="col-sm-4">
                                                        <div class="d-flex align-items-center gap-12">
                                                            <figure class="w-48-px h-48-px rounded-circle overflow-hidden mb-0">
                                                                <img src="<?php echo htmlspecialchars($guardian['profile_photo'] ?? 'https://academixsuite.com/tenant/assets/images/thumbs/guardian-img1.png'); ?>" alt="Guardian Image" class="flex-shrink-0 w-100 h-100 object-fit-cover">
                                                            </figure>
                                                            <div class="">
                                                                <h6 class="text-md mb-2 fw-medium flex-grow-1"><?php echo htmlspecialchars($guardian['name'] ?? 'N/A'); ?></h6>
                                                                <span class=""><?php echo ucfirst($guardian['relationship'] ?? 'Guardian'); ?>
                                                                    <?php if (!empty($guardian['is_primary'])): ?>
                                                                    <span class="badge bg-primary-100 text-primary-600 ms-1">Primary</span>
                                                                    <?php endif; ?>
                                                                </span>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="col-sm-4">
                                                        <div class="">
                                                            <h6 class="text-md mb-2 fw-medium flex-grow-1">Phone</h6>
                                                            <span class=""><?php echo htmlspecialchars($guardian['phone'] ?? 'N/A'); ?></span>
                                                        </div>
                                                    </div>
                                                    <div class="col-sm-4">
                                                        <div class="">
                                                            <h6 class="text-md mb-2 fw-medium flex-grow-1">Email</h6>
                                                            <span class=""><?php echo htmlspecialchars($guardian['email'] ?? 'N/A'); ?></span>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div class="shadow-1 radius-12 bg-base h-100 overflow-hidden">
                                    <div class="card-header border-bottom bg-base py-16 px-24 d-flex align-items-center justify-content-between">
                                        <h6 class="text-lg fw-semibold mb-0">Previous School Details</h6>
                                    </div>
                                    <div class="card-body p-20">
                                        <div class="row gy-4">
                                            <div class="col-sm-12">
                                                <div class="">
                                                    <h6 class="text-md mb-2 fw-medium flex-grow-1">Previous School Name</h6>
                                                    <span class=""><?php echo htmlspecialchars($student['previous_school'] ?? 'N/A'); ?></span>
                                                </div>
                                            </div>
                                            <div class="col-sm-12">
                                                <div class="">
                                                    <h6 class="text-md mb-2 fw-medium flex-grow-1">Previous Class</h6>
                                                    <span class=""><?php echo htmlspecialchars($student['previous_class'] ?? 'N/A'); ?></span>
                                                </div>
                                            </div>
                                            <div class="col-sm-12">
                                                <div class="">
                                                    <h6 class="text-md mb-2 fw-medium flex-grow-1">Transfer Certificate No.</h6>
                                                    <span class=""><?php echo htmlspecialchars($student['transfer_certificate_no'] ?? 'N/A'); ?></span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div class="shadow-1 radius-12 bg-base h-100 overflow-hidden">
                                    <div class="card-header border-bottom bg-base py-16 px-24 d-flex align-items-center justify-content-between">
                                        <h6 class="text-lg fw-semibold mb-0">Address</h6>
                                    </div>
                                    <div class="card-body p-20">
                                        <div class="row gy-4">
                                            <div class="col-sm-12">
                                                <div class="">
                                                    <h6 class="text-md mb-2 fw-medium flex-grow-1">Current Address</h6>
                                                    <span class=""><?php echo nl2br(htmlspecialchars($student['current_address'] ?? 'N/A')); ?></span>
                                                </div>
                                            </div>
                                            <div class="col-sm-12">
                                                <div class="">
                                                    <h6 class="text-md mb-2 fw-medium flex-grow-1">Permanent Address</h6>
                                                    <span class=""><?php echo nl2br(htmlspecialchars($student['permanent_address'] ?? 'N/A')); ?></span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div class="shadow-1 radius-12 bg-base h-100 overflow-hidden">
                                    <div class="card-header border-bottom bg-base py-16 px-24 d-flex align-items-center justify-content-between">
                                        <h6 class="text-lg fw-semibold mb-0">Medical Details</h6>
                                    </div>
                                    <div class="card-body p-20">
                                        <div class="row gy-4">
                                            <div class="col-sm-4">
                                                <div class="">
                                                    <h6 class="text-md mb-2 fw-medium flex-grow-1">Blood Group</h6>
                                                    <span class=""><?php echo htmlspecialchars($student['blood_group'] ?? $student['user_blood_group'] ?? 'N/A'); ?></span>
                                                </div>
                                            </div>
                                            <div class="col-sm-4">
                                                <div class="">
                                                    <h6 class="text-md mb-2 fw-medium flex-grow-1">Allergies</h6>
                                                    <span class=""><?php echo htmlspecialchars($student['allergies'] ?? 'None'); ?></span>
                                                </div>
                                            </div>
                                            <div class="col-sm-4">
                                                <div class="">
                                                    <h6 class="text-md mb-2 fw-medium flex-grow-1">Medical Conditions</h6>
                                                    <span class=""><?php echo htmlspecialchars($student['medical_conditions'] ?? 'None'); ?></span>
                                                </div>
                                            </div>
                                            <div class="col-sm-6">
                                                <div class="">
                                                    <h6 class="text-md mb-2 fw-medium flex-grow-1">Doctor's Name</h6>
                                                    <span class=""><?php echo htmlspecialchars($student['doctor_name'] ?? 'N/A'); ?></span>
                                                </div>
                                            </div>
                                            <div class="col-sm-6">
                                                <div class="">
                                                    <h6 class="text-md mb-2 fw-medium flex-grow-1">Doctor's Phone</h6>
                                                    <span class=""><?php echo htmlspecialchars($student['doctor_phone'] ?? 'N/A'); ?></span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Attendance tab -->
                    <div class="tab-pane fade" id="pills-attendance" role="tabpanel" aria-labelledby="pills-attendance-tab" tabindex="0">
                        <div class="shadow-1 radius-12 bg-base h-100 overflow-hidden">
                            <div class="card-header border-bottom bg-base py-16 px-24 d-flex align-items-center justify-content-between">
                                <h6 class="text-lg fw-semibold mb-0">Attendance - <?php echo date('Y'); ?></h6>
                            </div>
                            <div class="card-body p-0">
                                <div class="px-20 pt-20">
                                    <div class="row row-cols-xxl-4 row-cols-lg-3 row-cols-sm-2 row-cols-1 g-3">
                                        <div class="col">
                                            <div class="card px-20 py-28 shadow-2 radius-8 h-100 border border-neutral-200 shadow-none">
                                                <div class="card-body p-0">
                                                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-1">
                                                        <div>
                                                            <h6 class="fw-semibold mb-2"><?php echo $attendanceStats['present_count'] ?? 0; ?></h6>
                                                            <span class="fw-medium text-secondary-light text-sm">Present</span>
                                                        </div>
                                                        <span class="mb-0 w-48-px h-48-px bg-success-600 text-white flex-shrink-0 d-flex justify-content-center align-items-center rounded-circle">
                                                            <i class="ri-check-line"></i>
                                                        </span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col">
                                            <div class="card px-20 py-28 shadow-2 radius-8 h-100 border border-neutral-200 shadow-none">
                                                <div class="card-body p-0">
                                                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-1">
                                                        <div>
                                                            <h6 class="fw-semibold mb-2"><?php echo $attendanceStats['absent_count'] ?? 0; ?></h6>
                                                            <span class="fw-medium text-secondary-light text-sm">Absent</span>
                                                        </div>
                                                        <span class="mb-0 w-48-px h-48-px bg-danger-600 text-white flex-shrink-0 d-flex justify-content-center align-items-center rounded-circle">
                                                            <i class="ri-close-line"></i>
                                                        </span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col">
                                            <div class="card px-20 py-28 shadow-2 radius-8 h-100 border border-neutral-200 shadow-none">
                                                <div class="card-body p-0">
                                                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-1">
                                                        <div>
                                                            <h6 class="fw-semibold mb-2"><?php echo $attendanceStats['late_count'] ?? 0; ?></h6>
                                                            <span class="fw-medium text-secondary-light text-sm">Late</span>
                                                        </div>
                                                        <span class="mb-0 w-48-px h-48-px bg-warning-600 text-white flex-shrink-0 d-flex justify-content-center align-items-center rounded-circle">
                                                            <i class="ri-time-line"></i>
                                                        </span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col">
                                            <div class="card px-20 py-28 shadow-2 radius-8 h-100 border border-neutral-200 shadow-none">
                                                <div class="card-body p-0">
                                                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-1">
                                                        <div>
                                                            <h6 class="fw-semibold mb-2"><?php echo $attendanceStats['half_day_count'] ?? 0; ?></h6>
                                                            <span class="fw-medium text-secondary-light text-sm">Half Day</span>
                                                        </div>
                                                        <span class="mb-0 w-48-px h-48-px bg-info-600 text-white flex-shrink-0 d-flex justify-content-center align-items-center rounded-circle">
                                                            <i class="ri-calendar-line"></i>
                                                        </span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="mt-24 mb-16 mx-20">
                                    <div class="d-flex align-items-center flex-wrap gap-8">
                                        <p class="text-primary-light text-sm fw-medium mb-0">
                                            Present: <span class="fw-semibold text-success-600">P</span>
                                        </p>
                                        <p class="text-primary-light text-sm fw-medium mb-0">
                                            Absent: <span class="fw-semibold text-danger-600">A</span>
                                        </p>
                                        <p class="text-primary-light text-sm fw-medium mb-0">
                                            Late: <span class="fw-semibold text-warning-600">L</span>
                                        </p>
                                        <p class="text-primary-light text-sm fw-medium mb-0">
                                            Half Day: <span class="fw-semibold text-info-600">H</span>
                                        </p>
                                    </div>
                                </div>

                                <div class="table-responsive overflow-x-auto">
                                    <table class="table mb-0">
                                        <thead>
                                            <tr>
                                                <th class="bg-neutral-100 text-sm text-primary-light px-10 py-16">Date</th>
                                                <th class="bg-neutral-100 text-sm text-primary-light px-10 py-16">Status</th>
                                                <th class="bg-neutral-100 text-sm text-primary-light px-10 py-16">Remark</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($attendanceRecords)): ?>
                                            <tr>
                                                <td colspan="3" class="text-center py-20 text-secondary-light">
                                                    No attendance records found.
                                                </td>
                                            </tr>
                                            <?php else: ?>
                                                <?php foreach ($attendanceRecords as $record): ?>
                                                <tr>
                                                    <td class="px-10 py-16 text-sm"><?php echo date('d M Y', strtotime($record['date'])); ?></td>
                                                    <td class="px-10 py-14 text-sm text-uppercase">
                                                        <?php
                                                        $statusClass = '';
                                                        $statusChar = '';
                                                        switch($record['status'] ?? '') {
                                                            case 'present':
                                                                $statusClass = 'text-success-600';
                                                                $statusChar = 'P';
                                                                break;
                                                            case 'absent':
                                                                $statusClass = 'text-danger-600';
                                                                $statusChar = 'A';
                                                                break;
                                                            case 'late':
                                                                $statusClass = 'text-warning-600';
                                                                $statusChar = 'L';
                                                                break;
                                                            case 'half_day':
                                                                $statusClass = 'text-info-600';
                                                                $statusChar = 'H';
                                                                break;
                                                            default:
                                                                $statusClass = 'text-secondary-600';
                                                                $statusChar = '-';
                                                        }
                                                        ?>
                                                        <span class="attendance <?php echo $statusClass; ?>"><?php echo $statusChar; ?></span>
                                                    </td>
                                                    <td class="px-10 py-14 text-sm"><?php echo htmlspecialchars($record['remark'] ?? '-'); ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Fees tab -->
                    <div class="tab-pane fade" id="pills-fees" role="tabpanel" aria-labelledby="pills-fees-tab" tabindex="0">
                        <div class="shadow-1 radius-12 bg-base h-100 overflow-hidden">
                            <div class="card-header border-bottom bg-base py-16 px-24 d-flex align-items-center justify-content-between">
                                <h6 class="text-lg fw-semibold mb-0">Fees</h6>
                                <a href="fees-collect.php?student_id=<?php echo $studentId; ?>" class="btn btn-primary-600 d-flex align-items-center gap-6 py-8 text-sm">
                                    <span class="d-flex text-sm">
                                        <i class="ri-bank-card-line"></i>
                                    </span>
                                    Collect Fees
                                </a>
                            </div>
                            <div class="card-body p-0">
                                <div class="p-20">
                                    <div class="row g-3">
                                        <div class="col-xl-3 col-sm-6">
                                            <div class="card px-20 py-28 shadow-2 radius-8 h-100 border border-neutral-200 shadow-none">
                                                <div class="card-body p-0">
                                                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-1">
                                                        <div>
                                                            <h6 class="fw-semibold mb-2"><?php echo $currencySymbol . number_format($feeStats['total_amount'] ?? 0, 2); ?></h6>
                                                            <span class="fw-medium text-secondary-light text-sm">Total</span>
                                                        </div>
                                                        <span class="mb-0 w-48-px h-48-px bg-primary-600 text-white flex-shrink-0 d-flex justify-content-center align-items-center rounded-circle">
                                                            <i class="ri-money-dollar-circle-line"></i>
                                                        </span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-xl-3 col-sm-6">
                                            <div class="card px-20 py-28 shadow-2 radius-8 h-100 border border-neutral-200 shadow-none">
                                                <div class="card-body p-0">
                                                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-1">
                                                        <div>
                                                            <h6 class="fw-semibold mb-2"><?php echo $currencySymbol . number_format($feeStats['total_paid'] ?? 0, 2); ?></h6>
                                                            <span class="fw-medium text-secondary-light text-sm">Paid</span>
                                                        </div>
                                                        <span class="mb-0 w-48-px h-48-px bg-success-600 text-white flex-shrink-0 d-flex justify-content-center align-items-center rounded-circle">
                                                            <i class="ri-check-line"></i>
                                                        </span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-xl-3 col-sm-6">
                                            <div class="card px-20 py-28 shadow-2 radius-8 h-100 border border-neutral-200 shadow-none">
                                                <div class="card-body p-0">
                                                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-1">
                                                        <div>
                                                            <h6 class="fw-semibold mb-2"><?php echo $currencySymbol . number_format($feeStats['total_due'] ?? 0, 2); ?></h6>
                                                            <span class="fw-medium text-secondary-light text-sm">Due</span>
                                                        </div>
                                                        <span class="mb-0 w-48-px h-48-px bg-warning-600 text-white flex-shrink-0 d-flex justify-content-center align-items-center rounded-circle">
                                                            <i class="ri-error-warning-line"></i>
                                                        </span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <table class="table bordered-table mb-0 w-100">
                                    <thead>
                                        <tr>
                                            <th scope="col">#</th>
                                            <th scope="col">Fees Type</th>
                                            <th scope="col">Due Date</th>
                                            <th scope="col">Amount</th>
                                            <th scope="col">Paid</th>
                                            <th scope="col">Due</th>
                                            <th scope="col">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($feeRecords)): ?>
                                        <tr>
                                            <td colspan="7" class="text-center py-20 text-secondary-light">
                                                No fee records found.
                                            </td>
                                        </tr>
                                        <?php else: ?>
                                            <?php foreach ($feeRecords as $index => $fee): ?>
                                            <tr>
                                                <td><?php echo $index + 1; ?></td>
                                                <td><?php echo htmlspecialchars($fee['fee_category_name'] ?? 'N/A'); ?></td>
                                                <td><?php echo !empty($fee['due_date']) ? date('d M Y', strtotime($fee['due_date'])) : 'N/A'; ?></td>
                                                <td><?php echo $currencySymbol . number_format($fee['amount'] ?? 0, 2); ?></td>
                                                <td><?php echo $currencySymbol . number_format($fee['paid_amount'] ?? 0, 2); ?></td>
                                                <td><?php echo $currencySymbol . number_format(($fee['amount'] ?? 0) - ($fee['paid_amount'] ?? 0), 2); ?></td>
                                                <td>
                                                    <?php
                                                    $statusClass = '';
                                                    switch($fee['status'] ?? 'pending') {
                                                        case 'paid':
                                                            $statusClass = 'bg-success-100 text-success-600';
                                                            break;
                                                        case 'partial':
                                                            $statusClass = 'bg-warning-100 text-warning-600';
                                                            break;
                                                        case 'overdue':
                                                            $statusClass = 'bg-danger-100 text-danger-600';
                                                            break;
                                                        default:
                                                            $statusClass = 'bg-secondary-100 text-secondary-600';
                                                    }
                                                    ?>
                                                    <span class="<?php echo $statusClass; ?> px-20 py-4 radius-4 fw-medium text-sm">
                                                        <?php echo ucfirst($fee['status'] ?? 'Pending'); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Exam tab -->
                    <div class="tab-pane fade" id="pills-exam" role="tabpanel" aria-labelledby="pills-exam-tab" tabindex="0">
                        <div class="shadow-1 radius-12 bg-base h-100 overflow-hidden">
                            <div class="card-header border-bottom bg-base py-16 px-24 d-flex align-items-center justify-content-between">
                                <h6 class="text-lg fw-semibold mb-0">Exam Results</h6>
                            </div>
                            <div class="card-body p-20">
                                <?php if (empty($examResults)): ?>
                                <div class="text-center py-20 text-secondary-light">
                                    No exam results found.
                                </div>
                                <?php else: ?>
                                    <?php 
                                    // Group exams by exam name
                                    $groupedExams = [];
                                    foreach ($examResults as $result) {
                                        $groupedExams[$result['exam_name']][] = $result;
                                    }
                                    ?>
                                    
                                    <?php foreach ($groupedExams as $examName => $results): ?>
                                    <div class="border radius-8 overflow-hidden mb-20">
                                        <button type="button" class="custom-accordion-btn text-md fw-semibold text-secondary-light w-100 py-10 px-20 d-flex align-items-center gap-12 justify-content-between">
                                            <?php echo htmlspecialchars($examName); ?>
                                            <span class="arrow-icon text-lg d-flex line-height-1">
                                                <i class="ri-arrow-down-s-line"></i>
                                            </span>
                                        </button>
                                        <div class="custom-accordion-content">
                                            <table class="table bordered-table mb-0 w-100">
                                                <thead>
                                                    <tr>
                                                        <th class="text-start">Subject</th>
                                                        <th class="text-start">Max Marks</th>
                                                        <th class="text-start">Marks Obtained</th>
                                                        <th class="text-start">Grade</th>
                                                        <th class="text-start">Result</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php 
                                                    $totalMarks = 0;
                                                    $obtainedMarks = 0;
                                                    foreach ($results as $result): 
                                                        $totalMarks += $result['total_marks'] ?? 0;
                                                        $obtainedMarks += $result['marks_obtained'] ?? 0;
                                                    ?>
                                                    <tr>
                                                        <td class="text-start"><?php echo htmlspecialchars($result['subject_name'] ?? 'N/A'); ?></td>
                                                        <td class="text-start"><?php echo number_format($result['total_marks'] ?? 0, 2); ?></td>
                                                        <td class="text-start"><?php echo number_format($result['marks_obtained'] ?? 0, 2); ?></td>
                                                        <td class="text-start"><?php echo htmlspecialchars($result['grade'] ?? '-'); ?></td>
                                                        <td class="text-start">
                                                            <?php if (($result['marks_obtained'] ?? 0) >= (($result['total_marks'] ?? 0) * 0.35)): ?>
                                                            <span class="bg-success-100 text-success-600 px-16 py-2 radius-4 fw-medium text-sm">Pass</span>
                                                            <?php else: ?>
                                                            <span class="bg-danger-100 text-danger-600 px-16 py-2 radius-4 fw-medium text-sm">Fail</span>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                                <tfoot>
                                                    <tr>
                                                        <td colspan="5" class="bg-neutral-50 p-10">
                                                            <strong>Total: </strong><?php echo number_format($totalMarks, 2); ?> | 
                                                            <strong>Obtained: </strong><?php echo number_format($obtainedMarks, 2); ?> | 
                                                            <strong>Percentage: </strong><?php echo $totalMarks > 0 ? round(($obtainedMarks / $totalMarks) * 100, 2) : 0; ?>%
                                                        </td>
                                                    </tr>
                                                </tfoot>
                                            </table>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <footer class="d-footer">
        <div class="">
            <p class="mb-0 text-center"> &copy; <span class="current-year"></span> <?php echo htmlspecialchars($school['name'] ?? 'School'); ?> | Made With ❤️ by AcademixSuite.</p>
        </div>
    </footer>
</main>

<!-- Login Details sidebar -->
<div class="my-sidebar bg-white position-fixed end-0 top-0 h-100vh overflow-y-auto z-99 max-w-700-px w-100 translate-x-full duration-300 active-translate-0">
    <div class="px-20 py-12 border-bottom d-flex align-items-center justify-content-between gap-20">
        <h5 class="text-lg mb-0">Login Details</h5>
        <button type="button" class="close-my-sidebar text-danger-600 text-lg d-flex">
            <i class="ri-close-large-line"></i>
        </button>
    </div>
    <div class="p-20">
        <div class="d-flex align-items-center gap-20 mb-20">
            <figure class="w-72-px h-72-px rounded-circle overflow-hidden mb-0">
                <img src="<?php echo htmlspecialchars($student['profile_photo'] ?? 'https://academixsuite.com/tenant/assets/images/thumbs/student-details-img.png'); ?>" alt="Student Image" class="w-100 h-100 object-fit-cover">
            </figure>
            <div class="flex-grow-1">
                <h2 class="text-xl text-primary-light mb-4"><?php echo htmlspecialchars(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? '')); ?></h2>
                <p class="mb-0">Roll No: <span class="text-primary-light fw-semibold"><?php echo htmlspecialchars($student['roll_number'] ?? 'N/A'); ?></span></p>
            </div>
        </div>
        <table class="table bordered-table mb-0 w-100">
            <thead>
                <tr>
                    <th scope="col" class="text-start">User Type</th>
                    <th scope="col" class="text-start">Email</th>
                    <th scope="col" class="text-start">Password</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="text-start">Student</td>
                    <td class="text-start"><?php echo htmlspecialchars($student['student_email'] ?? 'N/A'); ?></td>
                    <td class="text-start">********</td>
                </tr>
                <?php foreach ($guardians as $guardian): ?>
                <tr>
                    <td class="text-start"><?php echo ucfirst($guardian['relationship'] ?? 'Parent'); ?></td>
                    <td class="text-start"><?php echo htmlspecialchars($guardian['email'] ?? 'N/A'); ?></td>
                    <td class="text-start">********</td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Suspend Modal -->
<div class="modal fade" id="suspendModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content radius-16 bg-base">
            <div class="modal-body pt-32 px-36 pb-24 text-center">
                <span class="mb-16 fs-1 line-height-1 text-danger">
                    <i class="ri-delete-bin-2-line"></i>
                </span>
                <h6 class="text-lg fw-semibold text-primary-light mb-0">Are you sure you want to suspend this student?</h6>
                <div class="d-flex align-items-center justify-content-center gap-3 mt-24">
                    <button type="button" class="flex-grow-1 border border-danger-600 bg-hover-danger-200 text-danger-600 text-md px-24 py-11 radius-8" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="flex-grow-1 btn btn-primary-600 border border-primary-600 text-md px-16 py-12 radius-8" id="confirmSuspendBtn">Yes, Suspend</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Activate Modal -->
<div class="modal fade" id="activateModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content radius-16 bg-base">
            <div class="modal-body pt-32 px-36 pb-24 text-center">
                <span class="mb-16 fs-1 line-height-1 text-success">
                    <i class="ri-check-line"></i>
                </span>
                <h6 class="text-lg fw-semibold text-primary-light mb-0">Are you sure you want to activate this student?</h6>
                <div class="d-flex align-items-center justify-content-center gap-3 mt-24">
                    <button type="button" class="flex-grow-1 border border-danger-600 bg-hover-danger-200 text-danger-600 text-md px-24 py-11 radius-8" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="flex-grow-1 btn btn-primary-600 border border-primary-600 text-md px-16 py-12 radius-8" id="confirmActivateBtn">Yes, Activate</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Promote Modal -->
<div class="modal fade" id="promoteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content radius-16 bg-base">
            <form id="promoteForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" value="promote">
                <div class="modal-header">
                    <h5 class="modal-title">Promote Student</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Academic Year</label>
                        <select name="to_academic_year_id" class="form-select" required>
                            <option value="">Select Year</option>
                            <?php foreach ($manager->getAcademicYears() as $year): ?>
                                <option value="<?php echo $year['id']; ?>"><?php echo htmlspecialchars($year['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Class</label>
                        <select name="to_class_id" class="form-select" required>
                            <option value="">Select Class</option>
                            <?php foreach ($manager->getClasses() as $class): ?>
                                <option value="<?php echo $class['id']; ?>"><?php echo htmlspecialchars($class['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Section (optional)</label>
                        <select name="to_section_id" class="form-select">
                            <option value="">No Section</option>
                            <?php foreach ($manager->getSections() as $section): ?>
                                <option value="<?php echo $section['id']; ?>"><?php echo htmlspecialchars($section['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Campus (optional)</label>
                        <select name="to_campus_id" class="form-select">
                            <option value="">No Campus</option>
                            <?php foreach ($manager->getCampuses() as $campus): ?>
                                <option value="<?php echo $campus['id']; ?>"><?php echo htmlspecialchars($campus['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Remarks (optional)</label>
                        <textarea name="remarks" class="form-control" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Promote</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Transfer Modal -->
<div class="modal fade" id="transferModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content radius-16 bg-base">
            <form id="transferForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" value="transfer">
                <div class="modal-header">
                    <h5 class="modal-title">Transfer Student</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Academic Year</label>
                        <select name="to_academic_year_id" class="form-select" required>
                            <option value="">Select Year</option>
                            <?php foreach ($manager->getAcademicYears() as $year): ?>
                                <option value="<?php echo $year['id']; ?>"><?php echo htmlspecialchars($year['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Class</label>
                        <select name="to_class_id" class="form-select" required>
                            <option value="">Select Class</option>
                            <?php foreach ($manager->getClasses() as $class): ?>
                                <option value="<?php echo $class['id']; ?>"><?php echo htmlspecialchars($class['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Section (optional)</label>
                        <select name="to_section_id" class="form-select">
                            <option value="">No Section</option>
                            <?php foreach ($manager->getSections() as $section): ?>
                                <option value="<?php echo $section['id']; ?>"><?php echo htmlspecialchars($section['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Campus (optional)</label>
                        <select name="to_campus_id" class="form-select">
                            <option value="">No Campus</option>
                            <?php foreach ($manager->getCampuses() as $campus): ?>
                                <option value="<?php echo $campus['id']; ?>"><?php echo htmlspecialchars($campus['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Remarks (optional)</label>
                        <textarea name="remarks" class="form-control" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">Transfer</button>
                </div>
            </form>
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
    console.log('Document ready - Student Details initialized');

    // Function to show toast
    function showToast(message, type = 'success') {
        const toastHtml = `
            <div class="toast ${type} show" role="alert" aria-live="assertive" aria-atomic="true" data-autohide="true" data-delay="5000">
                <div class="toast-header">
                    <i class="ri-${type === 'success' ? 'checkbox-circle' : 'error-warning'}-line me-2"></i>
                    <strong class="me-auto">${type === 'success' ? 'Success' : 'Error'}</strong>
                    <small>just now</small>
                    <button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Close"></button>
                </div>
                <div class="toast-body">
                    ${message}
                </div>
            </div>
        `;
        $('#toastContainer').append(toastHtml);
        $('.toast').toast('show');
        setTimeout(() => {
            $('.toast').first().remove();
        }, 5000);
    }

    // Suspend confirmation
    $('#confirmSuspendBtn').on('click', function() {
        $.post(window.location.href, {
            action: 'suspend',
            csrf_token: '<?php echo $csrfToken; ?>'
        }, function(response) {
            $('#suspendModal').modal('hide');
            if (response.success) {
                showToast(response.message, 'success');
                location.reload();
            } else {
                showToast(response.message, 'error');
            }
        }, 'json').fail(function() {
            showToast('Request failed. Please try again.', 'error');
        });
    });

    // Activate confirmation
    $('#confirmActivateBtn').on('click', function() {
        $.post(window.location.href, {
            action: 'activate',
            csrf_token: '<?php echo $csrfToken; ?>'
        }, function(response) {
            $('#activateModal').modal('hide');
            if (response.success) {
                showToast(response.message, 'success');
                location.reload();
            } else {
                showToast(response.message, 'error');
            }
        }, 'json').fail(function() {
            showToast('Request failed. Please try again.', 'error');
        });
    });

    // Promote form submission
    $('#promoteForm').on('submit', function(e) {
        e.preventDefault();
        $.post(window.location.href, $(this).serialize(), function(response) {
            if (response.success) {
                showToast(response.message, 'success');
                $('#promoteModal').modal('hide');
                location.reload();
            } else {
                showToast(response.message, 'error');
            }
        }, 'json').fail(function() {
            showToast('Request failed. Please try again.', 'error');
        });
    });

    // Transfer form submission
    $('#transferForm').on('submit', function(e) {
        e.preventDefault();
        $.post(window.location.href, $(this).serialize(), function(response) {
            if (response.success) {
                showToast(response.message, 'success');
                $('#transferModal').modal('hide');
                location.reload();
            } else {
                showToast(response.message, 'error');
            }
        }, 'json').fail(function() {
            showToast('Request failed. Please try again.', 'error');
        });
    });

    // Dynamic Class added to attendance status
    $('.attendance').each(function() {
        let value = $(this).text().trim().toUpperCase();
        if (value === 'P') {
            $(this).addClass('text-success-600');
        } else if (value === 'A') {
            $(this).addClass('text-danger-600');
        } else if (value === 'L') {
            $(this).addClass('text-warning-600');
        } else if (value === 'H') {
            $(this).addClass('text-info-600');
        }
    });

    // Custom accordion
    $(document).on('click', '.custom-accordion-btn', function() {
        $('.custom-accordion-btn').not(this).removeClass('active').siblings('.custom-accordion-content').slideUp();
        $(this).toggleClass('active');
        $(this).siblings('.custom-accordion-content').slideToggle();
    });

    // Keep first accordion open by default
    if ($('.custom-accordion-btn').length > 0) {
        $('.custom-accordion-btn').first().addClass('active');
        $('.custom-accordion-btn').first().siblings('.custom-accordion-content').show();
    }

    // Sidebar toggles
    $('.my-sidebar-btn').on('click', function() {
        $('.my-sidebar').addClass('active');
        $('.overlay').addClass('active');
    });
    
    $('.close-my-sidebar, .overlay').on('click', function() {
        $('.my-sidebar').removeClass('active');
        $('.overlay').removeClass('active');
    });
});
</script>
</body>
</html>