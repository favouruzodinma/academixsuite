<?php
/**
 * School Teacher List Page
 * Displays all teachers with their assignments and status
 * 
 * @package AcademixSuite
 * @version 2.0
 */

// Enable error reporting
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../../logs/school_teacher_list.log');

error_log("=== TEACHER LIST PAGE START ===");
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
    error_log("ERROR: User does not have permission to view teachers");
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

// Initialize variables
$settings = [];
$teachers = [];
$subjects = [];
$classes = [];
$filters = [];
$stats = [];
$adminUser = ['name' => 'Admin User', 'role_name' => 'Administrator'];
$toastSuccess = $_SESSION['toast_success'] ?? '';
$toastError = $_SESSION['toast_error'] ?? '';
$toastWarning = $_SESSION['toast_warning'] ?? '';
$toastInfo = $_SESSION['toast_info'] ?? '';

// Clear session toasts
unset($_SESSION['toast_success'], $_SESSION['toast_error'], $_SESSION['toast_warning'], $_SESSION['toast_info']);

// Get filter parameters
$filters['search'] = $_GET['search'] ?? '';
$filters['status'] = $_GET['status'] ?? '';
$filters['subject_id'] = $_GET['subject'] ?? '';
$filters['class_id'] = $_GET['class'] ?? '';

