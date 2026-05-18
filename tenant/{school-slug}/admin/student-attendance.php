<?php

/**
 * School Student Attendance Page
 * Handles recording student attendance with bulk operations and notifications
 */

// Enable error reporting
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../../logs/school_attendance.log');

error_log("=== ATTENDANCE PAGE START ===");
error_log("Request URI: " . ($_SERVER['REQUEST_URI'] ?? 'N/A'));
error_log("Script: " . __FILE__);

// Define constants if not defined
if (!defined('APP_NAME')) define('APP_NAME', 'AcademixSuite');
// IS_LOCAL is self-defining via config/constants.php (security: do not force true).

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
$currentPage = $GLOBALS['CURRENT_PAGE'] ?? 'student-attendance.php';
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
    if ($_SESSION['school_auth']['school_slug'] === $schoolSlug) {
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
$userId = $schoolAuth['user_id'] ?? 0;
$userType = $schoolAuth['user_type'] ?? '';

$currentPage = basename(__FILE__);

// Verify access (teachers and admins can mark attendance)
if (!in_array($userType, ['admin', 'teacher'])) {
    error_log("ERROR: User does not have attendance marking privileges");
    header('HTTP/1.1 403 Forbidden');
    echo "Access denied. Attendance marking privileges required.";
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
    
    // Include AttendanceManager
    $attendanceManagerPath = __DIR__ . '/../../../includes/AttendanceManager.php';
    if (file_exists($attendanceManagerPath)) {
        require_once $attendanceManagerPath;
    } else {
        throw new Exception("AttendanceManager.php not found at: " . $attendanceManagerPath);
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

// Initialize AttendanceManager
$attendanceManager = null;
if ($schoolDb) {
    $attendanceManager = new AttendanceManager($schoolDb, $school['id'], $userId, $userType, $school);
}

// Initialize variables
$settings = [];
$classes = [];
$sections = [];
$students = [];
$academicYears = [];
$message = '';
$error = '';
$adminUser = ['name' => 'Admin User', 'role_name' => 'Administrator'];

// Get filter parameters
$selectedClass = $_GET['class_id'] ?? '';
$selectedSection = $_GET['section_id'] ?? '';
$selectedDate = $_GET['attendance_date'] ?? date('Y-m-d');
$selectedAcademicYear = $_GET['academic_year'] ?? '';

// Fetch data from database
if ($schoolDb) {
    try {
        // Get school settings
        $settingsStmt = $schoolDb->prepare("SELECT * FROM settings WHERE school_id = ?");
        if ($settingsStmt) {
            $settingsStmt->execute([$school['id']]);
            $settingsRows = $settingsStmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($settingsRows as $row) {
                $settings[$row['key']] = $row['value'];
            }
        }

        // Get logged in user details with role information from user_roles table
        $userStmt = $schoolDb->prepare("
            SELECT u.*, GROUP_CONCAT(r.name) as role_names 
            FROM users u
            LEFT JOIN user_roles ur ON u.id = ur.user_id
            LEFT JOIN roles r ON ur.role_id = r.id
            WHERE u.id = ? AND u.school_id = ?
            GROUP BY u.id
        ");
        if ($userStmt) {
            $userStmt->execute([$userId, $school['id']]);
            $adminUserData = $userStmt->fetch(PDO::FETCH_ASSOC);
            if ($adminUserData) {
                $adminUser = $adminUserData;
                $adminUser['role_name'] = $adminUserData['role_names'] ?? $adminUserData['user_type'];
            } elseif (isset($_SESSION['school_user']['name'])) {
                $adminUser = [
                    'name' => $_SESSION['school_user']['name'],
                    'role_name' => 'Administrator'
                ];
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
            
            // Set default academic year if not selected
            if (empty($selectedAcademicYear)) {
                foreach ($academicYears as $year) {
                    if ($year['is_default'] == 1) {
                        $selectedAcademicYear = $year['id'];
                        break;
                    }
                }
            }
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

        // Get sections based on selected class
        if (!empty($selectedClass)) {
            $sectionStmt = $schoolDb->prepare("
                SELECT * FROM sections 
                WHERE school_id = ? AND class_id = ? AND is_active = 1
                ORDER BY name
            ");
            if ($sectionStmt) {
                $sectionStmt->execute([$school['id'], $selectedClass]);
                $sections = $sectionStmt->fetchAll(PDO::FETCH_ASSOC);
            }
        }

        // Get students with attendance using AttendanceManager
        if (!empty($selectedClass) && $attendanceManager) {
            $students = $attendanceManager->getStudentsWithAttendance(
                $selectedClass,
                $selectedSection ?: null,
                $selectedAcademicYear ?: null,
                $selectedDate
            );
        }

    } catch (Exception $e) {
        error_log("Error fetching data: " . $e->getMessage());
        $error = "Error loading data: " . $e->getMessage();
    }
}

// Handle form submission for bulk attendance
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_attendance']) && $attendanceManager) {
    
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        $error = "Invalid security token. Please try again.";
    } else {
        try {
            $attendanceDate = $_POST['attendance_date'] ?? date('Y-m-d');
            $classId = $_POST['class_id'] ?? '';
            $sectionId = $_POST['section_id'] ?? '';
            $academicYearId = $_POST['academic_year_id'] ?? '';
            $bulkStatus = $_POST['bulk_status'] ?? null;
            $attendanceData = $_POST['attendance'] ?? [];
            $remarks = $_POST['remarks'] ?? $_POST['notes'] ?? []; // Support both 'remarks' and 'notes'
            $selectedStudents = $_POST['selected_students'] ?? [];
            
            // If bulk status is set, create attendance data for selected students
            if (!empty($bulkStatus) && !empty($selectedStudents)) {
                $attendanceData = [];
                foreach ($selectedStudents as $studentId) {
                    $attendanceData[$studentId] = $bulkStatus;
                }
            }
            
            // Save attendance using AttendanceManager
            $result = $attendanceManager->saveAttendance(
                $attendanceData,
                $remarks,
                $attendanceDate,
                $classId,
                $sectionId,
                $academicYearId
            );
            
            if ($result['success']) {
                $message = $result['message'];
                
                // Redirect to refresh the page with filters
                $redirectUrl = "?class_id=" . urlencode($classId) . 
                              "&section_id=" . urlencode($sectionId) . 
                              "&academic_year=" . urlencode($academicYearId) . 
                              "&attendance_date=" . urlencode($attendanceDate);
                
                redirect($redirectUrl, 'success', $message);
            } else {
                $error = $result['message'];
            }
            
        } catch (Exception $e) {
            $error = "Error recording attendance: " . $e->getMessage();
            logError("Attendance error: " . $e->getMessage());
        }
    }
}

// Generate CSRF token
$csrfToken = generateCsrfToken();

// Handle AJAX request for sections
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['ajax']) && $_GET['ajax'] === 'get_sections') {
    header('Content-Type: application/json');
    
    $classId = $_GET['class_id'] ?? 0;
    $sections = [];
    
    if ($classId && $schoolDb) {
        $stmt = $schoolDb->prepare("
            SELECT id, name, capacity 
            FROM sections 
            WHERE school_id = ? AND class_id = ? AND is_active = 1
            ORDER BY name
        ");
        $stmt->execute([$school['id'], $classId]);
        $sections = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    echo json_encode(['success' => true, 'sections' => $sections]);
    exit;
}

// Get attendance config for display
$attendanceConfig = $attendanceManager ? $attendanceManager->getAttendanceConfig() : [];

// Format currency symbol
$currencySymbol = $settings['currency_symbol'] ?? '₦';

error_log("=== ATTENDANCE PAGE END ===");
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="description" content="Student Attendance Management - School Management System">
    <meta name="keywords" content="Student Attendance, Attendance Management, School Management">
    <meta name="robots" content="INDEX,FOLLOW">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Attendance - <?php echo htmlspecialchars($school['name']); ?></title>
    <link rel="icon" type="image/png" href="https://academixsuite.com/tenant/assets/images/favicon.png" sizes="16x16">
    <!-- remix icon font css  -->
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/remixicon.css">
    <!-- BootStrap css -->
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/lib/bootstrap.min.css">
    <!-- Apex Chart css -->
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/lib/apexcharts.css">
    <!-- Data Table css -->
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/lib/dataTables.min.css">
    <!-- Date picker css -->
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/lib/flatpickr.min.css">
    <!-- Calendar css -->
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/lib/full-calendar.css">
    <!-- calendar -->
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/lib/calendar.css">
    <!-- main css -->
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/style.css">
</head>

<body>

    <!-- Theme Customization Structure Start -->
    <div class="body-overlay"></div>

    <button type="button"
        class="theme-customization__button w-48-px h-48-px bg-primary-600 text-white rounded-circle d-flex justify-content-center align-items-center position-fixed end-0 bottom-0 mb-40 me-40 text-2xxl bg-hover-primary-700" aria-label="Theme Customization Button">
        <i class="ri-settings-3-line animate-spin"></i>
    </button>
    <div class="theme-customization-sidebar w-100 bg-base h-100vh overflow-y-auto position-fixed end-0 top-0">
        <div class="d-flex align-items-center gap-3 py-16 px-24 justify-content-between border-bottom">
            <div>
                <h6 class="text-sm dark:text-white">Theme Settings</h6>
                <p class="text-xs mb-0 text-neutral-500 dark:text-neutral-200">Customize and preview instantly</p>
            </div>
            <button data-slot="button"
                class="theme-customization-sidebar__close text-neutral-900 bg-transparent text-hover-primary-600 d-flex text-xl">
                <i class="ri-close-fill"></i>
            </button>
        </div>

        <div class="d-flex flex-column gap-48 p-24 overflow-y-auto flex-grow-1">
            <div class="theme-setting-item">
                <h6 class="fw-medium text-primary-light text-md mb-3">Theme Mode</h6>
                <div class="d-grid grid-cols-3 gap-3 dark-light-mode">
                    <button type="button"
                        class="theme-btn theme-setting-item__btn d-flex align-items-center justify-content-center h-64-px rounded-3 text-xl active"
                        data-theme="light" aria-label="light">
                        <i class="ri-sun-line"></i>
                    </button>
                    <button type="button"
                        class="theme-btn theme-setting-item__btn d-flex align-items-center justify-content-center h-64-px rounded-3 text-xl"
                        data-theme="dark" aria-label="dark">
                        <i class="ri-moon-line"></i>
                    </button>
                    <button type="button"
                        class="theme-btn theme-setting-item__btn d-flex align-items-center justify-content-center h-64-px rounded-3 text-xl"
                        data-theme="system" aria-label="system">
                        <i class="ri-computer-line"></i>
                    </button>
                </div>
            </div>

            <div class="theme-setting-item">
                <h6 class="fw-medium text-primary-light text-md mb-3">Color Schema</h6>
                <div class="d-grid grid-cols-3 gap-3">
                    <button type="button"
                        class="color-picker-btn d-flex flex-column justify-content-center align-items-center"
                        data-color="base" aria-label="Base">
                        <span class="color-picker-btn__box h-40-px w-100 rounded-3"
                            style="background-color: #25A194;"></span>
                        <span class="fw-medium mt-1" style="color: #25A194;">Base</span>
                    </button>
                    <button type="button"
                        class="color-picker-btn d-flex flex-column justify-content-center align-items-center"
                        data-color="red" aria-label="Red">
                        <span class="color-picker-btn__box h-40-px w-100 rounded-3"
                            style="background-color: #dc2626;"></span>
                        <span class="fw-medium mt-1" style="color: #dc2626;">Red</span>
                    </button>
                    <button type="button"
                        class="color-picker-btn d-flex flex-column justify-content-center align-items-center"
                        data-color="blue" aria-label="Blue">
                        <span class="color-picker-btn__box h-40-px w-100 rounded-3"
                            style="background-color: #2563eb;"></span>
                        <span class="fw-medium mt-1" style="color: #2563eb;">Blue</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
    <!-- Theme Customization Structure End -->

    <div class="overlay bg-black bg-opacity-50 w-100 h-100 position-fixed z-9 visibility-hidden opacity-0 duration-300">
    </div>
    <?php include_once('includes/sidebar.php'); ?>
<main class="dashboard-main">
        
        <?php include_once('includes/header.php'); ?>
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
                                <?php
                                // Get unread notification count using AttendanceManager if available
                                $unreadCount = 0;
                                if ($attendanceManager && $schoolDb) {
                                    $notifStmt = $schoolDb->prepare("SELECT COUNT(*) FROM notifications WHERE school_id = ? AND user_id = ? AND is_read = 0");
                                    $notifStmt->execute([$school['id'], $userId]);
                                    $unreadCount = $notifStmt->fetchColumn();
                                }
                                ?>
                                <?php if ($unreadCount > 0): ?>
                                <span class="w-8-px h-8-px bg-danger-600 position-absolute end-0 top-0 rounded-circle mt-2 me-2"></span>
                                <?php endif; ?>
                            </button>
                            <div class="dropdown-menu to-top dropdown-menu-lg p-0">
                                <div
                                    class="m-16 py-12 px-16 radius-8 bg-primary-50 mb-16 d-flex align-items-center justify-content-between gap-2">
                                    <div>
                                        <h6 class="text-lg text-primary-light fw-semibold mb-0">Notifications</h6>
                                    </div>
                                    <?php if ($unreadCount > 0): ?>
                                    <span
                                        class="text-primary-600 fw-semibold text-lg w-40-px h-40-px rounded-circle bg-base d-flex justify-content-center align-items-center"><?php echo $unreadCount; ?></span>
                                    <?php endif; ?>
                                </div>

                                <div class="max-h-400-px overflow-y-auto scroll-sm pe-4">
                                    <?php
                                    // Get recent notifications
                                    if ($schoolDb) {
                                        $notifStmt = $schoolDb->prepare("
                                            SELECT * FROM notifications 
                                            WHERE school_id = ? AND user_id = ? 
                                            ORDER BY created_at DESC LIMIT 5
                                        ");
                                        $notifStmt->execute([$school['id'], $userId]);
                                        $notifications = $notifStmt->fetchAll(PDO::FETCH_ASSOC);
                                        
                                        foreach ($notifications as $notification):
                                    ?>
                                    <a href="javascript:void(0)"
                                        class="px-24 py-12 d-flex align-items-start gap-3 mb-2 justify-content-between <?php echo !$notification['is_read'] ? 'bg-neutral-50' : ''; ?>">
                                        <div class="text-black hover-bg-transparent hover-text-primary d-flex align-items-center gap-3">
                                            <span
                                                class="w-44-px h-44-px bg-success-subtle text-success-main rounded-circle d-flex justify-content-center align-items-center flex-shrink-0">
                                                <iconify-icon icon="bitcoin-icons:verify-outline" class="icon text-xxl"></iconify-icon>
                                            </span>
                                            <div>
                                                <h6 class="text-md fw-semibold mb-4"><?php echo htmlspecialchars($notification['title']); ?></h6>
                                                <p class="mb-0 text-sm text-secondary-light text-w-200-px"><?php echo htmlspecialchars($notification['message']); ?></p>
                                            </div>
                                        </div>
                                        <span class="text-sm text-secondary-light flex-shrink-0"><?php echo timeAgo($notification['created_at']); ?></span>
                                    </a>
                                    <?php 
                                        endforeach;
                                    }
                                    ?>
                                </div>

                                <div class="text-center py-12 px-16">
                                    <a href="notifications.php" class="text-primary-600 fw-semibold text-md hover-underline">See All Notifications</a>
                                </div>
                            </div>
                        </div><!-- Notification dropdown end -->
                    </div>
                </div>
            </div>
        </div>

        <div class="dashboard-main-body">

            <div class="breadcrumb d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
                <div class="">
                    <h1 class="fw-semibold mb-4 h6 text-primary-light">Student Attendance</h1>
                    <div class="">
                        <a href="index.php" class="text-secondary-light hover-text-primary hover-underline">Dashboard</a>
                        <a href="student-list.php" class="text-secondary-light hover-text-primary hover-underline"> / Students</a>
                        <span class="text-secondary-light"> / Student Attendance</span>
                    </div>
                </div>
            </div>

            <?php if (!empty($message)): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>

            <?php if (!empty($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>

            <!-- Filter Section -->
            <div class="card mb-24">
                <div class="card-body">
                    <form method="GET" action="" class="row g-3">
                        <div class="col-md-3">
                            <label for="academic_year" class="form-label">Academic Year</label>
                            <select name="academic_year" id="academic_year" class="form-select">
                                <option value="">Select Academic Year</option>
                                <?php foreach ($academicYears as $year): ?>
                                <option value="<?php echo $year['id']; ?>" <?php echo $selectedAcademicYear == $year['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($year['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="class_id" class="form-label">Class</label>
                            <select name="class_id" id="class_id" class="form-select" required>
                                <option value="">Select Class</option>
                                <?php foreach ($classes as $class): ?>
                                <option value="<?php echo $class['id']; ?>" <?php echo $selectedClass == $class['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($class['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="section_id" class="form-label">Section</label>
                            <select name="section_id" id="section_id" class="form-select">
                                <option value="">All Sections</option>
                                <?php foreach ($sections as $section): ?>
                                <option value="<?php echo $section['id']; ?>" <?php echo $selectedSection == $section['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($section['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="attendance_date" class="form-label">Date</label>
                            <input type="date" name="attendance_date" id="attendance_date" class="form-control" value="<?php echo $selectedDate; ?>" required>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary-600">Load Students</button>
                            <button type="reset" class="btn btn-danger-200 text-danger-600">Reset</button>
                        </div>
                    </form>
                </div>
            </div>

            <?php if (!empty($students)): ?>
            <!-- Bulk Actions Bar -->
            <div class="card mb-24">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-md-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="selectAllStudents">
                                <label class="form-check-label" for="selectAllStudents">
                                    Select All Students
                                </label>
                            </div>
                        </div>
                        <div class="col-md-5">
                            <div class="d-flex gap-3">
                                <select class="form-select" id="bulkAttendanceStatus">
                                    <option value="">Bulk Set Status</option>
                                    <?php foreach ($attendanceConfig as $value => $config): ?>
                                    <option value="<?php echo $value; ?>" style="color: <?php echo $config['color']; ?>">
                                        <?php echo $config['label']; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="btn btn-primary-600" id="applyBulkStatus">Apply to Selected</button>
                            </div>
                        </div>
                        <div class="col-md-4 text-end">
                            <span class="text-secondary-light">Total Students: <strong><?php echo count($students); ?></strong></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Attendance Form -->
            <form method="POST" action="" id="attendanceForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="class_id" value="<?php echo $selectedClass; ?>">
                <input type="hidden" name="section_id" value="<?php echo $selectedSection; ?>">
                <input type="hidden" name="academic_year_id" value="<?php echo $selectedAcademicYear; ?>">
                <input type="hidden" name="attendance_date" value="<?php echo $selectedDate; ?>">
                <input type="hidden" name="bulk_status" id="bulk_status_input">
                
                <div class="mt-24">
                    <div class="card h-100">
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table bordered-table mb-0">
                                    <thead>
                                        <tr>
                                            <th scope="col" width="50">
                                                <div class="form-check style-check d-flex align-items-center">
                                                    <input class="form-check-input student-checkbox" type="checkbox">
                                                    <label class="form-check-label">#</label>
                                                </div>
                                            </th>
                                            <th scope="col">Admission No</th>
                                            <th scope="col">Student Name</th>
                                            <th scope="col">Class - Section</th>
                                            <th scope="col">Roll No</th>
                                            <th scope="col">Attendance</th>
                                            <th scope="col">Note</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($students as $index => $student): ?>
                                        <tr>
                                            <td>
                                                <div class="form-check style-check d-flex align-items-center">
                                                    <input class="form-check-input student-checkbox" type="checkbox" 
                                                           name="selected_students[]" value="<?php echo $student['user_id']; ?>"
                                                           id="student_<?php echo $student['user_id']; ?>">
                                                    <label class="form-check-label" for="student_<?php echo $student['user_id']; ?>">
                                                        <?php echo str_pad($index + 1, 2, '0', STR_PAD_LEFT); ?>
                                                    </label>
                                                </div>
                                            </td>
                                            <td><span class="text-primary-600"><?php echo htmlspecialchars($student['admission_number']); ?></span></td>
                                            <td>
                                                <div class="d-flex align-items-center flex-grow-1">
                                                    <img src="<?php echo htmlspecialchars($student['profile_photo'] ?? 'https://academixsuite.com/tenant/assets/images/thumbs/avatar-img1.png'); ?>"
                                                        alt="<?php echo htmlspecialchars($student['student_name']); ?>" class="flex-shrink-0 me-12 radius-8" style="width: 40px; height: 40px; object-fit: cover;">
                                                    <div class="">
                                                        <h6 class="text-md mb-0 fw-medium flex-grow-1"><?php echo htmlspecialchars($student['student_name']); ?></h6>
                                                        <?php if (!empty($student['parents'])): ?>
                                                        <small class="text-success">
                                                            <i class="ri-user-voice-line"></i> 
                                                            <?php echo count($student['parents']); ?> parent(s) linked
                                                        </small>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </td>
                                            <td><?php echo htmlspecialchars($student['class_name'] . ' (' . ($student['section_name'] ?? 'No Section') . ')'); ?></td>
                                            <td><?php echo htmlspecialchars($student['roll_number'] ?? 'N/A'); ?></td>
                                            <td>
                                                <div class="d-flex align-items-center flex-wrap gap-2">
                                                    <?php foreach ($attendanceConfig as $value => $config): 
                                                        $checked = ($student['attendance_status'] ?? '') == $value ? 'checked' : '';
                                                    ?>
                                                    <div class="form-check checked-primary d-flex align-items-center gap-1">
                                                        <input class="form-check-input attendance-radio" type="radio" 
                                                               name="attendance[<?php echo $student['user_id']; ?>]" 
                                                               value="<?php echo $value; ?>" 
                                                               id="attendance_<?php echo $student['user_id']; ?>_<?php echo $value; ?>"
                                                               <?php echo $checked; ?>>
                                                        <label class="form-check-label small" 
                                                               for="attendance_<?php echo $student['user_id']; ?>_<?php echo $value; ?>"
                                                               style="color: <?php echo $config['color']; ?>">
                                                            <?php echo $config['label']; ?>
                                                        </label>
                                                    </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <input type="text" class="form-control form-control-sm" 
                                                       name="notes[<?php echo $student['user_id']; ?>]" 
                                                       placeholder="Note..."
                                                       value="<?php echo htmlspecialchars($student['attendance_note'] ?? ''); ?>">
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-24 text-center">
                    <button type="reset" class="border border-danger-600 bg-hover-danger-200 text-danger-600 text-md px-50 py-11 radius-8 me-3">
                        Cancel
                    </button>
                    <button type="submit" name="submit_attendance" class="btn btn-primary-600 border border-primary-600 text-md px-28 py-12 radius-8">
                        Save Attendance
                    </button>
                </div>
            </form>
            <?php elseif ($_SERVER['REQUEST_METHOD'] === 'GET' && !empty($_GET['class_id'])): ?>
            <div class="alert alert-warning">
                No students found for the selected criteria.
            </div>
            <?php endif; ?>
        </div>

        <footer class="d-footer">
            <div class="">
                <p class="mb-0 text-center"> &copy; <span class="current-year"></span> <?php echo htmlspecialchars($school['name']); ?> | Made With ❤️ by AcademixSuite.</p>
            </div>
        </footer>
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
            // Load sections when class changes
            $('#class_id').on('change', function() {
                var classId = $(this).val();
                if (classId) {
                    $.get(window.location.pathname, {
                        ajax: 'get_sections',
                        class_id: classId
                    }, function(response) {
                        if (response.success) {
                            var options = '<option value="">All Sections</option>';
                            $.each(response.sections, function(index, section) {
                                options += '<option value="' + section.id + '">' + section.name + 
                                         (section.capacity ? ' (Capacity: ' + section.capacity + ')' : '') + '</option>';
                            });
                            $('#section_id').html(options);
                        }
                    }, 'json');
                } else {
                    $('#section_id').html('<option value="">All Sections</option>');
                }
            });

            // Select All functionality
            $('#selectAllStudents').on('change', function() {
                $('.student-checkbox').prop('checked', $(this).prop('checked'));
            });

            // Update Select All checkbox when individual checkboxes change
            $('.student-checkbox').on('change', function() {
                var allChecked = $('.student-checkbox:checked').length === $('.student-checkbox').length;
                $('#selectAllStudents').prop('checked', allChecked);
            });

            // Bulk apply status to selected students
            $('#applyBulkStatus').on('click', function() {
                var status = $('#bulkAttendanceStatus').val();
                var selectedStudents = $('.student-checkbox:checked').map(function() {
                    return $(this).val();
                }).get();

                if (selectedStudents.length === 0) {
                    alert('Please select at least one student.');
                    return;
                }

                if (!status) {
                    alert('Please select a status to apply.');
                    return;
                }

                // Apply status to all selected students
                selectedStudents.forEach(function(studentId) {
                    $('input[name="attendance[' + studentId + ']"]').each(function() {
                        if ($(this).val() === status) {
                            $(this).prop('checked', true);
                        }
                    });
                });

                // Show success message
                toastr.success('Applied ' + status + ' to ' + selectedStudents.length + ' students');
            });

            // Auto-dismiss alerts after 5 seconds
            setTimeout(function() {
                $('.alert').fadeOut('slow');
            }, 5000);

            // Form validation
            $('#attendanceForm').on('submit', function(e) {
                var hasAttendance = $('.attendance-radio:checked').length > 0;
                
                if (!hasAttendance) {
                    if (!confirm('No attendance records have been marked. Do you want to continue?')) {
                        e.preventDefault();
                        return false;
                    }
                }
                
                return true;
            });

            // Highlight rows with missing attendance
            $('tr').each(function() {
                var row = $(this);
                var hasChecked = row.find('.attendance-radio:checked').length > 0;
                
                if (!hasChecked && row.find('.attendance-radio').length > 0) {
                    row.addClass('bg-warning bg-opacity-10');
                }
            });

            $('.attendance-radio').on('change', function() {
                var row = $(this).closest('tr');
                var hasChecked = row.find('.attendance-radio:checked').length > 0;
                
                if (hasChecked) {
                    row.removeClass('bg-warning bg-opacity-10');
                } else {
                    row.addClass('bg-warning bg-opacity-10');
                }
            });

            // Add tooltips for parent info
            $('[data-toggle="tooltip"]').tooltip();
        });
    </script>

    <?php
    // Helper function for time ago
    function timeAgo($datetime) {
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
    ?>
</body>
</html>