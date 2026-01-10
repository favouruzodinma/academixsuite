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
    
    // Get all tables
    $tablesStmt = $schoolDb->query("SHOW TABLES");
    $tables = $tablesStmt->fetchAll(PDO::FETCH_COLUMN);
    
    $tablesTruncated = 0;
    $tablesDeleted = 0;
    
    // Truncate or drop each table
    foreach ($tables as $table) {
        // Don't truncate essential system tables if they exist
        $protectedTables = ['migrations', 'settings'];
        
        if (in_array($table, $protectedTables)) {
            // Skip protected tables
            continue;
        }
        
        try {
            // Try to truncate first (faster)
            $schoolDb->exec("TRUNCATE TABLE `$table`");
            $tablesTruncated++;
        } catch (Exception $e) {
            // If truncate fails, try to delete all rows
            try {
                $schoolDb->exec("DELETE FROM `$table`");
                $tablesTruncated++;
            } catch (Exception $e2) {
                // Log but continue
                error_log("Failed to clear table $table: " . $e2->getMessage());
            }
        }
    }
    
    // Log the action
    $logStmt = $db->prepare("
        INSERT INTO platform_audit_logs 
        (school_id, event, description, user_type, created_at) 
        VALUES (?, 'database_reset', ?, 'super_admin', NOW())
    ");
    $logDescription = "Database reset. $tablesTruncated tables cleared.";
    $logStmt->execute([$schoolId, $logDescription]);
    
    // Also reset school statistics
    $resetStatsStmt = $db->prepare("
        UPDATE schools 
        SET last_activity_at = NULL,
            user_count = 0,
            storage_used = 0,
            updated_at = NOW()
        WHERE id = ?
    ");
    $resetStatsStmt->execute([$schoolId]);
    
    echo json_encode([
        'success' => true, 
        'message' => 'Database reset completed',
        'tables_cleared' => $tablesTruncated,
        'warning' => 'All data has been removed from the database. This action cannot be undone.'
    ]);
    
} catch (Exception $e) {
    error_log("Error resetting database: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error resetting database: ' . $e->getMessage()]);
}
?>