// Fetch data from database
if ($schoolDb && $teacherManager) {
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

        // Get all subjects for filter
        $subjectStmt = $schoolDb->prepare("
            SELECT id, name, code FROM subjects 
            WHERE school_id = ? AND is_active = 1
            ORDER BY name
        ");
        if ($subjectStmt) {
            $subjectStmt->execute([$school['id']]);
            $subjects = $subjectStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // Get all classes for filter
        $classStmt = $schoolDb->prepare("
            SELECT id, name, code FROM classes 
            WHERE school_id = ? AND is_active = 1
            ORDER BY name
        ");
        if ($classStmt) {
            $classStmt->execute([$school['id']]);
            $classes = $classStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // Get teachers with filters
        $teachers = $teacherManager->getTeachers($filters);
        
        // Get teacher statistics
        $stats = $teacherManager->getTeacherStats();

        error_log("Fetched " . count($teachers) . " teachers");

    } catch (Exception $e) {
        error_log("Error fetching data: " . $e->getMessage());
        $toastError = "Error loading teacher data. Please refresh.";
    }
}

// Handle AJAX requests for teacher actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    try {
        $response = ['success' => false, 'message' => ''];
        
        switch ($_POST['action']) {
            case 'toggle_status':
                if (isset($_POST['teacher_id'])) {
                    $teacher = $teacherManager->getTeacher($_POST['teacher_id']);
                    if ($teacher) {
                        if ($teacher['is_active'] == 1) {
                            $result = $teacherManager->suspendTeacher($_POST['teacher_id'], $_POST['reason'] ?? '');
                        } else {
                            $result = $teacherManager->activateTeacher($_POST['teacher_id']);
                        }
                        $response = ['success' => $result[0], 'message' => $result[1]];
                    }
                }
                break;
                
            case 'delete':
                if (isset($_POST['teacher_id'])) {
                    $permanent = isset($_POST['permanent']) && $_POST['permanent'] == 'true';
                    $result = $teacherManager->deleteTeacher($_POST['teacher_id'], $permanent);
                    $response = ['success' => $result[0], 'message' => $result[1]];
                }
                break;
        }
        
        echo json_encode($response);
        exit;
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

error_log("=== TEACHER LIST PAGE END ===");
?>

<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="description" content="Teacher List - School Management System">
    <meta name="keywords" content="Teacher List, School Management">
    <meta name="robots" content="INDEX,FOLLOW">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teacher List - <?php echo htmlspecialchars($school['name'] ?? 'School'); ?></title>
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
        .stats-card {
            background: #fff;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            border: 1px solid #eef2f6;
        }
        .stats-number {
            font-size: 28px;
            font-weight: 700;
            color: #25A194;
        }
        .stats-label {
            color: #6c757d;
            font-size: 14px;
        }
        .teacher-avatar {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            object-fit: cover;
        }
        .avatar-placeholder {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            background: linear-gradient(135deg, #25A194, #1a7a6f);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 16px;
        }
        .filter-active {
            background-color: #25A194;
            color: white !important;
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
<?php require_once __DIR__ . '/includes/nav-header.php'; ?>

    <div class="dashboard-main-body">
        <div class="breadcrumb d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
            <div class="">
                <h1 class="fw-semibold mb-4 h6 text-primary-light">Teacher List</h1>
                <div class="">
                    <a href="index.php" class="text-secondary-light hover-text-primary hover-underline">Dashboard</a>
                    <span class="text-secondary-light">/ Teacher List</span>
                </div>
            </div>
            <a href="add-new-teacher.php" class="btn btn-primary-600 d-flex align-items-center gap-6">
                <span class="d-flex text-md">
                    <i class="ri-add-large-line"></i>
                </span>
                Add Teacher
            </a>
        </div>

        <!-- Statistics Cards -->
        <?php if (!empty($stats)): ?>
        <div class="row g-3 mb-24">
            <div class="col-xl-3 col-sm-6">
                <div class="stats-card">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="stats-number"><?php echo $stats['total'] ?? 0; ?></div>
                            <div class="stats-label">Total Teachers</div>
                        </div>
                        <div class="bg-primary-100 p-3 rounded-circle">
                            <i class="ri-user-line text-primary-600" style="font-size: 24px;"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-sm-6">
                <div class="stats-card">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="stats-number text-success"><?php echo $stats['active'] ?? 0; ?></div>
                            <div class="stats-label">Active Teachers</div>
                        </div>
                        <div class="bg-success-100 p-3 rounded-circle">
                            <i class="ri-check-line text-success-600" style="font-size: 24px;"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-sm-6">
                <div class="stats-card">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="stats-number text-danger"><?php echo $stats['inactive'] ?? 0; ?></div>
                            <div class="stats-label">Inactive Teachers</div>
                        </div>
                        <div class="bg-danger-100 p-3 rounded-circle">
                            <i class="ri-close-line text-danger-600" style="font-size: 24px;"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-sm-6">
                <div class="stats-card">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="stats-number text-info"><?php echo $stats['new_this_month'] ?? 0; ?></div>
                            <div class="stats-label">New This Month</div>
                        </div>
                        <div class="bg-info-100 p-3 rounded-circle">
                            <i class="ri-calendar-line text-info-600" style="font-size: 24px;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="mt-24">
            <div class="card h-100">
                <div class="card-body p-0 dataTable-wrapper">
                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-16 px-20 py-12 border-bottom border-neutral-200">
                        <div class="d-flex flex-wrap align-items-center gap-16">
                            <div class="dropdown">
                                <button type="button" class="px-12 py-5-px border border-neutral-300 radius-8 d-flex align-items-center gap-20" data-bs-toggle="dropdown" aria-expanded="false">
                                    <span class="d-flex align-items-center gap-1 text-secondary-light text-sm">
                                        <i class="ri-file-upload-line text-md line-height-1"></i>
                                        Export
                                    </span>
                                    <span class=""><i class="ri-arrow-down-s-line"></i></span>
                                </button>
                                <ul class="dropdown-menu p-12 border bg-base shadow">
                                    <li>
                                        <a href="export-teachers.php?format=pdf" class="dropdown-item px-16 py-8 rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-10">
                                            <i class="ri-file-pdf-line"></i> PDF
                                        </a>
                                    </li>
                                    <li>
                                        <a href="export-teachers.php?format=excel" class="dropdown-item px-16 py-8 rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-10">
                                            <i class="ri-file-excel-line"></i> Excel
                                        </a>
                                    </li>
                                </ul>
                            </div>

                            <div class="dropdown">
                                <button type="button" class="px-12 py-5-px border border-neutral-300 radius-8 d-flex align-items-center gap-20" data-bs-toggle="dropdown" aria-expanded="false">
                                    <span class="d-flex align-items-center gap-1 text-secondary-light text-sm">
                                        <i class="ri-filter-line"></i>
                                        Filter <?php echo (!empty($filters['status']) || !empty($filters['subject_id']) || !empty($filters['class_id'])) ? '(Active)' : ''; ?>
                                    </span>
                                    <span class=""><i class="ri-arrow-down-s-line"></i></span>
                                </button>
                                <div class="dropdown-menu border bg-base shadow dropdown-menu-lg p-0">
                                    <div class="d-flex align-items-center justify-content-between border-bottom py-8 px-16">
                                        <span class="fw-semibold text-lg text-primary-light">Filter Teachers</span>
                                        <button type="button" onclick="clearFilters()">
                                            <i class="ri-close-large-line"></i>
                                        </button>
                                    </div>

                                    <form method="GET" action="" class="p-16">
                                        <div class="row g-3">
                                            <div class="col-12">
                                                <label for="subject" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Subject</label>
                                                <select id="subject" name="subject" class="form-control form-select">
                                                    <option value="">All Subjects</option>
                                                    <?php foreach ($subjects as $subject): ?>
                                                    <option value="<?php echo $subject['id']; ?>" <?php echo ($filters['subject_id'] == $subject['id']) ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($subject['name']); ?>
                                                    </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-12">
                                                <label for="status" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Status</label>
                                                <select id="status" name="status" class="form-control form-select">
                                                    <option value="">All Status</option>
                                                    <option value="active" <?php echo ($filters['status'] == 'active') ? 'selected' : ''; ?>>Active</option>
                                                    <option value="inactive" <?php echo ($filters['status'] == 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                                                </select>
                                            </div>
                                            <div class="col-6">
                                                <button type="reset" class="btn btn-danger-200 text-danger-600 w-100" onclick="resetForm()">Reset</button>
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
                            <span class="">Total Teachers: <strong><?php echo count($teachers); ?></strong></span>
                        </div>
                    </div>

                    <div class="p-0 table-responsive">
                        <table class="table bordered-table mb-0 data-table" id="teacherTable">
                            <thead>
                                <tr>
                                    <th scope="col">S.L</th>
                                    <th scope="col">ID</th>
                                    <th scope="col">Name</th>
                                    <th scope="col">Subject</th>
                                    <th scope="col">Class</th>
                                    <th scope="col">Email</th>
                                    <th scope="col">Phone</th>
                                    <th scope="col">Join Date</th>
                                    <th scope="col">Status</th>
                                    <th scope="col">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($teachers)): ?>
                                    <?php $count = 1; ?>
                                    <?php foreach ($teachers as $teacher): ?>
                                    <tr>
                                        <td><?php echo $count++; ?></td>
                                        <td>
                                            <span class="text-primary-600"><?php echo htmlspecialchars($teacher['employee_id'] ?? 'N/A'); ?></span>
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <?php 
                                                $avatar = $teacher['profile_photo'] ?? '';
                                                if (!empty($avatar) && file_exists(__DIR__ . '/../../../' . $avatar)):
                                                ?>
                                                <img src="<?php echo htmlspecialchars($avatar); ?>" alt="<?php echo htmlspecialchars($teacher['name']); ?>" class="flex-shrink-0 me-12 teacher-avatar">
                                                <?php else: 
                                                    $initials = '';
                                                    $nameParts = explode(' ', $teacher['name'] ?? 'Teacher');
                                                    foreach ($nameParts as $part) {
                                                        $initials .= strtoupper(substr($part, 0, 1));
                                                    }
                                                ?>
                                                <div class="avatar-placeholder me-12">
                                                    <?php echo $initials ?: 'T'; ?>
                                                </div>
                                                <?php endif; ?>
                                                <div class="">
                                                    <h6 class="text-md mb-0 fw-medium"><?php echo htmlspecialchars($teacher['name'] ?? 'N/A'); ?></h6>
                                                </div>
                                            </div>
                                        </td>
                                        <td><?php echo htmlspecialchars($teacher['subject_names'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($teacher['class_names'] ?? 'Not Assigned'); ?></td>
                                        <td><?php echo htmlspecialchars($teacher['email'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($teacher['phone'] ?? 'N/A'); ?></td>
                                        <td>
                                            <?php 
                                            if (!empty($teacher['joining_date'])) {
                                                echo date('d M Y', strtotime($teacher['joining_date']));
                                            } else {
                                                echo 'N/A';
                                            }
                                            ?>
                                        </td>
                                        <td>
                                            <?php if ($teacher['is_active'] == 1): ?>
                                            <span class="bg-success-100 text-success-600 px-24 py-4 radius-4 fw-medium text-sm status-badge" data-teacher-id="<?php echo $teacher['id']; ?>" data-status="active">Active</span>
                                            <?php else: ?>
                                            <span class="bg-danger-100 text-danger-600 px-24 py-4 radius-4 fw-medium text-sm status-badge" data-teacher-id="<?php echo $teacher['id']; ?>" data-status="inactive">Inactive</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="btn-group">
                                                <button type="button" class="text-primary-light text-xl" data-bs-toggle="dropdown" data-bs-display="static" aria-expanded="false">
                                                    <iconify-icon icon="tabler:dots-vertical"></iconify-icon>
                                                </button>
                                                <ul class="dropdown-menu dropdown-menu-lg-end border p-12">
                                                    <li>
                                                        <a href="teacher-details.php?id=<?php echo $teacher['id']; ?>" class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                            <i class="ri-user-3-line"></i> View Details
                                                        </a>
                                                    </li>
                                                    <li>
                                                        <a href="edit-teacher.php?id=<?php echo $teacher['id']; ?>" class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                            <i class="ri-edit-2-line"></i> Edit
                                                        </a>
                                                    </li>
                                                    <li>
                                                        <button class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6 toggle-status-btn" data-teacher-id="<?php echo $teacher['id']; ?>" data-current-status="<?php echo $teacher['is_active']; ?>">
                                                            <i class="ri-error-warning-line"></i>
                                                            <?php echo ($teacher['is_active'] == 1) ? 'Deactivate' : 'Activate'; ?>
                                                        </button>
                                                    </li>
                                                    <li>
                                                        <hr class="dropdown-divider">
                                                    </li>
                                                    <li>
                                                        <button class="dropdown-item rounded text-danger bg-hover-neutral-200 text-hover-danger d-flex align-items-center gap-2 py-6 delete-btn" data-teacher-id="<?php echo $teacher['id']; ?>" data-teacher-name="<?php echo htmlspecialchars($teacher['name']); ?>">
                                                            <i class="ri-delete-bin-6-line"></i> Delete
                                                        </button>
                                                    </li>
                                                </ul>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="10" class="text-center py-20">
                                            <div class="text-center">
                                                <iconify-icon icon="fluent:people-20-regular" class="text-secondary-light" style="font-size: 48px;"></iconify-icon>
                                                <p class="text-secondary-light mt-16 mb-0">No teachers found</p>
                                                <a href="add-new-teacher.php" class="btn btn-primary-600 mt-16">
                                                    <i class="ri-add-line me-2"></i>Add Your First Teacher
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
</main>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm modal-dialog modal-dialog-centered">
        <div class="modal-content radius-16 bg-base">
            <div class="modal-body pt-32 px-36 pb-24 text-center">
                <span class="mb-16 fs-1 line-height-1 text-danger">
                    <iconify-icon icon="fluent:delete-24-regular" class="menu-icon"></iconify-icon>
                </span>
                <h6 class="text-lg fw-semibold text-primary-light mb-2" id="deleteModalTitle">Delete Teacher?</h6>
                <p class="text-secondary-light text-sm mb-4" id="deleteModalMessage">Are you sure you want to delete this teacher?</p>
                <div class="d-flex align-items-center justify-content-center gap-3 mt-24">
                    <button type="button" class="flex-grow-1 border border-danger-600 bg-hover-danger-200 text-danger-600 text-md px-24 py-11 radius-8" data-bs-dismiss="modal">
                        Cancel
                    </button>
                    <button type="button" class="flex-grow-1 btn btn-primary-600 border border-primary-600 text-md px-16 py-12 radius-8" id="confirmDeleteBtn">
                        Yes, Delete
                    </button>
                </div>
            </div>
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
    // Initialize DataTable
    let table = $('#teacherTable').DataTable({
        pageLength: 10,
        lengthMenu: [5, 10, 25, 50, 100],
        order: [[0, 'asc']],
        language: {
            search: "",
            searchPlaceholder: "Search...",
            lengthMenu: "Show _MENU_ entries",
            info: "Showing _START_ to _END_ of _TOTAL_ entries",
            infoEmpty: "Showing 0 to 0 of 0 entries",
            infoFiltered: "(filtered from _MAX_ total entries)",
            paginate: {
                first: "First",
                last: "Last",
                next: "Next",
                previous: "Previous"
            }
        },
        dom: 'rt<"d-flex align-items-center justify-content-between flex-wrap gap-16 px-20 py-12 border-top border-neutral-200"<"text-secondary-light"i><"pagination-wrapper"p>>',
        initComplete: function() {
            // Move the search input to the navbar search
            $('.dataTables_filter').hide();
        }
    });

    // Custom search input
    $('.navbar-search input').on('keyup', function() {
        table.search(this.value).draw();
    });

    // Initialize Bootstrap toasts
    $('.toast').toast({
        autohide: true,
        delay: 5000
    });
    $('.toast').toast('show');

    // Toggle status functionality
    $('.toggle-status-btn').on('click', function() {
        const teacherId = $(this).data('teacher-id');
        const currentStatus = $(this).data('current-status');
        const action = currentStatus == 1 ? 'suspend' : 'activate';
        const actionText = currentStatus == 1 ? 'deactivate' : 'activate';
        
        if (confirm(`Are you sure you want to ${actionText} this teacher?`)) {
            $.ajax({
                url: window.location.pathname,
                method: 'POST',
                data: {
                    action: 'toggle_status',
                    teacher_id: teacherId,
                    reason: 'Manual status change'
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert('Error: ' + response.message);
                    }
                },
                error: function() {
                    alert('An error occurred. Please try again.');
                }
            });
        }
    });

    // Delete functionality
    let teacherToDelete = null;
    let teacherName = '';

    $('.delete-btn').on('click', function() {
        teacherToDelete = $(this).data('teacher-id');
        teacherName = $(this).data('teacher-name');
        $('#deleteModalTitle').text('Delete Teacher?');
        $('#deleteModalMessage').html(`Are you sure you want to delete <strong>${teacherName}</strong>? This action cannot be undone.`);
        $('#deleteModal').modal('show');
    });

    $('#confirmDeleteBtn').on('click', function() {
        if (teacherToDelete) {
            $.ajax({
                url: window.location.pathname,
                method: 'POST',
                data: {
                    action: 'delete',
                    teacher_id: teacherToDelete,
                    permanent: true
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert('Error: ' + response.message);
                    }
                },
                error: function() {
                    alert('An error occurred. Please try again.');
                }
            });
        }
        $('#deleteModal').modal('hide');
    });

    // Clear filters
    window.clearFilters = function() {
        window.location.href = 'teacher-list.php';
    };

    // Reset form
    window.resetForm = function() {
        document.querySelector('form').reset();
    };

    // Set current year in footer
    $('.current-year').text(new Date().getFullYear());

    // Handle page length change
    $(document).on('change', '.dt-length select', function() {
        const value = $(this).val();
        table.page.len(value).draw();
    });
});
</script>

</body>
</html>