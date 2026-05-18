<?php

/**
 * School Student List Page - VIRTUAL VERSION
 * This file displays all students from the school database
 */

// Enable error reporting
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../../logs/school_students.log');

error_log("=== STUDENT LIST PAGE START ===");
error_log("Request URI: " . ($_SERVER['REQUEST_URI'] ?? 'N/A'));
error_log("Script: " . __FILE__);

// Define constants if not defined
if (!defined('APP_NAME')) define('APP_NAME', 'AcademixSuite');
// IS_LOCAL is self-defining via config/constants.php (security: do not force true).

// Start session safely
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

// Get school slug from GLOBALS (set by router.php)
$schoolSlug = $GLOBALS['SCHOOL_SLUG'] ?? '';
$userType = $GLOBALS['USER_TYPE'] ?? 'admin';
$currentPage = $GLOBALS['CURRENT_PAGE'] ?? 'students.php';
$schoolData = $GLOBALS['SCHOOL_DATA'] ?? [];
$baseUrl = $GLOBALS['BASE_URL'] ?? '';

if (empty($schoolSlug)) {
    error_log("ERROR: Empty school slug from router");
    header('HTTP/1.1 400 Bad Request');
    echo json_encode(['error' => 'School identifier missing']);
    exit;
}

// Get school info from session or GLOBALS
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
    if ($_SESSION['school_auth']['school_slug'] === $schoolSlug) {
        $isAuthenticated = true;
    }
}

if (!$isAuthenticated) {
    error_log("User not authenticated, redirecting to login");
    header('Location: ../../login.php?school_slug=' . urlencode($schoolSlug));
    exit;
}

// Get user info from session
$schoolAuth = $_SESSION['school_auth'];
$userId = $schoolAuth['user_id'] ?? 0;
$userType = $schoolAuth['user_type'] ?? '';

$currentPage = basename(__FILE__);

// Verify access (admin or teacher can view)
if (!in_array($userType, ['admin', 'teacher'])) {
    error_log("ERROR: User does not have permission to view students");
    header('HTTP/1.1 403 Forbidden');
    echo "Access denied. Insufficient privileges.";
    exit;
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
} catch (Exception $e) {
    error_log("Error loading autoload.php: " . $e->getMessage());
    http_response_code(500);
    die("Configuration loading failed.");
}

// Connect to school database
$schoolDb = null;
try {
    if (!empty($school['database_name'])) {
        $schoolDb = Database::getSchoolConnection($school['database_name']);
        error_log("School database connection successful");
    }
} catch (Exception $e) {
    error_log("ERROR connecting to school database: " . $e->getMessage());
    $schoolDb = null;
}

// Initialize variables
$students = [];
$classes = [];
$sections = [];
$categories = [];
$totalStudents = 0;
$settings = [];
$adminUser = ['name' => 'Admin User', 'role_name' => 'Administrator'];

