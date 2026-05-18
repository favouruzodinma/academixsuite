<?php
/**
 * School Notice Board Page
 * Displays and manages all notices and announcements
 * 
 * @package AcademixSuite
 * @version 2.0
 */

// Enable error reporting for debugging
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../../logs/school_notice_board.log');

error_log("=== NOTICE BOARD PAGE START ===");
error_log("Script: " . __FILE__);

// Define constants if not defined. IS_LOCAL is now self-defining via
// config/constants.php; don't force-true here, that would break production.
defined('APP_NAME') or define('APP_NAME', 'AcademixSuite');

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
$currentPage = $GLOBALS['CURRENT_PAGE'] ?? 'notice-board.php';
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

$currentPage = basename(__FILE__);

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
 * Target audience options
 */
$targetOptions = [
    'all' => 'Everyone',
    'students' => 'Students Only',
    'teachers' => 'Teachers Only',
    'parents' => 'Parents Only',
    'staff' => 'Staff Only',
    'class' => 'Specific Class',
    'section' => 'Specific Section'
];

/**
 * Fetch notices from database
 */
$notices = [];
$totalNotices = 0;
$activeNotices = 0;
$expiredNotices = 0;

if ($schoolDb) {
    try {
        // Get notices with creator info
        $noticeStmt = $schoolDb->prepare("
            SELECT 
                n.*,
                u.name as created_by_name,
                c.name as class_name,
                s.name as section_name,
                DATEDIFF(n.end_date, CURDATE()) as days_remaining,
                CASE 
                    WHEN n.is_published = 0 THEN 'draft'
                    WHEN n.end_date < CURDATE() THEN 'expired'
                    WHEN n.start_date > CURDATE() THEN 'upcoming'
                    ELSE 'active'
                END as status
            FROM announcements n
            LEFT JOIN users u ON n.created_by = u.id
            LEFT JOIN classes c ON n.class_id = c.id
            LEFT JOIN sections s ON n.section_id = s.id
            WHERE n.school_id = ?
            ORDER BY 
                CASE 
                    WHEN n.is_published = 0 THEN 1
                    WHEN n.end_date < CURDATE() THEN 2
                    WHEN n.start_date > CURDATE() THEN 3
                    ELSE 0
                END,
                n.created_at DESC
        ");
        $noticeStmt->execute([$school['id']]);
        $notices = $noticeStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        
        // Calculate statistics
        $totalNotices = count($notices);
        foreach ($notices as $notice) {
            if ($notice['status'] == 'active') {
                $activeNotices++;
            } elseif ($notice['status'] == 'expired') {
                $expiredNotices++;
            }
        }
        
        error_log("Fetched " . count($notices) . " notices successfully");
        
    } catch (Exception $e) {
        error_log("Error fetching notices: " . $e->getMessage());
        // If table doesn't exist, create it
        if (strpos($e->getMessage(), "Table '.*announcements' doesn't exist") !== false) {
            createAnnouncementsTable($schoolDb);
            // Try fetching again
            try {
                $noticeStmt = $schoolDb->prepare("
                    SELECT n.*, u.name as created_by_name 
                    FROM announcements n 
                    LEFT JOIN users u ON n.created_by = u.id 
                    WHERE n.school_id = ? 
                    ORDER BY n.created_at DESC
                ");
                $noticeStmt->execute([$school['id']]);
                $notices = $noticeStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                $totalNotices = count($notices);
            } catch (Exception $e2) {
                error_log("Error after table creation: " . $e2->getMessage());
            }
        } else {
            $_SESSION['toast_error'] = "Error loading notices.";
        }
    }
}

/**
 * Create announcements table if it doesn't exist
 */
function createAnnouncementsTable($db) {
    try {
        $sql = "
            CREATE TABLE IF NOT EXISTS announcements (
                id INT AUTO_INCREMENT PRIMARY KEY,
                school_id INT NOT NULL,
                title VARCHAR(255) NOT NULL,
                description TEXT,
                target VARCHAR(50) DEFAULT 'all',
                class_id INT NULL,
                section_id INT NULL,
                start_date DATE NULL,
                end_date DATE NULL,
                is_published TINYINT DEFAULT 1,
                is_important TINYINT DEFAULT 0,
                created_by INT NULL,
                created_at DATETIME,
                updated_at DATETIME,
                INDEX idx_school_id (school_id),
                INDEX idx_target (target),
                INDEX idx_dates (start_date, end_date),
                INDEX idx_published (is_published)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";
        $db->exec($sql);
        error_log("Announcements table created successfully");
        return true;
    } catch (Exception $e) {
        error_log("Error creating announcements table: " . $e->getMessage());
        return false;
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
            case 'create_notice':
                // Validate required fields
                if (empty($_POST['title'])) {
                    throw new Exception("Notice title is required");
                }
                
                $schoolDb->beginTransaction();
                
                $stmt = $schoolDb->prepare("
                    INSERT INTO announcements (
                        school_id, title, description, target,
                        class_id, section_id, start_date, end_date,
                        is_published, is_important, created_by, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                
                $stmt->execute([
                    $school['id'],
                    $_POST['title'],
                    $_POST['description'] ?? null,
                    $_POST['target'] ?? 'all',
                    !empty($_POST['class_id']) ? $_POST['class_id'] : null,
                    !empty($_POST['section_id']) ? $_POST['section_id'] : null,
                    !empty($_POST['start_date']) ? $_POST['start_date'] : null,
                    !empty($_POST['end_date']) ? $_POST['end_date'] : null,
                    isset($_POST['is_published']) ? 1 : 1,
                    isset($_POST['is_important']) ? 1 : 0,
                    $userId
                ]);
                
                $noticeId = $schoolDb->lastInsertId();
                
                // Create audit log
                $auditStmt = $schoolDb->prepare("
                    INSERT INTO audit_logs (
                        school_id, user_id, user_type, action, entity_type,
                        entity_id, new_values, ip_address, user_agent, url, created_at
                    ) VALUES (?, ?, ?, 'create', 'announcement', ?, ?, ?, ?, ?, NOW())
                ");
                
                $auditStmt->execute([
                    $school['id'],
                    $userId,
                    $userType,
                    $noticeId,
                    json_encode(['title' => $_POST['title']]),
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    $_SERVER['HTTP_USER_AGENT'] ?? null,
                    $_SERVER['REQUEST_URI'] ?? null
                ]);
                
                $schoolDb->commit();
                
                $success = true;
                $message = "Notice created successfully!";
                
                // Refresh notices data
                $noticeStmt->execute([$school['id']]);
                $notices = $noticeStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                $totalNotices = count($notices);
                
                break;
                
            case 'edit_notice':
                if (empty($_POST['notice_id']) || empty($_POST['title'])) {
                    throw new Exception("Notice ID and title are required");
                }
                
                $schoolDb->beginTransaction();
                
                $stmt = $schoolDb->prepare("
                    UPDATE announcements 
                    SET title = ?, description = ?, target = ?,
                        class_id = ?, section_id = ?, start_date = ?,
                        end_date = ?, is_published = ?, is_important = ?,
                        updated_at = NOW()
                    WHERE id = ? AND school_id = ?
                ");
                
                $stmt->execute([
                    $_POST['title'],
                    $_POST['description'] ?? null,
                    $_POST['target'] ?? 'all',
                    !empty($_POST['class_id']) ? $_POST['class_id'] : null,
                    !empty($_POST['section_id']) ? $_POST['section_id'] : null,
                    !empty($_POST['start_date']) ? $_POST['start_date'] : null,
                    !empty($_POST['end_date']) ? $_POST['end_date'] : null,
                    isset($_POST['is_published']) ? 1 : 0,
                    isset($_POST['is_important']) ? 1 : 0,
                    $_POST['notice_id'],
                    $school['id']
                ]);
                
                // Create audit log
                $auditStmt = $schoolDb->prepare("
                    INSERT INTO audit_logs (
                        school_id, user_id, user_type, action, entity_type,
                        entity_id, new_values, ip_address, user_agent, url, created_at
                    ) VALUES (?, ?, ?, 'update', 'announcement', ?, ?, ?, ?, ?, NOW())
                ");
                
                $auditStmt->execute([
                    $school['id'],
                    $userId,
                    $userType,
                    $_POST['notice_id'],
                    json_encode(['updated_fields' => array_keys($_POST)]),
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    $_SERVER['HTTP_USER_AGENT'] ?? null,
                    $_SERVER['REQUEST_URI'] ?? null
                ]);
                
                $schoolDb->commit();
                
                $success = true;
                $message = "Notice updated successfully!";
                
                // Refresh notices data
                $noticeStmt->execute([$school['id']]);
                $notices = $noticeStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                
                break;
                
            case 'delete_notice':
                if (empty($_POST['notice_id'])) {
                    throw new Exception("Notice ID is required");
                }
                
                $schoolDb->beginTransaction();
                
                // Get notice data for audit log
                $getStmt = $schoolDb->prepare("SELECT title FROM announcements WHERE id = ?");
                $getStmt->execute([$_POST['notice_id']]);
                $noticeData = $getStmt->fetch(PDO::FETCH_ASSOC);
                
                // Hard delete (or soft delete by setting is_published = 0)
                $stmt = $schoolDb->prepare("DELETE FROM announcements WHERE id = ? AND school_id = ?");
                $stmt->execute([$_POST['notice_id'], $school['id']]);
                
                // Create audit log
                $auditStmt = $schoolDb->prepare("
                    INSERT INTO audit_logs (
                        school_id, user_id, user_type, action, entity_type,
                        entity_id, old_values, ip_address, user_agent, url, created_at
                    ) VALUES (?, ?, ?, 'delete', 'announcement', ?, ?, ?, ?, ?, NOW())
                ");
                
                $auditStmt->execute([
                    $school['id'],
                    $userId,
                    $userType,
                    $_POST['notice_id'],
                    json_encode($noticeData),
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    $_SERVER['HTTP_USER_AGENT'] ?? null,
                    $_SERVER['REQUEST_URI'] ?? null
                ]);
                
                $schoolDb->commit();
                
                $success = true;
                $message = "Notice deleted successfully!";
                
                // Refresh notices data
                $noticeStmt->execute([$school['id']]);
                $notices = $noticeStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                $totalNotices = count($notices);
                
                break;
                
            default:
                throw new Exception("Unknown action");
        }
        
    } catch (Exception $e) {
        if ($schoolDb && $schoolDb->inTransaction()) {
            $schoolDb->rollBack();
        }
        $error = $e->getMessage();
        error_log("Error processing notice action: " . $error);
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

// Get classes for dropdown
$classes = [];
if ($schoolDb) {
    try {
        $classStmt = $schoolDb->prepare("
            SELECT id, name, code FROM classes 
            WHERE school_id = ? AND is_active = 1
            ORDER BY name
        ");
        $classStmt->execute([$school['id']]);
        $classes = $classStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {
        error_log("Error fetching classes: " . $e->getMessage());
    }
}

// Get sections for dropdown
$sections = [];
if ($schoolDb) {
    try {
        $sectionStmt = $schoolDb->prepare("
            SELECT s.id, s.name, s.code, c.name as class_name 
            FROM sections s
            JOIN classes c ON s.class_id = c.id
            WHERE s.school_id = ? AND s.is_active = 1
            ORDER BY c.name, s.name
        ");
        $sectionStmt->execute([$school['id']]);
        $sections = $sectionStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {
        error_log("Error fetching sections: " . $e->getMessage());
    }
}

error_log("=== NOTICE BOARD PAGE END ===");
?>

<!DOCTYPE html>
<html lang="en" data-theme="light">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="description" content="School Notice Board - Manage all announcements and notices">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($school['name']); ?> | <?php echo defined('APP_NAME') ? APP_NAME : 'School Management'; ?> - Notice Board</title>
    
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
        
        .notice-card {
            background: white;
            border: 1px solid #e9ecef;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            transition: all 0.3s ease;
        }
        .notice-card:hover {
            box-shadow: 0 8px 20px rgba(0,0,0,0.06);
            border-color: #25A194;
        }
        .notice-card.important {
            border-left: 4px solid #dc3545;
            background: linear-gradient(to right, rgba(220, 53, 69, 0.02), white);
        }
        .notice-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        .notice-date {
            background: #f8f9fa;
            padding: 8px 12px;
            border-radius: 8px;
            font-size: 13px;
        }
        .notice-date i {
            color: #25A194;
            margin-right: 5px;
        }
        .target-badge {
            background: #e3f2fd;
            color: #1976d2;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }
        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }
        .status-active {
            background: #d4edda;
            color: #155724;
        }
        .status-expired {
            background: #f8d7da;
            color: #721c24;
        }
        .status-upcoming {
            background: #fff3cd;
            color: #856404;
        }
        .status-draft {
            background: #e2e3e5;
            color: #383d41;
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
        
        .notice-description {
            max-width: 300px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .important-star {
            color: #ffc107;
            margin-left: 5px;
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
                <h6 class="fw-medium text-primary-light text-md mb-3">Page Direction</h6>
                <div class="d-grid grid-cols-2 gap-3">
                    <button type="button"
                        class="theme-setting-item__btn ltr-mode-btn d-flex align-items-center justify-content-center gap-2 h-56-px rounded-3 text-xl" aria-label="LTR">
                        <span><i class="ri-align-item-left-line"></i></span>
                        <span class="h6 text-sm font-medium mb-0">LTR</span>
                    </button>

                    <button type="button"
                        class="theme-setting-item__btn rtl-mode-btn d-flex align-items-center justify-content-center gap-2 h-56-px rounded-3 text-xl" aria-label="RTL">
                        <span class="h6 text-sm font-medium mb-0">RTL</span>
                        <span><i class="ri-align-item-right-line"></i></span>
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

    <div class="overlay bg-black bg-opacity-50 w-100 h-100 position-fixed z-9 visibility-hidden opacity-0 duration-300"></div>

    <!-- Sidebar -->
    <?php include_once('includes/sidebar.php') ?>

    <main class="dashboard-main">
        
        <?php include_once('includes/header.php'); ?>
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
                    <h1 class="fw-semibold mb-4 h6 text-primary-light">Notice Board</h1>
                    <div class="">
                        <a href="index.php" class="text-secondary-light hover-text-primary hover-underline">Dashboard</a>
                        <span class="text-secondary-light"> / Notice Board</span>
                    </div>
                </div>
                <button type="button" class="my-sidebar-btn btn btn-primary-600 d-flex align-items-center gap-6">
                    <span class="d-flex text-md">
                        <i class="ri-add-large-line"></i>
                    </span>
                    Add New Notice
                </button>
            </div>

            <!-- Quick Stats -->
            <div class="row mb-24">
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <div class="stat-value"><?php echo $totalNotices; ?></div>
                                <div class="stat-label">Total Notices</div>
                            </div>
                            <i class="ri-megaphone-line"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <div class="stat-value"><?php echo $activeNotices; ?></div>
                                <div class="stat-label">Active Notices</div>
                            </div>
                            <i class="ri-checkbox-circle-line"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <div class="stat-value"><?php echo $expiredNotices; ?></div>
                                <div class="stat-label">Expired</div>
                            </div>
                            <i class="ri-time-line"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <div class="stat-value"><?php echo $totalNotices - $activeNotices - $expiredNotices; ?></div>
                                <div class="stat-label">Upcoming/Draft</div>
                            </div>
                            <i class="ri-calendar-todo-line"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filter Section -->
            <div class="card mb-24">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-md-3">
                            <select class="form-select" id="statusFilter">
                                <option value="">All Status</option>
                                <option value="active">Active</option>
                                <option value="expired">Expired</option>
                                <option value="upcoming">Upcoming</option>
                                <option value="draft">Draft</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <select class="form-select" id="targetFilter">
                                <option value="">All Audiences</option>
                                <?php foreach ($targetOptions as $key => $value): ?>
                                <option value="<?php echo $key; ?>"><?php echo $value; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 text-md-end">
                            <button type="button" class="btn btn-outline-primary me-2" onclick="exportToExcel()">
                                <i class="ri-file-excel-line"></i> Export
                            </button>
                            <button type="button" class="btn btn-outline-primary" onclick="printList()">
                                <i class="ri-printer-line"></i> Print
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Notices Table -->
            <div class="card h-100">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table bordered-table mb-0" id="noticesTable">
                            <thead>
                                <tr>
                                    <th scope="col" width="50">
                                        <div class="form-check style-check d-flex align-items-center">
                                            <input class="form-check-input" type="checkbox" id="selectAll">
                                            <label class="form-check-label">S.L</label>
                                        </div>
                                    </th>
                                    <th scope="col">Date</th>
                                    <th scope="col">Title</th>
                                    <th scope="col">Description</th>
                                    <th scope="col">Target</th>
                                    <th scope="col">Status</th>
                                    <th scope="col">Posted By</th>
                                    <th scope="col" width="120">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($notices)): ?>
                                <tr>
                                    <td colspan="8" class="text-center py-4">
                                        <i class="ri-megaphone-line fs-1 text-secondary-light mb-3 d-block" style="font-size: 3rem;"></i>
                                        <p class="text-secondary-light">No notices found. Click "Add New Notice" to create your first notice.</p>
                                    </td>
                                </tr>
                                <?php else: ?>
                                    <?php foreach ($notices as $index => $notice): ?>
                                    <tr data-status="<?php echo $notice['status']; ?>" data-target="<?php echo $notice['target']; ?>">
                                        <td>
                                            <div class="form-check style-check d-flex align-items-center">
                                                <input class="form-check-input" type="checkbox" value="<?php echo $notice['id']; ?>">
                                                <label class="form-check-label">
                                                    <?php echo str_pad($index + 1, 2, '0', STR_PAD_LEFT); ?>
                                                </label>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="notice-date">
                                                <i class="ri-calendar-line"></i>
                                                <?php echo date('d M Y', strtotime($notice['created_at'])); ?>
                                                <?php if (!empty($notice['start_date']) && $notice['start_date'] > date('Y-m-d')): ?>
                                                <br><small class="text-warning">Starts: <?php echo date('d M', strtotime($notice['start_date'])); ?></small>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <strong>
                                                <?php echo htmlspecialchars($notice['title']); ?>
                                                <?php if (!empty($notice['is_important'])): ?>
                                                <i class="ri-star-fill important-star" title="Important"></i>
                                                <?php endif; ?>
                                            </strong>
                                            <?php if (!empty($notice['class_name'])): ?>
                                            <br><small class="text-muted">Class: <?php echo htmlspecialchars($notice['class_name']); ?></small>
                                            <?php endif; ?>
                                            <?php if (!empty($notice['section_name'])): ?>
                                            <br><small class="text-muted">Section: <?php echo htmlspecialchars($notice['section_name']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="notice-description" title="<?php echo htmlspecialchars($notice['description'] ?? ''); ?>">
                                                <?php echo htmlspecialchars($notice['description'] ?? 'No description'); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="target-badge">
                                                <i class="ri-group-line"></i>
                                                <?php echo $targetOptions[$notice['target']] ?? ucfirst($notice['target']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php
                                            $statusClass = '';
                                            $statusText = '';
                                            switch ($notice['status']) {
                                                case 'active':
                                                    $statusClass = 'status-active';
                                                    $statusText = 'Active';
                                                    break;
                                                case 'expired':
                                                    $statusClass = 'status-expired';
                                                    $statusText = 'Expired';
                                                    break;
                                                case 'upcoming':
                                                    $statusClass = 'status-upcoming';
                                                    $statusText = 'Upcoming';
                                                    break;
                                                default:
                                                    $statusClass = 'status-draft';
                                                    $statusText = 'Draft';
                                            }
                                            ?>
                                            <span class="status-badge <?php echo $statusClass; ?>">
                                                <?php echo $statusText; ?>
                                                <?php if (!empty($notice['days_remaining']) && $notice['days_remaining'] > 0 && $notice['days_remaining'] <= 7): ?>
                                                <br><small><?php echo $notice['days_remaining']; ?> days left</small>
                                                <?php endif; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <small class="text-muted">
                                                <?php echo htmlspecialchars($notice['created_by_name'] ?? 'System'); ?>
                                            </small>
                                        </td>
                                        <td>
                                            <div class="btn-group">
                                                <button type="button" class="text-primary-light text-xl" data-bs-toggle="dropdown">
                                                    <i class="ri-more-2-fill"></i>
                                                </button>
                                                <ul class="dropdown-menu dropdown-menu-end p-12">
                                                    <li>
                                                        <button type="button" class="dropdown-item" onclick="viewNotice(<?php echo $notice['id']; ?>)">
                                                            <i class="ri-eye-line"></i> View
                                                        </button>
                                                    </li>
                                                    <li>
                                                        <button type="button" class="dropdown-item edit-notice-btn" 
                                                                data-notice='<?php echo json_encode($notice); ?>'>
                                                            <i class="ri-edit-2-line"></i> Edit
                                                        </button>
                                                    </li>
                                                    <li>
                                                        <hr class="dropdown-divider">
                                                    </li>
                                                    <li>
                                                        <button type="button" class="dropdown-item text-danger" 
                                                                onclick="deleteNotice(<?php echo $notice['id']; ?>, '<?php echo addslashes($notice['title']); ?>')">
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

    <!-- Add Notice Sidebar -->
    <div class="my-sidebar bg-white position-fixed end-0 top-0 h-100vh overflow-y-auto z-99 max-w-700-px w-100">
        <div class="px-20 py-12 border-bottom d-flex align-items-center justify-content-between gap-20">
            <h5 class="text-lg mb-0">Add New Notice</h5>
            <button type="button" class="close-my-sidebar text-danger-600 text-lg d-flex">
                <i class="ri-close-large-line"></i>
            </button>
        </div>
        <form method="POST" class="p-20">
            <input type="hidden" name="action" value="create_notice">
            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
            
            <div class="row g-3">
                <div class="col-sm-12">
                    <div class="">
                        <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                            Title <span class="text-danger-600">*</span>
                        </label>
                        <input type="text" name="title" class="form-control" placeholder="Enter notice title" required>
                    </div>
                </div>
                
                <div class="col-sm-12">
                    <div class="">
                        <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Target Audience</label>
                        <select name="target" class="form-select" id="targetSelect">
                            <?php foreach ($targetOptions as $key => $value): ?>
                            <option value="<?php echo $key; ?>"><?php echo $value; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="col-sm-12" id="classSelectWrapper" style="display: none;">
                    <div class="">
                        <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Select Class</label>
                        <select name="class_id" class="form-select">
                            <option value="">Choose Class</option>
                            <?php foreach ($classes as $class): ?>
                            <option value="<?php echo $class['id']; ?>"><?php echo htmlspecialchars($class['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="col-sm-12" id="sectionSelectWrapper" style="display: none;">
                    <div class="">
                        <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Select Section</label>
                        <select name="section_id" class="form-select">
                            <option value="">Choose Section</option>
                            <?php foreach ($sections as $section): ?>
                            <option value="<?php echo $section['id']; ?>"><?php echo htmlspecialchars($section['name']); ?> (<?php echo htmlspecialchars($section['class_name']); ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="col-sm-6">
                    <div class="">
                        <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Start Date</label>
                        <input type="date" name="start_date" class="form-control">
                    </div>
                </div>
                
                <div class="col-sm-6">
                    <div class="">
                        <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">End Date</label>
                        <input type="date" name="end_date" class="form-control">
                    </div>
                </div>
                
                <div class="col-sm-12">
                    <div class="">
                        <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Description</label>
                        <textarea name="description" class="form-control" rows="4" placeholder="Enter notice description..."></textarea>
                    </div>
                </div>
                
                <div class="col-sm-12">
                    <div class="form-check">
                        <input type="checkbox" name="is_important" class="form-check-input" id="is_important" value="1">
                        <label class="form-check-label" for="is_important">Mark as Important</label>
                    </div>
                </div>
                
                <div class="col-12">
                    <div class="d-flex align-items-center justify-content-center gap-3 mt-8">
                        <button type="button" class="border border-danger-600 bg-hover-danger-200 text-danger-600 text-md px-50 py-11 radius-8 close-my-sidebar">
                            Cancel
                        </button>
                        <button type="submit" class="btn btn-primary-600 border border-primary-600 text-md px-28 py-12 radius-8">
                            Publish Notice
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <!-- Edit Notice Sidebar -->
    <div class="edit-sidebar bg-white position-fixed end-0 top-0 h-100vh overflow-y-auto z-99 max-w-700-px w-100">
        <div class="px-20 py-12 border-bottom d-flex align-items-center justify-content-between gap-20">
            <h5 class="text-lg mb-0">Edit Notice</h5>
            <button type="button" class="close-edit-sidebar text-danger-600 text-lg d-flex">
                <i class="ri-close-large-line"></i>
            </button>
        </div>
        <form method="POST" class="p-20" id="editNoticeForm">
            <input type="hidden" name="action" value="edit_notice">
            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
            <input type="hidden" name="notice_id" id="edit_notice_id">
            
            <div class="row g-3">
                <div class="col-sm-12">
                    <div class="">
                        <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                            Title <span class="text-danger-600">*</span>
                        </label>
                        <input type="text" name="title" id="edit_title" class="form-control" required>
                    </div>
                </div>
                
                <div class="col-sm-12">
                    <div class="">
                        <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Target Audience</label>
                        <select name="target" id="edit_target" class="form-select">
                            <?php foreach ($targetOptions as $key => $value): ?>
                            <option value="<?php echo $key; ?>"><?php echo $value; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="col-sm-12" id="edit_class_wrapper">
                    <div class="">
                        <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Class</label>
                        <select name="class_id" id="edit_class_id" class="form-select">
                            <option value="">None</option>
                            <?php foreach ($classes as $class): ?>
                            <option value="<?php echo $class['id']; ?>"><?php echo htmlspecialchars($class['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="col-sm-12" id="edit_section_wrapper">
                    <div class="">
                        <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Section</label>
                        <select name="section_id" id="edit_section_id" class="form-select">
                            <option value="">None</option>
                            <?php foreach ($sections as $section): ?>
                            <option value="<?php echo $section['id']; ?>"><?php echo htmlspecialchars($section['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="col-sm-6">
                    <div class="">
                        <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Start Date</label>
                        <input type="date" name="start_date" id="edit_start_date" class="form-control">
                    </div>
                </div>
                
                <div class="col-sm-6">
                    <div class="">
                        <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">End Date</label>
                        <input type="date" name="end_date" id="edit_end_date" class="form-control">
                    </div>
                </div>
                
                <div class="col-sm-12">
                    <div class="">
                        <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Description</label>
                        <textarea name="description" id="edit_description" class="form-control" rows="4"></textarea>
                    </div>
                </div>
                
                <div class="col-sm-12">
                    <div class="form-check">
                        <input type="checkbox" name="is_important" class="form-check-input" id="edit_is_important" value="1">
                        <label class="form-check-label" for="edit_is_important">Mark as Important</label>
                    </div>
                </div>
                
                <div class="col-sm-12">
                    <div class="form-check">
                        <input type="checkbox" name="is_published" class="form-check-input" id="edit_is_published" value="1">
                        <label class="form-check-label" for="edit_is_published">Published</label>
                    </div>
                </div>
                
                <div class="col-12">
                    <div class="d-flex align-items-center justify-content-center gap-3 mt-8">
                        <button type="button" class="border border-danger-600 bg-hover-danger-200 text-danger-600 text-md px-50 py-11 radius-8 close-edit-sidebar">
                            Cancel
                        </button>
                        <button type="submit" class="btn btn-primary-600 border border-primary-600 text-md px-28 py-12 radius-8">
                            Update Notice
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <!-- View Notice Modal -->
    <div class="modal fade" id="viewNoticeModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="viewNoticeTitle"></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <strong>Posted Date:</strong> <span id="viewNoticeDate"></span>
                        </div>
                        <div class="col-md-6">
                            <strong>Target:</strong> <span id="viewNoticeTarget"></span>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <strong>Start Date:</strong> <span id="viewNoticeStart"></span>
                        </div>
                        <div class="col-md-6">
                            <strong>End Date:</strong> <span id="viewNoticeEnd"></span>
                        </div>
                    </div>
                    <div class="mb-3">
                        <strong>Posted By:</strong> <span id="viewNoticeAuthor"></span>
                    </div>
                    <hr>
                    <div class="mb-3">
                        <h6>Description:</h6>
                        <p id="viewNoticeDescription" class="text-muted"></p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteNoticeModal" tabindex="-1">
        <div class="modal-dialog modal-sm modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-body pt-32 px-36 pb-24 text-center">
                    <span class="mb-16 fs-1 line-height-1 text-danger">
                        <i class="ri-delete-bin-line" style="font-size: 48px;"></i>
                    </span>
                    <h6 class="text-lg fw-semibold text-primary-light mb-8">Delete Notice</h6>
                    <p class="mb-24" id="deleteNoticeMessage">Are you sure you want to delete this notice?</p>
                    <form method="POST" id="deleteNoticeForm">
                        <input type="hidden" name="action" value="delete_notice">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                        <input type="hidden" name="notice_id" id="delete_notice_id">
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

            // Initialize DataTable
            var table = $('#noticesTable').DataTable({
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
                    { orderable: false, targets: [0, 7] }
                ]
            });

            // Select All checkbox
            $('#selectAll').on('click', function() {
                $('.form-check-input').prop('checked', this.checked);
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
            $('.edit-notice-btn').on('click', function () {
                const noticeData = $(this).data('notice');
                
                // Populate form
                $('#edit_notice_id').val(noticeData.id);
                $('#edit_title').val(noticeData.title);
                $('#edit_target').val(noticeData.target || 'all');
                $('#edit_class_id').val(noticeData.class_id || '');
                $('#edit_section_id').val(noticeData.section_id || '');
                $('#edit_start_date').val(noticeData.start_date || '');
                $('#edit_end_date').val(noticeData.end_date || '');
                $('#edit_description').val(noticeData.description || '');
                $('#edit_is_important').prop('checked', noticeData.is_important == 1);
                $('#edit_is_published').prop('checked', noticeData.is_published == 1);
                
                // Show/hide class/section based on target
                toggleTargetFields(noticeData.target);
                
                $('.edit-sidebar').addClass('active');
                $('.overlay').addClass('active');
            });
            
            $('.close-edit-sidebar, .overlay').on('click', function () {
                $('.edit-sidebar').removeClass('active');
                $('.overlay').removeClass('active');
            });

            // Target select change handler for add form
            $('#targetSelect').on('change', function() {
                toggleTargetFields($(this).val());
            });

            // Target select change handler for edit form
            $('#edit_target').on('change', function() {
                toggleTargetFields($(this).val());
            });

            // Filter functionality
            $('#statusFilter, #targetFilter').on('change', function() {
                const status = $('#statusFilter').val();
                const target = $('#targetFilter').val();
                
                $.fn.dataTable.ext.search.push(
                    function(settings, data, dataIndex) {
                        const row = table.row(dataIndex).node();
                        const rowStatus = $(row).data('status');
                        const rowTarget = $(row).data('target');
                        
                        let statusMatch = true;
                        let targetMatch = true;
                        
                        if (status && rowStatus != status) {
                            statusMatch = false;
                        }
                        
                        if (target && rowTarget != target) {
                            targetMatch = false;
                        }
                        
                        return statusMatch && targetMatch;
                    }
                );
                
                table.draw();
                $.fn.dataTable.ext.search.pop();
            });

            // Custom search for navbar
            $('.navbar-search input').on('keyup', function() {
                table.search(this.value).draw();
            });

            // Initialize flatpickr for date inputs
            flatpickr("input[type=date]", {
                dateFormat: "Y-m-d"
            });
        });

        // Toggle target specific fields
        function toggleTargetFields(target) {
            if (target === 'class') {
                $('#classSelectWrapper, #edit_class_wrapper').show();
                $('#sectionSelectWrapper, #edit_section_wrapper').hide();
            } else if (target === 'section') {
                $('#classSelectWrapper, #edit_class_wrapper').show();
                $('#sectionSelectWrapper, #edit_section_wrapper').show();
            } else {
                $('#classSelectWrapper, #edit_class_wrapper, #sectionSelectWrapper, #edit_section_wrapper').hide();
            }
        }

        // View notice function
        function viewNotice(noticeId) {
            // Find the notice data from the table
            const row = $(`button[onclick="viewNotice(${noticeId})"]`).closest('tr');
            
            $('#viewNoticeTitle').text(row.find('td:eq(2) strong').text().trim());
            $('#viewNoticeDate').text(row.find('td:eq(1) .notice-date').text().trim());
            $('#viewNoticeTarget').text(row.find('td:eq(4)').text().trim());
            $('#viewNoticeStart').text(row.find('td:eq(1) small').text().replace('Starts:', '').trim() || 'N/A');
            $('#viewNoticeEnd').text(row.find('td:eq(5) small').text().replace('days left', '').trim() || 'N/A');
            $('#viewNoticeAuthor').text(row.find('td:eq(6)').text().trim());
            $('#viewNoticeDescription').text(row.find('td:eq(3)').attr('title') || 'No description');
            
            $('#viewNoticeModal').modal('show');
        }

        // Delete notice function
        function deleteNotice(noticeId, noticeTitle) {
            $('#delete_notice_id').val(noticeId);
            $('#deleteNoticeMessage').text('Are you sure you want to delete "' + noticeTitle + '"? This action cannot be undone.');
            $('#deleteNoticeModal').modal('show');
        }

        // Export to Excel
        function exportToExcel() {
            let csv = "Date,Title,Description,Target,Status,Posted By\n";
            
            $('#noticesTable tbody tr').each(function() {
                if ($(this).find('td').length > 1) {
                    const date = $(this).find('td:eq(1)').text().trim();
                    const title = $(this).find('td:eq(2)').text().trim();
                    const description = $(this).find('td:eq(3)').text().trim();
                    const target = $(this).find('td:eq(4)').text().trim();
                    const status = $(this).find('td:eq(5)').text().trim();
                    const author = $(this).find('td:eq(6)').text().trim();
                    
                    csv += `"${date}","${title}","${description}","${target}","${status}","${author}"\n`;
                }
            });
            
            const blob = new Blob([csv], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'notice-board.csv';
            a.click();
            window.URL.revokeObjectURL(url);
        }

        // Print list
        function printList() {
            const printWindow = window.open('', '_blank');
            printWindow.document.write(`
                <html>
                <head>
                    <title>Notice Board - <?php echo htmlspecialchars($school['name']); ?></title>
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
                        .badge-warning { background: #fff3cd; color: #856404; }
                    </style>
                </head>
                <body>
                    <h1><?php echo htmlspecialchars($school['name']); ?></h1>
                    <h2>Notice Board</h2>
                    <p>Generated on: ${new Date().toLocaleString()}</p>
                    
                    <table>
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Title</th>
                                <th>Description</th>
                                <th>Target</th>
                                <th>Status</th>
                                <th>Posted By</th>
                            </tr>
                        </thead>
                        <tbody>
            `);
            
            $('#noticesTable tbody tr').each(function() {
                if ($(this).find('td').length > 1) {
                    const date = $(this).find('td:eq(1)').text().trim();
                    const title = $(this).find('td:eq(2)').text().trim();
                    const description = $(this).find('td:eq(3)').text().trim();
                    const target = $(this).find('td:eq(4)').text().trim();
                    const status = $(this).find('td:eq(5)').text().trim();
                    const author = $(this).find('td:eq(6)').text().trim();
                    
                    printWindow.document.write(`
                        <tr>
                            <td>${date}</td>
                            <td>${title}</td>
                            <td>${description}</td>
                            <td>${target}</td>
                            <td>${status}</td>
                            <td>${author}</td>
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