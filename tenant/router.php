<?php
/**
 * Enhanced School Router - Routes all school URLs to template files
 * Examples:
 * /tenant/thekingsinternationalsch/admin/dashboard.php
 * /tenant/thekingsinternationalsch/teacher/my-classes.php  
 * /tenant/thekingsinternationalsch/student/timetable.php
 * /tenant/thekingsinternationalsch/admin/students.php?action=view&id=123
 */

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/router.log');

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_name('academix_tenant');
    require_once __DIR__ . '/../includes/session_config.php';
    session_start(academix_session_options());
}

// CRITICAL: Check if this is a request for an asset file
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';

// If the request contains asset extensions, serve the file directly
$assetExtensions = ['.css', '.js', '.png', '.jpg', '.jpeg', '.gif', '.ico', '.svg', '.woff', '.woff2', '.ttf', '.eot', '.map'];
foreach ($assetExtensions as $ext) {
    if (strpos($requestUri, $ext) !== false) {
        // Extract the file path
        $filePath = __DIR__ . '/' . $requestUri;
        
        // Remove query string if present
        $filePath = strtok($filePath, '?');
        
        // Check if file exists and serve it
        if (file_exists($filePath)) {
            error_log("Serving asset directly: {$filePath}");
            
            // Set correct content type
            $contentType = 'text/plain';
            switch ($ext) {
                case '.css':
                    $contentType = 'text/css';
                    break;
                case '.js':
                    $contentType = 'application/javascript';
                    break;
                case '.png':
                    $contentType = 'image/png';
                    break;
                case '.jpg':
                case '.jpeg':
                    $contentType = 'image/jpeg';
                    break;
                case '.gif':
                    $contentType = 'image/gif';
                    break;
                case '.svg':
                    $contentType = 'image/svg+xml';
                    break;
                case '.ico':
                    $contentType = 'image/x-icon';
                    break;
            }
            
            header('Content-Type: ' . $contentType);
            header('Content-Length: ' . filesize($filePath));
            readfile($filePath);
            exit;
        }
    }
}

// Load configuration early so URL helpers can detect wildcard school subdomains.
require_once __DIR__ . '/../includes/autoload.php';

// Get URL parameters
$subdomainSlug = function_exists('school_subdomain_slug') ? school_subdomain_slug() : null;
$schoolSlug = trim((string)($_GET['school_slug'] ?? $subdomainSlug ?? ''), '/');
$userType = $_GET['user_type'] ?? 'admin';
$page = $_GET['page'] ?? 'dashboard.php';
$queryString = $_SERVER['QUERY_STRING'] ?? '';

error_log("ROUTER START: school={$schoolSlug}, type={$userType}, page={$page}");
error_log("Full request: " . ($_SERVER['REQUEST_URI'] ?? 'N/A'));

// Validate school slug
if (!preg_match('/^[a-zA-Z0-9_-]+$/', $schoolSlug)) {
    error_log("Invalid school slug format: {$schoolSlug}");
    header("Location: " . (function_exists('school_login_url') ? school_login_url('', false) : '../login.php'));
    exit;
}

if (!in_array($userType, ['admin', 'admin2', 'teacher', 'student', 'parent', 'staff'], true)) {
    error_log("Invalid user type: {$userType}");
    header("Location: " . (function_exists('school_login_url') ? school_login_url($schoolSlug, false) : '../login.php?school_slug=' . urlencode($schoolSlug)));
    exit;
}

$page = str_replace('\\', '/', (string) $page);
$page = ltrim($page, '/');
if ($page === '' || strpos($page, '..') !== false || !preg_match('/^[a-zA-Z0-9_\-\/.]+$/', $page)) {
    error_log("Invalid page path: {$page}");
    http_response_code(400);
    die("Invalid page request.");
}

$extension = strtolower(pathinfo($page, PATHINFO_EXTENSION));
if ($extension === '') {
    $page .= '.php';
    $extension = 'php';
}

