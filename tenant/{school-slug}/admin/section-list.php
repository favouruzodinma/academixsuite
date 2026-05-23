<?php
/**
 * School Section Details Page
 * Displays and manages all sections in the school
 * 
 * @package AcademixSuite
 * @version 2.0
 */

// Enable error reporting for debugging
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../../logs/school_section_list.log');

error_log("=== SECTION LIST PAGE START ===");
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
$currentPage = $GLOBALS['CURRENT_PAGE'] ?? 'section-list.php';
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
    
    // Include NotificationManager for notifications
    $notificationManagerPath = __DIR__ . '/../../../includes/NotificationManager.php';
    if (file_exists($notificationManagerPath)) {
        require_once $notificationManagerPath;
        error_log("NotificationManager loaded successfully");
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
 * Initialize notification variables using NotificationManager
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
 * Get class ID from URL if filtering by class
 */
$classId = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;

/**
 * Fetch sections with their statistics
 */
$sections = [];
$classes = [];
$totalSections = 0;
$totalStudents = 0;
$totalTeachers = 0;
$selectedClass = null;

if ($schoolDb) {
    try {
        // Get all classes for filter
        $classStmt = $schoolDb->prepare("
            SELECT id, name, code 
            FROM classes 
            WHERE school_id = ? AND is_active = 1
            ORDER BY name
        ");
        $classStmt->execute([$school['id']]);
        $classes = $classStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        
        // Get selected class details if filtering
        if ($classId > 0) {
            $selectedStmt = $schoolDb->prepare("
                SELECT * FROM classes WHERE id = ? AND school_id = ?
            ");
            $selectedStmt->execute([$classId, $school['id']]);
            $selectedClass = $selectedStmt->fetch(PDO::FETCH_ASSOC);
        }
        
        // Get sections with statistics (no description column)
        $sectionStmt = $schoolDb->prepare("
            SELECT 
                s.*,
                c.name as class_name,
                c.code as class_code,
                COUNT(DISTINCT st.id) as student_count,
                COUNT(DISTINCT CASE WHEN st.status = 'active' THEN st.id END) as active_students,
                GROUP_CONCAT(DISTINCT CONCAT(st.first_name, ' ', st.last_name) ORDER BY st.first_name SEPARATOR ', ') as student_names,
                u.name as class_teacher_name
            FROM sections s
            LEFT JOIN classes c ON s.class_id = c.id
            LEFT JOIN students st ON s.id = st.section_id AND st.status = 'active'
            LEFT JOIN users u ON s.class_teacher_id = u.id
            WHERE s.school_id = ? " . ($classId > 0 ? " AND s.class_id = ?" : "") . "
            GROUP BY s.id
            ORDER BY c.name, s.name
        ");
        
        if ($classId > 0) {
            $sectionStmt->execute([$school['id'], $classId]);
        } else {
            $sectionStmt->execute([$school['id']]);
        }
        
        $sections = $sectionStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        
        // Calculate totals
        $totalSections = count($sections);
        foreach ($sections as $section) {
            $totalStudents += $section['student_count'] ?? 0;
        }
        
        // Get total teachers count
        $teacherStmt = $schoolDb->prepare("
            SELECT COUNT(*) as count FROM users 
            WHERE school_id = ? AND user_type = 'teacher' AND is_active = 1
        ");
        $teacherStmt->execute([$school['id']]);
        $totalTeachers = $teacherStmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
        
        error_log("Fetched " . count($sections) . " sections successfully");
        
    } catch (Exception $e) {
        error_log("Error fetching sections: " . $e->getMessage());
        $_SESSION['toast_error'] = "Error loading section data.";
    }
}

/**
 * Get teachers for dropdowns
 */
$availableTeachers = [];
$allTeachers = [];
$assignedTeacherIds = [];

if ($schoolDb) {
    try {
        // Get IDs of teachers already assigned as class teacher in any active class or section
        $assignedStmt = $schoolDb->prepare("
            SELECT DISTINCT class_teacher_id FROM (
                SELECT class_teacher_id FROM classes WHERE school_id = ? AND is_active = 1 AND class_teacher_id IS NOT NULL
                UNION
                SELECT class_teacher_id FROM sections WHERE school_id = ? AND is_active = 1 AND class_teacher_id IS NOT NULL
            ) AS assigned
        ");
        $assignedStmt->execute([$school['id'], $school['id']]);
        $assignedTeacherIds = $assignedStmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Get all active teachers
        $teacherStmt = $schoolDb->prepare("
            SELECT id, name, email 
            FROM users 
            WHERE school_id = ? AND user_type = 'teacher' AND is_active = 1
            ORDER BY name
        ");
        $teacherStmt->execute([$school['id']]);
        $allTeachers = $teacherStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        
        // Available teachers = all teachers not in assigned list
        $availableTeachers = array_filter($allTeachers, function($t) use ($assignedTeacherIds) {
            return !in_array($t['id'], $assignedTeacherIds);
        });
        $availableTeachers = array_values($availableTeachers); // re-index
        
    } catch (Exception $e) {
        error_log("Error fetching teachers: " . $e->getMessage());
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
            case 'create_section':
                // Validate required fields
                if (empty($_POST['name']) || empty($_POST['code']) || empty($_POST['class_id'])) {
                    throw new Exception("Section name, code, and class are required");
                }
                
                // Check if section code already exists for this class
                $checkStmt = $schoolDb->prepare("
                    SELECT id FROM sections 
                    WHERE school_id = ? AND class_id = ? AND code = ?
                ");
                $checkStmt->execute([$school['id'], $_POST['class_id'], $_POST['code']]);
                if ($checkStmt->fetch()) {
                    throw new Exception("Section code already exists for this class");
                }
                
                $schoolDb->beginTransaction();
                
                // Insert into sections (no description column)
                $stmt = $schoolDb->prepare("
                    INSERT INTO sections (
                        school_id, class_id, name, code, room_number, capacity, class_teacher_id, is_active, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                
                $stmt->execute([
                    $school['id'],
                    $_POST['class_id'],
                    $_POST['name'],
                    $_POST['code'],
                    $_POST['room_number'] ?? null,
                    $_POST['capacity'] ?? 40,
                    !empty($_POST['class_teacher_id']) ? $_POST['class_teacher_id'] : null,
                    isset($_POST['is_active']) ? 1 : 1
                ]);
                
                $sectionId = $schoolDb->lastInsertId();
                
                // Create audit log
                $auditStmt = $schoolDb->prepare("
                    INSERT INTO audit_logs (
                        school_id, user_id, user_type, action, entity_type,
                        entity_id, new_values, ip_address, user_agent, url, created_at
                    ) VALUES (?, ?, ?, 'create', 'section', ?, ?, ?, ?, ?, NOW())
                ");
                
                $auditStmt->execute([
                    $school['id'],
                    $userId,
                    $userType,
                    $sectionId,
                    json_encode(['name' => $_POST['name'], 'code' => $_POST['code'], 'class_id' => $_POST['class_id']]),
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    $_SERVER['HTTP_USER_AGENT'] ?? null,
                    $_SERVER['REQUEST_URI'] ?? null
                ]);
                
                $schoolDb->commit();
                
                $success = true;
                $message = "Section created successfully!";
                
                // Refresh sections data
                $sectionStmt->execute([$school['id']]);
                $sections = $sectionStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                $totalSections = count($sections);
                
                break;
                
            case 'edit_section':
                if (empty($_POST['section_id']) || empty($_POST['name']) || empty($_POST['code'])) {
                    throw new Exception("Section ID, name, and code are required");
                }
                
                // Check if code exists for another section in the same class
                $checkStmt = $schoolDb->prepare("
                    SELECT id FROM sections 
                    WHERE school_id = ? AND class_id = ? AND code = ? AND id != ?
                ");
                $checkStmt->execute([$school['id'], $_POST['class_id'], $_POST['code'], $_POST['section_id']]);
                if ($checkStmt->fetch()) {
                    throw new Exception("Section code already exists for this class");
                }
                
                $schoolDb->beginTransaction();
                
                // Update sections (no description column)
                $stmt = $schoolDb->prepare("
                    UPDATE sections 
                    SET name = ?, code = ?, room_number = ?, capacity = ?, class_teacher_id = ?,
                        is_active = ?
                    WHERE id = ? AND school_id = ?
                ");
                
                $stmt->execute([
                    $_POST['name'],
                    $_POST['code'],
                    $_POST['room_number'] ?? null,
                    $_POST['capacity'] ?? 40,
                    !empty($_POST['class_teacher_id']) ? $_POST['class_teacher_id'] : null,
                    isset($_POST['is_active']) ? 1 : 0,
                    $_POST['section_id'],
                    $school['id']
                ]);
                
                // Create audit log
                $auditStmt = $schoolDb->prepare("
                    INSERT INTO audit_logs (
                        school_id, user_id, user_type, action, entity_type,
                        entity_id, new_values, ip_address, user_agent, url, created_at
                    ) VALUES (?, ?, ?, 'update', 'section', ?, ?, ?, ?, ?, NOW())
                ");
                
                $auditStmt->execute([
                    $school['id'],
                    $userId,
                    $userType,
                    $_POST['section_id'],
                    json_encode(['updated_fields' => array_keys($_POST)]),
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    $_SERVER['HTTP_USER_AGENT'] ?? null,
                    $_SERVER['REQUEST_URI'] ?? null
                ]);
                
                $schoolDb->commit();
                
                $success = true;
                $message = "Section updated successfully!";
                
                // Refresh sections data
                $sectionStmt->execute([$school['id']]);
                $sections = $sectionStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                
                break;
                
            case 'delete_section':
                if (empty($_POST['section_id'])) {
                    throw new Exception("Section ID is required");
                }
                
                // Check if section has students
                $studentCheck = $schoolDb->prepare("
                    SELECT COUNT(*) as count FROM students 
                    WHERE section_id = ? AND status = 'active'
                ");
                $studentCheck->execute([$_POST['section_id']]);
                $studentCount = $studentCheck->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
                
                if ($studentCount > 0) {
                    throw new Exception("Cannot delete section with active students. Please reassign students first.");
                }
                
                $schoolDb->beginTransaction();
                
                // Get section data for audit log
                $getStmt = $schoolDb->prepare("SELECT name, code FROM sections WHERE id = ?");
                $getStmt->execute([$_POST['section_id']]);
                $sectionData = $getStmt->fetch(PDO::FETCH_ASSOC);
                
                // Soft delete - just mark as inactive
                $stmt = $schoolDb->prepare("
                    UPDATE sections 
                    SET is_active = 0
                    WHERE id = ? AND school_id = ?
                ");
                $stmt->execute([$_POST['section_id'], $school['id']]);
                
                // Create audit log
                $auditStmt = $schoolDb->prepare("
                    INSERT INTO audit_logs (
                        school_id, user_id, user_type, action, entity_type,
                        entity_id, old_values, ip_address, user_agent, url, created_at
                    ) VALUES (?, ?, ?, 'delete', 'section', ?, ?, ?, ?, ?, NOW())
                ");
                
                $auditStmt->execute([
                    $school['id'],
                    $userId,
                    $userType,
                    $_POST['section_id'],
                    json_encode($sectionData),
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    $_SERVER['HTTP_USER_AGENT'] ?? null,
                    $_SERVER['REQUEST_URI'] ?? null
                ]);
                
                $schoolDb->commit();
                
                $success = true;
                $message = "Section deleted successfully!";
                
                // Refresh sections data
                $sectionStmt->execute([$school['id']]);
                $sections = $sectionStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                $totalSections = count($sections);
                
                break;
                
            default:
                throw new Exception("Unknown action");
        }
        
    } catch (Exception $e) {
        if ($schoolDb && $schoolDb->inTransaction()) {
            $schoolDb->rollBack();
        }
        $error = $e->getMessage();
        error_log("Error processing section action: " . $error);
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

// Encode available teachers for JavaScript (used in edit sidebar to build dropdown)
$availableTeachersJson = json_encode($availableTeachers);

error_log("=== SECTION LIST PAGE END ===");
?>

<!DOCTYPE html>
<html lang="en" data-theme="light">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="description" content="School Sections List - Manage all sections in the school">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($school['name']); ?> | <?php echo defined('APP_NAME') ? APP_NAME : 'School Management'; ?> - Sections List</title>
    
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
        
        .section-card {
            background: white;
            border: 1px solid #e9ecef;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            transition: all 0.3s ease;
        }
        .section-card:hover {
            box-shadow: 0 8px 20px rgba(0,0,0,0.06);
            border-color: #25A194;
        }
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        .section-badge {
            background: #25A194;
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
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
        .capacity-bar {
            height: 8px;
            border-radius: 4px;
            background: #e9ecef;
            margin: 10px 0;
        }
        .capacity-fill {
            height: 100%;
            border-radius: 4px;
            background: #25A194;
            transition: width 0.3s ease;
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
        
        .class-filter {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
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
        <?php require_once __DIR__ . '/includes/nav-header.php'; ?>

        <div class="dashboard-main-body">
            <!-- Breadcrumb -->
            <div class="breadcrumb d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
                <div class="">
                    <h1 class="fw-semibold mb-4 h6 text-primary-light">Section Details</h1>
                    <div class="">
                        <a href="index.php" class="text-secondary-light hover-text-primary hover-underline">Dashboard</a>
                        <?php if ($selectedClass): ?>
                        <a href="class-list.php" class="text-secondary-light hover-text-primary hover-underline"> / Classes</a>
                        <span class="text-secondary-light"> / <?php echo htmlspecialchars($selectedClass['name']); ?> Sections</span>
                        <?php else: ?>
                        <span class="text-secondary-light"> / Sections</span>
                        <?php endif; ?>
                    </div>
                </div>
                <button type="button" class="my-sidebar-btn btn btn-primary-600 d-flex align-items-center gap-6">
                    <span class="d-flex text-md">
                        <i class="ri-add-large-line"></i>
                    </span>
                    Add New Section
                </button>
            </div>

            <!-- Quick Stats -->
            <div class="row mb-24">
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
                                <div class="stat-value"><?php echo count($classes); ?></div>
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
                                <div class="stat-value"><?php echo $totalTeachers; ?></div>
                                <div class="stat-label">Available Teachers</div>
                            </div>
                            <i class="ri-user-star-line"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Class Filter -->
            <div class="class-filter">
                <div class="row align-items-center">
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Filter by Class:</label>
                        <select class="form-select" id="classFilter" onchange="filterByClass(this.value)">
                            <option value="">All Classes</option>
                            <?php foreach ($classes as $class): ?>
                            <option value="<?php echo $class['id']; ?>" <?php echo $classId == $class['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($class['name']); ?> (<?php echo htmlspecialchars($class['code']); ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Filter by Status:</label>
                        <select class="form-select" id="statusFilter">
                            <option value="">All Status</option>
                            <option value="1">Active</option>
                            <option value="0">Inactive</option>
                        </select>
                    </div>
                    <div class="col-md-4 text-md-end mt-4 mt-md-0">
                        <button type="button" class="btn btn-outline-primary me-2" onclick="exportToExcel()">
                            <i class="ri-file-excel-line"></i> Export
                        </button>
                        <button type="button" class="btn btn-outline-primary" onclick="printList()">
                            <i class="ri-printer-line"></i> Print
                        </button>
                    </div>
                </div>
            </div>

            <!-- Sections Table/Grid -->
            <div class="row" id="sectionsContainer">
                <?php if (empty($sections)): ?>
                <div class="col-12">
                    <div class="text-center py-5">
                        <i class="ri-grid-line fs-1 text-secondary-light mb-3 d-block" style="font-size: 3rem;"></i>
                        <h5>No Sections Found</h5>
                        <p class="text-secondary-light mb-4">Get started by adding your first section</p>
                        <button type="button" class="btn btn-primary-600 my-sidebar-btn">
                            <i class="ri-add-line"></i> Add New Section
                        </button>
                    </div>
                </div>
                <?php else: ?>
                    <?php foreach ($sections as $section): 
                        $capacityPercent = $section['capacity'] > 0 ? min(100, round(($section['student_count'] / $section['capacity']) * 100)) : 0;
                    ?>
                    <div class="col-xl-4 col-lg-6 section-item" 
                         data-class="<?php echo $section['class_id']; ?>"
                         data-status="<?php echo $section['is_active']; ?>">
                        <div class="section-card">
                            <div class="section-header">
                                <div class="d-flex align-items-center gap-3">
                                    <div class="stat-icon">
                                        <i class="ri-grid-line"></i>
                                    </div>
                                    <div>
                                        <h5 class="mb-1">Section <?php echo htmlspecialchars($section['name']); ?></h5>
                                        <span class="badge bg-info"><?php echo htmlspecialchars($section['code']); ?></span>
                                        <span class="badge bg-secondary ms-1"><?php echo htmlspecialchars($section['class_name']); ?></span>
                                    </div>
                                </div>
                                <div class="dropdown">
                                    <button type="button" class="btn btn-sm btn-light" data-bs-toggle="dropdown">
                                        <i class="ri-more-2-fill"></i>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end">
                                        <li>
                                            <button type="button" class="dropdown-item" onclick="viewSection(<?php echo $section['id']; ?>)">
                                                <i class="ri-eye-line"></i> View Details
                                            </button>
                                        </li>
                                        <li>
                                            <button type="button" class="dropdown-item" onclick="viewStudents(<?php echo $section['id']; ?>)">
                                                <i class="ri-group-line"></i> View Students
                                            </button>
                                        </li>
                                        <li>
                                            <button type="button" class="dropdown-item edit-section-btn" 
                                                    data-section='<?php echo json_encode($section); ?>'>
                                                <i class="ri-edit-line"></i> Edit
                                            </button>
                                        </li>
                                        <li>
                                            <hr class="dropdown-divider">
                                        </li>
                                        <li>
                                            <button type="button" class="dropdown-item text-danger" 
                                                    onclick="deleteSection(<?php echo $section['id']; ?>, 'Section <?php echo addslashes($section['name']); ?>')">
                                                <i class="ri-delete-bin-line"></i> Delete
                                            </button>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                            
                            <div class="row g-2 mb-3">
                                <div class="col-6">
                                    <div class="d-flex align-items-center gap-2">
                                        <i class="ri-group-line text-success"></i>
                                        <span class="text-sm"><?php echo $section['student_count'] ?? 0; ?> Students</span>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="d-flex align-items-center gap-2">
                                        <i class="ri-door-line text-info"></i>
                                        <span class="text-sm">Room: <?php echo htmlspecialchars($section['room_number'] ?? 'N/A'); ?></span>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="d-flex align-items-center gap-2">
                                        <i class="ri-user-star-line text-warning"></i>
                                        <span class="text-sm"><?php echo htmlspecialchars($section['class_teacher_name'] ?? 'No Teacher'); ?></span>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="d-flex align-items-center gap-2">
                                        <i class="ri-check-line text-primary"></i>
                                        <span class="text-sm"><?php echo $section['active_students'] ?? 0; ?> Active</span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="border-top pt-3">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span class="text-sm">Capacity: <?php echo $section['student_count'] ?? 0; ?>/<?php echo $section['capacity'] ?? 40; ?></span>
                                    <span class="text-sm text-<?php echo $capacityPercent >= 90 ? 'danger' : ($capacityPercent >= 70 ? 'warning' : 'success'); ?>">
                                        <?php echo $capacityPercent; ?>%
                                    </span>
                                </div>
                                <div class="capacity-bar">
                                    <div class="capacity-fill" style="width: <?php echo $capacityPercent; ?>%; background-color: <?php echo $capacityPercent >= 90 ? '#dc3545' : ($capacityPercent >= 70 ? '#ffc107' : '#25A194'); ?>;"></div>
                                </div>
                                
                                <div class="d-flex justify-content-between align-items-center mt-2">
                                    <span class="badge <?php echo $section['is_active'] ? 'bg-success' : 'bg-danger'; ?>">
                                        <?php echo $section['is_active'] ? 'Active' : 'Inactive'; ?>
                                    </span>
                                    <!-- Removed description display because sections table has no description column -->
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <?php require_once __DIR__ . '/includes/footer.php'; ?>
    </main>

    <!-- Add Section Sidebar -->
    <div class="my-sidebar bg-white position-fixed end-0 top-0 h-100vh overflow-y-auto z-99 max-w-700-px w-100">
        <div class="px-20 py-12 border-bottom d-flex align-items-center justify-content-between gap-20">
            <h5 class="text-lg mb-0">Add New Section</h5>
            <button type="button" class="close-my-sidebar text-danger-600 text-lg d-flex">
                <i class="ri-close-large-line"></i>
            </button>
        </div>
        <form method="POST" class="p-20">
            <input type="hidden" name="action" value="create_section">
            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
            
            <div class="row g-3">
                <div class="col-sm-12">
                    <div class="">
                        <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                            Select Class <span class="text-danger-600">*</span>
                        </label>
                        <select name="class_id" class="form-select" required>
                            <option value="">Choose Class</option>
                            <?php foreach ($classes as $class): ?>
                            <option value="<?php echo $class['id']; ?>" <?php echo $classId == $class['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($class['name']); ?> (<?php echo htmlspecialchars($class['code']); ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="col-sm-12">
                    <div class="">
                        <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                            Section Name <span class="text-danger-600">*</span>
                        </label>
                        <input type="text" name="name" class="form-control" placeholder="e.g., A, B, C" required>
                    </div>
                </div>
                
                <div class="col-sm-12">
                    <div class="">
                        <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                            Section Code <span class="text-danger-600">*</span>
                        </label>
                        <input type="text" name="code" class="form-control" placeholder="e.g., A, B, C" required>
                        <small class="text-muted">Unique identifier within the class</small>
                    </div>
                </div>
                
                <div class="col-sm-6">
                    <div class="">
                        <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Room Number</label>
                        <input type="text" name="room_number" class="form-control" placeholder="e.g., A101">
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
                        <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Class Teacher (Optional)</label>
                        <select name="class_teacher_id" class="form-select">
                            <option value="">Select Teacher</option>
                            <?php foreach ($availableTeachers as $teacher): ?>
                            <option value="<?php echo $teacher['id']; ?>"><?php echo htmlspecialchars($teacher['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Only teachers not already assigned as class teacher are shown.</small>
                    </div>
                </div>
                
                <!-- Removed description field -->
                
                <div class="col-sm-12">
                    <div class="form-check">
                        <input type="checkbox" name="is_active" class="form-check-input" id="is_active" value="1" checked>
                        <label class="form-check-label" for="is_active">Active Section</label>
                    </div>
                </div>
                
                <div class="col-12">
                    <div class="d-flex align-items-center justify-content-center gap-3 mt-8">
                        <button type="button" class="border border-danger-600 bg-hover-danger-200 text-danger-600 text-md px-50 py-11 radius-8 close-my-sidebar">
                            Cancel
                        </button>
                        <button type="submit" class="btn btn-primary-600 border border-primary-600 text-md px-28 py-12 radius-8">
                            Create Section
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <!-- Edit Section Sidebar -->
    <div class="edit-sidebar bg-white position-fixed end-0 top-0 h-100vh overflow-y-auto z-99 max-w-700-px w-100">
        <div class="px-20 py-12 border-bottom d-flex align-items-center justify-content-between gap-20">
            <h5 class="text-lg mb-0">Edit Section</h5>
            <button type="button" class="close-edit-sidebar text-danger-600 text-lg d-flex">
                <i class="ri-close-large-line"></i>
            </button>
        </div>
        <form method="POST" class="p-20" id="editSectionForm">
            <input type="hidden" name="action" value="edit_section">
            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
            <input type="hidden" name="section_id" id="edit_section_id">
            
            <div class="row g-3">
                <div class="col-sm-12">
                    <div class="">
                        <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                            Class
                        </label>
                        <select name="class_id" id="edit_class_id" class="form-select" required>
                            <option value="">Choose Class</option>
                            <?php foreach ($classes as $class): ?>
                            <option value="<?php echo $class['id']; ?>"><?php echo htmlspecialchars($class['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="col-sm-12">
                    <div class="">
                        <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                            Section Name <span class="text-danger-600">*</span>
                        </label>
                        <input type="text" name="name" id="edit_name" class="form-control" required>
                    </div>
                </div>
                
                <div class="col-sm-12">
                    <div class="">
                        <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                            Section Code <span class="text-danger-600">*</span>
                        </label>
                        <input type="text" name="code" id="edit_code" class="form-control" required>
                    </div>
                </div>
                
                <div class="col-sm-6">
                    <div class="">
                        <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Room Number</label>
                        <input type="text" name="room_number" id="edit_room_number" class="form-control">
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
                        <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Class Teacher</label>
                        <select name="class_teacher_id" id="edit_class_teacher_id" class="form-select">
                            <option value="">Select Teacher</option>
                            <!-- Options will be populated by JavaScript -->
                        </select>
                        <small class="text-muted">Only teachers not already assigned elsewhere are shown, plus the current teacher.</small>
                    </div>
                </div>
                
                <!-- Removed description field -->
                
                <div class="col-sm-12">
                    <div class="form-check">
                        <input type="checkbox" name="is_active" class="form-check-input" id="edit_is_active" value="1">
                        <label class="form-check-label" for="edit_is_active">Active Section</label>
                    </div>
                </div>
                
                <div class="col-12">
                    <div class="d-flex align-items-center justify-content-center gap-3 mt-8">
                        <button type="button" class="border border-danger-600 bg-hover-danger-200 text-danger-600 text-md px-50 py-11 radius-8 close-edit-sidebar">
                            Cancel
                        </button>
                        <button type="submit" class="btn btn-primary-600 border border-primary-600 text-md px-28 py-12 radius-8">
                            Update Section
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteSectionModal" tabindex="-1">
        <div class="modal-dialog modal-sm modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-body pt-32 px-36 pb-24 text-center">
                    <span class="mb-16 fs-1 line-height-1 text-danger">
                        <i class="ri-delete-bin-line" style="font-size: 48px;"></i>
                    </span>
                    <h6 class="text-lg fw-semibold text-primary-light mb-8">Delete Section</h6>
                    <p class="mb-24" id="deleteSectionMessage">Are you sure you want to delete this section?</p>
                    <form method="POST" id="deleteSectionForm">
                        <input type="hidden" name="action" value="delete_section">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                        <input type="hidden" name="section_id" id="delete_section_id">
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

    <!-- Pass available teachers to JavaScript -->
    <script>
        var availableTeachers = <?php echo $availableTeachersJson; ?>;
    </script>

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
            $('.edit-section-btn').on('click', function () {
                const sectionData = $(this).data('section');
                
                // Populate basic fields
                $('#edit_section_id').val(sectionData.id);
                $('#edit_class_id').val(sectionData.class_id);
                $('#edit_name').val(sectionData.name);
                $('#edit_code').val(sectionData.code);
                $('#edit_room_number').val(sectionData.room_number || '');
                $('#edit_capacity').val(sectionData.capacity || 40);
                $('#edit_is_active').prop('checked', sectionData.is_active == 1);
                
                // Build teacher dropdown: include current teacher + all available teachers (excluding duplicates)
                const currentTeacherId = sectionData.class_teacher_id ? sectionData.class_teacher_id : '';
                const currentTeacherName = sectionData.class_teacher_name ? sectionData.class_teacher_name : '';
                
                let teacherSelect = $('#edit_class_teacher_id');
                teacherSelect.empty().append('<option value="">Select Teacher</option>');
                
                // Add current teacher if exists and not already in available list
                if (currentTeacherId) {
                    const alreadyInAvailable = availableTeachers.some(t => t.id == currentTeacherId);
                    if (!alreadyInAvailable) {
                        teacherSelect.append('<option value="' + currentTeacherId + '">' + currentTeacherName + ' (current)</option>');
                    }
                }
                
                // Add available teachers
                availableTeachers.forEach(function(teacher) {
                    // If this teacher is the current one, skip to avoid duplicate (but we already added it above if not in available)
                    if (teacher.id == currentTeacherId) return;
                    teacherSelect.append('<option value="' + teacher.id + '">' + teacher.name + '</option>');
                });
                
                // Set selected value
                if (currentTeacherId) {
                    teacherSelect.val(currentTeacherId);
                }
                
                $('.edit-sidebar').addClass('active');
                $('.overlay').addClass('active');
            });
            
            $('.close-edit-sidebar, .overlay').on('click', function () {
                $('.edit-sidebar').removeClass('active');
                $('.overlay').removeClass('active');
            });

            // Filter functionality
            $('#statusFilter').on('change', function() {
                const status = $(this).val();
                
                $('.section-item').each(function() {
                    const itemStatus = $(this).data('status');
                    const show = status === '' || itemStatus == status;
                    $(this).toggle(show);
                });
            });

            // Search functionality
            $('.navbar-search input').on('keyup', function() {
                const searchTerm = $(this).val().toLowerCase();
                
                $('.section-card').each(function() {
                    const sectionName = $(this).find('h5').text().toLowerCase();
                    const sectionCode = $(this).find('.badge:first').text().toLowerCase();
                    const className = $(this).find('.badge.bg-secondary').text().toLowerCase();
                    
                    if (sectionName.includes(searchTerm) || sectionCode.includes(searchTerm) || className.includes(searchTerm)) {
                        $(this).closest('.section-item').show();
                    } else {
                        $(this).closest('.section-item').hide();
                    }
                });
            });
        });

        // Filter by class
        function filterByClass(classId) {
            if (classId) {
                window.location.href = 'section-list.php?class_id=' + classId;
            } else {
                window.location.href = 'section-list.php';
            }
        }

        // View section details
        function viewSection(sectionId) {
            window.location.href = 'section-details.php?id=' + sectionId;
        }

        // View students in section
        function viewStudents(sectionId) {
            window.location.href = 'student-list.php?section_id=' + sectionId;
        }

        // Delete section
        function deleteSection(sectionId, sectionName) {
            $('#delete_section_id').val(sectionId);
            $('#deleteSectionMessage').text('Are you sure you want to delete "' + sectionName + '"? This will not delete students but will mark the section as inactive.');
            $('#deleteSectionModal').modal('show');
        }

        // Export to Excel
        function exportToExcel() {
            let csv = "Section Name,Code,Class,Room,Capacity,Students,Teacher,Status\n";
            
            $('.section-card').each(function() {
                const card = $(this);
                const name = card.find('h5').text().replace('Section', '').trim();
                const code = card.find('.badge:first').text().trim();
                const className = card.find('.badge.bg-secondary').text().trim();
                const room = card.find('.ri-door-line').parent().text().replace('Room:', '').trim();
                const capacity = card.find('.text-sm:contains("Capacity")').text().split('/')[1]?.trim() || '40';
                const students = card.find('.ri-group-line').parent().text().replace('Students', '').trim();
                const teacher = card.find('.ri-user-star-line').parent().text().trim();
                const status = card.find('.badge:last').text().trim();
                
                csv += `"${name}","${code}","${className}","${room}","${capacity}","${students}","${teacher}","${status}"\n`;
            });
            
            const blob = new Blob([csv], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'sections-list.csv';
            a.click();
            window.URL.revokeObjectURL(url);
        }

        // Print list
        function printList() {
            const printWindow = window.open('', '_blank');
            printWindow.document.write(`
                <html>
                <head>
                    <title>Sections List - <?php echo htmlspecialchars($school['name']); ?></title>
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
                    </style>
                </head>
                <body>
                    <h1><?php echo htmlspecialchars($school['name']); ?></h1>
                    <h2>Sections List</h2>
                    <p>Generated on: ${new Date().toLocaleString()}</p>
                    
                    <table>
                        <thead>
                            <tr>
                                <th>Section</th>
                                <th>Code</th>
                                <th>Class</th>
                                <th>Room</th>
                                <th>Capacity</th>
                                <th>Students</th>
                                <th>Teacher</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
            `);
            
            $('.section-card').each(function() {
                const card = $(this);
                const name = card.find('h5').text().trim();
                const code = card.find('.badge:first').text().trim();
                const className = card.find('.badge.bg-secondary').text().trim();
                const room = card.find('.ri-door-line').parent().text().replace('Room:', '').trim() || 'N/A';
                const capacity = card.find('.text-sm:contains("Capacity")').text().split('/')[1]?.trim() || '40';
                const students = card.find('.ri-group-line').parent().text().replace('Students', '').trim();
                const teacher = card.find('.ri-user-star-line').parent().text().replace('No Teacher', 'N/A').trim();
                const status = card.find('.badge:last').text().trim();
                const statusClass = status === 'Active' ? 'badge-success' : 'badge-danger';
                
                printWindow.document.write(`
                    <tr>
                        <td>${name}</td>
                        <td><span class="badge badge-info">${code}</span></td>
                        <td>${className}</td>
                        <td>${room}</td>
                        <td>${capacity}</td>
                        <td>${students}</td>
                        <td>${teacher}</td>
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