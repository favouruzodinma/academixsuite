<?php
/**
 * Get CSRF Token for AJAX requests
 */
require_once __DIR__ . '/autoload.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Generate and return CSRF token
$token = generateCsrfToken();
Utils::jsonResponse(['token' => $token]);
?>