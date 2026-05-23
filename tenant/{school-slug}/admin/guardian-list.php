<?php
/**
 * School Guardian List Page
 * Displays all guardians/parents in the school
 * 
 * @package AcademixSuite
 * @version 2.0
 */

// Enable error reporting for debugging
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../../logs/school_guardian_list.log');

error_log("=== GUARDIAN LIST PAGE START ===");
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
$currentPage = $GLOBALS['CURRENT_PAGE'] ?? 'guardian-list.php';
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
 * Initialize GuardianManager
 */
$guardianManager = null;
if ($schoolDb) {
    try {
        $guardianManager = new GuardianManager($schoolDb, $school['id'], $userId, $userType, $school);
        error_log("GuardianManager initialized successfully");
    } catch (Exception $e) {
        error_log("ERROR initializing GuardianManager: " . $e->getMessage());
        $_SESSION['toast_error'] = "Failed to initialize guardian management system.";
    }
}

$notificationCount = 0;
$notifications = [];
if ($guardianManager) {
    try {
        $notificationCount = (int) $guardianManager->getNotificationCount();
        $notifications = $guardianManager->getNotifications(5);
    } catch (Exception $e) {
        error_log("Error fetching notifications: " . $e->getMessage());
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

/**
 * Get guardians list from database
 */
$guardians = [];
$totalGuardians = 0;

if ($schoolDb) {
    try {
        // Get total count
        $countStmt = $schoolDb->prepare("
            SELECT COUNT(DISTINCT u.id) as total 
            FROM users u
            JOIN user_roles ur ON u.id = ur.user_id
            WHERE u.school_id = ? AND u.user_type = 'parent' AND ur.role_id = 5
        ");
        $countStmt->execute([$school['id']]);
        $totalGuardians = $countStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
        
        // Get guardians with their linked students
        $stmt = $schoolDb->prepare("
            SELECT 
                u.id,
                u.name,
                u.email,
                u.phone,
                u.profile_photo,
                u.created_at,
                u.is_active,
                (
                    SELECT CONCAT(
                        '[',
                        GROUP_CONCAT(
                            JSON_OBJECT(
                                'student_id', s.id,
                                'student_name', CONCAT(s.first_name, ' ', s.last_name),
                                'class_name', c.name,
                                'section_name', sc.name,
                                'admission_number', s.admission_number,
                                'relationship', g.relationship,
                                'is_primary', g.is_primary
                            )
                        ),
                        ']'
                    )
                    FROM guardians g
                    JOIN students s ON g.student_id = s.id
                    LEFT JOIN classes c ON s.class_id = c.id
                    LEFT JOIN sections sc ON s.section_id = sc.id
                    WHERE g.user_id = u.id AND g.school_id = u.school_id
                ) as linked_students
            FROM users u
            JOIN user_roles ur ON u.id = ur.user_id
            WHERE u.school_id = ? AND u.user_type = 'parent' AND ur.role_id = 5
            ORDER BY u.created_at DESC
        ");
        $stmt->execute([$school['id']]);
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            // Parse linked students JSON
            $row['linked_students'] = json_decode($row['linked_students'] ?? '[]', true);
            $guardians[] = $row;
        }
        
        error_log("Found " . count($guardians) . " guardians");
        
    } catch (Exception $e) {
        error_log("Error fetching guardians: " . $e->getMessage());
        $_SESSION['toast_error'] = "Error loading guardians list.";
    }
}

/**
 * Handle AJAX requests
 */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    
    try {
        if ($_GET['ajax'] === 'get_guardians' && $guardianManager) {
            $search = $_GET['search'] ?? '';
            $offset = (int)($_GET['offset'] ?? 0);
            $limit = (int)($_GET['limit'] ?? 10);
            
            // Search guardians
            $searchStmt = $schoolDb->prepare("
                SELECT 
                    u.id,
                    u.name,
                    u.email,
                    u.phone,
                    u.profile_photo,
                    u.created_at,
                    u.is_active,
                    (
                        SELECT COUNT(*) 
                        FROM guardians g 
                        WHERE g.user_id = u.id
                    ) as student_count
                FROM users u
                JOIN user_roles ur ON u.id = ur.user_id
                WHERE u.school_id = ? AND u.user_type = 'parent' AND ur.role_id = 5
                AND (u.name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)
                ORDER BY u.created_at DESC
                LIMIT ? OFFSET ?
            ");
            
            $searchTerm = "%{$search}%";
            $searchStmt->execute([$school['id'], $searchTerm, $searchTerm, $searchTerm, $limit, $offset]);
            
            echo json_encode([
                'success' => true, 
                'guardians' => $searchStmt->fetchAll(PDO::FETCH_ASSOC)
            ]);
            exit;
        }
        
        if ($_GET['ajax'] === 'delete_guardian' && isset($_GET['id']) && $guardianManager) {
            $guardianId = (int)$_GET['id'];
            
            // Check if guardian has any students linked
            $checkStmt = $schoolDb->prepare("SELECT COUNT(*) as count FROM guardians WHERE user_id = ? AND school_id = ?");
            $checkStmt->execute([$guardianId, $school['id']]);
            $studentCount = $checkStmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
            
            if ($studentCount > 0) {
                echo json_encode([
                    'success' => false, 
                    'error' => 'Cannot delete guardian with linked students. Please remove student links first.'
                ]);
                exit;
            }
            
            // Soft delete or hard delete? Using soft delete by setting inactive
            $deleteStmt = $schoolDb->prepare("UPDATE users SET is_active = 0, deleted_at = NOW() WHERE id = ? AND school_id = ?");
            $result = $deleteStmt->execute([$guardianId, $school['id']]);
            
            echo json_encode([
                'success' => $result,
                'message' => $result ? 'Guardian deactivated successfully' : 'Failed to deactivate guardian'
            ]);
            exit;
        }
    } catch (Exception $e) {
        error_log("AJAX error: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'An error occurred']);
        exit;
    }
}

/**
 * Handle form actions
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'delete' && isset($_POST['guardian_id'])) {
        $guardianId = (int)$_POST['guardian_id'];
        
        try {
            // Check if guardian has any students linked
            $checkStmt = $schoolDb->prepare("SELECT COUNT(*) as count FROM guardians WHERE user_id = ? AND school_id = ?");
            $checkStmt->execute([$guardianId, $school['id']]);
            $studentCount = $checkStmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
            
            if ($studentCount > 0) {
                $_SESSION['toast_error'] = "Cannot delete guardian with linked students. Please remove student links first.";
            } else {
                // Soft delete
                $deleteStmt = $schoolDb->prepare("UPDATE users SET is_active = 0, deleted_at = NOW() WHERE id = ? AND school_id = ?");
                if ($deleteStmt->execute([$guardianId, $school['id']])) {
                    $_SESSION['toast_success'] = "Guardian deactivated successfully";
                    
                    // Create audit log
                    $auditStmt = $schoolDb->prepare("
                        INSERT INTO audit_logs (
                            school_id, user_id, user_type, action, entity_type, entity_id,
                            ip_address, user_agent, url, created_at
                        ) VALUES (?, ?, ?, 'delete', 'guardian', ?, ?, ?, ?, NOW())
                    ");
                    
                    $auditStmt->execute([
                        $school['id'],
                        $userId,
                        $userType,
                        $guardianId,
                        $_SERVER['REMOTE_ADDR'] ?? null,
                        $_SERVER['HTTP_USER_AGENT'] ?? null,
                        $_SERVER['REQUEST_URI'] ?? null
                    ]);
                } else {
                    $_SESSION['toast_error'] = "Failed to deactivate guardian";
                }
            }
        } catch (Exception $e) {
            error_log("Error deleting guardian: " . $e->getMessage());
            $_SESSION['toast_error'] = "Error deleting guardian";
        }
        
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
}

// Collect toast messages
$toastSuccess = $_SESSION['toast_success'] ?? '';
$toastError = $_SESSION['toast_error'] ?? '';
unset($_SESSION['toast_success'], $_SESSION['toast_error']);

error_log("=== GUARDIAN LIST PAGE END ===");
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
  <title><?php echo htmlspecialchars($school['name']); ?> | <?php echo defined('APP_NAME') ? APP_NAME : 'School Management'; ?> - Guardian List</title>
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
    
    .student-badge {
      background: var(--primary-50);
      color: var(--primary-700);
      padding: 4px 8px;
      border-radius: 6px;
      font-size: 12px;
      display: inline-block;
      margin: 2px;
    }
    
    .primary-badge {
      background: var(--primary-600);
      color: white;
      padding: 2px 6px;
      border-radius: 4px;
      font-size: 10px;
      margin-left: 4px;
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
    .status-inactive {
      background: #f8d7da;
      color: #721c24;
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
    <?php require_once __DIR__ . '/includes/nav-header.php'; ?>

    <div class="dashboard-main-body">
        <div class="breadcrumb d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
            <div class="">
                <h1 class="fw-semibold mb-4 h6 text-primary-light">Guardian List</h1>
                <div class="">
                    <a href="index.php" class="text-secondary-light hover-text-primary hover-underline">Dashboard </a>
                    <a href="javascript:void(0)" class="text-secondary-light hover-text-primary hover-underline d-none"> / guardian</a>
                    <span class="text-secondary-light">/ Guardian List</span>
                </div>
            </div>
            <a href="add-new-guardian.php" class="btn btn-primary-600 d-flex align-items-center gap-6">
                <span class="d-flex text-md">
                    <i class="ri-add-large-line"></i>
                </span>
                Add Guardian
            </a>
        </div>

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
                                            class="dropdown-item px-16 py-8 rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-10"
                                            onclick="exportGuardians('pdf')">
                                            <i class="ri-file-3-line"></i>
                                            PDF
                                        </button>
                                    </li>
                                    <li>
                                        <button type="button"
                                            class="dropdown-item px-16 py-8 rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-10"
                                            onclick="exportGuardians('excel')">
                                            <i class="ri-file-excel-line"></i>
                                            Excel
                                        </button>
                                    </li>
                                </ul>
                            </div>
                            <form class="navbar-search dt-search m-0">
                                <input type="text" class="dt-input bg-transparent radius-4" aria-controls="dataTable"
                                    name="search" placeholder="Search..." id="searchInput">
                                <iconify-icon icon="ion:search-outline" class="icon"></iconify-icon>
                            </form>
                            <div class="dropdown">
                                <button type="button"
                                    class="px-12 py-5-px border border-neutral-300 radius-8 d-flex align-items-center gap-20"
                                    data-bs-toggle="dropdown" aria-expanded="false">
                                    <span class="d-flex align-items-center gap-1 text-secondary-light text-sm">
                                        Filter
                                    </span>
                                    <span class="">
                                        <i class="ri-arrow-down-s-line"></i>
                                    </span>
                                </button>
                                <div class="dropdown-menu border bg-base shadow dropdown-menu-lg p-0">
                                    <div class="d-flex align-items-center justify-content-between border-bottom py-8 px-16">
                                        <span class="fw-semibold text-lg text-primary-light">Filter</span>
                                        <button type="button" onclick="closeFilter()">
                                            <i class="ri-close-large-line"></i>
                                        </button>
                                    </div>

                                    <form action="#" class="p-16" id="filterForm">
                                        <div class="row g-3">
                                            <div class="col-12">
                                                <label for="status"
                                                    class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Status</label>
                                                <select id="status" class="form-control form-select" name="status">
                                                    <option value="">All</option>
                                                    <option value="active">Active</option>
                                                    <option value="inactive">Inactive</option>
                                                </select>
                                            </div>
                                            <div class="col-6">
                                                <button type="reset" class="btn btn-danger-200 text-danger-600 w-100" onclick="resetFilter()">Reset</button>
                                            </div>
                                            <div class="col-6">
                                                <button type="submit" class="btn btn-primary-600 w-100">Apply</button>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-8 text-secondary-light">
                            <span class="">
                                Rows per page:
                            </span>
                            <div class="dt-length">
                                <select name="dataTable_length" aria-controls="dataTable"
                                    class="dt-input form-control form-select" id="pageLength">
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
                        <table class="table bordered-table mb-0 data-table" id="dataTable" data-page-length='10'>
                            <thead>
                                <tr>
                                    <th scope="col" width="50">
                                        <div class="form-check style-check d-flex align-items-center">
                                            <input class="form-check-input" type="checkbox" id="selectAll">
                                            <label class="form-check-label">S.L</label>
                                        </div>
                                    </th>
                                    <th scope="col">ID</th>
                                    <th scope="col">Name</th>
                                    <th scope="col">Children</th>
                                    <th scope="col">Email</th>
                                    <th scope="col">Phone Number</th>
                                    <th scope="col">Join Date</th>
                                    <th scope="col">Status</th>
                                    <th scope="col">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($guardians)): ?>
                                <tr>
                                    <td colspan="9" class="text-center py-4">
                                        <div class="text-secondary-light">
                                            <i class="ri-user-search-line fs-1 mb-3 d-block"></i>
                                            <p>No guardians found. Click "Add Guardian" to create one.</p>
                                        </div>
                                    </td>
                                </tr>
                                <?php else: ?>
                                    <?php $sl = 1; ?>
                                    <?php foreach ($guardians as $guardian): ?>
                                    <tr>
                                        <td>
                                            <div class="form-check style-check d-flex align-items-center">
                                                <input class="form-check-input row-checkbox" type="checkbox" value="<?php echo $guardian['id']; ?>">
                                                <label class="form-check-label"><?php echo str_pad($sl++, 2, '0', STR_PAD_LEFT); ?></label>
                                            </div>
                                        </td>
                                        <td><span class="text-primary-600">G<?php echo str_pad($guardian['id'], 5, '0', STR_PAD_LEFT); ?></span></td>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <img src="<?php echo !empty($guardian['profile_photo']) ? htmlspecialchars($guardian['profile_photo']) : 'https://academixsuite.com/tenant/assets/images/thumbs/teacher-avatar-img1.png'; ?>"
                                                    alt="<?php echo htmlspecialchars($guardian['name']); ?>" class="flex-shrink-0 me-12 radius-8" style="width: 40px; height: 40px; object-fit: cover;">
                                                <div>
                                                    <h6 class="text-md mb-0 fw-medium flex-grow-1 text-secondary-light">
                                                        <?php echo htmlspecialchars($guardian['name']); ?>
                                                    </h6>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if (!empty($guardian['linked_students'])): ?>
                                                <?php foreach ($guardian['linked_students'] as $index => $student): ?>
                                                <div class="d-flex align-items-center mb-2">
                                                    <div>
                                                        <h6 class="text-sm mb-0 fw-medium flex-grow-1 text-secondary-light">
                                                            <?php echo htmlspecialchars($student['student_name']); ?>
                                                            <?php if ($student['is_primary']): ?>
                                                                <span class="primary-badge">Primary</span>
                                                            <?php endif; ?>
                                                        </h6>
                                                        <span class="text-secondary-light text-xs">
                                                            <?php echo htmlspecialchars($student['class_name'] ?? 'N/A'); ?> 
                                                            (<?php echo htmlspecialchars($student['section_name'] ?? 'N/A'); ?>) - 
                                                            <?php echo htmlspecialchars($student['relationship']); ?>
                                                        </span>
                                                    </div>
                                                </div>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <span class="text-secondary-light">No children linked</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($guardian['email']); ?></td>
                                        <td><?php echo htmlspecialchars($guardian['phone'] ?? 'N/A'); ?></td>
                                        <td><?php echo date('d M Y', strtotime($guardian['created_at'])); ?></td>
                                        <td>
                                            <span class="status-badge <?php echo $guardian['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                                                <?php echo $guardian['is_active'] ? 'Active' : 'Inactive'; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="btn-group">
                                                <button type="button" class="text-primary-light text-xl"
                                                    data-bs-toggle="dropdown" aria-expanded="false">
                                                    <iconify-icon icon="tabler:dots-vertical"></iconify-icon>
                                                </button>
                                                <ul class="dropdown-menu dropdown-menu-lg-end border p-12">
                                                    <li>
                                                        <a href="guardian-details.php?id=<?php echo $guardian['id']; ?>"
                                                            class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                            <i class="ri-user-3-line"></i>View Guardian
                                                        </a>
                                                    </li>
                                                    <li>
                                                        <a href="edit-guardian.php?id=<?php echo $guardian['id']; ?>"
                                                            class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                            <i class="ri-edit-2-line"></i>Edit
                                                        </a>
                                                    </li>
                                                    <?php if ($guardian['is_active']): ?>
                                                    <li>
                                                        <a href="login-as-parent.php?id=<?php echo $guardian['id']; ?>"
                                                            class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                            <i class="ri-login-box-line"></i>Login as Parent
                                                        </a>
                                                    </li>
                                                    <?php endif; ?>
                                                    <li>
                                                        <button
                                                            class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"
                                                            onclick="confirmDelete(<?php echo $guardian['id']; ?>, '<?php echo htmlspecialchars($guardian['name']); ?>')">
                                                            <i class="ri-delete-bin-6-line"></i><?php echo $guardian['is_active'] ? 'Deactivate' : 'Delete'; ?>
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
                    
                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-16 px-20 py-12 border-top border-neutral-200">
                        <div class="text-secondary-light">
                            Showing <?php echo count($guardians); ?> of <?php echo $totalGuardians; ?> entries
                        </div>
                        <nav aria-label="Page navigation">
                            <ul class="pagination mb-0" id="pagination">
                                <!-- Pagination will be populated by JavaScript -->
                            </ul>
                        </nav>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php require_once __DIR__ . '/includes/footer.php'; ?>
</main>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm modal-dialog modal-dialog-centered max-w-340-px">
        <div class="modal-content radius-16 bg-base">
            <div class="modal-body pt-32 px-36 pb-24 text-center">
                <span class="mb-16 fs-1 line-height-1 text-danger">
                    <iconify-icon icon="fluent:delete-24-regular" class="menu-icon"></iconify-icon>
                </span>
                <h6 class="text-lg fw-semibold text-primary-light mb-0" id="deleteModalMessage">
                    Are you sure you want to deactivate this guardian?
                </h6>
                <p class="text-secondary-light text-sm mt-2" id="deleteGuardianName"></p>
                <div class="d-flex align-items-center justify-content-center gap-3 mt-24">
                    <button type="button"
                        class="flex-grow-1 border border-danger-600 bg-hover-danger-200 text-danger-600 text-md px-24 py-11 radius-8"
                        data-bs-dismiss="modal">
                        Cancel
                    </button>
                    <form method="POST" style="display: inline;" id="deleteForm">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="guardian_id" id="deleteGuardianId">
                        <button type="submit"
                            class="flex-grow-1 btn btn-danger-600 border border-danger-600 text-md px-16 py-12 radius-8">
                            Yes, Deactivate
                        </button>
                    </form>
                </div>
            </div>
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

        // Initialize DataTable
        let table = new DataTable('#dataTable', {
            pageLength: 10,
            ordering: true,
            searching: true,
            paging: true,
            info: true,
            language: {
                emptyTable: "No guardians found",
                zeroRecords: "No matching guardians found"
            }
        });

        // Handle search input
        $('#searchInput').on('keyup', function() {
            table.search(this.value).draw();
        });

        // Handle page length change
        $('#pageLength').on('change', function() {
            const value = $(this).val();
            table.page.len(value).draw();
        });

        // Handle filter form
        $('#filterForm').on('submit', function(e) {
            e.preventDefault();
            const status = $('#status').val();
            
            // Custom filtering
            $.fn.dataTable.ext.search.push(
                function(settings, data, dataIndex) {
                    const statusCell = data[7]; // Status column
                    if (!status) return true;
                    if (status === 'active') return statusCell.includes('Active');
                    if (status === 'inactive') return statusCell.includes('Inactive');
                    return true;
                }
            );
            
            table.draw();
            $.fn.dataTable.ext.search.pop();
        });

        // Select all checkboxes
        $('#selectAll').on('change', function() {
            $('.row-checkbox').prop('checked', $(this).prop('checked'));
        });

        // Individual checkbox change
        $('.row-checkbox').on('change', function() {
            if ($('.row-checkbox:checked').length === $('.row-checkbox').length) {
                $('#selectAll').prop('checked', true);
            } else {
                $('#selectAll').prop('checked', false);
            }
        });
    });

    // Export function
    function exportGuardians(type) {
        if (type === 'pdf') {
            // Implement PDF export
            alert('PDF export functionality will be implemented here');
        } else if (type === 'excel') {
            // Implement Excel export
            alert('Excel export functionality will be implemented here');
        }
    }

    // Close filter dropdown
    function closeFilter() {
        // Bootstrap doesn't have a direct method, but we can trigger the dropdown to close
        $('.dropdown-menu.show').removeClass('show');
    }

    // Reset filter
    function resetFilter() {
        $('#status').val('');
        $('#filterForm').submit();
    }

    // Confirm delete
    function confirmDelete(guardianId, guardianName) {
        $('#deleteGuardianId').val(guardianId);
        $('#deleteGuardianName').text('Guardian: ' + guardianName);
        $('#deleteModal').modal('show');
    }

    // Bulk action
    function bulkAction(action) {
        const selectedIds = [];
        $('.row-checkbox:checked').each(function() {
            selectedIds.push($(this).val());
        });
        
        if (selectedIds.length === 0) {
            alert('Please select at least one guardian');
            return;
        }
        
        if (action === 'delete') {
            if (confirm('Are you sure you want to deactivate ' + selectedIds.length + ' selected guardians?')) {
                // Implement bulk delete
                console.log('Bulk delete:', selectedIds);
            }
        } else if (action === 'export') {
            // Implement bulk export
            console.log('Bulk export:', selectedIds);
        }
    }
</script>

</body>
</html>
