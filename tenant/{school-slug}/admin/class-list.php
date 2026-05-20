<?php
/**
 * School Class List Page
 * Displays and manages all classes in the school
 * 
 * @package AcademixSuite
 * @version 2.0
 */

// Enable error reporting for debugging
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../../logs/school_class_list.log');

error_log("=== CLASS LIST PAGE START ===");
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
$currentPage = $GLOBALS['CURRENT_PAGE'] ?? 'class-list.php';
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

// Include NotificationManager if not already loaded
$notificationManagerPath = __DIR__ . '/../../../includes/NotificationManager.php';
if (file_exists($notificationManagerPath)) {
    require_once $notificationManagerPath;
} else {
    error_log("NotificationManager.php not found at: " . $notificationManagerPath);
}

/**
 * Initialize notification variables
 */
$notificationCount = 0;
$notifications = [];

if ($schoolDb && class_exists('NotificationManager')) {
    try {
        $notificationManager = new NotificationManager($schoolDb, $school['id'], $userId, $userType, $school);
        
        $notificationCount = $notificationManager->getUnreadCount();
        $notifications = $notificationManager->getNotifications(5, false); // only unread
        
    } catch (Exception $e) {
        error_log("ERROR initializing NotificationManager: " . $e->getMessage());
    }
}

/**
 * Fetch classes with their sections and statistics
 */
$classes = [];
$academicYears = [];
$totalStudents = 0;
$totalTeachers = 0;
$totalClasses = 0;
$totalSections = 0;

// Also fetch teachers for dropdown
$teachers = [];

