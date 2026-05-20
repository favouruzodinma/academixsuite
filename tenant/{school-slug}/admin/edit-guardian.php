<?php
/**
 * School Edit Guardian Page
 * Handles editing existing guardians in the school database
 * 
 * @package AcademixSuite
 * @version 2.0
 */

// Enable error reporting for debugging
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../../logs/school_edit_guardian.log');

error_log("=== EDIT GUARDIAN PAGE START ===");
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
$currentPage = $GLOBALS['CURRENT_PAGE'] ?? 'edit-guardian.php';
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
error_log("Guardian ID to edit: " . $guardianId);

if ($guardianId <= 0) {
    error_log("Invalid guardian ID");
    $_SESSION['toast_error'] = "Invalid guardian ID.";
    header("Location: guardian-list.php");
    exit;
}

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
        
        if (!$guardian) {
            error_log("Guardian not found with ID: " . $guardianId);
            $_SESSION['toast_error'] = "Guardian not found.";
            header("Location: guardian-list.php");
            exit;
        }
        
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

// Guardian types array
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
 * Handle form submission
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardian_name'])) {
    error_log("=== PROCESSING FORM SUBMISSION ===");
    
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        error_log("CSRF validation FAILED");
        $_SESSION['toast_error'] = "Invalid security token. Please try again.";
        header("Location: edit-guardian.php?id=" . $guardianId);
        exit;
    }

    try {
        $schoolDb->beginTransaction();
        
        // Update user information
        $updateStmt = $schoolDb->prepare("
            UPDATE users 
            SET 
                name = ?,
                email = ?,
                phone = ?,
                gender = ?,
                address = ?,
                updated_at = NOW()
            WHERE id = ? AND school_id = ?
        ");
        
        $updateResult = $updateStmt->execute([
            sanitize($_POST['guardian_name']),
            sanitize($_POST['email']),
            sanitize($_POST['phone']),
            $_POST['gender'] ?? null,
            sanitize($_POST['address']),
            $guardianId,
            $school['id']
        ]);

        if (!$updateResult) {
            throw new Exception("Failed to update guardian information");
        }

        // Handle file upload
        if (isset($_FILES['guardian_photo']) && $_FILES['guardian_photo']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = __DIR__ . '/../../../uploads/guardians/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            $fileExt = pathinfo($_FILES['guardian_photo']['name'], PATHINFO_EXTENSION);
            $fileName = 'guardian_' . time() . '_' . uniqid() . '.' . $fileExt;
            $uploadPath = $uploadDir . $fileName;
            
            if (move_uploaded_file($_FILES['guardian_photo']['tmp_name'], $uploadPath)) {
                $photoStmt = $schoolDb->prepare("UPDATE users SET profile_photo = ? WHERE id = ?");
                $photoStmt->execute(['uploads/guardians/' . $fileName, $guardianId]);
            }
        }

        // Handle password update if provided
        if (!empty($_POST['password'])) {
            $hashedPassword = password_hash($_POST['password'], PASSWORD_DEFAULT);
            $passStmt = $schoolDb->prepare("UPDATE users SET password = ? WHERE id = ?");
            $passStmt->execute([$hashedPassword, $guardianId]);
            
            // Store password in session for email
            if (!isset($_SESSION['temp_passwords'])) {
                $_SESSION['temp_passwords'] = [];
            }
            $_SESSION['temp_passwords'][$guardianId] = $_POST['password'];
        }

        // Create audit log
        $auditStmt = $schoolDb->prepare("
            INSERT INTO audit_logs (
                school_id, user_id, user_type, action, entity_type, entity_id,
                new_values, ip_address, user_agent, url, created_at
            ) VALUES (?, ?, ?, 'update', 'guardian', ?, ?, ?, ?, ?, NOW())
        ");
        
        $auditStmt->execute([
            $school['id'],
            $userId,
            $userType,
            $guardianId,
            json_encode($_POST),
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null,
            $_SERVER['REQUEST_URI'] ?? null
        ]);

        $schoolDb->commit();
        
        $_SESSION['toast_success'] = "Guardian updated successfully!";
        header("Location: guardian-details.php?id=" . $guardianId);
        exit;

    } catch (Exception $e) {
        $schoolDb->rollBack();
        error_log("ERROR updating guardian: " . $e->getMessage());
        $_SESSION['toast_error'] = "Error updating guardian: " . $e->getMessage();
        header("Location: edit-guardian.php?id=" . $guardianId);
        exit;
    }
}

// Generate CSRF token
$csrfToken = generateCsrfToken();

// Collect toast messages
$toastSuccess = $_SESSION['toast_success'] ?? '';
$toastError = $_SESSION['toast_error'] ?? '';
unset($_SESSION['toast_success'], $_SESSION['toast_error']);

error_log("=== EDIT GUARDIAN PAGE END ===");
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
  <title><?php echo htmlspecialchars($school['name']); ?> | <?php echo defined('APP_NAME') ? APP_NAME : 'School Management'; ?> - Edit Guardian</title>
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
      position: relative;
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
    .remove-student-btn {
      position: absolute;
      top: 12px;
      right: 12px;
      width: 32px;
      height: 32px;
      border-radius: 8px;
      background: #f8f9fa;
      border: 1px solid #e9ecef;
      color: #6c757d;
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      transition: all 0.2s ease;
    }
    .remove-student-btn:hover {
      background: #dc3545;
      border-color: #dc3545;
      color: white;
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
    
    /* Student avatar */
    .student-avatar-sm {
      width: 40px;
      height: 40px;
      background: linear-gradient(135deg, #e6f7f5, #d1f0ec);
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      color: #25A194;
      font-weight: 600;
      font-size: 16px;
    }
    
    /* Drop zone */
    .drop-zone {
      min-height: 120px;
      border: 2px dashed #e9ecef;
      border-radius: 8px;
      padding: 20px;
      text-align: center;
      cursor: pointer;
      transition: all 0.3s ease;
    }
    .drop-zone:hover {
      border-color: #25A194;
      background-color: #f8f9fa;
    }
    .drop-zone__thumb {
      width: 100%;
      height: 100%;
      border-radius: 10px;
      overflow: hidden;
      background-color: #f8f9fa;
      background-size: cover;
      position: relative;
    }
    .drop-zone__thumb::after {
      content: attr(data-label);
      position: absolute;
      bottom: 0;
      left: 0;
      width: 100%;
      padding: 5px 0;
      color: #ffffff;
      background: rgba(0, 0, 0, 0.5);
      font-size: 12px;
      text-align: center;
    }
    
    /* Student checkbox item */
    .student-checkbox-item {
      cursor: pointer;
      transition: all 0.2s ease;
    }
    .student-checkbox-item:hover {
      background-color: #f8f9fa !important;
    }
    .student-checkbox-item.selected {
      background-color: #f0f9f8;
      border-color: #25A194 !important;
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

        <div class="breadcrumb d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
            <div class="">
                <h1 class="fw-semibold mb-4 h6 text-primary-light">Edit Guardian</h1>
                <div class="">
                    <a href="index.php" class="text-secondary-light hover-text-primary hover-underline">Dashboard </a>
                    <a href="guardian-list.php" class="text-secondary-light hover-text-primary hover-underline"> / Guardian</a>
                    <span class="text-secondary-light">/ Edit Guardian</span>
                </div>
            </div>
            <a href="guardian-details.php?id=<?php echo $guardianId; ?>" class="btn btn-outline-primary d-flex align-items-center gap-6">
                <span class="d-flex text-md">
                    <i class="ri-arrow-left-line"></i>
                </span>
                Back to Details
            </a>
        </div>

        <?php if ($guardian): ?>
        <form action="#" method="POST" enctype="multipart/form-data" class="mt-24">
            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
            
            <div class="row gy-3">
                <!-- Personal Info Section -->
                <div class="col-xl-12">
                    <div class="shadow-1 radius-12 bg-base h-100 overflow-hidden">
                        <div class="card-header border-bottom bg-base py-16 px-24 d-flex align-items-center justify-content-between">
                            <h6 class="text-lg fw-semibold mb-0">Personal Info</h6>
                            <span class="badge bg-primary-50 text-primary-600">ID: <?php echo $guardian['guardian_id_formatted']; ?></span>
                        </div>
                        <div class="card-body p-20">
                            <div class="row gy-3">
                                <div class="col-xl-4 col-sm-6">
                                    <div class="">
                                        <label for="guardianType" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                            Guardian Type <span class="text-danger-600">*</span>
                                        </label>
                                        <select id="guardianType" name="guardian_type" class="form-control form-select" required>
                                            <option value="">Select Guardian</option>
                                            <?php foreach ($guardianTypes as $value => $label): ?>
                                            <option value="<?php echo $value; ?>" <?php echo ($guardian['guardian_type'] ?? '') == $value ? 'selected' : ''; ?>>
                                                <?php echo $label; ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-xl-4 col-sm-6">
                                    <div class="">
                                        <label for="guardianName" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                            Guardian Name <span class="text-danger-600">*</span>
                                        </label>
                                        <input type="text" id="guardianName" name="guardian_name" class="form-control"
                                            placeholder="Enter guardian name" required
                                            value="<?php echo htmlspecialchars($guardian['name']); ?>">
                                    </div>
                                </div>
                                <div class="col-xl-4 col-sm-6">
                                    <div class="">
                                        <label for="instagram" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                            Instagram
                                        </label>
                                        <input type="text" id="instagram" name="instagram" class="form-control"
                                            placeholder="@username"
                                            value="<?php echo htmlspecialchars($guardian['instagram'] ?? ''); ?>">
                                    </div>
                                </div>
                                <div class="col-xl-4 col-sm-6">
                                    <div class="">
                                        <label for="phoneNumber" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                            Phone Number <span class="text-danger-600">*</span>
                                        </label>
                                        <input type="tel" id="phoneNumber" name="phone" class="form-control"
                                            placeholder="Enter phone number" required
                                            value="<?php echo htmlspecialchars($guardian['phone'] ?? ''); ?>">
                                    </div>
                                </div>
                                <div class="col-xl-4 col-sm-6">
                                    <div class="">
                                        <label for="occupation" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                            Occupation
                                        </label>
                                        <input type="text" id="occupation" name="occupation" class="form-control"
                                            placeholder="Enter occupation"
                                            value="<?php echo htmlspecialchars($guardian['occupation'] ?? ''); ?>">
                                    </div>
                                </div>
                                <div class="col-xl-4 col-sm-6">
                                    <div class="">
                                        <label for="guardianAddress" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                            Guardian Address <span class="text-danger-600">*</span>
                                        </label>
                                        <input type="text" id="guardianAddress" name="address" class="form-control"
                                            placeholder="Enter guardian address" required
                                            value="<?php echo htmlspecialchars($guardian['address'] ?? ''); ?>">
                                    </div>
                                </div>
                                <div class="col-xl-4 col-sm-6">
                                    <div class="">
                                        <label for="gender" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Gender</label>
                                        <select id="gender" name="gender" class="form-control form-select">
                                            <option value="">Select Gender</option>
                                            <?php foreach ($genders as $value => $label): ?>
                                            <option value="<?php echo $value; ?>" <?php echo ($guardian['gender'] ?? '') == $value ? 'selected' : ''; ?>>
                                                <?php echo $label; ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-xl-8">
                                    <div class="">
                                        <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                            Guardian Photo
                                        </label>
                                        <div class="drop-zone height-44-px p-4 d-flex justify-content-center align-items-center text-center fw-medium text-md cursor-pointer border border-neutral-400 radius-8 border-dashed bg-hover-neutral-200">
                                            <span class="drop-zone__prompt">Drag & drop a file here or click</span>
                                            <input type="file" name="guardian_photo" class="drop-zone__input" accept="image/*">
                                        </div>
                                        <?php if (!empty($guardian['profile_photo'])): ?>
                                        <small class="text-muted mt-2 d-block">
                                            Current: <?php echo basename($guardian['profile_photo']); ?>
                                        </small>
                                        <?php endif; ?>
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
                                <div class="col-sm-6">
                                    <div class="">
                                        <label for="myEmail" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                            Email <span class="text-danger-600">*</span>
                                        </label>
                                        <input type="email" class="form-control" id="myEmail" name="email" 
                                            placeholder="Enter Email" required
                                            value="<?php echo htmlspecialchars($guardian['email']); ?>">
                                    </div>
                                </div>
                                <div class="col-sm-6">
                                    <div class="">
                                        <label for="your-password" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                            New Password <span class="text-muted">(Leave blank to keep current)</span>
                                        </label>
                                        <div class="position-relative">
                                            <input type="password" id="your-password" name="password" class="form-control"
                                                placeholder="Enter new password">
                                            <span
                                                class="toggle-password ri-eye-line cursor-pointer position-absolute end-0 top-50 translate-middle-y me-16 text-secondary-light"
                                                data-toggle="#your-password"></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Linked Students Section -->
                <div class="col-xl-12">
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
                                <div class="row" id="linkedStudentsContainer">
                                    <?php foreach ($linkedStudents as $index => $student): ?>
                                    <div class="col-md-6 col-lg-4 mb-3 student-card-wrapper" data-student-id="<?php echo $student['id']; ?>">
                                        <div class="student-card <?php echo $student['is_primary'] ? 'primary-card' : ''; ?>">
                                            <div class="remove-student-btn" onclick="unlinkStudent(<?php echo $student['guardian_link_id']; ?>)" title="Unlink student">
                                                <i class="ri-close-line"></i>
                                            </div>
                                            
                                            <div class="d-flex gap-3 mb-3">
                                                <div class="student-avatar-sm">
                                                    <?php echo strtoupper(substr($student['first_name'], 0, 1) . substr($student['last_name'] ?? '', 0, 1)); ?>
                                                </div>
                                                <div class="flex-grow-1">
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
                                            
                                            <div class="border-top pt-3">
                                                <div class="mb-2">
                                                    <label class="form-label fw-medium mb-1">Relationship</label>
                                                    <select class="form-select form-select-sm relationship-select" 
                                                            data-link-id="<?php echo $student['guardian_link_id']; ?>"
                                                            onchange="updateStudentRelationship(this)">
                                                        <option value="father" <?php echo $student['relationship'] == 'father' ? 'selected' : ''; ?>>Father</option>
                                                        <option value="mother" <?php echo $student['relationship'] == 'mother' ? 'selected' : ''; ?>>Mother</option>
                                                        <option value="guardian" <?php echo $student['relationship'] == 'guardian' ? 'selected' : ''; ?>>Legal Guardian</option>
                                                        <option value="brother" <?php echo $student['relationship'] == 'brother' ? 'selected' : ''; ?>>Brother</option>
                                                        <option value="sister" <?php echo $student['relationship'] == 'sister' ? 'selected' : ''; ?>>Sister</option>
                                                        <option value="uncle" <?php echo $student['relationship'] == 'uncle' ? 'selected' : ''; ?>>Uncle</option>
                                                        <option value="aunt" <?php echo $student['relationship'] == 'aunt' ? 'selected' : ''; ?>>Aunt</option>
                                                        <option value="grandfather" <?php echo $student['relationship'] == 'grandfather' ? 'selected' : ''; ?>>Grandfather</option>
                                                        <option value="grandmother" <?php echo $student['relationship'] == 'grandmother' ? 'selected' : ''; ?>>Grandmother</option>
                                                        <option value="other" <?php echo $student['relationship'] == 'other' ? 'selected' : ''; ?>>Other</option>
                                                    </select>
                                                </div>
                                                
                                                <div class="d-flex gap-2 mt-2">
                                                    <label class="d-flex align-items-center gap-1">
                                                        <input type="checkbox" class="form-check-input can-pickup-checkbox" 
                                                               data-link-id="<?php echo $student['guardian_link_id']; ?>"
                                                               <?php echo $student['can_pickup'] ? 'checked' : ''; ?>
                                                               onchange="updateStudentPermission(this, 'can_pickup')">
                                                        <small>Can Pickup</small>
                                                    </label>
                                                    <label class="d-flex align-items-center gap-1">
                                                        <input type="checkbox" class="form-check-input emergency-checkbox"
                                                               data-link-id="<?php echo $student['guardian_link_id']; ?>"
                                                               <?php echo $student['emergency_contact'] ? 'checked' : ''; ?>
                                                               onchange="updateStudentPermission(this, 'emergency_contact')">
                                                        <small>Emergency</small>
                                                    </label>
                                                    <?php if (!$student['is_primary']): ?>
                                                    <label class="d-flex align-items-center gap-1">
                                                        <input type="checkbox" class="form-check-input primary-checkbox"
                                                               data-link-id="<?php echo $student['guardian_link_id']; ?>"
                                                               onchange="setAsPrimary(this)">
                                                        <small>Set as Primary</small>
                                                    </label>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Form Actions -->
                <div class="col-12">
                    <div class="d-flex align-items-center justify-content-center gap-3 mt-8">
                        <a href="guardian-details.php?id=<?php echo $guardianId; ?>" 
                           class="border border-danger-600 bg-hover-danger-200 text-danger-600 text-md px-50 py-11 radius-8 text-decoration-none">
                            Cancel
                        </a>
                        <button type="submit" class="btn btn-primary-600 border border-primary-600 text-md px-28 py-12 radius-8">
                            <i class="ri-save-line me-2"></i>
                            Save Changes
                        </button>
                    </div>
                </div>
            </div>
        </form>
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
            <form method="POST" action="link-student.php">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="guardian_id" value="<?php echo $guardianId; ?>">
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
                                <div class="student-checkbox-item p-2 border rounded mb-2" onclick="toggleStudent(<?php echo $student['id']; ?>)">
                                    <div class="d-flex align-items-center">
                                        <input type="checkbox" name="student_ids[]" value="<?php echo $student['id']; ?>" 
                                               id="student_<?php echo $student['id']; ?>" class="form-check-input me-3" style="display: none;">
                                        <div class="student-avatar-sm me-3">
                                            <?php echo strtoupper(substr($student['first_name'], 0, 1) . substr($student['last_name'] ?? '', 0, 1)); ?>
                                        </div>
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

        // ================== Password Show Hide Js Start ==========
        function initializePasswordToggle(toggleSelector) {
            $(toggleSelector).on('click', function () {
                $(this).toggleClass("ri-eye-off-line");
                var input = $($(this).attr("data-toggle"));
                if (input.attr("type") === "password") {
                    input.attr("type", "text");
                } else {
                    input.attr("type", "password");
                }
            });
        }
        // Call the function
        initializePasswordToggle('.toggle-password');
        // ========================= Password Show Hide Js End ===========================

        // ========================== Drag & Drop Upload photo Js start ========================
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
            item.style.backgroundColor = '#f0f9f8';
            item.style.borderColor = '#25A194';
        } else {
            item.classList.remove('selected');
            item.style.backgroundColor = '';
            item.style.borderColor = '';
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
                <div class="student-checkbox-item p-2 border rounded mb-2 selected" onclick="toggleStudent(${id})" style="background-color: #f0f9f8; border-color: #25A194;">
                    <div class="d-flex align-items-center">
                        <input type="checkbox" name="student_ids[]" value="${id}" 
                               id="student_${id}" class="form-check-input me-3" checked style="display: none;">
                        <div class="student-avatar-sm me-3">
                            ${name.charAt(0)}
                        </div>
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

    // Update student relationship
    function updateStudentRelationship(selectElement) {
        const linkId = $(selectElement).data('link-id');
        const relationship = selectElement.value;
        
        $.ajax({
            url: 'update-student-relationship.php',
            method: 'POST',
            data: {
                link_id: linkId,
                relationship: relationship,
                csrf_token: '<?php echo $csrfToken; ?>'
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    // Show success message (you can implement toast here)
                    console.log('Relationship updated successfully');
                } else {
                    alert('Failed to update relationship: ' + response.error);
                }
            },
            error: function() {
                alert('An error occurred while updating relationship');
            }
        });
    }

    // Update student permission
    function updateStudentPermission(checkbox, permission) {
        const linkId = $(checkbox).data('link-id');
        const value = checkbox.checked ? 1 : 0;
        
        $.ajax({
            url: 'update-student-permission.php',
            method: 'POST',
            data: {
                link_id: linkId,
                permission: permission,
                value: value,
                csrf_token: '<?php echo $csrfToken; ?>'
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    // Show success message
                    console.log('Permission updated successfully');
                } else {
                    alert('Failed to update permission: ' + response.error);
                }
            },
            error: function() {
                alert('An error occurred while updating permission');
            }
        });
    }

    // Set as primary student
    function setAsPrimary(checkbox) {
        const linkId = $(checkbox).data('link-id');
        
        if (checkbox.checked) {
            if (confirm('Setting this student as primary will remove primary status from others. Continue?')) {
                $.ajax({
                    url: 'set-primary-student.php',
                    method: 'POST',
                    data: {
                        link_id: linkId,
                        csrf_token: '<?php echo $csrfToken; ?>'
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            alert('Failed to set as primary: ' + response.error);
                            checkbox.checked = false;
                        }
                    },
                    error: function() {
                        alert('An error occurred');
                        checkbox.checked = false;
                    }
                });
            } else {
                checkbox.checked = false;
            }
        }
    }

    // Unlink student
    function unlinkStudent(linkId) {
        if (confirm('Are you sure you want to unlink this student?')) {
            $.ajax({
                url: 'unlink-student.php',
                method: 'POST',
                data: {
                    link_id: linkId,
                    csrf_token: '<?php echo $csrfToken; ?>'
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert('Failed to unlink student: ' + response.error);
                    }
                },
                error: function() {
                    alert('An error occurred while unlinking student');
                }
            });
        }
    }

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
</script>

</body>
</html>