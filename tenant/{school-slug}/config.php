<?php
/**
 * School Configuration and Router
 */

// Get current URL path
$requestUri = $_SERVER['REQUEST_URI'];
$basePath = "/academixsuite/tenant/" . SCHOOL_SLUG . "/";

// Remove base path to get relative path
$relativePath = str_replace($basePath, '', $requestUri);

// Parse the path
$pathParts = explode('/', trim($relativePath, '/'));
$userType = $pathParts[0] ?? 'admin';
$page = $pathParts[1] ?? 'dashboard.php';

// Allowed user types and pages
$allowedUserTypes = ['admin', 'teacher', 'student', 'parent', 'staff'];
$allowedPages = [
    'admin' => ['dashboard.php', 'students.php', 'teachers.php', 'classes.php', 'attendance.php', 'fees.php', 'settings.php'],
    'teacher' => ['dashboard.php', 'classes.php', 'my-classes.php', 'attendance.php', 'grades.php', 'timetable.php', 'assignments.php', 'students.php', 'announcements.php', 'calendar.php', 'messages.php', 'profile.php'],
    'student' => ['dashboard.php', 'timetable.php', 'attendance.php', 'grades.php', 'results.php', 'assignments.php', 'fees.php', 'library.php', 'announcements.php', 'calendar.php', 'messages.php', 'profile.php'],
    'parent' => ['dashboard.php', 'children.php', 'attendance.php', 'grades.php', 'fees.php', 'schedule.php', 'announcements.php', 'messages.php', 'support.php', 'profile.php'],
    'staff' => ['dashboard.php', 'attendance.php', 'payroll.php', 'leave.php', 'library.php', 'inventory.php', 'fees.php', 'messages.php', 'reports.php', 'work.php', 'calendar.php', 'profile.php']
];

// Validate user type
if (!in_array($userType, $allowedUserTypes)) {
    http_response_code(404);
    die("Invalid user type");
}

// Validate user has access to this user type
if (isset($_SESSION['school_auth']) && $_SESSION['school_auth']['user_type'] !== $userType) {
    $correctType = $_SESSION['school_auth']['user_type'];
    header("Location: {$basePath}{$correctType}/dashboard.php");
    exit;
}

// Default to dashboard if page not specified or invalid
if (empty($page) || !in_array($page, $allowedPages[$userType])) {
    $page = 'dashboard.php';
}

// Build file path
$filePath = __DIR__ . "/{$userType}/{$page}";

// Check if file exists
if (!file_exists($filePath)) {
    $filePath = __DIR__ . "/{$userType}/dashboard.php";
}

// Load the file
require_once $filePath;
exit;
