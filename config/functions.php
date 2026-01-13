<?php
/**
 * Global Helper Functions
 */

// Load database configuration
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/constants.php';

/**
 * Sanitize input data
 * @param mixed $data
 * @return mixed
 */
function sanitize($data) {
    if (is_array($data)) {
        return array_map('sanitize', $data);
    }
    
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}

/**
 * Generate CSRF token
 * @return string
 */
function generateCsrfToken() {
    if (!isset($_SESSION['csrf_tokens'])) {
        $_SESSION['csrf_tokens'] = [];
    }
    
    $token = bin2hex(random_bytes(32));
    $_SESSION['csrf_tokens'][$token] = time() + CSRF_TOKEN_EXPIRY;
    
    // Clean expired tokens
    foreach ($_SESSION['csrf_tokens'] as $storedToken => $expiry) {
        if ($expiry < time()) {
            unset($_SESSION['csrf_tokens'][$storedToken]);
        }
    }
    
    return $token;
}

/**
 * Validate CSRF token
 * @param string $token
 * @return bool
 */
function validateCsrfToken($token) {
    if (!isset($_SESSION['csrf_tokens'][$token])) {
        return false;
    }
    
    if ($_SESSION['csrf_tokens'][$token] < time()) {
        unset($_SESSION['csrf_tokens'][$token]);
        return false;
    }
    
    unset($_SESSION['csrf_tokens'][$token]);
    return true;
}

/**
 * Redirect with message
 * @param string $url
 * @param string $type success|error|info|warning
 * @param string $message
 */
function redirect($url, $type = null, $message = null) {
    if ($type && $message) {
        $_SESSION['flash'][$type] = $message;
    }
    
    header("Location: $url");
    exit;
}

/**
 * Get flash message
 * @param string $type
 * @return string|null
 */
function getFlash($type) {
    if (isset($_SESSION['flash'][$type])) {
        $message = $_SESSION['flash'][$type];
        unset($_SESSION['flash'][$type]);
        return $message;
    }
    return null;
}

/**
 * Hash password
 * @param string $password
 * @return string
 */
function hashPassword($password) {
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
}

/**
 * Verify password
 * @param string $password
 * @param string $hash
 * @return bool
 */
function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

/**
 * Generate random string
 * @param int $length
 * @return string
 */
function generateRandomString($length = 10) {
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $randomString = '';
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[rand(0, strlen($characters) - 1)];
    }
    return $randomString;
}

/**
 * Generate student admission number
 * @param int $schoolId
 * @param int $year
 * @return string
 */
function generateAdmissionNumber($schoolId, $year) {
    $db = getDBConnection();
    $prefix = strtoupper(substr(APP_NAME, 0, 2));
    $schoolCode = str_pad($schoolId, 3, '0', STR_PAD_LEFT);
    $yearCode = substr($year, -2);
    
    // Get last admission number for this school/year
    $stmt = $db->prepare("
        SELECT admission_number FROM students 
        WHERE school_id = ? AND YEAR(admission_date) = ? 
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute([$schoolId, $year]);
    $last = $stmt->fetch();
    
    if ($last && preg_match('/\d{4}$/', $last['admission_number'], $matches)) {
        $nextNum = (int)$matches[0] + 1;
    } else {
        $nextNum = 1;
    }
    
    $serial = str_pad($nextNum, 4, '0', STR_PAD_LEFT);
    return $prefix . $schoolCode . $yearCode . $serial;
}

/**
 * Format currency
 * @param float $amount
 * @return string
 */
function formatCurrency($amount) {
    return CURRENCY_SYMBOL . number_format($amount, 2);
}

/**
 * Format date for display
 * @param string $date
 * @param string $format
 * @return string
 */
function formatDate($date, $format = DISPLAY_DATE_FORMAT) {
    if (empty($date) || $date == '0000-00-00') {
        return 'N/A';
    }
    return date($format, strtotime($date));
}

/**
 * Calculate age from date of birth
 * @param string $dob
 * @return int
 */
function calculateAge($dob) {
    $birthDate = new DateTime($dob);
    $today = new DateTime();
    $age = $today->diff($birthDate);
    return $age->y;
}

/**
 * Validate email
 * @param string $email
 * @return bool
 */
function isValidEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Validate phone number (Nigeria format)
 * @param string $phone
 * @return bool
 */
function isValidPhone($phone) {
    $phone = preg_replace('/[^0-9]/', '', $phone);
    return preg_match('/^(0|234)(7|8|9)(0|1)\d{8}$/', $phone);
}

/**
 * Generate pagination links
 * @param int $totalItems
 * @param int $currentPage
 * @param int $perPage
 * @param string $baseUrl
 * @return string HTML
 */
function generatePagination($totalItems, $currentPage, $perPage = ITEMS_PER_PAGE, $baseUrl = '') {
    $totalPages = ceil($totalItems / $perPage);
    
    if ($totalPages <= 1) {
        return '';
    }
    
    $html = '<nav><ul class="pagination">';
    
    // Previous button
    if ($currentPage > 1) {
        $html .= '<li class="page-item"><a class="page-link" href="' . $baseUrl . '?page=' . ($currentPage - 1) . '">&laquo;</a></li>';
    }
    
    // Page numbers
    $start = max(1, $currentPage - floor(MAX_PAGE_LINKS / 2));
    $end = min($totalPages, $start + MAX_PAGE_LINKS - 1);
    
    for ($i = $start; $i <= $end; $i++) {
        $active = $i == $currentPage ? ' active' : '';
        $html .= '<li class="page-item' . $active . '"><a class="page-link" href="' . $baseUrl . '?page=' . $i . '">' . $i . '</a></li>';
    }
    
    // Next button
    if ($currentPage < $totalPages) {
        $html .= '<li class="page-item"><a class="page-link" href="' . $baseUrl . '?page=' . ($currentPage + 1) . '">&raquo;</a></li>';
    }
    
    $html .= '</ul></nav>';
    return $html;
}

/**
 * Upload file with validation
 * @param array $file $_FILES array element
 * @param string $type image|document
 * @param string $directory
 * @return array [success, message, filename]
 */
function uploadFile($file, $type = 'image', $directory = '') {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return [false, 'File upload error: ' . $file['error'], null];
    }
    
    // Check file size
    if ($file['size'] > MAX_UPLOAD_SIZE) {
        return [false, 'File size exceeds limit of ' . (MAX_UPLOAD_SIZE / 1024 / 1024) . 'MB', null];
    }
    
    // Get file extension
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    // Validate file type
    if ($type == 'image' && !in_array($extension, ALLOWED_IMAGE_TYPES)) {
        return [false, 'Invalid image type. Allowed: ' . implode(', ', ALLOWED_IMAGE_TYPES), null];
    }
    
    if ($type == 'document' && !in_array($extension, ALLOWED_DOC_TYPES)) {
        return [false, 'Invalid document type. Allowed: ' . implode(', ', ALLOWED_DOC_TYPES), null];
    }
    
    // Generate unique filename
    $filename = uniqid() . '_' . time() . '.' . $extension;
    $uploadPath = UPLOAD_PATH . $directory . '/' . $filename;
    
    // Ensure directory exists
    $dirPath = dirname($uploadPath);
    if (!is_dir($dirPath)) {
        mkdir($dirPath, 0755, true);
    }
    
    // Move uploaded file
    if (move_uploaded_file($file['tmp_name'], $uploadPath)) {
        return [true, 'File uploaded successfully', $filename];
    }
    
    return [false, 'Failed to move uploaded file', null];
}

