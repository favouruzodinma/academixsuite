<?php
/**
 * Global Helper Functions
 */

// Load database configuration
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/constants.php';

/**
 * Read an environment variable. Thin global-namespace wrapper around the
 * namespaced helper class. config/mail.php, config/payment.php, and other
 * non-namespaced files call env(...) directly — they would otherwise fatal
 * with "Call to undefined function env()" because the EnvHelper file itself
 * lives under `namespace AcademixSuite\Helpers;` and its env() shim is not
 * visible from the global namespace.
 *
 * Order of resolution:
 *   1. AcademixSuite\Helpers\EnvHelper (once loaded — populated from .env)
 *   2. getenv()
 *   3. $_ENV / $_SERVER
 *   4. $default
 */
if (!function_exists('env')) {
    function env(string $key, $default = null) {
        if (class_exists('\\AcademixSuite\\Helpers\\EnvHelper')) {
            $value = \AcademixSuite\Helpers\EnvHelper::get($key, null);
            if ($value !== null) {
                return $value;
            }
        }
        $value = getenv($key);
        if ($value !== false) {
            return $value;
        }
        if (array_key_exists($key, $_ENV))    return $_ENV[$key];
        if (array_key_exists($key, $_SERVER)) return $_SERVER[$key];
        return $default;
    }
}

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
 * Get the configured platform URL without a trailing slash.
 */
