this my file sturcture [academixsuite/
├── config/
│   ├── database.php
│   ├── constants.php
│   └── functions.php
├── includes/
│   ├── autoload.php
│   ├── ErrorHandler.php
│   ├── Database.php
│   ├── Auth.php
    ├── AppRouter.php
│   ├── Session.php
│   └── Utils.php
│   ├── Tenant.php          # NEW: Tenant management
│   └── SchoolSession.php   # NEW: School-specific session] the database.php [<?php
/**
 * Database Configuration
 * This file contains database connection settings for both local (XAMPP) and production (AWS)
 */

// Development environment detection
define('IS_LOCAL', $_SERVER['SERVER_NAME'] == 'localhost' || $_SERVER['SERVER_NAME'] == '127.0.0.1');

// Database Configuration for Platform (Shared Database)
if (IS_LOCAL) {
    // XAMPP Local Development
    define('DB_HOST', 'localhost');
    define('DB_PORT', '3306');
    define('DB_USER', 'root');
    define('DB_PASS', '');
    define('DB_PLATFORM_NAME', 'academixsuite_platform');
    define('DB_CHARSET', 'utf8mb4');
    define('DB_COLLATION', 'utf8mb4_unicode_ci');
} else {
    // AWS RDS Production
    define('DB_HOST', 'your-rds-endpoint.cluster-xxx.us-east-1.rds.amazonaws.com');
    define('DB_PORT', '3306');
    define('DB_USER', 'academixsuite_platform');
    define('DB_PASS', '!@#admin!@#');
    define('DB_PLATFORM_NAME', 'academixsuite_platform');
    define('DB_CHARSET', 'utf8mb4');
    define('DB_COLLATION', 'utf8mb4_unicode_ci');
}

// School Database Template
// Each school will have its own database like: school_1, school_2, etc.
define('DB_SCHOOL_PREFIX', 'school_');

// Connection Pool Settings
define('DB_MAX_CONNECTIONS', 20);
define('DB_IDLE_TIMEOUT', 300); // 5 minutes

// Enable/Disable Query Logging
define('DB_LOG_QUERIES', IS_LOCAL);
define('DB_LOG_FILE', __DIR__ . '/../logs/database.log');

// Database Driver (MySQLi or PDO)
define('DB_DRIVER', 'PDO'); // Options: PDO, MySQLi

// SSL/TLS for Production (AWS RDS)
define('DB_SSL_ENABLED', !IS_LOCAL);
define('DB_SSL_CA', __DIR__ . '/../certs/rds-combined-ca-bundle.pem');

// Connection retry settings
define('DB_CONNECT_RETRIES', 3);
define('DB_CONNECT_TIMEOUT', 5); // seconds

// Table Prefix (if needed)
define('DB_TABLE_PREFIX', '');

// Default timezone for database
date_default_timezone_set('Africa/Lagos');

// Error reporting
if (IS_LOCAL) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(E_ERROR | E_WARNING | E_PARSE);
    ini_set('display_errors', 0);
}

// Database backup settings
define('BACKUP_ENABLED', true);
define('BACKUP_DIR', __DIR__ . '/../backups');
define('BACKUP_RETENTION_DAYS', 7);
// Add this at the end:
// Global database functions (legacy support)
function getDBConnection($database = null) {
    return $database ? Database::getSchoolConnection($database) : Database::getPlatformConnection();
}

?>] ,constants.php [<?php
/**
 * Application Constants
 */

// Application Information
define('APP_NAME', 'AcademixSuite');
define('APP_VERSION', '1.0.0');
define('APP_ENV', IS_LOCAL ? 'development' : 'production');
define('APP_DEBUG', IS_LOCAL);
define('APP_URL', IS_LOCAL ? 'http://localhost/academixsuite' : 'https://academixsuite.com');

// File Upload Constants
define('MAX_UPLOAD_SIZE', 10 * 1024 * 1024); // 10MB
define('ALLOWED_IMAGE_TYPES', ['jpg', 'jpeg', 'png', 'gif', 'webp']);
define('ALLOWED_DOC_TYPES', ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'csv']);
define('UPLOAD_PATH', __DIR__ . '/../assets/uploads/');

// User Roles Constants
define('ROLE_SUPER_ADMIN', 'super_admin');
define('ROLE_SCHOOL_ADMIN', 'admin');
define('ROLE_TEACHER', 'teacher');
define('ROLE_STUDENT', 'student');
define('ROLE_PARENT', 'parent');
define('ROLE_ACCOUNTANT', 'accountant');
define('ROLE_LIBRARIAN', 'librarian');

// School Status Constants
define('SCHOOL_STATUS_PENDING', 'pending');
define('SCHOOL_STATUS_TRIAL', 'trial');
define('SCHOOL_STATUS_ACTIVE', 'active');
define('SCHOOL_STATUS_SUSPENDED', 'suspended');
define('SCHOOL_STATUS_CANCELLED', 'cancelled');

// Subscription Status
define('SUBSCRIPTION_ACTIVE', 'active');
define('SUBSCRIPTION_PENDING', 'pending');
define('SUBSCRIPTION_CANCELLED', 'cancelled');
define('SUBSCRIPTION_PAST_DUE', 'past_due');

// Attendance Status
define('ATTENDANCE_PRESENT', 'present');
define('ATTENDANCE_ABSENT', 'absent');
define('ATTENDANCE_LATE', 'late');
define('ATTENDANCE_HALF_DAY', 'half_day');

// Fee Status
define('FEE_PENDING', 'pending');
define('FEE_PARTIAL', 'partial');
define('FEE_PAID', 'paid');
define('FEE_OVERDUE', 'overdue');

// Exam Grade Scales
define('GRADE_A_PLUS', 'A+');
define('GRADE_A', 'A');
define('GRADE_B_PLUS', 'B+');
define('GRADE_B', 'B');
define('GRADE_C_PLUS', 'C+');
define('GRADE_C', 'C');
define('GRADE_D', 'D');
define('GRADE_F', 'F');

// Gender Options
define('GENDER_MALE', 'male');
define('GENDER_FEMALE', 'female');
define('GENDER_OTHER', 'other');

// Academic Term Types
define('TERM_FIRST', 'first');
define('TERM_SECOND', 'second');
define('TERM_THIRD', 'third');

// Notification Types
define('NOTIFICATION_EMAIL', 'email');
define('NOTIFICATION_SMS', 'sms');
define('NOTIFICATION_PUSH', 'push');

// Payment Methods
define('PAYMENT_CASH', 'cash');
define('PAYMENT_CHEQUE', 'cheque');
define('PAYMENT_BANK_TRANSFER', 'bank_transfer');
define('PAYMENT_CARD', 'card');
define('PAYMENT_ONLINE', 'online');

// Date Formats
define('DATE_FORMAT', 'Y-m-d');
define('DATETIME_FORMAT', 'Y-m-d H:i:s');
define('DISPLAY_DATE_FORMAT', 'd M, Y');
define('DISPLAY_DATETIME_FORMAT', 'd M, Y h:i A');

// Currency
define('CURRENCY', 'NGN');
define('CURRENCY_SYMBOL', '₦');

// Pagination
define('ITEMS_PER_PAGE', 20);
define('MAX_PAGE_LINKS', 5);

// Security
define('PASSWORD_MIN_LENGTH', 6);
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_TIME', 15 * 60); // 15 minutes in seconds
define('SESSION_TIMEOUT', 24 * 60 * 60); // 24 hours
define('CSRF_TOKEN_EXPIRY', 3600); // 1 hour

// File Paths
define('SCHOOL_LOGOS_PATH', 'assets/uploads/schools/logos/');
define('STUDENT_PHOTOS_PATH', 'assets/uploads/schools/students/photos/');
define('TEACHER_PHOTOS_PATH', 'assets/uploads/schools/teachers/photos/');
define('ASSIGNMENTS_PATH', 'assets/uploads/schools/assignments/');
define('REPORTS_PATH', 'assets/uploads/schools/reports/');

// API Keys (Should be in environment variables in production)
if (IS_LOCAL) {
    define('SMS_API_KEY', 'test_key');
    define('EMAIL_API_KEY', 'test_key');
    define('PAYMENT_PUBLIC_KEY', 'test_key');
    define('PAYMENT_SECRET_KEY', 'test_key');
} else {
    define('SMS_API_KEY', getenv('SMS_API_KEY'));
    define('EMAIL_API_KEY', getenv('EMAIL_API_KEY'));
    define('PAYMENT_PUBLIC_KEY', getenv('PAYMENT_PUBLIC_KEY'));
    define('PAYMENT_SECRET_KEY', getenv('PAYMENT_SECRET_KEY'));
}

// Cache Settings
define('CACHE_ENABLED', !IS_LOCAL);
define('CACHE_EXPIRY', 3600); // 1 hour
define('CACHE_DIR', __DIR__ . '/../cache/');

// Logging
define('LOG_ERRORS', true);
define('LOG_DIR', __DIR__ . '/../logs/');
define('LOG_LEVEL', IS_LOCAL ? 'DEBUG' : 'ERROR');

