<?php
/**
 * AcademixSuite - Main Entry Point - SIMPLIFIED
 */

// Define application root
define('ROOT_PATH', __DIR__);

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_name('AcademixSuite_session');
    session_start();
}

// Load core classes
require_once __DIR__ . '/includes/autoload.php';

// Initialize error handler
ErrorHandler::register();

// Get request
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$requestUri = strtok($requestUri, '?');

// Simple routing - let .htaccess handle most things
if (strpos($requestUri, '/platform/') === 0) {
    // Platform admin
    require_once __DIR__ . '/platform/index.php';
    exit;
} elseif ($requestUri === '/login' || $requestUri === '/login.php') {
    // Global login
    if (isset($_SESSION['super_admin'])) {
        header('Location: ./platform/admin/dashboard.php');
    } elseif (isset($_SESSION['school_auth'])) {
        $schoolSlug = $_SESSION['school_auth']['school_slug'];
        $userType = $_SESSION['school_auth']['user_type'];
        header("Location: ./tenant/{$schoolSlug}/{$userType}/school-dashboard.php");
    } else {
        header('Location: ./tenant/login.php');
    }
    exit;
} else {
    // Default to public
    $publicPath = __DIR__ . '/public' . $requestUri;
    
    if (file_exists($publicPath) && is_file($publicPath)) {
        $ext = pathinfo($publicPath, PATHINFO_EXTENSION);
        if (in_array($ext, ['php', 'html', 'htm'])) {
            require_once $publicPath;
        } else {    
            header('Content-Type: ' . mime_content_type($publicPath));
            readfile($publicPath);
        }
    } elseif (file_exists($publicPath . '.php')) {
        require_once $publicPath . '.php';
    } elseif (file_exists($publicPath . '/index.php')) {
        require_once $publicPath . '/index.php';
    } else {
        require_once __DIR__ . '/public/index.php';
    }
    exit;
}
?>