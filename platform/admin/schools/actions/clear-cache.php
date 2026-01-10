<?php
session_start();
require_once __DIR__ . '/../../../../includes/autoload.php';

header('Content-Type: application/json');

// Check if super admin is logged in
$auth = new Auth();
if (!$auth->isLoggedIn('super_admin')) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

// Check if it's a JSON POST request
if (!isset($_SERVER['CONTENT_TYPE']) || stripos($_SERVER['CONTENT_TYPE'], 'application/json') === false) {
    echo json_encode(['success' => false, 'message' => 'Invalid content type. Expected JSON']);
    exit;
}

// Get JSON data
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    echo json_encode(['success' => false, 'message' => 'Invalid JSON data']);
    exit;
}

// Validate CSRF token using your existing function
if (!isset($data['csrf_token'])) {
    echo json_encode(['success' => false, 'message' => 'CSRF token is required']);
    exit;
}

// Use your existing CSRF validation function
if (!function_exists('validateCSRFToken')) {
    // Define the function if not exists (from your autoload.php)
    function validateCSRFToken($token) {
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
}

if (!validateCSRFToken($data['csrf_token'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid or expired CSRF token']);
    exit;
}
$schoolId = $data['school_id'] ?? 0;

if ($schoolId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid school ID']);
    exit;
}

try {
    $db = Database::getPlatformConnection();
    
    // Get school details
    $schoolStmt = $db->prepare("SELECT name FROM schools WHERE id = ?");
    $schoolStmt->execute([$schoolId]);
    $school = $schoolStmt->fetch();
    
    if (!$school) {
        echo json_encode(['success' => false, 'message' => 'School not found']);
        exit;
    }
    
    // Clear various types of cache
    $cacheTypesCleared = [];
    
    // 1. Clear opcache (if enabled)
    if (function_exists('opcache_reset')) {
        opcache_reset();
        $cacheTypesCleared[] = 'opcache';
    }
    
    // 2. Clear APC cache (if enabled)
    if (function_exists('apc_clear_cache')) {
        apc_clear_cache();
        apc_clear_cache('user');
        $cacheTypesCleared[] = 'apc';
    }
    
    // 3. Clear school-specific cache directories
    $cacheDirs = [
        __DIR__ . '/../../../storage/cache/',
        __DIR__ . '/../../../storage/views/',
        __DIR__ . '/../../../storage/sessions/'
    ];
    
    $filesCleared = 0;
    foreach ($cacheDirs as $cacheDir) {
        if (file_exists($cacheDir)) {
            $files = glob($cacheDir . '*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    // Don't delete .gitignore files
                    if (basename($file) !== '.gitignore') {
                        unlink($file);
                        $filesCleared++;
                    }
                }
            }
        }
    }
    if ($filesCleared > 0) {
        $cacheTypesCleared[] = "file_cache ($filesCleared files)";
    }
    
    // 4. Clear school database cache tables if they exist
    try {
        $databaseName = $data['database_name'] ?? '';
        if ($databaseName) {
            $schoolDb = Database::getSchoolConnection($databaseName);
            
            // Check for cache table
            $cacheTables = ['cache', 'sessions', 'failed_jobs'];
            foreach ($cacheTables as $table) {
                try {
                    $schoolDb->exec("TRUNCATE TABLE `$table`");
                    $cacheTypesCleared[] = "db_$table";
                } catch (Exception $e) {
                    // Table doesn't exist or can't be truncated
                }
            }
        }
    } catch (Exception $e) {
        // Database might not exist
    }
    
    // Log the action
    $logStmt = $db->prepare("
        INSERT INTO platform_audit_logs 
        (school_id, event, description, user_type, created_at) 
        VALUES (?, 'cache_cleared', ?, 'super_admin', NOW())
    ");
    $logDescription = "Cache cleared. Types: " . implode(', ', $cacheTypesCleared);
    $logStmt->execute([$schoolId, $logDescription]);
    
    echo json_encode([
        'success' => true, 
        'message' => 'Cache cleared successfully',
        'cache_types_cleared' => $cacheTypesCleared,
        'total_types' => count($cacheTypesCleared)
    ]);
    
} catch (Exception $e) {
    error_log("Error clearing cache: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error clearing cache: ' . $e->getMessage()]);
}
?>