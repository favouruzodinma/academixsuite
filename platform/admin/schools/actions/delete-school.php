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

if ($schoolId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid school ID']);
    exit;
}

try {
    $db = Database::getPlatformConnection();
    
    // Get school details for logging
    $schoolStmt = $db->prepare("SELECT name, database_name FROM schools WHERE id = ?");
    $schoolStmt->execute([$schoolId]);
    $school = $schoolStmt->fetch();
    
    if (!$school) {
        echo json_encode(['success' => false, 'message' => 'School not found']);
        exit;
    }
    
    // 1. Delete school database if exists
    if (!empty($school['database_name'])) {
        try {
            $db->exec("DROP DATABASE IF EXISTS `" . $school['database_name'] . "`");
        } catch (Exception $e) {
            error_log("Error dropping database: " . $e->getMessage());
        }
    }
    
    // 2. Delete related records (invoices, subscriptions, etc.)
    $deleteQueries = [
        "DELETE FROM invoices WHERE school_id = ?",
        "DELETE FROM subscriptions WHERE school_id = ?",
        "DELETE FROM platform_audit_logs WHERE school_id = ?",
        "DELETE FROM school_settings WHERE school_id = ?"
    ];
    
    foreach ($deleteQueries as $query) {
        try {
            $stmt = $db->prepare($query);
            $stmt->execute([$schoolId]);
        } catch (Exception $e) {
            // Some tables might not exist
            error_log("Error deleting records: " . $e->getMessage());
        }
    }
    
    // 3. Delete the school record
    $deleteStmt = $db->prepare("DELETE FROM schools WHERE id = ?");
    $deleteStmt->execute([$schoolId]);
    
    // 4. Log the action
    $logStmt = $db->prepare("
        INSERT INTO platform_audit_logs 
        (event, description, user_type, created_at) 
        VALUES ('school_deleted', ?, 'super_admin', NOW())
    ");
    $logStmt->execute(["School '{$school['name']}' (ID: $schoolId) deleted permanently. Database '{$school['database_name']}' dropped."]);
    
    echo json_encode([
        'success' => true, 
        'message' => 'School deleted permanently'
    ]);
    
} catch (Exception $e) {
    error_log("Error deleting school: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error deleting school: ' . $e->getMessage()]);
}
?>