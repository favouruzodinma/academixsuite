<?php
/**
 * Secure File Server
 * Serves uploaded files with permission checking
 */

require_once __DIR__ . '/../includes/Auth.php';
require_once __DIR__ . '/../includes/Tenant.php';

// Get parameters
$schoolId = $_GET['school'] ?? 0;
$filePath = $_GET['file'] ?? '';
$token = $_GET['token'] ?? '';

// Validate input
if (empty($schoolId) || empty($filePath)) {
    http_response_code(400);
    die('Invalid request');
}

// Security: Prevent directory traversal (must check before path construction)
if (strpos($filePath, '..') !== false || strpos($filePath, '/') === 0) {
    http_response_code(400);
    die('Invalid file path');
}

// Get full file path
$fullPath = __DIR__ . '/../assets/uploads/schools/' . $schoolId . '/' . $filePath;

// Security check
if (!$token && !checkFileAccess($schoolId, $filePath)) {
    http_response_code(403);
    die('Access denied');
}

// Validate token (if provided)
if ($token && !validateToken($token, $schoolId, $filePath)) {
    http_response_code(403);
    die('Invalid or expired token');
}

// Check if file exists
if (!file_exists($fullPath) || !is_file($fullPath)) {
    http_response_code(404);
    die('File not found');
}

// Security: Restrict file types (optional)
$allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'xlsx', 'txt'];
$extension = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));

if (!in_array($extension, $allowedExtensions)) {
    http_response_code(403);
    die('File type not allowed');
}

// Set headers and serve file
header('Content-Type: ' . mime_content_type($fullPath));
header('Content-Length: ' . filesize($fullPath));
header('Content-Disposition: inline; filename="' . basename($fullPath) . '"');
header('Cache-Control: private, max-age=86400');
header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 86400) . ' GMT');

readfile($fullPath);
exit;

/**
 * Check if user has permission to access file
 */
function checkFileAccess($schoolId, $filePath) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    if (!isset($_SESSION['school_user']) || $_SESSION['school_user']['school_id'] != $schoolId) {
        return false;
    }
    
    $userType = $_SESSION['school_user']['user_type'] ?? '';
    
    if (strpos($filePath, 'students/') === 0) {
        return in_array($userType, ['admin', 'teacher'], true);
    }
    
    if (strpos($filePath, 'teachers/') === 0) {
        return in_array($userType, ['admin', 'teacher'], true);
    }
    
    if (strpos($filePath, 'reports/') === 0) {
        return $userType === 'admin';
    }
    
    return true;
}

/**
 * Generate secure file access token
 */
function generateFileToken($schoolId, $filePath, $expiry = 3600) {
    $secret = defined('FILE_SERVER_SECRET') ? FILE_SERVER_SECRET : (getenv('FILE_SERVER_SECRET') ?: '');
    if (empty($secret)) {
        error_log('FILE_SERVER_SECRET not configured');
        return '';
    }
    
    $data = [
        'school_id' => $schoolId,
        'file_path' => $filePath,
        'expiry' => time() + $expiry,
        'ip' => $_SERVER['REMOTE_ADDR']
    ];
    
    $token = base64_encode(json_encode($data));
    $signature = hash_hmac('sha256', $token, $secret);
    
    return $token . '.' . $signature;
}

/**
 * Validate file access token
 */
function validateToken($token, $schoolId, $filePath) {
    $secret = defined('FILE_SERVER_SECRET') ? FILE_SERVER_SECRET : (getenv('FILE_SERVER_SECRET') ?: '');
    if (empty($secret)) {
        error_log('FILE_SERVER_SECRET not configured');
        return false;
    }
    
    $parts = explode('.', $token, 2);
    if (count($parts) !== 2) {
        return false;
    }
    list($data, $signature) = $parts;
    
    $expectedSignature = hash_hmac('sha256', $data, $secret);
    if (!hash_equals($expectedSignature, $signature)) {
        return false;
    }
    
    $data = json_decode(base64_decode($data), true);
    if (!$data) {
        return false;
    }
    
    if ($data['expiry'] < time()) {
        return false;
    }
    
    if (($data['ip'] ?? '') !== $_SERVER['REMOTE_ADDR']) {
        return false;
    }
    
    if ($data['school_id'] != $schoolId || $data['file_path'] != $filePath) {
        return false;
    }
    
    return true;
}
?>
