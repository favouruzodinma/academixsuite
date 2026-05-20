<?php
/**
 * School Guardian Details Page
 * Displays guardian information and allows linking/unlinking students
 * 
 * @package AcademixSuite
 * @version 2.0
 */

// Enable error reporting for debugging
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../../logs/school_guardian_details.log');

error_log("=== GUARDIAN DETAILS PAGE START ===");
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
$currentPage = $GLOBALS['CURRENT_PAGE'] ?? 'guardian-details.php';
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
 * Initialize GuardianManager and notification variables
 */
$guardianManager = null;
$notificationCount = 0;
$notifications = [];

if ($schoolDb) {
    try {
        $guardianManager = new GuardianManager($schoolDb, $school['id'], $userId, $userType, $school);
        error_log("GuardianManager initialized successfully");
        
        // Get notification data if methods exist
        if (method_exists($guardianManager, 'getNotificationCount')) {
            $notificationCount = $guardianManager->getNotificationCount();
        }
        
        if (method_exists($guardianManager, 'getNotifications')) {
            $notifications = $guardianManager->getNotifications(5);
        }
        
    } catch (Exception $e) {
        error_log("ERROR initializing GuardianManager: " . $e->getMessage());
        $_SESSION['toast_error'] = "Failed to initialize guardian management system.";
    }
}

/**
 * Get guardian ID from URL
 */
$guardianId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
error_log("Guardian ID: " . $guardianId);

/**
 * Fetch guardian details
 */
$guardian = null;
$linkedStudents = [];
$allStudents = [];

