<?php
/**
 * School Portal Login - Professional Version
 * Adapted for the new template structure
 */

// Enable error reporting (only for development)
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/login.log');

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_name('academix_tenant');
    require_once __DIR__ . '/../includes/session_config.php';
    session_start(academix_session_options());
}

// Handle logout
if (isset($_GET['logout'])) {
    $autoloadPathForLogout = __DIR__ . '/../includes/autoload.php';
    if (file_exists($autoloadPathForLogout)) {
        require_once $autoloadPathForLogout;
    }
    session_destroy();
    setcookie(session_name(), '', time() - 3600, '/');
    $logoutSchoolSlug = $_GET['school_slug'] ?? '';
    header('Location: ' . (function_exists('school_login_url') ? school_login_url($logoutSchoolSlug, false) : './login.php'));
    exit;
}

// Load configuration
$autoloadPath = __DIR__ . '/../includes/autoload.php';
if (!file_exists($autoloadPath)) {
    die("System configuration error. Please contact administrator.");
}

require_once $autoloadPath;

// Initialize variables
$error = '';
$schoolSlug = trim((string)($_GET['school_slug'] ?? (function_exists('school_subdomain_slug') ? school_subdomain_slug() : '')), '/');
$school = null;
$schools = [];

if (function_exists('redirect_legacy_school_url_to_subdomain')) {
    redirect_legacy_school_url_to_subdomain($schoolSlug, 'login.php');
}

// Check for existing session
if (isset($_SESSION['school_auth']) && !empty($_SESSION['school_auth']['school_slug'])) {
    $userType = $_SESSION['school_auth']['user_type'] ?? 'admin';
    $redirectUrl = function_exists('school_route_url')
        ? school_route_url($_SESSION['school_auth']['school_slug'], $userType, 'dashboard.php', false)
        : "./{$_SESSION['school_auth']['school_slug']}/{$userType}/dashboard.php";
    header("Location: {$redirectUrl}");
    exit;
}

