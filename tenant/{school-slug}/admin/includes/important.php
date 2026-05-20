<?php
/**
 * Backwards-compatible school admin bootstrap.
 *
 * Older admin pages still include this file directly. Keep it as a thin
 * compatibility layer over the shared bootstrap so common variables are
 * always defined in one predictable way.
 */

require_once __DIR__ . '/admin-bootstrap.php';

$schoolSlug = (string) ($schoolSlug ?? ($GLOBALS['SCHOOL_SLUG'] ?? ''));
$userType = (string) ($userType ?? ($GLOBALS['USER_TYPE'] ?? 'admin'));
$currentPage = (string) ($currentPage ?? ($GLOBALS['CURRENT_PAGE'] ?? basename($_SERVER['SCRIPT_NAME'] ?? 'index.php')));
$baseUrl = (string) ($baseUrl ?? ($GLOBALS['BASE_URL'] ?? (function_exists('academix_admin_url') ? academix_admin_url('') : '')));

$school = is_array($school ?? null) ? $school : [];
if (empty($school) && is_array($GLOBALS['SCHOOL_DATA'] ?? null)) {
    $school = $GLOBALS['SCHOOL_DATA'];
}
if (empty($school) && $schoolSlug !== '' && is_array($_SESSION['school_info'][$schoolSlug] ?? null)) {
    $school = $_SESSION['school_info'][$schoolSlug];
}

$existingSchoolData = null;
if (isset($schoolData) && is_array($schoolData)) {
    $existingSchoolData = $schoolData;
}
$schoolData = $existingSchoolData ?? $school;
$schoolAuth = is_array($schoolAuth ?? null) ? $schoolAuth : ($_SESSION['school_auth'] ?? []);
$userId = (int) ($userId ?? ($schoolAuth['user_id'] ?? 0));

$adminUser = is_array($adminUser ?? null) ? $adminUser : [];
$adminUser = array_merge([
    'name' => $schoolAuth['user_name'] ?? 'Admin User',
    'email' => $schoolAuth['user_email'] ?? '',
    'role_name' => $schoolAuth['role_name'] ?? 'Administrator',
    'avatar' => '',
    'profile_photo' => '',
], $adminUser);

$notifications = is_array($notifications ?? null) ? $notifications : [];
$unreadCount = (int) ($unreadCount ?? 0);
$notificationCount = (int) ($notificationCount ?? $unreadCount);

$schoolDb = $schoolDb ?? null;
$platformDb = $platformDb ?? null;
$schoolLogoUrl = $schoolLogoUrl ?? (function_exists('school_logo_url') ? school_logo_url($school, false) : '');
$schoolLogoAbsoluteUrl = $schoolLogoAbsoluteUrl ?? (function_exists('school_logo_url') ? school_logo_url($school, true) : $schoolLogoUrl);
$csrfToken = $csrfToken ?? (function_exists('academix_admin_csrf_token') ? academix_admin_csrf_token() : '');

$GLOBALS['SCHOOL_SLUG'] = $schoolSlug;
$GLOBALS['USER_TYPE'] = $userType;
$GLOBALS['CURRENT_PAGE'] = $currentPage;
$GLOBALS['SCHOOL_DATA'] = $school;
$GLOBALS['BASE_URL'] = $baseUrl;
?>