if (!in_array($extension, ['php', 'php', 'htm'], true)) {
    error_log("Invalid page extension: {$page}");
    http_response_code(400);
    die("Invalid page request.");
}

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
        'index.php',
        'dashboard.php',
        'student-list.php',
        'teachers.php',
        'parents.php',
        'classes.php',
        'subjects.php',
        'timetable.php',
        'student-attendance.php',
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
        'general.php',
        'invoice-details.php',
        'marks.php',
        'assignments.php',
        'messages.php',
        'subscription-plan.php',
        'forgot-password.php',
        'reset-password.php',
        'logout.php',
        'process-announcement.php',
        'process-student.php',
        'view.php',
        'add-new-student.php',
        'edit.php',
        'delete.php',
        'manage.php',
        // Public-profile editor — admins customise their school landing page.
        'school-profile.php'
    ],
    'teacher' => [
        'dashboard.php',
        'school-dashboard.php',
        'classes.php',
        'my-classes.php',
        'attendance.php',
        'grades.php',
        'marks.php',
        'timetable.php',
        'assignments.php',
        'profile.php',
        'messages.php',
        'students.php',
        'announcements.php',
        'calendar.php',
        'logout.php',
        'forgot-password.php',
        'reset-password.php'
    ],
    'student' => [
        'dashboard.php',
        'school-dashboard.php',
        'timetable.php',
        'attendance.php',
        'grades.php',
        'marks.php',
        'results.php',
        'assignments.php',
        'fees.php',
        'library.php',
        'announcements.php',
        'calendar.php',
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
        'grades.php',
        'marks.php',
        'fees.php',
        'schedule.php',
        'support.php',
        'announcements.php',
        'profile.php',
        'messages.php',
        'logout.php',
        'forgot-password.php',
        'reset-password.php'
    ],
    'staff' => [
        'dashboard.php',
        'school-dashboard.php',
        'attendance.php',
        'payroll.php',
        'leave.php',
        'library.php',
        'inventory.php',
        'fees.php',
        'messages.php',
        'reports.php',
        'work.php',
        'tasks.php',
        'calendar.php',
        'profile.php',
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

$pageAliases = [
    'admin' => [
        'dashboard.php' => 'index.php',
        'dashboard.php' => 'index.php',
        'index.php' => 'index.php',
        'teachers.php' => 'teacher-list.php',
        'teachers.php' => 'teacher-list.php',
        'parents.php' => 'guardian-list.php',
        'parents.php' => 'guardian-list.php',
        'classes.php' => 'class-list.php',
        'classes.php' => 'class-list.php',
        'subjects.php' => 'subject-list.php',
        'subjects.php' => 'subject-list.php',
        'fees.php' => 'fees-collect.php',
        'fees.php' => 'fees-collect.php',
        'notifications.php' => 'notification.php',
        'notifications.php' => 'notification.php',
        'settings.php' => 'general.php',
        'settings.php' => 'general.php',
        'events.php' => 'event.php',
        'events.php' => 'event.php',
        'event.php' => 'event.php',
        'messages.php' => 'message.php',
        'messages.php' => 'message.php',
        'announcements.php' => 'notice-board.php',
        'announcements.php' => 'notice-board.php',
        'exams.php' => 'exam.php',
        'exams.php' => 'exam.php',
        'exam-list.php' => 'exam.php',
        'exam-results.php' => 'exam-result.php',
        'fees-types.php' => 'fees-type.php',
        'student-categories.php' => 'student-category.php',
        'attendance.php' => 'student-attendance.php',
        'grades.php' => 'exam-result.php',
        'notifications.php' => 'notification.php',
    ],
    'admin2' => [
        'dashboard.php' => 'dashboard.php',
        'school-dashboard.php' => 'dashboard.php',
        'school-dashboard.php' => 'dashboard.php',
    ],
    'teacher' => [
        'dashboard.php' => 'dashboard.php',
        'teacher-dashboard.php' => 'dashboard.php',
        'teacher-dashboard.php' => 'dashboard.php',
        'school-dashboard.php' => 'dashboard.php',
        'teacher-classes.php' => 'classes.php',
        'my-classes.php' => 'classes.php',
        'teacher-attendance.php' => 'attendance.php',
        'teacher-grades.php' => 'grades.php',
        'marks.php' => 'grades.php',
    ],
    'student' => [
        'dashboard.php' => 'dashboard.php',
        'school-dashboard.php' => 'dashboard.php',
        'marks.php' => 'grades.php',
        'report-card.php' => 'results.php',
    ],
    'parent' => [
        'dashboard.php' => 'dashboard.php',
        'parent-dashboard.php' => 'dashboard.php',
        'parent-dashboard.php' => 'dashboard.php',
        'school-dashboard.php' => 'dashboard.php',
        'parent-child.php' => 'children.php',
        'parent-attendance.php' => 'attendance.php',
        'parent-grades.php' => 'grades.php',
        'marks.php' => 'grades.php',
        'parent-schedule.php' => 'schedule.php',
    ],
    'staff' => [
        'dashboard.php' => 'dashboard.php',
        'school-dashboard.php' => 'dashboard.php',
        'tasks.php' => 'work.php',
    ],
];

if ($pagePath === '' && isset($pageAliases[$userType][$basePage])) {
    $basePage = $pageAliases[$userType][$basePage];
    $page = $basePage;
}

error_log("Base page: {$basePage}, Page path: {$pagePath}");

if (!in_array($basePage, $publicPages, true) && isset($allowedPages[$userType]) && !in_array($basePage, $allowedPages[$userType], true)) {
    error_log("Page not allowed for type {$userType}: {$basePage}");
    http_response_code(404);
    die("Page not found.");
}

// Handle public pages
if (in_array($basePage, $publicPages)) {
    // For login page, redirect to actual login
    if ($basePage === 'login.php') {
        header("Location: " . (function_exists('school_login_url') ? school_login_url($schoolSlug, false) : '../login.php?school_slug=' . urlencode($schoolSlug)));
        exit;
    }
    
    // For logout, redirect to logout script
    if ($basePage === 'logout.php') {
        $redirectUrl = function_exists('is_school_subdomain_request') && is_school_subdomain_request($schoolSlug)
            ? '/logout.php'
            : '/tenant/logout.php?school_slug=' . urlencode($schoolSlug);
        if (!empty($_GET)) {
            $redirectUrl .= (strpos($redirectUrl, '?') === false ? '?' : '&') . http_build_query($_GET);
        }
        header("Location: {$redirectUrl}");
        exit;
    }
} else {
    // For authenticated pages, check session
    
    if (empty($_SESSION['school_auth'])) {
        error_log("No session found, redirecting to login");
        header("Location: " . (function_exists('school_login_url') ? school_login_url($schoolSlug, false) : '../login.php?school_slug=' . urlencode($schoolSlug)));
        exit;
    }
    
    // Verify session matches URL school slug
    if (($_SESSION['school_auth']['school_slug'] ?? '') !== $schoolSlug) {
        error_log("Session mismatch. Session: {$_SESSION['school_auth']['school_slug']}, URL: {$schoolSlug}");
        session_destroy();
        header("Location: " . (function_exists('school_login_url') ? school_login_url($schoolSlug, false) : '../login.php?school_slug=' . urlencode($schoolSlug)));
        exit;
    }
    
    // Verify user type
    $sessionType = $_SESSION['school_auth']['user_type'] ?? '';
    $allowedSessionTypes = [$userType === 'admin2' ? 'admin' : $userType];
    if ($userType === 'staff') {
        $allowedSessionTypes = ['staff', 'accountant', 'librarian', 'receptionist'];
    }
    if (!in_array($sessionType, $allowedSessionTypes, true)) {
        error_log("User type mismatch. Session: {$_SESSION['school_auth']['user_type']}, URL: {$userType}");
        $correctType = in_array($sessionType, ['accountant', 'librarian', 'receptionist'], true) ? 'staff' : $sessionType;
        $redirectUrl = function_exists('school_route_url')
            ? school_route_url($schoolSlug, $correctType, 'dashboard.php', false)
            : "../{$schoolSlug}/{$correctType}/dashboard.php";
        header("Location: {$redirectUrl}");
        exit;
    }
}

// Determine template file path
$candidatePages = [$pagePath . $basePage];
if (preg_match('/\.php?$/i', $basePage)) {
    $candidatePages[] = $pagePath . preg_replace('/\.php?$/i', '.php', $basePage);
} elseif (preg_match('/\.php$/i', $basePage)) {
    $candidatePages[] = $pagePath . preg_replace('/\.php$/i', '.php', $basePage);
}

$fallbackPages = [
    'admin' => 'index.php',
    'admin2' => 'dashboard.php',
    'teacher' => 'dashboard.php',
    'student' => 'dashboard.php',
    'parent' => 'dashboard.php',
    'staff' => 'dashboard.php',
];
if (isset($fallbackPages[$userType])) {
    $candidatePages[] = $fallbackPages[$userType];
}

// Try multiple possible locations
$possiblePaths = [];
foreach (array_unique($candidatePages) as $candidatePage) {
    // First try: actual school folder (for provisioned/customized portals)
    $possiblePaths[] = __DIR__ . "/{$schoolSlug}/{$userType}/{$candidatePage}";
    // Second try: shared school template folder
    $possiblePaths[] = __DIR__ . "/{school-slug}/{$userType}/{$candidatePage}";
}

$templateFile = null;
foreach ($possiblePaths as $path) {
    if (file_exists($path)) {
        $templateFile = $path;
        break;
    }
}

$sharedPortalTypes = ['teacher', 'student', 'parent', 'staff'];
if (!$templateFile && in_array($userType, $sharedPortalTypes, true) && !in_array($basePage, $publicPages, true)) {
    $sharedTemplate = __DIR__ . "/{school-slug}/shared/portal-template.php";
    if (file_exists($sharedTemplate)) {
        $templateFile = $sharedTemplate;
    }
}

if (!$templateFile) {
    error_log("ERROR: Template file not found for page: {$page}");
    error_log("Tried paths: " . implode(', ', $possiblePaths));
    
    // If we still can't find it, show error
    http_response_code(404);
    $safeSchoolName = htmlspecialchars($school['name'] ?? 'School', ENT_QUOTES, 'UTF-8');
    $safeBasePage = htmlspecialchars($basePage, ENT_QUOTES, 'UTF-8');
    $dashboardUrl = function_exists('school_route_url') ? school_route_url($schoolSlug, $userType, 'dashboard.php', false) : '/tenant/' . rawurlencode($schoolSlug) . '/' . rawurlencode($userType) . '/dashboard.php';
    $logoutUrl = function_exists('is_school_subdomain_request') && is_school_subdomain_request($schoolSlug)
        ? '/logout.php'
        : '/tenant/logout.php?school_slug=' . rawurlencode($schoolSlug);
    echo "<!DOCTYPE php>
    <php>
    <head>
        <title>Page Not Found - {$safeSchoolName}</title>
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
            <p>The requested page '{$safeBasePage}' was not found.</p>
            <div class='actions'>
                <a href='" . htmlspecialchars($dashboardUrl, ENT_QUOTES, 'UTF-8') . "' class='btn'>Go to Dashboard</a>
                <a href='" . htmlspecialchars($logoutUrl, ENT_QUOTES, 'UTF-8') . "' class='btn'>Logout</a>
            </div>
        </div>
    </body>
    </php>";
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
$baseUrl = function_exists('school_route_url')
    ? rtrim(school_route_url($schoolSlug, $userType, '', false), '/') . '/'
    : "/tenant/{$schoolSlug}/{$userType}/";
$GLOBALS['BASE_URL'] = $baseUrl;

// Also set a helper for subdirectory pages
if (!empty($pagePath)) {
    $GLOBALS['PAGE_BASE_URL'] = $GLOBALS['BASE_URL'] . $pagePath;
} else {
    $GLOBALS['PAGE_BASE_URL'] = $GLOBALS['BASE_URL'];
}

// Set base asset URL
$GLOBALS['ASSETS_URL'] = function_exists('is_school_subdomain_request') && is_school_subdomain_request($schoolSlug) ? '/assets/' : '/tenant/assets/';

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