if ($schoolDb && $guardianId > 0) {
    try {
        // Get guardian details from users table
        $stmt = $schoolDb->prepare("
            SELECT 
                u.*,
                DATE_FORMAT(u.created_at, '%d %b %Y') as join_date_formatted,
                CONCAT('G', LPAD(u.id, 7, '0')) as guardian_id_formatted
            FROM users u
            WHERE u.id = ? AND u.school_id = ? AND u.user_type = 'parent'
        ");
        $stmt->execute([$guardianId, $school['id']]);
        $guardian = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($guardian) {
            error_log("Guardian found: " . $guardian['name']);
            
            // Get linked students - REMOVED profile_photo column
            $linkedStmt = $schoolDb->prepare("
                SELECT 
                    s.id,
                    s.first_name,
                    s.last_name,
                    s.admission_number,
                    s.roll_number,
                    c.name as class_name,
                    sc.name as section_name,
                    g.relationship,
                    g.is_primary,
                    g.can_pickup,
                    g.emergency_contact,
                    g.id as guardian_link_id,
                    CONCAT(s.first_name, ' ', s.last_name) as full_name
                FROM students s
                JOIN guardians g ON s.id = g.student_id
                LEFT JOIN classes c ON s.class_id = c.id
                LEFT JOIN sections sc ON s.section_id = sc.id
                WHERE g.user_id = ? AND g.school_id = ? AND s.status = 'active'
                ORDER BY g.is_primary DESC, s.first_name, s.last_name
            ");
            $linkedStmt->execute([$guardianId, $school['id']]);
            $linkedStudents = $linkedStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get all active students for linking (excluding already linked ones)
            $linkedIds = array_column($linkedStudents, 'id');
            
            $allStmtSql = "
                SELECT 
                    s.id,
                    s.first_name,
                    s.last_name,
                    s.admission_number,
                    s.roll_number,
                    c.name as class_name,
                    sc.name as section_name,
                    CONCAT(s.first_name, ' ', s.last_name) as full_name
                FROM students s
                LEFT JOIN classes c ON s.class_id = c.id
                LEFT JOIN sections sc ON s.section_id = sc.id
                WHERE s.school_id = ? AND s.status = 'active'
            ";
            
            $params = [$school['id']];
            
            if (!empty($linkedIds)) {
                $placeholders = implode(',', array_fill(0, count($linkedIds), '?'));
                $allStmtSql .= " AND s.id NOT IN (" . $placeholders . ")";
                $params = array_merge($params, $linkedIds);
            }
            
            $allStmtSql .= " ORDER BY s.first_name, s.last_name LIMIT 100";
            
            $allStmt = $schoolDb->prepare($allStmtSql);
            $allStmt->execute($params);
            $allStudents = $allStmt->fetchAll(PDO::FETCH_ASSOC);
            
        } else {
            error_log("Guardian not found with ID: " . $guardianId);
            $_SESSION['toast_error'] = "Guardian not found.";
            header("Location: guardian-list.php");
            exit;
        }
    } catch (Exception $e) {
        error_log("Error fetching guardian details: " . $e->getMessage());
        $_SESSION['toast_error'] = "Error loading guardian details.";
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
 * Handle AJAX requests
 */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    
    try {
        if ($_GET['ajax'] === 'search_students' && isset($_GET['term']) && $guardianManager) {
            $term = sanitize($_GET['term']);
            $students = $guardianManager->searchStudents($term);
            
            // Filter out already linked students
            $linkedIds = array_column($linkedStudents, 'id');
            $filteredStudents = array_filter($students, function($student) use ($linkedIds) {
                return !in_array($student['id'], $linkedIds);
            });
            
            echo json_encode(['success' => true, 'students' => array_values($filteredStudents)]);
            exit;
        }
    } catch (Exception $e) {
        error_log("AJAX error: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'An error occurred']);
        exit;
    }
}

/**
 * Handle form submissions
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        error_log("CSRF validation FAILED");
        $_SESSION['toast_error'] = "Invalid security token. Please try again.";
        header("Location: guardian-details.php?id=" . $guardianId);
        exit;
    }
    
    // Handle link students
    if (isset($_POST['action']) && $_POST['action'] === 'link_students' && $guardianManager) {
        $studentIds = $_POST['student_ids'] ?? [];
        $relationships = $_POST['relationships'] ?? [];
        
        if (!empty($studentIds)) {
            $result = $guardianManager->linkGuardianToStudents($guardianId, $studentIds, $relationships);
            
            if ($result) {
                $_SESSION['toast_success'] = "Students linked successfully!";
            } else {
                $_SESSION['toast_error'] = "Failed to link students.";
            }
        } else {
            $_SESSION['toast_error'] = "No students selected.";
        }
        
        header("Location: guardian-details.php?id=" . $guardianId);
        exit;
    }
    
    // Handle update relationship
    if (isset($_POST['action']) && $_POST['action'] === 'update_relationship' && $guardianManager) {
        $linkId = (int)$_POST['link_id'];
        $relationship = $_POST['relationship'];
        $isPrimary = isset($_POST['is_primary']) ? 1 : 0;
        $canPickup = isset($_POST['can_pickup']) ? 1 : 0;
        $emergencyContact = isset($_POST['emergency_contact']) ? 1 : 0;
        
        try {
            $stmt = $schoolDb->prepare("
                UPDATE guardians 
                SET relationship = ?, is_primary = ?, can_pickup = ?, emergency_contact = ?
                WHERE id = ? AND user_id = ? AND school_id = ?
            ");
            
            $result = $stmt->execute([
                $relationship,
                $isPrimary,
                $canPickup,
                $emergencyContact,
                $linkId,
                $guardianId,
                $school['id']
            ]);
            
            // If setting as primary, remove primary from others
            if ($isPrimary && $result) {
                $updateStmt = $schoolDb->prepare("
                    UPDATE guardians 
                    SET is_primary = 0 
                    WHERE user_id = ? AND school_id = ? AND id != ?
                ");
                $updateStmt->execute([$guardianId, $school['id'], $linkId]);
            }
            
            if ($result) {
                $_SESSION['toast_success'] = "Relationship updated successfully!";
            } else {
                $_SESSION['toast_error'] = "Failed to update relationship.";
            }
        } catch (Exception $e) {
            error_log("Error updating relationship: " . $e->getMessage());
            $_SESSION['toast_error'] = "Error updating relationship.";
        }
        
        header("Location: guardian-details.php?id=" . $guardianId);
        exit;
    }
    
    // Handle unlink student
    if (isset($_POST['action']) && $_POST['action'] === 'unlink_student' && $guardianManager) {
        $linkId = (int)$_POST['link_id'];
        
        try {
            // Check if this is the primary student
            $checkStmt = $schoolDb->prepare("SELECT is_primary FROM guardians WHERE id = ? AND user_id = ?");
            $checkStmt->execute([$linkId, $guardianId]);
            $link = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($link && $link['is_primary']) {
                $_SESSION['toast_error'] = "Cannot unlink primary student. Set another student as primary first.";
            } else {
                $stmt = $schoolDb->prepare("DELETE FROM guardians WHERE id = ? AND user_id = ? AND school_id = ?");
                $result = $stmt->execute([$linkId, $guardianId, $school['id']]);
                
                if ($result) {
                    $_SESSION['toast_success'] = "Student unlinked successfully!";
                } else {
                    $_SESSION['toast_error'] = "Failed to unlink student.";
                }
            }
        } catch (Exception $e) {
            error_log("Error unlinking student: " . $e->getMessage());
            $_SESSION['toast_error'] = "Error unlinking student.";
        }
        
        header("Location: guardian-details.php?id=" . $guardianId);
        exit;
    }
}

// Generate CSRF token
$csrfToken = generateCsrfToken();

// Collect toast messages
$toastSuccess = $_SESSION['toast_success'] ?? '';
$toastError = $_SESSION['toast_error'] ?? '';
unset($_SESSION['toast_success'], $_SESSION['toast_error']);

error_log("=== GUARDIAN DETAILS PAGE END ===");
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
  <title><?php echo htmlspecialchars($school['name']); ?> | <?php echo defined('APP_NAME') ? APP_NAME : 'School Management'; ?> - Guardian Details</title>
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
    
    /* Student card styles */
    .student-card {
      background: white;
      border: 1px solid #e9ecef;
      border-radius: 12px;
      padding: 16px;
      margin-bottom: 16px;
      transition: all 0.3s ease;
    }
    .student-card:hover {
      box-shadow: 0 8px 20px rgba(0,0,0,0.06);
      border-color: #25A194;
    }
    .student-card.primary-card {
      border-left: 4px solid #25A194;
      background: linear-gradient(to right, rgba(37, 161, 148, 0.02), white);
    }
    .primary-badge {
      background: #25A194;
      color: white;
      padding: 2px 8px;
      border-radius: 12px;
      font-size: 11px;
      font-weight: 500;
    }
    .relationship-badge {
      background: #f8f9fa;
      color: #495057;
      padding: 4px 12px;
      border-radius: 20px;
      font-size: 12px;
    }
    
    /* Search results dropdown */
    .search-results-dropdown {
      position: absolute;
      top: 100%;
      left: 0;
      right: 0;
      max-height: 300px;
      overflow-y: auto;
      background: white;
      border: 1px solid #e9ecef;
      border-radius: 12px;
      box-shadow: 0 10px 40px rgba(0,0,0,0.08);
      z-index: 1000;
      display: none;
      margin-top: 4px;
    }
    .search-result-item {
      padding: 12px 16px;
      border-bottom: 1px solid #f1f3f5;
      cursor: pointer;
      transition: all 0.2s ease;
    }
    .search-result-item:last-child {
      border-bottom: none;
    }
    .search-result-item:hover {
      background-color: #f8f9fa;
    }
    .search-result-item .student-name {
      font-weight: 600;
      color: #212529;
    }
    .search-result-item .student-details {
      font-size: 12px;
      color: #6c757d;
      margin-top: 4px;
    }
    
    /* Action buttons */
    .action-btn {
      width: 32px;
      height: 32px;
      border-radius: 8px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      background: #f8f9fa;
      color: #495057;
      transition: all 0.2s ease;
      border: none;
    }
    .action-btn:hover {
      background: #25A194;
      color: white;
    }
    .action-btn.delete:hover {
      background: #dc3545;
      color: white;
    }
    
    /* Link student modal */
    .student-checkbox-item {
      padding: 12px;
      border: 1px solid #e9ecef;
      border-radius: 8px;
      margin-bottom: 8px;
      cursor: pointer;
      transition: all 0.2s ease;
    }
    .student-checkbox-item:hover {
      background: #f8f9fa;
      border-color: #25A194;
    }
    .student-checkbox-item.selected {
      background: rgba(37, 161, 148, 0.05);
      border-color: #25A194;
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
    <div class="navbar-header shadow-1">
        <div class="row align-items-center justify-content-between">
            <div class="col-auto">
                <div class="d-flex flex-wrap align-items-center gap-4">
                    <button type="button" class="sidebar-mobile-toggle" aria-label="Sidebar Mobile Toggler Button">
                        <iconify-icon icon="heroicons:bars-3-solid" class="icon"></iconify-icon>
                    </button>
                    <form class="navbar-search">
                        <input type="text" class="bg-transparent" name="search" placeholder="Search">
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
                            class="has-indicator w-40-px h-40-px bg-neutral-200 rounded-circle d-flex justify-content-center align-items-center"
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

        <div class="breadcrumb d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
            <div class="">
                <h1 class="fw-semibold mb-4 h6 text-primary-light">Guardian Details</h1>
                <div class="">
                    <a href="index.php" class="text-secondary-light hover-text-primary hover-underline">Dashboard </a>
                    <a href="guardian-list.php" class="text-secondary-light hover-text-primary hover-underline"> / Guardian</a>
                    <span class="text-secondary-light">/ Guardian Details</span>
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

        <?php if ($guardian): ?>
        <div class="mt-24">
            <div class="card h-100">
                <div class="card-body p-24">
                    <div class="d-flex gap-32 flex-md-row flex-column">
                        <div class="max-w-300-px w-100 text-center">
                            <figure class="mb-24 w-120-px h-120-px mx-auto rounded-circle overflow-hidden">
                                <img src="<?php echo !empty($guardian['profile_photo']) ? htmlspecialchars($guardian['profile_photo']) : 'https://academixsuite.com/tenant/assets/images/thumbs/teacher-details-img.png'; ?>" 
                                     alt="<?php echo htmlspecialchars($guardian['name']); ?>" class="w-100 h-100 object-fit-cover">
                            </figure>
                            <h2 class="h6 text-primary-light mb-8 fw-semibold"><?php echo htmlspecialchars($guardian['name']); ?></h2>
                            <p class="mb-0">ID: <span class="text-primary-600 fw-semibold"> <?php echo $guardian['guardian_id_formatted']; ?></span>
                            </p>
                            <div class="mt-20 d-flex gap-16 w-100">
                                <a href="edit-guardian.php?id=<?php echo $guardianId; ?>"
                                    class="btn btn-primary-600 border fw-medium border-primary-600 text-md d-flex justify-content-center align-items-center gap-8 flex-grow-1 px-12 py-8 radius-8">
                                    <span class="d-flex text-lg">
                                        <i class="ri-edit-line"></i>
                                    </span>
                                    Edit
                                </a>
                                <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#linkStudentModal">
                                    <i class="ri-link"></i> Link Student
                                </button>
                            </div>
                        </div>
                        <div class="">
                            <span class="h-100 w-1-px bg-neutral-200"></span>
                        </div>
                        <div class="flex-grow-1">
                            <div class="pb-16 border-bottom d-flex align-items-center justify-content-between gap-20">
                                <h3 class="h6 text-primary-light text-lg mb-0 fw-semibold">Personal Info</h3>
                            </div>
                            <div class="mt-16 d-flex flex-column gap-20">
                                <div class="d-flex gap-4">
                                    <span class="fw-semibold text-sm text-primary-light w-110-px">Guardian Type</span>
                                    <span class="fw-normal text-sm text-secondary-light">: Father</span>
                                </div>
                                <div class="d-flex gap-4">
                                    <span class="fw-semibold text-sm text-primary-light w-110-px">Phone Number</span>
                                    <span class="fw-normal text-sm text-secondary-light">: <?php echo htmlspecialchars($guardian['phone'] ?? 'N/A'); ?></span>
                                </div>
                                <div class="d-flex gap-4">
                                    <span class="fw-semibold text-sm text-primary-light w-110-px">Email</span>
                                    <span class="fw-normal text-sm text-secondary-light">: <?php echo htmlspecialchars($guardian['email']); ?></span>
                                </div>
                                <div class="d-flex gap-4">
                                    <span class="fw-semibold text-sm text-primary-light w-110-px">Address</span>
                                    <span class="fw-normal text-sm text-secondary-light">: <?php echo htmlspecialchars($guardian['address'] ?? 'N/A'); ?></span>
                                </div>
                                <div class="d-flex gap-4">
                                    <span class="fw-semibold text-sm text-primary-light w-110-px">Join Date</span>
                                    <span class="fw-normal text-sm text-secondary-light">: <?php echo $guardian['join_date_formatted']; ?></span>
                                </div>
                                <div class="d-flex gap-4">
                                    <span class="fw-semibold text-sm text-primary-light w-110-px">Status</span>
                                    <span class="fw-normal text-sm">
                                        <span class="badge <?php echo $guardian['is_active'] ? 'bg-success' : 'bg-danger'; ?>">
                                            <?php echo $guardian['is_active'] ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Linked Students Section -->
            <div class="mt-16">
                <div class="shadow-1 radius-12 bg-base h-100 overflow-hidden">
                    <div class="card-header border-bottom bg-base py-16 px-24 d-flex align-items-center justify-content-between">
                        <h6 class="text-lg fw-semibold mb-0">
                            <i class="ri-group-line me-2 text-primary-600"></i>
                            Linked Students (<?php echo count($linkedStudents); ?>)
                        </h6>
                        <button type="button" class="btn btn-sm btn-primary-600" data-bs-toggle="modal" data-bs-target="#linkStudentModal">
                            <i class="ri-add-line"></i> Link New Student
                        </button>
                    </div>
                    <div class="card-body p-20">
                        <?php if (empty($linkedStudents)): ?>
                            <div class="text-center py-4">
                                <i class="ri-user-search-line fs-1 text-secondary-light mb-3 d-block"></i>
                                <p class="text-secondary-light">No students linked to this guardian yet.</p>
                                <button type="button" class="btn btn-primary-600" data-bs-toggle="modal" data-bs-target="#linkStudentModal">
                                    <i class="ri-add-line"></i> Link First Student
                                </button>
                            </div>
                        <?php else: ?>
                            <div class="row">
                                <?php foreach ($linkedStudents as $index => $student): ?>
                                <div class="col-md-6 col-lg-4 mb-4">
                                    <div class="student-card <?php echo $student['is_primary'] ? 'primary-card' : ''; ?>">
                                        <div class="d-flex align-items-start justify-content-between mb-3">
                                            <div class="d-flex align-items-center gap-3">
                                                <div class="student-avatar" style="width: 48px; height: 48px; background: #f0f9f8; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #25A194; font-weight: 600;">
                                                    <?php echo strtoupper(substr($student['first_name'], 0, 1) . substr($student['last_name'] ?? '', 0, 1)); ?>
                                                </div>
                                                <div>
                                                    <h6 class="fw-semibold mb-1">
                                                        <?php echo htmlspecialchars($student['full_name']); ?>
                                                        <?php if ($student['is_primary']): ?>
                                                            <span class="primary-badge ms-2">Primary</span>
                                                        <?php endif; ?>
                                                    </h6>
                                                    <p class="mb-0 text-sm text-secondary-light">
                                                        <?php echo htmlspecialchars($student['class_name'] ?? 'N/A'); ?> 
                                                        (<?php echo htmlspecialchars($student['section_name'] ?? 'N/A'); ?>)
                                                    </p>
                                                </div>
                                            </div>
                                            <div class="dropdown">
                                                <button class="btn btn-sm btn-light" type="button" data-bs-toggle="dropdown">
                                                    <i class="ri-more-2-fill"></i>
                                                </button>
                                                <ul class="dropdown-menu">
                                                    <li>
                                                        <button class="dropdown-item" type="button" onclick="editRelationship(<?php echo $student['guardian_link_id']; ?>, '<?php echo $student['relationship']; ?>', <?php echo $student['is_primary']; ?>, <?php echo $student['can_pickup']; ?>, <?php echo $student['emergency_contact']; ?>)">
                                                            <i class="ri-edit-line"></i> Edit Relationship
                                                        </button>
                                                    </li>
                                                    <li>
                                                        <form method="POST" style="display: inline;">
                                                            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                                            <input type="hidden" name="action" value="unlink_student">
                                                            <input type="hidden" name="link_id" value="<?php echo $student['guardian_link_id']; ?>">
                                                            <button type="submit" class="dropdown-item text-danger" onclick="return confirm('Are you sure you want to unlink this student?')">
                                                                <i class="ri-unlink"></i> Unlink Student
                                                            </button>
                                                        </form>
                                                    </li>
                                                </ul>
                                            </div>
                                        </div>
                                        
                                        <div class="d-flex flex-wrap gap-2 mb-2">
                                            <span class="relationship-badge">
                                                <i class="ri-user-heart-line"></i> <?php echo ucfirst($student['relationship']); ?>
                                            </span>
                                            <?php if ($student['can_pickup']): ?>
                                                <span class="relationship-badge" style="background: #e3f2fd;">
                                                    <i class="ri-car-line"></i> Can Pickup
                                                </span>
                                            <?php endif; ?>
                                            <?php if ($student['emergency_contact']): ?>
                                                <span class="relationship-badge" style="background: #fff3e0;">
                                                    <i class="ri-phone-line"></i> Emergency
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <div class="mt-3 pt-2 border-top d-flex justify-content-between align-items-center">
                                            <span class="text-sm text-secondary-light">
                                                <i class="ri-hashtag"></i> <?php echo htmlspecialchars($student['admission_number']); ?>
                                            </span>
                                            <span class="text-sm text-secondary-light">
                                                <i class="ri-user-star-line"></i> Roll: <?php echo htmlspecialchars($student['roll_number'] ?? 'N/A'); ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
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

<!-- Link Student Modal -->
<div class="modal fade" id="linkStudentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Link Students to Guardian</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" value="link_students">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Search Students</label>
                        <div class="position-relative">
                            <input type="text" class="form-control" id="studentSearch" placeholder="Type to search students...">
                            <div id="studentSearchResults" class="search-results-dropdown"></div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Available Students</label>
                        <div id="availableStudentsList" style="max-height: 300px; overflow-y: auto;">
                            <?php if (empty($allStudents)): ?>
                                <p class="text-muted text-center py-3">No additional students available to link.</p>
                            <?php else: ?>
                                <?php foreach ($allStudents as $student): ?>
                                <div class="student-checkbox-item" onclick="toggleStudent(<?php echo $student['id']; ?>)">
                                    <div class="d-flex align-items-center">
                                        <input type="checkbox" name="student_ids[]" value="<?php echo $student['id']; ?>" 
                                               id="student_<?php echo $student['id']; ?>" class="form-check-input me-3" style="display: none;">
                                        <div class="flex-grow-1">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <div>
                                                    <h6 class="mb-1"><?php echo htmlspecialchars($student['full_name']); ?></h6>
                                                    <p class="mb-0 text-sm text-secondary-light">
                                                        <?php echo htmlspecialchars($student['class_name'] ?? 'N/A'); ?> - 
                                                        <?php echo htmlspecialchars($student['admission_number']); ?>
                                                    </p>
                                                </div>
                                                <div class="relationship-select-wrapper" style="width: 150px;">
                                                    <select name="relationships[<?php echo $student['id']; ?>]" class="form-select form-select-sm" onclick="event.stopPropagation()">
                                                        <option value="father">Father</option>
                                                        <option value="mother">Mother</option>
                                                        <option value="guardian" selected>Legal Guardian</option>
                                                        <option value="brother">Brother</option>
                                                        <option value="sister">Sister</option>
                                                        <option value="uncle">Uncle</option>
                                                        <option value="aunt">Aunt</option>
                                                        <option value="grandfather">Grandfather</option>
                                                        <option value="grandmother">Grandmother</option>
                                                        <option value="other">Other</option>
                                                    </select>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary-600">Link Selected Students</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Relationship Modal -->
<div class="modal fade" id="editRelationshipModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Student Relationship</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" id="editRelationshipForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" value="update_relationship">
                <input type="hidden" name="link_id" id="edit_link_id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Relationship</label>
                        <select name="relationship" id="edit_relationship" class="form-select">
                            <option value="father">Father</option>
                            <option value="mother">Mother</option>
                            <option value="guardian">Legal Guardian</option>
                            <option value="brother">Brother</option>
                            <option value="sister">Sister</option>
                            <option value="uncle">Uncle</option>
                            <option value="aunt">Aunt</option>
                            <option value="grandfather">Grandfather</option>
                            <option value="grandmother">Grandmother</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" name="is_primary" id="edit_is_primary" value="1">
                            <label class="form-check-label" for="edit_is_primary">Set as Primary Student</label>
                            <small class="d-block text-muted">The primary student will be the main contact point</small>
                        </div>
                    </div>
                    <div class="mb-3">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" name="can_pickup" id="edit_can_pickup" value="1">
                            <label class="form-check-label" for="edit_can_pickup">Can Pickup Student</label>
                        </div>
                    </div>
                    <div class="mb-3">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" name="emergency_contact" id="edit_emergency_contact" value="1">
                            <label class="form-check-label" for="edit_emergency_contact">Emergency Contact</label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary-600">Update Relationship</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Login Details sidebar start -->
<div class="my-sidebar bg-white position-fixed end-0 top-0 h-100vh overflow-y-auto z-99 max-w-700-px w-100 translate-x-full duration-300 active-translate-0">
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
                    <img src="<?php echo !empty($guardian['profile_photo']) ? htmlspecialchars($guardian['profile_photo']) : 'https://academixsuite.com/tenant/assets/images/thumbs/student-details-img.png'; ?>" 
                         alt="<?php echo htmlspecialchars($guardian['name'] ?? ''); ?>" class="w-100 h-100 object-fit-cover">
                </figure>
                <div class="flex-grow-1">
                    <h2 class="text-xl text-primary-light mb-4"><?php echo htmlspecialchars($guardian['name'] ?? ''); ?></h2>
                    <p class="mb-0">ID: <span class="text-primary-light fw-semibold"><?php echo $guardian['guardian_id_formatted'] ?? ''; ?></span></p>
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
                        <th scope="col" class="text-start">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="text-start">Guardian</td>
                        <td class="text-start"><?php echo htmlspecialchars($guardian['email'] ?? ''); ?></td>
                        <td class="text-start">••••••••</td>
                        <td class="text-start">
                            <button type="button" class="btn btn-sm btn-primary-600" onclick="resetPassword(<?php echo $guardianId; ?>)">
                                <i class="ri-refresh-line"></i> Reset
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="copyLoginDetails()">
                                <i class="ri-file-copy-line"></i> Copy
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </form>
</div>
<!-- Login Details sidebar end -->

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

        // Student search functionality
        let studentSearchTimeout;
        $('#studentSearch').on('input', function() {
            clearTimeout(studentSearchTimeout);
            const term = $(this).val().trim();
            
            if (term.length < 2) {
                $('#studentSearchResults').hide();
                return;
            }

            studentSearchTimeout = setTimeout(function() {
                $.ajax({
                    url: window.location.pathname + '?id=<?php echo $guardianId; ?>',
                    method: 'GET',
                    data: {
                        ajax: 'search_students',
                        term: term
                    },
                    dataType: 'json',
                    beforeSend: function() {
                        $('#studentSearchResults').html('<div class="search-result-item text-center p-3"><div class="spinner-border spinner-border-sm text-primary" role="status"></div> Searching...</div>').show();
                    },
                    success: function(response) {
                        if (response.success && response.students.length > 0) {
                            let html = '';
                            $.each(response.students, function(index, student) {
                                html += `
                                    <div class="search-result-item" onclick="selectStudentFromSearch(${student.id}, '${escapeHtml(student.full_name)}', '${escapeHtml(student.class_name || 'N/A')}', '${escapeHtml(student.admission_number)}')">
                                        <div class="student-name">${escapeHtml(student.full_name)}</div>
                                        <div class="student-details">
                                            <i class="ri-hashtag"></i> ${escapeHtml(student.admission_number || 'No Adm No')} 
                                            <i class="ri-group-line ms-2"></i> ${escapeHtml(student.class_name || 'No Class')}
                                        </div>
                                    </div>
                                `;
                            });
                            $('#studentSearchResults').html(html).show();
                        } else {
                            $('#studentSearchResults').html('<div class="search-result-item text-muted">No students found</div>').show();
                        }
                    }
                });
            }, 300);
        });

        // Click outside to close search results
        $(document).on('click', function(e) {
            if (!$(e.target).closest('#studentSearch, #studentSearchResults').length) {
                $('#studentSearchResults').hide();
            }
        });

        // Initialize DataTable for login details if needed
        if ($('#loginDetailsTable').length) {
            new DataTable('#loginDetailsTable', {
                paging: false,
                searching: false,
                info: false
            });
        }
    });

    // Escape HTML helper
    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Toggle student selection
    function toggleStudent(studentId) {
        const checkbox = document.getElementById('student_' + studentId);
        const item = checkbox.closest('.student-checkbox-item');
        
        checkbox.checked = !checkbox.checked;
        
        if (checkbox.checked) {
            item.classList.add('selected');
        } else {
            item.classList.remove('selected');
        }
    }

    // Select student from search
    function selectStudentFromSearch(id, name, className, admission) {
        // Check if already in the list
        if ($('#student_' + id).length) {
            toggleStudent(id);
        } else {
            // Add to available students list
            const html = `
                <div class="student-checkbox-item" onclick="toggleStudent(${id})">
                    <div class="d-flex align-items-center">
                        <input type="checkbox" name="student_ids[]" value="${id}" 
                               id="student_${id}" class="form-check-input me-3" checked style="display: none;">
                        <div class="flex-grow-1">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="mb-1">${escapeHtml(name)}</h6>
                                    <p class="mb-0 text-sm text-secondary-light">
                                        ${escapeHtml(className)} - ${escapeHtml(admission)}
                                    </p>
                                </div>
                                <div class="relationship-select-wrapper" style="width: 150px;">
                                    <select name="relationships[${id}]" class="form-select form-select-sm" onclick="event.stopPropagation()">
                                        <option value="father">Father</option>
                                        <option value="mother">Mother</option>
                                        <option value="guardian" selected>Legal Guardian</option>
                                        <option value="brother">Brother</option>
                                        <option value="sister">Sister</option>
                                        <option value="uncle">Uncle</option>
                                        <option value="aunt">Aunt</option>
                                        <option value="grandfather">Grandfather</option>
                                        <option value="grandmother">Grandmother</option>
                                        <option value="other">Other</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            $('#availableStudentsList').prepend(html);
        }
        
        $('#studentSearch').val('');
        $('#studentSearchResults').hide();
    }

    // Edit relationship
    function editRelationship(linkId, relationship, isPrimary, canPickup, emergencyContact) {
        $('#edit_link_id').val(linkId);
        $('#edit_relationship').val(relationship);
        $('#edit_is_primary').prop('checked', isPrimary == 1);
        $('#edit_can_pickup').prop('checked', canPickup == 1);
        $('#edit_emergency_contact').prop('checked', emergencyContact == 1);
        $('#editRelationshipModal').modal('show');
    }

    // Reset password
    function resetPassword(guardianId) {
        if (confirm('Are you sure you want to reset this guardian\'s password? A new password will be generated and sent to their email.')) {
            $.ajax({
                url: window.location.pathname,
                method: 'POST',
                data: {
                    ajax: 'reset_password',
                    guardian_id: guardianId,
                    csrf_token: '<?php echo $csrfToken; ?>'
                },
                success: function(response) {
                    if (response.success) {
                        alert('Password reset successfully. New password has been sent to the guardian\'s email.');
                    } else {
                        alert('Failed to reset password: ' + response.error);
                    }
                }
            });
        }
    }

    // Copy login details
    function copyLoginDetails() {
        const email = '<?php echo addslashes($guardian['email'] ?? ''); ?>';
        const text = `Email: ${email}\nPassword: [Password hidden for security]`;
        
        navigator.clipboard.writeText(text).then(function() {
            alert('Login details copied to clipboard');
        }, function() {
            alert('Failed to copy login details');
        });
    }

    // Sidebar js start
    $('.my-sidebar-btn').on('click', function () {
        $('.my-sidebar').addClass('active');
        $('.overlay').addClass('active');
    });
    $('.close-my-sidebar, .overlay').on('click', function () {
        $('.my-sidebar').removeClass('active');
        $('.overlay').removeClass('active');
    });
</script>

</body>
</html>