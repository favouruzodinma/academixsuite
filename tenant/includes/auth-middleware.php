<?php
/**
 * Authentication Middleware for School Portal Pages
 */

function requireSchoolAuth() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    require_once __DIR__ . '/../../includes/autoload.php';
    
    // Check if user is authenticated
    if (!isset($_SESSION['school_auth'])) {
        // Get school slug from URL path
        $urlPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $pattern = '/\/tenant\/([a-zA-Z0-9_-]+)\//';
        $subdomainSlug = function_exists('school_subdomain_slug') ? school_subdomain_slug() : null;
        
        if ($subdomainSlug || preg_match($pattern, $urlPath, $matches)) {
            $schoolSlug = $subdomainSlug ?: $matches[1];
            header('Location: ' . (function_exists('school_login_url') ? school_login_url($schoolSlug, false) : "/tenant/$schoolSlug/login.php"));
            exit;
        } else {
            header('Location: ' . (function_exists('school_login_url') ? school_login_url('', false) : '/tenant/login.php'));
            exit;
        }
    }
    
    // Verify school slug matches
    $urlPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $pattern = '/\/tenant\/([a-zA-Z0-9_-]+)\//';
    
    if (preg_match($pattern, $urlPath, $matches)) {
        $currentSlug = $matches[1];
        
        if ($_SESSION['school_auth']['school_slug'] !== $currentSlug) {
            // User trying to access wrong school
            $correctSlug = $_SESSION['school_auth']['school_slug'];
            header('Location: ' . (function_exists('school_login_url') ? school_login_url($correctSlug, false) : "/tenant/$correctSlug/login.php"));
            exit;
        }
    }
    
    return $_SESSION['school_auth'];
}

// Optional: Check user type
function requireUserType($allowedTypes) {
    $auth = requireSchoolAuth();
    
    if (!is_array($allowedTypes)) {
        $allowedTypes = [$allowedTypes];
    }
    
    if (!in_array($auth['user_type'], $allowedTypes)) {
        // Redirect to appropriate dashboard
        $dashboardUrl = function_exists('school_route_url')
            ? school_route_url($auth['school_slug'], $auth['user_type'], 'dashboard.php', false)
            : "/tenant/{$auth['school_slug']}/{$auth['user_type']}/dashboard.php";
        header("Location: {$dashboardUrl}");
        exit;
    }
    
    return $auth;
}
?>
