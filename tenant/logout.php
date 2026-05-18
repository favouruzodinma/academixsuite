<?php
// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_name('academix_tenant');
    require_once __DIR__ . '/../includes/session_config.php';
    session_start(academix_session_options());
}

require_once __DIR__ . '/../includes/autoload.php';

// Get school slug from session
$schoolSlug = $_SESSION['school_auth']['school_slug'] ?? ($_GET['school_slug'] ?? '');

// Clear session
$_SESSION = [];
session_destroy();
setcookie(session_name(), '', time() - 3600, '/');

// Redirect to login with school slug
if (!empty($schoolSlug)) {
    $loginUrl = function_exists('school_login_url') ? school_login_url($schoolSlug, false) : './login.php?school_slug=' . urlencode($schoolSlug);
    header("Location: {$loginUrl}");
} else {
    header("Location: " . (function_exists('school_login_url') ? school_login_url('', false) : './login.php'));
}
exit;
?>
