<?php
/**
 * Student Attendance Management System
 * Fixed version with proper form submission and select-all functionality
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
if (!defined('IS_LOCAL')) define('IS_LOCAL', true);

// Start session safely
if (function_exists('session_status') && session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_lifetime' => 86400,
        'read_and_close'  => false,
    ]);
}

// Get school slug from GLOBALS (set by router.php)
$schoolSlug = $GLOBALS['SCHOOL_SLUG'] ?? '';
$userType = $GLOBALS['USER_TYPE'] ?? 'admin';
$currentPage = $GLOBALS['CURRENT_PAGE'] ?? 'student-attendance.php';
$schoolData = $GLOBALS['SCHOOL_DATA'] ?? [];
$baseUrl = $GLOBALS['BASE_URL'] ?? '';

// Validate school slug
if (empty($schoolSlug)) {
    error_log("ERROR: Empty school slug from router");
    header('HTTP/1.1 400 Bad Request');
    exit('School identifier missing');
}

// Get school info from session or GLOBALS
$school = !empty($schoolData) ? $schoolData : ($_SESSION['school_info'][$schoolSlug] ?? []);

if (empty($school)) {
    error_log("ERROR: School data not found for slug: " . $schoolSlug);
    header("Location: ../../login.php?school_slug=" . urlencode($schoolSlug));
    exit;
}

// Check authentication
$isAuthenticated = isset($_SESSION['school_auth']) &&
                   is_array($_SESSION['school_auth']) &&
                   $_SESSION['school_auth']['school_slug'] === $schoolSlug;

if (!$isAuthenticated) {
    error_log("User not authenticated, redirecting to login");
    header('Location: ../../login.php?school_slug=' . urlencode($schoolSlug));
    exit;
}

// Get user info from session
$schoolAuth = $_SESSION['school_auth'];
$userId = $schoolAuth['user_id'] ?? 0;
$userType = $schoolAuth['user_type'] ?? '';

// Verify access (teachers and admins can mark attendance)
if (!in_array($userType, ['admin', 'teacher'])) {
    error_log("ERROR: User does not have attendance marking privileges");
    header('HTTP/1.1 403 Forbidden');
    exit('Access denied. Attendance marking privileges required.');
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

    // Include AttendanceManager
    $attendanceManagerPath = __DIR__ . '/../../../includes/AttendanceManager.php';
    if (file_exists($attendanceManagerPath)) {
        require_once $attendanceManagerPath;
    } else {
        throw new Exception("AttendanceManager.php not found");
    }
} catch (Exception $e) {
    error_log("Error loading files: " . $e->getMessage());
    http_response_code(500);
    die("Configuration loading failed. Please contact administrator.");
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
    die("Database connection failed. Please try again later.");
}

// Initialize AttendanceManager with school data (for email sender name)
$attendanceManager = new AttendanceManager($schoolDb, $school['id'], $userId, $userType, $school);

// Initialize variables
$settings = [];
$classes = [];
$sections = [];
$students = [];
$academicYears = [];
$adminUser = ['name' => 'Admin User', 'role_name' => 'Administrator'];

// Get filter parameters
$selectedClass = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
$selectedSection = isset($_GET['section_id']) ? (int)$_GET['section_id'] : 0;
$selectedDate = isset($_GET['attendance_date']) ? $_GET['attendance_date'] : date('Y-m-d');
$selectedAcademicYear = isset($_GET['academic_year']) ? (int)$_GET['academic_year'] : 0;

/**
 * Generate CSRF token
 * @return string
 */
if (!function_exists('generateCsrfToken')) {
    function generateCsrfToken() {
        if (!isset($_SESSION['csrf_tokens'])) {
            $_SESSION['csrf_tokens'] = [];
        }
        $token = bin2hex(random_bytes(32));
        $_SESSION['csrf_tokens'][$token] = time() + 3600;
        return $token;
    }
}

/**
 * Validate CSRF token
 * @param string $token
 * @return bool
 */
if (!function_exists('validateCsrfToken')) {
    function validateCsrfToken($token) {
        if (!isset($_SESSION['csrf_tokens'][$token])) {
            return false;
        }
        if ($_SESSION['csrf_tokens'][$token] < time()) {
            unset($_SESSION['csrf_tokens'][$token]);
            return false;
        }
        unset($_SESSION['csrf_tokens'][$token]);
        return true;
    }
}

