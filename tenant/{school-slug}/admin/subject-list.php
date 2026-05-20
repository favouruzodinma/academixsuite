<?php
/**
 * School Subjects List Page
 * Displays and manages all subjects in the school
 * 
 * @package AcademixSuite
 * @version 2.0
 */

// Enable error reporting for debugging
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../../logs/school_subjects_list.log');

error_log("=== SUBJECTS LIST PAGE START ===");
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
$currentPage = $GLOBALS['CURRENT_PAGE'] ?? 'subject-list.php';
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
    
    // Include GuardianManager for notifications
    $guardianManagerPath = __DIR__ . '/../../../includes/GuardianManager.php';
    if (file_exists($guardianManagerPath)) {
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
 * Initialize notification variables
 */
$notificationCount = 0;
$notifications = [];

if ($schoolDb && class_exists('GuardianManager')) {
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

/**
 * Subject types array
 */
$subjectTypes = [
    'core' => 'Core Subject',
    'elective' => 'Elective Subject',
    'extra_curricular' => 'Extra Curricular',
    'vocational' => 'Vocational',
    'language' => 'Language'
];

/**
 * Fetch subjects with their statistics
 */
$subjects = [];
$totalSubjects = 0;
$totalCore = 0;
$totalElective = 0;
$totalClasses = 0;
$totalTeachers = 0;

if ($schoolDb) {
    try {
        // Get subjects with class assignments count
        $subjectStmt = $schoolDb->prepare("
            SELECT 
                s.*,
                COUNT(DISTINCT cs.class_id) as class_count,
                COUNT(DISTINCT cs.teacher_id) as teacher_count,
                GROUP_CONCAT(DISTINCT c.name ORDER BY c.name SEPARATOR ', ') as assigned_classes
            FROM subjects s
            LEFT JOIN class_subjects cs ON s.id = cs.subject_id
            LEFT JOIN classes c ON cs.class_id = c.id
            WHERE s.school_id = ?
            GROUP BY s.id
            ORDER BY s.name
        ");
        $subjectStmt->execute([$school['id']]);
        $subjects = $subjectStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        
        // Calculate totals
        $totalSubjects = count($subjects);
        foreach ($subjects as $subject) {
            if ($subject['type'] == 'core') {
                $totalCore++;
            } elseif ($subject['type'] == 'elective') {
                $totalElective++;
            }
            $totalClasses += $subject['class_count'] ?? 0;
            $totalTeachers += $subject['teacher_count'] ?? 0;
        }
        
        // Get total teachers count
        $teacherStmt = $schoolDb->prepare("
            SELECT COUNT(*) as count FROM users 
            WHERE school_id = ? AND user_type = 'teacher' AND is_active = 1
        ");
        $teacherStmt->execute([$school['id']]);
        $totalTeachersCount = $teacherStmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
        
        error_log("Fetched " . count($subjects) . " subjects successfully");
        
    } catch (Exception $e) {
        error_log("Error fetching subjects: " . $e->getMessage());
        $_SESSION['toast_error'] = "Error loading subject data.";
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
            case 'create_subject':
                // Validate required fields
                if (empty($_POST['name']) || empty($_POST['code'])) {
                    throw new Exception("Subject name and code are required");
                }
                
                // Check if subject code already exists
                $checkStmt = $schoolDb->prepare("
                    SELECT id FROM subjects 
                    WHERE school_id = ? AND code = ?
                ");
                $checkStmt->execute([$school['id'], $_POST['code']]);
                if ($checkStmt->fetch()) {
                    throw new Exception("Subject code already exists");
                }
                
                $schoolDb->beginTransaction();
                
                $stmt = $schoolDb->prepare("
                    INSERT INTO subjects (
                        school_id, name, code, type, description,
                        credit_hours, is_active, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                
                $stmt->execute([
                    $school['id'],
                    $_POST['name'],
                    $_POST['code'],
                    $_POST['type'] ?? 'core',
                    $_POST['description'] ?? null,
                    $_POST['credit_hours'] ?? 1.0,
                    isset($_POST['is_active']) ? 1 : 1
                ]);
                
                $subjectId = $schoolDb->lastInsertId();
                
                // Create audit log
                $auditStmt = $schoolDb->prepare("
                    INSERT INTO audit_logs (
                        school_id, user_id, user_type, action, entity_type,
                        entity_id, new_values, ip_address, user_agent, url, created_at
                    ) VALUES (?, ?, ?, 'create', 'subject', ?, ?, ?, ?, ?, NOW())
                ");
                
                $auditStmt->execute([
                    $school['id'],
                    $userId,
                    $userType,
                    $subjectId,
                    json_encode(['name' => $_POST['name'], 'code' => $_POST['code']]),
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    $_SERVER['HTTP_USER_AGENT'] ?? null,
                    $_SERVER['REQUEST_URI'] ?? null
                ]);
                
                $schoolDb->commit();
                
                $success = true;
                $message = "Subject created successfully!";
                
                // Refresh subjects data
                $subjectStmt->execute([$school['id']]);
                $subjects = $subjectStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                $totalSubjects = count($subjects);
                
                break;
                
            case 'edit_subject':
                if (empty($_POST['subject_id']) || empty($_POST['name']) || empty($_POST['code'])) {
                    throw new Exception("Subject ID, name, and code are required");
                }
                
                // Check if code exists for another subject
                $checkStmt = $schoolDb->prepare("
                    SELECT id FROM subjects 
                    WHERE school_id = ? AND code = ? AND id != ?
                ");
                $checkStmt->execute([$school['id'], $_POST['code'], $_POST['subject_id']]);
                if ($checkStmt->fetch()) {
                    throw new Exception("Subject code already exists");
                }
                
                $schoolDb->beginTransaction();
                
                $stmt = $schoolDb->prepare("
                    UPDATE subjects 
                    SET name = ?, code = ?, type = ?, description = ?,
                        credit_hours = ?, is_active = ?, updated_at = NOW()
                    WHERE id = ? AND school_id = ?
                ");
                
                $stmt->execute([
                    $_POST['name'],
                    $_POST['code'],
                    $_POST['type'] ?? 'core',
                    $_POST['description'] ?? null,
                    $_POST['credit_hours'] ?? 1.0,
                    isset($_POST['is_active']) ? 1 : 0,
                    $_POST['subject_id'],
                    $school['id']
                ]);
                
                // Create audit log
                $auditStmt = $schoolDb->prepare("
                    INSERT INTO audit_logs (
                        school_id, user_id, user_type, action, entity_type,
                        entity_id, new_values, ip_address, user_agent, url, created_at
                    ) VALUES (?, ?, ?, 'update', 'subject', ?, ?, ?, ?, ?, NOW())
                ");
                
                $auditStmt->execute([
                    $school['id'],
                    $userId,
                    $userType,
                    $_POST['subject_id'],
                    json_encode(['updated_fields' => array_keys($_POST)]),
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    $_SERVER['HTTP_USER_AGENT'] ?? null,
                    $_SERVER['REQUEST_URI'] ?? null
                ]);
                
                $schoolDb->commit();
                
                $success = true;
                $message = "Subject updated successfully!";
                
                // Refresh subjects data
                $subjectStmt->execute([$school['id']]);
                $subjects = $subjectStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                
                break;
                
            case 'delete_subject':
                if (empty($_POST['subject_id'])) {
                    throw new Exception("Subject ID is required");
                }
                
                // Check if subject is assigned to any classes
                $assignmentCheck = $schoolDb->prepare("
                    SELECT COUNT(*) as count FROM class_subjects 
                    WHERE subject_id = ?
                ");
                $assignmentCheck->execute([$_POST['subject_id']]);
                $assignmentCount = $assignmentCheck->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
                
                if ($assignmentCount > 0) {
                    throw new Exception("Cannot delete subject that is assigned to classes. Please remove all assignments first.");
                }
                
                $schoolDb->beginTransaction();
                
                // Get subject data for audit log
                $getStmt = $schoolDb->prepare("SELECT name, code FROM subjects WHERE id = ?");
                $getStmt->execute([$_POST['subject_id']]);
                $subjectData = $getStmt->fetch(PDO::FETCH_ASSOC);
                
                // Soft delete - just mark as inactive
                $stmt = $schoolDb->prepare("
                    UPDATE subjects 
                    SET is_active = 0, updated_at = NOW() 
                    WHERE id = ? AND school_id = ?
                ");
                $stmt->execute([$_POST['subject_id'], $school['id']]);
                
                // Create audit log
                $auditStmt = $schoolDb->prepare("
                    INSERT INTO audit_logs (
                        school_id, user_id, user_type, action, entity_type,
                        entity_id, old_values, ip_address, user_agent, url, created_at
                    ) VALUES (?, ?, ?, 'delete', 'subject', ?, ?, ?, ?, ?, NOW())
                ");
                
                $auditStmt->execute([
                    $school['id'],
                    $userId,
                    $userType,
                    $_POST['subject_id'],
                    json_encode($subjectData),
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    $_SERVER['HTTP_USER_AGENT'] ?? null,
                    $_SERVER['REQUEST_URI'] ?? null
                ]);
                
                $schoolDb->commit();
                
                $success = true;
                $message = "Subject deleted successfully!";
                
                // Refresh subjects data
                $subjectStmt->execute([$school['id']]);
                $subjects = $subjectStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                $totalSubjects = count($subjects);
                
                break;
                
            default:
                throw new Exception("Unknown action");
        }
        
    } catch (Exception $e) {
        if ($schoolDb && $schoolDb->inTransaction()) {
            $schoolDb->rollBack();
        }
        $error = $e->getMessage();
        error_log("Error processing subject action: " . $error);
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

error_log("=== SUBJECTS LIST PAGE END ===");
?>

<!DOCTYPE html>
<html lang="en" data-theme="light">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="description" content="School Subjects List - Manage all subjects offered in the school">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($school['name']); ?> | <?php echo defined('APP_NAME') ? APP_NAME : 'School Management'; ?> - Subjects List</title>
    
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
        
        .subject-type-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }
        .type-core {
            background: #e3f2fd;
            color: #1976d2;
        }
        .type-elective {
            background: #f3e5f5;
            color: #7b1fa2;
        }
        .type-extra {
            background: #e8f5e8;
            color: #2e7d32;
        }
        .type-vocational {
            background: #fff3e0;
            color: #f57c00;
        }
        .type-language {
            background: #ffebee;
            color: #c62828;
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
        
        .table td, .table th {
            vertical-align: middle;
        }
        
        .action-buttons {
            white-space: nowrap;
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
                            <input type="text" class="bg-transparent" name="search" placeholder="Search subjects...">
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
                    <h1 class="fw-semibold mb-4 h6 text-primary-light">Subjects List</h1>
                    <div class="">
                        <a href="index.php" class="text-secondary-light hover-text-primary hover-underline">Dashboard</a>
                        <span class="text-secondary-light">/ Subjects</span>
                    </div>
                </div>
                <button type="button" class="my-sidebar-btn btn btn-primary-600 d-flex align-items-center gap-6">
                    <span class="d-flex text-md">
                        <i class="ri-add-large-line"></i>
                    </span>
                    Add New Subject
                </button>
            </div>

            <!-- Quick Stats -->
            <div class="row mb-24">
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <div class="stat-value"><?php echo $totalSubjects; ?></div>
                                <div class="stat-label">Total Subjects</div>
                            </div>
                            <i class="ri-book-open-line"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <div class="stat-value"><?php echo $totalCore; ?></div>
                                <div class="stat-label">Core Subjects</div>
                            </div>
                            <i class="ri-star-line"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <div class="stat-value"><?php echo $totalElective; ?></div>
                                <div class="stat-label">Elective Subjects</div>
                            </div>
                            <i class="ri-stack-line"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <div class="stat-value"><?php echo $totalTeachersCount; ?></div>
                                <div class="stat-label">Available Teachers</div>
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
                        <div class="col-md-3">
                            <select class="form-select" id="typeFilter">
                                <option value="">All Subject Types</option>
                                <?php foreach ($subjectTypes as $key => $value): ?>
                                <option value="<?php echo $key; ?>"><?php echo $value; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <select class="form-select" id="statusFilter">
                                <option value="">All Status</option>
                                <option value="1">Active</option>
                                <option value="0">Inactive</option>
                            </select>
                        </div>
                        <div class="col-md-6 text-md-end">
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

            <!-- Subjects Table -->
            <div class="card h-100">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table bordered-table mb-0" id="subjectsTable">
                            <thead>
                                <tr>
                                    <th scope="col" width="50">S.L</th>
                                    <th scope="col">Subject</th>
                                    <th scope="col">Code</th>
                                    <th scope="col">Type</th>
                                    <th scope="col">Credit Hours</th>
                                    <th scope="col">Classes</th>
                                    <th scope="col">Teachers</th>
                                    <th scope="col">Status</th>
                                    <th scope="col" width="120">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($subjects)): ?>
                                <tr>
                                    <td colspan="9" class="text-center py-4">
                                        <i class="ri-book-open-line fs-1 text-secondary-light mb-3 d-block"></i>
                                        <p class="text-secondary-light">No subjects found. Click "Add New Subject" to create your first subject.</p>
                                    </td>
                                </tr>
                                <?php else: ?>
                                    <?php foreach ($subjects as $index => $subject): ?>
                                    <tr data-type="<?php echo $subject['type']; ?>" data-status="<?php echo $subject['is_active']; ?>">
                                        <td>
                                            <div class="form-check style-check d-flex align-items-center">
                                                <input class="form-check-input" type="checkbox" value="<?php echo $subject['id']; ?>">
                                                <label class="form-check-label">
                                                    <?php echo str_pad($index + 1, 2, '0', STR_PAD_LEFT); ?>
                                                </label>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="stat-icon me-2" style="width: 32px; height: 32px; background: #e6f7f5; border-radius: 8px; display: flex; align-items: center; justify-content: center; color: #25A194;">
                                                    <i class="ri-book-open-line"></i>
                                                </div>
                                                <div>
                                                    <strong><?php echo htmlspecialchars($subject['name']); ?></strong>
                                                    <?php if (!empty($subject['description'])): ?>
                                                    <br><small class="text-muted"><?php echo htmlspecialchars(substr($subject['description'], 0, 30)) . '...'; ?></small>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td><span class="badge bg-info"><?php echo htmlspecialchars($subject['code']); ?></span></td>
                                        <td>
                                            <?php
                                            $typeClass = 'type-core';
                                            $typeLabel = 'Core Subject';
                                            if ($subject['type'] == 'elective') {
                                                $typeClass = 'type-elective';
                                                $typeLabel = 'Elective Subject';
                                            } elseif ($subject['type'] == 'extra_curricular') {
                                                $typeClass = 'type-extra';
                                                $typeLabel = 'Extra Curricular';
                                            } elseif ($subject['type'] == 'vocational') {
                                                $typeClass = 'type-vocational';
                                                $typeLabel = 'Vocational';
                                            } elseif ($subject['type'] == 'language') {
                                                $typeClass = 'type-language';
                                                $typeLabel = 'Language';
                                            }
                                            ?>
                                            <span class="subject-type-badge <?php echo $typeClass; ?>">
                                                <?php echo $typeLabel; ?>
                                            </span>
                                        </td>
                                        <td><?php echo number_format($subject['credit_hours'] ?? 1.0, 1); ?></td>
                                        <td>
                                            <?php if ($subject['class_count'] > 0): ?>
                                                <span class="badge bg-success"><?php echo $subject['class_count']; ?> Classes</span>
                                                <br><small class="text-muted"><?php echo htmlspecialchars(substr($subject['assigned_classes'] ?? '', 0, 20)); ?></small>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Not Assigned</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($subject['teacher_count'] > 0): ?>
                                                <span class="badge bg-info"><?php echo $subject['teacher_count']; ?> Teachers</span>
                                            <?php else: ?>
                                                <span class="badge bg-warning">No Teacher</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge <?php echo $subject['is_active'] ? 'bg-success' : 'bg-danger'; ?>">
                                                <?php echo $subject['is_active'] ? 'Active' : 'Inactive'; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="btn-group">
                                                <button type="button" class="text-primary-light text-xl" data-bs-toggle="dropdown">
                                                    <i class="ri-more-2-fill"></i>
                                                </button>
                                                <ul class="dropdown-menu dropdown-menu-end p-12">
                                                    <li>
                                                        <button type="button" class="dropdown-item edit-subject-btn" 
                                                                data-subject='<?php echo json_encode($subject); ?>'>
                                                            <i class="ri-edit-2-line"></i> Edit
                                                        </button>
                                                    </li>
                                                    <li>
                                                        <button type="button" class="dropdown-item" onclick="assignToClass(<?php echo $subject['id']; ?>)">
                                                            <i class="ri-add-circle-line"></i> Assign to Class
                                                        </button>
                                                    </li>
                                                    <li>
                                                        <button type="button" class="dropdown-item" onclick="viewAssignments(<?php echo $subject['id']; ?>)">
                                                            <i class="ri-eye-line"></i> View Assignments
                                                        </button>
                                                    </li>
                                                    <li>
                                                        <hr class="dropdown-divider">
                                                    </li>
                                                    <li>
                                                        <button type="button" class="dropdown-item text-danger" 
                                                                onclick="deleteSubject(<?php echo $subject['id']; ?>, '<?php echo addslashes($subject['name']); ?>')">
                                                            <i class="ri-delete-bin-line"></i> Delete
                                                        </button>
                                                    </li>
                                                </ul>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <footer class="d-footer">
            <div class="">
                <p class="mb-0 text-center"> &copy; <span class="current-year"></span> <?php echo htmlspecialchars($school['name']); ?> | Made With ❤️ by AcademixSuite.</p>
            </div>
        </footer>
    </main>

    <!-- Add Subject Sidebar -->
    <div class="my-sidebar bg-white position-fixed end-0 top-0 h-100vh overflow-y-auto z-99 max-w-700-px w-100">
        <div class="px-20 py-12 border-bottom d-flex align-items-center justify-content-between gap-20">
            <h5 class="text-lg mb-0">Add New Subject</h5>
            <button type="button" class="close-my-sidebar text-danger-600 text-lg d-flex">
                <i class="ri-close-large-line"></i>
            </button>
        </div>
        <form method="POST" class="p-20">
            <input type="hidden" name="action" value="create_subject">
            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
            
            <div class="row g-3">
                <div class="col-sm-12">
                    <div class="">
                        <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                            Subject Name <span class="text-danger-600">*</span>
                        </label>
                        <input type="text" name="name" class="form-control" placeholder="e.g., Mathematics" required>
                    </div>
                </div>
                
                <div class="col-sm-12">
                    <div class="">
                        <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                            Subject Code <span class="text-danger-600">*</span>
                        </label>
                        <input type="text" name="code" class="form-control" placeholder="e.g., MATH101" required>
                        <small class="text-muted">Unique identifier for the subject</small>
                    </div>
                </div>
                
                <div class="col-sm-6">
                    <div class="">
                        <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Subject Type</label>
                        <select name="type" class="form-select">
                            <?php foreach ($subjectTypes as $key => $value): ?>
                            <option value="<?php echo $key; ?>"><?php echo $value; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="col-sm-6">
                    <div class="">
                        <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Credit Hours</label>
                        <input type="number" name="credit_hours" class="form-control" value="1.0" step="0.5" min="0.5">
                    </div>
                </div>
                
                <div class="col-sm-12">
                    <div class="">
                        <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Description</label>
                        <textarea name="description" class="form-control" rows="3" placeholder="Subject description..."></textarea>
                    </div>
                </div>
                
                <div class="col-sm-12">
                    <div class="form-check">
                        <input type="checkbox" name="is_active" class="form-check-input" id="is_active" value="1" checked>
                        <label class="form-check-label" for="is_active">Active Subject</label>
                    </div>
                </div>
                
                <div class="col-12">
                    <div class="d-flex align-items-center justify-content-center gap-3 mt-8">
                        <button type="button" class="border border-danger-600 bg-hover-danger-200 text-danger-600 text-md px-50 py-11 radius-8 close-my-sidebar">
                            Cancel
                        </button>
                        <button type="submit" class="btn btn-primary-600 border border-primary-600 text-md px-28 py-12 radius-8">
                            Create Subject
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <!-- Edit Subject Sidebar -->
    <div class="edit-sidebar bg-white position-fixed end-0 top-0 h-100vh overflow-y-auto z-99 max-w-700-px w-100">
        <div class="px-20 py-12 border-bottom d-flex align-items-center justify-content-between gap-20">
            <h5 class="text-lg mb-0">Edit Subject</h5>
            <button type="button" class="close-edit-sidebar text-danger-600 text-lg d-flex">
                <i class="ri-close-large-line"></i>
            </button>
        </div>
        <form method="POST" class="p-20" id="editSubjectForm">
            <input type="hidden" name="action" value="edit_subject">
            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
            <input type="hidden" name="subject_id" id="edit_subject_id">
            
            <div class="row g-3">
                <div class="col-sm-12">
                    <div class="">
                        <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                            Subject Name <span class="text-danger-600">*</span>
                        </label>
                        <input type="text" name="name" id="edit_name" class="form-control" required>
                    </div>
                </div>
                
                <div class="col-sm-12">
                    <div class="">
                        <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                            Subject Code <span class="text-danger-600">*</span>
                        </label>
                        <input type="text" name="code" id="edit_code" class="form-control" required>
                    </div>
                </div>
                
                <div class="col-sm-6">
                    <div class="">
                        <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Subject Type</label>
                        <select name="type" id="edit_type" class="form-select">
                            <?php foreach ($subjectTypes as $key => $value): ?>
                            <option value="<?php echo $key; ?>"><?php echo $value; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="col-sm-6">
                    <div class="">
                        <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Credit Hours</label>
                        <input type="number" name="credit_hours" id="edit_credit_hours" class="form-control" step="0.5" min="0.5">
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
                        <label class="form-check-label" for="edit_is_active">Active Subject</label>
                    </div>
                </div>
                
                <div class="col-12">
                    <div class="d-flex align-items-center justify-content-center gap-3 mt-8">
                        <button type="button" class="border border-danger-600 bg-hover-danger-200 text-danger-600 text-md px-50 py-11 radius-8 close-edit-sidebar">
                            Cancel
                        </button>
                        <button type="submit" class="btn btn-primary-600 border border-primary-600 text-md px-28 py-12 radius-8">
                            Update Subject
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteSubjectModal" tabindex="-1">
        <div class="modal-dialog modal-sm modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-body pt-32 px-36 pb-24 text-center">
                    <span class="mb-16 fs-1 line-height-1 text-danger">
                        <i class="ri-delete-bin-line" style="font-size: 48px;"></i>
                    </span>
                    <h6 class="text-lg fw-semibold text-primary-light mb-8">Delete Subject</h6>
                    <p class="mb-24" id="deleteSubjectMessage">Are you sure you want to delete this subject?</p>
                    <form method="POST" id="deleteSubjectForm">
                        <input type="hidden" name="action" value="delete_subject">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                        <input type="hidden" name="subject_id" id="delete_subject_id">
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

            // Initialize DataTable
            var table = $('#subjectsTable').DataTable({
                pageLength: 10,
                lengthMenu: [[5, 10, 25, 50, -1], [5, 10, 25, 50, "All"]],
                language: {
                    search: "Search:",
                    lengthMenu: "Show _MENU_ entries",
                    info: "Showing _START_ to _END_ of _TOTAL_ entries",
                    paginate: {
                        first: "First",
                        last: "Last",
                        next: "Next",
                        previous: "Previous"
                    }
                },
                columnDefs: [
                    { orderable: false, targets: [0, 8] }
                ]
            });

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
            $('.edit-subject-btn').on('click', function () {
                const subjectData = $(this).data('subject');
                
                // Populate form
                $('#edit_subject_id').val(subjectData.id);
                $('#edit_name').val(subjectData.name);
                $('#edit_code').val(subjectData.code);
                $('#edit_type').val(subjectData.type || 'core');
                $('#edit_credit_hours').val(subjectData.credit_hours || 1.0);
                $('#edit_description').val(subjectData.description || '');
                $('#edit_is_active').prop('checked', subjectData.is_active == 1);
                
                $('.edit-sidebar').addClass('active');
                $('.overlay').addClass('active');
            });
            
            $('.close-edit-sidebar, .overlay').on('click', function () {
                $('.edit-sidebar').removeClass('active');
                $('.overlay').removeClass('active');
            });

            // Filter functionality
            $('#typeFilter, #statusFilter').on('change', function() {
                const type = $('#typeFilter').val();
                const status = $('#statusFilter').val();
                
                // DataTables custom filtering
                $.fn.dataTable.ext.search.push(
                    function(settings, data, dataIndex) {
                        const row = table.row(dataIndex).node();
                        const rowType = $(row).data('type');
                        const rowStatus = $(row).data('status');
                        
                        let typeMatch = true;
                        let statusMatch = true;
                        
                        if (type && rowType != type) {
                            typeMatch = false;
                        }
                        
                        if (status !== '' && rowStatus != status) {
                            statusMatch = false;
                        }
                        
                        return typeMatch && statusMatch;
                    }
                );
                
                table.draw();
                $.fn.dataTable.ext.search.pop();
            });

            // Custom search for the navbar search
            $('.navbar-search input').on('keyup', function() {
                table.search(this.value).draw();
            });
        });

        // Delete subject function
        function deleteSubject(subjectId, subjectName) {
            $('#delete_subject_id').val(subjectId);
            $('#deleteSubjectMessage').text('Are you sure you want to delete "' + subjectName + '"? This action cannot be undone and will remove all subject assignments.');
            $('#deleteSubjectModal').modal('show');
        }

        // Assign to class function
        function assignToClass(subjectId) {
            window.location.href = 'assign-subject.php?subject_id=' + subjectId;
        }

        // View assignments function
        function viewAssignments(subjectId) {
            window.location.href = 'subject-assignments.php?subject_id=' + subjectId;
        }

        // Export to Excel
        function exportToExcel() {
            let csv = "Subject Name,Code,Type,Credit Hours,Classes,Teachers,Status\n";
            
            $('#subjectsTable tbody tr').each(function() {
                if ($(this).find('td').length > 1) {
                    const name = $(this).find('td:eq(1) strong').text().trim();
                    const code = $(this).find('td:eq(2)').text().trim();
                    const type = $(this).find('td:eq(3)').text().trim();
                    const creditHours = $(this).find('td:eq(4)').text().trim();
                    const classes = $(this).find('td:eq(5)').text().replace('Classes', '').trim();
                    const teachers = $(this).find('td:eq(6)').text().replace('Teachers', '').trim();
                    const status = $(this).find('td:eq(7)').text().trim();
                    
                    csv += `"${name}","${code}","${type}","${creditHours}","${classes}","${teachers}","${status}"\n`;
                }
            });
            
            const blob = new Blob([csv], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'subjects-list.csv';
            a.click();
            window.URL.revokeObjectURL(url);
        }

        // Print list
        function printList() {
            const printWindow = window.open('', '_blank');
            printWindow.document.write(`
                <html>
                <head>
                    <title>Subjects List - <?php echo htmlspecialchars($school['name']); ?></title>
                    <style>
                        body { font-family: Arial, sans-serif; padding: 20px; }
                        h1 { color: #25A194; }
                        h2 { color: #333; margin-top: 10px; }
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
                        .badge-info { background: #cce5ff; color: #004085; }
                        .badge-warning { background: #fff3cd; color: #856404; }
                        .subject-type {
                            padding: 4px 12px;
                            border-radius: 20px;
                            font-size: 12px;
                            font-weight: 500;
                        }
                    </style>
                </head>
                <body>
                    <h1><?php echo htmlspecialchars($school['name']); ?></h1>
                    <h2>Subjects List</h2>
                    <p>Generated on: ${new Date().toLocaleString()}</p>
                    
                    <table>
                        <thead>
                            <tr>
                                <th>Subject Name</th>
                                <th>Code</th>
                                <th>Type</th>
                                <th>Credit Hours</th>
                                <th>Classes</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
            `);
            
            $('#subjectsTable tbody tr').each(function() {
                if ($(this).find('td').length > 1) {
                    const name = $(this).find('td:eq(1) strong').text().trim();
                    const code = $(this).find('td:eq(2)').text().trim();
                    const type = $(this).find('td:eq(3)').text().trim();
                    const creditHours = $(this).find('td:eq(4)').text().trim();
                    const classes = $(this).find('td:eq(5) .badge:first').text().trim() || 'Not Assigned';
                    const status = $(this).find('td:eq(7) .badge').text().trim();
                    const statusClass = status === 'Active' ? 'badge-success' : 'badge-danger';
                    
                    printWindow.document.write(`
                        <tr>
                            <td>${name}</td>
                            <td><span class="badge badge-info">${code}</span></td>
                            <td><span class="subject-type">${type}</span></td>
                            <td>${creditHours}</td>
                            <td>${classes}</td>
                            <td><span class="badge ${statusClass}">${status}</span></td>
                        </tr>
                    `);
                }
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