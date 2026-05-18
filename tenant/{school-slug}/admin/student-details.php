<?php

/**
 * School Student Details Page
 * Displays comprehensive student information including personal details, attendance, fees, etc.
 */

// Enable error reporting
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../../logs/school_student_details.log');

error_log("=== STUDENT DETAILS PAGE START ===");
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

// Initialize variables
$student = null;
$guardians = [];
$attendanceStats = [];
$attendanceRecords = [];
$feeStats = [];
$feeRecords = [];
$examResults = [];
$libraryRecords = [];
$leaveRecords = [];
$settings = [];
$adminUser = ['name' => 'Admin User', 'role_name' => 'Administrator'];
$error = '';
$message = '';

// Fetch student data from database
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

        // Get logged in user details with role information
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

        // Get student main details
        $studentStmt = $schoolDb->prepare("
            SELECT 
                s.*,
                u.name as student_name,
                u.email as student_email,
                u.phone as student_phone,
                u.profile_photo,
                u.gender,
                u.date_of_birth,
                u.blood_group as user_blood_group,
                u.religion,
                u.address as user_address,
                u.is_active as user_active,
                c.id as class_id,
                c.name as class_name,
                c.code as class_code,
                sec.id as section_id,
                sec.name as section_name,
                ay.id as academic_year_id,
                ay.name as academic_year_name,
                ay.start_date as academic_year_start,
                ay.end_date as academic_year_end,
                cat.name as category_name
            FROM students s
            JOIN users u ON s.user_id = u.id
            LEFT JOIN classes c ON s.class_id = c.id
            LEFT JOIN sections sec ON s.section_id = sec.id
            LEFT JOIN academic_years ay ON s.academic_year_id = ay.id
            LEFT JOIN student_categories cat ON s.category_id = cat.id
            WHERE s.id = ? AND s.school_id = ?
        ");
        $studentStmt->execute([$studentId, $school['id']]);
        $student = $studentStmt->fetch(PDO::FETCH_ASSOC);

        if (!$student) {
            error_log("ERROR: Student not found with ID: " . $studentId);
            header('Location: student-list.php?error=student_not_found');
            exit;
        }

        // Get guardians/parents
        $guardianStmt = $schoolDb->prepare("
            SELECT 
                u.id,
                u.name,
                u.email,
                u.phone,
                u.profile_photo,
                g.relationship,
                g.is_primary,
                g.can_pickup,
                g.emergency_contact
            FROM guardians g
            JOIN users u ON g.user_id = u.id
            WHERE g.student_id = ? AND g.school_id = ?
            ORDER BY g.is_primary DESC, g.relationship
        ");
        $guardianStmt->execute([$studentId, $school['id']]);
        $guardians = $guardianStmt->fetchAll(PDO::FETCH_ASSOC);

        // Get attendance statistics
        $currentYear = date('Y');
        $attendanceStatsStmt = $schoolDb->prepare("
            SELECT 
                COUNT(CASE WHEN status = 'present' THEN 1 END) as present_count,
                COUNT(CASE WHEN status = 'absent' THEN 1 END) as absent_count,
                COUNT(CASE WHEN status = 'late' THEN 1 END) as late_count,
                COUNT(CASE WHEN status = 'half_day' THEN 1 END) as half_day_count,
                COUNT(CASE WHEN status = 'holiday' THEN 1 END) as holiday_count,
                COUNT(*) as total_days
            FROM attendance 
            WHERE school_id = ? AND student_id = ? 
            AND YEAR(date) = ?
        ");
        $attendanceStatsStmt->execute([$school['id'], $student['user_id'], $currentYear]);
        $attendanceStats = $attendanceStatsStmt->fetch(PDO::FETCH_ASSOC);

        // Get attendance records for current year
        $attendanceRecordsStmt = $schoolDb->prepare("
            SELECT 
                date,
                status,
                remark,
                session,
                created_at
            FROM attendance 
            WHERE school_id = ? AND student_id = ? 
            AND YEAR(date) = ?
            ORDER BY date DESC
            LIMIT 30
        ");
        $attendanceRecordsStmt->execute([$school['id'], $student['user_id'], $currentYear]);
        $attendanceRecords = $attendanceRecordsStmt->fetchAll(PDO::FETCH_ASSOC);

        // Get fee statistics
        $feeStatsStmt = $schoolDb->prepare("
            SELECT 
                SUM(CASE WHEN status IN ('paid', 'partial') THEN amount ELSE 0 END) as total_paid,
                SUM(CASE WHEN status IN ('pending', 'partial') THEN (amount - COALESCE(paid_amount, 0)) ELSE 0 END) as total_due,
                SUM(amount) as total_amount,
                SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as paid_invoices,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_invoices,
                SUM(CASE WHEN status = 'overdue' THEN 1 ELSE 0 END) as overdue_invoices
            FROM invoices 
            WHERE school_id = ? AND student_id = ?
        ");
        $feeStatsStmt->execute([$school['id'], $studentId]);
        $feeStats = $feeStatsStmt->fetch(PDO::FETCH_ASSOC);

        // Get fee records
        $feeRecordsStmt = $schoolDb->prepare("
            SELECT 
                i.*,
                fc.name as fee_category_name
            FROM invoices i
            LEFT JOIN fee_categories fc ON i.fee_category_id = fc.id
            WHERE i.school_id = ? AND i.student_id = ?
            ORDER BY i.due_date DESC
            LIMIT 20
        ");
        $feeRecordsStmt->execute([$school['id'], $studentId]);
        $feeRecords = $feeRecordsStmt->fetchAll(PDO::FETCH_ASSOC);

        // Get exam results
        $examResultsStmt = $schoolDb->prepare("
            SELECT 
                eg.*,
                e.name as exam_name,
                e.start_date as exam_start_date,
                e.end_date as exam_end_date,
                sub.name as subject_name,
                sub.code as subject_code
            FROM exam_grades eg
            JOIN exams e ON eg.exam_id = e.id
            JOIN subjects sub ON eg.subject_id = sub.id
            WHERE eg.school_id = ? AND eg.student_id = ?
            ORDER BY e.start_date DESC, sub.name
            LIMIT 50
        ");
        $examResultsStmt->execute([$school['id'], $studentId]);
        $examResults = $examResultsStmt->fetchAll(PDO::FETCH_ASSOC);

        // Get library records
        $libraryStmt = $schoolDb->prepare("
            SELECT 
                lr.*,
                b.name as book_name,
                b.category as book_category,
                b.book_number
            FROM library_records lr
            JOIN books b ON lr.book_id = b.id
            WHERE lr.school_id = ? AND lr.student_id = ?
            ORDER BY lr.issue_date DESC
            LIMIT 20
        ");
        $libraryStmt->execute([$school['id'], $studentId]);
        $libraryRecords = $libraryStmt->fetchAll(PDO::FETCH_ASSOC);

        // Get leave records
        $leaveStmt = $schoolDb->prepare("
            SELECT 
                l.*,
                lt.name as leave_type_name
            FROM leaves l
            LEFT JOIN leave_types lt ON l.leave_type_id = lt.id
            WHERE l.school_id = ? AND l.student_id = ?
            ORDER BY l.apply_date DESC
            LIMIT 20
        ");
        $leaveStmt->execute([$school['id'], $studentId]);
        $leaveRecords = $leaveStmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (Exception $e) {
        error_log("Error fetching data: " . $e->getMessage());
        $error = "Error loading student data: " . $e->getMessage();
    }
}

// Handle suspend/activate student
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        $error = "Invalid security token. Please try again.";
    } else {
        try {
            if ($_POST['action'] === 'suspend' && isset($_POST['suspend_student'])) {
                // Suspend student
                $updateStmt = $schoolDb->prepare("
                    UPDATE students SET status = 'inactive' WHERE id = ? AND school_id = ?
                ");
                $updateStmt->execute([$studentId, $school['id']]);
                
                // Also update user status
                $updateUserStmt = $schoolDb->prepare("
                    UPDATE users SET is_active = 0 WHERE id = ? AND school_id = ?
                ");
                $updateUserStmt->execute([$student['user_id'], $school['id']]);
                
                // Create audit log
                $auditStmt = $schoolDb->prepare("
                    INSERT INTO audit_logs (
                        school_id, user_id, user_type, action, entity_type, 
                        entity_id, new_values, ip_address, user_agent, created_at
                    ) VALUES (?, ?, ?, 'suspend', 'student', ?, ?, ?, ?, NOW())
                ");
                $auditStmt->execute([
                    $school['id'],
                    $userId,
                    $userType,
                    $studentId,
                    json_encode(['status' => 'inactive']),
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    $_SERVER['HTTP_USER_AGENT'] ?? null
                ]);
                
                $message = "Student suspended successfully.";
                
            } elseif ($_POST['action'] === 'activate' && isset($_POST['activate_student'])) {
                // Activate student
                $updateStmt = $schoolDb->prepare("
                    UPDATE students SET status = 'active' WHERE id = ? AND school_id = ?
                ");
                $updateStmt->execute([$studentId, $school['id']]);
                
                // Also update user status
                $updateUserStmt = $schoolDb->prepare("
                    UPDATE users SET is_active = 1 WHERE id = ? AND school_id = ?
                ");
                $updateUserStmt->execute([$student['user_id'], $school['id']]);
                
                // Create audit log
                $auditStmt = $schoolDb->prepare("
                    INSERT INTO audit_logs (
                        school_id, user_id, user_type, action, entity_type, 
                        entity_id, new_values, ip_address, user_agent, created_at
                    ) VALUES (?, ?, ?, 'activate', 'student', ?, ?, ?, ?, NOW())
                ");
                $auditStmt->execute([
                    $school['id'],
                    $userId,
                    $userType,
                    $studentId,
                    json_encode(['status' => 'active']),
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    $_SERVER['HTTP_USER_AGENT'] ?? null
                ]);
                
                $message = "Student activated successfully.";
            }
            
            // Refresh student data
            $studentStmt->execute([$studentId, $school['id']]);
            $student = $studentStmt->fetch(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            $error = "Error: " . $e->getMessage();
        }
    }
}

// Generate CSRF token
$csrfToken = generateCsrfToken();

// Format currency symbol
$currencySymbol = $settings['currency_symbol'] ?? '₦';

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
    <title>Student Details - <?php echo htmlspecialchars($school['name']); ?></title>
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
                                // Get unread notification count
                                $unreadCount = 0;
                                if ($schoolDb) {
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
                                    <a href="notifications.php?id=<?php echo $notification['id']; ?>"
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
                    <h1 class="fw-semibold mb-4 h6 text-primary-light">Student Details</h1>
                    <div class="">
                        <a href="index.php" class="text-secondary-light hover-text-primary hover-underline">Dashboard</a>
                        <a href="student-list.php" class="text-secondary-light hover-text-primary hover-underline"> / Students</a>
                        <span class="text-secondary-light"> / Student Details</span>
                    </div>
                </div>
                <button type="button"
                    class="my-sidebar-btn btn btn-primary-600 d-flex align-items-center gap-6 bg-base text-primary-light bg-hover-primary-600">
                    <span class="d-flex text-md">
                        <i class="ri-lock-2-line"></i>
                    </span>
                    Login Details
                </button>
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

            <?php if ($student): ?>
            <div class="mt-24">
                <div class="card h-100">
                    <div class="card-body p-24">
                        <div class="d-flex gap-32 flex-md-row flex-column">
                            <div class="max-w-300-px w-100 text-center">
                                <figure class="mb-24 w-120-px h-120-px mx-auto rounded-circle overflow-hidden">
                                    <img src="<?php echo htmlspecialchars($student['profile_photo'] ?? 'https://academixsuite.com/tenant/assets/images/thumbs/student-details-img.png'); ?>" alt="Student Image" class="w-100 h-100 object-fit-cover">
                                </figure>
                                <h2 class="h6 text-primary-light mb-16 fw-semibold"><?php echo htmlspecialchars($student['student_name']); ?></h2>
                                <p class="mb-0">Admission No: <span class="text-primary-600 fw-semibold"><?php echo htmlspecialchars($student['admission_number']); ?></span></p>
                                <p class="mb-0">Roll No: <span class="text-primary-light fw-semibold"><?php echo htmlspecialchars($student['roll_number'] ?? 'N/A'); ?></span> </p>
                                <div class="mt-32 d-flex gap-16 w-100">
                                    <?php if ($student['status'] == 'active'): ?>
                                    <button type="button"
                                        class="btn border fw-medium border-danger-600 bg-hover-danger-200 text-danger-600 text-md d-flex justify-content-center align-items-center gap-8 flex-grow-1 px-12 py-8 radius-8"
                                        data-bs-toggle="modal" data-bs-target="#suspendModal">
                                        <span class="d-flex text-lg">
                                            <i class="ri-delete-bin-2-line"></i>
                                        </span>
                                        Suspend
                                    </button>
                                    <?php else: ?>
                                    <form method="POST" style="flex-grow: 1;">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                        <input type="hidden" name="action" value="activate">
                                        <button type="submit" name="activate_student"
                                            class="btn border fw-medium border-success-600 bg-hover-success-200 text-success-600 text-md d-flex justify-content-center align-items-center gap-8 w-100 px-12 py-8 radius-8">
                                            <span class="d-flex text-lg">
                                                <i class="ri-check-line"></i>
                                            </span>
                                            Activate
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                    <a href="edit-student.php?id=<?php echo $studentId; ?>"
                                        class="btn btn-primary-600 border fw-medium border-primary-600 text-md d-flex justify-content-center align-items-center gap-8 flex-grow-1 px-12 py-8 radius-8">
                                        <span class="d-flex text-lg">
                                            <i class="ri-edit-line"></i>
                                        </span>
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
                                    <span
                                        class="<?php echo $student['status'] == 'active' ? 'bg-success-100 text-success-600' : 'bg-danger-100 text-danger-600'; ?> px-16 py-4 radius-4 fw-medium text-sm">
                                        <?php echo ucfirst($student['status'] ?? 'Active'); ?>
                                    </span>
                                </div>
                                <div class="mt-16 d-flex flex-column gap-8">
                                    <div class="d-flex gap-4">
                                        <span class="fw-semibold text-sm text-primary-light w-110-px">Class</span>
                                        <span class="fw-normal text-sm text-secondary-light">: <?php echo htmlspecialchars($student['class_name'] ?? 'N/A'); ?> (<?php echo htmlspecialchars($student['section_name'] ?? 'No Section'); ?>)</span>
                                    </div>
                                    <div class="d-flex gap-4">
                                        <span class="fw-semibold text-sm text-primary-light w-110-px">Section</span>
                                        <span class="fw-normal text-sm text-secondary-light">: <?php echo htmlspecialchars($student['section_name'] ?? 'N/A'); ?></span>
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
                                        <span class="fw-normal text-sm text-secondary-light">: <?php echo $student['date_of_birth'] ? date('d M Y', strtotime($student['date_of_birth'])) : 'N/A'; ?></span>
                                    </div>
                                    <div class="d-flex gap-4">
                                        <span class="fw-semibold text-sm text-primary-light w-110-px">Category</span>
                                        <span class="fw-normal text-sm text-secondary-light">: <?php echo htmlspecialchars($student['category_name'] ?? 'General'); ?></span>
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
                            <button
                                class="nav-link d-flex align-items-center gap-8 text-secondary-light fw-medium text-sm text-hover-primary-600 text-capitalize bg-transparent px-20 py-12 active"
                                id="pills-studentDetails-tab" data-bs-toggle="pill" data-bs-target="#pills-studentDetails"
                                type="button" role="tab" aria-controls="pills-studentDetails" aria-selected="true">
                                <span class="d-flex tab-icon line-height-1 text-md">
                                    <i class="ri-group-line"></i>
                                </span>
                                Student Details
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button
                                class="nav-link d-flex align-items-center gap-8 text-secondary-light fw-medium text-sm text-hover-primary-600 text-capitalize bg-transparent px-20 py-12"
                                id="pills-attendance-tab" data-bs-toggle="pill" data-bs-target="#pills-attendance"
                                type="button" role="tab" aria-controls="pills-attendance" aria-selected="false">
                                <span class="d-flex tab-icon line-height-1 text-md">
                                    <i class="ri-calendar-check-line"></i>
                                </span>
                                Attendance
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button
                                class="nav-link d-flex align-items-center gap-8 text-secondary-light fw-medium text-sm text-hover-primary-600 text-capitalize bg-transparent px-20 py-12"
                                id="pills-leave-tab" data-bs-toggle="pill" data-bs-target="#pills-leave" type="button"
                                role="tab" aria-controls="pills-leave" aria-selected="false">
                                <span class="d-flex tab-icon line-height-1 text-md">
                                    <i class="ri-login-box-line"></i>
                                </span>
                                Leave
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button
                                class="nav-link d-flex align-items-center gap-8 text-secondary-light fw-medium text-sm text-hover-primary-600 text-capitalize bg-transparent px-20 py-12"
                                id="pills-fees-tab" data-bs-toggle="pill" data-bs-target="#pills-fees" type="button"
                                role="tab" aria-controls="pills-fees" aria-selected="false">
                                <span class="d-flex tab-icon line-height-1 text-md">
                                    <i class="ri-money-dollar-box-line"></i>
                                </span>
                                Fees
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button
                                class="nav-link d-flex align-items-center gap-8 text-secondary-light fw-medium text-sm text-hover-primary-600 text-capitalize bg-transparent px-20 py-12"
                                id="pills-exam-tab" data-bs-toggle="pill" data-bs-target="#pills-exam" type="button"
                                role="tab" aria-controls="pills-exam" aria-selected="false">
                                <span class="d-flex tab-icon line-height-1 text-md">
                                    <i class="ri-file-edit-line"></i>
                                </span>
                                Exam
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button
                                class="nav-link d-flex align-items-center gap-8 text-secondary-light fw-medium text-sm text-hover-primary-600 text-capitalize bg-transparent px-20 py-12"
                                id="pills-library-tab" data-bs-toggle="pill" data-bs-target="#pills-library" type="button"
                                role="tab" aria-controls="pills-library" aria-selected="false">
                                <span class="d-flex tab-icon line-height-1 text-md">
                                    <i class="ri-book-line"></i>
                                </span>
                                Library
                            </button>
                        </li>
                    </ul>

                    <div class="tab-content" id="pills-tabContent">

                        <!-- Student Details tab start -->
                        <div class="tab-pane fade show active" id="pills-studentDetails" role="tabpanel"
                            aria-labelledby="pills-studentDetails-tab" tabindex="0">
                            <div class="row gy-4">
                                <div class="col-12">
                                    <div class="shadow-1 radius-12 bg-base h-100 overflow-hidden">
                                        <div
                                            class="card-header border-bottom bg-base py-16 px-24 d-flex align-items-center justify-content-between">
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
                                                                <figure
                                                                    class="w-48-px h-48-px rounded-circle overflow-hidden mb-0">
                                                                    <img src="<?php echo htmlspecialchars($guardian['profile_photo'] ?? 'https://academixsuite.com/tenant/assets/images/thumbs/guardian-img1.png'); ?>"
                                                                        alt="Guardian Image"
                                                                        class="flex-shrink-0 w-100 h-100 object-fit-cover">
                                                                </figure>
                                                                <div class="">
                                                                    <h6 class="text-md mb-2 fw-medium flex-grow-1"><?php echo htmlspecialchars($guardian['name']); ?>
                                                                    </h6>
                                                                    <span class=""><?php echo ucfirst($guardian['relationship'] ?? 'Guardian'); ?>
                                                                        <?php if ($guardian['is_primary']): ?>
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
                                        <div
                                            class="card-header border-bottom bg-base py-16 px-24 d-flex align-items-center justify-content-between">
                                            <h6 class="text-lg fw-semibold mb-0">Previous School Details</h6>
                                        </div>
                                        <div class="card-body p-0">
                                            <div class="p-20">
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
                                </div>

                                <div class="col-md-6">
                                    <div class="shadow-1 radius-12 bg-base h-100 overflow-hidden">
                                        <div
                                            class="card-header border-bottom bg-base py-16 px-24 d-flex align-items-center justify-content-between">
                                            <h6 class="text-lg fw-semibold mb-0">Address</h6>
                                        </div>
                                        <div class="card-body p-0">
                                            <div class="p-20">
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
                                </div>

                                <div class="col-md-6">
                                    <div class="shadow-1 radius-12 bg-base h-100 overflow-hidden">
                                        <div
                                            class="card-header border-bottom bg-base py-16 px-24 d-flex align-items-center justify-content-between">
                                            <h6 class="text-lg fw-semibold mb-0">Bank Details</h6>
                                        </div>
                                        <div class="card-body p-0">
                                            <div class="p-20">
                                                <div class="row gy-4">
                                                    <div class="col-sm-4">
                                                        <div class="">
                                                            <h6 class="text-md mb-2 fw-medium flex-grow-1">Bank Name</h6>
                                                            <span class=""><?php echo htmlspecialchars($student['bank_name'] ?? 'N/A'); ?></span>
                                                        </div>
                                                    </div>
                                                    <div class="col-sm-4">
                                                        <div class="">
                                                            <h6 class="text-md mb-2 fw-medium flex-grow-1">Account Number</h6>
                                                            <span class=""><?php echo htmlspecialchars($student['bank_account'] ?? 'N/A'); ?></span>
                                                        </div>
                                                    </div>
                                                    <div class="col-sm-4">
                                                        <div class="">
                                                            <h6 class="text-md mb-2 fw-medium flex-grow-1">IFSC Code</h6>
                                                            <span class=""><?php echo htmlspecialchars($student['ifsc_code'] ?? 'N/A'); ?></span>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="shadow-1 radius-12 bg-base h-100 overflow-hidden">
                                        <div
                                            class="card-header border-bottom bg-base py-16 px-24 d-flex align-items-center justify-content-between">
                                            <h6 class="text-lg fw-semibold mb-0">Medical Details</h6>
                                        </div>
                                        <div class="card-body p-0">
                                            <div class="p-20">
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

                                <div class="col-md-6">
                                    <div class="shadow-1 radius-12 bg-base h-100 overflow-hidden">
                                        <div
                                            class="card-header border-bottom bg-base py-16 px-24 d-flex align-items-center justify-content-between">
                                            <h6 class="text-lg fw-semibold mb-0">Documents</h6>
                                        </div>
                                        <div class="card-body p-20">
                                            <div class="p-10 border radius-8">
                                                <div class="d-flex align-items-center justify-content-between gap-20">
                                                    <div class="d-flex align-items-center gap-12">
                                                        <span
                                                            class="w-36-px h-36-px radius-4 bg-neutral-50 d-flex justify-content-center align-items-center text-xl">
                                                            <i class="ri-file-text-line"></i>
                                                        </span>
                                                        <span class="text-md text-secondary-light">BirthCertificate.pdf</span>
                                                    </div>
                                                    <a href="#" download
                                                        class="w-36-px h-36-px radius-4 bg-primary-50 bg-hover-primary-100 text-primary-600 d-flex justify-content-center align-items-center text-xl">
                                                        <i class="ri-download-2-line"></i>
                                                    </a>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-md-12">
                                    <div class="shadow-1 radius-12 bg-base h-100 overflow-hidden">
                                        <div
                                            class="card-header border-bottom bg-base py-16 px-24 d-flex align-items-center justify-content-between">
                                            <h6 class="text-lg fw-semibold mb-0">Description</h6>
                                        </div>
                                        <div class="card-body p-0">
                                            <div class="p-20">
                                                <p class="text-secondary-light"><?php echo nl2br(htmlspecialchars($student['more_details'] ?? 'No additional information provided.')); ?></p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <!-- Student Details tab end -->

                        <!-- Attendance tab start -->
                        <div class="tab-pane fade" id="pills-attendance" role="tabpanel"
                            aria-labelledby="pills-attendance-tab" tabindex="0">
                            <div class="shadow-1 radius-12 bg-base h-100 overflow-hidden">
                                <div
                                    class="card-header border-bottom bg-base py-16 px-24 d-flex align-items-center justify-content-between">
                                    <h6 class="text-lg fw-semibold mb-0">Attendance - <?php echo date('Y'); ?></h6>
                                </div>
                                <div class="card-body p-0">
                                    <div class="px-20 pt-20">
                                        <div class="row row-cols-xxl-5 row-cols-lg-3 row-cols-sm-2 row-cols-1 g-3">
                                            <div class="col">
                                                <div
                                                    class="card px-20 py-28 shadow-2 radius-8 h-100 border border-neutral-200 shadow-none gradient-bg-end-7">
                                                    <div class="card-body p-0">
                                                        <div
                                                            class="d-flex flex-wrap align-items-center justify-content-between gap-1">
                                                            <div>
                                                                <h6 class="fw-semibold mb-2"><?php echo $attendanceStats['present_count'] ?? 0; ?></h6>
                                                                <span class="fw-medium text-secondary-light text-sm">Total Present</span>
                                                            </div>
                                                            <span
                                                                class="mb-0 w-48-px h-48-px bg-success-600 text-white flex-shrink-0 text-white d-flex justify-content-center align-items-center rounded-circle h6 mb-0">
                                                                <img src="https://academixsuite.com/tenant/assets/images/icons/attendence-icon1.png"
                                                                    alt="Present Icon">
                                                            </span>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col">
                                                <div
                                                    class="card px-20 py-28 shadow-2 radius-8 h-100 border border-neutral-200 shadow-none gradient-bg-end-8">
                                                    <div class="card-body p-0">
                                                        <div
                                                            class="d-flex flex-wrap align-items-center justify-content-between gap-1">
                                                            <div>
                                                                <h6 class="fw-semibold mb-2"><?php echo $attendanceStats['absent_count'] ?? 0; ?></h6>
                                                                <span class="fw-medium text-secondary-light text-sm">Total Absent</span>
                                                            </div>
                                                            <span
                                                                class="mb-0 w-48-px h-48-px bg-danger-600 text-white flex-shrink-0 text-white d-flex justify-content-center align-items-center rounded-circle h6 mb-0">
                                                                <img src="https://academixsuite.com/tenant/assets/images/icons/attendence-icon2.png"
                                                                    alt="Absent Icon">
                                                            </span>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col">
                                                <div
                                                    class="card px-20 py-28 shadow-2 radius-8 h-100 border border-neutral-200 shadow-none gradient-bg-end-9">
                                                    <div class="card-body p-0">
                                                        <div
                                                            class="d-flex flex-wrap align-items-center justify-content-between gap-1">
                                                            <div>
                                                                <h6 class="fw-semibold mb-2"><?php echo $attendanceStats['half_day_count'] ?? 0; ?></h6>
                                                                <span class="fw-medium text-secondary-light text-sm">Half Day</span>
                                                            </div>
                                                            <span
                                                                class="mb-0 w-48-px h-48-px bg-purple-600 text-white flex-shrink-0 text-white d-flex justify-content-center align-items-center rounded-circle h6 mb-0">
                                                                <img src="https://academixsuite.com/tenant/assets/images/icons/attendence-icon3.png"
                                                                    alt="Calendar Icon">
                                                            </span>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col">
                                                <div
                                                    class="card px-20 py-28 shadow-2 radius-8 h-100 border border-neutral-200 shadow-none gradient-bg-end-10">
                                                    <div class="card-body p-0">
                                                        <div
                                                            class="d-flex flex-wrap align-items-center justify-content-between gap-1">
                                                            <div>
                                                                <h6 class="fw-semibold mb-2"><?php echo $attendanceStats['late_count'] ?? 0; ?></h6>
                                                                <span class="fw-medium text-secondary-light text-sm">Total Late</span>
                                                            </div>
                                                            <span
                                                                class="mb-0 w-48-px h-48-px bg-info-600 text-white flex-shrink-0 text-white d-flex justify-content-center align-items-center rounded-circle h6 mb-0">
                                                                <img src="https://academixsuite.com/tenant/assets/images/icons/attendence-icon4.png"
                                                                    alt="Clock Icon">
                                                            </span>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col">
                                                <div
                                                    class="card px-20 py-28 shadow-2 radius-8 h-100 border border-neutral-200 shadow-none gradient-bg-end-11">
                                                    <div class="card-body p-0">
                                                        <div
                                                            class="d-flex flex-wrap align-items-center justify-content-between gap-1">
                                                            <div>
                                                                <h6 class="fw-semibold mb-2"><?php echo $attendanceStats['holiday_count'] ?? 0; ?></h6>
                                                                <span class="fw-medium text-secondary-light text-sm">Total Holiday</span>
                                                            </div>
                                                            <span
                                                                class="mb-0 w-48-px h-48-px bg-orange text-white flex-shrink-0 text-white d-flex justify-content-center align-items-center rounded-circle h6 mb-0">
                                                                <img src="https://academixsuite.com/tenant/assets/images/icons/attendence-icon5.png"
                                                                    alt="Holiday Icon">
                                                            </span>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="mt-24 mb-16 mx-20">
                                        <div
                                            class="d-flex flex-wrap align-items-center gap-24 justify-content-between flex-wrap">
                                            <div class="d-flex flex-wrap align-items-center gap-16 ">
                                                <div class="">
                                                    <select class="form-control form-select" id="attendanceYear">
                                                        <option value="<?php echo date('Y'); ?>" selected><?php echo date('Y'); ?></option>
                                                        <option value="<?php echo date('Y')-1; ?>"><?php echo date('Y')-1; ?></option>
                                                        <option value="<?php echo date('Y')-2; ?>"><?php echo date('Y')-2; ?></option>
                                                    </select>
                                                </div>
                                                <div class="dropdown">
                                                    <button type="button"
                                                        class="px-12 py-8 border border-neutral-300 radius-8 d-flex align-items-center gap-20"
                                                        data-bs-toggle="dropdown" aria-expanded="false">
                                                        <span
                                                            class="d-flex align-items-center gap-1 text-secondary-light text-sm">
                                                            <i class="ri-file-upload-line text-md line-height-1"></i>
                                                            Export
                                                        </span>
                                                        <span class="">
                                                            <i class="ri-arrow-down-s-line"></i>
                                                        </span>
                                                    </button>
                                                    <ul class="dropdown-menu p-12 border bg-base shadow">
                                                        <li>
                                                            <a href="export-attendance.php?student_id=<?php echo $studentId; ?>&format=pdf&year=<?php echo date('Y'); ?>" 
                                                               class="dropdown-item px-16 py-8 rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-10">
                                                                <i class="ri-file-3-line"></i>
                                                                PDF
                                                            </a>
                                                        </li>
                                                        <li>
                                                            <a href="export-attendance.php?student_id=<?php echo $studentId; ?>&format=excel&year=<?php echo date('Y'); ?>" 
                                                               class="dropdown-item px-16 py-8 rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-10">
                                                                <i class="ri-file-excel-line"></i>
                                                                Excel
                                                            </a>
                                                        </li>
                                                    </ul>
                                                </div>
                                            </div>
                                            <div class="d-flex align-items-center flex-wrap gap-8">
                                                <p class="text-primary-light text-sm fw-medium mb-0">
                                                    Present:
                                                    <span class="fw-semibold text-success-600">P </span>
                                                </p>
                                                <p class="text-primary-light text-sm fw-medium mb-0">
                                                    Absent:
                                                    <span class="fw-semibold text-danger-600">A </span>
                                                </p>
                                                <p class="text-primary-light text-sm fw-medium mb-0">
                                                    Holiday:
                                                    <span class="fw-semibold text-warning-600">H </span>
                                                </p>
                                                <p class="text-primary-light text-sm fw-medium mb-0">
                                                    Late:
                                                    <span class="fw-semibold text-info-600">L </span>
                                                </p>
                                                <p class="text-primary-light text-sm fw-medium mb-0">
                                                    Half Day:
                                                    <span class="fw-semibold text-purple-600">F </span>
                                                </p>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="table-responsive overflow-x-auto">
                                        <table class="table mb-0 table-heading-dark-mode">
                                            <thead>
                                                <tr>
                                                    <th class="bg-neutral-100 text-sm text-primary-light px-10 py-16">Date</th>
                                                    <th class="bg-neutral-100 text-sm text-primary-light px-10 py-16">Status</th>
                                                    <th class="bg-neutral-100 text-sm text-primary-light px-10 py-16">Session</th>
                                                    <th class="bg-neutral-100 text-sm text-primary-light px-10 py-16">Remark</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (empty($attendanceRecords)): ?>
                                                <tr>
                                                    <td colspan="4" class="text-center py-20 text-secondary-light">
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
                                                            switch($record['status']) {
                                                                case 'present':
                                                                    $statusClass = 'text-success-600';
                                                                    $statusChar = 'P';
                                                                    break;
                                                                case 'absent':
                                                                    $statusClass = 'text-danger-600';
                                                                    $statusChar = 'A';
                                                                    break;
                                                                case 'late':
                                                                    $statusClass = 'text-info-600';
                                                                    $statusChar = 'L';
                                                                    break;
                                                                case 'half_day':
                                                                    $statusClass = 'text-purple-600';
                                                                    $statusChar = 'F';
                                                                    break;
                                                                case 'holiday':
                                                                    $statusClass = 'text-warning-600';
                                                                    $statusChar = 'H';
                                                                    break;
                                                            }
                                                            ?>
                                                            <span class="attendance <?php echo $statusClass; ?>"><?php echo $statusChar; ?></span>
                                                        </td>
                                                        <td class="px-10 py-14 text-sm"><?php echo ucfirst(str_replace('_', ' ', $record['session'] ?? 'full_day')); ?></td>
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
                        <!-- Attendance tab end -->

                        <!-- Leave tab start -->
                        <div class="tab-pane fade" id="pills-leave" role="tabpanel" aria-labelledby="pills-leave-tab"
                            tabindex="0">
                            <div class="shadow-1 radius-12 bg-base h-100 overflow-hidden">
                                <div
                                    class="card-header border-bottom bg-base py-10 px-20 d-flex align-items-center justify-content-between">
                                    <h6 class="text-lg fw-semibold mb-0">Leave </h6>
                                    <button type="button"
                                        class="apply-leave-btn btn btn-primary-600 d-flex align-items-center gap-6 py-8 text-sm">
                                        <span class="d-flex text-sm">
                                            <i class="ri-calendar-close-line"></i>
                                        </span>
                                        Apply Leave
                                    </button>
                                </div>
                                <div class="card-body p-0">
                                    <table class="table bordered-table mb-0 table-heading-dark-mode w-100">
                                        <thead>
                                            <tr>
                                                <th scope="col">S.L</th>
                                                <th scope="col">Leave Type</th>
                                                <th scope="col">Date</th>
                                                <th scope="col">Duration</th>
                                                <th scope="col">Apply Date</th>
                                                <th scope="col">Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($leaveRecords)): ?>
                                            <tr>
                                                <td colspan="6" class="text-center py-20 text-secondary-light">
                                                    No leave records found.
                                                </td>
                                            </tr>
                                            <?php else: ?>
                                                <?php foreach ($leaveRecords as $index => $leave): ?>
                                                <tr>
                                                    <td><?php echo str_pad($index + 1, 2, '0', STR_PAD_LEFT); ?></td>
                                                    <td><?php echo htmlspecialchars($leave['leave_type_name'] ?? 'N/A'); ?></td>
                                                    <td><?php echo date('d M Y', strtotime($leave['from_date'])); ?> - <?php echo date('d M Y', strtotime($leave['to_date'])); ?></td>
                                                    <td><?php echo $leave['duration'] ?? '1'; ?> day(s)</td>
                                                    <td><?php echo date('d M Y', strtotime($leave['apply_date'] ?? $leave['created_at'])); ?></td>
                                                    <td>
                                                        <?php
                                                        $statusClass = '';
                                                        switch($leave['status'] ?? 'pending') {
                                                            case 'approved':
                                                                $statusClass = 'bg-success-100 text-success-600';
                                                                break;
                                                            case 'rejected':
                                                                $statusClass = 'bg-danger-100 text-danger-600';
                                                                break;
                                                            default:
                                                                $statusClass = 'bg-warning-100 text-warning-600';
                                                        }
                                                        ?>
                                                        <span class="<?php echo $statusClass; ?> px-20 py-4 radius-4 fw-medium text-sm">
                                                            <?php echo ucfirst($leave['status'] ?? 'Pending'); ?>
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
                        <!-- Leave tab end -->

                        <!-- Fees tab start -->
                        <div class="tab-pane fade" id="pills-fees" role="tabpanel" aria-labelledby="pills-fees-tab"
                            tabindex="0">
                            <div class="shadow-1 radius-12 bg-base h-100 overflow-hidden">
                                <div
                                    class="card-header border-bottom bg-base py-10 px-20 d-flex align-items-center justify-content-between">
                                    <h6 class="text-lg fw-semibold mb-0">Fees </h6>
                                    <button type="button"
                                        class="collect-fees-btn btn btn-primary-600 d-flex align-items-center gap-6 py-8 text-sm">
                                        <span class="d-flex text-sm">
                                            <i class="ri-bank-card-line"></i>
                                        </span>
                                        Collect Fees
                                    </button>
                                </div>
                                <div class="card-body p-0">
                                    <div class="p-20">
                                        <div class="row g-3">
                                            <div class="col-xl-3 col-sm-6">
                                                <div
                                                    class="card px-20 py-28 shadow-2 radius-8 h-100 border border-neutral-200 shadow-none gradient-bg-end-10">
                                                    <div class="card-body p-0">
                                                        <div
                                                            class="d-flex flex-wrap align-items-center justify-content-between gap-1">
                                                            <div>
                                                                <h6 class="fw-semibold mb-2"><?php echo $currencySymbol . number_format($feeStats['total_amount'] ?? 0, 2); ?></h6>
                                                                <span class="fw-medium text-secondary-light text-sm">Total Amount</span>
                                                            </div>
                                                            <span
                                                                class="mb-0 w-48-px h-48-px bg-info-600 text-white flex-shrink-0 text-white d-flex justify-content-center align-items-center rounded-circle h6 mb-0">
                                                                <img src="https://academixsuite.com/tenant/assets/images/icons/fees-icon1.png"
                                                                    alt="Total Icon">
                                                            </span>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-xl-3 col-sm-6">
                                                <div
                                                    class="card px-20 py-28 shadow-2 radius-8 h-100 border border-neutral-200 shadow-none gradient-bg-end-8">
                                                    <div class="card-body p-0">
                                                        <div
                                                            class="d-flex flex-wrap align-items-center justify-content-between gap-1">
                                                            <div>
                                                                <h6 class="fw-semibold mb-2"><?php echo $currencySymbol . number_format($feeStats['total_paid'] ?? 0, 2); ?></h6>
                                                                <span class="fw-medium text-secondary-light text-sm">Total Paid</span>
                                                            </div>
                                                            <span
                                                                class="mb-0 w-48-px h-48-px bg-success-600 text-white flex-shrink-0 text-white d-flex justify-content-center align-items-center rounded-circle h6 mb-0">
                                                                <img src="https://academixsuite.com/tenant/assets/images/icons/fees-icon3.png"
                                                                    alt="Paid Icon">
                                                            </span>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-xl-3 col-sm-6">
                                                <div
                                                    class="card px-20 py-28 shadow-2 radius-8 h-100 border border-neutral-200 shadow-none gradient-bg-end-11">
                                                    <div class="card-body p-0">
                                                        <div
                                                            class="d-flex flex-wrap align-items-center justify-content-between gap-1">
                                                            <div>
                                                                <h6 class="fw-semibold mb-2"><?php echo $currencySymbol . number_format($feeStats['total_due'] ?? 0, 2); ?></h6>
                                                                <span class="fw-medium text-secondary-light text-sm">Total Due</span>
                                                            </div>
                                                            <span
                                                                class="mb-0 w-48-px h-48-px bg-orange text-white flex-shrink-0 text-white d-flex justify-content-center align-items-center rounded-circle h6 mb-0">
                                                                <img src="https://academixsuite.com/tenant/assets/images/icons/fees-icon4.png"
                                                                    alt="Due Icon">
                                                            </span>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-xl-3 col-sm-6">
                                                <div
                                                    class="card px-20 py-28 shadow-2 radius-8 h-100 border border-neutral-200 shadow-none gradient-bg-end-7">
                                                    <div class="card-body p-0">
                                                        <div
                                                            class="d-flex flex-wrap align-items-center justify-content-between gap-1">
                                                            <div>
                                                                <h6 class="fw-semibold mb-2"><?php echo ($feeStats['paid_invoices'] ?? 0) + ($feeStats['pending_invoices'] ?? 0); ?></h6>
                                                                <span class="fw-medium text-secondary-light text-sm">Total Invoices</span>
                                                            </div>
                                                            <span
                                                                class="mb-0 w-48-px h-48-px bg-purple-600 text-white flex-shrink-0 text-white d-flex justify-content-center align-items-center rounded-circle h6 mb-0">
                                                                <i class="ri-file-list-line"></i>
                                                            </span>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <table class="table bordered-table mb-0 table-heading-dark-mode w-100">
                                        <thead>
                                            <tr>
                                                <th scope="col">S.L</th>
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
                                                    <td><?php echo str_pad($index + 1, 2, '0', STR_PAD_LEFT); ?></td>
                                                    <td><?php echo htmlspecialchars($fee['fee_category_name'] ?? 'N/A'); ?></td>
                                                    <td><?php echo date('d M Y', strtotime($fee['due_date'])); ?></td>
                                                    <td><?php echo $currencySymbol . number_format($fee['amount'] ?? 0, 2); ?></td>
                                                    <td><?php echo $currencySymbol . number_format($fee['paid_amount'] ?? 0, 2); ?></td>
                                                    <td><?php echo $currencySymbol . number_format($fee['balance_amount'] ?? ($fee['amount'] - ($fee['paid_amount'] ?? 0)), 2); ?></td>
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
                        <!-- Fees tab end -->

                        <!-- Exam tab start -->
                        <div class="tab-pane fade" id="pills-exam" role="tabpanel" aria-labelledby="pills-exam-tab"
                            tabindex="0">
                            <div class="shadow-1 radius-12 bg-base h-100 overflow-hidden">
                                <div
                                    class="card-header border-bottom bg-base py-16 px-24 d-flex align-items-center justify-content-between">
                                    <h6 class="text-lg fw-semibold mb-0">Exam Results</h6>
                                </div>
                                <div class="card-body p-20 d-flex flex-column gap-20">
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
                                        <div class="border radius-8 overflow-hidden">
                                            <button type="button"
                                                class="custom-accordion-btn text-md fw-semibold text-secondary-light w-100 py-10 px-20 d-flex align-items-center gap-12 justify-content-between">
                                                <?php echo htmlspecialchars($examName); ?>
                                                <span class="arrow-icon text-lg d-flex line-height-1">
                                                    <i class="ri-arrow-down-s-line"></i>
                                                </span>
                                            </button>
                                            <div class="custom-accordion-content table-bottom-info-none">
                                                <table class="table bordered-table mb-0 table-heading-dark-mode w-100">
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
                                                        $totalSubjects = count($results);
                                                        foreach ($results as $result): 
                                                            $totalMarks += $result['total_marks'];
                                                            $obtainedMarks += $result['marks_obtained'];
                                                        ?>
                                                        <tr>
                                                            <td class="text-start"><?php echo htmlspecialchars($result['subject_name']); ?></td>
                                                            <td class="text-start"><?php echo number_format($result['total_marks'], 2); ?></td>
                                                            <td class="text-start"><?php echo number_format($result['marks_obtained'], 2); ?></td>
                                                            <td class="text-start"><?php echo htmlspecialchars($result['grade'] ?? '-'); ?></td>
                                                            <td class="text-start">
                                                                <?php if (($result['marks_obtained'] ?? 0) >= ($result['total_marks'] * 0.35)): ?>
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
                                                            <td class="text-primary-light fw-semibold text-md border-top border-bottom border-neutral-200 text-start bg-neutral-50" colspan="2">
                                                                Total: <?php echo number_format($totalMarks, 2); ?>
                                                            </td>
                                                            <td class="text-primary-light fw-semibold text-md border-top border-bottom border-neutral-200 text-start bg-neutral-50">
                                                                Obtained: <?php echo number_format($obtainedMarks, 2); ?>
                                                            </td>
                                                            <td class="text-primary-light fw-semibold text-md border-top border-bottom border-neutral-200 text-start bg-neutral-50">
                                                                Percentage: <?php echo $totalMarks > 0 ? round(($obtainedMarks / $totalMarks) * 100, 2) : 0; ?>%
                                                            </td>
                                                            <td class="text-primary-light fw-semibold text-md border-top border-bottom border-neutral-200 text-start bg-neutral-50">
                                                                <?php echo ($obtainedMarks >= ($totalMarks * 0.35)) ? 'Pass' : 'Fail'; ?>
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
                        <!-- Exam tab end -->

                        <!-- Library tab start -->
                        <div class="tab-pane fade" id="pills-library" role="tabpanel" aria-labelledby="pills-library-tab"
                            tabindex="0">
                            <div class="shadow-1 radius-12 bg-base h-100 overflow-hidden">
                                <div
                                    class="card-header border-bottom bg-base py-10 px-20 d-flex align-items-center justify-content-between">
                                    <h6 class="text-lg fw-semibold mb-0">Library </h6>
                                </div>
                                <div class="card-body p-0">
                                    <table class="table bordered-table mb-0 table-heading-dark-mode w-100">
                                        <thead>
                                            <tr>
                                                <th scope="col" class="text-start">S.L</th>
                                                <th scope="col" class="text-start">Book Name</th>
                                                <th scope="col" class="text-start">Book Category</th>
                                                <th scope="col" class="text-start">Book Number</th>
                                                <th scope="col" class="text-start">Issue Date</th>
                                                <th scope="col" class="text-start">Return Date</th>
                                                <th scope="col" class="text-start">Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($libraryRecords)): ?>
                                            <tr>
                                                <td colspan="7" class="text-center py-20 text-secondary-light">
                                                    No library records found.
                                                </td>
                                            </tr>
                                            <?php else: ?>
                                                <?php foreach ($libraryRecords as $index => $book): ?>
                                                <tr>
                                                    <td class="text-start"><?php echo str_pad($index + 1, 2, '0', STR_PAD_LEFT); ?></td>
                                                    <td class="text-start">
                                                        <div class="d-flex align-items-center">
                                                            <img src="https://academixsuite.com/tenant/assets/images/thumbs/library-img1.png" alt="Library Image"
                                                                class="flex-shrink-0 me-12 radius-4 w-36-px h-36-px">
                                                            <div class="">
                                                                <h6 class="text-md mb-0 fw-medium flex-grow-1 text-secondary-light">
                                                                    <?php echo htmlspecialchars($book['book_name']); ?>
                                                                </h6>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td class="text-start"><?php echo htmlspecialchars($book['book_category'] ?? 'N/A'); ?></td>
                                                    <td class="text-start"><?php echo htmlspecialchars($book['book_number'] ?? 'N/A'); ?></td>
                                                    <td class="text-start"><?php echo date('d M Y', strtotime($book['issue_date'])); ?></td>
                                                    <td class="text-start"><?php echo $book['return_date'] ? date('d M Y', strtotime($book['return_date'])) : 'Not Returned'; ?></td>
                                                    <td class="text-start">
                                                        <?php if ($book['return_date'] && strtotime($book['return_date']) < time()): ?>
                                                        <span class="bg-danger-100 text-danger-600 px-16 py-2 radius-4 fw-medium text-sm">Overdue</span>
                                                        <?php elseif ($book['return_date']): ?>
                                                        <span class="bg-success-100 text-success-600 px-16 py-2 radius-4 fw-medium text-sm">Returned</span>
                                                        <?php else: ?>
                                                        <span class="bg-warning-100 text-warning-600 px-16 py-2 radius-4 fw-medium text-sm">Issued</span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <!-- Library tab end -->
                    </div>
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

    <!-- Login Details sidebar start -->
    <div
        class="my-sidebar bg-white position-fixed end-0 top-0 h-100vh overflow-y-auto z-99 max-w-700-px w-100 translate-x-full duration-300 active-translate-0">
        <div class="px-20 py-12 border-bottom d-flex align-items-center justify-content-between gap-20">
            <h5 class="text-lg mb-0">Login Details</h5>
            <button type="button" class="close-my-sidebar text-danger-600 text-lg d-flex">
                <i class="ri-close-large-line"></i>
            </button>
        </div>
        <form action="#" class="d-flex flex-column">
            <div class="p-20">
                <div class="d-flex align-items-center gap-20">
                    <figure class="w-72-px h-72-px rounded-circle overflow-hidden mb-0">
                        <img src="<?php echo htmlspecialchars($student['profile_photo'] ?? 'https://academixsuite.com/tenant/assets/images/thumbs/student-details-img.png'); ?>" alt="Student Image" class="w-100 h-100 object-fit-cover">
                    </figure>
                    <div class="flex-grow-1">
                        <h2 class="text-xl text-primary-light mb-4"><?php echo htmlspecialchars($student['student_name']); ?></h2>
                        <p class="mb-0">Roll No: <span class="text-primary-light fw-semibold"><?php echo htmlspecialchars($student['roll_number'] ?? 'N/A'); ?></span> </p>
                    </div>
                </div>
            </div>
            <div class="table-bottom-info-none">
                <table class="table bordered-table mb-0 table-heading-dark-mode w-100" id="loginDetailsTable">
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
        </form>
    </div>
    <!-- Login Details sidebar end -->

    <!-- Apply Leave sidebar start -->
    <div
        class="apply-leave bg-white position-fixed end-0 top-0 h-100vh overflow-y-auto z-99 max-w-700-px w-100 translate-x-full duration-300 active-translate-0">
        <div class="px-20 py-12 border-bottom d-flex align-items-center justify-content-between gap-20">
            <h5 class="text-lg mb-0">Apply Leave</h5>
            <button type="button" class="close-apply-leave text-danger-600 text-lg d-flex">
                <i class="ri-close-large-line"></i>
            </button>
        </div>
        <form action="apply-leave.php" method="POST" class="d-flex flex-column p-20">
            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
            <input type="hidden" name="student_id" value="<?php echo $studentId; ?>">
            <div class="row g-3">
                <div class="col-sm-6">
                    <div class="">
                        <label for="leaveType" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Leave Type</label>
                        <select id="leaveType" name="leave_type_id" class="form-control form-select" required>
                            <option value="" selected disabled>Select a leave type</option>
                            <option value="1">Sickness</option>
                            <option value="2">Emergency</option>
                            <option value="3">Travel</option>
                            <option value="4">Personal</option>
                        </select>
                    </div>
                </div>
                <div class="col-sm-6">
                    <div class="">
                        <label for="fromDate" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">From Date</label>
                        <input type="date" class="form-control" id="fromDate" name="from_date" required>
                    </div>
                </div>
                <div class="col-sm-6">
                    <div class="">
                        <label for="toDate" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">To Date</label>
                        <input type="date" class="form-control" id="toDate" name="to_date" required>
                    </div>
                </div>
                <div class="col-sm-6">
                    <div class="">
                        <label for="leaveDays" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Leave Days</label>
                        <select id="leaveDays" name="duration_type" class="form-control form-select">
                            <option value="full_day">Full Day</option>
                            <option value="first_half">First Half</option>
                            <option value="second_half">Second Half</option>
                        </select>
                    </div>
                </div>
                <div class="col-sm-12">
                    <div class="">
                        <label for="ReasonForLeave" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Reason for Leave</label>
                        <textarea id="ReasonForLeave" name="reason" class="form-control" placeholder="Enter reason for leave..." required></textarea>
                    </div>
                </div>
                <div class="col-12">
                    <div class="d-flex align-items-center justify-content-center gap-3 mt-8">
                        <button type="reset"
                            class="border border-danger-600 bg-hover-danger-200 text-danger-600 text-md px-50 py-11 radius-8">
                            Cancel
                        </button>
                        <button type="submit"
                            class="btn btn-primary-600 border border-primary-600 text-md px-28 py-12 radius-8">
                            Send Request
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>
    <!-- Apply Leave sidebar end -->

    <!-- Collect Fees sidebar start -->
    <div
        class="collect-fees bg-white position-fixed end-0 top-0 h-100vh overflow-y-auto z-99 max-w-700-px w-100 translate-x-full duration-300 active-translate-0">
        <div class="px-20 py-12 border-bottom d-flex align-items-center justify-content-between gap-20">
            <h5 class="text-lg mb-0">Collect Fees</h5>
            <button type="button" class="close-collect-fees text-danger-600 text-lg d-flex">
                <i class="ri-close-large-line"></i>
            </button>
        </div>
        <form action="collect-fees.php" method="POST" class="d-flex flex-column p-20">
            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
            <input type="hidden" name="student_id" value="<?php echo $studentId; ?>">
            <div class="row g-3">
                <div class="col-sm-6">
                    <div class="">
                        <label for="feesType" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Fees Type</label>
                        <select id="feesType" name="fee_category_id" class="form-control form-select" required>
                            <option value="" selected disabled>Select fees type</option>
                            <?php
                            // Fetch fee categories
                            if ($schoolDb) {
                                $catStmt = $schoolDb->prepare("SELECT id, name FROM fee_categories WHERE school_id = ? AND is_active = 1");
                                $catStmt->execute([$school['id']]);
                                $feeCategories = $catStmt->fetchAll(PDO::FETCH_ASSOC);
                                foreach ($feeCategories as $category) {
                                    echo '<option value="' . $category['id'] . '">' . htmlspecialchars($category['name']) . '</option>';
                                }
                            }
                            ?>
                        </select>
                    </div>
                </div>
                <div class="col-sm-6">
                    <div class="">
                        <label for="feesDate" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Payment Date</label>
                        <input type="date" class="form-control" id="feesDate" name="payment_date" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                </div>
                <div class="col-sm-6">
                    <div class="">
                        <label for="feesAmount" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Amount</label>
                        <input type="number" step="0.01" class="form-control" id="feesAmount" name="amount" placeholder="Enter amount" required>
                    </div>
                </div>
                <div class="col-sm-6">
                    <div class="">
                        <label for="feesPaymentType" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Payment Type</label>
                        <select id="feesPaymentType" name="payment_method" class="form-control form-select" required>
                            <option value="cash">Cash</option>
                            <option value="bank_transfer">Bank Transfer</option>
                            <option value="card">Card</option>
                            <option value="cheque">Cheque</option>
                            <option value="online">Online</option>
                        </select>
                    </div>
                </div>
                <div class="col-sm-12">
                    <div class="">
                        <label for="feesNote" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Note</label>
                        <textarea id="feesNote" name="note" class="form-control" placeholder="Enter note..."></textarea>
                    </div>
                </div>
                <div class="col-12">
                    <div class="d-flex align-items-center justify-content-center gap-3 mt-8">
                        <button type="reset"
                            class="border border-danger-600 bg-hover-danger-200 text-danger-600 text-md px-50 py-11 radius-8">
                            Cancel
                        </button>
                        <button type="submit" name="collect_fee"
                            class="btn btn-primary-600 border border-primary-600 text-md px-28 py-12 radius-8">
                            Pay
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>
    <!-- Collect Fees sidebar end -->

    <!-- Suspend Modal -->
    <div class="modal fade" id="suspendModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-sm modal-dialog modal-dialog-centered max-w-340-px">
            <div class="modal-content radius-16 bg-base">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                    <input type="hidden" name="action" value="suspend">
                    <div class="modal-body pt-32 px-36 pb-24 text-center">
                        <span class="mb-16 fs-1 line-height-1 text-danger">
                            <iconify-icon icon="fluent:delete-24-regular" class="menu-icon"></iconify-icon>
                        </span>
                        <h6 class="text-lg fw-semibold text-primary-light mb-0">Are you sure you want to Suspend this Student?</h6>
                        <div class="d-flex align-items-center justify-content-center gap-3 mt-24">
                            <button type="button" data-bs-dismiss="modal"
                                class="flex-grow-1 border border-danger-600 bg-hover-danger-200 text-danger-600 text-md px-24 py-11 radius-8">
                                Cancel
                            </button>
                            <button type="submit" name="suspend_student"
                                class="flex-grow-1 btn btn-primary-600 border border-primary-600 text-md px-16 py-12 radius-8">
                                Yes, Suspend
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

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
            // Data Table initialization
            $('.data-table').each(function() {
                if ($(this).find('tbody tr').length > 1) {
                    new DataTable(this, {
                        pageLength: 10,
                        responsive: true
                    });
                }
            });

            // Dynamic Class added to attendance status
            $('.attendance').each(function() {
                let value = $(this).text().trim().toUpperCase();
                if (value === 'P') {
                    $(this).addClass('text-success-600');
                } else if (value === 'A') {
                    $(this).addClass('text-danger-600');
                } else if (value === 'H') {
                    $(this).addClass('text-warning-600');
                } else if (value === 'F') {
                    $(this).addClass('text-purple-600');
                } else if (value === 'L') {
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
            $('.custom-accordion-btn').first().addClass('active');
            $('.custom-accordion-btn').first().siblings('.custom-accordion-content').show();

            // Sidebar toggles
            $('.my-sidebar-btn').on('click', function() {
                $('.my-sidebar').addClass('active');
                $('.overlay').addClass('active');
            });
            
            $('.close-my-sidebar, .overlay').on('click', function() {
                $('.my-sidebar').removeClass('active');
                $('.overlay').removeClass('active');
            });

            $('.apply-leave-btn').on('click', function() {
                $('.apply-leave').addClass('active');
                $('.overlay').addClass('active');
            });
            
            $('.close-apply-leave, .overlay').on('click', function() {
                $('.apply-leave').removeClass('active');
                $('.overlay').removeClass('active');
            });

            $('.collect-fees-btn').on('click', function() {
                $('.collect-fees').addClass('active');
                $('.overlay').addClass('active');
            });
            
            $('.close-collect-fees, .overlay').on('click', function() {
                $('.collect-fees').removeClass('active');
                $('.overlay').removeClass('active');
            });

            // Calculate leave days
            $('#fromDate, #toDate').on('change', function() {
                let fromDate = $('#fromDate').val();
                let toDate = $('#toDate').val();
                
                if (fromDate && toDate) {
                    let start = new Date(fromDate);
                    let end = new Date(toDate);
                    let diffTime = Math.abs(end - start);
                    let diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24)) + 1;
                    
                    if (diffDays > 0) {
                        $('#leaveDaysDisplay').text(diffDays + ' day(s)');
                    }
                }
            });

            // Auto-dismiss alerts
            setTimeout(function() {
                $('.alert').fadeOut('slow');
            }, 5000);
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