// Fetch data from database
if ($schoolDb) {
    try {
        // Get school settings
        $settingsStmt = $schoolDb->prepare("SELECT * FROM settings WHERE school_id = ?");
        if ($settingsStmt) {
            $settingsStmt->execute([$school['id']]);
            $settingsRows = $settingsStmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($settingsRows as $row) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
        }

        // Get logged in user details
        $userStmt = $schoolDb->prepare("
            SELECT u.*, r.name as role_name 
            FROM users u 
            LEFT JOIN roles r ON u.role_id = r.id 
            WHERE u.id = ? AND u.school_id = ?
        ");
        if ($userStmt) {
            $userStmt->execute([$userId, $school['id']]);
            $adminUserData = $userStmt->fetch(PDO::FETCH_ASSOC);
            if ($adminUserData) {
                $adminUser = $adminUserData;
            } elseif (isset($_SESSION['school_user']['name'])) {
                $adminUser = [
                    'name' => $_SESSION['school_user']['name'],
                    'role_name' => 'Administrator'
                ];
            }
        }

        // Get all classes for filter
        $classStmt = $schoolDb->prepare("
            SELECT id, name, section 
            FROM classes 
            WHERE school_id = ? AND is_active = 1 
            ORDER BY name
        ");
        if ($classStmt) {
            $classStmt->execute([$school['id']]);
            $classes = $classStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // Get student categories
        $catStmt = $schoolDb->prepare("
            SELECT id, name 
            FROM student_categories 
            WHERE school_id = ? 
            ORDER BY name
        ");
        if ($catStmt) {
            $catStmt->execute([$school['id']]);
            $categories = $catStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // Get all students with related data
        $studentStmt = $schoolDb->prepare("
            SELECT 
                s.*,
                c.name as class_name,
                c.section as class_section,
                sc.name as category_name,
                CONCAT(c.name, ' (', c.section, ')') as class_display
            FROM students s
            LEFT JOIN classes c ON s.class_id = c.id
            LEFT JOIN student_categories sc ON s.category_id = sc.id
            WHERE s.school_id = ?
            ORDER BY s.created_at DESC
        ");
        
        if ($studentStmt) {
            $studentStmt->execute([$school['id']]);
            $students = $studentStmt->fetchAll(PDO::FETCH_ASSOC);
            $totalStudents = count($students);
            error_log("Fetched " . $totalStudents . " students");
        }

    } catch (Exception $e) {
        error_log("Error fetching student data: " . $e->getMessage());
    }
}

// Handle search and filters
$searchTerm = $_GET['search'] ?? '';
$classFilter = $_GET['class'] ?? '';
$genderFilter = $_GET['gender'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$categoryFilter = $_GET['category'] ?? '';

// Filter students if needed
if (!empty($searchTerm) || !empty($classFilter) || !empty($genderFilter) || !empty($statusFilter) || !empty($categoryFilter)) {
    $filteredStudents = [];
    foreach ($students as $student) {
        $match = true;
        
        if (!empty($searchTerm)) {
            $searchLower = strtolower($searchTerm);
            $studentMatch = 
                strpos(strtolower($student['first_name'] . ' ' . $student['last_name']), $searchLower) !== false ||
                strpos(strtolower($student['admission_number'] ?? ''), $searchLower) !== false ||
                strpos(strtolower($student['roll_number'] ?? ''), $searchLower) !== false ||
                strpos(strtolower($student['mobile'] ?? ''), $searchLower) !== false;
            
            if (!$studentMatch) {
                $match = false;
            }
        }
        
        if (!empty($classFilter) && $student['class_id'] != $classFilter) {
            $match = false;
        }
        
        if (!empty($genderFilter) && strtolower($student['gender'] ?? '') != strtolower($genderFilter)) {
            $match = false;
        }
        
        if (!empty($statusFilter)) {
            $status = strtolower($student['status'] ?? 'active');
            if ($statusFilter == 'active' && $status != 'active') {
                $match = false;
            } elseif ($statusFilter == 'inactive' && $status == 'active') {
                $match = false;
            }
        }
        
        if (!empty($categoryFilter) && ($student['category_id'] ?? 0) != $categoryFilter) {
            $match = false;
        }
        
        if ($match) {
            $filteredStudents[] = $student;
        }
    }
    $students = $filteredStudents;
}

// Format currency symbol
$currencySymbol = $settings['currency_symbol'] ?? '₦';

error_log("=== STUDENT LIST PAGE END ===");
?>

<!-- meta tags and other links -->
<!DOCTYPE html>
<html lang="en" data-theme="light">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="description" content="Student List - Manage all students">
    <meta name="keywords" content="Student List, School Management">
    <meta name="robots" content="INDEX,FOLLOW">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student List - <?php echo htmlspecialchars($school['name']); ?></title>
    <link rel="icon" type="image/png" href="https://academixsuite.com/tenant/assets/images/favicon.png" sizes="16x16">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/remixicon.css">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/lib/bootstrap.min.css">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/lib/apexcharts.css">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/lib/dataTables.min.css">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/lib/flatpickr.min.css">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/lib/full-calendar.css">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/lib/calendar.css">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/style.css">
</head>

<body>

    <!-- Theme Customization Structure Start -->
    <div class="body-overlay"></div>
    <button type="button" class="theme-customization__button w-48-px h-48-px bg-primary-600 text-white rounded-circle d-flex justify-content-center align-items-center position-fixed end-0 bottom-0 mb-40 me-40 text-2xxl bg-hover-primary-700" aria-label="Theme Customization Button">
        <i class="ri-settings-3-line animate-spin"></i>
    </button>
    <div class="theme-customization-sidebar w-100 bg-base h-100vh overflow-y-auto position-fixed end-0 top-0">
        <div class="d-flex align-items-center gap-3 py-16 px-24 justify-content-between border-bottom">
            <div>
                <h6 class="text-sm dark:text-white">Theme Settings</h6>
                <p class="text-xs mb-0 text-neutral-500 dark:text-neutral-200">Customize and preview instantly</p>
            </div>
            <button data-slot="button" class="theme-customization-sidebar__close text-neutral-900 bg-transparent text-hover-primary-600 d-flex text-xl">
                <i class="ri-close-fill"></i>
            </button>
        </div>
        <div class="d-flex flex-column gap-48 p-24 overflow-y-auto flex-grow-1">
            <div class="theme-setting-item">
                <h6 class="fw-medium text-primary-light text-md mb-3">Theme Mode</h6>
                <div class="d-grid grid-cols-3 gap-3 dark-light-mode">
                    <button type="button" class="theme-btn theme-setting-item__btn d-flex align-items-center justify-content-center h-64-px rounded-3 text-xl active" data-theme="light" aria-label="light">
                        <i class="ri-sun-line"></i>
                    </button>
                    <button type="button" class="theme-btn theme-setting-item__btn d-flex align-items-center justify-content-center h-64-px rounded-3 text-xl" data-theme="dark" aria-label="dark">
                        <i class="ri-moon-line"></i>
                    </button>
                    <button type="button" class="theme-btn theme-setting-item__btn d-flex align-items-center justify-content-center h-64-px rounded-3 text-xl" data-theme="system" aria-label="system">
                        <i class="ri-computer-line"></i>
                    </button>
                </div>
            </div>
            <div class="theme-setting-item">
                <h6 class="fw-medium text-primary-light text-md mb-3">Color Schema</h6>
                <div class="d-grid grid-cols-3 gap-3">
                    <button type="button" class="color-picker-btn d-flex flex-column justify-content-center align-items-center" data-color="base" aria-label="Base">
                        <span class="color-picker-btn__box h-40-px w-100 rounded-3" style="background-color: #25A194;"></span>
                        <span class="fw-medium mt-1" style="color: #25A194;">Base</span>
                    </button>
                    <button type="button" class="color-picker-btn d-flex flex-column justify-content-center align-items-center" data-color="red" aria-label="Red">
                        <span class="color-picker-btn__box h-40-px w-100 rounded-3" style="background-color: #dc2626;"></span>
                        <span class="fw-medium mt-1" style="color: #dc2626;">Red</span>
                    </button>
                    <button type="button" class="color-picker-btn d-flex flex-column justify-content-center align-items-center" data-color="blue" aria-label="Blue">
                        <span class="color-picker-btn__box h-40-px w-100 rounded-3" style="background-color: #2563eb;"></span>
                        <span class="fw-medium mt-1" style="color: #2563eb;">Blue</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
    <!-- Theme Customization Structure End -->

    <div class="overlay bg-black bg-opacity-50 w-100 h-100 position-fixed z-9 visibility-hidden opacity-0 duration-300"></div>
    <?php include_once('includes/sidebar.php'); ?>
<main class="dashboard-main">
        
        <?php include_once('includes/header.php'); ?>
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
                    <h1 class="fw-semibold mb-4 h6 text-primary-light">Student List</h1>
                    <div class="">
                        <a href="index.php" class="text-secondary-light hover-text-primary hover-underline">Dashboard</a>
                        <span class="text-secondary-light"> / Student List</span>
                    </div>
                </div>
                <a href="add-new-student.php" class="btn btn-primary-600 d-flex align-items-center gap-6">
                    <span class="d-flex text-md">
                        <i class="ri-add-large-line"></i>
                    </span>
                    Add Student
                </a>
            </div>

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
                                            <a href="export-students.php?format=pdf" class="dropdown-item px-16 py-8 rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-10">
                                                <i class="ri-file-pdf-line"></i>
                                                PDF
                                            </a>
                                        </li>
                                        <li>
                                            <a href="export-students.php?format=excel" class="dropdown-item px-16 py-8 rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-10">
                                                <i class="ri-file-excel-line"></i>
                                                Excel
                                            </a>
                                        </li>
                                    </ul>
                                </div>
                                
                                <div class="dropdown">
                                    <button type="button" class="px-12 py-5-px border border-neutral-300 radius-8 d-flex align-items-center gap-20" data-bs-toggle="dropdown" aria-expanded="false">
                                        <span class="d-flex align-items-center gap-1 text-secondary-light text-sm">
                                            <i class="ri-filter-line"></i>
                                            Filter
                                        </span>
                                        <span class=""><i class="ri-arrow-down-s-line"></i></span>
                                    </button>
                                    <div class="dropdown-menu border bg-base shadow dropdown-menu-lg p-0">
                                        <div class="d-flex align-items-center justify-content-between border-bottom py-8 px-16">
                                            <span class="fw-semibold text-lg text-primary-light">Filter Students</span>
                                            <button type="button" onclick="clearFilters()">
                                                <i class="ri-close-large-line"></i>
                                            </button>
                                        </div>
                                        <form method="GET" action="" class="p-16 d-grid grid-cols-2 gap-16">
                                            <div class="">
                                                <label for="class" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Class</label>
                                                <select id="class" name="class" class="form-control form-select">
                                                    <option value="">All Classes</option>
                                                    <?php foreach ($classes as $class): ?>
                                                    <option value="<?php echo $class['id']; ?>" <?php echo $classFilter == $class['id'] ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($class['name'] . ' (' . ($class['section'] ?? 'No Section') . ')'); ?>
                                                    </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="">
                                                <label for="gender" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Gender</label>
                                                <select id="gender" name="gender" class="form-control form-select">
                                                    <option value="">All Genders</option>
                                                    <option value="male" <?php echo $genderFilter == 'male' ? 'selected' : ''; ?>>Male</option>
                                                    <option value="female" <?php echo $genderFilter == 'female' ? 'selected' : ''; ?>>Female</option>
                                                    <option value="other" <?php echo $genderFilter == 'other' ? 'selected' : ''; ?>>Other</option>
                                                </select>
                                            </div>
                                            <div class="">
                                                <label for="category" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Category</label>
                                                <select id="category" name="category" class="form-control form-select">
                                                    <option value="">All Categories</option>
                                                    <?php foreach ($categories as $category): ?>
                                                    <option value="<?php echo $category['id']; ?>" <?php echo $categoryFilter == $category['id'] ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($category['name']); ?>
                                                    </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="">
                                                <label for="status" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Status</label>
                                                <select id="status" name="status" class="form-control form-select">
                                                    <option value="">All Status</option>
                                                    <option value="active" <?php echo $statusFilter == 'active' ? 'selected' : ''; ?>>Active</option>
                                                    <option value="inactive" <?php echo $statusFilter == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                                </select>
                                            </div>
                                            <div class="">
                                                <button type="reset" class="btn btn-danger-200 text-danger-600 w-100" onclick="resetForm()">Reset</button>
                                            </div>
                                            <div class="">
                                                <button type="submit" class="btn btn-primary-600 w-100">Apply Filter</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            <div class="d-flex align-items-center gap-8 text-secondary-light">
                                <span class="">Total Students: <strong><?php echo count($students); ?></strong></span>
                            </div>
                        </div>

                        <div class="p-0">
                            <table class="table bordered-table mb-0 data-table" id="dataTable">
                                <thead>
                                    <tr>
                                        <th scope="col">#</th>
                                        <th scope="col">Admission No</th>
                                        <th scope="col">Student Name</th>
                                        <th scope="col">Class</th>
                                        <th scope="col">Roll No</th>
                                        <th scope="col">Date of Birth</th>
                                        <th scope="col">Gender</th>
                                        <th scope="col">Mobile</th>
                                        <th scope="col">Category</th>
                                        <th scope="col">Status</th>
                                        <th scope="col">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($students)): ?>
                                        <?php $count = 1; ?>
                                        <?php foreach ($students as $student): ?>
                                        <tr>
                                            <td><?php echo $count++; ?></td>
                                            <td>
                                                <span class="text-primary-600"><?php echo htmlspecialchars($student['admission_number'] ?? 'N/A'); ?></span>
                                            </td>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <?php 
                                                    $studentAvatar = $student['avatar'] ?? 'https://academixsuite.com/tenant/assets/images/thumbs/avatar-img' . (($count % 7) + 1) . '.png';
                                                    ?>
                                                    <img src="<?php echo htmlspecialchars($studentAvatar); ?>" alt="Student" class="flex-shrink-0 me-12 radius-8" style="width: 40px; height: 40px; object-fit: cover;">
                                                    <div class="">
                                                        <h6 class="text-md mb-0 fw-medium flex-grow-1">
                                                            <?php echo htmlspecialchars(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? '')); ?>
                                                        </h6>
                                                    </div>
                                                </div>
                                            </td>
                                            <td><?php echo htmlspecialchars($student['class_display'] ?? 'Not Assigned'); ?></td>
                                            <td><?php echo htmlspecialchars($student['roll_number'] ?? 'N/A'); ?></td>
                                            <td>
                                                <?php 
                                                if (!empty($student['date_of_birth'])) {
                                                    echo date('d M Y', strtotime($student['date_of_birth']));
                                                } else {
                                                    echo 'N/A';
                                                }
                                                ?>
                                            </td>
                                            <td>
                                                <?php 
                                                $gender = $student['gender'] ?? 'Not Specified';
                                                $genderClass = strtolower($gender) == 'male' ? 'primary' : (strtolower($gender) == 'female' ? 'warning' : 'secondary');
                                                echo ucfirst($gender); 
                                                ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($student['mobile'] ?? $student['phone'] ?? 'N/A'); ?></td>
                                            <td><?php echo htmlspecialchars($student['category_name'] ?? 'General'); ?></td>
                                            <td>
                                                <?php 
                                                $status = strtolower($student['status'] ?? 'active');
                                                if ($status == 'active'): 
                                                ?>
                                                <span class="bg-success-100 text-success-600 px-24 py-4 radius-4 fw-medium text-sm">Active</span>
                                                <?php else: ?>
                                                <span class="bg-danger-100 text-danger-600 px-24 py-4 radius-4 fw-medium text-sm">Inactive</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="btn-group">
                                                    <button type="button" class="text-primary-light text-xl" data-bs-toggle="dropdown" data-bs-display="static" aria-expanded="false">
                                                        <iconify-icon icon="tabler:dots-vertical"></iconify-icon>
                                                    </button>
                                                    <ul class="dropdown-menu dropdown-menu-lg-end border p-12">
                                                        <li>
                                                            <a href="student-details.php?id=<?php echo $student['id']; ?>" class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                                <i class="ri-user-3-line"></i>
                                                                View Details
                                                            </a>
                                                        </li>
                                                        <li>
                                                            <a href="edit-student.php?id=<?php echo $student['id']; ?>" class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                                <i class="ri-edit-2-line"></i>
                                                                Edit
                                                            </a>
                                                        </li>
                                                        <li>
                                                            <a href="fees-collect.php?student_id=<?php echo $student['id']; ?>" class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                                <i class="ri-money-dollar-box-line"></i>
                                                                Collect Fees
                                                            </a>
                                                        </li>
                                                        <li>
                                                            <button onclick="toggleStatus(<?php echo $student['id']; ?>, '<?php echo $status; ?>')" class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                                                                <i class="ri-error-warning-line"></i>
                                                                <?php echo $status == 'active' ? 'Deactivate' : 'Activate'; ?>
                                                            </button>
                                                        </li>
                                                        <li>
                                                            <button onclick="confirmDelete(<?php echo $student['id']; ?>)" class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6" data-bs-toggle="modal" data-bs-target="#deleteModal">
                                                                <i class="ri-delete-bin-6-line"></i>
                                                                Delete
                                                            </button>
                                                        </li>
                                                    </ul>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="11" class="text-center py-20">
                                                <p class="text-secondary-light">No students found</p>
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

        <footer class="d-footer">
            <div class="">
                <p class="mb-0 text-center"> &copy; <span class="current-year"></span> <?php echo htmlspecialchars($school['name']); ?> | Made With ❤️ by AcademixSuite.</p>
            </div>
        </footer>
    </main>

    <!-- Delete Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-sm modal-dialog modal-dialog-centered max-w-340-px">
            <div class="modal-content radius-16 bg-base">
                <div class="modal-body pt-32 px-36 pb-24 text-center">
                    <span class="mb-16 fs-1 line-height-1 text-danger">
                        <iconify-icon icon="fluent:delete-24-regular" class="menu-icon"></iconify-icon>
                    </span>
                    <h6 class="text-lg fw-semibold text-primary-light mb-0">Are you sure you want to delete this student?</h6>
                    <div class="d-flex align-items-center justify-content-center gap-3 mt-24">
                        <button type="button" class="flex-grow-1 border border-danger-600 bg-hover-danger-200 text-danger-600 text-md px-24 py-11 radius-8" data-bs-dismiss="modal">
                            Cancel
                        </button>
                        <button type="button" id="confirmDeleteBtn" class="flex-grow-1 btn btn-primary-600 border border-primary-600 text-md px-16 py-12 radius-8">
                            Yes, Delete
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://academixsuite.com/tenant/assets/js/lib/jquery-3.7.1.min.js"></script>
    <script src="https://academixsuite.com/tenant/assets/js/lib/bootstrap.bundle.min.js"></script>
    <script src="https://academixsuite.com/tenant/assets/js/lib/apexcharts.min.js"></script>
    <script src="https://academixsuite.com/tenant/assets/js/lib/iconify-icon.min.js"></script>
    <script src="https://academixsuite.com/tenant/assets/js/lib/dataTables.min.js"></script>
    <script src="https://academixsuite.com/tenant/assets/js/lib/jquery-ui.min.js"></script>
    <script src="https://academixsuite.com/tenant/assets/js/app.js"></script>

    <script>
        // Initialize DataTable
        let table = new DataTable('#dataTable', {
            pageLength: 10,
            lengthMenu: [5, 10, 25, 50, 100],
            language: {
                search: "",
                searchPlaceholder: "Search...",
                lengthMenu: "Show _MENU_ entries",
                info: "Showing _START_ to _END_ of _TOTAL_ entries",
                paginate: {
                    first: "First",
                    last: "Last",
                    next: "Next",
                    previous: "Previous"
                }
            }
        });

        // Custom search input
        $('.navbar-search input').on('keyup', function() {
            table.search(this.value).draw();
        });

        // Handle page length change
        $('.dt-length select').on('change', function() {
            const value = $(this).val();
            table.page.len(value).draw();
        });

        // Delete functionality
        let studentToDelete = null;

        function confirmDelete(studentId) {
            studentToDelete = studentId;
            $('#deleteModal').modal('show');
        }

        $('#confirmDeleteBtn').on('click', function() {
            if (studentToDelete) {
                window.location.href = 'delete-student.php?id=' + studentToDelete;
            }
        });

        // Toggle status
        function toggleStatus(studentId, currentStatus) {
            const newStatus = currentStatus === 'active' ? 'inactive' : 'active';
            if (confirm('Are you sure you want to change this student\'s status?')) {
                window.location.href = 'toggle-student-status.php?id=' + studentId + '&status=' + newStatus;
            }
        }

        // Clear filters
        function clearFilters() {
            window.location.href = 'student-list.php';
        }

        // Reset form
        function resetForm() {
            document.querySelector('form').reset();
        }

        // Export functionality
        $('.dropdown-item:contains("PDF")').on('click', function(e) {
            e.preventDefault();
            window.location.href = 'export-students.php?format=pdf' + window.location.search;
        });

        $('.dropdown-item:contains("Excel")').on('click', function(e) {
            e.preventDefault();
            window.location.href = 'export-students.php?format=excel' + window.location.search;
        });
    </script>
</body>
</html>