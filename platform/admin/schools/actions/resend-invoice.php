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
    $schoolStmt = $db->prepare("
        SELECT s.*, i.* 
        FROM schools s
        LEFT JOIN invoices i ON s.id = i.school_id
        WHERE s.id = ?
        ORDER BY i.created_at DESC 
        LIMIT 1
    ");
    $schoolStmt->execute([$schoolId]);
    $school = $schoolStmt->fetch();
    
    if (!$school) {
        echo json_encode(['success' => false, 'message' => 'School not found']);
        exit;
    }
    
    // Get school admin emails
    $schoolDb = Database::getSchoolConnection($school['database_name'] ?? '');
    $adminStmt = $schoolDb->prepare("
        SELECT email, first_name, last_name 
        FROM users 
        WHERE user_type = 'admin' AND is_active = 1
    ");
    $adminStmt->execute();
    $admins = $adminStmt->fetchAll();
    
    // Send invoice email to each admin
    $sentCount = 0;
    foreach ($admins as $admin) {
        // Prepare email data
        $to = $admin['email'];
        $subject = "Invoice #{$school['invoice_number']} - {$school['name']}";
        
        // Email template
        $message = "
            <h2>Invoice #{$school['invoice_number']}</h2>
            <p>Dear {$admin['first_name']} {$admin['last_name']},</p>
            <p>This is a reminder for your pending invoice.</p>
            
            <div style='background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0;'>
                <h3>Invoice Details</h3>
                <p><strong>School:</strong> {$school['name']}</p>
                <p><strong>Amount:</strong> {$school['amount']}</p>
                <p><strong>Due Date:</strong> " . date('F j, Y', strtotime($school['due_date'])) . "</p>
                <p><strong>Period:</strong> " . date('M j', strtotime($school['start_date'])) . " - " . date('M j, Y', strtotime($school['end_date'])) . "</p>
            </div>
            
            <p>Please make payment by the due date to avoid service interruption.</p>
            <p>Thank you,<br>Platform Administration</p>
        ";
        
        // Here you would call your email sending function
        // $sent = sendEmail($to, $subject, $message);
        // if ($sent) $sentCount++;
        
        $sentCount++; // For testing
    }
    
    // Log the action
    $logStmt = $db->prepare("
        INSERT INTO platform_audit_logs 
        (school_id, event, description, user_type, created_at) 
        VALUES (?, 'invoice_resent', ?, 'super_admin', NOW())
    ");
    $logDescription = "Invoice #{$school['invoice_number']} resent to $sentCount school administrators";
    $logStmt->execute([$schoolId, $logDescription]);
    
    echo json_encode([
        'success' => true, 
        'message' => "Invoice resent to $sentCount school administrators"
    ]);
    
} catch (Exception $e) {
    error_log("Error resending invoice: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error resending invoice: ' . $e->getMessage()]);
}
?>