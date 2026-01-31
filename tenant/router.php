<?php
/**
 * Enhanced School Router - Routes all school URLs to template files
 * Examples:
 * /tenant/thekingsinternationalsch/admin/dashboard.php
 * /tenant/thekingsinternationalsch/teacher/my-classes.php  
 * /tenant/thekingsinternationalsch/student/timetable.php
 * /tenant/thekingsinternationalsch/admin/students.php?action=view&id=123
 * / in place of the original url tenant/{school-slug}/admin/dashboard.php
 */

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/router.log');

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_name('academix_tenant');
    session_start([
        'cookie_lifetime' => 86400,
        'cookie_httponly' => true,
        'cookie_secure'   => false,
    ]);
}

// Get URL parameters
$schoolSlug = $_GET['school_slug'] ?? '';
$userType = $_GET['user_type'] ?? 'admin';
$page = $_GET['page'] ?? 'dashboard.php';
$queryString = $_SERVER['QUERY_STRING'] ?? '';

error_log("ROUTER START: school={$schoolSlug}, type={$userType}, page={$page}");
error_log("Full request: " . ($_SERVER['REQUEST_URI'] ?? 'N/A'));

// Validate school slug
if (!preg_match('/^[a-zA-Z0-9_-]+$/', $schoolSlug)) {
    error_log("Invalid school slug format: {$schoolSlug}");
    header("Location: ./login.php");
    exit;
}

// Load configuration early
require_once __DIR__ . '/../includes/autoload.php';

