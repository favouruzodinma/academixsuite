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
    
    // Create exports directory if it doesn't exist
    $exportDir = __DIR__ . '/../../exports/';
    if (!file_exists($exportDir)) {
        mkdir($exportDir, 0755, true);
    }
    
    // Generate export filename
    $timestamp = date('Y-m-d_H-i-s');
    $exportFile = $exportDir . "{$databaseName}_schema_{$timestamp}.sql";
    
    // Get only schema (no data)
    $command = sprintf(
        'mysqldump --user=%s --password=%s --host=%s --no-data %s > %s 2>&1',
        escapeshellarg(DB_USER),
        escapeshellarg(DB_PASS),
        escapeshellarg(DB_HOST),
        escapeshellarg($databaseName),
        escapeshellarg($exportFile)
    );
    
    // Execute export command
    exec($command, $output, $returnVar);
    
    if ($returnVar !== 0) {
        throw new Exception("Schema export failed: " . implode("\n", $output));
    }
    
    $exportSize = filesize($exportFile);
    
    // Log the action
    $logStmt = $db->prepare("
        INSERT INTO platform_audit_logs 
        (school_id, event, description, user_type, created_at) 
        VALUES (?, 'schema_exported', ?, 'super_admin', NOW())
    ");
    $logDescription = "Database schema exported: " . basename($exportFile) . " (" . formatBytes($exportSize) . ")";
    $logStmt->execute([$schoolId, $logDescription]);
    
    echo json_encode([
        'success' => true, 
        'message' => 'Database schema exported successfully',
        'filename' => basename($exportFile),
        'file_size' => formatBytes($exportSize),
        'download_url' => "../exports/" . basename($exportFile)
    ]);
    
} catch (Exception $e) {
    error_log("Error exporting schema: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error exporting schema: ' . $e->getMessage()]);
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