function app_url() {
    if (defined('APP_URL') && APP_URL) {
        return rtrim(APP_URL, '/');
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host;
}

/**
 * Get the base host used for wildcard school subdomains.
 */
function app_base_host() {
    $host = parse_url(app_url(), PHP_URL_HOST) ?: ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $host = strtolower(preg_replace('/:\d+$/', '', $host));

    if (strpos($host, 'www.') === 0) {
        $host = substr($host, 4);
    }

    return $host;
}

/**
 * Reserved subdomains that must never be interpreted as school slugs.
 */
function reserved_school_subdomains() {
    return [
        'www',
        'app',
        'admin',
        'platform',
        'api',
        'mail',
        'webmail',
        'cpanel',
        'whm',
        'ftp',
        'autodiscover',
        'localhost'
    ];
}

/**
 * Detect the current school slug from a wildcard subdomain request.
 */
function school_subdomain_slug() {
    $host = strtolower($_SERVER['HTTP_HOST'] ?? '');
    $host = preg_replace('/:\d+$/', '', $host);
    $baseHost = app_base_host();

    if ($host === '' || $baseHost === '' || $host === $baseHost || $host === 'www.' . $baseHost) {
        return null;
    }

    $suffix = '.' . $baseHost;
    if (substr($host, -strlen($suffix)) !== $suffix) {
        return null;
    }

    $subdomain = substr($host, 0, -strlen($suffix));

    if ($subdomain === '' || strpos($subdomain, '.') !== false || in_array($subdomain, reserved_school_subdomains(), true)) {
        return null;
    }

    return preg_match('/^[a-z0-9-]+$/', $subdomain) ? $subdomain : null;
}

/**
 * Check whether this request is already on the school's wildcard subdomain.
 */
function is_school_subdomain_request($schoolSlug = null) {
    $subdomain = school_subdomain_slug();

    if (!$subdomain) {
        return false;
    }

    return $schoolSlug === null || strtolower((string)$schoolSlug) === $subdomain;
}

/**
 * Generate a public school portal URL.
 *
 * Production domains use: https://demo.academixsuite.com/admin/dashboard.php
 * Local/IP environments fall back to: /tenant/demo/admin/dashboard.php
 */
function school_portal_url($schoolSlug, $path = '', $absolute = true) {
    $schoolSlug = trim((string)$schoolSlug, " \t\n\r\0\x0B/");
    $path = ltrim((string)$path, '/');

    if ($schoolSlug === '') {
        return $absolute ? app_url() : '/';
    }

    $baseHost = app_base_host();
    $isLocalHost = in_array($baseHost, ['localhost', '127.0.0.1'], true) || filter_var($baseHost, FILTER_VALIDATE_IP);

    if (!$isLocalHost && preg_match('/^[a-z0-9-]+$/i', $schoolSlug)) {
        $scheme = parse_url(app_url(), PHP_URL_SCHEME) ?: 'https';
        $url = $scheme . '://' . strtolower($schoolSlug) . '.' . $baseHost;
        return $path !== '' ? $url . '/' . $path : $url . '/';
    }

    $tenantPath = '/tenant/' . rawurlencode($schoolSlug) . '/';
    if ($path !== '') {
        $tenantPath .= $path;
    }

    return $absolute ? app_url() . $tenantPath : $tenantPath;
}

/**
 * Determine whether this installation can use wildcard school subdomains.
 */
function school_subdomain_urls_enabled($schoolSlug = '') {
    $schoolSlug = trim((string)$schoolSlug, " \t\n\r\0\x0B/");
    if ($schoolSlug === '' || !preg_match('/^[a-z0-9-]+$/i', $schoolSlug)) {
        return false;
    }

    $baseHost = app_base_host();
    return !in_array($baseHost, ['localhost', '127.0.0.1'], true)
        && !filter_var($baseHost, FILTER_VALIDATE_IP);
}

/**
 * Redirect old tenant URLs to the canonical wildcard subdomain URL.
 *
 * Example:
 * /tenant/login.php?school_slug=demo -> https://demo.academixsuite.com/login.php
 * /tenant/school_profile.php?slug=demo -> https://demo.academixsuite.com/
 */
function redirect_legacy_school_url_to_subdomain($schoolSlug, $path = 'login.php', array $query = []) {
    $schoolSlug = trim((string)$schoolSlug, " \t\n\r\0\x0B/");
    if (!school_subdomain_urls_enabled($schoolSlug) || is_school_subdomain_request($schoolSlug)) {
        return;
    }

    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if (!in_array($method, ['GET', 'HEAD'], true)) {
        return;
    }

    $host = strtolower($_SERVER['HTTP_HOST'] ?? '');
    $host = preg_replace('/:\d+$/', '', $host);
    $baseHost = app_base_host();
    $isPlatformHost = $host === $baseHost || $host === 'www.' . $baseHost;
    if (!$isPlatformHost) {
        return;
    }

    unset($query['school_slug'], $query['slug']);
    $target = school_portal_url($schoolSlug, $path, true);
    if (!empty($query)) {
        $target .= (strpos($target, '?') === false ? '?' : '&') . http_build_query($query);
    }

    header('Location: ' . $target, true, 301);
    exit;
}

/**
 * Generate a URL for the current request context, keeping subdomain URLs clean.
 */
function school_route_url($schoolSlug, $userType = '', $page = '', $absolute = false) {
    $pathParts = array_filter([trim((string)$userType, '/'), ltrim((string)$page, '/')], static function ($part) {
        return $part !== '';
    });
    $path = implode('/', $pathParts);

    if (is_school_subdomain_request($schoolSlug)) {
        $relative = '/' . $path;
        if ($relative === '/') {
            return $absolute ? school_portal_url($schoolSlug, '', true) : '/';
        }

        return $absolute ? school_portal_url($schoolSlug, $path, true) : $relative;
    }

    return school_portal_url($schoolSlug, $path, $absolute);
}

/**
 * Generate a school login URL for the current context.
 */
function school_login_url($schoolSlug = '', $absolute = false) {
    if ($schoolSlug !== '' && is_school_subdomain_request($schoolSlug)) {
        return $absolute ? school_portal_url($schoolSlug, 'login.php', true) : '/login.php';
    }

    if ($schoolSlug !== '') {
        if (school_subdomain_urls_enabled($schoolSlug)) {
            return school_portal_url($schoolSlug, 'login.php', true);
        }

        $url = $absolute ? app_url() . '/tenant/login.php' : '/tenant/login.php';
        return $url . '?school_slug=' . rawurlencode($schoolSlug);
    }

    return $absolute ? app_url() . '/tenant/login.php' : '/tenant/login.php';
}

/**
 * Generate an opaque token for public invoice payment links.
 */
function generate_invoice_access_token($bytes = 32) {
    return bin2hex(random_bytes((int)$bytes));
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
    if (stripos((string)$body, 'tenant/assets/images/logo.png') === false) {
        $logoUrl = function_exists('academix_logo_url')
            ? academix_logo_url(true)
            : rtrim((defined('APP_URL') ? APP_URL : 'https://www.academixsuite.com'), '/') . '/tenant/assets/images/logo.png';
        $brand = defined('APP_NAME') ? APP_NAME : 'AcademixSuite';
        $emailHeader = '<div style="background:#0f172a;padding:18px 22px;margin:0 0 24px 0;text-align:left;">'
            . '<img src="' . htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8') . '" alt="' . htmlspecialchars($brand, ENT_QUOTES, 'UTF-8') . '" style="height:36px;width:auto;display:block;">'
            . '</div>';

        if (preg_match('/<body\b[^>]*>/i', (string)$body)) {
            $body = preg_replace('/<body\b[^>]*>/i', '$0' . $emailHeader, (string)$body, 1);
        } else {
            $body = $emailHeader . (string)$body;
        }
    }
    
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