// Verify school exists in database
try {
    $platformDb = Database::getPlatformConnection();
    $stmt = $platformDb->prepare("
        SELECT id, name, slug, database_name, status, primary_color, secondary_color,
               trial_ends_at, plan_id, created_at
        FROM schools 
        WHERE slug = ? AND status IN ('active', 'trial')
    ");
    $stmt->execute([$schoolSlug]);
    $school = $stmt->fetch();
    
    if (!$school) {
        error_log("School not found in database: {$schoolSlug}");
        http_response_code(404);
        die("School '{$schoolSlug}' not found or inactive. Please contact administrator.");
    }
    
    // Store school info in session for later use
    if (!isset($_SESSION['school_info'])) {
        $_SESSION['school_info'] = [];
    }
    $_SESSION['school_info'][$schoolSlug] = $school;
    
    error_log("School verified: ID=" . $school['id'] . ", Name=" . $school['name']);
    
} catch (Exception $e) {
    error_log("Database error verifying school: " . $e->getMessage());
    http_response_code(500);
    die("System error. Please try again later.");
}

// Define allowed pages for each user type (security whitelist)
$allowedPages = [
    'admin' => [
        'dashboard.php',
        'school-dashboard.php',
        'students.php',
        'teachers.php',
        'parents.php',
        'classes.php',
        'subjects.php',
        'timetable.php',
        'attendance.php',
        'exams.php',
        'results.php',
        'fees.php',
        'notifications.php',
        'settings.php',
        'reports.php',
        'profile.php',
        'announcements.php',
        'events.php',
        'activity-log.php',
        'staff.php',
        'schedule.php',
        'grades.php',
        'my-classes.php',
        'marks.php',
        'assignments.php',
        'messages.php',
        'children.php',
        'forgot-password.php',
        'reset-password.php',
        'logout.php',
        'process-announcement.php',
        'process-student.php',
        'view.php',
        'add.php',
        'edit.php',
        'delete.php',
        'manage.php'
    ],
    'teacher' => [
        'dashboard.php',
        'school-dashboard.php',
        'my-classes.php',
        'attendance.php',
        'marks.php',
        'timetable.php',
        'assignments.php',
        'profile.php',
        'messages.php',
        'students.php',
        'grades.php',
        'logout.php',
        'forgot-password.php',
        'reset-password.php'
    ],
    'student' => [
        'dashboard.php',
        'school-dashboard.php',
        'timetable.php',
        'attendance.php',
        'marks.php',
        'assignments.php',
        'fees.php',
        'profile.php',
        'messages.php',
        'logout.php',
        'forgot-password.php',
        'reset-password.php'
    ],
    'parent' => [
        'dashboard.php',
        'school-dashboard.php',
        'children.php',
        'attendance.php',
        'marks.php',
        'fees.php',
        'profile.php',
        'messages.php',
        'logout.php',
        'forgot-password.php',
        'reset-password.php'
    ]
];

// Public pages that don't require authentication
$publicPages = [
    'login.php',
    'forgot-password.php',
    'reset-password.php',
    'logout.php'
];

// Extract just the filename from page (in case there are subdirectories)
$basePage = basename($page);
$pagePath = dirname($page) !== '.' ? dirname($page) . '/' : '';

error_log("Base page: {$basePage}, Page path: {$pagePath}");

// Replace this section in router.php:

// Handle public pages
if (in_array($basePage, $publicPages)) {
    // For login page, redirect to actual login
    if ($basePage === 'login.php') {
        header("Location: ../login.php?school_slug=" . urlencode($schoolSlug));
        exit;
    }
    
    // For logout, redirect to logout script
    if ($basePage === 'logout.php') {
        $redirectUrl = "../logout.php?school_slug=" . urlencode($schoolSlug);
        if (!empty($_GET)) {
            $redirectUrl .= '&' . http_build_query($_GET);
        }
        header("Location: {$redirectUrl}");
        exit;
    }
} else {
    // For authenticated pages, check session
    
    if (empty($_SESSION['school_auth'])) {
        error_log("No session found, redirecting to login");
        header("Location: ../login.php?school_slug=" . urlencode($schoolSlug));
        exit;
    }
    
    // Verify session matches URL school slug
    if (($_SESSION['school_auth']['school_slug'] ?? '') !== $schoolSlug) {
        error_log("Session mismatch. Session: {$_SESSION['school_auth']['school_slug']}, URL: {$schoolSlug}");
        session_destroy();
        header("Location: ../login.php?school_slug=" . urlencode($schoolSlug));
        exit;
    }
    
    // Verify user type
    if (($_SESSION['school_auth']['user_type'] ?? '') !== $userType) {
        error_log("User type mismatch. Session: {$_SESSION['school_auth']['user_type']}, URL: {$userType}");
        $correctType = $_SESSION['school_auth']['user_type'];
        header("Location: ../{$schoolSlug}/{$correctType}/dashboard.php");
        exit;
    }
}

// Determine template file path
// Try multiple possible locations
$possiblePaths = [
    // First try: {school-slug} folder (for backward compatibility)
    __DIR__ . "/{$schoolSlug}/{$userType}/{$pagePath}{$basePage}",
    // Second try: school-template folder (recommended)
    __DIR__ . "/{school-slug}/{$userType}/{$pagePath}{$basePage}",
    // Third try: just the page in user folder
    __DIR__ . "/{school-slug}/{$userType}/{$basePage}",
    // Fourth try: dashboard as fallback
    __DIR__ . "/{school-slug}/{$userType}/dashboard.php"
];

$templateFile = null;
foreach ($possiblePaths as $path) {
    if (file_exists($path)) {
        $templateFile = $path;
        break;
    }
}

if (!$templateFile) {
    error_log("ERROR: Template file not found for page: {$page}");
    error_log("Tried paths: " . implode(', ', $possiblePaths));
    
    // If we still can't find it, show error
    http_response_code(404);
    echo "<!DOCTYPE html>
    <html>
    <head>
        <title>Page Not Found - {$school['name']}</title>
        <style>
            body { font-family: Arial, sans-serif; text-align: center; padding: 50px; }
            .error-container { max-width: 600px; margin: 0 auto; }
            h1 { color: #dc2626; }
            .actions { margin-top: 30px; }
            .btn { display: inline-block; padding: 10px 20px; margin: 5px; 
                   background: #4f46e5; color: white; text-decoration: none; 
                   border-radius: 5px; }
        </style>
    </head>
    <body>
        <div class='error-container'>
            <h1>Page Not Found</h1>
            <p>The requested page '{$basePage}' was not found.</p>
            <div class='actions'>
                <a href='/academixsuite/tenant/{$schoolSlug}/{$userType}/dashboard.php' class='btn'>Go to Dashboard</a>
                <a href='/academixsuite/tenant/{$schoolSlug}/{$userType}/' class='btn'>Go to Home</a>
                <a href='/academixsuite/tenant/logout.php?school_slug={$schoolSlug}' class='btn'>Logout</a>
            </div>
        </div>
    </body>
    </html>";
    exit;
}

error_log("Found template: {$templateFile}");

// Set global variables for the template
$GLOBALS['SCHOOL_SLUG'] = $schoolSlug;
$GLOBALS['USER_TYPE'] = $userType;
$GLOBALS['CURRENT_PAGE'] = $basePage;
$GLOBALS['CURRENT_PATH'] = $pagePath;
$GLOBALS['SCHOOL_DATA'] = $school;
$GLOBALS['SCHOOL_AUTH'] = $_SESSION['school_auth'] ?? [];

// Build base URL for this school/user type
$GLOBALS['BASE_URL'] = "./{$schoolSlug}/{$userType}/";

// Also set a helper for subdirectory pages
if (!empty($pagePath)) {
    $GLOBALS['PAGE_BASE_URL'] = "./{$schoolSlug}/{$userType}/{$pagePath}";
} else {
    $GLOBALS['PAGE_BASE_URL'] = $GLOBALS['BASE_URL'];
}

// Pass any query parameters to the template
$GLOBALS['QUERY_PARAMS'] = $_GET;
unset($GLOBALS['QUERY_PARAMS']['school_slug']);
unset($GLOBALS['QUERY_PARAMS']['user_type']);
unset($GLOBALS['QUERY_PARAMS']['page']);

// Load the template
error_log("Loading template: {$templateFile}");
require_once $templateFile;

error_log("ROUTER END: Successfully served {$templateFile}");
exit;