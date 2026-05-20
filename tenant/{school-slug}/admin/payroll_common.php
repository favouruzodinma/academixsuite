<?php
/**
 * Common setup for all payroll pages
 */

// Error reporting (disable in production)
ini_set('display_errors', 1);
ini_set('log_errors', 1);

// Define constants if not defined
defined('APP_NAME') or define('APP_NAME', 'AcademixSuite');

// Start session safely
if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_lifetime' => 86400,
        'read_and_close'  => false,
    ]);
}

// Get school slug from GLOBALS (set by router.php)
$schoolSlug = $GLOBALS['SCHOOL_SLUG'] ?? '';
$userType = $GLOBALS['USER_TYPE'] ?? 'admin';
$schoolData = $GLOBALS['SCHOOL_DATA'] ?? [];

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
    if (($_SESSION['school_auth']['school_slug'] ?? '') === $schoolSlug) {
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
$userId = (int)($schoolAuth['user_id'] ?? 0);
$userType = $schoolAuth['user_type'] ?? '';

// Verify access (only admin and accountant should access payroll)
if (!in_array($userType, ['admin', 'accountant'])) {
    error_log("ERROR: User does not have access to payroll");
    header('HTTP/1.1 403 Forbidden');
    echo "Access denied.";
    exit;
}

// Load configuration and autoload
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
    error_log("Error loading files: " . $e->getMessage());
    http_response_code(500);
    die("Configuration loading failed.");
}

// Connect to school database
$schoolDb = null;
try {
    if (!empty($school['database_name'])) {
        $schoolDb = Database::getSchoolConnection($school['database_name']);
        error_log("School database connection successful");
    } else {
        throw new Exception("School database name not found");
    }
} catch (Exception $e) {
    error_log("ERROR connecting to school database: " . $e->getMessage());
    $schoolDb = null;
}

// Load PayrollManager
require_once __DIR__ . '/../../../includes/PayrollManager.php';
$payrollManager = new PayrollManager($schoolDb, $school['id']);

// ========== PAYROLL-SPECIFIC CSRF FUNCTIONS ==========
if (!function_exists('generatePayrollCsrfToken')) {
    function generatePayrollCsrfToken() {
        if (!isset($_SESSION['payroll_csrf_token'])) {
            $_SESSION['payroll_csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['payroll_csrf_token'];
    }
    function validatePayrollCsrfToken($token) {
        return isset($_SESSION['payroll_csrf_token']) && hash_equals($_SESSION['payroll_csrf_token'], $token);
    }
}
// =====================================================

// Get settings for currency symbol
$settings = [];
try {
    $settingsStmt = $schoolDb->prepare("SELECT `key`, `value` FROM settings WHERE school_id = ?");
    if ($settingsStmt) {
        $settingsStmt->execute([$school['id']]);
        while ($row = $settingsStmt->fetch(PDO::FETCH_ASSOC)) {
            $settings[$row['key']] = $row['value'];
        }
    }
} catch (Exception $e) {
    error_log("Error loading settings: " . $e->getMessage());
}
$currencySymbol = $settings['currency_symbol'] ?? '₦';

// Get logged in user details
$adminUser = ['name' => 'Admin User', 'role_name' => 'Administrator'];
try {
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
        } elseif (isset($_SESSION['school_user']['name'])) {
            $adminUser = [
                'name' => $_SESSION['school_user']['name'],
                'role_name' => 'Administrator'
            ];
        }
    }
} catch (Exception $e) {
    error_log("Error fetching user details: " . $e->getMessage());
}

// Generate payroll-specific CSRF token for the page
$csrfToken = generatePayrollCsrfToken();
?>