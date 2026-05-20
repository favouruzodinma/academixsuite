<?php
/**
 * Assign Subjects to Class Page
 * Allows admin to select which subjects are taught in a class
 * 
 * @package AcademixSuite
 * @version 1.0
 */

// Enable error reporting for debugging
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../../logs/school_assign_subjects.log');

error_log("=== ASSIGN SUBJECTS PAGE START ===");
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
$currentPage = $GLOBALS['CURRENT_PAGE'] ?? 'assign-subjects.php';
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

// Verify admin access
if ($userType !== 'admin') {
    error_log("ERROR: User does not have admin privileges");
    header('HTTP/1.1 403 Forbidden');
    echo "Access denied. Admin privileges required.";
    exit;
}

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
 * Get class ID from URL
 */
$classId = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
if (!$classId) {
    $_SESSION['toast_error'] = "No class selected.";
    header("Location: class-list.php");
    exit;
}

/**
 * Fetch class details
 */
$class = null;
if ($schoolDb) {
    try {
        $classStmt = $schoolDb->prepare("
            SELECT c.*, ay.name as academic_year_name
            FROM classes c
            LEFT JOIN academic_years ay ON c.academic_year_id = ay.id
            WHERE c.id = ? AND c.school_id = ?
        ");
        $classStmt->execute([$classId, $school['id']]);
        $class = $classStmt->fetch(PDO::FETCH_ASSOC);
        if (!$class) {
            throw new Exception("Class not found");
        }
    } catch (Exception $e) {
        error_log("Error fetching class: " . $e->getMessage());
        $_SESSION['toast_error'] = "Class not found.";
        header("Location: class-list.php");
        exit;
    }
}

/**
 * Fetch all active subjects
 */
$allSubjects = [];
if ($schoolDb) {
    try {
        $subjectStmt = $schoolDb->prepare("
            SELECT * FROM subjects
            WHERE school_id = ? AND is_active = 1
            ORDER BY name
        ");
        $subjectStmt->execute([$school['id']]);
        $allSubjects = $subjectStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {
        error_log("Error fetching subjects: " . $e->getMessage());
        $_SESSION['toast_error'] = "Error loading subjects.";
    }
}

/**
 * Fetch already assigned subjects for this class
 */
$assignedSubjectIds = [];
if ($schoolDb) {
    try {
        $assignedStmt = $schoolDb->prepare("
            SELECT subject_id FROM class_subjects
            WHERE class_id = ?
        ");
        $assignedStmt->execute([$classId]);
        $assignedSubjectIds = $assignedStmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        error_log("Error fetching assigned subjects: " . $e->getMessage());
    }
}

/**
 * Handle form submission
 */
$message = '';
$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_subjects'])) {
    // Validate CSRF token using global function
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        $error = "Invalid security token.";
    } else {
        try {
            // Verify class belongs to this school
            $classCheck = $schoolDb->prepare("SELECT id FROM classes WHERE id = ? AND school_id = ?");
            $classCheck->execute([$classId, $school['id']]);
            if (!$classCheck->fetch()) {
                throw new Exception('Invalid class selected');
            }

            $schoolDb->beginTransaction();
            
            // Get selected subject IDs from form
            $selectedSubjects = $_POST['subjects'] ?? [];
            if (!is_array($selectedSubjects)) {
                $selectedSubjects = [];
            }
            
            // Delete all existing assignments for this class
            $deleteStmt = $schoolDb->prepare("DELETE FROM class_subjects WHERE class_id = ?");
            $deleteStmt->execute([$classId]);
            
            // Insert new assignments
            if (!empty($selectedSubjects)) {
                $insertStmt = $schoolDb->prepare("
                    INSERT INTO class_subjects (class_id, subject_id, created_at)
                    VALUES (?, ?, NOW())
                ");
                foreach ($selectedSubjects as $subjectId) {
                    $insertStmt->execute([$classId, $subjectId]);
                }
            }
            
            // Create audit log
            $auditStmt = $schoolDb->prepare("
                INSERT INTO audit_logs (
                    school_id, user_id, user_type, action, entity_type,
                    entity_id, new_values, ip_address, user_agent, url, created_at
                ) VALUES (?, ?, ?, 'assign_subjects', 'class', ?, ?, ?, ?, ?, NOW())
            ");
            $auditStmt->execute([
                $school['id'],
                $userId,
                $userType,
                $classId,
                json_encode(['subjects' => $selectedSubjects]),
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null,
                $_SERVER['REQUEST_URI'] ?? null
            ]);
            
            $schoolDb->commit();
            $success = true;
            $message = "Subjects assigned successfully!";
            
            // Refresh assigned IDs
            $assignedSubjectIds = $selectedSubjects;
            
        } catch (Exception $e) {
            if ($schoolDb && $schoolDb->inTransaction()) {
                $schoolDb->rollBack();
            }
            $error = "Error assigning subjects: " . $e->getMessage();
            error_log($error);
        }
    }
}

// Collect toast messages
$toastSuccess = $_SESSION['toast_success'] ?? ($success ? $message : '');
$toastError = $_SESSION['toast_error'] ?? $error;
unset($_SESSION['toast_success'], $_SESSION['toast_error']);

// Generate CSRF token using global function
$csrfToken = generateCsrfToken();

error_log("=== ASSIGN SUBJECTS PAGE END ===");
?>

<!DOCTYPE html>
<html lang="en" data-theme="light">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="description" content="Assign subjects to a class">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($school['name']); ?> | <?php echo defined('APP_NAME') ? APP_NAME : 'School Management'; ?> - Assign Subjects</title>
    
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
        
        .class-info {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        
        .subject-item {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 10px;
            transition: all 0.2s;
        }
        .subject-item:hover {
            background: #e9ecef;
            border-color: #25A194;
        }
        .subject-checkbox {
            width: 20px;
            height: 20px;
            margin-right: 15px;
            cursor: pointer;
            accent-color: #25A194;
        }
        .subject-checkbox:checked {
            /* Additional styling can be done with accent-color */
        }
        .subject-type {
            font-size: 12px;
            padding: 3px 8px;
            border-radius: 12px;
            background: #e2e3e5;
            color: #383d41;
        }
        .subject-type.core {
            background: #cce5ff;
            color: #004085;
        }
        .subject-type.elective {
            background: #d4edda;
            color: #155724;
        }
        .subject-type.extra_curricular {
            background: #fff3cd;
            color: #856404;
        }
        .select-all {
            cursor: pointer;
            color: #25A194;
            font-weight: 500;
        }
        .select-all:hover {
            text-decoration: underline;
        }
        /* Tick sign for selected subjects: we can use a check icon next to label */
        .subject-checkbox:checked + label .subject-name:after {
            content: " ✓";
            color: #28a745;
            font-weight: bold;
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

    <!-- Theme Customization Structure Start (optional, same as other pages) -->
    
    
    
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
                    <h1 class="fw-semibold mb-4 h6 text-primary-light">Assign Subjects</h1>
                    <div class="">
                        <a href="index.php" class="text-secondary-light hover-text-primary hover-underline">Dashboard</a>
                        <a href="class-list.php" class="text-secondary-light hover-text-primary hover-underline"> / Classes</a>
                        <span class="text-secondary-light"> / Assign Subjects</span>
                    </div>
                </div>
                <a href="class-list.php" class="btn btn-outline-primary d-flex align-items-center gap-2">
                    <i class="ri-arrow-left-line"></i> Back to Classes
                </a>
            </div>

            <!-- Class Info -->
            <div class="class-info">
                <div class="row align-items-center">
                    <div class="col-md-8">
                        <h4 class="text-white mb-2"><?php echo htmlspecialchars($class['name']); ?> (<?php echo htmlspecialchars($class['code']); ?>)</h4>
                        <p class="text-white-50 mb-0">
                            <i class="ri-calendar-line me-1"></i> <?php echo htmlspecialchars($class['academic_year_name'] ?? 'N/A'); ?>
                            <?php if (!empty($class['room_number'])): ?>
                            | <i class="ri-door-line me-1"></i> Room <?php echo htmlspecialchars($class['room_number']); ?>
                            <?php endif; ?>
                            | <i class="ri-group-line me-1"></i> Capacity <?php echo $class['capacity'] ?? 40; ?>
                        </p>
                    </div>
                    <div class="col-md-4 text-md-end">
                        <span class="badge bg-white text-dark px-3 py-2">
                            <i class="ri-book-open-line me-1"></i> <?php echo count($assignedSubjectIds); ?> / <?php echo count($allSubjects); ?> Subjects
                        </span>
                    </div>
                </div>
            </div>

            <!-- Assign Form -->
            <div class="card">
                <div class="card-header bg-white py-16 px-24 d-flex align-items-center justify-content-between">
                    <h5 class="mb-0">Select Subjects for this Class</h5>
                    <span class="select-all" onclick="toggleAllSubjects()">
                        <i class="ri-checkbox-line me-1"></i> Select All
                    </span>
                </div>
                <div class="card-body p-24">
                    <form method="POST" id="assignForm">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                        <input type="hidden" name="assign_subjects" value="1">
                        
                        <div class="row">
                            <?php if (empty($allSubjects)): ?>
                                <div class="col-12 text-center py-5">
                                    <i class="ri-book-open-line fs-1 text-secondary-light mb-3 d-block"></i>
                                    <h5>No Subjects Found</h5>
                                    <p class="text-secondary-light">Please add subjects first.</p>
                                    <a href="subject-list.php" class="btn btn-primary-600">Manage Subjects</a>
                                </div>
                            <?php else: ?>
                                <div class="col-12 mb-3">
                                    <input type="text" class="form-control" id="subjectSearch" placeholder="Search subjects...">
                                </div>
                                <?php foreach ($allSubjects as $subject): 
                                    $checked = in_array($subject['id'], $assignedSubjectIds) ? 'checked' : '';
                                    $typeClass = '';
                                    if ($subject['type'] == 'core') $typeClass = 'core';
                                    elseif ($subject['type'] == 'elective') $typeClass = 'elective';
                                    elseif ($subject['type'] == 'extra_curricular') $typeClass = 'extra_curricular';
                                ?>
                                <div class="col-md-6 col-lg-4 subject-item-wrapper" data-name="<?php echo strtolower(htmlspecialchars($subject['name'])); ?>" data-code="<?php echo strtolower(htmlspecialchars($subject['code'])); ?>">
                                    <div class="subject-item d-flex align-items-center">
                                        <input type="checkbox" name="subjects[]" value="<?php echo $subject['id']; ?>" class="subject-checkbox" id="subj_<?php echo $subject['id']; ?>" <?php echo $checked; ?>>
                                        <label for="subj_<?php echo $subject['id']; ?>" class="d-flex flex-wrap align-items-center justify-content-between w-100 mb-0" style="cursor: pointer;">
                                            <span class="fw-semibold">
                                                <span class="subject-name"><?php echo htmlspecialchars($subject['name']); ?></span>
                                                <small class="text-muted">(<?php echo htmlspecialchars($subject['code']); ?>)</small>
                                            </span>
                                            <span class="subject-type <?php echo $typeClass; ?> ms-2"><?php echo ucfirst(str_replace('_', ' ', $subject['type'])); ?></span>
                                        </label>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                                
                                <div class="col-12 mt-4">
                                    <hr>
                                    <div class="d-flex align-items-center justify-content-center gap-3">
                                        <button type="button" class="border border-danger-600 bg-hover-danger-200 text-danger-600 text-md px-50 py-11 radius-8" onclick="window.location.href='class-list.php'">
                                            Cancel
                                        </button>
                                        <button type="submit" class="btn btn-primary-600 border border-primary-600 text-md px-28 py-12 radius-8">
                                            Save Assignments
                                        </button>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <footer class="d-footer">
            <div class="">
                <p class="mb-0 text-center"> &copy; <span class="current-year"></span> <?php echo htmlspecialchars($school['name']); ?> | Made With ❤️ by AcademixSuite.</p>
            </div>
        </footer>
    </main>

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
            // Initialize toasts
            $('.toast').toast({ autohide: true, delay: 5000 }).toast('show');

            // Current year
            $('.current-year').text(new Date().getFullYear());

            // Search functionality
            $('#subjectSearch').on('keyup', function() {
                var searchTerm = $(this).val().toLowerCase();
                $('.subject-item-wrapper').each(function() {
                    var name = $(this).data('name');
                    var code = $(this).data('code');
                    if (name.includes(searchTerm) || code.includes(searchTerm)) {
                        $(this).show();
                    } else {
                        $(this).hide();
                    }
                });
            });
        });

        // Select/Deselect all subjects
        function toggleAllSubjects() {
            var allChecked = $('.subject-checkbox:checked').length === $('.subject-checkbox').length;
            $('.subject-checkbox').prop('checked', !allChecked);
        }
    </script>
</body>
</html>