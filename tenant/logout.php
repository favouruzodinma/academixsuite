<?php
// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_name('academix_tenant');
    session_start();
}

// Get school slug from session
$schoolSlug = $_SESSION['school_auth']['school_slug'] ?? '';

// Clear session
$_SESSION = [];
session_destroy();
setcookie(session_name(), '', time() - 3600, '/');

// Redirect to login with school slug
if (!empty($schoolSlug)) {
    header("Location: ./login.php?school_slug=" . urlencode($schoolSlug));
} else {
    header("Location: ./login.php");
}
exit;
?>