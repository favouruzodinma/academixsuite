<?php
/**
 * School Portal Configuration Template
 * This file will be copied and customized for each school
 * Replace {SCHOOL_*} placeholders with actual school data
 */

// School Information from platform database
$school = [
    'id' => '{SCHOOL_ID}',
    'slug' => '{SCHOOL_SLUG}',
    'name' => '{SCHOOL_NAME}',
    'email' => '{SCHOOL_EMAIL}',
    'phone' => '{SCHOOL_PHONE}',
    'address' => '{SCHOOL_ADDRESS}',
    'database_name' => '{SCHOOL_DATABASE}',
    'logo_path' => '{SCHOOL_LOGO}',
    'primary_color' => '{SCHOOL_PRIMARY_COLOR}',
    'secondary_color' => '{SCHOOL_SECONDARY_COLOR}',
    'status' => '{SCHOOL_STATUS}',
    'plan_id' => '{SCHOOL_PLAN_ID}',
    'created_at' => '{SCHOOL_CREATED_AT}'
];

// Define school-specific constants
define('SCHOOL_ID', $school['id']);
define('SCHOOL_SLUG', $school['slug']);
define('SCHOOL_NAME', $school['name']);
define('SCHOOL_DB_NAME', $school['database_name']);
define('SCHOOL_UPLOAD_PATH', __DIR__ . '/../../../assets/uploads/schools/' . SCHOOL_ID . '/');
define('SCHOOL_ASSETS_URL', '/assets/uploads/schools/' . SCHOOL_ID . '/');
define('SCHOOL_PORTAL_URL', APP_URL . '/tenant/' . SCHOOL_SLUG);

// School timezone (default to Africa/Lagos, can be customized per school)
date_default_timezone_set('Africa/Lagos');

// School-specific database connection function
function getSchoolDb() {
    try {
        return Database::getSchoolConnection(SCHOOL_DB_NAME);
    } catch (Exception $e) {
        error_log("Failed to connect to school database: " . $e->getMessage());
        return null;
    }
}

// School-specific authentication check
function isSchoolUserAuthenticated() {
    if (!isset($_SESSION['school_auth'])) {
        return false;
    }
    
    $sessionSchoolId = $_SESSION['school_auth']['school_id'] ?? null;
    $sessionSchoolSlug = $_SESSION['school_auth']['school_slug'] ?? null;
    
    return ($sessionSchoolId == SCHOOL_ID && $sessionSchoolSlug == SCHOOL_SLUG);
}

// Require school authentication
function requireSchoolAuth() {
    if (!isSchoolUserAuthenticated()) {
        header('Location: /tenant/' . SCHOOL_SLUG . '/login');
        exit;
    }
}

// Get current school user
function getCurrentSchoolUser() {
    if (isSchoolUserAuthenticated()) {
        return $_SESSION['school_auth'];
    }
    return null;
}

// School-specific permission check
function hasSchoolPermission($permission) {
    $user = getCurrentSchoolUser();
    if (!$user || empty($user['permissions'])) {
        return false;
    }
    
    $permissions = $user['permissions'];
    
    // Check for wildcard permission
    if (in_array('*', $permissions)) {
        return true;
    }
    
    // Check for specific permission
    if (in_array($permission, $permissions)) {
        return true;
    }
    
    // Check for pattern permission
    foreach ($permissions as $userPerm) {
        if (strpos($userPerm, '*') !== false) {
            $pattern = '/^' . str_replace('*', '.*', $userPerm) . '$/';
            if (preg_match($pattern, $permission)) {
                return true;
            }
        }
    }
    
    return false;
}

// School-specific redirect
function redirectToSchoolPortal($path = '') {
    $url = SCHOOL_PORTAL_URL . $path;
    header("Location: $url");
    exit;
}
?>