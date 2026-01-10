<?php
/**
 * Auto-load classes and dependencies
 */

spl_autoload_register(function ($className) {
    $baseDir = __DIR__ . '/';
    $prefix = 'AcademixSuite\\';
    
    $len = strlen($prefix);
    if (strncmp($prefix, $className, $len) !== 0) {
        return;
    }
    
    $relativeClass = substr($className, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
    
    if (file_exists($file)) {
        require $file;
    }
});

// Load configuration
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/functions.php';

// Load core classes
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Session.php';
require_once __DIR__ . '/Tenant.php';
require_once __DIR__ . '/SchoolSession.php';
require_once __DIR__ . '/Utils.php';

// Initialize session if not already started
if (session_status() === PHP_SESSION_NONE && !defined('NO_SESSION')) {
    session_start();
}

// In your autoload.php or config file
function admin_url($path = '') {
    // Determine base URL based on current location
    $base = dirname($_SERVER['PHP_SELF']);
    
    // Remove /admin from path if already in it
    if (strpos($base, '/admin') !== false) {
        $base = dirname($base);
    }
    
    // If we're already at root level (platform/admin/)
    if (basename($base) == 'admin') {
        return $path ? './' . $path : './';
    }
    
    return $path ? '../' . $path : '../';
}
?>