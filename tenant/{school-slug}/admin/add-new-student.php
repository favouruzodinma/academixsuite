<?php

/**
 * School Add Student Page
 * Handles adding new students to the school database
 */

// Enable error reporting
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../../logs/school_add_student.log');

error_log("=== ADD STUDENT PAGE START ===");
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
$currentPage = $GLOBALS['CURRENT_PAGE'] ?? 'add-student.php';
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

// Verify admin access
if ($userType !== 'admin') {
    error_log("ERROR: User does not have admin privileges");
    header('HTTP/1.1 403 Forbidden');
    echo "Access denied. Admin privileges required.";
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
    
    // Include StudentManager
    require_once __DIR__ . '/../../../includes/StudentManager.php';
    
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

// Initialize StudentManager
$studentManager = null;
if ($schoolDb) {
    $studentManager = new StudentManager($schoolDb, $school['id'], $userId, $userType, $school);
}

// Initialize variables
$settings = [];
$classes = [];
$academicYears = [];
$studentCategories = [];
$bloodGroups = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
$message = '';
$error = '';
$adminUser = ['name' => 'Admin User', 'role_name' => 'Administrator'];
$selectedClass = $_GET['class_id'] ?? 0;
$sections = [];

// Fetch data from database
if ($schoolDb) {
    try {
        // Get school settings
        $settingsStmt = $schoolDb->prepare("SELECT * FROM settings WHERE school_id = ?");
        if ($settingsStmt) {
            $settingsStmt->execute([$school['id']]);
            $settingsRows = $settingsStmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($settingsRows as $row) {
                $settings[$row['key']] = $row['value'];
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

        // Get academic years
        $yearStmt = $schoolDb->prepare("
            SELECT * FROM academic_years 
            WHERE school_id = ? AND status IN ('active', 'upcoming')
            ORDER BY is_default DESC, start_date DESC
        ");
        if ($yearStmt) {
            $yearStmt->execute([$school['id']]);
            $academicYears = $yearStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // Get classes
        $classStmt = $schoolDb->prepare("
            SELECT c.*, ay.name as academic_year_name 
            FROM classes c
            LEFT JOIN academic_years ay ON c.academic_year_id = ay.id
            WHERE c.school_id = ? AND c.is_active = 1
            ORDER BY c.name
        ");
        if ($classStmt) {
            $classStmt->execute([$school['id']]);
            $classes = $classStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // Get sections for selected class
        if ($selectedClass && $studentManager) {
            $sections = $studentManager->getSectionsByClass($selectedClass);
        }

        // Get student categories
        $catStmt = $schoolDb->prepare("
            SELECT * FROM student_categories 
            WHERE school_id = ? 
            ORDER BY name
        ");
        if ($catStmt) {
            $catStmt->execute([$school['id']]);
            $studentCategories = $catStmt->fetchAll(PDO::FETCH_ASSOC);
        }

    } catch (Exception $e) {
        error_log("Error fetching data: " . $e->getMessage());
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit']) && $studentManager) {
    
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        $error = "Invalid security token. Please try again.";
    } else {
        // Prepare student data
        $studentData = [
            'academic_year_id' => $_POST['academic_year'] ?? null,
            'class_id' => $_POST['class'] ?? null,
            'section_id' => $_POST['section'] ?? null,
            'roll_number' => $_POST['roll_number'] ?? null,
            'admission_date' => $_POST['admission_date'] ?? date('Y-m-d'),
            
            // Student personal info
            'first_name' => sanitize($_POST['first_name'] ?? ''),
            'middle_name' => sanitize($_POST['middle_name'] ?? null),
            'last_name' => sanitize($_POST['last_name'] ?? ''),
            'gender' => $_POST['gender'] ?? null,
            'date_of_birth' => $_POST['date_of_birth'] ?? null,
            'student_email' => sanitize($_POST['student_email'] ?? ''),
            'student_phone' => sanitize($_POST['student_phone'] ?? ''),
            'category_id' => $_POST['category'] ?? null,
            
            // Parent/Guardian info
            'father_name' => sanitize($_POST['father_name'] ?? ''),
            'father_phone' => sanitize($_POST['father_phone'] ?? ''),
            'mother_name' => sanitize($_POST['mother_name'] ?? ''),
            'mother_phone' => sanitize($_POST['mother_phone'] ?? ''),
            'guardian_name' => sanitize($_POST['guardian_name'] ?? ''),
            'guardian_email' => sanitize($_POST['guardian_email'] ?? ''),
            'guardian_phone' => sanitize($_POST['guardian_phone'] ?? ''),
            'guardian_relation' => $_POST['guardian_relation'] ?? null,
            'guardian_address' => sanitize($_POST['guardian_address'] ?? ''),
            'existing_parent_id' => $_POST['existing_parent_id'] ?? null,
            
            // Medical details
            'blood_group' => $_POST['blood_group'] ?? null,
            'allergies' => sanitize($_POST['allergies'] ?? null),
            'medical_conditions' => sanitize($_POST['medical_conditions'] ?? null),
            'doctor_name' => sanitize($_POST['doctor_name'] ?? null),
            'doctor_phone' => sanitize($_POST['doctor_phone'] ?? null),
            
            // Address
            'current_address' => sanitize($_POST['current_address'] ?? null),
            'permanent_address' => sanitize($_POST['permanent_address'] ?? null),
            
            // Previous school
            'previous_school' => sanitize($_POST['previous_school'] ?? null),
            'previous_class' => sanitize($_POST['previous_class'] ?? null),
            'transfer_certificate_no' => sanitize($_POST['transfer_certificate_no'] ?? null),
        ];

        // Validate required fields
        $requiredFields = ['first_name', 'last_name', 'class_id', 'academic_year_id'];
        $missingFields = [];
        
        foreach ($requiredFields as $field) {
            if (empty($studentData[$field])) {
                $missingFields[] = str_replace('_', ' ', $field);
            }
        }

        if (!empty($missingFields)) {
            $error = "Please fill all required fields: " . implode(', ', $missingFields);
        } else {
            // Add student using StudentManager
            list($success, $message, $studentId) = $studentManager->addStudent($studentData);
            
            if ($success) {
                $message = $message;
                // Clear form data on success
                $_POST = [];
            } else {
                $error = $message;
            }
        }
    }
}

// Generate CSRF token
$csrfToken = generateCsrfToken();

// Handle AJAX requests for sections and parent search
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    
    if ($_GET['ajax'] === 'get_sections' && isset($_GET['class_id']) && $studentManager) {
        $sections = $studentManager->getSectionsByClass($_GET['class_id']);
        echo json_encode(['success' => true, 'sections' => $sections]);
        exit;
    }
    
    if ($_GET['ajax'] === 'search_parents' && isset($_GET['term']) && $studentManager) {
        $parents = $studentManager->searchParents($_GET['term']);
        echo json_encode(['success' => true, 'parents' => $parents]);
        exit;
    }
}

// Format currency symbol
$currencySymbol = $settings['currency_symbol'] ?? '₦';

error_log("=== ADD STUDENT PAGE END ===");
?>

<!DOCTYPE html>
<html lang="en" data-theme="light">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="description" content="Add New Student - School Management System">
    <meta name="keywords" content="Add Student, School Management">
    <meta name="robots" content="INDEX,FOLLOW">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add New Student - <?php echo htmlspecialchars($school['name']); ?></title>
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
                    <h1 class="fw-semibold mb-4 h6 text-primary-light">Add New Student</h1>
                    <div class="">
                        <a href="index.php" class="text-secondary-light hover-text-primary hover-underline">Dashboard</a>
                        <a href="student-list.php" class="text-secondary-light hover-text-primary hover-underline"> / Student</a>
                        <span class="text-secondary-light"> / Add New Student</span>
                    </div>
                </div>
            </div>

            <?php if (!empty($message)): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>

            <?php if (!empty($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>

            <form method="POST" action="" enctype="multipart/form-data" class="mt-24" id="addStudentForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                
                <div class="row gy-3">
                    <div class="col-lg-12">
                        <div class="shadow-1 radius-12 bg-base h-100 overflow-hidden">
                            <div class="card-header border-bottom bg-base py-16 px-24 d-flex align-items-center justify-content-between">
                                <h6 class="text-lg fw-semibold mb-0">Academic Information</h6>
                            </div>
                            <div class="card-body p-20">
                                <div class="row gy-3">
                                    <div class="col-xxl-3 col-xl-4 col-sm-6">
                                        <div class="">
                                            <label for="academicYear" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                                Academic Year <span class="text-danger-600">*</span>
                                            </label>
                                            <select id="academicYear" name="academic_year" class="form-control form-select" required>
                                                <option value="">Select Academic Year</option>
                                                <?php foreach ($academicYears as $year): ?>
                                                <option value="<?php echo $year['id']; ?>" <?php echo ($year['is_default'] ?? 0) == 1 ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($year['name']); ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-xxl-3 col-xl-4 col-sm-6">
                                        <div class="">
                                            <label for="classSelection" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                                Class <span class="text-danger-600">*</span>
                                            </label>
                                            <select id="classSelection" name="class" class="form-control form-select" required>
                                                <option value="">Select Class</option>
                                                <?php foreach ($classes as $class): ?>
                                                <option value="<?php echo $class['id']; ?>" <?php echo ($selectedClass == $class['id']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($class['name']); ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-xxl-3 col-xl-4 col-sm-6">
                                        <div class="">
                                            <label for="section" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                                Section
                                            </label>
                                            <select id="section" name="section" class="form-control form-select">
                                                <option value="">Select Section</option>
                                                <?php foreach ($sections as $section): ?>
                                                <option value="<?php echo $section['id']; ?>">
                                                    <?php echo htmlspecialchars($section['name']); ?>
                                                    <?php if (!empty($section['capacity'])): ?>
                                                    (Capacity: <?php echo $section['capacity']; ?>)
                                                    <?php endif; ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-xxl-3 col-xl-4 col-sm-6">
                                        <div class="">
                                            <label for="rollNumber" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                                Roll Number
                                            </label>
                                            <input type="text" class="form-control" id="rollNumber" name="roll_number" placeholder="Enter roll number">
                                        </div>
                                    </div>
                                    <div class="col-xxl-3 col-xl-4 col-sm-6">
                                        <div class="">
                                            <label for="admissionDate" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                                Admission Date
                                            </label>
                                            <input type="date" class="form-control" id="admissionDate" name="admission_date" value="<?php echo date('Y-m-d'); ?>">
                                        </div>
                                    </div>
                                    <div class="col-xxl-3 col-xl-4 col-sm-6">
                                        <div class="alert alert-info">
                                            <small>Admission number will be auto-generated</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-12">
                        <div class="shadow-1 radius-12 bg-base h-100 overflow-hidden">
                            <div class="card-header border-bottom bg-base py-16 px-24 d-flex align-items-center justify-content-between">
                                <h6 class="text-lg fw-semibold mb-0">Personal Information</h6>
                            </div>
                            <div class="card-body p-20">
                                <div class="row gy-3">
                                    <div class="col-xxl-3 col-xl-4 col-sm-6">
                                        <div class="">
                                            <label for="firstName" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                                First Name <span class="text-danger-600">*</span>
                                            </label>
                                            <input type="text" class="form-control" id="firstName" name="first_name" placeholder="Enter first name" required>
                                        </div>
                                    </div>
                                    <div class="col-xxl-3 col-xl-4 col-sm-6">
                                        <div class="">
                                            <label for="middleName" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                                Middle Name
                                            </label>
                                            <input type="text" class="form-control" id="middleName" name="middle_name" placeholder="Enter middle name">
                                        </div>
                                    </div>
                                    <div class="col-xxl-3 col-xl-4 col-sm-6">
                                        <div class="">
                                            <label for="lastName" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                                Last Name <span class="text-danger-600">*</span>
                                            </label>
                                            <input type="text" class="form-control" id="lastName" name="last_name" placeholder="Enter last name" required>
                                        </div>
                                    </div>
                                    <div class="col-xxl-3 col-xl-4 col-sm-6">
                                        <div class="">
                                            <label for="gender" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                                Gender
                                            </label>
                                            <select id="gender" name="gender" class="form-control form-select">
                                                <option value="">Select Gender</option>
                                                <option value="male">Male</option>
                                                <option value="female">Female</option>
                                                <option value="other">Other</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-xxl-3 col-xl-4 col-sm-6">
                                        <div class="">
                                            <label for="dateOfBirth" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                                Date Of Birth
                                            </label>
                                            <input type="date" class="form-control" id="dateOfBirth" name="date_of_birth">
                                        </div>
                                    </div>
                                    <div class="col-xxl-3 col-xl-4 col-sm-6">
                                        <div class="">
                                            <label for="category" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                                Category
                                            </label>
                                            <select id="category" name="category" class="form-control form-select">
                                                <option value="">Select Category</option>
                                                <?php foreach ($studentCategories as $category): ?>
                                                <option value="<?php echo $category['id']; ?>">
                                                    <?php echo htmlspecialchars($category['name']); ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-xxl-3 col-xl-4 col-sm-6">
                                        <div class="">
                                            <label for="studentPhone" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                                Phone Number
                                            </label>
                                            <input type="tel" class="form-control" id="studentPhone" name="student_phone" placeholder="Enter phone number">
                                        </div>
                                    </div>
                                    <div class="col-xxl-3 col-xl-4 col-sm-6">
                                        <div class="">
                                            <label for="studentEmail" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                                Email
                                            </label>
                                            <input type="email" class="form-control" id="studentEmail" name="student_email" placeholder="Enter email (optional - will be auto-generated if empty)">
                                            <small class="text-secondary-light">If left empty, a professional email will be auto-generated</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-12">
                        <div class="shadow-1 radius-12 bg-base h-100 overflow-hidden">
                            <div class="card-header border-bottom bg-base py-16 px-24 d-flex align-items-center justify-content-between">
                                <h6 class="text-lg fw-semibold mb-0">Parent & Guardian Information</h6>
                            </div>
                            <div class="card-body p-20">
                                <!-- Existing Parent Search -->
                                <div class="row mb-4">
                                    <div class="col-12">
                                        <div class="alert alert-info">
                                            <h6 class="mb-2">Link Existing Parent</h6>
                                            <p class="mb-2">Search for an existing parent by name, email, or phone:</p>
                                            <div class="input-group">
                                                <input type="text" class="form-control" id="parentSearch" placeholder="Type to search existing parents...">
                                                <input type="hidden" name="existing_parent_id" id="selectedParentId">
                                                <button class="btn btn-outline-secondary" type="button" id="clearParentSearch">Clear</button>
                                            </div>
                                            <div id="parentSearchResults" class="list-group mt-2" style="display: none;"></div>
                                            <small class="text-muted">If you select an existing parent, the guardian fields below will be optional</small>
                                        </div>
                                    </div>
                                </div>

                                <div class="row gy-3">
                                    <div class="col-xxl-3 col-xl-4 col-sm-6">
                                        <div class="">
                                            <label for="fatherName" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                                Father's Name
                                            </label>
                                            <input type="text" class="form-control" id="fatherName" name="father_name" placeholder="Enter father's name">
                                        </div>
                                    </div>
                                    <div class="col-xxl-3 col-xl-4 col-sm-6">
                                        <div class="">
                                            <label for="fatherPhone" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                                Father's Phone
                                            </label>
                                            <input type="tel" class="form-control" id="fatherPhone" name="father_phone" placeholder="Enter father's phone">
                                        </div>
                                    </div>
                                    <div class="col-xxl-3 col-xl-4 col-sm-6">
                                        <div class="">
                                            <label for="motherName" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                                Mother's Name
                                            </label>
                                            <input type="text" class="form-control" id="motherName" name="mother_name" placeholder="Enter mother's name">
                                        </div>
                                    </div>
                                    <div class="col-xxl-3 col-xl-4 col-sm-6">
                                        <div class="">
                                            <label for="motherPhone" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                                Mother's Phone
                                            </label>
                                            <input type="tel" class="form-control" id="motherPhone" name="mother_phone" placeholder="Enter mother's phone">
                                        </div>
                                    </div>
                                    <div class="col-12">
                                        <h6 class="text-md fw-semibold mb-16 mt-8">Primary Guardian Information</h6>
                                    </div>
                                    <div class="col-xxl-3 col-xl-4 col-sm-6">
                                        <div class="">
                                            <label for="guardianName" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                                Guardian Name
                                            </label>
                                            <input type="text" class="form-control" id="guardianName" name="guardian_name" placeholder="Enter guardian name">
                                        </div>
                                    </div>
                                    <div class="col-xxl-3 col-xl-4 col-sm-6">
                                        <div class="">
                                            <label for="guardianRelation" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                                Relation
                                            </label>
                                            <select id="guardianRelation" name="guardian_relation" class="form-control form-select">
                                                <option value="">Select Relation</option>
                                                <option value="father">Father</option>
                                                <option value="mother">Mother</option>
                                                <option value="brother">Brother</option>
                                                <option value="sister">Sister</option>
                                                <option value="uncle">Uncle</option>
                                                <option value="aunt">Aunt</option>
                                                <option value="grandfather">Grandfather</option>
                                                <option value="grandmother">Grandmother</option>
                                                <option value="guardian">Other Guardian</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-xxl-3 col-xl-4 col-sm-6">
                                        <div class="">
                                            <label for="guardianEmail" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                                Guardian Email
                                            </label>
                                            <input type="email" class="form-control" id="guardianEmail" name="guardian_email" placeholder="Enter guardian email">
                                            <small class="text-secondary-light">If left empty, a professional email will be auto-generated</small>
                                        </div>
                                    </div>
                                    <div class="col-xxl-3 col-xl-4 col-sm-6">
                                        <div class="">
                                            <label for="guardianPhone" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                                Guardian Phone
                                            </label>
                                            <input type="tel" class="form-control" id="guardianPhone" name="guardian_phone" placeholder="Enter guardian phone">
                                        </div>
                                    </div>
                                    <div class="col-xl-9">
                                        <div class="">
                                            <label for="guardianAddress" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                                Guardian Address
                                            </label>
                                            <input type="text" class="form-control" id="guardianAddress" name="guardian_address" placeholder="Enter guardian address">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-12">
                        <div class="shadow-1 radius-12 bg-base h-100 overflow-hidden">
                            <div class="card-header border-bottom bg-base py-16 px-24 d-flex align-items-center justify-content-between">
                                <h6 class="text-lg fw-semibold mb-0">Medical Information</h6>
                            </div>
                            <div class="card-body p-20">
                                <div class="row gy-3">
                                    <div class="col-xxl-3 col-xl-4 col-sm-6">
                                        <div class="">
                                            <label for="bloodGroup" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                                Blood Group
                                            </label>
                                            <select id="bloodGroup" name="blood_group" class="form-control form-select">
                                                <option value="">Select Blood Group</option>
                                                <?php foreach ($bloodGroups as $bg): ?>
                                                <option value="<?php echo $bg; ?>"><?php echo $bg; ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-xxl-3 col-xl-4 col-sm-6">
                                        <div class="">
                                            <label for="height" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                                Height (cm)
                                            </label>
                                            <input type="text" class="form-control" id="height" name="height" placeholder="Enter height">
                                        </div>
                                    </div>
                                    <div class="col-xxl-3 col-xl-4 col-sm-6">
                                        <div class="">
                                            <label for="weight" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                                Weight (kg)
                                            </label>
                                            <input type="text" class="form-control" id="weight" name="weight" placeholder="Enter weight">
                                        </div>
                                    </div>
                                    <div class="col-xxl-3 col-xl-4 col-sm-6">
                                        <div class="">
                                            <label for="allergies" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                                Allergies
                                            </label>
                                            <input type="text" class="form-control" id="allergies" name="allergies" placeholder="Enter any allergies">
                                        </div>
                                    </div>
                                    <div class="col-xxl-3 col-xl-4 col-sm-6">
                                        <div class="">
                                            <label for="medicalConditions" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                                Medical Conditions
                                            </label>
                                            <input type="text" class="form-control" id="medicalConditions" name="medical_conditions" placeholder="Enter any medical conditions">
                                        </div>
                                    </div>
                                    <div class="col-xxl-3 col-xl-4 col-sm-6">
                                        <div class="">
                                            <label for="doctorName" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                                Doctor's Name
                                            </label>
                                            <input type="text" class="form-control" id="doctorName" name="doctor_name" placeholder="Enter doctor's name">
                                        </div>
                                    </div>
                                    <div class="col-xxl-3 col-xl-4 col-sm-6">
                                        <div class="">
                                            <label for="doctorPhone" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                                Doctor's Phone
                                            </label>
                                            <input type="tel" class="form-control" id="doctorPhone" name="doctor_phone" placeholder="Enter doctor's phone">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-12">
                        <div class="shadow-1 radius-12 bg-base h-100 overflow-hidden">
                            <div class="card-header border-bottom bg-base py-16 px-24 d-flex align-items-center justify-content-between">
                                <h6 class="text-lg fw-semibold mb-0">Address Information</h6>
                            </div>
                            <div class="card-body p-20">
                                <div class="row gy-3">
                                    <div class="col-sm-6">
                                        <div class="">
                                            <label for="currentAddress" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                                Current Address
                                            </label>
                                            <textarea class="form-control" id="currentAddress" name="current_address" rows="3" placeholder="Enter current address"></textarea>
                                        </div>
                                    </div>
                                    <div class="col-sm-6">
                                        <div class="">
                                            <label for="permanentAddress" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                                Permanent Address
                                            </label>
                                            <textarea class="form-control" id="permanentAddress" name="permanent_address" rows="3" placeholder="Enter permanent address"></textarea>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-12">
                        <div class="shadow-1 radius-12 bg-base h-100 overflow-hidden">
                            <div class="card-header border-bottom bg-base py-16 px-24 d-flex align-items-center justify-content-between">
                                <h6 class="text-lg fw-semibold mb-0">Previous School Information</h6>
                            </div>
                            <div class="card-body p-20">
                                <div class="row gy-3">
                                    <div class="col-sm-6">
                                        <div class="">
                                            <label for="previousSchool" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                                Previous School Name
                                            </label>
                                            <input type="text" class="form-control" id="previousSchool" name="previous_school" placeholder="Enter previous school name">
                                        </div>
                                    </div>
                                    <div class="col-sm-6">
                                        <div class="">
                                            <label for="previousClass" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                                Previous Class
                                            </label>
                                            <input type="text" class="form-control" id="previousClass" name="previous_class" placeholder="Enter previous class">
                                        </div>
                                    </div>
                                    <div class="col-sm-6">
                                        <div class="">
                                            <label for="transferCertificate" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                                Transfer Certificate No.
                                            </label>
                                            <input type="text" class="form-control" id="transferCertificate" name="transfer_certificate_no" placeholder="Enter transfer certificate number">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-12">
                        <div class="shadow-1 radius-12 bg-base h-100 overflow-hidden">
                            <div class="card-header border-bottom bg-base py-16 px-24 d-flex align-items-center justify-content-between">
                                <h6 class="text-lg fw-semibold mb-0">Bank Details</h6>
                            </div>
                            <div class="card-body p-20">
                                <div class="row gy-3">
                                    <div class="col-xxl-3 col-xl-4 col-sm-6">
                                        <div class="">
                                            <label for="bankName" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                                Bank Name
                                            </label>
                                            <input type="text" class="form-control" id="bankName" name="bank_name" placeholder="Enter bank name">
                                        </div>
                                    </div>
                                    <div class="col-xxl-3 col-xl-4 col-sm-6">
                                        <div class="">
                                            <label for="bankAccount" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                                Account Number
                                            </label>
                                            <input type="text" class="form-control" id="bankAccount" name="bank_account" placeholder="Enter account number">
                                        </div>
                                    </div>
                                    <div class="col-xxl-3 col-xl-4 col-sm-6">
                                        <div class="">
                                            <label for="ifscCode" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                                IFSC Code
                                            </label>
                                            <input type="text" class="form-control" id="ifscCode" name="ifsc_code" placeholder="Enter IFSC code">
                                        </div>
                                    </div>
                                    <div class="col-xxl-3 col-xl-4 col-sm-6">
                                        <div class="">
                                            <label for="nationalId" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                                National ID / Aadhaar
                                            </label>
                                            <input type="text" class="form-control" id="nationalId" name="national_id" placeholder="Enter national ID">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-12">
                        <div class="shadow-1 radius-12 bg-base h-100 overflow-hidden">
                            <div class="card-header border-bottom bg-base py-16 px-24 d-flex align-items-center justify-content-between">
                                <h6 class="text-lg fw-semibold mb-0">Additional Information</h6>
                            </div>
                            <div class="card-body p-20">
                                <div class="row gy-3">
                                    <div class="col-sm-12">
                                        <div class="">
                                            <label for="moreDetails" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                                Additional Details
                                            </label>
                                            <textarea id="moreDetails" name="more_details" class="form-control" rows="4" placeholder="Enter any additional information about the student"></textarea>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-12">
                        <div class="d-flex align-items-center justify-content-center gap-3 mt-8">
                            <button type="reset" class="border border-danger-600 bg-hover-danger-200 text-danger-600 text-md px-50 py-11 radius-8">
                                Cancel
                            </button>
                            <button type="submit" name="submit" class="btn btn-primary-600 border border-primary-600 text-md px-28 py-12 radius-8">
                                Add Student
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </div>

        <footer class="d-footer">
            <div class="">
                <p class="mb-0 text-center"> &copy; <span class="current-year"></span> <?php echo htmlspecialchars($school['name']); ?> | Made With ❤️ by AcademixSuite.</p>
            </div>
        </footer>
    </main>

    <script src="https://academixsuite.com/tenant/assets/js/lib/jquery-3.7.1.min.js"></script>
    <script src="https://academixsuite.com/tenant/assets/js/lib/bootstrap.bundle.min.js"></script>
    <script src="https://academixsuite.com/tenant/assets/js/lib/apexcharts.min.js"></script>
    <script src="https://academixsuite.com/tenant/assets/js/lib/iconify-icon.min.js"></script>
    <script src="https://academixsuite.com/tenant/assets/js/lib/dataTables.min.js"></script>
    <script src="https://academixsuite.com/tenant/assets/js/lib/jquery-ui.min.js"></script>
    <script src="https://academixsuite.com/tenant/assets/js/app.js"></script>

    <script>
        $(document).ready(function() {
            // Load sections when class changes
            $('#classSelection').on('change', function() {
                var classId = $(this).val();
                if (classId) {
                    $.get(window.location.pathname, {
                        ajax: 'get_sections',
                        class_id: classId
                    }, function(response) {
                        if (response.success) {
                            var options = '<option value="">Select Section</option>';
                            $.each(response.sections, function(index, section) {
                                options += '<option value="' + section.id + '">' + section.name + 
                                         (section.capacity ? ' (Capacity: ' + section.capacity + ')' : '') + '</option>';
                            });
                            $('#section').html(options);
                        }
                    }, 'json');
                } else {
                    $('#section').html('<option value="">Select Section</option>');
                }
            });

            // Parent search functionality
            let searchTimeout;
            $('#parentSearch').on('input', function() {
                clearTimeout(searchTimeout);
                var term = $(this).val();
                
                if (term.length < 3) {
                    $('#parentSearchResults').hide();
                    return;
                }

                searchTimeout = setTimeout(function() {
                    $.get(window.location.pathname, {
                        ajax: 'search_parents',
                        term: term
                    }, function(response) {
                        if (response.success && response.parents.length > 0) {
                            var html = '';
                            $.each(response.parents, function(index, parent) {
                                html += '<a href="#" class="list-group-item list-group-item-action parent-result" ' +
                                       'data-id="' + parent.id + '" ' +
                                       'data-name="' + parent.name + '" ' +
                                       'data-email="' + (parent.email || '') + '" ' +
                                       'data-phone="' + (parent.phone || '') + '">' +
                                       '<strong>' + parent.name + '</strong><br>' +
                                       '<small>Email: ' + (parent.email || 'N/A') + ' | Phone: ' + (parent.phone || 'N/A') + '</small>' +
                                       '<br><small>Linked Students: ' + parent.linked_students + '</small>' +
                                       '</a>';
                            });
                            $('#parentSearchResults').html(html).show();
                        } else {
                            $('#parentSearchResults').html('<div class="list-group-item">No parents found</div>').show();
                        }
                    }, 'json');
                }, 500);
            });

            // Select parent from search results
            $(document).on('click', '.parent-result', function(e) {
                e.preventDefault();
                var parentId = $(this).data('id');
                var parentName = $(this).data('name');
                
                $('#parentSearch').val(parentName);
                $('#selectedParentId').val(parentId);
                $('#parentSearchResults').hide();
                
                // Optional: disable guardian fields if parent selected
                $('.guardian-fields input, .guardian-fields select').prop('disabled', true);
            });

            // Clear parent selection
            $('#clearParentSearch').on('click', function() {
                $('#parentSearch').val('');
                $('#selectedParentId').val('');
                $('#parentSearchResults').hide();
                $('.guardian-fields input, .guardian-fields select').prop('disabled', false);
            });

            // Auto-dismiss alerts after 5 seconds
            setTimeout(function() {
                $('.alert').fadeOut('slow');
            }, 5000);
        });
    </script>
</body>
</html>