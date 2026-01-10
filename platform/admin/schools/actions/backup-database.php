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
    
    // Check if database exists
    $checkStmt = $db->prepare("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?");
    $checkStmt->execute([$databaseName]);
    $databaseExists = $checkStmt->fetch();
    
    if (!$databaseExists) {
        echo json_encode(['success' => false, 'message' => 'Database does not exist']);
        exit;
    }
    
    // Create backup directory if it doesn't exist
    $backupDir = __DIR__ . '/../../backups/';
    if (!file_exists($backupDir)) {
        mkdir($backupDir, 0755, true);
    }
    
    // Generate backup filename
    $timestamp = date('Y-m-d_H-i-s');
    $backupFile = $backupDir . "{$databaseName}_backup_{$timestamp}.sql";
    
    // MySQL dump command
    $command = sprintf(
        'mysqldump --user=%s --password=%s --host=%s %s > %s 2>&1',
        escapeshellarg(DB_USER),
        escapeshellarg(DB_PASS),
        escapeshellarg(DB_HOST),
        escapeshellarg($databaseName),
        escapeshellarg($backupFile)
    );
    
    // Execute backup command
    exec($command, $output, $returnVar);
    
    if ($returnVar !== 0) {
        throw new Exception("Backup failed: " . implode("\n", $output));
    }
    
    // Compress the backup file
    $compressedFile = $backupFile . '.gz';
    $gzCommand = sprintf('gzip -c %s > %s', escapeshellarg($backupFile), escapeshellarg($compressedFile));
    exec($gzCommand, $gzOutput, $gzReturnVar);
    
    if ($gzReturnVar === 0 && file_exists($compressedFile)) {
        // Delete the uncompressed file
        unlink($backupFile);
        $backupFile = $compressedFile;
        $backupSize = filesize($backupFile);
    } else {
        $backupSize = filesize($backupFile);
    }
    
    // Log the action
    $logStmt = $db->prepare("
        INSERT INTO platform_audit_logs 
        (school_id, event, description, user_type, created_at) 
        VALUES (?, 'database_backup', ?, 'super_admin', NOW())
    ");
    $logDescription = "Database backup created: " . basename($backupFile) . " (" . formatBytes($backupSize) . ")";
    $logStmt->execute([$schoolId, $logDescription]);
    
    // Record backup in database
    $backupRecordStmt = $db->prepare("
        INSERT INTO database_backups 
        (school_id, database_name, filename, file_size, backup_type, created_at)
        VALUES (?, ?, ?, ?, 'manual', NOW())
    ");
    $backupRecordStmt->execute([
        $schoolId,
        $databaseName,
        basename($backupFile),
        $backupSize
    ]);
    
    echo json_encode([
        'success' => true, 
        'message' => 'Database backup created successfully',
        'filename' => basename($backupFile),
        'file_size' => formatBytes($backupSize),
        'download_url' => "../backups/" . basename($backupFile)
    ]);
    
} catch (Exception $e) {
    error_log("Error creating backup: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error creating backup: ' . $e->getMessage()]);
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