// Handle AJAX request for sections
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_sections') {
    header('Content-Type: application/json');

    $classId = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
    $sections = [];

    if ($classId > 0 && $schoolDb) {
        try {
            $stmt = $schoolDb->prepare("
                SELECT id, name, capacity
                FROM sections
                WHERE school_id = ? AND class_id = ? AND is_active = 1
                ORDER BY name
            ");
            $stmt->execute([$school['id'], $classId]);
            $sections = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error fetching sections: " . $e->getMessage());
        }
    }

    echo json_encode(['success' => true, 'sections' => $sections]);
    exit;
}

// Fetch data from database
if ($schoolDb) {
    try {
        // Get school settings
        $settingsStmt = $schoolDb->prepare("SELECT * FROM settings WHERE school_id = ?");
        if ($settingsStmt) {
            $settingsStmt->execute([$school['id']]);
            while ($row = $settingsStmt->fetch(PDO::FETCH_ASSOC)) {
                $settings[$row['key']] = $row['value'];
            }
        }

        // Get logged in user details
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
                $adminUser['profile_photo'] = $adminUserData['profile_photo'] ?? 'https://academixsuite.com/tenant/assets/images/thumbs/avatar-img1.png';
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
            if (empty($selectedAcademicYear) && !empty($academicYears)) {
                foreach ($academicYears as $year) {
                    if ($year['is_default'] == 1) {
                        $selectedAcademicYear = $year['id'];
                        break;
                    }
                }
                if (empty($selectedAcademicYear)) {
                    $selectedAcademicYear = $academicYears[0]['id'];
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
        if ($selectedClass > 0) {
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

        // Get students with attendance - using the manager method
        if ($selectedClass > 0) {
            $students = $attendanceManager->getStudentsWithAttendance(
                $selectedClass,
                $selectedSection ?: null,
                $selectedAcademicYear ?: null,
                $selectedDate
            );
            error_log("Found " . count($students) . " students");
        }

    } catch (Exception $e) {
        error_log("Error fetching data: " . $e->getMessage());
        $_SESSION['attendance_error'] = "Error loading data: " . $e->getMessage();
    }
}

// Handle form submission for bulk attendance
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_attendance']) && $schoolDb) {

    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        $_SESSION['attendance_error'] = "Invalid security token. Please try again.";
        error_log("CSRF validation failed for attendance submission");
    } else {
        try {
            $attendanceDate = $_POST['attendance_date'] ?? date('Y-m-d');
            $classId = (int)($_POST['class_id'] ?? 0);
            $sectionId = isset($_POST['section_id']) ? (int)$_POST['section_id'] : 0;
            $academicYearId = (int)($_POST['academic_year_id'] ?? 0);
            $attendanceData = $_POST['attendance'] ?? [];
            $remarks = $_POST['notes'] ?? [];

            if (empty($attendanceData)) {
                $_SESSION['attendance_error'] = "No attendance data submitted.";
                error_log("Attendance submission with empty data");
            } else {
                // Use the manager to save attendance (includes notifications)
                $result = $attendanceManager->saveAttendance(
                    $attendanceData,
                    $remarks,
                    $attendanceDate,
                    $classId,
                    $sectionId ?: null,
                    $academicYearId ?: null
                );

                if ($result['success']) {
                    $_SESSION['attendance_success'] = $result['message'];
                    error_log("Attendance saved successfully: " . $result['message']);
                } else {
                    $_SESSION['attendance_error'] = $result['message'];
                    error_log("Attendance save failed: " . $result['message']);
                }
            }

        } catch (Exception $e) {
            error_log("Attendance error: " . $e->getMessage());
            $_SESSION['attendance_error'] = "Error recording attendance: " . $e->getMessage();
        }
    }

    // Redirect back to refresh (prevents resubmission)
    $redirectUrl = "?class_id=" . urlencode($selectedClass) .
                  "&section_id=" . urlencode($selectedSection) .
                  "&academic_year=" . urlencode($selectedAcademicYear) .
                  "&attendance_date=" . urlencode($selectedDate);
    header("Location: " . $redirectUrl);
    exit;
}

// Get messages from session
$successMessage = $_SESSION['attendance_success'] ?? '';
$errorMessage = $_SESSION['attendance_error'] ?? '';
unset($_SESSION['attendance_success'], $_SESSION['attendance_error']);

// Generate CSRF token
$csrfToken = generateCsrfToken();

// Get unread notification count
$unreadCount = 0;
$notifications = [];
if ($schoolDb) {
    try {
        $notifStmt = $schoolDb->prepare("
            SELECT COUNT(*) FROM notifications
            WHERE school_id = ? AND user_id = ? AND is_read = 0
        ");
        $notifStmt->execute([$school['id'], $userId]);
        $unreadCount = $notifStmt->fetchColumn();

        $notifStmt = $schoolDb->prepare("
            SELECT * FROM notifications
            WHERE school_id = ? AND user_id = ?
            ORDER BY created_at DESC LIMIT 5
        ");
        $notifStmt->execute([$school['id'], $userId]);
        $notifications = $notifStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error fetching notifications: " . $e->getMessage());
    }
}

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
    <!-- Toastr CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.css">
    <style>
        /* Additional Attendance Styles */
        .attendance-radio-group {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .form-check.checked-primary {
            margin-bottom: 0;
            min-width: 70px;
        }

        .form-check.checked-primary .form-check-input:checked {
            background-color: #25A194;
            border-color: #25A194;
        }

        .form-check.checked-primary .form-check-input:focus {
            border-color: #25A194;
            box-shadow: 0 0 0 0.2rem rgba(37, 161, 148, 0.25);
        }

        .student-photo {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            object-fit: cover;
            margin-right: 12px;
        }

        .table-warning {
            background-color: #fff3cd !important;
        }

        .filter-card {
            background: #fff;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 24px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.03);
        }

        /* DataTable customization */
        .dataTable-wrapper {
            border: 1px solid #e9ecef;
            border-radius: 12px;
            overflow: hidden;
        }

        .dataTable-wrapper .dt-search .dt-input {
            min-width: 250px;
            padding: 8px 16px;
            border: 1px solid #e9ecef;
            border-radius: 30px;
            font-size: 14px;
        }

        .dataTable-wrapper .dt-search .dt-input:focus {
            outline: none;
            border-color: #25A194;
            box-shadow: 0 0 0 3px rgba(37, 161, 148, 0.1);
        }

        .dataTable-wrapper .dt-length .dt-input {
            padding: 6px 12px;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            margin-left: 8px;
        }

        .dataTable-wrapper .dt-length .dt-input:focus {
            outline: none;
            border-color: #25A194;
        }

        /* Status badges */
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 30px;
            font-size: 12px;
            font-weight: 500;
        }

        .status-present {
            background: #d4edda;
            color: #155724;
        }

        .status-absent {
            background: #f8d7da;
            color: #721c24;
        }

        .status-late {
            background: #fff3cd;
            color: #856404;
        }

        .status-half-day {
            background: #ffe5d0;
            color: #fd7e14;
        }

        .status-holiday {
            background: #e9ecef;
            color: #495057;
        }

        /* Loading spinner */
        .spinner-border-sm {
            width: 1rem;
            height: 1rem;
            border-width: 0.2em;
        }

        /* Bulk actions bar */
        .bulk-actions-bar {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 16px 20px;
            margin-bottom: 24px;
            border: 1px solid #e9ecef;
        }

        /* Filter dropdown */
        .filter-dropdown-menu {
            width: 320px;
            padding: 0;
        }

        /* Marked badge */
        .marked-badge {
            background-color: #d4edda;
            color: #155724;
            font-size: 0.75rem;
            padding: 2px 8px;
            border-radius: 30px;
            margin-left: 8px;
            font-weight: 500;
            display: inline-block;
        }
    </style>
</head>
<body>
    <!-- Theme Customization Structure Start -->
    <div class="body-overlay"></div>

    <button type="button"
        class="theme-customization__button w-48-px h-48-px bg-primary-600 text-white rounded-circle d-flex justify-content-center align-items-center position-fixed end-0 bottom-0 mb-40 me-40 text-2xxl bg-hover-primary-700" aria-label="Theme Customization Button">
        <i class="ri-settings-3-line animate-spin"></i>
    </button>

    <!-- Theme Customization Structure End -->

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
                        <form class="navbar-search" method="GET" action="">
                            <input type="text" class="bg-transparent" name="search" placeholder="Search students...">
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
                                        class="text-primary-600 fw-semibold text-lg w-40-px h-40-px rounded-circle bg-base d-flex justify-content-center align-items-center"><?php echo str_pad($unreadCount, 2, '0', STR_PAD_LEFT); ?></span>
                                    <?php endif; ?>
                                </div>

                                <div class="max-h-400-px overflow-y-auto scroll-sm pe-4">
                                    <?php if (empty($notifications)): ?>
                                    <div class="px-24 py-12 text-center text-secondary-light">
                                        <i class="ri-inbox-line fs-2 d-block mb-2"></i>
                                        <p class="mb-0">No notifications</p>
                                    </div>
                                    <?php else: ?>
                                        <?php foreach ($notifications as $notification): ?>
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
                                            <span class="text-sm text-secondary-light flex-shrink-0"><?php echo $attendanceManager->timeAgo($notification['created_at']); ?></span>
                                        </a>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
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

            <!-- Filter Section -->
            <div class="filter-card">
                <form method="GET" action="" id="filterForm">
                    <div class="row g-4">
                        <div class="col-md-3">
                            <label for="academic_year" class="form-label fw-semibold text-primary-light">Academic Year</label>
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
                            <label for="class_id" class="form-label fw-semibold text-primary-light">Class <span class="text-danger">*</span></label>
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
                            <label for="section_id" class="form-label fw-semibold text-primary-light">Section</label>
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
                            <label for="attendance_date" class="form-label fw-semibold text-primary-light">Date <span class="text-danger">*</span></label>
                            <input type="date" name="attendance_date" id="attendance_date" class="form-control" value="<?php echo $selectedDate; ?>" required>
                        </div>
                        <div class="col-12 text-end">
                            <button type="submit" class="btn btn-primary-600 px-5">
                                <i class="ri-filter-3-line me-1"></i>Load Students
                            </button>
                            <a href="?" class="btn btn-light px-5">
                                <i class="ri-refresh-line me-1"></i>Reset
                            </a>
                        </div>
                    </div>
                </form>
            </div>

            <?php if (!empty($students)): ?>

            <!-- Attendance Summary -->
            <?php
            $stats = [
                'present' => 0,
                'absent' => 0,
                'late' => 0,
                'half_day' => 0,
                'holiday' => 0,
                'unmarked' => 0
            ];

            foreach ($students as $student) {
                if (empty($student['attendance_status'])) {
                    $stats['unmarked']++;
                } else {
                    $stats[$student['attendance_status']] = ($stats[$student['attendance_status']] ?? 0) + 1;
                }
            }
            ?>
            <!-- Bulk Actions Bar -->
            <div class="bulk-actions-bar">
                <div class="row align-items-center">
                    <div class="col-md-5">
                        <div class="form-check style-check d-flex align-items-center">
                            <input class="form-check-input" type="checkbox" id="selectAllStudents">
                            <label class="form-check-label fw-semibold" for="selectAllStudents">
                                Select All Students
                            </label>
                        </div>
                    </div>
                    <div class="col-md-7">
                        <div class="d-flex gap-3">
                            <select class="form-select" id="bulkAttendanceStatus" style="max-width: 200px;">
                                <option value="">Bulk Set Status</option>
                                <option value="present">Present</option>
                                <option value="absent">Absent</option>
                                <option value="late">Late</option>
                                <option value="half_day">Half Day</option>
                                <option value="holiday">Holiday</option>
                            </select>
                            <button type="button" class="btn btn-primary-600" id="applyBulkStatus">
                                <i class="ri-check-double-line me-1"></i>Apply to Selected
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Attendance Form -->
            <form method="POST" action="" id="attendanceForm">
                <input type="hiddeun" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hiddeun" name="class_id" value="<?php echo $selectedClass; ?>">
                <input type="hiddeun" name="section_id" value="<?php echo $selectedSection; ?>">
                <input type="hiddeun" name="academic_year_id" value="<?php echo $selectedAcademicYear; ?>">
                <input type="hidden" name="attendance_date" value="<?php echo $selectedDate; ?>">

                <div class="mt-24">
                    <div class="card h-100">
                        <div class="card-body p-0 dataTable-wrapper">
                            <div class="d-flex align-items-center justify-content-between flex-wrap gap-16 px-20 py-12 border-bottom border-neutral-200">
                                <div class="d-flex flex-wrap align-items-center gap-16">
                                    <div class="dropdown">
                                        <button type="button"
                                            class="px-12 py-5-px border border-neutral-300 radius-8 d-flex align-items-center gap-20"
                                            data-bs-toggle="dropdown" aria-expanded="false">
                                            <span class="d-flex align-items-center gap-1 text-secondary-light text-sm">
                                                <i class="ri-file-upload-line text-md line-height-1"></i>
                                                Export
                                            </span>
                                            <span class="">
                                                <i class="ri-arrow-down-s-line"></i>
                                            </span>
                                        </button>
                                        <ul class="dropdown-menu p-12 border bg-base shadow">
                                            <li>
                                                <button type="button"
                                                    class="dropdown-item px-16 py-8 rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-10">
                                                    <i class="ri-file-pdf-line"></i>
                                                    PDF
                                                </button>
                                            </li>
                                            <li>
                                                <button type="button"
                                                    class="dropdown-item px-16 py-8 rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-10">
                                                    <i class="ri-file-excel-line"></i>
                                                    Excel
                                                </button>
                                            </li>
                                        </ul>
                                    </div>
                                    <form class="navbar-search dt-search m-0">
                                        <input type="text" class="dt-input bg-transparent radius-4" aria-controls="dataTable"
                                            name="search" placeholder="Search...">
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

                            <div class="p-0">
                                <table class="table bordered-table mb-0 data-table" id="attendanceTable" data-page-length='10'>
                                    <thead>
                                        <tr>
                                            <th scope="col" width="50">
                                                <div class="form-check style-check d-flex align-items-center">
                                                    <input class="form-check-input" type="checkbox" id="selectAllCheckbox">
                                                    <label class="form-check-label" for="selectAllCheckbox">S.L</label>
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
                                        <?php foreach ($students as $index => $student):
                                            $hasAttendance = !empty($student['attendance_status']);
                                            $rowClass = !$hasAttendance ? 'table-warning' : '';
                                        ?>
                                        <tr data-student-id="<?php echo $student['user_id']; ?>" class="<?php echo $rowClass; ?>">
                                            <td>
                                                <div class="form-check style-check d-flex align-items-center">
                                                    <input class="form-check-input row-checkbox" type="checkbox"
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
                                                        alt="<?php echo htmlspecialchars($student['student_name']); ?>" class="student-photo">
                                                    <div class="">
                                                        <h6 class="text-md mb-0 fw-medium d-flex align-items-center">
                                                            <?php echo htmlspecialchars($student['student_name']); ?>
                                                            <?php if ($hasAttendance): ?>
                                                                <span class="marked-badge ms-2">
                                                                    <i class="ri-check-line"></i> Marked
                                                                </span>
                                                            <?php endif; ?>
                                                        </h6>
                                                        <?php if (!empty($student['parents'])): ?>
                                                        <small class="text-success">
                                                            <i class="ri-user-voice-line"></i>
                                                            <?php echo count($student['parents']); ?> parent(s)
                                                        </small>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <?php echo htmlspecialchars($student['class_name']); ?>
                                                <?php if (!empty($student['section_name'])): ?>
                                                <span class="badge bg-light text-dark ms-1"><?php echo htmlspecialchars($student['section_name']); ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($student['roll_number'] ?? 'N/A'); ?></td>
                                            <td>
                                                <div class="attendance-radio-group">
                                                    <?php
                                                    $attendanceOptions = [
                                                        'present' => 'Present',
                                                        'late' => 'Late',
                                                        'absent' => 'Absent',
                                                        'half_day' => 'Halfday',
                                                        'holiday' => 'Holiday'
                                                    ];
                                                    foreach ($attendanceOptions as $value => $label):
                                                        $checked = ($student['attendance_status'] ?? '') == $value ? 'checked' : '';
                                                        $id = 'att_' . $student['user_id'] . '_' . $value;
                                                    ?>
                                                    <div class="form-check checked-primary d-flex align-items-center gap-2">
                                                        <input class="form-check-input" type="radio"
                                                               name="attendance[<?php echo $student['user_id']; ?>]"
                                                               value="<?php echo $value; ?>"
                                                               id="<?php echo $id; ?>"
                                                               <?php echo $checked; ?>>
                                                        <label class="form-check-label" for="<?php echo $id; ?>"><?php echo $label; ?></label>
                                                    </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <input type="text" class="form-control form-control-sm"
                                                       name="notes[<?php echo $student['user_id']; ?>]"
                                                       placeholder="Write note..."
                                                       value="<?php echo htmlspecialchars($student['attendance_note'] ?? ''); ?>"
                                                       style="min-width: 150px;">
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
                        <i class="ri-close-line me-1"></i>Cancel
                    </button>
                    <button type="submit" name="submit_attendance" class="btn btn-primary-600 border border-primary-600 text-md px-28 py-12 radius-8">
                        <i class="ri-save-line me-1"></i>Save Attendance
                    </button>
                </div>
            </form>
            <?php elseif ($selectedClass > 0): ?>
            <!-- No Students Found -->
            <div class="card">
                <div class="card-body text-center py-5">
                    <div class="mb-4">
                        <i class="ri-user-search-line" style="font-size: 64px; color: #cbd5e0;"></i>
                    </div>
                    <h4 class="mb-3">No Students Found</h4>
                    <p class="text-muted mb-4">No active students found for the selected class and section.</p>
                    <a href="add-new-student.php" class="btn btn-primary-600">
                        <i class="ri-add-line me-1"></i>Add New Student
                    </a>
                </div>
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
    <!-- Toastr js -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js"></script>
    <!-- main js -->
    <script src="https://academixsuite.com/tenant/assets/js/app.js"></script>

    <script>
        (function($) {
            'use strict';

            // Toastr configuration
            toastr.options = {
                "closeButton": true,
                "progressBar": true,
                "positionClass": "toast-top-right",
                "showDuration": "300",
                "hideDuration": "1000",
                "timeOut": "5000"
            };

            $(document).ready(function() {
                // Display any session messages via Toastr
                <?php if (!empty($successMessage)): ?>
                toastr.success('<?php echo addslashes($successMessage); ?>', 'Success');
                <?php endif; ?>

                <?php if (!empty($errorMessage)): ?>
                toastr.error('<?php echo addslashes($errorMessage); ?>', 'Error');
                <?php endif; ?>

                // Initialize DataTable
                var table = $('#attendanceTable').DataTable({
                    pageLength: 10,
                    lengthMenu: [[5, 10, 25, 50, 100], [5, 10, 25, 50, 100]],
                    ordering: true,
                    searching: true,
                    info: true,
                    paging: true,
                    columnDefs: [
                        { orderable: false, targets: [0, 5, 6] }
                    ],
                    drawCallback: function() {
                        // After table redraw (pagination, search), sync the select-all checkbox state
                        var allChecked = $('.row-checkbox:checked').length === $('.row-checkbox').length;
                        $('#selectAllCheckbox').prop('checked', allChecked);
                    }
                });

                // Handle search input
                $('.dt-search .dt-input').on('keyup', function() {
                    table.search(this.value).draw();
                });

                // Handle page length change
                $('.dt-length .dt-input').on('change', function() {
                    var value = $(this).val();
                    table.page.len(value).draw();
                });

                // Load sections when class changes
                $('#class_id').on('change', function() {
                    var classId = $(this).val();
                    if (classId) {
                        $.ajax({
                            url: window.location.pathname,
                            type: 'GET',
                            data: {
                                ajax: 'get_sections',
                                class_id: classId
                            },
                            dataType: 'json',
                            beforeSend: function() {
                                $('#section_id').prop('disabled', true);
                            },
                            success: function(response) {
                                var options = '<option value="">All Sections</option>';
                                if (response.success && response.sections.length > 0) {
                                    $.each(response.sections, function(index, section) {
                                        options += '<option value="' + section.id + '">' +
                                                  section.name +
                                                  (section.capacity ? ' (Capacity: ' + section.capacity + ')' : '') +
                                                  '</option>';
                                    });
                                }
                                $('#section_id').html(options).prop('disabled', false);
                            },
                            error: function() {
                                toastr.error('Failed to load sections');
                                $('#section_id').prop('disabled', false);
                            }
                        });
                    } else {
                        $('#section_id').html('<option value="">All Sections</option>');
                    }
                });

                // Select All functionality using DataTable API
                $('#selectAllCheckbox').on('change', function() {
                    var isChecked = $(this).prop('checked');
                    // Get all rows in the current filtered result (including hidden pages)
                    var allPageRows = table.rows({ search: 'applied' }).nodes();
                    $(allPageRows).find('.row-checkbox').prop('checked', isChecked);
                    updateBulkActionButton();
                });

                // Update Select All checkbox when individual checkboxes change
                $(document).on('change', '.row-checkbox', function() {
                    var totalRows = table.rows({ search: 'applied' }).count();
                    var checkedRows = table.rows({ search: 'applied' }).nodes().filter(function() {
                        return $(this).find('.row-checkbox').prop('checked');
                    }).length;
                    $('#selectAllCheckbox').prop('checked', totalRows === checkedRows);
                    updateBulkActionButton();
                });

                // Also the external "Select All Students" checkbox (outside table)
                $('#selectAllStudents').on('change', function() {
                    $('#selectAllCheckbox').prop('checked', $(this).prop('checked')).trigger('change');
                });

                function updateBulkActionButton() {
                    var count = $('.row-checkbox:checked').length;
                    var button = $('#applyBulkStatus');
                    if (count > 0) {
                        button.html('<i class="ri-check-double-line me-1"></i>Apply to ' + count + ' Selected');
                    } else {
                        button.html('<i class="ri-check-double-line me-1"></i>Apply to Selected');
                    }
                }

                // Bulk apply status to selected students
                $('#applyBulkStatus').on('click', function() {
                    var status = $('#bulkAttendanceStatus').val();
                    var selectedCheckboxes = $('.row-checkbox:checked');
                    var selectedStudents = [];

                    selectedCheckboxes.each(function() {
                        var studentId = $(this).closest('tr').data('student-id');
                        selectedStudents.push(studentId);
                    });

                    if (selectedStudents.length === 0) {
                        toastr.warning('Please select at least one student.');
                        return;
                    }

                    if (!status) {
                        toastr.warning('Please select a status to apply.');
                        return;
                    }

                    // Apply status to all selected students
                    selectedStudents.forEach(function(studentId) {
                        $('input[name="attendance[' + studentId + ']"][value="' + status + '"]').prop('checked', true);
                    });

                    var statusText = $('#bulkAttendanceStatus option:selected').text();
                    toastr.success('Applied ' + statusText + ' to ' + selectedStudents.length + ' students');

                    // Clear selection
                    $('.row-checkbox').prop('checked', false);
                    $('#selectAllCheckbox').prop('checked', false);
                    $('#selectAllStudents').prop('checked', false);
                    updateBulkActionButton();
                });

                // Form validation and loader
                $('#attendanceForm').on('submit', function(e) {
                    var hasAttendance = $('input[type="radio"]:checked').length > 0;

                    if (!hasAttendance) {
                        if (!confirm('No attendance records have been marked. Do you want to continue?')) {
                            e.preventDefault();
                            return false;
                        }
                    }

                    // Show loader on submit button
                    var $btn = $(this).find('button[type="submit"]');
                    $btn.prop('disabled', true);
                    $btn.html('<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Saving...');

                    return true; // Allow form submission
                });

                // Filter dropdown close button
                $('.btn-close').on('click', function() {
                    $(this).closest('.dropdown-menu').removeClass('show');
                });

                // Reset button functionality
                $('button[type="reset"]').on('click', function(e) {
                    e.preventDefault();
                    if (confirm('Are you sure you want to reset all changes?')) {
                        $('input[type="radio"]').prop('checked', false);
                        $('input[type="text"]').val('');
                        toastr.info('All changes have been reset');
                    }
                });
            });

        })(jQuery);

        // Update current year
        document.addEventListener('DOMContentLoaded', function() {
            var yearElements = document.getElementsByClassName('current-year');
            for (var i = 0; i < yearElements.length; i++) {
                yearElements[i].textContent = new Date().getFullYear();
            }
        });
    </script>
</body>
</html>
