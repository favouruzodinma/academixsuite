<?php
/**
 * School Admin Dashboard
 * File: /tenant/{school-slug}/admin/school-dashboard.php
 */

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../../logs/school_dashboard.log');

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_name('tenant_session');
    session_start();
}

// Get current path to extract school slug
$currentPath = $_SERVER['SCRIPT_FILENAME'] ?? '';
error_log("=== DASHBOARD START ===");
error_log("Script Path: " . $currentPath);

// Extract school slug from file path: /tenant/{school-slug}/admin/school-dashboard.php
$pathParts = explode('/', $currentPath);
$schoolSlug = '';

foreach ($pathParts as $index => $part) {
    if ($part === 'tenant' && isset($pathParts[$index + 1])) {
        $schoolSlug = $pathParts[$index + 1];
        break;
    }
}

if (empty($schoolSlug)) {
    error_log("ERROR: Could not extract school slug from path");
    header('Location: /tenant/login.php');
    exit;
}

error_log("School slug from path: " . $schoolSlug);

// Load configuration
require_once __DIR__ . '/../../../includes/autoload.php';

// Get school from database
try {
    $platformDb = Database::getPlatformConnection();
    $stmt = $platformDb->prepare("SELECT * FROM schools WHERE slug = ?");
    $stmt->execute([$schoolSlug]);
    $school = $stmt->fetch();
    
    if (!$school) {
        error_log("ERROR: School not found: " . $schoolSlug);
        header('Location: /tenant/login.php?error=School not found');
        exit;
    }
    
    // Set current school context
    $_SESSION['current_school'] = $school;
    
} catch (Exception $e) {
    error_log("ERROR getting school: " . $e->getMessage());
    header('Location: /tenant/login.php?error=System error');
    exit;
}

// Check authentication
if (!isset($_SESSION['school_auth']) || $_SESSION['school_auth']['school_slug'] !== $schoolSlug) {
    error_log("ERROR: User not authenticated for school: " . $schoolSlug);
    header("Location: /tenant/{$schoolSlug}/login.php");
    exit;
}

// Check user type (must be admin for this dashboard)
$userType = basename(dirname(__FILE__)); // Get 'admin' from folder name
if ($_SESSION['school_auth']['user_type'] !== $userType) {
    error_log("ERROR: User type mismatch. Expected: {$userType}, Got: " . $_SESSION['school_auth']['user_type']);
    header("Location: /tenant/{$schoolSlug}/{$_SESSION['school_auth']['user_type']}/school-dashboard.php");
    exit;
}

// Now you're authenticated!
error_log("User authenticated: " . $_SESSION['school_auth']['user_name']);
error_log("User type: " . $_SESSION['school_auth']['user_type']);
error_log("School: " . $school['name']);
error_log("=== AUTHENTICATION PASSED ===");

// Connect to school database
$schoolDb = null;
if (!empty($school['database_name'])) {
    try {
        $schoolDb = Database::getSchoolConnection($school['database_name']);
        error_log("School database connected successfully");
    } catch (Exception $e) {
        error_log("WARNING: School DB connection failed: " . $e->getMessage());
        // Continue with null database
    }
}

// YOUR EXISTING DASHBOARD CODE CONTINUES HERE...
// Use $school, $_SESSION['school_auth'], and $schoolDb
?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo htmlspecialchars($school['name']); ?> - Admin Dashboard</title>
    <!-- Your existing dashboard HTML/CSS/JS -->
</head>
<body>
    <!-- Your existing dashboard content -->
    <h1>Welcome to <?php echo htmlspecialchars($school['name']); ?> Admin Dashboard</h1>
    <p>Logged in as: <?php echo htmlspecialchars($_SESSION['school_auth']['user_name']); ?></p>
</body>
</html>