/**
 * Send email
 * @param string $to
 * @param string $subject
 * @param string $body
 * @param array $attachments
 * @return bool
 */
function sendEmail($to, $subject, $body, $attachments = []) {
    // In production, use PHPMailer or AWS SES
    // This is a simplified version
    
    $headers = "From: " . APP_NAME . " <noreply@" . parse_url(APP_URL, PHP_URL_HOST) . ">\r\n";
    $headers .= "Reply-To: support@" . parse_url(APP_URL, PHP_URL_HOST) . "\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    
    if (APP_DEBUG) {
        // Log email instead of sending in debug mode
        error_log("Email to $to: $subject");
        return true;
    }
    
    return mail($to, $subject, $body, $headers);
}

/**
 * Log error
 * @param string $message
 * @param string $level ERROR|WARNING|INFO|DEBUG
 */
function logError($message, $level = 'ERROR') {
    if (!LOG_ERRORS) {
        return;
    }
    
    $levels = ['DEBUG' => 1, 'INFO' => 2, 'WARNING' => 3, 'ERROR' => 4];
    $currentLevel = defined('LOG_LEVEL') ? constant('LOG_LEVEL') : 'ERROR';
    
    if ($levels[$level] < $levels[$currentLevel]) {
        return;
    }
    
    $logFile = LOG_DIR . date('Y-m-d') . '.log';
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] [$level] $message" . PHP_EOL;
    
    if (!is_dir(LOG_DIR)) {
        mkdir(LOG_DIR, 0755, true);
    }
    
    file_put_contents($logFile, $logMessage, FILE_APPEND);
}

/**
 * Check if user has permission
 * @param string $permission
 * @param array $userPermissions
 * @return bool
 */
function hasPermission($permission, $userPermissions) {
    if (empty($userPermissions)) {
        return false;
    }
    
    // Check if user has the specific permission
    if (in_array($permission, $userPermissions)) {
        return true;
    }
    
    // Check for wildcard permissions
    foreach ($userPermissions as $userPerm) {
        if ($userPerm === '*') {
            return true;
        }
        
        // Check for pattern matching (e.g., 'student.*')
        if (strpos($userPerm, '*') !== false) {
            $pattern = '/^' . str_replace('*', '.*', $userPerm) . '$/';
            if (preg_match($pattern, $permission)) {
                return true;
            }
        }
    }
    
    return false;
}

/**
 * Get current school from session
 * @return array|null
 */
function getCurrentSchool() {
    if (isset($_SESSION['school_id'])) {
        $db = getDBConnection();
        $stmt = $db->prepare("SELECT * FROM schools WHERE id = ?");
        $stmt->execute([$_SESSION['school_id']]);
        return $stmt->fetch();
    }
    return null;
}

/**
 * Check if running in demo mode
 * @return bool
 */
function isDemoMode() {
    return defined('DEMO_MODE') && DEMO_MODE;
}

/**
 * Escape string for SQL
 * @param string $string
 * @return string
 */
function escapeSQL($string) {
    $db = getDBConnection();
    return $db->quote($string);
}

/**
 * Get current academic year - FIXED VERSION
 * @param int $schoolId
 * @return array|null
 */
function getCurrentAcademicYear($schoolId) {
    try {
        // Use the Database class directly instead of getDBConnection()
        $db = Database::getSchoolConnection(DB_SCHOOL_PREFIX . $schoolId);
        $stmt = $db->prepare("
            SELECT * FROM academic_years 
            WHERE school_id = ? AND status = 'active' 
            ORDER BY is_default DESC, id DESC LIMIT 1
        ");
        $stmt->execute([$schoolId]);
        return $stmt->fetch();
    } catch (Exception $e) {
        error_log("Error getting academic year: " . $e->getMessage());
        return null;
    }
}

// Initialize session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Set default timezone
date_default_timezone_set('Africa/Lagos');
?>