<?php
/**
 * School Teacher Details Page
 * Displays comprehensive teacher information from users and teachers tables
 * 
 * @package AcademixSuite
 * @version 2.0
 */

// Enable error reporting
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../../logs/school_teacher_details.log');

error_log("=== TEACHER DETAILS PAGE START ===");
error_log("Request URI: " . ($_SERVER['REQUEST_URI'] ?? 'N/A'));
error_log("Script: " . __FILE__);

// Define constants
defined('APP_NAME') or define('APP_NAME', 'AcademixSuite');
defined('IS_LOCAL') or define('IS_LOCAL', true);

// Start session
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

// Get school slug from GLOBALS
$schoolSlug = $GLOBALS['SCHOOL_SLUG'] ?? '';
$userType = $GLOBALS['USER_TYPE'] ?? 'admin';
$schoolData = $GLOBALS['SCHOOL_DATA'] ?? [];
$baseUrl = $GLOBALS['BASE_URL'] ?? '';

if (empty($schoolSlug)) {
    error_log("ERROR: Empty school slug from router");
    header('HTTP/1.1 400 Bad Request');
    echo json_encode(['error' => 'School identifier missing']);
    exit;
}

// Get school info
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
    if (($_SESSION['school_auth']['school_slug'] ?? '') === $schoolSlug) {
        $isAuthenticated = true;
    }
}

if (!$isAuthenticated) {
    error_log("User not authenticated");
    header('Location: ../../login.php?school_slug=' . urlencode($schoolSlug));
    exit;
}

// Get user info
$schoolAuth = $_SESSION['school_auth'];
$userId = (int)($schoolAuth['user_id'] ?? 0);
$userType = $schoolAuth['user_type'] ?? '';

// Verify admin access
if (!in_array($userType, ['admin', 'teacher'])) {
    error_log("ERROR: User does not have permission to view teacher details");
    header('HTTP/1.1 403 Forbidden');
    die("Access denied. Insufficient privileges.");
}

// Load configuration
try {
    $autoloadPath = __DIR__ . '/../../../includes/autoload.php';
    if (!file_exists($autoloadPath)) {
        throw new Exception("Autoload file not found");
    }
    require_once $autoloadPath;
    
    if (!class_exists('Database')) {
        throw new Exception("Database class not found");
    }
    
    // Include TeacherManager
    $teacherManagerPath = __DIR__ . '/../../../includes/TeacherManager.php';
    if (!file_exists($teacherManagerPath)) {
        throw new Exception("TeacherManager file not found");
    }
    require_once $teacherManagerPath;
    
} catch (Exception $e) {
    error_log("Error loading files: " . $e->getMessage());
    http_response_code(500);
    die("Configuration loading failed.");
}

// Connect to school database
$schoolDb = null;
$teacherManager = null;
try {
    if (!empty($school['database_name'])) {
        $schoolDb = Database::getSchoolConnection($school['database_name']);
        error_log("School database connection successful");
        
        // Initialize TeacherManager
        $teacherManager = new TeacherManager($schoolDb, $school['id'], $userId, $userType, $school);
        error_log("TeacherManager initialized successfully");
    }
} catch (Exception $e) {
    error_log("ERROR connecting to school database: " . $e->getMessage());
    $schoolDb = null;
}

// Get teacher ID from URL
$teacherId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($teacherId === 0) {
    error_log("ERROR: No teacher ID provided in URL");
    header('Location: teacher-list.php?error=no_teacher_id');
    exit;
}

// Initialize variables
$teacher = null;
$assignedClasses = [];
$assignedSubjects = [];
$settings = [];
$adminUser = ['name' => 'Admin User', 'role_name' => 'Administrator'];
$toastSuccess = $_SESSION['toast_success'] ?? '';
$toastError = $_SESSION['toast_error'] ?? '';

// Clear session toasts
unset($_SESSION['toast_success'], $_SESSION['toast_error']);