// Demo Mode
define('DEMO_MODE', false);
define('DEMO_SCHOOL_ID', 1);
?>], functions.php [<?php
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
 * Get current academic year
 * @param int $schoolId
 * @return array|null
 */
function getCurrentAcademicYear($schoolId) {
    $db = getDBConnection(DB_SCHOOL_PREFIX . $schoolId);
    $stmt = $db->prepare("
        SELECT * FROM academic_years 
        WHERE school_id = ? AND status = 'active' 
        ORDER BY is_default DESC, id DESC LIMIT 1
    ");
    $stmt->execute([$schoolId]);
    return $stmt->fetch();
}

// Initialize session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Set default timezone
date_default_timezone_set('Africa/Lagos');
?>],autoload.php [<?php
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
?>],Database.php [<?php
/**
 * Database Connection Manager
 * Handles connections to platform and school databases
 */

class Database {
    private static $platformConnection = null;
    private static $schoolConnections = [];
    private static $queryLog = [];
    
    /**
     * Get connection to platform database
     * @return PDO
     */
    public static function getPlatformConnection() {
        if (self::$platformConnection === null) {
            try {
                require_once __DIR__ . '/../config/database.php';
                require_once __DIR__ . '/../config/constants.php';
                
                $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . 
                       ";dbname=" . DB_PLATFORM_NAME . ";charset=" . DB_CHARSET;
                
                $options = [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_PERSISTENT => false,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . DB_CHARSET . 
                                                   " COLLATE " . DB_COLLATION
                ];
                
                if (DB_SSL_ENABLED && file_exists(DB_SSL_CA)) {
                    $options[PDO::MYSQL_ATTR_SSL_CA] = DB_SSL_CA;
                }
                
                self::$platformConnection = new PDO($dsn, DB_USER, DB_PASS, $options);
                
                // Set timezone
                self::$platformConnection->exec("SET time_zone = '+01:00'");
                
            } catch (PDOException $e) {
                self::logQuery('Connection Error', $e->getMessage(), 0);
                throw new Exception("Platform database connection failed: " . $e->getMessage());
            }
        }
        
        return self::$platformConnection;
    }
    
    /**
     * Get connection to a specific school's database
     * @param string $schoolDb Database name
     * @return PDO
     */
    public static function getSchoolConnection($schoolDb) {
        if (!isset(self::$schoolConnections[$schoolDb])) {
            try {
                require_once __DIR__ . '/../config/database.php';
                
                $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . 
                       ";dbname=" . $schoolDb . ";charset=" . DB_CHARSET;
                
                $options = [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_PERSISTENT => false,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . DB_CHARSET . 
                                                   " COLLATE " . DB_COLLATION
                ];
                
                if (DB_SSL_ENABLED && file_exists(DB_SSL_CA)) {
                    $options[PDO::MYSQL_ATTR_SSL_CA] = DB_SSL_CA;
                }
                
                self::$schoolConnections[$schoolDb] = new PDO($dsn, DB_USER, DB_PASS, $options);
                
                // Set timezone
                self::$schoolConnections[$schoolDb]->exec("SET time_zone = '+01:00'");
                
            } catch (PDOException $e) {
                self::logQuery('Connection Error', "School DB: $schoolDb - " . $e->getMessage(), 0);
                throw new Exception("School database connection failed: " . $e->getMessage());
            }
        }
        
        return self::$schoolConnections[$schoolDb];
    }
    
    /**
     * Create a new school database
     * @param string $schoolDb Database name
     * @param string $templateSql SQL template
     * @return bool
     */
    public static function createSchoolDatabase($schoolDb, $templateSql = '') {
        try {
            $platformDb = self::getPlatformConnection();
            
            // Create database
            $platformDb->exec("CREATE DATABASE IF NOT EXISTS `$schoolDb` 
                              CHARACTER SET " . DB_CHARSET . " 
                              COLLATE " . DB_COLLATION);
            
            // Import template if provided
            if (!empty($templateSql)) {
                $schoolDbConn = self::getSchoolConnection($schoolDb);
                
                // Split SQL by semicolon, but preserve within quotes
                $queries = self::splitSQL($templateSql);
                
                foreach ($queries as $query) {
                    if (trim($query) !== '') {
                        $schoolDbConn->exec($query);
                    }
                }
            }
            
            self::logQuery('CREATE DATABASE', $schoolDb, 0);
            return true;
            
        } catch (PDOException $e) {
            self::logQuery('CREATE DATABASE Error', $schoolDb . " - " . $e->getMessage(), 0);
            throw new Exception("Failed to create school database: " . $e->getMessage());
        }
    }
    
    /**
     * Drop a school database
     * @param string $schoolDb Database name
     * @return bool
     */
    public static function dropSchoolDatabase($schoolDb) {
        try {
            $platformDb = self::getPlatformConnection();
            $platformDb->exec("DROP DATABASE IF EXISTS `$schoolDb`");
            
            // Remove from connections cache
            if (isset(self::$schoolConnections[$schoolDb])) {
                unset(self::$schoolConnections[$schoolDb]);
            }
            
            self::logQuery('DROP DATABASE', $schoolDb, 0);
            return true;
            
        } catch (PDOException $e) {
            self::logQuery('DROP DATABASE Error', $schoolDb . " - " . $e->getMessage(), 0);
            return false;
        }
    }
    
    /**
     * Check if school database exists
     * @param string $schoolDb Database name
     * @return bool
     */
    public static function schoolDatabaseExists($schoolDb) {
        try {
            $platformDb = self::getPlatformConnection();
            $stmt = $platformDb->query("SHOW DATABASES LIKE '$schoolDb'");
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            return false;
        }
    }
    
    /**
     * Get list of all school databases
     * @return array
     */
    public static function getAllSchoolDatabases() {
        try {
            $platformDb = self::getPlatformConnection();
            $stmt = $platformDb->query("SHOW DATABASES LIKE '" . DB_SCHOOL_PREFIX . "%'");
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            return [];
        }
    }
    
    /**
     * Execute query with logging
     * @param PDO $connection
     * @param string $sql
     * @param array $params
     * @return PDOStatement
     */
    public static function executeQuery($connection, $sql, $params = []) {
        $startTime = microtime(true);
        
        try {
            $stmt = $connection->prepare($sql);
            $stmt->execute($params);
            
            $executionTime = microtime(true) - $startTime;
            self::logQuery($sql, $params, $executionTime);
            
            return $stmt;
            
        } catch (PDOException $e) {
            $executionTime = microtime(true) - $startTime;
            self::logQuery($sql . " [ERROR]", $params . " - " . $e->getMessage(), $executionTime);
            throw $e;
        }
    }
    
    /**
     * Insert record and return last insert ID
     * @param PDO $connection
     * @param string $table
     * @param array $data
     * @return int
     */
    public static function insert($connection, $table, $data) {
        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $values = array_values($data);
        
        $sql = "INSERT INTO $table ($columns) VALUES ($placeholders)";
        $stmt = self::executeQuery($connection, $sql, $values);
        
        return $connection->lastInsertId();
    }
    
    /**
     * Update record
     * @param PDO $connection
     * @param string $table
     * @param array $data
     * @param string $where
     * @param array $whereParams
     * @return int Affected rows
     */
    public static function update($connection, $table, $data, $where, $whereParams = []) {
        $setParts = [];
        $values = [];
        
        foreach ($data as $column => $value) {
            $setParts[] = "$column = ?";
            $values[] = $value;
        }
        
        $setClause = implode(', ', $setParts);
        $sql = "UPDATE $table SET $setClause WHERE $where";
        
        // Merge values with where params
        $allParams = array_merge($values, $whereParams);
        
        $stmt = self::executeQuery($connection, $sql, $allParams);
        return $stmt->rowCount();
    }
    
    /**
     * Delete record
     * @param PDO $connection
     * @param string $table
     * @param string $where
     * @param array $params
     * @return int Affected rows
     */
    public static function delete($connection, $table, $where, $params = []) {
        $sql = "DELETE FROM $table WHERE $where";
        $stmt = self::executeQuery($connection, $sql, $params);
        return $stmt->rowCount();
    }
    
    /**
     * Select records
     * @param PDO $connection
     * @param string $table
     * @param string $columns
     * @param string $where
     * @param array $params
     * @param string $orderBy
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public static function select($connection, $table, $columns = '*', $where = '1', 
                                 $params = [], $orderBy = '', $limit = 0, $offset = 0) {
        $sql = "SELECT $columns FROM $table WHERE $where";
        
        if (!empty($orderBy)) {
            $sql .= " ORDER BY $orderBy";
        }
        
        if ($limit > 0) {
            $sql .= " LIMIT $limit";
            if ($offset > 0) {
                $sql .= " OFFSET $offset";
            }
        }
        
        $stmt = self::executeQuery($connection, $sql, $params);
        return $stmt->fetchAll();
    }
    
    /**
     * Count records
     * @param PDO $connection
     * @param string $table
     * @param string $where
     * @param array $params
     * @return int
     */
    public static function count($connection, $table, $where = '1', $params = []) {
        $sql = "SELECT COUNT(*) as count FROM $table WHERE $where";
        $stmt = self::executeQuery($connection, $sql, $params);
        $result = $stmt->fetch();
        return (int)$result['count'];
    }
    
    /**
     * Backup database
     * @param string $database
     * @param string $backupPath
     * @return bool
     */
    public static function backupDatabase($database, $backupPath = null) {
        if ($backupPath === null) {
            $backupPath = BACKUP_DIR . '/' . $database . '_' . date('Y-m-d_H-i-s') . '.sql';
        }
        
        // Ensure backup directory exists
        if (!is_dir(dirname($backupPath))) {
            mkdir(dirname($backupPath), 0755, true);
        }
        
        $command = "mysqldump --host=" . DB_HOST . 
                   " --user=" . DB_USER . 
                   " --password=" . DB_PASS . 
                   " $database > $backupPath 2>&1";
        
        exec($command, $output, $returnCode);
        
        if ($returnCode === 0) {
            self::logQuery('BACKUP', "Database $database backed up to $backupPath", 0);
            return true;
        } else {
            self::logQuery('BACKUP ERROR', "Failed to backup $database: " . implode("\n", $output), 0);
            return false;
        }
    }
    
    /**
     * Restore database from backup
     * @param string $database
     * @param string $backupFile
     * @return bool
     */
    public static function restoreDatabase($database, $backupFile) {
        if (!file_exists($backupFile)) {
            throw new Exception("Backup file not found: $backupFile");
        }
        
        $command = "mysql --host=" . DB_HOST . 
                   " --user=" . DB_USER . 
                   " --password=" . DB_PASS . 
                   " $database < $backupFile 2>&1";
        
        exec($command, $output, $returnCode);
        
        if ($returnCode === 0) {
            self::logQuery('RESTORE', "Database $database restored from $backupFile", 0);
            return true;
        } else {
            self::logQuery('RESTORE ERROR', "Failed to restore $database: " . implode("\n", $output), 0);
            return false;
        }
    }
    
    /**
     * Split SQL string into individual queries
     * @param string $sql
     * @return array
     */
    private static function splitSQL($sql) {
        $queries = [];
        $currentQuery = '';
        $inString = false;
        $stringChar = '';
        
        for ($i = 0; $i < strlen($sql); $i++) {
            $char = $sql[$i];
            
            // Handle string literals
            if (($char == "'" || $char == '"') && ($i == 0 || $sql[$i-1] != '\\')) {
                if (!$inString) {
                    $inString = true;
                    $stringChar = $char;
                } elseif ($char == $stringChar) {
                    $inString = false;
                }
            }
            
            // Add character to current query
            $currentQuery .= $char;
            
            // Check for end of query (semicolon not in string)
            if ($char == ';' && !$inString) {
                $queries[] = $currentQuery;
                $currentQuery = '';
            }
        }
        
        // Add any remaining query
        if (trim($currentQuery) !== '') {
            $queries[] = $currentQuery;
        }
        
        return $queries;
    }
    
    /**
     * Log query for debugging
     * @param string $sql
     * @param mixed $params
     * @param float $executionTime
     */
    private static function logQuery($sql, $params, $executionTime) {
        if (!DB_LOG_QUERIES) {
            return;
        }
        
        $logEntry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'sql' => $sql,
            'params' => is_array($params) ? json_encode($params) : $params,
            'time' => round($executionTime * 1000, 2) . 'ms'
        ];
        
        self::$queryLog[] = $logEntry;
        
        // Write to log file if enabled
        if (defined('DB_LOG_FILE')) {
            $logMessage = "[" . $logEntry['timestamp'] . "] " .
                         $logEntry['sql'] . " | " .
                         $logEntry['params'] . " | " .
                         $logEntry['time'] . PHP_EOL;
            
            file_put_contents(DB_LOG_FILE, $logMessage, FILE_APPEND);
        }
    }
    
    /**
     * Get query log
     * @return array
     */
    public static function getQueryLog() {
        return self::$queryLog;
    }
    
    /**
     * Clear query log
     */
    public static function clearQueryLog() {
        self::$queryLog = [];
    }
    
    /**
     * Close all database connections
     */
    public static function closeAllConnections() {
        self::$platformConnection = null;
        self::$schoolConnections = [];
    }
}
?>],Utils.php [<?php
/**
 * Utility Functions
 * Various helper functions used throughout the application
 */

class Utils {
    
    /**
     * Generate unique slug from string
     * @param string $string
     * @param string $table
     * @param string $column
     * @return string
     */
    public static function generateSlug($string, $table = null, $column = 'slug') {
        $slug = strtolower(trim($string));
        $slug = preg_replace('/[^a-z0-9-]/', '-', $slug);
        $slug = preg_replace('/-+/', '-', $slug);
        $slug = trim($slug, '-');
        
        // If table is provided, ensure uniqueness
        if ($table) {
            $db = Database::getPlatformConnection();
            $counter = 1;
            $originalSlug = $slug;
            
            while (true) {
                $stmt = $db->prepare("SELECT COUNT(*) as count FROM $table WHERE $column = ?");
                $stmt->execute([$slug]);
                $result = $stmt->fetch();
                
                if ($result['count'] == 0) {
                    break;
                }
                
                $slug = $originalSlug . '-' . $counter;
                $counter++;
            }
        }
        
        return $slug;
    }
    
    /**
     * Generate random password
     * @param int $length
     * @return string
     */
    public static function generateRandomPassword($length = 12) {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()';
        $password = '';
        
        for ($i = 0; $i < $length; $i++) {
            $password .= $chars[random_int(0, strlen($chars) - 1)];
        }
        
        return $password;
    }
    
    /**
     * Validate Nigerian phone number
     * @param string $phone
     * @return bool
     */
    public static function isValidNigerianPhone($phone) {
        // Remove all non-numeric characters
        $phone = preg_replace('/[^0-9]/', '', $phone);
        
        // Check if it's a valid Nigerian number
        // Nigerian numbers: 080, 081, 070, 071, 090, 091, etc.
        return preg_match('/^(0|234)(7|8|9)(0|1)\d{8}$/', $phone);
    }
    
    /**
     * Format Nigerian phone number
     * @param string $phone
     * @return string
     */
    public static function formatNigerianPhone($phone) {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        
        // Convert to international format if starts with 0
        if (strlen($phone) == 11 && substr($phone, 0, 1) == '0') {
            $phone = '234' . substr($phone, 1);
        }
        
        return $phone;
    }
    
    /**
     * Calculate age from date of birth
     * @param string $dob
     * @return int
     */
    public static function calculateAge($dob) {
        if (empty($dob) || $dob == '0000-00-00') {
            return 0;
        }
        
        $birthDate = new DateTime($dob);
        $today = new DateTime();
        $age = $today->diff($birthDate);
        return $age->y;
    }
    
    /**
     * Format date for display
     * @param string $date
     * @param string $format
     * @return string
     */
    public static function formatDate($date, $format = DISPLAY_DATE_FORMAT) {
        if (empty($date) || $date == '0000-00-00') {
            return 'N/A';
        }
        
        try {
            $dateObj = new DateTime($date);
            return $dateObj->format($format);
        } catch (Exception $e) {
            return $date;
        }
    }
    
    /**
     * Format date and time for display
     * @param string $datetime
     * @return string
     */
    public static function formatDateTime($datetime) {
        return self::formatDate($datetime, DISPLAY_DATETIME_FORMAT);
    }
    
    /**
     * Format currency
     * @param float $amount
     * @param bool $withSymbol
     * @return string
     */
    public static function formatCurrency($amount, $withSymbol = true) {
        if (!is_numeric($amount)) {
            $amount = 0;
        }
        
        $formatted = number_format($amount, 2);
        
        if ($withSymbol) {
            return CURRENCY_SYMBOL . $formatted;
        }
        
        return $formatted;
    }
    
    /**
     * Truncate text
     * @param string $text
     * @param int $length
     * @param string $suffix
     * @return string
     */
    public static function truncate($text, $length = 100, $suffix = '...') {
        if (mb_strlen($text) <= $length) {
            return $text;
        }
        
        $truncated = mb_substr($text, 0, $length - mb_strlen($suffix));
        return $truncated . $suffix;
    }
    
    /**
     * Sanitize filename
     * @param string $filename
     * @return string
     */
    public static function sanitizeFilename($filename) {
        $filename = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $filename);
        $filename = preg_replace('/_+/', '_', $filename);
        return trim($filename, '_');
    }
    
    /**
     * Get file extension
     * @param string $filename
     * @return string
     */
    public static function getFileExtension($filename) {
        return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    }
    
    /**
     * Check if file is an image
     * @param string $filename
     * @return bool
     */
    public static function isImage($filename) {
        $ext = self::getFileExtension($filename);
        return in_array($ext, ALLOWED_IMAGE_TYPES);
    }
    
    /**
     * Check if file is a document
     * @param string $filename
     * @return bool
     */
    public static function isDocument($filename) {
        $ext = self::getFileExtension($filename);
        return in_array($ext, ALLOWED_DOC_TYPES);
    }
    
    /**
     * Generate UUID v4
     * @return string
     */
    public static function generateUuid() {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
    
    /**
     * Generate student admission number
     * @param int $schoolId
     * @param int $year
     * @param string $prefix
     * @return string
     */
    public static function generateAdmissionNumber($schoolId, $year, $prefix = 'SCH') {
        $schoolCode = str_pad($schoolId, 3, '0', STR_PAD_LEFT);
        $yearCode = substr($year, -2);
        
        // Get last admission number for this school/year
        $db = Database::getSchoolConnection(DB_SCHOOL_PREFIX . $schoolId);
        $stmt = $db->prepare("
            SELECT admission_number 
            FROM students 
            WHERE admission_number LIKE ? 
            ORDER BY id DESC 
            LIMIT 1
        ");
        $stmt->execute([$prefix . $schoolCode . $yearCode . '%']);
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
     * Generate employee ID for teacher/staff
     * @param int $schoolId
     * @param string $type TEACH|STAFF
     * @return string
     */
    public static function generateEmployeeId($schoolId, $type = 'TEACH') {
        $schoolCode = str_pad($schoolId, 3, '0', STR_PAD_LEFT);
        $year = date('y');
        
        $db = Database::getSchoolConnection(DB_SCHOOL_PREFIX . $schoolId);
        $stmt = $db->prepare("
            SELECT employee_id 
            FROM teachers 
            WHERE employee_id LIKE ? 
            ORDER BY id DESC 
            LIMIT 1
        ");
        $stmt->execute([$type . $schoolCode . $year . '%']);
        $last = $stmt->fetch();
        
        if ($last && preg_match('/\d{3}$/', $last['employee_id'], $matches)) {
            $nextNum = (int)$matches[0] + 1;
        } else {
            $nextNum = 1;
        }
        
        $serial = str_pad($nextNum, 3, '0', STR_PAD_LEFT);
        return $type . $schoolCode . $year . $serial;
    }
    
    /**
     * Calculate GPA from grades
     * @param array $grades Array of grade points
     * @return float
     */
    public static function calculateGPA($grades) {
        if (empty($grades)) {
            return 0.0;
        }
        
        $totalPoints = 0;
        $totalCredits = 0;
        
        foreach ($grades as $grade) {
            $point = self::gradeToPoint($grade['grade']);
            $credits = $grade['credits'] ?? 1.0;
            
            $totalPoints += $point * $credits;
            $totalCredits += $credits;
        }
        
        if ($totalCredits == 0) {
            return 0.0;
        }
        
        return round($totalPoints / $totalCredits, 2);
    }
    
    /**
     * Convert letter grade to point
     * @param string $grade
     * @return float
     */
    public static function gradeToPoint($grade) {
        $scale = [
            'A+' => 5.0, 'A' => 5.0, 'A-' => 4.5,
            'B+' => 4.0, 'B' => 3.5, 'B-' => 3.0,
            'C+' => 2.5, 'C' => 2.0, 'C-' => 1.5,
            'D' => 1.0, 'E' => 0.5, 'F' => 0.0
        ];
        
        return $scale[strtoupper($grade)] ?? 0.0;
    }
    
    /**
     * Calculate percentage to grade
     * @param float $percentage
     * @return string
     */
    public static function percentageToGrade($percentage) {
        if ($percentage >= 90) return 'A+';
        if ($percentage >= 80) return 'A';
        if ($percentage >= 75) return 'A-';
        if ($percentage >= 70) return 'B+';
        if ($percentage >= 65) return 'B';
        if ($percentage >= 60) return 'B-';
        if ($percentage >= 55) return 'C+';
        if ($percentage >= 50) return 'C';
        if ($percentage >= 45) return 'C-';
        if ($percentage >= 40) return 'D';
        if ($percentage >= 35) return 'E';
        return 'F';
    }
    
    /**
     * Get Nigerian states
     * @return array
     */
    public static function getNigerianStates() {
        return [
            'Abia', 'Adamawa', 'Akwa Ibom', 'Anambra', 'Bauchi', 'Bayelsa',
            'Benue', 'Borno', 'Cross River', 'Delta', 'Ebonyi', 'Edo',
            'Ekiti', 'Enugu', 'Gombe', 'Imo', 'Jigawa', 'Kaduna',
            'Kano', 'Katsina', 'Kebbi', 'Kogi', 'Kwara', 'Lagos',
            'Nasarawa', 'Niger', 'Ogun', 'Ondo', 'Osun', 'Oyo',
            'Plateau', 'Rivers', 'Sokoto', 'Taraba', 'Yobe', 'Zamfara',
            'FCT Abuja'
        ];
    }
    
    /**
     * Get blood groups
     * @return array
     */
    public static function getBloodGroups() {
        return ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
    }
    
    /**
     * Get religions
     * @return array
     */
    public static function getReligions() {
        return ['Christianity', 'Islam', 'Traditional', 'Other'];
    }
    
    /**
     * Get academic terms
     * @return array
     */
    public static function getAcademicTerms() {
        return [
            'first' => 'First Term',
            'second' => 'Second Term', 
            'third' => 'Third Term'
        ];
    }
    
    /**
     * Get months
     * @return array
     */
    public static function getMonths() {
        return [
            1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
            5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
            9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
        ];
    }
    
    /**
     * Get days of week
     * @return array
     */
    public static function getDaysOfWeek() {
        return [
            'monday' => 'Monday',
            'tuesday' => 'Tuesday',
            'wednesday' => 'Wednesday',
            'thursday' => 'Thursday',
            'friday' => 'Friday',
            'saturday' => 'Saturday'
        ];
    }
    
    /**
     * Send JSON response
     * @param mixed $data
     * @param int $statusCode
     */
    public static function jsonResponse($data, $statusCode = 200) {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_PRETTY_PRINT);
        exit;
    }
    
    /**
     * Send error response
     * @param string $message
     * @param int $statusCode
     * @param array $additionalData
     */
    public static function errorResponse($message, $statusCode = 400, $additionalData = []) {
        $response = [
            'success' => false,
            'message' => $message,
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        if (!empty($additionalData)) {
            $response['data'] = $additionalData;
        }
        
        self::jsonResponse($response, $statusCode);
    }
    
    /**
     * Send success response
     * @param mixed $data
     * @param string $message
     * @param int $statusCode
     */
    public static function successResponse($data = null, $message = 'Success', $statusCode = 200) {
        $response = [
            'success' => true,
            'message' => $message,
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        if ($data !== null) {
            $response['data'] = $data;
        }
        
        self::jsonResponse($response, $statusCode);
    }
    
    /**
     * Validate Nigerian BVN format
     * @param string $bvn
     * @return bool
     */
    public static function isValidBVN($bvn) {
        return preg_match('/^\d{11}$/', $bvn);
    }
    
    /**
     * Validate Nigerian NIN format
     * @param string $nin
     * @return bool
     */
    public static function isValidNIN($nin) {
        return preg_match('/^\d{11}$/', $nin);
    }
    
    /**
     * Encrypt data (simple encryption for non-sensitive data)
     * @param string $data
     * @param string $key
     * @return string
     */
    public static function encrypt($data, $key = '') {
        if (empty($key)) {
            $key = APP_NAME;
        }
        
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));
        $encrypted = openssl_encrypt($data, 'aes-256-cbc', $key, 0, $iv);
        return base64_encode($encrypted . '::' . $iv);
    }
    
    /**
     * Decrypt data
     * @param string $data
     * @param string $key
     * @return string
     */
    public static function decrypt($data, $key = '') {
        if (empty($key)) {
            $key = APP_NAME;
        }
        
        list($encrypted_data, $iv) = explode('::', base64_decode($data), 2);
        return openssl_decrypt($encrypted_data, 'aes-256-cbc', $key, 0, $iv);
    }
    
    /**
     * Get client IP address
     * @return string
     */
    public static function getClientIp() {
        $ip = $_SERVER['REMOTE_ADDR'];
        
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        }
        
        return $ip;
    }
    
    /**
     * Check if request is AJAX
     * @return bool
     */
    public static function isAjax() {
        return isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
               strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }
    
    /**
     * Get base URL
     * @return string
     */
    public static function getBaseUrl() {
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'];
        return $protocol . '://' . $host;
    }
    
    /**
     * Get current URL
     * @return string
     */
    public static function getCurrentUrl() {
        return self::getBaseUrl() . $_SERVER['REQUEST_URI'];
    }
    
    /**
     * Redirect with message
     * @param string $url
     * @param string $message
     * @param string $type success|error|info|warning
     */
    public static function redirect($url, $message = null, $type = 'info') {
        if ($message) {
            Session::flash($type, $message);
        }
        
        header('Location: ' . $url);
        exit;
    }
    
    /**
     * Validate URL
     * @param string $url
     * @return bool
     */
    public static function isValidUrl($url) {
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
    
    /**
     * Get file size in human readable format
     * @param int $bytes
     * @return string
     */
    public static function formatFileSize($bytes) {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= pow(1024, $pow);
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }
}
?>],Tenant.php [ <?php
/**
 * Tenant Management
 * Handles multi-tenancy, school detection, and isolation
 */

class Tenant
{
    private static $currentSchool = null;
    private static $schoolDb = null;
    private static $schoolCache = [];
    
    // Performance metrics tracking
    private static $performanceMetrics = [];
    
    // Rate limiting storage
    private static $rateLimits = [];
    
    // Storage limits
    private static $storageLimits = [
        'free' => 1073741824, // 1GB
        'basic' => 5368709120, // 5GB
        'premium' => 21474836480, // 20GB
        'enterprise' => 107374182400 // 100GB
    ];

    /**
     * Detect current school from request
     * @return array|null
     */
    public static function detect()
    {
        if (self::$currentSchool !== null) {
            return self::$currentSchool;
        }

        // Method 1: Check subdomain
        $school = self::detectFromSubdomain();
        if ($school) {
            self::$currentSchool = $school;
            return $school;
        }

        // Method 2: Check URL path
        $school = self::detectFromPath();
        if ($school) {
            self::$currentSchool = $school;
            return $school;
        }

        // Method 3: Check session
        $school = self::detectFromSession();
        if ($school) {
            self::$currentSchool = $school;
            return $school;
        }

        return null;
    }

    /**
     * Detect school from subdomain
     * @return array|null
     */
    private static function detectFromSubdomain()
    {
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $parts = explode('.', $host);

        // Check for subdomain pattern: school.yoursaas.com
        if (count($parts) >= 3) {
            $subdomain = $parts[0];

            // Skip common subdomains
            if (in_array($subdomain, ['www', 'app', 'admin', 'platform'])) {
                return null;
            }

            return self::getSchoolBySlug($subdomain);
        }

        return null;
    }

    /**
     * Detect school from URL path
     * @return array|null
     */
    private static function detectFromPath()
    {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';

        // Pattern: /tenant/{slug}/...
        if (preg_match('/^\/school\/([a-z0-9-]+)(\/|$)/i', $requestUri, $matches)) {
            return self::getSchoolBySlug($matches[1]);
        }

        return null;
    }

    /**
     * Detect school from session
     * @return array|null
     */
    private static function detectFromSession()
    {
        // Start session if not already started
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (isset($_SESSION['school_user']['school_id'])) {
            return self::getSchoolById($_SESSION['school_user']['school_id']);
        }

        return null;
    }

    /**
     * Get school by slug
     * @param string $slug
     * @return array|null
     */
    public static function getSchoolBySlug($slug)
    {
        // Check cache first
        if (isset(self::$schoolCache[$slug])) {
            return self::$schoolCache[$slug];
        }

        try {
            $db = Database::getPlatformConnection();
            $stmt = $db->prepare("
                SELECT * FROM schools 
                WHERE slug = ? AND status IN ('active', 'trial')
            ");
            $stmt->execute([$slug]);
            $school = $stmt->fetch();

            if ($school) {
                self::$schoolCache[$slug] = $school;
            }

            return $school;
        } catch (Exception $e) {
            self::logError("Failed to get school by slug", $e);
            return null;
        }
    }

    /**
     * Get school by ID
     * @param int $id
     * @return array|null
     */
public static function getSchoolById($id)
{
    try {
        $db = Database::getPlatformConnection();
        $stmt = $db->prepare("
            SELECT 
                s.*,
                p.name as plan_name,
                p.storage_limit as plan_storage_limit
            FROM schools s
            LEFT JOIN plans p ON s.plan_id = p.id
            WHERE s.id = ? AND s.status IN ('active', 'trial')
        ");
        $stmt->execute([$id]);
        return $stmt->fetch();
    } catch (Exception $e) {
        self::logError("Failed to get school by ID", $e);
        return null;
    }
}

    /**
     * Get current school
     * @return array|null
     */
    public static function getCurrentSchool()
    {
        return self::detect();
    }

    /**
     * Get current school ID
     * @return int|null
     */
    public static function getCurrentSchoolId()
    {
        $school = self::getCurrentSchool();
        return $school ? $school['id'] : null;
    }

    /**
     * Get current school database connection
     * @return PDO|null
     */
    public static function getSchoolDb()
    {
        if (self::$schoolDb !== null) {
            return self::$schoolDb;
        }

        $school = self::getCurrentSchool();
        if (!$school || empty($school['database_name'])) {
            return null;
        }

        try {
            self::$schoolDb = Database::getSchoolConnection($school['database_name']);
            return self::$schoolDb;
        } catch (Exception $e) {
            self::logError("Failed to get school DB connection", $e);
            return null;
        }
    }

    /**
     * Create new school database with ALL tables including new features
     * @param array $schoolData Must contain: id, admin_name, admin_email, admin_phone, admin_password
     * @return array [success, message, database_name]
     */
    public static function createSchoolDatabase($schoolData)
    {
        try {
            // Validate required data
            $requiredFields = ['id', 'admin_name', 'admin_email', 'admin_phone', 'admin_password'];
            foreach ($requiredFields as $field) {
                if (!isset($schoolData[$field]) || empty($schoolData[$field])) {
                    return [
                        'success' => false,
                        'message' => "Missing required field: $field"
                    ];
                }
            }

            // Generate database name based on school ID
            $dbName = DB_SCHOOL_PREFIX . $schoolData['id'];
            self::logInfo("Creating school database: " . $dbName);

            // Check subscription limits before creating
            if (!self::checkSubscriptionLimits($schoolData['id'])) {
                return [
                    'success' => false,
                    'message' => 'Subscription limit reached. Please upgrade your plan.'
                ];
            }

            // Create database
            $result = Database::createSchoolDatabase($dbName);

            if (!$result) {
                return [
                    'success' => false,
                    'message' => 'Failed to create database'
                ];
            }

            // Get school database connection
            $schoolDb = Database::getSchoolConnection($dbName);
            self::logInfo("School database connection established");

            // Create ALL tables programmatically with enhanced features
            self::createCompleteSchema($schoolDb, $schoolData['id']);

            // Create initial admin user
            $adminUserId = self::createInitialAdmin($schoolDb, $schoolData);

            if (!$adminUserId) {
                return [
                    'success' => false,
                    'message' => 'Failed to create admin user'
                ];
            }

            // Initialize subscription and billing data
            self::initializeSubscriptionData($schoolDb, $schoolData['id']);

            // Create initial backup
            self::createInitialBackup($schoolData['id']);

            // Log the created tables
            $tables = $schoolDb->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
            self::logInfo("Total tables created in " . $dbName . ": " . count($tables));

            // Log performance metrics
            self::logPerformanceMetric('database_creation', $schoolData['id'], [
                'tables_created' => count($tables),
                'database_name' => $dbName
            ]);

            return [
                'success' => true,
                'message' => 'School database created successfully',
                'database_name' => $dbName,
                'admin_user_id' => $adminUserId
            ];
        } catch (Exception $e) {
            self::logError("Failed to create school database", $e);
            return [
                'success' => false,
                'message' => 'Failed to create school database: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Create COMPLETE schema with ALL tables including new features
     * @param PDO $db
     * @param int $schoolId
     */
    private static function createCompleteSchema($db, $schoolId)
    {
        self::logInfo("Creating COMPLETE schema with ALL tables for school ID: " . $schoolId);
        
        // Disable foreign key checks temporarily
        $db->exec("SET FOREIGN_KEY_CHECKS = 0");
        
        // Array of ALL table creation SQL
        $tables = [
            // Core educational tables (from your original schema)
            self::getAcademicTermsTableSql(),
            self::getAcademicYearsTableSql(),
            self::getAnnouncementsTableSql(),
            self::getAttendanceTableSql(),
            self::getClassesTableSql(),
            self::getClassSubjectsTableSql(),
            self::getEventsTableSql(),
            self::getExamsTableSql(),
            self::getExamGradesTableSql(),
            self::getFeeCategoriesTableSql(),
            self::getFeeStructuresTableSql(),
            self::getGuardiansTableSql(),
            self::getHomeworkTableSql(),
            self::getInvoicesTableSql(),
            self::getInvoiceItemsTableSql(),
            self::getPaymentsTableSql(),
            self::getRolesTableSql(),
            self::getSectionsTableSql(),
            self::getSettingsTableSql(),
            self::getStudentsTableSql(),
            self::getSubjectsTableSql(),
            self::getTeachersTableSql(),
            self::getTimetablesTableSql(),
            self::getUsersTableSql(),
            self::getUserRolesTableSql(),
            
            // NEW TABLES FOR ENHANCED FEATURES
            
            // 1. Subscription & Billing Management
            self::getSubscriptionsTableSql(),
            self::getBillingHistoryTableSql(),
            self::getPaymentMethodsTableSql(),
            self::getInvoicesV2TableSql(),
            
            // 2. Storage & Usage Tracking
            self::getStorageUsageTableSql(),
            self::getFileStorageTableSql(),
            
            // 3. Performance & Monitoring
            self::getPerformanceMetricsTableSql(),
            self::getApiLogsTableSql(),
            self::getAuditLogsTableSql(),
            
            // 4. Security & Rate Limiting
            self::getSecurityLogsTableSql(),
            self::getRateLimitsTableSql(),
            self::getLoginAttemptsTableSql(),
            
            // 5. Backup & Recovery
            self::getBackupHistoryTableSql(),
            self::getRecoveryPointsTableSql(),
            
            // 6. Communication & Notifications
            self::getNotificationsTableSql(),
            self::getEmailTemplatesTableSql(),
            self::getSmsLogsTableSql(),
            
            // 7. API Management
            self::getApiKeysTableSql(),
            self::getApiUsageTableSql(),
            
            // 8. System Maintenance
            self::getMaintenanceLogsTableSql(),
            self::getSystemAlertsTableSql()
        ];

        // Create each table
        $createdCount = 0;
        foreach ($tables as $sql) {
            try {
                $db->exec($sql);
                $createdCount++;
                
                // Extract table name for logging
                preg_match('/CREATE TABLE (?:IF NOT EXISTS )?`?(\w+)`?/', $sql, $matches);
                if (isset($matches[1])) {
                    self::logInfo("Created table: " . $matches[1]);
                }
            } catch (Exception $e) {
                self::logWarning("Error creating table (continuing): " . $e->getMessage());
                // Continue with other tables
            }
        }

        // Insert default data
        self::insertDefaultData($db, $schoolId);
        
        // Create indexes for performance
        self::createPerformanceIndexes($db);
        
        // Re-enable foreign key checks
        $db->exec("SET FOREIGN_KEY_CHECKS = 1");
        
        self::logInfo("Created " . $createdCount . " tables successfully");
        
        return $createdCount;
    }

    /**
     * =================================================================
     * TABLE DEFINITION METHODS
     * =================================================================
     */

    /**
     * 1. CORE EDUCATIONAL TABLES
     */
    
    private static function getAcademicTermsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `academic_terms` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `academic_year_id` int(10) UNSIGNED NOT NULL,
            `name` varchar(100) NOT NULL,
            `start_date` date NOT NULL,
            `end_date` date NOT NULL,
            `is_default` tinyint(1) DEFAULT 0,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_term_school` (`school_id`,`academic_year_id`,`name`),
            KEY `idx_school` (`school_id`),
            KEY `idx_year` (`academic_year_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getAcademicYearsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `academic_years` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `name` varchar(100) NOT NULL,
            `start_date` date NOT NULL,
            `end_date` date NOT NULL,
            `is_default` tinyint(1) DEFAULT 0,
            `status` enum('upcoming','active','completed') DEFAULT 'upcoming',
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_year_school` (`school_id`,`name`),
            KEY `idx_school` (`school_id`),
            KEY `idx_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getAnnouncementsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `announcements` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `title` varchar(255) NOT NULL,
            `description` text NOT NULL,
            `target` enum('all','students','teachers','parents','class','section') DEFAULT 'all',
            `class_id` int(10) UNSIGNED DEFAULT NULL,
            `section_id` int(10) UNSIGNED DEFAULT NULL,
            `start_date` date DEFAULT NULL,
            `end_date` date DEFAULT NULL,
            `is_published` tinyint(1) DEFAULT 1,
            `created_by` int(10) UNSIGNED NOT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `class_id` (`class_id`),
            KEY `section_id` (`section_id`),
            KEY `created_by` (`created_by`),
            KEY `idx_school` (`school_id`),
            KEY `idx_published` (`is_published`),
            KEY `idx_dates` (`start_date`,`end_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getAttendanceTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `attendance` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `student_id` int(10) UNSIGNED NOT NULL,
            `class_id` int(10) UNSIGNED NOT NULL,
            `date` date NOT NULL,
            `status` enum('present','absent','late','half_day','holiday','sunday') NOT NULL,
            `remark` varchar(255) DEFAULT NULL,
            `marked_by` int(10) UNSIGNED DEFAULT NULL,
            `session` enum('morning','afternoon','full_day') DEFAULT 'full_day',
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_attendance` (`student_id`,`date`,`session`),
            KEY `marked_by` (`marked_by`),
            KEY `idx_school` (`school_id`),
            KEY `idx_student` (`student_id`),
            KEY `idx_date` (`date`),
            KEY `idx_class` (`class_id`),
            KEY `idx_attendance_student_date` (`student_id`,`date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getClassesTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `classes` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED DEFAULT NULL,
            `name` varchar(100) NOT NULL,
            `code` varchar(50) NOT NULL,
            `description` text DEFAULT NULL,
            `grade_level` varchar(50) DEFAULT NULL,
            `class_teacher_id` int(10) UNSIGNED DEFAULT NULL,
            `capacity` int(10) UNSIGNED DEFAULT 40,
            `room_number` varchar(50) DEFAULT NULL,
            `academic_year_id` int(10) UNSIGNED NOT NULL,
            `is_active` tinyint(1) DEFAULT 1,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_class_school` (`school_id`,`academic_year_id`,`code`),
            KEY `class_teacher_id` (`class_teacher_id`),
            KEY `idx_school` (`school_id`),
            KEY `idx_year` (`academic_year_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getClassSubjectsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `class_subjects` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `class_id` int(10) UNSIGNED NOT NULL,
            `subject_id` int(10) UNSIGNED NOT NULL,
            `teacher_id` int(10) UNSIGNED DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_class_subject` (`class_id`,`subject_id`),
            KEY `subject_id` (`subject_id`),
            KEY `idx_class` (`class_id`),
            KEY `idx_teacher` (`teacher_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getEventsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `events` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `title` varchar(255) NOT NULL,
            `description` text DEFAULT NULL,
            `type` enum('holiday','exam','meeting','celebration','sports','other') DEFAULT 'other',
            `start_date` date NOT NULL,
            `end_date` date DEFAULT NULL,
            `start_time` time DEFAULT NULL,
            `end_time` time DEFAULT NULL,
            `venue` varchar(255) DEFAULT NULL,
            `is_public` tinyint(1) DEFAULT 1,
            `created_by` int(10) UNSIGNED NOT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `created_by` (`created_by`),
            KEY `idx_school` (`school_id`),
            KEY `idx_dates` (`start_date`,`end_date`),
            KEY `idx_type` (`type`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getExamsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `exams` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `name` varchar(100) NOT NULL,
            `description` text DEFAULT NULL,
            `academic_year_id` int(10) UNSIGNED NOT NULL,
            `academic_term_id` int(10) UNSIGNED NOT NULL,
            `start_date` date DEFAULT NULL,
            `end_date` date DEFAULT NULL,
            `is_published` tinyint(1) DEFAULT 0,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_exam_school` (`school_id`,`academic_year_id`,`academic_term_id`,`name`),
            KEY `academic_term_id` (`academic_term_id`),
            KEY `idx_school` (`school_id`),
            KEY `idx_year` (`academic_year_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getExamGradesTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `exam_grades` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `exam_id` int(10) UNSIGNED NOT NULL,
            `student_id` int(10) UNSIGNED NOT NULL,
            `subject_id` int(10) UNSIGNED NOT NULL,
            `class_id` int(10) UNSIGNED NOT NULL,
            `marks_obtained` decimal(5,2) DEFAULT NULL,
            `total_marks` decimal(5,2) NOT NULL,
            `grade` varchar(5) DEFAULT NULL,
            `remarks` varchar(255) DEFAULT NULL,
            `entered_by` int(10) UNSIGNED DEFAULT NULL,
            `entered_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `is_published` tinyint(1) DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_exam_grade` (`exam_id`,`student_id`,`subject_id`),
            KEY `class_id` (`class_id`),
            KEY `entered_by` (`entered_by`),
            KEY `idx_school` (`school_id`),
            KEY `idx_exam` (`exam_id`),
            KEY `idx_student` (`student_id`),
            KEY `idx_subject` (`subject_id`),
            KEY `idx_exam_grades_exam_student` (`exam_id`,`student_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getFeeCategoriesTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `fee_categories` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `name` varchar(100) NOT NULL,
            `description` text DEFAULT NULL,
            `is_active` tinyint(1) DEFAULT 1,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_category_school` (`school_id`,`name`),
            KEY `idx_school` (`school_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getFeeStructuresTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `fee_structures` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `academic_year_id` int(10) UNSIGNED NOT NULL,
            `academic_term_id` int(10) UNSIGNED NOT NULL,
            `class_id` int(10) UNSIGNED NOT NULL,
            `fee_category_id` int(10) UNSIGNED NOT NULL,
            `amount` decimal(10,2) NOT NULL,
            `due_date` date DEFAULT NULL,
            `late_fee` decimal(10,2) DEFAULT 0.00,
            `is_active` tinyint(1) DEFAULT 1,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_fee_structure` (`academic_year_id`,`academic_term_id`,`class_id`,`fee_category_id`),
            KEY `academic_term_id` (`academic_term_id`),
            KEY `class_id` (`class_id`),
            KEY `fee_category_id` (`fee_category_id`),
            KEY `idx_school` (`school_id`),
            KEY `idx_year` (`academic_year_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getGuardiansTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `guardians` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `user_id` int(10) UNSIGNED NOT NULL,
            `student_id` int(10) UNSIGNED NOT NULL,
            `relationship` enum('father','mother','brother','sister','uncle','aunt','grandfather','grandmother','guardian','other') NOT NULL,
            `is_primary` tinyint(1) DEFAULT 0,
            `can_pickup` tinyint(1) DEFAULT 1,
            `emergency_contact` tinyint(1) DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_guardian_student` (`student_id`,`user_id`),
            KEY `user_id` (`user_id`),
            KEY `idx_school` (`school_id`),
            KEY `idx_student` (`student_id`),
            KEY `idx_primary` (`is_primary`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getHomeworkTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `homework` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `class_id` int(10) UNSIGNED NOT NULL,
            `section_id` int(10) UNSIGNED DEFAULT NULL,
            `subject_id` int(10) UNSIGNED NOT NULL,
            `teacher_id` int(10) UNSIGNED NOT NULL,
            `title` varchar(255) NOT NULL,
            `description` text DEFAULT NULL,
            `attachment` varchar(500) DEFAULT NULL,
            `due_date` date NOT NULL,
            `submission_type` enum('online','offline') DEFAULT 'offline',
            `max_marks` decimal(5,2) DEFAULT NULL,
            `is_published` tinyint(1) DEFAULT 1,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `section_id` (`section_id`),
            KEY `subject_id` (`subject_id`),
            KEY `idx_school` (`school_id`),
            KEY `idx_class` (`class_id`),
            KEY `idx_due_date` (`due_date`),
            KEY `idx_teacher` (`teacher_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getInvoicesTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `invoices` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `invoice_number` varchar(100) NOT NULL,
            `student_id` int(10) UNSIGNED NOT NULL,
            `academic_year_id` int(10) UNSIGNED NOT NULL,
            `academic_term_id` int(10) UNSIGNED NOT NULL,
            `class_id` int(10) UNSIGNED NOT NULL,
            `issue_date` date NOT NULL,
            `due_date` date NOT NULL,
            `total_amount` decimal(10,2) NOT NULL,
            `discount` decimal(10,2) DEFAULT 0.00,
            `late_fee` decimal(10,2) DEFAULT 0.00,
            `paid_amount` decimal(10,2) DEFAULT 0.00,
            `balance_amount` decimal(10,2) NOT NULL,
            `status` enum('draft','pending','partial','paid','overdue','cancelled') DEFAULT 'pending',
            `payment_method` varchar(50) DEFAULT NULL,
            `paid_at` timestamp NULL DEFAULT NULL,
            `transaction_id` varchar(255) DEFAULT NULL,
            `notes` text DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `invoice_number` (`invoice_number`),
            KEY `academic_year_id` (`academic_year_id`),
            KEY `academic_term_id` (`academic_term_id`),
            KEY `class_id` (`class_id`),
            KEY `idx_school` (`school_id`),
            KEY `idx_student` (`student_id`),
            KEY `idx_status` (`status`),
            KEY `idx_due_date` (`due_date`),
            KEY `idx_invoices_student_status` (`student_id`,`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getInvoiceItemsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `invoice_items` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `invoice_id` int(10) UNSIGNED NOT NULL,
            `fee_category_id` int(10) UNSIGNED NOT NULL,
            `description` varchar(255) DEFAULT NULL,
            `amount` decimal(10,2) NOT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `fee_category_id` (`fee_category_id`),
            KEY `idx_invoice` (`invoice_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getPaymentsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `payments` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `invoice_id` int(10) UNSIGNED NOT NULL,
            `payment_number` varchar(100) NOT NULL,
            `student_id` int(10) UNSIGNED NOT NULL,
            `amount` decimal(10,2) NOT NULL,
            `payment_method` enum('cash','cheque','bank_transfer','card','mobile_money','online') NOT NULL,
            `payment_date` date NOT NULL,
            `collected_by` int(10) UNSIGNED DEFAULT NULL,
            `bank_name` varchar(255) DEFAULT NULL,
            `cheque_number` varchar(100) DEFAULT NULL,
            `transaction_id` varchar(255) DEFAULT NULL,
            `reference` varchar(255) DEFAULT NULL,
            `notes` text DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `payment_number` (`payment_number`),
            KEY `collected_by` (`collected_by`),
            KEY `idx_school` (`school_id`),
            KEY `idx_invoice` (`invoice_id`),
            KEY `idx_student` (`student_id`),
            KEY `idx_payment_date` (`payment_date`),
            KEY `idx_payments_invoice_date` (`invoice_id`,`payment_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getRolesTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `roles` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `name` varchar(100) NOT NULL,
            `slug` varchar(100) NOT NULL,
            `description` text DEFAULT NULL,
            `permissions` text DEFAULT NULL,
            `is_system` tinyint(1) DEFAULT 0,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_role_school` (`school_id`,`slug`),
            KEY `idx_school` (`school_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getSectionsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `sections` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `class_id` int(10) UNSIGNED NOT NULL,
            `name` varchar(100) NOT NULL,
            `code` varchar(50) NOT NULL,
            `room_number` varchar(50) DEFAULT NULL,
            `capacity` int(10) UNSIGNED DEFAULT 40,
            `class_teacher_id` int(10) UNSIGNED DEFAULT NULL,
            `is_active` tinyint(1) DEFAULT 1,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_section_class` (`class_id`,`code`),
            KEY `class_teacher_id` (`class_teacher_id`),
            KEY `idx_school` (`school_id`),
            KEY `idx_class` (`class_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getSettingsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `settings` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `key` varchar(100) NOT NULL,
            `value` text DEFAULT NULL,
            `type` varchar(50) DEFAULT 'string',
            `category` varchar(50) DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_setting` (`school_id`,`key`),
            KEY `idx_school` (`school_id`),
            KEY `idx_key` (`key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getStudentsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `students` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `campus_id` int(10) UNSIGNED DEFAULT NULL,
            `user_id` int(10) UNSIGNED NOT NULL,
            `admission_number` varchar(50) NOT NULL,
            `roll_number` varchar(50) DEFAULT NULL,
            `class_id` int(10) UNSIGNED DEFAULT NULL,
            `section_id` int(10) UNSIGNED DEFAULT NULL,
            `admission_date` date NOT NULL,
            `first_name` varchar(100) NOT NULL,
            `middle_name` varchar(100) DEFAULT NULL,
            `last_name` varchar(100) NOT NULL,
            `date_of_birth` date NOT NULL,
            `birth_place` varchar(255) DEFAULT NULL,
            `nationality` varchar(100) DEFAULT NULL,
            `mother_tongue` varchar(100) DEFAULT NULL,
            `current_address` text DEFAULT NULL,
            `permanent_address` text DEFAULT NULL,
            `previous_school` varchar(255) DEFAULT NULL,
            `previous_class` varchar(100) DEFAULT NULL,
            `transfer_certificate_no` varchar(100) DEFAULT NULL,
            `blood_group` varchar(5) DEFAULT NULL,
            `allergies` text DEFAULT NULL,
            `medical_conditions` text DEFAULT NULL,
            `doctor_name` varchar(255) DEFAULT NULL,
            `doctor_phone` varchar(20) DEFAULT NULL,
            `status` enum('active','inactive','graduated','transferred','withdrawn') DEFAULT 'active',
            `graduation_date` date DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `admission_number` (`admission_number`),
            KEY `user_id` (`user_id`),
            KEY `section_id` (`section_id`),
            KEY `idx_school` (`school_id`),
            KEY `idx_class` (`class_id`),
            KEY `idx_admission` (`admission_number`),
            KEY `idx_status` (`status`),
            KEY `idx_students_class_status` (`class_id`,`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getSubjectsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `subjects` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `name` varchar(100) NOT NULL,
            `code` varchar(50) NOT NULL,
            `type` enum('core','elective','extra_curricular') DEFAULT 'core',
            `description` text DEFAULT NULL,
            `credit_hours` decimal(4,1) DEFAULT 1.0,
            `is_active` tinyint(1) DEFAULT 1,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_subject_school` (`school_id`,`code`),
            KEY `idx_school` (`school_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getTeachersTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `teachers` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `user_id` int(10) UNSIGNED NOT NULL,
            `employee_id` varchar(50) NOT NULL,
            `qualification` varchar(255) DEFAULT NULL,
            `specialization` varchar(255) DEFAULT NULL,
            `experience_years` int(10) UNSIGNED DEFAULT NULL,
            `joining_date` date DEFAULT NULL,
            `leaving_date` date DEFAULT NULL,
            `salary_grade` varchar(50) DEFAULT NULL,
            `bank_name` varchar(255) DEFAULT NULL,
            `bank_account` varchar(50) DEFAULT NULL,
            `ifsc_code` varchar(20) DEFAULT NULL,
            `is_active` tinyint(1) DEFAULT 1,
            PRIMARY KEY (`id`),
            UNIQUE KEY `employee_id` (`employee_id`),
            KEY `user_id` (`user_id`),
            KEY `idx_school` (`school_id`),
            KEY `idx_employee` (`employee_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getTimetablesTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `timetables` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `class_id` int(10) UNSIGNED NOT NULL,
            `section_id` int(10) UNSIGNED DEFAULT NULL,
            `academic_year_id` int(10) UNSIGNED NOT NULL,
            `academic_term_id` int(10) UNSIGNED NOT NULL,
            `day` enum('monday','tuesday','wednesday','thursday','friday','saturday') NOT NULL,
            `period_number` int(10) UNSIGNED NOT NULL,
            `start_time` time NOT NULL,
            `end_time` time NOT NULL,
            `subject_id` int(10) UNSIGNED NOT NULL,
            `teacher_id` int(10) UNSIGNED NOT NULL,
            `room_number` varchar(50) DEFAULT NULL,
            `is_break` tinyint(1) DEFAULT 0,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_timetable` (`class_id`,`section_id`,`day`,`period_number`,`academic_year_id`),
            KEY `section_id` (`section_id`),
            KEY `academic_year_id` (`academic_year_id`),
            KEY `academic_term_id` (`academic_term_id`),
            KEY `subject_id` (`subject_id`),
            KEY `teacher_id` (`teacher_id`),
            KEY `idx_school` (`school_id`),
            KEY `idx_class` (`class_id`),
            KEY `idx_day` (`day`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getUsersTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `users` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `name` varchar(255) NOT NULL,
            `email` varchar(255) DEFAULT NULL,
            `phone` varchar(20) DEFAULT NULL,
            `username` varchar(100) DEFAULT NULL,
            `password` varchar(255) NOT NULL,
            `user_type` enum('admin','teacher','student','parent','accountant','librarian','receptionist') NOT NULL,
            `profile_photo` varchar(500) DEFAULT NULL,
            `gender` enum('male','female','other') DEFAULT NULL,
            `date_of_birth` date DEFAULT NULL,
            `blood_group` varchar(5) DEFAULT NULL,
            `religion` varchar(50) DEFAULT NULL,
            `address` text DEFAULT NULL,
            `email_verified_at` timestamp NULL DEFAULT NULL,
            `phone_verified_at` timestamp NULL DEFAULT NULL,
            `is_active` tinyint(1) DEFAULT 1,
            `last_login_at` timestamp NULL DEFAULT NULL,
            `last_login_ip` varchar(45) DEFAULT NULL,
            `remember_token` varchar(100) DEFAULT NULL,
            `reset_token` varchar(100) DEFAULT NULL,
            `reset_token_expires` timestamp NULL DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_email_school` (`school_id`,`email`),
            UNIQUE KEY `unique_phone_school` (`school_id`,`phone`),
            KEY `idx_school` (`school_id`),
            KEY `idx_user_type` (`user_type`),
            KEY `idx_email` (`email`),
            KEY `idx_phone` (`phone`),
            KEY `idx_users_school_type` (`school_id`,`user_type`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getUserRolesTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `user_roles` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` int(10) UNSIGNED NOT NULL,
            `role_id` int(10) UNSIGNED NOT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_user_role` (`user_id`,`role_id`),
            KEY `role_id` (`role_id`),
            KEY `idx_user` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    /**
     * 2. ENHANCED FEATURE TABLES
     */
    
    private static function getSubscriptionsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `subscriptions` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `plan_id` varchar(50) NOT NULL,
            `plan_name` varchar(100) NOT NULL,
            `status` enum('active','pending','cancelled','expired','past_due') DEFAULT 'pending',
            `billing_cycle` enum('monthly','quarterly','yearly') DEFAULT 'monthly',
            `amount` decimal(10,2) NOT NULL,
            `currency` varchar(3) DEFAULT 'NGN',
            `storage_limit` bigint(20) DEFAULT 1073741824,
            `user_limit` int(10) DEFAULT 100,
            `student_limit` int(10) DEFAULT 500,
            `features` text COMMENT 'JSON encoded features',
            `current_period_start` date NOT NULL,
            `current_period_end` date NOT NULL,
            `cancel_at_period_end` tinyint(1) DEFAULT 0,
            `cancelled_at` timestamp NULL DEFAULT NULL,
            `trial_ends_at` timestamp NULL DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_school_subscription` (`school_id`),
            KEY `idx_status` (`status`),
            KEY `idx_period` (`current_period_end`),
            KEY `idx_school_plan` (`school_id`,`plan_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getBillingHistoryTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `billing_history` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `subscription_id` int(10) UNSIGNED DEFAULT NULL,
            `invoice_number` varchar(100) NOT NULL,
            `description` varchar(255) DEFAULT NULL,
            `amount` decimal(10,2) NOT NULL,
            `tax_amount` decimal(10,2) DEFAULT 0.00,
            `total_amount` decimal(10,2) NOT NULL,
            `currency` varchar(3) DEFAULT 'NGN',
            `payment_method` varchar(50) DEFAULT NULL,
            `payment_status` enum('pending','paid','failed','refunded') DEFAULT 'pending',
            `payment_date` timestamp NULL DEFAULT NULL,
            `due_date` date NOT NULL,
            `paid_at` timestamp NULL DEFAULT NULL,
            `transaction_id` varchar(255) DEFAULT NULL,
            `payment_gateway` varchar(50) DEFAULT NULL,
            `gateway_response` text,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `invoice_number` (`invoice_number`),
            KEY `subscription_id` (`subscription_id`),
            KEY `idx_school` (`school_id`),
            KEY `idx_payment_status` (`payment_status`),
            KEY `idx_payment_date` (`payment_date`),
            KEY `idx_school_status` (`school_id`,`payment_status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getPaymentMethodsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `payment_methods` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `type` enum('card','bank_transfer','mobile_money','wallet') NOT NULL,
            `provider` varchar(50) DEFAULT NULL,
            `last_four` varchar(4) DEFAULT NULL,
            `exp_month` int(2) DEFAULT NULL,
            `exp_year` int(4) DEFAULT NULL,
            `is_default` tinyint(1) DEFAULT 0,
            `is_verified` tinyint(1) DEFAULT 0,
            `metadata` text COMMENT 'JSON encoded metadata',
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_school` (`school_id`),
            KEY `idx_type` (`type`),
            KEY `idx_default` (`is_default`),
            KEY `idx_school_default` (`school_id`,`is_default`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getInvoicesV2TableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `invoices_v2` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `invoice_number` varchar(100) NOT NULL,
            `billing_history_id` int(10) UNSIGNED DEFAULT NULL,
            `amount` decimal(10,2) NOT NULL,
            `tax` decimal(10,2) DEFAULT 0.00,
            `discount` decimal(10,2) DEFAULT 0.00,
            `total_amount` decimal(10,2) NOT NULL,
            `currency` varchar(3) DEFAULT 'NGN',
            `status` enum('draft','sent','viewed','paid','overdue','cancelled') DEFAULT 'draft',
            `due_date` date NOT NULL,
            `paid_date` timestamp NULL DEFAULT NULL,
            `notes` text,
            `terms` text,
            `pdf_path` varchar(500) DEFAULT NULL,
            `sent_at` timestamp NULL DEFAULT NULL,
            `viewed_at` timestamp NULL DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `invoice_number` (`invoice_number`),
            KEY `billing_history_id` (`billing_history_id`),
            KEY `idx_school` (`school_id`),
            KEY `idx_status` (`status`),
            KEY `idx_due_date` (`due_date`),
            KEY `idx_school_status` (`school_id`,`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getStorageUsageTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `storage_usage` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `storage_type` enum('database','files','backups','attachments') NOT NULL,
            `used_bytes` bigint(20) DEFAULT 0,
            `limit_bytes` bigint(20) DEFAULT 1073741824,
            `file_count` int(10) DEFAULT 0,
            `last_calculated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_school_storage` (`school_id`,`storage_type`),
            KEY `idx_school` (`school_id`),
            KEY `idx_type` (`storage_type`),
            KEY `idx_usage` (`used_bytes`),
            KEY `idx_school_type` (`school_id`,`storage_type`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getFileStorageTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `file_storage` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `user_id` int(10) UNSIGNED DEFAULT NULL,
            `file_name` varchar(255) NOT NULL,
            `file_path` varchar(500) NOT NULL,
            `file_type` varchar(100) NOT NULL,
            `file_size` bigint(20) NOT NULL,
            `mime_type` varchar(100) DEFAULT NULL,
            `storage_type` enum('local','s3','cloudinary','wasabi') DEFAULT 'local',
            `bucket_name` varchar(255) DEFAULT NULL,
            `object_key` varchar(500) DEFAULT NULL,
            `is_public` tinyint(1) DEFAULT 0,
            `access_hash` varchar(100) DEFAULT NULL,
            `expires_at` timestamp NULL DEFAULT NULL,
            `download_count` int(10) DEFAULT 0,
            `last_downloaded` timestamp NULL DEFAULT NULL,
            `metadata` text COMMENT 'JSON encoded metadata',
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `user_id` (`user_id`),
            KEY `idx_school` (`school_id`),
            KEY `idx_file_type` (`file_type`),
            KEY `idx_created_at` (`created_at`),
            KEY `idx_school_type` (`school_id`,`file_type`),
            KEY `idx_access_hash` (`access_hash`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getPerformanceMetricsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `performance_metrics` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `metric_type` enum('api_response','page_load','query_time','memory_usage','cpu_usage') NOT NULL,
            `endpoint` varchar(500) DEFAULT NULL,
            `value` decimal(10,4) NOT NULL,
            `unit` varchar(20) DEFAULT NULL,
            `sample_count` int(10) DEFAULT 1,
            `min_value` decimal(10,4) DEFAULT NULL,
            `max_value` decimal(10,4) DEFAULT NULL,
            `avg_value` decimal(10,4) DEFAULT NULL,
            `p95_value` decimal(10,4) DEFAULT NULL,
            `metadata` text COMMENT 'JSON encoded metadata',
            `recorded_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_school` (`school_id`),
            KEY `idx_metric_type` (`metric_type`),
            KEY `idx_recorded_at` (`recorded_at`),
            KEY `idx_school_metric` (`school_id`,`metric_type`,`recorded_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getApiLogsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `api_logs` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED DEFAULT NULL,
            `api_key_id` int(10) UNSIGNED DEFAULT NULL,
            `endpoint` varchar(500) NOT NULL,
            `method` varchar(10) NOT NULL,
            `request_body` text,
            `response_body` text,
            `status_code` int(3) DEFAULT NULL,
            `response_time` decimal(10,4) DEFAULT NULL,
            `ip_address` varchar(45) DEFAULT NULL,
            `user_agent` text,
            `is_success` tinyint(1) DEFAULT 0,
            `error_message` text,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `api_key_id` (`api_key_id`),
            KEY `idx_school` (`school_id`),
            KEY `idx_endpoint` (`endpoint`),
            KEY `idx_status_code` (`status_code`),
            KEY `idx_created_at` (`created_at`),
            KEY `idx_school_endpoint` (`school_id`,`endpoint`,`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getAuditLogsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `audit_logs` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `user_id` int(10) UNSIGNED DEFAULT NULL,
            `user_type` varchar(50) DEFAULT NULL,
            `action` varchar(100) NOT NULL,
            `entity_type` varchar(100) DEFAULT NULL,
            `entity_id` int(10) UNSIGNED DEFAULT NULL,
            `old_values` text COMMENT 'JSON encoded old values',
            `new_values` text COMMENT 'JSON encoded new values',
            `ip_address` varchar(45) DEFAULT NULL,
            `user_agent` text,
            `url` varchar(500) DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `user_id` (`user_id`),
            KEY `idx_school` (`school_id`),
            KEY `idx_action` (`action`),
            KEY `idx_entity` (`entity_type`,`entity_id`),
            KEY `idx_created_at` (`created_at`),
            KEY `idx_school_action` (`school_id`,`action`,`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getSecurityLogsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `security_logs` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED DEFAULT NULL,
            `event_type` enum('login_attempt','failed_login','password_change','session_start','session_end','suspicious_activity','blocked_ip') NOT NULL,
            `severity` enum('low','medium','high','critical') DEFAULT 'low',
            `user_id` int(10) UNSIGNED DEFAULT NULL,
            `ip_address` varchar(45) DEFAULT NULL,
            `user_agent` text,
            `location` varchar(255) DEFAULT NULL,
            `details` text,
            `resolved` tinyint(1) DEFAULT 0,
            `resolved_at` timestamp NULL DEFAULT NULL,
            `resolved_by` int(10) UNSIGNED DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `user_id` (`user_id`),
            KEY `idx_school` (`school_id`),
            KEY `idx_event_type` (`event_type`),
            KEY `idx_severity` (`severity`),
            KEY `idx_ip` (`ip_address`),
            KEY `idx_created_at` (`created_at`),
            KEY `idx_school_event` (`school_id`,`event_type`,`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getRateLimitsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `rate_limits` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `endpoint` varchar(500) NOT NULL,
            `ip_address` varchar(45) NOT NULL,
            `user_id` int(10) UNSIGNED DEFAULT NULL,
            `request_count` int(10) DEFAULT 1,
            `limit_reached` tinyint(1) DEFAULT 0,
            `first_request` timestamp NOT NULL DEFAULT current_timestamp(),
            `last_request` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            `window_reset` timestamp NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_rate_limit` (`school_id`,`endpoint`,`ip_address`,`user_id`),
            KEY `idx_school` (`school_id`),
            KEY `idx_endpoint` (`endpoint`),
            KEY `idx_ip` (`ip_address`),
            KEY `idx_window_reset` (`window_reset`),
            KEY `idx_school_endpoint_ip` (`school_id`,`endpoint`,`ip_address`,`last_request`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getLoginAttemptsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `login_attempts` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED DEFAULT NULL,
            `username` varchar(255) NOT NULL,
            `ip_address` varchar(45) NOT NULL,
            `user_agent` text,
            `success` tinyint(1) DEFAULT 0,
            `failed_reason` varchar(255) DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_school` (`school_id`),
            KEY `idx_username` (`username`),
            KEY `idx_ip` (`ip_address`),
            KEY `idx_success` (`success`),
            KEY `idx_created_at` (`created_at`),
            KEY `idx_school_ip` (`school_id`,`ip_address`,`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getBackupHistoryTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `backup_history` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `backup_type` enum('full','incremental','differential','schema_only') DEFAULT 'full',
            `storage_type` enum('local','s3','ftp','google_drive') DEFAULT 'local',
            `file_name` varchar(255) NOT NULL,
            `file_path` varchar(500) DEFAULT NULL,
            `file_size` bigint(20) DEFAULT NULL,
            `database_size` bigint(20) DEFAULT NULL,
            `table_count` int(10) DEFAULT NULL,
            `status` enum('pending','in_progress','completed','failed','cancelled') DEFAULT 'pending',
            `error_message` text,
            `started_at` timestamp NULL DEFAULT NULL,
            `completed_at` timestamp NULL DEFAULT NULL,
            `retention_days` int(10) DEFAULT 30,
            `expires_at` timestamp NULL DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_school` (`school_id`),
            KEY `idx_backup_type` (`backup_type`),
            KEY `idx_status` (`status`),
            KEY `idx_created_at` (`created_at`),
            KEY `idx_expires_at` (`expires_at`),
            KEY `idx_school_status` (`school_id`,`status`,`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getRecoveryPointsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `recovery_points` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `backup_id` int(10) UNSIGNED DEFAULT NULL,
            `point_name` varchar(255) NOT NULL,
            `description` text,
            `recovery_type` enum('full','partial','data_only','schema_only') DEFAULT 'full',
            `tables_included` text COMMENT 'JSON array of tables',
            `status` enum('available','restoring','restored','failed') DEFAULT 'available',
            `file_path` varchar(500) DEFAULT NULL,
            `file_size` bigint(20) DEFAULT NULL,
            `checksum` varchar(64) DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `restored_at` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `backup_id` (`backup_id`),
            KEY `idx_school` (`school_id`),
            KEY `idx_status` (`status`),
            KEY `idx_created_at` (`created_at`),
            KEY `idx_school_status` (`school_id`,`status`,`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getNotificationsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `notifications` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `user_id` int(10) UNSIGNED NOT NULL,
            `type` enum('email','sms','push','in_app','system') DEFAULT 'in_app',
            `title` varchar(255) NOT NULL,
            `message` text NOT NULL,
            `data` text COMMENT 'JSON encoded data',
            `priority` enum('low','normal','high','urgent') DEFAULT 'normal',
            `is_read` tinyint(1) DEFAULT 0,
            `read_at` timestamp NULL DEFAULT NULL,
            `is_sent` tinyint(1) DEFAULT 0,
            `sent_at` timestamp NULL DEFAULT NULL,
            `delivery_status` enum('pending','sent','delivered','failed','bounced') DEFAULT 'pending',
            `failure_reason` text,
            `scheduled_for` timestamp NULL DEFAULT NULL,
            `expires_at` timestamp NULL DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `user_id` (`user_id`),
            KEY `idx_school` (`school_id`),
            KEY `idx_type` (`type`),
            KEY `idx_is_read` (`is_read`),
            KEY `idx_priority` (`priority`),
            KEY `idx_created_at` (`created_at`),
            KEY `idx_school_user` (`school_id`,`user_id`,`is_read`,`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getEmailTemplatesTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `email_templates` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `template_key` varchar(100) NOT NULL,
            `name` varchar(255) NOT NULL,
            `subject` varchar(255) NOT NULL,
            `body_html` text NOT NULL,
            `body_text` text,
            `variables` text COMMENT 'JSON array of available variables',
            `is_active` tinyint(1) DEFAULT 1,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_template` (`school_id`,`template_key`),
            KEY `idx_school` (`school_id`),
            KEY `idx_template_key` (`template_key`),
            KEY `idx_is_active` (`is_active`),
            KEY `idx_school_active` (`school_id`,`is_active`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getSmsLogsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `sms_logs` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `recipient` varchar(20) NOT NULL,
            `message` text NOT NULL,
            `sender_id` varchar(20) DEFAULT NULL,
            `message_id` varchar(100) DEFAULT NULL,
            `status` enum('pending','sent','delivered','failed','undelivered') DEFAULT 'pending',
            `status_code` varchar(50) DEFAULT NULL,
            `status_message` text,
            `cost` decimal(8,4) DEFAULT NULL,
            `units` int(10) DEFAULT NULL,
            `provider` varchar(50) DEFAULT NULL,
            `sent_at` timestamp NULL DEFAULT NULL,
            `delivered_at` timestamp NULL DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_school` (`school_id`),
            KEY `idx_recipient` (`recipient`),
            KEY `idx_status` (`status`),
            KEY `idx_created_at` (`created_at`),
            KEY `idx_school_status` (`school_id`,`status`,`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getApiKeysTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `api_keys` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `name` varchar(255) NOT NULL,
            `api_key` varchar(100) NOT NULL,
            `api_secret` varchar(100) DEFAULT NULL,
            `permissions` text COMMENT 'JSON encoded permissions',
            `rate_limit_per_minute` int(10) DEFAULT 60,
            `rate_limit_per_hour` int(10) DEFAULT 1000,
            `rate_limit_per_day` int(10) DEFAULT 10000,
            `allowed_ips` text COMMENT 'JSON array of allowed IPs',
            `allowed_origins` text COMMENT 'JSON array of allowed origins',
            `expires_at` timestamp NULL DEFAULT NULL,
            `last_used_at` timestamp NULL DEFAULT NULL,
            `is_active` tinyint(1) DEFAULT 1,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `api_key` (`api_key`),
            KEY `idx_school` (`school_id`),
            KEY `idx_is_active` (`is_active`),
            KEY `idx_expires_at` (`expires_at`),
            KEY `idx_school_active` (`school_id`,`is_active`,`expires_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getApiUsageTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `api_usage` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `api_key_id` int(10) UNSIGNED DEFAULT NULL,
            `endpoint` varchar(500) NOT NULL,
            `method` varchar(10) NOT NULL,
            `request_count` int(10) DEFAULT 1,
            `total_response_time` decimal(12,4) DEFAULT 0,
            `failed_count` int(10) DEFAULT 0,
            `period` enum('minute','hour','day','month') DEFAULT 'day',
            `period_start` timestamp NOT NULL,
            `period_end` timestamp NOT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_api_usage` (`school_id`,`api_key_id`,`endpoint`,`method`,`period`,`period_start`),
            KEY `api_key_id` (`api_key_id`),
            KEY `idx_school` (`school_id`),
            KEY `idx_endpoint` (`endpoint`),
            KEY `idx_period` (`period`),
            KEY `idx_period_start` (`period_start`),
            KEY `idx_school_period` (`school_id`,`period`,`period_start`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getMaintenanceLogsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `maintenance_logs` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `maintenance_type` enum('database_optimization','cache_clear','backup_cleanup','storage_cleanup','system_update') NOT NULL,
            `description` text NOT NULL,
            `status` enum('pending','running','completed','failed','cancelled') DEFAULT 'pending',
            `started_at` timestamp NULL DEFAULT NULL,
            `completed_at` timestamp NULL DEFAULT NULL,
            `duration_seconds` int(10) DEFAULT NULL,
            `affected_records` int(10) DEFAULT NULL,
            `freed_space` bigint(20) DEFAULT NULL,
            `error_message` text,
            `performed_by` int(10) UNSIGNED DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `performed_by` (`performed_by`),
            KEY `idx_school` (`school_id`),
            KEY `idx_maintenance_type` (`maintenance_type`),
            KEY `idx_status` (`status`),
            KEY `idx_created_at` (`created_at`),
            KEY `idx_school_type` (`school_id`,`maintenance_type`,`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    private static function getSystemAlertsTableSql()
    {
        return "CREATE TABLE IF NOT EXISTS `system_alerts` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `school_id` int(10) UNSIGNED NOT NULL,
            `alert_type` enum('storage_limit','user_limit','subscription_expiry','payment_failed','performance_issue','security_issue','system_error') NOT NULL,
            `severity` enum('info','warning','error','critical') DEFAULT 'info',
            `title` varchar(255) NOT NULL,
            `message` text NOT NULL,
            `data` text COMMENT 'JSON encoded data',
            `is_resolved` tinyint(1) DEFAULT 0,
            `resolved_at` timestamp NULL DEFAULT NULL,
            `resolved_by` int(10) UNSIGNED DEFAULT NULL,
            `resolution_notes` text,
            `acknowledged` tinyint(1) DEFAULT 0,
            `acknowledged_at` timestamp NULL DEFAULT NULL,
            `acknowledged_by` int(10) UNSIGNED DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_school` (`school_id`),
            KEY `idx_alert_type` (`alert_type`),
            KEY `idx_severity` (`severity`),
            KEY `idx_is_resolved` (`is_resolved`),
            KEY `idx_created_at` (`created_at`),
            KEY `idx_school_resolved` (`school_id`,`is_resolved`,`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    /**
     * =================================================================
     * HELPER METHODS
     * =================================================================
     */

    /**
     * Insert default data into new school database
     * @param PDO $db
     * @param int $schoolId
     */
    private static function insertDefaultData($db, $schoolId)
    {
        try {
            // Insert default roles
            $db->exec("INSERT IGNORE INTO `roles` (`school_id`, `name`, `slug`, `description`, `permissions`, `is_system`, `created_at`) VALUES
                ($schoolId, 'Super Administrator', 'super_admin', 'Has full access to all features', '[\"*\"]', 1, NOW()),
                ($schoolId, 'School Administrator', 'school_admin', 'Manages school operations', '[\"dashboard.view\", \"students.*\", \"teachers.*\", \"classes.*\", \"attendance.*\", \"exams.*\", \"fees.*\", \"reports.*\", \"settings.*\"]', 1, NOW()),
                ($schoolId, 'Teacher', 'teacher', 'Can manage classes and students', '[\"dashboard.view\", \"attendance.mark\", \"grades.enter\", \"homework.*\", \"students.view\"]', 1, NOW()),
                ($schoolId, 'Student', 'student', 'Can view their own information', '[\"dashboard.view\", \"timetable.view\", \"grades.view\", \"homework.view\"]', 1, NOW()),
                ($schoolId, 'Parent', 'parent', 'Can view child information', '[\"dashboard.view\", \"children.view\", \"attendance.view\", \"fees.view\"]', 1, NOW()),
                ($schoolId, 'Accountant', 'accountant', 'Manages financial operations', '[\"dashboard.view\", \"fees.*\", \"payments.*\", \"invoices.*\", \"reports.financial\"]', 1, NOW()),
                ($schoolId, 'Librarian', 'librarian', 'Manages library operations', '[\"dashboard.view\", \"library.*\"]', 1, NOW())");

            // Insert default settings
            $db->exec("INSERT IGNORE INTO `settings` (`school_id`, `key`, `value`, `type`, `category`, `created_at`, `updated_at`) VALUES
                ($schoolId, 'school_name', 'New School', 'string', 'general', NOW(), NOW()),
                ($schoolId, 'school_email', '', 'string', 'general', NOW(), NOW()),
                ($schoolId, 'school_phone', '', 'string', 'general', NOW(), NOW()),
                ($schoolId, 'school_address', '', 'string', 'general', NOW(), NOW()),
                ($schoolId, 'currency', 'NGN', 'string', 'financial', NOW(), NOW()),
                ($schoolId, 'currency_symbol', '₦', 'string', 'financial', NOW(), NOW()),
                ($schoolId, 'attendance_method', 'daily', 'string', 'academic', NOW(), NOW()),
                ($schoolId, 'grading_system', 'percentage', 'string', 'academic', NOW(), NOW()),
                ($schoolId, 'result_publish', 'immediate', 'string', 'academic', NOW(), NOW()),
                ($schoolId, 'fee_due_days', '30', 'number', 'financial', NOW(), NOW()),
                ($schoolId, 'late_fee_percentage', '5', 'number', 'financial', NOW(), NOW())");

            // Insert default subscription plan (Free tier)
            $db->exec("INSERT IGNORE INTO `subscriptions` (`school_id`, `plan_id`, `plan_name`, `status`, `billing_cycle`, `amount`, `storage_limit`, `user_limit`, `student_limit`, `current_period_start`, `current_period_end`, `created_at`) VALUES
                ($schoolId, 'free_tier', 'Free Plan', 'active', 'monthly', 0.00, 1073741824, 100, 500, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 1 MONTH), NOW())");

            // Insert default storage usage
            $db->exec("INSERT IGNORE INTO `storage_usage` (`school_id`, `storage_type`, `used_bytes`, `limit_bytes`, `created_at`) VALUES
                ($schoolId, 'database', 0, 1073741824, NOW()),
                ($schoolId, 'files', 0, 1073741824, NOW()),
                ($schoolId, 'backups', 0, 536870912, NOW()),
                ($schoolId, 'attachments', 0, 536870912, NOW())");

            self::logInfo("Inserted default data for school ID: " . $schoolId);
            return true;
        } catch (Exception $e) {
            self::logError("Error inserting default data", $e);
            return false;
        }
    }

    /**
     * Create performance indexes
     * @param PDO $db
     */
    private static function createPerformanceIndexes($db)
    {
        try {
            // Add performance indexes for commonly queried columns
            $indexes = [
                "CREATE INDEX IF NOT EXISTS idx_users_email_type ON users(email, user_type)",
                "CREATE INDEX IF NOT EXISTS idx_students_admission_date ON students(admission_date)",
                "CREATE INDEX IF NOT EXISTS idx_attendance_student_date ON attendance(student_id, date)",
                "CREATE INDEX IF NOT EXISTS idx_payments_invoice_date ON payments(invoice_id, payment_date)",
                "CREATE INDEX IF NOT EXISTS idx_subscriptions_status_end ON subscriptions(status, current_period_end)",
                "CREATE INDEX IF NOT EXISTS idx_storage_usage_school_type ON storage_usage(school_id, storage_type)",
                "CREATE INDEX IF NOT EXISTS idx_api_logs_school_endpoint ON api_logs(school_id, endpoint, created_at)",
                "CREATE INDEX IF NOT EXISTS idx_audit_logs_school_action ON audit_logs(school_id, action, created_at)",
                "CREATE INDEX IF NOT EXISTS idx_backup_history_school_status ON backup_history(school_id, status, created_at)",
                "CREATE INDEX IF NOT EXISTS idx_notifications_school_user_read ON notifications(school_id, user_id, is_read, created_at)"
            ];

            foreach ($indexes as $indexSql) {
                try {
                    $db->exec($indexSql);
                } catch (Exception $e) {
                    self::logWarning("Failed to create index: " . $e->getMessage());
                }
            }

            self::logInfo("Created performance indexes");
        } catch (Exception $e) {
            self::logError("Error creating performance indexes", $e);
        }
    }

    /**
 * Get school statistics for dashboard
 * @param int $schoolId
 * @return array
 */
public static function getSchoolStatistics($schoolId)
{
    try {
        $school = self::getSchoolById($schoolId);
        if (!$school || empty($school['database_name'])) {
            return ['students' => 0, 'teachers' => 0, 'admins' => 0, 'parents' => 0];
        }

        $schoolDb = Database::getSchoolConnection($school['database_name']);
        
        $stats = [
            'students' => 0,
            'teachers' => 0,
            'admins' => 0,
            'parents' => 0
        ];

        // Get student count
        try {
            $stmt = $schoolDb->prepare("SELECT COUNT(*) as count FROM students WHERE status = 'active'");
            $stmt->execute();
            $result = $stmt->fetch();
            $stats['students'] = (int)$result['count'] ?? 0;
        } catch (Exception $e) {
            self::logError("Error counting students", $e);
        }

        // Get teacher count
        try {
            $stmt = $schoolDb->prepare("SELECT COUNT(*) as count FROM teachers WHERE is_active = 1");
            $stmt->execute();
            $result = $stmt->fetch();
            $stats['teachers'] = (int)$result['count'] ?? 0;
        } catch (Exception $e) {
            self::logError("Error counting teachers", $e);
        }

        // Get admin count
        try {
            $stmt = $schoolDb->prepare("SELECT COUNT(*) as count FROM users WHERE user_type = 'admin' AND is_active = 1");
            $stmt->execute();
            $result = $stmt->fetch();
            $stats['admins'] = (int)$result['count'] ?? 0;
        } catch (Exception $e) {
            self::logError("Error counting admins", $e);
        }

        // Get parent count
        try {
            $stmt = $schoolDb->prepare("SELECT COUNT(*) as count FROM users WHERE user_type = 'parent' AND is_active = 1");
            $stmt->execute();
            $result = $stmt->fetch();
            $stats['parents'] = (int)$result['count'] ?? 0;
        } catch (Exception $e) {
            self::logError("Error counting parents", $e);
        }

        return $stats;
        
    } catch (Exception $e) {
        self::logError("Error getting school statistics", $e);
        return ['students' => 0, 'teachers' => 0, 'admins' => 0, 'parents' => 0];
    }
}

    /**
     * Create initial admin user in school database
     * @param PDO $db School database connection
     * @param array $schoolData
     * @return int|false Admin user ID
     */
    private static function createInitialAdmin($db, $schoolData)
    {
        try {
            $hashedPassword = password_hash($schoolData['admin_password'], PASSWORD_BCRYPT);

            // Insert admin user
            $stmt = $db->prepare("
                INSERT INTO users 
                (school_id, name, email, phone, password, user_type, is_active) 
                VALUES (?, ?, ?, ?, ?, 'admin', 1)
            ");

            $stmt->execute([
                $schoolData['id'],
                $schoolData['admin_name'],
                $schoolData['admin_email'],
                $schoolData['admin_phone'],
                $hashedPassword
            ]);

            $adminUserId = $db->lastInsertId();
            self::logInfo("Admin user created with ID: " . $adminUserId);

            // Get school_admin role ID
            $roleStmt = $db->prepare("SELECT id FROM roles WHERE slug = 'school_admin' AND school_id = ? LIMIT 1");
            $roleStmt->execute([$schoolData['id']]);
            $role = $roleStmt->fetch();

            if ($role) {
                $roleId = $role['id'];
                
                // Assign role to user
                $userRoleStmt = $db->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)");
                $userRoleStmt->execute([$adminUserId, $roleId]);
                self::logInfo("Assigned role ID " . $roleId . " to admin user");
            } else {
                self::logWarning("school_admin role not found for school ID " . $schoolData['id']);
                return false;
            }

            return $adminUserId;
        } catch (Exception $e) {
            self::logError("Failed to create initial admin", $e);
            return false;
        }
    }

    /**
     * Initialize subscription data for new school
     * @param PDO $db
     * @param int $schoolId
     */
    private static function initializeSubscriptionData($db, $schoolId)
    {
        try {
            // Insert default free subscription
            $stmt = $db->prepare("
                INSERT INTO subscriptions 
                (school_id, plan_id, plan_name, status, billing_cycle, amount, 
                 storage_limit, user_limit, student_limit, 
                 current_period_start, current_period_end, created_at) 
                VALUES (?, 'free_tier', 'Free Plan', 'active', 'monthly', 0.00, 
                        1073741824, 100, 500, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 1 MONTH), NOW())
            ");
            $stmt->execute([$schoolId]);
            
            self::logInfo("Initialized subscription data for school ID: " . $schoolId);
        } catch (Exception $e) {
            self::logError("Error initializing subscription data", $e);
        }
    }

    /**
     * Create initial backup for new school
     * @param int $schoolId
     */
    private static function createInitialBackup($schoolId)
    {
        try {
            // This would call your backup method
            // For now, just log it
            self::logInfo("Initial backup triggered for school ID: " . $schoolId);
        } catch (Exception $e) {
            self::logError("Error creating initial backup", $e);
        }
    }

    /**
     * =================================================================
     * ENHANCED FEATURE METHODS
     * =================================================================
     */

    /**
     * Check subscription limits before creating school
     * @param int $schoolId
     * @return bool
     */
    private static function checkSubscriptionLimits($schoolId)
    {
        // This would check against platform-wide subscription limits
        // For now, we'll just return true
        return true;
    }

    /**
     * Check if school has exceeded storage limits
     * @param int $schoolId
     * @param string $storageType
     * @return array [isExceeded, usedBytes, limitBytes, percentage]
     */
    public static function checkStorageLimit($schoolId, $storageType = 'total')
    {
        try {
            $school = self::getSchoolById($schoolId);
            if (!$school || empty($school['database_name'])) {
                return [false, 0, 0, 0];
            }

            $schoolDb = Database::getSchoolConnection($school['database_name']);
            
            if ($storageType === 'total') {
                $stmt = $schoolDb->prepare("
                    SELECT SUM(used_bytes) as total_used, SUM(limit_bytes) as total_limit 
                    FROM storage_usage 
                    WHERE school_id = ?
                ");
                $stmt->execute([$schoolId]);
            } else {
                $stmt = $schoolDb->prepare("
                    SELECT used_bytes, limit_bytes 
                    FROM storage_usage 
                    WHERE school_id = ? AND storage_type = ?
                ");
                $stmt->execute([$schoolId, $storageType]);
            }
            
            $result = $stmt->fetch();
            
            if (!$result) {
                return [false, 0, 0, 0];
            }
            
            $usedBytes = (int)$result['total_used'] ?? (int)$result['used_bytes'];
            $limitBytes = (int)$result['total_limit'] ?? (int)$result['limit_bytes'];
            $percentage = $limitBytes > 0 ? ($usedBytes / $limitBytes) * 100 : 0;
            
            $isExceeded = $usedBytes >= $limitBytes;
            
            // Create alert if approaching limit (80% or more)
            if ($percentage >= 80 && $percentage < 100) {
                self::createStorageAlert($schoolId, 'warning', $percentage, $storageType);
            } elseif ($isExceeded) {
                self::createStorageAlert($schoolId, 'critical', 100, $storageType);
            }
            
            return [$isExceeded, $usedBytes, $limitBytes, $percentage];
        } catch (Exception $e) {
            self::logError("Error checking storage limit", $e);
            return [false, 0, 0, 0];
        }
    }

    /**
     * Update storage usage
     * @param int $schoolId
     * @param string $storageType
     * @param int $additionalBytes
     * @return bool
     */
    public static function updateStorageUsage($schoolId, $storageType, $additionalBytes)
    {
        try {
            // Check current limit before updating
            list($isExceeded, $usedBytes, $limitBytes) = self::checkStorageLimit($schoolId, $storageType);
            
            if ($isExceeded && $additionalBytes > 0) {
                throw new Exception("Storage limit exceeded for $storageType");
            }
            
            $school = self::getSchoolById($schoolId);
            if (!$school || empty($school['database_name'])) {
                return false;
            }
            
            $schoolDb = Database::getSchoolConnection($school['database_name']);
            
            $stmt = $schoolDb->prepare("
                INSERT INTO storage_usage (school_id, storage_type, used_bytes, limit_bytes) 
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                used_bytes = used_bytes + VALUES(used_bytes),
                last_calculated = NOW()
            ");
            
            $limitBytes = self::getStorageLimitForSchool($schoolId, $storageType);
            
            $stmt->execute([
                $schoolId,
                $storageType,
                $additionalBytes,
                $limitBytes
            ]);
            
            self::logInfo("Updated storage usage for school $schoolId, type $storageType: +$additionalBytes bytes");
            
            return true;
        } catch (Exception $e) {
            self::logError("Error updating storage usage", $e);
            return false;
        }
    }

    /**
     * Get storage limit for school based on subscription
     * @param int $schoolId
     * @param string $storageType
     * @return int
     */
   private static function getStorageLimitForSchool($schoolId, $storageType)
{
    try {
        $school = self::getSchoolById($schoolId);
        if (!$school || empty($school['database_name'])) {
            return self::$storageLimits['free'];
        }
        
        // Get plan from platform database
        $platformDb = Database::getPlatformConnection();
        $stmt = $platformDb->prepare("
            SELECT p.storage_limit 
            FROM schools s
            JOIN plans p ON s.plan_id = p.id
            WHERE s.id = ?
        ");
        $stmt->execute([$schoolId]);
        $result = $stmt->fetch();
        
        if (!$result || empty($result['storage_limit'])) {
            return self::$storageLimits['free'];
        }
        
        $totalLimit = (int)$result['storage_limit'] * 1024 * 1024; // Convert MB to bytes
        
        // Same allocation logic as before
        $allocations = [
            'starter' => ['database' => 0.3, 'files' => 0.4, 'backups' => 0.2, 'attachments' => 0.1],
            'growth' => ['database' => 0.4, 'files' => 0.3, 'backups' => 0.2, 'attachments' => 0.1],
            'enterprise' => ['database' => 0.5, 'files' => 0.3, 'backups' => 0.1, 'attachments' => 0.1]
        ];
        
        $planSlug = $school['plan_name'] ?? 'starter';
        $allocation = $allocations[$planSlug] ?? $allocations['starter'];
        
        if ($storageType === 'total') {
            return $totalLimit;
        }
        
        return (int)($totalLimit * ($allocation[$storageType] ?? 0.1));
    } catch (Exception $e) {
        return self::$storageLimits['free'];
    }
}

/**
 * Check if enhanced features are available
 */
public static function hasEnhancedFeatures($schoolId)
{
    try {
        $school = self::getSchoolById($schoolId);
        if (!$school || empty($school['database_name'])) {
            return false;
        }
        
        $schoolDb = Database::getSchoolConnection($school['database_name']);
        
        // Check if storage_usage table exists
        $tables = $schoolDb->query("SHOW TABLES LIKE 'storage_usage'")->fetchAll();
        
        return count($tables) > 0;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Safe storage check with fallback
 */
public static function safeCheckStorageLimit($schoolId, $storageType = 'total')
{
    if (!self::hasEnhancedFeatures($schoolId)) {
        // Return unlimited for schools without enhanced features
        return [false, 0, PHP_INT_MAX, 0];
    }
    
    return self::checkStorageLimit($schoolId, $storageType);
}

    /**
     * Create storage alert
     * @param int $schoolId
     * @param string $severity
     * @param float $percentage
     * @param string $storageType
     */
    private static function createStorageAlert($schoolId, $severity, $percentage, $storageType)
    {
        try {
            $school = self::getSchoolById($schoolId);
            if (!$school || empty($school['database_name'])) {
                return;
            }
            
            $schoolDb = Database::getSchoolConnection($school['database_name']);
            
            $title = "Storage Limit " . ($percentage >= 100 ? "Exceeded" : "Warning");
            $message = "Storage usage for $storageType is at " . round($percentage, 1) . "% of limit";
            
            $stmt = $schoolDb->prepare("
                INSERT INTO system_alerts 
                (school_id, alert_type, severity, title, message, data, created_at) 
                VALUES (?, 'storage_limit', ?, ?, ?, ?, NOW())
            ");
            
            $data = json_encode([
                'storage_type' => $storageType,
                'percentage' => $percentage,
                'threshold' => $percentage >= 100 ? 'exceeded' : 'warning'
            ]);
            
            $stmt->execute([$schoolId, $severity, $title, $message, $data]);
            
        } catch (Exception $e) {
            self::logError("Error creating storage alert", $e);
        }
    }

    /**
     * Track performance metric
     * @param string $metricType
     * @param int $schoolId
     * @param array $data
     */
    public static function logPerformanceMetric($metricType, $schoolId, $data = [])
    {
        try {
            $school = self::getSchoolById($schoolId);
            if (!$school || empty($school['database_name'])) {
                return;
            }
            
            $schoolDb = Database::getSchoolConnection($school['database_name']);
            
            $endpoint = $data['endpoint'] ?? null;
            $value = $data['value'] ?? 0;
            $unit = $data['unit'] ?? null;
            
            $stmt = $schoolDb->prepare("
                INSERT INTO performance_metrics 
                (school_id, metric_type, endpoint, value, unit, metadata, recorded_at) 
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $metadata = json_encode($data);
            
            $stmt->execute([$schoolId, $metricType, $endpoint, $value, $unit, $metadata]);
            
        } catch (Exception $e) {
            self::logError("Error logging performance metric", $e);
        }
    }

    /**
     * Check API rate limit
     * @param int $schoolId
     * @param string $endpoint
     * @param string $ipAddress
     * @param int $userId
     * @param int $limit
     * @param int $windowSeconds
     * @return array [allowed, remaining, resetTime]
     */
    public static function checkRateLimit($schoolId, $endpoint, $ipAddress, $userId = null, $limit = 60, $windowSeconds = 60)
    {
        $key = "{$schoolId}_{$endpoint}_{$ipAddress}" . ($userId ? "_{$userId}" : '');
        
        if (!isset(self::$rateLimits[$key])) {
            self::$rateLimits[$key] = [
                'count' => 0,
                'first_request' => time(),
                'window_reset' => time() + $windowSeconds
            ];
        }
        
        $rateLimit = self::$rateLimits[$key];
        
        // Reset if window has passed
        if (time() > $rateLimit['window_reset']) {
            $rateLimit['count'] = 0;
            $rateLimit['first_request'] = time();
            $rateLimit['window_reset'] = time() + $windowSeconds;
        }
        
        // Check if limit exceeded
        if ($rateLimit['count'] >= $limit) {
            // Log security event
            self::logSecurityEvent($schoolId, 'rate_limit_exceeded', $endpoint, $ipAddress, $userId);
            
            return [
                'allowed' => false,
                'remaining' => 0,
                'reset_time' => $rateLimit['window_reset'],
                'retry_after' => $rateLimit['window_reset'] - time()
            ];
        }
        
        // Increment count
        $rateLimit['count']++;
        self::$rateLimits[$key] = $rateLimit;
        
        // Also log to database for persistence
        self::logRateLimitToDatabase($schoolId, $endpoint, $ipAddress, $userId, $rateLimit['count']);
        
        return [
            'allowed' => true,
            'remaining' => $limit - $rateLimit['count'],
            'reset_time' => $rateLimit['window_reset']
        ];
    }

    /**
     * Log rate limit to database
     * @param int $schoolId
     * @param string $endpoint
     * @param string $ipAddress
     * @param int $userId
     * @param int $requestCount
     */
    private static function logRateLimitToDatabase($schoolId, $endpoint, $ipAddress, $userId, $requestCount)
    {
        try {
            $school = self::getSchoolById($schoolId);
            if (!$school || empty($school['database_name'])) {
                return;
            }
            
            $schoolDb = Database::getSchoolConnection($school['database_name']);
            
            $stmt = $schoolDb->prepare("
                INSERT INTO rate_limits 
                (school_id, endpoint, ip_address, user_id, request_count, window_reset, first_request, last_request) 
                VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 1 MINUTE), NOW(), NOW())
                ON DUPLICATE KEY UPDATE 
                request_count = VALUES(request_count),
                last_request = NOW(),
                window_reset = DATE_ADD(NOW(), INTERVAL 1 MINUTE)
            ");
            
            $stmt->execute([$schoolId, $endpoint, $ipAddress, $userId, $requestCount]);
            
        } catch (Exception $e) {
            self::logError("Error logging rate limit", $e);
        }
    }

    /**
     * Log security event
     * @param int $schoolId
     * @param string $eventType
     * @param string $endpoint
     * @param string $ipAddress
     * @param int $userId
     */
    private static function logSecurityEvent($schoolId, $eventType, $endpoint, $ipAddress, $userId = null)
    {
        try {
            $school = self::getSchoolById($schoolId);
            if (!$school || empty($school['database_name'])) {
                return;
            }
            
            $schoolDb = Database::getSchoolConnection($school['database_name']);
            
            $severity = in_array($eventType, ['rate_limit_exceeded', 'suspicious_activity']) ? 'high' : 'medium';
            $details = "Endpoint: $endpoint, IP: $ipAddress";
            
            $stmt = $schoolDb->prepare("
                INSERT INTO security_logs 
                (school_id, event_type, severity, user_id, ip_address, details, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([$schoolId, $eventType, $severity, $userId, $ipAddress, $details]);
            
        } catch (Exception $e) {
            self::logError("Error logging security event", $e);
        }
    }

    /**
     * =================================================================
     * LOGGING METHODS
     * =================================================================
     */

    /**
     * Log info message
     * @param string $message
     */
    private static function logInfo($message)
    {
        error_log("[INFO] " . $message);
    }

    /**
     * Log warning message
     * @param string $message
     */
    private static function logWarning($message)
    {
        error_log("[WARNING] " . $message);
    }

    /**
     * Log error message
     * @param string $message
     * @param Exception $exception
     */
    private static function logError($message, $exception = null)
    {
        $fullMessage = $message;
        if ($exception) {
            $fullMessage .= " - " . $exception->getMessage() . " in " . $exception->getFile() . ":" . $exception->getLine();
        }
        
        error_log("[ERROR] " . $fullMessage);
    }

    /**
     * =================================================================
     * ADDITIONAL METHODS FROM ORIGINAL TENANT.PHP
     * =================================================================
     */

    /**
     * Create school directories
     * @param int $schoolId
     * @return bool
     */
    public static function createSchoolDirectories($schoolId)
    {
        try {
            $basePath = realpath(__DIR__ . '/../../../') . '/assets/uploads/schools/';
            
            self::logInfo("Creating directories at: " . $basePath);
            
            // Create base uploads directory if it doesn't exist
            if (!file_exists($basePath)) {
                if (!mkdir($basePath, 0755, true)) {
                    self::logError("Failed to create base uploads directory");
                    return false;
                }
            }
            
            // Create school directory
            $schoolPath = $basePath . $schoolId . '/';
            if (!file_exists($schoolPath)) {
                if (!mkdir($schoolPath, 0755, true)) {
                    self::logError("Failed to create school directory: " . $schoolPath);
                    return false;
                }
            }
            
            // Create logo directory
            $logoDir = $schoolPath . 'logo/';
            if (!file_exists($logoDir)) {
                if (!mkdir($logoDir, 0755, true)) {
                    self::logError("Failed to create logo directory: " . $logoDir);
                    return false;
                }
            }
            
            // Create other directories
            $subDirs = ['students/photos', 'students/documents', 'teachers/photos', 'reports', 'temp'];
            foreach ($subDirs as $dir) {
                $fullPath = $schoolPath . $dir . '/';
                if (!file_exists($fullPath)) {
                    @mkdir($fullPath, 0755, true);
                }
            }
            
            return true;
            
        } catch (Exception $e) {
            self::logError("Directory creation error", $e);
            return false;
        }
    }

    /**
     * Split SQL into individual queries
     * @param string $sql
     * @return array
     */
    private static function splitSql($sql)
    {
        $queries = [];
        $currentQuery = '';
        $inString = false;
        $stringChar = '';
        $inComment = false;
        $commentType = '';

        $sql = trim($sql);
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $nextChar = $i < $length - 1 ? $sql[$i + 1] : '';

            // Handle comments
            if (!$inString) {
                // Single line comment
                if ($char == '#' || ($char == '-' && $nextChar == '-')) {
                    $inComment = true;
                    $commentType = 'single';
                    $i += ($char == '-' && $nextChar == '-') ? 1 : 0;
                    continue;
                }

                // Multi-line comment
                if ($char == '/' && $nextChar == '*') {
                    $inComment = true;
                    $commentType = 'multi';
                    $i++;
                    continue;
                }

                // End of multi-line comment
                if ($inComment && $commentType == 'multi' && $char == '*' && $nextChar == '/') {
                    $inComment = false;
                    $i++;
                    continue;
                }

                // End of single line comment
                if ($inComment && $commentType == 'single' && ($char == "\n" || $char == "\r")) {
                    $inComment = false;
                }

                // Skip comment characters
                if ($inComment) {
                    continue;
                }
            }

            // Handle string literals
            if (($char == "'" || $char == '"') && ($i == 0 || $sql[$i - 1] != '\\')) {
                if (!$inString) {
                    $inString = true;
                    $stringChar = $char;
                } elseif ($char == $stringChar) {
                    $inString = false;
                }
            }

            $currentQuery .= $char;

            // End of query (semicolon outside string and comments)
            if ($char == ';' && !$inString && !$inComment) {
                $queries[] = trim($currentQuery);
                $currentQuery = '';
            }
        }

        // Add any remaining query
        if (trim($currentQuery) !== '') {
            $queries[] = trim($currentQuery);
        }

        return array_filter($queries, function ($query) {
            return !empty(trim($query));
        });
    }

    /**
     * Get school upload path
     * @param int $schoolId
     * @param string $type
     * @return string
     */
    public static function getSchoolUploadPath($schoolId, $type = '')
    {
        $basePath = __DIR__ . '/../../assets/uploads/schools/' . $schoolId . '/';

        if (empty($type)) {
            return $basePath;
        }

        $typePaths = [
            'logo' => 'logo/',
            'student_photo' => 'students/photos/',
            'student_document' => 'students/documents/',
            'student_assignment' => 'students/assignments/',
            'teacher_photo' => 'teachers/photos/',
            'teacher_document' => 'teachers/documents/',
            'parent_document' => 'parents/documents/',
            'assignment' => 'assignments/',
            'report' => 'reports/',
            'timetable' => 'timetables/',
            'announcement' => 'announcements/',
            'library' => 'library/',
            'temp' => 'temp/'
        ];

        if (isset($typePaths[$type])) {
            return $basePath . $typePaths[$type];
        }

        return $basePath . $type . '/';
    }

    /**
     * Get school file URL for web access
     * @param int $schoolId
     * @param string $path
     * @return string
     */
    public static function getSchoolFileUrl($schoolId, $path)
    {
        return APP_URL . '/assets/uploads/schools/' . $schoolId . '/' . ltrim($path, '/');
    }

    /**
     * Get all schools
     * @param string $status
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public static function getAllSchools($status = null, $limit = 0, $offset = 0)
    {
        try {
            $db = Database::getPlatformConnection();

            $where = '';
            $params = [];

            if ($status) {
                $where = "WHERE status = ?";
                $params[] = $status;
            }

            $sql = "SELECT * FROM schools $where ORDER BY created_at DESC";

            if ($limit > 0) {
                $sql .= " LIMIT $limit";
                if ($offset > 0) {
                    $sql .= " OFFSET $offset";
                }
            }

            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            self::logError("Failed to get all schools", $e);
            return [];
        }
    }

    /**
     * Count schools by status
     * @param string $status
     * @return int
     */
    public static function countSchools($status = null)
    {
        try {
            $db = Database::getPlatformConnection();

            $where = '';
            $params = [];

            if ($status) {
                $where = "WHERE status = ?";
                $params[] = $status;
            }

            $sql = "SELECT COUNT(*) as count FROM schools $where";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $result = $stmt->fetch();

            return (int)$result['count'];
        } catch (Exception $e) {
            self::logError("Failed to count schools", $e);
            return 0;
        }
    }

    /**
     * Update school status
     * @param int $schoolId
     * @param string $status
     * @return bool
     */
    public static function updateSchoolStatus($schoolId, $status)
    {
        try {
            $db = Database::getPlatformConnection();
            $stmt = $db->prepare("UPDATE schools SET status = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$status, $schoolId]);
            return true;
        } catch (Exception $e) {
            self::logError("Failed to update school status", $e);
            return false;
        }
    }

    /**
     * Delete school (soft delete)
     * @param int $schoolId
     * @return bool
     */
    public static function deleteSchool($schoolId)
    {
        try {
            $db = Database::getPlatformConnection();
            $stmt = $db->prepare("UPDATE schools SET status = 'deleted', deleted_at = NOW() WHERE id = ?");
            $stmt->execute([$schoolId]);
            return true;
        } catch (Exception $e) {
            self::logError("Failed to delete school", $e);
            return false;
        }
    }

    /**
     * Backup school database
     * @param int $schoolId
     * @return string|false Backup file path
     */
    public static function backupSchoolDatabase($schoolId)
    {
        try {
            $school = self::getSchoolById($schoolId);
            if (!$school || empty($school['database_name'])) {
                return false;
            }

            $backupDir = __DIR__ . '/../../../backups/schools/' . $schoolId;
            if (!is_dir($backupDir)) {
                mkdir($backupDir, 0755, true);
            }

            $backupFile = $backupDir . '/' . $school['database_name'] . '_' . date('Y-m-d_H-i-s') . '.sql';

            return Database::backupDatabase($school['database_name'], $backupFile);
        } catch (Exception $e) {
            self::logError("Failed to backup school database", $e);
            return false;
        }
    }

    /**
     * Restore school database from backup
     * @param int $schoolId
     * @param string $backupFile
     * @return bool
     */
    public static function restoreSchoolDatabase($schoolId, $backupFile)
    {
        try {
            $school = self::getSchoolById($schoolId);
            if (!$school || empty($school['database_name'])) {
                return false;
            }

            if (!file_exists($backupFile)) {
                return false;
            }

            return Database::restoreDatabase($school['database_name'], $backupFile);
        } catch (Exception $e) {
            self::logError("Failed to restore school database", $e);
            return false;
        }
    }
}],SchoolSession.php [<?php
/**
 * School Session Management
 * Handles school-specific session data and validation
 */

class SchoolSession {
    
    /**
     * Initialize school session
     * @param array $school
     * @param array $user
     */
    public static function init($school, $user) {
        Session::start();
        
        // Set school context
        Session::set('school_id', $school['id']);
        Session::set('school_name', $school['name']);
        Session::set('school_slug', $school['slug']);
        Session::set('school_database', $school['database_name']);
        Session::set('school_logo', $school['logo_path']);
        Session::set('school_primary_color', $school['primary_color']);
        Session::set('school_secondary_color', $school['secondary_color']);
        
        // Set user context
        Session::set('user_id', $user['id']);
        Session::set('user_name', $user['name']);
        Session::set('user_email', $user['email']);
        Session::set('user_type', $user['user_type']);
        Session::set('user_permissions', $user['permissions'] ?? []);
        
        // Set timestamps
        Session::set('login_time', time());
        Session::set('last_activity', time());
        
        // Generate session token for API requests
        $sessionToken = bin2hex(random_bytes(32));
        Session::set('session_token', $sessionToken);
        
        // Store in database for validation
        self::storeSessionToken($school['id'], $user['id'], $sessionToken);
    }
    
    /**
     * Store session token in database
     * @param int $schoolId
     * @param int $userId
     * @param string $token
     */
    private static function storeSessionToken($schoolId, $userId, $token) {
        try {
            $db = Database::getSchoolConnection(DB_SCHOOL_PREFIX . $schoolId);
            
            // Clear old sessions for this user
            $stmt = $db->prepare("DELETE FROM user_sessions WHERE user_id = ? AND expires_at < NOW()");
            $stmt->execute([$userId]);
            
            // Insert new session
            $stmt = $db->prepare("
                INSERT INTO user_sessions 
                (user_id, session_token, ip_address, user_agent, expires_at, created_at) 
                VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND), NOW())
            ");
            
            $stmt->execute([
                $userId,
                $token,
                $_SERVER['REMOTE_ADDR'],
                $_SERVER['HTTP_USER_AGENT'] ?? '',
                SESSION_TIMEOUT
            ]);
            
        } catch (Exception $e) {
            error_log("Failed to store session token: " . $e->getMessage());
        }
    }
    
    /**
     * Validate current school session
     * @return bool
     */
    public static function validate() {
        Session::start();
        
        // Check if school session exists
        if (!Session::has('school_id') || !Session::has('user_id')) {
            return false;
        }
        
        // Check session timeout
        if (Session::isTimedOut()) {
            self::destroy();
            return false;
        }
        
        // Update last activity
        Session::updateLastActivity();
        
        // Validate session token (optional, for extra security)
        if (Session::has('session_token')) {
            return self::validateSessionToken(
                Session::get('school_id'),
                Session::get('user_id'),
                Session::get('session_token')
            );
        }
        
        return true;
    }
    
    /**
     * Validate session token against database
     * @param int $schoolId
     * @param int $userId
     * @param string $token
     * @return bool
     */
    private static function validateSessionToken($schoolId, $userId, $token) {
        try {
            $db = Database::getSchoolConnection(DB_SCHOOL_PREFIX . $schoolId);
            
            $stmt = $db->prepare("
                SELECT COUNT(*) as count 
                FROM user_sessions 
                WHERE user_id = ? AND session_token = ? AND expires_at > NOW()
            ");
            
            $stmt->execute([$userId, $token]);
            $result = $stmt->fetch();
            
            return $result['count'] > 0;
            
        } catch (Exception $e) {
            error_log("Failed to validate session token: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get current school ID from session
     * @return int|null
     */
    public static function getSchoolId() {
        return Session::get('school_id');
    }
    
    /**
     * Get current user ID from session
     * @return int|null
     */
    public static function getUserId() {
        return Session::get('user_id');
    }
    
    /**
     * Get current user type from session
     * @return string|null
     */
    public static function getUserType() {
        return Session::get('user_type');
    }
    
    /**
     * Get current user permissions from session
     * @return array
     */
    public static function getUserPermissions() {
        return Session::get('user_permissions', []);
    }
    
    /**
     * Check if user has permission
     * @param string $permission
     * @return bool
     */
    public static function hasPermission($permission) {
        $permissions = self::getUserPermissions();
        
        if (empty($permissions)) {
            return false;
        }
        
        // Check for wildcard permission
        if (in_array('*', $permissions)) {
            return true;
        }
        
        // Check for specific permission
        if (in_array($permission, $permissions)) {
            return true;
        }
        
        // Check for pattern permission (e.g., 'student.*')
        foreach ($permissions as $userPerm) {
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
     * Require specific permission
     * @param string $permission
     */
    public static function requirePermission($permission) {
        if (!self::hasPermission($permission)) {
            http_response_code(403);
            die("Access denied. You don't have permission to access this resource.");
        }
    }
    
    /**
     * Get school database connection
     * @return PDO|null
     */
    public static function getSchoolDb() {
        $schoolId = self::getSchoolId();
        if (!$schoolId) {
            return null;
        }
        
        return Database::getSchoolConnection(DB_SCHOOL_PREFIX . $schoolId);
    }
    
    /**
     * Get school information from session
     * @return array|null
     */
    public static function getSchoolInfo() {
        if (!self::validate()) {
            return null;
        }
        
        return [
            'id' => Session::get('school_id'),
            'name' => Session::get('school_name'),
            'slug' => Session::get('school_slug'),
            'database' => Session::get('school_database'),
            'logo' => Session::get('school_logo'),
            'primary_color' => Session::get('school_primary_color'),
            'secondary_color' => Session::get('school_secondary_color')
        ];
    }
    
    /**
     * Get user information from session
     * @return array|null
     */
    public static function getUserInfo() {
        if (!self::validate()) {
            return null;
        }
        
        return [
            'id' => Session::get('user_id'),
            'name' => Session::get('user_name'),
            'email' => Session::get('user_email'),
            'type' => Session::get('user_type'),
            'permissions' => Session::get('user_permissions')
        ];
    }
    
    /**
     * Update user session data
     * @param array $userData
     */
    public static function updateUserData($userData) {
        if (isset($userData['name'])) {
            Session::set('user_name', $userData['name']);
        }
        
        if (isset($userData['email'])) {
            Session::set('user_email', $userData['email']);
        }
        
        if (isset($userData['permissions'])) {
            Session::set('user_permissions', $userData['permissions']);
        }
    }
    
    /**
     * Update school session data
     * @param array $schoolData
     */
    public static function updateSchoolData($schoolData) {
        if (isset($schoolData['name'])) {
            Session::set('school_name', $schoolData['name']);
        }
        
        if (isset($schoolData['logo_path'])) {
            Session::set('school_logo', $schoolData['logo_path']);
        }
        
        if (isset($schoolData['primary_color'])) {
            Session::set('school_primary_color', $schoolData['primary_color']);
        }
        
        if (isset($schoolData['secondary_color'])) {
            Session::set('school_secondary_color', $schoolData['secondary_color']);
        }
    }
    
    /**
     * Destroy school session
     */
    public static function destroy() {
        // Remove session token from database
        $schoolId = Session::get('school_id');
        $userId = Session::get('user_id');
        $token = Session::get('session_token');
        
        if ($schoolId && $userId && $token) {
            self::removeSessionToken($schoolId, $userId, $token);
        }
        
        // Clear session data
        Session::remove('school_id');
        Session::remove('school_name');
        Session::remove('school_slug');
        Session::remove('school_database');
        Session::remove('school_logo');
        Session::remove('school_primary_color');
        Session::remove('school_secondary_color');
        
        Session::remove('user_id');
        Session::remove('user_name');
        Session::remove('user_email');
        Session::remove('user_type');
        Session::remove('user_permissions');
        
        Session::remove('login_time');
        Session::remove('last_activity');
        Session::remove('session_token');
    }
    
    /**
     * Remove session token from database
     * @param int $schoolId
     * @param int $userId
     * @param string $token
     */
    private static function removeSessionToken($schoolId, $userId, $token) {
        try {
            $db = Database::getSchoolConnection(DB_SCHOOL_PREFIX . $schoolId);
            $stmt = $db->prepare("DELETE FROM user_sessions WHERE user_id = ? AND session_token = ?");
            $stmt->execute([$userId, $token]);
        } catch (Exception $e) {
            error_log("Failed to remove session token: " . $e->getMessage());
        }
    }
    
    /**
     * Regenerate session ID
     */
    public static function regenerateId() {
        Session::regenerateId(true);
    }
    
    /**
     * Check if user is school admin
     * @return bool
     */
    public static function isSchoolAdmin() {
        return self::getUserType() === 'admin';
    }
    
    /**
     * Check if user is teacher
     * @return bool
     */
    public static function isTeacher() {
        return self::getUserType() === 'teacher';
    }
    
    /**
     * Check if user is student
     * @return bool
     */
    public static function isStudent() {
        return self::getUserType() === 'student';
    }
    
    /**
     * Check if user is parent
     * @return bool
     */
    public static function isParent() {
        return self::getUserType() === 'parent';
    }
    
    /**
     * Get user dashboard URL based on user type
     * @return string
     */
    public static function getDashboardUrl() {
        $schoolSlug = Session::get('school_slug');
        $userType = self::getUserType();
        
        switch ($userType) {
            case 'admin':
                return "/tenant/$schoolSlug/admin/dashboard.php";
            case 'teacher':
                return "/tenant/$schoolSlug/teacher/dashboard.php";
            case 'student':
                return "/tenant/$schoolSlug/student/dashboard.php";
            case 'parent':
                return "/tenant/$schoolSlug/parent/dashboard.php";
            case 'accountant':
                return "/tenant/$schoolSlug/accountant/dashboard.php";
            case 'librarian':
                return "/tenant/$schoolSlug/librarian/dashboard.php";
            default:
                return "/tenant/$schoolSlug/dashboard.php";
        }
    }
    
    /**
     * Redirect to dashboard if already logged in
     */
    public static function redirectIfLoggedIn() {
        if (self::validate()) {
            header('Location: ' . self::getDashboardUrl());
            exit;
        }
    }
    
    /**
     * Require login
     */
    public static function requireLogin() {
        if (!self::validate()) {
            $schoolSlug = $_GET['school'] ?? '';
            header("Location: /login.php?school=" . urlencode($schoolSlug));
            exit;
        }
    }
    
    /**
     * Require specific user type
     * @param string|array $userTypes
     */
    public static function requireUserType($userTypes) {
        self::requireLogin();
        
        if (!is_array($userTypes)) {
            $userTypes = [$userTypes];
        }
        
        $currentType = self::getUserType();
        
        if (!in_array($currentType, $userTypes)) {
            http_response_code(403);
            die("Access denied. This page is only accessible to: " . implode(', ', $userTypes));
        }
    }
}
?>],Auth.php[<?php
/**
 * Authentication and Authorization System
 * Handles user login, session management, and permissions
 */

class Auth {
    private $db;
    private $loginAttempts = [];
    
    public function __construct() {
        $this->db = Database::getPlatformConnection();
        $this->startSession();
    }
    
    /**
     * Start or resume session
     */
    private function startSession() {
        if (session_status() === PHP_SESSION_NONE) {
            session_name('AcademixSuite_session');
            session_set_cookie_params([
                'lifetime' => SESSION_TIMEOUT,
                'path' => '/',
                'domain' => '',
                'secure' => !IS_LOCAL,
                'httponly' => true,
                'samesite' => 'Strict'
            ]);
            session_start();
            
            // Regenerate session ID periodically for security
            if (!isset($_SESSION['created'])) {
                $_SESSION['created'] = time();
            } elseif (time() - $_SESSION['created'] > 1800) { // 30 minutes
                session_regenerate_id(true);
                $_SESSION['created'] = time();
            }
        }
    }
    
    /**
     * Login super admin
     * @param string $email
     * @param string $password
     * @return array [success, message, data]
     */
    public function loginSuperAdmin($email, $password) {
        // Check login attempts
        if ($this->isLoginLocked($email, 'super_admin')) {
            return [
                'success' => false,
                'message' => 'Too many login attempts. Please try again in ' . 
                           round((LOGIN_LOCKOUT_TIME - (time() - $this->loginAttempts[$email]['last_attempt'])) / 60) . ' minutes.'
            ];
        }
        
        try {
            $stmt = $this->db->prepare("
                SELECT * FROM platform_users 
                WHERE email = ? AND role = 'super_admin' AND is_active = 1
            ");
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            
            if (!$user) {
                $this->recordFailedAttempt($email, 'super_admin');
                return ['success' => false, 'message' => 'Invalid credentials'];
            }
            
            if (!password_verify($password, $user['password'])) {
                $this->recordFailedAttempt($email, 'super_admin');
                return ['success' => false, 'message' => 'Invalid credentials'];
            }
            
            // Check if password needs rehash
            if (password_needs_rehash($user['password'], PASSWORD_BCRYPT)) {
                $newHash = password_hash($password, PASSWORD_BCRYPT);
                $updateStmt = $this->db->prepare("UPDATE platform_users SET password = ? WHERE id = ?");
                $updateStmt->execute([$newHash, $user['id']]);
            }
            
            // Set session data
            $_SESSION['super_admin'] = [
                'id' => $user['id'],
                'name' => $user['name'],
                'email' => $user['email'],
                'role' => $user['role'],
                'login_time' => time(),
                'last_activity' => time()
            ];
            
            // Clear failed attempts
            $this->clearFailedAttempts($email);
            
            // Update last login
            $this->updateLastLogin($user['id'], 'platform_users');
            
            // Log the login
            $this->logActivity($user['id'], 'super_admin', 'login', 'Super admin logged in');
            
            return [
                'success' => true,
                'message' => 'Login successful',
                'redirect' => '../platform/admin/dashboard.php'
            ];
            
        } catch (Exception $e) {
            error_log("Super admin login error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Login failed. Please try again.'];
        }
    }
    
    /**
     * Login school user (admin, teacher, student, parent)
     * @param string $email
     * @param string $password
     * @param string $schoolSlug
     * @return array [success, message, data]
     */
    public function loginSchoolUser($email, $password, $schoolSlug) {
        // Get school info
        $school = $this->getSchoolBySlug($schoolSlug);
        if (!$school) {
            return ['success' => false, 'message' => 'School not found'];
        }
        
        // Check if school is active
        if ($school['status'] !== 'active' && $school['status'] !== 'trial') {
            return ['success' => false, 'message' => 'School account is ' . $school['status']];
        }
        
        // Check login attempts for this school user
        $attemptKey = $school['id'] . '_' . $email;
        if ($this->isLoginLocked($attemptKey, 'school_user')) {
            return [
                'success' => false,
                'message' => 'Too many login attempts. Please try again later.'
            ];
        }
        
        try {
            // Connect to school's database
            $schoolDb = Database::getSchoolConnection($school['database_name']);
            
            // Find user
            $stmt = $schoolDb->prepare("
                SELECT u.*, GROUP_CONCAT(r.permissions) as role_permissions
                FROM users u 
                LEFT JOIN user_roles ur ON u.id = ur.user_id 
                LEFT JOIN roles r ON ur.role_id = r.id 
                WHERE u.email = ? AND u.is_active = 1
                GROUP BY u.id
            ");
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            
            if (!$user) {
                $this->recordFailedAttempt($attemptKey, 'school_user');
                return ['success' => false, 'message' => 'Invalid credentials'];
            }
            
            if (!password_verify($password, $user['password'])) {
                $this->recordFailedAttempt($attemptKey, 'school_user');
                return ['success' => false, 'message' => 'Invalid credentials'];
            }
            
            // Check if password needs rehash
            if (password_needs_rehash($user['password'], PASSWORD_BCRYPT)) {
                $newHash = password_hash($password, PASSWORD_BCRYPT);
                $updateStmt = $schoolDb->prepare("UPDATE users SET password = ? WHERE id = ?");
                $updateStmt->execute([$newHash, $user['id']]);
            }
            
            // Parse permissions
            $permissions = [];
            if (!empty($user['role_permissions'])) {
                $rolePermissions = explode(',', $user['role_permissions']);
                foreach ($rolePermissions as $rolePerm) {
                    $decoded = json_decode($rolePerm, true);
                    if (is_array($decoded)) {
                        $permissions = array_merge($permissions, $decoded);
                    }
                }
            }
            
            // Set session data
            $_SESSION['school_user'] = [
                'id' => $user['id'],
                'school_id' => $school['id'],
                'school_slug' => $school['slug'],
                'school_name' => $school['name'],
                'school_db' => $school['database_name'],
                'name' => $user['name'],
                'email' => $user['email'],
                'user_type' => $user['user_type'],
                'permissions' => array_unique($permissions),
                'login_time' => time(),
                'last_activity' => time()
            ];
            
            // Clear failed attempts
            $this->clearFailedAttempts($attemptKey);
            
            // Update last login
            $this->updateLastLogin($user['id'], 'users', $schoolDb);
            
            // Log the login
            $this->logActivity($user['id'], $user['user_type'], 'login', 
                             'User logged in', $school['id'], $schoolDb);
            
            // Determine redirect URL based on user type
            $redirect = $this->getSchoolUserRedirect($user['user_type'], $school['slug']);
            
            return [
                'success' => true,
                'message' => 'Login successful',
                'user_type' => $user['user_type'],
                'redirect' => $redirect
            ];
            
        } catch (Exception $e) {
            error_log("School user login error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Login failed. Please try again.'];
        }
    }
    
    /**
     * Get redirect URL for school user
     * @param string $userType
     * @param string $schoolSlug
     * @return string
     */
    private function getSchoolUserRedirect($userType, $schoolSlug) {
        switch ($userType) {
            case ROLE_SCHOOL_ADMIN:
                return "/tenant/$schoolSlug/admin/dashboard.php";
            case ROLE_TEACHER:
                return "/tenant/$schoolSlug/teacher/dashboard.php";
            case ROLE_STUDENT:
                return "/tenant/$schoolSlug/student/dashboard.php";
            case ROLE_PARENT:
                return "/tenant/$schoolSlug/parent/dashboard.php";
            case ROLE_ACCOUNTANT:
                return "/tenant/$schoolSlug/accountant/dashboard.php";
            case ROLE_LIBRARIAN:
                return "/tenant/$schoolSlug/librarian/dashboard.php";
            default:
                return "/tenant/$schoolSlug/dashboard.php";
        }
    }
    
    /**
     * Check if user is logged in
     * @param string $type super_admin|school_user
     * @param string $userType Specific user type (admin, teacher, etc.)
     * @return bool
     */
    public function isLoggedIn($type = null, $userType = null) {
        if ($type === 'super_admin') {
            if (!isset($_SESSION['super_admin'])) {
                return false;
            }
            
            // Check session timeout
            if (time() - $_SESSION['super_admin']['last_activity'] > SESSION_TIMEOUT) {
                $this->logout();
                return false;
            }
            
            // Update last activity
            $_SESSION['super_admin']['last_activity'] = time();
            return true;
        }
        
        if ($type === 'school_user' || $type === null) {
            if (!isset($_SESSION['school_user'])) {
                return false;
            }
            
            // Check session timeout
            if (time() - $_SESSION['school_user']['last_activity'] > SESSION_TIMEOUT) {
                $this->logout();
                return false;
            }
            
            // Check specific user type if requested
            if ($userType && $_SESSION['school_user']['user_type'] !== $userType) {
                return false;
            }
            
            // Update last activity
            $_SESSION['school_user']['last_activity'] = time();
            return true;
        }
        
        return false;
    }
    
    /**
     * Require login for specific user type
     * @param string $type
     * @param string $userType
     */
    public function requireLogin($type = null, $userType = null) {
        if (!$this->isLoggedIn($type, $userType)) {
            if ($type === 'super_admin') {
                header("Location: /platform/login.php");
            } else {
                $schoolSlug = $_SESSION['school_user']['school_slug'] ?? 
                             $_GET['school'] ?? '';
                header("Location: /login.php?school=" . urlencode($schoolSlug));
            }
            exit;
        }
    }
    
    /**
     * Check if user has permission
     * @param string $permission
     * @return bool
     */
    public function hasPermission($permission) {
        if (!isset($_SESSION['school_user']['permissions'])) {
            return false;
        }
        
        $userPermissions = $_SESSION['school_user']['permissions'];
        
        // Check for wildcard or specific permission
        foreach ($userPermissions as $userPerm) {
            if ($userPerm === '*' || $userPerm === $permission) {
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
     * Require specific permission
     * @param string $permission
     */
    public function requirePermission($permission) {
        if (!$this->hasPermission($permission)) {
            http_response_code(403);
            die("Access denied. You don't have permission to access this resource.");
        }
    }
    
    /**
     * Logout user
     * @param bool $redirect
     */
    public function logout($redirect = true) {
        // Log logout activity
        if (isset($_SESSION['super_admin'])) {
            $this->logActivity($_SESSION['super_admin']['id'], 'super_admin', 'logout', 'Super admin logged out');
        } elseif (isset($_SESSION['school_user'])) {
            $schoolDb = Database::getSchoolConnection($_SESSION['school_user']['school_db']);
            $this->logActivity($_SESSION['school_user']['id'], $_SESSION['school_user']['user_type'], 
                             'logout', 'User logged out', $_SESSION['school_user']['school_id'], $schoolDb);
        }
        
        // Destroy session
        $_SESSION = [];
        
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        
        session_destroy();
        
        if ($redirect) {
            header("Location: /platform/login.php");
            exit;
        }
    }
    
    /**
     * Change user password
     * @param int $userId
     * @param string $currentPassword
     * @param string $newPassword
     * @param string $userType super_admin|school_user
     * @param PDO $db (for school users)
     * @return array [success, message]
     */
    public function changePassword($userId, $currentPassword, $newPassword, 
                                  $userType = 'school_user', $db = null) {
        if (strlen($newPassword) < PASSWORD_MIN_LENGTH) {
            return ['success' => false, 'message' => 'Password must be at least ' . PASSWORD_MIN_LENGTH . ' characters'];
        }
        
        try {
            if ($userType === 'super_admin') {
                $table = 'platform_users';
                $db = $this->db;
            } else {
                $table = 'users';
                if (!$db) {
                    throw new Exception('Database connection required for school users');
                }
            }
            
            // Get current password hash
            $stmt = $db->prepare("SELECT password FROM $table WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();
            
            if (!$user) {
                return ['success' => false, 'message' => 'User not found'];
            }
            
            // Verify current password
            if (!password_verify($currentPassword, $user['password'])) {
                return ['success' => false, 'message' => 'Current password is incorrect'];
            }
            
            // Hash new password
            $newHash = password_hash($newPassword, PASSWORD_BCRYPT);
            
            // Update password
            $updateStmt = $db->prepare("UPDATE $table SET password = ?, reset_token = NULL WHERE id = ?");
            $updateStmt->execute([$newHash, $userId]);
            
            // Log password change
            $this->logActivity($userId, $userType, 'password_change', 'Password changed', 
                             isset($_SESSION['school_user']) ? $_SESSION['school_user']['school_id'] : null, $db);
            
            return ['success' => true, 'message' => 'Password changed successfully'];
            
        } catch (Exception $e) {
            error_log("Password change error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to change password'];
        }
    }
    
    /**
     * Reset password (forgot password)
     * @param string $email
     * @param string $userType super_admin|school_user
     * @param string $schoolSlug (for school users)
     * @return array [success, message, token]
     */
    public function resetPassword($email, $userType = 'school_user', $schoolSlug = null) {
        try {
            if ($userType === 'super_admin') {
                $table = 'platform_users';
                $db = $this->db;
                $where = "email = ? AND role = 'super_admin'";
            } else {
                if (!$schoolSlug) {
                    return ['success' => false, 'message' => 'School slug required'];
                }
                
                $school = $this->getSchoolBySlug($schoolSlug);
                if (!$school) {
                    return ['success' => false, 'message' => 'School not found'];
                }
                
                $table = 'users';
                $db = Database::getSchoolConnection($school['database_name']);
                $where = "email = ? AND is_active = 1";
            }
            
            // Check if user exists
            $stmt = $db->prepare("SELECT id, name FROM $table WHERE $where");
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            
            if (!$user) {
                // Don't reveal that user doesn't exist (security)
                return ['success' => true, 'message' => 'If the email exists, reset instructions will be sent'];
            }
            
            // Generate reset token
            $token = bin2hex(random_bytes(32));
            $expiry = date('Y-m-d H:i:s', time() + 3600); // 1 hour
            
            // Store token
            $updateStmt = $db->prepare("UPDATE $table SET reset_token = ?, reset_token_expires = ? WHERE id = ?");
            $updateStmt->execute([$token, $expiry, $user['id']]);
            
            // Send reset email
            $resetUrl = APP_URL . "/reset-password.php?token=$token&type=$userType" . 
                       ($schoolSlug ? "&school=$schoolSlug" : "");
            
            $subject = "Password Reset Request - " . APP_NAME;
            $body = "
                <h2>Password Reset Request</h2>
                <p>Hello {$user['name']},</p>
                <p>You have requested to reset your password. Click the link below to proceed:</p>
                <p><a href='$resetUrl'>Reset Password</a></p>
                <p>This link will expire in 1 hour.</p>
                <p>If you didn't request this, please ignore this email.</p>
            ";
            
            // In production, use proper email service
            if (!IS_LOCAL) {
                // sendEmail($email, $subject, $body);
            }
            
            // Log password reset request
            $this->logActivity($user['id'], $userType, 'password_reset_request', 
                             'Password reset requested', 
                             $userType === 'school_user' ? $school['id'] : null, $db);
            
            return [
                'success' => true,
                'message' => 'Password reset instructions sent to your email',
                'token' => $token // For testing only
            ];
            
        } catch (Exception $e) {
            error_log("Password reset error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to process reset request'];
        }
    }
    
    /**
     * Validate reset token
     * @param string $token
     * @param string $userType
     * @param string $schoolSlug
     * @return array [success, message, userId]
     */
    public function validateResetToken($token, $userType = 'school_user', $schoolSlug = null) {
        try {
            if ($userType === 'super_admin') {
                $table = 'platform_users';
                $db = $this->db;
            } else {
                if (!$schoolSlug) {
                    return ['success' => false, 'message' => 'School slug required'];
                }
                
                $school = $this->getSchoolBySlug($schoolSlug);
                if (!$school) {
                    return ['success' => false, 'message' => 'School not found'];
                }
                
                $table = 'users';
                $db = Database::getSchoolConnection($school['database_name']);
            }
            
            $stmt = $db->prepare("
                SELECT id FROM $table 
                WHERE reset_token = ? AND reset_token_expires > NOW() AND is_active = 1
            ");
            $stmt->execute([$token]);
            $user = $stmt->fetch();
            
            if (!$user) {
                return ['success' => false, 'message' => 'Invalid or expired reset token'];
            }
            
            return [
                'success' => true,
                'message' => 'Token is valid',
                'userId' => $user['id']
            ];
            
        } catch (Exception $e) {
            error_log("Token validation error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to validate token'];
        }
    }
    
    /**
     * Complete password reset
     * @param string $token
     * @param string $newPassword
     * @param string $userType
     * @param string $schoolSlug
     * @return array [success, message]
     */
    public function completePasswordReset($token, $newPassword, $userType = 'school_user', $schoolSlug = null) {
        if (strlen($newPassword) < PASSWORD_MIN_LENGTH) {
            return ['success' => false, 'message' => 'Password must be at least ' . PASSWORD_MIN_LENGTH . ' characters'];
        }
        
        try {
            // Validate token first
            $validation = $this->validateResetToken($token, $userType, $schoolSlug);
            if (!$validation['success']) {
                return $validation;
            }
            
            $userId = $validation['userId'];
            
            // Get database connection
            if ($userType === 'super_admin') {
                $table = 'platform_users';
                $db = $this->db;
            } else {
                $school = $this->getSchoolBySlug($schoolSlug);
                $table = 'users';
                $db = Database::getSchoolConnection($school['database_name']);
            }
            
            // Hash new password
            $newHash = password_hash($newPassword, PASSWORD_BCRYPT);
            
            // Update password and clear token
            $stmt = $db->prepare("
                UPDATE $table 
                SET password = ?, reset_token = NULL, reset_token_expires = NULL 
                WHERE id = ?
            ");
            $stmt->execute([$newHash, $userId]);
            
            // Log password reset completion
            $this->logActivity($userId, $userType, 'password_reset_complete', 
                             'Password reset completed', 
                             $userType === 'school_user' ? $school['id'] : null, $db);
            
            return ['success' => true, 'message' => 'Password reset successfully'];
            
        } catch (Exception $e) {
            error_log("Password reset completion error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to reset password'];
        }
    }
    
    /**
     * Get school by slug
     * @param string $slug
     * @return array|null
     */
    private function getSchoolBySlug($slug) {
        $stmt = $this->db->prepare("SELECT * FROM schools WHERE slug = ?");
        $stmt->execute([$slug]);
        return $stmt->fetch();
    }
    
    /**
     * Check if login is locked due to too many attempts
     * @param string $key
     * @param string $type
     * @return bool
     */
    private function isLoginLocked($key, $type) {
        if (!isset($this->loginAttempts[$key])) {
            return false;
        }
        
        $attempts = $this->loginAttempts[$key];
        
        // Check if locked out
        if ($attempts['count'] >= MAX_LOGIN_ATTEMPTS && 
            (time() - $attempts['last_attempt']) < LOGIN_LOCKOUT_TIME) {
            return true;
        }
        
        // Reset count if lockout time has passed
        if ((time() - $attempts['last_attempt']) >= LOGIN_LOCKOUT_TIME) {
            unset($this->loginAttempts[$key]);
            return false;
        }
        
        return false;
    }
    
    /**
     * Record failed login attempt
     * @param string $key
     * @param string $type
     */
    private function recordFailedAttempt($key, $type) {
        if (!isset($this->loginAttempts[$key])) {
            $this->loginAttempts[$key] = [
                'count' => 0,
                'last_attempt' => time(),
                'type' => $type
            ];
        }
        
        $this->loginAttempts[$key]['count']++;
        $this->loginAttempts[$key]['last_attempt'] = time();
        
        // Log failed attempt
        error_log("Failed login attempt for $key ($type). Attempt: " . 
                 $this->loginAttempts[$key]['count']);
    }
    
    /**
     * Clear failed attempts for a key
     * @param string $key
     */
    private function clearFailedAttempts($key) {
        if (isset($this->loginAttempts[$key])) {
            unset($this->loginAttempts[$key]);
        }
    }
    
    /**
     * Update last login time
     * @param int $userId
     * @param string $table
     * @param PDO $db
     */
    private function updateLastLogin($userId, $table, $db = null) {
        if ($db === null) {
            $db = $this->db;
        }
        
        $stmt = $db->prepare("
            UPDATE $table 
            SET last_login_at = NOW(), last_login_ip = ? 
            WHERE id = ?
        ");
        $stmt->execute([$_SERVER['REMOTE_ADDR'], $userId]);
    }
    
    /**
     * Log user activity
     * @param int $userId
     * @param string $userType
     * @param string $event
     * @param string $description
     * @param int $schoolId
     * @param PDO $db
     */
    private function logActivity($userId, $userType, $event, $description, 
                               $schoolId = null, $db = null) {
        try {
            $logData = [
                'school_id' => $schoolId,
                'user_id' => $userId,
                'user_type' => $userType,
                'event' => $event,
                'description' => $description,
                'ip_address' => $_SERVER['REMOTE_ADDR'],
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                'url' => $_SERVER['REQUEST_URI'] ?? ''
            ];
            
            if ($userType === 'super_admin') {
                // Log to platform audit_logs
                $stmt = $this->db->prepare("
                    INSERT INTO audit_logs 
                    (user_id, user_type, event, description, ip_address, user_agent, url, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([
                    $logData['user_id'],
                    $logData['user_type'],
                    $logData['event'],
                    $logData['description'],
                    $logData['ip_address'],
                    $logData['user_agent'],
                    $logData['url']
                ]);
            } elseif ($db) {
                // Log to school's audit_logs table
                $stmt = $db->prepare("
                    INSERT INTO audit_logs 
                    (school_id, user_id, user_type, event, description, ip_address, user_agent, url, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([
                    $logData['school_id'],
                    $logData['user_id'],
                    $logData['user_type'],
                    $logData['event'],
                    $logData['description'],
                    $logData['ip_address'],
                    $logData['user_agent'],
                    $logData['url']
                ]);
            }
            
        } catch (Exception $e) {
            error_log("Failed to log activity: " . $e->getMessage());
        }
    }
    
    /**
     * Get current user data
     * @return array|null
     */
    public function getCurrentUser() {
        if (isset($_SESSION['super_admin'])) {
            return $_SESSION['super_admin'];
        } elseif (isset($_SESSION['school_user'])) {
            return $_SESSION['school_user'];
        }
        return null;
    }
    
    /**
     * Get current user ID
     * @return int|null
     */
    public function getCurrentUserId() {
        $user = $this->getCurrentUser();
        return $user ? $user['id'] : null;
    }
    
    /**
     * Get current school ID (for school users)
     * @return int|null
     */
    public function getCurrentSchoolId() {
        return $_SESSION['school_user']['school_id'] ?? null;
    }
    
    /**
     * Get current user type
     * @return string|null
     */
    public function getCurrentUserType() {
        $user = $this->getCurrentUser();
        return $user ? ($user['role'] ?? $user['user_type']) : null;
    }
}
?>],ErrorHandlig.php [<?php
/**
 * Custom Error Handler
 */

class ErrorHandler {
    public static function register() {
        set_error_handler([self::class, 'handleError']);
        set_exception_handler([self::class, 'handleException']);
        register_shutdown_function([self::class, 'handleShutdown']);
    }
    
    public static function handleError($errno, $errstr, $errfile, $errline) {
        if (!(error_reporting() & $errno)) {
            return false;
        }
        
        $errorType = [
            E_ERROR => 'ERROR',
            E_WARNING => 'WARNING',
            E_PARSE => 'PARSE',
            E_NOTICE => 'NOTICE',
            E_CORE_ERROR => 'CORE_ERROR',
            E_CORE_WARNING => 'CORE_WARNING',
            E_COMPILE_ERROR => 'COMPILE_ERROR',
            E_COMPILE_WARNING => 'COMPILE_WARNING',
            E_USER_ERROR => 'USER_ERROR',
            E_USER_WARNING => 'USER_WARNING',
            E_USER_NOTICE => 'USER_NOTICE',
            E_STRICT => 'STRICT',
            E_RECOVERABLE_ERROR => 'RECOVERABLE_ERROR',
            E_DEPRECATED => 'DEPRECATED',
            E_USER_DEPRECATED => 'USER_DEPRECATED'
        ];
        
        $type = $errorType[$errno] ?? 'UNKNOWN';
        
        $message = sprintf(
            '[%s] %s in %s on line %d',
            $type,
            $errstr,
            $errfile,
            $errline
        );
        
        error_log($message);
        
        if (APP_DEBUG) {
            echo '<pre>' . $message . '</pre>';
        }
        
        return true;
    }
    
    public static function handleException($exception) {
        $message = sprintf(
            'Uncaught Exception: %s in %s on line %d',
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine()
        );
        
        error_log($message);
        
        if (APP_DEBUG) {
            echo '<pre>' . $message . '</pre>';
            echo '<pre>' . $exception->getTraceAsString() . '</pre>';
        } else {
            header('HTTP/1.1 500 Internal Server Error');
            echo 'An unexpected error occurred. Please try again later.';
        }
        
        exit(1);
    }
    
    public static function handleShutdown() {
        $error = error_get_last();
        
        if ($error && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE])) {
            self::handleError(
                $error['type'],
                $error['message'],
                $error['file'],
                $error['line']
            );
        }
    }
}
?>] and AppRouter.php [<?php
/**
 * Application Router
 */

class AppRouter {
    
    private $requestUri;
    private $requestMethod;
    private $queryParams;
    private $routeParams;
    private $schoolSlug;
    private $userType;
    
    public function __construct() {
        $this->requestUri = $_SERVER['REQUEST_URI'];
        $this->requestMethod = $_SERVER['REQUEST_METHOD'];
        $this->queryParams = $_GET;
        $this->parseRequest();
    }
    
    private function parseRequest() {
        // Parse URL
        $path = parse_url($this->requestUri, PHP_URL_PATH);
        $path = trim($path, '/');
        
        // Extract school slug if present
        if (preg_match('#^school/([a-z0-9-]+)#', $path, $matches)) {
            $this->schoolSlug = $matches[1];
            
            // Extract user type
            if (preg_match('#^school/[a-z0-9-]+/(admin|teacher|student|parent)#', $path, $typeMatches)) {
                $this->userType = $typeMatches[1];
            }
        }
    }
    
    public function dispatch() {
        try {
            // Clean any previous output
            if (ob_get_length()) ob_clean();
            
            // Route the request
            $this->routeRequest();
            
        } catch (Exception $e) {
            $this->handleError($e);
        }
    }
    
    private function routeRequest() {
        $path = parse_url($this->requestUri, PHP_URL_PATH);
        $path = trim($path, '/');
        
        // Handle preflight requests
        if ($this->requestMethod === 'OPTIONS') {
            header("HTTP/1.1 200 OK");
            exit;
        }
        
        // --------------------------------------------------------------------
        // 1. Platform Admin Routes
        // --------------------------------------------------------------------
        if (strpos($path, 'platform/admin') === 0) {
            $this->routePlatformAdmin($path);
            return;
        }
        
        // --------------------------------------------------------------------
        // 2. School (Tenant) Routes
        // --------------------------------------------------------------------
        if (strpos($path, 'school/') === 0) {
            $this->routeTenant($path);
            return;
        }
        
        // --------------------------------------------------------------------
        // 3. Public Routes
        // --------------------------------------------------------------------
        if ($path === 'register') {
            require_once ROOT_PATH . '/public/register.php';
            return;
        }
        
        if ($path === 'pricing') {
            require_once ROOT_PATH . '/public/pricing.php';
            return;
        }
        
        if ($path === 'contact') {
            require_once ROOT_PATH . '/public/contact.php';
            return;
        }
        
        if (empty($path) || $path === 'index.php') {
            require_once ROOT_PATH . '/public/index.php';
            return;
        }
        
        // --------------------------------------------------------------------
        // 4. API Routes (if you add API later)
        // --------------------------------------------------------------------
        if (strpos($path, 'api/') === 0) {
            $this->routeApi($path);
            return;
        }
        
        // --------------------------------------------------------------------
        // 5. Default - Public Homepage
        // --------------------------------------------------------------------
        if (file_exists(ROOT_PATH . '/public/' . $path . '.php')) {
            require_once ROOT_PATH . '/public/' . $path . '.php';
        } else {
            $this->show404();
        }
    }
    
    private function routePlatformAdmin($path) {
        // Remove 'platform/admin/' from path
        $relativePath = substr($path, strlen('platform/admin/'));
        
        // Split into segments
        $segments = explode('/', $relativePath);
        
        // Base admin directory
        $adminDir = ROOT_PATH . '/platform/admin/';
        
        // Handle different URL patterns
        if (count($segments) === 1 && $segments[0] === '') {
            // platform/admin/ -> dashboard
            $file = $adminDir . 'dashboard.php';
        } elseif (count($segments) === 1) {
            // platform/admin/schools -> schools/index.php
            $file = $adminDir . $segments[0] . '/index.php';
        } elseif (count($segments) === 2) {
            // platform/admin/schools/view -> schools/view.php
            $file = $adminDir . $segments[0] . '/' . $segments[1] . '.php';
        } elseif (count($segments) === 3) {
            // platform/admin/schools/view/123 -> schools/view.php?id=123
            $file = $adminDir . $segments[0] . '/' . $segments[1] . '.php';
            $_GET['id'] = $segments[2];
        } else {
            $this->show404();
            return;
        }
        
        // Check if file exists
        if (file_exists($file)) {
            // Verify super admin access
            $this->verifySuperAdmin();
            require_once $file;
        } else {
            $this->show404();
        }
    }
    
    private function routeTenant($path) {
        // Remove 'school/' from path
        $relativePath = substr($path, strlen('school/'));
        
        // Split into segments
        $segments = explode('/', $relativePath);
        
        // First segment is school slug
        $schoolSlug = $segments[0];
        
        // Verify school exists and is active
        $school = Tenant::getSchoolBySlug($schoolSlug);
        if (!$school || !in_array($school['status'], ['active', 'trial'])) {
            $this->showError('School not found or inactive');
            return;
        }
        
        // Set school context
        $_SESSION['current_school'] = $school;
        
        // Check access based on subscription status
        if (!$this->checkSchoolAccess($school)) {
            return;
        }
        
        // Route based on URL pattern
        if (count($segments) === 1) {
            // school/{slug} -> School homepage
            $this->showSchoolHomepage($school);
        } elseif (count($segments) === 2 && $segments[1] === 'login') {
            // school/{slug}/login -> School login
            $this->showSchoolLogin($school);
        } elseif (count($segments) >= 2) {
            // school/{slug}/{user_type}/{page} -> School portal
            $this->routeSchoolPortal($school, $segments);
        } else {
            $this->show404();
        }
    }
    
    private function checkSchoolAccess($school) {
        // Check trial status
        if ($school['status'] === 'trial' && !empty($school['trial_ends_at'])) {
            $trialEnd = strtotime($school['trial_ends_at']);
            if ($trialEnd < time()) {
                // Trial expired - redirect to upgrade page
                header('Location: /pricing?school=' . urlencode($school['slug']));
                exit;
            }
        }
        
        // Check subscription status for active schools
        if ($school['status'] === 'active') {
            // Get subscription status from your Tenant class
            $subscription = $this->getSchoolSubscription($school['id']);
            if (!$subscription || $subscription['status'] !== 'active') {
                $this->showError('Subscription is not active. Please contact support.');
                return false;
            }
        }
        
        return true;
    }
    
    private function getSchoolSubscription($schoolId) {
        try {
            $db = Database::getPlatformConnection();
            $stmt = $db->prepare("
                SELECT s.*, sub.status as subscription_status, sub.current_period_end
                FROM schools s
                LEFT JOIN subscriptions sub ON s.id = sub.school_id
                WHERE s.id = ?
            ");
            $stmt->execute([$schoolId]);
            return $stmt->fetch();
        } catch (Exception $e) {
            error_log("Error getting school subscription: " . $e->getMessage());
            return null;
        }
    }
    
    private function showSchoolHomepage($school) {
        // Set school context
        $_SESSION['school_id'] = $school['id'];
        $_SESSION['school_slug'] = $school['slug'];
        $_SESSION['school_name'] = $school['name'];
        
        // Load school homepage
        $homepagePath = ROOT_PATH . '/tenant/' . $school['slug'] . '/index.php';
        if (file_exists($homepagePath)) {
            require_once $homepagePath;
        } else {
            // Default school homepage
            $this->renderSchoolHomepage($school);
        }
    }
    
    private function renderSchoolHomepage($school) {
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title><?php echo htmlspecialchars($school['name']); ?> | <?php echo APP_NAME; ?></title>
            <script src="https://cdn.tailwindcss.com"></script>
            <style>
                .school-gradient {
                    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                }
            </style>
        </head>
        <body class="bg-gray-50">
            <div class="min-h-screen flex flex-col">
                <!-- School Header -->
                <header class="school-gradient text-white">
                    <div class="container mx-auto px-4 py-8">
                        <div class="flex flex-col md:flex-row items-center justify-between">
                            <div class="mb-6 md:mb-0">
                                <h1 class="text-3xl font-bold"><?php echo htmlspecialchars($school['name']); ?></h1>
                                <p class="text-white/80">Welcome to our school portal</p>
                            </div>
                            <a href="/tenant/<?php echo $school['slug']; ?>/login" 
                               class="bg-white text-purple-600 px-6 py-2 rounded-lg font-semibold hover:bg-gray-100 transition">
                                Login to Portal
                            </a>
                        </div>
                    </div>
                </header>
                
                <!-- Main Content -->
                <main class="flex-1 container mx-auto px-4 py-12">
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                        <!-- Admin Portal Card -->
                        <a href="/tenant/<?php echo $school['slug']; ?>/admin/dashboard" 
                           class="bg-white rounded-xl shadow-lg p-6 hover:shadow-xl transition-shadow">
                            <div class="text-center">
                                <div class="w-16 h-16 bg-blue-100 rounded-full flex items-center justify-center mx-auto mb-4">
                                    <i class="fas fa-user-shield text-blue-600 text-2xl"></i>
                                </div>
                                <h3 class="text-lg font-semibold text-gray-800 mb-2">Admin Portal</h3>
                                <p class="text-gray-600 text-sm">School administration and management</p>
                            </div>
                        </a>
                        
                        <!-- Teacher Portal Card -->
                        <a href="/tenant/<?php echo $school['slug']; ?>/teacher/dashboard" 
                           class="bg-white rounded-xl shadow-lg p-6 hover:shadow-xl transition-shadow">
                            <div class="text-center">
                                <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
                                    <i class="fas fa-chalkboard-teacher text-green-600 text-2xl"></i>
                                </div>
                                <h3 class="text-lg font-semibold text-gray-800 mb-2">Teacher Portal</h3>
                                <p class="text-gray-600 text-sm">Manage classes, attendance, and grades</p>
                            </div>
                        </a>
                        
                        <!-- Student Portal Card -->
                        <a href="/tenant/<?php echo $school['slug']; ?>/student/dashboard" 
                           class="bg-white rounded-xl shadow-lg p-6 hover:shadow-xl transition-shadow">
                            <div class="text-center">
                                <div class="w-16 h-16 bg-purple-100 rounded-full flex items-center justify-center mx-auto mb-4">
                                    <i class="fas fa-graduation-cap text-purple-600 text-2xl"></i>
                                </div>
                                <h3 class="text-lg font-semibold text-gray-800 mb-2">Student Portal</h3>
                                <p class="text-gray-600 text-sm">Access timetable, grades, and assignments</p>
                            </div>
                        </a>
                        
                        <!-- Parent Portal Card -->
                        <a href="/tenant/<?php echo $school['slug']; ?>/parent/dashboard" 
                           class="bg-white rounded-xl shadow-lg p-6 hover:shadow-xl transition-shadow">
                            <div class="text-center">
                                <div class="w-16 h-16 bg-yellow-100 rounded-full flex items-center justify-center mx-auto mb-4">
                                    <i class="fas fa-user-friends text-yellow-600 text-2xl"></i>
                                </div>
                                <h3 class="text-lg font-semibold text-gray-800 mb-2">Parent Portal</h3>
                                <p class="text-gray-600 text-sm">Monitor child's progress and fees</p>
                            </div>
                        </a>
                    </div>
                    
                    <!-- School Info -->
                    <div class="mt-12 bg-white rounded-xl shadow-lg p-8">
                        <h2 class="text-2xl font-bold text-gray-800 mb-6">About Our School</h2>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                            <div>
                                <h3 class="text-lg font-semibold text-gray-700 mb-3">Contact Information</h3>
                                <p class="text-gray-600">
                                    <i class="fas fa-envelope mr-2 text-blue-500"></i>
                                    <?php echo htmlspecialchars($school['email']); ?>
                                </p>
                                <p class="text-gray-600 mt-2">
                                    <i class="fas fa-phone mr-2 text-blue-500"></i>
                                    <?php echo htmlspecialchars($school['phone']); ?>
                                </p>
                                <p class="text-gray-600 mt-2">
                                    <i class="fas fa-map-marker-alt mr-2 text-blue-500"></i>
                                    <?php echo htmlspecialchars($school['address']); ?>
                                </p>
                            </div>
                            <div>
                                <h3 class="text-lg font-semibold text-gray-700 mb-3">Quick Links</h3>
                                <ul class="space-y-2">
                                    <li>
                                        <a href="/pricing" class="text-blue-600 hover:text-blue-800">
                                            <i class="fas fa-chart-line mr-2"></i> View Plans & Pricing
                                        </a>
                                    </li>
                                    <li>
                                        <a href="/contact" class="text-blue-600 hover:text-blue-800">
                                            <i class="fas fa-headset mr-2"></i> Contact Support
                                        </a>
                                    </li>
                                    <li>
                                        <a href="/register" class="text-blue-600 hover:text-blue-800">
                                            <i class="fas fa-user-plus mr-2"></i> Register New Account
                                        </a>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </main>
                
                <!-- Footer -->
                <footer class="bg-gray-800 text-white py-6">
                    <div class="container mx-auto px-4 text-center">
                        <p>&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($school['name']); ?>. All rights reserved.</p>
                        <p class="text-gray-400 text-sm mt-2">Powered by <?php echo APP_NAME; ?></p>
                    </div>
                </footer>
            </div>
            
            <!-- Font Awesome for icons -->
            <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/js/all.min.js"></script>
        </body>
        </html>
        <?php
        exit;
    }
    
    private function showSchoolLogin($school) {
        // Redirect to your existing login system
        require_once ROOT_PATH . '/tenant/login.php';
        exit;
    }
    
    private function routeSchoolPortal($school, $segments) {
        // Expected pattern: school/{slug}/{user_type}/{page}/{action?}
        if (count($segments) < 2) {
            $this->show404();
            return;
        }
        
        $userType = $segments[1];
        $page = $segments[2] ?? 'dashboard';
        $action = $segments[3] ?? null;
        
        // Validate user type
        $validUserTypes = ['admin', 'teacher', 'student', 'parent'];
        if (!in_array($userType, $validUserTypes)) {
            $this->show404();
            return;
        }
        
        // Check if user is authenticated for this school and user type
        if (!$this->isSchoolUserAuthenticated($school['id'], $userType)) {
            // Redirect to login
            header('Location: /tenant/' . $school['slug'] . '/login?redirect=' . urlencode($_SERVER['REQUEST_URI']));
            exit;
        }
        
        // Build file path
        $filePath = ROOT_PATH . '/tenant/' . $school['slug'] . '/' . $userType . '/' . $page . '.php';
        
        // Check if file exists
        if (file_exists($filePath)) {
            // Set action parameter if present
            if ($action) {
                $_GET['action'] = $action;
            }
            
            // Load the portal page
            require_once $filePath;
        } else {
            $this->show404();
        }
    }
    
    private function isSchoolUserAuthenticated($schoolId, $userType) {
        if (!isset($_SESSION['school_user'])) {
            return false;
        }
        
        $sessionSchoolId = $_SESSION['school_user']['school_id'] ?? null;
        $sessionUserType = $_SESSION['school_user']['user_type'] ?? null;
        
        return ($sessionSchoolId == $schoolId && $sessionUserType == $userType);
    }
    
    private function routeApi($path) {
        // This is a placeholder for API routing
        // You can expand this based on your API needs
        header('Content-Type: application/json');
        
        $response = [
            'status' => 'error',
            'message' => 'API endpoint not implemented',
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        echo json_encode($response);
        exit;
    }
    
    private function verifySuperAdmin() {
        // Start session if not started
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // Check if super admin is logged in
        if (!isset($_SESSION['super_admin'])) {
            header('Location: /platform/login');
            exit;
        }
        
        // Verify session timeout
        if (isset($_SESSION['super_admin']['last_activity'])) {
            $timeout = 3600; // 1 hour
            if (time() - $_SESSION['super_admin']['last_activity'] > $timeout) {
                // Session expired
                unset($_SESSION['super_admin']);
                header('Location: /platform/login?expired=1');
                exit;
            }
        }
        
        // Update last activity
        $_SESSION['super_admin']['last_activity'] = time();
    }
    
    private function show404() {
        http_response_code(404);
        
        if (APP_DEBUG) {
            echo '<h1>404 - Page Not Found</h1>';
            echo '<p>The requested URL was not found on this server.</p>';
            echo '<pre>Request: ' . htmlspecialchars($this->requestUri) . '</pre>';
        } else {
            // Load your custom 404 page
            $errorPage = ROOT_PATH . '/errors/404.html';
            if (file_exists($errorPage)) {
                readfile($errorPage);
            } else {
                echo '<h1>404 - Page Not Found</h1>';
            }
        }
        exit;
    }
    
    private function showError($message) {
        http_response_code(500);
        
        if (APP_DEBUG) {
            echo '<h1>Error</h1>';
            echo '<p>' . htmlspecialchars($message) . '</p>';
            echo '<pre>Request: ' . htmlspecialchars($this->requestUri) . '</pre>';
        } else {
            // Load your custom error page
            $errorPage = ROOT_PATH . '/errors/500.html';
            if (file_exists($errorPage)) {
                readfile($errorPage);
            } else {
                echo '<h1>An error occurred</h1>';
                echo '<p>Please try again later.</p>';
            }
        }
        exit;
    }
    
    private function handleError($exception) {
        http_response_code(500);
        
        error_log("Router Error: " . $exception->getMessage() . " in " . $exception->getFile() . ":" . $exception->getLine());
        
        if (APP_DEBUG) {
            echo '<h1>Router Error</h1>';
            echo '<p><strong>Message:</strong> ' . htmlspecialchars($exception->getMessage()) . '</p>';
            echo '<p><strong>File:</strong> ' . $exception->getFile() . '</p>';
            echo '<p><strong>Line:</strong> ' . $exception->getLine() . '</p>';
            echo '<pre>' . htmlspecialchars($exception->getTraceAsString()) . '</pre>';
        } else {
            echo '<h1>Internal Server Error</h1>';
            echo '<p>Please try again later.</p>';
        }
        exit;
    }
}]