// Get school information if slug provided
if (!empty($schoolSlug)) {
    try {
        $db = Database::getPlatformConnection();
        $stmt = $db->prepare("
            SELECT s.*, p.name as plan_name 
            FROM schools s 
            LEFT JOIN plans p ON s.plan_id = p.id 
            WHERE s.slug = ? AND s.status IN ('active', 'trial')
            LIMIT 1
        ");
        
        if ($stmt) {
            $stmt->execute([$schoolSlug]);
            $school = $stmt->fetch();
        }
    } catch (Exception $e) {
        error_log("Platform database error: " . $e->getMessage());
    }
}

// Get schools for school selection
try {
    $db = Database::getPlatformConnection();
    $schools = $db->query("
        SELECT id, name, slug, logo_path 
        FROM schools 
        WHERE status IN ('active', 'trial') 
        ORDER BY name
    ")->fetchAll();
} catch (Exception $e) {
    error_log("Error fetching schools: " . $e->getMessage());
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postSchoolSlug = trim($_POST['school_slug'] ?? $schoolSlug);
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $userType = $_POST['user_type'] ?? 'admin';
    $staffUserTypes = ['accountant', 'librarian', 'receptionist'];
    
    // Validate inputs
    if (empty($postSchoolSlug) || empty($username) || empty($password) || empty($userType)) {
        $error = 'Please fill in all required fields';
    } else {
        // Get school data
        try {
            $db = Database::getPlatformConnection();
            $stmt = $db->prepare("
                SELECT s.*, p.name as plan_name 
                FROM schools s 
                LEFT JOIN plans p ON s.plan_id = p.id 
                WHERE s.slug = ? AND s.status IN ('active', 'trial')
                LIMIT 1
            ");
            $stmt->execute([$postSchoolSlug]);
            $school = $stmt->fetch();
            
            if (!$school) {
                $error = 'School not found or inactive';
            } else {
                // Connect to school database
                try {
                    $schoolDb = Database::getSchoolConnection($school['database_name']);
                    
                    // Get users table structure
                    $columns = $schoolDb->query("DESCRIBE users")->fetchAll();
                    $columnNames = array_column($columns, 'Field');
                    
                    // Build WHERE clause
                    $conditions = [];
                    $params = [];
                    
                    if (in_array('email', $columnNames)) {
                        $conditions[] = "email = ?";
                        $params[] = $username;
                    }
                    
                    if (in_array('username', $columnNames)) {
                        $conditions[] = "username = ?";
                        $params[] = $username;
                    }
                    
                    if (in_array('phone', $columnNames)) {
                        $conditions[] = "phone = ?";
                        $params[] = $username;
                    }
                    
                    if (in_array('admission_number', $columnNames)) {
                        $conditions[] = "admission_number = ?";
                        $params[] = $username;
                    }
                    
                    if (in_array('staff_id', $columnNames)) {
                        $conditions[] = "staff_id = ?";
                        $params[] = $username;
                    }
                    
                    if (empty($conditions)) {
                        $error = 'System configuration error';
                    } else {
                        $whereClause = implode(' OR ', $conditions);
                        $query = "SELECT * FROM users WHERE ($whereClause)";
                        
                        if (in_array('school_id', $columnNames)) {
                            $query .= " AND school_id = ?";
                            $params[] = $school['id'];
                        }
                        
                        if (in_array('user_type', $columnNames)) {
                            if ($userType === 'staff') {
                                $query .= " AND user_type IN (" . implode(',', array_fill(0, count($staffUserTypes), '?')) . ")";
                                $params = array_merge($params, $staffUserTypes);
                            } elseif ($userType !== 'admin') {
                                $query .= " AND user_type = ?";
                                $params[] = $userType;
                            }
                        }
                        
                        if (in_array('is_active', $columnNames)) {
                            $query .= " AND is_active = 1";
                        } elseif (in_array('status', $columnNames)) {
                            $query .= " AND status = 'active'";
                        }
                        
                        $query .= " LIMIT 1";
                        
                        $stmt = $schoolDb->prepare($query);
                        $stmt->execute($params);
                        $user = $stmt->fetch();
                        
                        if ($user) {
                            $passwordHash = $user['password'];
                            $authenticated = false;
                            
                            if (password_verify($password, $passwordHash)) {
                                $authenticated = true;
                            } elseif (strlen($passwordHash) === 32 && ctype_xdigit($passwordHash) && md5($password) === $passwordHash) {
                                $authenticated = true;
                            }
                            
                            if ($authenticated) {
                                $dbUserType = $user['user_type'] ?? 'admin';
                                $typeMatches = $userType === 'staff'
                                    ? in_array($dbUserType, $staffUserTypes, true)
                                    : $userType === $dbUserType;
                                if (!$typeMatches) {
                                    $error = "Access denied. Your account type is: " . ucfirst($dbUserType);
                                } else {
                                    $sessionUserType = $userType === 'staff' ? 'staff' : $userType;
                                    session_regenerate_id(true);

                                    if (password_needs_rehash($passwordHash, PASSWORD_DEFAULT)) {
                                        $rehashStmt = $schoolDb->prepare("UPDATE users SET password = ? WHERE id = ?");
                                        $rehashStmt->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
                                    }

                                    // Get user role
                                    $roleName = 'Administrator';
                                    if (in_array('user_roles', $schoolDb->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN, 0))) {
                                        try {
                                            $roleStmt = $schoolDb->prepare("
                                                SELECT r.name 
                                                FROM user_roles ur 
                                                JOIN roles r ON ur.role_id = r.id 
                                                WHERE ur.user_id = ? 
                                                LIMIT 1
                                            ");
                                            $roleStmt->execute([$user['id']]);
                                            $role = $roleStmt->fetch();
                                            if ($role) {
                                                $roleName = $role['name'];
                                            }
                                        } catch (Exception $e) {}
                                    }
                                    
                                    // Set session
                                    $_SESSION['school_auth'] = [
                                        'school_id' => $school['id'],
                                        'school_slug' => $school['slug'],
                                        'school_name' => $school['name'],
                                        'database_name' => $school['database_name'],
                                        'user_id' => $user['id'],
                                        'user_name' => $user['name'] ?? ($user['username'] ?? 'User'),
                                        'user_email' => $user['email'] ?? '',
                                        'user_type' => $sessionUserType,
                                        'staff_role' => in_array($dbUserType, $staffUserTypes, true) ? $dbUserType : null,
                                        'role_name' => $roleName,
                                        'login_time' => time(),
                                        'login_ip' => $_SERVER['REMOTE_ADDR'] ?? ''
                                    ];
                                    
                                    // Update last login
                                    if (in_array('last_login_at', $columnNames)) {
                                        $updateStmt = $schoolDb->prepare("UPDATE users SET last_login_at = NOW(), last_login_ip = ? WHERE id = ?");
                                        $updateStmt->execute([$_SERVER['REMOTE_ADDR'] ?? '', $user['id']]);
                                    }
                                    
                                    // Redirect to dashboard
                                    $redirectUrl = function_exists('school_route_url')
                                        ? school_route_url($school['slug'], $sessionUserType, 'dashboard.php', false)
                                        : "./{$school['slug']}/{$sessionUserType}/dashboard.php";
                                    header("Location: {$redirectUrl}");
                                    exit;
                                }
                            } else {
                                $error = 'Invalid credentials';
                            }
                        } else {
                            $error = 'Invalid credentials';
                        }
                    }
                    
                } catch (Exception $e) {
                    $error = 'System authentication error';
                    error_log("School database error: " . $e->getMessage());
                }
            }
        } catch (Exception $e) {
            $error = 'System error occurred';
            error_log("Platform database error: " . $e->getMessage());
        }
    }
}

// Get current user type for UI
$selectedUserType = $_POST['user_type'] ?? 'admin';
$tenantLoginLogoUrl = function_exists('school_logo_url')
    ? school_logo_url($school)
    : (!empty($school['logo_path']) ? '/' . ltrim((string) $school['logo_path'], '/') : '/tenant/assets/images/logo.png');
$tenantLoginLogoAlt = !empty($school['name']) ? ($school['name'] . ' logo') : 'AcademixSuite';
$tenantLoginTitle = !empty($school['name']) ? $school['name'] : 'School Portal';
?>
<!-- meta tags and other links -->
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
    <title><?php echo htmlspecialchars($tenantLoginTitle); ?> Login | <?php echo defined('APP_NAME') ? APP_NAME : 'AcademixSuite'; ?></title>
    <link rel="icon" type="image/png" href="<?php echo htmlspecialchars($tenantLoginLogoUrl); ?>" sizes="16x16">
    <!-- remix icon font css  -->
    <link rel="stylesheet" href="assets/css/remixicon.css">
    <!-- BootStrap css -->
    <link rel="stylesheet" href="assets/css/lib/bootstrap.min.css">
    <!-- Apex Chart css -->
    <link rel="stylesheet" href="assets/css/lib/apexcharts.css">
    <!-- Data Table css -->
    <link rel="stylesheet" href="assets/css/lib/dataTables.min.css">
    <!-- Date picker css -->
    <link rel="stylesheet" href="assets/css/lib/flatpickr.min.css">
    <!-- Calendar css -->
    <link rel="stylesheet" href="assets/css/lib/full-calendar.css">
    <!-- calendar -->
    <link rel="stylesheet" href="assets/css/lib/calendar.css">
    <!-- main css -->
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .school-select-dropdown {
            position: relative;
        }
        .school-suggestions {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 0.5rem;
            margin-top: 0.25rem;
            max-height: 200px;
            overflow-y: auto;
            z-index: 1050;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
        }
        .school-suggestion-item {
            padding: 0.75rem 1rem;
            cursor: pointer;
            transition: background-color 0.2s;
        }
        .school-suggestion-item:hover {
            background-color: #f3f4f6;
        }
        .school-suggestion-item.hover {
            background-color: #f3f4f6;
        }
        .user-type-btn {
            transition: all 0.3s ease;
            border: 2px solid transparent;
        }
        .user-type-btn.active {
            border-color: var(--bs-primary);
            background: linear-gradient(135deg, #f8fafc, #f1f5f9);
            box-shadow: 0 4px 6px -1px rgba(79, 70, 229, 0.1);
        }
        .password-toggle {
            cursor: pointer;
        }
    </style>
</head>

<body>

    <!-- Theme Customization Structure Start -->
    

    
    <div class="theme-customization-sidebar w-100 bg-base h-100vh overflow-y-auto position-fixed end-0 top-0">
        <div class="d-flex align-items-center gap-3 py-16 px-24 justify-content-between border-bottom">
            <div>
                <h6 class="text-sm dark:text-white">Theme Settings</h6>
                <p class="text-xs mb-0 text-neutral-500 dark:text-neutral-200">Customize and preview instantly</p>
            </div>
            <button data-slot="button"
                class="theme-customization-sidebar__close text-neutral-900 bg-transparent text-hover-primary-600 d-flex text-xl">
                <i class="ri-close-fill"></i>
            </button>
        </div>

        <div class="d-flex flex-column gap-48 p-24 overflow-y-auto flex-grow-1">

            <div class="theme-setting-item">
                <h6 class="fw-medium text-primary-light text-md mb-3">Theme Mode</h6>
                <div class="d-grid grid-cols-3 gap-3 dark-light-mode">
                    <button type="button"
                        class="theme-btn theme-setting-item__btn d-flex align-items-center justify-content-center h-64-px rounded-3 text-xl active"
                        data-theme="light" aria-label="light">
                        <i class="ri-sun-line"></i>
                    </button>
                    <button type="button"
                        class="theme-btn theme-setting-item__btn d-flex align-items-center justify-content-center h-64-px rounded-3 text-xl"
                        data-theme="dark" aria-label="dark">
                        <i class="ri-moon-line"></i>
                    </button>
                    <button type="button"
                        class="theme-btn theme-setting-item__btn d-flex align-items-center justify-content-center h-64-px rounded-3 text-xl"
                        data-theme="system" aria-label="system">
                        <i class="ri-computer-line"></i>
                    </button>
                </div>
            </div>


            <div class="theme-setting-item">
                <h6 class="fw-medium text-primary-light text-md mb-3">Color Schema</h6>
                <div class="d-grid grid-cols-3 gap-3">
                    <button type="button"
                        class="color-picker-btn d-flex flex-column justify-content-center align-items-center"
                        data-color="base" aria-label="Base">
                        <span class="color-picker-btn__box h-40-px w-100 rounded-3"
                            style="background-color: #25A194;"></span>
                        <span class="fw-medium mt-1" style="color: #25A194;">Base</span>
                    </button>
                    <button type="button"
                        class="color-picker-btn d-flex flex-column justify-content-center align-items-center"
                        data-color="red" aria-label="Red">
                        <span class="color-picker-btn__box h-40-px w-100 rounded-3"
                            style="background-color: #dc2626;"></span>
                        <span class="fw-medium mt-1" style="color: #dc2626;">Red</span>
                    </button>
                    <button type="button"
                        class="color-picker-btn d-flex flex-column justify-content-center align-items-center"
                        data-color="blue" aria-label="Blue">
                        <span class="color-picker-btn__box h-40-px w-100 rounded-3"
                            style="background-color: #2563eb;"></span>
                        <span class="fw-medium mt-1" style="color: #2563eb;">Blue</span>
                    </button>
                </div>
            </div>

        </div>
    </div>
    <!-- Theme Customization Structure End -->

    <div class="overlay bg-black bg-opacity-50 w-100 h-100 position-fixed z-9 visibility-hidden opacity-0 duration-300"></div>

    <div class="d-lg-flex bg-white">
        <div class="w-50 d-lg-flex d-none overflow-hidden">
            <img src="https://academixsuite.com/tenant/assets/images/thumbs/login-img.png" alt="Login Image" class="w-100 h-100 object-fit-cover">
        </div>
        <div class="lg-w-50 px-24 py-32 d-flex justify-content-center align-items-center">
            <div class="max-w-540-px mx-auto">
                <a href="./" class="">
                    <img src="<?php echo htmlspecialchars($tenantLoginLogoUrl); ?>" alt="<?php echo htmlspecialchars($tenantLoginLogoAlt); ?>" width="150">
                </a>
                <div class="mt-32 mb-32">
                    <h1 class="h6 fw-bold text-primary-light mb-8">
                        Welcome Back 👋
                    </h1>
                    <p class="text-sm text-secondary-light mb-0">
                        Log in to your school portal to continue
                    </p>
                </div>

                <?php if ($error): ?>
                <div class="alert alert-danger d-flex align-items-center mb-4" role="alert">
                    <i class="ri-error-warning-line me-2"></i>
                    <div><?php echo htmlspecialchars($error); ?></div>
                </div>
                <?php endif; ?>

                <form action="" method="POST" class="d-flex flex-column gap-32 submit-form" id="loginForm">
                    <input type="hidden" name="school_slug" id="schoolSlug" value="<?php echo isset($_POST['school_slug']) ? htmlspecialchars($_POST['school_slug']) : htmlspecialchars($schoolSlug); ?>">
                    
                    <div class="d-flex flex-column gap-16">
                        <!-- School Selection -->
                        <div class="school-select-dropdown">
                            <label for="schoolInput" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                School
                                <span class="text-danger-600">*</span>
                            </label>
                            <input type="text" 
                                   id="schoolInput" 
                                   class="form-control" 
                                   placeholder="Search for your school..."
                                   autocomplete="off"
                                   value="<?php 
                                        $schoolName = '';
                                        if (!empty($_POST['school_slug']) && !empty($schools)) {
                                            foreach ($schools as $s) {
                                                if ($s['slug'] === $_POST['school_slug']) {
                                                    $schoolName = $s['name'];
                                                    break;
                                                }
                                            }
                                        } elseif (!empty($schoolSlug) && !empty($schools)) {
                                            foreach ($schools as $s) {
                                                if ($s['slug'] === $schoolSlug) {
                                                    $schoolName = $s['name'];
                                                    break;
                                                }
                                            }
                                        }
                                        echo htmlspecialchars($schoolName);
                                   ?>">
                            <div id="schoolSuggestions" class="school-suggestions d-none"></div>
                        </div>

                        <!-- User Type Selection -->
                        <div>
                            <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                Login As
                                <span class="text-danger-600">*</span>
                            </label>
                            <div class="d-grid sm-grid-cols-3 grid-cols-2 gap-16">
                                
                                <button type="button" class="user-type-btn <?php echo $selectedUserType === 'admin' ? 'active' : ''; ?>" data-type="admin">
                                    <span class="d-flex align-items-center gap-8 fw-semibold text-sm radius-6 justify-content-center flex-grow-1 bg-info-600 text-white py-10 px-8">
                                        <span class="d-flex">
                                            <img src="https://academixsuite.com/tenant/assets/images/icons/dashboard-icon.png" alt="Icon">
                                        </span>
                                        <span>Admin</span>
                                    </span>
                                    <input type="radio" name="user_type" value="admin" <?php echo $selectedUserType === 'admin' ? 'checked' : ''; ?> class="d-none">
                                </button>

                                <button type="button" class="user-type-btn <?php echo $selectedUserType === 'student' ? 'active' : ''; ?>" data-type="student">
                                    <span class="d-flex align-items-center gap-8 fw-semibold text-sm radius-6 justify-content-center flex-grow-1 bg-warning-600 text-white py-10 px-8">
                                        <span class="d-flex">
                                            <img src="https://academixsuite.com/tenant/assets/images/icons/student-icon.png" alt="Icon">
                                        </span>
                                        <span>Student</span>
                                    </span>
                                    <input type="radio" name="user_type" value="student" <?php echo $selectedUserType === 'student' ? 'checked' : ''; ?> class="d-none">
                                </button>

                                <button type="button" class="user-type-btn <?php echo $selectedUserType === 'teacher' ? 'active' : ''; ?>" data-type="teacher">
                                    <span class="d-flex align-items-center gap-8 fw-semibold text-sm radius-6 justify-content-center flex-grow-1 bg-purple-600 text-white py-10 px-8">
                                        <span class="d-flex">
                                            <img src="https://academixsuite.com/tenant/assets/images/icons/teacher-icon.png" alt="Icon">
                                        </span>
                                        <span>Teacher</span>
                                    </span>
                                    <input type="radio" name="user_type" value="teacher" <?php echo $selectedUserType === 'teacher' ? 'checked' : ''; ?> class="d-none">
                                </button>

                                <button type="button" class="user-type-btn <?php echo $selectedUserType === 'parent' ? 'active' : ''; ?>" data-type="parent">
                                    <span class="d-flex align-items-center gap-8 fw-semibold text-sm radius-6 justify-content-center flex-grow-1 bg-primary-600 text-white py-10 px-8">
                                        <span class="d-flex">
                                            <img src="https://academixsuite.com/tenant/assets/images/icons/guardian-icon.png" alt="Icon">
                                        </span>
                                        <span>Guardian</span>
                                    </span>
                                    <input type="radio" name="user_type" value="parent" <?php echo $selectedUserType === 'parent' ? 'checked' : ''; ?> class="d-none">
                                </button>

                                <button type="button" class="user-type-btn <?php echo $selectedUserType === 'staff' ? 'active' : ''; ?>" data-type="staff">
                                    <span class="d-flex align-items-center gap-8 fw-semibold text-sm radius-6 justify-content-center flex-grow-1 bg-pink text-white py-10 px-8">
                                        <span class="d-flex">
                                            <img src="https://academixsuite.com/tenant/assets/images/icons/library-icon.png" alt="Icon">
                                        </span>
                                        <span>Staff</span>
                                    </span>
                                    <input type="radio" name="user_type" value="staff" <?php echo $selectedUserType === 'staff' ? 'checked' : ''; ?> class="d-none">
                                </button>
                            </div>
                        </div>

                        <!-- Username/Email/ID -->
                        <div>
                            <label for="username" class="text-sm fw-semibold text-primary-light d-inline-block mb-8" id="usernameLabel">
                                Email Address
                                <span class="text-danger-600">*</span>
                            </label>
                            <input type="text" id="username" name="username" class="form-control" 
                                   placeholder="Enter your email address"
                                   value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>" >
                        </div>

                        <!-- Password -->
                        <div>
                            <label for="password" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                Password
                                <span class="text-danger-600">*</span>
                            </label>
                            <div class="position-relative">
                                <input type="password" id="password" name="password" class="password-field form-control" 
                                       placeholder="Enter your password" >
                                <button type="button"
                                    class="toggle-password btn p-0 border-0 bg-transparent position-absolute end-0 top-50 translate-middle-y me-16 text-secondary-light cursor-pointer ri-eye-line"
                                    data-toggle="#password" aria-label="Toggle password visibility">
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex justify-content-between gap-2">
                        <div class="form-check style-check d-flex align-items-center">
                            <input class="form-check-input border border-neutral-400" type="checkbox" value="" id="remeber">
                            <label class="form-check-label" for="remeber">Remember me</label>
                        </div>
                        <a href="forgot-password.php" class="text-primary-600 fw-medium text-decoration-underline">Forgot Password?</a>
                    </div>

                    <div>
                        <button type="submit" class="loginBtn btn btn-primary-600 text-sm btn-sm px-12 py-16 w-100 radius-8">
                            Log In
                        </button>
                    </div>

                    <div class="text-center text-sm text-secondary-light">
                        <i class="ri-shield-check-line me-1"></i> School-level authentication. Each institution has isolated data.
                    </div>
                </form>

                <div class="mt-32 text-center text-sm">
                    Don't have an account?
                    <a href="/register" class="text-primary-600 fw-semibold text-decoration-underline">
                        Create an account
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- School Data for JavaScript -->
    <script id="schoolsData" type="application/json">
        <?php echo json_encode($schools); ?>
    </script>

    <!-- jQuery library js -->
    <script src="assets/js/lib/jquery-3.7.1.min.js"></script>
    <!-- Bootstrap js -->
    <script src="assets/js/lib/bootstrap.bundle.min.js"></script>
    <!-- Apex Chart js -->
    <script src="assets/js/lib/apexcharts.min.js"></script>
    <!-- Iconify Font js -->
    <script src="assets/js/lib/iconify-icon.min.js"></script>
    <!-- Data Table js -->
    <script src="assets/js/lib/dataTables.min.js"></script>
    
    <!-- jQuery UI js -->
    <script src="assets/js/lib/jquery-ui.min.js"></script>
    
    <!-- main js -->
    <script src="assets/js/app.js"></script>

    <script>
        $(document).ready(function() {
            // School suggestions functionality
            const schoolsData = JSON.parse(document.getElementById('schoolsData').textContent);
            const schoolInput = document.getElementById('schoolInput');
            const schoolSlug = document.getElementById('schoolSlug');
            const suggestionsContainer = document.getElementById('schoolSuggestions');
            
            // Filter schools based on input
            function filterSchools(query) {
                if (!query.trim()) return [];
                
                const lowerQuery = query.toLowerCase();
                return schoolsData.filter(school => 
                    school.name.toLowerCase().includes(lowerQuery) || 
                    school.slug.toLowerCase().includes(lowerQuery)
                ).slice(0, 5);
            }
            
            // Show suggestions
            function showSuggestions(schools) {
                suggestionsContainer.innerHTML = '';
                
                if (schools.length === 0) {
                    suggestionsContainer.innerHTML = `
                        <div class="school-suggestion-item text-center text-gray-500">
                            <i class="ri-search-line me-2"></i>No schools found
                        </div>
                    `;
                    suggestionsContainer.classList.remove('d-none');
                    return;
                }
                
                schools.forEach(school => {
                    const div = document.createElement('div');
                    div.className = 'school-suggestion-item';
                    div.innerHTML = `
                        <div class="fw-medium">${school.name}</div>
                        <div class="text-xs text-secondary">${school.slug}</div>
                    `;
                    div.addEventListener('click', () => {
                        schoolInput.value = school.name;
                        schoolSlug.value = school.slug;
                        suggestionsContainer.classList.add('d-none');
                        updateUsernamePlaceholder();
                    });
                    suggestionsContainer.appendChild(div);
                });
                
                suggestionsContainer.classList.remove('d-none');
            }
            
            // Hide suggestions when clicking outside
            $(document).on('click', function(e) {
                if (!$(e.target).closest('.school-select-dropdown').length) {
                    suggestionsContainer.classList.add('d-none');
                }
            });
            
            // Handle school input
            schoolInput.addEventListener('input', (e) => {
                const query = e.target.value.trim();
                
                if (query.length < 2) {
                    suggestionsContainer.classList.add('d-none');
                    return;
                }
                
                const filteredSchools = filterSchools(query);
                showSuggestions(filteredSchools);
            });
            
            schoolInput.addEventListener('focus', () => {
                const query = schoolInput.value.trim();
                if (query.length >= 2) {
                    const filteredSchools = filterSchools(query);
                    showSuggestions(filteredSchools);
                }
            });
            
            // User type selection
            $('.user-type-btn').on('click', function() {
                $('.user-type-btn').removeClass('active');
                $(this).addClass('active');
                $(this).find('input[type="radio"]').prop('checked', true);
                updateUsernamePlaceholder();
            });
            
            // Update username placeholder based on user type
            function updateUsernamePlaceholder() {
                const userType = $('input[name="user_type"]:checked').val();
                const usernameLabel = $('#usernameLabel');
                const usernameInput = $('#username');
                
                let labelText = 'Email Address';
                let placeholder = 'Enter your email address';
                
                switch (userType) {
                    case 'student':
                        labelText = 'Admission Number / Email';
                        placeholder = 'Enter admission number or email';
                        break;
                    case 'parent':
                        labelText = 'Phone / Email';
                        placeholder = 'Enter phone number or email';
                        break;
                    case 'teacher':
                        labelText = 'Staff ID / Email';
                        placeholder = 'Enter staff ID or email';
                        break;
                    case 'staff':
                        labelText = 'Employee ID / Email';
                        placeholder = 'Enter employee ID or email';
                        break;
                }
                
                usernameLabel.html(labelText + ' <span class="text-danger-600">*</span>');
                usernameInput.attr('placeholder', placeholder);
            }
            
            // Password toggle
            $('.toggle-password').on('click', function() {
                const passwordInput = $('#password');
                const icon = $(this);
                
                if (passwordInput.attr('type') === 'password') {
                    passwordInput.attr('type', 'text');
                    icon.removeClass('ri-eye-line').addClass('ri-eye-off-line');
                } else {
                    passwordInput.attr('type', 'password');
                    icon.removeClass('ri-eye-off-line').addClass('ri-eye-line');
                }
            });
            
            // Form submission loading state
            $('#loginForm').on('submit', function() {
                const btn = $('.loginBtn');
                btn.html('<i class="ri-loader-4-line animate-spin me-2"></i>Authenticating...');
                btn.prop('disabled', true);
            });
            
            // Keyboard navigation for suggestions
            schoolInput.addEventListener('keydown', (e) => {
                const suggestions = suggestionsContainer.querySelectorAll('.school-suggestion-item');
                const currentFocus = suggestionsContainer.querySelector('.school-suggestion-item.hover');
                
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    if (!currentFocus && suggestions.length > 0) {
                        suggestions[0].classList.add('hover');
                    } else if (currentFocus) {
                        const index = Array.from(suggestions).indexOf(currentFocus);
                        currentFocus.classList.remove('hover');
                        if (index < suggestions.length - 1) {
                            suggestions[index + 1].classList.add('hover');
                        } else {
                            suggestions[0].classList.add('hover');
                        }
                    }
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    if (!currentFocus && suggestions.length > 0) {
                        suggestions[suggestions.length - 1].classList.add('hover');
                    } else if (currentFocus) {
                        const index = Array.from(suggestions).indexOf(currentFocus);
                        currentFocus.classList.remove('hover');
                        if (index > 0) {
                            suggestions[index - 1].classList.add('hover');
                        } else {
                            suggestions[suggestions.length - 1].classList.add('hover');
                        }
                    }
                } else if (e.key === 'Enter' && currentFocus) {
                    e.preventDefault();
                    currentFocus.click();
                }
            });
            
            // Auto-focus school input
            setTimeout(() => {
                schoolInput.focus();
            }, 300);
            
            // Prevent form resubmission on refresh
            if (window.history.replaceState) {
                window.history.replaceState(null, null, window.location.href);
            }
        });
    </script>

</body>
</html>