// Fetch teacher data directly from database (bypassing TeacherManager's problematic getTeacher method)
if ($schoolDb) {
    try {
        // Get school settings
        $settingsStmt = $schoolDb->prepare("SELECT `key`, `value` FROM settings WHERE school_id = ?");
        if ($settingsStmt) {
            $settingsStmt->execute([$school['id']]);
            while ($row = $settingsStmt->fetch(PDO::FETCH_ASSOC)) {
                $settings[$row['key']] = $row['value'];
            }
        }

        // Get logged in user details
        $userStmt = $schoolDb->prepare("
            SELECT u.*, r.name as role_name 
            FROM users u 
            LEFT JOIN user_roles ur ON u.id = ur.user_id
            LEFT JOIN roles r ON ur.role_id = r.id
            WHERE u.id = ? AND u.school_id = ?
            LIMIT 1
        ");
        if ($userStmt) {
            $userStmt->execute([$userId, $school['id']]);
            $adminUserData = $userStmt->fetch(PDO::FETCH_ASSOC);
            if ($adminUserData) {
                $adminUser = $adminUserData;
            }
        }

        // Get teacher details - JOIN users and teachers tables
        $teacherStmt = $schoolDb->prepare("
            SELECT 
                t.*,
                u.id as user_id,
                u.name,
                u.email,
                u.phone,
                u.gender,
                u.date_of_birth,
                u.address as current_address,
                u.profile_photo,
                u.is_active as user_active,
                u.created_at as user_created_at,
                u.updated_at as user_updated_at
            FROM teachers t
            JOIN users u ON t.user_id = u.id AND u.school_id = t.school_id
            WHERE t.id = ? AND t.school_id = ?
        ");
        $teacherStmt->execute([$teacherId, $school['id']]);
        $teacher = $teacherStmt->fetch(PDO::FETCH_ASSOC);

        if (!$teacher) {
            error_log("ERROR: Teacher not found with ID: " . $teacherId);
            header('Location: teacher-list.php?error=teacher_not_found');
            exit;
        }

        // Get assigned subjects
        $subjectStmt = $schoolDb->prepare("
            SELECT s.*, c.name as class_name, c.id as class_id
            FROM subjects s
            JOIN class_subjects cs ON s.id = cs.subject_id
            LEFT JOIN classes c ON cs.class_id = c.id
            WHERE cs.teacher_id = ? AND s.school_id = ?
            GROUP BY s.id
            ORDER BY s.name
        ");
        $subjectStmt->execute([$teacher['user_id'], $school['id']]);
        $assignedSubjects = $subjectStmt->fetchAll(PDO::FETCH_ASSOC);

        // Get assigned classes (where teacher is class teacher)
        $classStmt = $schoolDb->prepare("
            SELECT c.*, ay.name as academic_year_name
            FROM classes c
            LEFT JOIN academic_years ay ON c.academic_year_id = ay.id
            WHERE c.class_teacher_id = ? AND c.school_id = ?
            ORDER BY c.name
        ");
        $classStmt->execute([$teacher['user_id'], $school['id']]);
        $assignedClasses = $classStmt->fetchAll(PDO::FETCH_ASSOC);

        error_log("Teacher details loaded for ID: " . $teacherId);

    } catch (Exception $e) {
        error_log("Error fetching teacher details: " . $e->getMessage());
        $toastError = "Error loading teacher details. Please try again.";
    }
}

// Handle suspend/activate teacher
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $teacherManager) {
    
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        $toastError = "Invalid security token. Please try again.";
    } else {
        try {
            if ($_POST['action'] === 'suspend' && isset($_POST['suspend_teacher'])) {
                $result = $teacherManager->suspendTeacher($teacherId, $_POST['reason'] ?? '');
                if ($result[0]) {
                    $toastSuccess = $result[1];
                    // Refresh teacher data
                    if ($schoolDb) {
                        $teacherStmt->execute([$teacherId, $school['id']]);
                        $teacher = $teacherStmt->fetch(PDO::FETCH_ASSOC);
                    }
                } else {
                    $toastError = $result[1];
                }
            } elseif ($_POST['action'] === 'activate' && isset($_POST['activate_teacher'])) {
                $result = $teacherManager->activateTeacher($teacherId);
                if ($result[0]) {
                    $toastSuccess = $result[1];
                    // Refresh teacher data
                    if ($schoolDb) {
                        $teacherStmt->execute([$teacherId, $school['id']]);
                        $teacher = $teacherStmt->fetch(PDO::FETCH_ASSOC);
                    }
                } else {
                    $toastError = $result[1];
                }
            }
        } catch (Exception $e) {
            error_log("Error in suspend/activate: " . $e->getMessage());
            $toastError = "Error: " . $e->getMessage();
        }
    }
}

// Helper function for CSRF token
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

// Generate subject names string
$subjectNames = [];
foreach ($assignedSubjects as $subject) {
    $subjectNames[] = $subject['name'];
}
$subjectNamesString = implode(', ', $subjectNames);

// Generate class names string
$classNames = [];
foreach ($assignedClasses as $class) {
    $classNames[] = $class['name'] . ($class['academic_year_name'] ? ' (' . $class['academic_year_name'] . ')' : '');
}
$classNamesString = implode(', ', $classNames);

// Generate CSRF token
$csrfToken = generateCsrfToken();

error_log("=== TEACHER DETAILS PAGE END ===");
?>

<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="description" content="Teacher Details - School Management System">
    <meta name="keywords" content="Teacher Details, Teacher Information, School Management">
    <meta name="robots" content="INDEX,FOLLOW">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teacher Details - <?php echo htmlspecialchars($school['name'] ?? 'School'); ?></title>
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
        .teacher-avatar {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid #fff;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        .avatar-placeholder {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: linear-gradient(135deg, #25A194, #1a7a6f);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 48px;
            margin: 0 auto;
        }
        .info-label {
            color: #6c757d;
            font-size: 14px;
            font-weight: 500;
            min-width: 120px;
        }
        .info-value {
            color: #2c3e50;
            font-size: 14px;
            font-weight: 400;
        }
        .badge-subject {
            background: #e9f2ff;
            color: #25A194;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            display: inline-block;
            margin: 2px;
        }
        .badge-class {
            background: #fff3e0;
            color: #f39c12;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            display: inline-block;
            margin: 2px;
        }
        .status-badge {
            padding: 6px 16px;
            border-radius: 30px;
            font-weight: 500;
            font-size: 13px;
        }
        .status-active {
            background: #d4edda;
            color: #155724;
        }
        .status-inactive {
            background: #f8d7da;
            color: #721c24;
        }
        .info-card {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            height: 100%;
        }
        .info-title {
            font-size: 14px;
            color: #6c757d;
            margin-bottom: 4px;
        }
        .info-content {
            font-size: 16px;
            font-weight: 500;
            color: #2c3e50;
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

<!-- Theme Customization Structure -->



<div class="overlay bg-black bg-opacity-50 w-100 h-100 position-fixed z-9 visibility-hidden opacity-0 duration-300"></div>

<!-- Sidebar -->
<?php include_once('includes/sidebar.php'); ?>

<main class="dashboard-main">
    <div class="navbar-header shadow-1">
        <div class="row align-items-center justify-content-between">
            <div class="col-auto">
                <div class="d-flex flex-wrap align-items-center gap-4">
                    <button type="button" class="sidebar-mobile-toggle" aria-label="Sidebar Mobile Toggler Button">
                        <iconify-icon icon="heroicons:bars-3-solid" class="icon"></iconify-icon>
                    </button>
                    <form class="navbar-search" method="GET" action="teacher-list.php">
                        <input type="text" class="bg-transparent" name="search" placeholder="Search teachers...">
                        <iconify-icon icon="ion:search-outline" class="icon"></iconify-icon>
                    </form>
                </div>
            </div>
            <div class="col-auto">
                <div class="d-flex flex-wrap align-items-center gap-3">
                    <button type="button" data-theme-toggle class="w-40-px h-40-px bg-neutral-200 rounded-circle d-flex justify-content-center align-items-center" aria-label="Dark & Light Mode Button"></button>
                    <div class="dropdown">
                        <button class="has-indicator w-40-px h-40-px bg-neutral-200 rounded-circle d-flex justify-content-center align-items-center position-relative" type="button" data-bs-toggle="dropdown" aria-label="Notification Button">
                            <iconify-icon icon="iconoir:bell" class="text-primary-light text-xl"></iconify-icon>
                        </button>
                        <div class="dropdown-menu to-top dropdown-menu-lg p-0">
                            <div class="text-center py-20">
                                <p class="text-secondary-light">No new notifications</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="dashboard-main-body">
        <div class="breadcrumb d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
            <div class="">
                <h1 class="fw-semibold mb-4 h6 text-primary-light">Teacher Details</h1>
                <div class="">
                    <a href="index.php" class="text-secondary-light hover-text-primary hover-underline">Dashboard</a>
                    <a href="teacher-list.php" class="text-secondary-light hover-text-primary hover-underline"> / Teacher</a>
                    <span class="text-secondary-light">/ Teacher Details</span>
                </div>
            </div>
            <button type="button" class="my-sidebar-btn btn btn-primary-600 d-flex align-items-center gap-6 bg-base text-primary-light bg-hover-primary-600">
                <span class="d-flex text-md">
                    <i class="ri-lock-2-line"></i>
                </span>
                Login Details
            </button>
        </div>

        <?php if ($teacher): ?>
        <div class="mt-24">
            <!-- Teacher Profile Card -->
            <div class="card h-100">
                <div class="card-body p-24">
                    <div class="d-flex gap-32 flex-md-row flex-column">
                        <div class="max-w-300-px w-100 text-center">
                            <figure class="mb-24 mx-auto">
                                <?php 
                                $avatar = $teacher['profile_photo'] ?? '';
                                if (!empty($avatar)):
                                ?>
                                <img src="<?php echo htmlspecialchars($avatar); ?>" alt="<?php echo htmlspecialchars($teacher['name']); ?>" class="teacher-avatar">
                                <?php else: 
                                    $initials = '';
                                    $nameParts = explode(' ', $teacher['name'] ?? 'Teacher');
                                    foreach ($nameParts as $part) {
                                        if (!empty($part)) {
                                            $initials .= strtoupper(substr($part, 0, 1));
                                        }
                                    }
                                ?>
                                <div class="avatar-placeholder">
                                    <?php echo $initials ?: 'T'; ?>
                                </div>
                                <?php endif; ?>
                            </figure>
                            <h2 class="h6 text-primary-light mb-8 fw-semibold"><?php echo htmlspecialchars($teacher['name'] ?? 'N/A'); ?></h2>
                            <p class="mb-1">ID: <span class="text-primary-600 fw-semibold"><?php echo htmlspecialchars($teacher['employee_id'] ?? 'N/A'); ?></span></p>
                            <p class="mb-0">Subject: <span class="text-primary-light fw-semibold"><?php echo htmlspecialchars($subjectNamesString ?: 'N/A'); ?></span></p>
                            
                            <!-- Subject Badges -->
                            <?php if (!empty($assignedSubjects)): ?>
                            <div class="mt-16">
                                <?php foreach ($assignedSubjects as $subject): ?>
                                <span class="badge-subject"><?php echo htmlspecialchars($subject['name']); ?></span>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                            
                            <div class="mt-32 d-flex gap-16 w-100">
                                <?php if (($teacher['is_active'] ?? 1) == 1): ?>
                                <button type="button" class="btn border fw-medium border-danger-600 bg-hover-danger-200 text-danger-600 text-md d-flex justify-content-center align-items-center gap-8 flex-grow-1 px-12 py-8 radius-8" data-bs-toggle="modal" data-bs-target="#suspendModal">
                                    <span class="d-flex text-lg">
                                        <i class="ri-delete-bin-2-line"></i>
                                    </span>
                                    Suspend
                                </button>
                                <?php else: ?>
                                <form method="POST" style="flex-grow: 1;" id="activateForm">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                    <input type="hidden" name="action" value="activate">
                                    <button type="submit" name="activate_teacher" class="btn border fw-medium border-success-600 bg-hover-success-200 text-success-600 text-md d-flex justify-content-center align-items-center gap-8 w-100 px-12 py-8 radius-8">
                                        <span class="d-flex text-lg">
                                            <i class="ri-check-line"></i>
                                        </span>
                                        Activate
                                    </button>
                                </form>
                                <?php endif; ?>
                                <a href="edit-teacher.php?id=<?php echo $teacherId; ?>" class="btn btn-primary-600 border fw-medium border-primary-600 text-md d-flex justify-content-center align-items-center gap-8 flex-grow-1 px-12 py-8 radius-8">
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
                                <h3 class="h6 text-primary-light text-lg mb-0 fw-semibold">Personal Information</h3>
                                <span class="status-badge <?php echo ($teacher['is_active'] ?? 1) == 1 ? 'status-active' : 'status-inactive'; ?>">
                                    <?php echo ($teacher['is_active'] ?? 1) == 1 ? 'Active' : 'Inactive'; ?>
                                </span>
                            </div>
                            
                            <div class="row mt-16 g-3">
                                <div class="col-md-6">
                                    <div class="d-flex">
                                        <span class="info-label">Employee ID</span>
                                        <span class="info-value">: <?php echo htmlspecialchars($teacher['employee_id'] ?? 'N/A'); ?></span>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="d-flex">
                                        <span class="info-label">Full Name</span>
                                        <span class="info-value">: <?php echo htmlspecialchars($teacher['name'] ?? 'N/A'); ?></span>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="d-flex">
                                        <span class="info-label">Email</span>
                                        <span class="info-value">: <?php echo htmlspecialchars($teacher['email'] ?? 'N/A'); ?></span>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="d-flex">
                                        <span class="info-label">Phone</span>
                                        <span class="info-value">: <?php echo htmlspecialchars($teacher['phone'] ?? 'N/A'); ?></span>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="d-flex">
                                        <span class="info-label">Gender</span>
                                        <span class="info-value">: <?php echo ucfirst($teacher['gender'] ?? 'N/A'); ?></span>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="d-flex">
                                        <span class="info-label">Date of Birth</span>
                                        <span class="info-value">: <?php echo !empty($teacher['date_of_birth']) ? date('d M Y', strtotime($teacher['date_of_birth'])) : 'N/A'; ?></span>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="d-flex">
                                        <span class="info-label">Qualification</span>
                                        <span class="info-value">: <?php echo htmlspecialchars($teacher['qualification'] ?? 'N/A'); ?></span>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="d-flex">
                                        <span class="info-label">Specialization</span>
                                        <span class="info-value">: <?php echo htmlspecialchars($teacher['specialization'] ?? 'N/A'); ?></span>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="d-flex">
                                        <span class="info-label">Experience</span>
                                        <span class="info-value">: <?php echo htmlspecialchars($teacher['experience_years'] ?? '0'); ?> Years</span>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="d-flex">
                                        <span class="info-label">Joining Date</span>
                                        <span class="info-value">: <?php echo !empty($teacher['joining_date']) ? date('d M Y', strtotime($teacher['joining_date'])) : 'N/A'; ?></span>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Class Teacher Badges -->
                            <?php if (!empty($assignedClasses)): ?>
                            <div class="mt-20 pt-20 border-top">
                                <h6 class="fw-semibold mb-12">Assigned as Class Teacher</h6>
                                <div>
                                    <?php foreach ($assignedClasses as $class): ?>
                                    <span class="badge-class">
                                        <?php echo htmlspecialchars($class['name']); ?> 
                                        (<?php echo htmlspecialchars($class['code']); ?>)
                                        <?php if (!empty($class['academic_year_name'])): ?>
                                        - <?php echo htmlspecialchars($class['academic_year_name']); ?>
                                        <?php endif; ?>
                                    </span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <!-- Address -->
                            <?php if (!empty($teacher['current_address'])): ?>
                            <div class="mt-20 pt-20 border-top">
                                <h6 class="fw-semibold mb-2">Current Address</h6>
                                <p class="text-secondary-light"><?php echo nl2br(htmlspecialchars($teacher['current_address'])); ?></p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Bank Details Section -->
            <?php if (!empty($teacher['bank_name']) || !empty($teacher['bank_account']) || !empty($teacher['ifsc_code'])): ?>
            <div class="row mt-24">
                <div class="col-12">
                    <div class="shadow-1 radius-12 bg-base overflow-hidden">
                        <div class="card-header border-bottom bg-base py-16 px-24">
                            <h6 class="text-lg fw-semibold mb-0">Bank Details</h6>
                        </div>
                        <div class="card-body p-24">
                            <div class="row g-4">
                                <?php if (!empty($teacher['bank_name'])): ?>
                                <div class="col-md-4">
                                    <div class="info-card">
                                        <div class="info-title">Bank Name</div>
                                        <div class="info-content"><?php echo htmlspecialchars($teacher['bank_name']); ?></div>
                                    </div>
                                </div>
                                <?php endif; ?>
                                <?php if (!empty($teacher['bank_account'])): ?>
                                <div class="col-md-4">
                                    <div class="info-card">
                                        <div class="info-title">Account Number</div>
                                        <div class="info-content"><?php echo htmlspecialchars($teacher['bank_account']); ?></div>
                                    </div>
                                </div>
                                <?php endif; ?>
                                <?php if (!empty($teacher['ifsc_code'])): ?>
                                <div class="col-md-4">
                                    <div class="info-card">
                                        <div class="info-title">IFSC Code</div>
                                        <div class="info-content"><?php echo htmlspecialchars($teacher['ifsc_code']); ?></div>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
        </div>
        <?php else: ?>
        <div class="alert alert-danger">
            Teacher not found. <a href="teacher-list.php" class="alert-link">Return to teacher list</a>.
        </div>
        <?php endif; ?>
    </div>

    <footer class="d-footer">
        <div class="">
            <p class="mb-0 text-center"> &copy; <span class="current-year"></span> <?php echo htmlspecialchars($school['name'] ?? 'School'); ?> | Made With ❤️ by AcademixSuite.</p>
        </div>
    </footer>
</main>

<!-- Login Details Sidebar -->
<div class="my-sidebar bg-white position-fixed end-0 top-0 h-100vh overflow-y-auto z-99 max-w-700-px w-100 translate-x-full duration-300 active-translate-0">
    <div class="px-20 py-12 border-bottom d-flex align-items-center justify-content-between gap-20">
        <h5 class="text-lg mb-0">Login Details</h5>
        <button type="button" class="close-my-sidebar text-danger-600 text-lg d-flex">
            <i class="ri-close-large-line"></i>
        </button>
    </div>
    <div class="p-20">
        <div class="d-flex align-items-center gap-20 mb-20">
            <?php 
            $avatar = $teacher['profile_photo'] ?? '';
            if (!empty($avatar)):
            ?>
            <img src="<?php echo htmlspecialchars($avatar); ?>" alt="<?php echo htmlspecialchars($teacher['name']); ?>" class="w-72-px h-72-px rounded-circle object-fit-cover">
            <?php else: 
                $initials = '';
                $nameParts = explode(' ', $teacher['name'] ?? 'Teacher');
                foreach ($nameParts as $part) {
                    if (!empty($part)) {
                        $initials .= strtoupper(substr($part, 0, 1));
                    }
                }
            ?>
            <div class="w-72-px h-72-px rounded-circle bg-primary-600 text-white d-flex align-items-center justify-content-center" style="font-size: 32px;">
                <?php echo $initials ?: 'T'; ?>
            </div>
            <?php endif; ?>
            <div>
                <h2 class="text-xl text-primary-light mb-2"><?php echo htmlspecialchars($teacher['name'] ?? 'N/A'); ?></h2>
                <p class="mb-0">Employee ID: <span class="text-primary-light fw-semibold"><?php echo htmlspecialchars($teacher['employee_id'] ?? 'N/A'); ?></span></p>
            </div>
        </div>
        
        <table class="table bordered-table mb-0">
            <thead>
                <tr>
                    <th class="text-start">User Type</th>
                    <th class="text-start">Email</th>
                    <th class="text-start">Username</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="text-start">Teacher</td>
                    <td class="text-start"><?php echo htmlspecialchars($teacher['email'] ?? 'N/A'); ?></td>
                    <td class="text-start"><?php echo htmlspecialchars(explode('@', $teacher['email'] ?? '')[0]); ?></td>
                </tr>
            </tbody>
        </table>
        
        <div class="mt-20 text-center text-secondary-light">
            <small>Password cannot be displayed for security reasons.</small>
        </div>
    </div>
</div>

<!-- Suspend Modal -->
<div class="modal fade" id="suspendModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm modal-dialog modal-dialog-centered">
        <div class="modal-content radius-16 bg-base">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" value="suspend">
                <div class="modal-body pt-32 px-36 pb-24 text-center">
                    <span class="mb-16 fs-1 line-height-1 text-danger">
                        <i class="ri-delete-bin-2-line"></i>
                    </span>
                    <h6 class="text-lg fw-semibold text-primary-light mb-2">Suspend Teacher?</h6>
                    <p class="text-secondary-light text-sm mb-4">Are you sure you want to suspend <strong><?php echo htmlspecialchars($teacher['name'] ?? 'this teacher'); ?></strong>?</p>
                    
                    <div class="mb-20">
                        <textarea name="reason" class="form-control" rows="2" placeholder="Enter reason for suspension (optional)"></textarea>
                    </div>
                    
                    <div class="d-flex align-items-center justify-content-center gap-3">
                        <button type="button" class="flex-grow-1 border border-danger-600 bg-hover-danger-200 text-danger-600 text-md px-24 py-11 radius-8" data-bs-dismiss="modal">
                            Cancel
                        </button>
                        <button type="submit" name="suspend_teacher" class="flex-grow-1 btn btn-primary-600 border border-primary-600 text-md px-16 py-12 radius-8">
                            Yes, Suspend
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://academixsuite.com/tenant/assets/js/lib/jquery-3.7.1.min.js"></script>
<script src="https://academixsuite.com/tenant/assets/js/lib/bootstrap.bundle.min.js"></script>
<script src="https://academixsuite.com/tenant/assets/js/lib/iconify-icon.min.js"></script>
<script src="https://academixsuite.com/tenant/assets/js/lib/dataTables.min.js"></script>
<script src="https://academixsuite.com/tenant/assets/js/app.js"></script>

<script>
$(document).ready(function() {
    // Initialize Bootstrap toasts
    $('.toast').toast({
        autohide: true,
        delay: 5000
    });
    $('.toast').toast('show');

    // Sidebar toggles
    $('.my-sidebar-btn').on('click', function() {
        $('.my-sidebar').addClass('active');
        $('.overlay').addClass('active');
    });
    
    $('.close-my-sidebar, .overlay').on('click', function() {
        $('.my-sidebar').removeClass('active');
        $('.overlay').removeClass('active');
    });

    // Form validation for activate form
    $('#activateForm').on('submit', function(e) {
        return confirm('Are you sure you want to activate this teacher?');
    });

    // Set current year in footer
    $('.current-year').text(new Date().getFullYear());
});
</script>

</body>
</html>