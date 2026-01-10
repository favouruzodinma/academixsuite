<?php
/**
 * AcademixSuite - Main Entry Point
 */

// Prevent direct access to includes
define('ACADEMIXSUITE', true);

// Load autoloader
require_once __DIR__ . '/includes/autoload.php';

// Detect school from request
$school = Tenant::detect();

if ($school) {
    // School-specific access
    define('CURRENT_SCHOOL', $school['id']);
    define('CURRENT_SCHOOL_SLUG', $school['slug']);
    
    // Check if user is logged in
    if (SchoolSession::validate()) {
        // User is logged in, redirect to dashboard
        header('Location: ' . SchoolSession::getDashboardUrl());
        exit;
    } else {
        // Show school login page
        include __DIR__ . '/school/login.php';
    }
} else {
    // Platform access (super admin or school signup)
    if (strpos($_SERVER['REQUEST_URI'], '/platform/') !== false) {
        // Platform admin area
        $auth = new Auth();
        if (!$auth->isLoggedIn('super_admin')) {
            header('Location: /platform/login.php');
            exit;
        }
        include __DIR__ . '/platform/admin/dashboard.php';
    } else {
        // Public landing page
        include __DIR__ . '/public/index.html';
    }
}
?>