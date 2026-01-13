<?php
/**
 * Authentication Middleware for School Portal Pages
 */

function requireSchoolAuth() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Check if user is authenticated
    if (!isset($_SESSION['school_auth'])) {
        // Get school slug from URL path
        $urlPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $pattern = '/\/tenant\/([a-zA-Z0-9_-]+)\//';
        
        if (preg_match($pattern, $urlPath, $matches)) {
            $schoolSlug = $matches[1];
            header("Location: /tenant/$schoolSlug/login.php");
            exit;
        } else {
            header("Location: /tenant/login.php");
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
            header("Location: /tenant/$correctSlug/login.php");
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
        header("Location: /tenant/{$auth['school_slug']}/{$auth['user_type']}/school-dashboard.php");
        exit;
    }
    
    return $auth;
}
?>