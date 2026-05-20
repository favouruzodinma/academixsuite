<?php
/**
 * Class Details Page
 * Displays detailed information about a class including sections, subjects, and timetable.
 * 
 * @package AcademixSuite
 * @version 1.0
 */

// Enable error reporting for debugging
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../../logs/school_class_details.log');

error_log("=== CLASS DETAILS PAGE START ===");
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
$currentPage = $GLOBALS['CURRENT_PAGE'] ?? 'class-details.php';
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

// Optional: restrict to admin/teacher? We'll allow both but you can adjust.
if (!in_array($userType, ['admin', 'teacher'])) {
    error_log("ERROR: User does not have sufficient privileges");
    header('HTTP/1.1 403 Forbidden');
    echo "Access denied.";
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
        $notifications = $notificationManager->getNotifications(5, false);
    } catch (Exception $e) {
        error_log("ERROR initializing NotificationManager: " . $e->getMessage());
    }
}

/**
 * Get class ID from URL
 */
$classId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$classId) {
    $_SESSION['toast_error'] = "No class selected.";
    header("Location: class-list.php");
    exit;
}

/**
 * Fetch class details
 */
$class = null;
$classTeacher = null;
$sections = [];
$subjects = [];
$timetable = [];

if ($schoolDb) {
    try {
        // Main class info with teacher name
        $classStmt = $schoolDb->prepare("
            SELECT c.*, ay.name as academic_year_name, u.name as class_teacher_name
            FROM classes c
            LEFT JOIN academic_years ay ON c.academic_year_id = ay.id
            LEFT JOIN users u ON c.class_teacher_id = u.id
            WHERE c.id = ? AND c.school_id = ?
        ");
        $classStmt->execute([$classId, $school['id']]);
        $class = $classStmt->fetch(PDO::FETCH_ASSOC);
        if (!$class) {
            throw new Exception("Class not found");
        }

        // Sections with student counts
        $sectionStmt = $schoolDb->prepare("
            SELECT s.*, 
                   COUNT(DISTINCT st.id) as student_count,
                   COUNT(DISTINCT CASE WHEN st.status = 'active' THEN st.id END) as active_students,
                   u.name as class_teacher_name
            FROM sections s
            LEFT JOIN students st ON s.id = st.section_id
            LEFT JOIN users u ON s.class_teacher_id = u.id
            WHERE s.class_id = ? AND s.school_id = ?
            GROUP BY s.id
            ORDER BY s.name
        ");
        $sectionStmt->execute([$classId, $school['id']]);
        $sections = $sectionStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Subjects offered by this class (from class_subjects)
        $subjectStmt = $schoolDb->prepare("
            SELECT s.*, cs.id as class_subject_id,
                   u.name as teacher_name
            FROM subjects s
            INNER JOIN class_subjects cs ON s.id = cs.subject_id
            LEFT JOIN users u ON cs.teacher_id = u.id
            WHERE cs.class_id = ?
            ORDER BY s.name
        ");
        $subjectStmt->execute([$classId]);
        $subjects = $subjectStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Timetable for this class
        $timetableStmt = $schoolDb->prepare("
            SELECT t.*, 
                   s.name as subject_name, s.code as subject_code,
                   u.name as teacher_name
            FROM timetables t
            LEFT JOIN subjects s ON t.subject_id = s.id
            LEFT JOIN users u ON t.teacher_id = u.id
            WHERE t.class_id = ? AND t.school_id = ?
            ORDER BY FIELD(t.day, 'monday','tuesday','wednesday','thursday','friday','saturday'), t.period_number
        ");
        $timetableStmt->execute([$classId, $school['id']]);
        $timetable = $timetableStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Total students count across all sections
        $totalStudents = array_sum(array_column($sections, 'student_count'));

    } catch (Exception $e) {
        error_log("Error fetching class details: " . $e->getMessage());
        $_SESSION['toast_error'] = "Error loading class details.";
        header("Location: class-list.php");
        exit;
    }
}

// Collect toast messages
$toastSuccess = $_SESSION['toast_success'] ?? '';
$toastError = $_SESSION['toast_error'] ?? '';
unset($_SESSION['toast_success'], $_SESSION['toast_error']);

// Generate CSRF token if needed (for any future actions)
if (!function_exists('generateCsrfToken')) {
    function generateCsrfToken() {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}
$csrfToken = generateCsrfToken();

// Helper function for day display
function formatDay($day) {
    return ucfirst($day);
}

error_log("=== CLASS DETAILS PAGE END ===");
?>

<!DOCTYPE html>
<html lang="en" data-theme="light">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="description" content="Class Details - View class information, sections, subjects and timetable">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($school['name']); ?> | <?php echo defined('APP_NAME') ? APP_NAME : 'School Management'; ?> - Class Details</title>
    
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
        
        .class-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        
        .stat-card {
            background: white;
            border: 1px solid #e9ecef;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            transition: all 0.3s;
            margin-bottom: 20px;
        }
        .stat-card:hover {
            box-shadow: 0 8px 20px rgba(0,0,0,0.06);
            border-color: #25A194;
        }
        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: #e6f7f5;
            color: #25A194;
            font-size: 1.8rem;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
        }
        
        .section-card {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
            border-left: 4px solid #25A194;
        }
        
        .timetable-table th {
            background: #25A194;
            color: white;
            text-align: center;
            vertical-align: middle;
        }
        .timetable-table td {
            vertical-align: middle;
            text-align: center;
        }
        .timetable-table .break-row td {
            background: #f8d7da;
            color: #721c24;
            font-style: italic;
        }
        
        .subject-badge {
            display: inline-block;
            background: #e2f0f9;
            color: #0c5460;
            padding: 5px 12px;
            border-radius: 20px;
            margin: 0 5px 5px 0;
            font-size: 13px;
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

    <!-- Theme Customization Structure (omitted for brevity, you can copy from other pages) -->
    
    

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
                            <input type="text" class="bg-transparent" name="search" placeholder="Search...">
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
                    <h1 class="fw-semibold mb-4 h6 text-primary-light">Class Details</h1>
                    <div class="">
                        <a href="index.php" class="text-secondary-light hover-text-primary hover-underline">Dashboard</a>
                        <a href="class-list.php" class="text-secondary-light hover-text-primary hover-underline"> / Classes</a>
                        <span class="text-secondary-light"> / <?php echo htmlspecialchars($class['name']); ?></span>
                    </div>
                </div>
                <div class="d-flex gap-2">
                    <a href="edit-class.php?id=<?php echo $classId; ?>" class="btn btn-outline-primary">
                        <i class="ri-edit-line"></i> Edit Class
                    </a>
                    <a href="assign-subjects.php?class_id=<?php echo $classId; ?>" class="btn btn-outline-success">
                        <i class="ri-book-open-line"></i> Assign Subjects
                    </a>
                </div>
            </div>

            <!-- Class Header -->
            <div class="class-header">
                <div class="row align-items-center">
                    <div class="col-md-8">
                        <h2 class="text-white mb-2"><?php echo htmlspecialchars($class['name']); ?> <span class="badge bg-light text-dark"><?php echo htmlspecialchars($class['code']); ?></span></h2>
                        <p class="text-white-50 mb-0">
                            <i class="ri-calendar-line me-1"></i> <?php echo htmlspecialchars($class['academic_year_name'] ?? 'N/A'); ?>
                            <?php if (!empty($class['grade_level'])): ?>
                            | <i class="ri-bar-chart-line me-1"></i> Grade: <?php echo htmlspecialchars($class['grade_level']); ?>
                            <?php endif; ?>
                            <?php if (!empty($class['room_number'])): ?>
                            | <i class="ri-door-line me-1"></i> Room: <?php echo htmlspecialchars($class['room_number']); ?>
                            <?php endif; ?>
                            | <i class="ri-group-line me-1"></i> Capacity: <?php echo $class['capacity'] ?? 40; ?>
                        </p>
                    </div>
                    <div class="col-md-4 text-md-end mt-3 mt-md-0">
                        <span class="badge bg-white text-dark px-3 py-2">
                            <i class="ri-user-star-line me-1"></i> Class Teacher: <?php echo htmlspecialchars($class['class_teacher_name'] ?? 'Not Assigned'); ?>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Quick Stats -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="ri-grid-line"></i>
                        </div>
                        <h4 class="mb-1"><?php echo count($sections); ?></h4>
                        <p class="text-muted mb-0">Sections</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="ri-group-line"></i>
                        </div>
                        <h4 class="mb-1"><?php echo $totalStudents ?? 0; ?></h4>
                        <p class="text-muted mb-0">Total Students</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="ri-book-open-line"></i>
                        </div>
                        <h4 class="mb-1"><?php echo count($subjects); ?></h4>
                        <p class="text-muted mb-0">Subjects</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="ri-time-line"></i>
                        </div>
                        <h4 class="mb-1"><?php echo count($timetable); ?></h4>
                        <p class="text-muted mb-0">Periods</p>
                    </div>
                </div>
            </div>

            <!-- Sections & Subjects -->
            <div class="row">
                <div class="col-lg-6">
                    <div class="card mb-4">
                        <div class="card-header bg-white py-3">
                            <h5 class="mb-0"><i class="ri-grid-line me-2 text-primary"></i>Sections</h5>
                        </div>
                        <div class="card-body p-3">
                            <?php if (empty($sections)): ?>
                                <p class="text-muted text-center py-3">No sections available for this class.</p>
                            <?php else: ?>
                                <?php foreach ($sections as $section): ?>
                                <div class="section-card d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="mb-1">Section <?php echo htmlspecialchars($section['name']); ?> <span class="badge bg-info"><?php echo htmlspecialchars($section['code']); ?></span></h6>
                                        <p class="mb-0 small text-muted">
                                            <i class="ri-group-line me-1"></i> <?php echo $section['student_count'] ?? 0; ?> Students 
                                            (<?php echo $section['active_students'] ?? 0; ?> active)
                                            <?php if (!empty($section['class_teacher_name'])): ?>
                                            | <i class="ri-user-star-line me-1"></i> Teacher: <?php echo htmlspecialchars($section['class_teacher_name']); ?>
                                            <?php endif; ?>
                                            <?php if (!empty($section['room_number'])): ?>
                                            | <i class="ri-door-line me-1"></i> Room <?php echo htmlspecialchars($section['room_number']); ?>
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                    <a href="section-list.php?class_id=<?php echo $classId; ?>" class="btn btn-sm btn-outline-primary">
                                        <i class="ri-arrow-right-line"></i>
                                    </a>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="card mb-4">
                        <div class="card-header bg-white py-3">
                            <h5 class="mb-0"><i class="ri-book-open-line me-2 text-success"></i>Subjects Offered</h5>
                        </div>
                        <div class="card-body p-3">
                            <?php if (empty($subjects)): ?>
                                <p class="text-muted text-center py-3">No subjects assigned to this class yet.</p>
                            <?php else: ?>
                                <div class="d-flex flex-wrap">
                                    <?php foreach ($subjects as $subject): ?>
                                    <span class="subject-badge">
                                        <?php echo htmlspecialchars($subject['name']); ?> 
                                        <small class="text-muted">(<?php echo htmlspecialchars($subject['code']); ?>)</small>
                                        <?php if (!empty($subject['teacher_name'])): ?>
                                        <br><small class="text-primary"><?php echo htmlspecialchars($subject['teacher_name']); ?></small>
                                        <?php endif; ?>
                                    </span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Timetable -->
            <div class="card">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0"><i class="ri-calendar-todo-line me-2 text-warning"></i>Class Timetable</h5>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($timetable)): ?>
                        <p class="text-muted text-center py-4">No timetable entries for this class.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-bordered timetable-table mb-0">
                                <thead>
                                    <tr>
                                        <th>Day</th>
                                        <th>Period</th>
                                        <th>Time</th>
                                        <th>Subject</th>
                                        <th>Teacher</th>
                                        <th>Room</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $currentDay = '';
                                    foreach ($timetable as $entry): 
                                        if ($entry['is_break']) {
                                            // Break row
                                    ?>
                                    <tr class="break-row">
                                        <td colspan="6" class="text-center">
                                            <i class="ri-cup-line me-2"></i> Break / Free Period
                                        </td>
                                    </tr>
                                    <?php 
                                        } else {
                                            if ($currentDay != $entry['day']) {
                                                $currentDay = $entry['day'];
                                            }
                                    ?>
                                    <tr>
                                        <td><strong><?php echo formatDay($entry['day']); ?></strong></td>
                                        <td><?php echo $entry['period_number']; ?></td>
                                        <td><?php echo date('h:i A', strtotime($entry['start_time'])); ?> - <?php echo date('h:i A', strtotime($entry['end_time'])); ?></td>
                                        <td><?php echo htmlspecialchars($entry['subject_name'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($entry['teacher_name'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($entry['room_number'] ?? 'N/A'); ?></td>
                                    </tr>
                                    <?php 
                                        }
                                    endforeach; 
                                    ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
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
        });
    </script>
</body>
</html>