if ($schoolDb) {
    try {
        // Get all academic years for filter
        $yearStmt = $schoolDb->prepare("
            SELECT * FROM academic_years 
            WHERE school_id = ? 
            ORDER BY start_date DESC
        ");
        $yearStmt->execute([$school['id']]);
        $academicYears = $yearStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        
        // Get teachers for class teacher dropdown (active teachers)
        $teacherStmt = $schoolDb->prepare("
            SELECT u.id, u.name 
            FROM users u
            INNER JOIN teachers t ON u.id = t.user_id
            WHERE u.school_id = ? AND u.user_type = 'teacher' AND u.is_active = 1
            ORDER BY u.name
        ");
        $teacherStmt->execute([$school['id']]);
        $teachers = $teacherStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        
        // Get classes with statistics
        $classStmt = $schoolDb->prepare("
            SELECT 
                c.*,
                ay.name as academic_year_name,
                COUNT(DISTINCT s.id) as section_count,
                COUNT(DISTINCT st.id) as student_count,
                COUNT(DISTINCT cs.subject_id) as subject_count,
                COUNT(DISTINCT CASE WHEN st.status = 'active' THEN st.id END) as active_students,
                GROUP_CONCAT(DISTINCT s.name ORDER BY s.name SEPARATOR ', ') as section_names
            FROM classes c
            LEFT JOIN academic_years ay ON c.academic_year_id = ay.id
            LEFT JOIN sections s ON c.id = s.class_id AND s.is_active = 1
            LEFT JOIN students st ON s.id = st.section_id AND st.status = 'active'
            LEFT JOIN class_subjects cs ON c.id = cs.class_id
            WHERE c.school_id = ?
            GROUP BY c.id
            ORDER BY c.grade_level, c.name
        ");
        $classStmt->execute([$school['id']]);
        $classes = $classStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        
        // Calculate totals
        $totalClasses = count($classes);
        foreach ($classes as $class) {
            $totalSections += $class['section_count'] ?? 0;
            $totalStudents += $class['student_count'] ?? 0;
        }
        
        // Get total teachers count (for stats)
        $teacherCountStmt = $schoolDb->prepare("
            SELECT COUNT(*) as count FROM users 
            WHERE school_id = ? AND user_type = 'teacher' AND is_active = 1
        ");
        $teacherCountStmt->execute([$school['id']]);
        $totalTeachers = $teacherCountStmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
        
        error_log("Fetched " . count($classes) . " classes successfully");
        
    } catch (Exception $e) {
        error_log("Error fetching classes: " . $e->getMessage());
        $_SESSION['toast_error'] = "Error loading class data.";
    }
}

/**
 * Handle form submissions
 */
$message = '';
$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        if (!$schoolDb) {
            throw new Exception("Database connection not available");
        }
        
        switch ($action) {
            case 'create_class':
                // Validate required fields
                if (empty($_POST['name']) || empty($_POST['code']) || empty($_POST['academic_year_id'])) {
                    throw new Exception("Class name, code, and academic year are required");
                }
                
                // Check if class code already exists
                $checkStmt = $schoolDb->prepare("
                    SELECT id FROM classes 
                    WHERE school_id = ? AND code = ? AND academic_year_id = ?
                ");
                $checkStmt->execute([$school['id'], $_POST['code'], $_POST['academic_year_id']]);
                if ($checkStmt->fetch()) {
                    throw new Exception("Class code already exists for this academic year");
                }
                
                $schoolDb->beginTransaction();
                
                $stmt = $schoolDb->prepare("
                    INSERT INTO classes (
                        school_id, name, code, description, grade_level,
                        class_teacher_id, capacity, room_number, academic_year_id,
                        is_active, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())
                ");
                
                $stmt->execute([
                    $school['id'],
                    $_POST['name'],
                    $_POST['code'],
                    $_POST['description'] ?? null,
                    $_POST['grade_level'] ?? null,
                    !empty($_POST['class_teacher_id']) ? $_POST['class_teacher_id'] : null,
                    $_POST['capacity'] ?? 40,
                    $_POST['room_number'] ?? null,
                    $_POST['academic_year_id']
                ]);
                
                $classId = $schoolDb->lastInsertId();
                
                // Create audit log
                $auditStmt = $schoolDb->prepare("
                    INSERT INTO audit_logs (
                        school_id, user_id, user_type, action, entity_type,
                        entity_id, new_values, ip_address, user_agent, url, created_at
                    ) VALUES (?, ?, ?, 'create', 'class', ?, ?, ?, ?, ?, NOW())
                ");
                
                $auditStmt->execute([
                    $school['id'],
                    $userId,
                    $userType,
                    $classId,
                    json_encode(['name' => $_POST['name'], 'code' => $_POST['code']]),
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    $_SERVER['HTTP_USER_AGENT'] ?? null,
                    $_SERVER['REQUEST_URI'] ?? null
                ]);
                
                $schoolDb->commit();
                
                $success = true;
                $message = "Class created successfully!";
                
                // Refresh classes data
                $classStmt->execute([$school['id']]);
                $classes = $classStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                $totalClasses = count($classes);
                
                break;
                
            case 'edit_class':
                if (empty($_POST['class_id']) || empty($_POST['name']) || empty($_POST['code'])) {
                    throw new Exception("Class ID, name, and code are required");
                }
                
                // Check if code exists for another class
                $checkStmt = $schoolDb->prepare("
                    SELECT id FROM classes 
                    WHERE school_id = ? AND code = ? AND id != ?
                ");
                $checkStmt->execute([$school['id'], $_POST['code'], $_POST['class_id']]);
                if ($checkStmt->fetch()) {
                    throw new Exception("Class code already exists");
                }
                
                $schoolDb->beginTransaction();
                
                $stmt = $schoolDb->prepare("
                    UPDATE classes 
                    SET name = ?, code = ?, description = ?, grade_level = ?,
                        capacity = ?, room_number = ?, class_teacher_id = ?,
                        is_active = ?, updated_at = NOW()
                    WHERE id = ? AND school_id = ?
                ");
                
                $stmt->execute([
                    $_POST['name'],
                    $_POST['code'],
                    $_POST['description'] ?? null,
                    $_POST['grade_level'] ?? null,
                    $_POST['capacity'] ?? 40,
                    $_POST['room_number'] ?? null,
                    !empty($_POST['class_teacher_id']) ? $_POST['class_teacher_id'] : null,
                    isset($_POST['is_active']) ? 1 : 0,
                    $_POST['class_id'],
                    $school['id']
                ]);
                
                // Create audit log
                $auditStmt = $schoolDb->prepare("
                    INSERT INTO audit_logs (
                        school_id, user_id, user_type, action, entity_type,
                        entity_id, new_values, ip_address, user_agent, url, created_at
                    ) VALUES (?, ?, ?, 'update', 'class', ?, ?, ?, ?, ?, NOW())
                ");
                
                $auditStmt->execute([
                    $school['id'],
                    $userId,
                    $userType,
                    $_POST['class_id'],
                    json_encode(['updated_fields' => array_keys($_POST)]),
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    $_SERVER['HTTP_USER_AGENT'] ?? null,
                    $_SERVER['REQUEST_URI'] ?? null
                ]);
                
                $schoolDb->commit();
                
                $success = true;
                $message = "Class updated successfully!";
                
                // Refresh classes data
                $classStmt->execute([$school['id']]);
                $classes = $classStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                
                break;
                
            case 'delete_class':
                if (empty($_POST['class_id'])) {
                    throw new Exception("Class ID is required");
                }
                
                // Check if class has sections
                $sectionCheck = $schoolDb->prepare("
                    SELECT COUNT(*) as count FROM sections 
                    WHERE class_id = ? AND school_id = ?
                ");
                $sectionCheck->execute([$_POST['class_id'], $school['id']]);
                $sectionCount = $sectionCheck->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
                
                if ($sectionCount > 0) {
                    throw new Exception("Cannot delete class with existing sections. Please delete or reassign sections first.");
                }
                
                $schoolDb->beginTransaction();
                
                // Get class data for audit log
                $getStmt = $schoolDb->prepare("SELECT name, code FROM classes WHERE id = ?");
                $getStmt->execute([$_POST['class_id']]);
                $classData = $getStmt->fetch(PDO::FETCH_ASSOC);
                
                // Soft delete - just mark as inactive
                $stmt = $schoolDb->prepare("
                    UPDATE classes 
                    SET is_active = 0, updated_at = NOW() 
                    WHERE id = ? AND school_id = ?
                ");
                $stmt->execute([$_POST['class_id'], $school['id']]);
                
                // Create audit log
                $auditStmt = $schoolDb->prepare("
                    INSERT INTO audit_logs (
                        school_id, user_id, user_type, action, entity_type,
                        entity_id, old_values, ip_address, user_agent, url, created_at
                    ) VALUES (?, ?, ?, 'delete', 'class', ?, ?, ?, ?, ?, NOW())
                ");
                
                $auditStmt->execute([
                    $school['id'],
                    $userId,
                    $userType,
                    $_POST['class_id'],
                    json_encode($classData),
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    $_SERVER['HTTP_USER_AGENT'] ?? null,
                    $_SERVER['REQUEST_URI'] ?? null
                ]);
                
                $schoolDb->commit();
                
                $success = true;
                $message = "Class deleted successfully!";
                
                // Refresh classes data
                $classStmt->execute([$school['id']]);
                $classes = $classStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                $totalClasses = count($classes);
                
                break;
                
            default:
                throw new Exception("Unknown action");
        }
        
    } catch (Exception $e) {
        if ($schoolDb && $schoolDb->inTransaction()) {
            $schoolDb->rollBack();
        }
        $error = $e->getMessage();
        error_log("Error processing class action: " . $error);
    }
}

// Collect toast messages
$toastSuccess = $_SESSION['toast_success'] ?? ($success ? $message : '');
$toastError = $_SESSION['toast_error'] ?? $error;
unset($_SESSION['toast_success'], $_SESSION['toast_error']);

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

// Helper function for sanitization
if (!function_exists('sanitize')) {
    function sanitize($input) {
        if ($input === null) return null;
        return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
    }
}

// Currency symbol
$currencySymbol = $school['currency_symbol'] ?? '₦';

error_log("=== CLASS LIST PAGE END ===");
?>

<!DOCTYPE html>
<html lang="en" data-theme="light">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="description" content="School Class List - Manage all classes and sections">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($school['name']); ?> | <?php echo defined('APP_NAME') ? APP_NAME : 'School Management'; ?> - Class List</title>
    
    <!-- Styles -->
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
        
        .stat-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        .stat-card i {
            font-size: 2.5rem;
            opacity: 0.8;
        }
        .stat-card .stat-value {
            font-size: 1.8rem;
            font-weight: 600;
        }
        .stat-card .stat-label {
            font-size: 0.9rem;
            opacity: 0.9;
        }
        .stat-card:nth-child(2) {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        }
        .stat-card:nth-child(3) {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
        }
        .stat-card:nth-child(4) {
            background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
        }
        
        .class-card {
            background: white;
            border: 1px solid #e9ecef;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            transition: all 0.3s ease;
        }
        .class-card:hover {
            box-shadow: 0 8px 20px rgba(0,0,0,0.06);
            border-color: #25A194;
        }
        .class-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        .class-badge {
            background: #25A194;
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }
        .section-tag {
            background: #f8f9fa;
            color: #495057;
            padding: 4px 10px;
            border-radius: 16px;
            font-size: 12px;
            display: inline-block;
            margin-right: 5px;
            margin-bottom: 5px;
        }
        .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #e6f7f5;
            color: #25A194;
            font-size: 1.2rem;
        }
        
        /* Sidebar styles */
        .my-sidebar {
            transition: transform 0.3s ease;
            transform: translateX(100%);
        }
        .my-sidebar.active {
            transform: translateX(0);
        }
        .edit-sidebar {
            transition: transform 0.3s ease;
            transform: translateX(100%);
        }
        .edit-sidebar.active {
            transform: translateX(0);
        }
        .overlay.active {
            visibility: visible;
            opacity: 1;
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
    <div class="body-overlay"></div>
    <button type="button"
        class="theme-customization__button w-48-px h-48-px bg-primary-600 text-white rounded-circle d-flex justify-content-center align-items-center position-fixed end-0 bottom-0 mb-40 me-40 text-2xxl bg-hover-primary-700" aria-label="Theme Customization Button">
        <i class="ri-settings-3-line animate-spin"></i>
    </button>
    
    <!-- Theme Customization Structure End -->

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
                        <form class="navbar-search">
                            <input type="text" class="bg-transparent" name="search" placeholder="Search classes...">
                            <iconify-icon icon="ion:search-outline" class="icon"></iconify-icon>
                        </form>
                    </div>
                </div>
                <div class="col-auto">
                    <div class="d-flex flex-wrap align-items-center gap-3">
                        <button type="button" data-theme-toggle
                            class="w-40-px h-40-px bg-neutral-200 rounded-circle d-flex justify-content-center align-items-center" aria-label="Dark & Light Mode Button"></button>
                        <div class="dropdown d-inline-block">
                            <button
                                class="has-indicator w-40-px h-40-px bg-neutral-200 rounded-circle d-flex justify-content-center align-items-center position-relative"
                                type="button" data-bs-toggle="dropdown" aria-label="Notification Button">
                                <iconify-icon icon="iconoir:bell" class="text-primary-light text-xl"></iconify-icon>
                                <?php if ($notificationCount > 0): ?>
                                <span class="w-8-px h-8-px bg-danger-600 position-absolute end-0 top-0 rounded-circle mt-2 me-2"></span>
                                <?php endif; ?>
                            </button>
                            <div class="dropdown-menu to-top dropdown-menu-lg p-0">
                                <div
                                    class="m-16 py-12 px-16 radius-8 bg-primary-50 mb-16 d-flex align-items-center justify-content-between gap-2">
                                    <div>
                                        <h6 class="text-lg text-primary-light fw-semibold mb-0">Notifications</h6>
                                    </div>
                                    <span
                                        class="text-primary-600 fw-semibold text-lg w-40-px h-40-px rounded-circle bg-base d-flex justify-content-center align-items-center"><?php echo str_pad($notificationCount, 2, '0', STR_PAD_LEFT); ?></span>
                                </div>

                                <div class="max-h-400-px overflow-y-auto scroll-sm pe-4">
                                    <?php if (!empty($notifications)): ?>
                                        <?php foreach ($notifications as $notification): ?>
                                        <a href="javascript:void(0)"
                                            class="px-24 py-12 d-flex align-items-start gap-3 mb-2 justify-content-between <?php echo !$notification['is_read'] ? 'bg-neutral-50' : ''; ?>">
                                            <div class="text-black hover-bg-transparent hover-text-primary d-flex align-items-center gap-3">
                                                <span
                                                    class="w-44-px h-44-px bg-<?php echo $notification['type'] == 'success' ? 'success' : ($notification['type'] == 'error' ? 'danger' : 'info'); ?>-subtle text-<?php echo $notification['type'] == 'success' ? 'success' : ($notification['type'] == 'error' ? 'danger' : 'info'); ?>-main rounded-circle d-flex justify-content-center align-items-center flex-shrink-0">
                                                    <i class="ri-<?php echo $notification['icon'] ?? 'notification-line'; ?> text-xl"></i>
                                                </span>
                                                <div>
                                                    <h6 class="text-md fw-semibold mb-4"><?php echo htmlspecialchars($notification['title']); ?></h6>
                                                    <p class="mb-0 text-sm text-secondary-light text-w-200-px"><?php echo htmlspecialchars($notification['message']); ?></p>
                                                </div>
                                            </div>
                                            <span class="text-sm text-secondary-light flex-shrink-0"><?php echo date('d M', strtotime($notification['created_at'])); ?></span>
                                        </a>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div class="text-center py-20">
                                            <p class="text-secondary-light">No notifications</p>
                                        </div>
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
            <!-- Breadcrumb -->
            <div class="breadcrumb d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
                <div class="">
                    <h1 class="fw-semibold mb-4 h6 text-primary-light">Class List</h1>
                    <div class="">
                        <a href="index.php" class="text-secondary-light hover-text-primary hover-underline">Dashboard</a>
                        <span class="text-secondary-light">/ Classes</span>
                    </div>
                </div>
                <button type="button" class="my-sidebar-btn btn btn-primary-600 d-flex align-items-center gap-6">
                    <span class="d-flex text-md">
                        <i class="ri-add-large-line"></i>
                    </span>
                    Add New Class
                </button>
            </div>

            <!-- Quick Stats -->
            <div class="row mb-24">
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <div class="stat-value"><?php echo $totalClasses; ?></div>
                                <div class="stat-label">Total Classes</div>
                            </div>
                            <i class="ri-school-line"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <div class="stat-value"><?php echo $totalSections; ?></div>
                                <div class="stat-label">Total Sections</div>
                            </div>
                            <i class="ri-grid-line"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <div class="stat-value"><?php echo $totalStudents; ?></div>
                                <div class="stat-label">Total Students</div>
                            </div>
                            <i class="ri-group-line"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <div class="stat-value"><?php echo $totalTeachers; ?></div>
                                <div class="stat-label">Total Teachers</div>
                            </div>
                            <i class="ri-user-star-line"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filter Section -->
            <div class="card mb-24">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-md-4">
                            <select class="form-select" id="academicYearFilter">
                                <option value="">All Academic Years</option>
                                <?php foreach ($academicYears as $year): ?>
                                <option value="<?php echo $year['id']; ?>" <?php echo (!empty($year['is_default']) && $year['is_default'] == 1) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($year['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <select class="form-select" id="statusFilter">
                                <option value="">All Status</option>
                                <option value="1">Active</option>
                                <option value="0">Inactive</option>
                            </select>
                        </div>
                        <div class="col-md-4 text-md-end">
                            <button type="button" class="btn btn-outline-primary" onclick="exportToExcel()">
                                <i class="ri-file-excel-line"></i> Export
                            </button>
                            <button type="button" class="btn btn-outline-primary" onclick="printList()">
                                <i class="ri-printer-line"></i> Print
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Classes List -->
            <div class="row" id="classesContainer">
                <?php if (empty($classes)): ?>
                <div class="col-12">
                    <div class="text-center py-5">
                        <i class="ri-school-line fs-1 text-secondary-light mb-3 d-block" style="font-size: 3rem;"></i>
                        <h5>No Classes Found</h5>
                        <p class="text-secondary-light mb-4">Get started by adding your first class</p>
                        <button type="button" class="btn btn-primary-600 my-sidebar-btn">
                            <i class="ri-add-line"></i> Add New Class
                        </button>
                    </div>
                </div>
                <?php else: ?>
                    <?php foreach ($classes as $class): ?>
                    <div class="col-xl-4 col-lg-6 class-item" 
                         data-academic-year="<?php echo $class['academic_year_id']; ?>"
                         data-status="<?php echo $class['is_active']; ?>">
                        <div class="class-card">
                            <div class="class-card-header">
                                <div class="d-flex align-items-center gap-3">
                                    <div class="stat-icon">
                                        <i class="ri-school-line"></i>
                                    </div>
                                    <div>
                                        <h5 class="mb-1"><?php echo htmlspecialchars($class['name']); ?></h5>
                                        <span class="badge bg-info"><?php echo htmlspecialchars($class['code']); ?></span>
                                        <?php if (!empty($class['academic_year_name'])): ?>
                                        <span class="badge bg-secondary ms-2"><?php echo htmlspecialchars($class['academic_year_name']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="dropdown">
                                    <button type="button" class="btn btn-sm btn-light" data-bs-toggle="dropdown">
                                        <i class="ri-more-2-fill"></i>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end">
                                        <li>
                                            <button type="button" class="dropdown-item" onclick="viewClass(<?php echo $class['id']; ?>)">
                                                <i class="ri-eye-line"></i> View Details
                                            </button>
                                        </li>
                                        <li>
                                            <button type="button" class="dropdown-item edit-class-btn" 
                                                    data-class='<?php echo json_encode($class); ?>'>
                                                <i class="ri-edit-line"></i> Edit
                                            </button>
                                        </li>
                                        <li>
                                            <button type="button" class="dropdown-item" onclick="manageSections(<?php echo $class['id']; ?>)">
                                                <i class="ri-grid-line"></i> Manage Sections
                                            </button>
                                        </li>
                                        <li>
                                            <button type="button" class="dropdown-item" onclick="assignSubjects(<?php echo $class['id']; ?>)">
                                                <i class="ri-book-open-line"></i> Assign Subjects
                                            </button>
                                        </li>
                                        <li>
                                            <hr class="dropdown-divider">
                                        </li>
                                        <li>
                                            <button type="button" class="dropdown-item text-danger" 
                                                    onclick="deleteClass(<?php echo $class['id']; ?>, '<?php echo addslashes($class['name']); ?>')">
                                                <i class="ri-delete-bin-line"></i> Delete
                                            </button>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <?php if (!empty($class['section_names'])): ?>
                                    <?php 
                                    $sections = explode(', ', $class['section_names']);
                                    foreach ($sections as $section): 
                                    ?>
                                    <span class="section-tag"><?php echo htmlspecialchars($section); ?></span>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <span class="text-muted">No sections yet</span>
                                <?php endif; ?>
                            </div>
                            
                            <div class="row g-2 mb-3">
                                <div class="col-6">
                                    <div class="d-flex align-items-center gap-2">
                                        <i class="ri-grid-line text-primary"></i>
                                        <span class="text-sm"><?php echo $class['section_count'] ?? 0; ?> Sections</span>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="d-flex align-items-center gap-2">
                                        <i class="ri-group-line text-success"></i>
                                        <span class="text-sm"><?php echo $class['student_count'] ?? 0; ?> Students</span>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="d-flex align-items-center gap-2">
                                        <i class="ri-book-open-line text-info"></i>
                                        <span class="text-sm"><?php echo $class['subject_count'] ?? 0; ?> Subjects</span>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="d-flex align-items-center gap-2">
                                        <i class="ri-user-star-line text-warning"></i>
                                        <span class="text-sm"><?php echo $class['active_students'] ?? 0; ?> Active</span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="border-top pt-3">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <span class="badge <?php echo $class['is_active'] ? 'bg-success' : 'bg-danger'; ?>">
                                            <?php echo $class['is_active'] ? 'Active' : 'Inactive'; ?>
                                        </span>
                                        <?php if (!empty($class['room_number'])): ?>
                                        <span class="text-muted ms-2">
                                            <i class="ri-door-line"></i> <?php echo htmlspecialchars($class['room_number']); ?>
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <span class="text-muted small">Capacity: <?php echo $class['capacity'] ?? 40; ?></span>
                                    </div>
                                </div>
                                <?php if (!empty($class['grade_level'])): ?>
                                <div class="mt-2">
                                    <span class="text-muted small">
                                        <i class="ri-bar-chart-line"></i> Grade Level: <?php echo htmlspecialchars($class['grade_level']); ?>
                                    </span>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <footer class="d-footer">
            <div class="">
                <p class="mb-0 text-center"> &copy; <span class="current-year"></span> <?php echo htmlspecialchars($school['name']); ?> | Made With ❤️ by AcademixSuite.</p>
            </div>
        </footer>
    </main>

    <!-- Add Class Sidebar -->
    <div class="my-sidebar bg-white position-fixed end-0 top-0 h-100vh overflow-y-auto z-99 max-w-700-px w-100">
        <div class="px-20 py-12 border-bottom d-flex align-items-center justify-content-between gap-20">
            <h5 class="text-lg mb-0">Add New Class</h5>
            <button type="button" class="close-my-sidebar text-danger-600 text-lg d-flex">
                <i class="ri-close-large-line"></i>
            </button>
        </div>
        <form method="POST" class="p-20">
            <input type="hidden" name="action" value="create_class">
            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
            
            <div class="row g-3">
                <div class="col-sm-12">
                    <div class="">
                        <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                            Class Name <span class="text-danger-600">*</span>
                        </label>
                        <input type="text" name="name" class="form-control" placeholder="e.g., Grade 10" required>
                    </div>
                </div>
                
                <div class="col-sm-12">
                    <div class="">
                        <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                            Class Code <span class="text-danger-600">*</span>
                        </label>
                        <input type="text" name="code" class="form-control" placeholder="e.g., G10" required>
                        <small class="text-muted">Unique identifier for the class</small>
                    </div>
                </div>
                
                <div class="col-sm-12">
                    <div class="">
                        <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                            Academic Year <span class="text-danger-600">*</span>
                        </label>
                        <select name="academic_year_id" class="form-select" required>
                            <option value="">Select Academic Year</option>
                            <?php foreach ($academicYears as $year): ?>
                            <option value="<?php echo $year['id']; ?>" <?php echo (!empty($year['is_default']) && $year['is_default'] == 1) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($year['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="col-sm-6">
                    <div class="">
                        <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Grade Level</label>
                        <input type="text" name="grade_level" class="form-control" placeholder="e.g., Secondary">
                    </div>
                </div>
                
                <div class="col-sm-6">
                    <div class="">
                        <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Capacity</label>
                        <input type="number" name="capacity" class="form-control" value="40" min="1">
                    </div>
                </div>
                
                <div class="col-sm-12">
                    <div class="">
                        <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Room Number</label>
                        <input type="text" name="room_number" class="form-control" placeholder="e.g., A101">
                    </div>
                </div>
                
                <div class="col-sm-12">
                    <div class="">
                        <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Class Teacher (Optional)</label>
                        <select name="class_teacher_id" class="form-select">
                            <option value="">Select Teacher</option>
                            <?php foreach ($teachers as $teacher): ?>
                            <option value="<?php echo $teacher['id']; ?>"><?php echo htmlspecialchars($teacher['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="col-sm-12">
                    <div class="">
                        <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Description</label>
                        <textarea name="description" class="form-control" rows="3" placeholder="Class description..."></textarea>
                    </div>
                </div>
                
                <div class="col-12">
                    <div class="d-flex align-items-center justify-content-center gap-3 mt-8">
                        <button type="button" class="border border-danger-600 bg-hover-danger-200 text-danger-600 text-md px-50 py-11 radius-8 close-my-sidebar">
                            Cancel
                        </button>
                        <button type="submit" class="btn btn-primary-600 border border-primary-600 text-md px-28 py-12 radius-8">
                            Create Class
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <!-- Edit Class Sidebar -->
    <div class="edit-sidebar bg-white position-fixed end-0 top-0 h-100vh overflow-y-auto z-99 max-w-700-px w-100">
        <div class="px-20 py-12 border-bottom d-flex align-items-center justify-content-between gap-20">
            <h5 class="text-lg mb-0">Edit Class</h5>
            <button type="button" class="close-edit-sidebar text-danger-600 text-lg d-flex">
                <i class="ri-close-large-line"></i>
            </button>
        </div>
        <form method="POST" class="p-20" id="editClassForm">
            <input type="hidden" name="action" value="edit_class">
            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
            <input type="hidden" name="class_id" id="edit_class_id">
            
            <div class="row g-3">
                <div class="col-sm-12">
                    <div class="">
                        <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                            Class Name <span class="text-danger-600">*</span>
                        </label>
                        <input type="text" name="name" id="edit_name" class="form-control" required>
                    </div>
                </div>
                
                <div class="col-sm-12">
                    <div class="">
                        <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                            Class Code <span class="text-danger-600">*</span>
                        </label>
                        <input type="text" name="code" id="edit_code" class="form-control" required>
                    </div>
                </div>
                
                <div class="col-sm-6">
                    <div class="">
                        <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Grade Level</label>
                        <input type="text" name="grade_level" id="edit_grade_level" class="form-control">
                    </div>
                </div>
                
                <div class="col-sm-6">
                    <div class="">
                        <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Capacity</label>
                        <input type="number" name="capacity" id="edit_capacity" class="form-control" min="1">
                    </div>
                </div>
                
                <div class="col-sm-12">
                    <div class="">
                        <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Room Number</label>
                        <input type="text" name="room_number" id="edit_room_number" class="form-control">
                    </div>
                </div>
                
                <div class="col-sm-12">
                    <div class="">
                        <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Class Teacher</label>
                        <select name="class_teacher_id" id="edit_class_teacher_id" class="form-select">
                            <option value="">Select Teacher</option>
                            <?php foreach ($teachers as $teacher): ?>
                            <option value="<?php echo $teacher['id']; ?>"><?php echo htmlspecialchars($teacher['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="col-sm-12">
                    <div class="">
                        <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Description</label>
                        <textarea name="description" id="edit_description" class="form-control" rows="3"></textarea>
                    </div>
                </div>
                
                <div class="col-sm-12">
                    <div class="form-check">
                        <input type="checkbox" name="is_active" class="form-check-input" id="edit_is_active" value="1">
                        <label class="form-check-label" for="edit_is_active">Active Class</label>
                    </div>
                </div>
                
                <div class="col-12">
                    <div class="d-flex align-items-center justify-content-center gap-3 mt-8">
                        <button type="button" class="border border-danger-600 bg-hover-danger-200 text-danger-600 text-md px-50 py-11 radius-8 close-edit-sidebar">
                            Cancel
                        </button>
                        <button type="submit" class="btn btn-primary-600 border border-primary-600 text-md px-28 py-12 radius-8">
                            Update Class
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteClassModal" tabindex="-1">
        <div class="modal-dialog modal-sm modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-body pt-32 px-36 pb-24 text-center">
                    <span class="mb-16 fs-1 line-height-1 text-danger">
                        <i class="ri-delete-bin-line" style="font-size: 48px;"></i>
                    </span>
                    <h6 class="text-lg fw-semibold text-primary-light mb-8">Delete Class</h6>
                    <p class="mb-24" id="deleteClassMessage">Are you sure you want to delete this class?</p>
                    <form method="POST" id="deleteClassForm">
                        <input type="hidden" name="action" value="delete_class">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                        <input type="hidden" name="class_id" id="delete_class_id">
                        <div class="d-flex align-items-center justify-content-center gap-3">
                            <button type="button" class="border border-danger-600 bg-hover-danger-200 text-danger-600 text-md px-24 py-11 radius-8" data-bs-dismiss="modal">
                                Cancel
                            </button>
                            <button type="submit" class="btn btn-danger-600 border border-danger-600 text-md px-24 py-12 radius-8">
                                Yes, Delete
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://academixsuite.com/tenant/assets/js/lib/jquery-3.7.1.min.js"></script>
    <script src="https://academixsuite.com/tenant/assets/js/lib/bootstrap.bundle.min.js"></script>
    <script src="https://academixsuite.com/tenant/assets/js/lib/apexcharts.min.js"></script>
    <script src="https://academixsuite.com/tenant/assets/js/lib/iconify-icon.min.js"></script>
    <script src="https://academixsuite.com/tenant/assets/js/lib/dataTables.min.js"></script>
    <script src="https://academixsuite.com/tenant/assets/js/lib/jquery-ui.min.js"></script>
    <script src="https://academixsuite.com/tenant/assets/js/lib/flatpickr.min.js"></script>
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

            // Sidebar toggles
            $('.my-sidebar-btn').on('click', function () {
                $('.my-sidebar').addClass('active');
                $('.overlay').addClass('active');
            });
            $('.close-my-sidebar, .overlay').on('click', function () {
                $('.my-sidebar').removeClass('active');
                $('.overlay').removeClass('active');
            });

            // Edit sidebar
            $('.edit-class-btn').on('click', function () {
                const classData = $(this).data('class');
                
                // Populate form
                $('#edit_class_id').val(classData.id);
                $('#edit_name').val(classData.name);
                $('#edit_code').val(classData.code);
                $('#edit_grade_level').val(classData.grade_level || '');
                $('#edit_capacity').val(classData.capacity || 40);
                $('#edit_room_number').val(classData.room_number || '');
                $('#edit_description').val(classData.description || '');
                $('#edit_is_active').prop('checked', classData.is_active == 1);
                
                // Select class teacher
                if (classData.class_teacher_id) {
                    $('#edit_class_teacher_id').val(classData.class_teacher_id);
                } else {
                    $('#edit_class_teacher_id').val('');
                }
                
                $('.edit-sidebar').addClass('active');
                $('.overlay').addClass('active');
            });
            
            $('.close-edit-sidebar, .overlay').on('click', function () {
                $('.edit-sidebar').removeClass('active');
                $('.overlay').removeClass('active');
            });

            // Filter functionality
            $('#academicYearFilter, #statusFilter').on('change', function() {
                const academicYear = $('#academicYearFilter').val();
                const status = $('#statusFilter').val();
                
                $('.class-item').each(function() {
                    const itemAcademicYear = $(this).data('academic-year');
                    const itemStatus = $(this).data('status');
                    
                    let show = true;
                    
                    if (academicYear && itemAcademicYear != academicYear) {
                        show = false;
                    }
                    
                    if (status !== '' && itemStatus != status) {
                        show = false;
                    }
                    
                    $(this).toggle(show);
                });
            });

            // Search functionality
            $('.navbar-search input').on('keyup', function() {
                const searchTerm = $(this).val().toLowerCase();
                
                $('.class-card').each(function() {
                    const className = $(this).find('h5').text().toLowerCase();
                    const classCode = $(this).find('.badge:first').text().toLowerCase();
                    
                    if (className.includes(searchTerm) || classCode.includes(searchTerm)) {
                        $(this).closest('.class-item').show();
                    } else {
                        $(this).closest('.class-item').hide();
                    }
                });
            });
        });

        // View class details
        function viewClass(classId) {
            window.location.href = 'class-details.php?id=' + classId;
        }

        // Manage sections
        function manageSections(classId) {
            window.location.href = 'section-list.php?class_id=' + classId;
        }

        // Assign subjects
        function assignSubjects(classId) {
            window.location.href = 'assign-subjects.php?class_id=' + classId;
        }

        // Delete class
        function deleteClass(classId, className) {
            $('#delete_class_id').val(classId);
            $('#deleteClassMessage').text('Are you sure you want to delete "' + className + '"? This will not delete sections or students but will mark the class as inactive.');
            $('#deleteClassModal').modal('show');
        }

        // Export to Excel
        function exportToExcel() {
            let csv = "Name,Code,Grade Level,Sections,Students,Room,Status\n";
            
            $('.class-item').each(function() {
                const card = $(this).find('.class-card');
                const name = card.find('h5').text().trim();
                const code = card.find('.badge:first').text().trim();
                const gradeLevel = card.find('.mt-2 .text-muted').text().replace('Grade Level:', '').trim() || 'N/A';
                const sections = card.find('.stat-icon').first().closest('.row').find('.col-6:first span').text().replace('Sections', '').trim();
                const students = card.find('.stat-icon').first().closest('.row').find('.col-6:eq(1) span').text().replace('Students', '').trim();
                const room = card.find('.border-top .badge + .text-muted i').parent().text().trim() || 'N/A';
                const status = card.find('.border-top .badge:first').text().trim();
                
                csv += `"${name}","${code}","${gradeLevel}","${sections}","${students}","${room}","${status}"\n`;
            });
            
            const blob = new Blob([csv], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'class-list.csv';
            a.click();
            window.URL.revokeObjectURL(url);
        }

        // Print list
        function printList() {
            const printWindow = window.open('', '_blank');
            printWindow.document.write(`
                <html>
                <head>
                    <title>Class List - <?php echo htmlspecialchars($school['name']); ?></title>
                    <style>
                        body { font-family: Arial, sans-serif; padding: 20px; }
                        h1 { color: #25A194; }
                        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                        th { background: #f8f9fa; text-align: left; padding: 12px; }
                        td { padding: 10px; border-bottom: 1px solid #dee2e6; }
                        .badge { 
                            display: inline-block; 
                            padding: 3px 8px; 
                            border-radius: 12px; 
                            font-size: 12px;
                            background: #e9ecef;
                        }
                        .badge-success { background: #d4edda; color: #155724; }
                        .badge-danger { background: #f8d7da; color: #721c24; }
                    </style>
                </head>
                <body>
                    <h1><?php echo htmlspecialchars($school['name']); ?></h1>
                    <h2>Class List</h2>
                    <p>Generated on: ${new Date().toLocaleString()}</p>
                    
                    <table>
                        <thead>
                            <tr>
                                <th>Class Name</th>
                                <th>Code</th>
                                <th>Grade Level</th>
                                <th>Sections</th>
                                <th>Students</th>
                                <th>Room</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
            `);
            
            $('.class-item').each(function() {
                const card = $(this).find('.class-card');
                const name = card.find('h5').text().trim();
                const code = card.find('.badge:first').text().trim();
                const gradeLevel = card.find('.mt-2 .text-muted').text().replace('Grade Level:', '').trim() || 'N/A';
                const sections = card.find('.stat-icon').first().closest('.row').find('.col-6:first span').text().replace('Sections', '').trim();
                const students = card.find('.stat-icon').first().closest('.row').find('.col-6:eq(1) span').text().replace('Students', '').trim();
                const room = card.find('.border-top .badge + .text-muted i').parent().text().trim() || 'N/A';
                const status = card.find('.border-top .badge:first').text().trim();
                const statusClass = status === 'Active' ? 'badge-success' : 'badge-danger';
                
                printWindow.document.write(`
                    <tr>
                        <td>${name}</td>
                        <td><span class="badge">${code}</span></td>
                        <td>${gradeLevel}</td>
                        <td>${sections}</td>
                        <td>${students}</td>
                        <td>${room}</td>
                        <td><span class="badge ${statusClass}">${status}</span></td>
                    </tr>
                `);
            });
            
            printWindow.document.write(`
                        </tbody>
                    </table>
                </body>
                </html>
            `);
            
            printWindow.document.close();
            printWindow.print();
        }
    </script>
</body>
</html>