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

// Security check
if (!$token && !self::checkFileAccess($schoolId, $filePath)) {
    http_response_code(403);
    die('Access denied');
}

// Validate token (if provided)
if ($token && !self::validateToken($token, $schoolId, $filePath)) {
    http_response_code(403);
    die('Invalid or expired token');
}

// Get full file path
$fullPath = __DIR__ . '/../assets/uploads/schools/' . $schoolId . '/' . $filePath;

// Check if file exists
if (!file_exists($fullPath) || !is_file($fullPath)) {
    http_response_code(404);
    die('File not found');
}

// Security: Prevent directory traversal
if (strpos($filePath, '..') !== false) {
    http_response_code(400);
    die('Invalid file path');
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
    // Start session
    session_start();
    
    // Check if user is logged in and belongs to this school
    if (!isset($_SESSION['school_user']) || $_SESSION['school_user']['school_id'] != $schoolId) {
        return false;
    }
    
    // Additional permission checks based on file type/location
    $userType = $_SESSION['school_user']['user_type'];
    
    // Restrict access based on file location
    if (strpos($filePath, 'students/') === 0) {
        // Student files: only accessible to admins, teachers, and the specific student/parent
        return self::hasStudentFileAccess($schoolId, $filePath, $userType);
    }
    
    if (strpos($filePath, 'teachers/') === 0) {
        // Teacher files: only accessible to admins and the specific teacher
        return self::hasTeacherFileAccess($schoolId, $filePath, $userType);
    }
    
    if (strpos($filePath, 'reports/') === 0) {
        // Reports: only accessible to admins
        return $userType === 'admin';
    }
    
    // Public files (logo, announcements) are accessible to all logged-in users
    return true;
}

/**
 * Generate secure file access token
 */
function generateFileToken($schoolId, $filePath, $expiry = 3600) {
    $data = [
        'school_id' => $schoolId,
        'file_path' => $filePath,
        'expiry' => time() + $expiry,
        'ip' => $_SERVER['REMOTE_ADDR']
    ];
    
    $token = base64_encode(json_encode($data));
    $signature = hash_hmac('sha256', $token, 'your-secret-key-here');
    
    return $token . '.' . $signature;
}

/**
 * Validate file access token
 */
function validateToken($token, $schoolId, $filePath) {
    list($data, $signature) = explode('.', $token, 2);
    
    // Verify signature
    $expectedSignature = hash_hmac('sha256', $data, 'your-secret-key-here');
    if (!hash_equals($expectedSignature, $signature)) {
        return false;
    }
    
    // Decode data
    $data = json_decode(base64_decode($data), true);
    if (!$data) {
        return false;
    }
    
    // Check expiry
    if ($data['expiry'] < time()) {
        return false;
    }
    
    // Check IP (optional)
    if ($data['ip'] !== $_SERVER['REMOTE_ADDR']) {
        return false;
    }
    
    // Check school and file match
    if ($data['school_id'] != $schoolId || $data['file_path'] != $filePath) {
        return false;
    }
    
    return true;
}
?>