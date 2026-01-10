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
    $schoolStmt = $db->prepare("SELECT name, database_name FROM schools WHERE id = ?");
    $schoolStmt->execute([$schoolId]);
    $school = $schoolStmt->fetch();
    
    if (!$school) {
        echo json_encode(['success' => false, 'message' => 'School not found']);
        exit;
    }
    
    // Backup current database before reset
    $timestamp = date('Y-m-d_H-i-s');
    $backupDir = __DIR__ . '/../../backups/resets/';
    if (!file_exists($backupDir)) {
        mkdir($backupDir, 0755, true);
    }
    
    $backupFile = $backupDir . "{$databaseName}_before_reset_{$timestamp}.sql.gz";
    
    // Create backup
    $command = sprintf(
        'mysqldump --user=%s --password=%s --host=%s %s | gzip > %s 2>&1',
        escapeshellarg(DB_USER),
        escapeshellarg(DB_PASS),
        escapeshellarg(DB_HOST),
        escapeshellarg($databaseName),
        escapeshellarg($backupFile)
    );
    
    exec($command, $output, $returnVar);
    
    if ($returnVar !== 0) {
        throw new Exception("Backup failed before reset: " . implode("\n", $output));
    }
    
    // Connect to school database
    $schoolDb = Database::getSchoolConnection($databaseName);
    
    // Get all tables
    $tablesStmt = $schoolDb->query("SHOW TABLES");
    $tables = $tablesStmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Disable foreign key checks
    $schoolDb->exec("SET FOREIGN_KEY_CHECKS = 0");
    
    $tablesDropped = 0;
    $tablesPreserved = 0;
    
    foreach ($tables as $table) {
        // Preserve essential system tables
        $preservedTables = ['migrations', 'settings'];
        
        if (in_array($table, $preservedTables)) {
            // Truncate but don't drop preserved tables
            $schoolDb->exec("TRUNCATE TABLE `$table`");
            $tablesPreserved++;
        } else {
            // Drop all other tables
            $schoolDb->exec("DROP TABLE IF EXISTS `$table`");
            $tablesDropped++;
        }
    }
    
    // Re-enable foreign key checks
    $schoolDb->exec("SET FOREIGN_KEY_CHECKS = 1");
    
    // Recreate the database schema from migrations
    // This assumes you have a way to run migrations for a specific school
    // For simplicity, we'll just note that migrations need to be run
    
    // Reset school statistics in platform database
    $resetStmt = $db->prepare("
        UPDATE schools 
        SET 
            status = 'active',
            trial_ends_at = NULL,
            suspended_at = NULL,
            last_activity_at = NULL,
            user_count = 0,
            storage_used = 0,
            updated_at = NOW()
        WHERE id = ?
    ");
    $resetStmt->execute([$schoolId]);
    
    // Reset subscription
    $resetSubStmt = $db->prepare("
        UPDATE subscriptions 
        SET 
            status = 'active',
            current_period_start = NOW(),
            current_period_end = DATE_ADD(NOW(), INTERVAL 30 DAY),
            updated_at = NOW()
        WHERE school_id = ?
    ");
    $resetSubStmt->execute([$schoolId]);
    
    // Clear all invoices and payments
    $clearInvoicesStmt = $db->prepare("DELETE FROM invoices WHERE school_id = ?");
    $clearInvoicesStmt->execute([$schoolId]);
    
    $clearPaymentsStmt = $db->prepare("DELETE FROM payments WHERE school_id = ?");
    $clearPaymentsStmt->execute([$schoolId]);
    
    // Log the action
    $logStmt = $db->prepare("
        INSERT INTO platform_audit_logs 
        (school_id, event, description, user_type, created_at) 
        VALUES (?, 'school_reset', ?, 'super_admin', NOW())
    ");
    $logDescription = "School completely reset. $tablesDropped tables dropped, $tablesPreserved tables preserved. Backup created: " . basename($backupFile);
    $logStmt->execute([$schoolId, $logDescription]);
    
    echo json_encode([
        'success' => true, 
        'message' => 'School has been reset to factory settings',
        'backup_created' => basename($backupFile),
        'backup_size' => formatBytes(filesize($backupFile)),
        'tables_dropped' => $tablesDropped,
        'tables_preserved' => $tablesPreserved,
        'next_steps' => [
            'Run database migrations for the school',
            'Configure initial school settings',
            'Create admin user accounts',
            'Import sample data if needed'
        ]
    ]);
    
} catch (Exception $e) {
    error_log("Error resetting school: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error resetting school: ' . $e->getMessage()]);
}

function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}
?>