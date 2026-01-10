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
$databaseName = $data['database_name'] ?? '';

if ($schoolId <= 0 || empty($databaseName)) {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
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
    
    // Connect to school database
    $schoolDb = Database::getSchoolConnection($databaseName);
    
    // Get list of tables
    $tablesStmt = $schoolDb->query("SHOW TABLES");
    $tables = $tablesStmt->fetchAll(PDO::FETCH_COLUMN);
    
    $optimizedTables = [];
    $totalSavings = 0;
    
    // Optimize each table
    foreach ($tables as $table) {
        // Get table status before optimization
        $statusStmt = $schoolDb->prepare("SHOW TABLE STATUS LIKE ?");
        $statusStmt->execute([$table]);
        $statusBefore = $statusStmt->fetch();
        
        // Optimize the table
        $optimizeStmt = $schoolDb->prepare("OPTIMIZE TABLE `$table`");
        $optimizeStmt->execute();
        
        // Get table status after optimization
        $statusStmt->execute([$table]);
        $statusAfter = $statusStmt->fetch();
        
        // Calculate savings
        $savings = ($statusBefore['Data_free'] ?? 0) - ($statusAfter['Data_free'] ?? 0);
        if ($savings > 0) {
            $totalSavings += $savings;
            $optimizedTables[] = [
                'table' => $table,
                'savings' => formatBytes($savings)
            ];
        }
    }
    
    // Log the action
    $logStmt = $db->prepare("
        INSERT INTO platform_audit_logs 
        (school_id, event, description, user_type, created_at) 
        VALUES (?, 'database_optimized', ?, 'super_admin', NOW())
    ");
    $logDescription = "Database optimized. " . count($optimizedTables) . " tables processed. Total savings: " . formatBytes($totalSavings);
    $logStmt->execute([$schoolId, $logDescription]);
    
    echo json_encode([
        'success' => true, 
        'message' => 'Database optimization completed',
        'tables_optimized' => count($optimizedTables),
        'total_savings' => formatBytes($totalSavings),
        'optimized_tables' => $optimizedTables
    ]);
    
} catch (Exception $e) {
    error_log("Error optimizing database: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error optimizing database: ' . $e->getMessage()]);
}

// Helper function to format bytes
function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}
?>