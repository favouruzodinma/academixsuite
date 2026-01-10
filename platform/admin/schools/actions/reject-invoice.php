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
    
    // Get latest invoice for the school
    $invoiceStmt = $db->prepare("
        SELECT * FROM invoices 
        WHERE school_id = ? 
        ORDER BY created_at DESC 
        LIMIT 1
    ");
    $invoiceStmt->execute([$schoolId]);
    $invoice = $invoiceStmt->fetch();
    
    if (!$invoice) {
        echo json_encode(['success' => false, 'message' => 'No invoice found']);
        exit;
    }
    
    // Update invoice status
    $updateStmt = $db->prepare("
        UPDATE invoices 
        SET status = 'rejected', 
            updated_at = NOW()
        WHERE id = ?
    ");
    $updateStmt->execute([$invoice['id']]);
    
    // Log the action
    $logStmt = $db->prepare("
        INSERT INTO platform_audit_logs 
        (school_id, event, description, user_type, created_at) 
        VALUES (?, 'invoice_rejected', ?, 'super_admin', NOW())
    ");
    $logDescription = "Invoice #{$invoice['invoice_number']} rejected. Amount: {$invoice['amount']}";
    $logStmt->execute([$schoolId, $logDescription]);
    
    echo json_encode([
        'success' => true, 
        'message' => 'Invoice rejected successfully'
    ]);
    
} catch (Exception $e) {
    error_log("Error rejecting invoice: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error rejecting invoice: ' . $e->getMessage()]);
}
?>